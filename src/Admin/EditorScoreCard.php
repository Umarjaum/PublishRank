<?php
/**
 * "SEO Analysis" panel in the post editor: live score ring out of 100, rating, passed checks,
 * items to improve, focus keyword and content metrics. It mirrors the PublishRank SEO Score meta box and
 * updates whenever that analysis runs (including while typing in the block editor).
 *
 * Placement: by default the panel is docked at the top of the editor sidebar (block editor: the
 * PublishRank sidebar, opened from the live score badge in the top toolbar; classic editor: right under
 * the Publish box). Editors can pop it out into a draggable floating panel and dock it back; the
 * choice is remembered per browser.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\SEO\ArticleIntelligence;

defined( 'ABSPATH' ) || exit;

final class EditorScoreCard {
	public function register() {
		// The sidebar that carries the SEO fields must exist even when the score analysis is switched off.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		if ( ArticleIntelligence::enabled() ) {
			add_action( 'admin_footer', array( $this, 'render' ), 20 );
		}
	}

	private static function on_supported_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'post' === $screen->base && in_array( (string) $screen->post_type, ArticleIntelligence::supported_post_types(), true );
	}

	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$scored = ArticleIntelligence::enabled() && self::on_supported_screen();
		$seo    = EditorPlacement::on_editor_screen();
		if ( ! $scored && ! $seo ) {
			return;
		}
		if ( $scored ) {
			$css = PUBLISHRANK_SEO_PATH . 'assets/css/editor-score-card.css';
			$js  = PUBLISHRANK_SEO_PATH . 'assets/js/editor-score-card.js';
			wp_enqueue_style( 'publishrank-editor-score-card', Branding::asset_url( 'css/editor-score-card.css' ), array(), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
			wp_enqueue_script( 'publishrank-editor-score-card', Branding::asset_url( 'js/editor-score-card.js' ), array( 'jquery', 'publishrank-link-inserter' ), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
			wp_localize_script(
				'publishrank-editor-score-card',
				'publishrankScoreCard',
				array(
					'i18n' => self::strings(),
				)
			);
		}
		global $post;
		$screen_type = function_exists( 'get_current_screen' ) && get_current_screen() ? (string) get_current_screen()->post_type : '';
		$block       = $post instanceof \WP_Post && function_exists( 'use_block_editor_for_post' )
			? use_block_editor_for_post( $post )
			: ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $screen_type ) );
		if ( $block ) {
			$sidebar = PUBLISHRANK_SEO_PATH . 'assets/js/block-editor-sidebar.js';
			$deps    = array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-editor', 'wp-edit-post' );
			if ( wp_script_is( EditorPlacement::HANDLE, 'registered' ) ) {
				$deps[] = EditorPlacement::HANDLE;
			}
			wp_enqueue_script( 'publishrank-block-sidebar', Branding::asset_url( 'js/block-editor-sidebar.js' ), $deps, Branding::version() . '.' . ( file_exists( $sidebar ) ? (string) filemtime( $sidebar ) : '0' ), true );
			wp_set_script_translations( 'publishrank-block-sidebar', 'publishrank' );
		}
	}

	/** Translated strings shared by PHP and JavaScript rendering. */
	private static function strings() {
		return array(
			'autoShort'     => __( 'auto', 'publishrank' ),
			'autoDesc'      => __( 'No meta description written: an automatic one is taken from the excerpt or the article.', 'publishrank' ),
			'excellent'     => __( 'Excellent', 'publishrank' ),
			'good'          => __( 'Good', 'publishrank' ),
			'ok'            => __( 'Needs improvement', 'publishrank' ),
			'bad'           => __( 'Needs work', 'publishrank' ),
			'msgExcellent'  => __( 'Your content is well optimized! Keep up the great work.', 'publishrank' ),
			'msgGood'       => __( 'Looking good. A few small tweaks will make it even stronger.', 'publishrank' ),
			'msgOk'         => __( 'You are on your way. Work through the items below.', 'publishrank' ),
			'msgBad'        => __( 'Start with the items below to improve this article.', 'publishrank' ),
			'analyzing'     => __( 'Analyzing…', 'publishrank' ),
			'updated'       => __( 'Updated just now', 'publishrank' ),
			'live'          => __( 'Updates as you write', 'publishrank' ),
			'failed'        => __( 'Analysis failed. Save a draft and refresh.', 'publishrank' ),
			'noKeyword'     => __( 'Not set — add one in the SEO fields', 'publishrank' ),
			'nothingToFix'  => __( 'Nothing to fix. Great job!', 'publishrank' ),
			'nonePassed'    => __( 'No checks pass yet.', 'publishrank' ),
			/* translators: %d: SEO score. */
			'scoreAria'     => __( 'SEO score %d out of 100', 'publishrank' ),
			'fix'           => __( 'Fix', 'publishrank' ),
			'fixing'        => __( 'Fixing…', 'publishrank' ),
			'fixed'         => __( 'Applied. Review it, then update the post.', 'publishrank' ),
			'fixFailed'     => __( 'No automatic fix could be derived. Open the full report.', 'publishrank' ),
			'linking'       => __( 'Finding related articles…', 'publishrank' ),
			'noLinks'       => __( 'No related published articles found yet.', 'publishrank' ),
			/* translators: %s: check title. */
			'fixAria'       => __( 'Fix: %s', 'publishrank' ),
			'popOut'        => __( 'Pop out as a floating panel', 'publishrank' ),
			'dock'          => __( 'Dock to the sidebar', 'publishrank' ),
		);
	}

	/** Rating key used for colors and labels. */
	private static function band( $score ) {
		$score = (int) $score;
		if ( $score >= 90 ) {
			return 'excellent';
		}
		if ( $score >= 80 ) {
			return 'good';
		}
		return $score >= 55 ? 'ok' : 'bad';
	}

	/** Report for the current post: reuse the meta box's analysis when available, otherwise build one. */
	private static function report( $post_id ) {
		$report = ArticleIntelligence::panel_report();
		if ( is_array( $report ) ) {
			return $report;
		}
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		try {
			return ArticleIntelligence::analyze( ArticleIntelligence::data_from_post( $post ), true );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function render() {
		if ( ! self::on_supported_screen() ) {
			return;
		}
		global $post;
		$id      = $post instanceof \WP_Post ? (int) $post->ID : ( isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report  = self::report( $id );
		$score   = $report ? (int) $report['score'] : 0;
		$band    = self::band( $score );
		$checks  = $report ? ArticleIntelligence::check_summary( $report ) : array();
		$stats   = $report && isset( $report['stats'] ) ? (array) $report['stats'] : array();
		$keyword = $report && isset( $report['current']['keyphrase'] ) ? (string) $report['current']['keyphrase'] : '';
		$s       = self::strings();
		$labels  = array( 'excellent' => $s['excellent'], 'good' => $s['good'], 'ok' => $s['ok'], 'bad' => $s['bad'] );
		$msgs    = array( 'excellent' => $s['msgExcellent'], 'good' => $s['msgGood'], 'ok' => $s['msgOk'], 'bad' => $s['msgBad'] );
		$fix     = array_values( array_filter( $checks, static function ( $c ) { return 'good' !== $c['status']; } ) );
		$pass    = array_values( array_filter( $checks, static function ( $c ) { return 'good' === $c['status']; } ) );
		$r       = 44;
		$circ    = 2 * M_PI * $r;
		$offset  = $circ * ( 1 - max( 0, min( 100, $score ) ) / 100 );
		$metric  = static function ( $key ) use ( $stats ) {
			return isset( $stats[ $key ] ) ? (int) $stats[ $key ] : 0;
		};

		echo '<aside id="publishrank-editor-score" class="pr-esc is-parked pr-esc--' . esc_attr( $band ) . '" data-score="' . esc_attr( (string) $score ) . '" data-post-id="' . esc_attr( (string) $id ) . '" aria-label="' . esc_attr__( 'PublishRank SEO analysis', 'publishrank' ) . '">';

		// Minimized pill.
		echo '<button type="button" class="pr-esc__pill" data-pr-esc-expand aria-expanded="false" aria-controls="publishrank-editor-score-panel">';
		echo '<span class="pr-esc__pill-ring" style="--pr-esc-score:' . esc_attr( (string) $score ) . '"><b data-pr-esc-pill-score>' . esc_html( (string) $score ) . '</b></span>';
		echo '<span class="pr-esc__pill-text"><strong>' . esc_html__( 'SEO', 'publishrank' ) . '</strong><small data-pr-esc-pill-label>' . esc_html( $labels[ $band ] ) . '</small></span></button>';

		echo '<div class="pr-esc__panel" id="publishrank-editor-score-panel">';
		echo '<header class="pr-esc__head" data-pr-esc-drag>';
		echo '<img class="pr-esc__logo" src="' . esc_url( Branding::logo_url() ) . '" width="20" height="20" alt="" />';
		echo '<strong class="pr-esc__title">' . esc_html__( 'SEO Analysis', 'publishrank' ) . '</strong>';
		echo '<span class="pr-esc__live" aria-hidden="true"><i></i>' . esc_html__( 'Live', 'publishrank' ) . '</span>';
		echo '<button type="button" class="pr-esc__icon-btn" data-pr-esc-refresh aria-label="' . esc_attr__( 'Re-analyze now', 'publishrank' ) . '" title="' . esc_attr__( 'Re-analyze now', 'publishrank' ) . '"><svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M10 3a7 7 0 0 1 6.32 4H14v2h5V4h-2v1.76A9 9 0 1 0 19 10h-2a7 7 0 1 1-7-7z"/></svg></button>';
		echo '<button type="button" class="pr-esc__icon-btn" data-pr-esc-mode aria-label="' . esc_attr( $s['popOut'] ) . '" title="' . esc_attr( $s['popOut'] ) . '"><svg class="pr-esc__ico-pop" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M11 3h6v6h-2V6.41l-5.29 5.3-1.42-1.42L13.59 5H11V3zM3 5h6v2H5v8h8v-4h2v6H3V5z"/></svg><svg class="pr-esc__ico-dock" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M2 3h16v14H2V3zm2 2v10h7V5H4zm9 0v10h3V5h-3z"/></svg></button>';
		echo '<button type="button" class="pr-esc__icon-btn" data-pr-esc-collapse aria-expanded="true" aria-controls="publishrank-editor-score-panel" aria-label="' . esc_attr__( 'Minimize SEO analysis', 'publishrank' ) . '" title="' . esc_attr__( 'Minimize', 'publishrank' ) . '"><svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M4 9h12v2H4z"/></svg></button>';
		echo '</header>';

		echo '<div class="pr-esc__scroll">';
		echo '<div class="pr-esc__score">';
		echo '<svg class="pr-esc__ring" viewBox="0 0 100 100" width="112" height="112" role="img" aria-label="' . esc_attr( sprintf( $s['scoreAria'], $score ) ) . '" data-pr-esc-ring>';
		echo '<circle class="pr-esc__ring-bg" cx="50" cy="50" r="' . esc_attr( (string) $r ) . '"/>';
		echo '<circle class="pr-esc__ring-val" cx="50" cy="50" r="' . esc_attr( (string) $r ) . '" stroke-dasharray="' . esc_attr( (string) round( $circ, 2 ) ) . '" stroke-dashoffset="' . esc_attr( (string) round( $offset, 2 ) ) . '" transform="rotate(-90 50 50)" data-pr-esc-arc/>';
		echo '</svg>';
		echo '<span class="pr-esc__num"><b data-pr-esc-score>' . esc_html( (string) $score ) . '</b><small>/100</small></span>';
		echo '</div>';
		echo '<p class="pr-esc__rating" data-pr-esc-rating>' . esc_html( $labels[ $band ] ) . '</p>';
		echo '<p class="pr-esc__msg" data-pr-esc-msg>' . esc_html( $msgs[ $band ] ) . '</p>';

		echo '<div class="pr-esc__metrics" role="list">';
		foreach ( array( 'words' => __( 'Words', 'publishrank' ), 'images' => __( 'Images', 'publishrank' ), 'internal' => __( 'Internal', 'publishrank' ), 'external' => __( 'External', 'publishrank' ) ) as $key => $label ) {
			echo '<span role="listitem"><b data-pr-esc-metric="' . esc_attr( $key ) . '">' . esc_html( number_format_i18n( $metric( $key ) ) ) . '</b><small>' . esc_html( $label ) . '</small></span>';
		}
		echo '</div>';

		echo '<div class="pr-esc__lengths" role="list"><span role="listitem">' . esc_html__( 'SEO title', 'publishrank' ) . ' <b data-pr-esc-len="title">0</b>/60</span><span role="listitem">' . esc_html__( 'Meta description', 'publishrank' ) . ' <b data-pr-esc-len="desc">0</b>/160</span></div>';

		echo '<div class="pr-esc__actions"><button type="button" class="button button-primary pr-esc__links-btn" data-pr-esc-links>' . esc_html__( 'Add internal links', 'publishrank' ) . '</button></div>';

		echo '<div class="pr-esc__keyword"><span>' . esc_html__( 'Focus keyword', 'publishrank' ) . '</span><b data-pr-esc-keyword class="' . ( '' === $keyword ? 'is-empty' : '' ) . '">' . esc_html( '' === $keyword ? $s['noKeyword'] : $keyword ) . '</b></div>';

		self::group( 'fix', __( 'To improve', 'publishrank' ), $fix, $s['nothingToFix'], true );
		self::group( 'pass', __( 'Passed checks', 'publishrank' ), $pass, $s['nonePassed'], false );
		echo '</div>';

		echo '<footer class="pr-esc__foot"><span class="pr-esc__status" data-pr-esc-status role="status" aria-live="polite">' . esc_html( $s['live'] ) . '</span>';
		echo '<a class="pr-esc__report" href="#publishrank_article_intelligence">' . esc_html__( 'Full report', 'publishrank' ) . '</a></footer>';
		echo '</div></aside>';
	}

	/** One collapsible check group. */
	private static function group( $kind, $title, array $rows, $empty, $open ) {
		echo '<details class="pr-esc__group pr-esc__group--' . esc_attr( $kind ) . '"' . ( $open ? ' open' : '' ) . ' data-pr-esc-group="' . esc_attr( $kind ) . '">';
		echo '<summary><span>' . esc_html( $title ) . '</span><span class="pr-esc__count" data-pr-esc-count>' . esc_html( (string) count( $rows ) ) . '</span></summary>';
		echo '<ul class="pr-esc__list" data-pr-esc-list data-empty="' . esc_attr( $empty ) . '">';
		if ( empty( $rows ) ) {
			echo '<li class="pr-esc__empty">' . esc_html( $empty ) . '</li>';
		}
		foreach ( $rows as $row ) {
			echo '<li class="pr-esc__check is-' . esc_attr( $row['status'] ) . '"><span class="pr-esc__dot" aria-hidden="true"></span><span><strong>' . esc_html( $row['title'] ) . '</strong><small>' . esc_html( $row['message'] ) . '</small></span>' . ( 'good' !== $row['status'] && ! empty( $row['fix'] ) ? '<button type="button" class="button button-small pr-esc__fix" data-pr-esc-fix="' . esc_attr( $row['fix'] ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: check title. */ __( 'Fix: %s', 'publishrank' ), $row['title'] ) ) . '">' . esc_html__( 'Fix', 'publishrank' ) . '</button>' : '' ) . '</li>';
		}
		echo '</ul></details>';
	}
}
