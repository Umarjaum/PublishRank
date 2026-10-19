<?php
/** Content inventory, score distribution, link and 404 insights for publishers. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Links\LinkManager;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Monitor\ScheduledAudit;
use PublishRankSEO\Redirect\RedirectStore;
use PublishRankSEO\SEO\EditorialAudit;
use PublishRankSEO\SEO\ArticleIntelligence;

defined( 'ABSPATH' ) || exit;

final class InsightsPage {
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'PublishRank Insights', 'publishrank' ), __( 'Insights', 'publishrank' ), 'manage_options', 'publishrank-insights', array( $this, 'page' ) );
	}

	/** Aggregated summary reused by this screen and WP-CLI. @return array<string,mixed> */
	public static function summary() {
		$types = ArticleIntelligence::supported_post_types();
		$published = 0;
		foreach ( $types as $type ) {
			$counts = wp_count_posts( $type );
			$published += (int) ( $counts->publish ?? 0 );
		}
		$ids = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'suppress_filters' => true ) );
		$sample = count( $ids );
		$missing_desc = 0; $missing_image = 0; $cornerstone = 0; $noindexed = 0; $stale = 0; $internal = 0; $external = 0;
		$scores = array( 'low' => 0, 'mid' => 0, 'high' => 0 );
		$audit = new EditorialAudit();
		foreach ( $ids as $post_id ) {
			if ( '' === PostMeta::get( $post_id, PostMeta::DESCRIPTION, '' ) ) { $missing_desc++; }
			if ( ! has_post_thumbnail( $post_id ) ) { $missing_image++; }
			if ( '1' === PostMeta::get( $post_id, PostMeta::CORNERSTONE, '0' ) ) { $cornerstone++; }
			if ( false !== strpos( PostMeta::get( $post_id, PostMeta::ROBOTS, '' ), 'noindex' ) ) { $noindexed++; }
			if ( get_post_time( 'U', true, $post_id ) < strtotime( '-180 days' ) ) { $stale++; }
			$links = LinkManager::counts( $post_id );
			$internal += $links['internal']; $external += $links['external'];
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				$score = $audit->analyze( $post )['score'];
				if ( $score >= 75 ) { $scores['high']++; } elseif ( $score >= 50 ) { $scores['mid']++; } else { $scores['low']++; }
			}
		}
		$redirects = count( RedirectStore::all() );
		$log = (array) get_option( 'publishrank_seo_404_log', array() );
		$unresolved404 = count( array_filter( $log, static function ( $entry ) { return is_array( $entry ) && empty( $entry['resolved'] ); } ) );
		$intel = ArticleIntelligence::overview( array_map( 'intval', $ids ) );
		return array(
			'published' => $published, 'sample' => $sample,
			'coverage'  => array( 'missing_description' => $missing_desc, 'missing_featured_image' => $missing_image, 'cornerstone' => $cornerstone, 'noindexed' => $noindexed, 'stale_180d' => $stale ),
			'scores'    => $scores,
			'links'     => array( 'internal' => $internal, 'external' => $external ),
			'redirects' => $redirects, 'unresolved_404' => $unresolved404,
			'article_intelligence' => $intel,
			'audit'     => get_option( ScheduledAudit::OPTION, array() ),
		);
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
			$s = self::summary();
			$bar = static function ( $part, $total ) {
				$pct = $total > 0 ? round( ( $part / $total ) * 100 ) : 0;
				return '<div class="pr-insights-meter" aria-hidden="true"><span style="width:' . (int) max( 2, $pct ) . '%"></span></div> <span>' . (int) $part . ' (' . (int) $pct . '%)</span>';
		};
		echo '<div class="wrap"><h1>' . esc_html__( 'PublishRank Insights', 'publishrank' ) . '</h1>';
		echo '<p>' . esc_html__( 'Capped, local content intelligence. Sampling is limited to the most recent 200 published editorial articles; no external analytics service is used.', 'publishrank' ) . '</p>';
		echo '<h2>' . esc_html__( 'Content inventory', 'publishrank' ) . ' <span class="description">(' . esc_html( sprintf( /* translators: %d: published posts. */ __( '%d published', 'publishrank' ), $s['published'] ) ) . ', ' . esc_html( sprintf( /* translators: %d: sampled posts. */ __( 'sample of %d', 'publishrank' ), $s['sample'] ) ) . ')</span></h2><table class="widefat striped" style="max-width:720px;"><tbody>';
		foreach ( array(
			'missing_description'    => __( 'Missing meta description', 'publishrank' ),
			'missing_featured_image' => __( 'Missing featured image', 'publishrank' ),
			'cornerstone'            => __( 'Cornerstone articles', 'publishrank' ),
			'noindexed'              => __( 'Noindexed articles', 'publishrank' ),
			'stale_180d'             => __( 'Not updated in 180+ days', 'publishrank' ),
		) as $key => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . wp_kses_post( $bar( $s['coverage'][ $key ], max( 1, $s['sample'] ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Article SEO Intelligence', 'publishrank' ) . '</h2>';
		$ai = $s['article_intelligence'];
		echo '<p>' . esc_html( sprintf( /* translators: 1: average score, 2: scored count, 3: total sample. */ __( 'Average intelligence score: %1$d/100 across %2$d of %3$d sampled articles.', 'publishrank' ), $ai['average'], $ai['scored'], $ai['total'] ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Distribution:', 'publishrank' ) . '</strong> ' . esc_html( sprintf( /* translators: 1: high, 2: medium, 3: low. */ __( 'High %1$d · Medium %2$d · Low %3$d', 'publishrank' ), $ai['distribution']['high'], $ai['distribution']['mid'], $ai['distribution']['low'] ) ) . '</p>';
		if ( ! empty( $ai['top_issues'] ) ) {
			echo '<p>' . esc_html__( 'Most common article issues:', 'publishrank' ) . '</p><ul>';
			foreach ( $ai['top_issues'] as $issue => $count ) {
				echo '<li>' . esc_html( ArticleIntelligence::check_label( $issue ) ) . ': ' . esc_html( (string) $count ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0 20px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ArticleIntelligence::BATCH_ACTION ) . '" />';
		wp_nonce_field( ArticleIntelligence::BATCH_ACTION );
		echo '<button type="submit" class="button">' . esc_html__( 'Analyze next 50 unscored articles', 'publishrank' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Editorial score distribution', 'publishrank' ) . '</h2><table class="widefat striped" style="max-width:720px;"><tbody>';
		foreach ( array( 'high' => __( '75–100', 'publishrank' ), 'mid' => __( '50–74', 'publishrank' ), 'low' => __( 'Below 50', 'publishrank' ) ) as $key => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . wp_kses_post( $bar( $s['scores'][ $key ], max( 1, $s['sample'] ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Links & housekeeping', 'publishrank' ) . '</h2><ul style="max-width:720px;">';
		echo '<li>' . esc_html( sprintf( /* translators: 1: internal links, 2: external links. */ __( 'Recorded internal links: %1$d; external links: %2$d.', 'publishrank' ), $s['links']['internal'], $s['links']['external'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: 1: redirects, 2: unresolved 404s. */ __( 'Active redirect rules: %1$d; unresolved 404 paths: %2$d.', 'publishrank' ), $s['redirects'], $s['unresolved_404'] ) ) . '</li>';
			$audit = $s['audit'];
			if ( is_array( $audit ) && ! empty( $audit['ran_at'] ) ) {
				$badge = 'PASS' === $audit['status'] ? 'pass' : ( 'ERROR' === $audit['status'] ? 'error' : 'warning' );
				echo '<li>' . esc_html__( 'Last scheduled audit:', 'publishrank' ) . ' <strong class="pr-audit-badge pr-audit-badge--' . esc_attr( $badge ) . '">' . esc_html( (string) $audit['status'] ) . '</strong> (' . esc_html( (string) $audit['ran_at'] ) . ')</li>';
		} else {
			echo '<li>' . esc_html__( 'Scheduled audit has not run yet.', 'publishrank' ) . '</li>';
		}
		echo '</ul></div>';
	}
}
