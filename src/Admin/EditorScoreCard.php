<?php
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\SEO\ArticleIntelligence;
use PublishRankSEO\Core\OutputSettings;

defined( 'ABSPATH' ) || exit;

/** Floating SEO score widget: ring, metrics, keyphrase status, priority count, last analysis. */
final class EditorScoreCard {
	public function register() {
		add_action( 'admin_footer', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return; }
		wp_enqueue_style( 'publishrank-editor-score-card', Branding::asset_url( 'css/editor-score-card.css' ), array(), Branding::version() );
		$script_path    = PUBLISHRANK_SEO_PATH . 'assets/js/editor-score-card.js';
		$script_version = Branding::version() . '.' . ( file_exists( $script_path ) ? (string) filemtime( $script_path ) : '0' );
		wp_enqueue_script( 'publishrank-editor-score-card', Branding::asset_url( 'js/editor-score-card.js' ), array( 'jquery' ), $script_version, true );
	}

	/** Print the score ring. Every dynamic value is escaped where it is printed. */
	private static function ring( $score, $rating ) {
		$score = max( 0, min( 100, (int) $score ) );
		$r     = 19;
		$c     = 2 * M_PI * $r;
		$off   = $c * ( 1 - $score / 100 );
		/* translators: %d: editorial SEO score. */
		$label = sprintf( __( 'SEO score %d out of 100', 'publishrank' ), $score );
		echo '<svg class="pr-editor-score-card__ring pr-ring--' . esc_attr( $rating ) . '" width="48" height="48" viewBox="0 0 48 48" role="img" aria-label="' . esc_attr( $label ) . '">';
		echo '<defs><linearGradient id="publishrank-score-gradient" x1="0" y1="48" x2="48" y2="0" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#e53935"/><stop offset=".5" stop-color="#2196f3"/><stop offset="1" stop-color="#2eaf5d"/></linearGradient></defs>';
		echo '<circle class="pr-ring__bg" cx="24" cy="24" r="' . esc_attr( (string) $r ) . '" stroke-width="5"/>';
		echo '<circle class="pr-ring__val pr-ring__val--' . esc_attr( $rating ) . '" cx="24" cy="24" r="' . esc_attr( (string) $r ) . '" stroke-width="5" stroke="url(#publishrank-score-gradient)" stroke-dasharray="' . esc_attr( (string) round( $c, 2 ) ) . '" stroke-dashoffset="' . esc_attr( (string) round( $off, 2 ) ) . '" transform="rotate(-90 24 24)"/>';
		echo '<text x="24" y="24" class="pr-ring__text" style="font-size:13px">' . esc_html( (string) $score ) . '</text></svg>';
	}

	public function render() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $screen->post_type, ArticleIntelligence::supported_post_types(), true ) ) {
			return; }
		$id     = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stored = $id ? ArticleIntelligence::stored( $id ) : null;
		$score  = $stored ? (int) $stored['score'] : 0;
		$rating = $score >= 80 ? 'good' : ( $score >= 55 ? 'ok' : 'bad' );
		$schema_score = OutputSettings::schema() ? 100 : 0;
		$at     = ( $stored && ! empty( $stored['at'] ) )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $stored['at'] ) )
			: __( 'Not analyzed yet', 'publishrank' );
		$stats  = $stored && ! empty( $stored['stats'] ) && is_array( $stored['stats'] ) ? $stored['stats'] : array();
		if ( $id && empty( $stats ) ) {
			$editor_post = get_post( $id );
			if ( $editor_post instanceof \WP_Post ) {
				$initial_report = ArticleIntelligence::analyze( ArticleIntelligence::data_from_post( $editor_post ), true );
				$stats = isset( $initial_report['stats'] ) && is_array( $initial_report['stats'] ) ? $initial_report['stats'] : array();
			}
		}
		$metric = static function ( $key, $default = 0 ) use ( $stats ) { return isset( $stats[ $key ] ) ? (int) $stats[ $key ] : $default; };
		echo '<aside id="publishrank-editor-score" class="pr-editor-score-card" data-score="' . esc_attr( (string) $score ) . '" data-post-id="' . esc_attr( (string) $id ) . '" data-schema-score="' . esc_attr( (string) $schema_score ) . '" aria-label="' . esc_attr__( 'PublishRank editorial scores', 'publishrank' ) . '">';
		echo '<div class="pr-editor-score-card__top">';
		echo '<div class="pr-editor-score-card__ring-wrap">';
		self::ring( $score, $rating );
		echo '<span class="pr-editor-score-card__ring-label">' . esc_html__( 'SEO score', 'publishrank' ) . '</span></div>';
		echo '<div class="pr-editor-score-card__titles"><strong class="pr-editor-score-card__brand">PublishRank</strong><span class="pr-editor-score-card__status-text">' . esc_html( $at ) . '</span><span class="pr-editor-score-card__schema">' . esc_html__( 'Schema score:', 'publishrank' ) . ' <b class="pr-editor-score-card__schema-value">' . esc_html( (string) $schema_score ) . '/100</b></span></div>';
		echo '</div>';
		echo '<div class="pr-editor-score-card__metrics" role="list">';
		foreach ( array( 'words' => __( 'Words', 'publishrank' ), 'images' => __( 'Images', 'publishrank' ), 'internal' => __( 'Internal links', 'publishrank' ), 'external' => __( 'External links', 'publishrank' ) ) as $key => $label ) {
			echo '<span role="listitem"><b class="pr-editor-score-card__' . esc_attr( $key ) . '">' . esc_html( (string) $metric( $key ) ) . '</b><small>' . esc_html( $label ) . '</small></span>';
		}
		echo '</div><div class="pr-editor-score-card__footer"><span class="pr-editor-score-card__live-status" aria-live="polite">' . esc_html__( 'Values update as you edit.', 'publishrank' ) . '</span><button type="button" class="button-link pr-editor-score-card__refresh" id="publishrank-editor-score-refresh">' . esc_html__( 'Refresh', 'publishrank' ) . '</button></div>';
		echo '</aside>';
	}
}
