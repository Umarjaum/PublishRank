<?php
/** Collects lightweight, local SEO snapshots for PublishRank analytics. */
namespace PublishRankSEO;

defined( 'ABSPATH' ) || exit;

final class AnalyticsCollector {
	const OPTION = 'publishrank_analytics_snapshots';
	const HOOK = 'publishrank_daily_analytics_snapshot';
	const MAX_DAYS = 30;

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
		$counts = wp_count_posts( 'post' );
		$published = $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
		$index_logs = get_option( 'publishrank_indexnow_log', array() );
		if ( ! is_array( $index_logs ) ) { $index_logs = array(); }
		$accepted = 0; $failed = 0; $recent = 0;
		foreach ( $index_logs as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['time'] ) ) { continue; }
			if ( 0 === strpos( (string) $entry['time'], $today ) ) {
				$recent++;
				if ( isset( $entry['result'] ) && 'accepted' === $entry['result'] ) { $accepted++; }
				if ( isset( $entry['result'] ) && 'failed' === $entry['result'] ) { $failed++; }
			}
		}
		$snaps = get_option( self::OPTION, array() );
		if ( ! is_array( $snaps ) ) { $snaps = array(); }
		$snaps[ $today ] = array(
			'published_posts' => $published,
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
}
