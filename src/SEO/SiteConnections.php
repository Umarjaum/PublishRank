<?php
/**
 * Search-engine site verification: meta tags, verification files and custom tags.
 *
 * Front-end engine used by the "Site Verification" admin page. Options are kept
 * backwards compatible: publishrank_verification_{google,bing,yandex,baidu,pinterest}
 * still hold the bare token exactly as before.
 */
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class SiteConnections {
	const OPT_CUSTOM = 'publishrank_verification_custom';
	const OPT_FILES  = 'publishrank_verification_files';
	const OPT_SCOPE  = 'publishrank_verification_scope';
	const OPT_CHECK  = 'publishrank_verification_last_check';
	const MAX_FILES  = 20;
	const MAX_BYTES  = 65536;

	/** Set once any verification tag has been printed through wp_head(). */
	private $printed = false;
	/** Output-buffer level owned by this instance (zero means not started). */
	private $fallback_buffer_level = 0;

	/**
	 * Supported engines. 'name' is the meta tag name the engine looks for.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function engines() {
		return array(
			'google'    => array( 'label' => 'Google Search Console', 'name' => 'google-site-verification',   'url' => 'https://search.google.com/search-console', 'ph' => 'e.g. 4Hs2…_x9Q' ),
			'bing'      => array( 'label' => 'Bing Webmaster Tools',  'name' => 'msvalidate.01',              'url' => 'https://www.bing.com/webmasters',           'ph' => 'e.g. 1A2B3C4D5E6F…' ),
			'yandex'    => array( 'label' => 'Yandex Webmaster',      'name' => 'yandex-verification',        'url' => 'https://webmaster.yandex.com',             'ph' => 'e.g. 4f5e6d7c8b9a…' ),
			'baidu'     => array( 'label' => 'Baidu Webmaster',       'name' => 'baidu-site-verification',    'url' => 'https://ziyuan.baidu.com',                 'ph' => 'e.g. codeva-AbCdEf1234' ),
			'naver'     => array( 'label' => 'Naver Search Advisor',  'name' => 'naver-site-verification',    'url' => 'https://searchadvisor.naver.com',          'ph' => 'e.g. 0a1b2c3d4e5f…' ),
			'seznam'    => array( 'label' => 'Seznam Webmaster',      'name' => 'seznam-wmt',                 'url' => 'https://search.seznam.cz/webmaster/',      'ph' => 'e.g. AbCdEf123456' ),
			'pinterest' => array( 'label' => 'Pinterest',             'name' => 'p:domain_verify',            'url' => 'https://www.pinterest.com/settings/claim', 'ph' => 'e.g. 0123456789abcdef…' ),
			'facebook'  => array( 'label' => 'Facebook / Meta domain', 'name' => 'facebook-domain-verification', 'url' => 'https://business.facebook.com/settings/owned-domains', 'ph' => 'e.g. abcd1234efgh5678…' ),
		);
	}

	/** Meta names that must never be injected through the free-form box. */
	private static function blocked_names() {
		return array( 'robots', 'googlebot', 'googlebot-news', 'bingbot', 'slurp', 'viewport', 'refresh', 'referrer', 'description', 'generator' );
	}

	/* ------------------------------------------------------------------ *
	 * Front end
	 * ------------------------------------------------------------------ */

	public function register() {
		add_action( 'init', array( $this, 'maybe_serve_file' ), 0 );
		add_action( 'wp_head', array( $this, 'meta' ), 1 );
		// Last-resort path for themes that never call wp_head().
		add_action( 'template_redirect', array( $this, 'start_fallback_buffer' ), 0 );
	}

	/** Print every configured verification tag. */
	public function meta() {
		if ( is_admin() ) { return; }
		$markup = self::markup();
		if ( '' === $markup || ! self::in_scope() ) { return; }
		$this->printed = true;
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput -- every value is escaped in markup().
	}

	/** Build the escaped meta tags for all configured engines plus custom tags. */
	public static function markup() {
		$out = '';
		foreach ( self::tags() as $tag ) {
			$out .= '<meta ' . $tag['attr'] . '="' . esc_attr( $tag['name'] ) . '" content="' . esc_attr( $tag['content'] ) . '" />' . "\n";
		}
		return $out;
	}

	/**
	 * Every tag that should be output.
	 *
	 * @return array<int,array<string,string>> Items: engine, attr (name|property), name, content.
	 */
	public static function tags() {
		$tags = array();
		foreach ( self::engines() as $key => $engine ) {
			$value = self::normalize_token( get_option( 'publishrank_verification_' . $key, '' ) );
			if ( '' !== $value ) {
				$tags[] = array( 'engine' => $key, 'attr' => 'name', 'name' => $engine['name'], 'content' => $value );
			}
		}
		foreach ( self::parse_meta_tags( (string) get_option( self::OPT_CUSTOM, '' ) ) as $tag ) {
			$tags[] = array( 'engine' => 'custom', 'attr' => $tag['attr'], 'name' => $tag['name'], 'content' => $tag['content'] );
		}
		return $tags;
	}

	/** Output scope: 'all' (default, backwards compatible) or 'home'. */
	public static function scope() {
		return 'home' === get_option( self::OPT_SCOPE, 'all' ) ? 'home' : 'all';
	}

	private static function in_scope() {
		return 'all' === self::scope() || is_front_page() || is_home();
	}

	/** Buffer the document only when there is something to inject. */
	public function start_fallback_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		if ( is_feed() || is_robots() || is_embed() || is_trackback() ) { return; }
		if ( '' !== (string) get_query_var( 'publishrank_sitemap' ) || '' !== (string) get_query_var( 'publishrank_xsl' ) ) { return; }
		if ( headers_sent() || '' === self::markup() || ! self::in_scope() || 0 !== $this->fallback_buffer_level ) { return; }
		ob_start( array( $this, 'inject_fallback' ) );
		$this->fallback_buffer_level = ob_get_level();
		// Close nested PublishRank buffers from innermost to outermost at shutdown.
		add_action( 'shutdown', array( $this, 'finish_fallback_buffer' ), max( 100, 1000 - $this->fallback_buffer_level ) );
	}

	/** Flush only this instance's buffer when it is still the topmost buffer. */
	public function finish_fallback_buffer() {
		if ( 0 === $this->fallback_buffer_level ) { return; }
		$status = ob_get_status( true );
		$top = end( $status );
		if ( ob_get_level() === $this->fallback_buffer_level && is_array( $top ) && __CLASS__ . '::inject_fallback' === $top['name'] ) {
			ob_end_flush();
		}
		$this->fallback_buffer_level = 0;
	}

	/** Output-buffer callback: add the tags before </head> only if wp_head() did not print them. */
	public function inject_fallback( $html ) {
		if ( $this->printed || ! is_string( $html ) || '' === $html ) { return $html; }
		if ( false === stripos( $html, '<html' ) && false === stripos( $html, '<!doctype' ) ) { return $html; }
		$pos = stripos( $html, '</head>' );
		if ( false === $pos ) { return $html; }
		$markup = self::markup();
		return '' === $markup ? $html : substr_replace( $html, $markup, $pos, 0 );
	}

	/* ------------------------------------------------------------------ *
	 * Virtual verification files (google123.html, BingSiteAuth.xml, ...)
	 * ------------------------------------------------------------------ */

	/** Serve a stored verification file for a root-level request without needing a physical file. */
	public function maybe_serve_file() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore
		if ( 'GET' !== $method && 'HEAD' !== $method ) { return; }
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path || ! preg_match( '/\.(html?|xml|txt)$/i', $path ) ) { return; }
		$name = self::request_filename( $path );
		if ( '' === $name ) { return; }
		$files = self::files();
		if ( ! isset( $files[ $name ] ) ) { return; }
		$file = $files[ $name ];
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$type = 'xml' === $ext ? 'application/xml' : ( 'txt' === $ext ? 'text/plain' : 'text/html' );
		status_header( 200 );
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=300' );
		if ( 'HEAD' !== $method ) {
			echo $file['content']; // phpcs:ignore WordPress.Security.EscapeOutput -- validated plain text, served with nosniff and a fixed content type.
		}
		exit;
	}

	/** Reduce a request path to a root-level file name (honours sub-directory installs). */
	private static function request_filename( $path ) {
		$path = rawurldecode( $path );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = '' === $home ? '/' : $home;
		if ( '/' !== $home && 0 === strpos( $path, $home ) ) { $path = '/' . substr( $path, strlen( $home ) ); }
		$path = ltrim( $path, '/' );
		if ( '' === $path || false !== strpos( $path, '/' ) || false !== strpos( $path, '\\' ) ) { return ''; }
		return $path;
	}

	/**
	 * Stored files.
	 *
	 * @return array<string,array{content:string,added:string,physical:bool}>
	 */
	public static function files() {
		$raw = get_option( self::OPT_FILES, array() );
		$out = array();
		if ( ! is_array( $raw ) ) { return $out; }
		foreach ( $raw as $name => $file ) {
			if ( ! is_array( $file ) || ! isset( $file['content'] ) || '' !== self::filename_error( (string) $name ) ) { continue; }
			$out[ (string) $name ] = array( 'content' => (string) $file['content'], 'added' => isset( $file['added'] ) ? (string) $file['added'] : '', 'physical' => ! empty( $file['physical'] ) );
		}
		return $out;
	}

	/** Public URL of a verification file. */
	public static function file_url( $name ) {
		return home_url( '/' . rawurlencode( (string) $name ) );
	}

	/** Add or replace a file. Returns '' on success or a translated error message. */
	public static function save_file( $name, $content ) {
		$name  = trim( (string) $name );
		$error = self::filename_error( $name );
		if ( '' !== $error ) { return $error; }
		$content = self::normalize_content( $content );
		$error   = self::content_error( $content );
		if ( '' !== $error ) { return $error; }
		$files = self::files();
		if ( ! isset( $files[ $name ] ) && count( $files ) >= self::MAX_FILES ) {
			/* translators: %d: maximum number of verification files. */
			return sprintf( __( 'You can store up to %d verification files. Delete one you no longer need first.', 'publishrank' ), self::MAX_FILES );
		}
		$files[ $name ] = array( 'content' => $content, 'added' => gmdate( 'c' ), 'physical' => self::write_physical( $name, $content ) );
		update_option( self::OPT_FILES, $files, true );
		return '';
	}

	public static function delete_file( $name ) {
		$files = self::files();
		if ( ! isset( $files[ $name ] ) ) { return false; }
		if ( ! empty( $files[ $name ]['physical'] ) ) { self::remove_physical( $name, $files[ $name ]['content'] ); }
		unset( $files[ $name ] );
		update_option( self::OPT_FILES, $files, true );
		return true;
	}

	/** Web root used for the optional physical copy (null when it cannot be determined). */
	private static function web_root() {
		if ( ! function_exists( 'get_home_path' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
		$root = function_exists( 'get_home_path' ) ? (string) get_home_path() : (string) ABSPATH;
		return '' !== $root && is_dir( $root ) ? trailingslashit( $root ) : null;
	}

	/**
	 * Best-effort physical copy in the web root. Servers that answer .html/.xml/.txt requests
	 * themselves (some nginx setups) never reach WordPress, so a real file is the most reliable.
	 * Never overwrites a file this plugin did not create.
	 */
	private static function write_physical( $name, $content ) {
		$root = self::web_root();
		if ( null === $root || ! is_writable( $root ) ) { return false; }
		$path = $root . $name;
		if ( file_exists( $path ) ) {
			$existing = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
			$stored   = self::files();
			$ours     = isset( $stored[ $name ] ) && ! empty( $stored[ $name ]['physical'] ) && is_string( $existing ) && $existing === $stored[ $name ]['content'];
			if ( ! $ours && $existing !== $content ) { return false; }
		}
		return false !== @file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
	}

	/** Remove the physical copy only if it is still exactly what this plugin wrote. */
	private static function remove_physical( $name, $content ) {
		$root = self::web_root();
		if ( null === $root ) { return; }
		$path = $root . $name;
		if ( is_file( $path ) && @file_get_contents( $path ) === $content ) { @unlink( $path ); } // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
	}

	/** @return string Empty when valid, otherwise a translated reason. */
	public static function filename_error( $name ) {
		$name = (string) $name;
		if ( '' === $name ) { return __( 'Enter the file name exactly as the search engine shows it.', 'publishrank' ); }
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,98}\.(html|htm|xml|txt)$/', $name ) ) {
			return __( 'Invalid file name. Use only letters, numbers, dots, dashes and underscores, and end with .html, .htm, .xml or .txt (for example google1234abcd.html or BingSiteAuth.xml).', 'publishrank' );
		}
		$lower = strtolower( $name );
		$reserved = array( 'robots.txt', 'ads.txt', 'app-ads.txt', 'security.txt', 'humans.txt', 'llms.txt', 'llms-full.txt', 'index.html', 'index.htm', 'readme.html', 'license.txt', 'publishrank-indexnow-key.txt' );
		if ( in_array( $lower, $reserved, true ) || 0 === strpos( $lower, 'wp-' ) || 0 === strpos( $lower, 'sitemap' ) || false !== strpos( $lower, '..' ) ) {
			return __( 'That file name is reserved by WordPress or another feature and cannot be used for verification.', 'publishrank' );
		}
		return '';
	}

	/** Normalise line endings and strip a BOM. */
	public static function normalize_content( $content ) {
		$content = (string) $content;
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) { $content = substr( $content, 3 ); }
		return str_replace( array( "\r\n", "\r" ), "\n", $content );
	}

	/** @return string Empty when valid. */
	public static function content_error( $content ) {
		if ( '' === trim( $content ) ) { return __( 'The verification file is empty.', 'publishrank' ); }
		if ( strlen( $content ) > self::MAX_BYTES ) { return __( 'The verification file is larger than 64 KB. Real verification files are only a few hundred bytes.', 'publishrank' ); }
		if ( false !== strpos( $content, "\0" ) || ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) ) {
			return __( 'The file is not plain UTF-8 text. Upload the original verification file from the search engine.', 'publishrank' );
		}
		if ( preg_match( '/<\?(php|=)|<script|<iframe|<object|<embed|javascript:/i', $content ) ) {
			return __( 'The file contains active code (PHP or script). Verification files are plain text, so it was refused for safety.', 'publishrank' );
		}
		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Token / tag parsing
	 * ------------------------------------------------------------------ */

	/**
	 * Clean a pasted token. Accepts a bare token, "name=token" (DNS style) or a full <meta> tag.
	 * Same character set and length as before, so existing values are unchanged.
	 */
	public static function normalize_token( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return ''; }
		if ( false !== stripos( $value, '<meta' ) ) {
			$tags = self::parse_meta_tags( $value, true );
			$value = $tags ? $tags[0]['content'] : '';
		} elseif ( preg_match( '/^[A-Za-z0-9:_.-]+=(.+)$/s', $value, $m ) && false === strpos( $m[1], '=' ) ) {
			$value = $m[1];
		}
		$value = trim( $value, " \t\n\r\0\x0B\"'" );
		return substr( (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', $value ), 0, 256 );
	}

	/**
	 * Extract <meta name|property=... content=...> tags from pasted HTML.
	 *
	 * @param string $text          Pasted text.
	 * @param bool   $allow_blocked Skip the blocked-name list (used for token extraction only).
	 * @return array<int,array<string,string>> Items: attr, name, content.
	 */
	public static function parse_meta_tags( $text, $allow_blocked = false ) {
		$out = array();
		$text = (string) $text;
		if ( '' === trim( $text ) || ! preg_match_all( '/<meta\b[^>]*>/i', $text, $tags ) ) { return $out; }
		foreach ( array_slice( $tags[0], 0, 200 ) as $tag ) {
			if ( ! preg_match_all( '/([A-Za-z:_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $tag, $attrs, PREG_SET_ORDER ) ) { continue; }
			$a = array();
			foreach ( $attrs as $pair ) {
				$a[ strtolower( $pair[1] ) ] = isset( $pair[3] ) && '' !== $pair[3] ? $pair[3] : $pair[2];
			}
			$attr = isset( $a['name'] ) ? 'name' : ( isset( $a['property'] ) ? 'property' : '' );
			if ( '' === $attr || ! isset( $a['content'] ) ) { continue; }
			$name = (string) preg_replace( '/[^A-Za-z0-9:._-]/', '', $a[ $attr ] );
			$content = trim( (string) preg_replace( '/[\x00-\x1F\x7F<>"\']/', '', html_entity_decode( $a['content'], ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' === $name || '' === $content || strlen( $name ) > 64 ) { continue; }
			if ( ! $allow_blocked && in_array( strtolower( $name ), self::blocked_names(), true ) ) { continue; }
			$out[] = array( 'attr' => $attr, 'name' => $name, 'content' => substr( $content, 0, 512 ) );
		}
		return $out;
	}

	/** Map a meta tag name to an engine key, or '' if it is not a known engine tag. */
	public static function engine_for_meta_name( $name ) {
		foreach ( self::engines() as $key => $engine ) {
			if ( 0 === strcasecmp( $engine['name'], (string) $name ) ) { return $key; }
		}
		return '';
	}

	/** Best-effort purge of the common page caches so a new tag or file is visible immediately. */
	public static function purge_caches() {
		try {
			if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
			if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
			if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); }
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache(); }
			if ( class_exists( '\\WpeCommon' ) && method_exists( '\\WpeCommon', 'purge_varnish_cache' ) ) { \WpeCommon::purge_varnish_cache(); }
			do_action( 'litespeed_purge_all' );
			do_action( 'wpfc_clear_all_cache' );
			do_action( 'autoptimize_action_cachepurged' );
			do_action( 'publishrank_verification_changed' );
		} catch ( \Throwable $e ) {
			unset( $e ); // Cache purging is best effort and must never break saving.
		}
	}

	/* ------------------------------------------------------------------ *
	 * Live check (used by the admin page)
	 * ------------------------------------------------------------------ */

	/** Short fingerprint of the current configuration, to flag results that are out of date. */
	public static function config_hash() {
		$parts = array();
		foreach ( self::tags() as $tag ) { $parts[] = $tag['name'] . '=' . $tag['content']; }
		$files = self::files();
		ksort( $files );
		foreach ( $files as $name => $file ) { $parts[] = $name . '#' . md5( $file['content'] ); }
		$parts[] = self::scope();
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Fetch the live home page and every verification file and report what a crawler would see.
	 *
	 * @return array<string,mixed>
	 */
	public static function run_check() {
		$result = array( 'time' => gmdate( 'c' ), 'hash' => self::config_hash(), 'home' => array( 'code' => 0, 'error' => '' ), 'tags' => array(), 'files' => array() );
		$tags   = self::tags();
		$args   = array( 'timeout' => 15, 'redirection' => 5, 'headers' => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ), 'user-agent' => 'PublishRank-Verification-Check/1.0 (+' . home_url( '/' ) . ')' );
		if ( $tags ) {
			$url  = add_query_arg( 'publishrank_vcheck', (string) time(), home_url( '/' ) );
			$resp = wp_remote_get( $url, $args );
			if ( is_wp_error( $resp ) ) {
				$result['home']['error'] = $resp->get_error_message();
			} else {
				$result['home']['code'] = (int) wp_remote_retrieve_response_code( $resp );
				$body = (string) wp_remote_retrieve_body( $resp );
				$head = $body;
				$end  = stripos( $body, '</head>' );
				if ( false !== $end ) { $head = substr( $body, 0, $end ); }
				$found = array();
				foreach ( self::parse_meta_tags( $head, true ) as $t ) { $found[ strtolower( $t['name'] ) ][] = $t['content']; }
				foreach ( $tags as $tag ) {
					$id   = $tag['engine'] . ':' . $tag['name'];
					$seen = isset( $found[ strtolower( $tag['name'] ) ] ) ? $found[ strtolower( $tag['name'] ) ] : array();
					if ( in_array( $tag['content'], $seen, true ) ) {
						$conflict = count( array_unique( $seen ) ) > 1;
						$result['tags'][ $id ] = array( 'status' => $conflict ? 'warn' : 'ok', 'message' => $conflict ? __( 'Found, but another plugin or theme prints a different value for the same tag. Remove the other one.', 'publishrank' ) : __( 'Found in the page <head>.', 'publishrank' ) );
					} elseif ( $seen ) {
						$result['tags'][ $id ] = array( 'status' => 'bad', 'message' => __( 'The page prints a different value for this tag (another plugin, theme or cache). Clear caches and remove duplicates.', 'publishrank' ) );
					} else {
						$result['tags'][ $id ] = array( 'status' => 'bad', 'message' => 200 === $result['home']['code'] ? __( 'Not found in the page <head>. Clear page and CDN caches, then check again.', 'publishrank' ) : sprintf( /* translators: %d: HTTP status */ __( 'The home page returned HTTP %d, so the tag could not be confirmed.', 'publishrank' ), $result['home']['code'] ) );
					}
				}
			}
		}
		foreach ( self::files() as $name => $file ) {
			$resp = wp_remote_get( add_query_arg( 'publishrank_vcheck', (string) time(), self::file_url( $name ) ), $args );
			if ( is_wp_error( $resp ) ) {
				$result['files'][ $name ] = array( 'status' => 'bad', 'message' => $resp->get_error_message() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = (string) wp_remote_retrieve_body( $resp );
			if ( 200 === $code && trim( $body ) === trim( $file['content'] ) ) {
				$result['files'][ $name ] = array( 'status' => 'ok', 'message' => __( 'Reachable and identical to the stored file.', 'publishrank' ) );
			} elseif ( 200 === $code ) {
				$result['files'][ $name ] = array( 'status' => 'warn', 'message' => __( 'Reachable, but the content differs. A physical file or cache may be overriding it.', 'publishrank' ) );
			} else {
				/* translators: %d: HTTP status */
				$result['files'][ $name ] = array( 'status' => 'bad', 'message' => sprintf( __( 'HTTP %d. A security plugin, cache or server rule may be blocking this path.', 'publishrank' ), $code ) );
			}
		}
		update_option( self::OPT_CHECK, $result, false );
		return $result;
	}
}
