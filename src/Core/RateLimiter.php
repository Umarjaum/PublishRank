<?php
/**
 * Small fixed-window rate limiter for public endpoints.
 *
 * Uses the object cache when one is available (atomic increments) and falls back to a transient, so it works on
 * every host. The client key is a hash of the visitor address; no address is stored.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	/**
	 * Count one hit. Returns seconds to wait when the limit is exceeded, or 0 when allowed.
	 *
	 * @param string $bucket Endpoint name.
	 * @param int    $limit  Hits allowed per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( $bucket, $limit, $window = 60 ) {
		$ip     = class_exists( '\\PublishRankSEO\\AccessShield\\Client' ) ? \PublishRankSEO\AccessShield\Client::ip() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$slot   = (int) floor( time() / max( 1, $window ) );
		$key    = 'prx_rl_' . substr( hash( 'sha256', $bucket . '|' . $ip . '|' . wp_salt( 'nonce' ) ), 0, 24 ) . '_' . $slot;
		$limit  = (int) apply_filters( 'publishrank_rate_limit', $limit, $bucket );
		if ( $limit <= 0 ) {
			return 0; }
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'publishrank_rl', $window + 5 );
			$count = (int) wp_cache_incr( $key, 1, 'publishrank_rl' );
		} else {
			$count = (int) get_transient( $key ) + 1;
			set_transient( $key, $count, $window + 5 );
		}
		return $count > $limit ? max( 1, ( ( $slot + 1 ) * $window ) - time() ) : 0;
	}

	/** REST helper: WP_Error with 429 + Retry-After when over the limit, null otherwise. */
	public static function rest( $bucket, $limit, $window = 60 ) {
		$wait = self::hit( $bucket, $limit, $window );
		if ( ! $wait ) {
			return null; }
		add_filter(
			'rest_post_dispatch',
			static function ( $response ) use ( $wait ) {
				if ( $response instanceof \WP_REST_Response ) {
					$response->header( 'Retry-After', (string) $wait ); }
				return $response;
			}
		);
		return new \WP_Error( 'publishrank_rate_limited', __( 'Too many requests. Please slow down and retry shortly.', 'publishrank' ), array( 'status' => 429 ) );
	}
}
