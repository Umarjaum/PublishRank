<?php
namespace PublishRankSEO\Sitemap\Engine;

final class SitemapRenderer {
	public static function urlset( $entries, $namespaces = array() ) {
		$attributes = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		foreach ( $namespaces as $prefix => $uri ) { $attributes .= ' xmlns:' . sanitize_key( $prefix ) . '="' . esc_xml( esc_url_raw( $uri ) ) . '"'; }
		return '<urlset ' . $attributes . '>' . implode( '', $entries ) . '</urlset>';
	}
	public static function url( $loc, $lastmod = '', $children = '' ) {
		$xml = '<url><loc>' . esc_xml( esc_url_raw( $loc ) ) . '</loc>';
		if ( $lastmod ) { $xml .= '<lastmod>' . esc_xml( $lastmod ) . '</lastmod>'; }
		return $xml . $children . '</url>';
	}
	public static function image( $url, $title = '', $caption = '', $geo_location = '', $license = '' ) {
		$xml = '<image:image><image:loc>' . esc_xml( esc_url_raw( $url ) ) . '</image:loc>';
		foreach ( array( 'title' => $title, 'caption' => $caption, 'geo_location' => $geo_location, 'license' => $license ) as $tag => $value ) {
			if ( $value ) { $xml .= '<image:' . $tag . '>' . esc_xml( wp_strip_all_tags( $value ) ) . '</image:' . $tag . '>'; }
		}
		return $xml . '</image:image>';
	}
}
