<?php
namespace PublishRankSEO\Sitemap;

use PublishRankSEO\Sitemap\Engine\SitemapEngine;

final class SitemapController {
	private $engine;
	public function __construct() { $this->engine = new SitemapEngine(); }
	public function register() {
		add_action( 'init', array( $this, 'rewrite' ) );
		add_action( 'template_redirect', array( $this, 'render' ) );
		add_filter( 'wp_sitemaps_enabled', '__return_false', 20 );
	}
	public function rewrite() {
		add_rewrite_tag( '%publishrank_sitemap%', '([^&]+)' );
		$endpoints = array_merge( array( SitemapUrls::base() ), SitemapUrls::aliases() );
		foreach ( $endpoints as $endpoint ) { add_rewrite_rule( '^' . preg_quote( $endpoint, '/' ) . '\.xml$', 'index.php?publishrank_sitemap=index', 'top' ); }
		add_rewrite_rule( '^publishrank-([a-z-]+)-sitemap-([0-9]+)\.xml$', 'index.php?publishrank_sitemap=$matches[1]&publishrank_sitemap_shard=$matches[2]', 'top' );
		add_rewrite_rule( '^publishrank-([a-z-]+)-sitemap\.xml$', 'index.php?publishrank_sitemap=$matches[1]&publishrank_sitemap_shard=1', 'top' );
		add_rewrite_tag( '%publishrank_sitemap_shard%', '([0-9]+)' );
	}
	public function render() {
		$slug = sanitize_key( (string) get_query_var( 'publishrank_sitemap' ) );
		if ( ! $slug ) { return; }
			$xml = 'index' === $slug ? $this->engine->index() : $this->engine->render( $slug, max( 1, absint( get_query_var( 'publishrank_sitemap_shard', 1 ) ) ) );
			if ( null === $xml ) { status_header( 503 ); header( 'Retry-After: 60' ); header( 'X-Robots-Tag: noindex' ); exit; }
			if ( '' === $xml ) { return; }
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );
		header( 'X-Robots-Tag: noindex' );
		$style = get_option( 'publishrank_sitemap_stylesheet', 1 ) ? '<?xml-stylesheet type="text/xsl" href="' . esc_url( SitemapStylesheet::url() ) . '"?>' : '';
		echo '<?xml version="1.0" encoding="UTF-8"?>' . $style . $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
	public function engine() { return $this->engine; }
}
