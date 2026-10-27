<?php
/**
 * Keeps Search Console data and newsroom signals fresh and pushes changes to open admin screens.
 *
 * - Hourly background sync (WP-Cron) of daily, hourly, query, page and Discover/News data.
 * - A REST endpoint for "Refresh now".
 * - WordPress Heartbeat (15 s on PublishRank screens) so numbers, charts and the article feed
 *   update on screen without a reload. Only changed data is sent.
 */
namespace PublishRankSEO\Google;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\SEO\ArticleIntelligence;

defined( 'ABSPATH' ) || exit;

final class LiveSync {
	const OPTION = 'publishrank_gsc_live';
	const HOOK   = 'publishrank_gsc_sync';
	const LOCK   = 'publishrank_gsc_sync_lock';
	const STALE  = 1200; // seconds before a heartbeat asks cron for a fresh sync

	public function register() {
		add_action( self::HOOK, array( $this, 'cron_sync' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'heartbeat_received', array( $this, 'heartbeat' ), 10, 2 );
		add_filter( 'heartbeat_settings', array( $this, 'heartbeat_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'publishrank_seo_after_admin_header', array( $this, 'livebar' ), 10, 1 );
	}

	/* ---------- scheduling ---------- */
	public function maybe_schedule() {
		$next = wp_next_scheduled( self::HOOK );
		if ( SearchConsoleClient::connected() && SearchConsoleClient::property() !== '' ) {
			if ( ! $next ) {
				wp_schedule_event( time() + 120, 'hourly', self::HOOK ); }
		} elseif ( $next ) {
			wp_unschedule_hook( self::HOOK );
		}
	}
	public static function unschedule() {
		wp_unschedule_hook( self::HOOK );
		delete_option( self::OPTION );
		delete_transient( self::LOCK ); }
	public function cron_sync() {
		self::sync(); }

	/* ---------- data ---------- */
	public static function snapshot() {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array(); }

	/** Pull fresh data from Google. Safe to call from cron or "Refresh now". */
	public static function sync() {
		$property = SearchConsoleClient::property();
		if ( ! SearchConsoleClient::connected() || '' === $property ) {
			return new \WP_Error( 'not_ready', __( 'Connect Search Console and choose a property first.', 'publishrank' ) ); }
		if ( get_transient( self::LOCK ) ) {
			return new \WP_Error( 'busy', __( 'A sync is already running.', 'publishrank' ) ); }
		set_transient( self::LOCK, 1, 90 );
		$client = new SearchConsoleClient();
		$old    = self::snapshot();
		$type   = isset( $old['search_type'] ) ? (string) $old['search_type'] : (string) ( SearchConsoleClient::metrics()['search_type'] ?? 'web' );
		if ( ! in_array( $type, array( 'web', 'news', 'googleNews', 'discover' ), true ) ) {
			$type = 'web'; }
		$errors = array();
		$path   = '/sites/' . rawurlencode( $property ) . '/searchAnalytics/query';
		$today  = gmdate( 'Y-m-d' );
		$ago    = static function ( $d ) {
			return gmdate( 'Y-m-d', time() - $d * DAY_IN_SECONDS );
		};

		$m     = SearchConsoleClient::metrics();
		$span  = ! empty( $m['start_date'] ) && ! empty( $m['end_date'] ) ? (int) round( ( strtotime( $m['end_date'] ) - strtotime( $m['start_date'] ) ) / DAY_IN_SECONDS ) : 28;
		$daily = $client->sync_daily( in_array( $span, array( 28, 90 ), true ) ? $span : 28, $type );
		if ( is_wp_error( $daily ) ) {
			$errors[] = 'daily';
			$daily    = isset( $old['daily'] ) ? $old['daily'] : array(); }

		$top     = static function ( $dim, $limit ) use ( $client, $path, $ago, $today, $type, &$errors ) {
			$r = $client->request(
				'POST',
				$path,
				array(
					'startDate'  => $ago( 7 ),
					'endDate'    => $today,
					'dimensions' => array( $dim ),
					'type'       => $type,
					'dataState'  => 'all',
					'rowLimit'   => $limit,
				)
			);
			if ( is_wp_error( $r ) ) {
				$errors[] = $dim;
				return null; }
			$rows = array();
			foreach ( (array) ( $r['rows'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['keys'][0] ) ) {
					continue; }
				$rows[] = array(
					'key'         => mb_substr( sanitize_text_field( (string) $row['keys'][0] ), 0, 300 ),
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'ctr'         => round( (float) ( $row['ctr'] ?? 0 ), 4 ),
					'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				);
			}
			return $rows;
		};
		$queries = $top( 'query', 10 );
		$pages   = $top( 'page', 10 );
		if ( null === $queries ) {
			$queries = $old['queries'] ?? array(); }
		if ( null === $pages ) {
			$pages = $old['pages'] ?? array(); }

		// Hourly data (last 48 h) is the closest Search Console gets to real time. Optional; failure is non-fatal.
		$hourly    = array();
		$hourly_ok = true;
		$h         = $client->request(
			'POST',
			$path,
			array(
				'startDate'  => $ago( 2 ),
				'endDate'    => $today,
				'dimensions' => array( 'HOUR' ),
				'type'       => $type,
				'dataState'  => 'hourly_all',
				'rowLimit'   => 100,
			)
		);
		if ( is_wp_error( $h ) ) {
			$hourly_ok = false;
			$hourly    = $old['hourly'] ?? array(); } else {
			foreach ( (array) ( $h['rows'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) || empty( $row['keys'][0] ) ) {
					continue; }
				$ts = strtotime( (string) $row['keys'][0] );
				if ( $ts ) {
					$hourly[] = array(
						't'           => (int) $ts,
						'clicks'      => (int) ( $row['clicks'] ?? 0 ),
						'impressions' => (int) ( $row['impressions'] ?? 0 ),
					); }
			}
			usort(
				$hourly,
				static function ( $a, $b ) {
					return $a['t'] <=> $b['t'];
				}
			);
			}

			// Newsroom surfaces: Discover and Google News totals for the last 7 days.
			$surfaces = array();
			foreach ( array( 'discover', 'googleNews' ) as $s ) {
				$r              = $client->request(
					'POST',
					$path,
					array(
						'startDate' => $ago( 7 ),
						'endDate'   => $today,
						'type'      => $s,
						'dataState' => 'all',
					)
				);
				$row            = ! is_wp_error( $r ) && ! empty( $r['rows'][0] ) && is_array( $r['rows'][0] ) ? $r['rows'][0] : array();
				$surfaces[ $s ] = array(
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
				);
			}

			$snap               = array(
				'property'    => $property,
				'search_type' => $type,
				'daily'       => $daily,
				'queries'     => $queries,
				'pages'       => $pages,
				'hourly'      => $hourly,
				'hourly_ok'   => $hourly_ok,
				'surfaces'    => $surfaces,
				'errors'      => array_values( array_unique( $errors ) ),
			);
			$hash               = md5( wp_json_encode( $snap ) );
			$snap['ver']        = (int) ( $old['ver'] ?? 0 ) + ( isset( $old['hash'] ) && $old['hash'] === $hash ? 0 : 1 );
			$snap['hash']       = $hash;
			$snap['updated_at'] = time();
			update_option( self::OPTION, $snap, false );
			delete_transient( self::LOCK );
			return $snap;
	}

	/** Totals for the last 7 days and the 7 before, from the daily series. */
	private static function kpis( $daily ) {
		if ( ! is_array( $daily ) || ! $daily ) {
			return array(); }
		ksort( $daily );
		$dates = array_keys( $daily );
		$last  = array_slice( $dates, -7 );
		$prev  = array_slice( $dates, -14, 7 );
		$sum   = static function ( $keys ) use ( $daily ) {
			$o = array(
				'clicks'      => 0,
				'impressions' => 0,
				'pos'         => 0.0,
				'n'           => 0,
			);
			foreach ( $keys as $d ) {
				$r                 = $daily[ $d ];
				$o['clicks']      += (int) $r['clicks'];
				$o['impressions'] += (int) $r['impressions'];
				if ( (float) $r['position'] > 0 ) {
					$o['pos'] += (float) $r['position'];
					++$o['n']; }
			}
			return $o;
		};
		$a     = $sum( $last );
		$b     = $sum( $prev );
		$pct   = static function ( $now, $then ) {
			return $then > 0 ? round( ( $now - $then ) / $then * 100, 1 ) : null;
		};
		return array(
			'clicks'            => $a['clicks'],
			'impressions'       => $a['impressions'],
			'ctr'               => $a['impressions'] ? round( $a['clicks'] / $a['impressions'], 4 ) : 0,
			'position'          => $a['n'] ? round( $a['pos'] / $a['n'], 1 ) : 0,
			'clicks_delta'      => $pct( $a['clicks'], $b['clicks'] ),
			'impressions_delta' => $pct( $a['impressions'], $b['impressions'] ),
			'from'              => $last ? $last[0] : '',
			'to'                => $last ? end( $last ) : '',
		);
	}

	/** Site-side signals that change the moment editors publish. */
	private static function newsroom() {
		$since  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$q      = new \WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 6,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'no_found_rows'       => false,
				'ignore_sticky_posts' => true,
			)
		);
		$latest = array();
		foreach ( $q->posts as $p ) {
			$score    = get_post_meta( $p->ID, ArticleIntelligence::SCORE_META, true );
			$latest[] = array(
				'id'    => (int) $p->ID,
				'title' => wp_html_excerpt( wp_strip_all_tags( get_the_title( $p ) ), 90, '…' ),
				'edit'  => get_edit_post_link( $p->ID, 'raw' ),
				'ts'    => (int) get_post_time( 'U', true, $p ),
				'score' => is_numeric( $score ) ? (int) $score : null,
			);
		}
		$day   = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'date_query'     => array(
					array(
						'after'     => $since,
						'inclusive' => true,
						'column'    => 'post_date_gmt',
					),
				),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
			)
		);
		$queue = get_option( 'publishrank_indexnow_queue', array() );
		$nf    = get_option( 'publishrank_seo_404_log', array() );
		return array(
			'published_24h'  => (int) $day->found_posts,
			'latest'         => $latest,
			'indexnow_queue' => is_array( $queue ) ? count( $queue ) : 0,
			'indexnow_on'    => (bool) get_option( 'publishrank_indexnow_enabled', false ),
			'not_found'      => is_array( $nf ) ? count( $nf ) : 0,
		);
	}

