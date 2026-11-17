<?php
/**
 * Brute-force and abuse protection for every credential path.
 *
 * Failures are counted in an indexed table, keyed by IP, by username and by IP+username. IP and
 * IP+username keys lock out progressively (ladder). A username attacked from many IPs is never locked
 * (that would lock out its real owner); instead a site-wide challenge is raised. When the table is
 * missing, logins keep working (fail open) and a warning is logged.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Guard {
	const CHALLENGE    = 'publishrank_shield_challenge';
	const DUMMY_HASH   = 'publishrank_shield_dummy_hash';
	const TRUSTED_OPT  = 'publishrank_shield_trusted_admin_ips';
	const GENERIC_CODE = 'publishrank_invalid_login';

	/** @var bool */
	private static $warned = false;

	public static function register() {
		add_filter( 'authenticate', array( __CLASS__, 'late_gate' ), 9999, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_failed' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'on_success' ), 10, 2 );
		add_action( 'login_init', array( __CLASS__, 'gate_login_screen' ), 1 );
		add_filter( 'login_errors', array( __CLASS__, 'uniform_message' ), 99 );
		add_action( 'login_form', array( __CLASS__, 'print_form_fields' ) );
		add_filter( 'login_form_middle', array( __CLASS__, 'form_fields_markup' ) );
		add_action( 'login_form_lostpassword', array( __CLASS__, 'uniform_lost_password' ), 1 );
		add_action( 'lostpassword_form', array( __CLASS__, 'print_form_fields' ) );
		add_action( 'lostpassword_post', array( __CLASS__, 'check_lost_password' ), 10, 2 );
		add_action( 'register_form', array( __CLASS__, 'print_form_fields' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'check_registration' ), 10, 3 );

		// XML-RPC.
		$mode = Settings::get( 'xmlrpc_mode' );
		if ( 'off' === $mode ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array', 999 );
			add_action( 'init', array( __CLASS__, 'kill_xmlrpc' ), 0 );
			add_filter( 'wp_headers', array( __CLASS__, 'remove_pingback_header' ) );
			add_filter( 'pings_open', '__return_false', 99 );
		} elseif ( 'no_auth' === $mode ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}
		if ( in_array( $mode, array( 'no_multicall', 'no_auth' ), true ) ) {
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'drop_multicall' ), 999 );
			add_filter( 'wp_xmlrpc_server_class', array( __CLASS__, 'xmlrpc_class' ), 99 );
		}

		// Application passwords.
		$app = Settings::get( 'app_passwords' );
		if ( 'off' === $app ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		} elseif ( 'admins' === $app ) {
			add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'app_passwords_for_admins' ), 10, 2 );
		}
		add_action( 'application_password_failed_authentication', array( __CLASS__, 'on_app_password_failed' ) );
		add_action( 'wp_authenticate_application_password_errors', array( __CLASS__, 'app_password_lock' ), 10, 4 );

		// Enumeration.
		if ( Settings::get( 'block_author_enum' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'block_author_query' ), 1 );
			add_filter( 'comment_class', array( __CLASS__, 'strip_comment_author_class' ), 99 );
		}
		if ( Settings::get( 'block_rest_users' ) ) {
			add_filter( 'rest_endpoints', array( __CLASS__, 'hide_rest_users' ), 99 );
		}
		if ( Settings::get( 'hide_oembed_author' ) ) {
			add_filter( 'oembed_response_data', array( __CLASS__, 'strip_oembed_author' ), 99 );
		}

		// Sessions.
		if ( (int) Settings::get( 'session_hours' ) > 0 || (int) Settings::get( 'session_remember_days' ) > 0 ) {
			add_filter( 'auth_cookie_expiration', array( __CLASS__, 'session_length' ), 99, 3 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Keys and storage
	 * ------------------------------------------------------------------- */

	public static function key_hash( $kind, $value ) {
		return hash_hmac( 'sha256', $kind . '|' . strtolower( (string) $value ), wp_salt( 'auth' ) );
	}

	public static function ready() {
		if ( Database::ready( 'shield_attempts' ) ) {
			return true;
		}
		if ( ! self::$warned ) {
			self::$warned = true;
			// Availability first: never block logins because our table is missing.
			error_log( 'PublishRank Access Shield: attempts table missing; brute-force throttling is temporarily disabled (fail open).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			SecurityLog::add( 'warning', 'warn', array( 'detail' => 'Attempts table missing; throttling failed open.' ) );
		}
		return false;
	}

	/** Whether throttling applies to this client at all. */
	public static function exempt( $ip = null ) {
		$ip = null === $ip ? Client::ip() : (string) $ip;
		if ( '' === $ip ) {
			return true;
		}
		$lists = array_merge( Settings::networks( 'allow_ips' ), Settings::networks( 'never_block_ips' ) );
		return $lists && \PublishRankSEO\Security\TrafficGuard::ip_matches_list( $ip, $lists );
	}

	/**
	 * An IP an administrator signed in from during the last 7 days. It is never locked as a whole
	 * (so a shared office or NAT address cannot lock the owner out), but IP + username locks still apply.
	 */
	public static function trusted_admin( $ip ) {
		$list = self::trusted_admin_ips();
		return $list && '' !== (string) $ip && \PublishRankSEO\Security\TrafficGuard::ip_matches_list( (string) $ip, $list );
	}

	/** IPs that recently signed in as administrators (kept 7 days). @return string[] */
	public static function trusted_admin_ips() {
		$list = get_option( self::TRUSTED_OPT, array() );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $ip => $until ) {
			if ( (int) $until > time() ) {
				$out[] = (string) $ip;
			}
		}
		return $out;
	}

	/** Seconds remaining on the longest active lock for an IP / IP+username, or 0. */
	public static function locked_for( $ip, $username = '' ) {
		global $wpdb;
		if ( ! Settings::get( 'throttle_enabled' ) || self::exempt( $ip ) || ! self::ready() ) {
			return 0;
		}
		$t     = Database::table( 'shield_attempts' );
		$until = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(lock_until) FROM {$t} WHERE lock_until > %s AND ((kind = 'ip' AND key_hash = %s) OR (kind = 'ipuser' AND key_hash = %s))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Database::now(),
				self::trusted_admin( $ip ) ? 'trusted-admin-ip' : self::key_hash( 'ip', $ip ),
				self::key_hash( 'ipuser', $ip . '|' . $username )
			)
		);
		return $until ? max( 0, strtotime( $until . ' UTC' ) - time() ) : 0;
	}

	/** Failures in the current window for an IP (used by "CAPTCHA after N failures"). */
	public static function ip_failures( $ip ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$t = Database::table( 'shield_attempts' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT failures FROM {$t} WHERE kind = 'ip' AND key_hash = %s AND first_at >= %s", self::key_hash( 'ip', $ip ), gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get( 'window_minutes' ) * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Count one failure for every key and lock keys that crossed their threshold.
	 *
	 * @return int Seconds of the lock that applies now (0 = not locked).
	 */
	public static function record_failure( $ip, $username, $event = 'login_failed' ) {
		global $wpdb;
		$username = strtolower( substr( sanitize_user( (string) $username, false ), 0, 60 ) );
		SecurityLog::add( $event, 'notice', array( 'ip' => $ip, 'username' => $username ) );
		if ( ! Settings::get( 'throttle_enabled' ) || '' === (string) $ip || self::exempt( $ip ) || ! self::ready() ) {
			return 0;
		}
		$t      = Database::table( 'shield_attempts' );
		$now    = Database::now();
		$window = gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get( 'window_minutes' ) * MINUTE_IN_SECONDS );
		$uhash  = '' !== $username ? self::key_hash( 'user', $username ) : '';
		$keys   = array( 'ip' => $ip );
		if ( '' !== $username ) {
			$keys['ipuser'] = $ip . '|' . $username;
			$keys['user']   = $username;
		}
		foreach ( $keys as $kind => $value ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT INTO {$t} (kind, key_hash, ip, user_hash, username, failures, first_at, last_at) VALUES (%s, %s, %s, %s, %s, 1, %s, %s)
					ON DUPLICATE KEY UPDATE failures = IF(first_at < %s, 1, failures + 1), first_at = IF(first_at < %s, VALUES(last_at), first_at), last_at = VALUES(last_at), ip = VALUES(ip), username = VALUES(username)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$kind,
					self::key_hash( $kind, $value ),
					'user' === $kind ? '' : $ip,
					$uhash,
					$username,
					$now,
					$now,
					$window,
					$window
				)
			);
		}
		$max  = (int) Settings::get( 'max_attempts' );
		if ( self::challenge_active() && 'none' === Settings::get( 'captcha_provider' ) ) {
			$max = max( 2, (int) floor( $max / 2 ) ); // Without a CAPTCHA, a flood tightens the per-IP limit.
		}
		$limits = array( 'ip' => $max * 3, 'ipuser' => $max );
		$lock   = 0;
		$trusted = self::trusted_admin( $ip );
		foreach ( array( 'ip', 'ipuser' ) as $kind ) {
			if ( ! isset( $keys[ $kind ] ) || ( 'ip' === $kind && $trusted ) ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, failures, lock_until, lock_level, last_at FROM {$t} WHERE kind = %s AND key_hash = %s", $kind, self::key_hash( $kind, $keys[ $kind ] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! $row || (int) $row['failures'] < $limits[ $kind ] ) {
				continue;
			}
			if ( $row['lock_until'] && strtotime( $row['lock_until'] . ' UTC' ) > time() ) {
				$lock = max( $lock, strtotime( $row['lock_until'] . ' UTC' ) - time() );
				continue;
			}
			$ladder = Settings::ladder();
			$level  = (int) $row['lock_level'];
			// Levels decay after a quiet day.
			if ( $row['lock_until'] && strtotime( $row['lock_until'] . ' UTC' ) < time() - DAY_IN_SECONDS ) {
				$level = 0;
			}
			$level   = min( count( $ladder ), $level + 1 );
			$minutes = $ladder[ $level - 1 ];
			$until   = gmdate( 'Y-m-d H:i:s', time() + $minutes * MINUTE_IN_SECONDS );
			$wpdb->update( $t, array( 'lock_until' => $until, 'lock_level' => $level, 'failures' => 0 ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			SecurityLog::add( 'lockout', 'warn', array( 'ip' => $ip, 'username' => $username, 'detail' => sprintf( '%s locked for %d min (level %d).', $kind, $minutes, $level ) ) );
			$lock = max( $lock, $minutes * MINUTE_IN_SECONDS );
		}
		if ( '' !== $uhash ) {
			self::maybe_flood( $uhash, $username, $window );
		}
		return $lock;
	}

	/** Raise a site-wide challenge when one username is attacked from many IPs. */
	private static function maybe_flood( $uhash, $username, $window ) {
		global $wpdb;
		if ( self::challenge_active() ) {
			return;
		}
		$t   = Database::table( 'shield_attempts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT failures FROM {$t} WHERE kind = 'user' AND key_hash = %s AND first_at >= %s", $uhash, $window ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $row || (int) $row['failures'] < (int) Settings::get( 'user_flood' ) ) {
			return;
		}
		$ips = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ip) FROM {$t} WHERE kind = 'ipuser' AND user_hash = %s AND last_at >= %s", $uhash, $window ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $ips < (int) Settings::get( 'flood_ips' ) ) {
			return;
		}
		set_transient( self::CHALLENGE, array( 'since' => time(), 'username' => $username, 'ips' => $ips ), HOUR_IN_SECONDS );
		SecurityLog::add( 'flood_challenge', 'alert', array( 'username' => $username, 'detail' => sprintf( 'Username targeted from %d IPs; site-wide challenge raised for 1 hour.', $ips ) ) );
	}

	public static function challenge_active() {
		return false !== get_transient( self::CHALLENGE );
	}

	/** Remove locks and counters for an IP or username. @return int rows affected */
	public static function unlock( $what ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		$t    = Database::table( 'shield_attempts' );
		$what = trim( (string) $what );
		if ( filter_var( $what, FILTER_VALIDATE_IP ) ) {
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE ip = %s", $what ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		} else {
			$user = strtolower( sanitize_user( $what, false ) );
			$n    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE username = %s OR user_hash = %s", $user, self::key_hash( 'user', $user ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		SecurityLog::add( 'unlock', 'notice', array( 'detail' => 'Unlocked ' . $what . ' (' . $n . ' rows).' ) );
		return $n;
	}

	/** Active locks for the admin screen. @return array<int,array<string,mixed>> */
	public static function active_locks( $limit = 200 ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$t = Database::table( 'shield_attempts' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT kind, ip, username, lock_until, lock_level, last_at FROM {$t} WHERE lock_until > %s ORDER BY lock_until DESC LIMIT %d", Database::now(), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/* ---------------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------------- */

	/** Whether this is a submission of an interactive WordPress form (not XML-RPC / REST). */
	public static function is_form_post() {
		return 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' )
			&& ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];
	}

	/** Deny-listed IPs and countries never see the login screen. */
	public static function gate_login_screen() {
		if ( self::denied() ) {
			SecurityLog::add( 'denied_ip', 'notice' );
			wp_die( esc_html__( 'Access to this page is not allowed from your network.', 'publishrank' ), esc_html__( 'Forbidden', 'publishrank' ), array( 'response' => 403 ) );
		}
	}

	public static function denied() {
		$ip = Client::ip();
		if ( '' === $ip || self::exempt( $ip ) ) {
			return false;
		}
		if ( Client::in( Settings::networks( 'deny_ips' ) ) ) {
			return true;
		}
		$country = Client::country();
		if ( '' !== $country ) {
			$deny  = Settings::countries( 'deny_countries' );
			$allow = Settings::countries( 'allow_countries' );
			if ( ( $deny && in_array( $country, $deny, true ) ) || ( $allow && ! in_array( $country, $allow, true ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Runs after every core authenticator. Enforces lockouts, denials, honeypot, CAPTCHA and uniform errors.
	 *
	 * @param \WP_User|\WP_Error|null $user
	 */
	public static function late_gate( $user, $username, $password ) {
		$username = is_string( $username ) ? $username : '';
		if ( '' === $username && '' === (string) $password ) {
			return $user; // Cookie checks and empty submissions.
		}
		$ip = Client::ip();
		if ( self::denied() ) {
			SecurityLog::add( 'denied_ip', 'notice', array( 'username' => $username ) );
			return new \WP_Error( 'publishrank_denied', __( 'Sign-in is not allowed from your network.', 'publishrank' ) );
		}
		$locked = self::locked_for( $ip, strtolower( sanitize_user( $username, false ) ) );
		if ( $locked > 0 ) {
			SecurityLog::add( 'locked_attempt', 'notice', array( 'username' => $username ) );
			return self::locked_error( $locked );
		}
		if ( self::is_form_post() ) {
			$bot = self::form_check();
			if ( is_wp_error( $bot ) ) {
				return $bot;
			}
			if ( Captcha::required( $ip ) && in_array( 'login', (array) Settings::get( 'captcha_forms' ), true ) ) {
				$ok = Captcha::verify();
				if ( true !== $ok ) {
					SecurityLog::add( 'captcha_failed', 'notice', array( 'username' => $username ) );
					return new \WP_Error( 'publishrank_captcha', __( 'Please complete the security check.', 'publishrank' ) );
				}
			}
		}
		if ( is_wp_error( $user ) ) {
			$codes = $user->get_error_codes();
			if ( Settings::get( 'equalize_timing' ) && array_intersect( $codes, array( 'invalid_username', 'invalid_email' ) ) && '' !== (string) $password ) {
				wp_check_password( (string) $password, self::dummy_hash() ); // Same cost as checking a real password.
			}
			if ( Settings::get( 'uniform_errors' ) && array_intersect( $codes, array( 'invalid_username', 'invalid_email', 'incorrect_password', 'spammer_account' ) ) ) {
				return new \WP_Error( self::GENERIC_CODE, self::generic_message() );
			}
		}
		return $user;
	}

	public static function locked_error( $seconds ) {
		$minutes = max( 1, (int) ceil( $seconds / 60 ) );
		/* translators: %d: minutes */
		return new \WP_Error( 'publishrank_locked', sprintf( _n( 'Too many failed sign-in attempts. Try again in %d minute.', 'Too many failed sign-in attempts. Try again in %d minutes.', $minutes, 'publishrank' ), $minutes ) );
	}

	public static function generic_message() {
		return sprintf(
			/* translators: %s: lost password URL */
			__( '<strong>Error:</strong> The sign-in details are not correct. <a href="%s">Lost your password?</a>', 'publishrank' ),
			esc_url( wp_lostpassword_url() )
		);
	}

	/** Make every credential error read the same. */
	public static function uniform_message( $message ) {
		if ( ! Settings::get( 'uniform_errors' ) || ! is_string( $message ) ) {
			return $message;
		}
		global $errors;
		if ( $errors instanceof \WP_Error && array_intersect( $errors->get_error_codes(), array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
			return self::generic_message() . "<br />\n";
		}
		return $message;
	}

	/** A bcrypt/phpass hash of random data, created once, used to equalise timing. */
	public static function dummy_hash() {
		$hash = (string) get_option( self::DUMMY_HASH, '' );
		if ( '' === $hash ) {
			$hash = wp_hash_password( wp_generate_password( 32, true, true ) );
			update_option( self::DUMMY_HASH, $hash, false );
		}
		return $hash;
	}

	public static function on_failed( $username, $error = null ) {
		if ( $error instanceof \WP_Error && array_intersect( $error->get_error_codes(), array( 'publishrank_locked' ) ) ) {
			return; // Do not extend a lock with attempts made while locked.
		}
		$event = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ? 'login_failed' : 'login_failed';
		self::record_failure( Client::ip(), (string) $username, $event );
	}

	public static function on_success( $login, $user ) {
		global $wpdb;
		$ip = Client::ip();
		if ( self::ready() && '' !== $ip ) {
			$t = Database::table( 'shield_attempts' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE (kind = 'ipuser' AND key_hash = %s)", self::key_hash( 'ipuser', $ip . '|' . strtolower( (string) $login ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		if ( $user instanceof \WP_User && \PublishRankSEO\Core\Capabilities::is_admin_user( $user ) && '' !== $ip ) {
			$list = get_option( self::TRUSTED_OPT, array() );
			$list = is_array( $list ) ? $list : array();
			foreach ( $list as $k => $until ) {
				if ( (int) $until < time() ) {
					unset( $list[ $k ] );
				}
			}
			$list[ $ip ] = time() + 7 * DAY_IN_SECONDS;
			arsort( $list );
			update_option( self::TRUSTED_OPT, array_slice( $list, 0, 20, true ), false );
		}
		SecurityLog::add( 'login_success', 'info', array( 'username' => (string) $login, 'user_id' => $user instanceof \WP_User ? $user->ID : 0 ) );
	}

	/** Hidden honeypot and a signed timestamp. */
	public static function form_fields_markup() {
		$ts  = (string) time();
		$sig = substr( hash_hmac( 'sha256', $ts, wp_salt( 'nonce' ) ), 0, 20 );
		$out = '';
		if ( Settings::get( 'honeypot' ) ) {
			$out .= '<p class="publishrank-hp" aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto;width:1px;height:1px;overflow:hidden"><label>' . esc_html__( 'Leave this field empty', 'publishrank' ) . '<input type="text" name="pr_website" value="" tabindex="-1" autocomplete="off" /></label></p>';
		}
		$out .= '<input type="hidden" name="pr_ts" value="' . esc_attr( $ts . '.' . $sig ) . '" />';
		$out .= Captcha::markup();
		return $out;
	}

	public static function print_form_fields() {
		echo self::form_fields_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
	}

	/** @return true|\WP_Error */
	public static function form_check() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- login forms carry no nonce; these are anti-bot fields.
		if ( Settings::get( 'honeypot' ) && ! empty( $_POST['pr_website'] ) ) {
			SecurityLog::add( 'honeypot', 'notice', array( 'detail' => 'Honeypot field filled.' ) );
			return new \WP_Error( self::GENERIC_CODE, self::generic_message() );
		}
		$min = (int) Settings::get( 'min_submit_seconds' );
		if ( $min > 0 && ! isset( $_POST['pr_ts'] ) && ! self::same_site_form() ) {
			// Our login, lost-password and registration forms (and wp_login_form()) always carry the
			// signed timestamp. A post without it that also did not come from a page on this site is a script.
			SecurityLog::add( 'honeypot', 'notice', array( 'detail' => 'Form posted without the signed timestamp.' ) );
			return new \WP_Error( self::GENERIC_CODE, self::generic_message() );
		}
		if ( $min > 0 && isset( $_POST['pr_ts'] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['pr_ts'] ) );
			$p   = explode( '.', $raw, 2 );
			$ok  = 2 === count( $p ) && ctype_digit( $p[0] ) && hash_equals( substr( hash_hmac( 'sha256', $p[0], wp_salt( 'nonce' ) ), 0, 20 ), $p[1] );
			if ( ! $ok || time() - (int) $p[0] < $min ) {
				SecurityLog::add( 'honeypot', 'notice', array( 'detail' => $ok ? 'Form submitted too quickly.' : 'Form timestamp invalid.' ) );
				return new \WP_Error( self::GENERIC_CODE, self::generic_message() );
			}
		}
		// phpcs:enable
		return true;
	}

	/** A custom sign-in form on one of this site's own pages (themes that hand-write the form). */
	private static function same_site_form() {
		$ref = wp_get_raw_referer();
		if ( ! $ref ) {
			return false;
		}
		$host = wp_parse_url( $ref, PHP_URL_HOST );
		$path = (string) wp_parse_url( $ref, PHP_URL_PATH );
		$slug = LoginAddress::slug();
		return $host && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
			&& false === stripos( $path, 'wp-login' ) && ( '' === $slug || false === stripos( $path, '/' . $slug ) );
	}

	/** Lost-password requests always read the same, whether or not the account exists. */
	public static function uniform_lost_password() {
		if ( ! Settings::get( 'uniform_errors' ) || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return;
		}
		$login = isset( $_POST['user_login'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $login ) {
			return;
		}
		$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
		if ( ! $user ) {
			SecurityLog::add( 'enumeration', 'notice', array( 'username' => $login, 'detail' => 'Lost-password request for an unknown account answered generically.' ) );
			$redirect = ! empty( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( $redirect ? $redirect : add_query_arg( 'checkemail', 'confirm', wp_login_url() ) );
			exit;
		}
	}

	/** @param \WP_Error $errors */
	public static function check_lost_password( $errors, $user_data = null ) {
		if ( ! self::is_form_post() || ! $errors instanceof \WP_Error ) {
			return;
		}
		$bot = self::form_check();
		if ( is_wp_error( $bot ) ) {
			$errors->add( $bot->get_error_code(), $bot->get_error_message() );
			return;
		}
		if ( Captcha::required( Client::ip() ) && in_array( 'lostpassword', (array) Settings::get( 'captcha_forms' ), true ) && true !== Captcha::verify() ) {
			SecurityLog::add( 'captcha_failed', 'notice' );
			$errors->add( 'publishrank_captcha', __( 'Please complete the security check.', 'publishrank' ) );
		}
	}

	public static function check_registration( $errors, $login, $email ) {
		if ( ! self::is_form_post() || ! $errors instanceof \WP_Error ) {
			return $errors;
		}
		if ( self::denied() ) {
			$errors->add( 'publishrank_denied', __( 'Registration is not allowed from your network.', 'publishrank' ) );
			return $errors;
		}
		$bot = self::form_check();
		if ( is_wp_error( $bot ) ) {
			$errors->add( 'publishrank_bot', __( 'Please try again.', 'publishrank' ) );
			return $errors;
		}
		if ( Captcha::required( Client::ip() ) && in_array( 'register', (array) Settings::get( 'captcha_forms' ), true ) && true !== Captcha::verify() ) {
			$errors->add( 'publishrank_captcha', __( 'Please complete the security check.', 'publishrank' ) );
		}
		return $errors;
	}

	public static function kill_xmlrpc() {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			SecurityLog::add( 'xmlrpc_blocked', 'info' );
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'XML-RPC is disabled on this site.';
			exit;
		}
	}

	public static function xmlrpc_class( $class ) {
		if ( 'wp_xmlrpc_server' !== $class ) {
			return $class; // Another plugin replaced the server; leave it alone.
		}
		require_once __DIR__ . '/XmlRpcServer.php';
		return class_exists( __NAMESPACE__ . '\\XmlRpcServer', false ) ? __NAMESPACE__ . '\\XmlRpcServer' : $class;
	}

	public static function drop_multicall( $methods ) {
		if ( is_array( $methods ) ) {
			unset( $methods['system.multicall'] );
		}
		return $methods;
	}

	public static function remove_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public static function app_passwords_for_admins( $available, $user ) {
		return $available && $user instanceof \WP_User && \PublishRankSEO\Core\Capabilities::is_admin_user( $user );
	}

	public static function on_app_password_failed( $error ) {
		$user = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_user( wp_unslash( $_SERVER['PHP_AUTH_USER'] ), false ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::record_failure( Client::ip(), $user, 'app_password_failed' );
	}

	/** Locked clients cannot authenticate with application passwords either. */
	public static function app_password_lock( $error, $user, $item, $password ) {
		if ( ! $error instanceof \WP_Error ) {
			return;
		}
		$name   = $user instanceof \WP_User ? $user->user_login : '';
		$locked = self::locked_for( Client::ip(), strtolower( $name ) );
		if ( $locked > 0 || self::denied() ) {
			$error->add( 'publishrank_locked', self::locked_error( max( 60, $locked ) )->get_error_message() );
		}
	}

	/** ?author=N must not reveal usernames to signed-out visitors. */
	public static function block_author_query() {
		if ( is_user_logged_in() || is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public query.
		if ( isset( $_GET['author'] ) || ( isset( $_GET['author_name'] ) && ! get_option( 'permalink_structure' ) ) ) {
			SecurityLog::add( 'enumeration', 'notice', array( 'detail' => 'Author query blocked.' ) );
			LoginAddress::render_404();
		}
	}

	public static function strip_comment_author_class( $classes ) {
		return array_values(
			array_filter(
				(array) $classes,
				static function ( $c ) {
					return 0 !== strpos( (string) $c, 'comment-author-' );
				}
			)
		);
	}

	public static function hide_rest_users( $endpoints ) {
		if ( is_user_logged_in() || ! is_array( $endpoints ) ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	public static function strip_oembed_author( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}

	public static function session_length( $length, $user_id, $remember ) {
		$hours = (int) Settings::get( 'session_hours' );
		$days  = (int) Settings::get( 'session_remember_days' );
		if ( $remember && $days > 0 ) {
			return $days * DAY_IN_SECONDS;
		}
		if ( ! $remember && $hours > 0 ) {
			return $hours * HOUR_IN_SECONDS;
		}
		return $length;
	}
}
