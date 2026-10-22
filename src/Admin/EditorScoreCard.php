<?php
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\SEO\ArticleIntelligence;

defined( 'ABSPATH' ) || exit;

/** Floating SEO score widget: ring, metrics, keyphrase status, priority count, last analysis. */
final class EditorScoreCard {
	public function register() {
		add_action( 'admin_footer', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) { return; }
		wp_enqueue_style( 'publishrank-editor-score-card', Branding::asset_url( 'css/editor-score-card.css' ), array(), Branding::version() );
		$script_path = PUBLISHRANK_SEO_PATH . 'assets/js/editor-score-card.js';
		$script_version = Branding::version() . '.' . ( file_exists( $script_path ) ? (string) filemtime( $script_path ) : '0' );
		wp_enqueue_script( 'publishrank-editor-score-card', Branding::asset_url( 'js/editor-score-card.js' ), array( 'jquery' ), $script_version, true );
	}

	private static function ring( $score, $rating ) {
		$score = max( 0, min( 100, (int) $score ) );
		$r = 19; $c = 2 * M_PI * $r; $off = $c * ( 1 - $score / 100 );
		return sprintf(
				'<svg class="pr-editor-score-card__ring pr-ring--%1$s" width="48" height="48" viewBox="0 0 48 48" role="img" aria-label="%2$s"><defs><linearGradient id="publishrank-score-gradient" x1="0" y1="48" x2="48" y2="0" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#e53935"/><stop offset=".5" stop-color="#2196f3"/><stop offset="1" stop-color="#2eaf5d"/></linearGradient></defs><circle class="pr-ring__bg" cx="24" cy="24" r="%3$s" stroke-width="5"/><circle class="pr-ring__val pr-ring__val--%1$s" cx="24" cy="24" r="%3$s" stroke-width="5" stroke="url(#publishrank-score-gradient)" stroke-dasharray="%4$s" stroke-dashoffset="%5$s" transform="rotate(-90 24 24)"/><text x="24" y="24" class="pr-ring__text" style="font-size:13px">%6$d</text></svg>',
			esc_attr( $rating ),
			esc_attr( sprintf( __( 'SEO score %d out of 100', 'publishrank' ), $score ) ),
			$r,
			round( $c, 2 ),
			round( $off, 2 ),
			$score
		);
	}

	public function render() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $screen->post_type, ArticleIntelligence::supported_post_types(), true ) ) { return; }
		$id     = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stored = $id ? ArticleIntelligence::stored( $id ) : null;
		$score  = $stored ? (int) $stored['score'] : 0;
		$rating = $score >= 80 ? 'good' : ( $score >= 55 ? 'ok' : 'bad' );
		$at     = ( $stored && ! empty( $stored['at'] ) )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $stored['at'] ) )
			: __( 'not analyzed yet', 'publishrank' );
		echo '<aside id="publishrank-editor-score" class="pr-editor-score-card" data-score="' . esc_attr( (string) $score ) . '" data-post-id="' . esc_attr( (string) $id ) . '" aria-label="' . esc_attr__( 'PublishRank SEO score', 'publishrank' ) . '">';
		echo '<div class="pr-editor-score-card__top">';
		echo self::ring( $score, $rating ); // phpcs:ignore WordPress.Security.EscapeOutput -- all interpolated values are ints or fixed labels, escaped above.
		echo '<div class="pr-editor-score-card__titles"><strong class="pr-editor-score-card__brand">PublishRank</strong><span class="pr-editor-score-card__status-text"></span></div>';
		echo '</div>';
		echo '</aside>';
	}
}
