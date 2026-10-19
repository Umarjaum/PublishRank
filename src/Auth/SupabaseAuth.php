<?php
/** Supabase identity is an additional PublishRank admin gate, never a WordPress role source. */
namespace PublishRankSEO\Auth;

defined( 'ABSPATH' ) || exit;

final class SupabaseAuth {
	const URL_OPTION = 'publishrank_supabase_url';
	const KEY_OPTION = 'publishrank_supabase_anon_key';
	const DEFAULT_PROJECT_URL = 'https://pgduryvupdltsqzqoiav.supabase.co';
	const DEFAULT_PUBLISHABLE_KEY = 'sb_publishable_M2yLwUrQHDh-ikMOr8qS5A_gIkVgx94';
	const ENABLED_OPTION = 'publishrank_supabase_auth_enabled';
	const SESSION_META = 'publishrank_supabase_session_enc';
	const SUBJECT_META = 'publishrank_supabase_subject';
	const PROVIDERS = array( 'google', 'apple', 'azure', 'bitbucket', 'discord', 'facebook', 'figma', 'github', 'gitlab', 'kakao', 'keycloak', 'linkedin_oidc', 'notion', 'slack_oidc', 'spotify', 'twitter', 'twitch', 'workos', 'zoom' );

	public function register() {
		add_action( 'admin_init', array( $this, 'gate_plugin_admin' ), 1 );
		add_action( 'admin_post_publishrank_supabase_save', array( $this, 'save_config' ) );
		add_action( 'admin_post_publishrank_supabase_start', array( $this, 'start_oauth' ) );
		add_action( 'admin_post_publishrank_supabase_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_publishrank_supabase_unlink', array( $this, 'unlink' ) );
		add_action( 'admin_post_publishrank_supabase_emergency_bypass', array( $this, 'emergency_bypass' ) );
	}

	public static function configured() { return self::valid_project_url( self::project_url() ) && strlen( self::publishable_key() ) >= 20; }
	public static function enabled() { return self::configured() && (bool) get_option( self::ENABLED_OPTION, false ) && ! get_option( 'publishrank_supabase_emergency_bypass', false ); }
	public static function subject( $user_id = 0 ) { return (string) get_user_meta( $user_id ? (int) $user_id : get_current_user_id(), self::SUBJECT_META, true ); }
	public static function project_url() { $url = (string) get_option( self::URL_OPTION, '' ); return untrailingslashit( '' !== $url ? $url : self::DEFAULT_PROJECT_URL ); }
	public static function publishable_key() { $key = (string) get_option( self::KEY_OPTION, '' ); return '' !== $key ? $key : self::DEFAULT_PUBLISHABLE_KEY; }
	public static function central_configured() { return self::valid_project_url( self::DEFAULT_PROJECT_URL ) && strlen( self::DEFAULT_PUBLISHABLE_KEY ) >= 20; }
	public static function uses_central_config() { return self::central_configured() && '' === (string) get_option( self::URL_OPTION, '' ) && '' === (string) get_option( self::KEY_OPTION, '' ); }
	/**
	 * Providers switched on in the Supabase project (public /auth/v1/settings), cached 10 minutes.
	 * @return array<string,bool>|null null when the project cannot be queried (callers then show everything).
	 */
	public static function enabled_providers( $force = false ) {
		if ( ! self::configured() ) { return null; }
		$key = 'publishrank_supabase_providers_' . md5( self::project_url() );
		if ( ! $force ) { $cached = get_transient( $key ); if ( is_array( $cached ) ) { return $cached; } }
		$res = wp_remote_get( self::project_url() . '/auth/v1/settings', array( 'timeout' => 8, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'apikey' => self::publishable_key(), 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) { return null; }
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || ! isset( $body['external'] ) || ! is_array( $body['external'] ) ) { return null; }
		$out = array(); foreach ( self::PROVIDERS as $provider ) { $out[ $provider ] = ! empty( $body['external'][ $provider ] ); }
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}
	public static function callback_url() { return admin_url( 'admin-post.php?action=publishrank_supabase_callback' ); }

