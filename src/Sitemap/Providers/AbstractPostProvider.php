<?php
namespace PublishRankSEO\Sitemap\Providers;

use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;

defined( 'ABSPATH' ) || exit;

abstract class AbstractPostProvider implements SitemapProviderInterface {
	const SHARD_SIZE      = 50000;
	protected $post_types = array();
	/** Post statuses listed in the sitemap (integrations may add public custom statuses such as bbPress "closed"). */
	protected $post_statuses = array( 'publish' );
	/** URLs per file. Sitemap protocol maximum is 50,000 URLs and 50 MB uncompressed; media providers use smaller files because each URL carries image/video blocks. */
	protected function shard_size() {
		return self::SHARD_SIZE; }
	protected $slug = 'post';
	protected $name = 'Posts';
	public function get_name() {
		return $this->name; }
	public function get_slug() {
		return $this->slug; }
	public function is_enabled() {
		return true; }
	/** Persistent count cache option for a provider slug. */
	public static function total_option( $slug ) {
		return 'publishrank_sitemap_total_' . sanitize_key( $slug );
	}

	/**
	 * Number of listable candidates.
	 *
	 * Large-site safe: the COUNT is stored per content version, so sitemap requests never repeat it. After content
	 * changes, the previous count keeps answering (for at most 6 hours) while a background job recounts, so a busy
	 * newsroom publishing every minute never makes visitors or crawlers wait for a full-table count.
	 */
	public function candidate_total() {
		static $cache = array();
		$slug = $this->get_slug();
		$ver  = SitemapCursorStore::version( $slug ) . '.' . SitemapCursorStore::epoch( $slug );
		$key  = $slug . '|' . $ver;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$opt = get_option( self::total_option( $slug ) );
		if ( is_array( $opt ) && isset( $opt['v'], $opt['n'] ) ) {
			if ( (string) $opt['v'] === $ver ) {
				return $cache[ $key ] = (int) $opt['n'];
			}
			if ( time() - (int) $opt['t'] < 6 * HOUR_IN_SECONDS && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				if ( ! wp_next_scheduled( 'publishrank_sitemap_recount', array( $slug ) ) ) {
					wp_schedule_single_event( time() + 30, 'publishrank_sitemap_recount', array( $slug ) );
				}
				return $cache[ $key ] = (int) $opt['n'];
			}
		}
		return $cache[ $key ] = $this->count_now();
	}

	/** Run the COUNT and store it for the current content version. */
	public function count_now() {
		$args                   = $this->query_args();
		$args['posts_per_page'] = 1;
		$args['no_found_rows']  = false;
		$args['fields']         = 'ids';
		$args['orderby']        = 'none';
		$n                      = (int) ( new \WP_Query( $args ) )->found_posts;
		update_option( self::total_option( $this->get_slug() ), array( 'v' => SitemapCursorStore::version( $this->get_slug() ) . '.' . SitemapCursorStore::epoch( $this->get_slug() ), 'n' => $n, 't' => time() ), false );
		return $n;
	}

