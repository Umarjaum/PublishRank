<?php
/** Netlify Identity bridge for the optional PublishRank admin gate. */
namespace PublishRankSEO\Auth;

defined( 'ABSPATH' ) || exit;

final class NetlifyAuth {
	const SITE_URL = 'https://publishrank.netlify.app';
	const URL_OPTION = 'publishrank_netlify_url';
	const KEY_OPTION = 'publishrank_netlify_key';
	const ENABLED_OPTION = 'publishrank_netlify_auth_enabled';
	const SESSION_META = 'publishrank_netlify_session_enc';
	const SUBJECT_META = 'publishrank_netlify_subject';
	const PROVIDERS = array( 'google', 'github' );

	public function register() {
		add_action( 'admin_init', array( $this, 'gate_plugin_admin' ), 1 );
		add_action( 'admin_post_publishrank_supabase_save', array( $this, 'save_config' ) );
		add_action( 'admin_post_publishrank_supabase_start', array( $this, 'start_oauth' ) );
		add_action( 'admin_post_publishrank_supabase_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_publishrank_supabase_unlink', array( $this, 'unlink' ) );
		add_action( 'admin_post_publishrank_supabase_emergency_bypass', array( $this, 'emergency_bypass' ) );
	}
	public static function configured() { return true; }
	public static function enabled() { return (bool) get_option( self::ENABLED_OPTION, false ) && ! get_option( 'publishrank_netlify_emergency_bypass', false ); }
	public static function subject( $user_id = 0 ) { return (string) get_user_meta( $user_id ? (int) $user_id : get_current_user_id(), self::SUBJECT_META, true ); }
	public static function project_url() { return self::SITE_URL; }
	public static function publishable_key() { return ''; }
	public static function central_configured() { return true; }
	public static function uses_central_config() { return true; }
	public static function callback_url() { return admin_url( 'admin-post.php?action=publishrank_supabase_callback' ); }
	public static function enabled_providers( $force = false ) { return array( 'google' => true, 'github' => true ); }

	public function save_config() {
		$this->guard( 'publishrank_supabase_save' );
		$enabled = isset( $_POST['auth_enabled'] ) && '1' === (string) wp_unslash( $_POST['auth_enabled'] );
		update_option( self::ENABLED_OPTION, $enabled, false );
		delete_option( 'publishrank_netlify_emergency_bypass' );
		$this->redirect( 'success', $enabled ? __( 'Netlify Identity is ready and the optional PublishRank screen gate is enabled.', 'publishrank' ) : __( 'Netlify Identity is ready; the optional sign-in gate remains disabled.', 'publishrank' ) );
	}

	public function start_oauth() {
		$this->guard( 'publishrank_supabase_start' );
		$state = wp_generate_password( 40, false, false );
		set_transient( 'publishrank_netlify_pending_' . get_current_user_id(), array( 'state' => $state, 'created' => time() ), 10 * MINUTE_IN_SECONDS );
		$params = array( 'connect' => '1', 'site_url' => home_url( '/' ), 'return_url' => self::callback_url(), 'state' => $state );
		wp_redirect( esc_url_raw( add_query_arg( $params, untrailingslashit( self::SITE_URL ) . '/account.html' ) ) );
		exit;
	}

