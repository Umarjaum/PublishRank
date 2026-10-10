<?php
namespace PublishRankSEO\Sitemap\Engine;

final class SitemapLock {
	const TTL = 30;
	const PREPARATION_TTL = 60;

	public function acquire( $provider, $shard ) {
		$key = 'publishrank_sitemap_lock_' . sanitize_key( $provider ) . '_' . absint( $shard );
		if ( get_transient( $key ) ) {
			return false;
		}
		return (bool) set_transient( $key, 1, self::TTL );
	}

	public function release( $provider, $shard ) {
		delete_transient( 'publishrank_sitemap_lock_' . sanitize_key( $provider ) . '_' . absint( $shard ) );
	}

	public function acquire_provider( $provider ) {
		$key = $this->provider_key( $provider );
		if ( get_transient( $key ) ) { return false; }
		return (bool) set_transient( $key, 1, self::PREPARATION_TTL );
	}

	public function release_provider( $provider ) { delete_transient( $this->provider_key( $provider ) ); }

	private function provider_key( $provider ) { return 'publishrank_sitemap_prepare_lock_' . sanitize_key( $provider ); }
}
