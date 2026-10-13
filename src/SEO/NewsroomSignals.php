<?php
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class NewsroomSignals {
	/** @return string */
	public static function format( $post ) {
		$stored = sanitize_key( (string) get_post_meta( $post->ID, '_publishrank_seo_news_format', true ) );
		$allowed = array( 'article', 'breaking', 'live', 'analysis', 'opinion', 'feature', 'review', 'story' );
		if ( in_array( $stored, $allowed, true ) ) { return $stored; }
		$title = strtolower( wp_strip_all_tags( (string) $post->post_title ) );
		$slugs = array(); foreach ( (array) get_the_category( $post->ID ) as $category ) { if ( isset( $category->slug ) ) { $slugs[] = sanitize_key( $category->slug ); } }
		$haystack = $title . ' ' . implode( ' ', $slugs );
		// 'live' is never inferred from a title or category ("live music" is not a live blog); editors choose it explicitly.
		if ( preg_match( '/\b(breaking|just-in|urgent|developing)\b/', $haystack ) ) { return 'breaking'; }
		if ( preg_match( '/\b(opinion|editorial|comment)\b/', $haystack ) ) { return 'opinion'; }
		if ( preg_match( '/\b(analysis|explainer|background)\b/', $haystack ) ) { return 'analysis'; }
		if ( preg_match( '/\b(review|critique)\b/', $haystack ) ) { return 'review'; }
		if ( preg_match( '/\b(feature|longread|long-read)\b/', $haystack ) ) { return 'feature'; }
		return 'article';
	}

	/** @return string */
	public static function genre( $post ) {
		$format = self::format( $post );
		return array( 'breaking' => 'Breaking News', 'live' => 'Live Updates', 'analysis' => 'News Analysis', 'opinion' => 'Opinion', 'feature' => 'Feature', 'review' => 'Review' )[ $format ] ?? 'News';
	}
}
