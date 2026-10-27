<?php
/** Opt-in, bounded 404 monitor with privacy-conscious aggregation. */
namespace PublishRankSEO\Monitor;

defined( 'ABSPATH' ) || exit;

final class NotFoundMonitor {
	public function register() {
		add_action( 'template_redirect', array( $this, 'capture' ), 1 ); }

	public function capture() {
		if ( ! get_option( 'publishrank_seo_404_monitor', false ) || ! is_404() || wp_doing_ajax() || is_admin() ) {
			return; }
		$path     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path     = sanitize_text_field( ! empty( wp_parse_url( $path, PHP_URL_PATH ) ) ? wp_parse_url( $path, PHP_URL_PATH ) : '/' );
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		// Retain only the referring URL path; strip query strings/fragments that may contain identifiers.
		if ( $referrer ) {
			$referrer_parts = wp_parse_url( $referrer );
			$referrer       = is_array( $referrer_parts ) && ! empty( $referrer_parts['host'] )
				? ( ! empty( $referrer_parts['scheme'] ) ? $referrer_parts['scheme'] . '://' : 'https://' ) . $referrer_parts['host'] . ( isset( $referrer_parts['port'] ) ? ':' . absint( $referrer_parts['port'] ) : '' ) . ( isset( $referrer_parts['path'] ) ? $referrer_parts['path'] : '/' )
				: '';
		}
		// Throttle writes so a scanner or bot flood cannot cause unbounded option rewrites.
		$bucket = 'publishrank_404_rate_' . gmdate( 'YmdHi' );
		$hits   = (int) get_transient( $bucket );
		if ( $hits >= 60 ) {
			return; }
		set_transient( $bucket, $hits + 1, 90 );
		$agent    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		$category = false !== strpos( $agent, 'bot' ) || false !== strpos( $agent, 'spider' ) || false !== strpos( $agent, 'crawler' ) ? 'crawler' : ( false !== strpos( $agent, 'mobile' ) ? 'mobile' : 'browser' );
		$log      = get_option( 'publishrank_seo_404_log', array() );
		$log      = is_array( $log ) ? $log : array();
		$found    = false;
		foreach ( $log as &$entry ) {
			if ( is_array( $entry ) && (string) ( $entry['path'] ?? '' ) === $path && (string) ( $entry['agent_category'] ?? '' ) === $category ) {
				$entry['count']     = min( 1000000, absint( $entry['count'] ?? 1 ) + 1 );
				$entry['last_seen'] = current_time( 'mysql', true );
				// Keep the latest useful referrer without storing visitor IP addresses.
				if ( '' !== $referrer ) {
					$entry['referrer'] = $referrer; }
				$found = true;
				break;
			}
		}
		unset( $entry );
		if ( ! $found ) {
			$now   = current_time( 'mysql', true );
			$log[] = array(
				'path'           => $path,
				'time'           => $now,
				'first_seen'     => $now,
				'last_seen'      => $now,
				'count'          => 1,
				'referrer'       => $referrer,
				'agent_category' => $category,
				'resolved'       => false,
			); }
		if ( count( $log ) > 500 ) {
			// Evict the least-recently-seen entries so new junk paths cannot displace frequently hit ones.
			usort(
				$log,
				static function ( $a, $b ) {
					return strcmp( (string) ( $b['last_seen'] ?? '' ), (string) ( $a['last_seen'] ?? '' ) );
				}
			);
			$log = array_slice( $log, 0, 500 );
		}
		update_option( 'publishrank_seo_404_log', array_values( $log ), false );
	}
}
