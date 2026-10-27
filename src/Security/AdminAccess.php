<?php
/**
 * Optional custom login entry point.
 *
 * WordPress core files and administration URLs are never renamed. The custom
 * query-key link grants this browser a short-lived pass to the native login
 * form. Logged-in administrators continue using native wp-admin and plugin
 * administration routes; unauthenticated admin requests are sent to the
 * custom entry link instead of being blocked with a dead-end 404.
 */
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class AdminAccess {
	const OPTION      = 'publishrank_admin_access_slug';
	const MODE_OPTION = 'publishrank_admin_access_mode';
	const QUERY_ARG   = 'publishrank_access';
	const COOKIE      = 'publishrank_admin_access';
	const TOKEN_TTL   = 43200;

	public function register() {
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
		add_action( 'init', array( $this, 'maybe_redirect_alias' ), 0 );
		add_action( 'init', array( $this, 'gate_native_admin_request' ), 1 );
		add_action( 'login_init', array( $this, 'gate_login' ), 0 );
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'lostpassword_url', array( $this, 'filter_lostpassword_url' ), 10, 2 );
	}

	/** Return the normalized custom key, or an empty string when disabled. */
	public static function slug() {
		$value = get_option( self::OPTION, '' );
		return is_string( $value ) ? trim( $value, " \t\n\r\0\x0B/" ) : '';
	}

	/** The custom route is enabled only when selected and configured. */
	public static function enabled() {
		return 'disabled' !== get_option( self::MODE_OPTION, 'custom' ) && '' !== self::slug();
	}

	/** Return a query-based entry URL that cannot shadow a public page slug. */
	public static function url() {
		return self::enabled() ? add_query_arg( self::QUERY_ARG, self::slug(), home_url( '/' ) ) : '';
	}

	/** Point WordPress-generated login links to the custom entry route. */
	public function filter_login_url( $login_url, $redirect = '', $force_reauth = false ) {
		if ( ! self::enabled() ) {
			return $login_url;
		}
		$url = self::url();
		if ( '' !== (string) $redirect ) {
			$url = add_query_arg( 'redirect_to', $redirect, $url );
		}
		if ( $force_reauth ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}
		return $url;
	}

	/** Keep standard password recovery actions available through the custom route. */
	public function filter_lostpassword_url( $url, $redirect = '' ) {
		if ( ! self::enabled() ) {
			return $url;
		}
		$url = add_query_arg( 'action', 'lostpassword', self::url() );
		return '' !== (string) $redirect ? add_query_arg( 'redirect_to', $redirect, $url ) : $url;
	}

	/** Resolve the custom entry link into the normal WordPress login form. */
	public function maybe_redirect_alias() {
		if ( ! self::enabled() || empty( $_SERVER['REQUEST_URI'] ) || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$key = isset( $_GET[ self::QUERY_ARG ] ) && is_scalar( $_GET[ self::QUERY_ARG ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
			? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
			: '';
		if ( '' === $key || ! hash_equals( self::slug(), $key ) ) {
			return;
		}

		// The shortcut is only active at the site's home path; it cannot intercept
		// other public pages, REST routes, or unrelated administration URLs.
		$request_path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home_path    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$request_path = '/' . trim( is_string( $request_path ) ? $request_path : '', '/' );
		$home_path    = '/' . trim( is_string( $home_path ) ? $home_path : '', '/' );
		if ( $request_path !== $home_path ) {
			return;
		}

		nocache_headers();
		$this->issue_access_cookie();
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( admin_url(), 302, 'PublishRank Custom Login' );
			exit;
		}

		$destination     = site_url( 'wp-login.php', 'login' );
		$args            = array();
		$allowed_actions = array( 'login', 'logout', 'lostpassword', 'retrievepassword', 'rp', 'resetpass', 'checkemail', 'confirmaction', 'confirm_admin_email', 'entered_recovery_mode', 'postpass', 'register' );
		if ( isset( $_GET['action'] ) && is_scalar( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
			$action = sanitize_key( (string) wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
			if ( in_array( $action, $allowed_actions, true ) ) {
				$args['action'] = $action;
			}
		}
		if ( isset( $_GET['redirect_to'] ) && is_scalar( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
			$args['redirect_to'] = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), admin_url() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
		}
		if ( isset( $_GET['reauth'] ) && is_scalar( $_GET['reauth'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['reauth'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
			$args['reauth'] = '1';
		}
		if ( ! empty( $args ) ) {
			$destination = add_query_arg( $args, $destination );
		}
		wp_safe_redirect( $destination, 302, 'PublishRank Custom Login' );
		exit;
	}

	/** Send unauthenticated admin-page requests to the custom login link. */
	public function gate_native_admin_request() {
		if ( ! self::enabled() || ! defined( 'WP_ADMIN' ) || ! WP_ADMIN || is_user_logged_in() || $this->has_access_cookie() ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'load-scripts.php', 'load-styles.php' ), true ) ) {
			return;
		}
		nocache_headers();
		$requested    = admin_url();
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$admin_path   = wp_parse_url( admin_url(), PHP_URL_PATH );
		$admin_prefix = is_string( $admin_path ) ? trailingslashit( $admin_path ) : '';
		if ( is_string( $request_path ) && '' !== $admin_prefix && 0 === strpos( $request_path, $admin_prefix ) ) {
			$relative  = ltrim( substr( $request_path, strlen( $admin_prefix ) ), '/' );
			$requested = admin_url( $relative );
			$query     = wp_parse_url( $request_uri, PHP_URL_QUERY );
			if ( is_string( $query ) && '' !== $query ) {
				$query_args = array();
				parse_str( $query, $query_args );
				$requested = add_query_arg( $query_args, $requested );
			}
		}
		$requested = wp_validate_redirect( $requested, admin_url() );
		wp_safe_redirect( add_query_arg( 'redirect_to', $requested, self::url() ), 302, 'PublishRank Custom Login' );
		exit;
	}

	/** Hide direct login-form access while preserving recovery/public actions. */
	public function gate_login() {
		if ( ! self::enabled() || is_user_logged_in() || $this->has_access_cookie() ) {
			return;
		}
		$raw_action     = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- used only to preserve public WordPress login actions.
		$action         = sanitize_key( (string) $raw_action );
		$public_actions = array( 'lostpassword', 'retrievepassword', 'rp', 'resetpass', 'checkemail', 'confirmaction', 'confirm_admin_email', 'entered_recovery_mode', 'postpass', 'register', 'logout' );
		if ( in_array( $action, $public_actions, true ) ) {
			return;
		}
		nocache_headers();
		wp_die( esc_html__( 'Use the site administrator’s custom login link to sign in.', 'publishrank' ), esc_html__( 'Not found', 'publishrank' ), array( 'response' => 404 ) );
	}

	/** Issue a short-lived HttpOnly browser pass for the native login form. */
	private function issue_access_cookie() {
		$token = wp_generate_password( 48, false, false );
		set_transient( self::transient_key( $token ), '1', self::TOKEN_TTL );
		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + self::TOKEN_TTL,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/** Validate the short-lived browser pass. */
	private function has_access_cookie() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) && is_scalar( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $token || strlen( $token ) > 128 ) {
			return false;
		}
		return '1' === get_transient( self::transient_key( $token ) );
	}

	private static function transient_key( $token ) {
		return 'publishrank_admin_gate_' . hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}
}
