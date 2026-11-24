<?php
/**
 * First-party, cookie-free visitor tracking.
 *
 * A tiny inline script sends one beacon per page view and one "engagement" beacon when the tab is
 * hidden. Visitors are counted with a daily-rotating salted hash of IP + browser: no cookies, no IP
 * addresses stored, nothing sent to a third party, and Do Not Track / Global Privacy Control are honoured.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Analytics;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Tracker {
	const SALT = 'publishrank_traffic_salt';
	const MAX_PAGES_PER_VISITOR = 400;

	public function register() {
		Store::register_cron();
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		if ( Store::enabled() ) {
			add_action( 'wp_footer', array( $this, 'script' ), 99 );
		}
	}

	public function routes() {
		register_rest_route(
			'publishrank/v1',
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'collect' ),
				'permission_callback' => '__return_true', // Public by design: anonymous page-view beacons.
			)
		);
		// Report endpoints for the admin screen (REST requests are never is_admin(), so they live here).
		( new \PublishRankSEO\Admin\AnalyticsPage() )->routes();
	}

	private static function should_track_request() {
		if ( is_admin() || is_feed() || is_robots() || is_preview() || is_customize_preview() || is_404() || wp_doing_ajax() ) {
			return false;
		}
		if ( is_user_logged_in() && Store::settings()['exclude_staff'] && current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return true;
	}

	public function script() {
		if ( ! self::should_track_request() || ! Store::ready() ) {
			return;
		}
		$endpoint = esc_url_raw( rest_url( 'publishrank/v1/collect' ) );
		$dnt      = Store::settings()['respect_dnt'] ? 1 : 0;
		// Kept inline and tiny (≈700 bytes) so it adds no request and works with page caching.
		echo "<script id=\"publishrank-analytics\">(function(){var n=navigator,d=document;if(" . (int) $dnt . "&&(n.globalPrivacyControl||n.doNotTrack==='1'||window.doNotTrack==='1'))return;var u=" . wp_json_encode( $endpoint ) . ",v=0,l=Date.now();function s(b){b=JSON.stringify(b);try{if(n.sendBeacon&&n.sendBeacon(u,new Blob([b],{type:'text/plain'})))return;}catch(e){}try{fetch(u,{method:'POST',body:b,keepalive:true,credentials:'omit',headers:{'Content-Type':'text/plain'}});}catch(e){}}var q=new URLSearchParams(location.search),z='';try{z=Intl.DateTimeFormat().resolvedOptions().timeZone||'';}catch(e){}s({e:'pv',p:location.pathname,r:d.referrer,us:q.get('utm_source')||'',um:q.get('utm_medium')||'',uc:q.get('utm_campaign')||'',tz:z,w:screen.width||0});d.addEventListener('visibilitychange',function(){if(d.visibilityState==='hidden'){v+=Date.now()-l;if(v>999){s({e:'ping',p:location.pathname,s:Math.round(v/1000)});}v=0;}else{l=Date.now();}});})();</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- endpoint JSON-encoded, integer flag.
	}

	/** Daily-rotating salt so visitor hashes cannot be linked across days. */
	private static function salt( $day ) {
		$s = get_option( self::SALT, array() );
		if ( ! is_array( $s ) || ( isset( $s['day'] ) ? $s['day'] : '' ) !== $day || empty( $s['salt'] ) ) {
			$s = array( 'day' => $day, 'salt' => wp_generate_password( 32, true, true ) );
			update_option( self::SALT, $s, false );
		}
		return (string) $s['salt'];
	}

	private static function client_ip() {
		if ( class_exists( '\\PublishRankSEO\\AccessShield\\Client' ) ) {
			return (string) \PublishRankSEO\AccessShield\Client::ip();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/** Clean a site path: no query string, no fragment, bounded length. */
	public static function clean_path( $raw ) {
		$p = (string) wp_parse_url( '/' . ltrim( (string) $raw, '/' ), PHP_URL_PATH );
		$p = '/' . ltrim( preg_replace( '#/{2,}#', '/', $p ), '/' );
		return substr( sanitize_text_field( rawurldecode( $p ) ), 0, 191 );
	}

	public function collect( \WP_REST_Request $request ) {
		$ok = new \WP_REST_Response( null, 204 );
		if ( ! Store::enabled() || ! Store::ready() ) {
			return $ok;
		}
		// 2.26.2: per-client rate limit (filterable through publishrank_rate_limit, bucket "collect").
		$limited = \PublishRankSEO\Core\RateLimiter::rest( 'collect', 120, 60 );
		if ( $limited ) {
			return $limited;
		}
		$body = (string) $request->get_body();
		if ( strlen( $body ) > 2048 ) {
			return $ok;
		}
		$in = json_decode( $body, true );
		if ( ! is_array( $in ) ) {
			$in = $request->get_json_params();
		}
		if ( ! is_array( $in ) || empty( $in['e'] ) || empty( $in['p'] ) || ! is_scalar( $in['p'] ) ) {
			return $ok;
		}
		if ( strlen( (string) $in['p'] ) > 1024 ) {
			return new \WP_Error( 'publishrank_collect_path', __( 'Path too long.', 'publishrank' ), array( 'status' => 400 ) );
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '';
		if ( Classifier::is_bot( $ua ) ) {
			return $ok;
		}
		// Beacons come from this site's own pages.
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ), PHP_URL_HOST ) : '';
		if ( '' !== $origin && strtolower( $origin ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return $ok;
		}
		$event = 'ping' === $in['e'] ? 'ping' : 'pv';
		$path  = self::clean_path( (string) $in['p'] );
		$day   = Store::today();
		$hash  = substr( hash( 'sha256', self::salt( $day ) . '|' . self::client_ip() . '|' . $ua . '|' . home_url() ), 0, 16 );
		self::record( $event, $path, $hash, $day, $ua, $in );
		return $ok;
	}

	/**
	 * Apply one beacon. Public so tests and imports can feed hits through the same pipeline.
	 *
	 * @param array<string,mixed> $in Beacon payload.
	 */
	public static function record( $event, $path, $hash, $day, $ua, array $in ) {
		global $wpdb;
		$seen_t = Database::table( 'traffic_seen' );
		$now    = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$seen = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$seen_t} WHERE day = %s AND vhash = %s", $day, $hash ), ARRAY_A );

		if ( 'ping' === $event ) {
			if ( ! $seen ) {
				return;
			}
			$secs = max( 0, min( 1800, (int) ( isset( $in['s'] ) ? $in['s'] : 0 ) ) );
			if ( ! $secs ) {
				return;
			}
			$engaged = ( ! (int) $seen['engaged'] && $secs >= 10 ) ? 1 : 0;
			Store::bump( $day, 'total', '', array( 'secs' => $secs, 'engaged' => $engaged ) );
			Store::bump( $day, 'page', $path, array( 'secs' => $secs ) );
			if ( $engaged ) {
				Store::bump( $day, 'source', (string) $seen['source'], array( 'engaged' => 1 ) );
			}
			$wpdb->update( $seen_t, array( 'last_at' => $now, 'engaged' => (int) $seen['engaged'] || $engaged ? 1 : 0 ), array( 'day' => $day, 'vhash' => $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		if ( $seen && (int) $seen['pages'] >= self::MAX_PAGES_PER_VISITOR ) {
			return; // Runaway script or abuse: stop counting this visitor today.
		}
		$new_visitor = ! $seen;
		$new_session = $new_visitor || strtotime( $seen['last_at'] . ' UTC' ) < time() - 30 * MINUTE_IN_SECONDS;
		$second_page = ! $new_session && 1 === (int) $seen['pages'] && ! (int) $seen['engaged'];

		$utm_s   = substr( sanitize_text_field( (string) ( isset( $in['us'] ) ? $in['us'] : '' ) ), 0, 80 );
		$utm_m   = substr( sanitize_text_field( (string) ( isset( $in['um'] ) ? $in['um'] : '' ) ), 0, 40 );
		$utm_c   = substr( sanitize_text_field( (string) ( isset( $in['uc'] ) ? $in['uc'] : '' ) ), 0, 120 );
		$ref     = substr( esc_url_raw( (string) ( isset( $in['r'] ) ? $in['r'] : '' ) ), 0, 500 );
		$src     = $new_session ? Classifier::source( $ref, $utm_s, $utm_m ) : array( 'source' => (string) $seen['source'], 'ref' => '', 'engine' => '' );
		$country = Geo::country();
		if ( '' === $country ) {
			$country = $seen && '' !== (string) $seen['country'] ? (string) $seen['country'] : Geo::from_timezone( substr( sanitize_text_field( (string) ( isset( $in['tz'] ) ? $in['tz'] : '' ) ), 0, 60 ) );
		}
		$agent = Classifier::agent( $ua, isset( $in['w'] ) ? (int) $in['w'] : 0 );
		$v     = $new_visitor ? 1 : 0;
		$sess  = $new_session ? 1 : 0;
		$eng   = $second_page ? 1 : 0;

		Store::bump( $day, 'total', '', array( 'views' => 1, 'visitors' => $v, 'sessions' => $sess, 'engaged' => $eng ) );
		Store::bump( $day, 'page', $path, array( 'views' => 1, 'visitors' => $v, 'sessions' => $sess ) );
		Store::bump( $day, 'source', $src['source'], array( 'views' => 1, 'visitors' => $v, 'sessions' => $sess, 'engaged' => $eng ) );
		if ( $new_session && '' !== $src['ref'] ) {
			Store::bump( $day, 'ref', $src['ref'], array( 'views' => 1, 'sessions' => 1 ) );
		}
		if ( $new_session && '' !== $src['engine'] ) {
			Store::bump( $day, 'engine', $src['engine'], array( 'views' => 1, 'sessions' => 1 ) );
		}
		if ( $new_session && 'ai' === $src['source'] ) {
			Store::bump( $day, 'ai', $src['ref'], array( 'views' => 1, 'sessions' => 1 ) );
		}
		if ( $new_session && '' !== $utm_c ) {
			Store::bump( $day, 'campaign', $utm_c, array( 'views' => 1, 'sessions' => 1 ) );
		}
		Store::bump( $day, 'country', $country, array( 'views' => 1, 'visitors' => $v, 'sessions' => $sess ) );
		Store::bump( $day, 'device', $agent['device'], array( 'views' => 1, 'visitors' => $v, 'sessions' => $sess ) );
		Store::bump( $day, 'browser', $agent['browser'], array( 'views' => 1, 'visitors' => $v ) );
		Store::bump( $day, 'os', $agent['os'], array( 'views' => 1, 'visitors' => $v ) );
		Store::bump( $day, 'hour', (string) (int) wp_date( 'G' ), array( 'views' => 1 ) );

		if ( $new_visitor ) {
			$wpdb->insert( $seen_t, array( 'day' => $day, 'vhash' => $hash, 'last_at' => $now, 'session_start' => $now, 'pages' => 1, 'engaged' => 0, 'path' => $path, 'country' => $country, 'source' => $src['source'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$upd = array( 'last_at' => $now, 'pages' => $new_session ? 1 : (int) $seen['pages'] + 1, 'path' => $path, 'country' => $country );
			if ( $new_session ) {
				$upd['session_start'] = $now;
				$upd['source']        = $src['source'];
				$upd['engaged']       = 0;
			} elseif ( $eng ) {
				$upd['engaged'] = 1;
			}
			$wpdb->update( $seen_t, $upd, array( 'day' => $day, 'vhash' => $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
