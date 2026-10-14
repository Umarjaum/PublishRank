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
		// Sitemap URLs must keep working even when the stored rewrite rules are missing or stale
		// (plugin files replaced without re-activation, permalinks reset, rules flushed by another plugin).
		add_action( 'parse_request', array( $this, 'match_request' ), 0 );
		add_action( 'wp_loaded', array( $this, 'heal_rewrite_rules' ), 20 );
	}
	/** Resolve a sitemap request straight from the URL when no stored rewrite rule matched it. */
	public function match_request( $wp ) {
		if ( ! is_object( $wp ) || ! empty( $wp->query_vars['publishrank_sitemap'] ) || empty( $_SERVER['REQUEST_URI'] ) ) { return; }
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) { $path = '/' . substr( $path, strlen( $home ) ); }
		$path = ltrim( rawurldecode( $path ), '/' );
		if ( '.xml' !== substr( $path, -4 ) ) { return; }
		$name = substr( $path, 0, -4 );
		if ( in_array( $name, array_merge( array( SitemapUrls::base() ), SitemapUrls::aliases() ), true ) ) {
			$wp->query_vars['publishrank_sitemap'] = 'index';
			return;
		}
		$prefix = preg_quote( SitemapUrls::shard_prefix(), '#' );
		if ( preg_match( '#^' . $prefix . '-([a-z-]+)-sitemap-([0-9]+)$#', $name, $m ) ) {
			$wp->query_vars['publishrank_sitemap'] = $m[1];
			$wp->query_vars['publishrank_sitemap_shard'] = $m[2];
		} elseif ( preg_match( '#^' . $prefix . '-([a-z-]+)-sitemap$#', $name, $m ) ) {
			$wp->query_vars['publishrank_sitemap'] = $m[1];
			$wp->query_vars['publishrank_sitemap_shard'] = 1;
		}
	}
	/** Flush rewrite rules once when the sitemap rule is absent from the stored rules. */
	public function heal_rewrite_rules() {
		if ( ! get_option( 'permalink_structure' ) ) { return; }
		$signature = md5( 'xsl|' . SitemapUrls::base() . '|' . implode( ',', SitemapUrls::aliases() ) . '|' . SitemapUrls::shard_prefix() );
		$rules = get_option( 'rewrite_rules' );
		$key = '^' . preg_quote( SitemapUrls::base(), '/' ) . '\.xml$';
		// The sitemap stylesheet rule is checked too: without it the styled sitemap view renders as a blank page.
		if ( is_array( $rules ) && isset( $rules[ $key ] ) && isset( $rules['^publishrank-sitemap\.xsl$'] ) ) { return; }
		if ( get_option( 'publishrank_sitemap_rules_healed' ) === $signature ) { return; }
		update_option( 'publishrank_sitemap_rules_healed', $signature, false );
		flush_rewrite_rules( false );
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
