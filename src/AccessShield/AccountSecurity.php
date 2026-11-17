<?php
/**
 * Account protection for privileged roles (2.25).
 *
 * - Strong-password policy: minimum length, character variety (or a long passphrase), no username / email / site name.
 * - Breached-password block via the Have I Been Pwned range API. k-anonymity: only the first 5 characters of the
 *   password's SHA-1 hash leave the site; the password and the full hash never do. Fails open on network errors.
 * - Concurrent session limit: on sign-in the oldest sessions beyond the limit are ended.
 * - Idle sign-out: a session with no real activity (Heartbeat does not count) for N minutes is ended.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

final class AccountSecurity {
	const HIBP = 'https://api.pwnedpasswords.com/range/';

	public static function register() {
		if ( Settings::get( 'pw_policy' ) || Settings::get( 'pw_breach_check' ) ) {
			add_action( 'user_profile_update_errors', array( __CLASS__, 'on_profile' ), 20, 3 );
			add_action( 'validate_password_reset', array( __CLASS__, 'on_reset' ), 20, 2 );
		}
		if ( (int) Settings::get( 'max_sessions' ) > 0 ) {
			add_action( 'wp_login', array( __CLASS__, 'limit_sessions' ), 99, 2 );
		}
		if ( (int) Settings::get( 'idle_minutes' ) > 0 ) {
			add_action( 'init', array( __CLASS__, 'idle_check' ), 2 );
			add_filter( 'login_message', array( __CLASS__, 'idle_message' ) );
		}
	}

	/** Whether the policy applies to a user (by role, or any administrator). */
	public static function privileged( $user, array $roles = array() ) {
		$roles = $roles ? $roles : ( $user instanceof \WP_User ? (array) $user->roles : array() );
		if ( array_intersect( $roles, (array) Settings::get( 'pw_roles' ) ) || in_array( 'administrator', $roles, true ) ) {
			return true;
		}
		return $user instanceof \WP_User && $user->ID && user_can( $user, 'manage_options' );
	}

	/**
	 * Problems with a password, empty when acceptable.
	 *
	 * @param bool $remote Allow the breach lookup.
	 * @return string[]
	 */
	public static function problems( $password, $user = null, $remote = true ) {
		$password = (string) $password;
		$out      = array();
		if ( Settings::get( 'pw_policy' ) ) {
			$min = (int) Settings::get( 'pw_min_length' );
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $password, 'UTF-8' ) : strlen( $password );
			if ( $len < $min ) {
				/* translators: %d: minimum length */
				$out[] = sprintf( __( 'Use at least %d characters.', 'publishrank' ), $min );
			}
			$classes = (int) preg_match( '/[a-z]/', $password ) + (int) preg_match( '/[A-Z]/', $password ) + (int) preg_match( '/\d/', $password ) + (int) preg_match( '/[^A-Za-z0-9]/', $password );
			if ( $len < 20 && $classes < 3 ) {
				$out[] = __( 'Mix at least three of: lowercase, uppercase, numbers, symbols — or use a passphrase of 20+ characters.', 'publishrank' );
			}
			$lower = strtolower( $password );
			$words = array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), strtolower( get_bloginfo( 'name' ) ) );
			if ( $user instanceof \WP_User ) {
				$words[] = strtolower( (string) $user->user_login );
				$words[] = strtolower( (string) strtok( (string) $user->user_email, '@' ) );
			}
			foreach ( $words as $w ) {
				if ( strlen( $w ) >= 4 && false !== strpos( $lower, $w ) ) {
					$out[] = __( 'Do not include your username, email name or the site name.', 'publishrank' );
					break;
				}
			}
		}
		if ( $remote && Settings::get( 'pw_breach_check' ) && '' !== $password ) {
			$hits = self::breach_count( $password );
			if ( $hits > 0 ) {
				/* translators: %s: number of breaches */
				$out[] = sprintf( __( 'This password appears in %s known data breaches. Choose a different one.', 'publishrank' ), number_format_i18n( $hits ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Times a password appears in breach corpora (0 = not found or lookup unavailable). */
	public static function breach_count( $password ) {
		$hash   = strtoupper( sha1( (string) $password ) );
		$prefix = substr( $hash, 0, 5 );
		$suffix = substr( $hash, 5 );
		$res    = wp_remote_get(
			self::HIBP . $prefix,
			array(
				'timeout'    => 4,
				'headers'    => array( 'Add-Padding' => 'true' ),
				'user-agent' => 'PublishRank-AccessShield',
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return 0; // Fail open: never block a password change because a third-party service is unreachable.
		}
		foreach ( preg_split( '/\r?\n/', (string) wp_remote_retrieve_body( $res ) ) as $line ) {
			$parts = explode( ':', trim( $line ) );
			if ( 2 === count( $parts ) && hash_equals( $suffix, strtoupper( $parts[0] ) ) ) {
				return (int) $parts[1];
			}
		}
		return 0;
	}

	/** Profile screen / new user screen. */
	public static function on_profile( $errors, $update, $user ) {
		if ( empty( $user->user_pass ) ) {
			return;
		}
		$roles = ! empty( $user->role ) ? array( $user->role ) : array();
		$full  = ! empty( $user->ID ) ? get_userdata( (int) $user->ID ) : null;
		if ( ! $roles && $full ) {
			$roles = (array) $full->roles;
		}
		if ( ! self::privileged( $full ? $full : null, $roles ) ) {
			return;
		}
		$probe = $full ? $full : new \WP_User();
		if ( ! $full ) {
			$probe->user_login = isset( $user->user_login ) ? $user->user_login : '';
			$probe->user_email = isset( $user->user_email ) ? $user->user_email : '';
		}
		foreach ( self::problems( $user->user_pass, $probe ) as $i => $msg ) {
			$errors->add( 'publishrank_password_' . $i, '<strong>' . esc_html__( 'Password', 'publishrank' ) . ':</strong> ' . esc_html( $msg ) );
		}
	}

	/** Lost-password reset form. */
	public static function on_reset( $errors, $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- core verified the reset key; the password is only measured.
		$pass = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
		if ( '' === $pass || ! $user instanceof \WP_User || ! self::privileged( $user ) ) {
			return;
		}
		foreach ( self::problems( $pass, $user ) as $i => $msg ) {
			$errors->add( 'publishrank_password_' . $i, esc_html( $msg ) );
		}
	}

	/** End the oldest sessions beyond the limit (default user-meta session storage). */
	public static function limit_sessions( $login, $user ) {
		if ( ! $user instanceof \WP_User || ! self::privileged( $user ) ) {
			return;
		}
		$max      = (int) Settings::get( 'max_sessions' );
		$sessions = get_user_meta( $user->ID, 'session_tokens', true );
		if ( $max < 1 || ! is_array( $sessions ) || count( $sessions ) <= $max ) {
			return;
		}
		uasort(
			$sessions,
			static function ( $a, $b ) {
				return (int) ( isset( $b['login'] ) ? $b['login'] : 0 ) <=> (int) ( isset( $a['login'] ) ? $a['login'] : 0 );
			}
		);
		$ended = count( $sessions ) - $max;
		update_user_meta( $user->ID, 'session_tokens', array_slice( $sessions, 0, $max, true ) );
		SecurityLog::add( 'settings', 'notice', array( 'username' => $user->user_login, 'detail' => sprintf( 'Session limit: ended %d older session(s).', $ended ) ) );
	}

	/** End a privileged session after N idle minutes (Heartbeat polling is not activity). */
	public static function idle_check() {
		if ( ! is_user_logged_in() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! self::privileged( $user ) ) {
			return;
		}
		$token = wp_get_session_token();
		if ( '' === $token ) {
			return;
		}
		$manager = \WP_Session_Tokens::get_instance( $user->ID );
		$session = $manager->get( $token );
		if ( ! is_array( $session ) ) {
			return;
		}
		$last = isset( $session['pr_active'] ) ? (int) $session['pr_active'] : (int) $session['login'];
		$idle = (int) Settings::get( 'idle_minutes' ) * MINUTE_IN_SECONDS;
		if ( time() - $last > $idle ) {
			$manager->destroy( $token );
			wp_clear_auth_cookie();
			SecurityLog::add( 'settings', 'info', array( 'username' => $user->user_login, 'detail' => 'Signed out after inactivity.' ) );
			if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			wp_safe_redirect( add_query_arg( 'publishrank_idle', '1', wp_login_url() ) );
			exit;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check of the AJAX action name.
		$heartbeat = wp_doing_ajax() && isset( $_POST['action'] ) && 'heartbeat' === $_POST['action'];
		if ( ! $heartbeat && time() - $last >= 60 ) {
			$session['pr_active'] = time();
			$manager->update( $token, $session );
		}
	}

	public static function idle_message( $message ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( isset( $_GET['publishrank_idle'] ) ) {
			$message .= '<p class="message">' . esc_html__( 'You were signed out after a period of inactivity.', 'publishrank' ) . '</p>';
		}
		return $message;
	}
}
