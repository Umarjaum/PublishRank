<?php
/** Local editorial audit for newsroom content; delegates to the single PublishRank SEO analysis (SeoAnalysis). */
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class EditorialAudit {
	/** @param \WP_Post $post @return array<string,mixed> */
	public function analyze( $post ) {
		// One authoritative score: the same engine and stored result the editor shows (see SeoAnalysis).
		$summary = SeoAnalysis::summary( $post );
		return array(
			'score'        => $summary['score'],
			'passed'       => $summary['passed'],
			'total'        => $summary['total'],
			'issues'       => $summary['issues'],
			'words'        => $summary['words'],
			'reading_ease' => $summary['reading_ease'],
		);
	}

	private function syllables( $text ) {
		$words = preg_split( '/\s+/u', strtolower( preg_replace( '/[^a-z\s]/i', ' ', $text ) ), -1, PREG_SPLIT_NO_EMPTY );
		$total = 0;
		foreach ( (array) $words as $word ) {
			$word   = preg_replace( '/e$/', '', $word );
			$total += max( 1, preg_match_all( '/[aeiouy]+/', $word, $matches ) );
		}
		return $total;
	}
}
