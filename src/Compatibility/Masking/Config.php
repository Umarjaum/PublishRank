<?php
/**
 * Builds and stores the theme masking configuration.
 *
 * Two options: a tiny autoloaded "route" (enabled flag, URL prefix, signature) that the early asset server checks
 * on every request at no cost, and the full configuration (non-autoloaded) loaded only when masking runs.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility\Masking;

use PublishRankSEO\Compatibility\ThemeSeo;

defined( 'ABSPATH' ) || exit;

final class Config {
	const OPTION = 'publishrank_mask_config';
	const ROUTE  = 'publishrank_mask_route';

	/** @var array<string,mixed>|null */
	private static $cfg = null;

	public static function register() {
		add_action( 'switch_theme', array( __CLASS__, 'rebuild' ), 20 );
		add_action( 'activated_plugin', array( __CLASS__, 'rebuild' ), 20 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'rebuild' ), 20 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'rebuild' ), 20 );
		add_action( 'update_option_' . ThemeSeo::OPTION, array( __CLASS__, 'rebuild' ), 20 );
	}

	/** Change signature: rebuild when the theme, the plugin version or the settings change. */
	public static function signature() {
		return md5( get_stylesheet() . '|' . get_template() . '|' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '' ) );
	}

	/** @return array<string,mixed> */
	public static function get() {
		if ( null !== self::$cfg ) {
			return self::$cfg;
		}
		$route = get_option( self::ROUTE );
		$cfg   = get_option( self::OPTION );
		if ( ! is_array( $cfg ) || ! is_array( $route ) || ( isset( $route['sig'] ) ? $route['sig'] : '' ) !== self::signature() ) {
			$cfg = self::rebuild();
		}
		self::$cfg = $cfg;
		return $cfg;
	}

	private static function host_of( $url ) {
		$h = (string) wp_parse_url( $url, PHP_URL_HOST );
		$p = wp_parse_url( $url, PHP_URL_PORT );
		return $h . ( $p ? ':' . $p : '' );
	}

	/** Build, store and return the configuration. @return array<string,mixed> */
	public static function rebuild() {
		$s        = ThemeSeo::settings();
		$child    = wp_get_theme();
		$parent   = wp_get_theme( get_template() );
		$adapter  = Adapters::for_theme( get_template() );
		$maximum  = 'maximum' === $s['mask_level'];
		$salt     = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : home_url() );

		$words = array( get_stylesheet(), get_template(), $child->get( 'Name' ), $parent->get( 'Name' ), $child->get( 'TextDomain' ), $parent->get( 'TextDomain' ) );
		foreach ( preg_split( '/[\s,]+/', (string) $s['mask_tokens'], -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$words[] = $w;
		}
		$prefixes = (array) $adapter['prefixes'];
		foreach ( preg_split( '/[\s,]+/', (string) $s['mask_prefixes'], -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$prefixes[] = rtrim( $w, '-_' );
		}
		$map = TokenMap::build( array_filter( array_map( 'strval', $words ) ), $prefixes, $salt );

		// In-scope wp-content folders whose CSS/JS target the theme's identifiers.
		$scope   = array();
		$content = wp_normalize_path( WP_CONTENT_DIR );
		if ( $maximum ) {
			$plugins_rel = ltrim( str_replace( $content, '', wp_normalize_path( WP_PLUGIN_DIR ) ), '/' );
			$uploads     = wp_get_upload_dir();
			$uploads_rel = ltrim( str_replace( $content, '', wp_normalize_path( $uploads['basedir'] ) ), '/' );
			foreach ( (array) $adapter['plugins'] as $d ) {
				if ( is_dir( WP_PLUGIN_DIR . '/' . $d ) ) {
					$scope[] = $plugins_rel . '/' . $d . '/';
				}
			}
			foreach ( (array) $adapter['uploads'] as $d ) {
				$scope[] = $uploads_rel . '/' . $d . '/';
			}
			$tokens = array_filter( array( get_template(), get_stylesheet() ) );
			foreach ( (array) glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
				$name = basename( (string) $dir );
				foreach ( $tokens as $t ) {
					if ( strlen( $t ) >= 4 && 0 === strpos( $name, $t . '-' ) ) {
						$scope[] = $plugins_rel . '/' . $name . '/';
					}
				}
			}
			foreach ( Adapters::optimizer_dirs() as $d ) {
				$scope[] = trailingslashit( $d );
			}
			$scope = array_values( array_unique( $scope ) );
		}

		$hosts = array_unique( array_filter( array( self::host_of( home_url() ), self::host_of( site_url() ), self::host_of( content_url() ) ) ) );
		foreach ( preg_split( '/[\s,]+/', (string) $s['mask_cdn_hosts'], -1, PREG_SPLIT_NO_EMPTY ) as $h ) {
			$hosts[] = preg_replace( '#[^a-z0-9.:\-]#i', '', $h );
		}
		$credit_hosts = (array) $adapter['hosts'];
		foreach ( array( $child, $parent ) as $t ) {
			foreach ( array( 'ThemeURI', 'AuthorURI' ) as $h ) {
				$host = (string) wp_parse_url( (string) $t->get( $h ), PHP_URL_HOST );
				$host = preg_replace( '#^www\.#', '', $host );
				if ( $host && ! in_array( $host, array( 'wordpress.org', 'wordpress.com', preg_replace( '#:\d+$#', '', self::host_of( home_url() ) ) ), true ) ) {
					$credit_hosts[] = $host;
				}
			}
		}
		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$scheme    = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
		$cfg       = array(
			'enabled'      => (int) $s['mask'],
			'level'        => $maximum ? 'maximum' : 'standard',
			'identifiers'  => $maximum ? 1 : 0,
			'credits'      => (int) $s['mask_credits'],
			'rest'         => (int) $s['mask_rest'],
			'versions'     => (int) $s['mask_versions'],
			'sourcemaps'   => 'keep' === $s['mask_sourcemaps'] ? 'keep' : 'strip',
			'pairs'        => $map['pairs'],
			'reverse'      => $map['reverse'],
			'sources'      => $map['sources'],
			'skipped'      => $map['skipped'],
			'scope'        => $scope,
			'hosts'        => array_values( array_unique( $hosts ) ),
			'origin'       => ( $scheme ? $scheme : 'https' ) . '://' . self::host_of( home_url() ),
			'content_path' => (string) wp_parse_url( content_url(), PHP_URL_PATH ),
			'alias_path'   => $home_path . '/' . $s['mask_alias'],
			'roots'        => array(
				'c' => array( 'slug' => get_stylesheet(), 'dir' => get_stylesheet_directory() ),
				'p' => array( 'slug' => get_template(), 'dir' => get_template_directory() ),
				'x' => array( 'slug' => '', 'dir' => WP_CONTENT_DIR ),
			),
			'credit_hosts' => array_values( array_unique( array_filter( $credit_hosts ) ) ),
			'credit_words' => array_values( array_unique( array_filter( array( strtolower( (string) $child->get( 'Name' ) ), strtolower( (string) $parent->get( 'Name' ) ), str_replace( '-', ' ', get_template() ) ) ) ) ),
			'adapter'      => $adapter,
		);
		$cfg['vhash']     = substr( md5( $child->get( 'Version' ) . '|' . $parent->get( 'Version' ) . '|' . serialize( $s ) . '|' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '' ) . '|' . $salt ), 0, 10 );
		$cfg['hash']      = substr( md5( serialize( $cfg ) ), 0, 12 );
		$cfg['cache_dir'] = WP_CONTENT_DIR . '/cache/' . TokenMap::word( $salt . '|cache', 6 ) . '/' . $cfg['hash'];
		update_option( self::OPTION, $cfg, false );
		$enabled = $cfg['enabled'] && ThemeSeo::masking_available();
		update_option( self::ROUTE, array( 'on' => $enabled ? 1 : 0, 'prefix' => $cfg['alias_path'] . '/', 'sig' => self::signature() ), true );
		self::$cfg = $cfg;
		return $cfg;
	}

	/** Remove cached processed assets (all generations). */
	public static function purge_cache() {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
		$base = WP_CONTENT_DIR . '/cache/' . TokenMap::word( $salt . '|cache', 6 );
		if ( ! is_dir( $base ) ) {
			return 0;
		}
		$n  = 0;
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isDir() ) {
				@rmdir( $f->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $f->getPathname() ); // phpcs:ignore
				++$n;
			}
		}
		@rmdir( $base ); // phpcs:ignore
		return $n;
	}
}
