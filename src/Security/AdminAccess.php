<?php
/**
 * Custom administrator access alias.
 *
 * This provides a private entry URL while retaining WordPress's native
 * /wp-admin/*.php routes for the authorized browser. Unauthenticated native
 * admin/login requests are stopped with 404; core service endpoints remain
 * available so AJAX, REST, cron, recovery and plugins do not break.
 */
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class AdminAccess {
	const OPTION = 'publishrank_admin_access_slug';
	const MODE_OPTION = 'publishrank_admin_access_mode';
	const COOKIE = 'publishrank_admin_access';
	const TOKEN_TTL = 43200;

	public function register() {
		add_action( 'parse_request', array( $this, 'maybe_redirect_alias' ), 0 );
		add_action( 'init', array( $this, 'gate_native_admin_request' ), 0 );
		add_action( 'admin_init', array( $this, 'gate_admin' ), 0 );
		add_action( 'login_init', array( $this, 'gate_login' ), 0 );
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'lostpassword_url', array( $this, 'filter_lostpassword_url' ), 10, 2 );
	}

	/** Return the normalized custom path, or an empty string when disabled. */
	public static function slug() {
		$value = get_option( self::OPTION, '' );
		return is_string( $value ) ? trim( $value, " \t\n\r\0\x0B/" ) : '';
	}

	/** Protection is enabled when the mode is enabled and a valid slug exists. */
	public static function enabled() {
		return 'disabled' !== get_option( self::MODE_OPTION, 'custom' ) && '' !== self::slug();
	}

	/** Return the configured custom access URL. */
	public static function url() {
		$slug = self::enabled() ? self::slug() : '';
		return '' !== $slug ? home_url( '/' . $slug . '/' ) : '';
	}

	/** Make core-generated login links point to the private entry URL. */
	public function filter_login_url( $login_url, $redirect = '', $force_reauth = false ) {
		if ( ! self::enabled() ) { return $login_url; }
		$url = self::url();
		if ( '' !== (string) $redirect ) { $url = add_query_arg( 'redirect_to', $redirect, $url ); }
		if ( $force_reauth ) { $url = add_query_arg( 'reauth', '1', $url ); }
		return $url;
	}

	/** Keep the standard recovery action on the private entry URL. */
	public function filter_lostpassword_url( $url, $redirect = '' ) {
		if ( ! self::enabled() ) { return $url; }
		$url = add_query_arg( 'action', 'lostpassword', self::url() );
		return '' !== (string) $redirect ? add_query_arg( 'redirect_to', $redirect, $url ) : $url;
	}

	/** Redirect only an exact request for the configured alias. */
	public function maybe_redirect_alias( $wp ) {
		$slug = self::enabled() ? self::slug() : '';
		if ( '' === $slug || empty( $_SERVER['REQUEST_URI'] ) || wp_doing_ajax() ) {
			return;
		}

		$request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$home_path    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$request_path = '/' . trim( is_string( $request_path ) ? $request_path : '', '/' );
		$home_path    = '/' . trim( is_string( $home_path ) ? $home_path : '', '/' );
		$expected     = ( '/' === $home_path ? '' : rtrim( $home_path, '/' ) ) . '/' . $slug;
		$expected     = '/' . trim( $expected, '/' );

		if ( untrailingslashit( $request_path ) !== $expected ) {
			return;
		}

		$this->issue_access_cookie();
		nocache_headers();
		$destination = site_url( 'wp-login.php?redirect_to=' . rawurlencode( admin_url() ), 'login' );
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$destination = admin_url();
		}
		wp_safe_redirect( $destination, 302, 'PublishRank Custom Admin Access' );
		exit;
	}

	/** Stop direct /wp-admin/*.php probes before core's unauthenticated redirect runs. */
	public function gate_native_admin_request() {
		if ( ! self::enabled() || ! defined( 'WP_ADMIN' ) || ! WP_ADMIN || $this->administrator_is_authenticated() || $this->has_access_cookie() ) { return; }
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) { return; }
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : '';
		if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'load-scripts.php', 'load-styles.php' ), true ) ) { return; }
		wp_die( esc_html__( 'The WordPress administration area is not available at this address.', 'publishrank' ), esc_html__( 'Not found', 'publishrank' ), array( 'response' => 404 ) );
	}

	/** Block dashboard screens unless this browser entered through the custom path. */
	public function gate_admin() {
		$is_admin_post = isset( $_SERVER['SCRIPT_NAME'] ) && 'admin-post.php' === basename( (string) $_SERVER['SCRIPT_NAME'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! self::enabled() || $this->administrator_is_authenticated() || $this->has_access_cookie() || wp_doing_ajax() || $is_admin_post || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		wp_die( esc_html__( 'The WordPress administration area is not available at this address.', 'publishrank' ), esc_html__( 'Not found', 'publishrank' ), array( 'response' => 404 ) );
	}

	/** Block direct wp-login.php access unless the browser first used the custom path. */
	public function gate_login() {
		$raw_action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? wp_unslash( $_REQUEST['action'] ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action is only used to preserve password recovery.
		$action = sanitize_key( (string) $raw_action );
		$recovery = in_array( $action, array( 'lostpassword', 'retrievepassword', 'rp', 'resetpass' ), true );
		if ( ! self::enabled() || $recovery || $this->administrator_is_authenticated() || $this->has_access_cookie() ) {
			return;
		}
		wp_die( esc_html__( 'The WordPress login page is not available at this address.', 'publishrank' ), esc_html__( 'Not found', 'publishrank' ), array( 'response' => 404 ) );
	}

	/** Keep an active administrator from being locked out when the short gate expires. */
	private function administrator_is_authenticated() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/** Issue a short-lived, HttpOnly browser gate after an exact custom-path visit. */
	private function issue_access_cookie() {
		$token = wp_generate_password( 48, false, false );
		set_transient( self::transient_key( $token ), '1', self::TOKEN_TTL );
		$secure = is_ssl();
		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + self::TOKEN_TTL,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/** Validate and consume no state from the access cookie; expiry is handled by the transient. */
	private function has_access_cookie() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		if ( '' === $token || strlen( $token ) > 128 ) {
			return false;
		}
		return '1' === get_transient( self::transient_key( $token ) );
	}

	private static function transient_key( $token ) {
		return 'publishrank_admin_gate_' . hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}
}
