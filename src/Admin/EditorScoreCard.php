<?php
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\SEO\ArticleIntelligence;

defined( 'ABSPATH' ) || exit;

final class EditorScoreCard {
	public function register() { add_action( 'admin_footer', array( $this, 'render' ) ); add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) ); }
	public function enqueue( $hook ) { if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) { return; } $path = PUBLISHRANK_SEO_PATH . 'assets/css/editor-score-card.css'; $version = Branding::version() . '.' . (int) ( is_file( $path ) ? filemtime( $path ) : 0 ); wp_enqueue_style( 'publishrank-editor-score-card', Branding::asset_url( 'css/editor-score-card.css' ), array(), $version ); }
	public function render() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $screen->post_type, ArticleIntelligence::supported_post_types(), true ) ) { return; }
		$id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$stored = $id ? ArticleIntelligence::stored( $id ) : null;
		$score = $stored ? (int) $stored['score'] : 0;
		echo '<aside id="publishrank-editor-score" class="pr-editor-score-card" data-score="' . esc_attr( $score ) . '" data-post-id="' . esc_attr( $id ) . '"><div class="pr-editor-score-card__brand"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span><strong>PublishRank</strong></div>';
		echo '<div class="pr-editor-score-card__overview"><div class="pr-editor-score-card__gauge" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $score ) . '" aria-label="' . esc_attr( sprintf( __( 'Editorial SEO score: %d out of 100', 'publishrank' ), $score ) ) . '"><div class="pr-editor-score-card__gauge-content"><span class="pr-editor-score-card__number">' . esc_html( $score ) . '</span><span>/100</span></div></div><div class="pr-editor-score-card__summary"><strong>' . esc_html__( 'Editorial score', 'publishrank' ) . '</strong>' . esc_html__( 'A local checklist score — not a search ranking prediction.', 'publishrank' ) . '</div></div>';
		echo '<details class="pr-editor-score-card__breakdown" open><summary class="pr-editor-score-card__breakdown-title">' . esc_html__( 'Score by area', 'publishrank' ) . '</summary><div class="pr-editor-score-card__bars">';
		$labels = ArticleIntelligence::group_labels();
		foreach ( array( 'keyphrase', 'headline', 'snippet', 'structure', 'readability', 'media', 'links' ) as $group ) {
			if ( empty( $labels[ $group ] ) ) { continue; }
			echo '<div class="pr-editor-score-card__bar-row" data-score-group="' . esc_attr( $group ) . '"><span>' . esc_html( $labels[ $group ] ) . '</span><span class="pr-editor-score-card__bar-track"><span class="pr-editor-score-card__bar-fill" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label=""></span></span><b class="pr-editor-score-card__bar-value">—</b></div>';
		}
		echo '</div></details><div class="pr-editor-score-card__metrics"><span><b class="pr-editor-score-card__words">—</b> ' . esc_html__( 'words', 'publishrank' ) . '</span><span><b class="pr-editor-score-card__images">—</b> ' . esc_html__( 'images', 'publishrank' ) . '</span><span><b class="pr-editor-score-card__keyword">—</b> ' . esc_html__( 'keyphrase', 'publishrank' ) . '</span></div><p>' . esc_html__( 'Live analysis updates as you edit.', 'publishrank' ) . '</p><div class="pr-editor-score-card__status" id="pr-editor-score-status" role="status" aria-live="polite"></div><button type="button" class="button button-primary" id="publishrank-editor-score-refresh">' . esc_html__( 'Analyze article', 'publishrank' ) . '</button></aside>';
	}
}
