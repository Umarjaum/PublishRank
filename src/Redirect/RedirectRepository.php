<?php
/**
 * Redirect Engine v2 storage: indexed custom table, validation, chain flattening, history and rollback.
 *
 * Lookup order at runtime: exact hash (one indexed query) → prefix / wildcard → regex candidates
 * from a compiled, non-autoloaded pattern cache. Exact rules scale to the configured cap
 * (default 100,000); pattern rules are bounded separately so each request stays cheap.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Redirect;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class RedirectRepository {
	const PATTERN_CACHE   = 'publishrank_redirect_patterns';
	const MIGRATED_OPT    = 'publishrank_redirects_migrated';
	const BACKUP_OPT      = 'publishrank_seo_redirects_backup_2_23';
	const MAX_PATTERNS    = 2000;
	const MATCH_TYPES     = array( 'exact', 'prefix', 'wildcard', 'regex' );
	const QUERY_MODES     = array( 'pass', 'ignore', 'exact', 'map' );

	/** @var array<int,int> Hit buffer flushed on shutdown. */
	private static $hits = array();
	/** @var string|null Current history batch. */
	private static $batch = null;
	/** @var int Nesting depth of begin_batch(). */
	private static $depth = 0;

	/* ---------------------------------------------------------------- Settings */

	public static function statuses() {
		return array( 301, 302, 303, 307, 308, 410, 451 );
	}

	public static function case_insensitive() {
		return (bool) get_option( 'publishrank_redirect_case_insensitive', 1 );
	}

	public static function ignore_trailing_slash() {
		return (bool) get_option( 'publishrank_redirect_ignore_trailing', 1 );
	}

	public static function max_rules() {
		return max( 100, (int) get_option( 'publishrank_redirect_max_rules', 100000 ) );
	}

	/** @return string[] Hosts allowed as external targets (lowercase; "*.example.com" allowed). */
	public static function external_hosts() {
		$raw   = (string) get_option( 'publishrank_redirect_external_hosts', '' );
		$hosts = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( $raw ), -1, PREG_SPLIT_NO_EMPTY ) as $h ) {
			$h = preg_replace( '#^https?://#', '', $h );
			$h = trim( (string) preg_replace( '#/.*$#', '', $h ), '.' );
			if ( preg_match( '/^(\*\.)?[a-z0-9.-]+$/', $h ) ) {
				$hosts[] = $h;
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	public static function host_allowed( $host ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return false;
		}
		foreach ( self::external_hosts() as $allowed ) {
			if ( $allowed === $host || ( 0 === strpos( $allowed, '*.' ) && ( substr( $host, -strlen( $allowed ) + 1 ) === substr( $allowed, 1 ) || $host === substr( $allowed, 2 ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function home_host() {
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/** Paths that are never redirected unless a rule is explicitly forced. */
	public static function protected_prefixes() {
		$list = array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-cron.php', '/xmlrpc.php', '/wp-content', '/wp-includes', '/robots.txt', '/sitemap', '/sitemap_index.xml', '/wp-sitemap', '/feed', '/llms.txt', '/llms-full.txt', '/favicon.ico' );
		if ( class_exists( '\\PublishRankSEO\\AccessShield\\LoginAddress' ) && \PublishRankSEO\AccessShield\LoginAddress::active() ) {
			$list[] = '/' . \PublishRankSEO\AccessShield\LoginAddress::slug();
		}
		return (array) apply_filters( 'publishrank_redirect_protected_prefixes', $list );
	}

	public static function is_protected( $path ) {
		$p = strtolower( (string) $path );
		foreach ( self::protected_prefixes() as $prefix ) {
			$prefix = strtolower( (string) $prefix );
			if ( $p === $prefix || 0 === strpos( $p, rtrim( $prefix, '/' ) . '/' ) || ( '' !== $prefix && 0 === strpos( $p, $prefix ) && preg_match( '#\.(xml|txt|php)$#', $prefix ) ) ) {
				return true;
			}
		}
		return (bool) preg_match( '#(^|/)(feed|sitemap[^/]*\.xml)(/|$)#i', $p );
	}

	/* ---------------------------------------------------------------- Normalisation */

	/** Normalise a request path or source into the lookup key. */
	public static function key( $value ) {
		$path = (string) wp_parse_url( trim( (string) $value ), PHP_URL_PATH );
		$path = '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );
		// Decode unreserved percent-escapes so /caf%C3%A9 and /café match; keep reserved ones.
		$path = (string) preg_replace_callback(
			'/%[0-9a-fA-F]{2}/',
			static function ( $m ) {
				$c = rawurldecode( $m[0] );
				return preg_match( '/[A-Za-z0-9\-._~]/', $c ) ? $c : strtoupper( $m[0] );
			},
			$path
		);
		if ( self::ignore_trailing_slash() && '/' !== $path ) {
			$path = untrailingslashit( $path );
		}
		if ( self::case_insensitive() ) {
			$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
		}
		return $path;
	}

	/** Sorted, normalised query string for "exact query" rules. */
	public static function query_key( $query ) {
		$args = array();
		wp_parse_str( (string) $query, $args );
		ksort( $args );
		return http_build_query( $args );
	}

	public static function hash( $key ) {
		return md5( (string) $key );
	}

	/* ---------------------------------------------------------------- Validation */

	/**
	 * Validate and normalise rule input. Returns the clean rule or a WP_Error whose messages list
	 * every problem (row-level errors for imports).
	 *
	 * @param array $in    Raw input.
	 * @param int   $id    Existing rule id (0 for a new rule).
	 * @return array|\WP_Error
	 */
	public static function validate( array $in, $id = 0 ) {
		$e      = new \WP_Error();
		$type   = isset( $in['match_type'] ) ? sanitize_key( $in['match_type'] ) : ( ! empty( $in['regex'] ) ? 'regex' : 'exact' );
		$type   = in_array( $type, self::MATCH_TYPES, true ) ? $type : 'exact';
		$status = isset( $in['status'] ) ? (int) $in['status'] : 301;
		if ( isset( $in['status'] ) && '' !== (string) $in['status'] && ! in_array( $status, self::statuses(), true ) ) {
			/* translators: %s: list of supported status codes */
			$e->add( 'status', sprintf( __( 'Choose a supported status code: %s.', 'publishrank' ), implode( ', ', self::statuses() ) ) );
		}
		$status = in_array( $status, self::statuses(), true ) ? $status : 301;
		$flags  = self::clean_flags( isset( $in['flags'] ) && is_array( $in['flags'] ) ? $in['flags'] : $in );
		$source = trim( (string) ( $in['source'] ?? '' ) );
		$target = trim( (string) ( $in['target'] ?? '' ) );

		if ( '' === $source ) {
			$e->add( 'source', __( 'Enter the old URL or path.', 'publishrank' ) );
		} elseif ( 'regex' === $type ) {
			if ( strlen( $source ) > 255 ) {
				$e->add( 'regex_length', __( 'Regular expressions must be 255 characters or fewer.', 'publishrank' ) );
			} elseif ( ! RedirectStore::regex_is_valid( $source ) ) {
				$e->add( 'regex', __( 'The regular expression is not valid.', 'publishrank' ) );
			}
		} else {
			$host = wp_parse_url( $source, PHP_URL_HOST );
			if ( $host && strtolower( $host ) !== self::home_host() ) {
				$e->add( 'source_host', __( 'The old URL must be on this site. Enter a path such as /old-page.', 'publishrank' ) );
			}
			if ( 'exact' === $type && 'exact' === $flags['query'] ) {
				$q      = (string) wp_parse_url( $source, PHP_URL_QUERY );
				$source = (string) wp_parse_url( $source, PHP_URL_PATH ) . ( '' !== $q ? '?' . self::query_key( $q ) : '' );
				$source = '/' . ltrim( $source, '/' );
			} else {
				$path   = (string) wp_parse_url( $source, PHP_URL_PATH );
				$source = '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );
			}
			if ( 'wildcard' === $type && false === strpos( $source, '*' ) ) {
				$e->add( 'wildcard', __( 'A wildcard rule needs at least one * in the old path.', 'publishrank' ) );
			}
			if ( '/' === $source && 'exact' === $type ) {
				$e->add( 'home', __( 'The homepage cannot be redirected.', 'publishrank' ) );
			}
			if ( empty( $flags['force'] ) && self::is_protected( strtok( $source, '?*' ) ) ) {
				$e->add( 'protected', __( 'That address is used by WordPress itself (admin, login, REST API, feeds, sitemaps or robots.txt). Tick “Allow special addresses” to redirect it anyway.', 'publishrank' ) );
			}
		}

		if ( in_array( $status, array( 410, 451 ), true ) ) {
			$target = '';
		} else {
			$check = self::check_target( $target, ! empty( $flags['external'] ) );
			if ( is_wp_error( $check ) ) {
				$e->add( $check->get_error_code(), $check->get_error_message() );
			} else {
				$target = $check;
			}
			if ( ! $e->has_errors() && 'exact' === $type && self::key( $target ) === self::key( strtok( $source, '?' ) ) && ! wp_parse_url( $target, PHP_URL_HOST ) ) {
				$e->add( 'loop', __( 'The target is the same as the old address, which would loop.', 'publishrank' ) );
			}
		}

		$start = isset( $in['start_at'] ) ? $in['start_at'] : 0;
		$end   = isset( $in['end_at'] ) ? $in['end_at'] : 0;
		$win   = RedirectStore::schedule_window( $start, $end );
		if ( is_wp_error( $win ) ) {
			$e->add( $win->get_error_code(), $win->get_error_message() );
			$win = array( 'start_at' => 0, 'end_at' => 0 );
		}

		$rule = array(
			'id'         => (int) $id,
			'source'     => $source,
			'match_type' => $type,
			'target'     => $target,
			'status'     => $status,
			'flags'      => $flags,
			'group_id'   => isset( $in['group_id'] ) ? absint( $in['group_id'] ) : 0,
			'enabled'    => isset( $in['enabled'] ) ? ( empty( $in['enabled'] ) ? 0 : 1 ) : 1,
			'start_at'   => (int) $win['start_at'],
			'end_at'     => (int) $win['end_at'],
			'note'       => isset( $in['note'] ) ? substr( sanitize_text_field( (string) $in['note'] ), 0, 255 ) : '',
			'origin'     => isset( $in['origin'] ) ? sanitize_key( $in['origin'] ) : 'manual',
		);

		if ( ! $e->has_errors() && 'exact' === $type && empty( $flags['force'] ) && empty( $in['allow_live'] ) ) {
			$live = url_to_postid( home_url( strtok( $source, '?' ) ) );
			if ( $live && 'publish' === get_post_status( $live ) ) {
				$e->add( 'live', sprintf( /* translators: %s: post title */ __( 'A published page already lives at that address (“%s”). Readers would never see it. Tick “Allow special addresses” to redirect anyway.', 'publishrank' ), get_the_title( $live ) ) );
			}
		}
		if ( ! $e->has_errors() && 'exact' === $type ) {
			$dupe = self::find_exact_by_source( $source );
			if ( $dupe && (int) $dupe['id'] !== (int) $id ) {
				$rule['id'] = (int) $dupe['id']; // Same source: update in place instead of duplicating.
			}
		}
		if ( ! $e->has_errors() && 0 === (int) $rule['id'] && self::count() >= self::max_rules() ) {
			$e->add( 'limit', sprintf( /* translators: %s: number */ __( 'The redirect limit (%s rules) has been reached. Raise it in Settings or remove unused rules.', 'publishrank' ), number_format_i18n( self::max_rules() ) ) );
		}
		if ( ! $e->has_errors() && 'exact' !== $type && 0 === (int) $rule['id'] && self::count_patterns() >= self::MAX_PATTERNS ) {
			$e->add( 'pattern_limit', sprintf( /* translators: %d: number */ __( 'At most %d prefix, wildcard and regex rules can be active. Use exact rules for one-to-one moves.', 'publishrank' ), self::MAX_PATTERNS ) );
		}
		return $e->has_errors() ? $e : $rule;
	}

	/** @return string|\WP_Error Clean target. */
	public static function check_target( $target, $allow_external ) {
		$target = trim( (string) $target );
		if ( '' === $target ) {
			return new \WP_Error( 'target', __( 'Enter where readers should go: a path such as /new-page or a full URL.', 'publishrank' ) );
		}
		if ( preg_match( '#^[\s\x00-\x1f]*(javascript|data|vbscript|file|about|blob):#i', $target ) || preg_match( '/[\x00-\x1f\x7f]/', $target ) ) {
			return new \WP_Error( 'target_scheme', __( 'That target type is not allowed. Use an http(s) address or a path.', 'publishrank' ) );
		}
		if ( 0 === strpos( $target, '//' ) ) {
			$target = ( is_ssl() ? 'https:' : 'http:' ) . $target;
		}
		$scheme = wp_parse_url( $target, PHP_URL_SCHEME );
		if ( $scheme && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'target_scheme', __( 'That target type is not allowed. Use an http(s) address or a path.', 'publishrank' ) );
		}
		$host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
		if ( '' === $host ) {
			$target = '/' . ltrim( $target, '/' );
			return esc_url_raw( $target, array( 'http', 'https' ) ) ? $target : new \WP_Error( 'target', __( 'The target address is not valid.', 'publishrank' ) );
		}
		$clean = esc_url_raw( $target, array( 'http', 'https' ) );
		if ( '' === $clean ) {
			return new \WP_Error( 'target', __( 'The target address is not valid.', 'publishrank' ) );
		}
		if ( $host === self::home_host() ) {
			return $clean;
		}
		if ( ! $allow_external ) {
			return new \WP_Error( 'external', __( 'That target is on another website. Tick “Allow external target” for this rule.', 'publishrank' ) );
		}
		if ( ! self::host_allowed( $host ) ) {
			/* translators: %s: host */
			return new \WP_Error( 'external_host', sprintf( __( '%s is not on your list of allowed external hosts (Redirects → Settings).', 'publishrank' ), $host ) );
		}
		return $clean;
	}

	/** @return array<string,mixed> */
	public static function clean_flags( array $in ) {
		$cond = isset( $in['conditions'] ) && is_array( $in['conditions'] ) ? $in['conditions'] : $in;
		$q    = isset( $in['query'] ) ? sanitize_key( $in['query'] ) : ( isset( $in['preserve_query'] ) && ! $in['preserve_query'] ? 'ignore' : 'pass' );
		$map  = array();
		$raw  = isset( $in['query_map'] ) ? $in['query_map'] : '';
		if ( is_string( $raw ) ) {
			foreach ( preg_split( '/[\r\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $pair ) {
				$p = array_map( 'trim', explode( '=', $pair, 2 ) );
				if ( 2 === count( $p ) && '' !== $p[0] ) {
					$map[ sanitize_key( $p[0] ) ] = sanitize_key( $p[1] );
				}
			}
		} elseif ( is_array( $raw ) ) {
			foreach ( $raw as $k => $v ) {
				$map[ sanitize_key( $k ) ] = sanitize_key( $v );
			}
		}
		$pick = static function ( $key, array $allowed, $default ) use ( $cond ) {
			$v = isset( $cond[ $key ] ) ? sanitize_key( $cond[ $key ] ) : $default;
			return in_array( $v, $allowed, true ) ? $v : $default;
		};
		return array(
			'query'      => in_array( $q, self::QUERY_MODES, true ) ? $q : 'pass',
			'query_map'  => $map,
			'external'   => ! empty( $in['external'] ) ? 1 : 0,
			'force'      => ! empty( $in['force'] ) ? 1 : 0,
			'append'     => ! isset( $in['append'] ) || ! empty( $in['append'] ) ? 1 : 0,
			'conditions' => array(
				'login'    => $pick( 'login', array( 'any', 'in', 'out' ), 'any' ),
				'device'   => $pick( 'device', array( 'any', 'mobile', 'desktop' ), 'any' ),
				'referrer' => isset( $cond['referrer'] ) ? substr( sanitize_text_field( (string) $cond['referrer'] ), 0, 120 ) : '',
				'cookie'   => isset( $cond['cookie'] ) ? substr( preg_replace( '/[^A-Za-z0-9_\-=.]/', '', (string) $cond['cookie'] ), 0, 120 ) : '',
				'language' => isset( $cond['language'] ) ? substr( preg_replace( '/[^A-Za-z\-_,]/', '', (string) $cond['language'] ), 0, 40 ) : '',
			),
		);
	}

	/* ---------------------------------------------------------------- Reads */

	private static function t() {
		return Database::table( 'redirects' );
	}

	public static function ready() {
		return Database::ready( 'redirects' );
	}

	private static function hydrate( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$flags = json_decode( (string) $row['flags'], true );
		$row['flags']    = self::clean_flags( is_array( $flags ) ? $flags : array() );
		foreach ( array( 'id', 'status', 'group_id', 'enabled', 'start_at', 'end_at', 'hits', 'created_by' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['regex'] = 'regex' === $row['match_type'] ? 1 : 0;
		return $row;
	}

	public static function get( $id ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return null;
		}
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function find_exact_by_source( $source ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return null;
		}
		$q    = strpos( (string) $source, '?' );
		$key  = false === $q ? self::key( $source ) : self::key( substr( $source, 0, $q ) ) . '?' . substr( $source, $q + 1 );
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE source_hash = %s AND match_type = 'exact' ORDER BY id ASC LIMIT 1", self::hash( $key ) ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function count( array $where = array() ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		list( $sql, $args ) = self::where( $where );
		$q = 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE ' . $sql;
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $q, $args ) ) : $wpdb->get_var( $q ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function count_patterns() {
		global $wpdb;
		return self::ready() ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE match_type <> 'exact'" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** @return array{0:string,1:array} */
	private static function where( array $f ) {
		global $wpdb;
		$sql  = array( '1=1' );
		$args = array();
		if ( ! empty( $f['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
			$sql[]  = '(source LIKE %s OR target LIKE %s OR note LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}
		if ( ! empty( $f['match_type'] ) && in_array( $f['match_type'], self::MATCH_TYPES, true ) ) {
			$sql[]  = 'match_type = %s';
			$args[] = $f['match_type'];
		}
		if ( ! empty( $f['status'] ) ) {
			$sql[]  = 'status = %d';
			$args[] = (int) $f['status'];
		}
		if ( isset( $f['group_id'] ) && '' !== (string) $f['group_id'] ) {
			$sql[]  = 'group_id = %d';
			$args[] = (int) $f['group_id'];
		}
		if ( ! empty( $f['origin'] ) ) {
			$sql[]  = 'origin = %s';
			$args[] = sanitize_key( $f['origin'] );
		}
		if ( isset( $f['enabled'] ) && '' !== (string) $f['enabled'] ) {
			$sql[]  = 'enabled = %d';
			$args[] = (int) $f['enabled'];
		}
		if ( ! empty( $f['unused'] ) ) {
			$sql[] = 'hits = 0';
		}
		return array( implode( ' AND ', $sql ), $args );
	}

	/** @return array{rows:array,total:int} */
	public static function query( array $f = array() ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$per   = max( 1, min( 500, isset( $f['per_page'] ) ? (int) $f['per_page'] : 50 ) );
		$page  = max( 1, isset( $f['paged'] ) ? (int) $f['paged'] : 1 );
		$order = isset( $f['orderby'] ) && in_array( $f['orderby'], array( 'id', 'source', 'hits', 'last_hit', 'status', 'created_at', 'updated_at' ), true ) ? $f['orderby'] : 'id';
		$dir   = isset( $f['order'] ) && 'asc' === strtolower( (string) $f['order'] ) ? 'ASC' : 'DESC';
		list( $sql, $args ) = self::where( $f );
		$args[] = $per;
		$args[] = ( $page - 1 ) * $per;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE {$sql} ORDER BY {$order} {$dir}, id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return array(
			'rows'  => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total' => self::count( $f ),
		);
	}

	/** Every rule (bounded) — used by exports and the legacy facade. */
	public static function all( $limit = 0 ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$sql  = 'SELECT * FROM ' . self::t() . ' ORDER BY id ASC';
		$rows = $limit ? $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d', (int) $limit ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/* ---------------------------------------------------------------- Writes */

	/** Group several writes into one history batch (one rollback step). */
	public static function begin_batch( $label = '' ) {
		// Nested calls (for example bulk() inside an import) join the outer batch so one undo reverts everything.
		if ( null !== self::$batch ) {
			++self::$depth;
			return self::$batch;
		}
		self::$depth = 0;
		self::$batch = wp_generate_uuid4();
		if ( '' !== $label ) {
			$labels                 = get_option( 'publishrank_redirect_batches', array() );
			$labels                 = is_array( $labels ) ? $labels : array();
			$labels[ self::$batch ] = substr( sanitize_text_field( $label ), 0, 120 );
			update_option( 'publishrank_redirect_batches', array_slice( $labels, -300, null, true ), false );
		}
		return self::$batch;
	}

	public static function end_batch() {
		if ( self::$depth > 0 ) {
			--self::$depth;
			return;
		}
		self::$batch = null;
	}

	/** Id of the batch currently being recorded, or the most recent one. */
	public static function last_batch() {
		$rows = self::batches( 1 );
		return $rows ? (string) $rows[0]['batch'] : '';
	}

	private static function history( $rule_id, $action, $before, $after ) {
		global $wpdb;
		if ( ! Database::ready( 'redirect_history' ) ) {
			return;
		}
		$batch = self::$batch ? self::$batch : wp_generate_uuid4();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Database::table( 'redirect_history' ),
			array(
				'batch'       => $batch,
				'rule_id'     => (int) $rule_id,
				'action'      => $action,
				'before_json' => null === $before ? null : wp_json_encode( $before ),
				'after_json'  => null === $after ? null : wp_json_encode( $after ),
				'user_id'     => get_current_user_id(),
				'created_at'  => Database::now(),
			)
		);
	}

	private static function raw_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	private static function source_hash_for( array $rule ) {
		if ( 'exact' !== $rule['match_type'] ) {
			return self::hash( $rule['match_type'] . '|' . $rule['source'] );
		}
		$q = strpos( $rule['source'], '?' );
		return self::hash( false === $q ? self::key( $rule['source'] ) : self::key( substr( $rule['source'], 0, $q ) ) . '?' . substr( $rule['source'], $q + 1 ) );
	}

	/**
	 * Create or update a rule after validation, then flatten chains.
	 *
	 * @return int|\WP_Error Rule id.
	 */
	public static function save( array $in, $id = 0, $flatten = true ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return new \WP_Error( 'storage', __( 'Redirect storage is not ready yet. Reload the page and try again.', 'publishrank' ) );
		}
		$rule = self::validate( $in, $id );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$target_host = strtolower( (string) wp_parse_url( $rule['target'], PHP_URL_HOST ) );
		if ( $flatten && '' !== $rule['target'] && ( '' === $target_host || self::home_host() === $target_host ) ) {
			$final = self::final_destination( $rule['target'], 'exact' === $rule['match_type'] ? self::key( strtok( $rule['source'], '?' ) ) : '' );
			if ( is_wp_error( $final ) ) {
				return $final;
			}
			if ( $final && $final['target'] !== $rule['target'] ) {
				$rule['note']   = trim( $rule['note'] . ' ' . sprintf( 'Flattened from %s.', $rule['target'] ) );
				$rule['target'] = $final['target'];
				if ( $final['status'] && in_array( $final['status'], array( 410, 451 ), true ) ) {
					$rule['status'] = $final['status'];
					$rule['target'] = '';
				}
			}
		}
		$now  = Database::now();
		$data = array(
			'source_hash' => self::source_hash_for( $rule ),
			'source'      => $rule['source'],
			'match_type'  => $rule['match_type'],
			'target'      => $rule['target'],
			'status'      => $rule['status'],
			'flags'       => wp_json_encode( $rule['flags'] ),
			'group_id'    => $rule['group_id'],
			'enabled'     => $rule['enabled'],
			'start_at'    => $rule['start_at'],
			'end_at'      => $rule['end_at'],
			'note'        => $rule['note'],
			'origin'      => $rule['origin'],
			'updated_at'  => $now,
		);
		$own_batch = null === self::$batch;
		if ( $own_batch ) {
			self::begin_batch();
		}
		if ( $rule['id'] ) {
			$before = self::raw_row( $rule['id'] );
			$wpdb->update( self::t(), $data, array( 'id' => $rule['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$new_id = (int) $rule['id'];
			self::history( $new_id, 'update', $before, self::raw_row( $new_id ) );
		} else {
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = $now;
			$data['hits']       = 0;
			$wpdb->insert( self::t(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$new_id = (int) $wpdb->insert_id;
			self::history( $new_id, 'insert', null, self::raw_row( $new_id ) );
		}
		if ( $flatten && 'exact' === $rule['match_type'] ) {
			self::flatten_into( $new_id, $rule );
		}
		if ( $own_batch ) {
			self::end_batch();
		}
		self::rebuild_patterns();
		do_action( 'publishrank_redirects_changed' );
		return $new_id;
	}

	/**
	 * Follow the chain from a target. @return array{target:string,status:int}|null|\WP_Error
	 * Returns a WP_Error when the chain loops back to $origin_key.
	 */
	public static function final_destination( $target, $origin_key = '' ) {
		$seen    = array();
		$current = $target;
		$status  = 0;
		$hops    = 0;
		$limit   = min( 50, max( 1, absint( get_option( 'publishrank_redirect_max_hops', 10 ) ) ) );
		while ( $hops < $limit ) {
			$host = strtolower( (string) wp_parse_url( $current, PHP_URL_HOST ) );
			if ( '' !== $host && $host !== self::home_host() ) {
				break;
			}
			$key = self::key( $current );
			if ( '' !== $origin_key && $key === $origin_key ) {
				return new \WP_Error( 'chain_loop', __( 'This rule would create a redirect loop through other rules.', 'publishrank' ) );
			}
			if ( isset( $seen[ $key ] ) ) {
				return new \WP_Error( 'chain_loop', __( 'The target is part of a redirect loop.', 'publishrank' ) );
			}
			$seen[ $key ] = true;
			$match        = self::match( $key, array( 'ignore_conditions' => true ) );
			if ( ! $match ) {
				break;
			}
			$status = (int) $match['rule']['status'];
			if ( in_array( $status, array( 410, 451 ), true ) ) {
				return array( 'target' => '', 'status' => $status );
			}
			$current = $match['target'];
			++$hops;
		}
		return $hops ? array( 'target' => $current, 'status' => 0 ) : null;
	}

	/** Rewrite A→B rules to A→C when B→C is saved (exact rules only). */
	private static function flatten_into( $rule_id, array $rule ) {
		global $wpdb;
		$key     = self::key( strtok( $rule['source'], '?' ) );
		$abs     = home_url( $key );
		$cands   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE id <> %d AND match_type = 'exact' AND status NOT IN (410,451) AND (target = %s OR target = %s OR target = %s OR target = %s) LIMIT 500", $rule_id, $key, trailingslashit( $key ), $abs, trailingslashit( $abs ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $cands as $row ) {
			if ( self::key( $row['source'] ) === self::key( $rule['target'] ) ) {
				continue; // Would loop: A→B, B→A. Leave it for the loop checker to report.
			}
			$before = $row;
			$upd    = array(
				'target'     => $rule['target'],
				'status'     => in_array( (int) $rule['status'], array( 410, 451 ), true ) ? (int) $rule['status'] : (int) $row['status'],
				'note'       => substr( trim( $row['note'] . ' Flattened: ' . $row['target'] . ' → ' . ( '' !== $rule['target'] ? $rule['target'] : $rule['status'] ) ), 0, 255 ),
				'updated_at' => Database::now(),
			);
			$wpdb->update( self::t(), $upd, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::history( (int) $row['id'], 'flatten', $before, self::raw_row( (int) $row['id'] ) );
		}
	}

	/** @return bool */
	public static function delete( $id ) {
		global $wpdb;
		$before = self::raw_row( $id );
		if ( ! $before ) {
			return false;
		}
		$own = null === self::$batch;
		if ( $own ) {
			self::begin_batch();
		}
		$wpdb->delete( self::t(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::history( (int) $id, 'delete', $before, null );
		if ( $own ) {
			self::end_batch();
		}
		self::rebuild_patterns();
		do_action( 'publishrank_redirects_changed' );
		return true;
	}

	/** Bulk enable/disable/group/status/delete. @return int affected */
	public static function bulk( array $ids, $action, $value = null ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		self::begin_batch( 'Bulk ' . $action . ' (' . count( $ids ) . ')' );
		$n = 0;
		foreach ( $ids as $id ) {
			$before = self::raw_row( $id );
			if ( ! $before ) {
				continue;
			}
			if ( 'delete' === $action ) {
				$wpdb->delete( self::t(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::history( $id, 'delete', $before, null );
				++$n;
				continue;
			}
			$upd = array();
			if ( 'enable' === $action || 'disable' === $action ) {
				$upd['enabled'] = 'enable' === $action ? 1 : 0;
			} elseif ( 'group' === $action ) {
				$upd['group_id'] = absint( $value );
			} elseif ( 'status' === $action && in_array( (int) $value, self::statuses(), true ) ) {
				$upd['status'] = (int) $value;
				if ( in_array( (int) $value, array( 410, 451 ), true ) ) {
					$upd['target'] = '';
				} elseif ( '' === (string) $before['target'] ) {
					continue; // A gone rule needs a target before it can become a redirect.
				}
			} elseif ( 'reset_hits' === $action ) {
				$upd['hits']     = 0;
				$upd['last_hit'] = null;
			}
			if ( $upd ) {
				$upd['updated_at'] = Database::now();
				$wpdb->update( self::t(), $upd, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::history( $id, 'update', $before, self::raw_row( $id ) );
				++$n;
			}
		}
		self::end_batch();
		self::rebuild_patterns();
		do_action( 'publishrank_redirects_changed' );
		return $n;
	}

	/* ---------------------------------------------------------------- History & rollback */

	/** @return array<int,array> Recent batches with counts. */
	public static function batches( $limit = 50 ) {
		global $wpdb;
		if ( ! Database::ready( 'redirect_history' ) ) {
			return array();
		}
		$h    = Database::table( 'redirect_history' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT batch, MIN(created_at) AS created_at, MAX(user_id) AS user_id, COUNT(*) AS changes, GROUP_CONCAT(DISTINCT action) AS actions FROM {$h} GROUP BY batch ORDER BY MAX(id) DESC LIMIT %d", (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$labels = get_option( 'publishrank_redirect_batches', array() );
		$rows   = (array) $rows;
		foreach ( $rows as &$r ) {
			$r['label'] = is_array( $labels ) && isset( $labels[ $r['batch'] ] ) ? $labels[ $r['batch'] ] : '';
		}
		unset( $r );
		return (array) $rows;
	}

	/** @return array<int,array> */
	public static function history_for( $rule_id, $limit = 50 ) {
		global $wpdb;
		$h = Database::table( 'redirect_history' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$h} WHERE rule_id = %d ORDER BY id DESC LIMIT %d", (int) $rule_id, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Undo one batch: rows come back exactly as they were (same id and every column).
	 *
	 * @return int|\WP_Error Number of changes reverted.
	 */
	public static function rollback( $batch ) {
		global $wpdb;
		$h    = Database::table( 'redirect_history' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$h} WHERE batch = %s ORDER BY id DESC", (string) $batch ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $rows ) {
			return new \WP_Error( 'batch', __( 'Nothing to undo for that change.', 'publishrank' ) );
		}
		self::begin_batch( 'Undo of ' . substr( (string) $batch, 0, 8 ) );
		$n = 0;
		foreach ( $rows as $r ) {
			$before = null === $r['before_json'] ? null : json_decode( (string) $r['before_json'], true );
			$after  = self::raw_row( (int) $r['rule_id'] );
			if ( 'insert' === $r['action'] ) {
				$wpdb->delete( self::t(), array( 'id' => (int) $r['rule_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::history( (int) $r['rule_id'], 'delete', $after, null );
			} elseif ( is_array( $before ) ) {
				$wpdb->replace( self::t(), $before ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- REPLACE keeps the original id.
				self::history( (int) $r['rule_id'], 'restore', $after, $before );
			}
			++$n;
		}
		self::end_batch();
		self::rebuild_patterns();
		do_action( 'publishrank_redirects_changed' );
		return $n;
	}

	/** Checksum of the whole ruleset (rollback verification). */
	public static function checksum() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, source_hash, source, match_type, target, status, flags, group_id, enabled, start_at, end_at, note, origin, created_by, created_at, updated_at FROM ' . self::t() . ' ORDER BY id', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return md5( wp_json_encode( $rows ) );
	}

	/* ---------------------------------------------------------------- Matching */

	/** Compiled pattern rules (prefix, wildcard, regex), cached in one non-autoloaded option. */
	public static function patterns() {
		$cache = get_option( self::PATTERN_CACHE, null );
		if ( ! is_array( $cache ) || ! isset( $cache['v'] ) ) {
			$cache = self::rebuild_patterns();
		}
		return $cache['rules'];
	}

	public static function rebuild_patterns() {
		global $wpdb;
		$rules = array();
		if ( self::ready() ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE match_type <> 'exact' AND enabled = 1 ORDER BY CASE match_type WHEN 'prefix' THEN 1 WHEN 'wildcard' THEN 2 ELSE 3 END, CHAR_LENGTH(source) DESC, id ASC LIMIT %d", self::MAX_PATTERNS ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$r = self::hydrate( $row );
				if ( 'prefix' === $r['match_type'] ) {
					$r['_prefix'] = self::key( $r['source'] );
				} elseif ( 'wildcard' === $r['match_type'] ) {
					$k          = self::key( $r['source'] );
					$r['_re']   = '~^' . str_replace( '\\*', '(.*)', preg_quote( $k, '~' ) ) . '$~' . ( self::case_insensitive() ? 'i' : '' );
				} else {
					$r['_re'] = RedirectStore::compile_regex( $r['source'] );
				}
				$rules[] = $r;
			}
		}
		$cache = array( 'v' => 2, 'rules' => $rules );
		update_option( self::PATTERN_CACHE, $cache, false );
		return $cache;
	}

	/**
	 * Find the rule for a request.
	 *
	 * @param string $path  Request path (may include a query string).
	 * @param array  $opts  ignore_conditions, query (raw query string).
	 * @return array{rule:array,target:string}|null
	 */
	public static function match( $path, array $opts = array() ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return null;
		}
		$query = isset( $opts['query'] ) ? (string) $opts['query'] : (string) wp_parse_url( (string) $path, PHP_URL_QUERY );
		$key   = self::key( $path );
		if ( strlen( $key ) > 2048 ) {
			return null;
		}
		$hashes = array( self::hash( $key ) );
		if ( '' !== $query ) {
			array_unshift( $hashes, self::hash( $key . '?' . self::query_key( $query ) ) );
		}
		$now  = time();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . self::t() . " WHERE source_hash IN (" . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ") AND match_type = 'exact' AND enabled = 1 AND (start_at = 0 OR start_at <= %d) AND (end_at = 0 OR end_at > %d) ORDER BY id ASC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( $hashes, array( $now, $now ) )
			),
			ARRAY_A
		);
		$best = array();
		foreach ( (array) $rows as $row ) {
			$r = self::hydrate( $row );
			if ( ! empty( $opts['ignore_conditions'] ) || self::conditions_pass( $r ) ) {
				$best[ $r['source_hash'] ][] = $r;
			}
		}
		foreach ( $hashes as $h ) {
			if ( ! empty( $best[ $h ] ) ) {
				return array( 'rule' => $best[ $h ][0], 'target' => $best[ $h ][0]['target'] );
			}
		}
		foreach ( self::patterns() as $r ) {
			if ( ( $r['start_at'] && $now < $r['start_at'] ) || ( $r['end_at'] && $now >= $r['end_at'] ) ) {
				continue;
			}
			if ( 'prefix' === $r['match_type'] ) {
				$p = $r['_prefix'];
				if ( $key !== $p && 0 !== strpos( $key, rtrim( $p, '/' ) . '/' ) ) {
					continue;
				}
				if ( empty( $opts['ignore_conditions'] ) && ! self::conditions_pass( $r ) ) {
					continue;
				}
				$target = $r['target'];
				if ( $r['flags']['append'] && '' !== $target && $key !== $p ) {
					$rest   = ltrim( substr( $key, strlen( rtrim( $p, '/' ) ) ), '/' );
					$target = trailingslashit( strtok( $target, '?' ) ) . $rest;
				}
				return array( 'rule' => $r, 'target' => $target );
			}
			$subject = 'regex' === $r['match_type'] ? (string) wp_parse_url( (string) $path, PHP_URL_PATH ) : $key;
			$subject = '/' . ltrim( $subject, '/' );
			$m       = array();
			if ( 1 !== @preg_match( $r['_re'], $subject, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validated pattern with PCRE limits; failure = no match.
				continue;
			}
			if ( empty( $opts['ignore_conditions'] ) && ! self::conditions_pass( $r ) ) {
				continue;
			}
			$target = $r['target'];
			if ( false !== strpos( $target, '$' ) && count( $m ) > 1 ) {
				$target = (string) preg_replace_callback(
					'/\$(\d)/',
					static function ( $x ) use ( $m ) {
						$v = isset( $m[ (int) $x[1] ] ) ? $m[ (int) $x[1] ] : '';
						return (string) preg_replace( '#[^A-Za-z0-9/_\-.~%]#', '', $v );
					},
					$target
				);
			}
			return array( 'rule' => $r, 'target' => $target );
		}
		return null;
	}

	/** Conditions: signed-in state, device, referrer, cookie, language. */
	public static function conditions_pass( array $r ) {
		$c = isset( $r['flags']['conditions'] ) ? $r['flags']['conditions'] : array();
		if ( ! empty( $c['login'] ) && 'any' !== $c['login'] && ( 'in' === $c['login'] ) !== is_user_logged_in() ) {
			return false;
		}
		if ( ! empty( $c['device'] ) && 'any' !== $c['device'] && ( 'mobile' === $c['device'] ) !== wp_is_mobile() ) {
			return false;
		}
		if ( ! empty( $c['referrer'] ) ) {
			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
			if ( false === strpos( $ref, strtolower( $c['referrer'] ) ) ) {
				return false;
			}
		}
		if ( ! empty( $c['cookie'] ) ) {
			$p = explode( '=', $c['cookie'], 2 );
			if ( ! isset( $_COOKIE[ $p[0] ] ) || ( isset( $p[1] ) && (string) wp_unslash( $_COOKIE[ $p[0] ] ) !== $p[1] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
				return false;
			}
		}
		if ( ! empty( $c['language'] ) ) {
			$accept = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
			$first  = strtolower( (string) strtok( $accept, ',;' ) );
			$ok     = false;
			foreach ( explode( ',', strtolower( $c['language'] ) ) as $lang ) {
				$lang = str_replace( '_', '-', trim( $lang ) );
				if ( '' !== $lang && ( $first === $lang || 0 === strpos( $first, $lang . '-' ) || 0 === strpos( $first, $lang ) ) ) {
					$ok = true;
				}
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/** Apply the rule's query-string mode to the target. */
	public static function apply_query( array $rule, $target, array $get ) {
		$mode = $rule['flags']['query'];
		if ( 'ignore' === $mode || 'exact' === $mode || ! $get || in_array( (int) $rule['status'], array( 410, 451 ), true ) ) {
			return $target;
		}
		if ( ! get_option( 'publishrank_redirect_preserve_query', true ) && 'map' !== $mode ) {
			return $target;
		}
		$args = map_deep( $get, 'sanitize_text_field' );
		if ( 'map' === $mode ) {
			$mapped = array();
			foreach ( $args as $k => $v ) {
				if ( isset( $rule['flags']['query_map'][ $k ] ) ) {
					if ( '' !== $rule['flags']['query_map'][ $k ] ) {
						$mapped[ $rule['flags']['query_map'][ $k ] ] = $v;
					}
				} else {
					$mapped[ $k ] = $v;
				}
			}
			$args = $mapped;
		}
		return add_query_arg( urlencode_deep( $args ), $target );
	}

	public static function record_hit( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return;
		}
		if ( ! self::$hits ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_hits' ), 1 );
		}
		self::$hits[ $id ] = ( isset( self::$hits[ $id ] ) ? self::$hits[ $id ] : 0 ) + 1;
	}

	public static function flush_hits() {
		global $wpdb;
		$hits       = self::$hits;
		self::$hits = array();
		foreach ( $hits as $id => $n ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET hits = hits + %d, last_hit = %s WHERE id = %d', (int) $n, Database::now(), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Re-key every exact rule after the case / trailing-slash settings change. */
	public static function rehash( $batch = 2000 ) {
		global $wpdb;
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, match_type FROM ' . self::t() . ' WHERE id > %d ORDER BY id ASC LIMIT %d', $last, $batch ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $r ) {
				$last = (int) $r['id'];
				$wpdb->update( self::t(), array( 'source_hash' => self::source_hash_for( $r ) ), array( 'id' => $last ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		} while ( $rows && count( $rows ) === $batch );
		self::rebuild_patterns();
	}

	/* ---------------------------------------------------------------- Migration */

	/**
	 * Move rules from the legacy option into the table. Idempotent; keeps a backup copy of the
	 * option and only marks the migration done when the row count matches.
	 *
	 * @return array{migrated:int,skipped:int,errors:array}
	 */
	public static function migrate_legacy() {
		global $wpdb;
		$out = array( 'migrated' => 0, 'skipped' => 0, 'errors' => array() );
		if ( ! self::ready() || get_option( self::MIGRATED_OPT ) ) {
			return $out;
		}
		$legacy = get_option( RedirectStore::OPTION, array() );
		$legacy = is_array( $legacy ) ? array_values( array_filter( $legacy, 'is_array' ) ) : array();
		if ( ! $legacy ) {
			update_option( self::MIGRATED_OPT, array( 'at' => time(), 'count' => 0 ), false );
			return $out;
		}
		if ( null === get_option( self::BACKUP_OPT, null ) ) {
			add_option( self::BACKUP_OPT, $legacy, '', false );
		}
		self::begin_batch( 'Migration from the 2.22 redirect list' );
		$before = self::count();
		foreach ( $legacy as $i => $r ) {
			$in = array(
				'source'     => (string) ( $r['source'] ?? '' ),
				'target'     => (string) ( $r['target'] ?? '' ),
				'status'     => (int) ( $r['status'] ?? 301 ),
				'match_type' => ! empty( $r['regex'] ) ? 'regex' : 'exact',
				'enabled'    => isset( $r['enabled'] ) ? (int) $r['enabled'] : 1,
				'start_at'   => (int) ( $r['start_at'] ?? 0 ),
				'end_at'     => (int) ( $r['end_at'] ?? 0 ),
				'note'       => (string) ( $r['note'] ?? '' ),
				'query'      => isset( $r['preserve_query'] ) && ! $r['preserve_query'] ? 'ignore' : 'pass',
				'origin'     => 'migrated',
				'force'      => 1, // Rules that already worked keep working.
				'allow_live' => 1,
			);
			$res = self::save( $in, 0, false );
			if ( is_wp_error( $res ) ) {
				// Legacy external targets were silently dropped by wp_safe_redirect; keep them, disabled, for review.
				$in['enabled']  = 0;
				$in['external'] = 1;
				$in['note']     = trim( $in['note'] . ' Needs review: ' . $res->get_error_message() );
				$raw            = self::raw_insert_legacy( $in );
				if ( $raw ) {
					++$out['migrated'];
				} else {
					++$out['skipped'];
				}
				$out['errors'][] = array( 'row' => $i + 1, 'source' => $in['source'], 'error' => $res->get_error_message() );
				continue;
			}
			++$out['migrated'];
		}
		self::end_batch();
		$after = self::count();
		if ( $after - $before === count( $legacy ) - $out['skipped'] && 0 === $out['skipped'] ) {
			update_option( self::MIGRATED_OPT, array( 'at' => time(), 'count' => $out['migrated'], 'review' => count( $out['errors'] ) ), false );
		} else {
			update_option( self::MIGRATED_OPT . '_error', $out, false );
		}
		self::rebuild_patterns();
		return $out;
	}

	/** Store a legacy rule that fails today's validation, disabled, so nothing is lost. */
	private static function raw_insert_legacy( array $in ) {
		global $wpdb;
		$now  = Database::now();
		$type = in_array( $in['match_type'], self::MATCH_TYPES, true ) ? $in['match_type'] : 'exact';
		$rule = array( 'source' => '/' . ltrim( (string) $in['source'], '/' ), 'match_type' => $type );
		if ( 'regex' === $type ) {
			$rule['source'] = (string) $in['source'];
		}
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::t(),
			array(
				'source_hash' => self::source_hash_for( $rule ),
				'source'      => $rule['source'],
				'match_type'  => $type,
				'target'      => esc_url_raw( (string) $in['target'] ),
				'status'      => in_array( (int) $in['status'], self::statuses(), true ) ? (int) $in['status'] : 301,
				'flags'       => wp_json_encode( self::clean_flags( $in ) ),
				'enabled'     => 0,
				'start_at'    => (int) $in['start_at'],
				'end_at'      => (int) $in['end_at'],
				'note'        => substr( (string) $in['note'], 0, 255 ),
				'origin'      => 'migrated',
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		if ( $ok ) {
			self::history( (int) $wpdb->insert_id, 'insert', null, self::raw_row( (int) $wpdb->insert_id ) );
		}
		return (bool) $ok;
	}

	/** Undo the migration: empty the table of migrated rows and forget the flag. */
	public static function rollback_migration() {
		global $wpdb;
		$wpdb->delete( self::t(), array( 'origin' => 'migrated' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( self::MIGRATED_OPT );
		$backup = get_option( self::BACKUP_OPT, null );
		if ( is_array( $backup ) ) {
			update_option( RedirectStore::OPTION, $backup, false );
		}
		self::rebuild_patterns();
	}

	/* ---------------------------------------------------------------- Groups */

	public static function groups() {
		global $wpdb;
		if ( ! Database::ready( 'redirect_groups' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Database::table( 'redirect_groups' ) . ' ORDER BY name ASC', ARRAY_A ) as $g ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$out[ (int) $g['id'] ] = $g['name'];
		}
		return $out;
	}

	public static function add_group( $name ) {
		global $wpdb;
		$name = substr( sanitize_text_field( (string) $name ), 0, 120 );
		if ( '' === $name ) {
			return 0;
		}
		$existing = array_search( $name, self::groups(), true );
		if ( false !== $existing ) {
			return (int) $existing;
		}
		$wpdb->insert( Database::table( 'redirect_groups' ), array( 'name' => $name, 'created_at' => Database::now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	public static function delete_group( $id ) {
		global $wpdb;
		$wpdb->update( self::t(), array( 'group_id' => 0 ), array( 'group_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Database::table( 'redirect_groups' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::rebuild_patterns();
	}

	/* ---------------------------------------------------------------- Import / export */

	/**
	 * Parse .htaccess Redirect / RedirectMatch / RewriteRule lines.
	 *
	 * @return array<int,array>
	 */
	public static function parse_htaccess( $text ) {
		$rows = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '/^Redirect(?:Permanent)?\s+(?:(\d{3}|permanent|temp|seeother|gone)\s+)?(\S+)(?:\s+(\S+))?/i', $line, $m ) && ! preg_match( '/^RedirectMatch/i', $line ) ) {
				$code   = strtolower( $m[1] ?? '' );
				$map    = array( 'permanent' => 301, 'temp' => 302, 'seeother' => 303, 'gone' => 410, '' => 0 === stripos( $line, 'RedirectPermanent' ) ? 301 : 302 );
				$status = ctype_digit( $code ) ? (int) $code : ( $map[ $code ] ?? 301 );
				$rows[] = array( 'source' => $m[2], 'target' => $m[3] ?? '', 'status' => $status, 'match_type' => 'exact' );
			} elseif ( preg_match( '/^RedirectMatch\s+(?:(\d{3})\s+)?(\S+)(?:\s+(\S+))?/i', $line, $m ) ) {
				$rows[] = array( 'source' => $m[2], 'target' => $m[3] ?? '', 'status' => $m[1] ? (int) $m[1] : 302, 'match_type' => 'regex' );
			} elseif ( preg_match( '/^RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]*)\]/i', $line, $m ) && preg_match( '/R(?:=(\d{3}))?/i', $m[3], $r ) ) {
				$src    = $m[1];
				$plain  = trim( $src, '^$' );
				$regex  = (bool) preg_match( '/[()\[\]*+?|\\\\]/', $plain );
				$rows[] = array(
					'source'     => $regex ? '^/' . ltrim( ltrim( $src, '^' ), '/' ) : '/' . ltrim( $plain, '/' ),
					'target'     => $m[2],
					'status'     => ! empty( $r[1] ) ? (int) $r[1] : 302,
					'match_type' => $regex ? 'regex' : 'exact',
				);
			} elseif ( preg_match( '/^RewriteRule\s+(\S+)\s+-\s+\[[^\]]*G[^\]]*\]/i', $line, $m ) ) {
				$rows[] = array( 'source' => '/' . ltrim( trim( $m[1], '^$' ), '/' ), 'target' => '', 'status' => 410, 'match_type' => 'exact' );
			}
		}
		return $rows;
	}

	/**
	 * Import rows. Validates every row, reports row-level errors, writes in one history batch.
	 *
	 * @return array{created:int,updated:int,errors:array,batch:string}
	 */
	public static function import( array $rows, $group_id = 0, $dry_run = false ) {
		$out = array( 'created' => 0, 'updated' => 0, 'errors' => array(), 'batch' => '' );
		if ( ! $dry_run ) {
			$out['batch'] = self::begin_batch( sprintf( 'Import of %d rows', count( $rows ) ) );
		}
		foreach ( array_values( $rows ) as $i => $row ) {
			$row['origin']   = 'import';
			$row['group_id'] = $group_id ? $group_id : ( $row['group_id'] ?? 0 );
			if ( ! empty( $row['regex'] ) && empty( $row['match_type'] ) ) {
				$row['match_type'] = 'regex';
			}
			$valid = self::validate( $row );
			if ( is_wp_error( $valid ) ) {
				$out['errors'][] = array( 'row' => $i + 2, 'source' => (string) ( $row['source'] ?? '' ), 'error' => implode( ' ', $valid->get_error_messages() ) );
				continue;
			}
			if ( $dry_run ) {
				++$out[ $valid['id'] ? 'updated' : 'created' ];
				continue;
			}
			$res = self::save( $row, $valid['id'], true );
			if ( is_wp_error( $res ) ) {
				$out['errors'][] = array( 'row' => $i + 2, 'source' => (string) ( $row['source'] ?? '' ), 'error' => $res->get_error_message() );
			} else {
				++$out[ $valid['id'] ? 'updated' : 'created' ];
			}
		}
		if ( ! $dry_run ) {
			self::end_batch();
		}
		return $out;
	}

	/** @return array<int,array<int,string>> CSV rows (header first). */
	public static function export_rows( array $filters = array() ) {
		$groups = self::groups();
		$out    = array( array( 'source', 'target', 'status', 'match_type', 'group', 'enabled', 'query', 'external', 'start_at', 'end_at', 'hits', 'last_hit', 'note' ) );
		$page   = 1;
		do {
			$res = self::query( $filters + array( 'per_page' => 500, 'paged' => $page, 'orderby' => 'id', 'order' => 'asc' ) );
			foreach ( $res['rows'] as $r ) {
				$out[] = array( $r['source'], $r['target'], (string) $r['status'], $r['match_type'], $groups[ $r['group_id'] ] ?? '', (string) $r['enabled'], $r['flags']['query'], (string) $r['flags']['external'], $r['start_at'] ? gmdate( 'c', $r['start_at'] ) : '', $r['end_at'] ? gmdate( 'c', $r['end_at'] ) : '', (string) $r['hits'], (string) $r['last_hit'], $r['note'] );
			}
			++$page;
		} while ( count( $res['rows'] ) === 500 && $page < 400 );
		return $out;
	}
}
