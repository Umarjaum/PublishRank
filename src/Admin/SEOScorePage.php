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
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_post_publishrank_inclusive_save', array( $this, 'save_language' ) );
	}

	/** Inclusive-language settings (optional analysis; never rewrites content). */
	public function save_language() {
		AdminAssets::guard( 'publishrank_inclusive_save', 'publishrank_manage_settings' );
		check_admin_referer( 'publishrank_inclusive_save' );
		$all     = array_keys( \PublishRankSEO\SEO\Language\InclusiveLanguage::rules() );
		$on      = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['rules'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_option(
			\PublishRankSEO\SEO\Language\InclusiveLanguage::OPTION,
			array(
				'enabled'  => empty( $_POST['enabled'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'disabled' => array_values( array_diff( $all, $on ) ),
			),
			false
		);
		AdminAssets::done( 'publishrank-seo-score', 'success', __( 'Inclusive-language settings saved.', 'publishrank' ) );
	}

	private function render_language_card() {
		$s = \PublishRankSEO\SEO\Language\InclusiveLanguage::settings();
		echo '<div class="rhs-card" id="pr-inclusive"><h2>' . esc_html__( 'Languages and inclusive language', 'publishrank' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The analysis detects English, Arabic and Urdu from the text. Arabic and Urdu use their own normalisation, word endings, sentence marks, connective words and sentence-length limits; English-only formulas (Flesch, passive voice) are not applied to them. Other languages get the language-neutral checks only.', 'publishrank' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_inclusive_save" />';
		wp_nonce_field( 'publishrank_inclusive_save' );
		echo '<p><label><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], true, false ) . ' /> <strong>' . esc_html__( 'Suggest more inclusive wording in the editor (English)', 'publishrank' ) . '</strong></label><br /><span class="description">' . esc_html__( 'Suggestions explain why and offer alternatives. They never change your text and do not affect the SEO score. Writers can dismiss a suggestion for one post.', 'publishrank' ) . '</span></p>';
		echo '<details><summary>' . esc_html__( 'Rules', 'publishrank' ) . '</summary><div style="columns:2;margin-top:8px">';
		foreach ( \PublishRankSEO\SEO\Language\InclusiveLanguage::rules() as $id => $rule ) {
			echo '<label style="display:block;break-inside:avoid;margin:0 0 6px"><input type="checkbox" name="rules[]" value="' . esc_attr( $id ) . '"' . checked( ! in_array( $id, $s['disabled'], true ), true, false ) . ' /> <code>' . esc_html( str_replace( '_', ' ', $id ) ) . '</code> <span class="description">→ ' . esc_html( $rule['alt'] ) . '</span></label>';
		}
		echo '</div></details><p><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'publishrank' ) . '</button></p></form></div>';
	}
	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'SEO Score', 'publishrank' ), __( 'SEO Score', 'publishrank' ), 'publishrank_view_reports', self::SLUG, array( $this, 'render' ) ); }
	public function render() {
		if ( ! \PublishRankSEO\Core\Capabilities::can( 'view_reports' ) ) {
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
		AdminAssets::render_flash();
		/* translators: %1$d: total count for the preceding category; %2$d: numeric count or measurement described by the message. */
		echo '<div class="rhs-grid"><div class="rhs-card rhs-score"><p class="rhs-label">' . esc_html__( 'Average editorial score', 'publishrank' ) . '</p><div class="pr-ring" style="--pr-score:' . esc_attr( (string) $average ) . '"><span class="pr-ring__num">' . esc_html( (string) $average ) . '<small>/100</small></span></div><p>' . esc_html( sprintf( __( 'Based on %1$d of %2$d published articles.', 'publishrank' ), count( $rows ), $published ) ) . '</p></div>';
		/* translators: %d: numeric count or measurement described by the message. */
		echo '<div class="rhs-card"><h2>' . esc_html__( 'Score distribution', 'publishrank' ) . '</h2><ul><li>' . esc_html( sprintf( __( 'Strong (75–100): %d', 'publishrank' ), $bands['strong'] ) ) . '</li><li>' . esc_html( sprintf( __( 'Improving (50–74): %d', 'publishrank' ), $bands['improving'] ) ) . '</li><li>' . esc_html( sprintf( __( 'Priority (0–49): %d', 'publishrank' ), $bands['priority'] ) ) . '</li></ul><p class="description">' . esc_html__( 'The score weighs headline, description, content depth, subheadings, featured image, author attribution, dates and links. It is an editorial checklist, not a ranking prediction.', 'publishrank' ) . '</p></div>';
		echo '<div class="rhs-card"><h2>' . esc_html__( 'Technical discovery', 'publishrank' ) . '</h2><p><strong>' . esc_html__( 'XML sitemap index', 'publishrank' ) . '</strong><br /><a href="' . esc_url( $index_url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $index_url ) . '</code></a></p><p>' . esc_html__( 'Run the Sitemap Hub screen for XML validity, provider coverage and shard diagnostics.', 'publishrank' ) . '</p><p><a class="button" href="' . esc_url( Branding::page_url( 'publishrank-sitemap-health' ) ) . '">' . esc_html__( 'Open Sitemap Hub', 'publishrank' ) . '</a> <a class="button" href="' . esc_url( Branding::page_url( 'publishrank-schema' ) ) . '">' . esc_html__( 'Schema settings', 'publishrank' ) . '</a></p></div></div>';
		$this->render_language_card();
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
