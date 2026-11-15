<?php
/**
 * PublishRank's single SEO analysis service.
 *
 * Every PublishRank surface that shows an SEO score for a post (editor panel, toolbar badge, post list
 * column, SEO Score page, Insights, scheduled audits, REST) gets it from here. The engine behind it is
 * ArticleIntelligence::analyze(); this service only adds a freshness-checked cache so list screens do
 * not re-analyze every post on every page view.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class SeoAnalysis {

	/** Bump when the scoring rules change so stored scores are recalculated. */
	const VERSION = 3;

	/** Full, live report for a post (same engine and inputs as the editor's saved state). */
	public static function report( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		return ArticleIntelligence::analyze( ArticleIntelligence::data_from_post( $post ), true );
	}

	/**
	 * Score summary for a post. Uses the stored result when it is newer than the post and was made by
	 * the current scoring version; otherwise analyzes and stores it again.
	 *
	 * @return array{score:int,rating:string,issues:string[],passed:int,total:int,words:int,reading_ease:float,fresh:bool}
	 */
	public static function summary( $post, $allow_compute = true ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return self::shape( 0, array( __( 'Article could not be loaded for analysis.', 'publishrank' ) ), 0, 0, 0, 0.0, false );
		}
		$stored = get_post_meta( $post->ID, ArticleIntelligence::SUMMARY_META, true );
		if ( is_array( $stored ) && isset( $stored['score'], $stored['v'] ) && (int) $stored['v'] === self::VERSION ) {
			$at       = isset( $stored['at'] ) ? strtotime( (string) $stored['at'] ) : 0;
			$modified = (int) get_post_modified_time( 'U', true, $post );
			if ( $at && $at >= $modified ) {
				return self::from_stored( $stored, true );
			}
		}
		if ( ! $allow_compute ) {
			return is_array( $stored ) && isset( $stored['score'] ) ? self::from_stored( $stored, false ) : self::shape( 0, array(), 0, 0, 0, 0.0, false );
		}
		$report = ArticleIntelligence::store( $post );
		return self::from_stored( (array) get_post_meta( $post->ID, ArticleIntelligence::SUMMARY_META, true ), true, $report );
	}

	/** Just the number. */
	public static function score( $post ) {
		$s = self::summary( $post );
		return (int) $s['score'];
	}

	/** Rating band used everywhere (matches the editor ring colours). */
	public static function rating( $score ) {
		$score = (int) $score;
		return $score >= 80 ? 'good' : ( $score >= 55 ? 'ok' : 'bad' );
	}

	private static function from_stored( array $stored, $fresh, $report = null ) {
		$issues = array();
		if ( is_array( $report ) && ! empty( $report['checks'] ) ) {
			foreach ( $report['checks'] as $check ) {
				if ( 'bad' === $check['status'] ) {
					$issues[] = wp_strip_all_tags( (string) $check['message'] );
				}
			}
		} else {
			foreach ( (array) ( $stored['fail'] ?? array() ) as $id ) {
				$issues[] = ArticleIntelligence::check_title( (string) $id );
			}
		}
		return self::shape(
			(int) ( $stored['score'] ?? 0 ),
			array_slice( $issues, 0, 8 ),
			(int) ( $stored['passed'] ?? 0 ),
			(int) ( $stored['total'] ?? 0 ),
			(int) ( $stored['words'] ?? 0 ),
			(float) ( $stored['reading_ease'] ?? 0 ),
			(bool) $fresh
		);
	}

	private static function shape( $score, array $issues, $passed, $total, $words, $ease, $fresh ) {
		$score = max( 0, min( 100, (int) $score ) );
		return array(
			'score'        => $score,
			'rating'       => self::rating( $score ),
			'issues'       => array_values( $issues ),
			'passed'       => (int) $passed,
			'total'        => (int) $total,
			'words'        => (int) $words,
			'reading_ease' => (float) $ease,
			'fresh'        => (bool) $fresh,
		);
	}
}
