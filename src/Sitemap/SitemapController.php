<?php
namespace PublishRankSEO\Sitemap;

use PublishRankSEO\Sitemap\Engine\SitemapEngine;

defined( 'ABSPATH' ) || exit;

final class SitemapController {
	private $engine;
	public function __construct() { $this->engine = new SitemapEngine(); }
	public function register() {
		add_action( 'init', array( $this, 'rewrite' ) );
		add_action( 'template_redirect', array( $this, 'render' ), 0 );
		add_filter( 'wp_sitemaps_enabled', '__return_false', 20 );
	}
	public function rewrite() {
		add_rewrite_tag( '%publishrank_sitemap%', '([^&]+)' );
		$endpoints = array_merge( array( SitemapUrls::base() ), SitemapUrls::aliases() );
		foreach ( $endpoints as $endpoint ) { add_rewrite_rule( '^' . preg_quote( $endpoint, '/' ) . '\.xml$', 'index.php?publishrank_sitemap=index', 'top' ); }
		$prefix = preg_quote( SitemapUrls::shard_prefix(), '/' );
		add_rewrite_rule( '^' . $prefix . '-([a-z-]+)-sitemap-([0-9]+)\.xml$', 'index.php?publishrank_sitemap=$matches[1]&publishrank_sitemap_shard=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $prefix . '-([a-z-]+)-sitemap\.xml$', 'index.php?publishrank_sitemap=$matches[1]&publishrank_sitemap_shard=1', 'top' );
		add_rewrite_tag( '%publishrank_sitemap_shard%', '([0-9]+)' );
	}
	public function render() {
		$slug = sanitize_key( (string) get_query_var( 'publishrank_sitemap' ) );
		if ( ! $slug ) { return; }
		// WordPress' canonical redirect must never rewrite a sitemap URL.
		remove_action( 'template_redirect', 'redirect_canonical' );
		$xml = 'index' === $slug ? $this->engine->index() : $this->engine->render( $slug, max( 1, absint( get_query_var( 'publishrank_sitemap_shard', 1 ) ) ) );
		// null: a cursor-backed provider is still preparing its checkpoints (large catalogues only).
		if ( null === $xml ) { $this->discard_output(); status_header( 503 ); header( 'Retry-After: 60' ); header( 'X-Robots-Tag: noindex' ); exit; }
		// false: generation failed; the cause is stored for the admin diagnostics screen.
		if ( false === $xml ) { $this->discard_output(); status_header( 500 ); header( 'Content-Type: text/plain; charset=UTF-8' ); header( 'X-Robots-Tag: noindex' ); echo 'Sitemap generation failed. See PublishRank > Sitemap Health for details.'; exit; }
		// '': unknown provider, disabled provider or shard number out of range: a genuine 404.
		if ( '' === $xml ) { global $wp_query; if ( is_object( $wp_query ) ) { $wp_query->set_404(); } status_header( 404 ); nocache_headers(); return; }
		$this->discard_output();
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=' . ( 'news' === $slug ? 60 : 300 ) );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) { exit; }
		$style = get_option( 'publishrank_sitemap_stylesheet', 1 ) ? '<?xml-stylesheet type="text/xsl" href="' . esc_url( SitemapStylesheet::url() ) . '"?>' : '';
		echo '<?xml version="1.0" encoding="UTF-8"?>' . $style . $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
	/** Drop buffered output (themes/plugins) so nothing precedes the XML declaration. */
	private function discard_output() { while ( ob_get_level() > 0 ) { @ob_end_clean(); } }
	public function engine() { return $this->engine; }
}
