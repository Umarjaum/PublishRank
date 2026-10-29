<?php
/**
 * Complete, site-local PublishRank settings surface.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	/** Register admin hooks. */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	/** Register the settings page. */
	public function menu() {
		add_submenu_page( \PublishRankSEO\Core\Branding::MENU_SLUG, __( 'PublishRank Settings', 'publishrank' ), __( 'Settings', 'publishrank' ), 'manage_options', 'publishrank', array( $this, 'page' ) );
	}

	/**
	 * Register an option. Options that moved to the Setup Wizard, or that have no field in this form, go in an
	 * unmanaged group: WordPress's options.php blanks every option in the submitted group that is missing from the POST.
	 */
	private function reg( $option, $args ) {
		static $unmanaged = null;
		if ( null === $unmanaged ) {
			$unmanaged = array_merge( \PublishRankSEO\Admin\OnboardingPage::setup_keys(), array( 'publishrank_indexnow_enabled', 'publishrank_indexnow_key', 'publishrank_indexnow_post_types', 'publishrank_indexnow_key_location', 'publishrank_seo_title_template_home', 'publishrank_seo_title_template_post', 'publishrank_seo_title_template_page', 'publishrank_seo_title_template_archive', 'publishrank_author_mode', 'publishrank_schema_legal_name' ) );
		}
		register_setting( in_array( $option, $unmanaged, true ) ? 'publishrank_seo_unmanaged' : 'publishrank_seo', $option, $args );
	}

	/** Register and sanitize every setting used by this site. */
	public function settings() {
		$this->reg(
			'publishrank_image_optimizer_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		$this->reg(
			'publishrank_image_max_width',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_image_width' ),
				'default'           => 2000,
			)
		);
		$this->reg(
			'publishrank_image_quality',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_image_quality' ),
				'default'           => 82,
			)
		);
		$this->reg(
			'publishrank_image_format',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_image_format' ),
				'default'           => 'webp',
			)
		);
		$this->reg(
			'publishrank_sitemap_endpoint',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_sitemap_endpoint' ),
				'default'           => 'publishrank-sitemap',
			)
		);
		$this->reg(
			'publishrank_sitemap_aliases',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_sitemap_aliases' ),
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_sitemap_stylesheet',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		$this->reg(
			'publishrank_seo_twitter_site',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_handle' ),
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_twitter_card',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_twitter_card' ),
				'default'           => 'summary_large_image',
			)
		);
		$this->reg(
			'publishrank_facebook_app_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_verification' ),
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_facebook_page_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_public_url' ),
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_social_watermark_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		$this->reg(
			'publishrank_social_watermark_logo',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_public_url' ),
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_social_watermark_position',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_watermark_position' ),
				'default'           => 'bottom-right',
			)
		);
		$this->reg(
			'publishrank_social_watermark_opacity',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_watermark_opacity' ),
				'default'           => 75,
			)
		);
		$this->reg(
			'publishrank_social_watermark_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_watermark_size' ),
				'default'           => 18,
			)
		);
		$this->reg(
			'publishrank_social_watermark_margin',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_watermark_margin' ),
				'default'           => 24,
			)
		);
		$this->reg(
			'publishrank_seo_news_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		$this->reg(
			'publishrank_seo_news_window',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_window' ),
				'default'           => 48,
			)
		);
			$this->reg(
				'publishrank_seo_publisher_type',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_publisher_type' ),
					'default'           => 'NewsMediaOrganization',
				)
			);
			$this->reg(
				'publishrank_seo_title_separator',
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '|',
				)
			);
		foreach ( array( 'publishrank_meta_enabled', 'publishrank_schema_enabled' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => true,
				)
			); }
					$this->reg(
						'publishrank_admin_access_mode',
						array(
							'type'              => 'string',
							'sanitize_callback' => array( $this, 'sanitize_admin_access_mode' ),
							'default'           => 'custom',
						)
					);
					$this->reg(
						'publishrank_admin_access_slug',
						array(
							'type'              => 'string',
							'sanitize_callback' => array( $this, 'sanitize_admin_access_slug' ),
							'default'           => '',
						)
					);
			$this->reg(
				'publishrank_seo_canonical_category_mode',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_canonical_category_mode' ),
					'default'           => 'permalink',
				)
			);
			$this->reg(
				'publishrank_schema_founding_date',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_schema_date' ),
					'default'           => '',
				)
			);
			$this->reg(
				'publishrank_seo_default_robots',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_robots' ),
					'default'           => 'index,follow',
				)
			);
		foreach ( array( 'publishrank_noindex_author_archives', 'publishrank_noindex_date_archives' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => false,
				)
			); }
		foreach ( array( 'publishrank_home_title', 'publishrank_home_description' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => '',
				)
			); }
		foreach ( array(
			'home'    => '%%sitename%% %%page%%',
			'post'    => '%%title%% %%sep%% %%sitename%% %%page%%',
			'page'    => '%%title%% %%sep%% %%sitename%%',
			'archive' => '%%title%% %%sep%% %%sitename%%',
		) as $context => $default ) {
			$this->reg(
				'publishrank_seo_title_template_' . $context,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_template' ),
					'default'           => $default,
				)
			);
		}
		$this->reg(
			'publishrank_seo_news_max_urls',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_urls' ),
				'default'           => 1000,
			)
		);
		$this->reg(
			'publishrank_seo_news_keywords',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		$this->reg(
			'publishrank_editorial_genre',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_editorial_genre' ),
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_sitemap_post_types',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
				'default'           => 'post',
			)
		);
		$this->reg(
			'publishrank_seo_media_post_types',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_media_post_types' ),
				'default'           => 'all',
			)
		);
		foreach ( array( 'publishrank_sitemap_pages_enabled', 'publishrank_sitemap_cpt_enabled', 'publishrank_sitemap_taxonomies_enabled', 'publishrank_sitemap_authors_enabled', 'publishrank_sitemap_attachments_enabled' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => true,
				)
			); }
		$this->reg(
			'publishrank_breadcrumbs_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		$this->reg(
			'publishrank_breadcrumb_schema_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		$this->reg(
			'publishrank_breadcrumb_style',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_breadcrumb_style' ),
				'default'           => 'plain',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_size',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_breadcrumb_size' ),
				'default'           => 'medium',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_text_color',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_hex_color',
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_link_color',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_hex_color',
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_bg_color',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_hex_color',
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_placement',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_breadcrumb_placement' ),
				'default'           => 'auto',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_hook',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_breadcrumb_hook' ),
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_home_label',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_breadcrumbs_separator',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '→',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_error_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_breadcrumb_error_mode' ),
				'default'           => 'skip',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_error_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => 'breadcrumb_error',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_fallback_label',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'Home',
			)
		);
		$this->reg(
			'publishrank_breadcrumb_fallback_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_public_url' ),
				'default'           => home_url( '/' ),
			)
		);
		$this->reg(
			'publishrank_breadcrumb_log_errors',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		$this->reg(
			'publishrank_indexnow_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		$this->reg(
			'publishrank_indexnow_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_indexnow_key' ),
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_identity_type',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_identity_type' ),
				'default'           => 'organization',
			)
		);
		foreach ( array( 'publishrank_identity_name', 'publishrank_identity_legal_name', 'publishrank_identity_alternate_name', 'publishrank_identity_alternate_names', 'publishrank_identity_description', 'publishrank_identity_founding_date', 'publishrank_identity_contact_email', 'publishrank_identity_contact_phone', 'publishrank_identity_street', 'publishrank_identity_locality', 'publishrank_identity_region', 'publishrank_identity_postal_code', 'publishrank_identity_country', 'publishrank_identity_person_name', 'publishrank_identity_person_alternate_name', 'publishrank_identity_person_description', 'publishrank_identity_person_job_title', 'publishrank_identity_person_email' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => '',
				)
			);
		}
		foreach ( array( 'publishrank_identity_parent_organization', 'publishrank_identity_owner_name', 'publishrank_identity_copyright_holder' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				)
			);
		}
		foreach ( array( 'publishrank_identity_logo', 'publishrank_identity_owner_url', 'publishrank_identity_owner_logo', 'publishrank_identity_person_image', 'publishrank_identity_person_url' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_public_url' ),
					'default'           => '',
				)
			);
		}
		foreach ( array_keys( \PublishRankSEO\Core\PublicationProfile::social_networks() ) as $network ) {
			$this->reg(
				'publishrank_social_' . $network,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_social_profile' ),
					'default'           => '',
				)
			);
		}
		$this->reg(
			'publishrank_social_custom',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_social_custom' ),
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_seo_hreflang_mirror',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		$this->reg(
			'publishrank_seo_multilingual_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		$this->reg(
			'publishrank_cdn_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		foreach ( array( 'publishrank_seo_publication_name', 'publishrank_indexnow_key', 'publishrank_indexnow_post_types', 'publishrank_seo_hreflang_map', 'publishrank_seo_news_post_types', 'publishrank_seo_news_exclude_ids', 'publishrank_seo_news_exclude_categories', 'publishrank_seo_news_genre', 'publishrank_editorial_genre', 'publishrank_seo_publisher_name', 'publishrank_publication_short_name', 'publishrank_default_language', 'publishrank_default_locale', 'publishrank_timezone', 'publishrank_country', 'publishrank_copyright', 'publishrank_contact', 'publishrank_social_profiles', 'publishrank_author_mode', 'publishrank_schema_org_description', 'publishrank_schema_legal_name', 'publishrank_schema_founder', 'publishrank_schema_phone', 'publishrank_schema_street_address', 'publishrank_schema_address_locality', 'publishrank_schema_address_region', 'publishrank_schema_postal_code', 'publishrank_schema_knows_about', 'publishrank_schema_parent_organization', 'publishrank_schema_founding_location' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => '',
				)
			);
		}
		foreach ( array( 'publishrank_seo_x_default_url', 'publishrank_indexnow_key_location', 'publishrank_primary_url', 'publishrank_media_host', 'publishrank_cdn_host', 'publishrank_video_host', 'publishrank_api_host', 'publishrank_seo_publisher_logo', 'publishrank_seo_default_social_image' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_public_url' ),
					'default'           => '',
				)
			);
		}
		foreach ( array( 'publishrank_seo_image_sitemap', 'publishrank_seo_video_sitemap', 'publishrank_seo_404_monitor' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => true,
				)
			);
		}
			$this->reg(
				'publishrank_image_license_url',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_public_url' ),
					'default'           => '',
				)
			);
		foreach ( array( 'google', 'bing', 'yandex', 'baidu', 'pinterest' ) as $engine ) {
			$this->reg(
				'publishrank_verification_' . $engine,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_verification' ),
					'default'           => '',
				)
			); }
	}


		/** @param mixed $value @return string */
	public function sanitize_canonical_category_mode( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'permalink', 'primary_category' ), true ) ? $value : 'permalink'; }
		/** @param mixed $value @return string */
	public function sanitize_admin_access_slug( $value ) {
		$value    = trim( sanitize_key( $value ), '/' );
		$reserved = array( 'admin', 'login', 'wp-admin', 'wp-login', 'wp-json', 'wp-cron', 'xmlrpc', 'feed', 'sitemap' );
		if ( '' === $value || strlen( $value ) < 6 || strlen( $value ) > 64 || ! preg_match( '/^[a-z][a-z0-9_-]+$/', $value ) || in_array( $value, $reserved, true ) ) {
			return '';
		}
		return $value;
	}
		/** @param mixed $value @return string */
	public function sanitize_admin_access_mode( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'custom', 'disabled' ), true ) ? $value : 'custom';
	}
	/** @param mixed $value @return string */
	public function sanitize_breadcrumb_style( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'plain', 'chips', 'bar', 'underline' ), true ) ? $value : 'plain';
	}

	/** @param mixed $value @return string */
	public function sanitize_breadcrumb_size( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'small', 'medium', 'large' ), true ) ? $value : 'medium';
	}

	/** @param mixed $value @return string */
	public function sanitize_breadcrumb_placement( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'auto', 'content', 'hook', 'manual', 'schema_only' ), true ) ? $value : 'auto';
	}

	/** @param mixed $value @return string */
	public function sanitize_breadcrumb_hook( $value ) {
		return trim( (string) preg_replace( '/[^A-Za-z0-9_:\\/\\-]/', '', (string) $value ) );
	}

	/** @param mixed $value @return string */
	public function sanitize_breadcrumb_error_mode( $value ) {
		$value   = sanitize_key( $value );
		$allowed = array( 'skip', 'fallback', 'text', 'disable' );
		return in_array( $value, $allowed, true ) ? $value : 'skip';
	}

	/** @param mixed $value @return string */
	public function sanitize_editorial_genre( $value ) {
		$allowed = array(
			'',
			'News',
			'Breaking News',
			'Live Updates',
			'Developing Story',
			'Local News',
			'National News',
			'International News',
			'Regional News',
			'World News',
			'Report',
			'Investigative',
			'In-Depth',
			'Exclusive',
			'Special Report',
			'Field Report',
			'Eyewitness Report',
			'Data Journalism',
			'Fact Check',
			'Verification',
			'News Analysis',
			'Analysis',
			'Explainer',
			'Background',
			'Context',
			'Briefing',
			'News Brief',
			'Roundup',
			'Timeline',
			'Opinion',
			'Editorial',
			'Column',
			'Commentary',
			'Op-Ed',
			'Letter to the Editor',
			'Guest Essay',
			'Perspective',
			'Interview',
			'Profile',
			'Q&A',
			'Portrait',
			'Biography',
			'Obituary',
			'Tribute',
			'Live Blog',
			'Event Coverage',
			'Election Coverage',
			'Breaking Coverage',
			'Conference Coverage',
			'Sports Coverage',
			'Review',
			'Criticism',
			'Preview',
			'Guide',
			'How-To',
			'FAQ',
			'Consumer Advice',
			'Service Journalism',
			'Travel',
			'Food',
			'Technology',
			'Science',
			'Health',
			'Business',
			'Finance',
			'Politics',
			'Sports',
			'Entertainment',
			'Culture',
			'Press Release',
			'Statement',
			'Transcript',
			'Speech',
			'Announcement',
			'Official Statement',
			'Court Filing',
			'Document',
			'Public Records',
			'Research',
			'Study',
			'Survey',
			'Feature',
			'Longform',
			'Magazine',
			'Essay',
			'Human Interest',
			'Special Feature',
			'Photo Essay',
			'Multimedia',
			'Documentary',
			'Satire',
			'User Generated',
			'Blog',
			'Archive',
			'Correction',
			'Update',
			'Retraction',
			'Clarification',
			'Fact Correction',
		);
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/** @param mixed $value @return string X/Twitter handle without the @ sign. */
	public function sanitize_custom_schema( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return ''; }
		$data = json_decode( $value, true );
		return ( JSON_ERROR_NONE === json_last_error() && is_array( $data ) ) ? wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
	}

	public function sanitize_handle( $value ) {
			return substr( preg_replace( '/[^A-Za-z0-9_]/', '', ltrim( trim( (string) $value ), '@' ) ), 0, 15 );
	}

	public function sanitize_twitter_card( $value ) {
		return in_array( (string) $value, array( 'summary', 'summary_large_image' ), true ) ? (string) $value : 'summary_large_image'; }
	public function sanitize_watermark_position( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' ), true ) ? $value : 'bottom-right'; }
	public function sanitize_watermark_opacity( $value ) {
		return min( 100, max( 10, absint( $value ) ) ); }
	public function sanitize_watermark_size( $value ) {
		return min( 50, max( 5, absint( $value ) ) ); }
	public function sanitize_watermark_margin( $value ) {
		return min( 300, max( 0, absint( $value ) ) ); }

		/** @param mixed $value @return string */
	public function sanitize_indexnow_key( $value ) {
		$key = preg_replace( '/[^A-Za-z0-9\-_]/', '', trim( (string) $value ) );
		return substr( (string) $key, 0, 128 );
	}

	/** @param mixed $value @return string */
	public function sanitize_publisher_type( $value ) {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'NewsMediaOrganization', 'Organization' ), true ) ? $value : 'NewsMediaOrganization';
	}

		/** @param mixed $value @return string */
	public function sanitize_robots( $value ) {
		$value = sanitize_text_field( (string) $value );
		return ( new \PublishRankSEO\Meta\MetaGenerator() )->normalize_robots( $value );
	}

		/** @param mixed $value @return string ISO year, month, or date. */
	public function sanitize_schema_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/', $value ) ? $value : '';
	}

		/** @param mixed $value @return string */
	public function sanitize_template( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_replace( '/[^\p{L}\p{N}\s%_\-–—|.,:()]+/u', '', $value );
	}

		/** @param mixed $value @return int */
	public function sanitize_max_urls( $value ) {
		return min( 1000, max( 1, absint( $value ) ) );
	}

	/** @param mixed $value @return int */
	public function sanitize_window( $value ) {
		$value = absint( $value );
		return min( 168, max( 1, $value ) );
	}
	public function sanitize_image_width( $value ) {
		return min( 4000, max( 640, absint( $value ) ) ); }
	public function sanitize_image_quality( $value ) {
		return min( 95, max( 50, absint( $value ) ) ); }
	public function sanitize_image_format( $value ) {
		return in_array( sanitize_key( $value ), array( 'webp', 'jpeg', 'preserve' ), true ) ? sanitize_key( $value ) : 'webp'; }

		/** @param mixed $value @return string */
	public function sanitize_public_url( $value ) {
		$url = \PublishRankSEO\Core\PublicationProfile::normalize_url( $value );
		return $url ? $url : '';
	}

	/** @param mixed $value @return string */
	public function sanitize_identity_type( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'organization', 'person' ), true ) ? $value : 'organization';
	}

	/** Accept a social handle or a full public URL. @param mixed $value @return string */
	public function sanitize_social_profile( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return ''; }
		if ( preg_match( '#^https?://#i', $value ) ) {
			$url = \PublishRankSEO\Core\PublicationProfile::normalize_url( $value );
			return $url ? $url : '';
		}
		return substr( preg_replace( '/[^\p{L}\p{N}._\-\/]/u', '', ltrim( $value, '@' ) ), 0, 100 );
	}

	/** Custom profiles: one URL per line, deduped. @param mixed $value @return string */
	public function sanitize_social_custom( $value ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
			$url = \PublishRankSEO\Core\PublicationProfile::normalize_url( trim( $line ) );
			if ( $url ) {
				$lines[] = $url; }
		}
		return implode( "\n", array_values( array_unique( $lines ) ) );
	}

		/** @param mixed $value @return string */
	public function sanitize_media_post_types( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || 'all' === strtolower( $value ) ) {
			return 'all'; }
		return $this->sanitize_post_types( $value );
	}

	public function sanitize_post_types( $value ) {
		$valid = array();
		foreach ( preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) as $type ) {
			$type   = sanitize_key( $type );
			$object = get_post_type_object( $type );
			if ( $object && $object->public && $object->publicly_queryable && 'attachment' !== $type ) {
				$valid[] = $type; }
		}
		return implode( ',', array_values( array_unique( $valid ? $valid : array( 'post' ) ) ) );
	}
	public function sanitize_verification( $value ) {
		return \PublishRankSEO\SEO\SiteConnections::normalize_token( $value ); }
	public function sanitize_sitemap_endpoint( $value ) {
		return \PublishRankSEO\Sitemap\SitemapUrls::ENDPOINT; }
	public function sanitize_sitemap_aliases( $value ) {
		return ''; }

		/** Render the settings form. */
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$val = static function ( $key, $default = '' ) {
			return get_option( $key, $default );
		};
		?>
			<div class="wrap publishrank-settings-page">
		<?php
		\PublishRankSEO\Admin\AdminAssets::header( __( 'Settings', 'publishrank' ), __( 'Output, indexing, News SEO, sitemaps and integrations. One-time publisher setup lives in the Setup Wizard.', 'publishrank' ) );
		settings_errors();
		?>
			<p><?php esc_html_e( 'Configure PublishRank for this WordPress site. Every site in a multisite network keeps its own publication identity and infrastructure settings.', 'publishrank' ); ?></p>
				<form method="post" action="options.php">
			<?php settings_fields( 'publishrank_seo' ); ?>
					<div class="publishrank-settings-actions publishrank-settings-actions--top"><?php submit_button( __( 'Save PublishRank settings', 'publishrank' ), 'primary', 'submit', false ); ?></div>
				<div class="rhs-setup-summary" style="margin:14px 0 22px;padding:16px 18px;border:1px solid var(--pr-border);border-left:4px solid var(--pr-primary);border-radius:12px;background:#f8f8ff;display:flex;gap:16px;align-items:center;flex-wrap:wrap">
					<div style="flex:1;min-width:240px"><strong><?php esc_html_e( 'One-time setup lives in the Setup Wizard', 'publishrank' ); ?></strong><p style="margin:4px 0 0"><?php esc_html_e( 'Publisher identity, organization or person details, social profiles, site verification, language and country, and homepage metadata are configured once in the wizard. This page keeps the ongoing controls.', 'publishrank' ); ?></p></div>
					<a class="button button-primary" href="<?php echo esc_url( \PublishRankSEO\Core\Branding::page_url( \PublishRankSEO\Admin\OnboardingPage::SLUG ) . '&edit=1&step=2' ); ?>"><?php esc_html_e( 'Edit in Setup Wizard', 'publishrank' ); ?></a>
			</div>
			<nav class="pr-settings-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'publishrank' ); ?>"><a href="#pr-settings-output"><?php esc_html_e( 'Output & indexing', 'publishrank' ); ?></a><a href="#pr-settings-international"><?php esc_html_e( 'International SEO', 'publishrank' ); ?></a><a href="#pr-settings-breadcrumbs"><?php esc_html_e( 'Breadcrumbs', 'publishrank' ); ?></a><a href="#pr-settings-infrastructure"><?php esc_html_e( 'Infrastructure', 'publishrank' ); ?></a><a href="#pr-settings-news"><?php esc_html_e( 'News & sitemaps', 'publishrank' ); ?></a><a href="#pr-settings-portability"><?php esc_html_e( 'Portability', 'publishrank' ); ?></a></nav>
			<h2 class="pr-settings-section" id="pr-settings-identity"><?php esc_html_e( 'Publisher identity variants', 'publishrank' ); ?></h2>
			<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="publishrank_identity_alternate_names"><?php esc_html_e( 'Organization name variants', 'publishrank' ); ?></label></th><td><textarea id="publishrank_identity_alternate_names" name="publishrank_identity_alternate_names" class="large-text" rows="4" placeholder="One variant per line"><?php echo esc_textarea( $val( 'publishrank_identity_alternate_names', '' ) ); ?></textarea><p class="description"><?php esc_html_e( 'Optional. Add recognized names, abbreviations, or spelling variants. Each line becomes an Organization alternateName value.', 'publishrank' ); ?></p></td></tr></tbody></table>
		<h2 class="pr-settings-section" id="pr-settings-output"><?php esc_html_e( 'Output and indexing', 'publishrank' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'External link policy', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_links_enabled" value="0" /><label><input name="publishrank_links_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_links_enabled', true ) ); ?> /> <?php esc_html_e( 'Automatically add nofollow to external links', 'publishrank' ); ?></label><br /><label for="publishrank_links_follow_domains"><?php esc_html_e( 'Approved external domains (follow)', 'publishrank' ); ?></label><textarea id="publishrank_links_follow_domains" name="publishrank_links_follow_domains" class="large-text" rows="2" placeholder="example.org, partner.example"><?php echo esc_textarea( $val( 'publishrank_links_follow_domains', '' ) ); ?></textarea><p class="description"><?php esc_html_e( 'One domain per line or comma-separated. Your main domain and all subdomains remain index/follow.', 'publishrank' ); ?></p><input type="hidden" name="publishrank_links_sponsored" value="0" /><label><input name="publishrank_links_sponsored" type="checkbox" value="1" <?php checked( $val( 'publishrank_links_sponsored', false ) ); ?> /> <?php esc_html_e( 'Mark external links as sponsored', 'publishrank' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Output controls', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_meta_enabled" value="0" /><label><input name="publishrank_meta_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_meta_enabled', true ) ); ?> /> <?php esc_html_e( 'Output PublishRank title, canonical, robots, Open Graph and X/Twitter metadata', 'publishrank' ); ?></label><br /><input type="hidden" name="publishrank_schema_enabled" value="0" /><label><input name="publishrank_schema_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_schema_enabled', true ) ); ?> /> <?php esc_html_e( 'Output the connected JSON-LD schema graph', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'Leave both enabled for the complete technical SEO layer. Disable only if another SEO plugin owns these outputs.', 'publishrank' ); ?></p></td></tr>
						<tr class="publishrank-new-security-feature"><th scope="row"><label for="publishrank_admin_access_mode"><?php esc_html_e( 'Custom login URL', 'publishrank' ); ?></label></th><td><select id="publishrank_admin_access_mode" name="publishrank_admin_access_mode"><option value="custom" <?php selected( $val( 'publishrank_admin_access_mode', 'custom' ), 'custom' ); ?>><?php esc_html_e( 'Enable custom login URL and hide standard login', 'publishrank' ); ?></option><option value="disabled" <?php selected( $val( 'publishrank_admin_access_mode', 'custom' ), 'disabled' ); ?>><?php esc_html_e( 'Disabled — use standard WordPress access URLs', 'publishrank' ); ?></option></select><p><label for="publishrank_admin_access_slug"><strong><?php esc_html_e( 'Private link key', 'publishrank' ); ?></strong></label><br /><code><?php echo esc_html( home_url( '/?publishrank_access=' ) ); ?></code><input id="publishrank_admin_access_slug" name="publishrank_admin_access_slug" type="text" value="<?php echo esc_attr( $val( 'publishrank_admin_access_slug' ) ); ?>" class="regular-text code" placeholder="newsroom-control" pattern="[a-z][a-z0-9_-]{5,63}" /></p><?php $admin_access_url = \PublishRankSEO\Security\AdminAccess::url(); ?>
						<?php
						if ( '' !== $admin_access_url ) :
							?>
							<p><strong><?php esc_html_e( 'Your custom login link:', 'publishrank' ); ?></strong> <a href="<?php echo esc_url( $admin_access_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $admin_access_url ); ?></a></p><?php endif; ?><p class="description"><strong><?php esc_html_e( 'How it works:', 'publishrank' ); ?></strong> <?php esc_html_e( 'Save a private key to use a query-based custom login link. Direct wp-login.php login requests are hidden, while password recovery, registration and logout remain available. Unauthenticated requests to /wp-admin/ are sent to the custom login link; after sign-in, WordPress returns the publisher to the requested administration page. PublishRank does not rename wp-admin or take over a public page slug. If needed, visiting /wp-admin/ while logged out will reveal the currently configured entry link.', 'publishrank' ); ?></p><p class="description"><strong><?php esc_html_e( 'Important:', 'publishrank' ); ?></strong> <?php esc_html_e( 'Hiding the login URL is only an extra obscurity measure, not a substitute for WordPress authentication or protection against vulnerabilities. Use HTTPS, strong unique passwords, multi-factor authentication, current software, backups and suitable server-side protections. Exclude the custom link from full-page caching; plugins or themes that hard-code wp-login.php may need compatibility changes. Disable this setting to restore the standard login route.', 'publishrank' ); ?></p></td></tr>
				<tr><th scope="row"><label for="publishrank_seo_canonical_category_mode"><?php esc_html_e( 'Canonical category path', 'publishrank' ); ?></label></th><td><select id="publishrank_seo_canonical_category_mode" name="publishrank_seo_canonical_category_mode"><option value="permalink" <?php selected( $val( 'publishrank_seo_canonical_category_mode', 'permalink' ), 'permalink' ); ?>><?php esc_html_e( 'Use the normal post permalink', 'publishrank' ); ?></option><option value="primary_category" <?php selected( $val( 'publishrank_seo_canonical_category_mode', 'permalink' ), 'primary_category' ); ?>><?php esc_html_e( 'Use the primary category when supported', 'publishrank' ); ?></option></select><p class="description"><?php /* translators: %category% is a literal WordPress permalink token and should remain unchanged. */ esc_html_e( 'Category mode follows the selected primary category only when WordPress uses a %category% permalink structure; otherwise it safely uses the normal permalink.', 'publishrank' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Archive indexing', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_noindex_author_archives" value="0" /><label><input name="publishrank_noindex_author_archives" type="checkbox" value="1" <?php checked( $val( 'publishrank_noindex_author_archives', false ) ); ?> /> <?php esc_html_e( 'Noindex author archives', 'publishrank' ); ?></label><br /><input type="hidden" name="publishrank_noindex_date_archives" value="0" /><label><input name="publishrank_noindex_date_archives" type="checkbox" value="1" <?php checked( $val( 'publishrank_noindex_date_archives', false ) ); ?> /> <?php esc_html_e( 'Noindex date archives', 'publishrank' ); ?></label></td></tr>
					<tr><th scope="row"><label for="publishrank_seo_default_robots"><?php esc_html_e( 'Default robots directives', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_default_robots" name="publishrank_seo_default_robots" type="text" class="large-text" value="<?php echo esc_attr( $val( 'publishrank_seo_default_robots', 'index,follow' ) ); ?>" placeholder="index,follow,max-image-preview:large" /><p class="description"><?php esc_html_e( 'Comma-separated defaults for pages without a specific override. Supported directives include index, noindex, follow, nofollow, noarchive, nosnippet, noimageindex, notranslate, max-snippet, max-image-preview and max-video-preview. A post-level robots setting takes precedence. Be careful: setting noindex globally can remove your public pages from search results.', 'publishrank' ); ?></p></td></tr>
					<tr><th scope="row"><label for="publishrank_facebook_page_url"><?php esc_html_e( 'Facebook publisher Page URL', 'publishrank' ); ?></label></th><td><input id="publishrank_facebook_page_url" name="publishrank_facebook_page_url" type="url" class="large-text" value="<?php echo esc_attr( $val( 'publishrank_facebook_page_url' ) ); ?>" placeholder="https://www.facebook.com/your-page/" /><p class="description"><?php esc_html_e( 'Used as the existing article:publisher attribution and fb:pages metadata when configured. This does not guarantee Facebook will display an authorship label.', 'publishrank' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Social share image watermark', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_social_watermark_enabled" value="0" /><label><input name="publishrank_social_watermark_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_social_watermark_enabled', false ) ); ?> /> <?php esc_html_e( 'Use a separate watermarked copy for Open Graph and X/Twitter images', 'publishrank' ); ?></label><p><label for="publishrank_social_watermark_logo"><?php esc_html_e( 'Watermark logo URL', 'publishrank' ); ?></label><br /><input id="publishrank_social_watermark_logo" name="publishrank_social_watermark_logo" type="url" class="large-text" value="<?php echo esc_attr( $val( 'publishrank_social_watermark_logo' ) ); ?>" placeholder="https://example.com/wp-content/uploads/logo.png" /></p><p><label for="publishrank_social_watermark_position"><?php esc_html_e( 'Position', 'publishrank' ); ?></label> <select id="publishrank_social_watermark_position" name="publishrank_social_watermark_position">
					<?php
					foreach ( array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' ) as $position ) :
						?>
						<option value="<?php echo esc_attr( $position ); ?>" <?php selected( $val( 'publishrank_social_watermark_position', 'bottom-right' ), $position ); ?>><?php echo esc_html( ucwords( str_replace( '-', ' ', $position ) ) ); ?></option><?php endforeach; ?></select> <label for="publishrank_social_watermark_opacity"><?php esc_html_e( 'Opacity %', 'publishrank' ); ?></label> <input id="publishrank_social_watermark_opacity" name="publishrank_social_watermark_opacity" type="number" min="10" max="100" value="<?php echo esc_attr( $val( 'publishrank_social_watermark_opacity', 75 ) ); ?>" class="small-text" /> <label for="publishrank_social_watermark_size"><?php esc_html_e( 'Size %', 'publishrank' ); ?></label> <input id="publishrank_social_watermark_size" name="publishrank_social_watermark_size" type="number" min="5" max="50" value="<?php echo esc_attr( $val( 'publishrank_social_watermark_size', 18 ) ); ?>" class="small-text" /> <label for="publishrank_social_watermark_margin"><?php esc_html_e( 'Margin px', 'publishrank' ); ?></label> <input id="publishrank_social_watermark_margin" name="publishrank_social_watermark_margin" type="number" min="0" max="300" value="<?php echo esc_attr( $val( 'publishrank_social_watermark_margin', 24 ) ); ?>" class="small-text" /></p><p class="description"><?php esc_html_e( 'The original media file is never changed. PublishRank creates and caches a separate copy only for social metadata; if GD is unavailable or processing fails, the original image is used.', 'publishrank' ); ?></p></td></tr>
				</tbody></table>
		<h2 class="pr-settings-section" id="pr-settings-international"><?php esc_html_e( 'Multilingual and international SEO', 'publishrank' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Multilingual and international SEO', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_multilingual_enabled" value="0" /><label><input name="publishrank_seo_multilingual_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_multilingual_enabled', true ) ); ?> /> <?php esc_html_e( 'Enable hreflang and regional metadata', 'publishrank' ); ?></label></td></tr>
				<tr><th scope="row"><label for="publishrank_seo_hreflang_map"><?php esc_html_e( 'Language / regional URL map', 'publishrank' ); ?></label></th><td><textarea id="publishrank_seo_hreflang_map" name="publishrank_seo_hreflang_map" rows="5" class="large-text code" placeholder="en-US=https://example.com/
fr-FR=https://fr.example.com/
ar-SA=https://example.com/ar/"><?php echo esc_textarea( $val( 'publishrank_seo_hreflang_map' ) ); ?></textarea><p class="description"><?php esc_html_e( 'One locale=URL per line. Supports language and regional codes such as en, en-US, fr-FR, ar-SA, and de-DE. WPML and Polylang URLs are detected automatically.', 'publishrank' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Mirror map on every page', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_hreflang_mirror" value="0" /><label><input name="publishrank_seo_hreflang_mirror" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_hreflang_mirror', false ) ); ?> /> <?php esc_html_e( 'Use the map above for every page by repeating the page path under each language URL (only when every language folder has the same URL structure). Otherwise the map applies to the home page, and individual pages use their own list in the editor.', 'publishrank' ); ?></label></td></tr>
				<tr><th scope="row"><label for="publishrank_seo_x_default_url"><?php esc_html_e( 'x-default URL', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_x_default_url" name="publishrank_seo_x_default_url" type="url" value="<?php echo esc_attr( $val( 'publishrank_seo_x_default_url' ) ); ?>" class="large-text" /><p class="description"><?php esc_html_e( 'Optional fallback URL for visitors whose language or region is not mapped.', 'publishrank' ); ?></p></td></tr>
			</tbody></table>
		<h2 class="pr-settings-section" id="pr-settings-attribution"><?php esc_html_e( 'Attribution', 'publishrank' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Version provenance metadata', 'publishrank' ); ?></th><td><strong><?php esc_html_e( 'Always enabled', 'publishrank' ); ?></strong><p class="description"><?php esc_html_e( 'PublishRank adds technical brand, version, and fingerprint markers to public HTML source. These source markers cannot be disabled here and do not add a promotional link or visible footer credit.', 'publishrank' ); ?></p></td></tr>
			</tbody></table>
	
			<h2 class="pr-settings-section" id="pr-settings-breadcrumbs"><?php esc_html_e( 'Breadcrumbs', 'publishrank' ); ?></h2>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e( 'Frontend breadcrumbs', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_breadcrumbs_enabled" value="0" /><label><input name="publishrank_breadcrumbs_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_breadcrumbs_enabled', true ) ); ?> /> <?php esc_html_e( 'Show breadcrumbs (posts, pages and category/tag/taxonomy archives)', 'publishrank' ); ?></label><br /><input type="hidden" name="publishrank_breadcrumb_schema_enabled" value="0" /><label><input name="publishrank_breadcrumb_schema_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_breadcrumb_schema_enabled', true ) ); ?> /> <?php esc_html_e( 'Keep BreadcrumbList structured data and SEO enabled', 'publishrank' ); ?></label><br /><label for="publishrank_breadcrumbs_separator"><?php esc_html_e( 'Separator', 'publishrank' ); ?></label> <input id="publishrank_breadcrumbs_separator" name="publishrank_breadcrumbs_separator" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumbs_separator', '→' ) ); ?>" class="small-text" /></td></tr>
<tr><th scope="row"><?php esc_html_e( 'Breadcrumb placement', 'publishrank' ); ?></th><td><label for="publishrank_breadcrumb_placement"><?php esc_html_e( 'Where to print the visible trail', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_placement" name="publishrank_breadcrumb_placement"><?php foreach ( array( 'auto' => __( 'Automatic (recommended)', 'publishrank' ), 'content' => __( 'Above the post content', 'publishrank' ), 'hook' => __( 'Theme hook (advanced)', 'publishrank' ), 'manual' => __( 'Manual: block, shortcode or template tag', 'publishrank' ), 'schema_only' => __( 'Structured data only (my theme prints its own trail)', 'publishrank' ) ) as $placement_value => $placement_label ) { ?><option value="<?php echo esc_attr( $placement_value ); ?>" <?php selected( $val( 'publishrank_breadcrumb_placement', 'auto' ), $placement_value ); ?>><?php echo esc_html( $placement_label ); ?></option><?php } ?></select><p class="description"><?php esc_html_e( 'Google expects the BreadcrumbList structured data to match a breadcrumb trail that visitors can actually see. Automatic prints PublishRank\'s trail above the post title in block themes and above the content in classic themes, using the same items as the structured data. Choose "Structured data only" only if your theme already prints an identical trail, and turn off the theme\'s own trail if you let PublishRank print it so the page does not show two.', 'publishrank' ); ?></p><label for="publishrank_breadcrumb_hook"><?php esc_html_e( 'Theme hook name', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_hook" name="publishrank_breadcrumb_hook" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_hook', '' ) ); ?>" class="regular-text code" placeholder="astra_primary_content_top" /><p class="description"><?php esc_html_e( 'Only used with "Theme hook". Enter an action hook from your theme, for example astra_primary_content_top, generate_before_content or the hook your theme documents for "before content".', 'publishrank' ); ?></p><label for="publishrank_breadcrumb_home_label"><?php esc_html_e( 'First crumb label', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_home_label" name="publishrank_breadcrumb_home_label" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_home_label', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /><p class="description"><?php esc_html_e( 'Leave blank to use the publication name, or enter "Home".', 'publishrank' ); ?></p><p class="description"><?php esc_html_e( 'Manual placement: add the "PublishRank Breadcrumbs" block, the [publishrank_breadcrumbs] shortcode, or call publishrank_breadcrumbs() in a theme template.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><?php esc_html_e( 'Breadcrumb style', 'publishrank' ); ?></th><td><?php
$pr_bc_seps = array( '→' => 'Arrow →', '›' => 'Single angle ›', '»' => 'Double angle »', '/' => 'Slash /', '|' => 'Pipe |', '·' => 'Middle dot ·', '•' => 'Bullet •', '‣' => 'Triangle bullet ‣', '>' => 'Greater than >', '–' => 'En dash –', '~' => 'Tilde ~', '⟩' => 'Mathematical angle ⟩', '➤' => 'Arrowhead ➤', '' => 'None' );
$pr_bc_sep  = (string) $val( 'publishrank_breadcrumbs_separator', '→' );
?><label for="publishrank_breadcrumb_sep_preset"><?php esc_html_e( 'Separator symbol', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_sep_preset"><?php foreach ( $pr_bc_seps as $sep_value => $sep_label ) { ?><option value="<?php echo esc_attr( $sep_value ); ?>" <?php selected( $pr_bc_sep, (string) $sep_value ); ?>><?php echo esc_html( $sep_label ); ?></option><?php } ?><option value="__custom" <?php selected( isset( $pr_bc_seps[ $pr_bc_sep ] ), false ); ?>><?php esc_html_e( 'Custom (use the separator field above)', 'publishrank' ); ?></option></select>
<script>(function(){var s=document.getElementById('publishrank_breadcrumb_sep_preset'),i=document.getElementById('publishrank_breadcrumbs_separator');if(!s||!i){return;}s.addEventListener('change',function(){if(s.value!=='__custom'){i.value=s.value;}else{i.focus();}});i.addEventListener('input',function(){var m=false;for(var k=0;k<s.options.length;k++){if(s.options[k].value===i.value){s.selectedIndex=k;m=true;break;}}if(!m){s.value='__custom';}});}());</script><br />
<label for="publishrank_breadcrumb_style"><?php esc_html_e( 'Look', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_style" name="publishrank_breadcrumb_style"><?php foreach ( array( 'plain' => __( 'Plain text (inherits theme)', 'publishrank' ), 'chips' => __( 'Chips (rounded pills)', 'publishrank' ), 'bar' => __( 'Bar (soft background strip)', 'publishrank' ), 'underline' => __( 'Underline (thin rule below)', 'publishrank' ) ) as $style_value => $style_label ) { ?><option value="<?php echo esc_attr( $style_value ); ?>" <?php selected( $val( 'publishrank_breadcrumb_style', 'plain' ), $style_value ); ?>><?php echo esc_html( $style_label ); ?></option><?php } ?></select>
<label for="publishrank_breadcrumb_size"><?php esc_html_e( 'Size', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_size" name="publishrank_breadcrumb_size"><?php foreach ( array( 'small' => __( 'Small', 'publishrank' ), 'medium' => __( 'Medium', 'publishrank' ), 'large' => __( 'Large', 'publishrank' ) ) as $size_value => $size_label ) { ?><option value="<?php echo esc_attr( $size_value ); ?>" <?php selected( $val( 'publishrank_breadcrumb_size', 'medium' ), $size_value ); ?>><?php echo esc_html( $size_label ); ?></option><?php } ?></select><br />
<label for="publishrank_breadcrumb_text_color"><?php esc_html_e( 'Text colour', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_text_color" name="publishrank_breadcrumb_text_color" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_text_color', '' ) ); ?>" class="small-text code" placeholder="#444444" />
<label for="publishrank_breadcrumb_link_color"><?php esc_html_e( 'Link colour', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_link_color" name="publishrank_breadcrumb_link_color" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_link_color', '' ) ); ?>" class="small-text code" placeholder="#0a58ca" />
<label for="publishrank_breadcrumb_bg_color"><?php esc_html_e( 'Background colour', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_bg_color" name="publishrank_breadcrumb_bg_color" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_bg_color', '' ) ); ?>" class="small-text code" placeholder="#eef2f7" />
<p class="description"><?php esc_html_e( 'Colours use hex codes such as #0a58ca. Leave them blank to follow your theme. Background applies to the Chips and Bar looks.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><?php esc_html_e( 'Breadcrumb error handling', 'publishrank' ); ?></th><td><label for="publishrank_breadcrumb_error_mode"><?php esc_html_e( 'Invalid item behavior', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_error_mode" name="publishrank_breadcrumb_error_mode"><option value="skip" <?php selected( $val( 'publishrank_breadcrumb_error_mode', 'skip' ), 'skip' ); ?>><?php esc_html_e( 'Skip invalid item', 'publishrank' ); ?></option><option value="fallback" <?php selected( $val( 'publishrank_breadcrumb_error_mode', 'skip' ), 'fallback' ); ?>><?php esc_html_e( 'Use fallback item', 'publishrank' ); ?></option><option value="text" <?php selected( $val( 'publishrank_breadcrumb_error_mode', 'skip' ), 'text' ); ?>><?php esc_html_e( 'Keep text without link', 'publishrank' ); ?></option><option value="disable" <?php selected( $val( 'publishrank_breadcrumb_error_mode', 'skip' ), 'disable' ); ?>><?php esc_html_e( 'Disable breadcrumbs on error', 'publishrank' ); ?></option></select><br /><label for="publishrank_breadcrumb_error_key"><?php esc_html_e( 'Error key', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_error_key" name="publishrank_breadcrumb_error_key" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_error_key', 'breadcrumb_error' ) ); ?>" class="regular-text code" /><br /><label for="publishrank_breadcrumb_fallback_label"><?php esc_html_e( 'Fallback label', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_fallback_label" name="publishrank_breadcrumb_fallback_label" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_fallback_label', 'Home' ) ); ?>" class="regular-text" /><br /><label for="publishrank_breadcrumb_fallback_url"><?php esc_html_e( 'Fallback URL', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_fallback_url" name="publishrank_breadcrumb_fallback_url" type="url" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_fallback_url', home_url( '/' ) ) ); ?>" class="large-text" /><br /><input type="hidden" name="publishrank_breadcrumb_log_errors" value="0" /><label><input name="publishrank_breadcrumb_log_errors" type="checkbox" value="1" <?php checked( $val( 'publishrank_breadcrumb_log_errors', false ) ); ?> /> <?php esc_html_e( 'Record breadcrumb errors for administrators', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'Use these controls when a taxonomy, archive, permalink, or canonical URL cannot be resolved. Error logs stay local to this site.', 'publishrank' ); ?></p></td></tr>
				</tbody></table>
			<h2 class="pr-settings-section" id="pr-settings-infrastructure"><?php esc_html_e( 'Infrastructure domains', 'publishrank' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="publishrank_media_host"><?php esc_html_e( 'Media host', 'publishrank' ); ?></label></th><td><input id="publishrank_media_host" name="publishrank_media_host" type="url" value="<?php echo esc_attr( $val( 'publishrank_media_host' ) ); ?>" class="large-text" placeholder="https://images.example.com" /><p class="description"><?php esc_html_e( 'Optional. Leave empty to keep normal WordPress uploads URLs.', 'publishrank' ); ?></p></td></tr>
				<tr><th scope="row"><label for="publishrank_cdn_host"><?php esc_html_e( 'CDN host', 'publishrank' ); ?></label></th><td><input id="publishrank_cdn_host" name="publishrank_cdn_host" type="url" value="<?php echo esc_attr( $val( 'publishrank_cdn_host' ) ); ?>" class="large-text" /><input type="hidden" name="publishrank_cdn_enabled" value="0" /><label><input id="publishrank_cdn_enabled" name="publishrank_cdn_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_cdn_enabled', false ) ); ?> /> <?php esc_html_e( 'Enable CDN URL generation', 'publishrank' ); ?></label></td></tr>
				<tr><th scope="row"><label for="publishrank_video_host"><?php esc_html_e( 'Video host', 'publishrank' ); ?></label></th><td><input id="publishrank_video_host" name="publishrank_video_host" type="url" value="<?php echo esc_attr( $val( 'publishrank_video_host' ) ); ?>" class="large-text" /></td></tr>
				<tr><th scope="row"><label for="publishrank_api_host"><?php esc_html_e( 'API host', 'publishrank' ); ?></label></th><td><input id="publishrank_api_host" name="publishrank_api_host" type="url" value="<?php echo esc_attr( $val( 'publishrank_api_host' ) ); ?>" class="large-text" /></td></tr>
			</tbody></table>
			<h2 class="pr-settings-section" id="pr-settings-news"><?php esc_html_e( 'News SEO and sitemaps', 'publishrank' ); ?></h2>
<table class="form-table" role="presentation"><tbody>
<tr><th scope="row"><?php esc_html_e( 'News sitemap', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_news_enabled" value="0" /><label><input name="publishrank_seo_news_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_news_enabled', true ) ); ?> /> <?php esc_html_e( 'Enable the News sitemap', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'Only recently published, indexable content is included.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_post_types"><?php esc_html_e( 'News post types', 'publishrank' ); ?></label></th><td><textarea id="publishrank_seo_news_post_types" name="publishrank_seo_news_post_types" rows="2" class="large-text" placeholder="post
news"><?php echo esc_textarea( $val( 'publishrank_seo_news_post_types', 'post' ) ); ?></textarea><p class="description"><?php esc_html_e( 'One public post type per line or comma-separated. Invalid or non-public types are ignored.', 'publishrank' ); ?></p></td></tr>
				<tr><th scope="row"><label for="publishrank_seo_news_window"><?php esc_html_e( 'News freshness window (hours)', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_news_window" name="publishrank_seo_news_window" type="number" min="1" max="168" value="<?php echo esc_attr( $val( 'publishrank_seo_news_window', 48 ) ); ?>" class="small-text" /><p class="description"><?php esc_html_e( 'Default is 48 hours. You can extend this to 72 hours or up to 7 days when your newsroom publishes fewer articles.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_max_urls"><?php esc_html_e( 'Maximum News URLs', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_news_max_urls" name="publishrank_seo_news_max_urls" type="number" min="1" max="1000" value="<?php echo esc_attr( $val( 'publishrank_seo_news_max_urls', 1000 ) ); ?>" class="small-text" /></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_exclude_ids"><?php esc_html_e( 'Exclude post IDs', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_news_exclude_ids" name="publishrank_seo_news_exclude_ids" type="text" value="<?php echo esc_attr( $val( 'publishrank_seo_news_exclude_ids' ) ); ?>" class="large-text" placeholder="12, 45, 89" /></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_exclude_categories"><?php esc_html_e( 'Exclude category IDs', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_news_exclude_categories" name="publishrank_seo_news_exclude_categories" type="text" value="<?php echo esc_attr( $val( 'publishrank_seo_news_exclude_categories' ) ); ?>" class="large-text" placeholder="3, 7" /></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_genre"><?php esc_html_e( 'News genre', 'publishrank' ); ?></label></th><td><select id="publishrank_seo_news_genre" name="publishrank_seo_news_genre"><option value="" <?php selected( $val( 'publishrank_seo_news_genre' ), '' ); ?>><?php esc_html_e( 'Not specified', 'publishrank' ); ?></option>
		<?php
		foreach ( array( 'Opinion', 'PressRelease', 'Satire', 'UserGenerated', 'Report', 'Review', 'Interview', 'Background', 'Transcript', 'Obituary', 'Profile' ) as $genre ) :
			?>
	<option value="<?php echo esc_attr( $genre ); ?>" <?php selected( $val( 'publishrank_seo_news_genre' ), $genre ); ?>><?php echo esc_html( $genre ); ?></option><?php endforeach; ?></select></td></tr>
<tr><th scope="row"><label for="publishrank_editorial_genre"><?php esc_html_e( 'Publisher editorial genre', 'publishrank' ); ?></label></th><td><select id="publishrank_editorial_genre" name="publishrank_editorial_genre"><option value="" <?php selected( $val( 'publishrank_editorial_genre' ), '' ); ?>><?php esc_html_e( 'Not specified', 'publishrank' ); ?></option>
		<?php
		foreach ( array( 'News', 'Breaking News', 'Live Updates', 'Developing Story', 'Local News', 'National News', 'International News', 'Regional News', 'World News', 'Report', 'Investigative', 'In-Depth', 'Exclusive', 'Special Report', 'Field Report', 'Eyewitness Report', 'Data Journalism', 'Fact Check', 'Verification', 'News Analysis', 'Analysis', 'Explainer', 'Background', 'Context', 'Briefing', 'News Brief', 'Roundup', 'Timeline', 'Opinion', 'Editorial', 'Column', 'Commentary', 'Op-Ed', 'Letter to the Editor', 'Guest Essay', 'Perspective', 'Interview', 'Profile', 'Q&A', 'Portrait', 'Biography', 'Obituary', 'Tribute', 'Live Blog', 'Event Coverage', 'Election Coverage', 'Breaking Coverage', 'Conference Coverage', 'Sports Coverage', 'Review', 'Criticism', 'Preview', 'Guide', 'How-To', 'FAQ', 'Consumer Advice', 'Service Journalism', 'Travel', 'Food', 'Technology', 'Science', 'Health', 'Business', 'Finance', 'Politics', 'Sports', 'Entertainment', 'Culture', 'Press Release', 'Statement', 'Transcript', 'Speech', 'Announcement', 'Official Statement', 'Court Filing', 'Document', 'Public Records', 'Research', 'Study', 'Survey', 'Feature', 'Longform', 'Magazine', 'Essay', 'Human Interest', 'Special Feature', 'Photo Essay', 'Multimedia', 'Documentary', 'Satire', 'User Generated', 'Blog', 'Archive', 'Correction', 'Update', 'Retraction', 'Clarification', 'Fact Correction' ) as $genre ) :
			?>
	<option value="<?php echo esc_attr( $genre ); ?>" <?php selected( $val( 'publishrank_editorial_genre' ), $genre ); ?>><?php echo esc_html( $genre ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Editorial classification for newsroom workflows and publisher taxonomy. This is separate from the Google News genre field above and is not written to the News Sitemap as a Google genre value.', 'publishrank' ); ?></p></td></tr>
	<tr><th scope="row"><?php esc_html_e( 'News keywords', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_news_keywords" value="0" /><label><input name="publishrank_seo_news_keywords" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_news_keywords', true ) ); ?> /> <?php esc_html_e( 'Include tags, categories, and focus keyword as news keywords', 'publishrank' ); ?></label></td></tr>
	<tr><th scope="row"><label for="publishrank_sitemap_post_types"><?php esc_html_e( 'Standard sitemap post types', 'publishrank' ); ?></label></th><td><input id="publishrank_sitemap_post_types" name="publishrank_sitemap_post_types" type="text" value="<?php echo esc_attr( $val( 'publishrank_sitemap_post_types', 'post' ) ); ?>" class="large-text" /><p class="description"><?php esc_html_e( 'Public, queryable types included in the standard post sitemap.', 'publishrank' ); ?></p></td></tr>
	<tr><th scope="row"><?php esc_html_e( 'Additional sitemap providers', 'publishrank' ); ?></th><td>
		<?php
		foreach ( array(
			'Pages'             => 'publishrank_sitemap_pages_enabled',
			'Custom post types' => 'publishrank_sitemap_cpt_enabled',
			'Taxonomies'        => 'publishrank_sitemap_taxonomies_enabled',
			'Authors'           => 'publishrank_sitemap_authors_enabled',
			'Attachments'       => 'publishrank_sitemap_attachments_enabled',
		) as $label => $field ) :
			?>
																		<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="0" /><label><input name="<?php echo esc_attr( $field ); ?>" type="checkbox" value="1" <?php checked( $val( $field, 'Attachments' !== $label ) ); ?> /> <?php echo esc_html( $label ); ?></label><br /><?php endforeach; ?></td></tr>
	<tr><th scope="row"><?php esc_html_e( 'Sitemap appearance', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_sitemap_stylesheet" value="0" /><label><input name="publishrank_sitemap_stylesheet" type="checkbox" value="1" <?php checked( $val( 'publishrank_sitemap_stylesheet', true ) ); ?> /> <?php esc_html_e( 'Show sitemaps as a readable page in browsers (search engines still read the plain XML)', 'publishrank' ); ?></label></td></tr>
	<tr><th scope="row"><?php esc_html_e( 'Sitemap URL', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_sitemap_endpoint" value="publishrank-sitemap" /><input type="hidden" name="publishrank_sitemap_aliases" value="" /><code><?php echo esc_html( \PublishRankSEO\Sitemap\SitemapUrls::index_url() ); ?></code> <span class="rhs-pill rhs-pill--pass"><?php esc_html_e( 'Managed by PublishRank', 'publishrank' ); ?></span><div class="notice notice-info inline" style="margin:10px 0 0"><p><strong><?php esc_html_e( 'Why this address is locked', 'publishrank' ); ?></strong><br /><?php esc_html_e( 'Thank you for understanding: we no longer allow the sitemap address to be edited. A custom address can clash with the built-in WordPress sitemap (wp-sitemap.xml) or with another plugin, which leads to missing or duplicate sitemaps and 404 errors for search engines. Keeping one fixed address means the sitemap you submit to Google Search Console and Bing always works. WordPress\' built-in sitemap is switched off while PublishRank is active.', 'publishrank' ); ?></p></div></td></tr>

				<tr><th scope="row"><?php esc_html_e( 'Media sitemaps', 'publishrank' ); ?></th><td><label for="publishrank_seo_media_post_types"><?php esc_html_e( 'Content types scanned for images and videos', 'publishrank' ); ?></label><br /><input id="publishrank_seo_media_post_types" name="publishrank_seo_media_post_types" type="text" value="<?php echo esc_attr( $val( 'publishrank_seo_media_post_types', 'all' ) ); ?>" class="large-text" /><p class="description"><?php esc_html_e( 'Use all to scan every public, queryable post type, or enter comma-separated type names such as post,page,news. Only URLs with image or video data are included in their respective sitemap.', 'publishrank' ); ?></p><input type="hidden" name="publishrank_seo_image_sitemap" value="0" /><label><input name="publishrank_seo_image_sitemap" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_image_sitemap', true ) ); ?> /> <?php esc_html_e( 'Image sitemap', 'publishrank' ); ?></label><br /><input type="hidden" name="publishrank_seo_video_sitemap" value="0" /><label><input name="publishrank_seo_video_sitemap" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_video_sitemap', true ) ); ?> /> <?php esc_html_e( 'Video sitemap', 'publishrank' ); ?></label><br /><label for="publishrank_image_license_url"><?php esc_html_e( 'Image license URL', 'publishrank' ); ?></label><br /><input id="publishrank_image_license_url" name="publishrank_image_license_url" type="url" value="<?php echo esc_attr( $val( 'publishrank_image_license_url' ) ); ?>" class="large-text" /></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Image optimization policy', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_image_optimizer_enabled" value="0" /><label><input name="publishrank_image_optimizer_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_image_optimizer_enabled', true ) ); ?> /> <?php esc_html_e( 'Enable image quality recommendations', 'publishrank' ); ?></label><br /><label><?php esc_html_e( 'Maximum width', 'publishrank' ); ?> <input name="publishrank_image_max_width" type="number" min="640" max="4000" value="<?php echo esc_attr( $val( 'publishrank_image_max_width', 2000 ) ); ?>" class="small-text" /></label> <label><?php esc_html_e( 'Quality', 'publishrank' ); ?> <input name="publishrank_image_quality" type="number" min="50" max="95" value="<?php echo esc_attr( $val( 'publishrank_image_quality', 82 ) ); ?>" class="small-text" /></label> <label><?php esc_html_e( 'Format', 'publishrank' ); ?> <select name="publishrank_image_format"><option value="webp" <?php selected( $val( 'publishrank_image_format', 'webp' ), 'webp' ); ?>>WebP</option><option value="jpeg" <?php selected( $val( 'publishrank_image_format', 'webp' ), 'jpeg' ); ?>>JPEG</option><option value="preserve" <?php selected( $val( 'publishrank_image_format', 'webp' ), 'preserve' ); ?>><?php esc_html_e( 'Preserve', 'publishrank' ); ?></option></select></label> <a class="button" href="<?php echo esc_url( \PublishRankSEO\Core\Branding::page_url( 'publishrank-image-optimizer' ) ); ?>"><?php esc_html_e( 'Review media', 'publishrank' ); ?></a></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Monitoring', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_404_monitor" value="0" /><label><input name="publishrank_seo_404_monitor" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_404_monitor', false ) ); ?> /> <?php esc_html_e( 'Record a capped rolling 404 log', 'publishrank' ); ?></label></td></tr>
			</tbody></table>
			<div class="publishrank-settings-actions publishrank-settings-actions--bottom"><?php submit_button( __( 'Save PublishRank settings', 'publishrank' ), 'primary', 'submit', false ); ?></div>
		</form>
		<hr />
		<h2 class="pr-settings-section" id="pr-settings-portability"><?php esc_html_e( 'Settings portability', 'publishrank' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="publishrank_seo_export" /><?php wp_nonce_field( 'publishrank_seo_export' ); ?><?php submit_button( __( 'Export settings', 'publishrank' ), 'secondary', 'submit', false ); ?></form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="publishrank_seo_import" /><?php wp_nonce_field( 'publishrank_seo_import' ); ?><label for="publishrank_seo_json"><span class="screen-reader-text"><?php esc_html_e( 'PublishRank JSON settings', 'publishrank' ); ?></span></label><textarea id="publishrank_seo_json" name="publishrank_seo_json" rows="8" class="large-text code" required></textarea><?php submit_button( __( 'Import settings', 'publishrank' ), 'secondary' ); ?></form>
		</div>
		<?php
	}
}
