<?php

namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

/**
 * PublishRank Hide Login: changes the login URL and blocks wp-login.php / wp-admin for visitors.
 * Ported from WPS Hide Login 1.9.19 (GPLv2+, WPServeur / Nicolas Kulka).
 */


final class WPHideLogin {

	const META_SEEN = 'publishrank_hide_login_seen';

	/** Features switch (Features screen). On by default so existing sites keep their protection. */
	const OPTION = 'publishrank_hide_login_enabled';

	/** Set on a fresh install: Hide Login stays off until the owner has seen the Security step or saved this screen. */
	const DEFERRED_OPTION = 'publishrank_hide_login_deferred';

	protected static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init();
	}

	/** Kept for the PublishRank module loader; hooks are registered in init(). */
	public function register() {}

	/** Whether Hide Login is switched on. Safe to call before WordPress has loaded every plugin. */
	public static function enabled() {
		// Emergency kill switch: define( 'PUBLISHRANK_DISABLE_HIDE_LOGIN', true ); in wp-config.php
		// restores the standard WordPress login no matter what is saved in the database.
		if ( self::disabled_by_constant() ) {
			return false;
		}
		$value = get_option( self::OPTION, 1 );
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}
		return (bool) $value;
	}

	/** True while a fresh install is waiting for the owner to confirm the login address. */
	public static function is_deferred() {
		return (bool) get_option( self::DEFERRED_OPTION, false );
	}

	/**
	 * The owner has seen the new login address (Security step, this screen or the Features switch):
	 * turn Hide Login on if it was held back, and stop holding it back.
	 */
	public static function confirm_login_address() {
		if ( ! self::is_deferred() ) {
			return;
		}
		delete_option( self::DEFERRED_OPTION );
		update_option( self::OPTION, 1 );
		flush_rewrite_rules( true );
	}

	/** Called when the Features switch changes: an explicit choice ends the hold-back without overriding it. */
	public static function forget_deferral() {
		delete_option( self::DEFERRED_OPTION );
	}

	/** True when wp-config.php forces Hide Login off (lock-out recovery). */
	public static function disabled_by_constant() {
		return defined( 'PUBLISHRANK_DISABLE_HIDE_LOGIN' ) && PUBLISHRANK_DISABLE_HIDE_LOGIN;
	}

	private $wp_login_php;

	protected function init() {
		global $wp_version;

		if ( version_compare( $wp_version, '4.0-RC1-src', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'admin_notices_incompatible' ) );
			add_action( 'network_admin_notices', array( $this, 'admin_notices_incompatible' ) );

			return;
		}


		if ( is_multisite() && ! function_exists( 'is_plugin_active_for_network' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );

		}

		if ( is_multisite() && is_plugin_active_for_network( PUBLISHRANK_HIDE_LOGIN_BASENAME ) ) {
			add_action( 'wpmu_options', array( $this, 'wpmu_options' ) );
			add_action( 'update_wpmu_options', array( $this, 'update_wpmu_options' ) );

			add_filter( 'network_admin_plugin_action_links_' . PUBLISHRANK_HIDE_LOGIN_BASENAME, array(
				$this,
				'plugin_action_links'
			) );
		}

		// The settings screen stays reachable while the feature is off, so it can be reviewed and switched back on.
		add_action( 'admin_menu', array( $this, 'publishrank_hide_login_menu_page' ), 11 );
		add_action( 'admin_post_publishrank_hide_login_save', array( $this, 'save_settings' ) );
		// One place flushes rewrite rules whenever the login address changes, wherever it was saved from
		// (Hide Login screen, Setup Wizard or General Settings).
		add_action( 'update_option_whl_page', array( __CLASS__, 'flush_after_change' ) );
		add_action( 'add_option_whl_page', array( __CLASS__, 'flush_after_change' ) );
		add_action( 'admin_init', array( $this, 'whl_template_redirect' ) );
		add_filter( 'plugin_action_links_' . PUBLISHRANK_HIDE_LOGIN_BASENAME, array( $this, 'plugin_action_links' ) );

		// An explicit change on the Features screen ends the fresh-install hold-back (it never overrides the choice made).
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'forget_deferral' ) );

		if ( ! self::enabled() ) {
			// Off: no login URL rewriting, no wp-login.php or wp-admin blocking. WordPress behaves as normal.
			return;
		}

		if ( is_multisite() ) {
			add_action( 'wp_before_admin_bar_render', array( $this, 'modify_mysites_menu' ), 999 );
		}

		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 9999 );
		add_action( 'init', array( $this, 'init_block_access' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'network_admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'wp_loaded', array( $this, 'wp_loaded' ) );
		add_action( 'setup_theme', array( $this, 'setup_theme' ), 1 );

		add_filter( 'site_url', array( $this, 'site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( $this, 'network_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'wp_redirect' ), 10, 2 );
		add_filter( 'site_option_welcome_email', array( $this, 'welcome_email' ) );

		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		add_action( 'template_redirect', array( $this, 'redirect_export_data' ) );
		add_filter( 'login_url', array( $this, 'login_url' ), 10, 3 );

		add_filter( 'user_request_action_email_content', array( $this, 'user_request_action_email_content' ), 999, 2 );

		add_filter( 'site_status_tests', array( $this, 'site_status_tests' ) );

		add_action( 'wp_ajax_publishrank_hide_login_dismiss_notice', array( $this, 'dismiss_admin_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts_notifs' ) );
		add_action( 'admin_notices', array( $this, 'warning_options_discussion' ) );
		//add_action( 'admin_notices', array( $this, 'warning_notice_for_comment_registration' ) );

		add_filter( 'manage_sites_action_links', array( $this, 'manage_sites_action_links' ), 10, 3 );
	}

	public function site_status_tests( $tests ) {
		unset( $tests['async']['loopback_requests'] );

		return $tests;
	}

	public function user_request_action_email_content( $email_text, $email_data ) {
		$email_text = str_replace( '###CONFIRM_URL###', esc_url_raw( str_replace( $this->new_login_slug() . '/', 'wp-login.php', $email_data['confirm_url'] ) ), $email_text );

		return $email_text;
	}

	private function use_trailing_slashes() {

		return ( '/' === substr( get_option( 'permalink_structure' ), - 1, 1 ) );

	}

	private function user_trailingslashit( $string ) {

		return $this->use_trailing_slashes() ? trailingslashit( $string ) : untrailingslashit( $string );

	}

	private function wp_template_loader() {

		global $pagenow;

		$pagenow = 'index.php';

		if ( ! defined( 'WP_USE_THEMES' ) ) {

			define( 'WP_USE_THEMES', true );

		}

		wp();

		require_once( ABSPATH . WPINC . '/template-loader.php' );

		die;

	}

	public function modify_mysites_menu() {
		global $wp_admin_bar;

		$all_toolbar_nodes = $wp_admin_bar->get_nodes();

		foreach ( $all_toolbar_nodes as $node ) {
			if ( preg_match( '/^blog-(\d+)(.*)/', $node->id, $matches ) ) {
				$blog_id = $matches[1];
				if ( $login_slug = $this->new_login_slug( $blog_id ) ) {
					if ( ! $matches[2] || '-d' === $matches[2] ) {
						$args       = $node;
						$old_href   = $args->href;
						$args->href = preg_replace( '/wp-admin\/$/', "$login_slug/", $old_href );
						if ( $old_href !== $args->href ) {
							$wp_admin_bar->add_node( $args );
						}
					} elseif ( strpos( $node->href, '/wp-admin/' ) !== false ) {
						$wp_admin_bar->remove_node( $node->id );
					}
				}
			}
		}
	}

	private function new_login_slug( $blog_id = '' ) {
		if ( $blog_id ) {
			if ( $slug = get_blog_option( $blog_id, 'whl_page' ) ) {
				return $slug;
			}
		} else {
			if ( $slug = get_option( 'whl_page' ) ) {
				return $slug;
			} else if ( ( is_multisite() && is_plugin_active_for_network( PUBLISHRANK_HIDE_LOGIN_BASENAME ) && ( $slug = get_site_option( 'whl_page', 'login' ) ) ) ) {
				return $slug;
			} else if ( $slug = 'login' ) {
				return $slug;
			}
		}
	}

	private function new_redirect_slug() {
		if ( $slug = get_option( 'whl_redirect_admin' ) ) {
			return $slug;
		} else if ( ( is_multisite() && is_plugin_active_for_network( PUBLISHRANK_HIDE_LOGIN_BASENAME ) && ( $slug = get_site_option( 'whl_redirect_admin', '404' ) ) ) ) {
			return $slug;
		} else if ( $slug = '404' ) {
			return $slug;
		}
	}

	public function new_login_url( $scheme = null ) {

		$url = apply_filters( 'publishrank_hide_login_home_url', home_url( '/', $scheme ) );

		if ( get_option( 'permalink_structure' ) ) {

			return $this->user_trailingslashit( $url . $this->new_login_slug() );

		} else {

			return $url . '?' . $this->new_login_slug();

		}

	}

	public function new_redirect_url( $scheme = null ) {

		if ( get_option( 'permalink_structure' ) ) {

			return $this->user_trailingslashit( home_url( '/', $scheme ) . $this->new_redirect_slug() );

		} else {

			return home_url( '/', $scheme ) . '?' . $this->new_redirect_slug();

		}

	}

	public function admin_notices_incompatible() {

		echo '<div class="error notice is-dismissible"><p>' . __( 'Please upgrade to the latest version of WordPress to activate', 'publishrank' ) . ' <strong>' . __( 'PublishRank Hide Login', 'publishrank' ) . '</strong>.</p></div>';

	}

	public function admin_notices_plugin_conflict() {

		echo '<div class="error notice is-dismissible"><p>' . __( 'PublishRank Hide Login conflicts with Rename wp-login.php. Please deactivate Rename wp-login.php.', 'publishrank' ) . '</p></div>';

	}

	/**
	 * Plugin activation
	 */
	public static function activate() {
		//add_option( 'whl_redirect', '1' );

		do_action( 'publishrank_hide_login_activate' );
	}

	public function wpmu_options() {

		$out = '';

		$out .= '<h3>' . __( 'PublishRank Hide Login', 'publishrank' ) . '</h3>';
		$out .= '<p>' . __( 'This option allows you to set a networkwide default, which can be overridden by individual sites. Simply go to to the site’s permalink settings to change the url.', 'publishrank' ) . '</p>';
		$out .= '<p>' . __( 'Need help? Visit the PublishRank website: <a href="https://publishrankseo.vercel.app/" target="_blank">publishrankseo.vercel.app</a>', 'publishrank' ) . '</p>';
		$out .= '<table class="form-table">';
		$out .= '<tr valign="top">';
		$out .= '<th scope="row"><label for="whl_page">' . __( 'Networkwide default', 'publishrank' ) . '</label></th>';
		$out .= '<td><input id="whl_page" type="text" name="whl_page" value="' . esc_attr( get_site_option( 'whl_page', 'login' ) ) . '"></td>';
		$out .= '<th scope="row"><label for="whl_redirect_admin">' . __( 'Redirection url default', 'publishrank' ) . '</label></th>';
		$out .= '<td><input id="whl_redirect_admin" type="text" name="whl_redirect_admin" value="' . esc_attr( get_site_option( 'whl_redirect_admin', '404' ) ) . '"></td>';
		$out .= '</tr>';
		$out .= '</table>';

		echo $out;

	}

	public function update_wpmu_options() {
		if ( empty( $_POST ) || ! check_admin_referer( 'siteoptions' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$changed = false;

		if ( isset( $_POST['whl_page'] ) ) {
			$whl_page = self::clean_login_slug( wp_unslash( $_POST['whl_page'] ) );
			if ( '' !== $whl_page ) {
				update_site_option( 'whl_page', $whl_page );
				$changed = true;
			}
		}

		if ( isset( $_POST['whl_redirect_admin'] ) ) {
			update_site_option( 'whl_redirect_admin', self::clean_redirect_slug( wp_unslash( $_POST['whl_redirect_admin'] ) ) );
			$changed = true;
		}

		if ( $changed ) {
			flush_rewrite_rules( true );
		}
	}

	public function admin_init() {

		global $pagenow;


		if ( get_option( 'whl_redirect' ) ) {

			delete_option( 'whl_redirect' );

			if ( is_multisite()
			     && is_super_admin()
			     && is_plugin_active_for_network( PUBLISHRANK_HIDE_LOGIN_BASENAME ) ) {

				$redirect = network_admin_url( 'settings.php#whl_settings' );

			} else {

				$redirect = admin_url( 'admin.php?page=publishrank-wp-hide-login' );

			}

			wp_safe_redirect( $redirect );
			die();

		}

	}

	public function whl_section_desc() {

		$out = '';

		$out .= '<div id=\"whl_settings\"><p>' . __( 'PublishRank Hide Login protects your website by moving the login page to a custom address and blocking wp-login.php and wp-admin for visitors who are not signed in.', 'publishrank' ) . '</p></div>';

		if ( is_multisite()
		     && is_super_admin()
		     && is_plugin_active_for_network( PUBLISHRANK_HIDE_LOGIN_BASENAME ) ) {

			$out .= '<p>' . sprintf( __( 'To set a networkwide default, go to <a href="%s">Network Settings</a>.', 'publishrank' ), network_admin_url( 'settings.php#whl_settings' ) ) . '</p>';

		}

		echo $out;

	}

	public function whl_page_input() {

		if ( get_option( 'permalink_structure' ) ) {

			echo '<code>' . trailingslashit( home_url() ) . '</code> <input id="whl_page" type="text" name="whl_page" value="' . $this->new_login_slug() . '">' . ( $this->use_trailing_slashes() ? ' <code>/</code>' : '' );

		} else {

			echo '<code>' . trailingslashit( home_url() ) . '?</code> <input id="whl_page" type="text" name="whl_page" value="' . $this->new_login_slug() . '">';

		}

		echo '<p class="description">' . __( 'Protect your website by changing the login URL and preventing access to the wp-login.php page and the wp-admin directory to non-connected people.', 'publishrank' ) . '</p>';

	}

	public function whl_redirect_admin_input() {
		if ( get_option( 'permalink_structure' ) ) {

			echo '<code>' . trailingslashit( home_url() ) . '</code> <input id="whl_redirect_admin" type="text" name="whl_redirect_admin" value="' . $this->new_redirect_slug() . '">' . ( $this->use_trailing_slashes() ? ' <code>/</code>' : '' );

		} else {

			echo '<code>' . trailingslashit( home_url() ) . '?</code> <input id="whl_redirect_admin" type="text" name="whl_redirect_admin" value="' . $this->new_redirect_slug() . '">';

		}

		echo '<p class="description">' . __( 'Redirect URL when someone tries to access the wp-login.php page and the wp-admin directory while not logged in.', 'publishrank' ) . '</p>';
	}

	public function admin_notices() {

		global $pagenow;

		$out = '';


	}

	public function plugin_action_links( $links ) {

		if ( is_network_admin()
		     && is_plugin_active_for_network( PUBLISHRANK_HIDE_LOGIN_BASENAME ) ) {

			array_unshift( $links, '<a href="' . network_admin_url( 'settings.php#whl_settings' ) . '">' . __( 'Settings', 'publishrank' ) . '</a>' );

		} elseif ( ! is_network_admin() ) {

			array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=publishrank-wp-hide-login' ) . '">' . __( 'Settings', 'publishrank' ) . '</a>' );

		}

		return $links;

	}

	public function redirect_export_data() {
		if ( ! empty( $_GET ) && isset( $_GET['action'] ) && 'confirmaction' === $_GET['action'] && isset( $_GET['request_id'] ) && isset( $_GET['confirm_key'] ) ) {
			$request_id = (int) $_GET['request_id'];
			$key        = sanitize_text_field( wp_unslash( $_GET['confirm_key'] ) );
			$result     = wp_validate_user_request_key( $request_id, $key );
			if ( ! is_wp_error( $result ) ) {
				wp_redirect( add_query_arg( array(
					'action'      => 'confirmaction',
					'request_id'  => $_GET['request_id'],
					'confirm_key' => $_GET['confirm_key']
				), $this->new_login_url()
				) );
				exit();
			}
		}
	}

	public function plugins_loaded() {

		global $pagenow;

		$request = parse_url( rawurldecode( $_SERVER['REQUEST_URI'] ) );

		if ( ( strpos( rawurldecode( $_SERVER['REQUEST_URI'] ), 'wp-login.php' ) !== false
		       || ( isset( $request['path'] ) && untrailingslashit( $request['path'] ) === site_url( 'wp-login', 'relative' ) ) )
		     && ! is_admin() ) {

			$this->wp_login_php = true;

			$_SERVER['REQUEST_URI'] = $this->user_trailingslashit( '/' . str_repeat( '-/', 10 ) );

			$pagenow = 'index.php';

		} elseif ( ( isset( $request['path'] ) && untrailingslashit( $request['path'] ) === home_url( $this->new_login_slug(), 'relative' ) )
		           || ( ! get_option( 'permalink_structure' )
		                && isset( $_GET[ $this->new_login_slug() ] )
		                && empty( $_GET[ $this->new_login_slug() ] ) ) ) {

			$_SERVER['SCRIPT_NAME'] = $this->new_login_slug();

			$pagenow = 'wp-login.php';

		} elseif ( ( strpos( rawurldecode( $_SERVER['REQUEST_URI'] ), 'wp-register.php' ) !== false
		             || ( isset( $request['path'] ) && untrailingslashit( $request['path'] ) === site_url( 'wp-register', 'relative' ) ) )
		           && ! is_admin() ) {

			$this->wp_login_php = true;

			$_SERVER['REQUEST_URI'] = $this->user_trailingslashit( '/' . str_repeat( '-/', 10 ) );

			$pagenow = 'index.php';
		}

	}

	public function setup_theme() {
		global $pagenow;

		if ( ! is_user_logged_in() && 'customize.php' === $pagenow ) {
			wp_die( __( 'This has been disabled', 'publishrank' ), 403 );
		}
	}

	public function wp_loaded() {
		global $pagenow;

		$request = parse_url( rawurldecode( $_SERVER['REQUEST_URI'] ) );

		do_action( 'publishrank_hide_login_before_redirect', $request );

		if ( ! ( isset( $_GET['action'] ) && $_GET['action'] === 'postpass' && isset( $_POST['post_password'] ) ) ) {

			if ( is_admin() && ! is_user_logged_in() && ! defined( 'WP_CLI' ) && ! defined( 'DOING_AJAX' ) && ! defined( 'DOING_CRON' ) && $pagenow !== 'admin-post.php' && $request['path'] !== '/wp-admin/options.php' ) {
				wp_safe_redirect( $this->new_redirect_url() );
				die();
			}

			if ( ! is_user_logged_in() && isset( $_GET['wc-ajax'] ) && $pagenow === 'profile.php' ) {
				wp_safe_redirect( $this->new_redirect_url() );
				die();
			}

			if ( ! is_user_logged_in() && isset( $request['path'] ) && $request['path'] === '/wp-admin/options.php' ) {
				header( 'Location: ' . $this->new_redirect_url() );
				die;
			}

			if ( $pagenow === 'wp-login.php' && isset( $request['path'] ) && $request['path'] !== $this->user_trailingslashit( $request['path'] ) && get_option( 'permalink_structure' ) ) {
				wp_safe_redirect( $this->user_trailingslashit( $this->new_login_url() )
				                  . ( ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . $_SERVER['QUERY_STRING'] : '' ) );

				die;

			} elseif ( $this->wp_login_php ) {

				if ( ( $referer = wp_get_referer() )
				     && strpos( $referer, 'wp-activate.php' ) !== false
				     && ( $referer = parse_url( $referer ) )
				     && ! empty( $referer['query'] ) ) {

					parse_str( $referer['query'], $referer );

					@require_once WPINC . '/ms-functions.php';

					if ( ! empty( $referer['key'] )
					     && ( $result = wpmu_activate_signup( $referer['key'] ) )
					     && is_wp_error( $result )
					     && ( $result->get_error_code() === 'already_active'
					          || $result->get_error_code() === 'blog_taken' ) ) {

						wp_safe_redirect( $this->new_login_url()
						                  . ( ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . $_SERVER['QUERY_STRING'] : '' ) );

						die;

					}

				}

				$this->wp_template_loader();

			} elseif ( $pagenow === 'wp-login.php' ) {
				global $error, $interim_login, $action, $user_login;

				$redirect_to = admin_url();

				$requested_redirect_to = '';
				if ( isset( $_REQUEST['redirect_to'] ) ) {
					$requested_redirect_to = $_REQUEST['redirect_to'];
				}

				if ( is_user_logged_in() ) {
					$user = wp_get_current_user();
					if ( ! isset( $_REQUEST['action'] ) ) {
						$logged_in_redirect = apply_filters( 'publishrank_hide_login_logged_in_redirect', $redirect_to, $requested_redirect_to, $user );
						wp_safe_redirect( $logged_in_redirect );
						die();
					}
				}

				@require_once ABSPATH . 'wp-login.php';

				die;

			}

		}

	}

	public function site_url( $url, $path, $scheme, $blog_id ) {
		return $this->filter_wp_login_php( $url, $scheme );
	}

	public function network_site_url( $url, $path, $scheme ) {
		return $this->filter_wp_login_php( $url, $scheme );
	}

	public function wp_redirect( $location, $status ) {
		if ( strpos( $location, 'https://wordpress.com/wp-login.php' ) !== false ) {
			return $location;
		}

		return $this->filter_wp_login_php( $location );
	}

	public function filter_wp_login_php( $url, $scheme = null ) {
		global $pagenow;

		$origin_url = $url;

		if ( strpos( $url, 'wp-login.php?action=postpass' ) !== false ) {
			return $url;
		}

		if ( is_multisite() && 'install.php' === $pagenow ) {
			return $url;
		}

		/*if ( strpos( $url, 'wp-admin/?action=postpass' ) === false ) {
			return $url;
		}*/

		if ( strpos( $url, 'wp-login.php' ) !== false && strpos( wp_get_referer(), 'wp-login.php' ) === false ) {

			if ( is_ssl() ) {
				$scheme = 'https';
			}

			$args = explode( '?', $url );

			if ( isset( $args[1] ) ) {

				parse_str( $args[1], $args );

				if ( isset( $args['login'] ) ) {
					$args['login'] = rawurlencode( $args['login'] );
				}

				$url = add_query_arg( $args, $this->new_login_url( $scheme ) );

			} else {

				$url = $this->new_login_url( $scheme );

			}

		}

		if ( isset( $_POST['post_password'] ) ) {
			global $current_user;
			if ( ! is_user_logged_in() && is_wp_error( wp_authenticate_username_password( null, $current_user->user_login, $_POST['post_password'] ) ) ) {
				return $origin_url;
			}
		}

		if ( ! is_user_logged_in() ) {
			if ( file_exists( WP_CONTENT_DIR . '/plugins/gravityforms/gravityforms.php' ) && isset( $_GET['gf_page'] ) ) {
				return $origin_url;
			}
		}

		return $url;
	}

	public function welcome_email( $value ) {
		return $value = str_replace( 'wp-login.php', trailingslashit( get_site_option( 'whl_page', 'login' ) ), $value );
	}

	public function forbidden_slugs() {

		return self::reserved_slugs();

	}

	/** Query variables WordPress reserves; a login address may not use them. @return string[] */
	public static function reserved_slugs() {

		$wp = new \WP;

		return array_merge( $wp->public_query_vars, $wp->private_query_vars );

	}

	/**
	 * Validate and normalise a private login address.
	 *
	 * @param mixed $raw Submitted value.
	 * @return string Clean slug, or '' when it is empty or reserved by WordPress.
	 */
	public static function clean_login_slug( $raw ) {

		$slug = sanitize_title_with_dashes( is_string( $raw ) ? $raw : '' );

		if ( '' === $slug || false !== strpos( $slug, 'wp-login' ) || false !== strpos( $slug, 'wp-admin' ) || in_array( $slug, self::reserved_slugs(), true ) ) {
			return '';
		}

		return $slug;

	}

	/** Clean the redirect address for blocked visitors; an empty value means the 404 page. */
	public static function clean_redirect_slug( $raw ) {

		$slug = sanitize_title_with_dashes( is_string( $raw ) ? $raw : '' );

		return '' === $slug ? '404' : $slug;

	}

	/** Current saved login slug (defaults to "login"). */
	public static function current_login_slug() {

		$slug = get_option( 'whl_page' );

		return $slug ? (string) $slug : 'login';

	}

	/** Current saved redirect slug (defaults to "404"). */
	public static function current_redirect_slug() {

		$slug = get_option( 'whl_redirect_admin' );

		return $slug ? (string) $slug : '404';

	}

	/** Rewrite rules are refreshed once after the login address changes. */
	public static function flush_after_change() {

		flush_rewrite_rules( true );

	}

	/**
	 * Load scripts
	 */
	public function admin_enqueue_scripts( $hook ) {
		if ( 'options-general.php' != $hook ) {
			return false;
		}

		wp_enqueue_style( 'plugin-install' );

		wp_enqueue_script( 'plugin-install' );
		wp_enqueue_script( 'updates' );
		add_thickbox();
	}

	public function publishrank_hide_login_menu_page() {
		$title   = __( 'Hide Login', 'publishrank' );
		$label   = $title;
		$user_id = get_current_user_id();
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'publishrank-wp-hide-login' === $page && $user_id && current_user_can( 'manage_options' ) ) {
			update_user_meta( $user_id, self::META_SEEN, 1 );
		}
		if ( $user_id && current_user_can( 'manage_options' ) && ! get_user_meta( $user_id, self::META_SEEN, true ) ) {
			$label .= ' <span class="pr-menu-new">' . esc_html__( 'New', 'publishrank' ) . '</span>';
		}
		add_submenu_page( 'publishrank-seo-dashboard', $title, $label, 'manage_options', 'publishrank-wp-hide-login', array(
			$this,
			'settings_page'
		), 12 );
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_hide_login_save' );
		$page     = self::clean_login_slug( isset( $_POST['whl_page'] ) ? wp_unslash( $_POST['whl_page'] ) : '' );
		$redirect = self::clean_redirect_slug( isset( $_POST['whl_redirect_admin'] ) ? wp_unslash( $_POST['whl_redirect_admin'] ) : '' );
		if ( '' === $page ) {
			$this->flash_and_return( 'error', __( 'No changes were saved. Please choose a different login address; this one is reserved by WordPress.', 'publishrank' ) );
		}
		update_option( 'whl_page', $page );
		update_option( 'whl_redirect_admin', $redirect );
		self::confirm_login_address();
		/* translators: %s: new login URL. */
		$this->flash_and_return( 'success', sprintf( __( 'Saved. Your login page is now %s — bookmark it!', 'publishrank' ), $this->new_login_url() ) );
	}

	private function flash_and_return( $type, $message ) {
		if ( class_exists( '\\PublishRankSEO\\Admin\\AdminAssets' ) && method_exists( '\\PublishRankSEO\\Admin\\AdminAssets', 'done' ) ) {
			\PublishRankSEO\Admin\AdminAssets::done( 'publishrank-wp-hide-login', $type, $message );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=publishrank-wp-hide-login' ) );
		exit;
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pretty    = (bool) get_option( 'permalink_structure' );
		$prefix    = trailingslashit( home_url() ) . ( $pretty ? '' : '?' );
		$suffix    = ( $pretty && $this->use_trailing_slashes() ) ? '/' : '';
		$login_url = $this->new_login_url();
		$has_ui    = class_exists( '\\PublishRankSEO\\Admin\\AdminAssets' );
		?>
		<div class="wrap prtg-wrap">
			<?php
			if ( $has_ui ) {
				\PublishRankSEO\Admin\AdminAssets::header( __( 'Hide Login', 'publishrank' ), __( 'Move the WordPress login page to a private address and keep wp-login.php and wp-admin hidden from visitors.', 'publishrank' ) );
				\PublishRankSEO\Admin\AdminAssets::render_flash();
			} else {
				echo '<h1>' . esc_html__( 'Hide Login', 'publishrank' ) . '</h1>';
			}
			?>
			<?php if ( self::disabled_by_constant() ) : ?>
			<div class="prtg-notice prtg-notice--off" role="alert">
				<span class="prtg-notice__icon fa-solid fa-triangle-exclamation" aria-hidden="true"></span>
				<div><strong><?php esc_html_e( 'Hide Login is disabled by wp-config.php.', 'publishrank' ); ?></strong><p><?php esc_html_e( 'The constant PUBLISHRANK_DISABLE_HIDE_LOGIN is set to true, so the standard WordPress login (wp-login.php and wp-admin) works as normal. Remove that line from wp-config.php to use your private login address again.', 'publishrank' ); ?></p></div>
			</div>
			<?php elseif ( self::is_deferred() ) : ?>
			<div class="prtg-notice prtg-notice--off" role="note">
				<span class="prtg-notice__icon fa-solid fa-hourglass-half" aria-hidden="true"></span>
				<div><strong><?php esc_html_e( 'Hide Login is waiting for you.', 'publishrank' ); ?></strong><p><?php echo esc_html( sprintf( /* translators: %s: login URL. */ __( 'Your sign-in page has not moved yet. When you save below, it moves to %s. Copy that address first.', 'publishrank' ), $login_url ) ); ?></p></div>
			</div>
			<?php elseif ( ! self::enabled() ) : ?>
			<div class="prtg-notice prtg-notice--off" role="note">
				<span class="prtg-notice__icon fa-solid fa-toggle-off" aria-hidden="true"></span>
				<div><strong><?php esc_html_e( 'Hide Login is turned off.', 'publishrank' ); ?></strong><p><?php esc_html_e( 'The standard WordPress login (wp-login.php and wp-admin) works as normal. Your address below is saved but not in use.', 'publishrank' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=publishrank-modules' ) ); ?>"><?php esc_html_e( 'Turn it on under Features', 'publishrank' ); ?></a></p></div>
			</div>
			<?php else : ?>
			<div class="prtg-notice" role="note">
				<span class="prtg-notice__icon fa-solid fa-key" aria-hidden="true"></span>
				<div><strong><?php esc_html_e( 'Your login page is now here:', 'publishrank' ); ?> <a href="<?php echo esc_url( $login_url ); ?>"><?php echo esc_html( $login_url ); ?></a></strong><p><?php esc_html_e( 'Bookmark this address. Visitors who are not signed in can no longer open wp-login.php, wp-register.php or the wp-admin folder.', 'publishrank' ); ?></p></div>
			</div>
			<?php endif; ?>
			<form class="prtg-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="publishrank_hide_login_save" />
				<?php wp_nonce_field( 'publishrank_hide_login_save' ); ?>
					<div class="prtg-grid">
						<section class="prtg-card prtg-card--primary">
							<div class="prtg-card__heading"><span class="prtg-icon prtg-icon--plum fa-solid fa-right-to-bracket" aria-hidden="true"></span><div><p class="prtg-kicker"><?php esc_html_e( 'Login address', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Login URL', 'publishrank' ); ?></h2></div></div>
							<label for="whl_page"><strong><?php esc_html_e( 'Your private login address', 'publishrank' ); ?></strong></label>
							<div class="prtg-url-composer"><code class="prtg-url-composer__prefix"><?php echo esc_html( $prefix ); ?></code><input id="whl_page" type="text" name="whl_page" value="<?php echo esc_attr( $this->new_login_slug() ); ?>" required /><?php if ( $suffix ) : ?><code class="prtg-url-composer__suffix">/</code><?php endif; ?></div>
							<p class="description"><?php esc_html_e( 'Protect your website by changing the login URL and preventing access to the wp-login.php page and the wp-admin directory to non-connected people.', 'publishrank' ); ?></p>
						</section>
						<section class="prtg-card">
							<div class="prtg-card__heading"><span class="prtg-icon prtg-icon--rose fa-solid fa-route" aria-hidden="true"></span><div><p class="prtg-kicker"><?php esc_html_e( 'Blocked visitors', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Redirection URL', 'publishrank' ); ?></h2></div></div>
							<label for="whl_redirect_admin"><strong><?php esc_html_e( 'Send blocked visitors to', 'publishrank' ); ?></strong></label>
							<div class="prtg-url-composer"><code class="prtg-url-composer__prefix"><?php echo esc_html( $prefix ); ?></code><input id="whl_redirect_admin" type="text" name="whl_redirect_admin" value="<?php echo esc_attr( $this->new_redirect_slug() ); ?>" /><?php if ( $suffix ) : ?><code class="prtg-url-composer__suffix">/</code><?php endif; ?></div>
							<p class="description"><?php esc_html_e( 'Redirect URL when someone tries to access the wp-login.php page and the wp-admin directory while not logged in. Leave 404 to show your not-found page.', 'publishrank' ); ?></p>
					</section>
					<section class="prtg-card">
						<div class="prtg-card__heading"><span class="prtg-icon prtg-icon--sage fa-solid fa-shield-halved" aria-hidden="true"></span><div><p class="prtg-kicker"><?php esc_html_e( 'What is protected', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Active protections', 'publishrank' ); ?></h2></div></div>
						<ul>
							<li><span class="fa-solid fa-check" aria-hidden="true"></span> <?php esc_html_e( 'wp-login.php and wp-register.php are hidden', 'publishrank' ); ?></li>
							<li><span class="fa-solid fa-check" aria-hidden="true"></span> <?php esc_html_e( 'wp-admin redirects visitors who are not signed in', 'publishrank' ); ?></li>
							<li><span class="fa-solid fa-check" aria-hidden="true"></span> <?php esc_html_e( 'wp-signup and wp-activate are blocked on single sites', 'publishrank' ); ?></li>
							<li><span class="fa-solid fa-check" aria-hidden="true"></span> <?php esc_html_e( 'Login links in emails and menus use your new address', 'publishrank' ); ?></li>
							<li><span class="fa-solid fa-check" aria-hidden="true"></span> <?php esc_html_e( 'The Customizer is blocked for visitors who are not signed in', 'publishrank' ); ?></li>
						</ul>
						<p class="description"><?php esc_html_e( 'Hiding the login page is not a replacement for strong passwords and two-factor sign-in.', 'publishrank' ); ?></p>
					</section>
				</div>
				<p class="submit"><button type="submit" class="button button-primary"><span class="fa-solid fa-floppy-disk" aria-hidden="true"></span> <?php esc_html_e( 'Save login settings', 'publishrank' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public function whl_template_redirect() {
		if ( ! empty( $_GET ) && isset( $_GET['page'] ) && 'whl_settings' === $_GET['page'] ) {
			wp_redirect( admin_url( 'admin.php?page=publishrank-wp-hide-login' ) );
			exit();
		}
	}

	/**
	 *
	 * Update url redirect : wp-admin/options.php
	 *
	 * @param $login_url
	 * @param $redirect
	 * @param $force_reauth
	 *
	 * @return string
	 */
	public function login_url( $login_url, $redirect, $force_reauth ) {
		if ( is_404() ) {
			return '#';
		}

		if ( $force_reauth === false ) {
			return $login_url;
		}

		if ( empty( $redirect ) ) {
			return $login_url;
		}

		$redirect = explode( '?', $redirect );

		if ( $redirect[0] === admin_url( 'options.php' ) ) {
			$login_url = admin_url();
		}

		return $login_url;
	}

	/**
	 * Handles Ajax request to persist notices dismissal.
	 */
	public function dismiss_admin_notice() {
		check_ajax_referer( 'publishrank-hide-login-dismissible-notice' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		// Only the notices this module actually shows may be dismissed.
		$allowed = array(
			'disable-notice-warning-comments',
			'disable-notice-warning-comment-registration',
		);

		$option_name        = isset( $_POST['option_name'] ) ? sanitize_text_field( wp_unslash( $_POST['option_name'] ) ) : '';
		$dismissible_length = isset( $_POST['dismissible_length'] ) ? sanitize_text_field( wp_unslash( $_POST['dismissible_length'] ) ) : '';

		if ( ! in_array( $option_name, $allowed, true ) ) {
			wp_die( '', '', array( 'response' => 400 ) );
		}

		$transient = 0;
		if ( 'forever' !== $dismissible_length ) {
			// If $dismissible_length is not an integer default to 1
			$days               = max( 1, absint( $dismissible_length ) );
			$transient          = $days * DAY_IN_SECONDS;
			$dismissible_length = strtotime( $days . ' days' );
		}
		set_site_transient( $option_name, $dismissible_length, $transient );
		wp_die();
	}

	/**
	 * Load scripts
	 */
	public function admin_enqueue_scripts_notifs( $hook ) {

		/*if ( 'options-general.php' != $hook ) {
			return false;
		}*/

		wp_enqueue_script( 'publishrank-hide-login', PUBLISHRANK_SEO_URL . 'assets/js/hide-login.js', array(
			'jquery',
		), false, true );

		wp_localize_script(
			'publishrank-hide-login',
			'dismissible_notice',
			array(
				'nonce' => wp_create_nonce( 'publishrank-hide-login-dismissible-notice' ),
			)
		);
	}

	public function warning_options_discussion() {
		if ( ! self::is_admin_notice_active( 'disable-notice-warning-comments' ) ) {
			return false;
		}

		$current_screen = get_current_screen();

		// Vérifie si la page actuelle est options-discussion.php
		if ( $current_screen && $current_screen->id === 'options-discussion' ) :
			/*if ( function_exists( 'wp_admin_notice' ) ) :
				wp_admin_notice(
					__( 'PublishRank Hide Login: Please note, if you check the comment_registration option "Users must be registered and logged in to comment", the login link will not be hidden on the comment block.', 'publishrank' ),
					array(
						'type'        => 'warning',
						'dismissible' => true,
						'attributes'  => array( 'data-slug' => 'publishrank' )
					)
				);
			else :*/ ?>
            <div class="publishrank-updates notice notice-warning is-dismissible"
                 data-dismissible="disable-notice-warning-comments-forever">
                <p><?php _e( 'PublishRank Hide Login: Please note, if you check the comment_registration option "Users must be registered and logged in to comment", the login link will not be hidden on the comment block.', 'publishrank' ); ?></p>
            </div>
		<?php
			//endif;
		endif;
	}

	public function warning_notice_for_comment_registration() {
		if ( ! self::is_admin_notice_active( 'disable-notice-warning-comment-registration' ) ) {
			return false;
		}

		$comment_registration_option = get_option( 'comment_registration' );

		if ( $comment_registration_option == '1' ) :
			/*if ( function_exists( 'wp_admin_notice' ) ) :
				wp_admin_notice(
					__( 'PublishRank Hide Login: Please note that the comment_registration option “Users must be registered and logged in to comment” is activated on your site, the connection link will not be hidden on the comments block.', 'publishrank' ),
					array(
						'type'        => 'warning',
						'dismissible' => true,
						'attributes'  => array( 'data-slug' => 'publishrank' )
					)
				);
			else :*/ ?>
            <div class="publishrank-updates notice notice-warning is-dismissible"
                 data-dismissible="disable-notice-warning-comment-registration-forever">
                <p><?php _e( 'PublishRank Hide Login: Please note that the comment_registration option “Users must be registered and logged in to comment” is activated on your site, the connection link will not be hidden on the comments block.', 'publishrank' ); ?></p>
            </div>
		<?php
			//endif;
		endif;
	}

	/**
	 * Is admin notice active?
	 *
	 * @param string $arg data-dismissible content of notice.
	 *
	 * @return bool
	 */
	public static function is_admin_notice_active( $arg ) {
		$array       = explode( '-', $arg );
		$option_name = implode( '-', $array );
		$db_record   = get_site_transient( $option_name );

		if ( 'forever' == $db_record ) {
			return false;
		} elseif ( absint( $db_record ) >= time() ) {
			return false;
		} else {
			return true;
		}
	}

	public function manage_sites_action_links( $actions, $blog_id, $blogname ) {

		$actions['backend'] = sprintf(
			'<a href="%1$s" class="edit">%2$s</a>',
			esc_url( get_site_url( $blog_id, $this->new_login_slug() ) ),
			__( 'Dashboard' )
		);

		return $actions;
	}

	/**
	 * Block access to wp-signup.php and wp-activate.php on non-multisite installations.
	 * This runs on 'init' hook to ensure translations are loaded.
	 */
	public function init_block_access() {
		if ( ! is_multisite()
		     && ( strpos( rawurldecode( $_SERVER['REQUEST_URI'] ), 'wp-signup' ) !== false
		          || strpos( rawurldecode( $_SERVER['REQUEST_URI'] ), 'wp-activate' ) !== false ) && apply_filters( 'publishrank_hide_login_signup_enable', false ) === false ) {

			wp_die( __( 'This feature is not enabled.', 'publishrank' ) );

		}
	}
}
