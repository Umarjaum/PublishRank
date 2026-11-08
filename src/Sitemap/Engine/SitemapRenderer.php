<?php
namespace PublishRankSEO\Sitemap\Engine;

defined( 'ABSPATH' ) || exit;

final class SitemapRenderer {
	/** XML text node: callers pass plain text (decode HTML entities beforehand). Strips invalid XML 1.0 characters and escapes exactly once. */
	public static function text( $value ) {
		$value = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
	public static function urlset( $entries, $namespaces = array() ) {
		$attributes = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		foreach ( $namespaces as $prefix => $uri ) {
			$attributes .= ' xmlns:' . sanitize_key( $prefix ) . '="' . self::text( esc_url_raw( $uri ) ) . '"'; }
		return '<urlset ' . $attributes . '>' . implode( '', $entries ) . '</urlset>';
	}
	/** Last-modified time of a post as W3C UTC, falling back to its publish date when the modified date is missing or invalid (empty string only when the post has no usable date at all). */
	public static function post_lastmod( $post_id ) {
		foreach ( array( array( 'modified', true ), array( 'modified', false ), array( 'time', true ), array( 'time', false ) ) as $try ) {
			$value = 'modified' === $try[0] ? get_post_modified_time( DATE_W3C, $try[1], (int) $post_id ) : get_post_time( DATE_W3C, $try[1], (int) $post_id );
			if ( is_string( $value ) && '' !== $value ) {
				return $value; }
		}
		return '';
	}
	/** Newest modification time of any published post: a last-resort lastmod for archives (authors, terms, community pages) whose own date is unknown, because they change when site content does. */
	public static function site_lastmod() {
		static $value = null;
		if ( null === $value ) {
			$value = '';
			if ( apply_filters( 'publishrank_sitemap_fallback_lastmod', true ) ) {
				$ids = get_posts(
					array(
						'post_type'        => 'any',
						'post_status'      => 'publish',
						'posts_per_page'   => 1,
						'orderby'          => 'modified',
						'order'            => 'DESC',
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => true,
					)
				);
				$value = ! empty( $ids ) ? self::post_lastmod( (int) $ids[0] ) : '';
			}
		}
		return $value;
	}
	public static function url( $loc, $lastmod = '', $children = '' ) {
		$xml = '<url><loc>' . self::text( esc_url_raw( $loc ) ) . '</loc>';
		if ( ! $lastmod ) {
			$lastmod = self::site_lastmod(); }
		if ( $lastmod ) {
			$xml .= '<lastmod>' . self::text( $lastmod ) . '</lastmod>'; }
		return $xml . $children . '</url>';
	}
	public static function image( $url, $title = '', $caption = '', $geo_location = '', $license = '' ) {
		$xml = '<image:image><image:loc>' . esc_xml( esc_url_raw( $url ) ) . '</image:loc>';
		foreach ( array(
			'title'        => $title,
			'caption'      => $caption,
			'geo_location' => $geo_location,
			'license'      => $license,
		) as $tag => $value ) {
			if ( $value ) {
				$xml .= '<image:' . $tag . '>' . esc_xml( wp_strip_all_tags( $value ) ) . '</image:' . $tag . '>'; }
		}
		return $xml . '</image:image>';
	}
}
