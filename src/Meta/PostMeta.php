<?php
/** Post-level SEO fields and sanitization. */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Schema\SchemaTypes;

defined( 'ABSPATH' ) || exit;

final class PostMeta {
	const TITLE                    = '_publishrank_seo_title';
	const DESCRIPTION              = '_publishrank_seo_description';
	const ROBOTS                   = '_publishrank_seo_robots';
	const CANONICAL                = '_publishrank_seo_canonical';
	const OG_TITLE                 = '_publishrank_seo_og_title';
	const OG_DESCRIPTION           = '_publishrank_seo_og_description';
	const OG_IMAGE                 = '_publishrank_seo_og_image';
	const OG_IMAGE_ALT             = '_publishrank_seo_og_image_alt';
	const TWITTER_CARD             = '_publishrank_seo_twitter_card';
	const TWITTER_CREATOR          = '_publishrank_seo_twitter_creator';
	const FOCUS_KEYWORD            = '_publishrank_seo_focus_keyword';
	const PRIMARY_KEYPHRASE        = '_publishrank_seo_primary_keyphrase';
	const SECONDARY_KEYPHRASES     = '_publishrank_seo_secondary_keyphrases';
	const RELATED_TERMS            = '_publishrank_seo_related_terms';
	const ENTITY_TOPIC             = '_publishrank_seo_entity_topic';
	const CORNERSTONE              = '_publishrank_seo_cornerstone';
	const PRIMARY_CATEGORY         = '_publishrank_seo_primary_category';
	const VIDEO_THUMBNAIL          = '_publishrank_seo_video_thumbnail';
	const VIDEO_DURATION           = '_publishrank_seo_video_duration';
	const SCHEMA_TYPE              = '_publishrank_seo_schema_type';
	const FAQ_ITEMS                = '_publishrank_seo_faq_items';
	const VIDEO_URL                = '_publishrank_seo_video_url';
	const VIDEO_EMBED_URL          = '_publishrank_seo_video_embed_url';
	const AUDIO_URL                = '_publishrank_seo_audio_url';
	const AUDIO_DURATION           = '_publishrank_seo_audio_duration';
	const EVENT_START              = '_publishrank_seo_event_start';
	const EVENT_LOCATION           = '_publishrank_seo_event_location';
	const EVENT_STREET             = '_publishrank_seo_event_street';
	const EVENT_LOCALITY           = '_publishrank_seo_event_locality';
	const EVENT_REGION             = '_publishrank_seo_event_region';
	const EVENT_POSTAL_CODE        = '_publishrank_seo_event_postal_code';
	const EVENT_COUNTRY            = '_publishrank_seo_event_country';
	const NEWS_ELIGIBLE            = '_publishrank_seo_news_eligible';
	const AUTHOR_NAME              = '_publishrank_seo_author_name';
	const AUTHOR_JOB_TITLE         = '_publishrank_seo_author_job_title';
	const AUTHOR_IMAGE             = '_publishrank_seo_author_image';
	const ACCESSIBLE_FOR_FREE      = '_publishrank_seo_accessible_for_free';
	const CONTENT_TIER             = '_publishrank_seo_content_tier';
	const CITATIONS                = '_publishrank_seo_citations';
	const REVIEW_ITEM              = '_publishrank_seo_review_item';
	const REVIEW_RATING            = '_publishrank_seo_review_rating';
	const RECIPE_INGREDIENTS       = '_publishrank_seo_recipe_ingredients';
	const RECIPE_STEPS             = '_publishrank_seo_recipe_steps';
	const RECIPE_TIME              = '_publishrank_seo_recipe_time';
	const RECIPE_YIELD             = '_publishrank_seo_recipe_yield';
	const PRODUCT_SKU              = '_publishrank_seo_product_sku';
	const PRODUCT_BRAND            = '_publishrank_seo_product_brand';
	const PRODUCT_PRICE            = '_publishrank_seo_product_price';
	const PRODUCT_CURRENCY         = '_publishrank_seo_product_currency';
	const PRODUCT_AVAILABILITY     = '_publishrank_seo_product_availability';
	const HREFLANG                 = '_publishrank_seo_hreflang';
	const WEB_STORY_POSTER         = '_publishrank_web_story_poster';
	const WEB_STORY_PUBLISHER_LOGO = '_publishrank_web_story_publisher_logo';

