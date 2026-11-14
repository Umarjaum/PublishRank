<?php
/**
 * First-run setup wizard for PublishRank.
 *
 * Holds every one-time setting (publisher identity, brand, social profiles, search appearance,
 * site verification, regional defaults, content scope). It opens inside the shared PublishRank frame on activation and,
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
	const TOTAL_STEPS = 9;
	/** Set on fresh activation; while set and setup is incomplete, PublishRank screens are locked to the wizard. */
	const REQUIRED_OPTION = 'publishrank_setup_required';

	/** @var string[]|null Per-request cache for missing_required(); cleared whenever any option changes. */
	private $missing_cache = null;

	/** Drop the per-request cache so a value saved earlier in the same request is never read stale. */
	public function clear_missing_cache() {
		$this->missing_cache = null;
	}

	public function register() {
		foreach ( array( 'updated_option', 'added_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( $this, 'clear_missing_cache' ) );
		}
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_lock_plugin_screens' ), 2 );
		add_action( 'admin_notices', array( $this, 'setup_reminder_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_publishrank_onboarding_save', array( $this, 'save' ) );
		add_action( 'admin_post_publishrank_onboarding_exit', array( $this, 'exit_wizard' ) );
		add_action( 'admin_post_publishrank_onboarding_defaults', array( $this, 'restore_defaults' ) );
		add_action( 'wp_ajax_publishrank_home_preview', array( $this, 'ajax_home_preview' ) );
	}

	/* ------------------------------------------------------------------
	 * Field registry — single source of truth for rendering, saving and
	 * for deciding which options no longer belong to the Settings form.
	 * ---------------------------------------------------------------- */

	/** @return array<int, array{label:string, icon:string}> */
	public static function steps() {
		return array(
			1 => array(
				'label' => __( 'Welcome', 'publishrank' ),
				'icon'  => 'fa-solid fa-flag',
			),
			2 => array(
				'label' => __( 'Publisher', 'publishrank' ),
				'icon'  => 'fa-solid fa-building',
			),
			3 => array(
				'label' => __( 'Details', 'publishrank' ),
				'icon'  => 'fa-solid fa-id-card',
			),
			4 => array(
				'label' => __( 'Social', 'publishrank' ),
				'icon'  => 'fa-solid fa-share-nodes',
			),
			5 => array(
				'label' => __( 'Search', 'publishrank' ),
				'icon'  => 'fa-solid fa-magnifying-glass',
			),
			6 => array(
				'label' => __( 'Content', 'publishrank' ),
				'icon'  => 'fa-solid fa-file-lines',
			),
			7 => array(
				'label' => __( 'Local', 'publishrank' ),
				'icon'  => 'fa-solid fa-location-dot',
			),
			8 => array(
				'label' => __( 'Security', 'publishrank' ),
				'icon'  => 'fa-solid fa-user-shield',
			),
			9 => array(
				'label' => __( 'Ready', 'publishrank' ),
				'icon'  => 'fa-solid fa-circle-check',
			),
		);
	}

	/**
	 * Separators supported by both the setup wizard and ongoing title settings.
	 * Keys are the literal values stored in publishrank_seo_title_separator.
	 *
	 * @return array<string,string>
	 */
	public static function title_separator_options() {
		return array(
			'|' => 'Pipe |', '¦' => 'Broken bar ¦', '/' => 'Slash /', '⁄' => 'Fraction slash ⁄',
			'-' => 'Hyphen -', '–' => 'En dash –', '—' => 'Em dash —', '−' => 'Minus −',
			':' => 'Colon :', '·' => 'Middle dot ·', '•' => 'Bullet •', '‧' => 'Hyphenation point ‧',
			'»' => 'Double angle »', '›' => 'Single angle ›', '>' => 'Greater than >', '→' => 'Arrow →',
			'➜' => 'Long arrow ➜', '⇒' => 'Double arrow ⇒', '⋅' => 'Dot operator ⋅', '~' => 'Tilde ~',
			'_' => 'Underscore _', ' ' => 'Space',
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
			return array_merge(
				array(
					'key'           => $key,
					'type'          => $type,
					'label'         => $label,
					'san'           => 'text',
					'required'      => false,
					'wide'          => false,
					'for'           => 'both',
					'mirror'        => array(),
					'person_mirror' => '',
					'fallback'      => array(),
					'help'          => '',
					'placeholder'   => '',
					'default'       => '',
				),
				$extra
			);
		};
		switch ( (int) $step ) {
			case 2:
				return array(
					$f(
						'publishrank_identity_type',
						'identity',
						__( 'Publisher type', 'publishrank' ),
						array(
							'san'      => 'sanitize_identity_type',
							'required' => true,
							'default'  => 'organization',
							'wide'     => true,
						)
					),
					$f(
						'publishrank_identity_name',
						'text',
						__( 'Publication / publisher name', 'publishrank' ),
						array(
							'required'      => true,
							'default'       => '',
							'mirror'        => array( 'publishrank_seo_publication_name', 'publishrank_seo_publisher_name' ),
							'person_mirror' => 'publishrank_identity_person_name',
							'fallback'      => array( 'publishrank_seo_publisher_name' ),
							'help'          => __( 'Used as the publisher name in schema, Open Graph and the News sitemap.', 'publishrank' ),
						)
					),
					$f(
						'publishrank_identity_alternate_name',
						'text',
						__( 'Short / alternate name', 'publishrank' ),
						array(
							'mirror'        => array( 'publishrank_publication_short_name' ),
							'person_mirror' => 'publishrank_identity_person_alternate_name',
							'fallback'      => array( 'publishrank_publication_short_name' ),
						)
					),
					$f(
						'publishrank_identity_alternate_names',
						'textarea',
						__( 'Organization name variants', 'publishrank' ),
						array(
							'for'  => 'org',
							'san'  => 'textarea',
							'wide' => true,
							'help' => __( 'Add one recognized publication or organization variant per line. These are emitted as Organization alternateName values.', 'publishrank' ),
						)
					),
					$f(
						'publishrank_identity_description',
						'textarea',
						__( 'Short description', 'publishrank' ),
						array(
							'wide'          => true,
							'san'           => 'textarea',
							'default'       => '',
							'mirror'        => array( 'publishrank_schema_org_description' ),
							'person_mirror' => 'publishrank_identity_person_description',
							'fallback'      => array( 'publishrank_schema_org_description' ),
							'placeholder'   => __( 'Independent news and analysis for…', 'publishrank' ),
						)
					),
					$f(
						'publishrank_identity_logo',
						'image',
						__( 'Publisher logo / photo', 'publishrank' ),
						array(
							'wide'          => true,
							'san'           => 'sanitize_public_url',
							'mirror'        => array( 'publishrank_seo_publisher_logo' ),
							'person_mirror' => 'publishrank_identity_person_image',
							'fallback'      => array( 'publishrank_seo_publisher_logo' ),
							'help'          => __( 'Square or wide logo, at least 112px tall. Used in schema and search knowledge surfaces.', 'publishrank' ),
						)
					),
					$f(
						'publishrank_seo_publisher_type',
						'select',
						__( 'Organization type', 'publishrank' ),
						array(
							'for'     => 'org',
							'san'     => 'sanitize_publisher_type',
							'default' => 'NewsMediaOrganization',
							'options' => array(
								'NewsMediaOrganization' => __( 'News media organization', 'publishrank' ),
								'Organization'          => __( 'Organization', 'publishrank' ),
							),
						)
					),
					$f(
						'publishrank_primary_url',
						'url',
						__( 'Primary website URL', 'publishrank' ),
						array(
							'san'     => 'sanitize_public_url',
							'default' => home_url( '/' ),
						)
					),
					$f(
						'publishrank_identity_country',
						'country',
						__( 'Country (ISO code)', 'publishrank' ),
						array(
							'san'         => 'country',
							'required'    => true,
							'mirror'      => array( 'publishrank_country' ),
							'fallback'    => array( 'publishrank_country' ),
							'placeholder' => 'PK',
							'help'        => __( 'Two-letter code, for example PK, US, GB.', 'publishrank' ),
						)
					),
					$f(
						'publishrank_default_language',
						'text',
						__( 'Language code', 'publishrank' ),
						array(
							'default'     => substr( (string) get_locale(), 0, 2 ),
							'placeholder' => 'en',
						)
					),
					$f(
						'publishrank_default_locale',
						'text',
						__( 'Locale', 'publishrank' ),
						array(
							'default'     => get_locale(),
							'placeholder' => 'en_US',
						)
					),
					$f( 'publishrank_timezone', 'timezone', __( 'Timezone', 'publishrank' ), array( 'default' => wp_timezone_string() ) ),
				);
			case 3:
				return array(
					$f(
						'publishrank_identity_legal_name',
						'text',
						__( 'Legal organization name', 'publishrank' ),
						array(
							'for'      => 'org',
							'mirror'   => array( 'publishrank_schema_legal_name' ),
							'fallback' => array( 'publishrank_schema_legal_name' ),
						)
					),
					$f(
						'publishrank_identity_founding_date',
						'text',
						__( 'Founding date', 'publishrank' ),
						array(
							'for'         => 'org',
							'san'         => 'sanitize_schema_date',
							'placeholder' => '2015 or 2015-03-14',
							'mirror'      => array( 'publishrank_schema_founding_date' ),
							'fallback'    => array( 'publishrank_schema_founding_date' ),
						)
					),
					$f(
						'publishrank_identity_contact_email',
						'email',
						__( 'Public contact email', 'publishrank' ),
						array(
							'san'           => 'email',
							'mirror'        => array( 'publishrank_contact' ),
							'person_mirror' => 'publishrank_identity_person_email',
							'fallback'      => array( 'publishrank_contact' ),
						)
					),
					$f(
						'publishrank_identity_contact_phone',
						'text',
						__( 'Public contact phone', 'publishrank' ),
						array(
							'for'      => 'org',
							'mirror'   => array( 'publishrank_schema_phone' ),
							'fallback' => array( 'publishrank_schema_phone' ),
						)
					),
					$f(
						'publishrank_identity_street',
						'text',
						__( 'Street address', 'publishrank' ),
						array(
							'for'      => 'org',
							'wide'     => true,
							'mirror'   => array( 'publishrank_schema_street_address' ),
							'fallback' => array( 'publishrank_schema_street_address' ),
						)
					),
					$f(
						'publishrank_identity_locality',
						'text',
						__( 'City / locality', 'publishrank' ),
						array(
							'for'      => 'org',
							'mirror'   => array( 'publishrank_schema_address_locality' ),
							'fallback' => array( 'publishrank_schema_address_locality' ),
						)
					),
					$f(
						'publishrank_identity_region',
						'text',
						__( 'State / region', 'publishrank' ),
						array(
							'for'      => 'org',
							'mirror'   => array( 'publishrank_schema_address_region' ),
							'fallback' => array( 'publishrank_schema_address_region' ),
						)
					),
					$f(
						'publishrank_identity_postal_code',
						'text',
						__( 'Postal code', 'publishrank' ),
						array(
							'for'      => 'org',
							'mirror'   => array( 'publishrank_schema_postal_code' ),
							'fallback' => array( 'publishrank_schema_postal_code' ),
						)
					),
					$f( 'publishrank_schema_founder', 'text', __( 'Founder', 'publishrank' ), array( 'for' => 'org' ) ),
					$f(
						'publishrank_identity_parent_organization',
						'text',
						__( 'Parent organization', 'publishrank' ),
						array(
							'for'      => 'org',
							'mirror'   => array( 'publishrank_schema_parent_organization' ),
							'fallback' => array( 'publishrank_schema_parent_organization' ),
						)
					),
					$f( 'publishrank_schema_founding_location', 'text', __( 'Founding location', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_copyright_holder', 'text', __( 'Copyright holder', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_copyright', 'textarea', __( 'Copyright notice', 'publishrank' ), array( 'wide' => true, 'san' => 'textarea', 'help' => __( 'Optional notice included with article structured data. Use %current_year% to keep the year current, for example: © %current_year% Your publication.', 'publishrank' ) ) ),
					$f( 'publishrank_identity_owner_name', 'text', __( 'Owner / rights holder', 'publishrank' ), array( 'for' => 'org' ) ),
					$f(
						'publishrank_identity_owner_url',
						'url',
						__( 'Owner URL', 'publishrank' ),
						array(
							'for' => 'org',
							'san' => 'sanitize_public_url',
						)
					),
					$f(
						'publishrank_identity_owner_logo',
						'image',
						__( 'Owner logo', 'publishrank' ),
						array(
							'for'  => 'org',
							'wide' => true,
							'san'  => 'sanitize_public_url',
						)
					),
					$f(
						'publishrank_schema_knows_about',
						'textarea',
						__( 'Topics / expertise (comma separated)', 'publishrank' ),
						array(
							'for'         => 'org',
							'wide'        => true,
							'san'         => 'textarea',
							'placeholder' => __( 'Investigative journalism, climate reporting, public policy', 'publishrank' ),
						)
					),
					$f( 'publishrank_identity_slogan', 'text', __( 'Slogan / tagline', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_employees', 'text', __( 'Number of employees', 'publishrank' ), array( 'for' => 'org', 'placeholder' => '25' ) ),
					$f( 'publishrank_identity_vat_id', 'text', __( 'VAT ID', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_tax_id', 'text', __( 'Tax ID / NTN', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_duns', 'text', __( 'DUNS number', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_lei_code', 'text', __( 'LEI code', 'publishrank' ), array( 'for' => 'org' ) ),
					$f( 'publishrank_identity_iso6523', 'text', __( 'ISO 6523 identifier', 'publishrank' ), array( 'for' => 'org', 'placeholder' => '0199:724500PMK2A2M1SQQ228' ) ),
					$f( 'publishrank_identity_naics', 'text', __( 'NAICS code', 'publishrank' ), array( 'for' => 'org', 'placeholder' => '513110' ) ),
					$f( 'publishrank_identity_editorial_email', 'text', __( 'Newsroom / editorial email', 'publishrank' ), array( 'for' => 'org', 'san' => 'email' ) ),
					$f( 'publishrank_identity_advertising_email', 'text', __( 'Advertising email', 'publishrank' ), array( 'for' => 'org', 'san' => 'email' ) ),
					$f( 'publishrank_identity_tips_email', 'text', __( 'News tips email', 'publishrank' ), array( 'for' => 'org', 'san' => 'email' ) ),
					$f( 'publishrank_identity_policy_publishing_principles', 'url', __( 'Publishing principles page URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_ethics', 'url', __( 'Ethics policy URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_corrections', 'url', __( 'Corrections policy URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_masthead', 'url', __( 'Masthead / team page URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_fact_checking', 'url', __( 'Fact-checking policy URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_unnamed_sources', 'url', __( 'Unnamed sources policy URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_feedback', 'url', __( 'Actionable feedback policy URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_diversity', 'url', __( 'Diversity policy URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_diversity_report', 'url', __( 'Diversity staffing report URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_ownership_funding', 'url', __( 'Ownership & funding info URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_no_bylines', 'url', __( 'No-bylines policy URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f( 'publishrank_identity_policy_mission_coverage', 'url', __( 'Mission & coverage priorities URL', 'publishrank' ), array( 'for' => 'org', 'san' => 'sanitize_public_url' ) ),
					$f(
						'publishrank_identity_person_job_title',
						'text',
						__( 'Job title', 'publishrank' ),
						array(
							'for'         => 'person',
							'placeholder' => 'Editor-in-Chief',
						)
					),
					$f(
						'publishrank_identity_person_url',
						'url',
						__( 'Profile URL', 'publishrank' ),
						array(
							'for'     => 'person',
							'san'     => 'sanitize_public_url',
							'default' => home_url( '/' ),
						)
					),
				);
			case 4:
				$fields = array();
				foreach ( PublicationProfile::social_networks() as $network => $data ) {
					$fields[] = $f(
						'publishrank_social_' . $network,
						'text',
						$data['label'],
						array(
							'san'         => 'sanitize_social_profile',
							'placeholder' => __( 'handle or full URL', 'publishrank' ),
						)
					);
				}
				$fields[] = $f(
					'publishrank_social_custom',
					'textarea',
					__( 'Other official profile URLs (one per line)', 'publishrank' ),
					array(
						'wide'        => true,
						'san'         => 'sanitize_social_custom',
						'placeholder' => 'https://mastodon.social/@yourpublication',
					)
				);
				$fields[] = $f(
					'publishrank_seo_twitter_site',
					'text',
					__( 'X/Twitter handle for cards', 'publishrank' ),
					array(
						'san'         => 'sanitize_handle',
						'placeholder' => 'yourpublication',
					)
				);
				$fields[] = $f(
					'publishrank_twitter_card',
					'select',
					__( 'Default X/Twitter card', 'publishrank' ),
					array(
						'san'     => 'sanitize_twitter_card',
						'default' => 'summary_large_image',
						'options' => array(
							'summary_large_image' => __( 'Summary with large image', 'publishrank' ),
							'summary'             => __( 'Summary', 'publishrank' ),
						),
					)
				);
				$fields[] = $f( 'publishrank_facebook_app_id', 'text', __( 'Facebook App ID (optional)', 'publishrank' ), array( 'san' => 'sanitize_verification' ) );
				$fields[] = $f(
					'publishrank_seo_default_social_image',
					'image',
					__( 'Default social sharing image', 'publishrank' ),
					array(
						'wide' => true,
						'san'  => 'sanitize_public_url',
						'help' => __( 'Used for Open Graph and X/Twitter when an article has no image. Recommended 1200×630.', 'publishrank' ),
					)
				);
				return $fields;
			case 5:
				$fields = array(
					$f(
						'publishrank_home_title',
						'text',
						__( 'Homepage SEO title', 'publishrank' ),
						array(
							'wide'        => true,
							'san'         => 'sanitize_home_text',
							'placeholder' => '%%sitename%% %%sep%% %%site_description%%',
							'help'        => __( 'Plain text, written by you. Type the exact homepage title you want, usually your brand plus a few words on what you cover, or insert variables from the list. The grey text is only an example and is not saved. Leave empty to use the site name.', 'publishrank' ),
						)
					),
					$f(
						'publishrank_home_description',
						'textarea',
						__( 'Homepage meta description', 'publishrank' ),
						array(
							'wide'        => true,
							'san'         => 'sanitize_home_text',
							'default'     => '',
							'placeholder' => __( 'Trusted reporting on the latest national and world news, politics, business, sport and culture.', 'publishrank' ),
							'help'        => __( 'WordPress has no excerpt for the homepage, so this dedicated field is what search engines and social networks use to describe it. Write one or two plain sentences (about 120 to 160 characters) saying what your site covers, as news brands do on their own homepages, and avoid repeating the title. The grey text is only an example and is not saved. Leave empty to use the front page\'s own SEO description (static front page) or your publication description.', 'publishrank' ),
						)
					),
					$f(
						'publishrank_seo_title_separator',
						'select',
						__( 'Title separator', 'publishrank' ),
						array(
							'default' => '|',
							'options' => self::title_separator_options(),
						)
					),
				);
				foreach ( array(
					'google'    => 'Google Search Console',
					'bing'      => 'Bing / Microsoft',
					'yandex'    => 'Yandex',
					'baidu'     => 'Baidu',
					'pinterest' => 'Pinterest',
				) as $engine => $label ) {
					$fields[] = $f(
						'publishrank_verification_' . $engine,
						'text',
						sprintf( /* translators: %s: search engine. */ __( '%s verification token', 'publishrank' ), $label ),
						array(
							'san'  => 'sanitize_verification',
							'help' => 'google' === $engine ? __( 'Paste only the content value of the verification meta tag.', 'publishrank' ) : '',
						)
					);
				}
				return $fields;
			case 6:
				return array(
					$f(
						'publishrank_seo_news_enabled',
						'toggle',
						__( 'Enable the Google News sitemap', 'publishrank' ),
						array(
							'san'     => 'bool',
							'default' => 1,
							'wide'    => true,
							'help'    => __( 'Recommended for publishers. Freshness window and limits stay in Settings.', 'publishrank' ),
						)
					),
					$f(
						'publishrank_seo_news_post_types',
						'posttypes',
						__( 'Post types in the News sitemap', 'publishrank' ),
						array(
							'san'     => 'sanitize_post_types',
							'default' => 'post',
							'wide'    => true,
						)
					),
					$f(
						'publishrank_sitemap_post_types',
						'posttypes',
						__( 'Post types in the standard sitemap', 'publishrank' ),
						array(
							'san'     => 'sanitize_post_types',
							'default' => 'post',
							'wide'    => true,
						)
					),
				);
			case 8:
				return array(
					$f(
						'publishrank_login_slug',
						'slug',
						__( 'Private login address', 'publishrank' ),
						array(
							'san'         => 'login_slug',
							'required'    => true,
							'wide'        => true,
							'default'     => 'login',
							'placeholder' => 'login',
							'help'        => __( 'Your sign-in page moves here. wp-login.php and wp-admin are hidden from visitors who are not signed in. Bookmark the new address.', 'publishrank' ),
						)
					),
					$f(
						'publishrank_login_redirect',
						'slug',
						__( 'Send blocked visitors to', 'publishrank' ),
						array(
							'san'         => 'redirect_slug',
							'wide'        => true,
							'default'     => '404',
							'placeholder' => '404',
							'help'        => __( 'Where visitors land if they open wp-login.php or wp-admin. Leave 404 to show your not-found page.', 'publishrank' ),
						)
					),
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
		$keep = array( 'publishrank_seo_news_enabled', 'publishrank_seo_news_post_types', 'publishrank_sitemap_post_types', 'publishrank_seo_title_separator', 'publishrank_login_slug', 'publishrank_login_redirect' ); // Also editable in Settings.
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
		add_submenu_page( Branding::MENU_SLUG, __( 'PublishRank Setup', 'publishrank' ), $label, \PublishRank_Newsroom::CAP_SETUP, self::SLUG, array( $this, 'page' ) );
	}

	/** Require the completion marker introduced with the verified setup flow. */
	private function is_setup_complete() {
		return (bool) get_option( 'publishrank_onboarding_complete', false )
			&& (string) get_option( 'publishrank_onboarding_completed_at', '' ) !== '';
	}

	/** True when PublishRank screens are locked to the wizard (fresh installs only). */
	public function is_locked() {
		return ! $this->is_setup_complete() && (bool) get_option( self::REQUIRED_OPTION, false ) && array() !== $this->missing_required();
	}

	/** Mark setup as done. Called when the last step is finished, or when the user exits with every required field saved. */
	private function complete_setup() {
		update_option( 'publishrank_onboarding_complete', 1 );
		update_option( 'publishrank_onboarding_version', Branding::version() );
		update_option( 'publishrank_onboarding_completed_at', gmdate( 'c' ) );
		delete_option( self::REQUIRED_OPTION );
		$this->bump_cache();
	}

	/** Current stored value for a field, honouring legacy fallbacks and defaults. */
	private function value( array $field ) {
		$value = get_option( $field['key'], '' );
		if ( '' === (string) $value ) {
			foreach ( (array) $field['fallback'] as $legacy ) {
				$value = get_option( $legacy, '' );
				if ( '' !== (string) $value ) {
					break; }
			}
		}
		return '' === (string) $value ? $field['default'] : $value;
	}

	/** Labels of required fields that are still empty. @return string[] */
	private function missing_required() {
		if ( null !== $this->missing_cache ) {
			return $this->missing_cache;
		}
		$missing = array();
		foreach ( array_keys( self::steps() ) as $step ) {
			foreach ( self::fields( $step ) as $field ) {
				if ( $field['required'] && '' === trim( (string) $this->value( $field ) ) ) {
					$missing[] = $field['label'];
				}
			}
		}
		$this->missing_cache = $missing;
		return $missing;
	}

	/** Whether a required field on a step other than $step is still empty. */
	private function required_missing_outside( $step ) {
		foreach ( array_keys( self::steps() ) as $other ) {
			if ( (int) $other === (int) $step ) {
				continue;
			}
			foreach ( self::fields( $other ) as $field ) {
				if ( $field['required'] && '' === trim( (string) $this->value( $field ) ) ) {
					return true;
				}
			}
		}
		return false;
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
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( \PublishRank_Newsroom::CAP_SETUP ) ) {
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
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( \PublishRank_Newsroom::CAP_SETUP ) || ! $this->is_locked() ) {
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
		if ( $this->is_setup_complete() || ! current_user_can( \PublishRank_Newsroom::CAP_SETUP ) ) {
			return;
		}
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_wizard = $screen && false !== strpos( (string) $screen->id, self::SLUG );
		if ( $is_wizard ) {
			return;
		}
		$icon       = Branding::logo_url();
		$wizard_url = Branding::page_url( self::SLUG );
		echo '<div class="notice notice-info prw-setup-notice"><div class="prw-setup-notice__inner">';
		echo '<img class="prw-setup-notice__logo" src="' . esc_url( $icon ) . '" alt="" width="44" height="44" />';
		echo '<div class="prw-setup-notice__copy"><strong>' . esc_html__( 'Finish setting up PublishRank', 'publishrank' ) . '</strong><p>' . esc_html__( 'Set up your publisher identity, social profiles, search appearance and content scope once in the setup wizard. PublishRank outputs your publisher details only after setup is completed.', 'publishrank' ) . '</p></div>';
		echo '<a href="' . esc_url( $wizard_url ) . '" class="button button-primary prw-setup-notice__button">' . esc_html__( 'Start setup', 'publishrank' ) . ' <span aria-hidden="true">→</span></a>';
		echo '</div></div>';
	}

	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page ) {
			return;
		}
		wp_enqueue_media();
		// Same frame styles as Features (modules.css + shell.css, enqueued by AdminShell), then the wizard layer on top.
		wp_enqueue_style( 'publishrank-seo-onboarding', Branding::asset_url( 'css/onboarding.css' ), array( 'dashicons', 'publishrank-seo-admin', 'publishrank-shell' ), AdminShell::ver( 'css/onboarding.css' ) );
		wp_enqueue_script( 'publishrank-seo-onboarding', Branding::asset_url( 'js/onboarding.js' ), array(), AdminShell::ver( 'js/onboarding.js' ), true );
		wp_localize_script(
			'publishrank-seo-onboarding',
			'publishrankWizard',
			array(
				'dirty'   => __( 'You have unsaved changes on this step', 'publishrank' ),
				'clean'   => __( 'Each step saves when you continue', 'publishrank' ),
				'saving'  => __( 'Saving…', 'publishrank' ),
				'confirm' => __( 'Leave this step? Changes you made here have not been saved yet.', 'publishrank' ),
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'previewNonce' => wp_create_nonce( 'publishrank_home_preview' ),
				'preview'      => array(
					'updating'   => __( 'Updating preview…', 'publishrank' ),
					'failed'     => __( 'The preview could not be updated. Your settings are not affected.', 'publishrank' ),
					'titleLong'  => __( 'Google will probably shorten this title. Put the most important words first.', 'publishrank' ),
					'titleFall'  => __( 'The title field is empty, so your site name is used.', 'publishrank' ),
					'descLong'   => __( 'This description is longer than Google usually shows, so the end may be cut off.', 'publishrank' ),
					'descShort'  => __( 'This description is quite short. About 120 to 160 characters usually reads best.', 'publishrank' ),
					'descNone'   => __( 'No description is set, so Google will choose its own text from your page.', 'publishrank' ),
					/* translators: %s: where the description comes from. */
					'descFrom'   => __( 'Description used: %s', 'publishrank' ),
				),
			)
		);
		\PublishRankSEO\Admin\VariablePicker::enqueue( '' );
	}

	/** Resolved homepage title and description for the Google-style preview, using the real resolvers and the real fallbacks. */
	public static function home_preview_data( $title_template, $description_template, $separator = null ) {
		$title_template       = \PublishRankSEO\Meta\TemplateResolver::sanitize_template( (string) $title_template, 320 );
		$description_template = \PublishRankSEO\Meta\TemplateResolver::sanitize_template( (string) $description_template, 320 );
		$filter               = null;
		if ( is_string( $separator ) && array_key_exists( $separator, self::title_separator_options() ) ) {
			// The separator on screen may not be saved yet: preview with the value the publisher has chosen.
			$filter = static function () use ( $separator ) {
				return $separator;
			};
			add_filter( 'pre_option_publishrank_seo_title_separator', $filter );
		}
		$meta  = new \PublishRankSEO\Meta\MetaGenerator();
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$name  = PublicationProfile::publication_name();
		$extra = array( 'title' => $name );

		$title        = $meta->resolve_template( $title_template, $front, 'title', $extra );
		$custom_title = '' !== $title;
		if ( ! $custom_title ) {
			$fallback = \PublishRankSEO\Meta\TemplateResolver::sanitize_template( (string) get_option( 'publishrank_seo_title_template_home', '%%sitename%% %%page%%' ), 300 );
			$title    = $meta->resolve_template( $fallback, $front, 'title', $extra );
		}
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 180 ) : substr( $title, 0, 180 );

		$limit       = \PublishRankSEO\Meta\SeoData::DESCRIPTION_LENGTH;
		$description = \PublishRankSEO\Meta\SeoData::clean( $meta->resolve_template( $description_template, $front, 'description' ) );
		$source      = 'homepage';
		if ( '' !== $description ) {
			$description = \PublishRankSEO\Meta\SeoData::truncate( $description, $limit );
		} else {
			if ( $front ) {
				$own = \PublishRankSEO\Meta\DescriptionResolver::for_post( $front, $limit );
				if ( '' !== $own['text'] && ! in_array( $own['source'], array( 'page_type', 'global' ), true ) ) {
					$description = $own['text'];
					$source      = $own['source'];
				}
			}
			if ( '' === $description ) {
				$global      = \PublishRankSEO\Meta\DescriptionResolver::global_result( $limit );
				$description = $global['text'];
				$source      = $global['source'];
			}
		}
		if ( $filter ) {
			remove_filter( 'pre_option_publishrank_seo_title_separator', $filter );
		}
		$labels = array(
			'homepage' => __( 'your homepage description', 'publishrank' ),
			'override' => __( 'the front page\'s own SEO description', 'publishrank' ),
			'excerpt'  => __( 'the front page excerpt', 'publishrank' ),
			'content'  => __( 'text taken from the front page content', 'publishrank' ),
			'builder'  => __( 'text taken from the front page content', 'publishrank' ),
			'global'   => __( 'your publication description (the homepage description is empty)', 'publishrank' ),
			'none'     => '',
		);
		return array(
			'title'          => $title,
			'title_custom'   => $custom_title,
			'description'    => $description,
			'source'         => $source,
			'source_label'   => isset( $labels[ $source ] ) ? $labels[ $source ] : '',
			'site_name'      => $name,
			'url'            => home_url( '/' ),
			'favicon'        => (string) get_site_icon_url( 32 ),
		);
	}

	/** AJAX: Google-style homepage preview from the unsaved field values. Read-only; nothing is saved. */
	public function ajax_home_preview() {
		check_ajax_referer( 'publishrank_home_preview', 'nonce' );
		if ( ! current_user_can( \PublishRank_Newsroom::CAP_SETUP ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
		$title = isset( $_POST['title'] ) ? (string) wp_unslash( $_POST['title'] ) : '';
		$desc  = isset( $_POST['description'] ) ? (string) wp_unslash( $_POST['description'] ) : '';
		$sep   = isset( $_POST['sep'] ) ? (string) wp_unslash( $_POST['sep'] ) : null;
		// phpcs:enable
		wp_send_json_success( self::home_preview_data( $title, $desc, $sep ) );
	}

	/** The Google-style preview card; filled in by assets/js/onboarding.js from the form's current values. */
	private function render_home_preview() {
		echo '<section class="prw-serp" data-prw-serp aria-labelledby="prw-serp-heading">';
		echo '<div class="prw-serp__head"><h3 id="prw-serp-heading">' . esc_html__( 'How your homepage looks in Google', 'publishrank' ) . '</h3>';
		echo '<div class="prw-serp__switch" role="group" aria-label="' . esc_attr__( 'Preview size', 'publishrank' ) . '"><button type="button" class="is-active" data-prw-serp-device="desktop" aria-pressed="true">' . esc_html__( 'Desktop', 'publishrank' ) . '</button><button type="button" data-prw-serp-device="mobile" aria-pressed="false">' . esc_html__( 'Mobile', 'publishrank' ) . '</button></div></div>';
		echo '<div class="prw-serp__card" data-prw-serp-card aria-live="polite"><div class="prw-serp__top"><span class="prw-serp__fav" data-prw-serp-fav aria-hidden="true"></span><div class="prw-serp__who"><div class="prw-serp__site" data-prw-serp-site></div><div class="prw-serp__url" data-prw-serp-url></div></div></div><div class="prw-serp__title" data-prw-serp-title></div><div class="prw-serp__desc" data-prw-serp-desc></div></div>';
		echo '<ul class="prw-serp__notes" data-prw-serp-notes></ul>';
		echo '<p class="prw-help">' . esc_html__( 'This is an estimate built from your fields as you type. Google may still rewrite titles and descriptions, and shows the title and description you set only when they match the page well.', 'publishrank' ) . '</p>';
		echo '</section>';
	}

	/** Leave the wizard without completing it. Not available while the wizard is locked. */
	public function exit_wizard() {
		AdminAssets::guard( 'publishrank_onboarding_exit', \PublishRank_Newsroom::CAP_SETUP );
		if ( array() !== $this->missing_required() ) {
			wp_safe_redirect( Branding::page_url( self::SLUG ) . '&step=2&error=required' );
			exit;
		}
		if ( ! $this->is_setup_complete() ) {
			$this->complete_setup();
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
		$conflict = implode( ', ', ( new \PublishRankSEO\Compatibility\ConflictDetector() )->active() );
		$uploads = wp_upload_dir();
		return array(
			/* translators: %s: dynamic value identified by the surrounding label or sentence. */
			'wp'       => array(
				'ok'    => version_compare( $wp_version, '6.3', '>=' ),
				/* translators: %s: detected WordPress version. */
				'label' => sprintf( __( 'WordPress %s (6.3+ required)', 'publishrank' ), $wp_version ),
			),
			/* translators: %s: dynamic value identified by the surrounding label or sentence. */
			'php'      => array(
				'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
				/* translators: %s: detected PHP version. */
				'label' => sprintf( __( 'PHP %s (7.4+ required)', 'publishrank' ), PHP_VERSION ),
			),
			'ssl'      => array(
				'ok'    => is_ssl(),
				'label' => __( 'HTTPS active', 'publishrank' ),
			),
			'perm'     => array(
				'ok'    => get_option( 'permalink_structure' ) !== '',
				'label' => __( 'Pretty permalinks enabled', 'publishrank' ),
				'fix'   => admin_url( 'options-permalink.php' ),
			),
			/* translators: %s: name of the detected SEO plugin. */
			'conflict' => array(
				'ok'    => '' === $conflict,
				/* translators: %s: name of the conflicting SEO plugin. */
				'label' => $conflict ? sprintf( __( '⚠️ Another SEO plugin is active: %s. Running two SEO plugins can print duplicate titles, canonicals and schema.', 'publishrank' ), $conflict ) : __( 'No conflicting SEO plugin detected', 'publishrank' ),
				'fix'   => admin_url( 'plugins.php' ),
			),
			'uploads'  => array(
				'ok'    => empty( $uploads['error'] ),
				'label' => empty( $uploads['error'] ) ? __( 'Uploads directory writable', 'publishrank' ) : $uploads['error'],
			),
		);
	}

	/** Restore conservative recommended defaults and reopen the wizard. */
	public function restore_defaults() {
		AdminAssets::guard( 'publishrank_onboarding_defaults', \PublishRank_Newsroom::CAP_SETUP );
		$defaults = array(
			'publishrank_identity_type'           => 'organization',
			'publishrank_identity_name'           => '',
			'publishrank_identity_description'    => '',
			'publishrank_seo_publication_name'    => '',
			'publishrank_seo_publisher_name'      => '',
			'publishrank_seo_publisher_logo'      => '',
			'publishrank_identity_logo'           => '',
			'publishrank_home_title'              => '',
			'publishrank_home_description'        => '',
			'publishrank_seo_default_robots'      => 'index,follow',
			'publishrank_seo_title_template_home' => '%%sitename%% %%page%%',
			'publishrank_seo_title_separator'     => '|',
			'publishrank_seo_news_enabled'        => 1,
			'publishrank_seo_news_window'         => 48,
			'publishrank_seo_news_keywords'       => 1,
				'publishrank_sitemap_stylesheet'      => 1,
				'publishrank_breadcrumbs_enabled'     => 1,
				'publishrank_breadcrumb_schema_enabled' => 1,
				'publishrank_meta_enabled'            => 1,
			'publishrank_schema_enabled'          => 1,
			'publishrank_org_website_schema_enabled' => 1,
			'publishrank_local_seo_profile'       => array(
				'enabled'       => 0,
				'name'          => '',
				'type'          => 'LocalBusiness',
				'description'   => '',
				'url'           => '',
				'phone'         => '',
				'email'         => '',
				'logo'          => '',
				'street'        => '',
				'city'          => '',
				'region'        => '',
				'postal_code'   => '',
				'country'       => '',
				'latitude'      => '',
				'longitude'     => '',
				'price_range'   => '',
				'has_map'       => '',
				'service_area'  => '',
				'opening_hours' => array(),
				'same_as'       => array(),
			),
		);
		foreach ( $defaults as $key => $value ) {
			update_option( $key, $value ); }
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
			case 'text':
				return sanitize_text_field( (string) $raw );
			case 'textarea':
				return sanitize_textarea_field( (string) $raw );
			case 'email':
				return sanitize_email( (string) $raw );
			case 'bool':
				return empty( $raw ) ? 0 : 1;
			case 'country':
				$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $raw ) );
				return 2 === strlen( $code ) ? $code : '';
			case 'login_slug':
				return \PublishRankSEO\Security\PrivateLogin::clean_login_slug( (string) $raw );
			case 'redirect_slug':
				return \PublishRankSEO\Security\PrivateLogin::clean_redirect_slug( (string) $raw );
		}
		return method_exists( $settings, $san ) ? $settings->{$san}( $raw ) : sanitize_text_field( (string) $raw );
	}

	public function save() {
		AdminAssets::guard( 'publishrank_onboarding_save', \PublishRank_Newsroom::CAP_SETUP );
		check_admin_referer( 'publishrank_onboarding_save' ); // Explicit nonce verification for the request data read below.
		$step     = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$step     = max( 1, min( self::TOTAL_STEPS, $step ) );
		$edit     = ! empty( $_POST['edit_mode'] ) ? '&edit=1' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$settings = new SettingsPage();
		$identity = PublicationProfile::identity_type();
		$errors   = array();
		$slug_error = false;

		if ( 2 === $step && isset( $_POST['publishrank_identity_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$identity = $settings->sanitize_identity_type( sanitize_text_field( wp_unslash( $_POST['publishrank_identity_type'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$is_person = 'person' === $identity;

		foreach ( self::fields( $step ) as $field ) {
			if ( ( 'org' === $field['for'] && $is_person ) || ( 'person' === $field['for'] && ! $is_person ) ) {
				continue;
			}
			$raw   = isset( $_POST[ $field['key'] ] ) ? map_deep( wp_unslash( $_POST[ $field['key'] ] ), 'sanitize_textarea_field' ) : ( 'toggle' === $field['type'] ? 0 : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$clean = $this->clean( $field, $raw, $settings );
			if ( 'publishrank_identity_person_url' === $field['key'] && '' === $clean ) {
				$clean = esc_url_raw( home_url( '/' ) ); }
			if ( 'publishrank_login_slug' === $field['key'] && '' === $clean ) {
				// Empty or reserved by WordPress: keep the address already in use and ask again.
				$slug_error = true;
				$errors[]   = $field['label'];
				continue;
			}
			if ( $field['required'] && '' === trim( (string) $clean ) ) {
				$errors[] = $field['label']; }
			update_option( $field['key'], $clean );
			foreach ( (array) $field['mirror'] as $legacy ) {
				update_option( $legacy, $clean ); }
			if ( $is_person && $field['person_mirror'] ) {
				update_option( $field['person_mirror'], $clean ); }
		}

		// Saving the Security step is the moment the owner has seen the new address: Private Login may now switch on.
		if ( 8 === $step && ! $slug_error ) {
			\PublishRankSEO\Security\PrivateLogin::confirm_login_address();
		}

		if ( 7 === $step ) {
			$local_input = isset( $_POST['local_seo'] ) && is_array( $_POST['local_seo'] ) ? map_deep( wp_unslash( $_POST['local_seo'] ), 'sanitize_textarea_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_option( 'publishrank_local_seo_profile', \PublishRankSEO\SEO\LocalSEO::sanitize_profile( $local_input ) );
		}

		// Back saves what was typed, then returns to the previous step without blocking on required fields.
		$back = isset( $_POST['prw_nav'] ) && 'back' === sanitize_key( wp_unslash( $_POST['prw_nav'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $back ) {
			$this->bump_cache();
			wp_safe_redirect( Branding::page_url( self::SLUG ) . '&step=' . max( 1, $step - 1 ) . $edit );
			exit;
		}

		$err_kind = $slug_error ? 'slug' : 'required';

		// Save & exit: whatever was typed on this step is already stored above. Leave as soon as every
		// required field is filled in; the rest of the wizard can be finished or edited later.
		$exit = isset( $_POST['prw_nav'] ) && 'exit' === sanitize_key( wp_unslash( $_POST['prw_nav'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $exit ) {
			$this->bump_cache();
			if ( $errors ) {
				wp_safe_redirect( Branding::page_url( self::SLUG ) . '&step=' . $step . '&error=' . $err_kind . $edit );
				exit;
			}
			if ( array() !== $this->missing_required() ) {
				wp_safe_redirect( Branding::page_url( self::SLUG ) . '&step=2&error=required' . $edit );
				exit;
			}
			if ( ! $this->is_setup_complete() ) {
				$this->complete_setup();
			}
			wp_safe_redirect( Branding::page_url( Branding::MENU_SLUG ) );
			exit;
		}

		$next = min( self::TOTAL_STEPS, $step + 1 );
		if ( $errors ) {
			$next = $step; // Stay here until the required fields are filled in.
		}

		if ( self::TOTAL_STEPS === $step ) {
			$missing = $this->missing_required();
			if ( $missing ) {
				$this->bump_cache();
				wp_safe_redirect( Branding::page_url( self::SLUG ) . '&step=2&error=required' . $edit );
				exit;
			}
			$this->complete_setup();
			wp_safe_redirect( Branding::page_url( self::SLUG ) . '&done=1' );
			exit;
		}

		$this->bump_cache();
		$query = '&step=' . $next . ( $errors ? '&error=' . $err_kind : '&saved=1' ) . $edit;
		wp_safe_redirect( Branding::page_url( self::SLUG ) . $query );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	/**
	 * Progress shown on the step track: 0 on the first step, 100 on the last.
	 *
	 * The track runs from the centre of the first dot to the centre of the last, so the
	 * fill must use (step - 1) / (total - 1); step / total overshoots every dot.
	 *
	 * @param int $step Step number.
	 * @return int Percentage 0–100.
	 */
	public static function progress_percent( $step ) {
		$step = max( 1, min( self::TOTAL_STEPS, (int) $step ) );
		return (int) round( ( ( $step - 1 ) / max( 1, self::TOTAL_STEPS - 1 ) ) * 100 );
	}

	/** Steps that have no required fields and may be skipped. @return int[] */
	public static function optional_steps() {
		return array( 3, 4, 5, 6, 7 );
	}

	/** Render a single field. */
	private function render_field( array $field, $is_person ) {
		$applies = ! ( ( 'org' === $field['for'] && $is_person ) || ( 'person' === $field['for'] && ! $is_person ) );
		$key     = $field['key'];
		$value   = $this->value( $field );
		$id      = 'prw_' . $key;
		$show_validation_error = isset( $_GET['error'] ) && ! is_array( $_GET['error'] ) && 'required' === sanitize_key( wp_unslash( $_GET['error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$invalid   = $applies && $show_validation_error && $field['required'] && '' === trim( (string) $value );
		$described = array();
		if ( $field['help'] ) {
			$described[] = 'prw_help_' . $key;
		}
		if ( $invalid ) {
			$described[] = 'prw_error_' . $key;
		}
		$req = $field['required'] ? ' required aria-required="true"' : '';
		if ( $invalid ) {
			$req .= ' aria-invalid="true"';
		}
		if ( $described ) {
			$req .= ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"';
		}
		if ( ! $applies ) {
			// Kept in the page so switching Organization / Person updates the form instantly; disabled
			// controls are neither validated nor submitted, and save() ignores them anyway.
			$req .= ' disabled';
		}
		$class = 'prw-field' . ( $field['wide'] ? ' prw-field--wide' : '' ) . ( 'toggle' === $field['type'] ? ' prw-field--toggle' : '' ) . ( $invalid ? ' is-invalid' : '' );
		$attrs = 'both' !== $field['for'] ? ' data-prw-for="' . esc_attr( (string) $field['for'] ) . '"' . ( $applies ? '' : ' hidden' ) : '';
		echo '<div class="' . esc_attr( $class ) . '"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		if ( 'identity' === $field['type'] ) {
			echo '<span class="prw-label" id="' . esc_attr( $id . '_label' ) . '">' . esc_html( $field['label'] ) . ' <em class="prw-req" aria-hidden="true">*</em></span><div class="prw-choices" role="radiogroup" aria-labelledby="' . esc_attr( $id . '_label' ) . '" aria-required="true">';
			foreach ( array(
				'organization' => array( __( 'Organization', 'publishrank' ), __( 'Newspapers, magazines, newsrooms and digital publishers.', 'publishrank' ), 'fa-building' ),
				'person'       => array( __( 'Person', 'publishrank' ), __( 'An individual publisher, journalist or creator-led site.', 'publishrank' ), 'fa-user-pen' ),
			) as $option => $copy ) {
				echo '<label class="prw-choice"><input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $option ) . '"' . checked( $value, $option, false ) . ' data-prw-identity /><span class="prw-choice__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $copy[2] ) . '"></i></span><span class="prw-choice__text"><b>' . esc_html( $copy[0] ) . '</b><small>' . esc_html( $copy[1] ) . '</small></span><span class="prw-choice__check" aria-hidden="true"><i class="fa-solid fa-check"></i></span></label>';
			}
			echo '</div>';
		} elseif ( 'toggle' === $field['type'] ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="0" /><label class="prw-switch-row" for="' . esc_attr( $id ) . '"><span class="prw-switch-row__text"><b>' . esc_html( $field['label'] ) . '</b>' . ( $field['help'] ? '<small>' . esc_html( $field['help'] ) . '</small>' : '' ) . '</span><span class="pr-switch"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( (bool) $value, true, false ) . ' /><span class="pr-switch__track"><span class="pr-switch__thumb"></span></span></span></label>';
		} else {
			if ( 'posttypes' === $field['type'] ) {
				echo '<span class="prw-label" id="' . esc_attr( $id . '_label' ) . '">' . esc_html( $field['label'] ) . '</span>';
			} else {
				echo '<label class="prw-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . ( $field['required'] ? ' <em class="prw-req" aria-hidden="true">*</em><span class="screen-reader-text">' . esc_html__( '(required)', 'publishrank' ) . '</span>' : '' ) . '</label>';
			}
			switch ( $field['type'] ) {
				case 'textarea':
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" rows="3" placeholder="' . esc_attr( $field['placeholder'] ) . '"' . $req . '>' . esc_textarea( (string) $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput
					if ( 'publishrank_home_description' === $key ) {
						echo \PublishRankSEO\Admin\VariablePicker::slot( $id, 'description', 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in slot().
						echo '<small class="prw-count" data-prw-count-for="' . esc_attr( $id ) . '" data-prw-count-min="120" data-prw-count-max="160" aria-live="polite"></small>';
					}
					break;
				case 'select':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"' . $req . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
					foreach ( (array) $field['options'] as $option => $label ) {
						echo '<option value="' . esc_attr( $option ) . '"' . selected( (string) $value, (string) $option, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
					break;
				case 'timezone':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"' . $req . '>' . wp_timezone_choice( (string) $value ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				case 'image':
					$thumb = '' !== (string) $value ? '<img src="' . esc_url( (string) $value ) . '" alt="" loading="lazy" />' : '';
					echo '<div class="prw-media"><span class="prw-media__thumb' . ( '' === $thumb ? ' is-empty' : '' ) . '" data-prw-thumb aria-hidden="true">' . $thumb . '<i class="fa-solid fa-image"></i></span><span class="prw-media__ctrl"><input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="https://" inputmode="url" data-prw-media-input' . $req . ' /><button type="button" class="button rhs-media-button prw-media__button" data-target="' . esc_attr( $id ) . '"' . ( $applies ? '' : ' disabled' ) . '><i class="fa-solid fa-photo-film" aria-hidden="true"></i> ' . esc_html__( 'Choose image', 'publishrank' ) . '</button></span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				case 'country':
					echo '<input type="text" class="prw-input--country" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" maxlength="2" pattern="[A-Za-z]{2}" placeholder="' . esc_attr( $field['placeholder'] ) . '" autocomplete="country"' . $req . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				case 'posttypes':
					$selected = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', (string) $value ) ) );
					echo '<div class="prw-chips" role="group" aria-labelledby="' . esc_attr( $id . '_label' ) . '"' . ( $field['help'] ? ' aria-describedby="' . esc_attr( 'prw_help_' . $key ) . '"' : '' ) . '>';
					foreach ( get_post_types(
						array(
							'public'             => true,
							'publicly_queryable' => true,
						),
						'objects'
					) as $type => $object ) {
						if ( 'attachment' === $type ) {
							continue; }
						echo '<label class="prw-chip"><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $type ) . '"' . checked( in_array( $type, $selected, true ), true, false ) . ( $applies ? '' : ' disabled' ) . ' /><span><i class="fa-solid fa-check" aria-hidden="true"></i>' . esc_html( $object->labels->name ) . '</span></label>';
					}
					echo '</div>';
					break;
				case 'slug':
					$pretty = (bool) get_option( 'permalink_structure' );
					$prefix = trailingslashit( home_url() ) . ( $pretty ? '' : '?' );
					$tail   = ( $pretty && '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ) ? '/' : '';
					echo '<div class="prw-slug"><code class="prw-slug__prefix">' . esc_html( $prefix ) . '</code><input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" autocomplete="off" autocapitalize="off" spellcheck="false"' . $req . ' />' . ( '' !== $tail ? '<code class="prw-slug__suffix">/</code>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				default: // text, url, email.
					$input = in_array( $field['type'], array( 'url', 'email' ), true ) ? $field['type'] : 'text';
					echo '<input type="' . esc_attr( $input ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '"' . $req . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
					if ( 'publishrank_home_title' === $key ) {
						echo \PublishRankSEO\Admin\VariablePicker::slot( $id, 'title', 'home' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in slot().
					}
			}
			if ( $field['help'] ) {
				echo '<small class="prw-help" id="' . esc_attr( 'prw_help_' . $key ) . '">' . esc_html( $field['help'] ) . '</small>';
			}
			if ( $invalid ) {
				echo '<small class="prw-field-error" id="' . esc_attr( 'prw_error_' . $key ) . '"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> ' . esc_html__( 'This field is required.', 'publishrank' ) . '</small>';
			}
		}
		echo '</div>';
	}

	/** Resolve the saved homepage SEO title for administrative summaries. */
	public static function homepage_title_preview() {
		$template = (string) get_option( 'publishrank_home_title', '' );
		if ( '' === trim( $template ) ) {
			$template = (string) get_option( 'publishrank_seo_title_template_home', '%%sitename%% %%page%%' );
		}
		$template = \PublishRankSEO\Meta\TemplateResolver::sanitize_template( $template );
		$resolved = ( new \PublishRankSEO\Meta\MetaGenerator() )->resolve_template( $template, 0, 'title', array( 'title' => PublicationProfile::publication_name() ) );
		return '' !== $resolved ? $resolved : __( 'Not configured', 'publishrank' );
	}

	/**
	 * Summary tiles for the review step and the completed view, each linking to the step that sets it.
	 *
	 * @param string $edit_base Base URL for the per-tile Edit links ('' hides them).
	 */
	private function recap( $edit_base = '' ) {
		$name    = (string) get_option( 'publishrank_identity_name', PublicationProfile::publication_name() );
		$title   = self::homepage_title_preview();
		$local   = get_option( 'publishrank_local_seo_profile', array() );
		$local   = is_array( $local ) ? $local : array();
		$news    = (bool) get_option( 'publishrank_seo_news_enabled', true );
		$country = strtoupper( (string) get_option( 'publishrank_identity_country', '' ) );
		$social  = count( PublicationProfile::social_profiles() );
		$items   = array(
			array( 2, 'fa-building', 'person' === PublicationProfile::identity_type() ? __( 'Publisher · person', 'publishrank' ) : __( 'Publisher · organization', 'publishrank' ), '' !== $name ? $name : PublicationProfile::publication_name(), '' !== $name ),
			array( 2, 'fa-earth-europe', __( 'Country', 'publishrank' ), '' !== $country ? $country : __( 'Not set', 'publishrank' ), '' !== $country ),
			/* translators: %d: number of social profiles. */
			array( 4, 'fa-share-nodes', __( 'Social profiles', 'publishrank' ), sprintf( _n( '%d profile', '%d profiles', $social, 'publishrank' ), $social ), $social > 0 ),
			array( 5, 'fa-heading', __( 'Homepage SEO title', 'publishrank' ), $title, '' !== trim( (string) get_option( 'publishrank_home_title', '' ) ) ),
			array( 6, 'fa-sitemap', __( 'Sitemaps', 'publishrank' ), ( $news ? __( 'News sitemap on', 'publishrank' ) : __( 'News sitemap off', 'publishrank' ) ) . ' · ' . str_replace( ',', ', ', (string) get_option( 'publishrank_seo_news_post_types', 'post' ) ), $news ),
			array( 7, 'fa-location-dot', __( 'Local business', 'publishrank' ), ! empty( $local['enabled'] ) ? ( ! empty( $local['name'] ) ? (string) $local['name'] : __( 'Local business on', 'publishrank' ) ) : __( 'Local SEO off', 'publishrank' ), ! empty( $local['enabled'] ) ),
			array( 8, 'fa-user-shield', __( 'Private login address', 'publishrank' ), \PublishRankSEO\Security\PrivateLogin::enabled() ? '/' . \PublishRankSEO\Security\PrivateLogin::current_login_slug() : __( 'Private Login is off', 'publishrank' ), \PublishRankSEO\Security\PrivateLogin::enabled() ),
		);
		echo '<ul class="prw-recap">';
		foreach ( $items as $item ) {
			echo '<li class="prw-recap__item' . ( $item[4] ? ' is-set' : '' ) . '"><span class="prw-recap__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $item[1] ) . '"></i></span><span class="prw-recap__body"><span class="prw-recap__label">' . esc_html( (string) $item[2] ) . '</span><strong class="prw-recap__value">' . esc_html( (string) $item[3] ) . '</strong></span>';
			if ( '' !== $edit_base ) {
				/* translators: %s: summary item label. */
				echo '<a class="prw-recap__edit" href="' . esc_url( $edit_base . '&step=' . (int) $item[0] ) . '" aria-label="' . esc_attr( sprintf( __( 'Edit %s', 'publishrank' ), (string) $item[2] ) ) . '"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/** The optional Local SEO step. */
	private function render_local_step() {
		$local = get_option( 'publishrank_local_seo_profile', array() );
		$local = wp_parse_args(
			is_array( $local ) ? $local : array(),
			array(
				'enabled'       => 0,
				'name'          => '',
				'type'          => 'LocalBusiness',
				'description'   => '',
				'url'           => home_url( '/' ),
				'phone'         => '',
				'email'         => '',
				'logo'          => '',
				'street'        => '',
				'city'          => '',
				'region'        => '',
				'postal_code'   => '',
				'country'       => '',
				'latitude'      => '',
				'longitude'     => '',
				'price_range'   => '',
				'has_map'       => '',
				'service_area'  => '',
				'opening_hours' => array(),
				'same_as'       => array(),
			)
		);
		echo '<div class="prw-fields">';
		echo '<div class="prw-field prw-field--wide prw-field--toggle"><input type="hidden" name="local_seo[enabled]" value="0" /><label class="prw-switch-row" for="prw_local_enabled"><span class="prw-switch-row__text"><b>' . esc_html__( 'Enable LocalBusiness schema', 'publishrank' ) . '</b><small>' . esc_html__( 'Leave off for a purely digital publication without a real public business location.', 'publishrank' ) . '</small></span><span class="pr-switch"><input id="prw_local_enabled" type="checkbox" name="local_seo[enabled]" value="1"' . checked( ! empty( $local['enabled'] ), true, false ) . ' data-prw-local-toggle /><span class="pr-switch__track"><span class="pr-switch__thumb"></span></span></span></label></div>';
		echo '<div class="prw-fields prw-field--wide prw-local-fields" data-prw-local-fields>';
		$text = array(
			'name'         => __( 'Business / location name', 'publishrank' ),
			'url'          => __( 'Business URL', 'publishrank' ),
			'phone'        => __( 'Business phone', 'publishrank' ),
			'email'        => __( 'Business email', 'publishrank' ),
			'logo'         => __( 'Business logo / image URL', 'publishrank' ),
			'street'       => __( 'Street address', 'publishrank' ),
			'city'         => __( 'City / locality', 'publishrank' ),
			'region'       => __( 'Region', 'publishrank' ),
			'postal_code'  => __( 'Postal code', 'publishrank' ),
			'country'      => __( 'Country', 'publishrank' ),
			'latitude'     => __( 'Latitude', 'publishrank' ),
			'longitude'    => __( 'Longitude', 'publishrank' ),
			'service_area' => __( 'Service area', 'publishrank' ),
			'has_map'      => __( 'Map URL', 'publishrank' ),
			'price_range'  => __( 'Price range (if applicable)', 'publishrank' ),
		);
		echo '<div class="prw-field"><label class="prw-label" for="prw_local_type">' . esc_html__( 'Business type', 'publishrank' ) . '</label><select id="prw_local_type" name="local_seo[type]">';
		foreach ( \PublishRankSEO\SEO\LocalSEO::types() as $type => $label ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $local['type'], $type, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></div>';
		foreach ( $text as $key => $label ) {
			$kind = in_array( $key, array( 'url', 'logo', 'has_map' ), true ) ? 'url' : ( 'email' === $key ? 'email' : ( in_array( $key, array( 'latitude', 'longitude' ), true ) ? 'number' : 'text' ) );
			echo '<div class="prw-field"><label class="prw-label" for="prw_local_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input id="prw_local_' . esc_attr( $key ) . '" type="' . esc_attr( $kind ) . '"' . ( 'number' === $kind ? ' step="any"' : '' ) . ' name="local_seo[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $local[ $key ] ) . '" /></div>';
		}
		echo '<div class="prw-field prw-field--wide"><label class="prw-label" for="prw_local_hours">' . esc_html__( 'Opening hours', 'publishrank' ) . '</label><textarea id="prw_local_hours" name="local_seo[opening_hours]" rows="2" aria-describedby="prw_local_hours_help">' . esc_textarea( is_array( $local['opening_hours'] ) ? implode( "\n", $local['opening_hours'] ) : '' ) . '</textarea><small class="prw-help" id="prw_local_hours_help">' . esc_html__( 'Example: Mo-Fr 09:00-17:00', 'publishrank' ) . '</small></div>';
		echo '<div class="prw-field prw-field--wide"><label class="prw-label" for="prw_local_same">' . esc_html__( 'Official profile URLs (one per line)', 'publishrank' ) . '</label><textarea id="prw_local_same" name="local_seo[same_as]" rows="2">' . esc_textarea( is_array( $local['same_as'] ) ? implode( "\n", $local['same_as'] ) : '' ) . '</textarea></div>';
		echo '</div></div>';
	}

	/** Shared intro block at the top of the main column (same markup as framed tool screens). */
	private static function intro( $kicker, $title, $lead, $icon, $badge = '' ) {
		echo '<div class="pr-modules-intro pr-shell-intro prw-intro"><div class="pr-shell-intro__text">';
		echo '<p class="pr-shell-kicker">' . esc_html( $kicker ) . '</p>';
		echo '<h2 class="pr-modules-title" id="prw-step-title" tabindex="-1">' . esc_html( $title ) . '</h2>';
		echo '<p class="pr-modules-lead">' . esc_html( $lead ) . '</p></div>';
		if ( '' !== $badge ) {
			echo '<span class="pr-modules-count prw-intro__badge">' . esc_html( $badge ) . '</span>';
		} else {
			echo '<span class="pr-shell-intro__icon" aria-hidden="true"><i class="' . esc_attr( $icon ) . '"></i></span>';
		}
		echo '</div>';
	}

	/** Step track: one dot per step, finished steps link back, the fill animates between steps. */
	private function render_track( $step, $self, $edit_qs, $navigable_all ) {
		$steps    = self::steps();
		$fraction = ( $step - 1 ) / max( 1, self::TOTAL_STEPS - 1 );
		echo '<div class="prw-progress">';
		echo '<div class="prw-progress__meta"><span class="prw-progress__count">' . esc_html( sprintf( /* translators: 1: step, 2: total. */ __( 'Step %1$d of %2$d', 'publishrank' ), $step, self::TOTAL_STEPS ) ) . '</span><span class="prw-progress__name">' . esc_html( $steps[ $step ]['label'] ) . '</span></div>';
		echo '<ol class="prw-steps" role="list" aria-label="' . esc_attr__( 'Setup progress', 'publishrank' ) . '" style="--prw-steps:' . (int) self::TOTAL_STEPS . ';--prw-progress:' . esc_attr( number_format( $fraction, 4, '.', '' ) ) . '" data-prw-progress="' . esc_attr( number_format( $fraction, 4, '.', '' ) ) . '">';
		foreach ( $steps as $number => $data ) {
			$state = $number < $step ? 'is-done' : ( $number === $step ? 'is-active' : 'is-todo' );
			$inner = '<span class="prw-steps__dot"><i class="' . esc_attr( $data['icon'] ) . '" aria-hidden="true"></i><span class="prw-steps__num" aria-hidden="true">' . (int) $number . '</span></span><span class="prw-steps__label">' . esc_html( $data['label'] ) . '</span>';
			$status = 'is-done' === $state ? __( 'completed', 'publishrank' ) : ( 'is-active' === $state ? __( 'current step', 'publishrank' ) : __( 'not started', 'publishrank' ) );
			$sr     = '<span class="screen-reader-text">' . esc_html( sprintf( /* translators: 1: step number, 2: status. */ __( 'Step %1$d, %2$s', 'publishrank' ), $number, $status ) ) . '</span>';
			echo '<li class="prw-steps__item ' . esc_attr( $state ) . '">';
			if ( 'is-active' === $state ) {
				echo '<span class="prw-steps__link" aria-current="step">' . $inner . $sr . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			} elseif ( 'is-done' === $state || $navigable_all ) {
				echo '<a class="prw-steps__link" href="' . esc_url( $self . '&step=' . (int) $number . $edit_qs ) . '">' . $inner . $sr . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			} else {
				echo '<span class="prw-steps__link">' . $inner . $sr . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}
			echo '</li>';
		}
		echo '</ol></div>';
	}

	public function page() {
		if ( ! current_user_can( \PublishRank_Newsroom::CAP_SETUP ) ) {
			return; }
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
		$close_url = $complete ? Branding::page_url( self::SLUG ) : wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_onboarding_exit' ), 'publishrank_onboarding_exit' );
		$self      = Branding::page_url( self::SLUG );
		$edit_qs   = $editing ? '&edit=1' : '';
		$lead      = $this->step_copy( $step, $is_person );
		$optional  = in_array( $step, self::optional_steps(), true );
		$error     = isset( $_GET['error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved     = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error_kind = isset( $_GET['error'] ) && ! is_array( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$can_exit   = array() === $this->missing_required();
		$elsewhere  = $this->required_missing_outside( $step );
		$kicker    = ( $complete ? __( 'Editing setup', 'publishrank' ) : __( 'First-time setup', 'publishrank' ) ) . ' · ' . sprintf( /* translators: 1: step, 2: total. */ __( 'Step %1$d of %2$d', 'publishrank' ), $step, self::TOTAL_STEPS );

		echo '<div class="wrap pr-modules-wrap pr-shell-wrap prw-wrap' . ( $locked ? ' is-locked' : '' ) . '" data-prw-wizard data-prw-step="' . (int) $step . '">';
		AdminAssets::header( __( 'Setup wizard', 'publishrank' ), $complete ? __( 'Update your publisher identity and core SEO settings. Every step saves on its own.', 'publishrank' ) : __( 'A few quick steps so search engines know exactly who publishes this site.', 'publishrank' ) );
		echo '<div class="pr-modules-layout">';
		AdminShell::nav( self::SLUG, $locked );
		echo '<div class="pr-modules-main pr-shell-main prw-main">';
		self::intro( $kicker, $lead[0], $lead[1], $steps[ $step ]['icon'] );
		$this->render_track( $step, $self, $edit_qs, $complete );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="prw-form" data-prw-form>
			<?php if ( 1 !== $step ) : ?>
				<?php /* Enter in a text field must continue, not go Back: the first submit button in the form is the default. */ ?>
				<button type="submit" class="prw-default-submit" tabindex="-1" aria-hidden="true"></button>
			<?php endif; ?>
			<input type="hidden" name="action" value="publishrank_onboarding_save" />
			<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />
			<?php if ( $editing ) : ?>
				<input type="hidden" name="edit_mode" value="1" />
			<?php endif; ?>
			<?php wp_nonce_field( 'publishrank_onboarding_save' ); ?>
			<?php if ( $error && 'slug' === $error_kind ) : ?>
				<div class="prw-alert prw-alert--error prw-validation-summary" id="prw-validation-summary" role="alert" tabindex="-1"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><p><?php esc_html_e( 'That login address cannot be used because it is empty or reserved by WordPress. Your previous address is unchanged. Please choose another one.', 'publishrank' ); ?></p></div>
			<?php elseif ( $error ) : ?>
				<div class="prw-alert prw-alert--error prw-validation-summary" id="prw-validation-summary" role="alert" tabindex="-1"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><p><?php echo esc_html( sprintf( /* translators: %s: field list. */ __( 'Please complete the required fields (*): %s', 'publishrank' ), ! empty( $this->missing_required() ) ? implode( ', ', $this->missing_required() ) : __( 'see highlighted fields', 'publishrank' ) ) ); ?></p></div>
			<?php endif; ?>
			<section class="prw-panel prw-panel--step-<?php echo (int) $step; ?>" aria-labelledby="prw-step-title">
				<?php $this->render_step_body( $step, $is_person, $locked, $self . $edit_qs ); ?>
			</section>
			<div class="prw-actions" data-prw-actions>
				<span class="prw-actions__status" data-prw-status>
					<i class="fa-solid <?php echo esc_attr( $can_exit ? 'fa-circle-check' : 'fa-lock' ); ?>" aria-hidden="true"></i>
					<span data-prw-status-text><?php echo esc_html( $can_exit ? __( 'Required fields saved. You can exit any time.', 'publishrank' ) : __( 'Fill in the required fields (*) to unlock exit', 'publishrank' ) ); ?></span>
				</span>
				<span class="prw-actions__buttons">
					<?php if ( $complete ) : ?>
						<a class="prw-link prw-link--muted" href="<?php echo esc_url( $close_url ); ?>"><?php esc_html_e( 'Cancel', 'publishrank' ); ?></a>
					<?php endif; ?>
					<button class="button prw-btn prw-btn--ghost prw-exit" type="submit" name="prw_nav" value="exit" formnovalidate data-prw-exit data-prw-exit-elsewhere="<?php echo $elsewhere ? '1' : '0'; ?>" title="<?php echo esc_attr__( 'Saves this step and leaves the wizard. Available once every required field (*) is filled in.', 'publishrank' ); ?>"<?php disabled( ! $can_exit ); ?>><i class="fa-solid fa-door-open" aria-hidden="true"></i> <?php esc_html_e( 'Save & exit', 'publishrank' ); ?></button>
					<?php if ( $optional ) : ?>
						<a class="prw-link" href="<?php echo esc_url( $self . '&step=' . ( $step + 1 ) . $edit_qs ); ?>"><?php esc_html_e( 'Skip for now', 'publishrank' ); ?></a>
					<?php endif; ?>
					<?php if ( $step > 1 ) : ?>
						<button class="button prw-btn prw-btn--ghost" type="submit" name="prw_nav" value="back" formnovalidate><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?php esc_html_e( 'Back', 'publishrank' ); ?></button>
					<?php endif; ?>
					<?php if ( 1 === $step ) : ?>
						<a class="button button-primary prw-btn prw-btn--primary" href="<?php echo esc_url( $self . '&step=2' . $edit_qs ); ?>"><?php esc_html_e( 'Start configuration', 'publishrank' ); ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
					<?php else : ?>
						<button class="button button-primary prw-btn prw-btn--primary" type="submit" name="prw_nav" value="next"><span class="prw-btn__spinner" aria-hidden="true"></span><?php echo esc_html( self::TOTAL_STEPS === $step ? __( 'Finish setup', 'publishrank' ) : __( 'Save & continue', 'publishrank' ) ); ?> <i class="fa-solid <?php echo esc_attr( self::TOTAL_STEPS === $step ? 'fa-check' : 'fa-arrow-right' ); ?>" aria-hidden="true"></i></button>
					<?php endif; ?>
				</span>
			</div>
		</form>
		<?php
		echo '</div></div>';
		if ( $saved && $step > 1 ) {
			echo '<div class="prw-toast" role="status" aria-live="polite" data-prw-toast><i class="fa-solid fa-circle-check" aria-hidden="true"></i> ' . esc_html( sprintf( /* translators: %s: name of the previous step. */ __( '%s saved', 'publishrank' ), $steps[ max( 1, $step - 1 ) ]['label'] ) ) . '</div>';
		}
		echo '</div>';
	}

	/** Heading and lead sentence for a step. @return array{0:string,1:string} */
	private function step_copy( $step, $is_person ) {
		$lead = array(
			1 => array( __( 'Welcome to PublishRank', 'publishrank' ), __( 'This one-time setup tells search engines who publishes this site. It takes about three minutes, and everything can be edited later.', 'publishrank' ) ),
			2 => array( __( 'Who publishes this site?', 'publishrank' ), __( 'Powers your Organization/Person schema and keeps publisher information consistent across search surfaces. Fields marked * are required.', 'publishrank' ) ),
			3 => array( $is_person ? __( 'About the publisher', 'publishrank' ) : __( 'Organization details', 'publishrank' ), __( 'All optional. Only add details that are public and accurate.', 'publishrank' ) ),
			4 => array( __( 'Social profiles and sharing', 'publishrank' ), __( 'Official profiles become the schema sameAs list. Enter a handle or a full URL.', 'publishrank' ) ),
			5 => array( __( 'Search appearance and verification', 'publishrank' ), __( 'Homepage title and description, plus ownership verification for search engines.', 'publishrank' ) ),
			6 => array( __( 'Content scope', 'publishrank' ), __( 'Choose which content types appear in your sitemaps.', 'publishrank' ) ),
			7 => array( __( 'Local business (optional)', 'publishrank' ), __( 'Only enable this if the publication has a real, public-facing business location.', 'publishrank' ) ),
			8 => array( __( 'Protect your sign-in page', 'publishrank' ), __( 'Choose a private address for the WordPress login. Fields marked * are required. You can change this any time in General Settings.', 'publishrank' ) ),
			9 => array( __( 'Review and finish', 'publishrank' ), __( 'Check your configuration, then finish. Ongoing controls such as the News window, breadcrumbs and robots live in General Settings.', 'publishrank' ) ),
		);
		return $lead[ $step ];
	}

	private function render_step_body( $step, $is_person, $locked, $edit_base = '' ) {
		if ( 1 === $step ) {
			$quick = array(
				array( 'fa-building', __( 'Publisher identity', 'publishrank' ), __( 'Your publication name, logo and country, used in schema, Open Graph and the News sitemap.', 'publishrank' ) ),
				array( 'fa-magnifying-glass', __( 'Search appearance', 'publishrank' ), __( 'Homepage title, social profiles, verification codes and which content goes in your sitemaps.', 'publishrank' ) ),
				array( 'fa-flag-checkered', __( 'Review & go live', 'publishrank' ), __( 'Check the summary and finish. Ongoing controls stay in General Settings.', 'publishrank' ) ),
			);
			echo '<ol class="pr-docs-steps prw-quick">';
			foreach ( $quick as $i => $item ) {
				echo '<li class="pr-docs-step"><span class="pr-docs-step__no" aria-hidden="true">' . (int) ( $i + 1 ) . '</span><span class="pr-docs-step__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $item[0] ) . '"></i></span><strong>' . esc_html( $item[1] ) . '</strong><p>' . esc_html( $item[2] ) . '</p></li>';
			}
			echo '</ol>';
			$checks = $this->environment_checks();
			$ok     = count( array_filter( wp_list_pluck( $checks, 'ok' ) ) );
			echo '<h3 class="pr-docs-h prw-subhead"><i class="fa-solid fa-stethoscope" aria-hidden="true"></i>' . esc_html__( 'Site readiness', 'publishrank' ) . '<span class="prw-subhead__meta">' . esc_html( sprintf( /* translators: 1: passed checks, 2: total checks. */ __( '%1$d of %2$d checks passed', 'publishrank' ), $ok, count( $checks ) ) ) . '</span></h3>';
			echo '<ul class="prw-checklist">';
			foreach ( $checks as $check ) {
				$icon = $check['ok'] ? 'fa-circle-check' : 'fa-triangle-exclamation';
				echo '<li class="' . ( $check['ok'] ? 'is-ok' : 'is-warn' ) . '"><i class="fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></i><span>' . esc_html( $check['label'] ) . '</span>';
				if ( ! $check['ok'] && ! empty( $check['fix'] ) ) {
					echo '<a class="prw-checklist__fix" href="' . esc_url( $check['fix'] ) . '">' . esc_html__( 'Fix', 'publishrank' ) . '</a>';
				} else {
					echo '<span class="prw-checklist__state">' . esc_html( $check['ok'] ? __( 'OK', 'publishrank' ) : __( 'Check', 'publishrank' ) ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
			if ( $locked ) {
				echo '<p class="prw-alert prw-alert--info"><i class="fa-solid fa-lock" aria-hidden="true"></i><span>' . esc_html__( 'PublishRank screens unlock as soon as the required publisher fields are saved.', 'publishrank' ) . '</span></p>';
			}
			return;
		}
		if ( 7 === $step ) {
			$this->render_local_step();
			return;
		}
		if ( self::TOTAL_STEPS === $step ) {
			$missing = $this->missing_required();
			if ( $missing ) {
				echo '<div class="prw-alert prw-alert--warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><p>' . esc_html( sprintf( /* translators: %s: field list. */ __( 'Still required: %s. Finishing will take you back to the Publisher step.', 'publishrank' ), implode( ', ', $missing ) ) ) . '</p></div>';
			}
			$this->recap( $edit_base );
			return;
		}
		if ( 8 === (int) $step ) {
			$this->render_login_url_notice();
		}
		echo '<div class="prw-fields">';
		foreach ( self::fields( $step ) as $field ) {
			$this->render_field( $field, $is_person );
		}
		echo '</div>';
		if ( 5 === (int) $step ) {
			$this->render_home_preview();
		}
	}

	/** Security step: shows the exact new login address with a copy button before anything moves. */
	private function render_login_url_notice() {
		$hide     = \PublishRankSEO\Security\PrivateLogin::get_instance();
		$url      = $hide->new_login_url();
		$pretty   = (bool) get_option( 'permalink_structure' );
		$prefix   = trailingslashit( home_url() ) . ( $pretty ? '' : '?' );
		$tail     = ( $pretty && '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ) ? '/' : '';
		$deferred = \PublishRankSEO\Security\PrivateLogin::is_deferred();
		$off      = ! $deferred && ! \PublishRankSEO\Security\PrivateLogin::enabled();
		if ( $deferred ) {
			$text = __( 'Your sign-in page has not moved yet. It moves to the address below when you save this step. After that, wp-login.php and wp-admin are hidden from visitors who are not signed in. Copy the address and keep it somewhere safe.', 'publishrank' );
		} elseif ( $off ) {
			$text = __( 'Private Login is switched off under Features, so your address is saved but not active yet. When it is on, your sign-in page is the address below.', 'publishrank' );
		} else {
			$text = __( 'After you save, sign in at the address below. Visitors who are not signed in can no longer open wp-login.php or wp-admin. Copy the address before you continue.', 'publishrank' );
		}
		echo '<div class="prw-alert prw-alert--info prw-loginurl" data-prw-loginurl data-prw-input="prw_publishrank_login_slug" data-prw-prefix="' . esc_attr( $prefix ) . '" data-prw-tail="' . esc_attr( $tail ) . '" data-copied="' . esc_attr__( 'Copied', 'publishrank' ) . '" data-copy-failed="' . esc_attr__( 'Press Ctrl+C to copy', 'publishrank' ) . '">';
		echo '<i class="fa-solid fa-user-shield" aria-hidden="true"></i><div><p>' . esc_html( $text ) . '</p>';
		echo '<p class="prw-loginurl__row"><code data-prw-loginurl-text>' . esc_html( $url ) . '</code> <button type="button" class="button prw-btn prw-btn--ghost" data-prw-copy-url><i class="fa-regular fa-copy" aria-hidden="true"></i> ' . esc_html__( 'Copy address', 'publishrank' ) . '</button> <span class="prw-loginurl__status" data-prw-copy-status role="status" aria-live="polite"></span></p></div></div>';
	}

	/** The summary shown once setup is complete, inside the shared frame. */
	private function render_complete_view() {
		$self = Branding::page_url( self::SLUG );
		$when = (string) get_option( 'publishrank_onboarding_completed_at', '' );
		$time = '' !== $when ? strtotime( $when ) : false;
		echo '<div class="wrap pr-modules-wrap pr-shell-wrap prw-wrap prw-wrap--complete">';
		AdminAssets::header( __( 'Setup wizard', 'publishrank' ), __( 'Your setup is complete. Review the configuration here, or edit it when your publication changes.', 'publishrank' ) );
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Setup complete. PublishRank is now outputting your publisher identity.', 'publishrank' ) . '</p></div>';
		}
		if ( isset( $_GET['restored'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recommended defaults restored.', 'publishrank' ) . '</p></div>';
		}
		echo '<div class="pr-modules-layout">';
		AdminShell::nav( self::SLUG );
		echo '<div class="pr-modules-main pr-shell-main prw-main">';
		self::intro( __( 'Help', 'publishrank' ), __( 'Setup wizard', 'publishrank' ), __( 'Review your core site identity and SEO settings, and jump straight to any step to change it.', 'publishrank' ), 'fa-solid fa-wand-magic-sparkles' );
		echo '<div class="prw-success' . ( isset( $_GET['done'] ) ? ' is-celebrating' : '' ) . '"><span class="prw-success__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span><div class="prw-success__copy"><p class="pr-shell-kicker">' . esc_html__( 'Setup complete', 'publishrank' ) . '</p><h3>' . esc_html__( 'PublishRank is outputting your publisher identity', 'publishrank' ) . '</h3><p>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $time ) {
			/* translators: %s: date the setup was completed. */
			echo esc_html( sprintf( __( 'Completed on %s. One-time settings live in this wizard; ongoing controls are in General Settings.', 'publishrank' ), wp_date( get_option( 'date_format' ), $time ) ) );
		} else {
			esc_html_e( 'One-time settings live in this wizard; ongoing controls are in General Settings.', 'publishrank' );
		}
		echo '</p></div><a class="button button-primary prw-btn prw-btn--primary" href="' . esc_url( $self . '&edit=1&step=2' ) . '"><i class="fa-solid fa-pen" aria-hidden="true"></i> ' . esc_html__( 'Edit setup', 'publishrank' ) . '</a></div>';
		echo '<h3 class="pr-docs-h prw-subhead"><i class="fa-solid fa-list-check" aria-hidden="true"></i>' . esc_html__( 'Your configuration', 'publishrank' ) . '</h3>';
		$this->recap( $self . '&edit=1' );
		echo '<div class="prw-complete-actions"><div class="prw-complete-actions__main"><a class="button prw-btn prw-btn--ghost" href="' . esc_url( Branding::page_url( Branding::MENU_SLUG ) ) . '"><i class="fa-solid fa-gauge-high" aria-hidden="true"></i> ' . esc_html__( 'Go to dashboard', 'publishrank' ) . '</a><a class="button prw-btn prw-btn--ghost" href="' . esc_url( Branding::page_url( 'publishrank' ) ) . '"><i class="fa-solid fa-sliders" aria-hidden="true"></i> ' . esc_html__( 'General Settings', 'publishrank' ) . '</a><a class="button prw-btn prw-btn--ghost" href="' . esc_url( Branding::page_url( 'publishrank-modules' ) ) . '"><i class="fa-solid fa-toggle-on" aria-hidden="true"></i> ' . esc_html__( 'Features', 'publishrank' ) . '</a></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="prw-restore" onsubmit="return confirm(&quot;' . esc_attr__( 'Restore recommended defaults? Your current wizard-managed values will be replaced.', 'publishrank' ) . '&quot;);"><input type="hidden" name="action" value="publishrank_onboarding_defaults" />';
		wp_nonce_field( 'publishrank_onboarding_defaults' );
		echo '<button class="prw-link prw-link--danger" type="submit"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> ' . esc_html__( 'Restore recommended defaults', 'publishrank' ) . '</button></form></div>';
		echo '</div></div></div>';
	}
}
