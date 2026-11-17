<?php
/**
 * Daily traffic aggregates: writes from the tracker and reads for the reports.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Analytics;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Store {
	const OPTION = 'publishrank_traffic';
	const CRON   = 'publishrank_traffic_daily';

	/** @return array<string,mixed> */
	public static function settings() {
		$d = array( 'enabled' => 1, 'exclude_staff' => 1, 'respect_dnt' => 1, 'retention_days' => 400 );
		$s = get_option( self::OPTION, array() );
		$s = array_merge( $d, is_array( $s ) ? $s : array() );
		$s['enabled']        = (int) (bool) $s['enabled'];
		$s['exclude_staff']  = (int) (bool) $s['exclude_staff'];
		$s['respect_dnt']    = (int) (bool) $s['respect_dnt'];
		$s['retention_days'] = max( 30, min( 1100, (int) $s['retention_days'] ) );
		return $s;
	}

	public static function enabled() {
		return (bool) self::settings()['enabled'];
	}

	public static function ready() {
		return Database::ready( 'traffic_daily' ) && Database::ready( 'traffic_seen' );
	}

	/** Site-local date (Y-m-d). */
	public static function today( $ts = null ) {
		return wp_date( 'Y-m-d', null === $ts ? time() : (int) $ts );
	}

	/**
	 * Add to one aggregate row.
	 *
	 * @param array<string,int> $inc views, visitors, sessions, engaged, secs.
	 */
	public static function bump( $day, $dim, $val, array $inc ) {
		global $wpdb;
		$cols = array( 'views', 'visitors', 'sessions', 'engaged', 'secs' );
		$vals = array();
		foreach ( $cols as $c ) {
			$vals[ $c ] = max( 0, (int) ( isset( $inc[ $c ] ) ? $inc[ $c ] : 0 ) );
		}
		if ( ! array_sum( $vals ) ) {
			return;
		}
		$t   = Database::table( 'traffic_daily' );
		$val = substr( (string) $val, 0, 191 );
		$set = array();
		foreach ( $cols as $c ) {
			if ( $vals[ $c ] ) {
				$set[] = "{$c} = {$c} + " . (int) $vals[ $c ];
			}
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- table name and integer increments only.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET " . implode( ', ', $set ) . ' WHERE day = %s AND dim = %s AND val = %s', $day, $dim, $val ) );
		if ( ! $updated ) {
			$ok = $wpdb->insert( $t, array_merge( array( 'day' => $day, 'dim' => $dim, 'val' => $val ), $vals ) );
			if ( ! $ok ) {
				// Another request inserted the row first.
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET " . implode( ', ', $set ) . ' WHERE day = %s AND dim = %s AND val = %s', $day, $dim, $val ) );
			}
		}
		// phpcs:enable
	}

	/**
	 * Daily totals between two dates (inclusive), every day present.
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function series( $from, $to, $dim = 'total', $val = '' ) {
		global $wpdb;
		$out = array();
		for ( $ts = strtotime( $from . ' 12:00:00' ); $ts <= strtotime( $to . ' 12:00:00' ); $ts += DAY_IN_SECONDS ) {
			$out[ gmdate( 'Y-m-d', $ts ) ] = array( 'views' => 0, 'visitors' => 0, 'sessions' => 0, 'engaged' => 0, 'secs' => 0 );
		}
		if ( ! self::ready() ) {
			return $out;
		}
		$t    = Database::table( 'traffic_daily' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, views, visitors, sessions, engaged, secs FROM {$t} WHERE dim = %s AND val = %s AND day BETWEEN %s AND %s", $dim, $val, $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $r ) {
			$d = substr( (string) $r['day'], 0, 10 );
			if ( isset( $out[ $d ] ) ) {
				foreach ( array( 'views', 'visitors', 'sessions', 'engaged', 'secs' ) as $c ) {
					$out[ $d ][ $c ] = (int) $r[ $c ];
				}
			}
		}
		return $out;
	}

	/** @return array<string,int> */
	public static function totals( $from, $to ) {
		$sum = array( 'views' => 0, 'visitors' => 0, 'sessions' => 0, 'engaged' => 0, 'secs' => 0 );
		foreach ( self::series( $from, $to ) as $row ) {
			foreach ( $sum as $k => $v ) {
				$sum[ $k ] += $row[ $k ];
			}
		}
		return $sum;
	}

	/**
	 * Top values of a dimension.
	 *
	 * @return array<int,array{val:string,views:int,visitors:int,sessions:int,secs:int}>
	 */
	public static function top( $dim, $from, $to, $limit = 10, $order = 'views' ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$order = in_array( $order, array( 'views', 'visitors', 'sessions', 'secs' ), true ) ? $order : 'views';
		$t     = Database::table( 'traffic_daily' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT val, SUM(views) views, SUM(visitors) visitors, SUM(sessions) sessions, SUM(engaged) engaged, SUM(secs) secs FROM {$t} WHERE dim = %s AND day BETWEEN %s AND %s GROUP BY val ORDER BY {$order} DESC LIMIT %d", $dim, $from, $to, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'val' => (string) $r['val'], 'views' => (int) $r['views'], 'visitors' => (int) $r['visitors'], 'sessions' => (int) $r['sessions'], 'engaged' => (int) $r['engaged'], 'secs' => (int) $r['secs'] );
		}
		return $out;
	}

	/** Visitors seen in the last N minutes. */
	public static function live( $minutes = 5 ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$t = Database::table( 'traffic_seen' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE last_at >= %s", gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** Latest visitor activity (no personal data: page, country, source, time). */
	public static function recent( $limit = 8 ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t = Database::table( 'traffic_seen' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT last_at, path, country, source, pages FROM {$t} ORDER BY last_at DESC LIMIT %d", (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** Views by weekday (0 = Monday) and hour for the heatmap. @return array<int,array<int,int>> */
	public static function heatmap( $from, $to ) {
		global $wpdb;
		$grid = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		if ( ! self::ready() ) {
			return $grid;
		}
		$t    = Database::table( 'traffic_daily' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, val, views FROM {$t} WHERE dim = 'hour' AND day BETWEEN %s AND %s", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $r ) {
			$wd = ( (int) gmdate( 'N', strtotime( substr( (string) $r['day'], 0, 10 ) . ' 12:00:00 UTC' ) ) ) - 1;
			$h  = max( 0, min( 23, (int) $r['val'] ) );
			$grid[ $wd ][ $h ] += (int) $r['views'];
		}
		return $grid;
	}

	public static function register_cron() {
		add_action( self::CRON, array( __CLASS__, 'retention' ) );
		if ( ! wp_next_scheduled( self::CRON ) && ! wp_installing() ) {
			wp_schedule_event( time() + 900, 'daily', self::CRON );
		}
	}

	public static function retention() {
		global $wpdb;
		if ( ! self::ready() ) {
			return;
		}
		$s    = self::settings();
		$seen = Database::table( 'traffic_seen' );
		$t    = Database::table( 'traffic_daily' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$seen} WHERE day < %s", self::today( time() - 2 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE day < %s", self::today( time() - (int) $s['retention_days'] * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE dim = 'page' AND views <= 1 AND day < %s", self::today( time() - 90 * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}
}
