<?php
namespace PublishRankSEO\Sitemap\Engine;

defined( 'ABSPATH' ) || exit;

final class SitemapAudit {
	/** @return array<string,mixed> */
	public static function run( $engine, $preview_limit = 10 ) {
		$report = array( 'status' => 'PASS', 'providers' => 0, 'shards' => 0, 'urls' => 0, 'unique_urls' => 0, 'duplicates' => 0, 'lastmod' => 0, 'errors' => array(), 'preview' => array() );
		$seen_urls = array();
		$index = $engine->index();
		if ( ! self::valid_xml( $index ) || false === strpos( $index, '<sitemapindex' ) ) { $report['errors'][] = __( 'Sitemap index is not valid XML.', 'publishrank' ); }
		foreach ( $engine->providers() as $provider ) {
			if ( ! $provider->is_enabled() ) { continue; }
			$report['providers']++;
			$count = max( 1, (int) $provider->get_shard_count() );
			for ( $shard = 1; $shard <= $count; $shard++ ) {
				$report['shards']++;
				$xml = $engine->render( $provider->get_slug(), $shard );
				if ( ! $xml || ! self::valid_xml( $xml ) || false === strpos( $xml, '<urlset' ) ) { $report['errors'][] = sprintf( __( '%s shard %d is not valid XML.', 'publishrank' ), $provider->get_name(), $shard ); continue; }
								$urls = self::url_values( $xml );
				$report['urls'] += count( $urls );
				$report['lastmod'] += substr_count( $xml, '<lastmod>' );
				$shard_seen = array();
				foreach ( $urls as $url ) {
					$key = self::url_key( $url );
					if ( '' === $key ) { continue; }
					if ( isset( $shard_seen[ $key ] ) ) {
						$report['errors'][] = sprintf( __( '%s shard %d contains a duplicate URL: %s', 'publishrank' ), $provider->get_name(), $shard, $url );
						continue;
					}
					$shard_seen[ $key ] = true;
					if ( isset( $seen_urls[ $key ] ) ) {
						$report['duplicates']++;
						continue;
					}
					$seen_urls[ $key ] = true;
					$report['unique_urls']++;
					if ( count( $report['preview'] ) < max( 0, absint( $preview_limit ) ) ) { $report['preview'][] = $url; }
				}
			}
		}
		if ( $report['errors'] ) { $report['status'] = 'ERROR'; }
		elseif ( $report['urls'] && $report['lastmod'] < $report['urls'] ) { $report['status'] = 'WARNING'; $report['errors'][] = __( 'Some sitemap URLs do not include lastmod values.', 'publishrank' ); }
		return $report;
	}

	private static function valid_xml( $xml ) {
		if ( ! is_string( $xml ) || '' === trim( $xml ) ) { return false; }
		if ( function_exists( 'simplexml_load_string' ) ) { libxml_use_internal_errors( true ); $parsed = simplexml_load_string( $xml ); libxml_clear_errors(); return false !== $parsed; }
		return 1 === preg_match( '/^<[^>]+>.*<\/[^>]+>$/s', trim( $xml ) );
	}

	private static function url_key( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url ) { return ''; }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return $url; }
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '/' !== $path ) { $path = rtrim( $path, '/' ); }
		$query  = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
		return $scheme . '://' . $host . $port . $path . $query;
	}

	private static function url_values( $xml ) {
		$values = array();
		if ( preg_match_all( '/<loc>(.*?)<\/loc>/s', $xml, $matches ) ) { foreach ( $matches[1] as $value ) { $values[] = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ); } }
		return $values;
	}
}
