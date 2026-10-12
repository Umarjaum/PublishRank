<?php
namespace PublishRankSEO\Sitemap\Engine;

use PublishRankSEO\Sitemap\Providers\SitemapProviderInterface;
use PublishRankSEO\Sitemap\Providers\PostSitemapProvider;
use PublishRankSEO\Sitemap\Providers\PageSitemapProvider;
use PublishRankSEO\Sitemap\Providers\CPTSitemapProvider;
use PublishRankSEO\Sitemap\Providers\TaxonomySitemapProvider;
use PublishRankSEO\Sitemap\Providers\AuthorSitemapProvider;
use PublishRankSEO\Sitemap\Providers\AttachmentSitemapProvider;
use PublishRankSEO\Sitemap\News\NewsSitemapProvider;
use PublishRankSEO\Sitemap\Images\ImageSitemapProvider;
use PublishRankSEO\Sitemap\Video\VideoSitemapProvider;

defined( 'ABSPATH' ) || exit;

final class SitemapEngine {
	private $cache;
	private $lock;
	private $providers = array();
	public function __construct() {
		SitemapCursorStore::register();
		$this->cache = new SitemapCache();
		$this->lock = new SitemapLock();
		$this->providers = array( new PostSitemapProvider(), new PageSitemapProvider(), new CPTSitemapProvider(), new TaxonomySitemapProvider(), new AuthorSitemapProvider(), new AttachmentSitemapProvider(), new NewsSitemapProvider(), new ImageSitemapProvider(), new VideoSitemapProvider() );
		$this->providers = apply_filters( 'publishrank_sitemap_providers', $this->providers );
	}
	public function providers() { return array_filter( $this->providers, function ( $provider ) { return $provider instanceof SitemapProviderInterface; } ); }
	public function provider( $slug ) { foreach ( $this->providers() as $provider ) { if ( $provider->get_slug() === $slug ) { return $provider; } } return null; }
	public function index() { return ( new SitemapIndex() )->render( $this->providers() ); }
	public function render( $slug, $shard = 1 ) {
		$provider = $this->provider( $slug );
		if ( ! $provider || ! $provider->is_enabled() || absint( $shard ) < 1 || absint( $shard ) > $provider->get_shard_count() ) { return ''; }
		$shard = absint( $shard );
		// News entries expire on a rolling publication-time window. Never serve
		// cached or stale XML for this provider, otherwise an expired article can
		// remain discoverable after its timestamp has left the configured window.
		$cached = 'news' === $slug ? false : $this->cache->get( $slug, $shard );
		if ( false !== $cached && '' !== $cached ) { return $cached; }
		if ( method_exists( $provider, 'prepare_cursor' ) && ! SitemapCursorStore::is_ready( $slug, $shard ) ) { SitemapCursorStore::request_preparation(); return null; }
			if ( ! $this->lock->acquire( $slug, $shard ) ) {
				$stale = get_transient( $this->stale_key( $slug, $shard ) );
				return 'news' !== $slug && false !== $stale ? $stale : $provider->render_shard( $shard );
		}
		try {
			$xml = $provider->render_shard( $shard );
			$ready = ! method_exists( $provider, 'prepare_cursor' ) || SitemapCursorStore::is_ready( $slug, $shard );
				if ( $xml && $ready && 'news' !== $slug ) { $this->cache->set( $slug, $shard, $xml ); set_transient( $this->stale_key( $slug, $shard ), $xml, DAY_IN_SECONDS ); }
			return $xml;
		} finally { $this->lock->release( $slug, $shard ); }
	}
	public function clear_cache() { $this->cache->clear(); }
	private function stale_key( $slug, $shard ) { return 'publishrank_sitemap_stale_' . sanitize_key( $slug ) . '_' . absint( $shard ) . '_' . SitemapCursorStore::version( $slug ); }
}
