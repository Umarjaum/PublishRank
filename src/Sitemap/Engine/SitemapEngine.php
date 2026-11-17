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
	private $providers = null;
	public function __construct() {
		SitemapCursorStore::register();
		if ( ! has_action( 'publishrank_sitemap_recount' ) ) {
			add_action(
				'publishrank_sitemap_recount',
				function ( $slug ) {
					$provider = $this->provider( sanitize_key( (string) $slug ) );
					if ( $provider && method_exists( $provider, 'count_now' ) ) {
						$provider->count_now();
					}
				}
			);
		}
		$this->cache     = new SitemapCache();
		$this->lock      = new SitemapLock();
		$this->providers = null;
	}
	/**
	 * Providers are resolved on first use, not in the constructor: the engine is built while modules are still
	 * loading, so integrations registered later (bbPress, podcasts, Web Stories, WooCommerce) would otherwise
	 * miss the publishrank_sitemap_providers filter and their sitemaps would answer 404.
	 */
	public function providers() {
		if ( null === $this->providers ) {
			$this->providers = array( new PostSitemapProvider(), new PageSitemapProvider(), new CPTSitemapProvider(), new TaxonomySitemapProvider(), new AuthorSitemapProvider(), new AttachmentSitemapProvider(), new NewsSitemapProvider(), new ImageSitemapProvider(), new VideoSitemapProvider() );
			$this->providers = (array) apply_filters( 'publishrank_sitemap_providers', $this->providers );
		}
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
	/** Index XML, cached until any provider's version changes (content/settings change) or 5 minutes pass. */
	public function index() {
		$versions = array();
		foreach ( $this->providers() as $provider ) {
			$versions[] = $provider->get_slug() . ':' . SitemapCursorStore::version( $provider->get_slug() ) . ':' . SitemapCursorStore::gens_hash( $provider->get_slug() ) . ':' . ( $provider->is_enabled() ? 1 : 0 );
		}
		$key    = 'publishrank_sitemap_index_' . md5( implode( '|', $versions ) . '|' . gmdate( 'YmdH' ) );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached; }
		$xml = ( new SitemapIndex() )->render( $this->providers(), $this );
		set_transient( $key, $xml, 5 * MINUTE_IN_SECONDS );
		return $xml;
	}
	/** True when the XML holds at least one <url> entry. The sitemap protocol requires one, so an empty <urlset> is rejected by Google Search Console ("Missing XML tag: url"). */
	public static function has_urls( $xml ) {
		return is_string( $xml ) && false !== strpos( $xml, '<url>' ); }
	/**
	 * Shard numbers worth publishing for a provider. A sitemap file with no URLs is invalid, so an empty provider
	 * (no forums yet, no web stories) is left out of the index. The News sitemap is the exception: it is always listed. Only the first file can be empty:
	 * a provider with several files has more than one file of URLs by definition.
	 *
	 * @return int[]
	 */
	public function listed_shards( $provider ) {
		$count = max( 0, (int) $provider->get_shard_count() );
		if ( $count > 1 ) {
			return range( 1, $count ); }
		// The News sitemap is always listed, even with no recent articles.
		if ( 1 === $count && ( 'news' === $provider->get_slug() || $this->has_content( $provider ) ) ) {
			return array( 1 ); }
		return array();
	}
	/** Cheap "does this single-file provider list anything?" check (a count query instead of rendering the file). */
	private function has_content( $provider ) {
		if ( method_exists( $provider, 'candidate_total' ) ) {
			return $provider->candidate_total() > 0; }
		if ( method_exists( $provider, 'author_total' ) ) {
			return $provider->author_total() > 0; }
		return '' !== $this->render( $provider->get_slug(), 1 );
	}
	/** Public entry: an enabled provider with no URLs answers 404 instead of serving an invalid empty <urlset>. */
	public function render( $slug, $shard = 1 ) {
		$xml = $this->render_raw( $slug, $shard );
		return ( is_string( $xml ) && '' !== $xml && ! self::has_urls( $xml ) ) ? '' : $xml;
	}
	/**
	 * Response for the public sitemap URL itself. An enabled provider that has no URLs yet never answers 404 and never
	 * serves an empty <urlset> (Search Console rejects that as "Missing XML tag: url"): it serves a valid sitemap holding
	 * the home page as its single entry. Empty files stay out of the sitemap index (see listed_shards), so the
	 * placeholder is only ever seen when the file is opened or submitted directly.
	 * Unknown or disabled providers and shard numbers beyond the last file are still a genuine 404 ('').
	 * The News sitemap is the exception: Google News requires news tags on every entry, so a placeholder would be
	 * invalid there; it answers with an empty <urlset> and a 200.
	 *
	 * @return string|false|null XML, '' for 404, false on failure, null while a cursor is preparing.
	 */
	public function render_public( $slug, $shard = 1 ) {
		$xml = $this->render( $slug, $shard );
		if ( '' !== $xml ) {
			return $xml; }
		$provider = $this->provider( $slug );
		if ( ! $provider || ! $provider->is_enabled() || 1 !== absint( $shard ) ) {
			return ''; }
		if ( 'news' === $slug ) {
			return '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><!-- No recent news articles. --></urlset>'; }
		return SitemapRenderer::urlset( array( SitemapRenderer::url( home_url( '/' ) ) ) );
	}
	private function render_raw( $slug, $shard = 1 ) {
		$provider = $this->provider( $slug );
		if ( ! $provider || ! $provider->is_enabled() || absint( $shard ) < 1 || absint( $shard ) > $provider->get_shard_count() ) {
			return ''; }
		$shard = absint( $shard );
		if ( method_exists( $provider, 'prepare_cursor' ) ) {
			SitemapCursorStore::verify( $slug, $provider->get_shard_count() ); }
		// News entries expire on a rolling publication-time window. Never serve
		// cached or stale XML for this provider, otherwise an expired article can
		// remain discoverable after its timestamp has left the configured window.
		$cached = 'news' === $slug ? false : $this->cache->get( $slug, $shard );
		if ( false !== $cached && '' !== $cached ) {
			return $cached; }
		if ( method_exists( $provider, 'prepare_cursor' ) && ! SitemapCursorStore::is_ready( $slug, $shard ) ) {
			// Do not depend on WP-Cron: advance the cursor a bounded number of steps inline first.
			$this->prepare_inline( $provider, $slug, $shard );
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
	private function prepare_inline( $provider, $slug, $shard = 1 ) {
		if ( ! $this->lock->acquire_provider( $slug ) ) {
			return; }
		// Advance in small steps until the requested shard is ready or the time budget is spent (default 6 s),
		// so a host without WP-Cron still serves the file on the first request instead of a 503 in most cases.
		$budget = (float) apply_filters( 'publishrank_sitemap_inline_budget', 6.0, $slug );
		$start  = microtime( true );
		try {
			do {
				$provider->prepare_cursor( SitemapCursorStore::INLINE_STEPS );
			} while ( ! SitemapCursorStore::is_ready( $slug, $shard ) && ! SitemapCursorStore::is_complete( $slug ) && ( microtime( true ) - $start ) < $budget );
		} finally {
			$this->lock->release_provider( $slug ); }
	}
	public function clear_cache() {
		$this->cache->clear(); }
	private function stale_key( $slug, $shard ) {
		return 'publishrank_sitemap_stale_' . sanitize_key( $slug ) . '_' . absint( $shard ) . '_' . SitemapCursorStore::version( $slug ); } // Stale copy survives per-file refreshes on purpose.
}
