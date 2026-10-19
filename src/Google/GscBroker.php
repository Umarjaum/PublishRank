<?php
/**
 * Client for the PublishRank Google sign-in broker (a Supabase Edge Function).
 *
 * The broker owns the Google OAuth client, so site owners connect Search Console with a plain
 * "Sign in with Google" click and never create or paste a client ID. This site only ever stores a
 * revocable connection id + secret; Google refresh tokens never leave the broker.
 */
namespace PublishRankSEO\Google;

use PublishRankSEO\Auth\SupabaseAuth;

defined( 'ABSPATH' ) || exit;

final class GscBroker {
	const HEALTH_CACHE = 'publishrank_gsc_broker_health';

	public static function base() {
		$base = untrailingslashit( SupabaseAuth::DEFAULT_PROJECT_URL ) . '/functions/v1/gsc-broker';
		return (string) apply_filters( 'publishrank_gsc_broker_url', $base );
	}

	/** @return array{ok:bool,configured:bool,reachable:bool} cached for five minutes */
	public static function health( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::HEALTH_CACHE );
			if ( is_array( $cached ) ) { return $cached; }
		}
		$out = array( 'ok' => false, 'configured' => false, 'reachable' => false );
		$res = wp_remote_get( self::base() . '/health', array( 'timeout' => 8, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Accept' => 'application/json', 'apikey' => SupabaseAuth::DEFAULT_PUBLISHABLE_KEY ) ) );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $body ) ) { $out = array( 'ok' => ! empty( $body['ok'] ), 'configured' => ! empty( $body['configured'] ), 'reachable' => true ); }
		}
		set_transient( self::HEALTH_CACHE, $out, $out['configured'] ? 5 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
		return $out;
	}

	public static function start_url( $state, $challenge, $return ) {
		return self::base() . '/start?' . http_build_query( array( 'state' => $state, 'challenge' => $challenge, 'return' => $return ), '', '&', PHP_QUERY_RFC3986 );
	}

	public static function exchange( $ticket, $verifier ) { return self::post( '/exchange', array( 'ticket' => $ticket, 'verifier' => $verifier ) ); }
	public static function token( $id, $secret ) { return self::post( '/token', array( 'connection_id' => $id, 'connection_secret' => $secret ) ); }
	public static function disconnect( $id, $secret ) { return self::post( '/disconnect', array( 'connection_id' => $id, 'connection_secret' => $secret ) ); }

	/** @return array|\WP_Error Error code is the broker's own (`reauth`, `invalid_connection` …) or `network`. */
	private static function post( $path, $body ) {
		$res = wp_remote_post( self::base() . $path, array(
			'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
			'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'apikey' => SupabaseAuth::DEFAULT_PUBLISHABLE_KEY ),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $res ) ) { return new \WP_Error( 'network', __( 'Could not reach the PublishRank sign-in service.', 'publishrank' ) ); }
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$err = is_array( $data ) && ! empty( $data['error'] ) ? sanitize_key( (string) $data['error'] ) : 'http_' . $code;
			return new \WP_Error( $err, __( 'The PublishRank sign-in service rejected the request.', 'publishrank' ) );
		}
		return $data;
	}
}
