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
	/** Sitemap protocol: 50 MB uncompressed per file. */
	const MAX_BYTES    = 52428800;
	const ERROR_OPTION = 'publishrank_seo_sitemap_last_error';
	/** Store a sanitized failure (no paths, no stack trace) for the admin diagnostics screen. */
	public static function record_error( $slug, $message ) {
		$message = trim( preg_replace( '#(?:[A-Za-z]:)?[\\/][^\s:]+#', '[path]', wp_strip_all_tags( (string) $message ) ) );
		update_option(
			self::ERROR_OPTION,
			array(
				'provider' => sanitize_key( $slug ),
				'message'  => substr( $message, 0, 300 ),
				'time'     => time(),
			),
			false
		);
	}
	private $cache;
	private $lock;
	private $providers = array();
	public function __construct() {
		SitemapCursorStore::register();
		$this->cache     = new SitemapCache();
		$this->lock      = new SitemapLock();
		$this->providers = array( new PostSitemapProvider(), new PageSitemapProvider(), new CPTSitemapProvider(), new TaxonomySitemapProvider(), new AuthorSitemapProvider(), new AttachmentSitemapProvider(), new NewsSitemapProvider(), new ImageSitemapProvider(), new VideoSitemapProvider() );
		$this->providers = apply_filters( 'publishrank_sitemap_providers', $this->providers );
	}
	public function providers() {
		return array_filter(
			$this->providers,
			function ( $provider ) {
				return $provider instanceof SitemapProviderInterface;
			}
		); }
	public function provider( $slug ) {
		foreach ( $this->providers() as $provider ) {
			if ( $provider->get_slug() === $slug ) {
				return $provider;
			}
		} return null; }
	public function index() {
		return ( new SitemapIndex() )->render( $this->providers() ); }
	public function render( $slug, $shard = 1 ) {
		$provider = $this->provider( $slug );
		if ( ! $provider || ! $provider->is_enabled() || absint( $shard ) < 1 || absint( $shard ) > $provider->get_shard_count() ) {
			return ''; }
		$shard = absint( $shard );
		// News entries expire on a rolling publication-time window. Never serve
		// cached or stale XML for this provider, otherwise an expired article can
		// remain discoverable after its timestamp has left the configured window.
		$cached = 'news' === $slug ? false : $this->cache->get( $slug, $shard );
		if ( false !== $cached && '' !== $cached ) {
			return $cached; }
		if ( method_exists( $provider, 'prepare_cursor' ) && ! SitemapCursorStore::is_ready( $slug, $shard ) ) {
			// Do not depend on WP-Cron: advance the cursor a bounded number of steps inline first.
			$this->prepare_inline( $provider, $slug );
			if ( ! SitemapCursorStore::is_ready( $slug, $shard ) ) {
				SitemapCursorStore::request_preparation();
				return null; }
		}
		// The News provider has no shared state, so it needs no render lock.
		$locked = 'news' !== $slug;
		if ( $locked && ! $this->lock->acquire( $slug, $shard ) ) {
			$stale = get_transient( $this->stale_key( $slug, $shard ) );
			return false !== $stale ? $stale : $provider->render_shard( $shard );
		}
		try {
			try {
				$xml = $provider->render_shard( $shard );
			} catch ( \Throwable $e ) {
				self::record_error( $slug, $e->getMessage() );
				return false;
			}
			if ( is_string( $xml ) && strlen( $xml ) > self::MAX_BYTES ) {
				self::record_error( $slug, sprintf( 'Shard %d exceeds the 50 MB uncompressed sitemap limit; lower the provider shard size.', $shard ) );
				return false;
			}
			$ready = ! method_exists( $provider, 'prepare_cursor' ) || SitemapCursorStore::is_ready( $slug, $shard );
			if ( $xml && $ready && 'news' !== $slug ) {
				$this->cache->set( $slug, $shard, $xml );
				set_transient( $this->stale_key( $slug, $shard ), $xml, DAY_IN_SECONDS ); }
			return $xml;
		} finally {
			if ( $locked ) {
				$this->lock->release( $slug, $shard ); }
		}
	}
	/** Bounded synchronous cursor progress, so a missing or disabled WP-Cron cannot leave a shard unavailable. */
	private function prepare_inline( $provider, $slug ) {
		if ( ! $this->lock->acquire_provider( $slug ) ) {
			return; }
		try {
			$provider->prepare_cursor( SitemapCursorStore::INLINE_STEPS );
		} finally {
			$this->lock->release_provider( $slug ); }
	}
	public function clear_cache() {
		$this->cache->clear(); }
	private function stale_key( $slug, $shard ) {
		return 'publishrank_sitemap_stale_' . sanitize_key( $slug ) . '_' . absint( $shard ) . '_' . SitemapCursorStore::version( $slug ); }
}
