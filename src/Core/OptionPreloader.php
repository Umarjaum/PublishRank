<?php
/**
 * Loads every small PublishRank setting in one query per request.
 *
 * Without a persistent object cache WordPress runs one SELECT for every non-autoloaded option a request reads,
 * and one more for every option that does not exist (it cannot remember absence between requests). PublishRank
 * reads ~90 such settings on a typical front-end page. This preloader replaces them with a single query:
 * - existing settings up to 4 KB are put into the request cache exactly as WordPress would cache them;
 * - settings known to be absent are added to WordPress's "notoptions" list (absence is learned automatically
 *   and verified by the same query, so an option created later is never hidden);
 * - large values (sitemap XML, scan results, snapshots) stay lazy, so memory does not grow.
 * Sites with a persistent object cache (Redis, Memcached) already avoid these queries and are left alone.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class OptionPreloader {
	const INDEX    = 'publishrank_option_index';
	const MAX_SIZE = 4096;
	/** Settings prefixes. Transients are not preloaded: their names change (hashed keys) and values can be large. */
	const PREFIXES = array( 'publishrank_', 'uj_newsroom_' );

	/** @var array<string,bool> names read this request */
	private static $seen = array();

	public static function boot() {
		if ( wp_using_ext_object_cache() || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) || ! empty( $GLOBALS['publishrank_option_preloaded'] ) ) {
			return;
		}
		$GLOBALS['publishrank_option_preloaded'] = true;
		self::preload();
		add_filter( 'pre_option', array( __CLASS__, 'watch' ), 1, 2 );
		add_action( 'shutdown', array( __CLASS__, 'learn' ), 1 );
	}

	public static function preload() {
		global $wpdb;
		$exists   = array();
		$autoload = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes', 'on', 'auto-on', 'auto' );
		// One plain, index-friendly prefix query per prefix (fast on MySQL, MariaDB and SQLite alike).
		foreach ( self::PREFIXES as $prefix ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $rows ) ) {
				return;
			}
			foreach ( $rows as $r ) {
				$exists[ $r->option_name ] = true;
				if ( strlen( (string) $r->option_value ) <= self::MAX_SIZE && ! in_array( $r->autoload, $autoload, true ) ) {
					wp_cache_add( $r->option_name, $r->option_value, 'options' );
				}
			}
			unset( $rows );
		}
		$index = get_option( self::INDEX, array() );
		if ( is_array( $index ) && $index ) {
			$not = wp_cache_get( 'notoptions', 'options' );
			$not = is_array( $not ) ? $not : array();
			foreach ( $index as $name ) {
				if ( ! isset( $exists[ $name ] ) ) {
					$not[ $name ] = true;
				}
			}
			wp_cache_set( 'notoptions', $not, 'options' );
		}
	}

	/** Remember which PublishRank options this request read. */
	public static function watch( $pre, $option ) {
		$o = (string) $option;
		foreach ( self::PREFIXES as $p ) {
			if ( 0 === strpos( $o, $p ) ) {
				self::$seen[ $o ] = true;
				break;
			}
		}
		return $pre;
	}

	/** Add newly discovered absent options to the index (writes only when something new was learned). */
	public static function learn() {
		if ( ! self::$seen ) {
			return;
		}
		$not   = wp_cache_get( 'notoptions', 'options' );
		$not   = is_array( $not ) ? $not : array();
		$index = get_option( self::INDEX, array() );
		$index = is_array( $index ) ? $index : array();
		$add   = array();
		foreach ( array_keys( self::$seen ) as $name ) {
			if ( isset( $not[ $name ] ) && ! in_array( $name, $index, true ) && self::INDEX !== $name ) {
				$add[] = $name;
			}
		}
		if ( $add ) {
			update_option( self::INDEX, array_slice( array_values( array_unique( array_merge( $index, $add ) ) ), -600 ), true );
		}
	}
}
