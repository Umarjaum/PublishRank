<?php
/** Detect other SEO plugins that would duplicate PublishRank output. */
namespace PublishRankSEO\Compatibility;

use PublishRankSEO\Core\OutputSettings;

defined( 'ABSPATH' ) || exit;

final class ConflictDetector {
	/** @return string[] Names of active SEO plugins. */
	public function active() {
		$found = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found[] = 'Yoast SEO';
		}
		if ( class_exists( '\\RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$found[] = 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$found[] = 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$found[] = 'SEOPress';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$found[] = 'The SEO Framework';
		}
		$found = apply_filters( 'publishrank_detected_seo_plugins', $found );
		$found = array_map( 'sanitize_text_field', (array) $found );
		return array_values( array_unique( array_filter( $found ) ) );
	}

	/** @return array<string,string> status + message */
	public function diagnostics() {
		$active = $this->active();
		if ( empty( $active ) ) {
			return array(
				'status'  => 'PASS',
				'message' => __( 'No other SEO plugin was detected.', 'publishrank' ),
			);
		}
		if ( OutputSettings::external_seo_handoff() ) {
			/* translators: %s: plugin names. */
			return array(
				'status'  => 'PASS',
				'message' => sprintf( __( '%s is active. Ownership handoff is enabled: PublishRank suppresses its overlapping frontend metadata and page schema; other settings are unchanged.', 'publishrank' ), implode( ', ', $active ) ),
			);
		}
		/* translators: %s: plugin names. */
		return array(
			'status'  => 'WARNING',
			/* translators: %s: comma-separated names of active SEO plugins. */
			'message' => sprintf( __( '%s is active and may print duplicate titles, canonicals or schema. Keep one plugin responsible for SEO output, or enable the opt-in ownership handoff in PublishRank Settings.', 'publishrank' ), implode( ', ', $active ) ),
		);
	}
}
