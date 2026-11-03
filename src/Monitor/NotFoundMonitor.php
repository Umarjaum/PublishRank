<?php
/** Opt-in, bounded 404 monitor with privacy-conscious aggregation. */
namespace PublishRankSEO\Monitor;

defined( 'ABSPATH' ) || exit;

final class NotFoundMonitor {
	const OPTION       = 'publishrank_seo_404_log';
	const MAX_ENTRIES  = 500;
	const RETENTION_DAYS = 90;
	const MAX_PATH_LEN = 512;
	const MAX_REFERRER_LEN = 512;
	const WRITE_LOCK   = 'publishrank_404_write_lock';

	public function register() {
		add_action( 'template_redirect', array( $this, 'capture' ), 1 ); }

	public function capture() {
		if ( ! get_option( 'publishrank_seo_404_monitor', false ) || ! is_404() || wp_doing_ajax() || is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return; }
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return; }
		$path = $this->request_path();
		if ( '' === $path || $this->is_noise_path( $path ) ) {
			return; }
		$referrer = $this->referrer();
		// Throttle writes so a scanner or bot flood cannot cause unbounded option rewrites.
		$bucket = 'publishrank_404_rate_' . gmdate( 'YmdHi' );
		$hits   = (int) get_transient( $bucket );
		if ( $hits >= 300 ) {
			return; }
		set_transient( $bucket, $hits + 1, 90 );
		// Avoid lost updates when several 404 requests arrive together. The lock is short-lived and
		// the monitor is best-effort: a request skipped during contention is preferable to blocking a page.
		if ( ! wp_cache_add( self::WRITE_LOCK, 1, 'publishrank_404', 5 ) ) {
			return; }
		try {
			$agent    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
			$category = preg_match( '/bot|spider|crawler|slurp|archiver|uptime|monitor|headless|curl|wget/i', $agent ) ? 'crawler' : ( false !== strpos( $agent, 'mobile' ) ? 'mobile' : 'browser' );
			$log      = get_option( self::OPTION, array() );
			$log      = is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
			$now      = current_time( 'mysql', true );
			$cutoff   = time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
			$found    = false;
			foreach ( $log as &$entry ) {
				if ( ! empty( $entry['last_seen'] ) && strtotime( (string) $entry['last_seen'] ) < $cutoff ) {
					$entry['_expired'] = true;
					continue;
				}
				if ( (string) ( $entry['path'] ?? '' ) === $path && (string) ( $entry['agent_category'] ?? '' ) === $category ) {
					$entry['count']     = min( 1000000, absint( $entry['count'] ?? 1 ) + 1 );
					$entry['last_seen'] = $now;
					if ( '' !== $referrer ) {
						$entry['referrer'] = $referrer; }
					$found = true;
					break;
				}
			}
			unset( $entry );
			$log = array_values( array_filter( $log, static function ( $entry ) { return empty( $entry['_expired'] ); } ) );
			if ( ! $found ) {
				$log[] = array( 'path' => $path, 'time' => $now, 'first_seen' => $now, 'last_seen' => $now, 'count' => 1, 'referrer' => $referrer, 'agent_category' => $category, 'resolved' => false ); }
			if ( count( $log ) > self::MAX_ENTRIES ) {
				usort( $log, static function ( $a, $b ) { return strcmp( (string) ( $b['last_seen'] ?? '' ), (string) ( $a['last_seen'] ?? '' ) ); } );
				$log = array_slice( $log, 0, self::MAX_ENTRIES );
			}
			update_option( self::OPTION, array_values( $log ), false );
		} finally {
			wp_cache_delete( self::WRITE_LOCK, 'publishrank_404' );
		}
	}

	private function request_path() {
		$raw  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = wp_parse_url( $raw, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';
		$path = '/' . ltrim( preg_replace( '#/+#', '/', $path ), '/' );
		$path = function_exists( 'mb_substr' ) ? mb_substr( $path, 0, self::MAX_PATH_LEN ) : substr( $path, 0, self::MAX_PATH_LEN );
		return sanitize_text_field( $path );
	}

	private function referrer() {
		$raw = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( '' === $raw ) { return ''; }
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return ''; }
		$value = ( ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https' ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '/' );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_REFERRER_LEN ) : substr( $value, 0, self::MAX_REFERRER_LEN );
	}

	private function is_noise_path( $path ) {
		if ( preg_match( '/\.(?:css|js|map|ico|png|jpe?g|gif|svg|webp|avif|woff2?|ttf|eot|xml|txt)$/i', $path ) ) { return true; }
		return (bool) preg_match( '#^/(?:wp-json(?:/|$)|xmlrpc\.php$|\.env(?:$|/)|\.git(?:/|$)|vendor(?:/|$))#i', $path );
	}
}