	public static function payload() {
		$snap         = self::snapshot();
		$data         = array(
			'connected'   => SearchConsoleClient::connected(),
			'reauth'      => (bool) get_option( 'publishrank_gsc_needs_reauth', false ),
			'email'       => SearchConsoleClient::email(),
			'property'    => SearchConsoleClient::property(),
			'ready'       => ! empty( $snap['daily'] ),
			'updated_at'  => (int) ( $snap['updated_at'] ?? 0 ),
			'ver'         => (int) ( $snap['ver'] ?? 0 ),
			'kpi'         => self::kpis( $snap['daily'] ?? array() ),
			'hourly'      => $snap['hourly'] ?? array(),
			'hourly_ok'   => ! empty( $snap['hourly_ok'] ),
			'queries'     => $snap['queries'] ?? array(),
			'pages'       => $snap['pages'] ?? array(),
			'surfaces'    => $snap['surfaces'] ?? array(),
			'search_type' => $snap['search_type'] ?? 'web',
			'newsroom'    => self::newsroom(),
		);
		$data['hash'] = md5( wp_json_encode( array( $data['kpi'], $data['hourly'], $data['queries'], $data['pages'], $data['newsroom'], $data['connected'], $data['reauth'], $data['property'], $data['surfaces'] ) ) );
		return $data;
	}

