<?php
/**
 * Access Shield settings: one option array, normalised on every read.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION = 'publishrank_access_shield';

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			// Login address.
			'login_enabled'      => 0,
			'login_slug'         => '',
			'blocked_behaviour'  => '404', // 404 | redirect.
			'blocked_redirect'   => '',
			// Brute force.
			'throttle_enabled'   => 1,
			'max_attempts'       => 5,
			'window_minutes'     => 15,
			'lock_ladder'        => '1,5,15,60,1440',
			'user_flood'         => 20,
			'flood_ips'          => 5,
			'retention_days'     => 30,
			'log_retention_days' => 30,
			'uniform_errors'     => 1,
			'equalize_timing'    => 1,
			'honeypot'           => 1,
			'min_submit_seconds' => 2,
			'allow_ips'          => '',
			'deny_ips'           => '',
			'never_block_ips'    => '',
			'deny_countries'     => '',
			'allow_countries'    => '',
			// Credential paths.
			'xmlrpc_mode'        => 'no_multicall', // on | no_multicall | no_auth | off.
			'app_passwords'      => 'on', // on | admins | off.
			'block_author_enum'  => 1,
			'block_rest_users'   => 1,
			'hide_oembed_author' => 1,
			// CAPTCHA.
			'captcha_provider'   => 'none', // none | turnstile | hcaptcha | recaptcha.
			'captcha_site_key'   => '',
			'captcha_secret'     => '',
			'captcha_mode'       => 'challenge', // always | challenge | after_failures.
			'captcha_after'      => 3,
			'captcha_forms'      => array( 'login', 'register', 'lostpassword' ),
			'captcha_fail_open'  => 1,
			// Two-factor.
			'twofa_enabled'      => 1,
			'twofa_roles'        => array(),
			'twofa_grace_days'   => 7,
			'twofa_app_password' => 1,
			// Alerts.
			'alert_enabled'      => 1,
			'alert_email'        => '',
			'alert_threshold'    => 10,
			// Sessions.
			'session_hours'      => 0,
			'session_remember_days' => 0,
			// 2.25 account protection for privileged roles.
			'pw_policy'          => 1,
			'pw_min_length'      => 12,
			'pw_breach_check'    => 1,
			'pw_roles'           => array( 'administrator', 'editor', 'shop_manager' ),
			'max_sessions'       => 0,
			'idle_minutes'       => 0,
		);
	}

	/** @return array<string,mixed> */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved       = get_option( self::OPTION, array() );
		$saved       = is_array( $saved ) ? $saved : array();
		self::$cache = array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Save a partial update after sanitising. */
	public static function update( array $changes ) {
		$next = array_merge( self::all(), self::sanitize( $changes ) );
		update_option( self::OPTION, $next, true );
		self::$cache = null;
		return self::all();
	}

	public static function flush() {
		self::$cache = null;
	}

	/** @param array<string,mixed> $in */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( $in as $key => $value ) {
			if ( ! array_key_exists( $key, $d ) ) {
				continue;
			}
			switch ( $key ) {
				case 'login_slug':
					$out[ $key ] = LoginAddress::clean_slug( $value );
					break;
				case 'blocked_behaviour':
					$out[ $key ] = in_array( $value, array( '404', 'redirect' ), true ) ? $value : '404';
					break;
				case 'blocked_redirect':
					$out[ $key ] = LoginAddress::clean_slug( $value );
					break;
				case 'xmlrpc_mode':
					$out[ $key ] = in_array( $value, array( 'on', 'no_multicall', 'no_auth', 'off' ), true ) ? $value : 'no_multicall';
					break;
				case 'app_passwords':
					$out[ $key ] = in_array( $value, array( 'on', 'admins', 'off' ), true ) ? $value : 'on';
					break;
				case 'captcha_provider':
					$out[ $key ] = in_array( $value, array( 'none', 'turnstile', 'hcaptcha', 'recaptcha' ), true ) ? $value : 'none';
					break;
				case 'captcha_mode':
					$out[ $key ] = in_array( $value, array( 'always', 'challenge', 'after_failures' ), true ) ? $value : 'challenge';
					break;
				case 'captcha_site_key':
					$out[ $key ] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
					break;
				case 'captcha_secret':
					$plain       = trim( (string) $value );
					$out[ $key ] = '' === $plain ? '' : Crypto::encrypt( preg_replace( '/[^A-Za-z0-9_\-.]/', '', $plain ) );
					break;
				case 'captcha_forms':
					$out[ $key ] = array_values( array_intersect( array_map( 'sanitize_key', (array) $value ), array( 'login', 'register', 'lostpassword', 'comments' ) ) );
					break;
				case 'twofa_roles':
					$roles       = array_keys( wp_roles()->roles );
					$out[ $key ] = array_values( array_intersect( array_map( 'sanitize_key', (array) $value ), $roles ) );
					break;
				case 'lock_ladder':
					$steps = array();
					foreach ( preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) as $m ) {
						$m = absint( $m );
						if ( $m > 0 ) {
							$steps[] = min( 43200, $m );
						}
					}
					$out[ $key ] = $steps ? implode( ',', array_slice( $steps, 0, 10 ) ) : $d['lock_ladder'];
					break;
				case 'allow_ips':
				case 'deny_ips':
				case 'never_block_ips':
					$res         = \PublishRankSEO\Security\TrafficGuard::inspect_networks( (string) $value );
					$out[ $key ] = implode( "\n", $res['valid'] );
					break;
				case 'deny_countries':
				case 'allow_countries':
					$res         = \PublishRankSEO\Security\TrafficGuard::inspect_country_codes( (string) $value );
					$out[ $key ] = implode( ',', $res['valid'] );
					break;
				case 'alert_email':
					$out[ $key ] = sanitize_email( (string) $value );
					break;
				case 'max_attempts':
					$out[ $key ] = max( 1, min( 100, absint( $value ) ) );
					break;
				case 'window_minutes':
					$out[ $key ] = max( 1, min( 1440, absint( $value ) ) );
					break;
				case 'user_flood':
					$out[ $key ] = max( 3, min( 10000, absint( $value ) ) );
					break;
				case 'flood_ips':
					$out[ $key ] = max( 2, min( 1000, absint( $value ) ) );
					break;
				case 'retention_days':
				case 'log_retention_days':
					$out[ $key ] = max( 1, min( 365, absint( $value ) ) );
					break;
				case 'min_submit_seconds':
					$out[ $key ] = max( 0, min( 30, absint( $value ) ) );
					break;
				case 'captcha_after':
					$out[ $key ] = max( 1, min( 50, absint( $value ) ) );
					break;
				case 'twofa_grace_days':
					$out[ $key ] = max( 0, min( 90, absint( $value ) ) );
					break;
				case 'alert_threshold':
					$out[ $key ] = max( 1, min( 10000, absint( $value ) ) );
					break;
				case 'session_hours':
					$out[ $key ] = max( 0, min( 720, absint( $value ) ) );
					break;
				case 'session_remember_days':
					$out[ $key ] = max( 0, min( 365, absint( $value ) ) );
					break;
				case 'pw_min_length':
					$out[ $key ] = max( 8, min( 64, absint( $value ) ) );
					break;
				case 'pw_roles':
					$out[ $key ] = array_values( array_intersect( array_map( 'sanitize_key', (array) $value ), array_keys( wp_roles()->roles ) ) );
					break;
				case 'max_sessions':
					$out[ $key ] = max( 0, min( 20, absint( $value ) ) );
					break;
				case 'idle_minutes':
					$out[ $key ] = max( 0, min( 1440, absint( $value ) ) );
					break;
				default:
					$out[ $key ] = empty( $value ) ? 0 : 1;
			}
		}
		return $out;
	}

	/** Lock durations in minutes. @return int[] */
	public static function ladder() {
		return array_map( 'absint', explode( ',', (string) self::get( 'lock_ladder' ) ) );
	}

	/** @return string[] */
	public static function networks( $key ) {
		$v = trim( (string) self::get( $key ) );
		return '' === $v ? array() : preg_split( '/\s+/', $v );
	}

	/** @return string[] */
	public static function countries( $key ) {
		$v = trim( (string) self::get( $key ) );
		return '' === $v ? array() : \PublishRankSEO\Core\Countries::parse_list( $v, true )['valid'];
	}
}
