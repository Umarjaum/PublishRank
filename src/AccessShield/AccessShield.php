<?php
/**
 * PublishRank Access Shield bootstrap.
 *
 * First-party PublishRank security module built on WordPress's documented hooks (authenticate,
 * wp_login_failed, login_form_*, site_url, wp_redirect, xmlrpc_methods, application-password actions,
 * rest_endpoints). It succeeded PublishRank's earlier "Private Login" screen (removed in 2.24.0) and
 * carries that screen's saved sign-in address forward once, on first run.
 *
 * Emergency switches: PUBLISHRANK_DISABLE_ACCESS_SHIELD (everything) and the older
 * PUBLISHRANK_DISABLE_PRIVATE_LOGIN (login address only).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AccessShield {
	const MASTER   = 'publishrank_access_shield_enabled';
	const IMPORTED = 'publishrank_access_shield_imported';

	/** @var bool */
	private static $booted = false;

	public static function enabled() {
		if ( defined( 'PUBLISHRANK_DISABLE_ACCESS_SHIELD' ) && PUBLISHRANK_DISABLE_ACCESS_SHIELD ) {
			return false;
		}
		return (bool) get_option( self::MASTER, 1 );
	}

	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		self::maybe_import_legacy();
		SecurityLog::register();
		\PublishRankSEO\AccessShield\LoginBridge::bridge();
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
		if ( ! self::enabled() ) {
			return;
		}
		Hardening::register();
		Guard::register();
		TwoFactor::register();
		AccountSecurity::register();
		LoginAddress::register();
		LoginDesign::register();
	}

	/** One-time, read-only import of the sign-in address saved by PublishRank releases before 2.24. */
	public static function maybe_import_legacy() {
		if ( get_option( self::IMPORTED ) ) {
			return;
		}
		$current = get_option( Settings::OPTION, null );
		if ( null === $current ) {
			$enabled  = get_option( 'publishrank_private_login_enabled', null );
			$slug     = (string) get_option( 'publishrank_login_slug', '' );
			$redirect = (string) get_option( 'publishrank_login_redirect', '' );
			$slug  = LoginAddress::clean_slug( $slug );
			$input = array();
			if ( '' !== $slug && (bool) $enabled ) {
				// The address was already in use before this upgrade: keep it working exactly as it was.
				$input['login_enabled'] = 1;
				$input['login_slug']    = $slug;
			}
			if ( '' !== $redirect && '404' !== $redirect ) {
				$input['blocked_behaviour'] = 'redirect';
				$input['blocked_redirect']  = $redirect;
			}
			update_option( Settings::OPTION, array_merge( Settings::defaults(), Settings::sanitize( $input ) ), true );
			Settings::flush();
			if ( ! empty( $input['login_enabled'] ) ) {
				SecurityLog::add( 'settings', 'info', array( 'detail' => 'Imported the private login address from an earlier version.' ) );
			}
		}
		update_option( self::IMPORTED, gmdate( 'c' ), false );
	}

	/** Turn the private login address off (CLI recovery). */
	public static function disable_login_address() {
		Settings::update( array( 'login_enabled' => 0 ) );
		delete_option( LoginAddress::PENDING_OPT );
		SecurityLog::add( 'settings', 'warn', array( 'detail' => 'Private login address turned off (recovery).' ) );
		flush_rewrite_rules( false );
	}

	/** Status summary for CLI / REST / dashboard. @return array<string,mixed> */
	public static function status() {
		$counts = SecurityLog::counts( 24 );
		return array(
			'enabled'          => self::enabled(),
			'constant_off'     => LoginAddress::disabled_by_constant(),
			'login_address'    => LoginAddress::active() ? LoginAddress::url() : '',
			'pending_change'   => (bool) LoginAddress::pending(),
			'throttle'         => (bool) Settings::get( 'throttle_enabled' ),
			'xmlrpc_mode'      => (string) Settings::get( 'xmlrpc_mode' ),
			'captcha'          => Captcha::configured() ? (string) Settings::get( 'captcha_provider' ) : 'none',
			'challenge_active' => Guard::challenge_active(),
			'active_locks'     => count( Guard::active_locks( 1000 ) ),
			'failed_24h'       => isset( $counts['login_failed'] ) ? $counts['login_failed'] : 0,
			'lockouts_24h'     => isset( $counts['lockout'] ) ? $counts['lockout'] : 0,
			'hardening_score'  => Hardening::audit( false )['score'],
		);
	}

	public static function routes() {
		$ns = 'publishrank/v1';
		register_rest_route(
			$ns,
			'/shield/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'view_security_log' ),
				'callback'            => static function () {
					return rest_ensure_response( self::status() );
				},
			)
		);
		register_rest_route(
			$ns,
			'/shield/log',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'view_security_log' ),
				'args'                => array(
					'event'    => array( 'type' => 'string' ),
					'ip'       => array( 'type' => 'string' ),
					'username' => array( 'type' => 'string' ),
					'search'   => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res  = SecurityLog::query(
						array(
							'event'    => $r['event'],
							'ip'       => $r['ip'],
							'username' => $r['username'],
							'search'   => $r['search'],
							'paged'    => $r['page'],
							'per_page' => $r['per_page'],
						)
					);
					$resp = rest_ensure_response( $res['rows'] );
					$resp->header( 'X-WP-Total', (string) $res['total'] );
					return $resp;
				},
			)
		);
		register_rest_route(
			$ns,
			'/shield/locks',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => Capabilities::rest( 'view_security_log' ),
					'callback'            => static function () {
						return rest_ensure_response( Guard::active_locks( 500 ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => Capabilities::rest( 'manage_security' ),
					'args'                => array( 'target' => array( 'type' => 'string', 'required' => true ) ),
					'callback'            => static function ( \WP_REST_Request $r ) {
						return rest_ensure_response( array( 'removed' => Guard::unlock( (string) $r['target'] ) ) );
					},
				),
			)
		);
		register_rest_route(
			$ns,
			'/shield/audit',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'manage_security' ),
				'callback'            => static function () {
					return rest_ensure_response( Hardening::audit( true ) );
				},
			)
		);
		register_rest_route(
			$ns,
			'/shield/verify',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => Capabilities::rest( 'manage_security' ),
					'callback'            => static function () {
						return rest_ensure_response( Verifier::last() );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => Capabilities::rest( 'manage_security' ),
					'callback'            => static function () {
						return rest_ensure_response( Verifier::run() );
					},
				),
			)
		);
	}
}
