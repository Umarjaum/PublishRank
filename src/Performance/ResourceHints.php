<?php
/** Emits preconnect/dns-prefetch hints for configured infrastructure hosts. */
namespace PublishRankSEO\Performance;

final class ResourceHints {
	public function register() {
		add_filter( 'wp_resource_hints', array( $this, 'hints' ), 10, 2 );
	}

	/** @param array<int,mixed> $urls @param string $relation_type @return array<int,mixed> */
	public function hints( $urls, $relation_type ) {
		if ( ! get_option( 'publishrank_resource_hints', 1 ) ) { return $urls; }
		$known = array();
		foreach ( (array) $urls as $entry ) { $known[ is_array( $entry ) ? ( $entry['href'] ?? '' ) : (string) $entry ] = true; }

		$add = array();
		if ( 'preconnect' === $relation_type ) {
			if ( get_option( 'publishrank_cdn_enabled', 0 ) ) { $add[] = self::normalize( (string) get_option( 'publishrank_cdn_host', '' ) ); }
		} elseif ( 'dns-prefetch' === $relation_type ) {
			foreach ( array( 'publishrank_media_host', 'publishrank_video_host', 'publishrank_api_host' ) as $option ) {
				$add[] = self::normalize( (string) get_option( $option, '' ) );
			}
		}
		foreach ( array_filter( array_unique( $add ) ) as $href ) {
			if ( ! isset( $known[ $href ] ) ) { $urls[] = $href; }
		}
		return $urls;
	}

	/** Accepts a URL or bare host; returns a scheme-qualified origin or ''. */
	private static function normalize( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return ''; }
		if ( ! preg_match( '#^https?://#i', $value ) ) { $value = 'https://' . preg_replace( '#^//#', '', $value ); }
		$parts = wp_parse_url( $value );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host ) { return ''; }
		$origin = 'https://' . $host;
		if ( ! empty( $parts['port'] ) ) { $origin .= ':' . (int) $parts['port']; }
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return $host === $site_host ? '' : $origin;
	}
}