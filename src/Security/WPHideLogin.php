<?php
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Optional custom login endpoint and custom administration path for PublishRank.
 * This feature obscures default paths; it does not replace authentication or authorization.
 */
final class WPHideLogin {
	const OPTION = 'publishrank_wp_hide_login';
	const QUERY_VAR = 'publishrank_custom_login';
	const NONCE_ACTION = 'publishrank_wp_hide_login_save';
	const RULES_VERSION = '3';

	public function register() {
		add_action( 'init', array( $this, 'register_rewrite_rule' ) );
		add_action( 'init', array( $this, 'maybe_refresh_rewrite_rules' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'site_url', array( $this, 'filter_login_url' ), 10, 4 );
		add_filter( 'network_site_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'admin_url', array( $this, 'filter_admin_url' ), 10, 3 );
		add_filter( 'network_admin_url', array( $this, 'filter_admin_url' ), 10, 3 );
		add_filter( 'user_admin_url', array( $this, 'filter_admin_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'filter_admin_redirect' ), 10, 2 );
		add_filter( 'mod_rewrite_rules', array( $this, 'filter_mod_rewrite_rules' ), 20 );
		add_action( 'template_redirect', array( $this, 'serve_custom_login' ), 0 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_post_publishrank_wp_hide_login_save', array( $this, 'save_settings' ) );
		}
		// PublishRank boots on plugins_loaded; run the PHP fallback now because that
		// hook may already have passed. Apache rules remain the earliest protection.
		$this->protect_default_login();
		$this->protect_default_admin_path();
	}

	public static function defaults() {
		return array( 'enabled' => 0, 'slug' => 'secure-login' );
	}

	public static function settings() {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public function register_rewrite_rule() {
		global $wp_rewrite;
		// Remove a previous PublishRank login rule so changing the slug truly retires it.
		if ( isset( $wp_rewrite->extra_rules_top ) && is_array( $wp_rewrite->extra_rules_top ) ) {
			foreach ( $wp_rewrite->extra_rules_top as $regex => $query ) {
				if ( is_string( $query ) && false !== strpos( $query, self::QUERY_VAR . '=1' ) ) {
					unset( $wp_rewrite->extra_rules_top[ $regex ] );
				}
			}
		}
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		$slug = $this->sanitize_slug( $settings['slug'] );
		if ( '' !== $slug ) {
			add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}
	}

	/** Persist the Apache rules too; a soft flush only refreshes the WordPress option. */
	public function maybe_refresh_rewrite_rules() {
		if ( self::RULES_VERSION !== get_option( 'publishrank_wp_hide_login_rules_version' ) ) {
			flush_rewrite_rules( true );
			update_option( 'publishrank_wp_hide_login_rules_version', self::RULES_VERSION, false );
			update_option( 'publishrank_wp_hide_login_apache_rules_active', $this->apache_rules_installed() && ! empty( self::settings()['enabled'] ) ? 1 : 0, false );
		}
	}

	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Rewrite WordPress-generated administration URLs to /{slug}/admin.php, etc.
	 * The bare admin base becomes /{slug}/index.php because /{slug}/ is the login page.
	 */
	public function filter_admin_url( $url, $path = '', $blog_id = null ) {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || ! $this->dashboard_rules_active() ) {
			return $url;
		}
		$slug = $this->sanitize_slug( $settings['slug'] );
		if ( '' === $slug ) {
			return $url;
		}
		$url = preg_replace( '~(?<=/)wp-admin(?=/|$)~', $slug, (string) $url, 1 );
		if ( '' === (string) $path || '/' === (string) $path || 0 === strpos( (string) $path, '?' ) ) {
			$url = preg_replace( '~/' . preg_quote( $slug, '~' ) . '/?(?=([?#]|$))~', '/' . $slug . '/index.php', $url, 1 );
		}
		return $url;
	}

	/**
	 * Rewrite same-site redirects that still point at /wp-admin/. Avoid modifying
	 * external URLs, and ensure the dashboard base never collides with the login URL.
	 */
	public function filter_admin_redirect( $location, $status ) {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || ! $this->dashboard_rules_active() || ! is_string( $location ) || '' === $location ) {
			return $location;
		}
		$slug = $this->sanitize_slug( $settings['slug'] );
		if ( '' === $slug ) {
			return $location;
		}
		$target_host = wp_parse_url( $location, PHP_URL_HOST );
		$site_host   = wp_parse_url( site_url(), PHP_URL_HOST );
		if ( $target_host && $site_host && strtolower( $target_host ) !== strtolower( $site_host ) ) {
			return $location;
		}
		if ( ! preg_match( '~(?<=/)wp-admin(?=/|$)~', $location ) ) {
			return $location;
		}
		$location = preg_replace( '~(?<=/)wp-admin(?=/|$)~', $slug, $location, 1 );
		return preg_replace( '~/' . preg_quote( $slug, '~' ) . '/?(?=([?#]|$))~', '/' . $slug . '/index.php', $location, 1 );
	}

	/** Add front-controller rules before WordPress' normal catch-all rules. */
	public function filter_mod_rewrite_rules( $rules ) {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || ! is_string( $rules ) || '' === $rules || false !== strpos( $rules, '# BEGIN PublishRank WP Hide Login Dashboard' ) ) {
			return $rules;
		}
		$slug = $this->sanitize_slug( $settings['slug'] );
		if ( '' === $slug ) {
			return $rules;
		}
		$slug_rule = preg_quote( $slug, '#' );
		$custom  = "# BEGIN PublishRank WP Hide Login Dashboard\n";
		// THE_REQUEST distinguishes a visitor's original URL from an internal rewrite.
		$custom .= "RewriteCond %{THE_REQUEST} \\s/+[^?\\s]*wp-admin(?:/|\\?|\\s) [NC]\n";
		$custom .= "RewriteRule ^wp-admin(?:/.*)?$ - [R=404,L]\n";
		$custom .= "RewriteRule ^" . $slug_rule . "/(.+)$ wp-admin/$1 [QSA,L]\n";
		$custom .= "# END PublishRank WP Hide Login Dashboard\n";

		if ( preg_match( '/RewriteBase [^\r\n]*\r?\n/', $rules ) ) {
			return preg_replace_callback( '/RewriteBase [^\r\n]*\r?\n/', static function ( $match ) use ( $custom ) { return $match[0] . $custom; }, $rules, 1 );
		}
		if ( preg_match( '/RewriteEngine On\r?\n/i', $rules ) ) {
			return preg_replace_callback( '/RewriteEngine On\r?\n/i', static function ( $match ) use ( $custom ) { return $match[0] . $custom; }, $rules, 1 );
		}
		return $rules;
	}

	/** Rewrite WordPress-generated login links, including reset and logout links. */
	public function filter_login_url( $url, $path = '', $scheme = null, $blog_id = null ) {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return $url;
		}
		$slug = $this->sanitize_slug( $settings['slug'] );
		if ( '' === $slug || false === strpos( (string) $url, 'wp-login.php' ) ) {
			return $url;
		}
		return preg_replace( '~wp-login\.php(?=([?#]|$))~', $slug . '/', (string) $url, 1 );
	}

	public function serve_custom_login() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'Not Found', 'publishrank' ), esc_html__( 'Not Found', 'publishrank' ), array( 'response' => 404 ) );
		}
		// Reuse the WordPress core controller so normal authentication flows remain intact.
		if ( ! defined( 'PUBLISHRANK_CUSTOM_LOGIN_REQUEST' ) ) {
			define( 'PUBLISHRANK_CUSTOM_LOGIN_REQUEST', true );
		}
		$_SERVER['SCRIPT_NAME'] = '/wp-login.php';
		$_SERVER['PHP_SELF']    = '/wp-login.php';
		require ABSPATH . 'wp-login.php';
		exit;
	}

	/** Return a real 404 for direct requests to the standard login script. */
	public function protect_default_login() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || defined( 'PUBLISHRANK_CUSTOM_LOGIN_REQUEST' ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) $_SERVER['SCRIPT_NAME'] ) : '';
		if ( 'wp-login.php' !== $script ) {
			return;
		}
		$this->not_found();
	}

	/**
	 * Fallback for hosts where the Apache 404 rule cannot be written. Internal
	 * rewrites from /{slug}/file.php retain the custom REQUEST_URI and are allowed.
	 */
	public function protect_default_admin_path() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		// Allow one request to complete the rules migration after an upgrade.
		if ( self::RULES_VERSION !== get_option( 'publishrank_wp_hide_login_rules_version' ) || ! $this->dashboard_rules_active() ) {
			return;
		}
		$request_path = wp_parse_url( rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		if ( ! is_string( $request_path ) ) {
			return;
		}
		$install_path = wp_parse_url( site_url(), PHP_URL_PATH );
		$install_path = is_string( $install_path ) ? untrailingslashit( $install_path ) : '';
		if ( '' !== $install_path && 0 === strpos( $request_path, $install_path . '/' ) ) {
			$request_path = substr( $request_path, strlen( $install_path ) );
		}
		if ( preg_match( '~^/wp-admin(?:/|$)~i', $request_path ) ) {
			$this->not_found();
		}
	}

	/** True only when the generated Apache rules are present and the feature is enabled. */
	private function dashboard_rules_active() {
		return ! empty( self::settings()['enabled'] ) && 1 === (int) get_option( 'publishrank_wp_hide_login_apache_rules_active', 0 );
	}

	/** Check the actual .htaccess file rather than assuming a hard flush succeeded. */
	private function apache_rules_installed() {
		if ( ! function_exists( 'get_home_path' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$home_path = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$file      = trailingslashit( $home_path ) . '.htaccess';
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$contents = file_get_contents( $file );
		return is_string( $contents ) && false !== strpos( $contents, '# BEGIN PublishRank WP Hide Login Dashboard' );
	}

	private function not_found() {
		status_header( 404 );
		nocache_headers();
		wp_die( esc_html__( 'Not Found', 'publishrank' ), esc_html__( 'Not Found', 'publishrank' ), array( 'response' => 404 ) );
	}

	public function admin_menu() {
		add_submenu_page(
			'publishrank-seo-dashboard',
			__( 'WP Hide Login', 'publishrank' ),
			__( 'WP Hide Login', 'publishrank' ),
			'manage_options',
			'publishrank-wp-hide-login',
			array( $this, 'render_page' )
		);
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'publishrank' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );
		$enabled = isset( $_POST['enabled'] ) ? 1 : 0;
		$slug = isset( $_POST['slug'] ) ? $this->sanitize_slug( wp_unslash( $_POST['slug'] ) ) : '';
		if ( '' === $slug || in_array( $slug, array( 'wp-admin', 'wp-login.php', 'wp-content', 'wp-includes', 'admin', 'login' ), true ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'publishrank-wp-hide-login', 'pr_error' => 'slug' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$old = self::settings();
		$new = array( 'enabled' => $enabled, 'slug' => $slug );
		update_option( self::OPTION, $new, false );
		$this->register_rewrite_rule();
		if ( $old['slug'] !== $slug || (int) $old['enabled'] !== $enabled ) {
			flush_rewrite_rules( true );
		}
		update_option( 'publishrank_wp_hide_login_apache_rules_active', $enabled && $this->apache_rules_installed() ? 1 : 0, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'publishrank-wp-hide-login', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::settings();
		$slug = $this->sanitize_slug( $settings['slug'] );
		$url = home_url( '/' . $slug . '/' );
		$admin_settings_url = home_url( '/' . $slug . '/admin.php?page=publishrank-wp-hide-login' );
		echo '<div class="wrap"><h1>' . esc_html__( 'WP Hide Login', 'publishrank' ) . '</h1>';
		echo '<p>' . esc_html__( 'Change the public login URL and route administration scripts through the same custom slug. Apache mod_rewrite and writable WordPress rewrite rules are required for the strongest direct-path 404 behavior.', 'publishrank' ) . '</p>';
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Login URL settings saved.', 'publishrank' ) . '</p></div>';
		}
		if ( isset( $_GET['pr_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Please choose a valid, non-reserved URL slug.', 'publishrank' ) . '</p></div>';
		}
		if ( ! empty( $settings['enabled'] ) && ! $this->dashboard_rules_active() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Custom login is enabled, but PublishRank could not confirm that its Apache dashboard rewrite rules were written. To prevent locking you out, dashboard links will continue using the standard WordPress path until the server supports and saves the required .htaccess rules. Check Apache mod_rewrite and file permissions, then save these settings again.', 'publishrank' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="publishrank_wp_hide_login_save">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Enable custom login and administration URLs', 'publishrank' ) . '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( 1, (int) $settings['enabled'], false ) . '> ' . esc_html__( 'Enable WP Hide Login', 'publishrank' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="publishrank-login-slug">' . esc_html__( 'Custom URL slug', 'publishrank' ) . '</label></th><td><code>' . esc_html( home_url( '/' ) ) . '</code><input id="publishrank-login-slug" name="slug" type="text" class="regular-text" value="' . esc_attr( $settings['slug'] ) . '" pattern="[A-Za-z0-9_-]+" required><p class="description">' . esc_html__( 'The same slug is used for the login page and administration scripts. The login page is /slug/; dashboard files use /slug/admin.php, /slug/index.php, and similar paths.', 'publishrank' ) . '</p>';
		if ( ! empty( $settings['enabled'] ) ) {
			echo '<p><strong>' . esc_html__( 'Custom login URL:', 'publishrank' ) . '</strong> <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
			echo '<p><strong>' . esc_html__( 'PublishRank settings URL:', 'publishrank' ) . '</strong> <a href="' . esc_url( $admin_settings_url ) . '">' . esc_html( $admin_settings_url ) . '</a></p>';
		}
		echo '</td></tr></tbody></table>';
		submit_button( __( 'Save Login Settings', 'publishrank' ) );
		echo '</form>';
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Important:', 'publishrank' ) . '</strong> ' . esc_html__( 'Changing URLs is not a replacement for strong passwords, multi-factor authentication, or rate limiting. Test login, logout, password recovery, AJAX, assets, and all admin pages on staging. If the custom URL stops working, disable this feature by setting enabled to 0 in the publishrank_wp_hide_login option using database tools or temporarily disabling PublishRank.', 'publishrank' ) . '</p></div></div>';
	}

	private function sanitize_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug, " /\t\n\r\0\x0B" ) );
		return preg_replace( '/[^a-z0-9_-]/', '', $slug );
	}
}
