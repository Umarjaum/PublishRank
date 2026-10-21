<?php
/**
 * Custom administrator access alias.
 *
 * This intentionally adds an alternate private-looking entry URL without
 * disabling WordPress's native wp-admin path. Blocking or rewriting native
 * admin URLs from a plugin can break AJAX, REST, cron, recovery and plugins.
 */
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class AdminAccess {
	const OPTION = 'publishrank_admin_access_slug';
	const COOKIE = 'publishrank_admin_access';
	const TOKEN_TTL = 43200;

	public function register() {
		add_action( 'parse_request', array( $this, 'maybe_redirect_alias' ), 0 );
		add_action( 'admin_init', array( $this, 'gate_admin' ), 0 );
		add_action( 'login_init', array( $this, 'gate_login' ), 0 );
	}

	/** Return the normalized custom path, or an empty string when disabled. */
	public static function slug() {
		$value = get_option( self::OPTION, '' );
		return is_string( $value ) ? trim( $value, " \t\n\r\0\x0B/" ) : '';
	}

	/** Return the configured custom access URL. */
	public static function url() {
		$slug = self::slug();
		return '' !== $slug ? home_url( '/' . $slug . '/' ) : '';
	}

	/** Redirect only an exact request for the configured alias. */
	public function maybe_redirect_alias( $wp ) {
		$slug = self::slug();
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
		$destination = wp_login_url( admin_url() );
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$destination = admin_url();
		}
		wp_safe_redirect( $destination, 302, 'PublishRank Custom Admin Access' );
		exit;
	}

	/** Block dashboard screens unless this browser entered through the custom path. */
	public function gate_admin() {
		$is_admin_post = isset( $_SERVER['SCRIPT_NAME'] ) && 'admin-post.php' === basename( (string) $_SERVER['SCRIPT_NAME'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === self::slug() || $this->has_access_cookie() || wp_doing_ajax() || $is_admin_post || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		wp_die( esc_html__( 'The WordPress administration area is not available at this address.', 'publishrank' ), esc_html__( 'Not found', 'publishrank' ), array( 'response' => 404 ) );
	}

	/** Block direct wp-login.php access unless the browser first used the custom path. */
	public function gate_login() {
		if ( '' === self::slug() || $this->has_access_cookie() ) {
			return;
		}
		wp_die( esc_html__( 'The WordPress login page is not available at this address.', 'publishrank' ), esc_html__( 'Not found', 'publishrank' ), array( 'response' => 404 ) );
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
