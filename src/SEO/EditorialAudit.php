<?php
/** Local editorial audit for newsroom content; no external service required. */
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class EditorialAudit {
	/** @param \WP_Post $post @return array<string,mixed> */
	public function analyze( $post ) {
		$content = (string) $post->post_content;
		$text    = trim( wp_strip_all_tags( $content ) );
		$words   = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$words   = is_array( $words ) ? $words : array();
		$count   = count( $words );
		$seo_title = (string) PostMeta::get( $post->ID, PostMeta::TITLE, '' );
		$title     = trim( wp_strip_all_tags( '' !== $seo_title ? $seo_title : (string) $post->post_title ) );
		$description = PostMeta::get( $post->ID, PostMeta::DESCRIPTION, '' );
		$issues  = array();
		$passed  = 0;
		$total   = 8;

		$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
		$description_length = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );
		if ( $title_length >= 30 && $title_length <= 65 ) { $passed++; } else { $issues[] = __( 'Keep the headline between 30 and 65 characters.', 'publishrank' ); }
		if ( $description && $description_length >= 70 && $description_length <= 160 ) { $passed++; } else { $issues[] = __( 'Add a 70–160 character meta description.', 'publishrank' ); }
		if ( $count >= 300 ) { $passed++; } else { $issues[] = __( 'Add enough original context for a substantial news article (300+ words recommended).', 'publishrank' ); }
		if ( preg_match( '/<h2\b/i', $content ) ) { $passed++; } else { $issues[] = __( 'Use descriptive H2 subheadings for longer articles.', 'publishrank' ); }
		if ( has_post_thumbnail( $post ) ) { $passed++; } else { $issues[] = __( 'Add a featured image for article and social previews.', 'publishrank' ); }
		if ( get_post_meta( $post->ID, PostMeta::AUTHOR_NAME, true ) || get_userdata( $post->post_author ) ) { $passed++; } else { $issues[] = __( 'Add a named author or editorial byline.', 'publishrank' ); }
		if ( get_post_time( 'U', true, $post ) && get_post_modified_time( 'U', true, $post ) ) { $passed++; } else { $issues[] = __( 'Ensure published and modified dates are available.', 'publishrank' ); }
		if ( false !== strpos( $content, 'href=' ) ) { $passed++; } else { $issues[] = __( 'Add relevant internal or source links where editorially appropriate.', 'publishrank' ); }

		$sentences = preg_split( '/[.!?]+\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$sentences = is_array( $sentences ) ? count( $sentences ) : 0;
		$reading = $sentences > 0 ? round( 206.835 - ( 1.015 * ( $count / $sentences ) ) - ( 84.6 * ( $this->syllables( $text ) / max( 1, $count ) ) ), 1 ) : 0;
		return array( 'score' => (int) round( ( $passed / $total ) * 100 ), 'passed' => $passed, 'total' => $total, 'issues' => $issues, 'words' => $count, 'reading_ease' => $reading );
	}

	private function syllables( $text ) {
		$words = preg_split( '/\s+/u', strtolower( preg_replace( '/[^a-z\s]/i', ' ', $text ) ), -1, PREG_SPLIT_NO_EMPTY );
		$total = 0;
		foreach ( (array) $words as $word ) {
			$word = preg_replace( '/e$/', '', $word );
			$total += max( 1, preg_match_all( '/[aeiouy]+/', $word, $matches ) );
		}
		return $total;
	}
}
