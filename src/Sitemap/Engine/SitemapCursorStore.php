<?php
namespace PublishRankSEO\Sitemap\Engine;

/**
 * Persistent keyset checkpoints for large sitemap providers.
 *
 * One small option is stored per checkpoint; no request walks preceding shards
 * and no complete boundary array is kept in a single autoloaded option.
 */
final class SitemapCursorStore {
	const PREPARE_HOOK = 'publishrank_prepare_sitemap_cursors';
	const CONTINUE_HOOK = 'publishrank_continue_sitemap_cursor_preparation';
	const MAX_SYNC_STEPS = 1;
	const CRON_STEPS = 2;
	const CONTINUE_DELAY = 30;

	public static function register() {
		add_action( self::PREPARE_HOOK, array( __CLASS__, 'prepare_registered_providers' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'prepare_registered_providers' ) );
		if ( ! wp_next_scheduled( self::PREPARE_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::PREPARE_HOOK );
		}
	}

	public static function unregister() {
		wp_clear_scheduled_hook( self::PREPARE_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	public static function version( $provider ) { return max( 1, (int) get_option( self::version_key( $provider ), 1 ) ); }
	public static function bump( $provider ) { update_option( self::version_key( $provider ), self::version( $provider ) + 1, false ); }
	public static function bump_many( $providers ) { foreach ( array_unique( array_map( 'sanitize_key', (array) $providers ) ) as $provider ) { if ( $provider ) { self::bump( $provider ); } } }

	public static function post_page( $provider, $args, $shard, $size ) {
		$cursor = self::checkpoint( $provider, $shard );
		if ( null === $cursor ) { self::queue(); return null; }
		return self::query_posts( $args, $size, $cursor );
	}
	public static function user_page( $provider, $args, $shard, $size ) {
		$cursor = self::checkpoint( $provider, $shard );
		if ( null === $cursor ) { self::queue(); return null; }
		return self::query_users( $args, $size, $cursor );
	}
	public static function term_page( $provider, $args, $shard, $size ) {
		$cursor = self::checkpoint( $provider, $shard );
		if ( null === $cursor ) { self::queue(); return null; }
		return self::query_terms( $args, $size, $cursor );
	}
	public static function is_ready( $provider, $shard ) { return null !== self::checkpoint( $provider, $shard ); }
	public static function request_preparation() { self::queue(); }
	public static function is_complete( $provider ) { return (bool) get_option( self::meta_key( $provider, 'complete' ), 0 ); }

	public static function prepare_posts( $provider, $args, $size, $steps = self::CRON_STEPS ) { return self::prepare( $provider, $args, $size, 'posts', $steps ); }
	public static function prepare_users( $provider, $args, $size, $steps = self::CRON_STEPS ) { return self::prepare( $provider, $args, $size, 'users', $steps ); }
	public static function prepare_terms( $provider, $args, $size, $steps = self::CRON_STEPS ) { return self::prepare( $provider, $args, $size, 'terms', $steps ); }

	private static function prepare( $provider, $args, $size, $kind, $steps ) {
		$prepared = (int) get_option( self::meta_key( $provider, 'prepared' ), 1 );
		if ( self::is_complete( $provider ) ) { return $prepared; }
		self::checkpoint( $provider, 1 );
		for ( $i = 0; $i < max( 1, (int) $steps ); $i++ ) {
			$cursor = (int) self::checkpoint( $provider, $prepared );
			$rows = 'posts' === $kind ? self::query_posts( $args, $size, $cursor ) : ( 'users' === $kind ? self::query_users( $args, $size, $cursor ) : self::query_terms( $args, $size, $cursor ) );
			if ( count( $rows ) < $size ) { update_option( self::meta_key( $provider, 'complete' ), 1, false ); break; }
			$last = end( $rows );
			$next = 'posts' === $kind ? (int) $last : ( 'users' === $kind ? (int) $last->ID : (int) $last->term_id );
			++$prepared;
			update_option( self::checkpoint_key( $provider, $prepared ), $next, false );
			update_option( self::meta_key( $provider, 'prepared' ), $prepared, false );
		}
		return $prepared;
	}

	private static function checkpoint( $provider, $shard ) {
		$shard = max( 1, absint( $shard ) );
		if ( 1 === $shard ) {
			$key = self::checkpoint_key( $provider, 1 );
			if ( false === get_option( $key, false ) ) { add_option( $key, 0, '', false ); }
			return 0;
		}
		$value = get_option( self::checkpoint_key( $provider, $shard ), false );
		return false === $value ? null : absint( $value );
	}
	private static function queue() {
		self::queue_continuation();
	}
	private static function queue_continuation() {
		// The hourly event is separate; it must not suppress this short follow-up.
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) { wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::CONTINUE_HOOK ); }
	}
	public static function prepare_registered_providers() {
		$engine = new SitemapEngine();
		$lock = new SitemapLock();
		$needs_continuation = false;
		foreach ( $engine->providers() as $provider ) {
			if ( ! method_exists( $provider, 'prepare_cursor' ) ) { continue; }
			$slug = $provider->get_slug();
			if ( self::is_complete( $slug ) ) { continue; }
			if ( ! $lock->acquire_provider( $slug ) ) {
				$needs_continuation = true;
				continue;
			}
			try {
				$provider->prepare_cursor( self::CRON_STEPS );
			} finally {
				$lock->release_provider( $slug );
			}
			if ( ! self::is_complete( $slug ) ) { $needs_continuation = true; }
		}
		if ( $needs_continuation ) { self::queue_continuation(); }
	}
	private static function version_key( $provider ) { return 'publishrank_sitemap_version_' . sanitize_key( $provider ); }
	private static function meta_key( $provider, $name ) { return 'publishrank_sitemap_cursor_' . sanitize_key( $provider ) . '_' . self::version( $provider ) . '_' . sanitize_key( $name ); }
	private static function checkpoint_key( $provider, $shard ) { return 'publishrank_sitemap_checkpoint_' . sanitize_key( $provider ) . '_' . self::version( $provider ) . '_' . max( 1, absint( $shard ) ); }

	private static function query_posts( $args, $size, $after ) {
		$where = static function ( $sql ) use ( $after ) { global $wpdb; return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after ); };
		add_filter( 'posts_where', $where, 10, 1 );
		$args['posts_per_page'] = $size; $args['orderby'] = 'ID'; $args['order'] = 'ASC'; $args['no_found_rows'] = true; $args['fields'] = 'ids';
		$rows = ( new \WP_Query( $args ) )->posts; remove_filter( 'posts_where', $where, 10 ); return array_map( 'absint', (array) $rows );
	}
	private static function query_users( $args, $size, $after ) {
		$where = static function ( $query ) use ( $after ) { global $wpdb; $query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d", $after ); };
		add_action( 'pre_user_query', $where, 10, 1 ); $args['number'] = $size; $args['orderby'] = 'ID'; $args['order'] = 'ASC';
		$rows = get_users( $args ); remove_action( 'pre_user_query', $where, 10 ); return (array) $rows;
	}
	private static function query_terms( $args, $size, $after ) {
		$where = static function ( $clauses ) use ( $after ) { global $wpdb; $clauses['where'] .= $wpdb->prepare( " AND {$wpdb->terms}.term_id > %d", $after ); return $clauses; };
		add_filter( 'terms_clauses', $where, 10, 3 ); $args['number'] = $size; $args['orderby'] = 'term_id'; $args['order'] = 'ASC';
		$rows = get_terms( $args ); remove_filter( 'terms_clauses', $where, 10 ); return is_wp_error( $rows ) ? array() : (array) $rows;
	}
}
