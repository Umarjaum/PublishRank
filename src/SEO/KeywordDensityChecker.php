<?php
namespace PublishRankSEO\SEO;

final class KeywordDensityChecker {
	/** @return array<string, mixed> */
	public function analyze( $post ) {
		$keyword = trim( (string) get_post_meta( $post->ID, '_publishrank_seo_primary_keyphrase', true ) );
		if ( '' === $keyword ) { $keyword = trim( (string) get_post_meta( $post->ID, '_publishrank_seo_focus_keyword', true ) ); }
		$text = wp_strip_all_tags( (string) $post->post_title . ' ' . (string) $post->post_content );
		$words = $this->words( $text );
		$total = count( $words );
		if ( '' === $keyword || 0 === $total ) { return array( 'keyword' => $keyword, 'count' => 0, 'words' => $total, 'density' => 0.0, 'status' => 'not-available' ); }
		$phrase_words = $this->words( $keyword );
		$phrase_count = count( $phrase_words ); $count = 0;
		if ( $phrase_count ) {
			$limit = $total - $phrase_count;
			for ( $i = 0; $i <= $limit; $i++ ) { if ( array_slice( $words, $i, $phrase_count ) === $phrase_words ) { $count++; } }
		}
		$density = $total ? ( $count * max( 1, $phrase_count ) / $total ) * 100 : 0.0;
		$status = $density < 0.5 ? 'low' : ( $density > 3.0 ? 'high' : 'healthy' );
		return array( 'keyword' => $keyword, 'count' => $count, 'words' => $total, 'density' => round( $density, 2 ), 'status' => $status );
	}
	/** @return string[] */
	private function words( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return preg_split( '/\s+/', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
	}
}
