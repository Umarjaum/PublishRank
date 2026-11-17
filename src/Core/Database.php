<?php
/**
 * Custom tables for high-volume PublishRank data (2.23). Versioned and idempotent: dbDelta() only adds
 * what is missing. Every runtime reader checks Database::ready() and fails open when a table is absent.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class Database {
	const VERSION     = 5;
	const VERSION_OPT = 'publishrank_db_version';

	/** @var array<string,bool> */
	private static $exists = array();

	/** Short table key => full name. */
	public static function table( $key ) {
		global $wpdb;
		return $wpdb->prefix . 'publishrank_' . $key;
	}

	/** @return string[] */
	public static function keys() {
		return array( 'shield_attempts', 'security_log', '404', '404_daily', 'redirects', 'redirect_groups', 'redirect_history', 'ai_bots', 'ai_bots_daily', 'ai_citations', 'feed_stats', 'traffic_daily', 'traffic_seen' );
	}

	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 1 );
		add_action( 'wp_initialize_site', array( __CLASS__, 'install_for_site' ), 20, 1 );
	}

	public static function maybe_install() {
		if ( (int) get_option( self::VERSION_OPT, 0 ) >= self::VERSION ) {
			return;
		}
		self::install();
	}

	/** New multisite subsite. */
	public static function install_for_site( $site ) {
		if ( ! is_object( $site ) || empty( $site->blog_id ) || ! function_exists( 'is_plugin_active_for_network' ) ) {
			return;
		}
		if ( ! is_plugin_active_for_network( plugin_basename( PUBLISHRANK_SEO_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install();
		restore_current_blog();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = array();
		foreach ( self::keys() as $key ) {
			$t[ $key ] = self::table( $key );
		}
		$sql = array();
		$sql[] = "CREATE TABLE {$t['shield_attempts']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(8) NOT NULL DEFAULT 'ip',
			key_hash char(64) NOT NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			user_hash char(64) NOT NULL DEFAULT '',
			username varchar(60) NOT NULL DEFAULT '',
			failures int(10) unsigned NOT NULL DEFAULT 0,
			first_at datetime NOT NULL,
			last_at datetime NOT NULL,
			lock_until datetime NULL DEFAULT NULL,
			lock_level tinyint(3) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY kind_key (kind,key_hash),
			KEY user_hash (user_hash,last_at),
			KEY last_at (last_at),
			KEY lock_until (lock_until)
		) $c;";
		$sql[] = "CREATE TABLE {$t['security_log']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			event varchar(40) NOT NULL,
			severity varchar(8) NOT NULL DEFAULT 'info',
			ip varchar(45) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			username varchar(60) NOT NULL DEFAULT '',
			path varchar(255) NOT NULL DEFAULT '',
			detail varchar(255) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY event (event,created_at),
			KEY ip (ip)
		) $c;";
		// 2.24: first-party visitor analytics (daily aggregates; no IP addresses or cookies are stored).
		$sql[] = "CREATE TABLE {$t['traffic_daily']} (
			day date NOT NULL,
			dim varchar(12) NOT NULL,
			val varchar(191) NOT NULL DEFAULT '',
			views int(10) unsigned NOT NULL DEFAULT 0,
			visitors int(10) unsigned NOT NULL DEFAULT 0,
			sessions int(10) unsigned NOT NULL DEFAULT 0,
			engaged int(10) unsigned NOT NULL DEFAULT 0,
			secs bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,dim,val),
			KEY dim_day (dim,day)
		) $c;";
		$sql[] = "CREATE TABLE {$t['traffic_seen']} (
			day date NOT NULL,
			vhash char(16) NOT NULL,
			last_at datetime NOT NULL,
			session_start datetime NOT NULL,
			pages smallint(5) unsigned NOT NULL DEFAULT 0,
			engaged tinyint(1) unsigned NOT NULL DEFAULT 0,
			path varchar(191) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT '',
			source varchar(12) NOT NULL DEFAULT '',
			PRIMARY KEY  (day,vhash),
			KEY last_at (last_at)
		) $c;";
		$sql[] = "CREATE TABLE {$t['404']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path_hash char(32) NOT NULL,
			path varchar(512) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			hits_human bigint(20) unsigned NOT NULL DEFAULT 0,
			hits_search bigint(20) unsigned NOT NULL DEFAULT 0,
			hits_ai bigint(20) unsigned NOT NULL DEFAULT 0,
			hits_scanner bigint(20) unsigned NOT NULL DEFAULT 0,
			referrer_host varchar(191) NOT NULL DEFAULT '',
			top_referrer_path varchar(255) NOT NULL DEFAULT '',
			ua_class varchar(12) NOT NULL DEFAULT 'human',
			status varchar(12) NOT NULL DEFAULT 'open',
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY last_seen (last_seen),
			KEY hits (hits),
			KEY status (status,last_seen),
			KEY ua_class (ua_class)
		) $c;";
		$sql[] = "CREATE TABLE {$t['404_daily']} (
			day date NOT NULL,
			ua_class varchar(12) NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,ua_class)
		) $c;";
		$sql[] = "CREATE TABLE {$t['redirects']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_hash char(32) NOT NULL,
			source varchar(1024) NOT NULL,
			match_type varchar(10) NOT NULL DEFAULT 'exact',
			target text NOT NULL,
			status smallint(5) unsigned NOT NULL DEFAULT 301,
			flags text NULL,
			group_id bigint(20) unsigned NOT NULL DEFAULT 0,
			enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
			start_at bigint(20) unsigned NOT NULL DEFAULT 0,
			end_at bigint(20) unsigned NOT NULL DEFAULT 0,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime NULL DEFAULT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			origin varchar(12) NOT NULL DEFAULT 'manual',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source_hash (source_hash,match_type),
			KEY match_type (match_type,enabled),
			KEY group_id (group_id),
			KEY hits (hits)
		) $c;";
		$sql[] = "CREATE TABLE {$t['redirect_groups']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(120) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $c;";
		$sql[] = "CREATE TABLE {$t['redirect_history']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch char(36) NOT NULL DEFAULT '',
			rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(12) NOT NULL,
			before_json longtext NULL,
			after_json longtext NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY rule_id (rule_id),
			KEY batch (batch),
			KEY created_at (created_at)
		) $c;";
		$sql[] = "CREATE TABLE {$t['ai_bots']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			bot varchar(40) NOT NULL,
			operator varchar(60) NOT NULL DEFAULT '',
			purpose varchar(16) NOT NULL DEFAULT '',
			verified tinyint(1) unsigned NOT NULL DEFAULT 0,
			method varchar(10) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			path varchar(512) NOT NULL DEFAULT '',
			status smallint(5) unsigned NOT NULL DEFAULT 200,
			action varchar(10) NOT NULL DEFAULT 'allowed',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY bot (bot,created_at),
			KEY verified (verified)
		) $c;";
		$sql[] = "CREATE TABLE {$t['ai_bots_daily']} (
			day date NOT NULL,
			bot varchar(40) NOT NULL,
			verified tinyint(1) unsigned NOT NULL DEFAULT 0,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,bot,verified)
		) $c;";
		$sql[] = "CREATE TABLE {$t['ai_citations']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id char(36) NOT NULL,
			created_at datetime NOT NULL,
			provider varchar(20) NOT NULL,
			model varchar(80) NOT NULL DEFAULT '',
			prompt_id varchar(20) NOT NULL DEFAULT '',
			prompt text NOT NULL,
			mentioned tinyint(1) unsigned NOT NULL DEFAULT 0,
			cited tinyint(1) unsigned NOT NULL DEFAULT 0,
			position smallint(5) unsigned NOT NULL DEFAULT 0,
			cited_urls text NULL,
			competitors text NULL,
			excerpt text NULL,
			tokens int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(12) NOT NULL DEFAULT 'ok',
			error varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY prompt_id (prompt_id),
			KEY provider (provider,created_at),
			KEY run_id (run_id)
		) $c;";
		$sql[] = "CREATE TABLE {$t['feed_stats']} (
			day date NOT NULL,
			reader varchar(40) NOT NULL,
			feed varchar(40) NOT NULL DEFAULT 'main',
			fetches bigint(20) unsigned NOT NULL DEFAULT 0,
			subscribers bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,reader,feed)
		) $c;";
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		self::$exists = array();
		update_option( self::VERSION_OPT, self::VERSION, true );
	}

	/** Whether a table exists (cached per request). */
	public static function ready( $key ) {
		if ( isset( self::$exists[ $key ] ) ) {
			return self::$exists[ $key ];
		}
		// Installed at the current schema and verified within 12 hours: trust it (no SHOW TABLES per request).
		$verified = (int) get_option( 'publishrank_db_verified', 0 );
		if ( (int) get_option( self::VERSION_OPT, 0 ) >= self::VERSION && $verified > time() - 12 * HOUR_IN_SECONDS ) {
			self::$exists[ $key ] = true;
			return true;
		}
		global $wpdb;
		$name  = self::table( $key );
		$cache = wp_cache_get( 'tbl_' . $key, 'publishrank_db' );
		if ( false !== $cache ) {
			self::$exists[ $key ] = '1' === $cache;
			return self::$exists[ $key ];
		}
		$found                = $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::$exists[ $key ] = $found;
		wp_cache_set( 'tbl_' . $key, $found ? '1' : '0', 'publishrank_db', 300 );
		if ( $found && 'traffic_daily' === $key ) {
			update_option( 'publishrank_db_verified', time(), true );
		}
		if ( ! $found && (int) get_option( self::VERSION_OPT, 0 ) >= self::VERSION ) {
			// Version says installed but the table is gone: allow the next request to re-create it.
			update_option( self::VERSION_OPT, 0, true );
		}
		return $found;
	}

	/** Forget the cached existence flags (tests, uninstall). */
	public static function forget() {
		self::$exists = array();
		foreach ( self::keys() as $key ) {
			wp_cache_delete( 'tbl_' . $key, 'publishrank_db' );
		}
	}

	/** Drop every PublishRank table (uninstall with "delete data" only). */
	public static function drop_all() {
		global $wpdb;
		foreach ( self::keys() as $key ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names are built from $wpdb->prefix and a fixed list.
		}
		delete_option( self::VERSION_OPT );
		self::forget();
	}

	/** Current GMT MySQL datetime. */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** Delete rows older than a cutoff in batches so a large purge never locks the table for long. */
	public static function purge_older_than( $key, $column, $cutoff, $batch = 5000, $max_batches = 40 ) {
		global $wpdb;
		if ( ! self::ready( $key ) ) {
			return 0;
		}
		$table   = self::table( $key );
		$deleted = 0;
		for ( $i = 0; $i < $max_batches; $i++ ) {
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} < %s LIMIT %d", $cutoff, $batch ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- fixed table/column names.
			$deleted += $n;
			if ( $n < $batch ) {
				break;
			}
		}
		return $deleted;
	}
}