	public function save_config() {
		$this->guard( 'publishrank_supabase_save' );
		if ( self::uses_central_config() ) {
			$enabled = isset( $_POST['auth_enabled'] ) && '1' === (string) wp_unslash( $_POST['auth_enabled'] );
			update_option( self::ENABLED_OPTION, $enabled, false );
			delete_option( 'publishrank_supabase_emergency_bypass' );
			$this->redirect( 'success', $enabled ? __( 'The shared Supabase identity service is ready. Link this WordPress administrator before the optional screen gate is enforced.', 'publishrank' ) : __( 'The shared Supabase identity service is ready; the optional sign-in gate remains disabled.', 'publishrank' ) );
		}
		$url = isset( $_POST['project_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['project_url'] ) ) ) : '';
		$key = isset( $_POST['anon_key'] ) ? trim( (string) wp_unslash( $_POST['anon_key'] ) ) : '';
		$enabled = isset( $_POST['auth_enabled'] ) && '1' === (string) wp_unslash( $_POST['auth_enabled'] );
		if ( ! self::valid_project_url( $url ) ) { $this->redirect( 'error', __( 'Use an HTTPS Supabase project URL, for example https://your-project.supabase.co.', 'publishrank' ) ); }
		if ( strlen( $key ) < 20 || preg_match( '/\s/', $key ) ) { $this->redirect( 'error', __( 'Enter the Supabase publishable/anon key. Never use a service_role key in this plugin.', 'publishrank' ) ); }
		update_option( self::URL_OPTION, untrailingslashit( $url ), false );
		update_option( self::KEY_OPTION, $key, false );
		update_option( self::ENABLED_OPTION, $enabled, false );
		delete_option( 'publishrank_supabase_emergency_bypass' );
		$this->redirect( 'success', $enabled ? __( 'Supabase is configured. Link this WordPress administrator to enable the PublishRank screen gate.', 'publishrank' ) : __( 'Supabase configuration saved. The additional sign-in gate remains disabled.', 'publishrank' ) );
	}

	public function start_oauth() {
		$this->guard( 'publishrank_supabase_start' );
		if ( ! self::configured() ) { $this->redirect( 'error', __( 'Configure the Supabase project URL and publishable key first.', 'publishrank' ) ); }
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! in_array( $provider, self::PROVIDERS, true ) ) { $this->redirect( 'error', __( 'Choose a supported Supabase identity provider.', 'publishrank' ) ); }
		try { $verifier = self::base64url( random_bytes( 48 ) ); } catch ( \Exception $e ) { $this->redirect( 'error', __( 'Secure random generation is unavailable on this server.', 'publishrank' ) ); }
		set_transient( 'publishrank_supabase_pending_' . get_current_user_id(), array( 'user_id' => get_current_user_id(), 'verifier' => $verifier, 'provider' => $provider, 'created' => time() ), 10 * MINUTE_IN_SECONDS );
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );
		$url = self::project_url() . '/auth/v1/authorize?' . http_build_query( array( 'provider' => $provider, 'redirect_to' => self::callback_url(), 'code_challenge' => $challenge, 'code_challenge_method' => 's256' ), '', '&', PHP_QUERY_RFC3986 );
		wp_redirect( $url );
		exit;
	}

