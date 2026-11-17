<?php
/**
 * Visitor country without IP lookups: CDN / host headers first, then the browser's time zone.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Analytics;

defined( 'ABSPATH' ) || exit;

final class Geo {
	/** Headers set by CDNs and hosts (Cloudflare, CloudFront, Fastly, Google, Vercel, Netlify, nginx GeoIP…). */
	const HEADERS = array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_X_NF_GEO_COUNTRY', 'HTTP_FASTLY_GEO_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE', 'HTTP_GEOIP_COUNTRY_CODE', 'HTTP_X_REAL_COUNTRY' );

	/** Two-letter country from request headers, or ''. */
	public static function country() {
		foreach ( self::HEADERS as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$cc = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) ), 0, 2 ) );
				if ( 2 === strlen( $cc ) && ! in_array( $cc, array( 'XX', 'T1', 'ZZ' ), true ) ) {
					return $cc;
				}
			}
		}
		return (string) apply_filters( 'publishrank_visitor_country', '' );
	}

	/** Country for an IANA time zone (browser Intl API). Covers every populated zone in common use. */
	public static function from_timezone( $tz ) {
		$tz  = (string) $tz;
		$map = self::zones();
		if ( isset( $map[ $tz ] ) ) {
			return $map[ $tz ];
		}
		// PHP knows the country of most zones.
		if ( '' !== $tz && in_array( $tz, timezone_identifiers_list(), true ) ) {
			try {
				$loc = ( new \DateTimeZone( $tz ) )->getLocation();
				if ( is_array( $loc ) && ! empty( $loc['country_code'] ) && '??' !== $loc['country_code'] ) {
					return strtoupper( $loc['country_code'] );
				}
			} catch ( \Exception $e ) {
				return '';
			}
		}
		return '';
	}

	/** Zones whose country PHP's database reports ambiguously or not at all. @return array<string,string> */
	private static function zones() {
		return array(
			'Asia/Calcutta' => 'IN', 'Asia/Kolkata' => 'IN', 'Asia/Karachi' => 'PK', 'Asia/Saigon' => 'VN', 'Asia/Rangoon' => 'MM',
			'Asia/Katmandu' => 'NP', 'Europe/Kiev' => 'UA', 'America/Buenos_Aires' => 'AR', 'Asia/Dacca' => 'BD', 'Etc/UTC' => '',
			'UTC' => '', 'Australia/ACT' => 'AU', 'US/Eastern' => 'US', 'US/Central' => 'US', 'US/Pacific' => 'US', 'US/Mountain' => 'US',
		);
	}

	/** English country name (WordPress/PublishRank list when available). */
	public static function name( $cc ) {
		$cc = strtoupper( (string) $cc );
		if ( '' === $cc ) {
			return __( 'Unknown', 'publishrank' );
		}
		if ( class_exists( '\\PublishRankSEO\\Core\\Countries' ) && method_exists( '\\PublishRankSEO\\Core\\Countries', 'all' ) ) {
			$all = \PublishRankSEO\Core\Countries::all();
			if ( isset( $all[ $cc ] ) ) {
				return (string) $all[ $cc ];
			}
		}
		return $cc;
	}

	/** Flag emoji for a country code. */
	public static function flag( $cc ) {
		$cc = strtoupper( (string) $cc );
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			return '🌐';
		}
		$out = '';
		foreach ( str_split( $cc ) as $ch ) {
			$out .= html_entity_decode( '&#' . ( 127397 + ord( $ch ) ) . ';', ENT_NOQUOTES, 'UTF-8' );
		}
		return $out;
	}
}
