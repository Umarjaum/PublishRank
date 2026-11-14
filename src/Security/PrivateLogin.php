<?php
/**
 * PublishRank Private Login.
 *
 * Gives the WordPress sign-in screen a private address (for example /newsroom-desk/) and answers
 * wp-login.php, wp-register.php and wp-admin with the site's not-found page (or a chosen page) for
 * visitors who are not signed in. Login links WordPress prints — emails, menus, the admin bar and
 * redirects — are rewritten to the private address.
 *
 * Emergency switch: define( 'PUBLISHRANK_DISABLE_PRIVATE_LOGIN', true ); in wp-config.php restores the
 * standard login at once.
 *
 * Request-routing approach credit: WPS Hide Login (GPLv2+). PublishRank's implementation, settings,
 * screens and storage are its own.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class PrivateLogin {

	/** Admin screen slug. */
	const PAGE = 'publishrank-private-login';

	/** Features switch. */
	const OPTION = 'publishrank_private_login_enabled';

	/** Fresh installs hold the feature back until the owner has seen the new address. */
	const DEFERRED_OPTION = 'publishrank_private_login_deferred';

	/** Private login address (a single path segment). */
	const SLUG_OPTION = 'publishrank_login_slug';

	/** Where blocked visitors are sent ("404" = the not-found page). */
	const REDIRECT_OPTION = 'publishrank_login_redirect';

	/** User meta: the owner has opened this screen (removes the "New" badge). */
	const META_SEEN = 'publishrank_private_login_seen';

	const DEFAULT_SLUG     = 'login';
	const DEFAULT_REDIRECT = '404';

	const SAVE_ACTION    = 'publishrank_private_login_save';
	const DISMISS_ACTION = 'publishrank_private_login_dismiss';
	const NOTICE_KEY     = 'publishrank_private_login_comment_notice';

	/** Option names used by earlier PublishRank releases, moved once to the names above. */
	const LEGACY_OPTIONS = array(
		'whl_page'                        => self::SLUG_OPTION,
		'whl_redirect_admin'              => self::REDIRECT_OPTION,
		'publishrank_hide_login_enabled' => self::OPTION,
		'publishrank_hide_login_deferred' => self::DEFERRED_OPTION,
	);

	/** @var self|null */
	private static $instance = null;

	/** True when this request asked for a hidden WordPress login file and must be answered as not found. */
	private $blocked_request = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::upgrade_storage();
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/** Kept for the PublishRank module loader; hooks are added in boot(). */
	public function register() {}

	/* --------------------------------------------------------------------- state */

	/** Copy settings saved under earlier option names to the current names, once. */
	public static function upgrade_storage() {
		if ( get_option( 'publishrank_private_login_storage', 0 ) >= 1 ) {
			return;
		}
		foreach ( self::LEGACY_OPTIONS as $old => $new ) {
			$value = get_option( $old, null );
			if ( null !== $value && null === get_option( $new, null ) ) {
				add_option( $new, $value );
			}
			if ( null !== $value ) {
				delete_option( $old );
			}
			if ( is_multisite() ) {
				$network = get_site_option( $old, null );
				if ( null !== $network && null === get_site_option( $new, null ) ) {
					add_site_option( $new, $network );
				}
				if ( null !== $network ) {
					delete_site_option( $old );
				}
			}
		}
		update_option( 'publishrank_private_login_storage', 1 );
	}

	/** True when wp-config.php forces the standard WordPress login. */
	public static function disabled_by_constant() {
		return ( defined( 'PUBLISHRANK_DISABLE_PRIVATE_LOGIN' ) && PUBLISHRANK_DISABLE_PRIVATE_LOGIN )
			|| ( defined( 'PUBLISHRANK_DISABLE_HIDE_LOGIN' ) && PUBLISHRANK_DISABLE_HIDE_LOGIN ); // Older documented name.
	}

	/** Whether the private login is active. Safe to call early. */
	public static function enabled() {
		if ( self::disabled_by_constant() ) {
			return false;
		}
		$value = get_option( self::OPTION, 1 );
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}
		return (bool) $value;
	}

	public static function is_deferred() {
		return (bool) get_option( self::DEFERRED_OPTION, false );
	}

	/** The owner has seen the address: switch the feature on if it was held back. */
	public static function confirm_login_address() {
		if ( ! self::is_deferred() ) {
			return;
		}
		delete_option( self::DEFERRED_OPTION );
		update_option( self::OPTION, 1 );
		flush_rewrite_rules( true );
	}

	/** An explicit Features choice ends the fresh-install hold-back without overriding it. */
	public static function forget_deferral() {
		delete_option( self::DEFERRED_OPTION );
	}

	private static function network_active() {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( self::basename() );
	}

	private static function basename() {
		return defined( 'PUBLISHRANK_SEO_FILE' ) ? plugin_basename( PUBLISHRANK_SEO_FILE ) : 'publishrank/publishrank.php';
	}

	/** Saved login address for a site (defaults to "login", or the network default). */
	public static function current_login_slug( $blog_id = 0 ) {
		$slug = $blog_id ? get_blog_option( (int) $blog_id, self::SLUG_OPTION ) : get_option( self::SLUG_OPTION );
		if ( ! $slug && self::network_active() ) {
			$slug = get_site_option( self::SLUG_OPTION, self::DEFAULT_SLUG );
		}
		return $slug ? (string) $slug : self::DEFAULT_SLUG;
	}

	/** Saved address for blocked visitors (defaults to "404"). */
	public static function current_redirect_slug() {
		$slug = get_option( self::REDIRECT_OPTION );
		if ( ! $slug && self::network_active() ) {
			$slug = get_site_option( self::REDIRECT_OPTION, self::DEFAULT_REDIRECT );
		}
		return $slug ? (string) $slug : self::DEFAULT_REDIRECT;
	}

	/** Query variables WordPress reserves; a login address may not use them. @return string[] */
	public static function reserved_slugs() {
		$wp = new \WP();
		return array_merge( $wp->public_query_vars, $wp->private_query_vars );
	}

	/** Clean a login address. Returns '' when it is empty or reserved by WordPress. */
	public static function clean_login_slug( $raw ) {
		$slug = sanitize_title_with_dashes( is_string( $raw ) ? $raw : '' );
		if ( '' === $slug || false !== strpos( $slug, 'wp-login' ) || false !== strpos( $slug, 'wp-admin' ) || in_array( $slug, self::reserved_slugs(), true ) ) {
			return '';
		}
		return $slug;
	}

	/** Clean the blocked-visitor address; empty means the not-found page. */
	public static function clean_redirect_slug( $raw ) {
		$slug = sanitize_title_with_dashes( is_string( $raw ) ? $raw : '' );
		return '' === $slug ? self::DEFAULT_REDIRECT : $slug;
	}

	public static function flush_after_change() {
		flush_rewrite_rules( true );
	}

	private static function pretty_permalinks() {
		return (bool) get_option( 'permalink_structure' );
	}

	/** Match the site's trailing-slash style. */
	private static function slashed( $url ) {
		return '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ? trailingslashit( $url ) : untrailingslashit( $url );
	}

	/** Public address of the private login screen. */
	public function new_login_url( $scheme = null ) {
		$base = apply_filters( 'publishrank_private_login_base_url', home_url( '/', $scheme ) );
		return self::pretty_permalinks() ? self::slashed( $base . self::current_login_slug() ) : $base . '?' . self::current_login_slug();
	}

	/** Where blocked visitors are sent. */
	public function blocked_url( $scheme = null ) {
		$base = home_url( '/', $scheme );
		return self::pretty_permalinks() ? self::slashed( $base . self::current_redirect_slug() ) : $base . '?' . self::current_redirect_slug();
	}

	/* --------------------------------------------------------------------- hooks */

	private function boot() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_menu', array( $this, 'redirect_old_screens' ), 1 ); // Before WordPress rejects the unknown screen slug.
		add_action( 'update_option_' . self::SLUG_OPTION, array( __CLASS__, 'flush_after_change' ) );
		add_action( 'add_option_' . self::SLUG_OPTION, array( __CLASS__, 'flush_after_change' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'forget_deferral' ) );
		add_filter( 'plugin_action_links_' . self::basename(), array( $this, 'settings_link' ) );

		if ( self::network_active() ) {
			add_action( 'wpmu_options', array( $this, 'network_fields' ) );
			add_action( 'update_wpmu_options', array( $this, 'save_network_fields' ) );
			add_filter( 'network_admin_plugin_action_links_' . self::basename(), array( $this, 'settings_link' ) );
		}

		if ( ! self::enabled() ) {
			return; // Off: WordPress login behaves as normal.
		}

		add_action( 'plugins_loaded', array( $this, 'route_request' ), 9999 );
		add_action( 'setup_theme', array( $this, 'guard_customizer' ), 1 );
		add_action( 'init', array( $this, 'guard_signup_files' ) );
		add_action( 'wp_loaded', array( $this, 'serve_request' ) );
		add_action( 'template_redirect', array( $this, 'forward_privacy_confirmation' ) );
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );

		add_filter( 'site_url', array( $this, 'filter_site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( $this, 'filter_network_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'filter_redirect' ), 10, 2 );
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'site_option_welcome_email', array( $this, 'filter_welcome_email' ) );
		add_filter( 'user_request_action_email_content', array( $this, 'filter_privacy_email' ), 999, 2 );
		add_filter( 'site_status_tests', array( $this, 'filter_site_health' ) );

		add_action( 'admin_notices', array( $this, 'comment_registration_notice' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( $this, 'dismiss_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'notice_script' ) );

		if ( is_multisite() ) {
			add_action( 'wp_before_admin_bar_render', array( $this, 'rewrite_my_sites_menu' ), 999 );
			add_filter( 'manage_sites_action_links', array( $this, 'network_site_links' ), 10, 2 );
		}
	}

	/** Path part of the current request, decoded. */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	private static function request_uri() {
		return isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	}

	/** Recognise the private address and the hidden WordPress files before WordPress routes the request. */
	public function route_request() {
		global $pagenow;
		$uri  = self::request_uri();
		$path = untrailingslashit( self::request_path() );
		$slug = self::current_login_slug();

		$asks_login    = false !== strpos( $uri, 'wp-login.php' ) || site_url( 'wp-login', 'relative' ) === $path;
		$asks_register = false !== strpos( $uri, 'wp-register.php' ) || site_url( 'wp-register', 'relative' ) === $path;
		$asks_private  = home_url( $slug, 'relative' ) === $path || ( ! self::pretty_permalinks() && isset( $_GET[ $slug ] ) && '' === $_GET[ $slug ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ( $asks_login || $asks_register ) && ! is_admin() ) {
			$this->blocked_request  = true;
			$_SERVER['REQUEST_URI'] = self::slashed( '/' . str_repeat( '-/', 10 ) ); // A path no content can match: WordPress answers 404.
			$pagenow                = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return;
		}
		if ( $asks_private ) {
			$_SERVER['SCRIPT_NAME'] = $slug;
			$pagenow                = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/** Visitors who are not signed in cannot open the Customizer. */
	public function guard_customizer() {
		global $pagenow;
		if ( 'customize.php' === $pagenow && ! is_user_logged_in() ) {
			wp_die( esc_html__( 'This has been disabled', 'publishrank' ), 403 );
		}
	}

	/** wp-signup.php and wp-activate.php are not used on single sites. */
	public function guard_signup_files() {
		if ( is_multisite() || apply_filters( 'publishrank_private_login_allow_signup', false ) ) {
			return;
		}
		$uri = self::request_uri();
		if ( false !== strpos( $uri, 'wp-signup' ) || false !== strpos( $uri, 'wp-activate' ) ) {
			wp_die( esc_html__( 'This feature is not enabled.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
	}

	/** Answer the request: serve the login screen, the not-found page, or send blocked visitors away. */
	public function serve_request() {
		global $pagenow;
		$path = self::request_path();
		do_action( 'publishrank_private_login_before_redirect', wp_parse_url( self::request_uri() ) );

		// Password-protected posts post to wp-login.php?action=postpass; let that through.
		if ( isset( $_GET['action'] ) && 'postpass' === $_GET['action'] && isset( $_POST['post_password'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$signed_in    = is_user_logged_in();
		$options_page = '/wp-admin/options.php' === $path;

		if ( is_admin() && ! $signed_in && ! defined( 'WP_CLI' ) && ! wp_doing_ajax() && ! wp_doing_cron() && 'admin-post.php' !== $pagenow && ! $options_page ) {
			wp_safe_redirect( $this->blocked_url() );
			exit;
		}
		if ( ! $signed_in && ( $options_page || ( isset( $_GET['wc-ajax'] ) && 'profile.php' === $pagenow ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( $this->blocked_url() );
			exit;
		}

		if ( 'wp-login.php' === $pagenow && '' !== $path && self::pretty_permalinks() && self::slashed( $path ) !== $path ) {
			$query = isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ? '?' . wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_safe_redirect( self::slashed( $this->new_login_url() ) . $query );
			exit;
		}

		if ( $this->blocked_request ) {
			$this->finish_multisite_activation();
			$this->render_not_found();
		}

		if ( 'wp-login.php' === $pagenow ) {
			global $error, $interim_login, $action, $user_login; // phpcs:ignore -- wp-login.php reads these globals.
			if ( $signed_in && ! isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$requested = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore
				wp_safe_redirect( apply_filters( 'publishrank_private_login_signed_in_redirect', admin_url(), $requested, wp_get_current_user() ) );
				exit;
			}
			require_once ABSPATH . 'wp-login.php';
			exit;
		}
	}

	/** A multisite activation link that was already used points back to the private login screen. */
	private function finish_multisite_activation() {
		$referer = wp_get_referer();
		if ( ! $referer || false === strpos( $referer, 'wp-activate.php' ) ) {
			return;
		}
		$query = (string) wp_parse_url( $referer, PHP_URL_QUERY );
		parse_str( $query, $args );
		if ( empty( $args['key'] ) || ! is_multisite() ) {
			return;
		}
		require_once ABSPATH . WPINC . '/ms-functions.php';
		$result = wpmu_activate_signup( (string) $args['key'] );
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'already_active', 'blog_taken' ), true ) ) {
			$qs = isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ? '?' . wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_safe_redirect( $this->new_login_url() . $qs );
			exit;
		}
	}

	/** Render the theme's not-found page for a hidden WordPress file. */
	private function render_not_found() {
		global $pagenow;
		$pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}
		wp();
		require_once ABSPATH . WPINC . '/template-loader.php';
		exit;
	}

	/** Swap wp-login.php for the private address in a URL WordPress is about to print or follow. */
	private function to_private( $url, $scheme = null ) {
		$original = $url;
		if ( false !== strpos( $url, 'wp-login.php?action=postpass' ) ) {
			return $url;
		}
		if ( is_multisite() && 'install.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			return $url;
		}
		if ( false !== strpos( $url, 'wp-login.php' ) && false === strpos( (string) wp_get_referer(), 'wp-login.php' ) ) {
			if ( is_ssl() ) {
				$scheme = 'https';
			}
			$parts = explode( '?', $url, 2 );
			if ( isset( $parts[1] ) ) {
				parse_str( $parts[1], $args );
				if ( isset( $args['login'] ) ) {
					$args['login'] = rawurlencode( $args['login'] );
				}
				$url = add_query_arg( $args, $this->new_login_url( $scheme ) );
			} else {
				$url = $this->new_login_url( $scheme );
			}
		}
		// A wrong password on a protected post keeps the standard address so WordPress can show the error.
		if ( isset( $_POST['post_password'] ) && ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			global $current_user;
			if ( is_wp_error( wp_authenticate_username_password( null, (string) ( $current_user->user_login ?? '' ), (string) wp_unslash( $_POST['post_password'] ) ) ) ) { // phpcs:ignore
				return $original;
			}
		}
		// Gravity Forms preview pages still need the original address for visitors.
		if ( ! is_user_logged_in() && isset( $_GET['gf_page'] ) && file_exists( WP_PLUGIN_DIR . '/gravityforms/gravityforms.php' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $original;
		}
		return $url;
	}

	public function filter_site_url( $url, $path, $scheme, $blog_id ) {
		return $this->to_private( $url, $scheme );
	}

	public function filter_network_site_url( $url, $path, $scheme ) {
		return $this->to_private( $url, $scheme );
	}

	public function filter_redirect( $location, $status ) {
		if ( false !== strpos( (string) $location, 'https://wordpress.com/wp-login.php' ) ) {
			return $location;
		}
		return $this->to_private( (string) $location );
	}

	/** Re-authentication from options.php goes to the dashboard; on 404 pages no login link is printed. */
	public function filter_login_url( $login_url, $redirect, $force_reauth ) {
		if ( is_404() ) {
			return '#';
		}
		if ( false === $force_reauth || empty( $redirect ) ) {
			return $login_url;
		}
		$target = explode( '?', (string) $redirect );
		return admin_url( 'options.php' ) === $target[0] ? admin_url() : $login_url;
	}

	public function filter_welcome_email( $value ) {
		return str_replace( 'wp-login.php', trailingslashit( (string) get_site_option( self::SLUG_OPTION, self::DEFAULT_SLUG ) ), (string) $value );
	}

	/** Privacy request emails link to the standard confirmation address, which is forwarded below. */
	public function filter_privacy_email( $email_text, $email_data ) {
		$confirm = str_replace( self::current_login_slug() . '/', 'wp-login.php', (string) $email_data['confirm_url'] );
		return str_replace( '###CONFIRM_URL###', esc_url_raw( $confirm ), $email_text );
	}

	/** Forward a valid privacy confirmation link to the private login screen. */
	public function forward_privacy_confirmation() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the request key is verified below.
		if ( ! isset( $_GET['action'], $_GET['request_id'], $_GET['confirm_key'] ) || 'confirmaction' !== $_GET['action'] ) {
			return;
		}
		$request_id = (int) $_GET['request_id'];
		$key        = sanitize_text_field( wp_unslash( $_GET['confirm_key'] ) );
		// phpcs:enable
		if ( is_wp_error( wp_validate_user_request_key( $request_id, $key ) ) ) {
			return;
		}
		wp_safe_redirect( add_query_arg( array( 'action' => 'confirmaction', 'request_id' => $request_id, 'confirm_key' => $key ), $this->new_login_url() ) );
		exit;
	}

	/** The loopback test calls wp-login.php, which is hidden on purpose. */
	public function filter_site_health( $tests ) {
		unset( $tests['async']['loopback_requests'] );
		return $tests;
	}

	/* --------------------------------------------------------------------- multisite */

	public function rewrite_my_sites_menu() {
		global $wp_admin_bar;
		if ( ! is_object( $wp_admin_bar ) ) {
			return;
		}
		foreach ( (array) $wp_admin_bar->get_nodes() as $node ) {
			if ( ! preg_match( '/^blog-(\d+)(.*)/', (string) $node->id, $m ) ) {
				continue;
			}
			$slug = self::current_login_slug( (int) $m[1] );
			if ( '' === $m[2] || '-d' === $m[2] ) {
				$href = preg_replace( '#wp-admin/$#', $slug . '/', (string) $node->href );
				if ( $href !== $node->href ) {
					$node->href = $href;
					$wp_admin_bar->add_node( $node );
				}
			} elseif ( false !== strpos( (string) $node->href, '/wp-admin/' ) ) {
				$wp_admin_bar->remove_node( $node->id );
			}
		}
	}

	public function network_site_links( $actions, $blog_id ) {
		$actions['backend'] = sprintf( '<a href="%1$s" class="edit">%2$s</a>', esc_url( get_site_url( $blog_id, self::current_login_slug( (int) $blog_id ) ) ), esc_html__( 'Dashboard', 'publishrank' ) );
		return $actions;
	}

	public function network_fields() {
		echo '<h3 id="publishrank-private-login">' . esc_html__( 'PublishRank Private Login', 'publishrank' ) . '</h3>';
		echo '<p>' . esc_html__( 'Network default for the private login address. Each site can choose its own under PublishRank → Private Login.', 'publishrank' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="publishrank-network-login-slug">' . esc_html__( 'Default login address', 'publishrank' ) . '</label></th>';
		echo '<td><input id="publishrank-network-login-slug" type="text" name="' . esc_attr( self::SLUG_OPTION ) . '" value="' . esc_attr( (string) get_site_option( self::SLUG_OPTION, self::DEFAULT_SLUG ) ) . '" class="regular-text code" /></td></tr>';
		echo '<tr><th scope="row"><label for="publishrank-network-login-redirect">' . esc_html__( 'Send blocked visitors to', 'publishrank' ) . '</label></th>';
		echo '<td><input id="publishrank-network-login-redirect" type="text" name="' . esc_attr( self::REDIRECT_OPTION ) . '" value="' . esc_attr( (string) get_site_option( self::REDIRECT_OPTION, self::DEFAULT_REDIRECT ) ) . '" class="regular-text code" /></td></tr></table>';
	}

	public function save_network_fields() {
		if ( empty( $_POST ) || ! check_admin_referer( 'siteoptions' ) || ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$changed = false;
		if ( isset( $_POST[ self::SLUG_OPTION ] ) ) {
			$slug = self::clean_login_slug( wp_unslash( $_POST[ self::SLUG_OPTION ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by clean_login_slug().
			if ( '' !== $slug ) {
				update_site_option( self::SLUG_OPTION, $slug );
				$changed = true;
			}
		}
		if ( isset( $_POST[ self::REDIRECT_OPTION ] ) ) {
			update_site_option( self::REDIRECT_OPTION, self::clean_redirect_slug( wp_unslash( $_POST[ self::REDIRECT_OPTION ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$changed = true;
		}
		if ( $changed ) {
			flush_rewrite_rules( true );
		}
	}

	/* --------------------------------------------------------------------- admin */

	public function settings_link( $links ) {
		$url = is_network_admin() ? network_admin_url( 'settings.php#publishrank-private-login' ) : admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Login settings', 'publishrank' ) . '</a>' );
		return $links;
	}

	/** Bookmarks to the screen's earlier address keep working. */
	public function redirect_old_screens() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $page, array( 'publishrank-wp-hide-login', 'whl_settings' ), true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}
	}

	public function add_menu() {
		$title   = __( 'Private Login', 'publishrank' );
		$label   = $title;
		$user_id = get_current_user_id();
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE === $page && $user_id && current_user_can( 'manage_options' ) ) {
			update_user_meta( $user_id, self::META_SEEN, 1 );
		}
		if ( $user_id && current_user_can( 'manage_options' ) && ! get_user_meta( $user_id, self::META_SEEN, true ) && ! get_user_meta( $user_id, 'publishrank_hide_login_seen', true ) ) {
			$label .= ' <span class="pr-menu-new">' . esc_html__( 'New', 'publishrank' ) . '</span>';
		}
		$hook = add_submenu_page( 'publishrank-seo-dashboard', $title, $label, 'manage_options', self::PAGE, array( $this, 'render_page' ), 12 );
		if ( $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( $this, 'page_styles' ) );
		}
	}

	public function page_styles() {
		$file = PUBLISHRANK_SEO_PATH . 'assets/css/private-login.css';
		wp_enqueue_style( 'publishrank-private-login', PUBLISHRANK_SEO_URL . 'assets/css/private-login.css', array(), ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '1' ) . '.' . ( file_exists( $file ) ? (string) filemtime( $file ) : '0' ) );
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_ACTION );
		$slug     = self::clean_login_slug( isset( $_POST['pr_login_slug'] ) ? wp_unslash( $_POST['pr_login_slug'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$redirect = self::clean_redirect_slug( isset( $_POST['pr_login_redirect'] ) ? wp_unslash( $_POST['pr_login_redirect'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $slug ) {
			$this->flash( 'error', __( 'No changes were saved. Please choose a different login address; this one is reserved by WordPress.', 'publishrank' ) );
		}
		update_option( self::SLUG_OPTION, $slug );
		update_option( self::REDIRECT_OPTION, $redirect );
		self::confirm_login_address();
		/* translators: %s: new login URL. */
		$this->flash( 'success', sprintf( __( 'Saved. Your login page is now %s — bookmark it!', 'publishrank' ), $this->new_login_url() ) );
	}

	private function flash( $type, $message ) {
		if ( class_exists( '\\PublishRankSEO\\Admin\\AdminAssets' ) && method_exists( '\\PublishRankSEO\\Admin\\AdminAssets', 'done' ) ) {
			\PublishRankSEO\Admin\AdminAssets::done( self::PAGE, $type, $message );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pretty    = self::pretty_permalinks();
		$prefix    = trailingslashit( home_url() ) . ( $pretty ? '' : '?' );
		$suffix    = ( $pretty && '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ) ? '/' : '';
		$login_url = $this->new_login_url();
		$has_ui    = class_exists( '\\PublishRankSEO\\Admin\\AdminAssets' );

		if ( self::disabled_by_constant() ) {
			$state = array( 'off', 'fa-triangle-exclamation', __( 'Private Login is disabled by wp-config.php.', 'publishrank' ), __( 'The constant PUBLISHRANK_DISABLE_PRIVATE_LOGIN is set to true, so the standard WordPress login (wp-login.php and wp-admin) works as normal. Remove that line from wp-config.php to use your private login address again.', 'publishrank' ), '' );
		} elseif ( self::is_deferred() ) {
			/* translators: %s: login URL. */
			$state = array( 'wait', 'fa-hourglass-half', __( 'Private Login is waiting for you.', 'publishrank' ), sprintf( __( 'Your sign-in page has not moved yet. When you save below, it moves to %s. Copy that address first.', 'publishrank' ), $login_url ), '' );
		} elseif ( ! self::enabled() ) {
			$state = array( 'off', 'fa-toggle-off', __( 'Private Login is turned off.', 'publishrank' ), __( 'The standard WordPress login (wp-login.php and wp-admin) works as normal. Your address below is saved but not in use.', 'publishrank' ), 'features' );
		} else {
			$state = array( 'on', 'fa-key', __( 'Your login page is now here:', 'publishrank' ), __( 'Bookmark this address. Visitors who are not signed in can no longer open wp-login.php, wp-register.php or the wp-admin folder.', 'publishrank' ), 'url' );
		}

		echo '<div class="wrap pr-plogin">';
		if ( $has_ui ) {
			\PublishRankSEO\Admin\AdminAssets::header( __( 'Private Login', 'publishrank' ), __( 'Move the WordPress login page to a private address and keep wp-login.php and wp-admin hidden from visitors.', 'publishrank' ) );
			\PublishRankSEO\Admin\AdminAssets::render_flash();
		} else {
			echo '<h1>' . esc_html__( 'Private Login', 'publishrank' ) . '</h1>';
		}

		echo '<div class="pr-plogin-state pr-plogin-state--' . esc_attr( $state[0] ) . '" role="' . ( 'off' === $state[0] && self::disabled_by_constant() ? 'alert' : 'note' ) . '">';
		echo '<span class="pr-plogin-state__icon fa-solid ' . esc_attr( $state[1] ) . '" aria-hidden="true"></span><div><strong>' . esc_html( $state[2] );
		if ( 'url' === $state[4] ) {
			echo ' <a href="' . esc_url( $login_url ) . '">' . esc_html( $login_url ) . '</a>';
		}
		echo '</strong><p>' . esc_html( $state[3] );
		if ( 'features' === $state[4] ) {
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=publishrank-modules' ) ) . '">' . esc_html__( 'Turn it on under Features', 'publishrank' ) . '</a>';
		}
		echo '</p></div></div>';

		echo '<form class="pr-plogin-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<div class="pr-plogin-grid">';

		$this->panel_open( 'primary', 'fa-right-to-bracket', __( 'Login address', 'publishrank' ), __( 'Login URL', 'publishrank' ) );
		echo '<label for="pr-login-slug"><strong>' . esc_html__( 'Your private login address', 'publishrank' ) . '</strong></label>';
		$this->address_field( 'pr-login-slug', 'pr_login_slug', self::current_login_slug(), $prefix, $suffix, true );
		echo '<p class="description">' . esc_html__( 'You sign in here from now on. wp-login.php and the wp-admin folder are hidden from visitors who are not signed in.', 'publishrank' ) . '</p>';
		$this->panel_close();

		$this->panel_open( 'rose', 'fa-route', __( 'Blocked visitors', 'publishrank' ), __( 'Redirection URL', 'publishrank' ) );
		echo '<label for="pr-login-redirect"><strong>' . esc_html__( 'Send blocked visitors to', 'publishrank' ) . '</strong></label>';
		$this->address_field( 'pr-login-redirect', 'pr_login_redirect', self::current_redirect_slug(), $prefix, $suffix, false );
		echo '<p class="description">' . esc_html__( 'Where visitors land if they open wp-login.php or wp-admin while signed out. Leave 404 to show your not-found page.', 'publishrank' ) . '</p>';
		$this->panel_close();

		$this->panel_open( 'sage', 'fa-shield-halved', __( 'What is protected', 'publishrank' ), __( 'Active protections', 'publishrank' ) );
		echo '<ul class="pr-plogin-list">';
		foreach ( array(
			__( 'wp-login.php and wp-register.php are hidden', 'publishrank' ),
			__( 'wp-admin redirects visitors who are not signed in', 'publishrank' ),
			__( 'wp-signup and wp-activate are blocked on single sites', 'publishrank' ),
			__( 'Login links in emails and menus use your new address', 'publishrank' ),
			__( 'The Customizer is blocked for visitors who are not signed in', 'publishrank' ),
		) as $line ) {
			echo '<li><span class="fa-solid fa-check" aria-hidden="true"></span> ' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'Hiding the login page is not a replacement for strong passwords and two-factor sign-in.', 'publishrank' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Locked out? Add define( \'PUBLISHRANK_DISABLE_PRIVATE_LOGIN\', true ); to wp-config.php to restore the standard login.', 'publishrank' ) . '</p>';
		$this->panel_close();

		echo '</div>';
		echo '<div class="pr-plogin-actions"><button type="submit" class="button button-primary"><span class="fa-solid fa-floppy-disk" aria-hidden="true"></span> ' . esc_html__( 'Save login settings', 'publishrank' ) . '</button></div>';
		echo '</form></div>';
	}

	private function panel_open( $tone, $icon, $kicker, $title ) {
		echo '<section class="pr-plogin-panel pr-plogin-panel--' . esc_attr( $tone ) . '"><div class="pr-plogin-panel__head"><span class="pr-plogin-panel__icon fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></span><div><p class="pr-plogin-panel__kicker">' . esc_html( $kicker ) . '</p><h2>' . esc_html( $title ) . '</h2></div></div>';
	}

	private function panel_close() {
		echo '</section>';
	}

	private function address_field( $id, $name, $value, $prefix, $suffix, $required ) {
		echo '<div class="pr-plogin-address"><code class="pr-plogin-address__base">' . esc_html( $prefix ) . '</code>';
		echo '<input id="' . esc_attr( $id ) . '" type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" autocomplete="off" spellcheck="false"' . ( $required ? ' required aria-required="true"' : '' ) . ' />';
		if ( '' !== $suffix ) {
			echo '<code class="pr-plogin-address__end">/</code>';
		}
		echo '</div>';
	}

	/* --------------------------------------------------------------------- comment-registration notice */

	private static function notice_dismissed() {
		return (bool) get_site_transient( self::NOTICE_KEY );
	}

	/** On Settings → Discussion: the comment form's login link cannot be hidden when comments need an account. */
	public function comment_registration_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'options-discussion' !== $screen->id || self::notice_dismissed() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible pr-plogin-notice" data-pr-plogin-notice><p>' . esc_html__( 'PublishRank Private Login: if "Users must be registered and logged in to comment" is ticked, the comment form still shows a login link, so the private address is visible there.', 'publishrank' ) . '</p></div>';
	}

	public function notice_script( $hook ) {
		if ( 'options-discussion.php' !== $hook || self::notice_dismissed() ) {
			return;
		}
		$file = PUBLISHRANK_SEO_PATH . 'assets/js/private-login.js';
		wp_enqueue_script( 'publishrank-private-login', PUBLISHRANK_SEO_URL . 'assets/js/private-login.js', array(), ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '1' ) . '.' . ( file_exists( $file ) ? (string) filemtime( $file ) : '0' ), true );
		wp_add_inline_script( 'publishrank-private-login', 'window.publishrankPrivateLogin=' . wp_json_encode( array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'action' => self::DISMISS_ACTION, 'nonce' => wp_create_nonce( self::DISMISS_ACTION ) ) ) . ';', 'before' );
	}

	public function dismiss_notice() {
		check_ajax_referer( self::DISMISS_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		set_site_transient( self::NOTICE_KEY, 1, 0 );
		wp_send_json_success();
	}
}
