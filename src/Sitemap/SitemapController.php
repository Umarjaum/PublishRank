<?php
namespace PublishRankSEO\Sitemap;

use PublishRankSEO\Sitemap\Engine\SitemapEngine;

defined( 'ABSPATH' ) || exit;

final class SitemapController {
	private $engine;
	public function __construct() {
		$this->engine = new SitemapEngine(); }
	public function register() {
		// Master switch (Features screen). When off, WordPress core sitemaps stay available.
		if ( ! \PublishRankSEO\Core\ModuleSwitches::sitemap_enabled() ) {
			return;
		}
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
		if ( ! is_object( $wp ) || ! empty( $wp->query_vars['publishrank_sitemap'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return; }
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) ); }
		$path = ltrim( rawurldecode( $path ), '/' );
		if ( '.xml' !== substr( $path, -4 ) ) {
			return; }
		$name = substr( $path, 0, -4 );
		if ( ( in_array( $name, array( 'sitemap', 'sitemap_index' ), true ) || preg_match( '#^wp-sitemap(?:-[a-z0-9-]+)?$#', $name ) ) && ! self::other_sitemap_owner() ) {
			wp_safe_redirect( SitemapUrls::index_url(), 301, 'PublishRank' );
			exit;
		}
		if ( in_array( $name, array_merge( array( SitemapUrls::base() ), SitemapUrls::aliases() ), true ) ) {
			$wp->query_vars['publishrank_sitemap'] = 'index';
			return;
		}
		$prefix = preg_quote( SitemapUrls::shard_prefix(), '#' );
		if ( preg_match( '#^' . $prefix . '-([a-z-]+)-sitemap-([0-9]+)$#', $name, $m ) ) {
			$wp->query_vars['publishrank_sitemap']       = $m[1];
			$wp->query_vars['publishrank_sitemap_shard'] = $m[2];
		} elseif ( preg_match( '#^' . $prefix . '-([a-z-]+)-sitemap$#', $name, $m ) ) {
			$wp->query_vars['publishrank_sitemap']       = $m[1];
			$wp->query_vars['publishrank_sitemap_shard'] = 1;
		}
	}
	/** Another SEO plugin that serves its own /sitemap.xml or /sitemap_index.xml (reported as a conflict in Sitemap Hub). */
	public static function other_sitemap_owner() {
		$owners = array(
			'Yoast SEO'          => defined( 'WPSEO_VERSION' ),
			'Rank Math'          => class_exists( 'RankMath' ),
			'All in One SEO'     => defined( 'AIOSEO_VERSION' ),
			'SEOPress'           => defined( 'SEOPRESS_VERSION' ),
			'The SEO Framework'  => defined( 'THE_SEO_FRAMEWORK_VERSION' ),
			'Google XML Sitemaps' => class_exists( 'GoogleSitemapGenerator' ),
			'Jetpack Sitemaps'   => class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) && \Jetpack::is_module_active( 'sitemaps' ),
		);
		foreach ( $owners as $label => $active ) {
			if ( $active ) {
				return $label; }
		}
		return '';
	}
	/** Flush rewrite rules once when the sitemap rule is absent from the stored rules. */
	public function heal_rewrite_rules() {
		if ( ! get_option( 'permalink_structure' ) ) {
			return; }
		$signature = md5( 'xsl|' . SitemapUrls::base() . '|' . implode( ',', SitemapUrls::aliases() ) . '|' . SitemapUrls::shard_prefix() );
		$rules     = get_option( 'rewrite_rules' );
		$key       = '^' . preg_quote( SitemapUrls::base(), '/' ) . '\.xml$';
		// The sitemap stylesheet rule is checked too: without it the styled sitemap view renders as a blank page.
		if ( is_array( $rules ) && isset( $rules[ $key ] ) && isset( $rules['^publishrank-sitemap\.xsl$'] ) ) {
			return; }
		if ( get_option( 'publishrank_sitemap_rules_healed' ) === $signature ) {
			return; }
		update_option( 'publishrank_sitemap_rules_healed', $signature, false );
		flush_rewrite_rules( false );
	}
	public function rewrite() {
		add_rewrite_tag( '%publishrank_sitemap%', '([^&]+)' );
		$endpoints = array_merge( array( SitemapUrls::base() ), SitemapUrls::aliases() );
		foreach ( $endpoints as $endpoint ) {
			add_rewrite_rule( '^' . preg_quote( $endpoint, '/' ) . '\.xml$', 'index.php?publishrank_sitemap=index', 'top' ); }
		$prefix = preg_quote( SitemapUrls::shard_prefix(), '/' );
		add_rewrite_rule( '^' . $prefix . '-([a-z-]+)-sitemap-([0-9]+)\.xml$', 'index.php?publishrank_sitemap=$matches[1]&publishrank_sitemap_shard=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $prefix . '-([a-z-]+)-sitemap\.xml$', 'index.php?publishrank_sitemap=$matches[1]&publishrank_sitemap_shard=1', 'top' );
		add_rewrite_tag( '%publishrank_sitemap_shard%', '([0-9]+)' );
	}
	public function render() {
		$slug = sanitize_key( (string) get_query_var( 'publishrank_sitemap' ) );
		if ( ! $slug ) {
			return; }
		// WordPress' canonical redirect must never rewrite a sitemap URL.
		remove_action( 'template_redirect', 'redirect_canonical' );
		$shard_raw = (string) get_query_var( 'publishrank_sitemap_shard', '1' );
		// Shard numbers are 1-based digits only: "0", "-1" or "abc" are a 404, never silently mapped to file 1.
		$xml = 'index' === $slug ? $this->engine->index() : ( ( '' !== $shard_raw && ! ctype_digit( $shard_raw ) ) || ( '' !== $shard_raw && (int) $shard_raw < 1 ) ? '' : $this->engine->render_public( $slug, max( 1, absint( $shard_raw ) ) ) );
		// null: a cursor-backed provider is still preparing its checkpoints (large catalogues only).
		if ( null === $xml ) {
			$this->discard_output();
			status_header( 503 );
			header( 'Retry-After: 60' );
			header( 'X-Robots-Tag: noindex' );
			exit; }
		// false: generation failed; the cause is stored for the admin diagnostics screen.
		if ( false === $xml ) {
			$this->discard_output();
			status_header( 500 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex' );
			echo 'Sitemap generation failed. See PublishRank > Sitemap Hub for details.';
			exit; }
		// '': unknown provider, disabled provider or shard number out of range: a genuine 404. (An enabled but empty sitemap returns an empty urlset, not '').
		if ( '' === $xml ) {
			// A small, fast 404 for crawlers: no theme rendering for a sitemap file that does not exist.
			$this->discard_output();
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex' );
			echo 'Sitemap file not found.';
			exit; }
		// ?raw=1 returns the bare XML (no stylesheet) so people can inspect exactly what crawlers receive.
		$raw   = isset( $_GET['raw'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display switch.
		$style = ( ! $raw && get_option( 'publishrank_sitemap_stylesheet', 1 ) ) ? '<?xml-stylesheet type="text/xsl" href="' . esc_url( SitemapStylesheet::url( $slug, max( 1, absint( get_query_var( 'publishrank_sitemap_shard', 1 ) ) ) ) ) . '"?>' : '';
		$body  = '<?xml version="1.0" encoding="UTF-8"?>' . $style . $xml;
		$this->discard_output();
		self::send( $body, 'news' === $slug ? 60 : 300 );
		exit;
	}

	/**
	 * Conditional, cacheable XML response: strong ETag of the exact bytes, Last-Modified from the newest <lastmod>,
	 * 304 for If-None-Match / If-Modified-Since, gzip when the client accepts it, and a body-less HEAD.
	 */
	public static function send( $body, $max_age = 300 ) {
		$etag = '"' . substr( hash( 'sha256', $body ), 0, 32 ) . '"';
		$last = 0;
		if ( preg_match_all( '#<lastmod>([^<]+)</lastmod>#', $body, $m ) ) {
			foreach ( $m[1] as $value ) {
				$t = strtotime( $value );
				if ( $t && $t > $last ) {
					$last = $t; }
			}
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=' . (int) $max_age . ', stale-while-revalidate=60' );
		header( 'ETag: ' . $etag );
		header( 'Vary: Accept-Encoding' );
		if ( $last ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last ) . ' GMT' ); }
		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tags = array_map( 'trim', explode( ',', str_replace( 'W/', '', $inm ) ) );
		if ( ( '' !== $inm && ( in_array( $etag, $tags, true ) || in_array( '*', $tags, true ) ) ) || ( '' === $inm && $ims && $last && $last <= $ims ) ) {
			status_header( 304 );
			header_remove( 'Content-Type' );
			return;
		}
		$head = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		$enc  = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) ) : '';
		if ( false !== strpos( $enc, 'gzip' ) && function_exists( 'gzencode' ) && ! ini_get( 'zlib.output_compression' ) && ! headers_sent() && apply_filters( 'publishrank_sitemap_gzip', true ) ) {
			$body = gzencode( $body, 6 );
			header( 'Content-Encoding: gzip' );
		}
		header( 'Content-Length: ' . strlen( $body ) );
		if ( ! $head ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built and escaped by the renderer.
		}
	}
	/** Drop buffered output (themes/plugins) so nothing precedes the XML declaration. */
	private function discard_output() {
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); } }
	public function engine() {
		return $this->engine; }
}
