<?php
/**
 * Crawler verification.
 *
 * 1. Operators' published IP lists (JSON "prefixes" format) are downloaded once a day with
 *    wp_safe_remote_get (TLS verified, 1 MB limit) — only when the publisher turns verification on.
 * 2. Operators that document reverse DNS are checked with forward-confirmed reverse DNS in a
 *    background batch, never during a page request; results are cached per IP for a day.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AI;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class BotVerifier {
	const OPT_ON     = 'publishrank_ai_verify_enabled';
	const LIST_OPT   = 'publishrank_ai_ip_lists';
	const QUEUE_OPT  = 'publishrank_ai_rdns_queue';
	const CRON_LISTS = 'publishrank_ai_refresh_ip_lists';
	const CRON_RDNS  = 'publishrank_ai_rdns_batch';
	const MAX_BYTES  = 1048576;

	public static function register() {
		add_action( self::CRON_LISTS, array( __CLASS__, 'refresh_lists' ) );
		add_action( self::CRON_RDNS, array( __CLASS__, 'process_queue' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	public static function schedule() {
		if ( ! self::enabled() ) {
			wp_clear_scheduled_hook( self::CRON_RDNS );
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_LISTS ) ) {
			wp_schedule_event( time() + 60, 'daily', self::CRON_LISTS );
		}
		if ( ! wp_next_scheduled( self::CRON_RDNS ) ) {
			wp_schedule_event( time() + 120, 'publishrank_ten_minutes', self::CRON_RDNS );
		}
	}

	public static function schedules( $s ) {
		// Schedules are read when events are scheduled on plugins_loaded; translating before init
		// triggers WordPress's "translation loading triggered too early" notice (WP 6.7+).
		$label = did_action( 'init' ) ? __( 'Every 10 minutes', 'publishrank' ) : 'Every 10 minutes';
		$s['publishrank_ten_minutes'] = array( 'interval' => 600, 'display' => $label );
		return $s;
	}

	public static function enabled() {
		return (bool) get_option( self::OPT_ON, 0 );
	}

	/** @return array<string,array{fetched:int,prefixes:string[],error:string}> keyed by URL */
	public static function lists() {
		$l = get_option( self::LIST_OPT, array() );
		return is_array( $l ) ? $l : array();
	}

	/** Download every operator IP list. @return array<string,string> url => result */
	public static function refresh_lists() {
		$urls = array();
		foreach ( BotRegistry::all() as $b ) {
			if ( '' !== $b['ips'] ) {
				$urls[ $b['ips'] ] = true;
			}
		}
		$lists  = self::lists();
		$result = array();
		foreach ( array_keys( $urls ) as $url ) {
			$res = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'redirection'         => 2,
					'limit_response_size' => self::MAX_BYTES,
					'user-agent'          => 'PublishRank/' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '2' ) . ' (+' . home_url( '/' ) . ')',
					'headers'             => array( 'Accept' => 'application/json' ),
				)
			);
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$data = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $res ), true ) : null;
			$pref = array();
			if ( is_array( $data ) && isset( $data['prefixes'] ) && is_array( $data['prefixes'] ) ) {
				foreach ( $data['prefixes'] as $p ) {
					$cidr = is_array( $p ) ? ( $p['ipv4Prefix'] ?? ( $p['ipv6Prefix'] ?? '' ) ) : '';
					if ( is_string( $cidr ) && preg_match( '#^[0-9a-fA-F:.]+/\d{1,3}$#', $cidr ) ) {
						$pref[] = $cidr;
					}
				}
			}
			if ( $pref ) {
				$lists[ $url ] = array( 'fetched' => time(), 'prefixes' => array_values( array_unique( $pref ) ), 'error' => '' );
				$result[ $url ] = sprintf( '%d ranges', count( $pref ) );
			} else {
				$err            = is_wp_error( $res ) ? $res->get_error_message() : sprintf( 'HTTP %d', $code );
				$lists[ $url ]  = array_merge( array( 'fetched' => 0, 'prefixes' => array() ), $lists[ $url ] ?? array(), array( 'error' => substr( $err, 0, 200 ), 'tried' => time() ) );
				$result[ $url ] = 'error: ' . $err;
			}
		}
		update_option( self::LIST_OPT, $lists, false );
		return $result;
	}

	/**
	 * Verify a crawler visit without blocking: IP lists inline (cheap), reverse DNS from cache or queued.
	 *
	 * @return int BotRegistry::VERIFIED|UNVERIFIED|PENDING|UNCHECKABLE
	 */
	public static function verify( $token, $ip ) {
		$bot = BotRegistry::get( $token );
		if ( ! $bot || '' === (string) $ip || ! self::enabled() ) {
			return BotRegistry::UNCHECKABLE;
		}
		$cached = wp_cache_get( $token . '|' . $ip, 'publishrank_aiv' );
		if ( false === $cached ) {
			$cached = get_transient( 'pr_aiv_' . md5( $token . '|' . $ip ) );
		}
		if ( false !== $cached && '' !== $cached ) {
			return (int) $cached;
		}
		$verdict = null;
		if ( '' !== $bot['ips'] ) {
			$lists = self::lists();
			if ( ! empty( $lists[ $bot['ips'] ]['prefixes'] ) ) {
				$verdict = \PublishRankSEO\Security\TrafficGuard::ip_matches_list( $ip, $lists[ $bot['ips'] ]['prefixes'] ) ? BotRegistry::VERIFIED : ( $bot['rdns'] ? null : BotRegistry::UNVERIFIED );
			}
		}
		if ( null === $verdict && $bot['rdns'] ) {
			self::queue( $token, $ip );
			return BotRegistry::PENDING;
		}
		if ( null === $verdict ) {
			$verdict = BotRegistry::UNCHECKABLE;
		}
		self::remember( $token, $ip, $verdict );
		return $verdict;
	}

	private static function remember( $token, $ip, $verdict ) {
		wp_cache_set( $token . '|' . $ip, (string) $verdict, 'publishrank_aiv', HOUR_IN_SECONDS );
		set_transient( 'pr_aiv_' . md5( $token . '|' . $ip ), (string) $verdict, DAY_IN_SECONDS );
	}

	private static function queue( $token, $ip ) {
		$q = get_option( self::QUEUE_OPT, array() );
		$q = is_array( $q ) ? $q : array();
		$k = $token . '|' . $ip;
		if ( isset( $q[ $k ] ) || count( $q ) >= 500 ) {
			return;
		}
		$q[ $k ] = time();
		update_option( self::QUEUE_OPT, $q, false );
	}

	/** Forward-confirmed reverse DNS. */
	public static function fcrdns( $ip, array $suffixes ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! function_exists( 'gethostbyaddr' ) ) {
			return false;
		}
		$host = @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure means "not verified".
		if ( ! $host || $host === $ip ) {
			return false;
		}
		$host = strtolower( rtrim( $host, '.' ) );
		$ok   = false;
		foreach ( $suffixes as $s ) {
			$s = strtolower( $s );
			if ( substr( $host, -strlen( $s ) ) === $s ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			return false;
		}
		$v6      = false !== strpos( $ip, ':' );
		$forward = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$recs = @dns_get_record( $host, $v6 ? DNS_AAAA : DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( (array) $recs as $r ) {
				$forward[] = $v6 ? ( $r['ipv6'] ?? '' ) : ( $r['ip'] ?? '' );
			}
		} elseif ( ! $v6 ) {
			$forward = (array) @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( $forward as $f ) {
			if ( $f && inet_pton( $f ) === inet_pton( $ip ) ) {
				return true;
			}
		}
		return false;
	}

	/** Background batch: resolve queued IPs and correct the visit log. @return int processed */
	public static function process_queue( $max = 40 ) {
		global $wpdb;
		$q = get_option( self::QUEUE_OPT, array() );
		if ( ! is_array( $q ) || ! $q ) {
			return 0;
		}
		$start = microtime( true );
		$n     = 0;
		foreach ( $q as $k => $t ) {
			if ( $n >= $max || microtime( true ) - $start > 20 ) {
				break;
			}
			unset( $q[ $k ] );
			list( $token, $ip ) = array_pad( explode( '|', $k, 2 ), 2, '' );
			$bot = BotRegistry::get( $token );
			if ( ! $bot ) {
				continue;
			}
			$verdict = self::fcrdns( $ip, $bot['rdns'] ) ? BotRegistry::VERIFIED : BotRegistry::UNVERIFIED;
			self::remember( $token, $ip, $verdict );
			if ( Database::ready( 'ai_bots' ) ) {
				$t1   = Database::table( 'ai_bots' );
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS day, COUNT(*) AS n FROM {$t1} WHERE bot = %s AND ip = %s AND verified = %d GROUP BY DATE(created_at)", $token, $ip, BotRegistry::PENDING ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "UPDATE {$t1} SET verified = %d WHERE bot = %s AND ip = %s AND verified = %d", $verdict, $token, $ip, BotRegistry::PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$t2 = Database::table( 'ai_bots_daily' );
				foreach ( (array) $rows as $r ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$t2} SET hits = GREATEST(0, CAST(hits AS SIGNED) - %d) WHERE day = %s AND bot = %s AND verified = %d", (int) $r['n'], $r['day'], $token, BotRegistry::PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
					$wpdb->query( $wpdb->prepare( "INSERT INTO {$t2} (day, bot, verified, hits) VALUES (%s, %s, %d, %d) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)", $r['day'], $token, $verdict, (int) $r['n'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				}
			}
			++$n;
		}
		update_option( self::QUEUE_OPT, $q, false );
		return $n;
	}

	public static function labels() {
		return array(
			BotRegistry::VERIFIED    => __( 'Verified', 'publishrank' ),
			BotRegistry::UNVERIFIED  => __( 'Spoofed', 'publishrank' ),
			BotRegistry::PENDING     => __( 'Checking', 'publishrank' ),
			BotRegistry::UNCHECKABLE => __( 'Not verifiable', 'publishrank' ),
		);
	}
}
