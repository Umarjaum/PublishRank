<?php
/** Collects lightweight, local SEO snapshots for PublishRank analytics. */
namespace PublishRankSEO;

use PublishRankSEO\SEO\ArticleIntelligence;
use PublishRankSEO\SEO\EditorialAudit;

defined( 'ABSPATH' ) || exit;

final class AnalyticsCollector {
	const OPTION = 'publishrank_analytics_snapshots';
	const INDEXNOW_DAILY_OPTION = 'publishrank_indexnow_daily_counts';
	const HOOK = 'publishrank_daily_analytics_snapshot';
	const MAX_DAYS = 90;
	const SCORE_SAMPLE = 50;

	public function register() {
		add_action( 'init', array( $this, 'schedule' ), 20 );
		add_action( self::HOOK, array( $this, 'snapshot' ) );
		add_action( 'publish_post', array( $this, 'maybe_snapshot' ), 20 );
		add_action( 'save_post', array( $this, 'maybe_snapshot' ), 99, 1 );
	}

	public function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
		if ( ! get_option( 'publishrank_analytics_last_snapshot', '' ) ) {
			$this->snapshot();
		}
	}

	public function maybe_snapshot( $post_id = 0 ) {
		if ( $post_id && ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) ) { return; }
		$last = (string) get_option( 'publishrank_analytics_last_snapshot', '' );
		if ( gmdate( 'Y-m-d' ) !== $last ) { $this->snapshot(); }
	}

	public function snapshot() {
		$today = gmdate( 'Y-m-d' );
		$published = 0;
		foreach ( ArticleIntelligence::supported_post_types() as $type ) {
			$counts = wp_count_posts( $type );
			$published += $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		$score_sum = 0; $score_count = 0; $score_bands = array( 'strong' => 0, 'improving' => 0, 'priority' => 0 );
		$sample = get_posts( array( 'post_type' => ArticleIntelligence::supported_post_types(), 'post_status' => 'publish', 'posts_per_page' => self::SCORE_SAMPLE, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => true, 'no_found_rows' => true ) );
		$audit = new EditorialAudit();
		foreach ( (array) $sample as $post ) {
			if ( ! $post instanceof \WP_Post ) { continue; }
			$score = max( 0, min( 100, (int) $audit->analyze( $post )['score'] ) );
			$score_sum += $score; $score_count++;
			$key = $score >= 75 ? 'strong' : ( $score >= 50 ? 'improving' : 'priority' );
			$score_bands[ $key ]++;
		}
		$daily_counts = get_option( self::INDEXNOW_DAILY_OPTION, array() );
		if ( ! is_array( $daily_counts ) ) { $daily_counts = array(); }
		$today_counts = isset( $daily_counts[ $today ] ) && is_array( $daily_counts[ $today ] ) ? $daily_counts[ $today ] : array();
		$accepted = (int) ( $today_counts['accepted'] ?? 0 ); $failed = (int) ( $today_counts['failed'] ?? 0 ); $recent = (int) ( $today_counts['events'] ?? 0 );
		if ( ! $today_counts ) {
			$index_logs = get_option( 'publishrank_indexnow_log', array() );
			if ( ! is_array( $index_logs ) ) { $index_logs = array(); }
			foreach ( $index_logs as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['time'] ) || 0 !== strpos( (string) $entry['time'], $today ) ) { continue; }
				$recent++;
				if ( isset( $entry['result'] ) && 'accepted' === $entry['result'] ) { $accepted++; }
				if ( isset( $entry['result'] ) && 'failed' === $entry['result'] ) { $failed++; }
			}
		}
		$snaps = get_option( self::OPTION, array() );
		if ( ! is_array( $snaps ) ) { $snaps = array(); }
		$snaps[ $today ] = array(
			'published_posts' => $published,
			'seo_score_average' => $score_count ? (int) round( $score_sum / $score_count ) : 0,
			'seo_score_sample_size' => $score_count,
			'seo_score_strong' => $score_bands['strong'],
			'seo_score_improving' => $score_bands['improving'],
			'seo_score_priority' => $score_bands['priority'],
			'indexnow_accepted' => $accepted,
			'indexnow_failed' => $failed,
			'indexnow_events' => $recent,
			'llms_enabled' => (int) (bool) get_option( 'publishrank_llms_txt_enabled', 1 ),
			'indexnow_enabled' => (int) (bool) get_option( 'publishrank_indexnow_enabled', false ),
		);
		ksort( $snaps );
		$snaps = array_slice( $snaps, -self::MAX_DAYS, null, true );
		update_option( self::OPTION, $snaps, false );
		update_option( 'publishrank_analytics_last_snapshot', $today, false );
	}

	public static function snapshots() {
		$data = get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	/** Record one sanitized endpoint response without storing the submitted URL. */
	public static function record_indexnow_result( $result ) {
		$result = sanitize_key( (string) $result );
		if ( ! in_array( $result, array( 'accepted', 'failed' ), true ) ) { return; }
		$today = gmdate( 'Y-m-d' );
		$counts = get_option( self::INDEXNOW_DAILY_OPTION, array() );
		if ( ! is_array( $counts ) ) { $counts = array(); }
		if ( ! isset( $counts[ $today ] ) || ! is_array( $counts[ $today ] ) ) { $counts[ $today ] = array( 'accepted' => 0, 'failed' => 0, 'events' => 0 ); }
		$counts[ $today ][ $result ] = max( 0, (int) ( $counts[ $today ][ $result ] ?? 0 ) ) + 1;
		$counts[ $today ]['events'] = max( 0, (int) ( $counts[ $today ]['events'] ?? 0 ) ) + 1;
		ksort( $counts );
		$counts = array_slice( $counts, -self::MAX_DAYS, null, true );
		update_option( self::INDEXNOW_DAILY_OPTION, $counts, false );
		$snapshots = get_option( self::OPTION, array() );
		if ( is_array( $snapshots ) && isset( $snapshots[ $today ] ) && is_array( $snapshots[ $today ] ) ) {
			$snapshots[ $today ]['indexnow_accepted'] = (int) $counts[ $today ]['accepted'];
			$snapshots[ $today ]['indexnow_failed'] = (int) $counts[ $today ]['failed'];
			$snapshots[ $today ]['indexnow_events'] = (int) $counts[ $today ]['events'];
			update_option( self::OPTION, $snapshots, false );
		}
	}
}
