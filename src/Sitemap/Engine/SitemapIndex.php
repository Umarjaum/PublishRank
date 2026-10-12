<?php
namespace PublishRankSEO\Sitemap\Engine;
use PublishRankSEO\Sitemap\SitemapUrls;

defined( 'ABSPATH' ) || exit;

final class SitemapIndex {
	public function render( $providers ) {
		$items = array();
		foreach ( $providers as $provider ) {
			if ( ! $provider->is_enabled() ) {
				continue;
			}
			$count = $provider->get_shard_count();
			for ( $shard = 1; $shard <= $count; $shard++ ) {
				$url = SitemapUrls::shard_url( $provider->get_slug(), $shard );
				$lastmod = method_exists( $provider, 'get_lastmod' ) ? $provider->get_lastmod() : '';
				$items[] = '<sitemap><loc>' . esc_xml( esc_url_raw( $url ) ) . '</loc>' . ( $lastmod ? '<lastmod>' . esc_xml( $lastmod ) . '</lastmod>' : '' ) . '</sitemap>';
			}
		}
		return '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode( '', $items ) . '</sitemapindex>';
	}
}
