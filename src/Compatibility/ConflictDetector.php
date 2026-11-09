<?php
/**
 * Detect other SEO plugins that would duplicate PublishRank output.
 *
 * Detection is deliberately layered so one missed signal does not hide a conflict:
 * 1. the plugin's main file in the active plugin list (single site and network-activated),
 *    matched by full path and by file name, so a renamed plugin folder is still found;
 * 2. a version constant, class or function the plugin defines (also finds must-use copies).
 *
 * It is safe on the front end: it reads options and checks symbols only, loads no admin files and is memoised per request.
 * The file names and symbols below come from each plugin's public distribution and have not been tested against every release.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility;

use PublishRankSEO\Core\OutputSettings;

defined( 'ABSPATH' ) || exit;

final class ConflictDetector {
	/** @var array<string,string[]>|null Per-request memo: plugin name => detection signals. */
	private static $memo = null;

	/**
	 * Known SEO plugins: files (main plugin files), constants, classes, functions.
	 *
	 * @return array<string,array<string,string[]>>
	 */
	public static function known() {
		return array(
			'Yoast SEO'          => array(
				'files'     => array( 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' ),
				'constants' => array( 'WPSEO_VERSION' ),
				'classes'   => array( 'WPSEO_Options' ),
			),
			'Rank Math SEO'      => array(
				'files'     => array( 'seo-by-rank-math/rank-math.php', 'rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php', 'rank-math-pro/rank-math-pro.php' ),
				'constants' => array( 'RANK_MATH_VERSION' ),
				'classes'   => array( 'RankMath' ),
			),
			'All in One SEO'     => array(
				'files'     => array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ),
				'constants' => array( 'AIOSEO_VERSION' ),
				'functions' => array( 'aioseo' ),
			),
			'SEOPress'           => array(
				'files'     => array( 'wp-seopress/seopress.php', 'wp-seopress-pro/seopress-pro.php' ),
				'constants' => array( 'SEOPRESS_VERSION' ),
			),
			'The SEO Framework'  => array(
				'files'     => array( 'autodescription/autodescription.php' ),
				'constants' => array( 'THE_SEO_FRAMEWORK_VERSION' ),
				'functions' => array( 'the_seo_framework' ),
			),
			'Squirrly SEO'       => array(
				'files' => array( 'squirrly-seo/squirrly.php' ),
			),
			'SmartCrawl SEO'     => array(
				'files'     => array( 'smartcrawl-seo/wpmu-dev-seo.php' ),
				'constants' => array( 'SMARTCRAWL_VERSION' ),
			),
			'Slim SEO'           => array(
				'files'     => array( 'slim-seo/slim-seo.php' ),
				'constants' => array( 'SLIM_SEO_VER' ),
			),
		);
	}

	/** Active plugin files (lower-cased), including network-activated plugins on multisite. @return string[] */
	private static function active_files() {
		$files = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_unique( array_map( 'strtolower', array_filter( array_map( 'strval', $files ) ) ) ) );
	}

	/**
	 * Detected plugins with how each was found.
	 *
	 * @return array<string,string[]> Plugin name => signals ("active plugin file", "constant X", ...).
	 */
	public function details() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$active = self::active_files();
		$bases  = array_map( 'basename', $active );
		$found  = array();
		foreach ( self::known() as $name => $signals ) {
			$hits = array();
			foreach ( (array) ( $signals['files'] ?? array() ) as $file ) {
				$file = strtolower( $file );
				if ( in_array( $file, $active, true ) ) {
					$hits[] = __( 'active plugin file', 'publishrank' );
				} elseif ( in_array( basename( $file ), $bases, true ) ) {
					$hits[] = __( 'active plugin file (renamed folder)', 'publishrank' );
				}
			}
			foreach ( (array) ( $signals['constants'] ?? array() ) as $constant ) {
				if ( defined( $constant ) ) {
					$hits[] = sprintf( 'constant %s', $constant ); }
			}
			foreach ( (array) ( $signals['classes'] ?? array() ) as $class ) {
				if ( class_exists( $class, false ) ) {
					$hits[] = sprintf( 'class %s', $class ); }
			}
			foreach ( (array) ( $signals['functions'] ?? array() ) as $function ) {
				if ( function_exists( $function ) ) {
					$hits[] = sprintf( 'function %s()', $function ); }
			}
			if ( $hits ) {
				$found[ $name ] = array_values( array_unique( $hits ) );
			}
		}
		// Developers can add SEO plugins to this list, or remove one they have deliberately handled.
		$final = array();
		foreach ( (array) apply_filters( 'publishrank_detected_seo_plugins', array_keys( $found ) ) as $name ) {
			$name = sanitize_text_field( is_scalar( $name ) ? (string) $name : '' );
			if ( '' !== $name ) {
				$final[ $name ] = $found[ $name ] ?? array( __( 'added by a filter', 'publishrank' ) ); }
		}
		$found = $final;
		self::$memo = $found;
		return $found;
	}

	/** @return string[] Names of active SEO plugins. */
	public function active() {
		return array_keys( $this->details() );
	}

	/** @return array<string,string> status + message */
	public function diagnostics() {
		$active = $this->active();
		if ( empty( $active ) ) {
			return array(
				'status'  => 'PASS',
				'message' => __( 'No other SEO plugin was detected.', 'publishrank' ),
			);
		}
		if ( OutputSettings::external_seo_handoff() ) {
			/* translators: %s: plugin names. */
			return array(
				'status'  => 'PASS',
				'message' => sprintf( __( '%s is active. Ownership handoff is enabled: PublishRank suppresses its overlapping frontend metadata and page schema; other settings are unchanged.', 'publishrank' ), implode( ', ', $active ) ),
			);
		}
		return array(
			'status'  => 'WARNING',
			/* translators: %s: comma-separated names of active SEO plugins. */
			'message' => sprintf( __( '⚠️ %s is active and will print duplicate titles, canonicals, meta descriptions or schema alongside PublishRank. Keep only one SEO plugin responsible for output: import its data with Migration & Portability, then deactivate it, or enable the ownership handoff in PublishRank Settings.', 'publishrank' ), implode( ', ', $active ) ),
		);
	}
}
