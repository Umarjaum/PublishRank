<?php
/** Minimal safe security headers plus feed noindex via X-Robots-Tag. */
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class SecurityHeaders {
	public function register() {
		add_action( 'send_headers', array( $this, 'send' ) );
	}

	public function send() {
		if ( is_feed() && get_option( 'publishrank_feed_noindex', 1 ) && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		if ( is_admin() || ! get_option( 'publishrank_security_headers', 1 ) || headers_sent() ) {
			return; }
		header( 'X-Content-Type-Options: nosniff', true );
		$allowed_referrer = array( 'no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url' );
		$referrer         = (string) get_option( 'publishrank_referrer_policy', 'strict-origin-when-cross-origin' );
		if ( in_array( $referrer, $allowed_referrer, true ) ) {
			header( 'Referrer-Policy: ' . $referrer, true ); }
		$frame = strtoupper( (string) get_option( 'publishrank_frame_options', '' ) );
		if ( in_array( $frame, array( 'SAMEORIGIN', 'DENY' ), true ) ) {
			header( 'X-Frame-Options: ' . $frame, true ); }
	}
}