	/** Meta keys that editors may override and that are exposed to the block editor and REST API. */
	public static function overridable() {
		return array(
			self::TITLE          => 'text',
			self::DESCRIPTION    => 'text',
			self::CANONICAL      => 'url',
			self::OG_TITLE       => 'text',
			self::OG_DESCRIPTION => 'text',
			self::OG_IMAGE       => 'public_url',
			self::OG_IMAGE_ALT   => 'text',
			self::ROBOTS         => 'robots',
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
							if ( 'url' === $kind ) {
								return PostMeta::sanitize_url( addslashes( $value ) ); }
							if ( 'public_url' === $kind ) {
								return PostMeta::sanitize_public_url( addslashes( $value ) ); }
							if ( 'robots' === $kind ) {
								$value = sanitize_text_field( $value );
								return '' === trim( $value ) ? '' : ( new MetaGenerator() )->normalize_robots( $value ); }
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
		// Token-preserving: sanitize_text_field() alone would turn "%%date%%" into "%te%%" and "%%category%%" into "%tegory%%".
		return TemplateResolver::sanitize_template( wp_unslash( (string) $value ), $length );
	}

	/** Trim an already-sanitized string to a maximum number of characters. */
	public static function truncate( $value, $length = 255 ) {
		$value = (string) $value;
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	public static function sanitize_url( $value ) {
		$value = esc_url_raw( wp_unslash( (string) $value ) );
		return wp_validate_redirect( $value, '' ) ? $value : '';
	}

	/** Validate public media/embed URLs; unlike redirect destinations these may use an external CDN or host. */
	public static function sanitize_public_url( $value ) {
		$value = trim( (string) wp_unslash( $value ) );
		if ( '' === $value ) {
			return ''; }
		if ( 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' ) ) {
			return esc_url_raw( home_url( $value ) );
		}
		$value = esc_url_raw( $value );
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return ''; }
		return $value;
	}

	public static function get_list( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );
		if ( ! is_array( $value ) ) {
			return array(); }
		return array_values( array_filter( array_map( 'strval', $value ) ) );
	}

