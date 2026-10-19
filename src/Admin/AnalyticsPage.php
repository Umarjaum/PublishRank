<?php
/** Local newsroom analytics and accessible trend charts. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\AnalyticsCollector;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Google\SearchConsoleClient;

defined( 'ABSPATH' ) || exit;

final class AnalyticsPage {
	const SLUG = 'publishrank-analytics';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_post_publishrank_analytics_export', array( $this, 'export' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Analytics', 'publishrank' ), __( 'Analytics', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function export() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to export analytics.', 'publishrank' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'publishrank_analytics_export' );
		$data = AnalyticsCollector::snapshots();
		ksort( $data );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=publishrank-local-analytics.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'date_utc', 'published_posts', 'seo_score_average', 'seo_score_sample_size', 'seo_score_strong', 'seo_score_improving', 'seo_score_priority', 'indexnow_accepted', 'indexnow_failed', 'indexnow_events' ) );
		foreach ( $data as $date => $row ) {
			if ( ! is_array( $row ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) { continue; }
			fputcsv( $out, array( $date, (int) ( $row['published_posts'] ?? 0 ), (int) ( $row['seo_score_average'] ?? 0 ), (int) ( $row['seo_score_sample_size'] ?? 0 ), (int) ( $row['seo_score_strong'] ?? 0 ), (int) ( $row['seo_score_improving'] ?? 0 ), (int) ( $row['seo_score_priority'] ?? 0 ), (int) ( $row['indexnow_accepted'] ?? 0 ), (int) ( $row['indexnow_failed'] ?? 0 ), (int) ( $row['indexnow_events'] ?? 0 ) ) );
		}
		fclose( $out );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$range = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $range, array( 7, 30, 90 ), true ) ) { $range = 30; }
		$data = AnalyticsCollector::snapshots();
		ksort( $data );
		$data = array_slice( $data, -$range, null, true );
		$latest = $data ? end( $data ) : array();
		$score = (int) ( $latest['seo_score_average'] ?? 0 );
		$sample = (int) ( $latest['seo_score_sample_size'] ?? 0 );
		$published = (int) ( $latest['published_posts'] ?? 0 );
		$accepted = array_sum( array_map( static function ( $row ) { return (int) ( $row['indexnow_accepted'] ?? 0 ); }, $data ) );
		$failed = array_sum( array_map( static function ( $row ) { return (int) ( $row['indexnow_failed'] ?? 0 ); }, $data ) );
		$last_date = $data ? (string) array_key_last( $data ) : '';
		echo '<div class="wrap publishrank-analytics">';
		AdminAssets::header( __( 'Publisher Analytics', 'publishrank' ), __( 'Local editorial and indexing-operation trends. No visitor tracking or article content is sent to a remote analytics service.', 'publishrank' ) );
		echo '<div class="pr-analytics-toolbar"><div><strong>' . esc_html__( 'Reporting period', 'publishrank' ) . '</strong><div class="pr-analytics-ranges">';
		foreach ( array( 7, 30, 90 ) as $days ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'range' => $days ), admin_url( 'admin.php' ) );
			echo '<a class="button ' . ( $range === $days ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $range === $days ? ' aria-current="page"' : '' ) . '>' . esc_html( sprintf( _n( '%d day', '%d days', $days, 'publishrank' ), $days ) ) . '</a>';
		}
		echo '</div></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_analytics_export">'; wp_nonce_field( 'publishrank_analytics_export' ); submit_button( __( 'Export local data (CSV)', 'publishrank' ), 'secondary', 'submit', false ); echo '</form></div>';
		echo '<div class="rhs-grid pr-analytics-kpis"><section class="rhs-card"><p class="rhs-label">' . esc_html__( 'Latest sampled SEO score', 'publishrank' ) . '</p><p class="rhs-stat">' . esc_html( $sample ? (string) $score : '—' ) . ( $sample ? '<small>/100</small>' : '' ) . '</p><p>' . esc_html( sprintf( __( '%1$d eligible articles sampled; recalculated daily.', 'publishrank' ), $sample ) ) . '</p><a href="' . esc_url( Branding::page_url( 'publishrank-seo-score' ) ) . '">' . esc_html__( 'Run current SEO audit', 'publishrank' ) . '</a></section>';
		echo '<section class="rhs-card"><p class="rhs-label">' . esc_html__( 'Published articles', 'publishrank' ) . '</p><p class="rhs-stat">' . esc_html( (string) $published ) . '</p><p>' . esc_html__( 'Current published count across supported editorial post types.', 'publishrank' ) . '</p></section>';
		echo '<section class="rhs-card"><p class="rhs-label">' . esc_html__( 'IndexNow accepted', 'publishrank' ) . '</p><p class="rhs-stat">' . esc_html( (string) $accepted ) . '</p><p>' . esc_html( sprintf( __( '%d failed response(s) in this period.', 'publishrank' ), $failed ) ) . '</p><a href="' . esc_url( Branding::page_url( 'publishrank-indexnow' ) ) . '">' . esc_html__( 'Review submission log', 'publishrank' ) . '</a></section>';
		echo '<section class="rhs-card"><p class="rhs-label">' . esc_html__( 'Last local snapshot', 'publishrank' ) . '</p><p class="rhs-stat pr-analytics-stat-date">' . esc_html( $last_date ?: '—' ) . '</p><p>' . esc_html__( 'WordPress scheduled snapshots run daily; WP-Cron depends on site traffic.', 'publishrank' ) . '</p></section></div>';
		if ( ! $data ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No daily snapshots are available yet. The first snapshot is created on the next scheduled visit or when a published article changes.', 'publishrank' ) . '</p></div>';
		} else {
			echo '<div class="pr-analytics-grid"><section class="rhs-card pr-analytics-chart-card"><h2>' . esc_html__( 'Editorial SEO score trend', 'publishrank' ) . '</h2><p class="description">' . esc_html__( 'Daily average from a capped sample of the 50 most recent published articles. This is a local editorial checklist, not a search ranking, traffic, or Google score.', 'publishrank' ) . '</p>' . $this->line_chart( $data, 'seo_score_average', 100, __( 'Average editorial score', 'publishrank' ) ) . '</section>';
			echo '<section class="rhs-card pr-analytics-chart-card"><h2>' . esc_html__( 'IndexNow response activity', 'publishrank' ) . '</h2><p class="description">' . esc_html__( 'Accepted means the shared endpoint acknowledged the request; it does not confirm crawling or indexing by a specific engine.', 'publishrank' ) . '</p>' . $this->dual_chart( $data ) . '<p class="pr-analytics-legend"><span class="pr-analytics-legend__accepted">' . esc_html__( 'Accepted', 'publishrank' ) . '</span><span class="pr-analytics-legend__failed">' . esc_html__( 'Failed', 'publishrank' ) . '</span></p></section></div>';
			echo '<section class="rhs-card pr-analytics-table"><h2>' . esc_html__( 'Daily detail', 'publishrank' ) . '</h2><div class="rhs-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date (UTC)', 'publishrank' ) . '</th><th>' . esc_html__( 'Avg. SEO score', 'publishrank' ) . '</th><th>' . esc_html__( 'Sampled', 'publishrank' ) . '</th><th>' . esc_html__( 'Strong', 'publishrank' ) . '</th><th>' . esc_html__( 'Improving', 'publishrank' ) . '</th><th>' . esc_html__( 'Priority', 'publishrank' ) . '</th><th>' . esc_html__( 'IndexNow accepted / failed', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $data, true ) as $date => $row ) {
				echo '<tr><td>' . esc_html( (string) $date ) . '</td><td>' . esc_html( (string) (int) ( $row['seo_score_average'] ?? 0 ) ) . '</td><td>' . esc_html( (string) (int) ( $row['seo_score_sample_size'] ?? 0 ) ) . '</td><td>' . esc_html( (string) (int) ( $row['seo_score_strong'] ?? 0 ) ) . '</td><td>' . esc_html( (string) (int) ( $row['seo_score_improving'] ?? 0 ) ) . '</td><td>' . esc_html( (string) (int) ( $row['seo_score_priority'] ?? 0 ) ) . '</td><td>' . esc_html( (string) (int) ( $row['indexnow_accepted'] ?? 0 ) . ' / ' . (string) (int) ( $row['indexnow_failed'] ?? 0 ) ) . '</td></tr>';
			}
			echo '</tbody></table></div></section>';
		}
		$this->render_search_console();
		echo '</div>';
	}

	private function render_search_console() {
		$metrics = SearchConsoleClient::metrics();
		$connected = SearchConsoleClient::connected();
		echo '<section class="rhs-card pr-analytics-disclosure"><h2>' . esc_html__( 'Google Search Console', 'publishrank' ) . '</h2>';
		if ( $connected && ! empty( $metrics['days'] ) ) {
			$clicks = array_sum( array_map( static function ( $row ) { return (int) ( $row['clicks'] ?? 0 ); }, $metrics['days'] ) );
			$impressions = array_sum( array_map( static function ( $row ) { return (int) ( $row['impressions'] ?? 0 ); }, $metrics['days'] ) );
			echo '<p>' . esc_html( sprintf( __( '%1$s · %2$s to %3$s · fetched %4$s', 'publishrank' ), (string) ( $metrics['property'] ?? '' ), (string) ( $metrics['start_date'] ?? '' ), (string) ( $metrics['end_date'] ?? '' ), (string) ( $metrics['fetched_at'] ?? '' ) ) ) . '</p><div class="rhs-grid pr-analytics-gsc-kpis"><div><span class="rhs-label">' . esc_html__( 'Search clicks', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( $clicks ) ) . '</strong></div><div><span class="rhs-label">' . esc_html__( 'Impressions', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( $impressions ) ) . '</strong></div></div>' . $this->search_console_chart( $metrics['days'] );
			echo '<p class="description">' . esc_html__( 'Clicks and impressions are separately scaled to show direction, not relative magnitude. Search Console data is aggregated and delayed; the newest dates may be incomplete. No query or page-level data is collected in this view.', 'publishrank' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Connect a Google account with access to a verified Search Console property to display clicks, impressions and daily trends. Search Console OAuth is separate from Supabase sign-in.', 'publishrank' ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( Branding::page_url( 'publishrank-search-console' ) ) . '">' . esc_html__( $connected ? 'Manage Search Console connection' : 'Connect Search Console', 'publishrank' ) . '</a></p></section>';
	}

	private function search_console_chart( $data ) {
		$rows = array(); foreach ( $data as $date => $row ) { if ( is_array( $row ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) { $rows[ $date ] = $row; } }
		if ( ! $rows ) { return ''; }
		$max_clicks = 1; $max_impressions = 1;
		foreach ( $rows as $row ) { $max_clicks = max( $max_clicks, (int) ( $row['clicks'] ?? 0 ) ); $max_impressions = max( $max_impressions, (int) ( $row['impressions'] ?? 0 ) ); }
		$clicks = $this->points( $rows, 'clicks', $max_clicks ); $impressions = $this->points( $rows, 'impressions', $max_impressions );
		$serialize = static function ( $points ) { return implode( ' ', array_map( static function ( $point ) { return round( $point[0], 1 ) . ',' . round( $point[1], 1 ); }, $points ) ); };
		return '<div class="pr-chart-scroll"><svg class="pr-chart" viewBox="0 0 720 250" role="img" aria-label="' . esc_attr__( 'Search Console daily clicks and impressions trends', 'publishrank' ) . '"><title>' . esc_html__( 'Search Console daily clicks and impressions', 'publishrank' ) . '</title><line x1="44" y1="30" x2="700" y2="30" class="pr-chart__grid"/><line x1="44" y1="125" x2="700" y2="125" class="pr-chart__grid"/><line x1="44" y1="220" x2="700" y2="220" class="pr-chart__grid"/><polyline points="' . esc_attr( $serialize( $clicks ) ) . '" class="pr-chart__line pr-chart__line--accepted"/><polyline points="' . esc_attr( $serialize( $impressions ) ) . '" class="pr-chart__line pr-chart__line--failed"/></svg></div><p class="pr-analytics-legend"><span class="pr-analytics-legend__accepted">' . esc_html__( 'Clicks (own scale)', 'publishrank' ) . '</span><span class="pr-analytics-legend__failed">' . esc_html__( 'Impressions (own scale)', 'publishrank' ) . '</span></p>';
	}

	private function line_chart( $data, $key, $max_value, $label ) {
		$points = $this->points( $data, $key, $max_value );
		if ( ! $points ) { return '<p class="pr-analytics-empty">' . esc_html__( 'No score observations in this period yet.', 'publishrank' ) . '</p>'; }
		$polyline = implode( ' ', array_map( static function ( $point ) { return round( $point[0], 1 ) . ',' . round( $point[1], 1 ); }, $points ) );
		return '<div class="pr-chart-scroll"><svg class="pr-chart" viewBox="0 0 720 250" role="img" aria-label="' . esc_attr( $label ) . '"><title>' . esc_html( $label ) . '</title><line x1="44" y1="30" x2="700" y2="30" class="pr-chart__grid"/><line x1="44" y1="125" x2="700" y2="125" class="pr-chart__grid"/><line x1="44" y1="220" x2="700" y2="220" class="pr-chart__grid"/><text x="8" y="34" class="pr-chart__label">100</text><text x="20" y="129" class="pr-chart__label">50</text><text x="27" y="224" class="pr-chart__label">0</text><polyline points="' . esc_attr( $polyline ) . '" class="pr-chart__line"/><circle cx="' . esc_attr( (string) round( end( $points )[0], 1 ) ) . '" cy="' . esc_attr( (string) round( end( $points )[1], 1 ) ) . '" r="4" class="pr-chart__dot"/></svg></div>';
	}

	private function dual_chart( $data ) {
		$accepted = $this->points( $data, 'indexnow_accepted', null ); $failed = $this->points( $data, 'indexnow_failed', null );
		if ( ! $accepted && ! $failed ) { return '<p class="pr-analytics-empty">' . esc_html__( 'No IndexNow observations in this period yet.', 'publishrank' ) . '</p>'; }
		$all_values = array(); foreach ( $data as $row ) { $all_values[] = (int) ( $row['indexnow_accepted'] ?? 0 ); $all_values[] = (int) ( $row['indexnow_failed'] ?? 0 ); }
		$maximum = max( 1, max( $all_values ) );
		$accepted = $this->points( $data, 'indexnow_accepted', $maximum ); $failed = $this->points( $data, 'indexnow_failed', $maximum );
		$to_string = static function ( $points ) { return implode( ' ', array_map( static function ( $point ) { return round( $point[0], 1 ) . ',' . round( $point[1], 1 ); }, $points ) ); };
		return '<div class="pr-chart-scroll"><svg class="pr-chart" viewBox="0 0 720 250" role="img" aria-label="' . esc_attr__( 'Daily IndexNow accepted and failed submissions', 'publishrank' ) . '"><title>' . esc_html__( 'IndexNow response activity', 'publishrank' ) . '</title><line x1="44" y1="30" x2="700" y2="30" class="pr-chart__grid"/><line x1="44" y1="125" x2="700" y2="125" class="pr-chart__grid"/><line x1="44" y1="220" x2="700" y2="220" class="pr-chart__grid"/><polyline points="' . esc_attr( $to_string( $accepted ) ) . '" class="pr-chart__line pr-chart__line--accepted"/><polyline points="' . esc_attr( $to_string( $failed ) ) . '" class="pr-chart__line pr-chart__line--failed"/></svg></div>';
	}

	private function points( $data, $key, $maximum ) {
		$rows = array_values( $data ); $dates = array_keys( $data ); $count = count( $rows ); if ( ! $count ) { return array(); }
		if ( null === $maximum ) { $values = array_map( static function ( $row ) use ( $key ) { return (int) ( $row[ $key ] ?? 0 ); }, $rows ); $maximum = max( 1, max( $values ) ); }
		$first_time = strtotime( (string) reset( $dates ) . ' UTC' ); $last_time = strtotime( (string) end( $dates ) . ' UTC' );
		$points = array();
		foreach ( $rows as $index => $row ) {
			$value = max( 0, min( (int) $maximum, (int) ( $row[ $key ] ?? 0 ) ) );
			$time = strtotime( (string) $dates[ $index ] . ' UTC' );
			$x = $count > 1 && $first_time && $last_time > $first_time && $time ? 44 + ( 656 * ( $time - $first_time ) / ( $last_time - $first_time ) ) : 328;
			$y = 220 - ( 190 * $value / max( 1, (int) $maximum ) );
			$points[] = array( $x, $y );
		}
		return $points;
	}
}
