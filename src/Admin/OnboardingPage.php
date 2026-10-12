<?php
/** First-run premium setup wizard for PublishRank (Yoast-style onboarding). */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

final class OnboardingPage {
	const SLUG = 'publishrank-onboarding';
	const TOTAL_STEPS = 6;

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ), 1 );
		add_action( 'admin_notices', array( $this, 'setup_reminder_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_publishrank_onboarding_save', array( $this, 'save' ) );
		add_action( 'admin_post_publishrank_onboarding_exit', array( $this, 'exit_wizard' ) );
		add_action( 'admin_post_publishrank_onboarding_defaults', array( $this, 'restore_defaults' ) );
	}

	public function menu() {
		$label = $this->is_setup_complete() ? __( 'Setup Wizard', 'publishrank' ) : __( 'Complete Setup →', 'publishrank' );
		add_submenu_page( Branding::MENU_SLUG, __( 'PublishRank Setup', 'publishrank' ), $label, 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	/** Yoast-style: after activation, send the admin straight into the wizard. */
	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'publishrank_seo_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'publishrank_seo_activation_redirect' );
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return; // Bulk activation: stay on the plugins screen.
		}
		if ( $this->is_setup_complete() ) {
			return; // A completed setup is not re-triggered on upgrades.
		}
		wp_safe_redirect( Branding::page_url( self::SLUG ) );
		exit;
	}

	/** A persistent, Yoast-style first-run card; it remains until setup is truly completed. */
	public function setup_reminder_notice() {
		if ( $this->is_setup_complete() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_wizard = $screen && false !== strpos( (string) $screen->id, self::SLUG );
		if ( $is_wizard ) {
			return;
		}
		$icon = add_query_arg( 'ver', rawurlencode( Branding::version() ), Branding::image( 'publishrank-header-icon-v2.png' ) );
		$wizard_url = Branding::page_url( self::SLUG );
		echo '<div class="notice notice-info prw-setup-notice" style="border-left-color:#3858c8;padding:14px 16px;max-width:1000px"><div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">';
		echo '<img src="' . esc_url( $icon ) . '" alt="" width="48" height="48" style="width:48px;height:48px;object-fit:contain;flex:0 0 48px" />';
		echo '<div style="flex:1;min-width:220px"><strong style="font-size:14px;color:#202938">' . esc_html__( 'First-time SEO configuration', 'publishrank' ) . '</strong><p style="margin:5px 0 0">' . esc_html__( 'Set up your publisher identity, search appearance, schema, News SEO and sitemap infrastructure in one guided flow. This reminder stays here until setup is completed.', 'publishrank' ) . '</p></div>';
		echo '<a href="' . esc_url( $wizard_url ) . '" class="button button-primary prw-notice-button">' . esc_html__( 'Start onboarding', 'publishrank' ) . '</a>';
		echo '</div></div>';
	}

	/** Require the completion marker introduced with the verified setup flow. */
	private function is_setup_complete() {
		return (bool) get_option( 'publishrank_onboarding_complete', false )
			&& (string) get_option( 'publishrank_onboarding_completed_at', '' ) !== '';
	}

	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'publishrank-seo-onboarding', Branding::asset_url( 'css/onboarding.css' ), array( 'dashicons', 'publishrank-seo-admin' ), Branding::version() );
	}

	/** Leave the wizard without completing it (Yoast's close option). */
	public function exit_wizard() {
		AdminAssets::guard( 'publishrank_onboarding_exit' );
		// Leaving the wizard does not mark onboarding as complete. The reminder remains until setup is saved.
		wp_safe_redirect( Branding::page_url( Branding::MENU_SLUG ) );
		exit;
	}

	/** Environment readiness checks shown on step 1 (Yoast's first step). */
	private function environment_checks() {
		global $wp_version;
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$seo_plugins = array( 'wordpress-seo/wp-seo.php' => 'Yoast SEO', 'rank-math/rank-math.php' => 'Rank Math', 'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO' );
		$conflict = '';
		foreach ( $seo_plugins as $file => $label ) {
			if ( is_plugin_active( $file ) ) { $conflict = $label; break; }
		}
		$uploads = wp_upload_dir();
		return array(
			'wp'      => array( 'ok' => version_compare( $wp_version, '6.3', '>=' ), 'label' => sprintf( __( 'WordPress %s (6.3+ required)', 'publishrank' ), $wp_version ) ),
			'php'     => array( 'ok' => version_compare( PHP_VERSION, '7.4', '>=' ), 'label' => sprintf( __( 'PHP %s (7.4+ required)', 'publishrank' ), PHP_VERSION ) ),
			'ssl'     => array( 'ok' => is_ssl(), 'label' => __( 'HTTPS active', 'publishrank' ) ),
			'perm'    => array( 'ok' => get_option( 'permalink_structure' ) !== '', 'label' => __( 'Pretty permalinks enabled', 'publishrank' ) ),
			'conflict'=> array( 'ok' => '' === $conflict, 'label' => $conflict ? sprintf( __( 'Another SEO plugin detected: %s — running both is not recommended.', 'publishrank' ), $conflict ) : __( 'No conflicting SEO plugin detected', 'publishrank' ) ),
			'uploads' => array( 'ok' => empty( $uploads['error'] ), 'label' => empty( $uploads['error'] ) ? __( 'Uploads directory writable', 'publishrank' ) : $uploads['error'] ),
		);
	}

	/** Restore conservative recommended defaults without deleting unrelated SEO data. */
	public function restore_defaults() {
		AdminAssets::guard( 'publishrank_onboarding_defaults' );
		$defaults = array(
			'publishrank_identity_type' => 'organization',
			'publishrank_identity_name' => get_bloginfo( 'name' ),
			'publishrank_identity_description' => get_bloginfo( 'description' ),
			'publishrank_seo_publisher_name' => get_bloginfo( 'name' ),
			'publishrank_seo_publisher_logo' => '',
			'publishrank_identity_alternate_name' => '',
			'publishrank_identity_legal_name' => '',
			'publishrank_identity_contact_email' => '',
			'publishrank_identity_contact_phone' => '',
			'publishrank_identity_founding_date' => '',
			'publishrank_identity_street' => '',
			'publishrank_identity_locality' => '',
			'publishrank_identity_region' => '',
			'publishrank_identity_postal_code' => '',
			'publishrank_identity_country' => '',
			'publishrank_schema_founder' => '',
			'publishrank_schema_knows_about' => '',
			'publishrank_schema_parent_organization' => '',
			'publishrank_schema_founding_location' => '',
			'publishrank_home_title' => '',
			'publishrank_home_description' => get_bloginfo( 'description' ),
			'publishrank_seo_default_robots' => 'index,follow',
			'publishrank_seo_title_template_home' => '%%sitename%% %%page%%',
			'publishrank_seo_news_enabled' => 1,
			'publishrank_seo_news_window' => 48,
			'publishrank_seo_news_keywords' => 1,
			'publishrank_sitemap_stylesheet' => 1,
			'publishrank_sitemap_pages_enabled' => 1,
			'publishrank_sitemap_taxonomies_enabled' => 1,
			'publishrank_breadcrumbs_enabled' => 1,
			'publishrank_meta_enabled' => 1,
			'publishrank_schema_enabled' => 1,
			'publishrank_image_optimizer_enabled' => 0,
			'publishrank_image_max_width' => 2000,
			'publishrank_image_quality' => 82,
			'publishrank_image_format' => 'webp',
			'publishrank_local_seo_profile' => array( 'enabled' => 0, 'name' => '', 'type' => 'LocalBusiness', 'description' => '', 'url' => '', 'phone' => '', 'email' => '', 'logo' => '', 'street' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'country' => '', 'latitude' => '', 'longitude' => '', 'price_range' => '', 'has_map' => '', 'service_area' => '', 'opening_hours' => array(), 'same_as' => array() ),
		);
		foreach ( $defaults as $key => $value ) { update_option( $key, $value, false ); }
		// Defaults update configuration but do not count as a completed guided onboarding.
		delete_option( 'publishrank_onboarding_complete' );
		delete_option( 'publishrank_onboarding_completed_at' );
		wp_safe_redirect( Branding::page_url( self::SLUG ) . '&step=1&restored=1' );
		exit;
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ) ); }
		check_admin_referer( 'publishrank_onboarding_save' );
		$step = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 1;
		$step = max( 1, min( self::TOTAL_STEPS, $step ) );
		$set = static function ( $key, $value ) { update_option( $key, $value, false ); };

		if ( 2 === $step ) {
			$set( 'publishrank_identity_type', isset( $_POST['identity_type'] ) && 'person' === $_POST['identity_type'] ? 'person' : 'organization' );
			$set( 'publishrank_identity_name', sanitize_text_field( wp_unslash( $_POST['identity_name'] ?? '' ) ) );
			$set( 'publishrank_identity_description', sanitize_textarea_field( wp_unslash( $_POST['identity_description'] ?? '' ) ) );
			$set( 'publishrank_seo_publisher_name', sanitize_text_field( wp_unslash( $_POST['identity_name'] ?? get_bloginfo( 'name' ) ) ) );
			$publisher_logo = esc_url_raw( wp_unslash( $_POST['identity_logo'] ?? '' ) );
			$set( 'publishrank_seo_publisher_logo', $publisher_logo );
			$set( 'publishrank_identity_logo', $publisher_logo );
			if ( isset( $_POST['identity_type'] ) && 'person' === $_POST['identity_type'] ) {
				$set( 'publishrank_identity_person_name', sanitize_text_field( wp_unslash( $_POST['identity_name'] ?? '' ) ) );
				$set( 'publishrank_identity_person_description', sanitize_textarea_field( wp_unslash( $_POST['identity_description'] ?? '' ) ) );
				$set( 'publishrank_identity_person_image', $publisher_logo );
				$set( 'publishrank_identity_person_url', esc_url_raw( home_url( '/' ) ) );
				$set( 'publishrank_identity_person_email', sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) ) );
			}
			$identity_fields = array( 'alternate_name' => 'publishrank_identity_alternate_name', 'legal_name' => 'publishrank_identity_legal_name', 'contact_email' => 'publishrank_identity_contact_email', 'contact_phone' => 'publishrank_identity_contact_phone', 'founding_date' => 'publishrank_identity_founding_date', 'street' => 'publishrank_identity_street', 'locality' => 'publishrank_identity_locality', 'region' => 'publishrank_identity_region', 'postal_code' => 'publishrank_identity_postal_code', 'country' => 'publishrank_identity_country' );
			foreach ( $identity_fields as $field => $option ) {
				$value = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
				$clean = 'contact_email' === $field ? sanitize_email( $value ) : ( 'founding_date' === $field ? sanitize_text_field( $value ) : sanitize_text_field( $value ) );
				$set( $option, $clean );
			}
			$set( 'publishrank_schema_founder', sanitize_text_field( wp_unslash( $_POST['schema_founder'] ?? '' ) ) );
			$set( 'publishrank_schema_knows_about', sanitize_textarea_field( wp_unslash( $_POST['schema_knows_about'] ?? '' ) ) );
			$set( 'publishrank_schema_parent_organization', sanitize_text_field( wp_unslash( $_POST['schema_parent_organization'] ?? '' ) ) );
			$set( 'publishrank_schema_founding_location', sanitize_text_field( wp_unslash( $_POST['schema_founding_location'] ?? '' ) ) );
			$local_input = isset( $_POST['local_seo'] ) && is_array( $_POST['local_seo'] ) ? wp_unslash( $_POST['local_seo'] ) : array();
			$set( 'publishrank_local_seo_profile', \PublishRankSEO\SEO\LocalSEO::sanitize_profile( $local_input ) );
		} elseif ( 3 === $step ) {
			$set( 'publishrank_home_title', sanitize_text_field( wp_unslash( $_POST['home_title'] ?? '' ) ) );
			$set( 'publishrank_home_description', sanitize_textarea_field( wp_unslash( $_POST['home_description'] ?? '' ) ) );
			$set( 'publishrank_seo_default_robots', in_array( $_POST['robots'] ?? '', array( 'index,follow', 'noindex,follow' ), true ) ? sanitize_text_field( wp_unslash( $_POST['robots'] ) ) : 'index,follow' );
			$set( 'publishrank_seo_title_template_home', sanitize_text_field( wp_unslash( $_POST['title_template'] ?? '%%sitename%% %%page%%' ) ) );
		} elseif ( 4 === $step ) {
			$set( 'publishrank_seo_news_enabled', ! empty( $_POST['news_enabled'] ) );
			$window = absint( $_POST['news_window'] ?? 48 );
			$set( 'publishrank_seo_news_window', max( 1, min( 168, $window ) ) );
			$set( 'publishrank_seo_news_keywords', ! empty( $_POST['news_keywords'] ) );
		} elseif ( 5 === $step ) {
			$set( 'publishrank_sitemap_stylesheet', ! empty( $_POST['sitemap_stylesheet'] ) );
			$set( 'publishrank_sitemap_pages_enabled', ! empty( $_POST['sitemap_pages'] ) );
			$set( 'publishrank_sitemap_taxonomies_enabled', ! empty( $_POST['sitemap_taxonomies'] ) );
			$set( 'publishrank_breadcrumbs_enabled', ! empty( $_POST['breadcrumbs'] ) );
			$set( 'publishrank_meta_enabled', ! empty( $_POST['meta_enabled'] ) );
			$set( 'publishrank_schema_enabled', ! empty( $_POST['schema_enabled'] ) );
			$set( 'publishrank_image_optimizer_enabled', ! empty( $_POST['image_optimizer_enabled'] ) );
			$set( 'publishrank_image_max_width', min( 4000, max( 640, absint( $_POST['image_max_width'] ?? 2000 ) ) ) );
			$set( 'publishrank_image_quality', min( 95, max( 50, absint( $_POST['image_quality'] ?? 82 ) ) ) );
			$set( 'publishrank_image_format', in_array( $_POST['image_format'] ?? '', array( 'webp', 'jpeg', 'preserve' ), true ) ? sanitize_key( $_POST['image_format'] ) : 'webp' );
		} elseif ( 6 === $step ) {
			$set( 'publishrank_onboarding_complete', 1 );
			$set( 'publishrank_onboarding_version', Branding::version() );
			$set( 'publishrank_onboarding_completed_at', gmdate( 'c' ) );
		}
		$next = min( self::TOTAL_STEPS, $step + 1 );
		$edit_suffix = ! empty( $_POST['edit_mode'] ) ? '&edit=1' : '';
		wp_safe_redirect( Branding::page_url( self::SLUG ) . '&step=' . $next . '&saved=1' . $edit_suffix );
		exit;
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$step = isset( $_GET['step'] ) ? max( 1, min( self::TOTAL_STEPS, absint( $_GET['step'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$steps = array(
			1 => array( 'label' => __( 'Welcome', 'publishrank' ), 'icon' => 'dashicons-flag' ),
			2 => array( 'label' => __( 'Site identity', 'publishrank' ), 'icon' => 'dashicons-building' ),
			3 => array( 'label' => __( 'Search appearance', 'publishrank' ), 'icon' => 'dashicons-search' ),
			4 => array( 'label' => __( 'News SEO', 'publishrank' ), 'icon' => 'dashicons-media-document' ),
			5 => array( 'label' => __( 'Infrastructure', 'publishrank' ), 'icon' => 'dashicons-networking' ),
			6 => array( 'label' => __( 'Ready', 'publishrank' ), 'icon' => 'dashicons-yes-alt' ),
		);
		$identity = get_option( 'publishrank_identity_type', 'organization' );
		$name = get_option( 'publishrank_identity_name', get_bloginfo( 'name' ) );
		$title = get_option( 'publishrank_home_title', '' );
		$description = get_option( 'publishrank_home_description', get_bloginfo( 'description' ) );
		$news = (bool) get_option( 'publishrank_seo_news_enabled', true );
		$news_window = (int) get_option( 'publishrank_seo_news_window', 48 );
		$complete = $this->is_setup_complete();
		$exit_url = wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_onboarding_exit' ), 'publishrank_onboarding_exit' );
		if ( $complete && empty( $_GET['edit'] ) ) {
			$local_profile = get_option( 'publishrank_local_seo_profile', array() );
			$local_profile = is_array( $local_profile ) ? $local_profile : array();
			echo '<div class="wrap rhs-onboarding-wrap">';
			AdminAssets::header( __( 'PublishRank Configuration', 'publishrank' ), __( 'Your setup is complete. Review the current configuration here, or edit it when your publication changes.', 'publishrank' ) );
			echo '<div class="rhs-onboarding-card"><div class="rhs-setup-complete"><div class="rhs-setup-complete__icon" aria-hidden="true">✓</div><div class="rhs-setup-complete__copy"><span class="rhs-eyebrow">' . esc_html__( 'Setup complete', 'publishrank' ) . '</span><h2>' . esc_html__( 'Your PublishRank configuration', 'publishrank' ) . '</h2><small>' . esc_html__( 'The wizard will not keep prompting you. Your settings remain saved until you edit them.', 'publishrank' ) . '</small></div></div>';
			echo '<div class="prw-recap"><div class="prw-recap__item"><strong>' . esc_html( $name ?: get_bloginfo( 'name' ) ) . '</strong><span>' . esc_html__( 'Publisher / site identity', 'publishrank' ) . '</span></div><div class="prw-recap__item"><strong>' . esc_html( $title ?: get_bloginfo( 'name' ) ) . '</strong><span>' . esc_html__( 'Homepage SEO title', 'publishrank' ) . '</span></div><div class="prw-recap__item"><strong>' . ( get_option( 'publishrank_schema_enabled', true ) ? esc_html__( 'Schema graph enabled', 'publishrank' ) : esc_html__( 'Schema graph disabled', 'publishrank' ) ) . '</strong><span>' . esc_html__( 'Organization, website, webpage and article schema where applicable', 'publishrank' ) . '</span></div><div class="prw-recap__item"><strong>' . ( ! empty( $local_profile['enabled'] ) ? esc_html( $local_profile['name'] ?? __( 'Local business profile enabled', 'publishrank' ) ) : esc_html__( 'Local SEO schema disabled', 'publishrank' ) ) . '</strong><span>' . esc_html__( 'Local business information', 'publishrank' ) . '</span></div><div class="prw-recap__item"><strong>' . ( $news ? esc_html__( 'News sitemap enabled', 'publishrank' ) : esc_html__( 'News sitemap disabled', 'publishrank' ) ) . '</strong><span>' . esc_html( sprintf( __( 'Freshness window: %d hours', 'publishrank' ), $news_window ) ) . '</span></div></div>';
			echo '<div class="rhs-onboarding-actions"><a class="button button-primary" href="' . esc_url( Branding::page_url( self::SLUG ) . '&edit=1&step=1' ) . '">' . esc_html__( 'Edit configuration', 'publishrank' ) . '</a><a class="button button-secondary" href="' . esc_url( Branding::page_url( Branding::MENU_SLUG ) ) . '">' . esc_html__( 'Go to dashboard', 'publishrank' ) . '</a><a class="button button-secondary" href="' . esc_url( Branding::page_url( 'publishrank-seo-settings' ) ) . '">' . esc_html__( 'Advanced settings', 'publishrank' ) . '</a><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(&quot;Restore recommended setup defaults? Your current wizard-managed values will be replaced.&quot;);"><input type="hidden" name="action" value="publishrank_onboarding_defaults" />';
			wp_nonce_field( 'publishrank_onboarding_defaults' );
			echo '<button class="button" type="submit">' . esc_html__( 'Restore recommended defaults', 'publishrank' ) . '</button></form></div></div></div>';
			if ( isset( $_GET['restored'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Recommended defaults restored.', 'publishrank' ) . '</p></div>'; }
			return;
		}
		?>
		<div class="wrap rhs-onboarding-wrap">
			<?php AdminAssets::header( __( 'PublishRank Setup', 'publishrank' ), __( 'Your first-time SEO configuration — guided, publisher-grade, done in minutes.', 'publishrank' ) ); ?>

			<div class="rhs-onboarding-hero">
				<div>
					<span class="rhs-eyebrow"><?php esc_html_e( 'First-time SEO configuration', 'publishrank' ); ?></span>
					<h1><?php esc_html_e( 'Set up PublishRank the right way, from day one.', 'publishrank' ); ?></h1>
					<p><?php esc_html_e( 'Six guided steps for environment readiness, site identity, local business details, search appearance, News SEO, schema and technical infrastructure.', 'publishrank' ); ?></p>
				</div>
				<div class="rhs-onboarding-orbit" aria-hidden="true"><span>PR</span><i></i></div>
			</div>

			<ol class="prw-stepper" aria-label="<?php esc_attr_e( 'Setup progress', 'publishrank' ); ?>">
				<?php foreach ( $steps as $number => $data ) :
					$class = $number < $step ? 'is-done' : ( $number === $step ? 'is-active' : '' ); ?>
					<li class="prw-stepper__item <?php echo esc_attr( $class ); ?>">
						<span class="prw-stepper__bubble"><span class="dashicons <?php echo esc_attr( $data['icon'] ); ?>" aria-hidden="true"></span></span>
						<span class="prw-stepper__label"><?php echo esc_html( $data['label'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. Your configuration is ready for the next step.', 'publishrank' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rhs-onboarding-card">
				<input type="hidden" name="action" value="publishrank_onboarding_save" />
				<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />
				<?php if ( ! empty( $_GET['edit'] ) ) : ?><input type="hidden" name="edit_mode" value="1" /><?php endif; ?>
				<?php wp_nonce_field( 'publishrank_onboarding_save' ); ?>

				<?php if ( 1 === $step ) : ?>
					<span class="rhs-eyebrow"><?php esc_html_e( 'Step 1 · Welcome', 'publishrank' ); ?></span>
					<h2><?php esc_html_e( 'Let\'s make sure your site is ready.', 'publishrank' ); ?></h2>
					<p class="rhs-lead"><?php esc_html_e( 'PublishRank checked your environment. Everything marked ready below is confirmed before we configure your publication.', 'publishrank' ); ?></p>
					<ul class="prw-checklist">
						<?php foreach ( $this->environment_checks() as $check ) : ?>
							<li class="<?php echo $check['ok'] ? 'is-ok' : 'is-warn'; ?>">
								<span class="dashicons <?php echo $check['ok'] ? 'dashicons-yes' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
								<?php echo esc_html( $check['label'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>

				<?php elseif ( 2 === $step ) : ?>
					<span class="rhs-eyebrow"><?php esc_html_e( 'Step 2 · Site identity', 'publishrank' ); ?></span>
					<h2><?php esc_html_e( 'Who publishes this site?', 'publishrank' ); ?></h2>
					<p class="rhs-lead"><?php esc_html_e( 'This identity powers your Organization/Person schema graph and keeps your publisher information consistent across search surfaces.', 'publishrank' ); ?></p>
					<div class="rhs-choice-grid">
						<label class="rhs-choice"><input type="radio" name="identity_type" value="organization" <?php checked( $identity, 'organization' ); ?> /><span><b><?php esc_html_e( 'Organization', 'publishrank' ); ?></b><small><?php esc_html_e( 'Best for newspapers, magazines, newsrooms and digital publishers.', 'publishrank' ); ?></small></span></label>
						<label class="rhs-choice"><input type="radio" name="identity_type" value="person" <?php checked( $identity, 'person' ); ?> /><span><b><?php esc_html_e( 'Person', 'publishrank' ); ?></b><small><?php esc_html_e( 'Best for an individual publisher, journalist or creator-led site.', 'publishrank' ); ?></small></span></label>
					</div>
					<div class="rhs-onboarding-fields">
						<label><b><?php esc_html_e( 'Publication / publisher name', 'publishrank' ); ?></b><input type="text" name="identity_name" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" required /></label>
						<label><b><?php esc_html_e( 'Short description', 'publishrank' ); ?></b><textarea name="identity_description" rows="4" placeholder="<?php esc_attr_e( 'Independent news and analysis for...', 'publishrank' ); ?>"><?php echo esc_textarea( get_option( 'publishrank_identity_description', get_bloginfo( 'description' ) ) ); ?></textarea></label>
						<label><b><?php esc_html_e( 'Publisher logo', 'publishrank' ); ?></b>
							<span class="prw-media-row">
								<input type="text" id="prw_identity_logo" name="identity_logo" value="<?php echo esc_attr( get_option( 'publishrank_seo_publisher_logo', '' ) ); ?>" placeholder="https://" class="regular-text" />
								<button type="button" class="button rhs-media-button" data-target="prw_identity_logo"><?php esc_html_e( 'Choose image', 'publishrank' ); ?></button>
							</span>
							<small><?php esc_html_e( 'Used in schema markup and search knowledge surfaces.', 'publishrank' ); ?></small>
						</label>
						<label><b><?php esc_html_e( 'Alternate / short name', 'publishrank' ); ?></b><input type="text" name="alternate_name" value="<?php echo esc_attr( get_option( 'publishrank_identity_alternate_name', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Legal organization name', 'publishrank' ); ?></b><input type="text" name="legal_name" value="<?php echo esc_attr( get_option( 'publishrank_identity_legal_name', get_option( 'publishrank_schema_legal_name', '' ) ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Public contact email', 'publishrank' ); ?></b><input type="email" name="contact_email" value="<?php echo esc_attr( get_option( 'publishrank_identity_contact_email', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Public contact phone', 'publishrank' ); ?></b><input type="text" name="contact_phone" value="<?php echo esc_attr( get_option( 'publishrank_identity_contact_phone', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Founding date', 'publishrank' ); ?></b><input type="text" name="founding_date" placeholder="2015 or 2015-03-14" value="<?php echo esc_attr( get_option( 'publishrank_identity_founding_date', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Street address (optional)', 'publishrank' ); ?></b><input type="text" name="street" value="<?php echo esc_attr( get_option( 'publishrank_identity_street', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'City / locality', 'publishrank' ); ?></b><input type="text" name="locality" value="<?php echo esc_attr( get_option( 'publishrank_identity_locality', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'State / region', 'publishrank' ); ?></b><input type="text" name="region" value="<?php echo esc_attr( get_option( 'publishrank_identity_region', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Postal code', 'publishrank' ); ?></b><input type="text" name="postal_code" value="<?php echo esc_attr( get_option( 'publishrank_identity_postal_code', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Country', 'publishrank' ); ?></b><input type="text" name="country" placeholder="PK or Pakistan" value="<?php echo esc_attr( get_option( 'publishrank_identity_country', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Founder (optional)', 'publishrank' ); ?></b><input type="text" name="schema_founder" value="<?php echo esc_attr( get_option( 'publishrank_schema_founder', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Parent organization', 'publishrank' ); ?></b><input type="text" name="schema_parent_organization" value="<?php echo esc_attr( get_option( 'publishrank_schema_parent_organization', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Founding location', 'publishrank' ); ?></b><input type="text" name="schema_founding_location" value="<?php echo esc_attr( get_option( 'publishrank_schema_founding_location', '' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Topics / expertise (comma separated)', 'publishrank' ); ?></b><textarea name="schema_knows_about" rows="2"><?php echo esc_textarea( get_option( 'publishrank_schema_knows_about', '' ) ); ?></textarea></label>
					</div>
					<h3><?php esc_html_e( 'Local SEO (optional)', 'publishrank' ); ?></h3>
					<p class="rhs-lead"><?php esc_html_e( 'Only enable this if the publication or company has a real, public-facing business location. News publishers without a customer-facing location should leave it off.', 'publishrank' ); ?></p>
					<?php $local = get_option( 'publishrank_local_seo_profile', array() ); $local = wp_parse_args( is_array( $local ) ? $local : array(), array( 'enabled' => 0, 'name' => '', 'type' => 'LocalBusiness', 'description' => '', 'url' => home_url( '/' ), 'phone' => '', 'email' => '', 'logo' => '', 'street' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'country' => '', 'latitude' => '', 'longitude' => '', 'price_range' => '', 'has_map' => '', 'service_area' => '', 'opening_hours' => array(), 'same_as' => array() ) ); ?>
					<div class="rhs-onboarding-fields">
						<label class="rhs-toggle"><input type="checkbox" name="local_seo[enabled]" value="1" <?php checked( ! empty( $local['enabled'] ) ); ?> /><span><b><?php esc_html_e( 'Enable LocalBusiness schema', 'publishrank' ); ?></b><small><?php esc_html_e( 'Leave off for a purely digital publication without a real public business location.', 'publishrank' ); ?></small></span></label>
						<label><b><?php esc_html_e( 'Business / location name', 'publishrank' ); ?></b><input type="text" name="local_seo[name]" value="<?php echo esc_attr( $local['name'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Business type', 'publishrank' ); ?></b><select name="local_seo[type]"><?php foreach ( \PublishRankSEO\SEO\LocalSEO::types() as $type => $label ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( $local['type'], $type ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
						<label><b><?php esc_html_e( 'Business URL', 'publishrank' ); ?></b><input type="url" name="local_seo[url]" value="<?php echo esc_attr( $local['url'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Business phone', 'publishrank' ); ?></b><input type="text" name="local_seo[phone]" value="<?php echo esc_attr( $local['phone'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Business email', 'publishrank' ); ?></b><input type="email" name="local_seo[email]" value="<?php echo esc_attr( $local['email'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Business logo / image URL', 'publishrank' ); ?></b><input type="url" name="local_seo[logo]" value="<?php echo esc_attr( $local['logo'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Street address', 'publishrank' ); ?></b><input type="text" name="local_seo[street]" value="<?php echo esc_attr( $local['street'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'City / locality', 'publishrank' ); ?></b><input type="text" name="local_seo[city]" value="<?php echo esc_attr( $local['city'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Region', 'publishrank' ); ?></b><input type="text" name="local_seo[region]" value="<?php echo esc_attr( $local['region'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Postal code', 'publishrank' ); ?></b><input type="text" name="local_seo[postal_code]" value="<?php echo esc_attr( $local['postal_code'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Country', 'publishrank' ); ?></b><input type="text" name="local_seo[country]" value="<?php echo esc_attr( $local['country'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Latitude', 'publishrank' ); ?></b><input type="number" step="any" name="local_seo[latitude]" value="<?php echo esc_attr( $local['latitude'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Longitude', 'publishrank' ); ?></b><input type="number" step="any" name="local_seo[longitude]" value="<?php echo esc_attr( $local['longitude'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Service area', 'publishrank' ); ?></b><input type="text" name="local_seo[service_area]" value="<?php echo esc_attr( $local['service_area'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Map URL', 'publishrank' ); ?></b><input type="url" name="local_seo[has_map]" value="<?php echo esc_attr( $local['has_map'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Price range (if applicable)', 'publishrank' ); ?></b><input type="text" name="local_seo[price_range]" value="<?php echo esc_attr( $local['price_range'] ); ?>" /></label>
						<label><b><?php esc_html_e( 'Opening hours', 'publishrank' ); ?></b><textarea name="local_seo[opening_hours]" rows="2"><?php echo esc_textarea( is_array( $local['opening_hours'] ) ? implode( "\n", $local['opening_hours'] ) : '' ); ?></textarea><small><?php esc_html_e( 'Example: Mo-Fr 09:00-17:00', 'publishrank' ); ?></small></label>
						<label><b><?php esc_html_e( 'Official profile URLs (one per line)', 'publishrank' ); ?></b><textarea name="local_seo[same_as]" rows="2"><?php echo esc_textarea( is_array( $local['same_as'] ) ? implode( "\n", $local['same_as'] ) : '' ); ?></textarea></label>
					</div>

				<?php elseif ( 3 === $step ) : ?>
					<span class="rhs-eyebrow"><?php esc_html_e( 'Step 3 · Search appearance', 'publishrank' ); ?></span>
					<h2><?php esc_html_e( 'Shape how your publication appears in search.', 'publishrank' ); ?></h2>
					<p class="rhs-lead"><?php esc_html_e( 'Set the homepage title, description and baseline indexing policy. You can refine advanced templates later in Settings.', 'publishrank' ); ?></p>
					<div class="rhs-preview-box"><span class="rhs-preview-url"><?php echo esc_html( home_url( '/' ) ); ?></span><strong><?php echo esc_html( $title ?: get_bloginfo( 'name' ) ); ?></strong><p><?php echo esc_html( $description ); ?></p></div>
					<div class="rhs-onboarding-fields">
						<label><b><?php esc_html_e( 'Homepage SEO title', 'publishrank' ); ?></b><input type="text" name="home_title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Homepage meta description', 'publishrank' ); ?></b><textarea name="home_description" rows="4"><?php echo esc_textarea( $description ); ?></textarea></label>
						<label><b><?php esc_html_e( 'Default robots policy', 'publishrank' ); ?></b><select name="robots"><option value="index,follow" <?php selected( get_option( 'publishrank_seo_default_robots', 'index,follow' ), 'index,follow' ); ?>>index, follow</option><option value="noindex,follow" <?php selected( get_option( 'publishrank_seo_default_robots', 'index,follow' ), 'noindex,follow' ); ?>>noindex, follow</option></select></label>
						<label><b><?php esc_html_e( 'Homepage title template', 'publishrank' ); ?></b><input type="text" name="title_template" value="<?php echo esc_attr( get_option( 'publishrank_seo_title_template_home', '%%sitename%% %%page%%' ) ); ?>" /></label>
					</div>

				<?php elseif ( 4 === $step ) : ?>
					<span class="rhs-eyebrow"><?php esc_html_e( 'Step 4 · News SEO', 'publishrank' ); ?></span>
					<h2><?php esc_html_e( 'Make your newsroom search-ready.', 'publishrank' ); ?></h2>
					<p class="rhs-lead"><?php esc_html_e( 'PublishRank can maintain a dedicated Google News sitemap and enrich its keywords from your editorial keyphrase strategy.', 'publishrank' ); ?></p>
					<div class="rhs-feature-callout"><div class="rhs-feature-icon" aria-hidden="true"><span class="dashicons dashicons-media-document"></span></div><div><b><?php esc_html_e( 'Google News infrastructure', 'publishrank' ); ?></b><p><?php esc_html_e( 'Keep recent articles discoverable with a configurable freshness window and News keywords.', 'publishrank' ); ?></p></div></div>
					<div class="rhs-onboarding-fields">
						<label class="rhs-toggle"><input type="checkbox" name="news_enabled" value="1" <?php checked( $news ); ?> /><span><b><?php esc_html_e( 'Enable News sitemap', 'publishrank' ); ?></b><small><?php esc_html_e( 'Recommended for publishers and news sites.', 'publishrank' ); ?></small></span></label>
						<label><b><?php esc_html_e( 'Freshness window', 'publishrank' ); ?></b><select name="news_window"><?php foreach ( array( 24, 48, 72, 168 ) as $hours ) : ?><option value="<?php echo esc_attr( $hours ); ?>" <?php selected( $news_window, $hours ); ?>><?php echo esc_html( sprintf( _n( '%d hour', '%d hours', $hours, 'publishrank' ), $hours ) ); ?></option><?php endforeach; ?></select></label>
						<label class="rhs-toggle"><input type="checkbox" name="news_keywords" value="1" <?php checked( get_option( 'publishrank_seo_news_keywords', true ) ); ?> /><span><b><?php esc_html_e( 'Include News keywords', 'publishrank' ); ?></b><small><?php esc_html_e( 'Uses your primary, secondary and related editorial keyphrases where available.', 'publishrank' ); ?></small></span></label>
					</div>

				<?php elseif ( 5 === $step ) : ?>
					<span class="rhs-eyebrow"><?php esc_html_e( 'Step 5 · Infrastructure', 'publishrank' ); ?></span>
					<h2><?php esc_html_e( 'Turn on the technical foundation.', 'publishrank' ); ?></h2>
					<p class="rhs-lead"><?php esc_html_e( 'Configure the connected schema graph and technical SEO foundation here. Your site identity and optional LocalBusiness profile were configured in the previous step.', 'publishrank' ); ?></p>
					<div class="rhs-settings-grid"><?php $toggles = array( 'meta_enabled' => array( 'publishrank_meta_enabled', __( 'SEO metadata', 'publishrank' ), __( 'Title, canonical, robots and social metadata.', 'publishrank' ) ), 'schema_enabled' => array( 'publishrank_schema_enabled', __( 'Connected schema graph', 'publishrank' ), __( 'Organization, WebSite, WebPage and article structured data.', 'publishrank' ) ), 'sitemap_stylesheet' => array( 'publishrank_sitemap_stylesheet', __( 'Sitemap presentation', 'publishrank' ), __( 'Human-readable sitemap stylesheet and publisher-friendly output.', 'publishrank' ) ), 'sitemap_pages' => array( 'publishrank_sitemap_pages_enabled', __( 'Pages in sitemap', 'publishrank' ), __( 'Include public pages in the sitemap infrastructure.', 'publishrank' ) ), 'sitemap_taxonomies' => array( 'publishrank_sitemap_taxonomies_enabled', __( 'Taxonomies in sitemap', 'publishrank' ), __( 'Include eligible public taxonomy archives.', 'publishrank' ) ), 'breadcrumbs' => array( 'publishrank_breadcrumbs_enabled', __( 'Breadcrumbs', 'publishrank' ), __( 'Connect breadcrumb navigation with structured data.', 'publishrank' ) ), 'image_optimizer_enabled' => array( 'publishrank_image_optimizer_enabled', __( 'Image optimizer', 'publishrank' ), __( 'Resize oversized images and create WebP/JPEG derivatives for faster Discover and News pages.', 'publishrank' ) ) ); foreach ( $toggles as $field => $item ) : ?><label class="rhs-setting-card"><input type="checkbox" name="<?php echo esc_attr( $field ); ?>" value="1" <?php checked( get_option( $item[0], true ) ); ?> /><span><b><?php echo esc_html( $item[1] ); ?></b><small><?php echo esc_html( $item[2] ); ?></small></span></label><?php endforeach; ?></div>
					<div class="rhs-onboarding-fields">
						<label><b><?php esc_html_e( 'Maximum image width', 'publishrank' ); ?></b><input type="number" name="image_max_width" min="640" max="4000" value="<?php echo esc_attr( get_option( 'publishrank_image_max_width', 2000 ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Image quality', 'publishrank' ); ?></b><input type="number" name="image_quality" min="50" max="95" value="<?php echo esc_attr( get_option( 'publishrank_image_quality', 82 ) ); ?>" /></label>
						<label><b><?php esc_html_e( 'Preferred format', 'publishrank' ); ?></b><select name="image_format"><option value="webp" <?php selected( get_option( 'publishrank_image_format', 'webp' ), 'webp' ); ?>>WebP</option><option value="jpeg" <?php selected( get_option( 'publishrank_image_format', 'webp' ), 'jpeg' ); ?>>JPEG</option><option value="preserve" <?php selected( get_option( 'publishrank_image_format', 'webp' ), 'preserve' ); ?>><?php esc_html_e( 'Preserve original', 'publishrank' ); ?></option></select></label>
					</div>

				<?php else : ?>
					<?php if ( $complete ) : ?>
						<div class="rhs-setup-complete" role="status" aria-live="polite">
							<div class="rhs-setup-complete__icon" aria-hidden="true">✓</div>
							<div class="rhs-setup-complete__copy">
								<span class="rhs-eyebrow"><?php esc_html_e( 'Setup complete', 'publishrank' ); ?></span>
								<h2><?php esc_html_e( 'All setup — thanks for choosing PublishRank.', 'publishrank' ); ?></h2>
								<small><?php esc_html_e( 'Your publisher SEO foundation is configured and ready. You can now manage advanced controls from the PublishRank dashboard.', 'publishrank' ); ?></small>
							</div>
						</div>
					<?php else : ?>
						<span class="rhs-eyebrow"><?php esc_html_e( 'Step 6 · Ready', 'publishrank' ); ?></span>
						<h2><?php esc_html_e( 'PublishRank is ready for your newsroom.', 'publishrank' ); ?></h2>
						<p class="rhs-lead"><?php esc_html_e( 'Review your configuration below, then finish setup. Everything remains editable in PublishRank Settings afterwards.', 'publishrank' ); ?></p>
						<div class="prw-recap">
							<div class="prw-recap__item"><strong><?php echo esc_html( $name ?: get_bloginfo( 'name' ) ); ?></strong><span><?php esc_html_e( 'Publication identity', 'publishrank' ); ?></span></div>
							<div class="prw-recap__item"><strong><?php echo esc_html( $title ?: get_bloginfo( 'name' ) ); ?></strong><span><?php esc_html_e( 'Homepage title', 'publishrank' ); ?></span></div>
							<div class="prw-recap__item"><strong><?php echo $news ? esc_html__( 'News sitemap: enabled', 'publishrank' ) : esc_html__( 'News sitemap: off', 'publishrank' ); ?></strong><span><?php echo esc_html( sprintf( __( 'Freshness window: %d hours', 'publishrank' ), $news_window ) ); ?></span></div>
							<div class="prw-recap__item"><strong><?php echo get_option( 'publishrank_schema_enabled', true ) ? esc_html__( 'Schema graph: on', 'publishrank' ) : esc_html__( 'Schema graph: off', 'publishrank' ); ?></strong><span><?php esc_html_e( 'Technical infrastructure', 'publishrank' ); ?></span></div>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<div class="rhs-onboarding-actions">
					<div class="rhs-onboarding-actions__context">
						<strong><?php esc_html_e( 'PublishRank Setup', 'publishrank' ); ?></strong>
						<small><?php echo esc_html( $complete ? __( 'Setup complete', 'publishrank' ) : sprintf( __( 'Step %1$d of 6', 'publishrank' ), $step ) ); ?></small>
					</div>
					<?php if ( ! $complete ) : ?>
						<a class="prw-exit" href="<?php echo esc_url( $exit_url ); ?>"><?php esc_html_e( 'Exit wizard', 'publishrank' ); ?></a>
						<span></span>
						<?php if ( $step > 1 ) : ?><a class="button" href="<?php echo esc_url( Branding::page_url( self::SLUG ) . '&step=' . ( $step - 1 ) . ( ! empty( $_GET['edit'] ) ? '&edit=1' : '' ) ); ?>"><?php esc_html_e( 'Back', 'publishrank' ); ?></a><?php endif; ?>
						<?php if ( 1 === $step ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( Branding::page_url( self::SLUG ) . '&step=2' . ( ! empty( $_GET['edit'] ) ? '&edit=1' : '' ) ); ?>"><?php esc_html_e( 'Start configuration', 'publishrank' ); ?> <span>→</span></a>
						<?php else : ?>
							<button class="button button-primary rhs-onboarding-next" type="submit"><?php echo esc_html( 6 === $step ? __( 'Finish setup', 'publishrank' ) : __( 'Save & continue', 'publishrank' ) ); ?> <span>→</span></button>
						<?php endif; ?>
					<?php else : ?>
						<a class="button button-primary" href="<?php echo esc_url( Branding::page_url( Branding::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Go to dashboard', 'publishrank' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( Branding::page_url( 'publishrank-seo-settings' ) ); ?>"><?php esc_html_e( 'Open full settings', 'publishrank' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( Branding::page_url( self::SLUG ) . '&step=1' ); ?>"><?php esc_html_e( 'Review setup', 'publishrank' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</div>
		<?php
	}
}
