<?php
/**
 * Per-bot AI crawler control and the AI-bot visit log.
 *
 * Modes per crawler: allow · robots (robots.txt Disallow only) · block (robots.txt + HTTP 403 at the
 * server) · limit (robots allowed, HTTP 429 above a per-minute budget). Crawlers that claim a
 * verifiable user agent but fail verification can be refused as spoofed.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AI;

use PublishRankSEO\Core\Database;
use PublishRankSEO\Robots\RobotsTxt;

defined( 'ABSPATH' ) || exit;

final class CrawlerControl {
	const OPTION = 'publishrank_ai_crawlers';
	const MODES  = array( 'allow', 'robots', 'block', 'limit' );
	const CRON   = 'publishrank_ai_log_retention';

	/** @var array|null */
	private static $settings = null;
	/** @var array|null Visit captured for the shutdown logger. */
	private static $visit = null;

	public static function defaults() {
		return array(
			'rules'          => array(),
			'log_enabled'    => 1,
			'log_engines'    => 1,
			'log_seo'        => 1,
			'block_spoofed'  => 0,
			'retention_days' => 30,
			'max_per_minute' => 600,
		);
	}

	public static function settings() {
		if ( null === self::$settings ) {
			$s              = get_option( self::OPTION, array() );
			self::$settings = array_merge( self::defaults(), is_array( $s ) ? $s : array() );
		}
		return self::$settings;
	}

	/** Effective rule for one crawler. @return array{mode:string,limit:int,markdown:int} */
	public static function rule( $token ) {
		$s    = self::settings();
		$rule = isset( $s['rules'][ $token ] ) && is_array( $s['rules'][ $token ] ) ? $s['rules'][ $token ] : array();
		if ( ! isset( $rule['mode'] ) ) {
			$policy       = class_exists( '\\PublishRankSEO\\Robots\\RobotsTxt' ) ? RobotsTxt::policy() : array();
			$rule['mode'] = isset( $policy[ $token ] ) && 'block' === $policy[ $token ] ? 'robots' : 'allow';
		}
		return array(
			'mode'     => in_array( $rule['mode'], self::MODES, true ) ? $rule['mode'] : 'allow',
			'limit'    => max( 1, (int) ( $rule['limit'] ?? 60 ) ),
			'markdown' => isset( $rule['markdown'] ) ? (int) (bool) $rule['markdown'] : 1,
		);
	}

	/** Save settings and mirror robots decisions into the robots.txt policy. */
	public static function save( array $in ) {
		$s     = self::settings();
		$rules = array();
		$known = BotRegistry::all();
		foreach ( isset( $in['rules'] ) && is_array( $in['rules'] ) ? $in['rules'] : array() as $token => $r ) {
			if ( ! isset( $known[ $token ] ) || ! is_array( $r ) ) {
				continue;
			}
			$mode = isset( $r['mode'] ) ? sanitize_key( $r['mode'] ) : 'allow';
			if ( $known[ $token ]['robots_only'] && in_array( $mode, array( 'block', 'limit' ), true ) ) {
				$mode = 'robots'; // Robots-only tokens (Google-Extended, Applebot-Extended) never send requests.
			}
			$rules[ $token ] = array(
				'mode'     => in_array( $mode, self::MODES, true ) ? $mode : 'allow',
				'limit'    => min( 10000, max( 1, (int) ( $r['limit'] ?? 60 ) ) ),
				'markdown' => empty( $r['markdown'] ) ? 0 : 1,
			);
		}
		foreach ( array( 'log_enabled', 'log_engines', 'log_seo', 'block_spoofed' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$s[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			}
		}
		if ( isset( $in['retention_days'] ) ) {
			$s['retention_days'] = min( 365, max( 1, (int) $in['retention_days'] ) );
		}
		if ( isset( $in['max_per_minute'] ) ) {
			$s['max_per_minute'] = min( 100000, max( 10, (int) $in['max_per_minute'] ) );
		}
		if ( isset( $in['rules'] ) ) {
			$s['rules'] = $rules;
			// robots.txt policy stays the single source for the Disallow groups.
			$catalog = RobotsTxt::catalog();
			$policy  = RobotsTxt::policy();
			foreach ( $rules as $token => $r ) {
				if ( isset( $catalog[ $token ] ) ) {
					$policy[ $token ] = in_array( $r['mode'], array( 'robots', 'block' ), true ) ? 'block' : 'allow';
				}
			}
			update_option( RobotsTxt::OPT_AI_POLICY, RobotsTxt::sanitize_policy( $policy ) );
		}
		update_option( self::OPTION, $s, false );
		self::$settings = null;
	}

	public static function register() {
		add_action( 'init', array( __CLASS__, 'gate' ), 0 );
		add_action( self::CRON, array( __CLASS__, 'retention' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 300, 'daily', self::CRON );
				}
			},
			20
		);
		// Bots that are blocked in robots.txt but not represented in its catalogue still get a group.
		add_filter( 'robots_txt', array( __CLASS__, 'robots_extra' ), 60, 2 );
	}

	private static function front_request() {
		return ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) && isset( $_SERVER['REQUEST_METHOD'] );
	}

	public static function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path only, sanitised.
		$p   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return substr( '/' . ltrim( sanitize_text_field( $p ), '/' ), 0, 512 );
	}

	/** Decide what happens to a crawler request before WordPress does any real work. */
	public static function gate() {
		if ( ! self::front_request() ) {
			return;
		}
		$token = BotRegistry::current();
		if ( '' === $token ) {
			return;
		}
		$bot = BotRegistry::get( $token );
		$s   = self::settings();
		if ( 'seo' === $bot['purpose'] && ! $s['log_seo'] && ! isset( $s['rules'][ $token ] ) ) {
			return;
		}
		$ip      = \PublishRankSEO\Security\TrafficGuard::current_client_ip();
		$verdict = BotVerifier::verify( $token, $ip );
		$path    = self::request_path();
		$rule    = self::rule( $token );
		$action  = 'allowed';
		$exempt  = in_array( strtolower( $path ), array( '/robots.txt', '/llms.txt', '/ads.txt' ), true );
		if ( ! $exempt ) {
			if ( $s['block_spoofed'] && BotRegistry::UNVERIFIED === $verdict ) {
				$action = 'spoofed';
			} elseif ( 'block' === $rule['mode'] ) {
				$action = 'blocked';
			} elseif ( 'limit' === $rule['mode'] && self::over_limit( $token, $rule['limit'] ) ) {
				$action = 'limited';
			}
		}
		self::$visit = array(
			'bot'      => $token,
			'operator' => $bot['operator'],
			'purpose'  => $bot['purpose'],
			'verified' => $verdict,
			'method'   => isset( $_SERVER['REQUEST_METHOD'] ) ? substr( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ), 0, 10 ) : 'get',
			'ip'       => $ip,
			'path'     => $path,
			'action'   => $action,
		);
		if ( $s['log_enabled'] && ( 'searchengine' !== $bot['purpose'] || $s['log_engines'] ) ) {
			add_action( 'shutdown', array( __CLASS__, 'log_visit' ), 0 );
		}
		if ( 'allowed' === $action ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex', true );
		header( 'Content-Type: text/plain; charset=utf-8', true );
		if ( 'limited' === $action ) {
			status_header( 429 );
			header( 'Retry-After: 60', true );
			echo 'Too many requests. Slow down and try again later.';
		} else {
			status_header( 403 );
			echo 'spoofed' === $action ? 'Forbidden: this request claims to be a crawler but did not come from its operator.' : 'Forbidden: this site does not allow this crawler. See /robots.txt.';
		}
		exit;
	}

	/** Mark that this request is answered as Markdown (logged as "markdown"). */
	public static function mark_markdown() {
		if ( is_array( self::$visit ) && 'allowed' === self::$visit['action'] ) {
			self::$visit['action'] = 'markdown';
		}
	}

	private static function over_limit( $token, $limit ) {
		$key = 'pr_ai_rl_' . md5( $token ) . '_' . gmdate( 'YmdHi' );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'publishrank_ai', 120 );
			$n = (int) wp_cache_incr( $key, 1, 'publishrank_ai' );
		} else {
			$n = (int) get_transient( $key ) + 1;
			set_transient( $key, $n, 120 );
		}
		return $n > $limit;
	}

	public static function log_visit() {
		global $wpdb;
		$v = self::$visit;
		if ( ! is_array( $v ) || ! Database::ready( 'ai_bots' ) ) {
			return;
		}
		$status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		$now    = Database::now();
		$day    = substr( $now, 0, 10 );
		// The daily total is always exact; individual rows are capped per minute so a crawl storm cannot flood the table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Database::table( 'ai_bots_daily' ) . ' (day, bot, verified, hits) VALUES (%s, %s, %d, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', $day, $v['bot'], (int) $v['verified'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$cap = (int) self::settings()['max_per_minute'];
		$key = 'pr_ai_rows_' . gmdate( 'YmdHi' );
		$n   = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, 120 );
		if ( $n > $cap ) {
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Database::table( 'ai_bots' ),
			array(
				'created_at' => $now,
				'bot'        => $v['bot'],
				'operator'   => substr( $v['operator'], 0, 60 ),
				'purpose'    => $v['purpose'],
				'verified'   => (int) $v['verified'],
				'method'     => strtoupper( $v['method'] ),
				'ip'         => substr( (string) $v['ip'], 0, 45 ),
				'path'       => $v['path'],
				'status'     => $status,
				'action'     => $v['action'],
			)
		);
	}

	public static function retention() {
		$days = (int) self::settings()['retention_days'];
		Database::purge_older_than( 'ai_bots', 'created_at', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
		Database::purge_older_than( 'ai_bots_daily', 'day', gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) );
	}

	/** Add robots.txt groups for blocked registry crawlers the robots catalogue does not list. */
	public static function robots_extra( $output, $public ) {
		if ( '0' === (string) $public ) {
			return $output;
		}
		$catalog = RobotsTxt::catalog();
		$lines   = array();
		foreach ( BotRegistry::ai_tokens() as $token ) {
			if ( isset( $catalog[ $token ] ) ) {
				continue;
			}
			$r = self::rule( $token );
			if ( in_array( $r['mode'], array( 'robots', 'block' ), true ) && ! preg_match( '/^User-agent:\s*' . preg_quote( $token, '/' ) . '\s*$/mi', (string) $output ) ) {
				$lines[] = 'User-agent: ' . $token;
				$lines[] = 'Disallow: /';
				$lines[] = '';
			}
		}
		return $lines ? rtrim( (string) $output ) . "\n\n# PublishRank AI Crawler Control\n" . implode( "\n", $lines ) : $output;
	}

	/* ---------------------------------------------------------------- Reports */

	/** @return array<string,array{total:int,verified:int,spoofed:int,pending:int,unverifiable:int}> last N days */
	public static function totals( $days = 30 ) {
		global $wpdb;
		$out = array();
		if ( ! Database::ready( 'ai_bots_daily' ) ) {
			return $out;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT bot, verified, SUM(hits) AS hits FROM ' . Database::table( 'ai_bots_daily' ) . ' WHERE day >= %s GROUP BY bot, verified', gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$map  = array( BotRegistry::VERIFIED => 'verified', BotRegistry::UNVERIFIED => 'spoofed', BotRegistry::PENDING => 'pending', BotRegistry::UNCHECKABLE => 'unverifiable' );
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $out[ $r['bot'] ] ) ) {
				$out[ $r['bot'] ] = array( 'total' => 0, 'verified' => 0, 'spoofed' => 0, 'pending' => 0, 'unverifiable' => 0 );
			}
			$out[ $r['bot'] ]['total']                   += (int) $r['hits'];
			$out[ $r['bot'] ][ $map[ (int) $r['verified'] ] ?? 'unverifiable' ] += (int) $r['hits'];
		}
		uasort( $out, static function ( $a, $b ) {
			return $b['total'] - $a['total'];
		} );
		return $out;
	}

	/** Daily chart points: v = AI crawlers (training, search, assistants), v2 = search engines. */
	public static function series( $days = 30 ) {
		global $wpdb;
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = array( 'v' => 0, 'v2' => 0 );
		}
		if ( Database::ready( 'ai_bots_daily' ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, bot, SUM(hits) AS hits FROM ' . Database::table( 'ai_bots_daily' ) . ' WHERE day >= %s GROUP BY day, bot', gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				$b = BotRegistry::get( $r['bot'] );
				if ( ! $b || ! isset( $out[ $r['day'] ] ) ) {
					continue;
				}
				if ( in_array( $b['purpose'], array( 'training', 'search', 'assistant' ), true ) ) {
					$out[ $r['day'] ]['v'] += (int) $r['hits'];
				} elseif ( 'searchengine' === $b['purpose'] ) {
					$out[ $r['day'] ]['v2'] += (int) $r['hits'];
				}
			}
		}
		$list = array();
		foreach ( $out as $day => $v ) {
			$list[] = array( 'l' => $day, 'v' => $v['v'], 'v2' => $v['v2'] );
		}
		return $list;
	}

	/** @return array{rows:array,total:int} */
	public static function visits( array $f ) {
		global $wpdb;
		if ( ! Database::ready( 'ai_bots' ) ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$t     = Database::table( 'ai_bots' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['bot'] ) ) {
			$where[] = 'bot = %s';
			$args[]  = (string) $f['bot'];
		}
		if ( ! empty( $f['purpose'] ) ) {
			$where[] = 'purpose = %s';
			$args[]  = sanitize_key( $f['purpose'] );
		}
		if ( isset( $f['verified'] ) && '' !== (string) $f['verified'] ) {
			$where[] = 'verified = %d';
			$args[]  = (int) $f['verified'];
		}
		if ( ! empty( $f['action'] ) ) {
			$where[] = 'action = %s';
			$args[]  = sanitize_key( $f['action'] );
		}
		if ( ! empty( $f['search'] ) ) {
			$where[] = '(path LIKE %s OR ip LIKE %s)';
			$like    = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
		}
		if ( ! empty( $f['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $f['to'] . ' 23:59:59';
		}
		$sql   = implode( ' AND ', $where );
		$per   = max( 1, min( 1000, (int) ( $f['per_page'] ?? 50 ) ) );
		$page  = max( 1, (int) ( $f['paged'] ?? 1 ) );
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$sql}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	/** Most-fetched paths by AI crawlers (last 7 days). */
	public static function top_paths( $limit = 15, $bot = '' ) {
		global $wpdb;
		if ( ! Database::ready( 'ai_bots' ) ) {
			return array();
		}
		$t    = Database::table( 'ai_bots' );
		$args = array( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) );
		$w    = "created_at >= %s AND purpose IN ('training','search','assistant')";
		if ( '' !== $bot ) {
			$w     .= ' AND bot = %s';
			$args[] = $bot;
		}
		$args[] = (int) $limit;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT path, COUNT(*) AS hits, COUNT(DISTINCT bot) AS bots, SUM(action = 'markdown') AS markdown FROM {$t} WHERE {$w} GROUP BY path ORDER BY hits DESC LIMIT %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
