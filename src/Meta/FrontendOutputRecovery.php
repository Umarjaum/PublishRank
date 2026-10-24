<?php
/** Recovers essential SEO head output when a theme omits wp_head(). */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Schema\GraphOutput;
use PublishRankSEO\Security\TraceIntegrity;

defined( 'ABSPATH' ) || exit;

final class FrontendOutputRecovery {
	private $registry;
	/** Rendered recovery markup (built once, outside any output-buffer callback). */
	private $payload       = '';
	private $trace_payload = '';
	private $prepared      = false;
	private $injected      = false;
	/** Output-buffer level owned by this instance (zero means not started). */
	private $fallback_buffer_level = 0;

	public function __construct( OutputRegistry $registry ) {
		$this->registry = $registry; }
	public function register() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return; }
		if ( ! get_option( 'publishrank_meta_enabled', true ) && ! get_option( 'publishrank_schema_enabled', true ) ) {
			return; }
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
		if ( $this->is_non_html_request() ) {
			return; }
		if ( ! headers_sent() && 0 === $this->fallback_buffer_level ) {
			ob_start( array( $this, 'inject' ) );
			$this->fallback_buffer_level = ob_get_level();
			// Close this buffer explicitly after the page has rendered. Inner buffers
			// get a lower shutdown priority so nested PublishRank buffers close safely.
			add_action( 'shutdown', array( $this, 'finish_fallback_buffer' ), max( 100, 1000 - $this->fallback_buffer_level ) );
		}
	}

	/** Flush only this instance's buffer when it is still the topmost buffer. */
	public function finish_fallback_buffer() {
		if ( 0 === $this->fallback_buffer_level ) {
			return; }
		$status = ob_get_status( true );
		$top    = end( $status );
		if ( ob_get_level() === $this->fallback_buffer_level && is_array( $top ) && __CLASS__ . '::inject' === $top['name'] ) {
			ob_end_flush();
		}
		$this->fallback_buffer_level = 0;
	}

	/** Requests whose response is never a themed HTML document (feeds, sitemaps, the sitemap stylesheet, embeds, robots.txt). */
	private function is_non_html_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true; }
		if ( did_action( 'wp' ) && ( is_feed() || is_robots() || is_embed() || is_trackback() ) ) {
			return true; }
		if ( '' !== (string) get_query_var( 'publishrank_sitemap' ) || '' !== (string) get_query_var( 'publishrank_xsl' ) ) {
			return true; }
		return false;
	}

	/** Render the missing head markup in a normal (non output-handler) context. */
	public function prepare_payload() {
		if ( $this->prepared ) {
			return; }
		$this->prepared = true;
		if ( ! did_action( 'template_redirect' ) || $this->is_non_html_request() ) {
			return; }
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'html' ) ) {
				return; }
		}
		// If wp_head() already ran PublishRank's output the slots are claimed and there is nothing to recover.
		// wp_head() is missing in some themes; in that case TraceIntegrity's wp_head
		// callback never runs. Keep provenance independent of metadata/schema recovery.
		$this->trace_payload = TraceIntegrity::markup();
		$needs_head          = (bool) get_option( 'publishrank_meta_enabled', true ) && ! $this->registry->has( 'canonical' );
		$needs_graph         = (bool) get_option( 'publishrank_schema_enabled', true ) && ! $this->registry->has( 'jsonld' );
		if ( ! $needs_head && ! $needs_graph ) {
			return; }
		$rendered = '';
			ob_start();
		try {
			if ( $needs_head ) {
				( new HeadOutput( $this->registry ) )->render(); }
			if ( $needs_graph ) {
				( new GraphOutput( $this->registry ) )->render(); }
			$rendered = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$rendered = '';
		}
		$this->payload = $rendered;
	}

	/** Output-buffer / filter callback. Must never start its own output buffer. */
	public function inject( $html ) {
		if ( $this->injected || ! is_string( $html ) || false === stripos( $html, '</head>' ) ) {
			return $html; }
		$insertion = '';
		// Inject technical provenance even when the theme has already printed SEO
		// metadata, or when metadata/schema output is disabled by the administrator.
		if ( '' !== $this->trace_payload && false === strpos( $html, '<!-- PublishRank provenance:' ) ) {
			$insertion .= $this->trace_payload;
		}
		$has_seo_output = false !== strpos( $html, '<!-- SEO metadata output begin -->' ) || false !== strpos( $html, '<!-- PublishRank: begin;' ) || false !== strpos( $html, '<!-- Structured data graph begin' );
		if ( '' !== $this->payload && ! $has_seo_output ) {
			$payload = $this->payload;
			if ( ! preg_match( '/<title\b[^>]*>.*?<\/title\s*>/is', $html ) ) {
				$title = ( new MetaGenerator() )->title();
				if ( '' !== $title ) {
					$payload = '<title>' . esc_html( $title ) . '</title>' . "\n" . $payload; }
			}
			$insertion .= $payload;
		}
		if ( '' === $insertion ) {
			return $html; }
		$this->injected = true;
		return preg_replace( '/<\/head\s*>/i', "\n" . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $insertion ) . '</head>', $html, 1 );
	}
}
