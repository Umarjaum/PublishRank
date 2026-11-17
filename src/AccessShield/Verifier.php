<?php
/**
 * Access Shield live verification (2.25).
 *
 * Instead of reporting which settings are switched on, this attacks the site the way an outsider would — as an
 * anonymous visitor over HTTP, from the server itself — and records the evidence (status codes, headers, matched
 * text) for every protection. Safe by design:
 * - it never submits credentials to the real login form, so it cannot lock out the server or a proxy address;
 * - brute-force locking is exercised in-process with a reserved documentation address (192.0.2.0/24, RFC 5737)
 *   and a throwaway username, then unlocked;
 * - the uploads PHP-execution probe writes one random, harmless file and always deletes it.
 * The report is signed with an HMAC derived from this site's secret keys, so an exported report can later be
 * checked for tampering on the same site (wp publishrank shield verify-report <file>).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

final class Verifier {
	const OPTION = 'publishrank_shield_verification';
	const UA     = 'PublishRank-Shield-Verify/2.25';

	/** @var array<int,array<string,mixed>> */
	private $results = array();

	/** Severity weights used for the score. */
	private static function weight( $severity ) {
		$w = array( 'critical' => 10, 'high' => 6, 'medium' => 3, 'low' => 1 );
		return isset( $w[ $severity ] ) ? $w[ $severity ] : 3;
	}

	private function add( $id, $group, $severity, $status, $label, $evidence, $fix = '' ) {
		$this->results[] = array(
			'id'       => $id,
			'group'    => $group,
			'severity' => $severity,
			'status'   => $status, // pass | warn | fail | skip.
			'label'    => $label,
			'evidence' => substr( (string) $evidence, 0, 300 ),
			'fix'      => 'pass' === $status ? '' : $fix,
		);
	}

	/** Anonymous same-site request; never follows redirects, never sends cookies. */
	private function http( $url, $method = 'GET', $body = null, array $headers = array() ) {
		$url = add_query_arg( 'prv', wp_generate_password( 6, false ), $url ); // Bypass page caches.
		$res = wp_remote_request(
			$url,
			array(
				'method'              => $method,
				'timeout'             => 8,
				'redirection'         => 0,
				'sslverify'           => (bool) apply_filters( 'publishrank_shield_verify_sslverify', true ),
				'limit_response_size' => 262144,
				'user-agent'          => self::UA,
				'headers'             => array_merge( array( 'Cache-Control' => 'no-cache' ), $headers ),
				'body'                => $body,
				'cookies'             => array(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'error' => $res->get_error_message(), 'body' => '', 'headers' => array() );
		}
		$h = wp_remote_retrieve_headers( $res );
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'body'    => (string) wp_remote_retrieve_body( $res ),
			'headers' => is_object( $h ) && method_exists( $h, 'getAll' ) ? array_change_key_case( $h->getAll(), CASE_LOWER ) : array_change_key_case( (array) $h, CASE_LOWER ),
			'error'   => '',
		);
	}

	private static function header( array $r, $name ) {
		$v = isset( $r['headers'][ strtolower( $name ) ] ) ? $r['headers'][ strtolower( $name ) ] : '';
		return is_array( $v ) ? implode( ', ', $v ) : (string) $v;
	}

	/** Run every probe and store the signed report. @return array<string,mixed> */
	public static function run() {
		$v     = new self();
		$start = microtime( true );
		$v->probe_login();
		$v->probe_enumeration();
		$v->probe_xmlrpc();
		$v->probe_headers();
		$v->probe_firewall();
		$v->probe_files();
		$v->probe_uploads_php();
		$v->probe_bruteforce();
		$v->probe_accounts();
		$report = $v->report( microtime( true ) - $start );
		update_option( self::OPTION, $report, false );
		SecurityLog::add( 'settings', 'info', array( 'detail' => sprintf( 'Live verification: score %d, %d failed, %d warnings.', $report['score'], $report['counts']['fail'], $report['counts']['warn'] ) ) );
		return $report;
	}

	public static function last() {
		$r = get_option( self::OPTION, array() );
		return is_array( $r ) ? $r : array();
	}

	/* ---------------- Probes ---------------- */

	private function probe_login() {
		// Raw address: site_url() would already be rewritten to the private address by Access Shield.
		$std = $this->http( trailingslashit( (string) get_option( 'siteurl' ) ) . 'wp-login.php' );
		if ( ! LoginAddress::active() ) {
			$this->add( 'login_hidden', 'login', 'medium', 'warn', __( 'Login address is private', 'publishrank' ), 'GET /wp-login.php → ' . $std['code'] . ' (standard address in use)', __( 'Set a private login address in the Login address tab.', 'publishrank' ) );
			return;
		}
		$form = false !== strpos( $std['body'], 'name="log"' ) || false !== strpos( $std['body'], 'id="loginform"' );
		$this->add( 'login_hidden', 'login', 'high', ( 200 === $std['code'] && $form ) ? 'fail' : 'pass', __( 'Standard /wp-login.php does not show a login form', 'publishrank' ), 'GET /wp-login.php → ' . $std['code'] . ( $form ? ' with login form' : ' without login form' ), __( 'A cache or another plugin is serving wp-login.php; exclude it from page caching.', 'publishrank' ) );
		$adm  = $this->http( admin_url( '/' ) );
		$loc  = self::header( $adm, 'location' );
		$leak = '' !== $loc && false !== stripos( $loc, '/' . LoginAddress::slug() );
		$this->add( 'login_admin_leak', 'login', 'high', $leak ? 'fail' : 'pass', __( '/wp-admin/ does not reveal the private login address', 'publishrank' ), 'GET /wp-admin/ → ' . $adm['code'] . ( $loc ? ' Location: ' . preg_replace( '#' . preg_quote( LoginAddress::slug(), '#' ) . '#', '[private]', $loc ) : '' ), __( 'Another plugin redirects logged-out visitors to the login page; disable that redirect.', 'publishrank' ) );
		$own  = $this->http( LoginAddress::url() );
		$ok   = 200 === $own['code'] && ( false !== strpos( $own['body'], 'name="log"' ) || false !== strpos( $own['body'], 'loginform' ) );
		$this->add( 'login_private_works', 'login', 'critical', $ok ? 'pass' : 'fail', __( 'The private login address works (no lock-out risk)', 'publishrank' ), 'GET [private login] → ' . $own['code'], __( 'Re-save permalinks, or turn the private address off with: wp publishrank shield disable', 'publishrank' ) );
	}

	private function probe_enumeration() {
		$rest = $this->http( rest_url( 'wp/v2/users' ) );
		$json = json_decode( $rest['body'], true );
		$leak = 200 === $rest['code'] && is_array( $json ) && isset( $json[0]['slug'] );
		$this->add( 'rest_users', 'enumeration', 'high', $leak ? 'fail' : 'pass', __( 'REST API does not list usernames', 'publishrank' ), 'GET /wp-json/wp/v2/users → ' . $rest['code'] . ( $leak ? ' (' . count( $json ) . ' users listed)' : '' ), __( 'Turn on “Block REST user listing” in Credential paths.', 'publishrank' ) );

		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => array( 'ID', 'user_nicename' ) ) );
		if ( ! $admins ) {
			$this->add( 'author_enum', 'enumeration', 'high', 'skip', __( 'Author archives do not reveal usernames', 'publishrank' ), 'No administrator found.' );
		} else {
			$a    = $admins[0];
			$r    = $this->http( add_query_arg( 'author', (int) $a->ID, home_url( '/' ) ) );
			$loc  = self::header( $r, 'location' );
			$leak = ( false !== stripos( $loc, '/author/' . $a->user_nicename ) ) || ( 200 === $r['code'] && false !== stripos( $r['body'], 'author-' . $a->user_nicename ) );
			$this->add( 'author_enum', 'enumeration', 'high', $leak ? 'fail' : 'pass', __( '?author=N does not reveal an administrator username', 'publishrank' ), 'GET /?author=' . (int) $a->ID . ' → ' . $r['code'] . ( $leak ? ' (username exposed)' : '' ), __( 'Turn on “Block author enumeration” in Credential paths.', 'publishrank' ) );
		}
		$post = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'fields' => 'ids', 'no_found_rows' => true ) );
		if ( $post ) {
			$r    = $this->http( add_query_arg( 'url', rawurlencode( get_permalink( $post[0] ) ), rest_url( 'oembed/1.0/embed' ) ) );
			$j    = json_decode( $r['body'], true );
			$auth = get_userdata( (int) get_post_field( 'post_author', $post[0] ) );
			$leak = is_array( $j ) && $auth && ( ( isset( $j['author_url'] ) && false !== stripos( (string) $j['author_url'], '/author/' . $auth->user_nicename ) ) || ( isset( $j['author_name'] ) && 0 === strcasecmp( (string) $j['author_name'], $auth->user_login ) ) );
			$this->add( 'oembed_author', 'enumeration', 'medium', $leak ? 'fail' : 'pass', __( 'oEmbed data does not expose the author username', 'publishrank' ), 'GET /wp-json/oembed/1.0/embed → ' . $r['code'] . ( $leak ? ' (author username in response)' : '' ), __( 'Turn on “Hide author in oEmbed” in Credential paths.', 'publishrank' ) );
		}
	}

	private function probe_xmlrpc() {
		$mode = (string) Settings::get( 'xmlrpc_mode' );
		$xml  = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName><params><param><value><array><data>'
			. '<value><struct><member><name>methodName</name><value><string>system.listMethods</string></value></member><member><name>params</name><value><array><data></data></array></value></member></struct></value>'
			. '<value><struct><member><name>methodName</name><value><string>system.listMethods</string></value></member><member><name>params</name><value><array><data></data></array></value></member></struct></value>'
			. '</data></array></value></param></params></methodCall>';
		$r         = $this->http( trailingslashit( (string) get_option( 'siteurl' ) ) . 'xmlrpc.php', 'POST', $xml, array( 'Content-Type' => 'text/xml' ) );
		$processed = 200 === $r['code'] && substr_count( $r['body'], 'system.listMethods' ) >= 2 && false === strpos( $r['body'], 'faultCode' );
		$auth      = 200 === $r['code'] && false !== strpos( $r['body'], 'wp.getUsersBlogs' );
		$ev        = 'POST /xmlrpc.php system.multicall → ' . $r['code'] . ( $processed ? ' (batch executed)' : ' (batch refused)' );
		if ( 'on' === $mode ) {
			$this->add( 'xmlrpc', 'xmlrpc', 'high', $processed ? 'fail' : 'pass', __( 'XML-RPC cannot batch hundreds of password guesses in one request', 'publishrank' ), $ev, __( 'Set XML-RPC to “No multicall” or “Off”.', 'publishrank' ) );
		} elseif ( 'no_auth' === $mode ) {
			$this->add( 'xmlrpc', 'xmlrpc', 'high', $auth ? 'fail' : 'pass', __( 'XML-RPC offers no sign-in methods', 'publishrank' ), $ev . ( $auth ? '; wp.getUsersBlogs available' : '' ), __( 'Another plugin re-adds XML-RPC methods.', 'publishrank' ) );
		} else {
			$this->add( 'xmlrpc', 'xmlrpc', 'high', $processed ? 'fail' : 'pass', __( 'XML-RPC cannot batch hundreds of password guesses in one request', 'publishrank' ), $ev, __( 'Another plugin replaces the XML-RPC server; check Access Shield → Credential paths.', 'publishrank' ) );
		}
	}

	private function probe_headers() {
		$r = $this->http( home_url( '/' ) );
		if ( ! $r['code'] ) {
			$this->add( 'headers', 'headers', 'medium', 'skip', __( 'Security headers', 'publishrank' ), 'Home page not reachable from the server: ' . $r['error'] );
			return;
		}
		$h      = Hardening::all();
		$frame  = self::header( $r, 'x-frame-options' );
		$csp    = self::header( $r, 'content-security-policy' );
		$checks = array(
			array( 'hdr_nosniff', 'medium', 'nosniff' === strtolower( trim( self::header( $r, 'x-content-type-options' ) ) ), 'X-Content-Type-Options: nosniff', self::header( $r, 'x-content-type-options' ) ),
			array( 'hdr_frame', 'medium', '' !== $frame || false !== stripos( $csp, 'frame-ancestors' ), __( 'Clickjacking protection (X-Frame-Options or frame-ancestors)', 'publishrank' ), $frame ? $frame : $csp ),
			array( 'hdr_referrer', 'low', '' !== self::header( $r, 'referrer-policy' ), 'Referrer-Policy', self::header( $r, 'referrer-policy' ) ),
			array( 'hdr_permissions', 'low', '' !== self::header( $r, 'permissions-policy' ) || empty( $h['header_permissions'] ), 'Permissions-Policy', self::header( $r, 'permissions-policy' ) ),
			array( 'hdr_powered', 'low', '' === self::header( $r, 'x-powered-by' ), __( 'No X-Powered-By header (PHP version hidden)', 'publishrank' ), self::header( $r, 'x-powered-by' ) ),
		);
		if ( 0 === strpos( home_url(), 'https://' ) ) {
			$checks[] = array( 'hdr_hsts', 'medium', '' !== self::header( $r, 'strict-transport-security' ), 'Strict-Transport-Security (HSTS)', self::header( $r, 'strict-transport-security' ) );
		}
		foreach ( $checks as $c ) {
			$this->add( $c[0], 'headers', $c[1], $c[2] ? 'pass' : ( 'low' === $c[1] ? 'warn' : 'fail' ), $c[3], 'GET / → ' . ( '' !== $c[4] ? $c[4] : '(header absent)' ), __( 'Turn on the matching header in Hardening, or add it in your server/CDN.', 'publishrank' ) );
		}
		$gen = (bool) preg_match( '#<meta[^>]+name=["\']generator["\'][^>]+WordPress#i', $r['body'] );
		$ver = (bool) preg_match( '#wp-(includes|admin)/[^"\']+\?ver=' . preg_quote( get_bloginfo( 'version' ), '#' ) . '\b#', $r['body'] );
		$this->add( 'version_leak', 'headers', 'low', ( $gen || $ver ) ? 'warn' : 'pass', __( 'WordPress version is not shown in page source', 'publishrank' ), ( $gen ? 'generator meta present; ' : '' ) . ( $ver ? 'core assets carry ?ver=' . get_bloginfo( 'version' ) : ( $gen ? '' : 'no version found' ) ), __( 'Turn on “Hide the WordPress version” in Hardening.', 'publishrank' ) );
	}

	private function probe_firewall() {
		if ( ! Hardening::on( 'firewall' ) ) {
			$this->add( 'firewall', 'firewall', 'medium', 'warn', __( 'Request firewall blocks attack payloads', 'publishrank' ), 'Firewall is off.', __( 'Turn on the request firewall in Hardening.', 'publishrank' ) );
			return;
		}
		$tests = array(
			'traversal' => '?file=..%2F..%2F..%2Fetc%2Fpasswd',
			'sql'       => '?id=1%20UNION%20SELECT%20user_pass%20FROM%20wp_users',
			'script'    => '?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E',
		);
		$ev  = array();
		$bad = 0;
		foreach ( $tests as $name => $qs ) {
			$r     = $this->http( home_url( '/' ) . $qs );
			$ev[]  = $name . '→' . $r['code'];
			$bad  += 403 === $r['code'] ? 0 : 1;
		}
		$this->add( 'firewall', 'firewall', 'medium', $bad ? 'fail' : 'pass', __( 'Request firewall blocks traversal, SQL injection and script payloads', 'publishrank' ), implode( ', ', $ev ), __( 'A page cache answers before WordPress; bypass the cache for query strings or add WAF rules at your CDN.', 'publishrank' ) );
	}

	private function probe_files() {
		$files = array(
			'.env'                 => array( 'critical', '#^[A-Z_]+=#m' ),
			'.git/HEAD'            => array( 'critical', '#^ref:#' ),
			'wp-config.php.bak'    => array( 'critical', '#DB_(NAME|PASSWORD)#' ),
			'wp-config.php~'       => array( 'critical', '#DB_(NAME|PASSWORD)#' ),
			'wp-config.old'        => array( 'critical', '#DB_(NAME|PASSWORD)#' ),
			'wp-content/debug.log' => array( 'high', '#PHP (Notice|Warning|Fatal|Deprecated)#' ),
			'error_log'            => array( 'high', '#PHP (Notice|Warning|Fatal)#' ),
			'readme.html'          => array( 'low', '#WordPress#' ),
			'license.txt'          => array( 'low', '#WordPress#' ),
		);
		$exposed = array();
		foreach ( $files as $file => $spec ) {
			$r = $this->http( home_url( '/' . $file ) );
			if ( 200 === $r['code'] && preg_match( $spec[1], $r['body'] ) ) {
				$exposed[ $file ] = $spec[0];
			}
		}
		foreach ( array( 'critical', 'high', 'low' ) as $sev ) {
			$list = array_keys( array_filter( $exposed, static function ( $s ) use ( $sev ) { return $s === $sev; } ) );
			$ids  = array_keys( array_filter( $files, static function ( $s ) use ( $sev ) { return $s[0] === $sev; } ) );
			/* translators: %s: file list */
			$this->add( 'files_' . $sev, 'files', $sev, $list ? ( 'low' === $sev ? 'warn' : 'fail' ) : 'pass', sprintf( __( 'Not publicly readable: %s', 'publishrank' ), implode( ', ', $ids ) ), $list ? 'Readable: ' . implode( ', ', $list ) : 'All returned non-200 or no sensitive content', __( 'Delete the files, or apply the server rules (Apache) / nginx snippet in Hardening.', 'publishrank' ) );
		}
		$up = wp_get_upload_dir();
		$r  = $this->http( trailingslashit( $up['baseurl'] ) );
		$li = 200 === $r['code'] && false !== stripos( $r['body'], 'Index of' );
		$this->add( 'listing', 'files', 'medium', $li ? 'fail' : 'pass', __( 'Uploads folder does not list its files', 'publishrank' ), 'GET ' . wp_parse_url( $up['baseurl'], PHP_URL_PATH ) . '/ → ' . $r['code'], __( 'Disable directory listing (Options -Indexes / autoindex off).', 'publishrank' ) );
	}

	private function probe_uploads_php() {
		$up = wp_get_upload_dir();
		if ( ! empty( $up['error'] ) || ! wp_is_writable( $up['basedir'] ) ) {
			$this->add( 'uploads_php', 'files', 'critical', 'skip', __( 'PHP files in uploads cannot run', 'publishrank' ), 'Uploads folder not writable.' );
			return;
		}
		$token = wp_generate_password( 24, false );
		$name  = 'publishrank-verify-' . strtolower( wp_generate_password( 16, false ) ) . '.php';
		$path  = trailingslashit( $up['basedir'] ) . $name;
		// The marker is only assembled when PHP actually executes the file; served as text it never matches.
		file_put_contents( $path, "<?php echo 'PRV' . '-' . '" . $token . "';" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		try {
			$r = $this->http( trailingslashit( $up['baseurl'] ) . $name );
		} finally {
			wp_delete_file( $path );
			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		$ran = false !== strpos( $r['body'], 'PRV-' . $token );
		$this->add( 'uploads_php', 'files', 'critical', $ran ? 'fail' : 'pass', __( 'PHP files in uploads cannot run', 'publishrank' ), 'Probe file → ' . $r['code'] . ( $ran ? ' and executed' : ' and not executed' ) . ' (file deleted)', __( 'Apply the server rules (Apache) or the nginx snippet in Hardening so uploads never execute PHP.', 'publishrank' ) );
	}

	private function probe_bruteforce() {
		if ( ! Settings::get( 'throttle_enabled' ) ) {
			$this->add( 'bruteforce', 'login', 'critical', 'fail', __( 'Repeated wrong passwords lock the attacker out', 'publishrank' ), 'Brute-force protection is off.', __( 'Turn on brute-force protection.', 'publishrank' ) );
			return;
		}
		if ( ! Guard::ready() ) {
			$this->add( 'bruteforce', 'login', 'critical', 'fail', __( 'Repeated wrong passwords lock the attacker out', 'publishrank' ), 'Attempts table missing.', __( 'Deactivate and reactivate PublishRank to recreate its tables.', 'publishrank' ) );
			return;
		}
		$ip   = '192.0.2.' . wp_rand( 1, 254 ); // RFC 5737 documentation range: never a real visitor.
		$user = 'pr-selftest-' . strtolower( wp_generate_password( 6, false ) );
		$max  = (int) Settings::get( 'max_attempts' );
		$n    = 0;
		SecurityLog::$mute = true;
		while ( $n < $max && ! Guard::locked_for( $ip, $user ) ) {
			Guard::record_failure( $ip, $user, 'login_failed' );
			++$n;
		}
		$lock = Guard::locked_for( $ip, $user );
		Guard::unlock( $ip );
		Guard::unlock( $user );
		SecurityLog::$mute = false;
		/* translators: 1: attempts, 2: seconds */
		$this->add( 'bruteforce', 'login', 'critical', $lock > 0 ? 'pass' : 'fail', __( 'Repeated wrong passwords lock the attacker out', 'publishrank' ), $lock > 0 ? sprintf( 'Locked after %1$d failed attempts for %2$d s (simulated from %3$s, then unlocked)', $n, $lock, $ip ) : sprintf( 'No lock after %d attempts', $n ), __( 'Check the attempt limit and that your IP lists do not exempt everyone.', 'publishrank' ) );

		if ( Settings::get( 'uniform_errors' ) ) {
			// Reproduce the login screen's state for an unknown username, then restore it.
			global $errors;
			$saved  = isset( $errors ) ? $errors : null;
			$text   = '<strong>Error:</strong> The username <strong>pr-selftest</strong> is not registered on this site.';
			$errors = new \WP_Error( 'invalid_username', $text ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$msg    = apply_filters( 'login_errors', $text );
			$errors = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$ok  = false === stripos( (string) $msg, 'not registered' ) && false === stripos( (string) $msg, 'pr-selftest' );
			$this->add( 'uniform_errors', 'login', 'medium', $ok ? 'pass' : 'fail', __( 'Login errors do not reveal whether a username exists', 'publishrank' ), $ok ? 'Unknown-user error replaced by a generic message' : 'Unknown-user error passed through', __( 'Another plugin overrides login error messages.', 'publishrank' ) );
		}
	}

	private function probe_accounts() {
		$admins = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login' ), 'number' => 200 ) );
		$admin  = get_user_by( 'login', 'admin' );
		$this->add( 'admin_username', 'accounts', 'medium', ( $admin && user_can( $admin, 'manage_options' ) ) ? 'fail' : 'pass', __( 'No administrator uses the username “admin”', 'publishrank' ), ( $admin ? '“admin” exists' : 'not found' ), __( 'Create a new administrator and delete “admin” (attribute its content to the new account).', 'publishrank' ) );
		if ( $admins ) {
			$editable = user_can( (int) $admins[0]->ID, 'edit_plugins' ) || user_can( (int) $admins[0]->ID, 'edit_themes' );
			$this->add( 'file_editor', 'accounts', 'high', $editable ? 'fail' : 'pass', __( 'A stolen admin session cannot edit plugin or theme code', 'publishrank' ), $editable ? 'edit_plugins/edit_themes granted to administrators' : 'edit_plugins/edit_themes denied', __( 'Turn on “Disable the file editor” in Hardening.', 'publishrank' ) );
			$without = 0;
			foreach ( $admins as $a ) {
				$without += get_user_meta( (int) $a->ID, TwoFactor::META_ENABLED, true ) ? 0 : 1;
			}
			$this->add( 'twofa_admins', 'accounts', 'high', $without ? ( in_array( 'administrator', (array) Settings::get( 'twofa_roles' ), true ) ? 'warn' : 'fail' ) : 'pass', __( 'Every administrator has two-factor sign-in', 'publishrank' ), sprintf( '%d of %d administrators without two-factor', $without, count( $admins ) ), __( 'Require two-factor for Administrators and ask each to enrol from their profile.', 'publishrank' ) );
		}
		$weak = Settings::get( 'pw_policy' ) ? AccountSecurity::problems( 'password1', null, false ) : array();
		$this->add( 'password_policy', 'accounts', 'medium', $weak ? 'pass' : 'fail', __( 'Weak passwords are refused for privileged roles', 'publishrank' ), $weak ? 'Test password “password1” rejected: ' . $weak[0] : 'Policy off', __( 'Turn on the password policy in the Accounts tab.', 'publishrank' ) );
		$this->add( 'breach_check', 'accounts', 'medium', Settings::get( 'pw_breach_check' ) ? 'pass' : 'warn', __( 'Passwords found in data breaches are refused', 'publishrank' ), Settings::get( 'pw_breach_check' ) ? 'Breach check on (k-anonymity range lookup)' : 'Off', __( 'Turn on the breached-password check in the Accounts tab.', 'publishrank' ) );
		$https = 0 === strpos( home_url(), 'https://' );
		$this->add( 'https', 'accounts', 'critical', $https ? 'pass' : 'fail', __( 'Site uses HTTPS (passwords never travel in clear text)', 'publishrank' ), home_url(), __( 'Install a TLS certificate and switch the site address to https://.', 'publishrank' ) );
	}

	/* ---------------- Report ---------------- */

	private function report( $seconds ) {
		$counts = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0 );
		$total  = 0;
		$got    = 0;
		foreach ( $this->results as $r ) {
			++$counts[ $r['status'] ];
			if ( 'skip' === $r['status'] ) {
				continue;
			}
			$w      = self::weight( $r['severity'] );
			$total += $w;
			$got   += 'pass' === $r['status'] ? $w : ( 'warn' === $r['status'] ? $w / 2 : 0 );
		}
		$report = array(
			'generator' => 'PublishRank Access Shield ' . ( defined( 'PUBLISHRANK_VERSION' ) ? PUBLISHRANK_VERSION : '' ),
			'site'      => home_url(),
			'time'      => gmdate( 'c' ),
			'seconds'   => round( $seconds, 2 ),
			'wordpress' => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'score'     => $total ? (int) round( 100 * $got / $total ) : 0,
			'counts'    => $counts,
			'results'   => $this->results,
		);
		$report['signature'] = self::sign( $report );
		return $report;
	}

	private static function key() {
		return hash_hmac( 'sha256', 'publishrank-shield-verify', wp_salt( 'auth' ) );
	}

	public static function sign( array $report ) {
		unset( $report['signature'] );
		return hash_hmac( 'sha256', wp_json_encode( $report ), self::key() );
	}

	/** Whether an exported report is unmodified and was produced by this site. */
	public static function check( array $report ) {
		return isset( $report['signature'] ) && is_string( $report['signature'] ) && hash_equals( self::sign( $report ), $report['signature'] );
	}
}
