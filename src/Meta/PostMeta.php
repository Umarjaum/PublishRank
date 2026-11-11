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
	const X_TITLE                  = '_publishrank_seo_x_title';
	const X_DESCRIPTION            = '_publishrank_seo_x_description';
	const MASTODON_TITLE           = '_publishrank_seo_mastodon_title';
	const MASTODON_DESCRIPTION     = '_publishrank_seo_mastodon_description';
	const OG_IMAGE                 = '_publishrank_seo_og_image';
	const OG_IMAGE_ALT             = '_publishrank_seo_og_image_alt';
	const TWITTER_CARD             = '_publishrank_seo_twitter_card';
	const TWITTER_CREATOR          = '_publishrank_seo_twitter_creator';
	const FOCUS_KEYWORD            = '_publishrank_seo_focus_keyword';
	const PRIMARY_KEYPHRASE        = '_publishrank_seo_primary_keyphrase';
	const SECONDARY_KEYPHRASES     = '_publishrank_seo_secondary_keyphrases';
	const KEYPHRASE_SYNONYMS       = '_publishrank_seo_keyphrase_synonyms';
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
	const NEWS_STOCK_TICKERS       = '_publishrank_seo_news_stock_tickers';
	const AUTHOR_NAME              = '_publishrank_seo_author_name';
	const AUTHOR_JOB_TITLE         = '_publishrank_seo_author_job_title';
	const AUTHOR_IMAGE             = '_publishrank_seo_author_image';
	const ACCESSIBLE_FOR_FREE      = '_publishrank_seo_accessible_for_free';
	const CONTENT_TIER             = '_publishrank_seo_content_tier';
	const CITATIONS                = '_publishrank_seo_citations';
	const REVIEW_ITEM              = '_publishrank_seo_review_item';
	const REVIEW_RATING            = '_publishrank_seo_review_rating';
	const REVIEW_ITEM_TYPE         = '_publishrank_seo_review_item_type';
	const REVIEW_ITEM_URL          = '_publishrank_seo_review_item_url';
	const REVIEW_ITEM_IMAGE        = '_publishrank_seo_review_item_image';
	const REVIEW_ITEM_BRAND        = '_publishrank_seo_review_item_brand';
	const REVIEW_ITEM_CREATOR      = '_publishrank_seo_review_item_creator';
	const REVIEW_PROS              = '_publishrank_seo_review_pros';
	const REVIEW_CONS              = '_publishrank_seo_review_cons';
	const JOB_VALID_THROUGH        = '_publishrank_seo_job_valid_through';
	const JOB_EMPLOYMENT_TYPE      = '_publishrank_seo_job_employment_type';
	const JOB_HIRING_ORG           = '_publishrank_seo_job_hiring_org';
	const JOB_HIRING_ORG_URL       = '_publishrank_seo_job_hiring_org_url';
	const JOB_HIRING_ORG_LOGO      = '_publishrank_seo_job_hiring_org_logo';
	const JOB_LOCALITY             = '_publishrank_seo_job_locality';
	const JOB_REGION               = '_publishrank_seo_job_region';
	const JOB_COUNTRY              = '_publishrank_seo_job_country';
	const JOB_STREET               = '_publishrank_seo_job_street';
	const JOB_POSTAL_CODE          = '_publishrank_seo_job_postal_code';
	const JOB_REMOTE               = '_publishrank_seo_job_remote';
	const JOB_REMOTE_COUNTRY       = '_publishrank_seo_job_remote_country';
	const JOB_SALARY_MIN           = '_publishrank_seo_job_salary_min';
	const JOB_SALARY_MAX           = '_publishrank_seo_job_salary_max';
	const JOB_SALARY_CURRENCY      = '_publishrank_seo_job_salary_currency';
	const JOB_SALARY_UNIT          = '_publishrank_seo_job_salary_unit';
	const JOB_IDENTIFIER           = '_publishrank_seo_job_identifier';
	const JOB_DIRECT_APPLY         = '_publishrank_seo_job_direct_apply';
	const PAYWALL_SELECTOR         = '_publishrank_seo_paywall_selector';
	const CLAIM_TEXT               = '_publishrank_seo_claim_text';
	const CLAIM_AUTHOR             = '_publishrank_seo_claim_author';
	const CLAIM_APPEARANCE         = '_publishrank_seo_claim_appearance';
	const CLAIM_DATE               = '_publishrank_seo_claim_date';
	const CLAIM_RATING             = '_publishrank_seo_claim_rating';
	const CLAIM_VERDICT            = '_publishrank_seo_claim_verdict';
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
	const DATELINE                 = '_publishrank_seo_dateline';
	const LOCATION_CREATED         = '_publishrank_seo_location_created';
	const CONTENT_LOCATION         = '_publishrank_seo_content_location';
	const BACKSTORY                = '_publishrank_seo_backstory';
	const CORRECTION               = '_publishrank_seo_correction';

	/** Meta keys that editors may override and that are exposed to the block editor and REST API. */
	public static function overridable() {
		return array(
			self::TITLE          => 'text',
			self::DESCRIPTION    => 'text',
			self::CANONICAL      => 'url',
			self::OG_TITLE       => 'text',
			self::OG_DESCRIPTION => 'text',
			self::X_TITLE        => 'text',
			self::X_DESCRIPTION  => 'text',
			self::MASTODON_TITLE => 'text',
			self::MASTODON_DESCRIPTION => 'text',
			self::OG_IMAGE       => 'public_url',
			self::OG_IMAGE_ALT   => 'text',
			self::ROBOTS         => 'robots',
		);
	}

	/**
	 * Item types Google accepts for review snippets (https://developers.google.com/search/docs/appearance/structured-data/review-snippet).
	 *
	 * @return array<string,string>
	 */
	public static function review_item_types() {
		return array(
			'Product'             => __( 'Product', 'publishrank' ),
			'Book'                => __( 'Book', 'publishrank' ),
			'Movie'               => __( 'Movie', 'publishrank' ),
			'TVSeries'            => __( 'TV series', 'publishrank' ),
			'MusicRecording'      => __( 'Music recording', 'publishrank' ),
			'MusicAlbum'          => __( 'Music album', 'publishrank' ),
			'VideoGame'           => __( 'Video game', 'publishrank' ),
			'SoftwareApplication' => __( 'Software / app', 'publishrank' ),
			'MobileApplication'   => __( 'Mobile app', 'publishrank' ),
			'Course'              => __( 'Course', 'publishrank' ),
			'Event'               => __( 'Event', 'publishrank' ),
			'Game'                => __( 'Game', 'publishrank' ),
			'LocalBusiness'       => __( 'Local business', 'publishrank' ),
			'Restaurant'          => __( 'Restaurant', 'publishrank' ),
			'Hotel'               => __( 'Hotel', 'publishrank' ),
			'Organization'        => __( 'Organization', 'publishrank' ),
			'CreativeWorkSeason'  => __( 'TV / series season', 'publishrank' ),
			'Recipe'              => __( 'Recipe', 'publishrank' ),
		);
	}

	/** @return array<string,string> Google JobPosting employmentType values. */
	public static function job_employment_types() {
		return array(
			'FULL_TIME'  => __( 'Full time', 'publishrank' ),
			'PART_TIME'  => __( 'Part time', 'publishrank' ),
			'CONTRACTOR' => __( 'Contractor', 'publishrank' ),
			'TEMPORARY'  => __( 'Temporary', 'publishrank' ),
			'INTERN'     => __( 'Internship', 'publishrank' ),
			'VOLUNTEER'  => __( 'Volunteer', 'publishrank' ),
			'PER_DIEM'   => __( 'Per diem', 'publishrank' ),
			'OTHER'      => __( 'Other', 'publishrank' ),
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
			// The focus keyword is edited in the block editor sidebar, so it must be exposed to the REST API.
			register_post_meta(
				$post_type,
				self::FOCUS_KEYWORD,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => static function ( $value ) {
						return self::truncate( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 100 );
					},
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
			// Secondary keyphrases, synonyms and related terms are lists of short strings.
			foreach ( self::keyphrase_list_limits() as $list_key => $limits ) {
				register_post_meta(
					$post_type,
					$list_key,
					array(
						'type'              => 'array',
						'single'            => true,
						'default'           => array(),
						'show_in_rest'      => array(
							'schema' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
						'sanitize_callback' => static function ( $value ) use ( $limits ) {
							return PostMeta::parse_list( $value, $limits[0], $limits[1] );
						},
						'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', (int) $post_id );
						},
					)
				);
			}
		}
	}

	/** Keyphrase list meta keys with their maximum item count and item length. @return array<string,array<int,int>> */
	public static function keyphrase_list_limits() {
		return array(
			self::SECONDARY_KEYPHRASES => array( 10, 100 ),
			self::KEYPHRASE_SYNONYMS   => array( 10, 100 ),
			self::RELATED_TERMS        => array( 20, 100 ),
		);
	}

	/** Accept an array, or a string with one item per line or comma separated, and return a clean list. */
	public static function parse_list( $value, $max_items = 20, $length = 100 ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,;\x{060C}\x{061B}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY );
			$value = is_array( $value ) ? $value : array();
		} elseif ( ! is_array( $value ) ) {
			$value = array();
		}
		$value = array_filter( $value, 'is_scalar' );
		return self::sanitize_list( array_map( 'strval', $value ), $max_items, $length );
	}

	public static function get( $post_id, $key, $default = '' ) {
		$value = get_post_meta( $post_id, $key, true );
		$localized = LocalizedSeoMeta::get_override( $post_id, $key );
		if ( '' !== $localized ) {
			$value = $localized;
		}
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

	/**
	 * Validate a comma/space/newline separated list of EXCHANGE:SYMBOL stock tickers (max 5).
	 * Invalid entries are dropped, never guessed or rewritten into a different symbol.
	 *
	 * @param mixed $raw Raw user input.
	 * @return string[]
	 */
	public static function parse_tickers( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return array(); }
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtoupper( sanitize_text_field( (string) $raw ) ), -1, PREG_SPLIT_NO_EMPTY ) as $symbol ) {
			if ( preg_match( '/^[A-Z0-9]{1,12}:[A-Z0-9][A-Z0-9.\-]{0,11}$/', $symbol ) ) {
				$out[] = $symbol; }
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 5 );
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
			self::X_TITLE           => 255,
			self::X_DESCRIPTION     => 320,
			self::MASTODON_TITLE    => 255,
			self::MASTODON_DESCRIPTION => 320,
			self::OG_IMAGE_ALT      => 255,
			self::TWITTER_CREATOR   => 15,
			self::ENTITY_TOPIC      => 120,
			self::VIDEO_DURATION    => 32,
			self::AUTHOR_NAME       => 255,
			self::AUTHOR_JOB_TITLE  => 120,
			self::CONTENT_TIER      => 80,
			self::CITATIONS         => 2000,
		);
		foreach ( array(
			self::DATELINE         => 120,
			self::LOCATION_CREATED => 200,
			self::CONTENT_LOCATION => 200,
			self::BACKSTORY        => 1000,
			self::CORRECTION       => 1000,
			self::REVIEW_PROS      => 1500,
			self::REVIEW_CONS      => 1500,
			self::CLAIM_TEXT       => 500,
			self::CLAIM_AUTHOR     => 200,
			self::CLAIM_VERDICT    => 100,
			self::PAYWALL_SELECTOR => 200,
			self::REVIEW_ITEM_BRAND   => 120,
			self::REVIEW_ITEM_CREATOR => 200,
		) as $key => $length ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, self::truncate( sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ), $length ) ); }
		}
		if ( isset( $_POST[ self::FOCUS_KEYWORD ] ) ) {
			update_post_meta( $post_id, self::FOCUS_KEYWORD, self::truncate( sanitize_text_field( wp_unslash( $_POST[ self::FOCUS_KEYWORD ] ) ), 100 ) ); }
		foreach ( $text_fields as $key => $length ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, TemplateResolver::sanitize_template( wp_unslash( $_POST[ $key ] ), $length ) ); }
		}
		foreach ( array(
			self::SECONDARY_KEYPHRASES => array( 10, 100 ),
			self::KEYPHRASE_SYNONYMS   => array( 10, 100 ),
			self::RELATED_TERMS        => array( 20, 100 ),
		) as $key => $limits ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue; }
			// Textareas post one string (one item per line); older forms post an array. Both are accepted.
			$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; parse_list() sanitizes every item.
			update_post_meta( $post_id, $key, self::parse_list( $raw, $limits[0], $limits[1] ) );
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
		foreach ( array( self::REVIEW_ITEM_URL, self::REVIEW_ITEM_IMAGE, self::CLAIM_APPEARANCE ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, self::sanitize_public_url( esc_url_raw( wp_unslash( $_POST[ $key ] ) ) ) ); }
		}
		if ( isset( $_POST[ self::REVIEW_ITEM_TYPE ] ) ) {
			$item_type = sanitize_text_field( wp_unslash( $_POST[ self::REVIEW_ITEM_TYPE ] ) );
			update_post_meta( $post_id, self::REVIEW_ITEM_TYPE, array_key_exists( $item_type, self::review_item_types() ) ? $item_type : '' );
		}
		foreach ( array(
			self::JOB_HIRING_ORG     => 200,
			self::JOB_LOCALITY       => 120,
			self::JOB_REGION         => 120,
			self::JOB_STREET         => 200,
			self::JOB_POSTAL_CODE    => 30,
			self::JOB_IDENTIFIER     => 100,
		) as $key => $length ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, self::truncate( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), $length ) ); }
		}
		foreach ( array( self::JOB_HIRING_ORG_URL, self::JOB_HIRING_ORG_LOGO ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, self::sanitize_public_url( esc_url_raw( wp_unslash( $_POST[ $key ] ) ) ) ); }
		}
		foreach ( array( self::JOB_COUNTRY, self::JOB_REMOTE_COUNTRY ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$code = strtoupper( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
				update_post_meta( $post_id, $key, preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '' ); }
		}
		if ( isset( $_POST[ self::JOB_SALARY_CURRENCY ] ) ) {
			$code = strtoupper( sanitize_text_field( wp_unslash( $_POST[ self::JOB_SALARY_CURRENCY ] ) ) );
			update_post_meta( $post_id, self::JOB_SALARY_CURRENCY, preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '' ); }
		foreach ( array( self::JOB_SALARY_MIN, self::JOB_SALARY_MAX ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$num = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				update_post_meta( $post_id, $key, preg_match( '/^\d+(?:\.\d{1,2})?$/', $num ) ? $num : '' ); }
		}
		if ( isset( $_POST[ self::JOB_SALARY_UNIT ] ) ) {
			$unit = strtoupper( sanitize_text_field( wp_unslash( $_POST[ self::JOB_SALARY_UNIT ] ) ) );
			update_post_meta( $post_id, self::JOB_SALARY_UNIT, in_array( $unit, array( 'HOUR', 'DAY', 'WEEK', 'MONTH', 'YEAR' ), true ) ? $unit : '' ); }
		if ( isset( $_POST[ self::JOB_VALID_THROUGH ] ) ) {
			$date = sanitize_text_field( wp_unslash( $_POST[ self::JOB_VALID_THROUGH ] ) );
			update_post_meta( $post_id, self::JOB_VALID_THROUGH, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '' ); }
		if ( isset( $_POST['publishrank_job_fields'] ) ) {
			$types = isset( $_POST[ self::JOB_EMPLOYMENT_TYPE ] ) && is_array( $_POST[ self::JOB_EMPLOYMENT_TYPE ] ) ? array_map( 'sanitize_key', wp_unslash( $_POST[ self::JOB_EMPLOYMENT_TYPE ] ) ) : array();
			$types = array_values( array_intersect( array_map( 'strtoupper', $types ), array_keys( self::job_employment_types() ) ) );
			update_post_meta( $post_id, self::JOB_EMPLOYMENT_TYPE, implode( ',', $types ) );
			update_post_meta( $post_id, self::JOB_REMOTE, empty( $_POST[ self::JOB_REMOTE ] ) ? '0' : '1' );
			update_post_meta( $post_id, self::JOB_DIRECT_APPLY, empty( $_POST[ self::JOB_DIRECT_APPLY ] ) ? '0' : '1' );
		}
		if ( isset( $_POST[ self::CLAIM_RATING ] ) ) {
			$claim_rating = absint( wp_unslash( $_POST[ self::CLAIM_RATING ] ) );
			update_post_meta( $post_id, self::CLAIM_RATING, ( $claim_rating >= 1 && $claim_rating <= 5 ) ? (string) $claim_rating : '' );
		}
		if ( isset( $_POST[ self::CLAIM_DATE ] ) ) {
			$claim_date = sanitize_text_field( wp_unslash( $_POST[ self::CLAIM_DATE ] ) );
			update_post_meta( $post_id, self::CLAIM_DATE, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $claim_date ) ? $claim_date : '' );
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
		if ( ! empty( $_POST['publishrank_seo_news_tickers_field'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; parse_tickers() validates every symbol.
			$tickers = self::parse_tickers( isset( $_POST[ self::NEWS_STOCK_TICKERS ] ) ? wp_unslash( $_POST[ self::NEWS_STOCK_TICKERS ] ) : '' );
			if ( $tickers ) {
				update_post_meta( $post_id, self::NEWS_STOCK_TICKERS, implode( ', ', $tickers ) );
			} else {
				delete_post_meta( $post_id, self::NEWS_STOCK_TICKERS );
			}
		}
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
