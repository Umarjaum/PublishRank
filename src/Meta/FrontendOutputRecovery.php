<?php
/** Recovers essential SEO head output when a theme omits wp_head(). */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Schema\GraphOutput;

defined( 'ABSPATH' ) || exit;

final class FrontendOutputRecovery {
	private $registry;
	private static $started = false;
	public function __construct( OutputRegistry $registry ) { $this->registry = $registry; }
	public function register() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		if ( ! get_option( 'publishrank_meta_enabled', true ) && ! get_option( 'publishrank_schema_enabled', true ) ) { return; }
		add_action( 'template_redirect', array( $this, 'start' ), 999 );
	}
	public function start() {
		// Caching, compression, and security plugins commonly open a buffer first.
		// A nested buffer is safe and is required to recover SEO output when a theme
		// omits wp_head().
		if ( ! headers_sent() && ! self::$started ) { self::$started = true; ob_start( array( $this, 'inject' ) ); }
	}
	public function inject( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '</head>' ) || false !== strpos( $html, '<!-- SEO metadata output begin -->' ) || false !== strpos( $html, '<!-- PublishRank: begin;' ) ) { return $html; }
		ob_start();
		$head = new HeadOutput( $this->registry );
		$head->render();
		$graph = new GraphOutput( $this->registry );
		$graph->render();
		$payload = ob_get_clean();
		if ( ! preg_match( '/<title\b[^>]*>.*?<\/title\s*>/is', $html ) ) {
			$title = ( new MetaGenerator() )->title();
			if ( '' !== $title ) { $payload = '<title>' . esc_html( $title ) . '</title>\n' . $payload; }
		}
		if ( '' === trim( $payload ) ) { return $html; }
		return preg_replace( '/<\/head\s*>/i', "\n" . $payload . '</head>', $html, 1 );
	}
}
