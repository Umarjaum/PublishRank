<?php
/**
 * PublishRank Analytics: real visitor analytics, search performance and editorial trends.
 *
 * Visitor numbers come from PublishRank's own cookie-free tracker (src/Analytics). Search figures
 * come from Google Search Console when connected. Editorial figures come from daily local snapshots.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Analytics\Classifier;
use PublishRankSEO\Analytics\Geo;
use PublishRankSEO\Analytics\Store;
use PublishRankSEO\AnalyticsCollector;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Google\SearchConsoleClient;

defined( 'ABSPATH' ) || exit;

final class AnalyticsPage {
	const SLUG   = 'publishrank-analytics';
	const RANGES = array( 7, 30, 90, 365 );

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_post_publishrank_analytics_export', array( $this, 'export' ) );
		add_action( 'admin_post_publishrank_traffic_export', array( $this, 'export_traffic' ) );
		add_action( 'admin_post_publishrank_traffic_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Analytics', 'publishrank' ), __( 'Analytics', 'publishrank' ), 'publishrank_view_analytics', self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === $page ) {
			Suite::enqueue();
			Charts::enqueue( true );
		}
	}

	public function routes() {
		register_rest_route(
			'publishrank/v1',
			'/analytics/live',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'live' ),
				'permission_callback' => static function () {
					return Capabilities::can( 'view_analytics' );
				},
			)
		);
		register_rest_route(
			'publishrank/v1',
			'/analytics/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => static function () {
					return Capabilities::can( 'view_analytics' );
				},
				'args'                => array( 'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 1100 ) ),
			)
		);
	}

	public function live() {
		return rest_ensure_response( array( 'live' => Store::live(), 'html' => self::recent_html() ) );
	}

	public function summary( \WP_REST_Request $request ) {
		list( $from, $to ) = self::window( (int) $request['days'] );
		return rest_ensure_response(
			array(
				'from'      => $from,
				'to'        => $to,
				'totals'    => Store::totals( $from, $to ),
				'daily'     => Store::series( $from, $to ),
				'pages'     => Store::top( 'page', $from, $to, 25 ),
				'sources'   => Store::top( 'source', $from, $to, 10 ),
				'countries' => Store::top( 'country', $from, $to, 25 ),
				'devices'   => Store::top( 'device', $from, $to, 5 ),
				'live'      => Store::live(),
			)
		);
	}

	/** @return array{0:string,1:string} From/to dates (site time) for the last N days including today. */
	public static function window( $days, $offset = 0 ) {
		$days = max( 1, (int) $days );
		$to   = Store::today( time() - $offset * $days * DAY_IN_SECONDS );
		$from = Store::today( time() - ( $offset * $days + $days - 1 ) * DAY_IN_SECONDS );
		return array( $from, $to );
	}

	private static function range() {
		$r = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $r, self::RANGES, true ) ? $r : 30;
	}

	public function save_settings() {
		if ( ! Capabilities::can( 'manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change analytics settings.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_traffic_settings' );
		$in = isset( $_POST['traffic'] ) && is_array( $_POST['traffic'] ) ? wp_unslash( $_POST['traffic'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key cast below.
		update_option(
			Store::OPTION,
			array(
				'enabled'        => empty( $in['enabled'] ) ? 0 : 1,
				'exclude_staff'  => empty( $in['exclude_staff'] ) ? 0 : 1,
				'respect_dnt'    => empty( $in['respect_dnt'] ) ? 0 : 1,
				'retention_days' => max( 30, min( 1100, absint( isset( $in['retention_days'] ) ? $in['retention_days'] : 400 ) ) ),
			),
			false
		);
		if ( ! empty( $_POST['reset_data'] ) && current_user_can( 'manage_options' ) ) {
			global $wpdb;
			$wpdb->query( 'DELETE FROM ' . \PublishRankSEO\Core\Database::table( 'traffic_daily' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( 'DELETE FROM ' . \PublishRankSEO\Core\Database::table( 'traffic_seen' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) . '#settings' );
		exit;
	}

	public function export_traffic() {
		if ( ! Capabilities::can( 'view_analytics' ) ) {
			wp_die( esc_html__( 'You are not allowed to export analytics.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_traffic_export' );
		$days            = isset( $_POST['range'] ) ? absint( $_POST['range'] ) : 30;
		$days            = in_array( $days, self::RANGES, true ) ? $days : 30;
		list( $from, $to ) = self::window( $days );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=publishrank-visitors-' . $from . '-to-' . $to . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'section', 'date_or_value', 'page_views', 'visitors', 'sessions', 'engaged_sessions', 'engagement_seconds' ) );
		foreach ( Store::series( $from, $to ) as $d => $r ) {
			fputcsv( $out, array( 'daily', $d, $r['views'], $r['visitors'], $r['sessions'], $r['engaged'], $r['secs'] ) );
		}
		foreach ( array( 'page', 'source', 'ref', 'engine', 'ai', 'campaign', 'country', 'device', 'browser', 'os' ) as $dim ) {
			foreach ( Store::top( $dim, $from, $to, 500 ) as $r ) {
				fputcsv( $out, array( $dim, self::csv_safe( $r['val'] ), $r['views'], $r['visitors'], $r['sessions'], $r['engaged'], $r['secs'] ) );
			}
		}
		fclose( $out );
		exit;
	}

	/** Stop spreadsheet formula injection from visitor-supplied values. */
	private static function csv_safe( $v ) {
		$v = (string) $v;
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	public function export() {
		if ( ! Capabilities::can( 'view_analytics' ) ) {
			wp_die( esc_html__( 'You are not allowed to export analytics.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_analytics_export' );
		$data = AnalyticsCollector::snapshots();
		ksort( $data );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=publishrank-editorial-analytics.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'date_utc', 'published_posts', 'seo_score_average', 'seo_score_sample_size', 'seo_score_strong', 'seo_score_improving', 'seo_score_priority', 'indexnow_accepted', 'indexnow_failed', 'indexnow_events' ) );
		foreach ( $data as $date => $row ) {
			if ( ! is_array( $row ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
				continue;
			}
			fputcsv( $out, array( $date, (int) ( $row['published_posts'] ?? 0 ), (int) ( $row['seo_score_average'] ?? 0 ), (int) ( $row['seo_score_sample_size'] ?? 0 ), (int) ( $row['seo_score_strong'] ?? 0 ), (int) ( $row['seo_score_improving'] ?? 0 ), (int) ( $row['seo_score_priority'] ?? 0 ), (int) ( $row['indexnow_accepted'] ?? 0 ), (int) ( $row['indexnow_failed'] ?? 0 ), (int) ( $row['indexnow_events'] ?? 0 ) ) );
		}
		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------- helpers */

	private static function duration( $secs ) {
		$secs = (int) round( $secs );
		if ( $secs < 60 ) {
			return $secs . 's';
		}
		return floor( $secs / 60 ) . 'm ' . str_pad( (string) ( $secs % 60 ), 2, '0', STR_PAD_LEFT ) . 's';
	}

	private static function delta( $now, $before, $invert = false ) {
		return Charts::delta( $now, $before, $invert );
	}

	private static function kpi( $label, $value, $delta_html, $icon, $tone, array $spark, $hint = '', $vs = null ) {
		Charts::kpi( $label, $value, $delta_html, $icon, $tone, $spark, $hint, $vs );
	}

	private static function card_open( $title, $icon = '', $sub = '', $extra = '', $class = '' ) {
		Charts::card_open( $title, $icon, $sub, $extra, $class );
	}

	private static function card_close() {
		Charts::card_close();
	}

	private static function page_label( $path ) {
		$path = (string) $path;
		if ( '/' === $path || '' === $path ) {
			return __( 'Home page', 'publishrank' );
		}
		return $path;
	}

	private static function source_icon( $source ) {
		$map = array( 'organic' => 'fa-magnifying-glass', 'direct' => 'fa-arrow-pointer', 'referral' => 'fa-link', 'social' => 'fa-share-nodes', 'ai' => 'fa-robot', 'email' => 'fa-envelope', 'paid' => 'fa-bullhorn' );
		return isset( $map[ $source ] ) ? $map[ $source ] : 'fa-circle';
	}

	private static function recent_html() {
		$rows = Store::recent( 8 );
		if ( ! $rows ) {
			return '<li class="prc-empty">' . esc_html__( 'Visits will appear here as they happen.', 'publishrank' ) . '</li>';
		}
		$labels = Classifier::source_labels();
		$out    = '';
		foreach ( $rows as $r ) {
			$ago  = human_time_diff( strtotime( $r['last_at'] . ' UTC' ), time() );
			$src  = (string) $r['source'];
			$out .= '<li><span class="prc-activity__flag" aria-hidden="true">' . esc_html( Geo::flag( $r['country'] ) ) . '</span><span class="prc-activity__main"><a href="' . esc_url( home_url( $r['path'] ) ) . '" target="_blank" rel="noopener">' . esc_html( self::page_label( $r['path'] ) ) . '</a><small><i class="fa-solid ' . esc_attr( self::source_icon( $src ) ) . '" aria-hidden="true"></i> ' . esc_html( isset( $labels[ $src ] ) ? $labels[ $src ] : $src ) . ' · ' . esc_html( Geo::name( $r['country'] ) ) . ' · ' . esc_html( sprintf( /* translators: %d: pages */ _n( '%d page', '%d pages', (int) $r['pages'], 'publishrank' ), (int) $r['pages'] ) ) . '</small></span><time>' . esc_html( sprintf( /* translators: %s: time */ __( '%s ago', 'publishrank' ), $ago ) ) . '</time></li>';
		}
		return $out;
	}

	/* ---------------------------------------------------------------- screen */

	public function render() {
		if ( ! Capabilities::can( 'view_analytics' ) ) {
			return;
		}
		$range                 = self::range();
		list( $from, $to )     = self::window( $range );
		list( $pfrom, $pto )   = self::window( $range, 1 );
		$series                = Store::series( $from, $to );
		$tot                   = Store::totals( $from, $to );
		$prev                  = Store::totals( $pfrom, $pto );
		$ready                 = Store::ready();

		echo '<div class="wrap publishrank-analytics prc-screen">';
		AdminAssets::header( __( 'Analytics', 'publishrank' ), __( 'Real visitors, where they come from, what they read and how your site performs in search — measured on your own site, without cookies.', 'publishrank' ) );

		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analytics settings saved.', 'publishrank' ) . '</p></div>';
		}
		if ( ! $ready ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The analytics tables are being created. Reload this page in a moment.', 'publishrank' ) . '</p></div>';
		} elseif ( ! Store::enabled() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Visitor tracking is switched off. Turn it on in the Settings tab to start collecting visits.', 'publishrank' ) . '</p></div>';
		}

		// Toolbar.
		echo '<div class="prc-toolbar"><form method="get" class="prc-range"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="prc-range"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span class="screen-reader-text">' . esc_html__( 'Reporting period', 'publishrank' ) . '</span></label><select id="prc-range" name="range" data-prc-range>';
		foreach ( self::RANGES as $d ) {
			/* translators: %d: number of days. */
			echo '<option value="' . (int) $d . '"' . selected( $range, $d, false ) . '>' . esc_html( 365 === $d ? __( 'Last 12 months', 'publishrank' ) : sprintf( _n( 'Last %d day', 'Last %d days', $d, 'publishrank' ), $d ) ) . '</option>';
		}
		echo '</select><noscript><button class="button">' . esc_html__( 'Apply', 'publishrank' ) . '</button></noscript><span class="prc-range__dates">' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $from . ' 12:00' ) ) . ' – ' . wp_date( get_option( 'date_format' ), strtotime( $to . ' 12:00' ) ) ) . '</span></form>';
		echo '<div class="prc-toolbar__right"><span class="prc-live" data-prc-live="' . esc_url( rest_url( 'publishrank/v1/analytics/live' ) ) . '"><span class="prc-live__dot" aria-hidden="true"></span><strong data-prc-live-count>' . (int) Store::live() . '</strong> ' . esc_html__( 'online now', 'publishrank' ) . '<ul hidden data-prc-live-list></ul></span>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_traffic_export"><input type="hidden" name="range" value="' . (int) $range . '">';
		wp_nonce_field( 'publishrank_traffic_export' );
		echo '<button type="submit" class="button prc-btn"><i class="fa-solid fa-download" aria-hidden="true"></i> ' . esc_html__( 'Export CSV', 'publishrank' ) . '</button></form></div></div>';

		Suite::tabs(
			array(
				'overview'  => __( 'Overview', 'publishrank' ),
				'pages'     => __( 'Pages', 'publishrank' ),
				'sources'   => __( 'Traffic sources', 'publishrank' ),
				'audience'  => __( 'Audience', 'publishrank' ),
				'search'    => __( 'Search performance', 'publishrank' ),
				'editorial' => __( 'Editorial', 'publishrank' ),
				'settings'  => __( 'Settings', 'publishrank' ),
			),
			'overview'
		);

		Suite::panel_open( 'overview', false );
		$this->overview( $series, $tot, $prev, $from, $to );
		Suite::panel_close();
		Suite::panel_open( 'pages' );
		$this->pages( $from, $to, $tot );
		Suite::panel_close();
		Suite::panel_open( 'sources' );
		$this->sources( $series, $from, $to );
		Suite::panel_close();
		Suite::panel_open( 'audience' );
		$this->audience( $from, $to );
		Suite::panel_close();
		Suite::panel_open( 'search' );
		$this->search( $from, $to, $range );
		Suite::panel_close();
		Suite::panel_open( 'editorial' );
		$this->editorial( $range );
		Suite::panel_close();
		Suite::panel_open( 'settings' );
		$this->settings();
		Suite::panel_close();
		echo '</div>';
	}

	private function overview( array $series, array $tot, array $prev, $from, $to ) {
		$col = static function ( $key ) use ( $series ) {
			return array_values( array_map( static function ( $r ) use ( $key ) { return $r[ $key ]; }, $series ) );
		};
		$avg      = $tot['sessions'] ? $tot['secs'] / $tot['sessions'] : 0;
		$pavg     = $prev['sessions'] ? $prev['secs'] / $prev['sessions'] : 0;
		$rate     = $tot['sessions'] ? 100 * $tot['engaged'] / $tot['sessions'] : 0;
		$prate    = $prev['sessions'] ? 100 * $prev['engaged'] / $prev['sessions'] : 0;
		$pps      = $tot['sessions'] ? $tot['views'] / $tot['sessions'] : 0;
		$ppps     = $prev['sessions'] ? $prev['views'] / $prev['sessions'] : 0;
		$avg_line = array();
		foreach ( $series as $r ) {
			$avg_line[] = $r['sessions'] ? round( $r['secs'] / $r['sessions'] ) : 0;
		}
		$rate_line = array();
		foreach ( $series as $r ) {
			$rate_line[] = $r['sessions'] ? round( 100 * $r['engaged'] / $r['sessions'], 1 ) : 0;
		}

		echo '<div class="prc-kpis">';
		self::kpi( __( 'Visitors', 'publishrank' ), number_format_i18n( $tot['visitors'] ), self::delta( $tot['visitors'], $prev['visitors'] ), 'fa-users', 'plum', $col( 'visitors' ), __( 'Unique visitors per day, added up over the period.', 'publishrank' ) );
		self::kpi( __( 'Page views', 'publishrank' ), number_format_i18n( $tot['views'] ), self::delta( $tot['views'], $prev['views'] ), 'fa-eye', 'teal', $col( 'views' ) );
		self::kpi( __( 'Visits', 'publishrank' ), number_format_i18n( $tot['sessions'] ), self::delta( $tot['sessions'], $prev['sessions'] ), 'fa-door-open', 'brass', $col( 'sessions' ), __( 'A visit ends after 30 minutes without activity.', 'publishrank' ) );
		self::kpi( __( 'Avg. time on site', 'publishrank' ), self::duration( $avg ), self::delta( $avg, $pavg ), 'fa-clock', 'slate', $avg_line );
		self::kpi( __( 'Engagement rate', 'publishrank' ), number_format_i18n( $rate, 1 ) . '%', self::delta( $rate, $prate ), 'fa-bolt', 'coral', $rate_line, __( 'Visits that read a second page or stayed at least 10 seconds.', 'publishrank' ) );
		self::kpi( __( 'Pages per visit', 'publishrank' ), number_format_i18n( $pps, 2 ), self::delta( $pps, $ppps ), 'fa-layer-group', 'sage', array_map( static function ( $r ) { return $r['sessions'] ? $r['views'] / $r['sessions'] : 0; }, array_values( $series ) ) );
		echo '</div>';

		$labels = array_keys( $series );
		$lines  = array(
			array( 'name' => __( 'Visitors', 'publishrank' ), 'tone' => 'plum', 'values' => $col( 'visitors' ) ),
			array( 'name' => __( 'Page views', 'publishrank' ), 'tone' => 'teal', 'values' => $col( 'views' ) ),
			array( 'name' => __( 'Visits', 'publishrank' ), 'tone' => 'brass', 'values' => $col( 'sessions' ) ),
		);
		$gsc = SearchConsoleClient::metrics();
		if ( ! empty( $gsc['days'] ) && is_array( $gsc['days'] ) ) {
			$clicks = array();
			foreach ( $labels as $d ) {
				$clicks[] = isset( $gsc['days'][ $d ]['clicks'] ) ? (int) $gsc['days'][ $d ]['clicks'] : 0;
			}
			if ( array_sum( $clicks ) ) {
				$lines[] = array( 'name' => __( 'Google clicks', 'publishrank' ), 'tone' => 'slate', 'values' => $clicks );
			}
		}

		echo '<div class="prc-grid prc-grid--main">';
		self::card_open( __( 'Traffic overview', 'publishrank' ), 'fa-chart-line', __( 'Click a name to hide or show it. Hover the chart for daily numbers.', 'publishrank' ), '', 'prc-span-2' );
		echo Charts::line( $labels, $lines, true, 320 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data attribute escaped inside.
		self::card_close();

		$sources = Store::top( 'source', $from, $to, 10, 'sessions' );
		$names   = Classifier::source_labels();
		$tones   = array( 'organic' => 'plum', 'direct' => 'teal', 'referral' => 'brass', 'social' => 'slate', 'ai' => 'coral', 'email' => 'sage', 'paid' => 'rose' );
		$seg     = array();
		foreach ( $sources as $s ) {
			$seg[] = array( 'label' => isset( $names[ $s['val'] ] ) ? $names[ $s['val'] ] : ( '' === $s['val'] ? __( 'Other', 'publishrank' ) : $s['val'] ), 'value' => $s['sessions'], 'color' => isset( $tones[ $s['val'] ] ) ? $tones[ $s['val'] ] : 'ink' );
		}
		self::card_open( __( 'Traffic sources', 'publishrank' ), 'fa-chart-pie', __( 'Share of visits', 'publishrank' ) );
		echo $seg ? Charts::donut( $seg, number_format_i18n( $tot['sessions'] ), __( 'visits', 'publishrank' ) ) : '<p class="prc-empty">' . esc_html__( 'No visits yet in this period.', 'publishrank' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		echo '</div>';

		echo '<div class="prc-grid prc-grid--three">';
		self::card_open( __( 'Top pages', 'publishrank' ), 'fa-file-lines', '', '<a class="prc-link" href="#pages">' . esc_html__( 'View all', 'publishrank' ) . '</a>' );
		$pages = Store::top( 'page', $from, $to, 7 );
		if ( $pages ) {
			echo '<table class="prc-table"><thead><tr><th>' . esc_html__( 'Page', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Views', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Share', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $pages as $p ) {
				$share = $tot['views'] ? 100 * $p['views'] / $tot['views'] : 0;
				echo '<tr><td class="prc-table__page"><a href="' . esc_url( home_url( $p['val'] ) ) . '" target="_blank" rel="noopener">' . esc_html( self::page_label( $p['val'] ) ) . '</a></td><td class="num">' . esc_html( number_format_i18n( $p['views'] ) ) . '</td><td class="num"><span class="prc-share"><span style="width:' . esc_attr( (string) round( $share, 1 ) ) . '%"></span></span>' . esc_html( number_format_i18n( $share, 1 ) ) . '%</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="prc-empty">' . esc_html__( 'No page views yet in this period.', 'publishrank' ) . '</p>';
		}
		self::card_close();

		self::card_open( __( 'Countries', 'publishrank' ), 'fa-earth-americas', '', '<a class="prc-link" href="#audience">' . esc_html__( 'View all', 'publishrank' ) . '</a>' );
		$countries = Store::top( 'country', $from, $to, 6, 'visitors' );
		$map       = array();
		foreach ( Store::top( 'country', $from, $to, 250, 'visitors' ) as $c ) {
			if ( '' !== $c['val'] ) {
				$map[ $c['val'] ] = $c['visitors'];
			}
		}
		echo Charts::world( $map ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$rows = array();
		foreach ( $countries as $c ) {
			$rows[] = array( 'label' => Geo::name( $c['val'] ), 'value' => $c['visitors'], 'icon' => Geo::flag( $c['val'] ) );
		}
		echo Charts::bars( $rows, 0, '', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();

		self::card_open( __( 'Live activity', 'publishrank' ), 'fa-signal', __( 'Most recent visits (refreshes every 30 seconds)', 'publishrank' ) );
		echo '<div data-prc-live="' . esc_url( rest_url( 'publishrank/v1/analytics/live' ) ) . '"><p class="prc-live-big"><strong data-prc-live-count>' . (int) Store::live() . '</strong> ' . esc_html__( 'visitors in the last 5 minutes', 'publishrank' ) . '</p><ul class="prc-activity" data-prc-live-list>' . self::recent_html() . '</ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- recent_html escapes every value.
		self::card_close();
		echo '</div>';

		// Monthly growth bars (last 12 months) like a classic growth chart.
		$months = array();
		list( $yfrom ) = self::window( 365 );
		foreach ( Store::series( $yfrom, $to ) as $d => $r ) {
			$m = substr( $d, 0, 7 );
			$months[ $m ] = ( isset( $months[ $m ] ) ? $months[ $m ] : 0 ) + $r['visitors'];
		}
		echo '<div class="prc-grid prc-grid--two">';
		self::card_open( __( 'Growth', 'publishrank' ), 'fa-arrow-trend-up', __( 'Visitors per month over the last 12 months', 'publishrank' ) );
		$max = max( 1, $months ? max( $months ) : 1 );
		echo '<div class="prc-columns" role="img" aria-label="' . esc_attr__( 'Visitors per month', 'publishrank' ) . '">';
		foreach ( $months as $m => $v ) {
			echo '<div class="prc-columns__col" title="' . esc_attr( wp_date( 'F Y', strtotime( $m . '-15' ) ) . ': ' . number_format_i18n( $v ) ) . '"><span class="prc-columns__val">' . esc_html( $v >= 1000 ? number_format_i18n( $v / 1000, 1 ) . 'k' : (string) $v ) . '</span><span class="prc-columns__bar" style="height:' . esc_attr( (string) max( 2, round( 100 * $v / $max ) ) ) . '%"></span><span class="prc-columns__lbl">' . esc_html( wp_date( 'M', strtotime( $m . '-15' ) ) ) . '</span></div>';
		}
		echo '</div>';
		self::card_close();

		self::card_open( __( 'Devices', 'publishrank' ), 'fa-mobile-screen', __( 'Share of visitors', 'publishrank' ) );
		$dev   = Store::top( 'device', $from, $to, 5, 'visitors' );
		$dname = array( 'desktop' => __( 'Desktop', 'publishrank' ), 'mobile' => __( 'Mobile', 'publishrank' ), 'tablet' => __( 'Tablet', 'publishrank' ) );
		$dtone = array( 'desktop' => 'plum', 'mobile' => 'teal', 'tablet' => 'brass' );
		$seg   = array();
		foreach ( $dev as $d ) {
			$seg[] = array( 'label' => isset( $dname[ $d['val'] ] ) ? $dname[ $d['val'] ] : $d['val'], 'value' => $d['visitors'], 'color' => isset( $dtone[ $d['val'] ] ) ? $dtone[ $d['val'] ] : 'slate' );
		}
		echo $seg ? Charts::donut( $seg, number_format_i18n( $tot['visitors'] ), __( 'visitors', 'publishrank' ) ) : '<p class="prc-empty">' . esc_html__( 'No visitors yet in this period.', 'publishrank' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		echo '</div>';
	}

	private function pages( $from, $to, array $tot ) {
		$pages = Store::top( 'page', $from, $to, 200 );
		self::card_open( __( 'All pages', 'publishrank' ), 'fa-file-lines', __( 'Entrances are visits that started on the page. Time is the average engaged time per view.', 'publishrank' ), '<input type="search" class="prc-filter" placeholder="' . esc_attr__( 'Filter pages…', 'publishrank' ) . '" data-prc-filter="#prc-pages-table" aria-label="' . esc_attr__( 'Filter pages', 'publishrank' ) . '">' );
		if ( ! $pages ) {
			echo '<p class="prc-empty">' . esc_html__( 'No page views yet in this period.', 'publishrank' ) . '</p>';
			self::card_close();
			return;
		}
		echo '<div class="prc-table-wrap"><table class="prc-table prc-table--full" id="prc-pages-table"><thead><tr><th>#</th><th>' . esc_html__( 'Page', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Views', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Visitors', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Entrances', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Avg. time', 'publishrank' ) . '</th><th>' . esc_html__( 'Share of views', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $pages as $i => $p ) {
			$share = $tot['views'] ? 100 * $p['views'] / $tot['views'] : 0;
			echo '<tr><td class="prc-muted">' . (int) ( $i + 1 ) . '</td><td class="prc-table__page"><a href="' . esc_url( home_url( $p['val'] ) ) . '" target="_blank" rel="noopener">' . esc_html( self::page_label( $p['val'] ) ) . '</a></td><td class="num">' . esc_html( number_format_i18n( $p['views'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $p['visitors'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $p['sessions'] ) ) . '</td><td class="num">' . esc_html( self::duration( $p['views'] ? $p['secs'] / $p['views'] : 0 ) ) . '</td><td><span class="prc-share prc-share--wide"><span style="width:' . esc_attr( (string) round( $share, 1 ) ) . '%"></span></span> ' . esc_html( number_format_i18n( $share, 1 ) ) . '%</td></tr>';
		}
		echo '</tbody></table></div>';
		self::card_close();
	}

	private function sources( array $series, $from, $to ) {
		$names   = Classifier::source_labels();
		$tones   = array( 'organic' => 'plum', 'direct' => 'teal', 'referral' => 'brass', 'social' => 'slate', 'ai' => 'coral', 'email' => 'sage', 'paid' => 'rose' );
		$labels  = array_keys( $series );
		$lines   = array();
		foreach ( array_keys( $names ) as $src ) {
			$daily = Store::series( $from, $to, 'source', $src );
			$vals  = array_values( array_map( static function ( $r ) { return $r['sessions']; }, $daily ) );
			if ( array_sum( $vals ) ) {
				$lines[] = array( 'name' => $names[ $src ], 'tone' => $tones[ $src ], 'values' => $vals );
			}
		}
		self::card_open( __( 'Visits by source', 'publishrank' ), 'fa-chart-area', __( 'How each channel trends over time', 'publishrank' ) );
		echo Charts::line( $labels, $lines, true, 280 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();

		$src_rows = array();
		foreach ( Store::top( 'source', $from, $to, 10, 'sessions' ) as $s ) {
			$rate       = $s['sessions'] ? 100 * $s['engaged'] / $s['sessions'] : 0;
			$src_rows[] = array( 'label' => isset( $names[ $s['val'] ] ) ? $names[ $s['val'] ] : $s['val'], 'value' => $s['sessions'], 'tone' => isset( $tones[ $s['val'] ] ) ? $tones[ $s['val'] ] : 'ink', 'rate' => $rate, 'views' => $s['views'] );
		}
		echo '<div class="prc-grid prc-grid--two">';
		self::card_open( __( 'Channels', 'publishrank' ), 'fa-diagram-project' );
		if ( $src_rows ) {
			echo '<table class="prc-table"><thead><tr><th>' . esc_html__( 'Channel', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Visits', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Page views', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Engagement', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $src_rows as $r ) {
				echo '<tr><td><span class="prc-dot prc-dot--' . esc_attr( $r['tone'] ) . '"></span>' . esc_html( $r['label'] ) . '</td><td class="num">' . esc_html( number_format_i18n( $r['value'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $r['views'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $r['rate'], 1 ) ) . '%</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="prc-empty">' . esc_html__( 'No visits yet in this period.', 'publishrank' ) . '</p>';
		}
		self::card_close();
		self::card_open( __( 'Referring websites', 'publishrank' ), 'fa-link', __( 'Sites, social networks and apps that sent visitors', 'publishrank' ) );
		$rows = array();
		foreach ( Store::top( 'ref', $from, $to, 12, 'sessions' ) as $r ) {
			$rows[] = array( 'label' => $r['val'], 'value' => $r['sessions'], 'tone' => 'brass' );
		}
		echo Charts::bars( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		echo '</div><div class="prc-grid prc-grid--three">';
		self::card_open( __( 'Search engines', 'publishrank' ), 'fa-magnifying-glass', __( 'Includes Bing, DuckDuckGo and others Search Console cannot see', 'publishrank' ) );
		$rows = array();
		foreach ( Store::top( 'engine', $from, $to, 10, 'sessions' ) as $r ) {
			$rows[] = array( 'label' => $r['val'], 'value' => $r['sessions'], 'tone' => 'plum' );
		}
		echo Charts::bars( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		self::card_open( __( 'AI assistants', 'publishrank' ), 'fa-robot', __( 'Visitors who clicked a link in ChatGPT, Perplexity, Gemini, Copilot…', 'publishrank' ) );
		$rows = array();
		foreach ( Store::top( 'ai', $from, $to, 10, 'sessions' ) as $r ) {
			$rows[] = array( 'label' => $r['val'], 'value' => $r['sessions'], 'tone' => 'coral' );
		}
		echo Charts::bars( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		self::card_open( __( 'Campaigns', 'publishrank' ), 'fa-bullhorn', __( 'From utm_campaign links', 'publishrank' ) );
		$rows = array();
		foreach ( Store::top( 'campaign', $from, $to, 10, 'sessions' ) as $r ) {
			$rows[] = array( 'label' => $r['val'], 'value' => $r['sessions'], 'tone' => 'teal' );
		}
		echo Charts::bars( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		echo '</div>';
	}

	private function audience( $from, $to ) {
		$all = Store::top( 'country', $from, $to, 250, 'visitors' );
		$map = array();
		foreach ( $all as $c ) {
			if ( '' !== $c['val'] ) {
				$map[ $c['val'] ] = $c['visitors'];
			}
		}
		$total = array_sum( array_map( static function ( $c ) { return $c['visitors']; }, $all ) );
		echo '<div class="prc-grid prc-grid--main">';
		self::card_open( __( 'Visitors around the world', 'publishrank' ), 'fa-earth-europe', __( 'Country comes from your host or CDN when available, otherwise from the visitor\'s time zone. No IP addresses are stored.', 'publishrank' ), '', 'prc-span-2' );
		echo Charts::world( $map ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		self::card_open( __( 'Top countries', 'publishrank' ), 'fa-flag' );
		if ( $all ) {
			echo '<table class="prc-table"><thead><tr><th>' . esc_html__( 'Country', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Visitors', 'publishrank' ) . '</th><th class="num">%</th></tr></thead><tbody>';
			foreach ( array_slice( $all, 0, 15 ) as $c ) {
				echo '<tr><td class="prc-nowrap"><span aria-hidden="true">' . esc_html( Geo::flag( $c['val'] ) ) . '</span> ' . esc_html( Geo::name( $c['val'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $c['visitors'] ) ) . '</td><td class="num">' . esc_html( $total ? number_format_i18n( 100 * $c['visitors'] / $total, 1 ) : '0' ) . '%</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="prc-empty">' . esc_html__( 'No visitors yet in this period.', 'publishrank' ) . '</p>';
		}
		self::card_close();
		echo '</div><div class="prc-grid prc-grid--three">';
		$dname = array( 'desktop' => __( 'Desktop', 'publishrank' ), 'mobile' => __( 'Mobile', 'publishrank' ), 'tablet' => __( 'Tablet', 'publishrank' ) );
		$dtone = array( 'desktop' => 'plum', 'mobile' => 'teal', 'tablet' => 'brass' );
		self::card_open( __( 'Devices', 'publishrank' ), 'fa-laptop' );
		$seg = array();
		foreach ( Store::top( 'device', $from, $to, 5, 'visitors' ) as $d ) {
			$seg[] = array( 'label' => isset( $dname[ $d['val'] ] ) ? $dname[ $d['val'] ] : $d['val'], 'value' => $d['visitors'], 'color' => isset( $dtone[ $d['val'] ] ) ? $dtone[ $d['val'] ] : 'slate' );
		}
		echo $seg ? Charts::donut( $seg, '', __( 'visitors', 'publishrank' ) ) : '<p class="prc-empty">' . esc_html__( 'No visitors yet in this period.', 'publishrank' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		foreach ( array( 'browser' => array( __( 'Browsers', 'publishrank' ), 'fa-window-maximize', 'teal' ), 'os' => array( __( 'Operating systems', 'publishrank' ), 'fa-desktop', 'slate' ) ) as $dim => $meta ) {
			self::card_open( $meta[0], $meta[1] );
			$rows = array();
			foreach ( Store::top( $dim, $from, $to, 8, 'visitors' ) as $r ) {
				$rows[] = array( 'label' => $r['val'], 'value' => $r['visitors'], 'tone' => $meta[2] );
			}
			echo Charts::bars( $rows, 0, '', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			self::card_close();
		}
		echo '</div>';
		self::card_open( __( 'When visitors arrive', 'publishrank' ), 'fa-calendar-week', __( 'Page views by weekday and hour (site time zone). Darker means busier — useful for scheduling posts.', 'publishrank' ) );
		echo Charts::heatmap( Store::heatmap( $from, $to ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
	}

	private function search( $from, $to, $range ) {
		$gsc       = SearchConsoleClient::metrics();
		$connected = SearchConsoleClient::connected();
		$days      = ! empty( $gsc['days'] ) && is_array( $gsc['days'] ) ? $gsc['days'] : array();
		$series    = Store::series( $from, $to );
		$organic   = Store::series( $from, $to, 'source', 'organic' );
		$labels    = array_keys( $series );
		$clicks    = array();
		$impr      = array();
		$ctr       = array();
		$pos       = array();
		foreach ( $labels as $d ) {
			$r        = isset( $days[ $d ] ) ? $days[ $d ] : array();
			$clicks[] = (int) ( $r['clicks'] ?? 0 );
			$impr[]   = (int) ( $r['impressions'] ?? 0 );
			$ctr[]    = round( 100 * (float) ( $r['ctr'] ?? 0 ), 2 );
			$pos[]    = round( (float) ( $r['position'] ?? 0 ), 1 );
		}
		$tc  = array_sum( $clicks );
		$ti  = array_sum( $impr );
		$pw  = array_filter( $pos );
		$org = array_sum( array_map( static function ( $r ) { return $r['sessions']; }, $organic ) );

		echo '<div class="prc-kpis prc-kpis--four">';
		self::kpi( __( 'Google clicks', 'publishrank' ), number_format_i18n( $tc ), '<span class="prc-delta prc-delta--flat">Google</span>', 'fa-arrow-pointer', 'plum', $clicks, '', __( 'Search Console', 'publishrank' ) );
		self::kpi( __( 'Impressions', 'publishrank' ), number_format_i18n( $ti ), '<span class="prc-delta prc-delta--flat">Google</span>', 'fa-eye', 'teal', $impr, '', __( 'Search Console', 'publishrank' ) );
		self::kpi( __( 'Click-through rate', 'publishrank' ), $ti ? number_format_i18n( 100 * $tc / $ti, 2 ) . '%' : '—', '<span class="prc-delta prc-delta--flat">Google</span>', 'fa-percent', 'brass', $ctr, '', __( 'Search Console', 'publishrank' ) );
		self::kpi( __( 'Search visits (all engines)', 'publishrank' ), number_format_i18n( $org ), '<span class="prc-delta prc-delta--flat">' . esc_html__( 'measured', 'publishrank' ) . '</span>', 'fa-magnifying-glass', 'slate', array_values( array_map( static function ( $r ) { return $r['sessions']; }, $organic ) ), __( 'Real visits arriving from any search engine, measured on your site.', 'publishrank' ), __( 'on your site', 'publishrank' ) );
		echo '</div>';

		if ( ! $connected || ! $days ) {
			echo '<div class="prc-callout"><i class="fa-brands fa-google" aria-hidden="true"></i><div><strong>' . esc_html__( 'Connect Google Search Console to add clicks, impressions, rankings and top searches.', 'publishrank' ) . '</strong><p>' . esc_html__( 'Search visits from every engine are already measured above without it.', 'publishrank' ) . '</p></div><a class="button button-primary" href="' . esc_url( Branding::page_url( 'publishrank-search-console' ) ) . '">' . esc_html__( 'Connect Search Console', 'publishrank' ) . '</a></div>';
		}

		echo '<div class="prc-grid prc-grid--two">';
		self::card_open( __( 'Search clicks vs real search visits', 'publishrank' ), 'fa-chart-line', __( 'Google-reported clicks next to visits your site actually received from all search engines', 'publishrank' ) );
		echo Charts::line( $labels, array( array( 'name' => __( 'Google clicks', 'publishrank' ), 'tone' => 'plum', 'values' => $clicks ), array( 'name' => __( 'Search visits (all engines)', 'publishrank' ), 'tone' => 'teal', 'values' => array_values( array_map( static function ( $r ) { return $r['sessions']; }, $organic ) ) ) ), true, 260 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		self::card_open( __( 'Impressions and average position', 'publishrank' ), 'fa-ranking-star', __( 'How often you appeared in Google, and where', 'publishrank' ) );
		echo Charts::line( $labels, array( array( 'name' => __( 'Impressions', 'publishrank' ), 'tone' => 'brass', 'values' => $impr ) ), true, 180 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="prc-stat-line"><span>' . esc_html__( 'Average position', 'publishrank' ) . '</span><strong>' . esc_html( $pw ? number_format_i18n( array_sum( $pw ) / count( $pw ), 1 ) : '—' ) . '</strong></p>';
		self::card_close();
		echo '</div>';

		$dims = SearchConsoleClient::dimensions();
		echo '<div class="prc-grid prc-grid--two">';
		foreach ( array( 'query' => array( __( 'Top searches', 'publishrank' ), 'fa-magnifying-glass', __( 'Search', 'publishrank' ) ), 'page' => array( __( 'Top pages in Google', 'publishrank' ), 'fa-file-lines', __( 'Page', 'publishrank' ) ) ) as $dim => $meta ) {
			self::card_open( $meta[0], $meta[1], ! empty( $dims['start_date'] ) ? $dims['start_date'] . ' – ' . $dims['end_date'] : '' );
			$rows = isset( $dims[ $dim ] ) && is_array( $dims[ $dim ] ) ? array_slice( $dims[ $dim ], 0, 15 ) : array();
			if ( $rows ) {
				echo '<table class="prc-table"><thead><tr><th>' . esc_html( $meta[2] ) . '</th><th class="num">' . esc_html__( 'Clicks', 'publishrank' ) . '</th><th class="num">' . esc_html__( 'Impr.', 'publishrank' ) . '</th><th class="num">CTR</th><th class="num">' . esc_html__( 'Pos.', 'publishrank' ) . '</th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$key = 'page' === $dim ? (string) wp_parse_url( $r['key'], PHP_URL_PATH ) : $r['key'];
					echo '<tr><td class="prc-table__page">' . esc_html( 'page' === $dim ? self::page_label( $key ) : $key ) . '</td><td class="num">' . esc_html( number_format_i18n( $r['clicks'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $r['impressions'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( 100 * $r['ctr'], 1 ) ) . '%</td><td class="num">' . esc_html( number_format_i18n( $r['position'], 1 ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p class="prc-empty">' . esc_html( $connected ? __( 'Refresh Search Console data to load this list.', 'publishrank' ) : __( 'Available after connecting Search Console.', 'publishrank' ) ) . '</p>';
			}
			self::card_close();
		}
		echo '</div>';
	}

	private function editorial( $range ) {
		$data = AnalyticsCollector::snapshots();
		ksort( $data );
		$data   = array_slice( $data, -min( 365, $range ), null, true );
		$labels = array_keys( $data );
		$get    = static function ( $k ) use ( $data ) {
			return array_values( array_map( static function ( $r ) use ( $k ) { return (int) ( $r[ $k ] ?? 0 ); }, $data ) );
		};
		$latest = $data ? end( $data ) : array();
		echo '<div class="prc-kpis prc-kpis--four">';
		self::kpi( __( 'Editorial SEO score', 'publishrank' ), ! empty( $latest['seo_score_sample_size'] ) ? (int) $latest['seo_score_average'] . '/100' : '—', '<span class="prc-delta prc-delta--flat">' . esc_html__( 'latest', 'publishrank' ) . '</span>', 'fa-gauge-high', 'plum', $get( 'seo_score_average' ), '', '' );
		self::kpi( __( 'Published articles', 'publishrank' ), number_format_i18n( (int) ( $latest['published_posts'] ?? 0 ) ), '<span class="prc-delta prc-delta--flat">' . esc_html__( 'latest', 'publishrank' ) . '</span>', 'fa-newspaper', 'teal', $get( 'published_posts' ), '', '' );
		self::kpi( __( 'IndexNow accepted', 'publishrank' ), number_format_i18n( array_sum( $get( 'indexnow_accepted' ) ) ), '<span class="prc-delta prc-delta--flat">' . esc_html__( 'period', 'publishrank' ) . '</span>', 'fa-paper-plane', 'brass', $get( 'indexnow_accepted' ), '', '' );
		self::kpi( __( 'IndexNow failed', 'publishrank' ), number_format_i18n( array_sum( $get( 'indexnow_failed' ) ) ), '<span class="prc-delta prc-delta--flat">' . esc_html__( 'period', 'publishrank' ) . '</span>', 'fa-circle-exclamation', 'coral', $get( 'indexnow_failed' ), '', '' );
		echo '</div>';
		if ( ! $data ) {
			echo '<p class="prc-empty">' . esc_html__( 'No daily snapshots yet. The first one is created on the next scheduled visit or when a published article changes.', 'publishrank' ) . '</p>';
			return;
		}
		echo '<div class="prc-grid prc-grid--two">';
		self::card_open( __( 'Editorial SEO score trend', 'publishrank' ), 'fa-chart-line', __( 'Average checklist score of the 50 most recent articles. Not a Google score.', 'publishrank' ) );
		echo Charts::line( $labels, array( array( 'name' => __( 'Average score', 'publishrank' ), 'tone' => 'plum', 'values' => $get( 'seo_score_average' ), 'avg' => true ), array( 'name' => __( 'Strong articles', 'publishrank' ), 'tone' => 'teal', 'values' => $get( 'seo_score_strong' ), 'avg' => true ), array( 'name' => __( 'Priority fixes', 'publishrank' ), 'tone' => 'coral', 'values' => $get( 'seo_score_priority' ), 'avg' => true ) ), true, 260 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		self::card_open( __( 'IndexNow submissions', 'publishrank' ), 'fa-paper-plane', __( 'Accepted means the endpoint acknowledged the request, not that a page was indexed.', 'publishrank' ) );
		echo Charts::line( $labels, array( array( 'name' => __( 'Accepted', 'publishrank' ), 'tone' => 'teal', 'values' => $get( 'indexnow_accepted' ) ), array( 'name' => __( 'Failed', 'publishrank' ), 'tone' => 'coral', 'values' => $get( 'indexnow_failed' ) ) ), true, 260 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::card_close();
		echo '</div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_analytics_export">';
		wp_nonce_field( 'publishrank_analytics_export' );
		echo '<button class="button prc-btn" type="submit"><i class="fa-solid fa-download" aria-hidden="true"></i> ' . esc_html__( 'Export editorial data (CSV)', 'publishrank' ) . '</button></form>';
	}

	private function settings() {
		$s = Store::settings();
		self::card_open( __( 'Visitor tracking', 'publishrank' ), 'fa-sliders', __( 'Privacy-friendly by design: no cookies, no IP addresses stored, nothing sent to other companies.', 'publishrank' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="prc-form"><input type="hidden" name="action" value="publishrank_traffic_settings">';
		wp_nonce_field( 'publishrank_traffic_settings' );
		$toggles = array(
			'enabled'       => array( __( 'Measure visitors', 'publishrank' ), __( 'Adds a tiny script (under 1 KB) to your pages. It works with page caching.', 'publishrank' ) ),
			'exclude_staff' => array( __( 'Don\'t count logged-in editors and admins', 'publishrank' ), __( 'Your own visits won\'t inflate the numbers.', 'publishrank' ) ),
			'respect_dnt'   => array( __( 'Respect "Do Not Track" and Global Privacy Control', 'publishrank' ), __( 'Visitors who asked not to be tracked are skipped.', 'publishrank' ) ),
		);
		foreach ( $toggles as $k => $t ) {
			echo '<label class="prc-toggle"><input type="checkbox" name="traffic[' . esc_attr( $k ) . ']" value="1"' . checked( ! empty( $s[ $k ] ), true, false ) . '><span class="prc-toggle__ui" aria-hidden="true"></span><span><strong>' . esc_html( $t[0] ) . '</strong><small>' . esc_html( $t[1] ) . '</small></span></label>';
		}
		echo '<label class="prc-field"><span>' . esc_html__( 'Keep daily totals for', 'publishrank' ) . '</span><select name="traffic[retention_days]">';
		foreach ( array( 90 => __( '3 months', 'publishrank' ), 180 => __( '6 months', 'publishrank' ), 400 => __( '13 months', 'publishrank' ), 760 => __( '25 months', 'publishrank' ), 1100 => __( '3 years', 'publishrank' ) ) as $d => $l ) {
			echo '<option value="' . (int) $d . '"' . selected( (int) $s['retention_days'], $d, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label>';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<label class="prc-check"><input type="checkbox" name="reset_data" value="1"> ' . esc_html__( 'Also delete all collected visitor data now', 'publishrank' ) . '</label>';
		}
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'publishrank' ) . '</button></p></form>';
		self::card_close();
		self::card_open( __( 'How it works', 'publishrank' ), 'fa-circle-info' );
		echo '<ul class="prc-list"><li>' . esc_html__( 'Each visitor is recognised for one day with a scrambled code that changes every day, so nobody can be followed over time.', 'publishrank' ) . '</li><li>' . esc_html__( 'Search engines, AI crawlers, uptime monitors and other bots are left out automatically.', 'publishrank' ) . '</li><li>' . esc_html__( 'Country comes from your host or CDN (Cloudflare, CloudFront, Fastly…) when it provides it, otherwise from the visitor\'s time zone.', 'publishrank' ) . '</li><li>' . esc_html__( 'Because no cookies are used, a cookie banner is normally not needed for these statistics. Check the rules where you operate.', 'publishrank' ) . '</li></ul>';
		self::card_close();
	}
}
