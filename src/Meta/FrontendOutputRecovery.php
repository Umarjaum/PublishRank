<?php
/** Recovers essential SEO head output when a theme omits wp_head(). */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Schema\GraphOutput;

defined( 'ABSPATH' ) || exit;

final class FrontendOutputRecovery {
	private $registry;
	/** Rendered recovery markup (built once, outside any output-buffer callback). */
	private $payload = '';
	private $prepared = false;
	private $injected = false;

	public function __construct( OutputRegistry $registry ) { $this->registry = $registry; }
	public function register() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		if ( ! get_option( 'publishrank_meta_enabled', true ) && ! get_option( 'publishrank_schema_enabled', true ) ) { return; }
		// WordPress 6.9+ provides a standard template-enhancement output buffer.
		add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'inject' ) );
		// Some themes omit wp_head() entirely. Buffer the front-end document and inject into </head> as a last-resort compatibility path.
		add_action( 'template_redirect', array( $this, 'start_fallback_buffer' ), 0 );
		// PHP forbids ob_start() inside an output-buffer callback (fatal error, blank page). The markup is therefore
		// rendered here, at shutdown priority 0, which runs before WordPress flushes the buffers at priority 1.
		add_action( 'shutdown', array( $this, 'prepare_payload' ), 0 );
	}

	/** Start only on front-end HTML requests; inject() safely returns unchanged content for non-HTML responses. */
	public function start_fallback_buffer() {
		if ( $this->is_non_html_request() ) { return; }
		if ( ! headers_sent() ) { ob_start( array( $this, 'inject' ) ); }
	}

	/** Requests whose response is never a themed HTML document (feeds, sitemaps, the sitemap stylesheet, embeds, robots.txt). */
	private function is_non_html_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return true; }
		if ( did_action( 'wp' ) && ( is_feed() || is_robots() || is_embed() || is_trackback() ) ) { return true; }
		if ( '' !== (string) get_query_var( 'publishrank_sitemap' ) || '' !== (string) get_query_var( 'publishrank_xsl' ) ) { return true; }
		return false;
	}

	/** Render the missing head markup in a normal (non output-handler) context. */
	public function prepare_payload() {
		if ( $this->prepared ) { return; }
		$this->prepared = true;
		if ( ! did_action( 'template_redirect' ) || $this->is_non_html_request() ) { return; }
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'html' ) ) { return; }
		}
		// If wp_head() already ran PublishRank's output the slots are claimed and there is nothing to recover.
		$needs_head  = (bool) get_option( 'publishrank_meta_enabled', true ) && ! $this->registry->has( 'canonical' );
		$needs_graph = (bool) get_option( 'publishrank_schema_enabled', true ) && ! $this->registry->has( 'jsonld' );
		if ( ! $needs_head && ! $needs_graph ) { return; }
		$rendered = '';
		ob_start();
		try {
			if ( $needs_head ) { ( new HeadOutput( $this->registry ) )->render(); }
			if ( $needs_graph ) { ( new GraphOutput( $this->registry ) )->render(); }
			$rendered = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$rendered = '';
		}
		$this->payload = $rendered;
	}

	/** Output-buffer / filter callback. Must never start its own output buffer. */
	public function inject( $html ) {
		if ( $this->injected || '' === $this->payload || ! is_string( $html ) || false === stripos( $html, '</head>' ) ) { return $html; }
		if ( false !== strpos( $html, '<!-- SEO metadata output begin -->' ) || false !== strpos( $html, '<!-- PublishRank: begin;' ) || false !== strpos( $html, '<!-- Structured data graph begin' ) ) { return $html; }
		$payload = $this->payload;
		if ( ! preg_match( '/<title\b[^>]*>.*?<\/title\s*>/is', $html ) ) {
			$title = ( new MetaGenerator() )->title();
			if ( '' !== $title ) { $payload = '<title>' . esc_html( $title ) . '</title>' . "\n" . $payload; }
		}
		$this->injected = true;
		return preg_replace( '/<\/head\s*>/i', "\n" . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $payload ) . '</head>', $html, 1 );
	}
}