	public function get_shard_count() {
		return max( 1, (int) ceil( $this->candidate_total() / $this->shard_size() ) );
	}
	/**
	 * Optional SQL condition (on {$wpdb->posts}) that narrows the candidate set in the database, so media sitemaps
	 * are dense: every shard is full and none is listed empty. Internal constant SQL only, never user input.
	 */
	protected function candidate_where() {
		return ''; }
	/** Bulk-load whatever extra() needs for a chunk of IDs (avoids one query per URL). */
	protected function prefetch( array $ids ) {
	}
	/** Attach the candidate filter to every WP_Query that carries the internal query var. */
	public static function ensure_candidate_filter() {
		static $done = false;
		if ( $done ) {
			return; }
		$done = true;
		add_filter(
			'posts_where',
			static function ( $where, $query ) {
				$extra = $query instanceof \WP_Query ? (string) $query->get( 'publishrank_sitemap_where' ) : '';
				return '' !== $extra ? $where . ' AND ' . $extra : $where;
			},
			10,
			2
		);
	}
	/** Newest modification among the URLs of one shard (exact per-file lastmod for the index). */
	public function shard_lastmod( $shard ) {
		global $wpdb;
		$bounds = SitemapCursorStore::bounds( $this->get_slug(), $shard );
		if ( null === $bounds ) {
			return $this->get_lastmod(); }
		$types    = array_map( 'sanitize_key', (array) $this->post_types );
		$statuses = array_map( 'sanitize_key', (array) $this->post_statuses );
		if ( ! $types || ! $statuses ) {
			return ''; }
		$sql  = "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ") AND post_password = '' AND ID > %d";
		$args = array_merge( $types, $statuses, array( (int) $bounds[0] ) );
		if ( null !== $bounds[1] ) {
			$sql   .= ' AND ID <= %d';
			$args[] = (int) $bounds[1];
		}
		$value = $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		return $value && '0000-00-00 00:00:00' !== $value ? gmdate( DATE_W3C, strtotime( $value . ' UTC' ) ) : $this->get_lastmod();
	}
	public function get_lastmod() {
		$query = new \WP_Query(
			array(
				'post_type'      => $this->post_types,
				'post_status'    => $this->post_statuses,
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		return ! empty( $query->posts ) ? get_post_modified_time( DATE_W3C, true, (int) $query->posts[0] ) : '';
	}
	public function prepare_cursor( $steps = 2 ) {
		SitemapCursorStore::prepare_posts( $this->get_slug(), $this->query_args(), $this->shard_size(), $steps ); }
	public function render_shard( $shard ) {
		$post_ids = SitemapCursorStore::post_page( $this->get_slug(), $this->query_args(), $shard, $this->shard_size() );
		$entries  = array();
		if ( null === $post_ids ) {
			return SitemapRenderer::urlset( $entries, $this->namespaces() ); }
		// Prime post rows + meta in chunks (one query per 500 IDs instead of several per URL) and keep memory flat.
		foreach ( array_chunk( $post_ids, 500 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
			$this->prefetch( $chunk );
			$entries = array_merge( $entries, $this->entries( $chunk ) );
			if ( function_exists( 'wp_cache_flush_runtime' ) && ! wp_using_ext_object_cache() ) {
				wp_cache_flush_runtime();
			}
		}
		return SitemapRenderer::urlset( $entries, $this->all_namespaces() );
	}
	/** @return string[] <url> entries for already-primed IDs. */
	private function entries( array $post_ids ) {
		$entries = array();
		foreach ( $post_ids as $post_id ) {
			if ( ! $this->is_indexable( $post_id ) ) {
				continue; }
			$url = get_permalink( $post_id );
			if ( ! $url || $this->is_bad_url( $url ) ) {
				continue; }
			$extra = $this->extra( $post_id );
			if ( $this->requires_extra() && '' === trim( (string) $extra ) ) {
				continue; }
			if ( ! $this->requires_extra() ) {
				$extra .= \PublishRankSEO\Meta\LanguageAlternates::sitemap_links( $post_id ); }
			$lastmod  = SitemapRenderer::post_lastmod( $post_id );
			$entries[] = SitemapRenderer::url( $url, $lastmod, $extra );
		}
		return $entries;
	}
	protected function query_args() {
		$args  = array(
			'post_type'           => $this->post_types,
			'post_status'         => $this->post_statuses,
			'has_password'        => false, // Password-protected content is never listed.
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
			'meta_query'          => $this->robots_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		$where = $this->candidate_where();
		if ( '' !== $where ) {
			self::ensure_candidate_filter();
			$args['publishrank_sitemap_where'] = $where;
		}
		return $args; }
	protected function robots_query() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => '_publishrank_seo_robots',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_publishrank_seo_robots',
				'value'   => 'noindex',
				'compare' => 'NOT LIKE',
			),
		); }
	protected function is_indexable( $post_id ) {
		$robots = strtolower( (string) get_post_meta( $post_id, '_publishrank_seo_robots', true ) );
		$post   = get_post( $post_id );
		return $post && '' === (string) $post->post_password && in_array( $post->post_status, (array) $this->post_statuses, true ) && false === strpos( $robots, 'noindex' ) && ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ); }
	protected function is_bad_url( $url ) {
		$parsed = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		return empty( $parsed['host'] ) || empty( $home['host'] ) || strtolower( $parsed['host'] ) !== strtolower( $home['host'] ); }
	protected function extra( $post_id ) {
		return ''; }
	protected function requires_extra() {
		return false; }
	/** Resolve the media sitemap content-type setting; 'all' means every public, queryable non-attachment type. */
	protected function media_post_types() {
		$raw = trim( (string) get_option( 'publishrank_seo_media_post_types', 'all' ) );
		if ( '' === $raw || 'all' === strtolower( $raw ) ) {
			// 'page' is public but not publicly_queryable; it must still be covered by image/video sitemaps.
			$types = get_post_types( array( 'public' => true ), 'names' );
			return array_values( array_diff( (array) $types, array( 'attachment', 'revision', 'nav_menu_item' ) ) );
		}
		$types = array();
		foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $type ) {
			$type   = sanitize_key( $type );
			$object = get_post_type_object( $type );
			if ( $object && $object->public && 'attachment' !== $type ) {
				$types[] = $type; }
		}
		return array_values( array_unique( $types ? $types : array( 'post', 'page' ) ) );
	}
	protected function namespaces() {
		return array(); }
	/** Provider namespaces plus xhtml when translations may be listed. */
	private function all_namespaces() {
		$ns = $this->namespaces();
		if ( ! $this->requires_extra() && \PublishRankSEO\Meta\LanguageAlternates::has_multilingual_plugin() ) {
			$ns['xhtml'] = 'http://www.w3.org/1999/xhtml'; }
		return $ns;
	}
}
