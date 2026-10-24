<?php
/** Editorial and technical SEO score overview for publishers. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\SEO\ArticleIntelligence;
use PublishRankSEO\SEO\EditorialAudit;
use PublishRankSEO\Sitemap\SitemapUrls;
defined( 'ABSPATH' ) || exit;
final class SEOScorePage {
	const SLUG        = 'publishrank-seo-score';
	const SAMPLE_SIZE = 50;
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 ); }
	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'SEO Score', 'publishrank' ), __( 'SEO Score', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) ); }
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$types  = ArticleIntelligence::supported_post_types();
		$posts  = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'posts_per_page'   => self::SAMPLE_SIZE,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		$audit  = new EditorialAudit();
		$rows   = array();
		$sum    = 0;
		$bands  = array(
			'strong'    => 0,
			'improving' => 0,
			'priority'  => 0,
		);
		$issues = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue; }
			$report = $audit->analyze( $post );
			$score  = max( 0, min( 100, (int) $report['score'] ) );
			$sum   += $score;
			$key    = $score >= 75 ? 'strong' : ( $score >= 50 ? 'improving' : 'priority' );
			++$bands[ $key ];
			foreach ( $report['issues'] as $issue ) {
				$issues[ $issue ] = ( $issues[ $issue ] ?? 0 ) + 1; }
			$rows[] = array(
				'post'   => $post,
				'score'  => $score,
				'issues' => (array) $report['issues'],
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['score'] <=> $b['score'];
			}
		);
		arsort( $issues );
		$average   = count( $rows ) ? (int) round( $sum / count( $rows ) ) : 0;
		$published = 0;
		foreach ( $types as $type ) {
			$counts     = wp_count_posts( $type );
			$published += (int) ( $counts->publish ?? 0 ); }
		$index_url = SitemapUrls::index_url();
		echo '<div class="wrap">';
		AdminAssets::header( __( 'SEO Score', 'publishrank' ), __( 'Editorial quality signals and technical discovery, scored locally without sending article content to an external service.', 'publishrank' ) );
		/* translators: %1$d: total count for the preceding category; %2$d: numeric count or measurement described by the message. */
		echo '<div class="rhs-grid"><div class="rhs-card rhs-score"><p class="rhs-label">' . esc_html__( 'Average editorial score', 'publishrank' ) . '</p><div class="pr-ring" style="--pr-score:' . esc_attr( (string) $average ) . '"><span class="pr-ring__num">' . esc_html( (string) $average ) . '<small>/100</small></span></div><p>' . esc_html( sprintf( __( 'Based on %1$d of %2$d published articles.', 'publishrank' ), count( $rows ), $published ) ) . '</p></div>';
		/* translators: %d: numeric count or measurement described by the message. */
		echo '<div class="rhs-card"><h2>' . esc_html__( 'Score distribution', 'publishrank' ) . '</h2><ul><li>' . esc_html( sprintf( __( 'Strong (75–100): %d', 'publishrank' ), $bands['strong'] ) ) . '</li><li>' . esc_html( sprintf( __( 'Improving (50–74): %d', 'publishrank' ), $bands['improving'] ) ) . '</li><li>' . esc_html( sprintf( __( 'Priority (0–49): %d', 'publishrank' ), $bands['priority'] ) ) . '</li></ul><p class="description">' . esc_html__( 'The score weighs headline, description, content depth, subheadings, featured image, author attribution, dates and links. It is an editorial checklist, not a ranking prediction.', 'publishrank' ) . '</p></div>';
		echo '<div class="rhs-card"><h2>' . esc_html__( 'Technical discovery', 'publishrank' ) . '</h2><p><strong>' . esc_html__( 'XML sitemap index', 'publishrank' ) . '</strong><br /><a href="' . esc_url( $index_url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $index_url ) . '</code></a></p><p>' . esc_html__( 'Run the Sitemap Health screen for XML validity, provider coverage and shard diagnostics.', 'publishrank' ) . '</p><p><a class="button" href="' . esc_url( Branding::page_url( 'publishrank-sitemap-health' ) ) . '">' . esc_html__( 'Open Sitemap Health', 'publishrank' ) . '</a> <a class="button" href="' . esc_url( Branding::page_url( 'publishrank-schema' ) ) . '">' . esc_html__( 'Schema settings', 'publishrank' ) . '</a></p></div></div>';
		if ( $issues ) {
			echo '<h2>' . esc_html__( 'Most common opportunities', 'publishrank' ) . '</h2><ul>';
			/* translators: %1$s: dynamic value identified by the surrounding label or sentence; %2$d: numeric count or measurement described by the message. */
			foreach ( array_slice( $issues, 0, 8, true ) as $issue => $count ) {
				/* translators: 1: SEO issue label, 2: number of sampled articles. */
				echo '<li>' . esc_html( sprintf( __( '%1$s — %2$d sampled article(s)', 'publishrank' ), $issue, $count ) ) . '</li>'; }
			echo '</ul>';
		}
		echo '<h2>' . esc_html__( 'Articles to review first', 'publishrank' ) . '</h2><div class="rhs-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Article', 'publishrank' ) . '</th><th>' . esc_html__( 'Score', 'publishrank' ) . '</th><th>' . esc_html__( 'Next steps', 'publishrank' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No published editorial articles were found.', 'publishrank' ) . '</td></tr>'; }
		foreach ( array_slice( $rows, 0, 10 ) as $row ) {
			$title      = get_the_title( $row['post'] );
			$edit       = get_edit_post_link( $row['post']->ID );
			$title_html = $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
			echo '<tr><td>' . wp_kses( $title_html, array( 'a' => array( 'href' => true ) ) ) . '</td><td><strong>' . esc_html( (string) $row['score'] ) . '/100</strong></td><td>' . esc_html( ! empty( implode( ' ', array_slice( $row['issues'], 0, 3 ) ) ) ? implode( ' ', array_slice( $row['issues'], 0, 3 ) ) : __( 'No priority issues identified.', 'publishrank' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div><p class="description">' . esc_html__( 'Sample is capped at the 50 most recently published eligible articles to keep this screen responsive on large sites. Scores are calculated on demand and are not cached as a site-wide ranking metric.', 'publishrank' ) . '</p></div>';
	}
}
