<?php
/** Local-only, opt-in IP and country rules for anonymous front-end requests. */
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class TrafficGuard {
	const OPTION    = 'publishrank_traffic_guard';
	const MAX_RULES = 500;
	/** Narrowest-allowed prefix breadth: networks broader than /8 (IPv4) or /16 (IPv6) are rejected as unsafe. */
	const MIN_PREFIX_V4 = 8;
	const MIN_PREFIX_V6 = 16;
	/** Reverse-DNS domains that genuine search-engine crawlers resolve to. */
	const CRAWLER_DOMAINS = array( 'googlebot.com', 'google.com', 'search.msn.com', 'applebot.apple.com', 'crawl.baidu.com', 'crawl.baidu.jp', 'yandex.com', 'yandex.ru', 'yandex.net' );

	public function register() {
		add_action( 'template_redirect', array( $this, 'enforce' ), 0 );
	}

	/** Return normalized settings; malformed stored values are ignored safely. */
	public static function settings() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$saved     = get_option( self::OPTION, array() );
		$saved     = is_array( $saved ) ? $saved : array();
		$blocked   = self::inspect_networks( isset( $saved['blocked_ips'] ) ? $saved['blocked_ips'] : '' );
		$allowed   = self::inspect_networks( isset( $saved['allow_ips'] ) ? $saved['allow_ips'] : '' );
		$proxies   = self::inspect_networks( isset( $saved['trusted_proxies'] ) ? $saved['trusted_proxies'] : '' );
		$countries = self::inspect_country_codes( isset( $saved['blocked_countries'] ) ? $saved['blocked_countries'] : '' );

		$cache = array(
			'enabled'           => ! empty( $saved['enabled'] ),
			'allow_crawlers'    => ! array_key_exists( 'allow_crawlers', $saved ) || ! empty( $saved['allow_crawlers'] ),
			'blocked_ips'       => $blocked['valid'],
			'allow_ips'         => $allowed['valid'],
			'trusted_proxies'   => $proxies['valid'],
			'blocked_countries' => $countries['valid'],
		);
		return $cache;
	}

	/** Apply rules to anonymous public page requests only. */
	public function enforce() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || ! self::is_blockable_request() ) {
			return;
		}

		$ip = self::client_ip( $settings['trusted_proxies'] );
		if ( '' === $ip ) {
			return;
		}
		if ( self::matches_any( $ip, $settings['allow_ips'] ) ) {
			return;
		}
		$blocked = self::matches_any( $ip, $settings['blocked_ips'] );
		if ( ! $blocked ) {
			$country = self::country_code( $settings['trusted_proxies'] );
			$blocked = '' !== $country && in_array( $country, $settings['blocked_countries'], true );
		}
		if ( ! $blocked ) {
			return;
		}
		// Never lock out genuine search-engine crawlers (verified by reverse + forward DNS, not by User-Agent alone).
		if ( ! empty( $settings['allow_crawlers'] ) && self::is_verified_crawler( $ip ) ) {
			return;
		}
		self::deny();
	}

	/** The client IP as Traffic Guard sees it for the current request (used by the settings screen). */
	public static function current_client_ip( $trusted_proxies = array() ) {
		return self::client_ip( $trusted_proxies );
	}

	/** The trusted-proxy country code for the current request, or an empty string. */
	public static function current_country( $trusted_proxies = array() ) {
		return self::country_code( $trusted_proxies );
	}

	/** Whether an IP matches any rule in a list; used for save-time lock-out checks. */
	public static function ip_matches_list( $ip, $networks ) {
		return self::matches_any( $ip, $networks );
	}

	/** Confirm a crawler claim with reverse DNS plus a matching forward lookup; the verdict is cached. */
	private static function is_verified_crawler( $ip ) {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $agent || ! preg_match( '/googlebot|google-inspectiontool|bingbot|msnbot|applebot|baiduspider|yandex/i', $agent ) ) {
			return false;
		}
		$key    = 'publishrank_tg_bot_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$verified = false;
		$host     = strtolower( (string) @gethostbyaddr( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure simply means "not verified".
		if ( '' !== $host && $host !== $ip ) {
			foreach ( self::CRAWLER_DOMAINS as $domain ) {
				if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
					$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					foreach ( is_array( $records ) ? $records : array() as $record ) {
						$candidate = isset( $record['ip'] ) ? $record['ip'] : ( isset( $record['ipv6'] ) ? $record['ipv6'] : '' );
						if ( '' !== self::valid_ip( (string) $candidate ) && self::valid_ip( (string) $candidate ) === $ip ) {
							$verified = true;
							break 2;
						}
					}
				}
			}
		}
		// The key is a salted hash, so no visitor IP is stored.
		set_transient( $key, $verified ? '1' : '0', $verified ? DAY_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $verified;
	}

	/** Validate and normalize one-IP-or-CIDR-per-line form data. */
	public static function inspect_networks( $raw ) {
		$values  = self::split_values( $raw );
		$valid   = array();
		$invalid = array();
		foreach ( $values as $value ) {
			if ( count( $valid ) >= self::MAX_RULES ) {
				$invalid[] = $value;
				continue;
			}
			$network = self::normalize_network( $value );
			if ( '' !== $network && self::is_too_broad( $network ) ) {
				$network = '';
			}
			if ( '' === $network ) {
				$invalid[] = $value;
			} elseif ( ! in_array( $network, $valid, true ) ) {
				$valid[] = $network;
			}
		}
		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/** Reject networks so broad (e.g. 0.0.0.0/0) that they would match most of the internet. */
	private static function is_too_broad( $network ) {
		$parts = explode( '/', $network, 2 );
		if ( ! isset( $parts[1] ) ) {
			return false;
		}
		$packed = @inet_pton( $parts[0] );
		$min    = ( is_string( $packed ) && 16 === strlen( $packed ) ) ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4;
		return (int) $parts[1] < $min;
	}

	/** Validate and normalize ISO alpha-2 country values plus Cloudflare XX/T1. */
	public static function inspect_country_codes( $raw ) {
		$values  = self::split_values( $raw );
		$valid   = array();
		$invalid = array();
		foreach ( $values as $value ) {
			$code = strtoupper( trim( $value ) );
			if ( count( $valid ) >= 250 || ! preg_match( '/^(?:[A-Z]{2}|T1)$/', $code ) ) {
				$invalid[] = $value;
				continue;
			}
			if ( ! in_array( $code, $valid, true ) ) {
				$valid[] = $code;
			}
		}
		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/** Test whether an address belongs to a valid IP or CIDR network. */
	public static function matches_network( $ip, $network ) {
		$target = self::valid_ip( $ip );
		$parts  = explode( '/', trim( (string) $network ), 2 );
		$base   = self::valid_ip( $parts[0] );
		if ( '' === $target || '' === $base ) {
			return false;
		}
		$target_bin = @inet_pton( $target );
		$base_bin   = @inet_pton( $base );
		if ( false === $target_bin || false === $base_bin || strlen( $target_bin ) !== strlen( $base_bin ) ) {
			return false;
		}
		$bits   = strlen( $base_bin ) * 8;
		$prefix = isset( $parts[1] ) ? $parts[1] : (string) $bits;
		if ( ! preg_match( '/^\d+$/', $prefix ) || (int) $prefix < 0 || (int) $prefix > $bits ) {
			return false;
		}
		$prefix     = (int) $prefix;
		$full_bytes = intdiv( $prefix, 8 );
		if ( $full_bytes && substr( $target_bin, 0, $full_bytes ) !== substr( $base_bin, 0, $full_bytes ) ) {
			return false;
		}
		$remaining_bits = $prefix % 8;
		if ( $remaining_bits ) {
			$mask = ( 0xff << ( 8 - $remaining_bits ) ) & 0xff;
			if ( ( ord( $target_bin[ $full_bytes ] ) & $mask ) !== ( ord( $base_bin[ $full_bytes ] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/** Return a canonical valid IPv4/IPv6 address, or an empty string. */
	private static function valid_ip( $ip ) {
		if ( ! is_string( $ip ) || strlen( $ip ) > 45 ) {
			return '';
		}
		$ip = trim( $ip );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}
		// Treat IPv4-mapped IPv6 (::ffff:a.b.c.d) as the plain IPv4 address so IPv4 rules cannot be bypassed.
		if ( 16 === strlen( $packed ) && "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $packed, 0, 12 ) ) {
			$packed = substr( $packed, 12 );
		}
		$canonical = @inet_ntop( $packed );
		return is_string( $canonical ) ? strtolower( $canonical ) : '';
	}

	private static function normalize_network( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 64 || substr_count( $value, '/' ) > 1 ) {
			return '';
		}
		$parts = explode( '/', $value, 2 );
		$ip    = self::valid_ip( $parts[0] );
		if ( '' === $ip ) {
			return '';
		}
		if ( ! isset( $parts[1] ) ) {
			return $ip;
		}
		$packed   = @inet_pton( $ip );
		$max_bits = is_string( $packed ) ? strlen( $packed ) * 8 : 0;
		if ( ! preg_match( '/^\d+$/', $parts[1] ) || (int) $parts[1] < 0 || (int) $parts[1] > $max_bits ) {
			return '';
		}
		return $ip . '/' . (int) $parts[1];
	}

	private static function split_values( $raw ) {
		if ( is_array( $raw ) ) {
			$raw = implode( "\n", array_map( 'strval', $raw ) );
		}
		if ( ! is_string( $raw ) || strlen( $raw ) > 65536 ) {
			return array();
		}
		$parts  = preg_split( '/[\r\n,]+/', $raw );
		$values = array();
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$values[] = $part;
			}
		}
		return $values;
	}

	private static function matches_any( $ip, $networks ) {
		foreach ( is_array( $networks ) ? $networks : array() as $network ) {
			if ( self::matches_network( $ip, $network ) ) {
				return true;
			}
		}
		return false;
	}

	/** Trust forwarded addresses only when the direct peer is explicitly trusted. */
	private static function client_ip( $trusted_proxies ) {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? self::valid_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( '' === $remote || ! self::matches_any( $remote, $trusted_proxies ) ) {
			return $remote;
		}

		$cloudflare_ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? self::valid_ip( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ) : '';
		if ( '' !== $cloudflare_ip ) {
			return $cloudflare_ip;
		}

		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		if ( '' === $forwarded || strlen( $forwarded ) > 4096 ) {
			return $remote;
		}
		$chain = explode( ',', $forwarded );
		if ( count( $chain ) > 32 ) {
			return $remote;
		}
		$chain[] = $remote;
		for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
			$candidate = self::valid_ip( trim( $chain[ $i ] ) );
			if ( '' === $candidate ) {
				return $remote;
			}
			if ( self::matches_any( $candidate, $trusted_proxies ) ) {
				continue;
			}
			return $candidate;
		}
		return $remote;
	}

	/** Read country only from a request whose immediate peer is trusted. */
	private static function country_code( $trusted_proxies ) {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? self::valid_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( '' === $remote || ! self::matches_any( $remote, $trusted_proxies ) || ! isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return '';
		}
		$country = strtoupper( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) );
		return preg_match( '/^(?:[A-Z]{2}|T1)$/', $country ) ? $country : '';
	}

	/** Never interfere with authenticated use, recovery endpoints, or indexable feeds/maps. */
	private static function is_blockable_request() {
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( ( function_exists( 'is_feed' ) && is_feed() ) || ( function_exists( 'is_robots' ) && is_robots() ) || ( function_exists( 'is_preview' ) && is_preview() ) || ( function_exists( 'is_trackback' ) && is_trackback() ) || ( function_exists( 'is_embed' ) && is_embed() ) ) {
			return false;
		}
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';
		if ( preg_match( '~(?:^|/)wp-admin(?:/|$)|(?:^|/)wp-login\.php$|(?:^|/)wp-cron\.php$|(?:^|/)wp-json(?:/|$)~i', $path ) || preg_match( '/\.(?:xml|txt)$/i', $path ) ) {
			return false;
		}
		if ( function_exists( 'get_query_var' ) && ( get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) || get_query_var( 'publishrank_sitemap' ) || get_query_var( 'publishrank_xsl' ) ) ) {
			return false;
		}
		return true;
	}

	private static function deny() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			status_header( 403 );
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Cache-Control: no-store, private', true );
			header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset', 'UTF-8' ), true );
		}
		exit( esc_html__( 'Access restricted by the site administrator.', 'publishrank' ) );
	}
}
