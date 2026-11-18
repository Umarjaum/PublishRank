<?php
/**
 * Private login address.
 *
 * Written from WordPress's documented request flow: wp-login.php and wp-admin/admin.php both load
 * wp-load.php, which fires plugins_loaded, init and wp_loaded before any login or admin code runs.
 * On wp_loaded this class either serves the login screen at the private address, or answers requests
 * for wp-login.php / wp-admin from signed-out visitors with a 404 (or a configured redirect) before
 * WordPress can redirect them to the login page. Every URL WordPress builds for the login screen
 * (site_url, network_site_url, wp_redirect, login/logout/lost-password/register links and e-mails)
 * is rewritten to the private address.
 *
 * Recovery: define( 'PUBLISHRANK_DISABLE_ACCESS_SHIELD', true ) or the older
 * define( 'PUBLISHRANK_DISABLE_PRIVATE_LOGIN', true ) in wp-config.php, or run
 * `wp publishrank shield disable`.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

final class LoginAddress {
	const PENDING_OPT  = 'publishrank_access_shield_pending';
	const UNDO_SECONDS = 600;

	/** @var bool Whether this request is for the private login address. */
	private static $is_private = false;
	/** @var string '' (allow), 'always' (block) or 'signed_out' (block unless signed in). */
	private static $block = '';
	/** @var bool Re-entrancy guard while wp-login.php is included. */
	private static $serving = false;

	/** Slugs that may never be used. */
	public static function reserved() {
		$list = array( 'admin', 'administrator', 'dashboard', 'login', 'log-in', 'signin', 'sign-in', 'wp', 'wordpress', 'wp-admin', 'wp-login', 'wp-login-php', 'wp-content', 'wp-includes', 'wp-json', 'wp-signup', 'wp-activate', 'wp-cron', 'xmlrpc', 'feed', 'rss', 'rss2', 'atom', 'rdf', 'comments', 'search', 'page', 'author', 'category', 'tag', 'embed', 'trackback', 'attachment', 'sitemap', 'robots', 'favicon', 'llms', 'index', 'home', 'register', 'lostpassword', 'logout', 'user', 'users', 'account', 'my-account', 'cart', 'checkout', 'shop' );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( ! empty( $pt->rewrite['slug'] ) ) {
				$list[] = (string) $pt->rewrite['slug'];
			}
			$list[] = (string) $pt->name;
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( ! empty( $tax->rewrite['slug'] ) ) {
				$list[] = (string) $tax->rewrite['slug'];
			}
		}
		return array_values( array_unique( array_map( 'strtolower', $list ) ) );
	}

	/** Lowercase, URL-safe, single segment. */
	public static function clean_slug( $raw ) {
		$slug = sanitize_title_with_dashes( remove_accents( (string) $raw ), '', 'save' );
		$slug = trim( preg_replace( '/[^a-z0-9\-_]/', '', strtolower( $slug ) ), '-_' );
		return substr( $slug, 0, 64 );
	}

	/**
	 * Validate a proposed slug: length, reserved words, existing content and rewrite collisions.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_slug( $slug ) {
		$slug = self::clean_slug( $slug );
		if ( strlen( $slug ) < 6 ) {
			return new \WP_Error( 'publishrank_slug_short', __( 'Use at least 6 characters so the address is hard to guess.', 'publishrank' ) );
		}
		if ( in_array( $slug, self::reserved(), true ) ) {
			return new \WP_Error( 'publishrank_slug_reserved', __( 'That address is reserved by WordPress or is too easy to guess. Choose another.', 'publishrank' ) );
		}
		if ( get_page_by_path( $slug, OBJECT, get_post_types( array( 'public' => true ) ) ) || url_to_postid( home_url( '/' . $slug . '/' ) ) ) {
			return new \WP_Error( 'publishrank_slug_taken', __( 'A page or post already uses that address.', 'publishrank' ) );
		}
		if ( term_exists( $slug ) ) {
			return new \WP_Error( 'publishrank_slug_term', __( 'A category or tag already uses that address.', 'publishrank' ) );
		}
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) ) {
			foreach ( array_keys( $rules ) as $pattern ) {
				$plain = trim( str_replace( array( '^', '$', '\\', '/?' ), '', (string) $pattern ), '/' );
				if ( $plain === $slug ) {
					return new \WP_Error( 'publishrank_slug_rewrite', __( 'Another feature already serves that address.', 'publishrank' ) );
				}
			}
		}
		return true;
	}

	public static function disabled_by_constant() {
		return ( defined( 'PUBLISHRANK_DISABLE_ACCESS_SHIELD' ) && PUBLISHRANK_DISABLE_ACCESS_SHIELD )
			|| ( defined( 'PUBLISHRANK_DISABLE_PRIVATE_LOGIN' ) && PUBLISHRANK_DISABLE_PRIVATE_LOGIN )
			|| ( defined( 'PUBLISHRANK_DISABLE_HIDE_LOGIN' ) && PUBLISHRANK_DISABLE_HIDE_LOGIN ); // Recovery constant documented by PublishRank releases before 2.24; still honoured so old wp-config.php recovery steps keep working.
	}

	/** Whether the private address is in force on this site. */
	public static function active( $blog_id = 0 ) {
		if ( self::disabled_by_constant() || ! AccessShield::enabled() ) {
			return false;
		}
		if ( $blog_id && is_multisite() && (int) $blog_id !== get_current_blog_id() ) {
			$opt = get_blog_option( (int) $blog_id, Settings::OPTION, array() );
			return is_array( $opt ) && ! empty( $opt['login_enabled'] ) && '' !== (string) ( $opt['login_slug'] ?? '' );
		}
		return (bool) Settings::get( 'login_enabled' ) && '' !== (string) Settings::get( 'login_slug' );
	}

	public static function slug( $blog_id = 0 ) {
		if ( $blog_id && is_multisite() && (int) $blog_id !== get_current_blog_id() ) {
			$opt = get_blog_option( (int) $blog_id, Settings::OPTION, array() );
			return is_array( $opt ) ? (string) ( $opt['login_slug'] ?? '' ) : '';
		}
		return (string) Settings::get( 'login_slug' );
	}

	/** The private login URL. */
	public static function url( $scheme = null, $blog_id = 0 ) {
		$slug = self::slug( $blog_id );
		$home = $blog_id && is_multisite() ? get_home_url( (int) $blog_id, '/', $scheme ) : home_url( '/', $scheme );
		if ( get_option( 'permalink_structure' ) ) {
			$url = trailingslashit( $home ) . $slug;
			return '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ? trailingslashit( $url ) : $url;
		}
		return add_query_arg( $slug, '', $home );
	}

	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_auto_undo' ), 0 );
		if ( ! self::active() ) {
			return;
		}
		self::classify();
		add_action( 'wp_loaded', array( __CLASS__, 'serve' ), 9999 );
		add_action( 'setup_theme', array( __CLASS__, 'guard_customizer' ), 1 );
		add_action( 'init', array( __CLASS__, 'disable_admin_shortcuts' ), 0 );
		add_filter( 'site_url', array( __CLASS__, 'filter_site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( __CLASS__, 'filter_network_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( __CLASS__, 'filter_redirect' ), 10, 2 );
		add_filter( 'login_url', array( __CLASS__, 'filter_login_url' ), 10, 3 );
		add_filter( 'register_url', array( __CLASS__, 'rewrite' ) );
		add_filter( 'lostpassword_url', array( __CLASS__, 'rewrite' ) );
		add_filter( 'logout_url', array( __CLASS__, 'rewrite' ) );
		add_filter( 'site_option_welcome_email', array( __CLASS__, 'rewrite_text' ) );
		add_filter( 'site_option_welcome_user_email', array( __CLASS__, 'rewrite_text' ) );
		add_filter( 'wp_new_user_notification_email', array( __CLASS__, 'rewrite_mail' ), 99 );
		add_filter( 'wp_new_user_notification_email_admin', array( __CLASS__, 'rewrite_mail' ), 99 );
		add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'rewrite_mail' ), 99 );
		add_filter( 'user_request_action_email_content', array( __CLASS__, 'rewrite_text' ), 99 );
	}

	/** Work out what this request is, using only the URL. */
	private static function classify() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed below.
		$path = rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base = '/' === $base ? '' : untrailingslashit( $base );
		$rel  = $path;
		if ( '' !== $base && 0 === stripos( $path, $base . '/' ) ) {
			$rel = substr( $path, strlen( $base ) );
		} elseif ( '' !== $base && strtolower( $path ) === strtolower( $base ) ) {
			$rel = '/';
		}
		$rel  = '/' . ltrim( (string) preg_replace( '#/+#', '/', $rel ), '/' );
		$slug = self::slug();
		$seg  = strtolower( trim( $rel, '/' ) );

		if ( $seg === $slug || ( ! get_option( 'permalink_structure' ) && '' === $seg && isset( $_GET[ $slug ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::$is_private = true;
			return;
		}
		if ( preg_match( '#^/wp-login(?:\.php)?/?$#i', $rel ) ) {
			// Password-protected post forms on cached pages may still post here; that only sets a cookie.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::$block = 'postpass' !== $action ? 'always' : '';
			return;
		}
		if ( ! is_multisite() && preg_match( '#^/wp-(?:signup|activate)\.php$#i', $rel ) ) {
			self::$block = 'always';
			return;
		}
		if ( preg_match( '#^/wp-admin(?:/|$)#i', $rel ) ) {
			// Signed-out front-end features use these two endpoints; static styles/scripts used by the login screen too.
			if ( preg_match( '#^/wp-admin/(?:admin-ajax\.php|admin-post\.php|load-styles\.php|load-scripts\.php)$#i', $rel ) ) {
				return;
			}
			self::$block = 'signed_out';
		}
	}

	/** Serve or block once WordPress and the current user are fully loaded. */
	public static function serve() {
		if ( self::$serving || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}
		if ( 'signed_out' === self::$block ) {
			if ( is_user_logged_in() || wp_doing_ajax() ) {
				return;
			}
			self::deny();
		}
		if ( 'always' === self::$block ) {
			self::deny();
		}
		if ( self::$is_private ) {
			self::maybe_confirm();
			self::include_login();
		}
	}

	/** Include core's login screen at the private address. */
	private static function include_login() {
		global $error, $interim_login, $action, $user_login, $pagenow;
		self::$serving = true;
		$pagenow       = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- login screen expects it.
		if ( ! defined( 'PUBLISHRANK_SERVING_LOGIN' ) ) {
			define( 'PUBLISHRANK_SERVING_LOGIN', true );
		}
		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/** Answer like a page that does not exist (or redirect, if configured). */
	public static function deny() {
		SecurityLog::add( 'hidden_login_hit', 'notice', array( 'detail' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) );
		if ( 'redirect' === Settings::get( 'blocked_behaviour' ) && '' !== (string) Settings::get( 'blocked_redirect' ) ) {
			wp_safe_redirect( home_url( '/' . Settings::get( 'blocked_redirect' ) . '/' ), 302 );
			exit;
		}
		self::render_404();
	}

	/** Render the theme's 404 template with a 404 status. */
	public static function render_404() {
		global $wp_query;
		if ( ! headers_sent() ) {
			status_header( 404 );
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		$template = function_exists( 'get_404_template' ) ? get_404_template() : '';
		if ( $template && is_readable( $template ) ) {
			include $template;
		} else {
			echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Page not found', 'publishrank' ) . '</title><meta name="robots" content="noindex"></head><body><h1>' . esc_html__( 'Page not found', 'publishrank' ) . '</h1></body></html>';
		}
		exit;
	}

	/** Signed-out visitors cannot reach the customizer (it would bounce them to the login page). */
	public static function guard_customizer() {
		global $pagenow;
		if ( 'customize.php' === $pagenow && ! is_user_logged_in() ) {
			self::deny();
		}
	}

	/** Core maps /login, /admin and /dashboard to the login screen; that would reveal the address. */
	public static function disable_admin_shortcuts() {
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	/** Replace wp-login.php in a URL with the private address, keeping the query. */
	public static function rewrite( $url, $blog_id = 0 ) {
		$url = (string) $url;
		if ( false === strpos( $url, 'wp-login.php' ) || ( $blog_id && ! self::active( $blog_id ) ) ) {
			return $url;
		}
		$parts  = wp_parse_url( $url );
		$query  = isset( $parts['query'] ) ? $parts['query'] : '';
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : null;
		$new    = self::url( $scheme, $blog_id );
		if ( '' !== $query ) {
			parse_str( $query, $args );
			$new = add_query_arg( array_map( 'rawurlencode_deep', $args ), $new );
		}
		if ( isset( $parts['fragment'] ) ) {
			$new .= '#' . $parts['fragment'];
		}
		return $new;
	}

	public static function filter_site_url( $url, $path, $scheme, $blog_id ) {
		return self::rewrite( $url, $blog_id && is_multisite() && (int) $blog_id !== get_current_blog_id() ? (int) $blog_id : 0 );
	}

	public static function filter_network_site_url( $url, $path, $scheme ) {
		if ( is_multisite() && function_exists( 'get_main_site_id' ) && get_main_site_id() !== get_current_blog_id() ) {
			return self::rewrite( $url, get_main_site_id() );
		}
		return self::rewrite( $url );
	}

	public static function filter_redirect( $location, $status ) {
		return self::rewrite( $location );
	}

	public static function filter_login_url( $login_url, $redirect, $force ) {
		return self::rewrite( $login_url );
	}

	public static function rewrite_text( $text ) {
		if ( ! is_string( $text ) || false === strpos( $text, 'wp-login.php' ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			'#https?://[^\s"\'<>]*wp-login\.php[^\s"\'<>]*#',
			static function ( $m ) {
				return self::rewrite( $m[0] );
			},
			$text
		);
	}

	/** @param array $mail wp_mail args. */
	public static function rewrite_mail( $mail ) {
		if ( is_array( $mail ) && isset( $mail['message'] ) ) {
			$mail['message'] = self::rewrite_text( $mail['message'] );
		}
		return $mail;
	}

	/**
	 * Start a change with a 10-minute safety window. The new address is live immediately; if nobody
	 * opens the confirmation link in time, the previous configuration comes back automatically.
	 */
	public static function begin_change( array $previous, $slug ) {
		$token = wp_generate_password( 32, false, false );
		update_option(
			self::PENDING_OPT,
			array(
				'previous' => array(
					'login_enabled' => ! empty( $previous['login_enabled'] ) ? 1 : 0,
					'login_slug'    => (string) ( $previous['login_slug'] ?? '' ),
				),
				'slug'     => $slug,
				'hash'     => wp_hash( $token ),
				'expires'  => time() + self::UNDO_SECONDS,
				'user'     => get_current_user_id(),
			),
			false
		);
		$link = add_query_arg( 'pr_shield_confirm', $token, self::url() );
		$user = wp_get_current_user();
		$to   = $user && $user->user_email ? $user->user_email : get_option( 'admin_email' );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your new private login address', 'publishrank' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$message = sprintf(
			/* translators: 1: login URL, 2: confirm link, 3: minutes */
			__( "Your WordPress login address is now:\n\n%1\$s\n\nBookmark it. /wp-login.php and /wp-admin/ now show a \"page not found\" error to signed-out visitors.\n\nConfirm the change within %3\$d minutes by opening this link:\n%2\$s\n\nIf it is not confirmed in time, the previous login address comes back automatically.\n\nRecovery: add define( 'PUBLISHRANK_DISABLE_ACCESS_SHIELD', true ); to wp-config.php, or run: wp publishrank shield disable", 'publishrank' ),
			self::url(),
			$link,
			(int) ( self::UNDO_SECONDS / 60 )
		);
		wp_mail( $to, $subject, $message );
		return $link;
	}

	/** @return array|null */
	public static function pending() {
		$p = get_option( self::PENDING_OPT, null );
		return is_array( $p ) && ! empty( $p['expires'] ) ? $p : null;
	}

	private static function maybe_confirm() {
		$token = isset( $_GET['pr_shield_confirm'] ) ? sanitize_text_field( wp_unslash( $_GET['pr_shield_confirm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token itself is the proof.
		$p     = self::pending();
		if ( '' === $token || ! $p || ! hash_equals( (string) $p['hash'], wp_hash( $token ) ) ) {
			return;
		}
		delete_option( self::PENDING_OPT );
		SecurityLog::add( 'settings', 'notice', array( 'detail' => 'Private login address confirmed.' ) );
		if ( is_user_logged_in() ) {
			\PublishRankSEO\Admin\AdminAssets::flash( 'success', __( 'Your private login address is confirmed.', 'publishrank' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=publishrank-access-shield' ) );
			exit;
		}
	}

	/** Revert an unconfirmed change after the safety window. */
	public static function maybe_auto_undo() {
		$p = self::pending();
		if ( ! $p || time() < (int) $p['expires'] ) {
			return;
		}
		delete_option( self::PENDING_OPT );
		Settings::update( $p['previous'] );
		SecurityLog::add( 'auto_undo', 'warn', array( 'detail' => 'Unconfirmed login address change reverted after 10 minutes.' ) );
		flush_rewrite_rules( false );
	}

	/** Confirm explicitly (admin pressed "Keep this address" from a session at the new URL). */
	public static function confirm_now() {
		delete_option( self::PENDING_OPT );
	}
}
