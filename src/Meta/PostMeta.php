<?php
/** Post-level SEO fields and sanitization. */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Schema\SchemaTypes;

defined( 'ABSPATH' ) || exit;

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
	const FAQ_ITEMS = '_publishrank_seo_faq_items';
	const VIDEO_URL = '_publishrank_seo_video_url';
	const VIDEO_EMBED_URL = '_publishrank_seo_video_embed_url';
	const AUDIO_URL = '_publishrank_seo_audio_url';
	const AUDIO_DURATION = '_publishrank_seo_audio_duration';
	const EVENT_START = '_publishrank_seo_event_start';
	const EVENT_LOCATION = '_publishrank_seo_event_location';
	const NEWS_ELIGIBLE = '_publishrank_seo_news_eligible';
	const AUTHOR_NAME = '_publishrank_seo_author_name';
	const AUTHOR_JOB_TITLE = '_publishrank_seo_author_job_title';
	const AUTHOR_IMAGE = '_publishrank_seo_author_image';
	const ACCESSIBLE_FOR_FREE = '_publishrank_seo_accessible_for_free';
	const CONTENT_TIER = '_publishrank_seo_content_tier';
	const CITATIONS = '_publishrank_seo_citations';
	const REVIEW_ITEM = '_publishrank_seo_review_item';
	const REVIEW_RATING = '_publishrank_seo_review_rating';
	const RECIPE_INGREDIENTS = '_publishrank_seo_recipe_ingredients';
	const RECIPE_STEPS = '_publishrank_seo_recipe_steps';
	const RECIPE_TIME = '_publishrank_seo_recipe_time';
	const RECIPE_YIELD = '_publishrank_seo_recipe_yield';
	const HREFLANG = '_publishrank_seo_hreflang';

	/** Meta keys that editors may override and that are exposed to the block editor and REST API. */
	public static function overridable() {
		return array(
			self::TITLE => 'text', self::DESCRIPTION => 'text', self::CANONICAL => 'url', self::OG_TITLE => 'text',
			self::OG_DESCRIPTION => 'text', self::OG_IMAGE => 'url', self::OG_IMAGE_ALT => 'text', self::ROBOTS => 'robots',
			self::FOCUS_KEYWORD => 'text',
		);
	}

	/** Public post types that support SEO overrides. */
	public static function post_types() {
		$types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$types = array_diff( $types, array( 'attachment' ) );
		return array_values( array_unique( array_filter( (array) apply_filters( 'publishrank_seo_meta_box_post_types', $types ) ) ) );
	}

	/** Register overrides as post meta so the block editor, REST API and WP-CLI can read and write them. */
	public static function register_meta() {
		foreach ( self::post_types() as $post_type ) {
			foreach ( self::overridable() as $key => $kind ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'show_in_rest'      => true,
						'sanitize_callback' => static function ( $value ) use ( $kind ) {
							$value = is_scalar( $value ) ? (string) $value : '';
							if ( 'url' === $kind ) { return PostMeta::sanitize_url( addslashes( $value ) ); }
							if ( 'robots' === $kind ) { $value = sanitize_text_field( $value ); return '' === trim( $value ) ? '' : ( new MetaGenerator() )->normalize_robots( $value ); }
							return PostMeta::sanitize_text( addslashes( $value ), 320 );
						},
						'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', (int) $post_id );
						},
					)
				);
			}
		}
	}

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
			update_post_meta( $post_id, self::ROBOTS, '' === trim( $robots ) ? '' : ( new MetaGenerator() )->normalize_robots( $robots ) ); // Blank = follow the site default.
		}
		if ( isset( $_POST[ self::TWITTER_CARD ] ) ) {
			$card = sanitize_text_field( wp_unslash( $_POST[ self::TWITTER_CARD ] ) );
			update_post_meta( $post_id, self::TWITTER_CARD, in_array( $card, array( 'summary', 'summary_large_image' ), true ) ? $card : 'summary_large_image' );
		}
		foreach ( array( self::RECIPE_INGREDIENTS, self::RECIPE_STEPS, self::HREFLANG ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) ); }
		}
		if ( isset( $_POST[ self::SCHEMA_TYPE ] ) ) {
			$type = sanitize_text_field( wp_unslash( $_POST[ self::SCHEMA_TYPE ] ) );
			update_post_meta( $post_id, self::SCHEMA_TYPE, ( '' === $type || SchemaTypes::resolve( $type ) ) ? $type : '' );
		}
		foreach ( array( self::VIDEO_URL, self::VIDEO_EMBED_URL, self::AUDIO_URL, self::VIDEO_THUMBNAIL ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, self::sanitize_url( $_POST[ $key ] ) ); }
		}
		foreach ( array( self::AUDIO_DURATION => 32, self::EVENT_START => 40, self::EVENT_LOCATION => 200, self::REVIEW_ITEM => 200, self::REVIEW_RATING => 8, self::RECIPE_TIME => 32, self::RECIPE_YIELD => 60 ) as $key => $length ) {
			if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, self::sanitize_text( $_POST[ $key ], $length ) ); }
		}
		if ( isset( $_POST['publishrank_faq_items'] ) && is_array( $_POST['publishrank_faq_items'] ) ) {
			$faq = array();
			foreach ( array_slice( $_POST['publishrank_faq_items'], 0, 20 ) as $item ) {
				if ( ! is_array( $item ) ) { continue; }
				$q = isset( $item['q'] ) ? self::sanitize_text( $item['q'], 300 ) : '';
				$a = isset( $item['a'] ) ? sanitize_textarea_field( wp_unslash( $item['a'] ) ) : '';
				if ( '' !== $q && '' !== $a ) { $faq[] = array( 'q' => $q, 'a' => wp_trim_words( $a, 200, '' ) ); }
			}
			update_post_meta( $post_id, self::FAQ_ITEMS, $faq );
		}
		if ( ! empty( $_POST['publishrank_seo_article_fields'] ) ) { update_post_meta( $post_id, self::ACCESSIBLE_FOR_FREE, isset( $_POST[ self::ACCESSIBLE_FOR_FREE ] ) ? '1' : '0' ); }
		if ( isset( $_POST[ self::NEWS_ELIGIBLE ] ) ) {
			update_post_meta( $post_id, self::NEWS_ELIGIBLE, '1' === (string) wp_unslash( $_POST[ self::NEWS_ELIGIBLE ] ) ? '1' : '-1' );
		}
		update_post_meta( $post_id, self::CORNERSTONE, isset( $_POST[ self::CORNERSTONE ] ) ? '1' : '0' );
		if ( isset( $_POST[ self::PRIMARY_CATEGORY ] ) ) {
			$category_id = absint( wp_unslash( $_POST[ self::PRIMARY_CATEGORY ] ) );
			update_post_meta( $post_id, self::PRIMARY_CATEGORY, term_exists( $category_id, 'category' ) ? (string) $category_id : '' );
		}
		SeoData::flush( (int) $post_id );
	}
}
