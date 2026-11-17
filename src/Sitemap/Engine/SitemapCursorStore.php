<?php
namespace PublishRankSEO\Sitemap\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Persistent keyset checkpoints for large sitemap providers.
 *
 * One small option is stored per checkpoint; no request walks preceding shards
 * and no complete boundary array is kept in a single autoloaded option.
 */
final class SitemapCursorStore {
	const PREPARE_HOOK   = 'publishrank_prepare_sitemap_cursors';
	const CONTINUE_HOOK  = 'publishrank_continue_sitemap_cursor_preparation';
	const MAX_SYNC_STEPS = 1;
	const CRON_STEPS     = 2;
	const CONTINUE_DELAY = 30;
	/** Cursor steps advanced inline when a shard is requested before its checkpoint exists. */
	const INLINE_STEPS = 3;
	/** A cursor that has not advanced for this long is discarded and rebuilt. */
	const STALE_AFTER = DAY_IN_SECONDS;

	public static function register() {
		add_action( self::PREPARE_HOOK, array( __CLASS__, 'prepare_registered_providers' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'prepare_registered_providers' ) );
		if ( ! wp_next_scheduled( self::PREPARE_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::PREPARE_HOOK );
		}
		// A follow-up event left far in the past means WP-Cron is not running: drop it so it can be re-queued.
		$next = wp_next_scheduled( self::CONTINUE_HOOK );
		if ( $next && $next < time() - HOUR_IN_SECONDS ) {
			wp_clear_scheduled_hook( self::CONTINUE_HOOK ); }
	}

