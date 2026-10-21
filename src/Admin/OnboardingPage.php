<?php
/**
 * First-run setup wizard for PublishRank.
 *
 * Holds every one-time setting (publisher identity, brand, social profiles, search appearance,
 * site verification, regional defaults, content scope). It opens as a modal on activation and,
 * on fresh installs, locks PublishRank screens until the required fields are saved. Ongoing
 * controls stay in the Settings page.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\PublicationProfile;

defined( 'ABSPATH' ) || exit;

final class OnboardingPage {
	const SLUG        = 'publishrank-onboarding';
	const TOTAL_STEPS = 8;
	/** Set on fresh activation; while set and setup is incomplete, PublishRank screens are locked to the wizard. */
	const REQUIRED_OPTION = 'publishrank_setup_required';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_lock_plugin_screens' ), 2 );
		add_action( 'admin_notices', array( $this, 'setup_reminder_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_publishrank_onboarding_save', array( $this, 'save' ) );
		add_action( 'admin_post_publishrank_onboarding_exit', array( $this, 'exit_wizard' ) );
		add_action( 'admin_post_publishrank_onboarding_defaults', array( $this, 'restore_defaults' ) );
	}

	/* ------------------------------------------------------------------
	 * Field registry — single source of truth for rendering, saving and
	 * for deciding which options no longer belong to the Settings form.
	 * ---------------------------------------------------------------- */

	/** @return array<int, array{label:string, icon:string}> */
	public static function steps() {
		return array(
			1 => array( 'label' => __( 'Welcome to PublishRank 🤗', 'publishrank' ), 'icon' => 'fa-solid fa-flag' ),
			2 => array( 'label' => __( 'Publisher', 'publishrank' ), 'icon' => 'fa-solid fa-building' ),
			3 => array( 'label' => __( 'Details', 'publishrank' ), 'icon' => 'fa-solid fa-id-card' ),
			4 => array( 'label' => __( 'Social', 'publishrank' ), 'icon' => 'fa-solid fa-share-nodes' ),
			5 => array( 'label' => __( 'Search', 'publishrank' ), 'icon' => 'fa-solid fa-magnifying-glass' ),
			6 => array( 'label' => __( 'Content', 'publishrank' ), 'icon' => 'fa-solid fa-file-lines' ),
			7 => array( 'label' => __( 'Local', 'publishrank' ), 'icon' => 'fa-solid fa-location-dot' ),
			8 => array( 'label' => __( 'Ready', 'publishrank' ), 'icon' => 'fa-solid fa-circle-check' ),
		);
	}

	/**
	 * Field definitions for a step.
	 *
	 * Keys: key (option + input name), type, label, san (sanitizer), required, help, placeholder,
	 * wide, for ('both'|'org'|'person'), mirror (legacy options kept in sync), person_mirror
	 * (options also written when the identity is a Person), fallback (legacy options read for display).
	 *
	 * @param int $step Step number.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields( $step ) {
		$f = static function ( $key, $type, $label, $extra = array() ) {
			return array_merge( array( 'key' => $key, 'type' => $type, 'label' => $label, 'san' => 'text', 'required' => false, 'wide' => false, 'for' => 'both', 'mirror' => array(), 'person_mirror' => '', 'fallback' => array(), 'help' => '', 'placeholder' => '', 'default' => '' ), $extra );
		};
		switch ( (int) $step ) {
			case 2:
				return array(
					$f( 'publishrank_identity_type', 'identity', __( 'Publisher type', 'publishrank' ), array( 'san' => 'sanitize_identity_type', 'required' => true, 'default' => 'organization', 'wide' => true ) ),
					$f( 'publishrank_identity_name', 'text', __( 'Publication / publisher name', 'publishrank' ), array( 'required' => true, 'default' => get_bloginfo( 'name' ), 'mirror' => array( 'publishrank_seo_publication_name', 'publishrank_seo_publisher_name' ), 'person_mirror' => 'publishrank_identity_person_name', 'fallback' => array( 'publishrank_seo_publisher_name' ), 'help' => __( 'Used as the publisher name in schema, Open Graph and the News sitemap.', 'publishrank' ) ) ),
					$f( 'publishrank_identity_alternate_name', 'text', __( 'Short / alternate name', 'publishrank' ), array( 'mirror' => array( 'publishrank_publication_short_name' ), 'person_mirror' => 'publishrank_identity_person_alternate_name', 'fallback' => array( 'publishrank_publication_short_name' ) ) ),
					$f( 'publishrank_identity_alternate_names', 'textarea', __( 'Organization name variants', 'publishrank' ), array( 'for' => 'org', 'san' => 'textarea', 'wide' => true, 'help' => __( 'Add one recognized publication or organization variant per line. These are emitted as Organization alternateName values.', 'publishrank' ) ) ),
					$f( 'publishrank_identity_description', 'textarea', __( 'Short description', 'publishrank' ), array( 'wide' => true, 'san' => 'textarea', 'default' => get_bloginfo( 'description' ), 'mirror' => array( 'publishrank_schema_org_description' ), 'person_mirror' => 'publishrank_identity_person_description', 'fallback' => array( 'publishrank_schema_org_description' ), 'placeholder' => __( 'Independent news and analysis for…', 'publishrank' ) ) ),
					$f( 'publishrank_identity_logo', 'image', __( 'Publisher logo / photo', 'publishrank' ), array( 'wide' => true, 'san' => 'sanitize_public_url', 'mirror' => array( 'publishrank_seo_publisher_logo' ), 'person_mirror' => 'publishrank_identity_person_image', 'fallback' => array( 'publishrank_seo_publisher_logo' ), 'help' => __( 'Square or wide logo, at least 112px tall. Used in schema and search knowledge surfaces.', 'publishrank' ) ) ),
					$f( 'publishrank_seo_publisher_type', 'select', __( 'Organization type', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_publisher_type', 'default' => 'NewsMediaOrganization', 'options' => array( 'NewsMediaOrganization' => __( 'News media organization', 'publishrank' ), 'Organization' => __( 'Organization', 'publishrank' ) ) ) ),
					$f( 'publishrank_primary_url', 'url', __( 'Primary website URL', 'publishrank' ), array( 'san' => 'sanitize_public_url', 'default' => home_url( '/' ) ) ),
					$f( 'publishrank_identity_country', 'country', __( 'Country (ISO code)', 'publishrank' ), array( 'san' => 'country', 'required' => true, 'mirror' => array( 'publishrank_country' ), 'fallback' => array( 'publishrank_country' ), 'placeholder' => 'PK', 'help' => __( 'Two-letter code, for example PK, US, GB.', 'publishrank' ) ) ),
					$f( 'publishrank_default_language', 'text', __( 'Language code', 'publishrank' ), array( 'default' => substr( (string) get_locale(), 0, 2 ), 'placeholder' => 'en' ) ),
					$f( 'publishrank_default_locale', 'text', __( 'Locale', 'publishrank' ), array( 'default' => get_locale(), 'placeholder' => 'en_US' ) ),
					$f( 'publishrank_timezone', 'timezone', __( 'Timezone', 'publishrank' ), array( 'default' => wp_timezone_string() ) ),
				);
			case 3:
				return array(
					$f( 'publishrank_identity_legal_name', 'text', __( 'Legal organization name', 'publishrank' ), array( 'for' => 'org', 'mirror' => array( 'publishrank_schema_legal_name' ), 'fallback' => array( 'publishrank_schema_legal_name' ) ) ),
					$f( 'publishrank_identity_founding_date', 'text', __( 'Founding date', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_schema_date', 'placeholder' => '2015 or 2015-03-14', 'mirror' => array( 'publishrank_schema_founding_date' ), 'fallback' => array( 'publishrank_schema_founding_date' ) ) ),
					$f( 'publishrank_identity_contact_email', 'email', __( 'Public contact email', 'publishrank' ), array( 'san' => 'email', 'mirror' => array( 'publishrank_contact' ), 'person_mirror' => 'publishrank_identity_person_email', 'fallback' => array( 'publishrank_contact' ) ) ),
					$f( 'publishrank_identity_contact_phone', 'text', __( 'Public contact phone', 'publishrank' ), array( 'for' => 'org', 'mirror' => array( 'publishrank_schema_phone' ), 'fallback' => array( 'publishrank_schema_phone' ) ) ),
					$f( 'publishrank_identity_street', 'text', __( 'Street address', 'publishrank' ), array( 'for' => 'org', 'wide' => true, 'mirror' => array( 'publishrank_schema_street_address' ), 'fallback' => array( 'publishrank_schema_street_address' ) ) ),
					$f( 'publishrank_identity_locality', 'text', __( 'City / locality', 'publishrank' ), array( 'for' => 'org', 'mirror' => array( 'publishrank_schema_address_locality' ), 'fallback' => array( 'publishrank_schema_address_locality' ) ) ),
					$f( 'publishrank_identity_region', 'text', __( 'State / region', 'publishrank' ), array( 'for' => 'org', 'mirror' => array( 'publishrank_schema_address_region' ), 'fallback' => array( 'publishrank_schema_address_region' ) ) ),
					$f( 'publishrank_identity_postal_code', 'text', __( 'Postal code', 'publishrank' ), array( 'for' => 'org', 'mirror' => array( 'publishrank_schema_postal_code' ), 'fallback' => array( 'publishrank_schema_postal_code' ) ) ),
					$f( 'publishrank_schema_founder', 'text', __( 'Founder', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_parent_organization', 'text', __( 'Parent organization', 'publishrank' ), array( 'for' => 'org', 'mirror' => array( 'publishrank_schema_parent_organization' ), 'fallback' => array( 'publishrank_schema_parent_organization' ) ) ),
					$f( 'publishrank_schema_founding_location', 'text', __( 'Founding location', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_copyright_holder', 'text', __( 'Copyright holder', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_owner_name', 'text', __( 'Owner / rights holder', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_owner_url', 'url', __( 'Owner URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_owner_logo', 'image', __( 'Owner logo', 'publishrank' ), array( 'for' => 'org', 'wide' => true, 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_schema_knows_about', 'textarea', __( 'Topics / expertise (comma separated)', 'publishrank' ), array( 'for' => 'org', 'wide' => true, 'san' => 'textarea', 'placeholder' => __( 'Investigative journalism, climate reporting, public policy', 'publishrank' ) ) ),
					$f( 'publishrank_identity_person_job_title', 'text', __( 'Job title', 'publishrank' ), array( 'for' => 'person', 'placeholder' => 'Editor-in-Chief' ) ),
					$f( 'publishrank_identity_person_url', 'url', __( 'Profile URL', 'publishrank' ), array( 'for' => 'person', 'san' => 'sanitize_public_url', 'default' => home_url( '/' ) ) ),
				);
			case 4:
				$fields = array();
				foreach ( PublicationProfile::social_networks() as $network => $data ) {
					$fields[] = $f( 'publishrank_social_' . $network, 'text', $data['label'], array( 'san' => 'sanitize_social_profile', 'placeholder' => __( 'handle or full URL', 'publishrank' ) ) );
				}
				$fields[] = $f( 'publishrank_social_custom', 'textarea', __( 'Other official profile URLs (one per line)', 'publishrank' ), array( 'wide' => true, 'san' => 'sanitize_social_custom', 'placeholder' => 'https://mastodon.social/@yourpublication' ) );
				$fields[] = $f( 'publishrank_seo_twitter_site', 'text', __( 'X/Twitter handle for cards', 'publishrank' ), array( 'san' => 'sanitize_handle', 'placeholder' => 'yourpublication' ) );
				$fields[] = $f( 'publishrank_twitter_card', 'select', __( 'Default X/Twitter card', 'publishrank' ), array( 'san' => 'sanitize_twitter_card', 'default' => 'summary_large_image', 'options' => array( 'summary_large_image' => __( 'Summary with large image', 'publishrank' ), 'summary' => __( 'Summary', 'publishrank' ) ) ) );
				$fields[] = $f( 'publishrank_facebook_app_id', 'text', __( 'Facebook App ID (optional)', 'publishrank' ), array( 'san' => 'sanitize_verification' ) );
				$fields[] = $f( 'publishrank_seo_default_social_image', 'image', __( 'Default social sharing image', 'publishrank' ), array( 'wide' => true, 'san' => 'sanitize_public_url', 'help' => __( 'Used for Open Graph and X/Twitter when an article has no image. Recommended 1200×630.', 'publishrank' ) ) );
				return $fields;
			case 5:
				$fields = array(
					$f( 'publishrank_home_title', 'text', __( 'Homepage SEO title', 'publishrank' ), array( 'wide' => true, 'placeholder' => get_bloginfo( 'name' ), 'help' => __( 'Leave empty to use the site name.', 'publishrank' ) ) ),
					$f( 'publishrank_home_description', 'textarea', __( 'Homepage meta description', 'publishrank' ), array( 'wide' => true, 'san' => 'textarea', 'default' => get_bloginfo( 'description' ) ) ),
					$f( 'publishrank_seo_title_separator', 'select', __( 'Title separator', 'publishrank' ), array( 'default' => '|', 'options' => array( '|' => '|', '-' => '-', '–' => '–', '—' => '—', '·' => '·', '•' => '•', '»' => '»' ) ) ),
				);
				foreach ( array( 'google' => 'Google Search Console', 'bing' => 'Bing / Microsoft', 'yandex' => 'Yandex', 'baidu' => 'Baidu', 'pinterest' => 'Pinterest' ) as $engine => $label ) {
					$fields[] = $f( 'publishrank_verification_' . $engine, 'text', sprintf( /* translators: %s: search engine. */ __( '%s verification token', 'publishrank' ), $label ), array( 'san' => 'sanitize_verification', 'help' => 'google' === $engine ? __( 'Paste only the content value of the verification meta tag.', 'publishrank' ) : '' ) );
				}
				return $fields;
			case 6:
				return array(
					$f( 'publishrank_seo_news_enabled', 'toggle', __( 'Enable the Google News sitemap', 'publishrank' ), array( 'san' => 'bool', 'default' => 1, 'wide' => true, 'help' => __( 'Recommended for publishers. Freshness window and limits stay in Settings.', 'publishrank' ) ) ),
					$f( 'publishrank_seo_news_post_types', 'posttypes', __( 'Post types in the News sitemap', 'publishrank' ), array( 'san' => 'sanitize_post_types', 'default' => 'post', 'wide' => true ) ),
					$f( 'publishrank_sitemap_post_types', 'posttypes', __( 'Post types in the standard sitemap', 'publishrank' ), array( 'san' => 'sanitize_post_types', 'default' => 'post', 'wide' => true ) ),
				);
		}
		return array();
	}

	/**
	 * Options owned by the wizard that are no longer shown in the Settings form.
	 * They must not stay in the Settings option group, or options.php would blank them on save.
	 *
	 * @return string[]
	 */
	public static function setup_keys() {
		$keep = array( 'publishrank_seo_news_enabled', 'publishrank_seo_news_post_types', 'publishrank_sitemap_post_types' ); // Also editable in Settings.
		$keys = array( 'publishrank_social_profiles', 'publishrank_copyright', 'publishrank_identity_person_name', 'publishrank_identity_person_alternate_name', 'publishrank_identity_person_description', 'publishrank_identity_person_image', 'publishrank_identity_person_email' );
		foreach ( array_keys( self::steps() ) as $step ) {
			foreach ( self::fields( $step ) as $field ) {
				$keys[] = $field['key'];
				$keys   = array_merge( $keys, (array) $field['mirror'] );
			}
		}
		return array_values( array_diff( array_unique( $keys ), $keep ) );
	}

	/* ------------------------------------------------------------------
	 * State
	 * ---------------------------------------------------------------- */

	public function menu() {
		$label = $this->is_setup_complete() ? __( 'Setup Wizard', 'publishrank' ) : __( 'Complete Setup →', 'publishrank' );
		add_submenu_page( Branding::MENU_SLUG, __( 'PublishRank Setup', 'publishrank' ), $label, 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	/** Require the completion marker introduced with the verified setup flow. */
	private function is_setup_complete() {
		return (bool) get_option( 'publishrank_onboarding_complete', false )
			&& (string) get_option( 'publishrank_onboarding_completed_at', '' ) !== '';
	}

	/** True when PublishRank screens are locked to the wizard (fresh installs only). */
	public function is_locked() {
		return ! $this->is_setup_complete() && (bool) get_option( self::REQUIRED_OPTION, false );
	}

	/** Current stored value for a field, honouring legacy fallbacks and defaults. */
	private function value( array $field ) {
		$value = get_option( $field['key'], '' );
		if ( '' === (string) $value ) {
			foreach ( (array) $field['fallback'] as $legacy ) {
				$value = get_option( $legacy, '' );
				if ( '' !== (string) $value ) { break; }
			}
		}
		return '' === (string) $value ? $field['default'] : $value;
	}

	/** Labels of required fields that are still empty. @return string[] */
	private function missing_required() {
		$missing = array();
		foreach ( array_keys( self::steps() ) as $step ) {
			foreach ( self::fields( $step ) as $field ) {
				if ( $field['required'] && '' === trim( (string) $this->value( $field ) ) ) {
					$missing[] = $field['label'];
				}
			}
		}
		return $missing;
	}

	/* ------------------------------------------------------------------
	 * Activation redirect, lock and reminder
	 * ---------------------------------------------------------------- */

	/** After activation, open the wizard straight away. */
	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'publishrank_seo_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'publishrank_seo_activation_redirect' );
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return; // Bulk activation: the lock below catches the first PublishRank screen instead.
		}
		if ( $this->is_setup_complete() ) {
			return;
		}
		wp_safe_redirect( Branding::page_url( self::SLUG ) );
		exit;
	}

	/** On a fresh install, any PublishRank screen other than the wizard opens the wizard until setup is done. */
	public function maybe_lock_plugin_screens() {
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) || ! $this->is_locked() ) {
			return;
		}
		global $pagenow;
		if ( 'admin.php' !== $pagenow || ! Branding::is_plugin_screen() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === $page ) {
			return;
		}
		wp_safe_redirect( Branding::page_url( self::SLUG ) );
		exit;
	}

	/** A persistent first-run card on other admin screens; it remains until setup is completed. */
	public function setup_reminder_notice() {
		if ( $this->is_setup_complete() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_wizard = $screen && false !== strpos( (string) $screen->id, self::SLUG );
		if ( $is_wizard ) {
			return;
		}
		$icon       = add_query_arg( 'ver', rawurlencode( Branding::version() ), Branding::image( 'publishrank-header-icon-v2.png' ) );
		$wizard_url = Branding::page_url( self::SLUG );
		echo '<div class="notice notice-info prw-setup-notice" style="border-left-color:#5a4be2;padding:14px 16px;max-width:1000px"><div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">';
		echo '<img src="' . esc_url( $icon ) . '" alt="" width="48" height="48" style="width:48px;height:48px;object-fit:contain;flex:0 0 48px" />';
		echo '<div style="flex:1;min-width:220px"><strong style="font-size:14px;color:#1b2340">' . esc_html__( 'First-time SEO configuration', 'publishrank' ) . '</strong><p style="margin:5px 0 0">' . esc_html__( 'Set up your publisher identity, social profiles, search appearance and content scope once in the setup wizard. PublishRank outputs your publisher details only after setup is completed.', 'publishrank' ) . '</p></div>';
		echo '<a href="' . esc_url( $wizard_url ) . '" class="button button-primary prw-notice-button">' . esc_html__( 'Start onboarding', 'publishrank' ) . '</a>';
		echo '</div></div>';
	}

	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'publishrank-seo-onboarding', Branding::asset_url( 'css/onboarding.css' ), array( 'dashicons', 'publishrank-seo-admin' ), Branding::version() );
		wp_enqueue_script( 'publishrank-seo-onboarding', Branding::asset_url( 'js/onboarding.js' ), array( 'jquery' ), Branding::version(), true );
	}

	/** Leave the wizard without completing it. Not available while the wizard is locked. */
	public function exit_wizard() {
		AdminAssets::guard( 'publishrank_onboarding_exit' );
		if ( $this->is_locked() ) {
			wp_safe_redirect( Branding::page_url( self::SLUG ) );
			exit;
		}
		wp_safe_redirect( Branding::page_url( Branding::MENU_SLUG ) );
		exit;
	}

	/** Environment readiness checks shown on step 1. */
	private function environment_checks() {
		global $wp_version;
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$seo_plugins = array( 'wordpress-seo/wp-seo.php' => 'Yoast SEO', 'rank-math/rank-math.php' => 'Rank Math', 'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO' );
		$conflict    = '';
		foreach ( $seo_plugins as $file => $label ) {
			if ( is_plugin_active( $file ) ) { $conflict = $label; break; }
		}
		$uploads = wp_upload_dir();
		return array(
			'wp'       => array( 'ok' => version_compare( $wp_version, '6.3', '>=' ), 'label' => sprintf( __( /* translators: %s: installed WordPress version. */ 'WordPress %s (6.3+ required)', 'publishrank' ), $wp_version ) ),
			'php'      => array( 'ok' => version_compare( PHP_VERSION, '7.4', '>=' ), 'label' => sprintf( __( /* translators: %s: installed PHP version. */ 'PHP %s (7.4+ required)', 'publishrank' ), PHP_VERSION ) ),
			'ssl'      => array( 'ok' => is_ssl(), 'label' => __( 'HTTPS active', 'publishrank' ) ),
			'perm'     => array( 'ok' => get_option( 'permalink_structure' ) !== '', 'label' => __( 'Pretty permalinks enabled', 'publishrank' ) ),
			'conflict' => array( 'ok' => '' === $conflict, 'label' => $conflict ? sprintf( __( /* translators: %s: name of the other active SEO plugin. */ 'Another SEO plugin detected: %s — running both is not recommended.', 'publishrank' ), $conflict ) : __( 'No conflicting SEO plugin detected', 'publishrank' ) ),
			'uploads'  => array( 'ok' => empty( $uploads['error'] ), 'label' => empty( $uploads['error'] ) ? __( 'Uploads directory writable', 'publishrank' ) : $uploads['error'] ),
		);
	}

	/** Restore conservative recommended defaults and reopen the wizard. */
	public function restore_defaults() {
		AdminAssets::guard( 'publishrank_onboarding_defaults' );
		$defaults = array(
			'publishrank_identity_type'           => 'organization',
			'publishrank_identity_name'           => get_bloginfo( 'name' ),
			'publishrank_identity_description'    => get_bloginfo( 'description' ),
			'publishrank_seo_publication_name'    => get_bloginfo( 'name' ),
			'publishrank_seo_publisher_name'      => get_bloginfo( 'name' ),
			'publishrank_seo_publisher_logo'      => '',
			'publishrank_identity_logo'           => '',
			'publishrank_home_title'              => '',
			'publishrank_home_description'        => get_bloginfo( 'description' ),
			'publishrank_seo_default_robots'      => 'index,follow',
			'publishrank_seo_title_template_home' => '%%sitename%% %%page%%',
			'publishrank_seo_title_separator'     => '|',
			'publishrank_seo_news_enabled'        => 1,
			'publishrank_seo_news_window'         => 48,
			'publishrank_seo_news_keywords'       => 1,
			'publishrank_sitemap_stylesheet'      => 1,
			'publishrank_breadcrumbs_enabled'     => 1,
			'publishrank_meta_enabled'            => 1,
			'publishrank_schema_enabled'          => 1,
			'publishrank_local_seo_profile'       => array( 'enabled' => 0, 'name' => '', 'type' => 'LocalBusiness', 'description' => '', 'url' => '', 'phone' => '', 'email' => '', 'logo' => '', 'street' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'country' => '', 'latitude' => '', 'longitude' => '', 'price_range' => '', 'has_map' => '', 'service_area' => '', 'opening_hours' => array(), 'same_as' => array() ),
		);
		foreach ( $defaults as $key => $value ) { update_option( $key, $value ); }
		delete_option( 'publishrank_onboarding_complete' );
		delete_option( 'publishrank_onboarding_completed_at' );
		update_option( self::REQUIRED_OPTION, 1, false );
		$this->bump_cache();
		wp_safe_redirect( Branding::page_url( self::SLUG ) . '&step=1&restored=1' );
		exit;
	}

	private function bump_cache() {
		update_option( 'publishrank_seo_cache_epoch', time(), false );
	}

	/* ------------------------------------------------------------------
	 * Saving
	 * ---------------------------------------------------------------- */

	/** Sanitize a raw submitted value according to a field's sanitizer key. */
	private function clean( array $field, $raw, SettingsPage $settings ) {
		$san = $field['san'];
		if ( 'posttypes' === $field['type'] ) {
			$raw = is_array( $raw ) ? implode( ',', array_map( 'sanitize_key', $raw ) ) : '';
			return $settings->sanitize_post_types( $raw );
		}
		switch ( $san ) {
			case 'text':     return sanitize_text_field( (string) $raw );
			case 'textarea': return sanitize_textarea_field( (string) $raw );
			case 'email':    return sanitize_email( (string) $raw );
			case 'bool':     return empty( $raw ) ? 0 : 1;
			case 'country':
				$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $raw ) );
				return 2 === strlen( $code ) ? $code : '';
		}
		return method_exists( $settings, $san ) ? $settings->{$san}( $raw ) : sanitize_text_field( (string) $raw );
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_onboarding_save' );
		$step      = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 1;
		$step      = max( 1, min( self::TOTAL_STEPS, $step ) );
		$edit      = ! empty( $_POST['edit_mode'] ) ? '&edit=1' : '';
		$settings  = new SettingsPage();
		$identity  = PublicationProfile::identity_type();
		$errors    = array();

		if ( 2 === $step && isset( $_POST['publishrank_identity_type'] ) ) {
			$identity = $settings->sanitize_identity_type( wp_unslash( $_POST['publishrank_identity_type'] ) );
		}
		$is_person = 'person' === $identity;

		foreach ( self::fields( $step ) as $field ) {
			if ( ( 'org' === $field['for'] && $is_person ) || ( 'person' === $field['for'] && ! $is_person ) ) {
				continue;
			}
			$raw   = isset( $_POST[ $field['key'] ] ) ? wp_unslash( $_POST[ $field['key'] ] ) : ( 'toggle' === $field['type'] ? 0 : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by the field-specific cleaner immediately below.
			$clean = $this->clean( $field, $raw, $settings );
			if ( 'publishrank_identity_person_url' === $field['key'] && '' === $clean ) { $clean = esc_url_raw( home_url( '/' ) ); }
			if ( $field['required'] && '' === trim( (string) $clean ) ) { $errors[] = $field['label']; }
			update_option( $field['key'], $clean );
			foreach ( (array) $field['mirror'] as $legacy ) { update_option( $legacy, $clean ); }
			if ( $is_person && $field['person_mirror'] ) { update_option( $field['person_mirror'], $clean ); }
		}

		if ( 7 === $step ) {
			$local_input = isset( $_POST['local_seo'] ) && is_array( $_POST['local_seo'] ) ? wp_unslash( $_POST['local_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by LocalSEO::sanitize_profile() below.
			update_option( 'publishrank_local_seo_profile', \PublishRankSEO\SEO\LocalSEO::sanitize_profile( $local_input ) );
		}

		$next = min( self::TOTAL_STEPS, $step + 1 );
		if ( $errors ) {
			$next = $step; // Stay here until the required fields are filled in.
		}

		if ( 8 === $step ) {
			$missing = $this->missing_required();
			if ( $missing ) {
				$this->bump_cache();
				wp_safe_redirect( Branding::page_url( self::SLUG ) . '&step=2&error=required' . $edit );
				exit;
			}
			update_option( 'publishrank_onboarding_complete', 1 );
			update_option( 'publishrank_onboarding_version', Branding::version() );
			update_option( 'publishrank_onboarding_completed_at', gmdate( 'c' ) );
			delete_option( self::REQUIRED_OPTION );
			$this->bump_cache();
			wp_safe_redirect( Branding::page_url( self::SLUG ) . '&done=1' );
			exit;
		}

		$this->bump_cache();
		$query = '&step=' . $next . ( $errors ? '&error=required' : '&saved=1' ) . $edit;
		wp_safe_redirect( Branding::page_url( self::SLUG ) . $query );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	/** Render a single field. */
	private function render_field( array $field, $is_person ) {
		if ( ( 'org' === $field['for'] && $is_person ) || ( 'person' === $field['for'] && ! $is_person ) ) {
			return;
		}
		$key   = $field['key'];
		$value = $this->value( $field );
		$id    = 'prw_' . $key;
		$req   = $field['required'] ? ' required aria-required="true"' : '';
		$class = 'prw-field' . ( $field['wide'] ? ' prw-field--wide' : '' ) . ( 'toggle' === $field['type'] ? ' prw-field--toggle' : '' );
		echo '<div class="' . esc_attr( $class ) . '">';
		if ( 'identity' === $field['type'] ) {
			echo '<span class="prw-label">' . esc_html( $field['label'] ) . ' <em class="prw-req">*</em></span><div class="rhs-choice-grid">';
			foreach ( array( 'organization' => array( __( 'Organization', 'publishrank' ), __( 'Newspapers, magazines, newsrooms and digital publishers.', 'publishrank' ) ), 'person' => array( __( 'Person', 'publishrank' ), __( 'An individual publisher, journalist or creator-led site.', 'publishrank' ) ) ) as $option => $copy ) {
				echo '<label class="rhs-choice"><input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $option ) . '"' . checked( $value, $option, false ) . ' /><span><b>' . esc_html( $copy[0] ) . '</b><small>' . esc_html( $copy[1] ) . '</small></span></label>';
			}
			echo '</div>';
		} elseif ( 'toggle' === $field['type'] ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="0" /><label class="rhs-toggle"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( (bool) $value, true, false ) . ' /><span><b>' . esc_html( $field['label'] ) . '</b>' . ( $field['help'] ? '<small>' . esc_html( $field['help'] ) . '</small>' : '' ) . '</span></label>';
		} else {
			echo '<label class="prw-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . ( $field['required'] ? ' <em class="prw-req">*</em>' : '' ) . '</label>';
			switch ( $field['type'] ) {
				case 'textarea':
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" rows="3" placeholder="' . esc_attr( $field['placeholder'] ) . '"' . $req . '>' . esc_textarea( (string) $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				case 'select':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">';
					foreach ( (array) $field['options'] as $option => $label ) {
						echo '<option value="' . esc_attr( $option ) . '"' . selected( (string) $value, (string) $option, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
					break;
				case 'timezone':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">' . wp_timezone_choice( (string) $value ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				case 'image':
					echo '<span class="prw-media-row"><input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="https://" /><button type="button" class="button rhs-media-button" data-target="' . esc_attr( $id ) . '">' . esc_html__( 'Choose image', 'publishrank' ) . '</button></span>';
					break;
				case 'country':
					echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" maxlength="2" pattern="[A-Za-z]{2}" placeholder="' . esc_attr( $field['placeholder'] ) . '" autocomplete="country"' . $req . ' style="text-transform:uppercase" />'; // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				case 'posttypes':
					$selected = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', (string) $value ) ) );
					echo '<div class="prw-checks">';
					foreach ( get_post_types( array( 'public' => true, 'publicly_queryable' => true ), 'objects' ) as $type => $object ) {
						if ( 'attachment' === $type ) { continue; }
						echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $type ) . '"' . checked( in_array( $type, $selected, true ), true, false ) . ' /> ' . esc_html( $object->labels->name ) . '</label>';
					}
					echo '</div>';
					break;
				default: // text, url, email.
					$input = in_array( $field['type'], array( 'url', 'email' ), true ) ? $field['type'] : 'text';
					echo '<input type="' . esc_attr( $input ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '"' . $req . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( $field['help'] ) {
				echo '<small class="prw-help">' . esc_html( $field['help'] ) . '</small>';
			}
		}
		echo '</div>';
	}

	/** Summary cards for the review step and the completed view. */
	private function recap() {
		$name  = (string) get_option( 'publishrank_identity_name', get_bloginfo( 'name' ) );
		$title = (string) get_option( 'publishrank_home_title', '' );
		$local = get_option( 'publishrank_local_seo_profile', array() );
		$local = is_array( $local ) ? $local : array();
		$news  = (bool) get_option( 'publishrank_seo_news_enabled', true );
		$items = array(
			array( $name ?: get_bloginfo( 'name' ), 'person' === PublicationProfile::identity_type() ? __( 'Publisher (person)', 'publishrank' ) : __( 'Publisher (organization)', 'publishrank' ) ),
			array( strtoupper( (string) get_option( 'publishrank_identity_country', '' ) ) ?: '—', __( 'Country', 'publishrank' ) ),
			array( count( PublicationProfile::social_profiles() ) . ' ' . __( 'profiles', 'publishrank' ), __( 'Social profiles (sameAs)', 'publishrank' ) ),
			array( $title ?: get_bloginfo( 'name' ), __( 'Homepage SEO title', 'publishrank' ) ),
			array( $news ? __( 'News sitemap on', 'publishrank' ) : __( 'News sitemap off', 'publishrank' ), (string) get_option( 'publishrank_sitemap_post_types', 'post' ) ),
			array( ! empty( $local['enabled'] ) ? ( $local['name'] ?? __( 'Local business on', 'publishrank' ) ) : __( 'Local SEO off', 'publishrank' ), __( 'Local business', 'publishrank' ) ),
		);
		echo '<div class="prw-recap">';
		foreach ( $items as $item ) {
			echo '<div class="prw-recap__item"><strong>' . esc_html( (string) $item[0] ) . '</strong><span>' . esc_html( (string) $item[1] ) . '</span></div>';
		}
		echo '</div>';
	}

	/** The optional Local SEO step. */
	private function render_local_step() {
		$local = get_option( 'publishrank_local_seo_profile', array() );
		$local = wp_parse_args( is_array( $local ) ? $local : array(), array( 'enabled' => 0, 'name' => '', 'type' => 'LocalBusiness', 'description' => '', 'url' => home_url( '/' ), 'phone' => '', 'email' => '', 'logo' => '', 'street' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'country' => '', 'latitude' => '', 'longitude' => '', 'price_range' => '', 'has_map' => '', 'service_area' => '', 'opening_hours' => array(), 'same_as' => array() ) );
		echo '<div class="prw-fields">';
		echo '<div class="prw-field prw-field--wide prw-field--toggle"><input type="hidden" name="local_seo[enabled]" value="0" /><label class="rhs-toggle"><input type="checkbox" name="local_seo[enabled]" value="1"' . checked( ! empty( $local['enabled'] ), true, false ) . ' /><span><b>' . esc_html__( 'Enable LocalBusiness schema', 'publishrank' ) . '</b><small>' . esc_html__( 'Leave off for a purely digital publication without a real public business location.', 'publishrank' ) . '</small></span></label></div>';
		$text = array( 'name' => __( 'Business / location name', 'publishrank' ), 'url' => __( 'Business URL', 'publishrank' ), 'phone' => __( 'Business phone', 'publishrank' ), 'email' => __( 'Business email', 'publishrank' ), 'logo' => __( 'Business logo / image URL', 'publishrank' ), 'street' => __( 'Street address', 'publishrank' ), 'city' => __( 'City / locality', 'publishrank' ), 'region' => __( 'Region', 'publishrank' ), 'postal_code' => __( 'Postal code', 'publishrank' ), 'country' => __( 'Country', 'publishrank' ), 'latitude' => __( 'Latitude', 'publishrank' ), 'longitude' => __( 'Longitude', 'publishrank' ), 'service_area' => __( 'Service area', 'publishrank' ), 'has_map' => __( 'Map URL', 'publishrank' ), 'price_range' => __( 'Price range (if applicable)', 'publishrank' ) );
		echo '<div class="prw-field"><label class="prw-label" for="prw_local_type">' . esc_html__( 'Business type', 'publishrank' ) . '</label><select id="prw_local_type" name="local_seo[type]">';
		foreach ( \PublishRankSEO\SEO\LocalSEO::types() as $type => $label ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $local['type'], $type, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></div>';
		foreach ( $text as $key => $label ) {
			$kind = in_array( $key, array( 'url', 'logo', 'has_map' ), true ) ? 'url' : ( 'email' === $key ? 'email' : ( in_array( $key, array( 'latitude', 'longitude' ), true ) ? 'number' : 'text' ) );
			echo '<div class="prw-field"><label class="prw-label" for="prw_local_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input id="prw_local_' . esc_attr( $key ) . '" type="' . esc_attr( $kind ) . '"' . ( 'number' === $kind ? ' step="any"' : '' ) . ' name="local_seo[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $local[ $key ] ) . '" /></div>';
		}
		echo '<div class="prw-field prw-field--wide"><label class="prw-label" for="prw_local_hours">' . esc_html__( 'Opening hours', 'publishrank' ) . '</label><textarea id="prw_local_hours" name="local_seo[opening_hours]" rows="2">' . esc_textarea( is_array( $local['opening_hours'] ) ? implode( "\n", $local['opening_hours'] ) : '' ) . '</textarea><small class="prw-help">' . esc_html__( 'Example: Mo-Fr 09:00-17:00', 'publishrank' ) . '</small></div>';
		echo '<div class="prw-field prw-field--wide"><label class="prw-label" for="prw_local_same">' . esc_html__( 'Official profile URLs (one per line)', 'publishrank' ) . '</label><textarea id="prw_local_same" name="local_seo[same_as]" rows="2">' . esc_textarea( is_array( $local['same_as'] ) ? implode( "\n", $local['same_as'] ) : '' ) . '</textarea></div>';
		echo '</div>';
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$steps    = self::steps();
		$step     = isset( $_GET['step'] ) ? max( 1, min( self::TOTAL_STEPS, absint( $_GET['step'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$complete = $this->is_setup_complete();
		$editing  = ! empty( $_GET['edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$locked   = $this->is_locked();

		if ( $complete && ! $editing ) {
			$this->render_complete_view();
			return;
		}

		$is_person = PublicationProfile::identity_is_person();
		$closable  = ! $locked;
		$close_url = $complete ? Branding::page_url( self::SLUG ) : wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_onboarding_exit' ), 'publishrank_onboarding_exit' );
		$self      = Branding::page_url( self::SLUG );
		$edit_qs   = $editing ? '&edit=1' : '';
		$percent   = (int) round( ( $step / self::TOTAL_STEPS ) * 100 );
		?>
		<div class="wrap rhs-onboarding-wrap prw-modal-page">
			<div class="prw-modal-backdrop" id="prw-modal">
				<div class="prw-modal" role="dialog" aria-modal="true" aria-labelledby="prw-modal-title">
					<header class="prw-modal__head">
						<img src="<?php echo esc_url( Branding::image( 'publishrank-header-icon-v2.png' ) ); ?>" alt="" width="40" height="40" />
						<div class="prw-modal__title">
							<span class="rhs-eyebrow"><?php echo esc_html( $complete ? __( 'Edit setup', 'publishrank' ) : __( 'First-time setup', 'publishrank' ) ); ?> · <?php echo esc_html( sprintf( /* translators: 1: step, 2: total. */ __( 'Step %1$d of %2$d', 'publishrank' ), $step, self::TOTAL_STEPS ) ); ?></span>
							<h1 id="prw-modal-title"><?php echo esc_html( $steps[ $step ]['label'] ); ?></h1>
						</div>
						<?php if ( $closable ) : ?><a class="prw-modal__close" href="<?php echo esc_url( $close_url ); ?>" aria-label="<?php esc_attr_e( 'Close setup wizard', 'publishrank' ); ?>">&times;</a><?php endif; ?>
					</header>
					<div class="prw-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $percent ); ?>"><span style="width:<?php echo esc_attr( $percent ); ?>%"></span></div>
						<ol class="prw-stepper" aria-label="<?php esc_attr_e( 'Setup progress', 'publishrank' ); ?>">
							<?php foreach ( $steps as $number => $data ) : $class = $number < $step ? 'is-done' : ( $number === $step ? 'is-active' : '' ); ?>
								<li class="prw-stepper__item <?php echo esc_attr( $class ); ?>"><span class="prw-stepper__bubble"><i class="<?php echo esc_attr( $data['icon'] ); ?>" aria-hidden="true"></i></span><span class="prw-stepper__label"><?php echo esc_html( $data['label'] ); ?></span></li>
							<?php endforeach; ?>
						</ol>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="prw-modal__form">
						<input type="hidden" name="action" value="publishrank_onboarding_save" />
						<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />
						<?php if ( $editing ) : ?><input type="hidden" name="edit_mode" value="1" /><?php endif; ?>
						<?php wp_nonce_field( 'publishrank_onboarding_save' ); ?>
						<div class="prw-modal__body">
							<?php if ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
								<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( /* translators: %s: field list. */ __( 'Please complete the required fields (*): %s', 'publishrank' ), implode( ', ', $this->missing_required() ) ?: __( 'see highlighted fields', 'publishrank' ) ) ); ?></p></div>
							<?php elseif ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
								<div class="notice notice-success inline"><p><?php esc_html_e( 'Saved.', 'publishrank' ); ?></p></div>
							<?php endif; ?>
							<?php $this->render_step_body( $step, $is_person, $locked ); ?>
						</div>
						<footer class="prw-modal__foot">
							<span class="prw-modal__note"><?php echo esc_html( $locked ? __( 'Required one-time setup', 'publishrank' ) : __( 'Everything stays editable here later.', 'publishrank' ) ); ?></span>
							<?php if ( ! $locked && ! $complete ) : ?><a class="prw-exit" href="<?php echo esc_url( $close_url ); ?>"><?php esc_html_e( 'Exit wizard', 'publishrank' ); ?></a><?php endif; ?>
							<?php if ( $step > 1 ) : ?><a class="button" href="<?php echo esc_url( $self . '&step=' . ( $step - 1 ) . $edit_qs ); ?>"><?php esc_html_e( 'Back', 'publishrank' ); ?></a><?php endif; ?>
							<?php if ( 1 === $step ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( $self . '&step=2' . $edit_qs ); ?>"><?php esc_html_e( 'Start configuration', 'publishrank' ); ?> <span>→</span></a>
							<?php else : ?>
								<button class="button button-primary" type="submit"><?php echo esc_html( 8 === $step ? __( 'Finish setup', 'publishrank' ) : __( 'Save & continue', 'publishrank' ) ); ?> <span>→</span></button>
							<?php endif; ?>
						</footer>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_step_body( $step, $is_person, $locked ) {
		$lead = array(
			1 => array( __( 'Welcome to PublishRank 🤗', 'publishrank' ), __( 'Here is your quick-start guide. This one-time setup tells search engines who publishes this site, and everything can be edited later from the Setup Wizard.', 'publishrank' ) ),
			2 => array( __( 'Who publishes this site?', 'publishrank' ), __( 'Powers your Organization/Person schema and keeps publisher information consistent across search surfaces. Fields marked * are required.', 'publishrank' ) ),
			3 => array( $is_person ? __( 'About the publisher', 'publishrank' ) : __( 'Organization details', 'publishrank' ), __( 'All optional. Only add details that are public and accurate.', 'publishrank' ) ),
			4 => array( __( 'Social profiles and sharing', 'publishrank' ), __( 'Official profiles become the schema sameAs list. Enter a handle or a full URL.', 'publishrank' ) ),
			5 => array( __( 'Search appearance and verification', 'publishrank' ), __( 'Homepage title and description, plus ownership verification for search engines.', 'publishrank' ) ),
			6 => array( __( 'Content scope', 'publishrank' ), __( 'Choose which content types appear in your sitemaps.', 'publishrank' ) ),
			7 => array( __( 'Local business (optional)', 'publishrank' ), __( 'Only enable this if the publication has a real, public-facing business location.', 'publishrank' ) ),
			8 => array( __( 'Review and finish', 'publishrank' ), __( 'Check your configuration, then finish. Ongoing controls such as the News window, breadcrumbs and robots live in Settings.', 'publishrank' ) ),
		);
		echo '<h2>' . esc_html( $lead[ $step ][0] ) . '</h2><p class="rhs-lead">' . esc_html( $lead[ $step ][1] ) . '</p>';
		if ( 1 === $step ) {
			echo '<div class="prw-quick-start" role="note"><strong>' . esc_html__( 'Quick start', 'publishrank' ) . '</strong><ol><li>' . esc_html__( 'Add your publication identity and logo.', 'publishrank' ) . '</li><li>' . esc_html__( 'Review search appearance, social profiles and content scope.', 'publishrank' ) . '</li><li>' . esc_html__( 'Finish setup, then review ongoing controls in Settings.', 'publishrank' ) . '</li></ol></div>';
			echo '<ul class="prw-checklist">';
				foreach ( $this->environment_checks() as $check ) {
					$icon = $check['ok'] ? 'fa-circle-check' : 'fa-triangle-exclamation';
					echo '<li class="' . ( $check['ok'] ? 'is-ok' : 'is-warn' ) . '"><i class="fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></i>' . esc_html( $check['label'] ) . '</li>';
				}
			echo '</ul>';
			if ( $locked ) {
				echo '<p class="prw-lock-note">' . esc_html__( 'PublishRank screens unlock as soon as the required publisher fields are saved.', 'publishrank' ) . '</p>';
			}
			return;
		}
		if ( 7 === $step ) {
			$this->render_local_step();
			return;
		}
		if ( 8 === $step ) {
			$this->recap();
			$missing = $this->missing_required();
			if ( $missing ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( /* translators: %s: field list. */ __( 'Still required: %s. Finishing will take you back to the Publisher step.', 'publishrank' ), implode( ', ', $missing ) ) ) . '</p></div>';
			}
			return;
		}
		echo '<div class="prw-fields">';
		foreach ( self::fields( $step ) as $field ) {
			$this->render_field( $field, $is_person );
		}
		echo '</div>';
	}

	/** The summary shown once setup is complete. */
	private function render_complete_view() {
		$self = Branding::page_url( self::SLUG );
		echo '<div class="wrap rhs-onboarding-wrap">';
		AdminAssets::header( __( 'PublishRank Setup', 'publishrank' ), __( 'Your setup is complete. Review the configuration here, or edit it when your publication changes.', 'publishrank' ) );
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Setup complete. PublishRank is now outputting your publisher identity.', 'publishrank' ) . '</p></div>';
		}
		if ( isset( $_GET['restored'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recommended defaults restored.', 'publishrank' ) . '</p></div>';
		}
			echo '<div class="rhs-onboarding-card"><div class="rhs-setup-complete"><div class="rhs-setup-complete__icon" aria-hidden="true">✓</div><div class="rhs-setup-complete__copy"><span class="rhs-eyebrow">' . esc_html__( 'Setup complete', 'publishrank' ) . '</span><h2>' . esc_html__( 'Your PublishRank configuration', 'publishrank' ) . '</h2><small>' . esc_html__( 'One-time settings live in this wizard. Ongoing controls are in Settings.', 'publishrank' ) . '</small></div></div>';
			$this->recap();
			echo '<div class="rhs-feature-callout"><div class="rhs-feature-icon" aria-hidden="true">ID</div><div><strong>' . esc_html__( 'Next: publisher account and data connections', 'publishrank' ) . '</strong><p>' . esc_html__( 'Configure the shared PublishRank sign-in, connect Search Console with read-only access, and review webmaster submission tools.', 'publishrank' ) . '</p></div><a class="button button-secondary" href="' . esc_url( Branding::page_url( 'publishrank-auth' ) ) . '">' . esc_html__( 'Set up connections', 'publishrank' ) . '</a></div>';
			echo '<div class="rhs-onboarding-actions"><a class="button button-primary" href="' . esc_url( $self . '&edit=1&step=2' ) . '">' . esc_html__( 'Edit setup', 'publishrank' ) . '</a><a class="button button-secondary" href="' . esc_url( Branding::page_url( Branding::MENU_SLUG ) ) . '">' . esc_html__( 'Go to dashboard', 'publishrank' ) . '</a><a class="button button-secondary" href="' . esc_url( Branding::page_url( 'publishrank' ) ) . '">' . esc_html__( 'Settings', 'publishrank' ) . '</a><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(&quot;' . esc_attr__( 'Restore recommended defaults? Your current wizard-managed values will be replaced.', 'publishrank' ) . '&quot;);"><input type="hidden" name="action" value="publishrank_onboarding_defaults" />';
		wp_nonce_field( 'publishrank_onboarding_defaults' );
		echo '<button class="button" type="submit">' . esc_html__( 'Restore recommended defaults', 'publishrank' ) . '</button></form></div></div></div>';
	}
}
