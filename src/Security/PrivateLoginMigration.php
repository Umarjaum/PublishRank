<?php
/**
 * Backward-compatibility layer for PublishRank Private Login settings.
 *
 * Releases up to 2.20 stored the private login address under generic third-party option names and
 * under "hide_login" names. PublishRank 2.21 keeps every setting under its own `publishrank_` names; this
 * class is the only place that still knows the old names, so the rest of the module stays independent.
 *
 * Safety rules:
 * - Values are copied to the PublishRank names once and never overwrite a value already saved there.
 * - Options with generic (non-PublishRank) names are only read and removed when no other login plugin
 *   that uses the same names is installed, so another plugin's configuration is never touched.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class PrivateLoginMigration {

	/** Storage version written after the migration ran. */
	const STORAGE_OPTION  = 'publishrank_private_login_storage';
	const STORAGE_VERSION = 2;

	/** Earlier PublishRank-owned names. @return array<string,string> old => new */
	public static function owned_map() {
		return array(
			'publishrank_hide_login_enabled'  => PrivateLogin::OPTION,
			'publishrank_hide_login_deferred' => PrivateLogin::DEFERRED_OPTION,
		);
	}

	/** Generic names written by releases up to 2.20. @return array<string,string> old => new */
	public static function generic_map() {
		return array(
			'whl_page'           => PrivateLogin::SLUG_OPTION,
			'whl_redirect_admin' => PrivateLogin::REDIRECT_OPTION,
		);
	}

	/** Earlier admin screen slugs that should open the Private Login screen. @return string[] */
	public static function old_screens() {
		return array( 'publishrank-wp-hide-login', 'whl_settings' );
	}

	/** Earlier user meta that marked the screen as seen. */
	public static function old_seen_meta() {
		return 'publishrank_hide_login_seen';
	}

	/**
	 * True when another installed login plugin may own the generic option names. Uses the plugin folder
	 * on disk, so it also works before plugins are loaded and during uninstall.
	 */
	public static function generic_names_in_use() {
		$dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '';
		if ( '' !== $dir && is_dir( $dir . '/wps-hide-login' ) ) {
			return true;
		}
		/** Filter: return true when another plugin on this site uses the same generic login option names. */
		return (bool) apply_filters( 'publishrank_private_login_generic_names_in_use', false );
	}

	/** Copy settings saved under earlier names to the current names, once. */
	public static function run() {
		if ( (int) get_option( self::STORAGE_OPTION, 0 ) >= self::STORAGE_VERSION ) {
			return;
		}
		self::move( self::owned_map(), true );
		if ( ! self::generic_names_in_use() ) {
			self::move( self::generic_map(), true );
		} else {
			self::move( self::generic_map(), false ); // Copy only; leave the other plugin's settings in place.
		}
		update_option( self::STORAGE_OPTION, self::STORAGE_VERSION );
	}

	/** @param array<string,string> $map old => new. */
	private static function move( array $map, $delete_old ) {
		foreach ( $map as $old => $new ) {
			$value = get_option( $old, null );
			if ( null !== $value && null === get_option( $new, null ) ) {
				add_option( $new, $value );
			}
			if ( $delete_old && null !== $value ) {
				delete_option( $old );
			}
			if ( is_multisite() ) {
				$network = get_site_option( $old, null );
				if ( null !== $network && null === get_site_option( $new, null ) ) {
					add_site_option( $new, $network );
				}
				if ( $delete_old && null !== $network ) {
					delete_site_option( $old );
				}
			}
		}
	}

	/** Translate earlier names inside an imported PublishRank settings file. @param array<string,mixed> $data */
	public static function translate_import( array $data ) {
		foreach ( self::generic_map() as $legacy => $current ) {
			if ( isset( $data[ $legacy ] ) && ! isset( $data[ $current ] ) ) {
				$data[ $current ] = $data[ $legacy ];
			}
			unset( $data[ $legacy ] );
		}
		return $data;
	}

	/** Option names uninstall may remove. @return string[] */
	public static function uninstall_options() {
		$names = array_keys( self::owned_map() );
		if ( ! self::generic_names_in_use() ) {
			$names = array_merge( $names, array_keys( self::generic_map() ) );
		}
		return array_merge( $names, array( self::STORAGE_OPTION, 'publishrank_wp_hide_login', 'publishrank_wp_hide_login_apache_rules_active' ) );
	}
}
