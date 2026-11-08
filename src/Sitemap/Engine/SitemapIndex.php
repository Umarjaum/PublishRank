<?php
namespace PublishRankSEO\Sitemap\Engine;

use PublishRankSEO\Sitemap\SitemapUrls;

defined( 'ABSPATH' ) || exit;

final class SitemapIndex {
	public function render( $providers, $engine = null ) {
		$items = array();
		foreach ( $providers as $provider ) {
			if ( ! $provider->is_enabled() ) {
				continue;
			}
			$shards = $engine ? $engine->listed_shards( $provider ) : range( 1, max( 0, (int) $provider->get_shard_count() ) );
			foreach ( $shards as $shard ) {
				$url     = SitemapUrls::shard_url( $provider->get_slug(), $shard );
				$lastmod = method_exists( $provider, 'get_lastmod' ) ? $provider->get_lastmod() : '';
				$items[] = '<sitemap><loc>' . esc_xml( esc_url_raw( $url ) ) . '</loc>' . ( $lastmod ? '<lastmod>' . esc_xml( $lastmod ) . '</lastmod>' : '' ) . '</sitemap>';
			}
		}
		return '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode( '', $items ) . '</sitemapindex>';
	}
}
