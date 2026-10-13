<?php
/** Recovers essential SEO head output when a theme omits wp_head(). */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Schema\GraphOutput;

defined( 'ABSPATH' ) || exit;

final class FrontendOutputRecovery {
	private $registry;
	public function __construct( OutputRegistry $registry ) { $this->registry = $registry; }
	public function register() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		if ( ! get_option( 'publishrank_meta_enabled', true ) && ! get_option( 'publishrank_schema_enabled', true ) ) { return; }
		// WordPress 6.9+ provides a standard template-enhancement output buffer.
		// The plugin does not open or manage its own output buffer for the page.
		add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'inject' ) );
	}

	public function inject( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '</head>' ) || false !== strpos( $html, '<!-- SEO metadata output begin -->' ) || false !== strpos( $html, '<!-- PublishRank: begin;' ) ) { return $html; }
		$payload = '';
		ob_start();
		try {
			$head = new HeadOutput( $this->registry );
			$head->render();
			$graph = new GraphOutput( $this->registry );
			$graph->render();
		} finally {
			$payload = (string) ob_get_clean();
		}
		if ( ! preg_match( '/<title\b[^>]*>.*?<\/title\s*>/is', $html ) ) {
			$title = ( new MetaGenerator() )->title();
			if ( '' !== $title ) { $payload = '<title>' . esc_html( $title ) . '</title>' . "\n" . $payload; }
		}
		if ( '' === trim( $payload ) ) { return $html; }
		return preg_replace( '/<\/head\s*>/i', "\n" . $payload . '</head>', $html, 1 );
	}
}
