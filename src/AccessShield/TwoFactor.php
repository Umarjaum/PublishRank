<?php
/**
 * TOTP two-factor authentication (RFC 6238, 30-second steps, 6 digits, SHA-1) with one-time recovery
 * codes, per-role enforcement with a grace period, replay protection and attempt limits. Seeds are
 * encrypted at rest; recovery codes are stored as keyed hashes only.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class TwoFactor {
	const META_SECRET    = 'publishrank_2fa_secret';
	const META_PENDING   = 'publishrank_2fa_pending';
	const META_ENABLED   = 'publishrank_2fa_enabled';
	const META_CODES     = 'publishrank_2fa_recovery';
	const META_LAST      = 'publishrank_2fa_last_step';
	const META_CHALLENGE = 'publishrank_2fa_challenge';
	const META_SINCE     = 'publishrank_2fa_required_since';
	const ACTION         = 'publishrank_2fa';
	const CHALLENGE_TTL  = 300;
	const MAX_TRIES      = 5;

	public static function register() {
		if ( ! Settings::get( 'twofa_enabled' ) ) {
			return;
		}
		add_filter( 'authenticate', array( __CLASS__, 'intercept' ), 10000, 3 );
		add_action( 'login_form_' . self::ACTION, array( __CLASS__, 'login_screen' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile' ), 5 );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile' ), 5 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'admin_init', array( __CLASS__, 'enforce_enrollment' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'enrollment_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ---------------- TOTP primitives ---------------- */

	public static function base32_encode( $bin ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits     = '';
		foreach ( str_split( (string) $bin ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= $alphabet[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	public static function base32_decode( $b32 ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32      = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', (string) $b32 ) );
		$bits     = '';
		foreach ( str_split( $b32 ) as $c ) {
			$pos = strpos( $alphabet, $c );
			if ( false === $pos ) {
				return '';
			}
			$bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	/** HOTP value for a counter. */
	public static function hotp( $secret_b32, $counter ) {
		$key  = self::base32_decode( $secret_b32 );
		$bin  = pack( 'N*', 0 ) . pack( 'N*', (int) $counter );
		$hash = hash_hmac( 'sha1', $bin, $key, true );
		$off  = ord( substr( $hash, -1 ) ) & 0x0F;
		$num  = ( ( ord( $hash[ $off ] ) & 0x7F ) << 24 ) | ( ( ord( $hash[ $off + 1 ] ) & 0xFF ) << 16 ) | ( ( ord( $hash[ $off + 2 ] ) & 0xFF ) << 8 ) | ( ord( $hash[ $off + 3 ] ) & 0xFF );
		return str_pad( (string) ( $num % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	public static function now_step( $time = null ) {
		return (int) floor( ( null === $time ? time() : (int) $time ) / 30 );
	}

	/**
	 * Check a code against a secret within ±1 step, refusing steps at or before $last (replay).
	 *
	 * @return int|false Matched step.
	 */
	public static function check_code( $secret_b32, $code, $last = 0, $time = null ) {
		$code = preg_replace( '/\D/', '', (string) $code );
		if ( 6 !== strlen( $code ) || '' === $secret_b32 ) {
			return false;
		}
		$step = self::now_step( $time );
		for ( $i = -1; $i <= 1; $i++ ) {
			$s = $step + $i;
			if ( $s > (int) $last && hash_equals( self::hotp( $secret_b32, $s ), $code ) ) {
				return $s;
			}
		}
		return false;
	}

	public static function new_secret() {
		return self::base32_encode( random_bytes( 20 ) );
	}

	public static function otpauth_uri( $secret, \WP_User $user ) {
		$issuer = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$issuer = '' === trim( $issuer ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : $issuer;
		$label  = rawurlencode( $issuer . ':' . $user->user_login );
		return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode( $issuer ) . '&algorithm=SHA1&digits=6&period=30';
	}

	/* ---------------- Per-user state ---------------- */

	public static function is_enabled( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, self::META_ENABLED, true ) && '' !== Crypto::decrypt( (string) get_user_meta( (int) $user_id, self::META_SECRET, true ) );
	}

	public static function secret( $user_id ) {
		return Crypto::decrypt( (string) get_user_meta( (int) $user_id, self::META_SECRET, true ) );
	}

	public static function role_requires( \WP_User $user ) {
		$roles = (array) Settings::get( 'twofa_roles' );
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/** Enable with a verified secret; returns the plain recovery codes (shown once). @return string[] */
	public static function enable( $user_id, $secret ) {
		update_user_meta( (int) $user_id, self::META_SECRET, Crypto::encrypt( $secret ) );
		update_user_meta( (int) $user_id, self::META_ENABLED, '1' );
		delete_user_meta( (int) $user_id, self::META_PENDING );
		return self::new_recovery_codes( $user_id );
	}

	public static function disable( $user_id, $reason = 'user' ) {
		foreach ( array( self::META_SECRET, self::META_PENDING, self::META_ENABLED, self::META_CODES, self::META_LAST, self::META_CHALLENGE ) as $k ) {
			delete_user_meta( (int) $user_id, $k );
		}
		SecurityLog::add( 'twofa_reset', 'notice', array( 'user_id' => (int) $user_id, 'detail' => 'Two-factor turned off (' . sanitize_key( $reason ) . ').' ) );
	}

	/** @return string[] */
	public static function new_recovery_codes( $user_id ) {
		$plain  = array();
		$hashed = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$code     = strtolower( wp_generate_password( 4, false, false ) . '-' . wp_generate_password( 4, false, false ) . '-' . wp_generate_password( 4, false, false ) );
			$plain[]  = $code;
			$hashed[] = self::code_hash( $code );
		}
		update_user_meta( (int) $user_id, self::META_CODES, $hashed );
		return $plain;
	}

	private static function code_hash( $code ) {
		return hash_hmac( 'sha256', strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $code ) ), wp_salt( 'secure_auth' ) );
	}

	public static function recovery_left( $user_id ) {
		$codes = get_user_meta( (int) $user_id, self::META_CODES, true );
		return is_array( $codes ) ? count( $codes ) : 0;
	}

	/** Consume a recovery code. */
	public static function use_recovery( $user_id, $code ) {
		$codes = get_user_meta( (int) $user_id, self::META_CODES, true );
		if ( ! is_array( $codes ) ) {
			return false;
		}
		$h = self::code_hash( $code );
		foreach ( $codes as $i => $stored ) {
			if ( hash_equals( (string) $stored, $h ) ) {
				unset( $codes[ $i ] );
				update_user_meta( (int) $user_id, self::META_CODES, array_values( $codes ) );
				return true;
			}
		}
		return false;
	}

	/** Verify a TOTP code with replay protection. */
	public static function verify_totp( $user_id, $code ) {
		$step = self::check_code( self::secret( $user_id ), $code, (int) get_user_meta( (int) $user_id, self::META_LAST, true ) );
		if ( false === $step ) {
			return false;
		}
		update_user_meta( (int) $user_id, self::META_LAST, $step );
		return true;
	}

	/* ---------------- Login flow ---------------- */

	private static function is_api_request() {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || did_action( 'application_password_did_authenticate' );
	}

	/** After a correct password: send 2FA users to the code screen instead of signing them in. */
	public static function intercept( $user, $username, $password ) {
		if ( ! $user instanceof \WP_User || ! AccessShield::enabled() || ! self::is_enabled( $user->ID ) ) {
			return $user;
		}
		if ( self::is_api_request() ) {
			return Settings::get( 'twofa_app_password' ) ? $user : new \WP_Error( 'publishrank_2fa_api', __( 'Two-factor accounts must use the web sign-in.', 'publishrank' ) );
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			SecurityLog::add( 'xmlrpc_blocked', 'notice', array( 'user_id' => $user->ID, 'detail' => 'XML-RPC sign-in refused for a two-factor account.' ) );
			return new \WP_Error( 'publishrank_2fa_xmlrpc', __( 'Two-factor accounts cannot sign in over XML-RPC. Use an application password.', 'publishrank' ) );
		}
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return $user;
		}
		$token    = wp_generate_password( 40, false, false );
		$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ), admin_url() ) : admin_url(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		update_user_meta(
			$user->ID,
			self::META_CHALLENGE,
			array(
				'hash'     => wp_hash( $token ),
				'expires'  => time() + self::CHALLENGE_TTL,
				'remember' => ! empty( $_POST['rememberme'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'redirect' => $redirect,
				'tries'    => 0,
			)
		);
		$url = add_query_arg(
			array(
				'action' => self::ACTION,
				'u'      => (int) $user->ID,
				't'      => $token,
			),
			wp_login_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** The code screen (wp-login.php?action=publishrank_2fa). */
	public static function login_screen() {
		// phpcs:disable WordPress.Security.NonceVerification -- the one-time challenge token is the proof.
		$uid   = isset( $_REQUEST['u'] ) ? absint( $_REQUEST['u'] ) : 0;
		$token = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';
		$ch    = $uid ? get_user_meta( $uid, self::META_CHALLENGE, true ) : array();
		$valid = is_array( $ch ) && ! empty( $ch['hash'] ) && hash_equals( (string) $ch['hash'], wp_hash( $token ) ) && time() < (int) $ch['expires'];
		if ( ! $valid ) {
			wp_safe_redirect( add_query_arg( 'pr2fa', 'expired', wp_login_url() ) );
			exit;
		}
		$error = '';
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			$code     = isset( $_POST['pr_code'] ) ? sanitize_text_field( wp_unslash( $_POST['pr_code'] ) ) : '';
			$user     = get_userdata( $uid );
			$locked   = Guard::locked_for( Client::ip(), $user ? strtolower( $user->user_login ) : '' );
			$ok       = false;
			$recovery = false;
			if ( $locked <= 0 && $user ) {
				$ok = self::verify_totp( $uid, $code );
				if ( ! $ok && preg_match( '/[a-z]/i', $code ) ) {
					$ok       = self::use_recovery( $uid, $code );
					$recovery = $ok;
				}
			}
			if ( $ok ) {
				delete_user_meta( $uid, self::META_CHALLENGE );
				SecurityLog::add( $recovery ? 'twofa_recovery' : 'twofa_success', 'info', array( 'user_id' => $uid, 'username' => $user->user_login ) );
				wp_set_auth_cookie( $uid, ! empty( $ch['remember'] ) );
				wp_set_current_user( $uid );
				/** This action is documented in wp-includes/user.php */
				do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				wp_safe_redirect( ! empty( $ch['redirect'] ) ? $ch['redirect'] : admin_url() );
				exit;
			}
			$ch['tries'] = (int) $ch['tries'] + 1;
			if ( $user ) {
				Guard::record_failure( Client::ip(), $user->user_login, 'twofa_failed' );
			}
			if ( $ch['tries'] >= self::MAX_TRIES || $locked > 0 ) {
				delete_user_meta( $uid, self::META_CHALLENGE );
				wp_safe_redirect( add_query_arg( 'pr2fa', 'locked', wp_login_url() ) );
				exit;
			}
			update_user_meta( $uid, self::META_CHALLENGE, $ch );
			$error = __( 'That code is not valid. Check your authenticator app and try again.', 'publishrank' );
		}
		// phpcs:enable
		$errors = new \WP_Error();
		if ( '' !== $error ) {
			$errors->add( 'publishrank_2fa', $error );
		}
		login_header( __( 'Two-factor verification', 'publishrank' ), '<p class="message">' . esc_html__( 'Enter the 6-digit code from your authenticator app, or one of your recovery codes.', 'publishrank' ) . '</p>', $errors );
		echo '<form name="publishrank2fa" id="loginform" action="' . esc_url( add_query_arg( array( 'action' => self::ACTION, 'u' => $uid, 't' => $token ), wp_login_url() ) ) . '" method="post" autocomplete="off">';
		echo '<p><label for="pr_code">' . esc_html__( 'Authentication code', 'publishrank' ) . '</label><input type="text" name="pr_code" id="pr_code" class="input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9a-zA-Z\- ]*" maxlength="20" size="20" autofocus required /></p>';
		echo '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Verify', 'publishrank' ) . '" /></p></form>';
		login_footer( 'pr_code' );
		exit;
	}

	/* ---------------- Profile UI ---------------- */

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'publishrank-qrcode', \PublishRankSEO\Core\Branding::asset_url( 'vendor/qrcode-generator/qrcode.js' ), array(), '1.4.4', true );
		wp_add_inline_script( 'publishrank-qrcode', '(function(){var el=document.getElementById("publishrank-2fa-qr");if(!el||typeof qrcode!=="function"){return;}var q=qrcode(0,"M");q.addData(el.getAttribute("data-uri"));q.make();el.innerHTML=q.createSvgTag({cellSize:5,margin:2,scalable:true});var s=el.querySelector("svg");if(s){s.setAttribute("role","img");s.setAttribute("aria-label",el.getAttribute("data-label"));s.style.width="180px";s.style.height="180px";}})();' );
	}

	public static function profile( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$self    = get_current_user_id() === $user->ID;
		$enabled = self::is_enabled( $user->ID );
		wp_nonce_field( 'publishrank_2fa_profile_' . $user->ID, 'publishrank_2fa_nonce' );
		echo '<h2 id="publishrank-2fa">' . esc_html__( 'Two-factor authentication (Access Shield)', 'publishrank' ) . '</h2><table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Status', 'publishrank' ) . '</th><td>';
		$codes = get_transient( 'publishrank_2fa_codes_' . $user->ID );
		if ( is_array( $codes ) && $self ) {
			delete_transient( 'publishrank_2fa_codes_' . $user->ID );
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Save these recovery codes now. Each works once; they will not be shown again.', 'publishrank' ) . '</strong></p><p><code style="display:block;white-space:pre;line-height:1.8;padding:8px">' . esc_html( implode( "\n", $codes ) ) . '</code></p></div>';
		}
		if ( $enabled ) {
			echo '<p><strong style="color:#1E6B4F">' . esc_html__( 'On', 'publishrank' ) . '</strong> — ' . esc_html( sprintf( /* translators: %d: count */ __( '%d recovery codes left.', 'publishrank' ), self::recovery_left( $user->ID ) ) ) . '</p>';
			if ( $self ) {
				echo '<p><label><input type="checkbox" name="pr_2fa_regen" value="1" /> ' . esc_html__( 'Generate new recovery codes', 'publishrank' ) . '</label></p>';
				echo '<p><label for="pr_2fa_off_code">' . esc_html__( 'To turn two-factor off, enter a current code:', 'publishrank' ) . '</label><br /><input type="text" id="pr_2fa_off_code" name="pr_2fa_off_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="regular-text" style="max-width:140px" /></p>';
			} elseif ( Capabilities::can( 'manage_security' ) || current_user_can( 'edit_users' ) ) {
				echo '<p><label><input type="checkbox" name="pr_2fa_reset" value="1" /> ' . esc_html__( 'Reset two-factor for this user (they can enrol again at next sign-in)', 'publishrank' ) . '</label></p>';
			}
		} elseif ( $self ) {
			$pending = Crypto::decrypt( (string) get_user_meta( $user->ID, self::META_PENDING, true ) );
			if ( '' === $pending ) {
				$pending = self::new_secret();
				update_user_meta( $user->ID, self::META_PENDING, Crypto::encrypt( $pending ) );
			}
			$uri = self::otpauth_uri( $pending, $user );
			echo '<p>' . esc_html__( 'Off. Scan this code with an authenticator app (Google Authenticator, 1Password, Authy, Microsoft Authenticator…), then enter the 6-digit code it shows.', 'publishrank' ) . '</p>';
			echo '<div id="publishrank-2fa-qr" data-uri="' . esc_attr( $uri ) . '" data-label="' . esc_attr__( 'Two-factor QR code', 'publishrank' ) . '" style="margin:10px 0;background:#fff;display:inline-block;padding:6px;border:1px solid #dcdcde;border-radius:8px"></div>';
			echo '<p>' . esc_html__( 'Or enter this key manually:', 'publishrank' ) . ' <code>' . esc_html( trim( chunk_split( $pending, 4, ' ' ) ) ) . '</code> · <a href="' . esc_attr( $uri ) . '">' . esc_html__( 'Open in authenticator app', 'publishrank' ) . '</a></p>';
			echo '<p><label for="pr_2fa_code">' . esc_html__( 'Code from the app', 'publishrank' ) . '</label><br /><input type="text" id="pr_2fa_code" name="pr_2fa_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="regular-text" style="max-width:140px" /> <span class="description">' . esc_html__( 'Then press “Update Profile”.', 'publishrank' ) . '</span></p>';
		} else {
			echo '<p>' . esc_html__( 'Off. Only the user can enrol.', 'publishrank' ) . '</p>';
		}
		if ( self::role_requires( $user ) && ! $enabled ) {
			echo '<p class="description" style="color:#A1232B">' . esc_html__( 'Two-factor authentication is required for this account’s role.', 'publishrank' ) . '</p>';
		}
		echo '</td></tr></table>';
	}

	public static function save_profile( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! isset( $_POST['publishrank_2fa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_2fa_nonce'] ) ), 'publishrank_2fa_profile_' . $user_id ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$self = get_current_user_id() === $user_id;
		if ( $self && ! self::is_enabled( $user_id ) && ! empty( $_POST['pr_2fa_code'] ) ) {
			$pending = Crypto::decrypt( (string) get_user_meta( $user_id, self::META_PENDING, true ) );
			$step    = self::check_code( $pending, sanitize_text_field( wp_unslash( $_POST['pr_2fa_code'] ) ) );
			if ( false !== $step ) {
				$codes = self::enable( $user_id, $pending );
				update_user_meta( $user_id, self::META_LAST, $step );
				set_transient( 'publishrank_2fa_codes_' . $user_id, $codes, 10 * MINUTE_IN_SECONDS );
				SecurityLog::add( 'settings', 'info', array( 'user_id' => $user_id, 'detail' => 'Two-factor enabled.' ) );
			} else {
				add_action(
					'user_profile_update_errors',
					static function ( $errors ) {
						$errors->add( 'publishrank_2fa', __( 'The two-factor code was not valid, so two-factor was not turned on.', 'publishrank' ) );
					}
				);
			}
			return;
		}
		if ( $self && self::is_enabled( $user_id ) ) {
			if ( ! empty( $_POST['pr_2fa_regen'] ) ) {
				set_transient( 'publishrank_2fa_codes_' . $user_id, self::new_recovery_codes( $user_id ), 10 * MINUTE_IN_SECONDS );
			}
			if ( ! empty( $_POST['pr_2fa_off_code'] ) ) {
				if ( self::verify_totp( $user_id, sanitize_text_field( wp_unslash( $_POST['pr_2fa_off_code'] ) ) ) ) {
					self::disable( $user_id, 'self' );
				} else {
					add_action(
						'user_profile_update_errors',
						static function ( $errors ) {
							$errors->add( 'publishrank_2fa', __( 'The code was not valid, so two-factor stays on.', 'publishrank' ) );
						}
					);
				}
			}
			return;
		}
		if ( ! $self && ! empty( $_POST['pr_2fa_reset'] ) && ( Capabilities::can( 'manage_security' ) || current_user_can( 'edit_users' ) ) ) {
			self::disable( $user_id, 'admin' );
		}
	}

	/** Required roles: notice during the grace period, then only the profile screen is reachable. */
	public static function enforce_enrollment() {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! self::role_requires( $user ) || self::is_enabled( $user->ID ) || wp_doing_ajax() ) {
			return;
		}
		$since = (int) get_user_meta( $user->ID, self::META_SINCE, true );
		if ( ! $since ) {
			update_user_meta( $user->ID, self::META_SINCE, time() );
			return;
		}
		$grace = (int) Settings::get( 'twofa_grace_days' ) * DAY_IN_SECONDS;
		global $pagenow;
		if ( time() > $since + $grace && 'profile.php' !== $pagenow ) {
			wp_safe_redirect( admin_url( 'profile.php#publishrank-2fa' ) );
			exit;
		}
	}

	public static function enrollment_notice() {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! self::role_requires( $user ) || self::is_enabled( $user->ID ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Two-factor authentication is required for your account.', 'publishrank' ) . '</strong> <a href="' . esc_url( admin_url( 'profile.php#publishrank-2fa' ) ) . '">' . esc_html__( 'Set it up now', 'publishrank' ) . '</a></p></div>';
	}
}