	public static function unregister() {
		wp_clear_scheduled_hook( self::PREPARE_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	public static function version( $provider ) {
		return max( 1, (int) get_option( self::version_key( $provider ), 1 ) ); }

	/* ---- 2.25 incremental invalidation: one changed post refreshes only the file that holds it. ---- */

	private static function gen_key( $provider ) {
		return 'publishrank_sitemap_gen_' . sanitize_key( $provider ); }

	/** @return array<int,int> shard => generation, plus 'e' => content epoch. */
	private static function gens( $provider ) {
		$g = get_option( self::gen_key( $provider ), array() );
		return is_array( $g ) && isset( $g['v'] ) && (int) $g['v'] === self::version( $provider ) ? $g : array( 'v' => self::version( $provider ), 'e' => 0 );
	}

	/** Cache generation of one shard. */
	public static function gen( $provider, $shard ) {
		$g = self::gens( $provider );
		return isset( $g[ (int) $shard ] ) ? (int) $g[ (int) $shard ] : 0;
	}

	/** Content epoch: changes whenever any post of the provider changes (drives the background recount). */
	public static function epoch( $provider ) {
		$g = self::gens( $provider );
		return (int) $g['e'];
	}

	/** Short hash of every shard generation (index cache key, per-file lastmod). */
	public static function gens_hash( $provider ) {
		return substr( md5( serialize( self::gens( $provider ) ) ), 0, 8 );
	}

	/** Shard that holds an object ID according to the prepared cursor, or 0 when unknown. */
	public static function shard_for( $provider, $object_id ) {
		if ( ! self::is_complete( $provider ) ) {
			return 0; }
		$prepared = (int) get_option( self::meta_key( $provider, 'prepared' ), 1 );
		$shard    = 1;
		for ( $k = 2; $k <= $prepared; $k++ ) {
			$c = get_option( self::checkpoint_key( $provider, $k ), false );
			if ( false === $c || (int) $object_id <= (int) $c ) {
				break; }
			$shard = $k;
		}
		return $shard;
	}

	/**
	 * A post changed: refresh only the file that holds it (or the whole provider when the cursor is not ready).
	 * New posts have the highest IDs and land in the last file; ID-bounded files never lose or duplicate URLs.
	 */
	public static function touch( $provider, $object_id ) {
		$shard = $object_id ? self::shard_for( $provider, (int) $object_id ) : 0;
		if ( ! $shard ) {
			self::bump( $provider );
			return; }
		$g             = self::gens( $provider );
		$g[ $shard ]   = ( isset( $g[ $shard ] ) ? (int) $g[ $shard ] : 0 ) + 1;
		$g['e']        = (int) $g['e'] + 1;
		update_option( self::gen_key( $provider ), $g, false );
	}
	public static function bump( $provider ) {
		update_option( self::version_key( $provider ), self::version( $provider ) + 1, false ); }
	public static function bump_many( $providers ) {
		foreach ( array_unique( array_map( 'sanitize_key', (array) $providers ) ) as $provider ) {
			if ( $provider ) {
				self::bump( $provider ); }
		} }

	public static function post_page( $provider, $args, $shard, $size ) {
		$cursor = self::checkpoint( $provider, $shard );
		if ( null === $cursor ) {
			self::queue();
			return null; }
		// Files with a following checkpoint are bounded by ID on both sides, so a post published or restored
		// inside an older range stays in its file instead of pushing another URL out of every later file.
		$next = self::is_complete( $provider ) ? get_option( self::checkpoint_key( $provider, (int) $shard + 1 ), false ) : false;
		if ( false !== $next ) {
			$rows = self::query_posts( $args, $size + 1, $cursor, (int) $next );
			if ( count( $rows ) > $size ) {
				// More than the protocol allows in one file: rebuild the cursor on the next request.
				self::bump( $provider );
				$rows = array_slice( $rows, 0, $size );
			}
			return $rows;
		}
		return self::query_posts( $args, $size, $cursor );
	}
	public static function user_page( $provider, $args, $shard, $size ) {
		$cursor = self::checkpoint( $provider, $shard );
		if ( null === $cursor ) {
			self::queue();
			return null; }
		return self::query_users( $args, $size, $cursor );
	}
	public static function term_page( $provider, $args, $shard, $size ) {
		$cursor = self::checkpoint( $provider, $shard );
		if ( null === $cursor ) {
			self::queue();
			return null; }
		return self::query_terms( $args, $size, $cursor );
	}
	/** [after, upto|null] ID window of a shard when its checkpoints exist; null otherwise. */
	public static function bounds( $provider, $shard ) {
		$shard = max( 1, absint( $shard ) );
		$after = self::checkpoint( $provider, $shard );
		if ( null === $after ) {
			return null; }
		$next = get_option( self::checkpoint_key( $provider, $shard + 1 ), false );
		return array( (int) $after, false === $next ? null : (int) $next );
	}
	/**
	 * A finished cursor that no longer matches the catalogue (content added without hooks, e.g. SQL import or
	 * restore) or that is older than STALE_AFTER is rebuilt; this never loops because the rebuild stamps a new time.
	 */
	public static function verify( $provider, $expected_shards ) {
		if ( ! self::is_complete( $provider ) ) {
			return; }
		$prepared = (int) get_option( self::meta_key( $provider, 'prepared' ), 1 );
		$done_at  = (int) get_option( self::meta_key( $provider, 'completed_at' ), 0 );
		if ( $prepared !== max( 1, (int) $expected_shards ) || ( $done_at && $done_at < time() - self::STALE_AFTER ) ) {
			self::bump( $provider ); }
	}
	public static function is_ready( $provider, $shard ) {
		return null !== self::checkpoint( $provider, $shard ); }
	public static function request_preparation() {
		self::queue(); }
	public static function is_complete( $provider ) {
		return (bool) get_option( self::meta_key( $provider, 'complete' ), 0 ); }

	public static function prepare_posts( $provider, $args, $size, $steps = self::CRON_STEPS ) {
		return self::prepare( $provider, $args, $size, 'posts', $steps ); }
	public static function prepare_users( $provider, $args, $size, $steps = self::CRON_STEPS ) {
		return self::prepare( $provider, $args, $size, 'users', $steps ); }
	public static function prepare_terms( $provider, $args, $size, $steps = self::CRON_STEPS ) {
		return self::prepare( $provider, $args, $size, 'terms', $steps ); }

	private static function prepare( $provider, $args, $size, $kind, $steps ) {
		self::expire_if_stale( $provider );
		$prepared = (int) get_option( self::meta_key( $provider, 'prepared' ), 1 );
		if ( self::is_complete( $provider ) ) {
			return $prepared; }
		update_option( self::meta_key( $provider, 'progress' ), time(), false );
		self::checkpoint( $provider, 1 );
		for ( $i = 0; $i < max( 1, (int) $steps ); $i++ ) {
			$cursor = (int) self::checkpoint( $provider, $prepared );
			// Preparation only needs the last ID of each page: fetch IDs, not full objects (keeps memory flat on 60k+ terms/users).
			$rows   = 'posts' === $kind ? self::query_posts( $args, $size, $cursor ) : ( 'users' === $kind ? self::query_users( array_merge( $args, array( 'fields' => 'ID' ) ), $size, $cursor ) : self::query_terms( array_merge( $args, array( 'fields' => 'ids', 'cache_results' => false ) ), $size, $cursor ) );
			if ( count( $rows ) < $size ) {
				update_option( self::meta_key( $provider, 'complete' ), 1, false );
				update_option( self::meta_key( $provider, 'completed_at' ), time(), false );
				break; }
			$last = end( $rows );
			$next = is_object( $last ) ? (int) ( 'users' === $kind ? $last->ID : $last->term_id ) : (int) $last;
			++$prepared;
			update_option( self::checkpoint_key( $provider, $prepared ), $next, false );
			update_option( self::meta_key( $provider, 'prepared' ), $prepared, false );
		}
		return $prepared;
	}

	/** Restart an incomplete cursor that has made no progress for STALE_AFTER seconds (interrupted preparation). */
	private static function expire_if_stale( $provider ) {
		if ( self::is_complete( $provider ) ) {
			return; }
		$progress = (int) get_option( self::meta_key( $provider, 'progress' ), 0 );
		if ( $progress && $progress < time() - self::STALE_AFTER ) {
			self::bump( $provider ); }
	}

	/** Cursor health for diagnostics. @return array<string,mixed> */
	public static function health( $provider ) {
		$progress = (int) get_option( self::meta_key( $provider, 'progress' ), 0 );
		$next     = wp_next_scheduled( self::CONTINUE_HOOK );
		return array(
			'complete'      => self::is_complete( $provider ),
			'prepared'      => (int) get_option( self::meta_key( $provider, 'prepared' ), 1 ),
			'last_progress' => $progress,
			'stale'         => ! self::is_complete( $provider ) && $progress && $progress < time() - self::STALE_AFTER,
			'cron_overdue'  => (bool) ( $next && $next < time() - HOUR_IN_SECONDS ),
		);
	}

	private static function checkpoint( $provider, $shard ) {
		$shard = max( 1, absint( $shard ) );
		if ( 1 === $shard ) {
			$key = self::checkpoint_key( $provider, 1 );
			if ( false === get_option( $key, false ) ) {
				add_option( $key, 0, '', false ); }
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
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::CONTINUE_HOOK ); }
	}
	public static function prepare_registered_providers() {
		$engine             = new SitemapEngine();
		$lock               = new SitemapLock();
		$needs_continuation = false;
		foreach ( $engine->providers() as $provider ) {
			if ( ! method_exists( $provider, 'prepare_cursor' ) ) {
				continue; }
			$slug = $provider->get_slug();
			if ( self::is_complete( $slug ) ) {
				continue; }
			if ( ! $lock->acquire_provider( $slug ) ) {
				$needs_continuation = true;
				continue;
			}
			try {
				$provider->prepare_cursor( self::CRON_STEPS );
			} finally {
				$lock->release_provider( $slug );
			}
			if ( ! self::is_complete( $slug ) ) {
				$needs_continuation = true; }
		}
		if ( $needs_continuation ) {
			self::queue_continuation(); }
	}
	private static function version_key( $provider ) {
		return 'publishrank_sitemap_version_' . sanitize_key( $provider ); }
	private static function meta_key( $provider, $name ) {
		return 'publishrank_sitemap_cursor_' . sanitize_key( $provider ) . '_' . self::version( $provider ) . '_' . sanitize_key( $name ); }
	private static function checkpoint_key( $provider, $shard ) {
		return 'publishrank_sitemap_checkpoint_' . sanitize_key( $provider ) . '_' . self::version( $provider ) . '_' . max( 1, absint( $shard ) ); }

	private static function query_posts( $args, $size, $after, $upto = 0 ) {
		$where = static function ( $sql ) use ( $after, $upto ) {
			global $wpdb;
			$sql .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
			return $upto ? $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID <= %d", $upto ) : $sql;
		};
		add_filter( 'posts_where', $where, 10, 1 );
		$args['posts_per_page'] = $size;
		$args['orderby']        = 'ID';
		$args['order']          = 'ASC';
		$args['no_found_rows']  = true;
		$args['fields']         = 'ids';
		$rows                   = ( new \WP_Query( $args ) )->posts;
		remove_filter( 'posts_where', $where, 10 );
		return array_map( 'absint', (array) $rows );
	}
	private static function query_users( $args, $size, $after ) {
		$where = static function ( $query ) use ( $after ) {
			global $wpdb;
			$query->query_where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d", $after );
		};
		add_action( 'pre_user_query', $where, 10, 1 );
		$args['number']  = $size;
		$args['orderby'] = 'ID';
		$args['order']   = 'ASC';
		$rows            = get_users( $args );
		remove_action( 'pre_user_query', $where, 10 );
		return (array) $rows;
	}
	private static function query_terms( $args, $size, $after ) {
		$where = static function ( $clauses ) use ( $after ) {
			global $wpdb;
			$clauses['where'] .= $wpdb->prepare( ' AND t.term_id > %d', $after );
			return $clauses;
		};
		add_filter( 'terms_clauses', $where, 10, 3 );
		$args['number']  = $size;
		$args['orderby'] = 'term_id';
		$args['order']   = 'ASC';
		$rows            = get_terms( $args );
		remove_filter( 'terms_clauses', $where, 10 );
		return is_wp_error( $rows ) ? array() : (array) $rows;
	}
}
