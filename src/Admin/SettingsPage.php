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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** Register the settings page. */
	public function menu() {
		add_submenu_page( \PublishRankSEO\Core\Branding::MENU_SLUG, __( 'PublishRank Settings', 'publishrank' ), __( 'Settings', 'publishrank' ), 'manage_options', 'publishrank', array( $this, 'page' ) );
	}

	/** Load the existing variable picker for the global title-template fields. */
	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'publishrank' === $page ) {
			VariablePicker::enqueue( '' );
			wp_enqueue_style( 'publishrank-settings-ui', \PublishRankSEO\Core\Branding::asset_url( 'css/settings.css' ), array(), AdminShell::ver( 'css/settings.css' ) );
			wp_enqueue_script( 'publishrank-settings-ui', \PublishRankSEO\Core\Branding::asset_url( 'js/settings.js' ), array(), AdminShell::ver( 'js/settings.js' ), true );
			wp_localize_script(
				'publishrank-settings-ui',
				'publishrankSettingsUi',
				array(
					'dirty'     => __( 'You have unsaved changes', 'publishrank' ),
					'clean'     => __( 'All changes saved', 'publishrank' ),
					'saving'    => __( 'Saving…', 'publishrank' ),
					/* translators: %d: number of matching settings. */
					'matches'   => __( '%d matching settings', 'publishrank' ),
					'noResults' => __( 'No settings match your search.', 'publishrank' ),
				)
			);
		}
	}

	/**
	 * Register an option. Options that moved to the Setup Wizard, or that have no field in this form, go in an
	 * unmanaged group: WordPress's options.php blanks every option in the submitted group that is missing from the POST.
	 */
	private function reg( $option, $args ) {
		static $unmanaged = null;
		if ( null === $unmanaged ) {
			$unmanaged = array_merge( \PublishRankSEO\Admin\OnboardingPage::setup_keys(), array( 'publishrank_indexnow_enabled', 'publishrank_indexnow_key', 'publishrank_indexnow_post_types', 'publishrank_indexnow_key_location', 'publishrank_author_mode', 'publishrank_schema_legal_name' ) );
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
			'whl_page',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_login_slug' ),
				'default'           => 'login',
			)
		);
		$this->reg(
			'whl_redirect_admin',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_redirect_slug' ),
				'default'           => '404',
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
			'publishrank_seo_news_include_modified',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		$this->reg(
			'publishrank_seo_news_modified_window',
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
		foreach ( array( 'publishrank_meta_enabled', 'publishrank_schema_enabled', 'publishrank_org_website_schema_enabled' ) as $field ) {
			$this->reg(
				$field,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => true,
				)
				); }
					$this->reg(
						\PublishRankSEO\Core\OutputSettings::EXTERNAL_SEO_HANDOFF,
						array(
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
							'default'           => false,
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
		$this->reg(
			'publishrank_seo_description_fallback',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_description_fallback' ),
				'default'           => 'page_type',
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
					'sanitize_callback' => array( $this, 'sanitize_home_text' ),
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
			'publishrank_breadcrumb_align',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_breadcrumb_align' ),
				'default'           => 'left',
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
				// Not sanitize_text_field(): that trims the spaces of spaced separators and cannot represent "none" or a non-breaking space.
				'sanitize_callback' => array( \PublishRankSEO\SEO\BreadcrumbSeparators::class, 'sanitize_setting' ),
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
			foreach ( array( 'publishrank_hide_visible_site_title', 'publishrank_hide_visible_site_description', 'publishrank_override_wp_identity' ) as $field ) {
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
		// News sitemap category exclusions: accepts the legacy comma-separated text or the category picker array; always stored as "3, 7".
		$this->reg(
			'publishrank_seo_news_exclude_categories',
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ) {
					$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
					$ids   = array();
					foreach ( (array) $items as $item ) {
						if ( is_scalar( $item ) && absint( $item ) ) {
							$ids[] = absint( $item ); }
					}
					return implode( ', ', array_values( array_unique( $ids ) ) );
				},
				'default'           => '',
			)
		);
		$this->reg(
			'publishrank_seo_news_stock_tickers',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
		foreach ( array( 'publishrank_seo_publication_name', 'publishrank_indexnow_key', 'publishrank_indexnow_post_types', 'publishrank_seo_hreflang_map', 'publishrank_seo_news_post_types', 'publishrank_seo_news_exclude_ids', 'publishrank_seo_news_genre', 'publishrank_editorial_genre', 'publishrank_seo_publisher_name', 'publishrank_publication_short_name', 'publishrank_default_language', 'publishrank_default_locale', 'publishrank_timezone', 'publishrank_country', 'publishrank_copyright', 'publishrank_contact', 'publishrank_social_profiles', 'publishrank_author_mode', 'publishrank_schema_org_description', 'publishrank_schema_legal_name', 'publishrank_schema_founder', 'publishrank_schema_phone', 'publishrank_schema_street_address', 'publishrank_schema_address_locality', 'publishrank_schema_address_region', 'publishrank_schema_postal_code', 'publishrank_schema_knows_about', 'publishrank_schema_parent_organization', 'publishrank_schema_founding_location' ) as $field ) {
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
	public function sanitize_breadcrumb_style( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'plain', 'chips', 'bar', 'underline', 'arrows', 'minimal', 'steps', 'pills' ), true ) ? $value : 'plain';
	}

	/** @param mixed $value @return string */
	public function sanitize_breadcrumb_align( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'left', 'center', 'right' ), true ) ? $value : 'left';
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
	/** @param mixed $value @return string page_type|global */
	public function sanitize_description_fallback( $value ) {
		return 'global' === sanitize_key( (string) $value ) ? 'global' : 'page_type';
	}

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
		// Keeps %%variables%% intact and, unlike the previous character allow-list, keeps symbols such as › » · & ' ! ?.
		// Markup is removed here and the resolved text is escaped at the output boundary.
		return \PublishRankSEO\Meta\TemplateResolver::sanitize_template( $value, 300 );
	}

	/** @param mixed $value @return string Homepage SEO title/description: plain text that may contain %%variables%%. */
	public function sanitize_home_text( $value ) {
		return \PublishRankSEO\Meta\TemplateResolver::sanitize_template( $value, 320 );
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
	/** Private login address: reserved or empty values keep the address already in use. @param mixed $value @return string */
	public function sanitize_login_slug( $value ) {
		$clean = \PublishRankSEO\Security\WPHideLogin::clean_login_slug( is_string( $value ) ? $value : '' );
		if ( '' === $clean ) {
			add_settings_error( 'whl_page', 'publishrank_login_slug', __( 'The login address was not changed because it is empty or reserved by WordPress.', 'publishrank' ), 'error' );
			return \PublishRankSEO\Security\WPHideLogin::current_login_slug();
		}
		return $clean;
	}

	/** Where blocked visitors are sent; empty means the 404 page. @param mixed $value @return string */
	public function sanitize_redirect_slug( $value ) {
		return \PublishRankSEO\Security\WPHideLogin::clean_redirect_slug( is_string( $value ) ? $value : '' );
	}
	public function sanitize_sitemap_endpoint( $value ) {
		return \PublishRankSEO\Sitemap\SitemapUrls::ENDPOINT; }
	public function sanitize_sitemap_aliases( $value ) {
		return ''; }

	/** Settings sections in display order: icon, title and one-line summary. */
	private static function sections() {
		return array(
			'titles'         => array( 'fa-heading', __( 'Titles & Meta', 'publishrank' ), __( 'How page titles are built in search results.', 'publishrank' ) ),
			'identity'       => array( 'fa-id-card', __( 'Publisher identity variants', 'publishrank' ), __( 'Other names your publication is known by.', 'publishrank' ) ),
			'output'         => array( 'fa-sliders', __( 'Output and indexing', 'publishrank' ), __( 'What PublishRank prints on your pages and what search engines may index.', 'publishrank' ) ),
			'international'  => array( 'fa-globe', __( 'Multilingual and international SEO', 'publishrank' ), __( 'Language and regional versions of your site.', 'publishrank' ) ),
			'attribution'    => array( 'fa-fingerprint', __( 'Attribution', 'publishrank' ), __( 'Technical markers in your page source.', 'publishrank' ) ),
			'security'       => array( 'fa-user-shield', __( 'Hide Login', 'publishrank' ), __( 'Your private sign-in address and where blocked visitors are sent.', 'publishrank' ) ),
			'breadcrumbs'    => array( 'fa-signs-post', __( 'Breadcrumbs', 'publishrank' ), __( 'The breadcrumb trail readers see and its structured data.', 'publishrank' ) ),
			'infrastructure' => array( 'fa-server', __( 'Infrastructure domains', 'publishrank' ), __( 'Optional media, CDN, video and API hosts.', 'publishrank' ) ),
			'news'           => array( 'fa-newspaper', __( 'News SEO and sitemaps', 'publishrank' ), __( 'Google News, XML sitemap content, media sitemaps and monitoring.', 'publishrank' ) ),
			'portability'    => array( 'fa-file-export', __( 'Settings portability', 'publishrank' ), __( 'Copy these settings to another site, or restore a backup.', 'publishrank' ) ),
		);
	}

	/** Short labels for the section chips. */
	private static function chip_labels() {
		return array(
			'titles'         => __( 'Titles & Meta', 'publishrank' ),
			'identity'       => __( 'Identity', 'publishrank' ),
			'output'         => __( 'Output & indexing', 'publishrank' ),
			'international'  => __( 'International SEO', 'publishrank' ),
			'attribution'    => __( 'Attribution', 'publishrank' ),
			'security'       => __( 'Hide Login', 'publishrank' ),
			'breadcrumbs'    => __( 'Breadcrumbs', 'publishrank' ),
			'infrastructure' => __( 'Infrastructure', 'publishrank' ),
			'news'           => __( 'News & sitemaps', 'publishrank' ),
			'portability'    => __( 'Portability', 'publishrank' ),
		);
	}

	/** @var bool Whether a section card is open. */
	private static $section_open = false;

	/** Close the previous section card (if any) and open the next one. */
	private static function section_open( $key, $extra = '' ) {
		self::section_close();
		$all = self::sections();
		list( $icon, $title, $summary ) = $all[ $key ];
		echo '<section class="pr-set-card" id="pr-settings-' . esc_attr( $key ) . '" data-pr-set-section aria-labelledby="pr-settings-' . esc_attr( $key ) . '-title">';
		echo '<header class="pr-set-card__head"><span class="pr-set-card__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $icon ) . '"></i></span>';
		echo '<div class="pr-set-card__heading"><h2 class="pr-set-card__title" id="pr-settings-' . esc_attr( $key ) . '-title">' . esc_html( $title ) . '</h2><p class="pr-set-card__summary">' . esc_html( $summary ) . '</p></div>';
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the caller.
		echo '</header><div class="pr-set-card__body">';
		self::$section_open = true;
	}

	/** Close the open section card. */
	private static function section_close() {
		if ( self::$section_open ) {
			echo '</div></section>';
			self::$section_open = false;
		}
	}

		/** Render the settings form. */
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$val = static function ( $key, $default = '' ) {
			return get_option( $key, $default );
		};
		$title_separator   = (string) $val( 'publishrank_seo_title_separator', '|' );
		$separator_options = OnboardingPage::title_separator_options();
		if ( ! array_key_exists( $title_separator, $separator_options ) ) {
			/* translators: %s: the currently saved title separator. */
			$separator_options = array( $title_separator => sprintf( __( 'Current value: %s', 'publishrank' ), $title_separator ) ) + $separator_options;
		}
		$title_templates = array(
			'home'    => array( 'label' => __( 'Homepage title', 'publishrank' ), 'default' => '%%sitename%% %%page%%', 'context' => 'home' ),
			'post'    => array( 'label' => __( 'Post title', 'publishrank' ), 'default' => '%%title%% %%sep%% %%sitename%% %%page%%', 'context' => 'post' ),
			'page'    => array( 'label' => __( 'Page title', 'publishrank' ), 'default' => '%%title%% %%sep%% %%sitename%%', 'context' => 'post' ),
			'archive' => array( 'label' => __( 'Archive title', 'publishrank' ), 'default' => '%%title%% %%sep%% %%sitename%%', 'context' => 'archive' ),
		);
		?>
			<div class="wrap publishrank-settings-page pr-modules-wrap pr-set-wrap">
		<?php
		\PublishRankSEO\Admin\AdminAssets::header( __( 'Settings', 'publishrank' ), __( 'Output, indexing, News SEO, sitemaps and integrations. One-time publisher setup lives in the Setup Wizard.', 'publishrank' ) );
		settings_errors();
		?>
			<div class="pr-modules-layout pr-set-layout">
			<?php AdminShell::nav( 'settings' ); ?>
			<div class="pr-modules-main pr-set-main">
			<div class="pr-modules-intro pr-set-intro">
				<div>
					<p class="pr-shell-kicker"><?php esc_html_e( 'Control center', 'publishrank' ); ?></p>
					<h2 class="pr-modules-title"><?php esc_html_e( 'General Settings', 'publishrank' ); ?></h2>
					<p class="pr-modules-lead"><?php esc_html_e( 'Configure PublishRank for this WordPress site. Every site in a multisite network keeps its own publication identity and infrastructure settings.', 'publishrank' ); ?></p>
				</div>
				<a class="pr-set-wizard-link" href="<?php echo esc_url( \PublishRankSEO\Core\Branding::page_url( \PublishRankSEO\Admin\OnboardingPage::SLUG ) . '&edit=1&step=2' ); ?>"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> <?php esc_html_e( 'Edit in Setup Wizard', 'publishrank' ); ?></a>
			</div>
			<div class="pr-set-callout">
				<span class="pr-set-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-info"></i></span>
				<div><strong><?php esc_html_e( 'One-time setup lives in the Setup Wizard', 'publishrank' ); ?></strong><p><?php esc_html_e( 'Publisher identity, organization or person details, social profiles, site verification, language and country, and homepage metadata are configured once in the wizard. This page keeps the ongoing controls.', 'publishrank' ); ?></p></div>
			</div>
			<div class="pr-set-toolbar" data-pr-set-toolbar>
				<nav class="pr-set-chips" aria-label="<?php esc_attr_e( 'Settings sections', 'publishrank' ); ?>">
				<?php foreach ( self::chip_labels() as $chip_key => $chip_label ) : ?>
					<a href="#pr-settings-<?php echo esc_attr( $chip_key ); ?>" data-pr-set-chip="pr-settings-<?php echo esc_attr( $chip_key ); ?>"><?php echo esc_html( $chip_label ); ?></a>
				<?php endforeach; ?>
				</nav>
				<label class="screen-reader-text" for="pr-set-search"><?php esc_html_e( 'Find a setting', 'publishrank' ); ?></label>
				<span class="pr-modules-search pr-set-search hide-if-no-js"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="pr-set-search" placeholder="<?php esc_attr_e( 'Find a setting…', 'publishrank' ); ?>" autocomplete="off" data-pr-set-search /></span>
			</div>
			<p class="pr-set-search-status" data-pr-set-search-status aria-live="polite" hidden></p>
				<form method="post" action="options.php" class="pr-set-form" data-pr-set-form>
			<?php settings_fields( 'publishrank_seo' ); ?>
			<?php self::section_open( 'titles' ); ?>
			<p><?php esc_html_e( 'Control the global search-title format. Individual posts and pages can still override their own SEO title.', 'publishrank' ); ?></p>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="publishrank_seo_title_separator"><?php esc_html_e( 'Title Separator', 'publishrank' ); ?></label></th><td><select id="publishrank_seo_title_separator" name="publishrank_seo_title_separator">
					<?php foreach ( $separator_options as $separator_value => $separator_label ) : ?>
						<option value="<?php echo esc_attr( $separator_value ); ?>" <?php selected( $title_separator, (string) $separator_value ); ?>><?php echo esc_html( $separator_label ); ?></option>
					<?php endforeach; ?>
				</select><p class="description"><?php esc_html_e( 'Used wherever a title template contains %%sep%%. The Setup Wizard and frontend titles read this same saved value.', 'publishrank' ); ?></p></td></tr>
				<?php foreach ( $title_templates as $template_context => $template ) : ?>
					<?php $template_option = 'publishrank_seo_title_template_' . $template_context; ?>
					<tr><th scope="row"><label for="<?php echo esc_attr( $template_option ); ?>"><?php echo esc_html( $template['label'] ); ?></label></th><td><input id="<?php echo esc_attr( $template_option ); ?>" name="<?php echo esc_attr( $template_option ); ?>" type="text" class="large-text code" value="<?php echo esc_attr( $val( $template_option, $template['default'] ) ); ?>" /> <?php echo VariablePicker::slot( $template_option, 'title', $template['context'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by VariablePicker::slot(). ?><p class="description"><?php esc_html_e( 'Use Insert variable to add a supported token, including %%sep%% for the separator above.', 'publishrank' ); ?></p></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<?php self::section_open( 'identity' ); ?>
			<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="publishrank_identity_alternate_names"><?php esc_html_e( 'Organization name variants', 'publishrank' ); ?></label></th><td><textarea id="publishrank_identity_alternate_names" name="publishrank_identity_alternate_names" class="large-text" rows="4" placeholder="One variant per line"><?php echo esc_textarea( $val( 'publishrank_identity_alternate_names', '' ) ); ?></textarea><p class="description"><?php esc_html_e( 'Optional. Add recognized names, abbreviations, or spelling variants. Each line becomes an Organization alternateName value.', 'publishrank' ); ?></p></td></tr></tbody></table>
		<?php self::section_open( 'output' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'SEO plugin ownership handoff', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_external_seo_handoff" value="0" /><label><input name="publishrank_external_seo_handoff" type="checkbox" value="1" <?php checked( \PublishRankSEO\Core\OutputSettings::external_seo_handoff_preference() ); ?> /> <?php esc_html_e( 'Let a supported active SEO plugin own overlapping page metadata and JSON-LD', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'Off by default. When enabled, PublishRank suppresses its titles, descriptions, canonical and robots tags, social metadata, hreflang and page JSON-LD only while Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework is detected. Confirm the other plugin is configured to provide the output you need. This does not change its settings or your saved PublishRank output switches; redirects, sitemaps and standalone schema feeds are unaffected.', 'publishrank' ); ?></p>
					<?php
					$active_seo_plugins = ( new \PublishRankSEO\Compatibility\ConflictDetector() )->active();
					$handoff_preference = \PublishRankSEO\Core\OutputSettings::external_seo_handoff_preference();
					$handoff_active     = $handoff_preference && ! empty( $active_seo_plugins );
					$handoff_notice     = $handoff_active ? 'notice-success' : ( ! empty( $active_seo_plugins ) ? 'notice-warning' : 'notice-info' );
					?>
					<p class="description"><?php esc_html_e( 'Status reflects saved settings and currently active plugins; save this form to apply changes.', 'publishrank' ); ?></p>
					<div class="notice <?php echo esc_attr( $handoff_notice ); ?> inline" role="status">
						<p><strong><?php
						if ( $handoff_active ) {
							esc_html_e( 'Saved status: handoff active', 'publishrank' );
						} elseif ( ! empty( $active_seo_plugins ) ) {
							esc_html_e( 'Saved status: handoff off', 'publishrank' );
						} elseif ( $handoff_preference ) {
							esc_html_e( 'Saved status: waiting for a supported plugin', 'publishrank' );
						} else {
							esc_html_e( 'Saved status: PublishRank provides its own output', 'publishrank' );
						}
						?></strong><br /><?php
						if ( $handoff_active ) {
							/* translators: %s: comma-separated names of active SEO plugins. */
							echo esc_html( sprintf( __( 'Detected: %s. PublishRank is suppressing its overlapping frontend metadata, hreflang and page JSON-LD. Check that the other plugin supplies the output you need.', 'publishrank' ), implode( ', ', $active_seo_plugins ) ) );
						} elseif ( ! empty( $active_seo_plugins ) ) {
							/* translators: %s: comma-separated names of active SEO plugins. */
							echo esc_html( sprintf( __( 'Detected: %s. PublishRank is still outputting its own SEO data. Review both plugins for duplicate output, or enable handoff above.', 'publishrank' ), implode( ', ', $active_seo_plugins ) ) );
						} elseif ( $handoff_preference ) {
							esc_html_e( 'No supported SEO plugin detected. PublishRank continues its own output; the saved handoff preference will apply automatically if a supported plugin is activated.', 'publishrank' );
						} else {
							esc_html_e( 'No supported SEO plugin detected. PublishRank continues to provide its metadata and page schema.', 'publishrank' );
						}
						?></p>
					</div></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'External link policy', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_links_enabled" value="0" /><label><input name="publishrank_links_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_links_enabled', true ) ); ?> /> <?php esc_html_e( 'Automatically add nofollow to external links', 'publishrank' ); ?></label><br /><label for="publishrank_links_follow_domains"><?php esc_html_e( 'Approved external domains (follow)', 'publishrank' ); ?></label><textarea id="publishrank_links_follow_domains" name="publishrank_links_follow_domains" class="large-text" rows="2" placeholder="example.org, partner.example"><?php echo esc_textarea( $val( 'publishrank_links_follow_domains', '' ) ); ?></textarea><p class="description"><?php esc_html_e( 'One domain per line or comma-separated. Your main domain and all subdomains remain index/follow.', 'publishrank' ); ?></p><input type="hidden" name="publishrank_links_sponsored" value="0" /><label><input name="publishrank_links_sponsored" type="checkbox" value="1" <?php checked( $val( 'publishrank_links_sponsored', false ) ); ?> /> <?php esc_html_e( 'Mark external links as sponsored', 'publishrank' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Output controls', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_meta_enabled" value="0" /><label><input name="publishrank_meta_enabled" type="checkbox" value="1" <?php checked( \PublishRankSEO\Core\OutputSettings::meta() ); ?> /> <?php esc_html_e( 'Output PublishRank title, description, canonical, robots, Open Graph and X/Twitter metadata', 'publishrank' ); ?></label><br /><input type="hidden" name="publishrank_schema_enabled" value="0" /><label><input name="publishrank_schema_enabled" type="checkbox" value="1" <?php checked( \PublishRankSEO\Core\OutputSettings::schema() ); ?> /> <?php esc_html_e( 'Output the connected JSON-LD schema graph (structured data)', 'publishrank' ); ?></label><br /><input type="hidden" name="publishrank_org_website_schema_enabled" value="0" /><label><input name="publishrank_org_website_schema_enabled" type="checkbox" value="1" <?php checked( \PublishRankSEO\Core\OutputSettings::org_website_schema() ); ?> /> <?php esc_html_e( 'Include Organization (or Person) and WebSite entities in the schema graph', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'All of these are enabled by default and each one controls only its own output: turning off structured data does not affect the SEO metadata above, and turning off Organization/WebSite keeps the page and article entities. Your Organization, logo and website details are read from the Setup Wizard on every page view, so a change appears on the whole site without editing individual posts. Disable an option only if another SEO plugin owns that output.', 'publishrank' ); ?></p><p class="description"><?php esc_html_e( 'What PublishRank can and cannot change: it controls its own head metadata, JSON-LD and breadcrumb trail. A logo, site title, breadcrumb trail or metadata that your theme or another plugin hard-codes is theme output and is not rewritten. If the theme prints its own breadcrumbs, choose "Structured data only" under Breadcrumb placement; if a theme prints a second title or description tag, switch that off in the theme.', 'publishrank' ); ?></p></td></tr>
				<tr><th scope="row"><label for="publishrank_seo_description_fallback"><?php esc_html_e( 'Pages with no description text', 'publishrank' ); ?></label></th><td><select id="publishrank_seo_description_fallback" name="publishrank_seo_description_fallback"><option value="page_type" <?php selected( $val( 'publishrank_seo_description_fallback', 'page_type' ), 'page_type' ); ?>><?php esc_html_e( 'Describe the page by its title and publication (recommended)', 'publishrank' ); ?></option><option value="global" <?php selected( $val( 'publishrank_seo_description_fallback', 'page_type' ), 'global' ); ?>><?php esc_html_e( 'Use the publication-wide description', 'publishrank' ); ?></option></select><p class="description"><?php esc_html_e( 'Order used for every page: your own description, the excerpt, text extracted from the page or its page builder, then this fallback. Pages that only contain a form or a builder layout with no text use this setting, so each page can still get its own description.', 'publishrank' ); ?></p></td></tr>
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
		<?php self::section_open( 'international' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Multilingual and international SEO', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_multilingual_enabled" value="0" /><label><input name="publishrank_seo_multilingual_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_multilingual_enabled', true ) ); ?> /> <?php esc_html_e( 'Enable hreflang and regional metadata', 'publishrank' ); ?></label></td></tr>
				<tr><th scope="row"><label for="publishrank_seo_hreflang_map"><?php esc_html_e( 'Language / regional URL map', 'publishrank' ); ?></label></th><td><textarea id="publishrank_seo_hreflang_map" name="publishrank_seo_hreflang_map" rows="5" class="large-text code" placeholder="en-US=https://example.com/
fr-FR=https://fr.example.com/
ar-SA=https://example.com/ar/"><?php echo esc_textarea( $val( 'publishrank_seo_hreflang_map' ) ); ?></textarea><p class="description"><?php esc_html_e( 'One locale=URL per line. Supports language and regional codes such as en, en-US, fr-FR, ar-SA, and de-DE. WPML and Polylang URLs are detected automatically.', 'publishrank' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Mirror map on every page', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_hreflang_mirror" value="0" /><label><input name="publishrank_seo_hreflang_mirror" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_hreflang_mirror', false ) ); ?> /> <?php esc_html_e( 'Use the map above for every page by repeating the page path under each language URL (only when every language folder has the same URL structure). Otherwise the map applies to the home page, and individual pages use their own list in the editor.', 'publishrank' ); ?></label></td></tr>
				<tr><th scope="row"><label for="publishrank_seo_x_default_url"><?php esc_html_e( 'x-default URL', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_x_default_url" name="publishrank_seo_x_default_url" type="url" value="<?php echo esc_attr( $val( 'publishrank_seo_x_default_url' ) ); ?>" class="large-text" /><p class="description"><?php esc_html_e( 'Optional fallback URL for visitors whose language or region is not mapped.', 'publishrank' ); ?></p></td></tr>
			</tbody></table>
		<?php self::section_open( 'attribution' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Version provenance metadata', 'publishrank' ); ?></th><td><strong><?php esc_html_e( 'Always enabled', 'publishrank' ); ?></strong><p class="description"><?php esc_html_e( 'PublishRank adds technical brand, version, and fingerprint markers to public HTML source. These source markers cannot be disabled here and do not add a promotional link or visible footer credit.', 'publishrank' ); ?></p></td></tr>
			</tbody></table>
	
			<?php
			self::section_open( 'security' );
			$hl_pretty = (bool) get_option( 'permalink_structure' );
			$hl_prefix = trailingslashit( home_url() ) . ( $hl_pretty ? '' : '?' );
			$hl_suffix = ( $hl_pretty && '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ) ? '/' : '';
			$hl_on     = \PublishRankSEO\Security\WPHideLogin::enabled();
			?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="whl_page"><?php esc_html_e( 'Private login address', 'publishrank' ); ?> <span class="pr-set-required" aria-hidden="true">*</span></label></th><td><code><?php echo esc_html( $hl_prefix ); ?></code><input id="whl_page" name="whl_page" type="text" class="regular-text code" value="<?php echo esc_attr( \PublishRankSEO\Security\WPHideLogin::current_login_slug() ); ?>" required aria-required="true" autocomplete="off" spellcheck="false" /><?php echo '' !== $hl_suffix ? ' <code>/</code>' : ''; ?><p class="description"><?php esc_html_e( 'Your sign-in page lives here. wp-login.php and wp-admin are hidden from visitors who are not signed in. Bookmark the new address after saving.', 'publishrank' ); ?></p></td></tr>
					<tr><th scope="row"><label for="whl_redirect_admin"><?php esc_html_e( 'Send blocked visitors to', 'publishrank' ); ?></label></th><td><code><?php echo esc_html( $hl_prefix ); ?></code><input id="whl_redirect_admin" name="whl_redirect_admin" type="text" class="regular-text code" value="<?php echo esc_attr( \PublishRankSEO\Security\WPHideLogin::current_redirect_slug() ); ?>" autocomplete="off" spellcheck="false" /><?php echo '' !== $hl_suffix ? ' <code>/</code>' : ''; ?><p class="description"><?php esc_html_e( 'Where visitors land if they open wp-login.php or wp-admin. Leave 404 to show your not-found page.', 'publishrank' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Status', 'publishrank' ); ?></th><td><strong><?php echo esc_html( $hl_on ? __( 'Hide Login is on', 'publishrank' ) : __( 'Hide Login is off', 'publishrank' ) ); ?></strong><p class="description"><?php esc_html_e( 'Switch Hide Login on or off under Features.', 'publishrank' ); ?> <a href="<?php echo esc_url( \PublishRankSEO\Core\Branding::page_url( 'publishrank-modules' ) ); ?>"><?php esc_html_e( 'Open Features', 'publishrank' ); ?></a></p></td></tr>
				</tbody></table>
			<?php self::section_open( 'breadcrumbs' ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e( 'Frontend breadcrumbs', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_breadcrumbs_enabled" value="0" /><label><input name="publishrank_breadcrumbs_enabled" type="checkbox" value="1" <?php checked( \PublishRankSEO\Core\OutputSettings::is_on( 'publishrank_breadcrumbs_enabled' ) ); ?> /> <?php esc_html_e( 'Show PublishRank breadcrumbs (posts, pages and category/tag/taxonomy archives)', 'publishrank' ); ?></label><br /><input type="hidden" name="publishrank_breadcrumb_schema_enabled" value="0" /><label><input name="publishrank_breadcrumb_schema_enabled" type="checkbox" value="1" <?php checked( \PublishRankSEO\Core\OutputSettings::is_on( 'publishrank_breadcrumb_schema_enabled' ) ); ?> /> <?php esc_html_e( 'Output BreadcrumbList structured data', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'These two switches are independent: you can show the visible trail without the structured data, or keep the structured data while your theme prints the visible trail. BreadcrumbList data is part of the JSON-LD graph, so it also needs "Output the connected JSON-LD schema graph" (Output controls above) to be on. Both are enabled by default and only change what PublishRank itself prints; a breadcrumb trail drawn by your theme or another plugin is not modified.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><?php esc_html_e( 'Visible publication identity', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_override_wp_identity" value="0" /><label><input name="publishrank_override_wp_identity" type="checkbox" value="1" <?php checked( $val( 'publishrank_override_wp_identity', true ) ); ?> /> <?php esc_html_e( 'Use the PublishRank publication title and description as the WordPress Site Title and Tagline on the public site (on by default)', 'publishrank' ); ?></label><br /><input type="hidden" name="publishrank_hide_visible_site_title" value="0" /><label><input name="publishrank_hide_visible_site_title" type="checkbox" value="1" <?php checked( $val( 'publishrank_hide_visible_site_title', true ) ); ?> /> <?php esc_html_e( 'Hide the visible site/publication title on the public site (on by default)', 'publishrank' ); ?></label><br /><input type="hidden" name="publishrank_hide_visible_site_description" value="0" /><label><input name="publishrank_hide_visible_site_description" type="checkbox" value="1" <?php checked( $val( 'publishrank_hide_visible_site_description', true ) ); ?> /> <?php esc_html_e( 'Hide the visible site description/tagline on the public site (on by default)', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'This is a visual-only control for common WordPress classic and block-theme selectors. It does not remove the site title or description from SEO meta tags, Open Graph, X/Twitter, JSON-LD, feeds or the WordPress General Settings fields. The publication title and description themselves are set in PublishRank (Identity), not taken from WordPress General Settings.', 'publishrank' ); ?></p></td></tr>

	<tr><th scope="row"><?php esc_html_e( 'Breadcrumb placement', 'publishrank' ); ?></th><td><label for="publishrank_breadcrumb_placement"><?php esc_html_e( 'Where to print the visible trail', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_placement" name="publishrank_breadcrumb_placement"><?php foreach ( array( 'auto' => __( 'Automatic (recommended)', 'publishrank' ), 'content' => __( 'Above the post content', 'publishrank' ), 'hook' => __( 'Theme hook (advanced)', 'publishrank' ), 'manual' => __( 'Manual: block, shortcode or template tag', 'publishrank' ), 'schema_only' => __( 'Structured data only (my theme prints its own trail)', 'publishrank' ) ) as $placement_value => $placement_label ) { ?><option value="<?php echo esc_attr( $placement_value ); ?>" <?php selected( $val( 'publishrank_breadcrumb_placement', 'auto' ), $placement_value ); ?>><?php echo esc_html( $placement_label ); ?></option><?php } ?></select><p class="description"><?php esc_html_e( 'Google expects the BreadcrumbList structured data to match a breadcrumb trail that visitors can actually see. Automatic prints PublishRank\'s trail above the post title in block themes and above the content in classic themes, using the same items as the structured data. Choose "Structured data only" only if your theme already prints an identical trail, and turn off the theme\'s own trail if you let PublishRank print it so the page does not show two.', 'publishrank' ); ?></p><label for="publishrank_breadcrumb_hook"><?php esc_html_e( 'Theme hook name', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_hook" name="publishrank_breadcrumb_hook" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_hook', '' ) ); ?>" class="regular-text code" placeholder="astra_primary_content_top" /><p class="description"><?php esc_html_e( 'Only used with "Theme hook". Enter an action hook from your theme, for example astra_primary_content_top, generate_before_content or the hook your theme documents for "before content".', 'publishrank' ); ?></p><label for="publishrank_breadcrumb_home_label"><?php esc_html_e( 'First crumb label', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_home_label" name="publishrank_breadcrumb_home_label" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_home_label', '' ) ); ?>" class="regular-text" placeholder="Home" /><p class="description"><?php esc_html_e( 'Leave blank to use Home and the site homepage URL. Enter a custom label only when you intentionally want a different root label.', 'publishrank' ); ?></p><p class="description"><?php esc_html_e( 'Manual placement: add the "PublishRank Breadcrumbs" block, the [publishrank_breadcrumbs] shortcode, or call publishrank_breadcrumbs() in a theme template.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><?php esc_html_e( 'Breadcrumb style', 'publishrank' ); ?></th><td><?php
$pr_bc_groups = \PublishRankSEO\SEO\BreadcrumbSeparators::groups();
$pr_bc_sep    = \PublishRankSEO\SEO\BreadcrumbSeparators::current();
$pr_bc_is_preset = \PublishRankSEO\SEO\BreadcrumbSeparators::is_preset( $pr_bc_sep );
?><label for="publishrank_breadcrumb_sep_preset"><?php esc_html_e( 'Separator symbol', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_sep_preset"><?php foreach ( $pr_bc_groups as $group_label => $group_options ) { ?><optgroup label="<?php echo esc_attr( $group_label ); ?>"><?php foreach ( $group_options as $sep_value => $sep_label ) { ?><option value="<?php echo esc_attr( (string) $sep_value ); ?>" data-sep="<?php echo esc_attr( (string) $sep_value ); ?>" <?php selected( $pr_bc_sep, (string) $sep_value ); ?>><?php echo esc_html( $sep_label ); ?></option><?php } ?></optgroup><?php } ?><option value="__custom" <?php selected( $pr_bc_is_preset, false ); ?>><?php esc_html_e( 'Custom (type it in the field next to this list)', 'publishrank' ); ?></option></select>
<label for="publishrank_breadcrumbs_separator"><?php esc_html_e( 'Separator text', 'publishrank' ); ?></label> <input id="publishrank_breadcrumbs_separator" name="publishrank_breadcrumbs_separator" type="text" value="<?php echo esc_attr( $pr_bc_sep ); ?>" class="small-text" maxlength="<?php echo esc_attr( (string) \PublishRankSEO\SEO\BreadcrumbSeparators::MAX_LENGTH ); ?>" spellcheck="false" autocomplete="off" />
<p class="description"><?php echo esc_html( sprintf( /* translators: %d: maximum number of characters. */ __( 'Pick a preset or type your own symbol or short text (up to %d characters). It is printed as plain text between crumbs, so HTML is not allowed. Leading and trailing spaces are kept; leave the field empty for no visible separator. The separator is a visual setting only and never appears in BreadcrumbList structured data.', 'publishrank' ), \PublishRankSEO\SEO\BreadcrumbSeparators::MAX_LENGTH ) ); ?></p>
<p class="description"><?php esc_html_e( 'Preview:', 'publishrank' ); ?> <code id="publishrank_breadcrumb_sep_preview" style="white-space:pre-wrap" aria-live="polite"></code></p>
<script>(function(){var s=document.getElementById('publishrank_breadcrumb_sep_preset'),i=document.getElementById('publishrank_breadcrumbs_separator'),p=document.getElementById('publishrank_breadcrumb_sep_preview');if(!s||!i){return;}function preview(){if(p){p.textContent='Home'+i.value+'News'+i.value+'Article';}}s.addEventListener('change',function(){var o=s.options[s.selectedIndex];if(o&&o.value!=='__custom'){i.value=o.getAttribute('data-sep');preview();}else{i.focus();}});i.addEventListener('input',function(){var m=false;for(var k=0;k<s.options.length;k++){if(s.options[k].value!=='__custom'&&s.options[k].getAttribute('data-sep')===i.value){s.selectedIndex=k;m=true;break;}}if(!m){s.value='__custom';}preview();});preview();}());</script><br />
<label for="publishrank_breadcrumb_style"><?php esc_html_e( 'Look', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_style" name="publishrank_breadcrumb_style"><?php foreach ( array( 'plain' => __( 'Plain text (inherits theme)', 'publishrank' ), 'chips' => __( 'Chips (rounded pills)', 'publishrank' ), 'bar' => __( 'Bar (soft background strip)', 'publishrank' ), 'underline' => __( 'Underline (thin rule below)', 'publishrank' ), 'arrows' => __( 'Arrow ribbon (chevron steps)', 'publishrank' ), 'minimal' => __( 'Minimal (lowercase, underlined current)', 'publishrank' ), 'steps' => __( 'Progress steps (numbered circles)', 'publishrank' ), 'pills' => __( 'Pills (highlighted current page)', 'publishrank' ) ) as $style_value => $style_label ) { ?><option value="<?php echo esc_attr( $style_value ); ?>" <?php selected( $val( 'publishrank_breadcrumb_style', 'plain' ), $style_value ); ?>><?php echo esc_html( $style_label ); ?></option><?php } ?></select>
<label for="publishrank_breadcrumb_align"><?php esc_html_e( 'Position', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_align" name="publishrank_breadcrumb_align"><?php foreach ( array( 'left' => __( 'Left', 'publishrank' ), 'center' => __( 'Center', 'publishrank' ), 'right' => __( 'Right', 'publishrank' ) ) as $align_value => $align_label ) { ?><option value="<?php echo esc_attr( $align_value ); ?>" <?php selected( $val( 'publishrank_breadcrumb_align', 'left' ), $align_value ); ?>><?php echo esc_html( $align_label ); ?></option><?php } ?></select>
<?php echo \PublishRankSEO\Admin\BreadcrumbsPage::theme_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
<label for="publishrank_breadcrumb_size"><?php esc_html_e( 'Size', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_size" name="publishrank_breadcrumb_size"><?php foreach ( array( 'small' => __( 'Small', 'publishrank' ), 'medium' => __( 'Medium', 'publishrank' ), 'large' => __( 'Large', 'publishrank' ) ) as $size_value => $size_label ) { ?><option value="<?php echo esc_attr( $size_value ); ?>" <?php selected( $val( 'publishrank_breadcrumb_size', 'medium' ), $size_value ); ?>><?php echo esc_html( $size_label ); ?></option><?php } ?></select><br />
<label for="publishrank_breadcrumb_text_color"><?php esc_html_e( 'Text colour', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_text_color" name="publishrank_breadcrumb_text_color" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_text_color', '' ) ); ?>" class="small-text code" placeholder="#444444" />
<label for="publishrank_breadcrumb_link_color"><?php esc_html_e( 'Link colour', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_link_color" name="publishrank_breadcrumb_link_color" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_link_color', '' ) ); ?>" class="small-text code" placeholder="#0a58ca" />
<label for="publishrank_breadcrumb_bg_color"><?php esc_html_e( 'Background colour', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_bg_color" name="publishrank_breadcrumb_bg_color" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_bg_color', '' ) ); ?>" class="small-text code" placeholder="#eef2f7" />
<p class="description"><?php esc_html_e( 'Colours use hex codes such as #0a58ca. Leave them blank to follow your theme. Background applies to the Chips and Bar looks.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><?php esc_html_e( 'Breadcrumb error handling', 'publishrank' ); ?></th><td><label for="publishrank_breadcrumb_error_mode"><?php esc_html_e( 'Invalid item behavior', 'publishrank' ); ?></label> <select id="publishrank_breadcrumb_error_mode" name="publishrank_breadcrumb_error_mode"><option value="skip" <?php selected( $val( 'publishrank_breadcrumb_error_mode', 'skip' ), 'skip' ); ?>><?php esc_html_e( 'Skip invalid item', 'publishrank' ); ?></option><option value="fallback" <?php selected( $val( 'publishrank_breadcrumb_error_mode', 'skip' ), 'fallback' ); ?>><?php esc_html_e( 'Use fallback item', 'publishrank' ); ?></option><option value="text" <?php selected( $val( 'publishrank_breadcrumb_error_mode', 'skip' ), 'text' ); ?>><?php esc_html_e( 'Keep text without link', 'publishrank' ); ?></option><option value="disable" <?php selected( $val( 'publishrank_breadcrumb_error_mode', 'skip' ), 'disable' ); ?>><?php esc_html_e( 'Disable breadcrumbs on error', 'publishrank' ); ?></option></select><br /><label for="publishrank_breadcrumb_error_key"><?php esc_html_e( 'Error key', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_error_key" name="publishrank_breadcrumb_error_key" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_error_key', 'breadcrumb_error' ) ); ?>" class="regular-text code" /><br /><label for="publishrank_breadcrumb_fallback_label"><?php esc_html_e( 'Fallback label', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_fallback_label" name="publishrank_breadcrumb_fallback_label" type="text" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_fallback_label', 'Home' ) ); ?>" class="regular-text" /><br /><label for="publishrank_breadcrumb_fallback_url"><?php esc_html_e( 'Fallback URL', 'publishrank' ); ?></label> <input id="publishrank_breadcrumb_fallback_url" name="publishrank_breadcrumb_fallback_url" type="url" value="<?php echo esc_attr( $val( 'publishrank_breadcrumb_fallback_url', home_url( '/' ) ) ); ?>" class="large-text" /><br /><input type="hidden" name="publishrank_breadcrumb_log_errors" value="0" /><label><input name="publishrank_breadcrumb_log_errors" type="checkbox" value="1" <?php checked( $val( 'publishrank_breadcrumb_log_errors', false ) ); ?> /> <?php esc_html_e( 'Record breadcrumb errors for administrators', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'Use these controls when a taxonomy, archive, permalink, or canonical URL cannot be resolved. Error logs stay local to this site.', 'publishrank' ); ?></p></td></tr>
				</tbody></table>
			<?php self::section_open( 'infrastructure' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="publishrank_media_host"><?php esc_html_e( 'Media host', 'publishrank' ); ?></label></th><td><input id="publishrank_media_host" name="publishrank_media_host" type="url" value="<?php echo esc_attr( $val( 'publishrank_media_host' ) ); ?>" class="large-text" placeholder="https://images.example.com" /><p class="description"><?php esc_html_e( 'Optional. Leave empty to keep normal WordPress uploads URLs.', 'publishrank' ); ?></p></td></tr>
				<tr><th scope="row"><label for="publishrank_cdn_host"><?php esc_html_e( 'CDN host', 'publishrank' ); ?></label></th><td><input id="publishrank_cdn_host" name="publishrank_cdn_host" type="url" value="<?php echo esc_attr( $val( 'publishrank_cdn_host' ) ); ?>" class="large-text" /><input type="hidden" name="publishrank_cdn_enabled" value="0" /><label><input id="publishrank_cdn_enabled" name="publishrank_cdn_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_cdn_enabled', false ) ); ?> /> <?php esc_html_e( 'Enable CDN URL generation', 'publishrank' ); ?></label></td></tr>
				<tr><th scope="row"><label for="publishrank_video_host"><?php esc_html_e( 'Video host', 'publishrank' ); ?></label></th><td><input id="publishrank_video_host" name="publishrank_video_host" type="url" value="<?php echo esc_attr( $val( 'publishrank_video_host' ) ); ?>" class="large-text" /></td></tr>
				<tr><th scope="row"><label for="publishrank_api_host"><?php esc_html_e( 'API host', 'publishrank' ); ?></label></th><td><input id="publishrank_api_host" name="publishrank_api_host" type="url" value="<?php echo esc_attr( $val( 'publishrank_api_host' ) ); ?>" class="large-text" /></td></tr>
			</tbody></table>
			<?php self::section_open( 'news', '<a class="pr-set-card__link" href="' . esc_url( \PublishRankSEO\Core\Branding::page_url( 'publishrank-sitemap-health' ) ) . '"><i class="fa-solid fa-sitemap" aria-hidden="true"></i> ' . esc_html__( 'Open Sitemap Hub', 'publishrank' ) . '</a>' ); ?>
<table class="form-table" role="presentation"><tbody>
<tr><th scope="row"><?php esc_html_e( 'News sitemap', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_news_enabled" value="0" /><label><input name="publishrank_seo_news_enabled" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_news_enabled', true ) ); ?> /> <?php esc_html_e( 'Enable the News sitemap', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'Only recently published, indexable content is included.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_post_types"><?php esc_html_e( 'News post types', 'publishrank' ); ?></label></th><td><textarea id="publishrank_seo_news_post_types" name="publishrank_seo_news_post_types" rows="2" class="large-text" placeholder="post
news"><?php echo esc_textarea( $val( 'publishrank_seo_news_post_types', 'post' ) ); ?></textarea><p class="description"><?php esc_html_e( 'One public post type per line or comma-separated. Invalid or non-public types are ignored.', 'publishrank' ); ?></p></td></tr>
				<tr><th scope="row"><label for="publishrank_seo_news_window"><?php esc_html_e( 'News freshness window (hours)', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_news_window" name="publishrank_seo_news_window" type="number" min="1" max="168" value="<?php echo esc_attr( $val( 'publishrank_seo_news_window', 48 ) ); ?>" class="small-text" /><p class="description"><?php esc_html_e( 'Default is 48 hours. You can extend this to 72 hours or up to 7 days when your newsroom publishes fewer articles.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><?php esc_html_e( 'Updated articles', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_news_include_modified" value="0" /><label><input name="publishrank_seo_news_include_modified" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_news_include_modified', true ) ); ?> /> <?php esc_html_e( 'Also list articles that were edited recently', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'An older article that you update appears in the News sitemap for the time set below. Its publication date stays the original one and its last-modified time shows the edit.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_modified_window"><?php esc_html_e( 'Updated articles window (hours)', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_news_modified_window" name="publishrank_seo_news_modified_window" type="number" min="1" max="168" value="<?php echo esc_attr( $val( 'publishrank_seo_news_modified_window', 48 ) ); ?>" class="small-text" /><p class="description"><?php esc_html_e( 'How long an edited article stays in the News sitemap after its last update. Default is 48 hours.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_max_urls"><?php esc_html_e( 'Maximum News URLs', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_news_max_urls" name="publishrank_seo_news_max_urls" type="number" min="1" max="1000" value="<?php echo esc_attr( $val( 'publishrank_seo_news_max_urls', 1000 ) ); ?>" class="small-text" /></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_exclude_ids"><?php esc_html_e( 'Exclude post IDs', 'publishrank' ); ?></label></th><td><input id="publishrank_seo_news_exclude_ids" name="publishrank_seo_news_exclude_ids" type="text" value="<?php echo esc_attr( $val( 'publishrank_seo_news_exclude_ids' ) ); ?>" class="large-text" placeholder="12, 45, 89" /></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_exclude_categories"><?php esc_html_e( 'Exclude categories', 'publishrank' ); ?></label></th><td>
<?php
$pr_cats     = get_categories( array( 'hide_empty' => false, 'orderby' => 'name', 'number' => 500 ) );
$pr_selected = array_map( 'absint', preg_split( '/[\s,]+/', (string) $val( 'publishrank_seo_news_exclude_categories' ), -1, PREG_SPLIT_NO_EMPTY ) );
?>
<input type="hidden" name="publishrank_seo_news_exclude_categories[]" value="" />
<select id="publishrank_seo_news_exclude_categories" name="publishrank_seo_news_exclude_categories[]" multiple size="6" class="large-text">
<?php foreach ( $pr_cats as $pr_cat ) : ?>
<option value="<?php echo esc_attr( (string) $pr_cat->term_id ); ?>" <?php selected( in_array( (int) $pr_cat->term_id, $pr_selected, true ) ); ?>><?php echo esc_html( $pr_cat->name ); ?></option>
<?php endforeach; ?>
</select>
<p class="description"><?php esc_html_e( 'Articles in any selected category are left out of the News sitemap and counted as excluded in the diagnostics. Ctrl/Cmd-click to select several.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><?php esc_html_e( 'Stock tickers (legacy)', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_news_stock_tickers" value="0" /><label><input name="publishrank_seo_news_stock_tickers" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_news_stock_tickers', false ) ); ?> /> <?php esc_html_e( 'Add an optional stock-ticker field to articles and output it in the News sitemap', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( 'Off by default. Google no longer lists the stock_tickers tag in its News sitemap documentation, so it may be ignored. Only valid EXCHANGE:SYMBOL entries (max 5 per article) are output.', 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><label for="publishrank_seo_news_genre"><?php esc_html_e( 'News genre (legacy)', 'publishrank' ); ?></label></th><td><select id="publishrank_seo_news_genre" name="publishrank_seo_news_genre"><option value="" <?php selected( $val( 'publishrank_seo_news_genre' ), '' ); ?>><?php esc_html_e( 'Not specified', 'publishrank' ); ?></option>
		<?php
		foreach ( array( 'Opinion', 'PressRelease', 'Satire', 'UserGenerated', 'Report', 'Review', 'Interview', 'Background', 'Transcript', 'Obituary', 'Profile' ) as $genre ) :
			?>
	<option value="<?php echo esc_attr( $genre ); ?>" <?php selected( $val( 'publishrank_seo_news_genre' ), $genre ); ?>><?php echo esc_html( $genre ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( "Google's current News sitemap documentation no longer lists this tag, so it is not written to the sitemap unless a developer enables it with the publishrank_news_emit_legacy_tags filter.", 'publishrank' ); ?></p></td></tr>
<tr><th scope="row"><label for="publishrank_editorial_genre"><?php esc_html_e( 'Publisher editorial genre', 'publishrank' ); ?></label></th><td><select id="publishrank_editorial_genre" name="publishrank_editorial_genre"><option value="" <?php selected( $val( 'publishrank_editorial_genre' ), '' ); ?>><?php esc_html_e( 'Not specified', 'publishrank' ); ?></option>
		<?php
		foreach ( array( 'News', 'Breaking News', 'Live Updates', 'Developing Story', 'Local News', 'National News', 'International News', 'Regional News', 'World News', 'Report', 'Investigative', 'In-Depth', 'Exclusive', 'Special Report', 'Field Report', 'Eyewitness Report', 'Data Journalism', 'Fact Check', 'Verification', 'News Analysis', 'Analysis', 'Explainer', 'Background', 'Context', 'Briefing', 'News Brief', 'Roundup', 'Timeline', 'Opinion', 'Editorial', 'Column', 'Commentary', 'Op-Ed', 'Letter to the Editor', 'Guest Essay', 'Perspective', 'Interview', 'Profile', 'Q&A', 'Portrait', 'Biography', 'Obituary', 'Tribute', 'Live Blog', 'Event Coverage', 'Election Coverage', 'Breaking Coverage', 'Conference Coverage', 'Sports Coverage', 'Review', 'Criticism', 'Preview', 'Guide', 'How-To', 'FAQ', 'Consumer Advice', 'Service Journalism', 'Travel', 'Food', 'Technology', 'Science', 'Health', 'Business', 'Finance', 'Politics', 'Sports', 'Entertainment', 'Culture', 'Press Release', 'Statement', 'Transcript', 'Speech', 'Announcement', 'Official Statement', 'Court Filing', 'Document', 'Public Records', 'Research', 'Study', 'Survey', 'Feature', 'Longform', 'Magazine', 'Essay', 'Human Interest', 'Special Feature', 'Photo Essay', 'Multimedia', 'Documentary', 'Satire', 'User Generated', 'Blog', 'Archive', 'Correction', 'Update', 'Retraction', 'Clarification', 'Fact Correction' ) as $genre ) :
			?>
	<option value="<?php echo esc_attr( $genre ); ?>" <?php selected( $val( 'publishrank_editorial_genre' ), $genre ); ?>><?php echo esc_html( $genre ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Editorial classification for newsroom workflows and publisher taxonomy. This is separate from the Google News genre field above and is not written to the News Sitemap as a Google genre value.', 'publishrank' ); ?></p></td></tr>
	<tr><th scope="row"><?php esc_html_e( 'News keywords (legacy)', 'publishrank' ); ?></th><td><input type="hidden" name="publishrank_seo_news_keywords" value="0" /><label><input name="publishrank_seo_news_keywords" type="checkbox" value="1" <?php checked( $val( 'publishrank_seo_news_keywords', true ) ); ?> /> <?php esc_html_e( 'Include tags, categories, and focus keyword as news keywords', 'publishrank' ); ?></label><p class="description"><?php esc_html_e( "Google's current News sitemap documentation no longer lists this tag, so it is not written to the sitemap unless a developer enables it with the publishrank_news_emit_legacy_tags filter.", 'publishrank' ); ?></p></td></tr>
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
			<?php self::section_close(); ?>
			<div class="pr-set-savebar" data-pr-set-savebar>
				<span class="pr-set-savebar__status" data-pr-set-status><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <span><?php esc_html_e( 'Changes apply after you save.', 'publishrank' ); ?></span></span>
				<?php submit_button( __( 'Save PublishRank settings', 'publishrank' ), 'primary', 'submit', false ); ?>
			</div>
		</form>
		<?php self::section_open( 'portability' ); ?>
		<p class="description"><?php esc_html_e( 'Move metadata from Yoast SEO, Rank Math, AIOSEO, SEOPress, or The SEO Framework with field-aware safeguards.', 'publishrank' ); ?> <a class="button" href="<?php echo esc_url( \PublishRankSEO\Core\Branding::page_url( 'publishrank-seo-portability' ) ); ?>"><?php esc_html_e( 'Open Migration & Portability', 'publishrank' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="publishrank_seo_export" /><?php wp_nonce_field( 'publishrank_seo_export' ); ?><?php submit_button( __( 'Export settings', 'publishrank' ), 'secondary', 'submit', false ); ?></form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="publishrank_seo_import" /><?php wp_nonce_field( 'publishrank_seo_import' ); ?><label for="publishrank_seo_json"><span class="screen-reader-text"><?php esc_html_e( 'PublishRank JSON settings', 'publishrank' ); ?></span></label><textarea id="publishrank_seo_json" name="publishrank_seo_json" rows="8" class="large-text code" required></textarea><?php submit_button( __( 'Import settings', 'publishrank' ), 'secondary' ); ?></form>
		<?php self::section_close(); ?>
			<p class="pr-modules-empty" data-pr-set-empty hidden><?php esc_html_e( 'No settings match your search.', 'publishrank' ); ?></p>
			</div>
			</div>
		</div>
		<?php
	}
}
