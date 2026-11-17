<?php
/**
 * Security event log (custom table) with retention, CSV export and rate-limited e-mail alerts.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class SecurityLog {
	/** @var bool Suppress logging (self-tests only). */
	public static $mute = false;

	const CRON = 'publishrank_shield_daily';

	/** Human labels. @return array<string,string> */
	public static function events() {
		return array(
			'login_failed'      => __( 'Failed login', 'publishrank' ),
			'login_success'     => __( 'Successful login', 'publishrank' ),
			'lockout'           => __( 'Lockout', 'publishrank' ),
			'locked_attempt'    => __( 'Attempt while locked', 'publishrank' ),
			'flood_challenge'   => __( 'Flood challenge raised', 'publishrank' ),
			'honeypot'          => __( 'Honeypot / too-fast form', 'publishrank' ),
			'captcha_failed'    => __( 'CAPTCHA failed', 'publishrank' ),
			'twofa_failed'      => __( 'Two-factor code rejected', 'publishrank' ),
			'twofa_success'     => __( 'Two-factor verified', 'publishrank' ),
			'twofa_recovery'    => __( 'Recovery code used', 'publishrank' ),
			'twofa_reset'       => __( 'Two-factor reset', 'publishrank' ),
			'xmlrpc_blocked'    => __( 'XML-RPC blocked', 'publishrank' ),
			'app_password_failed' => __( 'Application password failed', 'publishrank' ),
			'enumeration'       => __( 'User enumeration blocked', 'publishrank' ),
			'hidden_login_hit'  => __( 'Hidden login address probed', 'publishrank' ),
			'denied_ip'         => __( 'Denied IP / country', 'publishrank' ),
			'unlock'            => __( 'Manual unlock', 'publishrank' ),
			'settings'          => __( 'Settings changed', 'publishrank' ),
			'auto_undo'         => __( 'Login address change undone', 'publishrank' ),
			'firewall'          => __( 'Firewall block', 'publishrank' ),
			'warning'           => __( 'Warning', 'publishrank' ),
		);
	}

	public static function register() {
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		if ( ! wp_next_scheduled( self::CRON ) && ! wp_installing() ) {
			wp_schedule_event( time() + 600, 'daily', self::CRON );
		}
	}

	/**
	 * Record an event. Never throws; never blocks the request.
	 *
	 * @param array<string,mixed> $ctx ip, user_id, username, path, detail.
	 */
	public static function add( $event, $severity = 'info', array $ctx = array() ) {
		global $wpdb;
		if ( self::$mute ) {
			return; // Live verification self-test: simulated attempts must not count as attacks or trigger alerts.
		}
		$row = array(
			'created_at' => Database::now(),
			'event'      => substr( sanitize_key( $event ), 0, 40 ),
			'severity'   => in_array( $severity, array( 'info', 'notice', 'warn', 'alert' ), true ) ? $severity : 'info',
			'ip'         => substr( (string) ( isset( $ctx['ip'] ) ? $ctx['ip'] : Client::ip() ), 0, 45 ),
			'user_id'    => isset( $ctx['user_id'] ) ? absint( $ctx['user_id'] ) : 0,
			'username'   => substr( sanitize_user( (string) ( isset( $ctx['username'] ) ? $ctx['username'] : '' ), false ), 0, 60 ),
			'path'       => substr( (string) ( isset( $ctx['path'] ) ? $ctx['path'] : Client::path() ), 0, 255 ),
			'detail'     => substr( sanitize_text_field( (string) ( isset( $ctx['detail'] ) ? $ctx['detail'] : '' ) ), 0, 255 ),
			'country'    => substr( strtoupper( (string) ( isset( $ctx['country'] ) ? $ctx['country'] : \PublishRankSEO\Analytics\Geo::country() ) ), 0, 2 ),
		);
		if ( ! Database::ready( 'security_log' ) ) {
			return false;
		}
		if ( (int) get_option( Database::VERSION_OPT, 0 ) < 5 ) {
			unset( $row['country'] ); // Column added in schema v5; never lose an event before the upgrade runs.
		}
		$ok = $wpdb->insert( Database::table( 'security_log' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 'lockout' === $row['event'] ) {
			self::maybe_alert();
		}
		/**
		 * Fires after a security event was logged (SIEM forwarding, Slack, ...).
		 *
		 * @param array $row Logged row.
		 */
		do_action( 'publishrank_security_event', $row );
		return (bool) $ok;
	}

	/**
	 * Query the log.
	 *
	 * @param array<string,mixed> $f event, ip, username, from, to, search, paged, per_page.
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $f ) {
		global $wpdb;
		if ( ! Database::ready( 'security_log' ) ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$t     = Database::table( 'security_log' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['event'] ) ) {
			$where[] = 'event = %s';
			$args[]  = sanitize_key( $f['event'] );
		}
		if ( ! empty( $f['ip'] ) ) {
			$where[] = 'ip = %s';
			$args[]  = (string) $f['ip'];
		}
		if ( ! empty( $f['username'] ) ) {
			$where[] = 'username = %s';
			$args[]  = sanitize_user( (string) $f['username'], false );
		}
		if ( ! empty( $f['from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = gmdate( 'Y-m-d 00:00:00', strtotime( (string) $f['from'] ) );
		}
		if ( ! empty( $f['to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = gmdate( 'Y-m-d 23:59:59', strtotime( (string) $f['to'] ) );
		}
		if ( ! empty( $f['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
			$where[] = '(detail LIKE %s OR path LIKE %s OR username LIKE %s OR ip LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$per   = max( 1, min( 1000, isset( $f['per_page'] ) ? (int) $f['per_page'] : 50 ) );
		$paged = max( 1, isset( $f['paged'] ) ? (int) $f['paged'] : 1 );
		$sqlw  = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$sqlw}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) );
		$q     = "SELECT * FROM {$t} WHERE {$sqlw} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $q, array_merge( $args, array( $per, ( $paged - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		return array( 'rows' => is_array( $rows ) ? $rows : array(), 'total' => $total );
	}

	/** Counts per event for the last N hours. @return array<string,int> */
	public static function counts( $hours = 24 ) {
		global $wpdb;
		if ( ! Database::ready( 'security_log' ) ) {
			return array();
		}
		$t    = Database::table( 'security_log' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event, COUNT(*) n FROM {$t} WHERE created_at >= %s GROUP BY event", gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['event'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** Daily failed-login/lockout series for charts. @return array<int,array{l:string,v:int,v2:int}> */
	public static function series( $days = 14 ) {
		global $wpdb;
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = array( 'v' => 0, 'v2' => 0 );
		}
		if ( Database::ready( 'security_log' ) ) {
			$t    = Database::table( 'security_log' );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, SUM(event='login_failed') f, SUM(event='lockout') l FROM {$t} WHERE created_at >= %s GROUP BY DATE(created_at)", gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				if ( isset( $out[ $r['d'] ] ) ) {
					$out[ $r['d'] ] = array( 'v' => (int) $r['f'], 'v2' => (int) $r['l'] );
				}
			}
		}
		$list = array();
		foreach ( $out as $day => $v ) {
			$list[] = array( 'l' => $day, 'v' => $v['v'], 'v2' => $v['v2'] );
		}
		return $list;
	}

	/** E-mail the site owner when lockouts spike, at most once per hour. */
	public static function maybe_alert() {
		if ( ! Settings::get( 'alert_enabled' ) || get_transient( 'publishrank_shield_alert_sent' ) ) {
			return;
		}
		$counts = self::counts( 1 );
		$locks  = isset( $counts['lockout'] ) ? $counts['lockout'] : 0;
		if ( $locks < (int) Settings::get( 'alert_threshold' ) ) {
			return;
		}
		set_transient( 'publishrank_shield_alert_sent', 1, HOUR_IN_SECONDS );
		$to = Settings::get( 'alert_email' ) ? Settings::get( 'alert_email' ) : get_option( 'admin_email' );
		/* translators: 1: number of lockouts, 2: site name */
		$subject = sprintf( __( '[%2$s] Access Shield: %1$d lockouts in the last hour', 'publishrank' ), $locks, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body    = sprintf( /* translators: 1: number, 2: URL */ __( "Access Shield locked out %1\$d login sources in the last hour. This usually means a password-guessing attack. Your site is protected; no action is required unless you expected legitimate users to be affected.\n\nReview the security log: %2\$s\n\nYou will receive at most one alert per hour.", 'publishrank' ), $locks, admin_url( 'admin.php?page=publishrank-access-shield#log' ) );
		wp_mail( $to, $subject, $body );
	}

	/** Retention for the log and the attempt table. */
	public static function daily() {
		Database::purge_older_than( 'security_log', 'created_at', gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get( 'log_retention_days' ) * DAY_IN_SECONDS ) );
		Database::purge_older_than( 'shield_attempts', 'last_at', gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get( 'retention_days' ) * DAY_IN_SECONDS ) );
	}
}
