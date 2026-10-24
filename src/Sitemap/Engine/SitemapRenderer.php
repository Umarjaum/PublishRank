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
	public static function url( $loc, $lastmod = '', $children = '' ) {
		$xml = '<url><loc>' . self::text( esc_url_raw( $loc ) ) . '</loc>';
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
