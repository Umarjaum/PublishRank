<?php
/**
 * Traffic Guard storage: the event log, temporary blocks, rate counters, statistics and retention.
 *
 * Writes are bounded so an attack cannot turn logging into a resource-exhaustion problem:
 * at most `log_cap_minute` events are written per minute (the rest are counted only), and the
 * event table is purged daily according to the retention setting.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Security;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class TrafficGuardStore {
	const CRON         = 'publishrank_traffic_guard_daily';
	const BLOCKS_UNTIL = 'publishrank_tg_blocks_until';
	const DROPPED      = 'publishrank_tg_dropped';
	const DECISIONS    = array( 'blocked', 'would_block', 'rate_limited', 'logged', 'test_match' );

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** Daily maintenance: retention purge and expired temporary blocks. */
	public static function daily() {
		$settings = TrafficGuard::settings();
		Database::purge_older_than( 'tg_events', 'created_at', gmdate( 'Y-m-d H:i:s', time() - (int) $settings['retention_days'] * DAY_IN_SECONDS ) );
		self::purge_expired_blocks();
	}

	/* ------------------------------------------------------------------ Events */

	/**
	 * Record one event. Returns the visitor-facing reference code ('' when not stored).
	 */
	public static function log( $decision, $reason, $rule_id, array $ctx, array $settings ) {
		global $wpdb;
		if ( ! in_array( $decision, self::DECISIONS, true ) || ! Database::ready( 'tg_events' ) ) {
			return '';
		}
		if ( ! self::within_cap( (int) $settings['log_cap_minute'] ) ) {
			$dropped = get_option( self::DROPPED, array() );
			$day     = gmdate( 'Y-m-d' );
			$dropped = is_array( $dropped ) && isset( $dropped['day'] ) && $dropped['day'] === $day ? $dropped : array( 'day' => $day, 'count' => 0 );
			++$dropped['count'];
			update_option( self::DROPPED, $dropped, false );
			return '';
		}
		$reference = strtoupper( substr( wp_hash( uniqid( '', true ) . $ctx['ip'] ), 0, 10 ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Database::table( 'tg_events' ),
			array(
				'created_at' => Database::now(),
				'decision'   => $decision,
				'reason'     => substr( (string) $reason, 0, 32 ),
				'rule_id'    => substr( (string) $rule_id, 0, 32 ),
				'ip'         => substr( (string) $ctx['ip'], 0, 45 ),
				'country'    => substr( (string) $ctx['country'], 0, 2 ),
				'method'     => substr( (string) $ctx['method'], 0, 8 ),
				'path'       => substr( (string) $ctx['path'] . ( '' !== (string) $ctx['query'] ? '?' . $ctx['query'] : '' ), 0, 255 ),
				'user_agent' => substr( (string) $ctx['user_agent'], 0, 255 ),
				'bot'        => substr( (string) $ctx['bot'], 0, 16 ),
				'reference'  => $reference,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $reference;
	}

	/** Fixed one-minute window shared by all visitors. */
	private static function within_cap( $cap ) {
		$key = 'publishrank_tg_cap_' . gmdate( 'YmdHi' );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'publishrank_tg', 120 );
			return (int) wp_cache_incr( $key, 1, 'publishrank_tg' ) <= $cap;
		}
		$n = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, 120 );
		return $n <= $cap;
	}

	/**
	 * Query events with filters, sorting and pagination.
	 *
	 * @param array $f decision, reason, country, search, from (Y-m-d), to (Y-m-d), orderby, order, per_page, paged.
	 * @return array{rows:array<int,array>,total:int}
	 */
	public static function query( array $f ) {
		global $wpdb;
		if ( ! Database::ready( 'tg_events' ) ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		list( $where, $args ) = self::where( $f );
		$table    = Database::table( 'tg_events' );
		$orderby  = isset( $f['orderby'] ) && in_array( $f['orderby'], array( 'created_at', 'ip', 'country', 'decision', 'reason', 'path' ), true ) ? $f['orderby'] : 'created_at';
		$order    = isset( $f['order'] ) && 'asc' === strtolower( (string) $f['order'] ) ? 'ASC' : 'DESC';
		$per_page = isset( $f['per_page'] ) ? max( 1, min( 5000, (int) $f['per_page'] ) ) : 25;
		$paged    = isset( $f['paged'] ) ? max( 1, (int) $f['paged'] ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- fixed table name; ORDER BY from an allow-list; values prepared.
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array( 'rows' => is_array( $rows ) ? $rows : array(), 'total' => $total );
	}

	/** @return array{0:string,1:array} */
	private static function where( array $f ) {
		global $wpdb;
		$w    = array( '1=1' );
		$args = array();
		if ( ! empty( $f['decision'] ) && in_array( $f['decision'], self::DECISIONS, true ) ) {
			$w[]    = 'decision = %s';
			$args[] = $f['decision'];
		}
		if ( ! empty( $f['reason'] ) ) {
			$w[]    = 'reason = %s';
			$args[] = substr( sanitize_key( $f['reason'] ), 0, 32 );
		}
		if ( ! empty( $f['rule'] ) ) {
			$w[]    = 'rule_id = %s';
			$args[] = substr( sanitize_key( $f['rule'] ), 0, 32 );
		}
		if ( ! empty( $f['country'] ) && preg_match( '/^[A-Z]{2}$/', (string) $f['country'] ) ) {
			$w[]    = 'country = %s';
			$args[] = $f['country'];
		}
		if ( ! empty( $f['fp'] ) ) {
			$w[] = 'false_positive = 1';
		}
		if ( ! empty( $f['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( substr( (string) $f['search'], 0, 100 ) ) . '%';
			$w[]    = '(ip LIKE %s OR path LIKE %s OR user_agent LIKE %s OR reference = %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
			$args[] = strtoupper( substr( (string) $f['search'], 0, 10 ) );
		}
		if ( ! empty( $f['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f['from'] ) ) {
			$w[]    = 'created_at >= %s';
			$args[] = $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f['to'] ) ) {
			$w[]    = 'created_at <= %s';
			$args[] = $f['to'] . ' 23:59:59';
		}
		return array( implode( ' AND ', $w ), $args );
	}

	/** One event by id. */
	public static function get( $id ) {
		global $wpdb;
		if ( ! Database::ready( 'tg_events' ) ) {
			return null;
		}
		$table = Database::table( 'tg_events' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : null;
	}

	public static function mark_false_positive( $id ) {
		global $wpdb;
		if ( ! Database::ready( 'tg_events' ) ) {
			return false;
		}
		return false !== $wpdb->update( Database::table( 'tg_events' ), array( 'false_positive' => 1 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function clear_events() {
		global $wpdb;
		if ( Database::ready( 'tg_events' ) ) {
			$wpdb->query( 'DELETE FROM ' . Database::table( 'tg_events' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- fixed table name.
		}
		delete_option( self::DROPPED );
	}

	/* ------------------------------------------------------------------ Statistics */

	/**
	 * Overview statistics for the last $days days (and the previous period for comparison).
	 *
	 * @return array
	 */
	public static function stats( $days = 7 ) {
		global $wpdb;
		$days  = max( 1, min( 365, (int) $days ) );
		$empty = array(
			'totals'    => array(),
			'previous'  => array(),
			'daily'     => array(),
			'countries' => array(),
			'ips'       => array(),
			'reasons'   => array(),
			'bots'      => array(),
			'hourly'    => array(),
			'fp'        => 0,
		);
		if ( ! Database::ready( 'tg_events' ) ) {
			return $empty;
		}
		$t     = Database::table( 'tg_events' );
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$prev  = gmdate( 'Y-m-d 00:00:00', time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- fixed table name, prepared values.
		$out           = $empty;
		$out['totals'] = wp_list_pluck( (array) $wpdb->get_results( $wpdb->prepare( "SELECT decision, COUNT(*) n FROM {$t} WHERE created_at >= %s GROUP BY decision", $since ), ARRAY_A ), 'n', 'decision' );
		$out['previous'] = wp_list_pluck( (array) $wpdb->get_results( $wpdb->prepare( "SELECT decision, COUNT(*) n FROM {$t} WHERE created_at >= %s AND created_at < %s GROUP BY decision", $prev, $since ), ARRAY_A ), 'n', 'decision' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTR(created_at,1,10) d, decision, COUNT(*) n FROM {$t} WHERE created_at >= %s GROUP BY d, decision", $since ), ARRAY_A ) as $r ) {
			$out['daily'][ $r['d'] ][ $r['decision'] ] = (int) $r['n'];
		}
		$out['countries'] = wp_list_pluck( (array) $wpdb->get_results( $wpdb->prepare( "SELECT country, COUNT(*) n FROM {$t} WHERE created_at >= %s AND decision IN ('blocked','would_block','rate_limited') AND country <> '' GROUP BY country ORDER BY n DESC LIMIT 50", $since ), ARRAY_A ), 'n', 'country' );
		$out['ips']       = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ip, MAX(country) country, COUNT(*) n, MAX(created_at) last_at FROM {$t} WHERE created_at >= %s GROUP BY ip ORDER BY n DESC LIMIT 10", $since ), ARRAY_A );
		$out['reasons']   = wp_list_pluck( (array) $wpdb->get_results( $wpdb->prepare( "SELECT reason, COUNT(*) n FROM {$t} WHERE created_at >= %s GROUP BY reason ORDER BY n DESC LIMIT 12", $since ), ARRAY_A ), 'n', 'reason' );
		$out['bots']      = wp_list_pluck( (array) $wpdb->get_results( $wpdb->prepare( "SELECT bot, COUNT(*) n FROM {$t} WHERE created_at >= %s GROUP BY bot ORDER BY n DESC", $since ), ARRAY_A ), 'n', 'bot' );
		$out['fp']        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s AND false_positive = 1", $since ) );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTR(created_at,1,13) h, COUNT(*) n FROM {$t} WHERE created_at >= %s GROUP BY h", gmdate( 'Y-m-d H:00:00', time() - 8 * DAY_IN_SECONDS ) ), ARRAY_A ) as $r ) {
			$out['hourly'][ $r['h'] ] = (int) $r['n'];
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Anomaly check: events in the current hour against the average hour of the previous 7 days.
	 *
	 * @param array<string,int> $hourly 'Y-m-d H' => count.
	 * @return array{now:int,baseline:float,anomaly:bool}
	 */
	public static function anomaly( array $hourly ) {
		$now_key = gmdate( 'Y-m-d H' );
		$now     = isset( $hourly[ $now_key ] ) ? (int) $hourly[ $now_key ] : 0;
		$sum     = 0;
		$cutoff  = gmdate( 'Y-m-d H', time() - 7 * DAY_IN_SECONDS );
		foreach ( $hourly as $h => $n ) {
			if ( $h !== $now_key && $h >= $cutoff ) {
				$sum += (int) $n;
			}
		}
		$baseline = $sum / ( 7 * 24 );
		return array(
			'now'      => $now,
			'baseline' => round( $baseline, 2 ),
			'anomaly'  => $now >= 20 && $now > 3 * max( 1, $baseline ),
		);
	}

	/** Per-rule hits, last match and false positives from the event log. @return array<string,array{hits:int,last:string,fp:int}> */
	public static function rule_stats() {
		global $wpdb;
		if ( ! Database::ready( 'tg_events' ) ) {
			return array();
		}
		$t   = Database::table( 'tg_events' );
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT rule_id, COUNT(*) n, MAX(created_at) last_at, SUM(false_positive) fp FROM {$t} WHERE rule_id <> '' GROUP BY rule_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- no user input.
			$out[ $r['rule_id'] ] = array( 'hits' => (int) $r['n'], 'last' => (string) $r['last_at'], 'fp' => (int) $r['fp'] );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Temporary blocks */

	/** Unix time a temporary block on this IP ends, or 0. Costs no query when no block is active. */
	public static function blocked_until( $ip ) {
		global $wpdb;
		$latest = (int) get_option( self::BLOCKS_UNTIL, 0 );
		if ( $latest < time() || '' === (string) $ip || ! Database::ready( 'tg_blocks' ) ) {
			return 0;
		}
		$t   = Database::table( 'tg_blocks' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT expires_at FROM {$t} WHERE ip = %s", (string) $ip ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			return 0;
		}
		$until = strtotime( $row['expires_at'] . ' UTC' );
		return $until > time() ? (int) $until : 0;
	}

	public static function block_ip( $ip, $reason, $seconds ) {
		global $wpdb;
		$ip = TrafficGuard::valid_ip( (string) $ip );
		if ( '' === $ip || ! Database::ready( 'tg_blocks' ) ) {
			return false;
		}
		$until = time() + max( 60, (int) $seconds );
		$t     = Database::table( 'tg_blocks' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE ip = %s", $ip ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ok = (bool) $wpdb->insert( $t, array( 'ip' => $ip, 'reason' => substr( sanitize_text_field( (string) $reason ), 0, 64 ), 'created_at' => Database::now(), 'expires_at' => gmdate( 'Y-m-d H:i:s', $until ) ), array( '%s', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $until > (int) get_option( self::BLOCKS_UNTIL, 0 ) ) {
			update_option( self::BLOCKS_UNTIL, $until, true );
		}
		return $ok;
	}

	public static function unblock_ip( $ip ) {
		global $wpdb;
		if ( ! Database::ready( 'tg_blocks' ) ) {
			return false;
		}
		$t = Database::table( 'tg_blocks' );
		return false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE ip = %s", (string) $ip ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** Active temporary blocks, newest first. */
	public static function active_blocks( $limit = 200 ) {
		global $wpdb;
		if ( ! Database::ready( 'tg_blocks' ) ) {
			return array();
		}
		$t = Database::table( 'tg_blocks' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE expires_at > %s ORDER BY created_at DESC LIMIT %d", Database::now(), (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function purge_expired_blocks() {
		global $wpdb;
		if ( ! Database::ready( 'tg_blocks' ) ) {
			return;
		}
		$t = Database::table( 'tg_blocks' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE expires_at <= %s", Database::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$max = $wpdb->get_var( "SELECT MAX(expires_at) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		update_option( self::BLOCKS_UNTIL, $max ? (int) strtotime( $max . ' UTC' ) : 0, true );
	}

	/* ------------------------------------------------------------------ Rate counting */

	/**
	 * Count a request for an IP in a fixed window and return the count so far. Uses the object cache
	 * when available; otherwise one transient per IP and window (documented cost on the settings screen).
	 */
	public static function rate_hit( $ip, $window, $increment = true ) {
		$slot = (int) floor( time() / max( 1, (int) $window ) );
		$key  = 'publishrank_tg_rl_' . substr( hash_hmac( 'sha256', (string) $ip, wp_salt( 'nonce' ) ), 0, 20 ) . '_' . $slot;
		if ( wp_using_ext_object_cache() ) {
			if ( ! $increment ) {
				return (int) wp_cache_get( $key, 'publishrank_tg' );
			}
			wp_cache_add( $key, 0, 'publishrank_tg', (int) $window + 5 );
			return (int) wp_cache_incr( $key, 1, 'publishrank_tg' );
		}
		$n = (int) get_transient( $key );
		if ( $increment ) {
			++$n;
			set_transient( $key, $n, (int) $window + 5 );
		}
		return $n;
	}

	/** Events that could not be written today because of the per-minute cap. */
	public static function dropped_today() {
		$d = get_option( self::DROPPED, array() );
		return is_array( $d ) && isset( $d['day'] ) && gmdate( 'Y-m-d' ) === $d['day'] ? (int) $d['count'] : 0;
	}
}
