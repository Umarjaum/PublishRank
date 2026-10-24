<?php
namespace PublishRankSEO\Sitemap\Engine;

defined( 'ABSPATH' ) || exit;

final class SitemapCache {
	const TTL = 3600;

	public function key( $provider, $shard ) {
		return 'publishrank_sitemap_' . sanitize_key( $provider ) . '_' . absint( $shard ) . '_' . SitemapCursorStore::version( $provider ); }

	public function get( $provider, $shard ) {
		return get_transient( $this->key( $provider, $shard ) );
	}

	public function set( $provider, $shard, $xml ) {
		return set_transient( $this->key( $provider, $shard ), $xml, self::TTL );
	}

	public function clear( $provider = '' ) {
		$providers = $provider ? array( $provider ) : array( 'post', 'page', 'cpt', 'taxonomy', 'author', 'attachment', 'news', 'image', 'video' );
		SitemapCursorStore::bump_many( $providers );
		update_option( 'publishrank_seo_last_invalidation', time(), false );
	}
}
