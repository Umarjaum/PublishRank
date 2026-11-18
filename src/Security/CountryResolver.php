<?php
/**
 * Request country for security decisions (Traffic Guard and Access Shield).
 *
 * Only sources a visitor cannot forge are used, in this order:
 * 1. A developer lookup through the `publishrank_ip_country` filter (for example a local MaxMind
 *    database): `add_filter( 'publishrank_ip_country', fn( $code, $ip ) => my_lookup( $ip ), 10, 2 );`
 * 2. Variables set by the web server itself (nginx/Apache GeoIP modules): GEOIP_COUNTRY_CODE,
 *    GEOIP2_COUNTRY_CODE, MM_COUNTRY_CODE. These are not HTTP headers, so clients cannot inject them.
 * 3. CDN country headers (Cloudflare, CloudFront, Fastly, Vercel, Netlify, App Engine) — but only when
 *    the direct peer (REMOTE_ADDR) is one of the configured trusted proxies.
 *
 * Browser geolocation, time zones and Accept-Language are never used here. When no trusted source
 * is available the country is '' (unknown) and callers apply their safe fallback.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Security;

use PublishRankSEO\Core\Countries;

defined( 'ABSPATH' ) || exit;

final class CountryResolver {
	const SERVER_VARS = array( 'GEOIP_COUNTRY_CODE', 'GEOIP2_COUNTRY_CODE', 'MM_COUNTRY_CODE' );
	const CDN_HEADERS = array(
		'HTTP_CF_IPCOUNTRY'              => 'Cloudflare',
		'HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'Amazon CloudFront',
		'HTTP_FASTLY_GEO_COUNTRY_CODE'   => 'Fastly',
		'HTTP_X_VERCEL_IP_COUNTRY'       => 'Vercel',
		'HTTP_X_NF_GEO_COUNTRY'          => 'Netlify',
		'HTTP_X_APPENGINE_COUNTRY'       => 'Google App Engine',
		'HTTP_X_COUNTRY_CODE'            => 'Proxy header (X-Country-Code)',
	);

	/** @var array<string,array{code:string,source:string}> Per-request memo keyed by IP. */
	private static $memo = array();

	/**
	 * Resolve the country for the current request.
	 *
	 * @param string   $ip              Client IP as determined by Traffic Guard.
	 * @param string[] $trusted_proxies Normalised trusted proxy networks.
	 * @param string   $mode            auto | cdn | server | filter | off.
	 * @return array{code:string,source:string} code is ISO alpha-2, 'T1' (Tor) or '' when unknown.
	 */
	public static function resolve( $ip, array $trusted_proxies, $mode = 'auto' ) {
		$key = $ip . '|' . $mode . '|' . md5( implode( ',', $trusted_proxies ) );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$result = array(
			'code'   => '',
			'source' => '',
		);
		if ( 'off' !== $mode ) {
			if ( in_array( $mode, array( 'auto', 'filter' ), true ) && '' !== $ip ) {
				$code = self::normalise( apply_filters( 'publishrank_ip_country', '', $ip ) );
				if ( '' !== $code ) {
					$result = array( 'code' => $code, 'source' => 'lookup' );
				}
			}
			if ( '' === $result['code'] && in_array( $mode, array( 'auto', 'server' ), true ) ) {
				foreach ( self::SERVER_VARS as $var ) {
					if ( ! empty( $_SERVER[ $var ] ) && is_string( $_SERVER[ $var ] ) ) {
						$code = self::normalise( sanitize_text_field( wp_unslash( $_SERVER[ $var ] ) ) );
						if ( '' !== $code ) {
							$result = array( 'code' => $code, 'source' => 'server' );
							break;
						}
					}
				}
			}
			if ( '' === $result['code'] && in_array( $mode, array( 'auto', 'cdn' ), true ) && self::peer_trusted( $trusted_proxies ) ) {
				foreach ( array_keys( self::CDN_HEADERS ) as $header ) {
					if ( ! empty( $_SERVER[ $header ] ) && is_string( $_SERVER[ $header ] ) ) {
						$code = self::normalise( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
						if ( '' !== $code ) {
							$result = array( 'code' => $code, 'source' => 'cdn' );
							break;
						}
					}
				}
			}
		}
		self::$memo[ $key ] = $result;
		return $result;
	}

	/** Forget memoised results (tests and the rule tester). */
	public static function reset() {
		self::$memo = array();
	}

	/** Alpha-2 (or T1) from any input; XX, ZZ, A1/A2 (anonymous proxy / satellite) and junk become ''. */
	public static function normalise( $value ) {
		$value = strtoupper( trim( (string) $value ) );
		if ( 'T1' === $value ) {
			return 'T1';
		}
		if ( ! preg_match( '/^[A-Z]{2,3}$/', $value ) ) {
			return '';
		}
		return Countries::sanitize( $value );
	}

	private static function peer_trusted( array $trusted_proxies ) {
		if ( ! $trusted_proxies || empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return false;
		}
		return TrafficGuard::ip_matches_list( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), $trusted_proxies );
	}

	/** Human description of where country data would come from right now (settings screen). */
	public static function describe( array $trusted_proxies, $mode = 'auto' ) {
		$r = self::resolve( TrafficGuard::current_client_ip( $trusted_proxies ), $trusted_proxies, $mode );
		$labels = array(
			'lookup' => __( 'developer lookup (publishrank_ip_country filter)', 'publishrank' ),
			'server' => __( 'web-server GeoIP variable', 'publishrank' ),
			'cdn'    => __( 'CDN header from a trusted proxy', 'publishrank' ),
		);
		return array(
			'code'   => $r['code'],
			'source' => isset( $labels[ $r['source'] ] ) ? $labels[ $r['source'] ] : '',
		);
	}
}