	/* ---------- REST ---------- */
	public function routes() {
		$can = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			'publishrank/v1',
			'/live',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static function () {
					return rest_ensure_response( self::payload() );
				},
			)
		);
		register_rest_route(
			'publishrank/v1',
			'/live/refresh',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => static function () {
					$r = self::sync();
					if ( is_wp_error( $r ) ) {
						return new \WP_REST_Response(
							array(
								'error'   => $r->get_error_code(),
								'message' => $r->get_error_message(),
								'data'    => self::payload(),
							),
							'busy' === $r->get_error_code() ? 429 : 400
						); }
					return rest_ensure_response( self::payload() );
				},
			)
		);
	}

	/* ---------- Heartbeat ---------- */
	public function heartbeat_settings( $settings ) {
		if ( is_admin() && Branding::is_plugin_screen() ) {
			$settings['interval'] = 15; }
		return $settings;
	}
	public function heartbeat( $response, $data ) {
		if ( empty( $data['publishrank_live'] ) || ! current_user_can( 'manage_options' ) ) {
			return $response; }
		$client_hash = isset( $data['publishrank_live']['hash'] ) ? sanitize_text_field( (string) $data['publishrank_live']['hash'] ) : '';
		$payload     = self::payload();
		$out         = array(
			'hash'    => $payload['hash'],
			'now'     => time(),
			'changed' => $payload['hash'] !== $client_hash,
		);
		if ( $out['changed'] ) {
			$out['data'] = $payload; }
		// Ask cron (non-blocking) for fresh Google data when the cache is old.
		if ( $payload['connected'] && $payload['property'] && time() - $payload['updated_at'] > self::STALE && ! get_transient( self::LOCK ) && ! get_transient( 'publishrank_gsc_sync_asked' ) ) {
			set_transient( 'publishrank_gsc_sync_asked', 1, 10 * MINUTE_IN_SECONDS );
			wp_schedule_single_event( time(), self::HOOK );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron(); }
		}
		$response['publishrank_live'] = $out;
		return $response;
	}

	/* ---------- status bar shown under every PublishRank page header ---------- */
	public function livebar( $page = '' ) {
		if ( 'publishrank-search-console' === $page || ! current_user_can( 'manage_options' ) ) {
			return; }
		$item = static function ( $label, $path, $fmt = 'int' ) {
			echo '<span class="pr-livebar__item"><span class="pr-livebar__label">' . esc_html( $label ) . '</span><strong data-pr-live="' . esc_attr( $path ) . '" data-fmt="' . esc_attr( $fmt ) . '">—</strong></span>';
		};
		echo '<div class="pr-livebar" data-pr-live-root><span class="pr-live-pill" data-pr-live-pill><i class="pr-live-dot"></i><span>' . esc_html__( 'Live', 'publishrank' ) . '</span></span>';
		$item( __( 'Published today', 'publishrank' ), 'newsroom.published_24h' );
		echo '<span data-pr-when="connected" class="pr-livebar__group">';
		$item( __( 'Clicks · 7d', 'publishrank' ), 'kpi.clicks' );
		$item( __( 'Impressions · 7d', 'publishrank' ), 'kpi.impressions' );
		echo '</span>';
		$item( __( 'IndexNow queue', 'publishrank' ), 'newsroom.indexnow_queue' );
		$item( __( '404s logged', 'publishrank' ), 'newsroom.not_found' );
		echo '<a class="pr-livebar__cta" data-pr-when="disconnected" href="' . esc_url( admin_url( 'admin.php?page=publishrank-search-console' ) ) . '">' . esc_html__( 'Connect Search Console →', 'publishrank' ) . '</a><span class="pr-live__updated" data-pr-live-updated></span></div>';
	}

	/* ---------- assets ---------- */
	public function enqueue() {
		if ( ! Branding::is_plugin_screen() ) {
			return; }
		$ver = Branding::version() . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/js/live.js' );
		wp_enqueue_style( 'publishrank-live', Branding::asset_url( 'css/live.css' ), array( 'publishrank-seo-palette' ), Branding::version() . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/css/live.css' ) );
		wp_enqueue_script( 'publishrank-live', Branding::asset_url( 'js/live.js' ), array( 'jquery', 'heartbeat' ), $ver, true );
		wp_localize_script(
			'publishrank-live',
			'publishrankLive',
			array(
				'refreshUrl' => esc_url_raw( rest_url( 'publishrank/v1/live/refresh' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'initial'    => self::payload(),
				'i18n'       => array(
					'live'        => __( 'Live', 'publishrank' ),
					'updated'     => __( 'Updated', 'publishrank' ),
					'justNow'     => __( 'just now', 'publishrank' ),
					'ago'         => __( 'ago', 'publishrank' ),
					'refreshing'  => __( 'Refreshing…', 'publishrank' ),
					'refresh'     => __( 'Refresh now', 'publishrank' ),
					'failed'      => __( 'Could not refresh. Try again in a minute.', 'publishrank' ),
					'noData'      => __( 'No data yet', 'publishrank' ),
					'clicks'      => __( 'Clicks', 'publishrank' ),
					'impressions' => __( 'Impressions', 'publishrank' ),
					'reauth'      => __( 'Google access expired. Sign in again to resume live data.', 'publishrank' ),
				),
			)
		);
	}
}
