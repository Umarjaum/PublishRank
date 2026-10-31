<?php
/** Weekly cron audit: sitemap validity, 404 summary, content coverage; optional email digest. */
namespace PublishRankSEO\Monitor;

use PublishRankSEO\Sitemap\Engine\SitemapAudit;

defined( 'ABSPATH' ) || exit;

final class ScheduledAudit {
	const CRON   = 'publishrank_weekly_audit';
	const OPTION = 'publishrank_seo_audit_report';

	public function register() {
		add_action( 'admin_init', array( $this, 'schedule' ) );
		add_action( self::CRON, array( $this, 'run_and_store' ) );
		add_action( 'update_option_publishrank_audit_auto', array( $this, 'reschedule' ), 20 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function schedule() {
		if ( ! get_option( 'publishrank_audit_auto', 1 ) ) {
			return; }
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON ); }
	}

	public function reschedule() {
		$timestamp = wp_next_scheduled( self::CRON );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON ); }
		if ( get_option( 'publishrank_audit_auto', 1 ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON ); }
	}

	public static function clear_schedule() {
		$timestamp = wp_next_scheduled( self::CRON );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON ); }
	}

	/** Build the report; reusable from cron, WP-CLI and the Insights screen. @return array<string,mixed> */
	public static function build( $preview_limit = 5 ) {
		$engine  = ( new \PublishRankSEO\Sitemap\SitemapController() )->engine();
		$sitemap = SitemapAudit::run( $engine, $preview_limit );

		$log = (array) get_option( 'publishrank_seo_404_log', array() );
		usort(
			$log,
			static function ( $a, $b ) {
				return (int) ( $b['count'] ?? 0 ) <=> (int) ( $a['count'] ?? 0 );
			}
		);
		$top404 = array();
		foreach ( array_slice( $log, 0, 5 ) as $entry ) {
			if ( ! empty( $entry['resolved'] ) ) {
				continue; }
			$top404[] = array(
				'path'  => (string) ( $entry['path'] ?? '' ),
				'count' => (int) ( $entry['count'] ?? 0 ),
			);
		}

		$sample              = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		$missing_description = 0;
		$noindexed           = 0;
		$scores              = array(
			'low'  => 0,
			'mid'  => 0,
			'high' => 0,
		);
		$audit               = new \PublishRankSEO\SEO\EditorialAudit();
		foreach ( $sample as $post_id ) {
			if ( '' === \PublishRankSEO\Meta\PostMeta::get( $post_id, \PublishRankSEO\Meta\PostMeta::DESCRIPTION, '' ) ) {
				++$missing_description; }
			if ( false !== strpos( \PublishRankSEO\Meta\PostMeta::get( $post_id, \PublishRankSEO\Meta\PostMeta::ROBOTS, '' ), 'noindex' ) ) {
				++$noindexed; }
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				$score = $audit->analyze( $post )['score'];
				if ( $score >= 75 ) {
					++$scores['high'];
				} elseif ( $score >= 50 ) {
					++$scores['mid'];
				} else {
					++$scores['low']; }
			}
		}

		$status = 'PASS' === $sitemap['status'] && ! $top404 ? 'PASS' : ( 'ERROR' === $sitemap['status'] ? 'ERROR' : 'WARNING' );
		return array(
			'ran_at'  => gmdate( 'c' ),
			'status'  => $status,
			'sitemap' => $sitemap,
			'top_404' => $top404,
			'content' => array(
				'sample'              => count( $sample ),
				'missing_description' => $missing_description,
				'noindexed'           => $noindexed,
				'scores'              => $scores,
			),
		);
	}

	public function run_and_store() {
		$report = self::build();
		update_option( self::OPTION, $report, false );
		$email = sanitize_email( (string) get_option( 'publishrank_audit_email', '' ) );
		if ( $email && 'PASS' !== $report['status'] ) {
			$subject = sprintf( /* translators: %1$s: site name; %2$s: audit status. */ __( '[%1$s] PublishRank weekly SEO audit: %2$s', 'publishrank' ), ( '' !== \PublishRankSEO\Core\PublicationProfile::publication_name() ? \PublishRankSEO\Core\PublicationProfile::publication_name() : 'PublishRank' ), $report['status'] );
			$body    = __( 'Status', 'publishrank' ) . ": {$report['status']}\n" . __( 'Ran at', 'publishrank' ) . ": {$report['ran_at']}\n\n" . wp_json_encode( $report['sitemap']['errors'] ?? array(), JSON_PRETTY_PRINT );
			wp_mail( $email, $subject, $body );
		}
	}

	/** Surface failures on PublishRank admin screens only. */
	public function notice() {
		if ( ! \PublishRankSEO\Core\Branding::is_plugin_screen() ) {
			return; }
		$report = get_option( self::OPTION );
		if ( ! is_array( $report ) || empty( $report['sitemap']['errors'] ) ) {
			return; }
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'PublishRank weekly audit found sitemap errors.', 'publishrank' ) . '</strong> ';
		foreach ( array_slice( (array) $report['sitemap']['errors'], 0, 3 ) as $error ) {
			echo esc_html( (string) $error ) . ' '; }
		echo '<a href="' . esc_url( \PublishRankSEO\Core\Branding::page_url( 'publishrank-insights' ) ) . '">' . esc_html__( 'View Insights', 'publishrank' ) . '</a></p></div>';
	}
}
