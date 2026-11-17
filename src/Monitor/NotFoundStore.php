<?php
/**
 * 404 Monitor v2 storage.
 *
 * - One row per path in {prefix}publishrank_404 (unique path_hash) plus a daily rollup per visitor class.
 * - Hits are buffered during the request and written once on shutdown with
 *   INSERT … ON DUPLICATE KEY UPDATE hits = hits + n, so concurrent requests never lose counts.
 * - Above a configurable hits-per-minute ceiling only one request in N is written, with weight N,
 *   so totals stay statistically correct while the database is protected during floods.
 * - Privacy: no IP addresses are stored; referrers are reduced to host + path (no query string).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Monitor;

use PublishRankSEO\AI\BotRegistry;
use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class NotFoundStore {
	const LEGACY_OPTION = 'publishrank_seo_404_log';
	const MIGRATED_OPT  = 'publishrank_404_migrated';
	const CRON          = 'publishrank_404_retention';
	const CLASSES       = array( 'human', 'mobile', 'search', 'ai', 'scanner' );
	const STATUSES      = array( 'open', 'ignored', 'redirected' );

	/** @var array<string,array> path_hash => buffered hit. */
	private static $buffer = array();
	/** @var bool */
	private static $hooked = false;

	/* ------------------------------------------------------------ Settings */

	public static function enabled() {
		return (bool) get_option( 'publishrank_seo_404_monitor', false );
	}

	public static function retention_days() {
		return max( 1, min( 3650, (int) get_option( 'publishrank_404_retention_days', 90 ) ) );
	}

	/** Hits per minute written in full before sampling starts. */
	public static function ceiling() {
		return max( 10, (int) get_option( 'publishrank_404_rate_ceiling', 600 ) );
	}

	public static function sample_rate() {
		return max( 2, min( 1000, (int) get_option( 'publishrank_404_sample_rate', 10 ) ) );
	}

	/** Built-in scanner / probe patterns (regular expressions, case-insensitive). */
	public static function default_noise() {
		return array(
			'(^|/)wp-config\.php',
			'(^|/)\.env(\.|$|/)',
			'(^|/)\.git(/|$)',
			'(^|/)\.(aws|ssh|svn|hg|DS_Store|htpasswd|htaccess)',
			'(^|/)xmlrpc\.php',
			'(^|/)(phpmyadmin|pma|adminer|myadmin)(/|\.php|$)',
			'\.(sql|bak|old|swp|zip|tar|gz|7z|rar)$',
			'(^|/)(vendor|node_modules)/',
			'(^|/)(cgi-bin|shell|cmd|eval-stdin)\b',
			'\.(asp|aspx|jsp|cgi)$',
			'(^|/)wp-(includes|content)/.+\.php$',
			'(^|/)(wlwmanifest\.xml|license\.txt|readme\.html)$',
			'(\.\./|%2e%2e)',
			'(^|/)(owa|ecp|autodiscover|actuator|solr|boaform|hudson|jenkins|manager/html)(/|$)',
		);
	}

	/** @return string[] Active noise patterns (built-in + site rules; lines starting with ! disable a built-in). */
	public static function noise_rules() {
		static $cache = null;
		if ( null !== $cache && ! ( defined( 'WP_CLI' ) && WP_CLI && did_action( 'updated_option' ) ) ) {
			return $cache;
		}
		$rules  = self::default_noise();
		$custom = preg_split( '/\r\n|\r|\n/', (string) get_option( 'publishrank_404_noise_rules', '' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $custom as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( 0 === strpos( $line, '!' ) ) {
				$rules = array_values( array_diff( $rules, array( substr( $line, 1 ) ) ) );
				continue;
			}
			// Plain text with * becomes a glob; anything else is a regular expression.
			if ( false === strpbrk( $line, '()[]^$\\|+?' ) ) {
				$line = str_replace( '\*', '.*', preg_quote( $line, '#' ) );
			}
			if ( false !== @preg_match( '#' . str_replace( '#', '\#', $line ) . '#i', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validating a user pattern.
				$rules[] = $line;
			}
		}
		$cache = array_values( array_unique( $rules ) );
		return $cache;
	}

	public static function is_noise( $path ) {
		foreach ( self::noise_rules() as $re ) {
			if ( 1 === @preg_match( '#' . str_replace( '#', '\#', $re ) . '#i', (string) $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return true;
			}
		}
		return false;
	}

	/** Visitor class: human, mobile, search (crawler), ai (crawler) or scanner. */
	public static function classify( $path, $ua ) {
		if ( self::is_noise( $path ) ) {
			return 'scanner';
		}
		$ua = (string) $ua;
		if ( '' === trim( $ua ) || preg_match( '/(curl|wget|python-requests|go-http-client|libwww|nikto|sqlmap|masscan|zgrab|nmap|httpclient|okhttp|scrapy)/i', $ua ) ) {
			return 'scanner';
		}
		if ( class_exists( BotRegistry::class ) ) {
			$token = BotRegistry::identify( $ua );
			if ( '' !== $token ) {
				$bot = BotRegistry::get( $token );
				return $bot && in_array( $bot['purpose'], array( 'training', 'search', 'assistant' ), true ) ? 'ai' : 'search';
			}
		}
		if ( preg_match( '/bot|spider|crawler|slurp|archiver|facebookexternalhit|embedly|preview/i', $ua ) ) {
			return 'search';
		}
		return preg_match( '/mobile|android|iphone|ipad/i', $ua ) ? 'mobile' : 'human';
	}

	/** Host and path of a referrer, never the query string. @return array{0:string,1:string} */
	public static function referrer_parts( $raw ) {
		$parts = wp_parse_url( (string) $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array( '', '' );
		}
		$host = strtolower( substr( (string) $parts['host'], 0, 191 ) );
		$path = isset( $parts['path'] ) ? substr( sanitize_text_field( $parts['path'] ), 0, 255 ) : '/';
		return array( $host, $path );
	}

	public static function normalise_path( $raw ) {
		$path = wp_parse_url( (string) $raw, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';
		$path = '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );
		$path = function_exists( 'mb_substr' ) ? mb_substr( $path, 0, 512 ) : substr( $path, 0, 512 );
		return sanitize_text_field( $path );
	}

	public static function hash( $path ) {
		return md5( strtolower( (string) $path ) );
	}

	/* ------------------------------------------------------------ Writes */

	/**
	 * Record one 404 hit (buffered). Returns the weight written (0 when sampled out).
	 *
	 * @param string $path    Normalised path.
	 * @param string $ua      User agent.
	 * @param string $referer Referrer URL.
	 */
	public static function record( $path, $ua = '', $referer = '' ) {
		$weight = self::sampling_weight();
		if ( 0 === $weight ) {
			return 0;
		}
		$class            = self::classify( $path, $ua );
		list( $rh, $rp )  = self::referrer_parts( $referer );
		$h                = self::hash( $path );
		if ( ! isset( self::$buffer[ $h ] ) ) {
			self::$buffer[ $h ] = array( 'path' => $path, 'n' => 0, 'by' => array_fill_keys( self::CLASSES, 0 ), 'rh' => '', 'rp' => '', 'class' => $class );
		}
		self::$buffer[ $h ]['n']             += $weight;
		self::$buffer[ $h ]['by'][ $class ] += $weight;
		if ( '' !== $rh ) {
			self::$buffer[ $h ]['rh'] = $rh;
			self::$buffer[ $h ]['rp'] = $rp;
		}
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
		}
		return $weight;
	}

	/** 1 = write fully; N = sampled write with weight N; 0 = skipped. */
	private static function sampling_weight() {
		$key   = 'publishrank_404_rpm_' . gmdate( 'YmdHi' );
		$count = wp_cache_incr( $key, 1, 'publishrank_404' );
		if ( false === $count ) {
			// No persistent object cache: fall back to a short transient counter.
			$count = (int) get_transient( $key ) + 1;
			set_transient( $key, $count, 120 );
		}
		if ( $count <= self::ceiling() ) {
			return 1;
		}
		$rate = self::sample_rate();
		return 0 === ( $count % $rate ) ? $rate : 0;
	}

	/** Write the buffer: one upsert per distinct path + one per (day, class). */
	public static function flush() {
		global $wpdb;
		if ( ! self::$buffer || ! Database::ready( '404' ) ) {
			self::$buffer = array();
			return 0;
		}
		$t     = Database::table( '404' );
		$d     = Database::table( '404_daily' );
		$now   = current_time( 'mysql', true );
		$day   = gmdate( 'Y-m-d' );
		$daily = array_fill_keys( self::CLASSES, 0 );
		$n     = 0;
		foreach ( self::$buffer as $h => $b ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT INTO {$t} (path_hash, path, first_seen, last_seen, hits, hits_human, hits_search, hits_ai, hits_scanner, referrer_host, top_referrer_path, ua_class, status)
					VALUES (%s, %s, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s, 'open')
					ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), hits_human = hits_human + VALUES(hits_human), hits_search = hits_search + VALUES(hits_search), hits_ai = hits_ai + VALUES(hits_ai), hits_scanner = hits_scanner + VALUES(hits_scanner), last_seen = VALUES(last_seen),
					referrer_host = IF(VALUES(referrer_host) <> '', VALUES(referrer_host), referrer_host), top_referrer_path = IF(VALUES(referrer_host) <> '', VALUES(top_referrer_path), top_referrer_path),
					ua_class = VALUES(ua_class), status = IF(status = 'redirected', 'open', status)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$h,
					$b['path'],
					$now,
					$now,
					$b['n'],
					$b['by']['human'] + $b['by']['mobile'],
					$b['by']['search'],
					$b['by']['ai'],
					$b['by']['scanner'],
					$b['rh'],
					$b['rp'],
					$b['class']
				)
			);
			foreach ( $b['by'] as $class => $c ) {
				$daily[ $class ] += $c;
			}
			$n += $b['n'];
		}
		foreach ( $daily as $class => $c ) {
			if ( $c > 0 ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$d} (day, ua_class, hits) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)", $day, $class, $c ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			}
		}
		self::$buffer = array();
		return $n;
	}

	/* ------------------------------------------------------------ Reads */

	/** @return array{0:string,1:array} */
	private static function where( array $f ) {
		global $wpdb;
		$sql  = array( '1=1' );
		$args = array();
		if ( ! empty( $f['search'] ) ) {
			$sql[]  = '(path LIKE %s OR referrer_host LIKE %s)';
			$like   = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
			$args[] = $like;
			$args[] = $like;
		}
		if ( ! empty( $f['class'] ) ) {
			if ( 'crawler' === $f['class'] ) {
				$sql[] = '(hits_search > 0 OR hits_ai > 0)';
			} elseif ( in_array( $f['class'], self::CLASSES, true ) ) {
				$col   = array( 'human' => 'hits_human', 'mobile' => 'hits_human', 'search' => 'hits_search', 'ai' => 'hits_ai', 'scanner' => 'hits_scanner' )[ $f['class'] ];
				$sql[] = $col . ' > 0';
			}
		}
		if ( isset( $f['noise'] ) && 'hide' === $f['noise'] ) {
			$sql[] = "ua_class <> 'scanner'";
		}
		if ( ! empty( $f['status'] ) && in_array( $f['status'], self::STATUSES, true ) ) {
			$sql[]  = 'status = %s';
			$args[] = $f['status'];
		}
		if ( ! empty( $f['min_hits'] ) ) {
			$sql[]  = 'hits >= %d';
			$args[] = (int) $f['min_hits'];
		}
		if ( ! empty( $f['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['from'] ) ) {
			$sql[]  = 'last_seen >= %s';
			$args[] = $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['to'] ) ) {
			$sql[]  = 'last_seen <= %s';
			$args[] = $f['to'] . ' 23:59:59';
		}
		return array( implode( ' AND ', $sql ), $args );
	}

	/** @return array{rows:array,total:int} */
	public static function query( array $f = array() ) {
		global $wpdb;
		if ( ! Database::ready( '404' ) ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$t     = Database::table( '404' );
		$per   = max( 1, min( 500, (int) ( $f['per_page'] ?? 50 ) ) );
		$page  = max( 1, (int) ( $f['paged'] ?? 1 ) );
		$order = isset( $f['orderby'] ) && in_array( $f['orderby'], array( 'hits', 'last_seen', 'first_seen', 'path', 'hits_search', 'hits_ai' ), true ) ? $f['orderby'] : 'hits';
		$dir   = isset( $f['order'] ) && 'asc' === strtolower( (string) $f['order'] ) ? 'ASC' : 'DESC';
		list( $sql, $args ) = self::where( $f );
		$count = 'SELECT COUNT(*) FROM ' . $t . ' WHERE ' . $sql;
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count, $args ) ) : $wpdb->get_var( $count ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$args[] = $per;
		$args[] = ( $page - 1 ) * $per;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql} ORDER BY {$order} {$dir}, id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $i => $r ) {
			foreach ( array( 'id', 'hits', 'hits_human', 'hits_search', 'hits_ai', 'hits_scanner' ) as $k ) {
				$rows[ $i ][ $k ] = (int) $r[ $k ];
			}
		}
		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	public static function get( $id ) {
		global $wpdb;
		return Database::ready( '404' ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( '404' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function count( array $f = array() ) {
		$f['per_page'] = 1;
		return self::query( $f )['total'];
	}

	/** Summary numbers for KPI tiles. */
	public static function summary( $days = 30 ) {
		global $wpdb;
		$out = array( 'paths' => 0, 'open' => 0, 'hits' => 0, 'human' => 0, 'search' => 0, 'ai' => 0, 'scanner' => 0, 'today' => 0 );
		if ( ! Database::ready( '404' ) ) {
			return $out;
		}
		$t = Database::table( '404' );
		$d = Database::table( '404_daily' );
		$out['paths'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out['open']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'open' AND ua_class <> 'scanner'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ua_class, SUM(hits) AS h FROM {$d} WHERE day >= %s GROUP BY ua_class", gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$k = 'mobile' === $r['ua_class'] ? 'human' : $r['ua_class'];
			if ( isset( $out[ $k ] ) ) {
				$out[ $k ] += (int) $r['h'];
			}
			$out['hits'] += (int) $r['h'];
		}
		$out['today'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(hits) FROM {$d} WHERE day = %s", gmdate( 'Y-m-d' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return $out;
	}

	/** Daily chart points: v = people, v2 = crawlers (search + AI). Scanners are excluded. */
	public static function series( $days = 30 ) {
		global $wpdb;
		$pts = array();
		$by  = array();
		if ( Database::ready( '404_daily' ) ) {
			$d = Database::table( '404_daily' );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, ua_class, hits FROM {$d} WHERE day >= %s", gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ) ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$by[ $r['day'] ][ $r['ua_class'] ] = (int) $r['hits'];
			}
		}
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day   = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$c     = isset( $by[ $day ] ) ? $by[ $day ] : array();
			$pts[] = array( 'l' => gmdate( 'm-d', strtotime( $day ) ), 'v' => (int) ( $c['human'] ?? 0 ) + (int) ( $c['mobile'] ?? 0 ), 'v2' => (int) ( $c['search'] ?? 0 ) + (int) ( $c['ai'] ?? 0 ) );
		}
		return $pts;
	}

	/** Days whose people+crawler hits exceed 3× the trailing median (spike detection). */
	public static function spikes( $days = 30 ) {
		$pts  = self::series( $days );
		$vals = array_map( static function ( $p ) { return $p['v'] + $p['v2']; }, $pts );
		$sorted = $vals;
		sort( $sorted );
		$median = $sorted ? $sorted[ (int) floor( count( $sorted ) / 2 ) ] : 0;
		$out    = array();
		foreach ( $pts as $i => $p ) {
			if ( $vals[ $i ] >= max( 20, 3 * $median ) ) {
				$out[] = array( 'day' => $p['l'], 'hits' => $vals[ $i ], 'median' => $median );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ Suggestions */

	/** Seconds spent on uncached suggestion lookups during this request (render budget). */
	private static $suggest_spent = 0.0;

	const SUGGEST_VER = 'publishrank_404_suggest_ver';

	/**
	 * Fuzzy redirect suggestions for a missing address.
	 *
	 * Results are cached per address for 12 hours and invalidated whenever published content
	 * changes. An indexed prefix match runs first; the slower substring scan runs only when it
	 * finds nothing. When $budget (seconds) is set and uncached lookups in this request already
	 * used it up, null is returned so the caller can load the suggestion later (the admin list
	 * fetches it over REST) instead of making the screen slow on large sites.
	 *
	 * @return array|null
	 */
	public static function suggestions( $path, $limit = 3, $budget = 0.0 ) {
		global $wpdb;
		$seg   = trim( (string) basename( untrailingslashit( (string) $path ) ) );
		$seg   = strtolower( preg_replace( '/\.(html?|php|aspx?)$/i', '', $seg ) );
		$words = array_values( array_filter( preg_split( '/[^a-z0-9]+/', $seg ), static function ( $w ) { return strlen( $w ) >= 4 && ! is_numeric( $w ); } ) );
		if ( ! $words ) {
			return array();
		}
		$limit = max( 1, min( 10, (int) $limit ) );
		$key   = 'pr404s_' . md5( (int) get_option( self::SUGGEST_VER, 1 ) . '|' . $seg . '|' . $limit );
		$hit   = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		if ( $budget > 0 && self::$suggest_spent >= $budget ) {
			return null;
		}
		$t0 = microtime( true );
		usort( $words, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		$types = array_values( array_diff( array_values( get_post_types( array( 'public' => true ) ) ), array( 'attachment' ) ) );
		if ( ! $types ) {
			return array();
		}
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$w1    = $words[0];
		$w2    = isset( $words[1] ) ? $words[1] : $words[0];
		$base  = "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ({$in}) AND ";
		// Candidate rows depend only on the two words, so many similar addresses share one lookup.
		$rkey = 'pr404c_' . md5( (int) get_option( self::SUGGEST_VER, 1 ) . '|' . implode( ',', $types ) . '|' . $w1 . '|' . $w2 );
		$rows = get_transient( $rkey );
		if ( ! is_array( $rows ) ) {
		// 1) Indexed: slug starts with one of the two strongest words (uses the post_name index).
		$rows = $wpdb->get_results( $wpdb->prepare( $base . '(post_name LIKE %s OR post_name LIKE %s) LIMIT 60', array_merge( $types, array( $wpdb->esc_like( $w1 ) . '%', $wpdb->esc_like( $w2 ) . '%' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		// 2) Fallback: the word anywhere in the slug (full scan, so only when 1) found nothing).
		if ( ! $rows ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $base . '(post_name LIKE %s OR post_name LIKE %s) ORDER BY post_date DESC LIMIT 60', array_merge( $types, array( '%' . $wpdb->esc_like( $w1 ) . '%', '%' . $wpdb->esc_like( $w2 ) . '%' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$rows = array_map( static function ( $r ) { return array( 'ID' => (int) $r->ID, 'post_name' => (string) $r->post_name ); }, (array) $rows );
		set_transient( $rkey, $rows, 12 * HOUR_IN_SECONDS );
		}
		$out = array();
		foreach ( $rows as $r ) {
			$r = (object) $r;
			similar_text( $seg, (string) $r->post_name, $pct );
			if ( $pct >= 45 ) {
				$out[] = array( 'id' => (int) $r->ID, 'title' => get_the_title( (int) $r->ID ), 'url' => (string) get_permalink( (int) $r->ID ), 'score' => (int) round( $pct ) );
			}
		}
		usort( $out, static function ( $a, $b ) { return $b['score'] - $a['score']; } );
		$out = array_slice( $out, 0, $limit );
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
		self::$suggest_spent += microtime( true ) - $t0;
		return $out;
	}

	/** Published content changed: start a new suggestion cache generation. */
	public static function bump_suggestions( $a = null, $b = null, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : ( $a instanceof \WP_Post ? $a : get_post( is_numeric( $a ) ? (int) $a : 0 ) );
		if ( $post && ( 'publish' === $post->post_status || 'publish' === $b || 'publish' === $a ) ) {
			update_option( self::SUGGEST_VER, (int) get_option( self::SUGGEST_VER, 1 ) + 1, false );
		}
	}

	/* ------------------------------------------------------------ Actions */

	public static function set_status( array $ids, $status ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids || ! in_array( $status, self::STATUSES, true ) ) {
			return 0;
		}
		$in = implode( ',', $ids );
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::table( '404' ) . " SET status = %s WHERE id IN ({$in})", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( 'DELETE FROM ' . Database::table( '404' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** Remove every row (and the daily rollup when $all). */
	public static function clear( $all = true ) {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Database::table( '404' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $all ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Database::table( '404_daily' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Create a redirect from a 404 row and mark it redirected. The row is kept (status redirected)
	 * so it can be found again; it reopens automatically if the address keeps failing.
	 *
	 * @return int|\WP_Error Redirect id.
	 */
	public static function redirect( $id, $target, $status = 301 ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new \WP_Error( 'missing', __( 'That 404 entry no longer exists.', 'publishrank' ) );
		}
		$res = \PublishRankSEO\Redirect\RedirectRepository::save(
			array(
				'source' => $row['path'],
				'target' => (string) $target,
				'status' => (int) $status,
				'origin' => '404',
				'note'   => sprintf( /* translators: %s: hits */ __( 'Created from the 404 monitor (%s hits).', 'publishrank' ), number_format_i18n( (int) $row['hits'] ) ),
			)
		);
		if ( ! is_wp_error( $res ) ) {
			self::set_status( array( (int) $id ), 'redirected' );
		}
		return $res;
	}

	/** Export rows as CSV arrays (header first). */
	public static function export_rows( array $f = array() ) {
		$out  = array( array( 'path', 'hits', 'people', 'search_crawlers', 'ai_crawlers', 'scanners', 'first_seen', 'last_seen', 'referrer_host', 'referrer_path', 'class', 'status' ) );
		$page = 1;
		do {
			$res = self::query( $f + array( 'per_page' => 500, 'paged' => $page, 'orderby' => 'hits' ) );
			foreach ( $res['rows'] as $r ) {
				$out[] = array( $r['path'], $r['hits'], $r['hits_human'], $r['hits_search'], $r['hits_ai'], $r['hits_scanner'], $r['first_seen'], $r['last_seen'], $r['referrer_host'], $r['top_referrer_path'], $r['ua_class'], $r['status'] );
			}
			++$page;
		} while ( 500 === count( $res['rows'] ) && $page < 1000 );
		return $out;
	}

	/* ------------------------------------------------------------ Retention & migration */

	public static function register_cron() {
		add_action( self::CRON, array( __CLASS__, 'retention' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'bump_suggestions' ), 10, 3 );
		add_action( 'post_updated', static function ( $id, $after, $before ) { if ( $after->post_name !== $before->post_name && 'publish' === $after->post_status ) { NotFoundStore::bump_suggestions( $after ); } }, 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'bump_suggestions' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 900, 'daily', self::CRON );
				}
			},
			20
		);
	}

	/** Delete rows (and daily rollups) older than the retention window, in batches of 5,000. */
	public static function retention( $max_batches = 40 ) {
		global $wpdb;
		if ( ! Database::ready( '404' ) ) {
			return 0;
		}
		$cut     = gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS );
		$deleted = 0;
		for ( $i = 0; $i < $max_batches; $i++ ) {
			$n        = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( '404' ) . ' WHERE last_seen < %s LIMIT 5000', $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$deleted += $n;
			if ( $n < 5000 ) {
				break;
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( '404_daily' ) . ' WHERE day < %s', gmdate( 'Y-m-d', time() - max( 400, self::retention_days() ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $deleted;
	}

	/**
	 * Move the 2.22 option log into the table. The option is deleted only after every path is
	 * present in the table with at least the old hit count.
	 *
	 * @return array{migrated:int,total:int,deleted_option:bool}
	 */
	public static function migrate_legacy() {
		global $wpdb;
		$out = array( 'migrated' => 0, 'total' => 0, 'deleted_option' => false );
		if ( ! Database::ready( '404' ) || get_option( self::MIGRATED_OPT ) ) {
			return $out;
		}
		$log = get_option( self::LEGACY_OPTION, array() );
		$log = is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
		$t   = Database::table( '404' );
		$expect = array();
		foreach ( $log as $e ) {
			$path = self::normalise_path( (string) ( $e['path'] ?? '' ) );
			if ( '' === $path ) {
				continue;
			}
			$hits  = max( 1, absint( $e['count'] ?? 1 ) );
			$first = (string) ( $e['first_seen'] ?? ( $e['time'] ?? current_time( 'mysql', true ) ) );
			$last  = (string) ( $e['last_seen'] ?? $first );
			$cat   = (string) ( $e['agent_category'] ?? 'browser' );
			$class = 'crawler' === $cat ? 'search' : ( 'mobile' === $cat ? 'mobile' : 'human' );
			if ( self::is_noise( $path ) ) {
				$class = 'scanner';
			}
			list( $rh, $rp ) = self::referrer_parts( (string) ( $e['referrer'] ?? '' ) );
			$h = self::hash( $path );
			$expect[ $h ] = ( $expect[ $h ] ?? 0 ) + $hits;
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT INTO {$t} (path_hash, path, first_seen, last_seen, hits, hits_human, hits_search, hits_ai, hits_scanner, referrer_host, top_referrer_path, ua_class, status) VALUES (%s,%s,%s,%s,%d,%d,%d,0,%d,%s,%s,%s,%s)
					ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), hits_human = hits_human + VALUES(hits_human), hits_search = hits_search + VALUES(hits_search), hits_scanner = hits_scanner + VALUES(hits_scanner), first_seen = LEAST(first_seen, VALUES(first_seen)), last_seen = GREATEST(last_seen, VALUES(last_seen))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$h,
					$path,
					$first,
					$last,
					$hits,
					in_array( $class, array( 'human', 'mobile' ), true ) ? $hits : 0,
					'search' === $class ? $hits : 0,
					'scanner' === $class ? $hits : 0,
					$rh,
					$rp,
					$class,
					! empty( $e['resolved'] ) ? 'ignored' : 'open'
				)
			);
			++$out['migrated'];
		}
		$out['total'] = count( $log );
		// Verify before removing the option: every path present with at least the expected hits.
		$ok = true;
		foreach ( array_chunk( array_keys( $expect ), 200 ) as $chunk ) {
			$in    = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT path_hash, hits FROM {$t} WHERE path_hash IN ({$in})", $chunk ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( $chunk as $h ) {
				if ( ! isset( $found[ $h ] ) || (int) $found[ $h ]->hits < $expect[ $h ] ) {
					$ok = false;
					break 2;
				}
			}
		}
		if ( $ok ) {
			update_option( self::LEGACY_OPTION . '_backup_2_23', $log, false );
			delete_option( self::LEGACY_OPTION );
			update_option( self::MIGRATED_OPT, array( 'at' => time(), 'rows' => count( $expect ), 'entries' => count( $log ) ), false );
			$out['deleted_option'] = true;
		}
		return $out;
	}

	/** Compatibility view in the 2.22 option format for older consumers. @return array<int,array> */
	public static function legacy_rows( $limit = 500 ) {
		$res = self::query( array( 'per_page' => min( 500, $limit ), 'orderby' => 'hits', 'noise' => 'hide' ) );
		return array_map(
			static function ( $r ) {
				return array(
					'path'           => $r['path'],
					'time'           => $r['first_seen'],
					'first_seen'     => $r['first_seen'],
					'last_seen'      => $r['last_seen'],
					'count'          => (int) $r['hits'],
					'referrer'       => $r['referrer_host'] ? 'https://' . $r['referrer_host'] . $r['top_referrer_path'] : '',
					'agent_category' => in_array( $r['ua_class'], array( 'search', 'ai' ), true ) ? 'crawler' : ( 'mobile' === $r['ua_class'] ? 'mobile' : 'browser' ),
					'resolved'       => 'open' !== $r['status'],
				);
			},
			$res['rows']
		);
	}
}