	public function callback() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only an existing WordPress administrator can link a PublishRank account.', 'publishrank' ), '', array( 'response' => 403 ) ); }
		$user_id = get_current_user_id(); $key = 'publishrank_netlify_pending_' . $user_id; $pending = get_transient( $key ); delete_transient( $key );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( ! is_array( $pending ) || empty( $pending['state'] ) || ! hash_equals( (string) $pending['state'], $state ) || time() - (int) ( $pending['created'] ?? 0 ) > 600 || '' === $code ) { $this->redirect( 'error', __( 'The Netlify connection transaction expired or did not match this administrator. Start again.', 'publishrank' ) ); }
		$response = wp_remote_get( add_query_arg( 'code', $code, untrailingslashit( self::SITE_URL ) . '/api/plugin-connection' ), array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Accept' => 'application/json' ) ) );
		$body = is_wp_error( $response ) ? array() : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) || empty( $body['connection_token'] ) || empty( $body['netlify_user_id'] ) ) { $this->redirect( 'error', __( 'Netlify connection-code exchange failed. Check that Identity and the database are enabled on the website.', 'publishrank' ) ); }
		$bundle = array( 'connection_token' => sanitize_text_field( (string) $body['connection_token'] ), 'subject' => sanitize_text_field( (string) $body['netlify_user_id'] ), 'email' => sanitize_email( (string) ( $body['email'] ?? '' ) ), 'provider' => 'netlify', 'verified_until' => 0 );
		$encrypted = self::encrypt( wp_json_encode( $bundle ) );
		if ( '' === $encrypted ) { $this->redirect( 'error', __( 'Encrypted session storage is unavailable. Enable OpenSSL and valid WordPress salts.', 'publishrank' ) ); }
		update_user_meta( $user_id, self::SESSION_META, $encrypted ); update_user_meta( $user_id, self::SUBJECT_META, $bundle['subject'] );
		$this->redirect( 'success', __( 'Netlify Identity linked to this existing WordPress administrator. No WordPress account or role was created or changed.', 'publishrank' ) );
	}

	public function gate_plugin_admin() {
		if ( ! is_admin() || is_network_admin() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! self::enabled() ) { return; }
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$is_plugin = 'publishrank' === $page || 0 === strpos( $page, 'publishrank-' ) || 0 === strpos( $page, 'publishrank_' );
		if ( ! $is_plugin || in_array( $page, array( 'publishrank-auth', 'publishrank-onboarding' ), true ) ) { return; }
		if ( ! $this->verify_session( get_current_user_id() ) ) { wp_safe_redirect( admin_url( 'admin.php?page=publishrank-auth&reauth=1' ) ); exit; }
	}
	public function unlink() { $this->guard( 'publishrank_supabase_unlink' ); delete_user_meta( get_current_user_id(), self::SESSION_META ); delete_user_meta( get_current_user_id(), self::SUBJECT_META ); $this->redirect( 'success', __( 'This administrator’s Netlify Identity connection was removed. WordPress access and roles are unchanged.', 'publishrank' ) ); }
	public function emergency_bypass() { $this->guard( 'publishrank_supabase_emergency_bypass' ); update_option( 'publishrank_netlify_emergency_bypass', 1, false ); $this->redirect( 'success', __( 'Emergency local WordPress-admin access is enabled until the identity gate is re-enabled.', 'publishrank' ) ); }
	public static function session_info( $user_id = 0 ) { $bundle = self::session_bundle( $user_id ? (int) $user_id : get_current_user_id() ); return is_array( $bundle ) ? array( 'email' => (string) ( $bundle['email'] ?? '' ), 'provider' => (string) ( $bundle['provider'] ?? '' ), 'subject' => (string) ( $bundle['subject'] ?? '' ) ) : array(); }

	private function verify_session( $user_id ) {
		$subject = self::subject( $user_id ); $bundle = self::session_bundle( $user_id );
		if ( '' === $subject || ! is_array( $bundle ) || empty( $bundle['connection_token'] ) || ! hash_equals( $subject, (string) ( $bundle['subject'] ?? '' ) ) ) { return false; }
		if ( (int) ( $bundle['verified_until'] ?? 0 ) <= time() ) {
			$res = wp_remote_get( untrailingslashit( self::SITE_URL ) . '/api/plugin-verify', array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . (string) $bundle['connection_token'] ) ) );
			$data = is_wp_error( $res ) ? array() : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! is_array( $data ) || empty( $data['authenticated'] ) ) { return false; }
			$bundle['verified_until'] = time() + 300;
			$encrypted = self::encrypt( wp_json_encode( $bundle ) ); if ( '' === $encrypted ) { return false; } update_user_meta( $user_id, self::SESSION_META, $encrypted );
		}
		return true;
	}
	private static function session_bundle( $user_id ) { $value = self::decrypt( (string) get_user_meta( (int) $user_id, self::SESSION_META, true ) ); $bundle = '' !== $value ? json_decode( $value, true ) : false; return is_array( $bundle ) ? $bundle : false; }
	private static function encrypt( $plain ) { if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) || '' === (string) wp_salt( 'auth' ) ) { return ''; } try { $iv = random_bytes( 12 ); } catch ( \Exception $e ) { return ''; } $key = hash_hmac( 'sha256', 'publishrank-netlify-session-v1', wp_salt( 'auth' ), true ); $tag = ''; $cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'publishrank-auth-v1', 16 ); return false === $cipher ? '' : base64_encode( $iv . $tag . $cipher ); }
	private static function decrypt( $encoded ) { if ( '' === $encoded || ! function_exists( 'openssl_decrypt' ) ) { return ''; } $raw = base64_decode( $encoded, true ); if ( false === $raw || strlen( $raw ) < 29 ) { return ''; } $key = hash_hmac( 'sha256', 'publishrank-netlify-session-v1', wp_salt( 'auth' ), true ); $plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'publishrank-auth-v1' ); return false === $plain ? '' : $plain; }
	private function guard( $action ) { if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only a WordPress administrator can manage this connection.', 'publishrank' ), '', array( 'response' => 403 ) ); } check_admin_referer( $action ); }
	private function redirect( $type, $message ) { set_transient( 'publishrank_supabase_notice_' . get_current_user_id(), array( 'type' => 'error' === $type ? 'error' : 'success', 'message' => (string) $message ), 120 ); wp_safe_redirect( admin_url( 'admin.php?page=publishrank-auth' ) ); exit; }
}
