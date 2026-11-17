<?php
/**
 * Request facts used by Access Shield: the real client IP (trusted-proxy aware, via Traffic Guard),
 * the request path and the CDN country.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

use PublishRankSEO\Security\TrafficGuard;

defined( 'ABSPATH' ) || exit;

final class Client {
	/** @var string|null */
	private static $ip = null;

	/** Client IP. X-Forwarded-For / CF-Connecting-IP are honoured only from configured trusted proxies. */
	public static function ip() {
		if ( null === self::$ip ) {
			$proxies  = TrafficGuard::settings()['trusted_proxies'];
			self::$ip = (string) TrafficGuard::current_client_ip( $proxies );
		}
		return self::$ip;
	}

	/** For tests and CLI. */
	public static function reset() {
		self::$ip = null;
	}

	public static function country() {
		return (string) TrafficGuard::current_country( TrafficGuard::settings()['trusted_proxies'] );
	}

	public static function path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed and length-limited.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return substr( '' === $path ? '/' : $path, 0, 255 );
	}

	public static function in( array $networks ) {
		$ip = self::ip();
		return '' !== $ip && $networks && TrafficGuard::ip_matches_list( $ip, $networks );
	}
}