	public static function sanitize_list( $values, $max_items = 20, $length = 100 ) {
		if ( ! is_array( $values ) ) {
			$values = array( $values ); }
		$clean = array();
		foreach ( array_slice( $values, 0, $max_items ) as $value ) {
			$value = sanitize_text_field( (string) $value );
			$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
			if ( '' !== $value && ! in_array( $value, $clean, true ) ) {
				$clean[] = $value; }
		}
		return $clean;
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['publishrank_seo_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_seo_meta_nonce'] ) ), 'publishrank_seo_meta' ) ) {
			return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return; }
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return; }
		$text_fields = array(
			self::TITLE             => 255,
			self::DESCRIPTION       => 320,
			self::OG_TITLE          => 255,
			self::OG_DESCRIPTION    => 320,
			self::OG_IMAGE_ALT      => 255,
			self::TWITTER_CREATOR   => 15,
			self::ENTITY_TOPIC      => 120,
			self::VIDEO_DURATION    => 32,
			self::AUTHOR_NAME       => 255,
			self::AUTHOR_JOB_TITLE  => 120,
			self::CONTENT_TIER      => 80,
			self::CITATIONS         => 2000,
		);
		foreach ( $text_fields as $key => $length ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, TemplateResolver::sanitize_template( wp_unslash( $_POST[ $key ] ), $length ) ); }
		}
		foreach ( array(
			self::SECONDARY_KEYPHRASES => array( 10, 100 ),
			self::RELATED_TERMS        => array( 20, 100 ),
		) as $key => $limits ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue; }
			$raw = map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			update_post_meta( $post_id, $key, self::sanitize_list( $raw, $limits[0], $limits[1] ) );
		}
		foreach ( array( self::CANONICAL ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, self::sanitize_url( esc_url_raw( wp_unslash( $_POST[ $key ] ) ) ) ); }
		}
		foreach ( array( self::OG_IMAGE, self::VIDEO_THUMBNAIL, self::AUTHOR_IMAGE, self::WEB_STORY_POSTER, self::WEB_STORY_PUBLISHER_LOGO ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, self::sanitize_public_url( esc_url_raw( wp_unslash( $_POST[ $key ] ) ) ) ); }
		}
		if ( isset( $_POST[ self::ROBOTS ] ) ) {
			$robots = sanitize_text_field( wp_unslash( $_POST[ self::ROBOTS ] ) );
			// Use the same allow-list as frontend output so editors can safely combine supported directives.
			update_post_meta( $post_id, self::ROBOTS, strlen( trim( $robots ) ) > 0 ? ( new MetaGenerator() )->normalize_robots( $robots ) : '' ); // Blank = follow the site default.
		}
		if ( isset( $_POST[ self::TWITTER_CARD ] ) ) {
			$card = sanitize_text_field( wp_unslash( $_POST[ self::TWITTER_CARD ] ) );
			update_post_meta( $post_id, self::TWITTER_CARD, in_array( $card, array( 'summary', 'summary_large_image' ), true ) ? $card : 'summary_large_image' );
		}
		foreach ( array( self::RECIPE_INGREDIENTS, self::RECIPE_STEPS, self::HREFLANG ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) ); }
		}
		if ( isset( $_POST[ self::SCHEMA_TYPE ] ) ) {
			$type          = sanitize_text_field( wp_unslash( $_POST[ self::SCHEMA_TYPE ] ) );
			$resolved_type = SchemaTypes::resolve_in( $type, SchemaTypes::selectable(), '' );
			update_post_meta( $post_id, self::SCHEMA_TYPE, $resolved_type ); // Canonical casing; unknown or non-selectable values are cleared.
		}
		foreach ( array( self::VIDEO_URL, self::VIDEO_EMBED_URL, self::AUDIO_URL, self::VIDEO_THUMBNAIL ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, self::sanitize_public_url( esc_url_raw( wp_unslash( $_POST[ $key ] ) ) ) ); }
		}
		foreach ( array(
			self::AUDIO_DURATION    => 32,
			self::EVENT_START       => 40,
			self::EVENT_LOCATION    => 200,
			self::EVENT_STREET      => 200,
			self::EVENT_LOCALITY    => 120,
			self::EVENT_REGION      => 120,
			self::EVENT_POSTAL_CODE => 30,
			self::EVENT_COUNTRY     => 2,
			self::REVIEW_ITEM       => 200,
			self::REVIEW_RATING     => 8,
			self::RECIPE_TIME       => 32,
			self::RECIPE_YIELD      => 60,
			self::PRODUCT_SKU       => 100,
			self::PRODUCT_BRAND     => 120,
			self::PRODUCT_PRICE     => 20,
			self::PRODUCT_CURRENCY  => 3,
		) as $key => $length ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, self::truncate( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), $length ) ); }
		}
		if ( isset( $_POST[ self::PRODUCT_AVAILABILITY ] ) ) {
			$availability = sanitize_text_field( wp_unslash( $_POST[ self::PRODUCT_AVAILABILITY ] ) );
			$allowed      = array( 'https://schema.org/InStock', 'https://schema.org/OutOfStock', 'https://schema.org/PreOrder', 'https://schema.org/BackOrder', 'https://schema.org/Discontinued' );
			update_post_meta( $post_id, self::PRODUCT_AVAILABILITY, in_array( $availability, $allowed, true ) ? $availability : '' );
		}
		if ( isset( $_POST['publishrank_faq_items'] ) && is_array( $_POST['publishrank_faq_items'] ) ) {
			$faq = array();
			$faq_posted = array_slice( map_deep( wp_unslash( $_POST['publishrank_faq_items'] ), 'sanitize_textarea_field' ), 0, 20 );
			foreach ( $faq_posted as $item ) {
				if ( ! is_array( $item ) ) {
					continue; }
				$q = isset( $item['q'] ) ? self::truncate( sanitize_text_field( $item['q'] ), 300 ) : '';
				$a = isset( $item['a'] ) ? sanitize_textarea_field( $item['a'] ) : '';
				if ( '' !== $q && '' !== $a ) {
					$faq[] = array(
						'q' => $q,
						'a' => wp_trim_words( $a, 200, '' ),
					); }
			}
			update_post_meta( $post_id, self::FAQ_ITEMS, $faq );
		}
		if ( ! empty( $_POST['publishrank_seo_article_fields'] ) ) {
			update_post_meta( $post_id, self::ACCESSIBLE_FOR_FREE, isset( $_POST[ self::ACCESSIBLE_FOR_FREE ] ) ? '1' : '0' ); }
		if ( isset( $_POST[ self::NEWS_ELIGIBLE ] ) ) {
			update_post_meta( $post_id, self::NEWS_ELIGIBLE, in_array( sanitize_text_field( wp_unslash( $_POST[ self::NEWS_ELIGIBLE ] ) ), array( '1' ), true ) ? '1' : '-1' );
		}
		update_post_meta( $post_id, self::CORNERSTONE, isset( $_POST[ self::CORNERSTONE ] ) ? '1' : '0' );
		if ( isset( $_POST[ self::PRIMARY_CATEGORY ] ) ) {
			$category_id = absint( wp_unslash( $_POST[ self::PRIMARY_CATEGORY ] ) );
			update_post_meta( $post_id, self::PRIMARY_CATEGORY, term_exists( $category_id, 'category' ) ? (string) $category_id : '' );
		}
		SeoData::flush( (int) $post_id );
	}
}
