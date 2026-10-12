<?php
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\SEO\ArticleIntelligence;

defined( 'ABSPATH' ) || exit;

final class EditorScoreCard {
	public function register() { add_action( 'admin_footer', array( $this, 'render' ) ); add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) ); }
	public function enqueue( $hook ) { if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) { return; } wp_enqueue_style( 'publishrank-editor-score-card', Branding::asset_url( 'css/editor-score-card.css' ), array(), Branding::version() ); wp_enqueue_script( 'publishrank-editor-score-card', Branding::asset_url( 'js/editor-score-card.js' ), array( 'jquery' ), Branding::version(), true ); }
	public function render() { $screen = get_current_screen(); if ( ! $screen || 'post' !== $screen->base ) { return; } $id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; $stored = $id ? ArticleIntelligence::stored( $id ) : null; $score = $stored ? (int) $stored['score'] : 0; echo '<aside id="publishrank-editor-score" class="pr-editor-score-card" data-score="' . esc_attr( $score ) . '" data-post-id="' . esc_attr( $id ) . '"><div class="pr-editor-score-card__brand"><span class="dashicons dashicons-chart-line"></span><strong>PublishRank</strong></div><div class="pr-editor-score-card__score"><span class="pr-editor-score-card__number">' . esc_html( $score ) . '</span><span>/100</span></div><div class="pr-editor-score-card__metrics"><span><b class="pr-editor-score-card__words">—</b> ' . esc_html__( 'words', 'publishrank' ) . '</span><span><b class="pr-editor-score-card__images">—</b> ' . esc_html__( 'images', 'publishrank' ) . '</span><span><b class="pr-editor-score-card__keyword">—</b> ' . esc_html__( 'keyphrase', 'publishrank' ) . '</span></div><p>' . esc_html__( 'SEO score updates while you prepare the article in Gutenberg or Elementor.', 'publishrank' ) . '</p><button type="button" class="button button-primary" id="publishrank-editor-score-refresh">' . esc_html__( 'Analyze article', 'publishrank' ) . '</button></aside>'; }
}
