<?php
/** Post-level SEO fields and sanitization. */
namespace PublishRankSEO\Meta;

final class PostMeta {
	const TITLE = '_publishrank_seo_title';
	const DESCRIPTION = '_publishrank_seo_description';
	const ROBOTS = '_publishrank_seo_robots';
	const CANONICAL = '_publishrank_seo_canonical';
	const OG_TITLE = '_publishrank_seo_og_title';
	const OG_DESCRIPTION = '_publishrank_seo_og_description';
	const OG_IMAGE = '_publishrank_seo_og_image';
	const OG_IMAGE_ALT = '_publishrank_seo_og_image_alt';
	const TWITTER_CARD = '_publishrank_seo_twitter_card';
	const TWITTER_CREATOR = '_publishrank_seo_twitter_creator';
	const FOCUS_KEYWORD = '_publishrank_seo_focus_keyword';
	const PRIMARY_KEYPHRASE = '_publishrank_seo_primary_keyphrase';
	const SECONDARY_KEYPHRASES = '_publishrank_seo_secondary_keyphrases';
	const RELATED_TERMS = '_publishrank_seo_related_terms';
	const ENTITY_TOPIC = '_publishrank_seo_entity_topic';
	const CORNERSTONE = '_publishrank_seo_cornerstone';
	const PRIMARY_CATEGORY = '_publishrank_seo_primary_category';
	const VIDEO_THUMBNAIL = '_publishrank_seo_video_thumbnail';
	const VIDEO_DURATION = '_publishrank_seo_video_duration';
	const SCHEMA_TYPE = '_publishrank_seo_schema_type';
	const NEWS_ELIGIBLE = '_publishrank_seo_news_eligible';
	const AUTHOR_NAME = '_publishrank_seo_author_name';
	const AUTHOR_JOB_TITLE = '_publishrank_seo_author_job_title';
	const AUTHOR_IMAGE = '_publishrank_seo_author_image';
	const ACCESSIBLE_FOR_FREE = '_publishrank_seo_accessible_for_free';
	const CONTENT_TIER = '_publishrank_seo_content_tier';
	const CITATIONS = '_publishrank_seo_citations';

	public static function get( $post_id, $key, $default = '' ) {
		$value = get_post_meta( $post_id, $key, true );
		return '' === $value ? $default : $value;
	}

	public static function sanitize_text( $value, $length = 255 ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	public static function sanitize_url( $value ) {
		$value = esc_url_raw( wp_unslash( (string) $value ) );
		return wp_validate_redirect( $value, '' ) ? $value : '';
	}

	public static function get_list( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );
		if ( ! is_array( $value ) ) { return array(); }
		return array_values( array_filter( array_map( 'strval', $value ) ) );
	}

	public static function sanitize_list( $values, $max_items = 20, $length = 100 ) {
		if ( ! is_array( $values ) ) { $values = array( $values ); }
		$clean = array();
		foreach ( array_slice( $values, 0, $max_items ) as $value ) {
			$value = sanitize_text_field( (string) $value );
			$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
			if ( '' !== $value && ! in_array( $value, $clean, true ) ) { $clean[] = $value; }
		}
		return $clean;
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['publishrank_seo_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_seo_meta_nonce'] ) ), 'publishrank_seo_meta' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$text_fields = array(
			self::TITLE => 255, self::DESCRIPTION => 320, self::OG_TITLE => 255,
			self::OG_DESCRIPTION => 320, self::OG_IMAGE_ALT => 255, self::TWITTER_CREATOR => 15,
			self::FOCUS_KEYWORD => 100, self::PRIMARY_KEYPHRASE => 100, self::ENTITY_TOPIC => 120, self::VIDEO_DURATION => 32, self::AUTHOR_NAME => 255,
			self::AUTHOR_JOB_TITLE => 120, self::CONTENT_TIER => 80, self::CITATIONS => 2000,
		);
		foreach ( $text_fields as $key => $length ) {
			if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, self::sanitize_text( $_POST[ $key ], $length ) ); }
		}
		foreach ( array( self::SECONDARY_KEYPHRASES => array( 10, 100 ), self::RELATED_TERMS => array( 20, 100 ) ) as $key => $limits ) {
			if ( ! isset( $_POST[ $key ] ) ) { continue; }
			$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			update_post_meta( $post_id, $key, self::sanitize_list( $raw, $limits[0], $limits[1] ) );
		}
		foreach ( array( self::CANONICAL, self::OG_IMAGE, self::VIDEO_THUMBNAIL, self::AUTHOR_IMAGE ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, self::sanitize_url( $_POST[ $key ] ) ); }
		}
		if ( isset( $_POST[ self::ROBOTS ] ) ) {
			$robots = sanitize_text_field( wp_unslash( $_POST[ self::ROBOTS ] ) );
			// Use the same allow-list as frontend output so editors can safely combine supported directives.
			update_post_meta( $post_id, self::ROBOTS, ( new MetaGenerator() )->normalize_robots( $robots ) );
		}
		if ( isset( $_POST[ self::TWITTER_CARD ] ) ) {
			$card = sanitize_text_field( wp_unslash( $_POST[ self::TWITTER_CARD ] ) );
			update_post_meta( $post_id, self::TWITTER_CARD, in_array( $card, array( 'summary', 'summary_large_image' ), true ) ? $card : 'summary_large_image' );
		}
		if ( isset( $_POST[ self::SCHEMA_TYPE ] ) ) {
			$type = sanitize_key( wp_unslash( $_POST[ self::SCHEMA_TYPE ] ) );
			update_post_meta( $post_id, self::SCHEMA_TYPE, in_array( $type, array( 'NewsArticle', 'Article', 'BlogPosting' ), true ) ? $type : 'NewsArticle' );
		}
		update_post_meta( $post_id, self::ACCESSIBLE_FOR_FREE, isset( $_POST[ self::ACCESSIBLE_FOR_FREE ] ) ? '1' : '0' );
		update_post_meta( $post_id, self::NEWS_ELIGIBLE, isset( $_POST[ self::NEWS_ELIGIBLE ] ) ? '1' : '0' );
		update_post_meta( $post_id, self::CORNERSTONE, isset( $_POST[ self::CORNERSTONE ] ) ? '1' : '0' );
		if ( isset( $_POST[ self::PRIMARY_CATEGORY ] ) ) {
			$category_id = absint( wp_unslash( $_POST[ self::PRIMARY_CATEGORY ] ) );
			update_post_meta( $post_id, self::PRIMARY_CATEGORY, term_exists( $category_id, 'category' ) ? (string) $category_id : '' );
		}
	}
}
