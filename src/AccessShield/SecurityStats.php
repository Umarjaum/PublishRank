<?php
/**
 * Aggregates the Access Shield security log for the analytics view.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class SecurityStats {
	/** Event groups shown in charts. @return array<string,string[]> */
	public static function groups() {
		return array(
			'success' => array( 'login_success' ),
			'failed'  => array( 'login_failed', 'captcha_failed', 'twofa_failed', 'app_password_failed' ),
			'blocked' => array( 'lockout', 'locked_attempt', 'denied_ip', 'xmlrpc_blocked', 'firewall', 'honeypot', 'hidden_login_hit', 'enumeration', 'flood_challenge' ),
			'twofa'   => array( 'twofa_success', 'twofa_recovery' ),
		);
	}

	private static function since( $days ) {
		return gmdate( 'Y-m-d 00:00:00', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
	}

	private static function in( array $events ) {
		return implode( ',', array_map( static function ( $e ) { return "'" . esc_sql( $e ) . "'"; }, $events ) );
	}

	/** Event counts for the last N days, or the N days before that when $offset is 1. @return array<string,int> */
	public static function event_counts( $days, $offset = 0 ) {
		global $wpdb;
		if ( ! Database::ready( 'security_log' ) ) {
			return array();
		}
		$t    = Database::table( 'security_log' );
		$end  = time() + 60 - $offset * $days * DAY_IN_SECONDS;
		$from = gmdate( 'Y-m-d H:i:s', $end - 60 - $days * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event, COUNT(*) n FROM {$t} WHERE created_at >= %s AND created_at < %s GROUP BY event", $from, gmdate( 'Y-m-d H:i:s', $end ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['event'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** @return array<string,int> group => count */
	public static function grouped( array $counts ) {
		$out = array();
		foreach ( self::groups() as $g => $events ) {
			$out[ $g ] = 0;
			foreach ( $events as $e ) {
				$out[ $g ] += isset( $counts[ $e ] ) ? $counts[ $e ] : 0;
			}
		}
		return $out;
	}

	/** Daily counts per group (UTC days). @return array{labels:string[],series:array<string,int[]>} */
	public static function daily( $days ) {
		global $wpdb;
		$labels = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$labels[] = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		}
		$series = array();
		foreach ( array_keys( self::groups() ) as $g ) {
			$series[ $g ] = array_fill( 0, $days, 0 );
		}
		if ( ! Database::ready( 'security_log' ) ) {
			return array( 'labels' => $labels, 'series' => $series );
		}
		$t    = Database::table( 'security_log' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT SUBSTR(created_at,1,10) d, event, COUNT(*) n FROM {$t} WHERE created_at >= %s GROUP BY d, event", self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$idx  = array_flip( $labels );
		$map  = array();
		foreach ( self::groups() as $g => $events ) {
			foreach ( $events as $e ) {
				$map[ $e ] = $g;
			}
		}
		foreach ( (array) $rows as $r ) {
			if ( isset( $idx[ $r['d'] ], $map[ $r['event'] ] ) ) {
				$series[ $map[ $r['event'] ] ][ $idx[ $r['d'] ] ] += (int) $r['n'];
			}
		}
		return array( 'labels' => $labels, 'series' => $series );
	}

	/** Top values of a column for a set of events. @return array<int,array{val:string,n:int}> */
	public static function top( $column, array $events, $days, $limit = 8 ) {
		global $wpdb;
		if ( ! Database::ready( 'security_log' ) || ! in_array( $column, array( 'ip', 'username', 'country', 'path' ), true ) ) {
			return array();
		}
		if ( 'country' === $column && (int) get_option( Database::VERSION_OPT, 0 ) < 5 ) {
			return array();
		}
		$t    = Database::table( 'security_log' );
		$in   = self::in( $events );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$column} val, COUNT(*) n FROM {$t} WHERE created_at >= %s AND event IN ({$in}) AND {$column} <> '' GROUP BY {$column} ORDER BY n DESC LIMIT %d", self::since( $days ), (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_map( static function ( $r ) { return array( 'val' => (string) $r['val'], 'n' => (int) $r['n'] ); }, (array) $rows );
	}

	public static function unique_ips( $days ) {
		global $wpdb;
		if ( ! Database::ready( 'security_log' ) ) {
			return 0;
		}
		$t = Database::table( 'security_log' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ip) FROM {$t} WHERE created_at >= %s AND ip <> ''", self::since( $days ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** Attacks by weekday × hour (site time). @return array<int,array<int,int>> */
	public static function heatmap( $days ) {
		global $wpdb;
		$grid = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		if ( ! Database::ready( 'security_log' ) ) {
			return $grid;
		}
		$t    = Database::table( 'security_log' );
		$in   = self::in( array_merge( self::groups()['failed'], self::groups()['blocked'] ) );
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT created_at FROM {$t} WHERE created_at >= %s AND event IN ({$in}) ORDER BY id DESC LIMIT 20000", self::since( $days ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $c ) {
			$ts = strtotime( $c . ' UTC' );
			$grid[ (int) wp_date( 'N', $ts ) - 1 ][ (int) wp_date( 'G', $ts ) ]++;
		}
		return $grid;
	}

	/** Latest events. */
	public static function recent( $limit = 10 ) {
		$r = SecurityLog::query( array( 'per_page' => $limit, 'paged' => 1 ) );
		return isset( $r['rows'] ) ? (array) $r['rows'] : array();
	}
}