	public function callback() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only an existing WordPress administrator can link a PublishRank account.', 'publishrank' ), '', array( 'response' => 403 ) ); }
		$user_id = get_current_user_id(); $key = 'publishrank_supabase_pending_' . $user_id; $data = get_transient( $key ); delete_transient( $key );
		if ( ! is_array( $data ) || (int) ( $data['user_id'] ?? 0 ) !== $user_id || empty( $data['verifier'] ) || time() - (int) ( $data['created'] ?? 0 ) > 600 ) { $this->redirect( 'error', __( 'The Supabase sign-in transaction expired or did not match this administrator. Start again.', 'publishrank' ) ); }
		if ( isset( $_GET['error'] ) ) { $this->redirect( 'error', __( 'The identity provider denied or cancelled sign-in.', 'publishrank' ) ); }
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) { $this->redirect( 'error', __( 'Supabase did not return an authorization code.', 'publishrank' ) ); }
		$response = $this->api( '/auth/v1/token?grant_type=pkce', 'POST', array( 'auth_code' => $code, 'code_verifier' => (string) ( $data['verifier'] ?? '' ) ) );
		if ( is_wp_error( $response ) || empty( $response['access_token'] ) || empty( $response['refresh_token'] ) ) { $this->redirect( 'error', __( 'Supabase token exchange failed. Check the project, enabled provider and redirect allow-list.', 'publishrank' ) ); }
		$access = (string) $response['access_token']; $profile = $this->api( '/auth/v1/user', 'GET', null, $access );
		if ( is_wp_error( $profile ) || empty( $profile['id'] ) ) { $this->redirect( 'error', __( 'Supabase could not verify the signed-in identity.', 'publishrank' ) ); }
		$provider = sanitize_key( (string) ( $profile['app_metadata']['provider'] ?? $data['provider'] ?? '' ) );
		$bundle = array( 'access_token' => $access, 'refresh_token' => (string) $response['refresh_token'], 'expires_at' => time() + max( 60, (int) ( $response['expires_in'] ?? 3600 ) ), 'subject' => sanitize_text_field( (string) $profile['id'] ), 'email' => sanitize_email( (string) ( $profile['email'] ?? '' ) ), 'provider' => $provider, 'verified_until' => time() + 300 );
		$encrypted = self::encrypt( wp_json_encode( $bundle ) );
		if ( '' === $encrypted ) { $this->redirect( 'error', __( 'Encrypted session storage is unavailable. Enable OpenSSL and valid WordPress salts.', 'publishrank' ) ); }
		update_user_meta( $user_id, self::SESSION_META, $encrypted ); update_user_meta( $user_id, self::SUBJECT_META, $bundle['subject'] );
		$this->redirect( 'success', __( 'Supabase identity linked to this existing WordPress administrator. No WordPress account or role was created or changed.', 'publishrank' ) );
	}

	/** Gate only PublishRank admin routes. WordPress, the frontend and recovery screens remain available. */
	public function gate_plugin_admin() {
		if ( ! is_admin() || is_network_admin() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! self::enabled() ) { return; }
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_plugin = 'publishrank' === $page || 0 === strpos( $page, 'publishrank-' ) || 0 === strpos( $page, 'publishrank_' );
		if ( ! $is_plugin || in_array( $page, array( 'publishrank-auth', 'publishrank-onboarding' ), true ) ) { return; }
		if ( ! $this->verify_session( get_current_user_id() ) ) { wp_safe_redirect( admin_url( 'admin.php?page=publishrank-auth&reauth=1' ) ); exit; }
	}

	public function unlink() {
		$this->guard( 'publishrank_supabase_unlink' );
		$user_id = get_current_user_id(); $bundle = self::session_bundle( $user_id );
		if ( is_array( $bundle ) && ! empty( $bundle['access_token'] ) && self::configured() ) { $this->api( '/auth/v1/logout', 'POST', null, (string) $bundle['access_token'] ); }
		delete_user_meta( $user_id, self::SESSION_META ); delete_user_meta( $user_id, self::SUBJECT_META ); delete_user_meta( $user_id, 'publishrank_supabase_verified_at' );
		$this->redirect( 'success', __( 'This administrator’s Supabase session was removed. WordPress access and roles are unchanged.', 'publishrank' ) );
	}

	public function emergency_bypass() {
		$this->guard( 'publishrank_supabase_emergency_bypass' );
		update_option( 'publishrank_supabase_emergency_bypass', 1, false );
		$this->redirect( 'success', __( 'Emergency local WordPress-admin access is enabled; Supabase is no longer required for PublishRank screens until re-enabled in configuration.', 'publishrank' ) );
	}

	public static function session_info( $user_id = 0 ) { $bundle = self::session_bundle( $user_id ? (int) $user_id : get_current_user_id() ); return is_array( $bundle ) ? array( 'email' => (string) ( $bundle['email'] ?? '' ), 'provider' => (string) ( $bundle['provider'] ?? '' ), 'subject' => (string) ( $bundle['subject'] ?? '' ) ) : array(); }

	private function verify_session( $user_id ) {
		$subject = self::subject( $user_id ); $bundle = self::session_bundle( $user_id );
		if ( '' === $subject || ! is_array( $bundle ) || empty( $bundle['refresh_token'] ) || ! hash_equals( $subject, (string) ( $bundle['subject'] ?? '' ) ) ) { return false; }
		if ( (int) ( $bundle['expires_at'] ?? 0 ) <= time() + 90 ) {
			$response = $this->api( '/auth/v1/token?grant_type=refresh_token', 'POST', array( 'refresh_token' => (string) $bundle['refresh_token'] ) );
			if ( is_wp_error( $response ) || empty( $response['access_token'] ) ) { return false; }
			$bundle['access_token'] = (string) $response['access_token']; if ( ! empty( $response['refresh_token'] ) ) { $bundle['refresh_token'] = (string) $response['refresh_token']; }
			$bundle['expires_at'] = time() + max( 60, (int) ( $response['expires_in'] ?? 3600 ) ); $bundle['verified_until'] = 0;
		}
		if ( (int) ( $bundle['verified_until'] ?? 0 ) <= time() ) {
			$profile = $this->api( '/auth/v1/user', 'GET', null, (string) $bundle['access_token'] );
			if ( is_wp_error( $profile ) || empty( $profile['id'] ) || ! hash_equals( $subject, (string) $profile['id'] ) ) { return false; }
			$bundle['verified_until'] = time() + 300;
		}
		$encrypted = self::encrypt( wp_json_encode( $bundle ) ); if ( '' === $encrypted ) { return false; }
		update_user_meta( $user_id, self::SESSION_META, $encrypted ); update_user_meta( $user_id, 'publishrank_supabase_verified_at', time() );
		return true;
	}

	private static function session_bundle( $user_id ) { $value = self::decrypt( (string) get_user_meta( (int) $user_id, self::SESSION_META, true ) ); $bundle = '' !== $value ? json_decode( $value, true ) : false; return is_array( $bundle ) ? $bundle : false; }

	private function api( $path, $method, $body = null, $access_token = '' ) {
		if ( ! self::configured() ) { return new \WP_Error( 'publishrank_supabase_config', __( 'Supabase is not configured.', 'publishrank' ) ); }
		$args = array( 'method' => strtoupper( (string) $method ), 'timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'apikey' => self::publishable_key(), 'Accept' => 'application/json' ) );
		if ( '' !== $access_token ) { $args['headers']['Authorization'] = 'Bearer ' . $access_token; }
		if ( null !== $body ) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode( $body ); }
		$response = wp_remote_request( self::project_url() . $path, $args );
		if ( is_wp_error( $response ) ) { return new \WP_Error( 'publishrank_supabase_network', __( 'Could not reach Supabase over HTTPS.', 'publishrank' ) ); }
		$code = (int) wp_remote_retrieve_response_code( $response ); $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) { return new \WP_Error( 'publishrank_supabase_api', __( 'Supabase returned an authorization error. Provider response details were not stored.', 'publishrank' ) ); }
		return $decoded;
	}

	private static function valid_project_url( $url ) { $parts = wp_parse_url( (string) $url ); return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( $parts['scheme'] ) && empty( $parts['user'] ) && empty( $parts['pass'] ) && false === strpos( (string) $parts['host'], '/' ); }
	private static function base64url( $data ) { return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); }
	private static function encrypt( $plain ) { if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) || ! function_exists( 'wp_salt' ) || '' === (string) wp_salt( 'auth' ) ) { return ''; } try { $iv = random_bytes( 12 ); } catch ( \Exception $e ) { return ''; } $key = hash_hmac( 'sha256', 'publishrank-supabase-session-v1', wp_salt( 'auth' ), true ); $tag = ''; $cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'publishrank-auth-v1', 16 ); return false === $cipher ? '' : base64_encode( $iv . $tag . $cipher ); }
	private static function decrypt( $encoded ) { if ( '' === $encoded || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'wp_salt' ) ) { return ''; } $raw = base64_decode( $encoded, true ); if ( false === $raw || strlen( $raw ) < 29 ) { return ''; } $iv = substr( $raw, 0, 12 ); $tag = substr( $raw, 12, 16 ); $cipher = substr( $raw, 28 ); $key = hash_hmac( 'sha256', 'publishrank-supabase-session-v1', wp_salt( 'auth' ), true ); $plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'publishrank-auth-v1' ); return false === $plain ? '' : $plain; }
	private function guard( $action ) { if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Only a WordPress administrator can manage this connection.', 'publishrank' ), '', array( 'response' => 403 ) ); } check_admin_referer( $action ); }
	private function redirect( $type, $message ) { set_transient( 'publishrank_supabase_notice_' . get_current_user_id(), array( 'type' => 'error' === $type ? 'error' : 'success', 'message' => (string) $message ), 120 ); wp_safe_redirect( admin_url( 'admin.php?page=publishrank-auth' ) ); exit; }
}
