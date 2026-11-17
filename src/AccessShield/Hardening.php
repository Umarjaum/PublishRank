<?php
/**
 * WordPress hardening ("hide WordPress"): removes software fingerprints, closes information leaks,
 * adds security headers, an optional request firewall, REST restrictions, server rules for static
 * files that PHP cannot protect (written to .htaccess between markers, or shown as an nginx snippet),
 * and a scored security audit with loopback probes.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

final class Hardening {
	const OPTION = 'publishrank_hardening';
	const MARKER = 'PublishRank Access Shield';

	/** @var array|null */
	private static $cache = null;

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'hide_wp_version'        => 1,
			'obfuscate_asset_ver'    => 0,
			'hide_plugin_versions'   => 1,
			'remove_rsd_wlw'         => 1,
			'remove_shortlink'       => 0,
			'remove_rest_discovery'  => 0,
			'remove_oembed_discovery' => 0,
			'remove_x_powered_by'    => 1,
			'disable_file_editor'    => 1,
			'disable_pingbacks'      => 0,
			'restrict_rest'          => 0,
			'rest_allow'             => "oembed/1.0\npublishrank/v1\ncontact-form-7/v1\nwc/store\njetpack/v4",
			'firewall'               => 1,
			'header_frame'           => 'SAMEORIGIN',
			'header_permissions'     => 1,
			'header_hsts'            => 0,
			'hsts_max_age'           => 31536000,
			'header_coop'            => 0,
			'header_csp_basic'       => 0,
			'admin_noindex'          => 1,
			'disable_user_sitemap'   => 1,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array() );
		}
		return self::$cache;
	}

	public static function on( $key ) {
		$a = self::all();
		return ! empty( $a[ $key ] );
	}

	public static function save( array $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $key => $default ) {
			if ( 'rest_allow' === $key ) {
				$lines = array();
				foreach ( preg_split( '/[\r\n,]+/', (string) ( $in[ $key ] ?? '' ) ) as $ns ) {
					$ns = trim( preg_replace( '#[^a-z0-9_\-/.]#i', '', $ns ), '/' );
					if ( '' !== $ns ) {
						$lines[] = $ns;
					}
				}
				$out[ $key ] = implode( "\n", array_slice( array_unique( $lines ), 0, 50 ) );
			} elseif ( 'header_frame' === $key ) {
				$v           = strtoupper( (string) ( $in[ $key ] ?? '' ) );
				$out[ $key ] = in_array( $v, array( 'SAMEORIGIN', 'DENY', '' ), true ) ? $v : 'SAMEORIGIN';
			} elseif ( 'hsts_max_age' === $key ) {
				$out[ $key ] = max( 300, min( 63072000, absint( $in[ $key ] ?? $default ) ) );
			} else {
				$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
			}
		}
		update_option( self::OPTION, $out, true );
		self::$cache = null;
		return $out;
	}

	public static function register() {
		if ( self::on( 'hide_wp_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string', 99 );
			add_filter( 'style_loader_src', array( __CLASS__, 'strip_core_version' ), 99 );
			add_filter( 'script_loader_src', array( __CLASS__, 'strip_core_version' ), 99 );
		}
		if ( self::on( 'obfuscate_asset_ver' ) ) {
			add_filter( 'style_loader_src', array( __CLASS__, 'obfuscate_version' ), 100 );
			add_filter( 'script_loader_src', array( __CLASS__, 'obfuscate_version' ), 100 );
		}
		if ( self::on( 'hide_plugin_versions' ) ) {
			add_filter( 'publishrank_expose_version', '__return_false' );
		}
		if ( self::on( 'remove_rsd_wlw' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
		if ( self::on( 'remove_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
		if ( self::on( 'remove_rest_discovery' ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}
		if ( self::on( 'remove_oembed_discovery' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		}
		if ( self::on( 'disable_file_editor' ) ) {
			add_filter( 'map_meta_cap', array( __CLASS__, 'no_file_editing' ), 10, 2 );
		}
		if ( self::on( 'disable_pingbacks' ) ) {
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'no_pingback_methods' ) );
			add_filter( 'wp_headers', array( __CLASS__, 'no_pingback_header' ) );
			add_action( 'pre_ping', array( __CLASS__, 'no_self_pings' ) );
		}
		if ( self::on( 'restrict_rest' ) ) {
			add_filter( 'rest_authentication_errors', array( __CLASS__, 'restrict_rest' ), 99 );
		}
		if ( self::on( 'firewall' ) ) {
			add_action( 'plugins_loaded', array( __CLASS__, 'firewall' ), 2 );
		}
		if ( self::on( 'disable_user_sitemap' ) ) {
			add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'no_user_sitemap' ), 10, 2 );
		}
		add_action( 'send_headers', array( __CLASS__, 'headers' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'admin_headers' ) );
		add_action( 'login_init', array( __CLASS__, 'admin_headers' ) );
	}

	/** Remove ?ver=<WordPress version> from enqueued assets. */
	public static function strip_core_version( $src ) {
		if ( is_string( $src ) && false !== strpos( $src, 'ver=' . get_bloginfo( 'version' ) ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/** Replace every ?ver= with a salted short hash so caches still bust but versions are not revealed. */
	public static function obfuscate_version( $src ) {
		if ( ! is_string( $src ) || is_admin() ) {
			return $src;
		}
		$q = wp_parse_url( $src, PHP_URL_QUERY );
		if ( ! $q ) {
			return $src;
		}
		parse_str( $q, $args );
		if ( empty( $args['ver'] ) ) {
			return $src;
		}
		return add_query_arg( 'ver', substr( hash_hmac( 'sha256', (string) $args['ver'] . $src, wp_salt( 'nonce' ) ), 0, 8 ), $src );
	}

	public static function no_file_editing( $caps, $cap ) {
		if ( in_array( $cap, array( 'edit_themes', 'edit_plugins', 'edit_files' ), true ) ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}

	public static function no_pingback_methods( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}

	public static function no_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public static function no_self_pings( &$links ) {
		$home = home_url();
		foreach ( $links as $i => $link ) {
			if ( 0 === strpos( $link, $home ) ) {
				unset( $links[ $i ] );
			}
		}
	}

	public static function no_user_sitemap( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/** Anonymous REST requests only reach allow-listed namespaces. */
	public static function restrict_rest( $result ) {
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}
		$route = '';
		if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		} elseif ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$route = '/' . ltrim( $route, '/' );
		if ( '/' === $route ) {
			return $result;
		}
		foreach ( preg_split( '/\s+/', (string) self::all()['rest_allow'] ) as $ns ) {
			if ( '' !== $ns && 0 === strpos( $route, '/' . $ns ) ) {
				return $result;
			}
		}
		return new \WP_Error( 'rest_login_required', __( 'This API requires authentication.', 'publishrank' ), array( 'status' => 401 ) );
	}

	/**
	 * Patterns used by the request firewall. Deliberately conservative: each one is something a
	 * legitimate page request never contains.
	 *
	 * @return array<string,string>
	 */
	public static function firewall_rules() {
		return array(
			'traversal'     => '#(\.\./|\.\.%2f|%2e%2e%2f|%2e%2e/)#i',
			'sensitive'     => '#(/etc/passwd|/proc/self/environ|wp-config\.php|\.env\b|\.git/)#i',
			'sql_union'     => '#union(\s|%20|\+|/\*.*?\*/)+(all(\s|%20|\+)+)?select#i',
			'sql_sleep'     => '#(benchmark\s*\(|sleep\s*\(\s*\d|waitfor\s+delay|information_schema)#i',
			'script'        => '#(<|%3c)\s*script#i',
			'php_wrappers'  => '#(php://|phar://|expect://|data:text/html|zip://)#i',
			'encoded_exec'  => '#(base64_decode\s*\(|eval\s*\(|assert\s*\(|system\s*\(\s*[\x27"$]|shell_exec\s*\(|passthru\s*\()#i',
			'null_byte'     => '#%00#',
		);
	}

	/** Inspect the query string and request path for obvious attack payloads. */
	public static function firewall() {
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || is_user_logged_in() ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only pattern-matched.
		if ( '' === $uri || strlen( $uri ) > 8192 ) {
			return;
		}
		$hit = self::firewall_match( $uri );
		if ( '' === $hit ) {
			return;
		}
		SecurityLog::add( 'firewall', 'warn', array( 'detail' => 'Firewall blocked request (' . $hit . ').' ) );
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Forbidden';
		exit;
	}

	/** @return string Matching rule name or ''. */
	public static function firewall_match( $uri ) {
		$decoded = rawurldecode( (string) $uri );
		foreach ( self::firewall_rules() as $name => $re ) {
			if ( preg_match( $re, (string) $uri ) || preg_match( $re, $decoded ) ) {
				return $name;
			}
		}
		return '';
	}

	/** Front-end security headers. */
	public static function headers() {
		if ( headers_sent() ) {
			return;
		}
		$a = self::all();
		if ( ! empty( $a['remove_x_powered_by'] ) && function_exists( 'header_remove' ) ) {
			header_remove( 'X-Powered-By' );
		}
		if ( ! empty( $a['header_frame'] ) && ! get_option( 'publishrank_frame_options', '' ) ) {
			header( 'X-Frame-Options: ' . $a['header_frame'] );
		}
		if ( ! empty( $a['header_permissions'] ) ) {
			header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=(), browsing-topics=()' );
		}
		if ( ! empty( $a['header_hsts'] ) && is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=' . (int) $a['hsts_max_age'] . '; includeSubDomains' );
		}
		if ( ! empty( $a['header_coop'] ) ) {
			header( 'Cross-Origin-Opener-Policy: same-origin-allow-popups' );
		}
		if ( ! empty( $a['header_csp_basic'] ) ) {
			header( "Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'" . ( is_ssl() ? '; upgrade-insecure-requests' : '' ) );
		}
	}

	/** Admin and login screens: never indexable, never framed. */
	public static function admin_headers() {
		if ( headers_sent() ) {
			return;
		}
		if ( self::on( 'admin_noindex' ) ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		header( 'X-Frame-Options: SAMEORIGIN', true );
		header( 'X-Content-Type-Options: nosniff', true );
		if ( self::on( 'remove_x_powered_by' ) && function_exists( 'header_remove' ) ) {
			header_remove( 'X-Powered-By' );
		}
	}

	/* ---------------- Server rules ---------------- */

	/** Apache rules for the site root. @return string[] */
	public static function apache_rules() {
		$lines   = array();
		$lines[] = '<IfModule mod_autoindex.c>';
		$lines[] = '	Options -Indexes';
		$lines[] = '</IfModule>';
		$lines[] = '<FilesMatch "^(readme\.html|license\.txt|licence\.txt|wp-config\.php|wp-config-sample\.php|xmlrpc\.php|debug\.log|error_log|\.env|\.htaccess|\.user\.ini|php\.ini|composer\.(json|lock)|package(-lock)?\.json)$">';
		$lines[] = '	<IfModule mod_authz_core.c>';
		$lines[] = '		Require all denied';
		$lines[] = '	</IfModule>';
		$lines[] = '	<IfModule !mod_authz_core.c>';
		$lines[] = '		Order allow,deny';
		$lines[] = '		Deny from all';
		$lines[] = '	</IfModule>';
		$lines[] = '</FilesMatch>';
		$lines[] = '<IfModule mod_rewrite.c>';
		$lines[] = '	RewriteEngine On';
		$lines[] = '	RewriteRule ^wp-admin/includes/ - [F,L]';
		$lines[] = '	RewriteRule !^wp-includes/ - [S=3]';
		$lines[] = '	RewriteRule ^wp-includes/[^/]+\.php$ - [F,L]';
		$lines[] = '	RewriteRule ^wp-includes/js/tinymce/langs/.+\.php - [F,L]';
		$lines[] = '	RewriteRule ^wp-includes/theme-compat/ - [F,L]';
		$lines[] = '	RewriteRule (^|/)\.git(/|$) - [F,L]';
		$lines[] = '	RewriteRule ^wp-content/(debug\.log|upgrade/|uploads/.*\.(php|phtml|phar)$) - [F,L]';
		$lines[] = '</IfModule>';
		if ( 'off' !== Settings::get( 'xmlrpc_mode' ) ) {
			// Keep XML-RPC reachable unless it is switched off.
			$lines = array_map(
				static function ( $l ) {
					return str_replace( '|xmlrpc\.php', '', $l );
				},
				$lines
			);
		}
		return $lines;
	}

	public static function nginx_snippet() {
		$x = 'off' === Settings::get( 'xmlrpc_mode' ) ? '|xmlrpc\.php' : '';
		return "# PublishRank Access Shield — add inside your server { } block\n"
			. "autoindex off;\n"
			. "location ~* ^/(readme\\.html|license\\.txt|wp-config\\.php|wp-config-sample\\.php{$x}|\\.env|\\.git|composer\\.(json|lock)) { deny all; return 404; }\n"
			. "location ~* ^/wp-content/(debug\\.log|upgrade/) { deny all; return 404; }\n"
			. "location ~* ^/wp-content/uploads/.*\\.(php|phtml|phar)$ { deny all; return 404; }\n"
			. "location ~* ^/wp-includes/[^/]+\\.php$ { deny all; return 404; }\n"
			. "location ~* ^/wp-admin/includes/ { deny all; return 404; }\n";
	}

	public static function is_apache() {
		global $is_apache;
		return ! empty( $is_apache ) || ( isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ), 'litespeed' ) );
	}

	/** Write or remove the root .htaccess block. @return true|\WP_Error */
	public static function write_server_rules( $enable ) {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = get_home_path() . '.htaccess';
		if ( ( file_exists( $file ) && ! wp_is_writable( $file ) ) || ( ! file_exists( $file ) && ! wp_is_writable( get_home_path() ) ) ) {
			return new \WP_Error( 'publishrank_htaccess', __( 'The .htaccess file is not writable. Copy the rules manually.', 'publishrank' ) );
		}
		$ok = insert_with_markers( $file, self::MARKER, $enable ? self::apache_rules() : array() );
		update_option( 'publishrank_hardening_server_rules', $enable ? 1 : 0, false );
		return $ok ? true : new \WP_Error( 'publishrank_htaccess', __( 'The rules could not be written.', 'publishrank' ) );
	}

	/* ---------------- Audit ---------------- */

	/**
	 * Scored security audit.
	 *
	 * @param bool $probe Make loopback HEAD requests for exposed files.
	 * @return array{score:int,checks:array<int,array{id:string,ok:bool,weight:int,label:string,fix:string}>}
	 */
	public static function audit( $probe = true ) {
		global $wpdb;
		$checks = array();
		$add    = static function ( $id, $ok, $weight, $label, $fix ) use ( &$checks ) {
			$checks[] = array( 'id' => $id, 'ok' => (bool) $ok, 'weight' => (int) $weight, 'label' => $label, 'fix' => $fix );
		};
		$add( 'https', is_ssl() || 0 === strpos( home_url(), 'https://' ), 10, __( 'Site uses HTTPS', 'publishrank' ), __( 'Install a TLS certificate and switch the site address to https://.', 'publishrank' ) );
		$add( 'login_hidden', LoginAddress::active(), 8, __( 'Login address is private', 'publishrank' ), __( 'Set a private login address above.', 'publishrank' ) );
		$add( 'throttle', (bool) Settings::get( 'throttle_enabled' ), 10, __( 'Brute-force throttling is on', 'publishrank' ), __( 'Turn on brute-force protection.', 'publishrank' ) );
		$add( 'xmlrpc', 'on' !== Settings::get( 'xmlrpc_mode' ), 6, __( 'XML-RPC multicall or XML-RPC is restricted', 'publishrank' ), __( 'Disable system.multicall or XML-RPC entirely.', 'publishrank' ) );
		$admin_user = get_user_by( 'login', 'admin' );
		$add( 'admin_user', ! ( $admin_user && user_can( $admin_user, 'manage_options' ) ), 6, __( 'No administrator is called “admin”', 'publishrank' ), __( 'Create a new administrator with a unique username and delete “admin”.', 'publishrank' ) );
		$add( 'file_editor', ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || self::on( 'disable_file_editor' ), 6, __( 'Theme/plugin file editor is disabled', 'publishrank' ), __( 'Turn on “Disable the file editor”.', 'publishrank' ) );
		$add( 'debug_display', ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ), 6, __( 'PHP errors are not shown to visitors', 'publishrank' ), __( 'Set WP_DEBUG_DISPLAY to false in wp-config.php.', 'publishrank' ) );
		$add( 'version_hidden', self::on( 'hide_wp_version' ), 4, __( 'WordPress version is hidden', 'publishrank' ), __( 'Turn on “Hide the WordPress version”.', 'publishrank' ) );
		$add( 'registration', ! get_option( 'users_can_register' ) || 'administrator' !== get_option( 'default_role' ), 10, __( 'New registrations cannot become administrators', 'publishrank' ), __( 'Settings → General: change “New User Default Role”.', 'publishrank' ) );
		$add( 'db_prefix', 'wp_' !== $wpdb->prefix, 2, __( 'Database table prefix is not the default', 'publishrank' ), __( 'Optional: a custom prefix makes automated SQL attacks slightly harder.', 'publishrank' ) );
		$add( 'php', version_compare( PHP_VERSION, '8.1', '>=' ), 6, __( 'PHP is a supported version (8.1+)', 'publishrank' ), __( 'Ask your host to upgrade PHP.', 'publishrank' ) );
		$add( 'salts', defined( 'AUTH_KEY' ) && 'put your unique phrase here' !== AUTH_KEY && strlen( (string) AUTH_KEY ) >= 32, 8, __( 'Security keys and salts are set', 'publishrank' ), __( 'Generate keys at https://api.wordpress.org/secret-key/1.1/salt/ and add them to wp-config.php.', 'publishrank' ) );
		$add( 'twofa_admins', in_array( 'administrator', (array) Settings::get( 'twofa_roles' ), true ), 8, __( 'Two-factor is required for administrators', 'publishrank' ), __( 'Require two-factor for the Administrator role.', 'publishrank' ) );
		$add( 'enumeration', Settings::get( 'block_author_enum' ) && Settings::get( 'block_rest_users' ), 4, __( 'Username enumeration is blocked', 'publishrank' ), __( 'Turn on author-query and REST user blocking.', 'publishrank' ) );
		$add( 'auto_updates', (bool) apply_filters( 'auto_update_core', true ) || ( defined( 'WP_AUTO_UPDATE_CORE' ) && WP_AUTO_UPDATE_CORE ), 4, __( 'WordPress core security updates install automatically', 'publishrank' ), __( 'Do not disable core automatic updates.', 'publishrank' ) );
		if ( $probe ) {
			foreach ( array( 'readme.html' => __( 'readme.html (reveals the WordPress version)', 'publishrank' ), 'wp-content/debug.log' => __( 'wp-content/debug.log (may contain paths and secrets)', 'publishrank' ), 'wp-config-sample.php' => __( 'wp-config-sample.php', 'publishrank' ) ) as $file => $label ) {
				$res     = wp_remote_head( home_url( '/' . $file ), array( 'timeout' => 4, 'redirection' => 0, 'sslverify' => false ) );
				$exposed = ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
				/* translators: %s: file */
				$add( 'probe_' . sanitize_key( $file ), ! $exposed, 'wp-content/debug.log' === $file ? 8 : 3, sprintf( __( 'Not publicly readable: %s', 'publishrank' ), $label ), __( 'Apply the server rules (Apache) or add the nginx snippet.', 'publishrank' ) );
			}
			$uploads = wp_get_upload_dir();
			$res     = wp_remote_get( trailingslashit( $uploads['baseurl'] ), array( 'timeout' => 4, 'redirection' => 0, 'sslverify' => false, 'limit_response_size' => 20000 ) );
			$listing = ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) && false !== stripos( (string) wp_remote_retrieve_body( $res ), 'Index of' );
			$add( 'listing', ! $listing, 6, __( 'Uploads folder does not list its files', 'publishrank' ), __( 'Apply the server rules or disable directory listing on your server.', 'publishrank' ) );
		}
		$total = 0;
		$got   = 0;
		foreach ( $checks as $c ) {
			$total += $c['weight'];
			$got   += $c['ok'] ? $c['weight'] : 0;
		}
		return array( 'score' => $total ? (int) round( 100 * $got / $total ) : 0, 'checks' => $checks );
	}
}
