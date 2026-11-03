<?php
/** Regression coverage for PublishRank and Rank Math coexistence. */

use PublishRankSEO\Compatibility\ConflictDetector;
use PublishRankSEO\Core\OutputSettings;

return array(
	'the active Rank Math package is recognized by its runtime marker' => function () {
		if ( class_exists( '\\RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$active = ( new ConflictDetector() )->active();
			pr_assert( in_array( 'Rank Math', $active, true ), 'Rank Math is active but not detected.' );
		}
	},
	'opt-in handoff suppresses PublishRank metadata and page schema without rewriting saved switches' => function () {
		$filter = static function ( $active ) {
			$active[] = 'Rank Math';
			return $active;
		};
		add_filter( 'publishrank_detected_seo_plugins', $filter );

		try {
			update_option( OutputSettings::EXTERNAL_SEO_HANDOFF, 1 );
			update_option( OutputSettings::META, 1 );
			update_option( OutputSettings::SCHEMA, 1 );

			pr_assert( OutputSettings::external_seo_handoff(), 'the requested handoff did not activate.' );
			pr_assert( ! OutputSettings::metadata_output(), 'PublishRank metadata should be suppressed during handoff.' );
			pr_assert( ! OutputSettings::schema_graph_output(), 'PublishRank page schema should be suppressed during handoff.' );
			pr_assert( OutputSettings::meta(), 'handoff must not rewrite the saved metadata preference.' );
			pr_assert( OutputSettings::schema(), 'handoff must not rewrite the saved schema preference.' );
			pr_same( 'PASS', ( new ConflictDetector() )->diagnostics()['status'] );

			$id = pr_make_post( array( 'post_title' => 'Rank Math ownership test' ) );
			pr_go_to( get_permalink( $id ) );
			pr_not_contains( '<!-- PublishRank: begin;', pr_head(), 'PublishRank frontend metadata must stay suppressed.' );
		} finally {
			remove_filter( 'publishrank_detected_seo_plugins', $filter );
		}
	},
);
