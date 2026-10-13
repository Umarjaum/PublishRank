<?php
/** Admin styles, media picker script, branded header and flash notices. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class AdminAssets {
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'menu_alignment_css' ) );
		add_action( 'admin_bar_menu', array( $this, 'welcome_admin_bar' ), 90 );
	}

	/** Add a compact, branded welcome link to the admin toolbar. */
	public function welcome_admin_bar( $wp_admin_bar ) {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) { return; }
		$wp_admin_bar->add_node( array( 'id' => 'publishrank-welcome', 'parent' => 'top-secondary', 'title' => '<span class="ab-icon dashicons dashicons-chart-line"></span><span class="ab-label">' . esc_html__( 'Welcome to PublishRank', 'publishrank' ) . '</span>', 'href' => esc_url( Branding::page_url( Branding::MENU_SLUG ) ), 'meta' => array( 'title' => __( 'Welcome to PublishRank', 'publishrank' ) ) ) );
	}

	/** Enqueue the small static stylesheet that keeps the top-level menu icon centered. */
	public function menu_alignment_css() {
		wp_enqueue_style( 'publishrank-seo-menu', Branding::asset_url( 'css/admin-menu.css' ), array(), Branding::version() );
	}

	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_editor = $screen && 'post' === $screen->base && in_array( $screen->post_type, array( 'post', 'page' ), true );
		if ( ! Branding::is_plugin_screen() && ! $on_editor ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'publishrank-seo-admin', Branding::asset_url( 'css/admin.css' ), array( 'dashicons' ), Branding::version() );
		wp_enqueue_media();
		wp_enqueue_script( 'publishrank-seo-admin', Branding::asset_url( 'js/admin.js' ), array( 'jquery' ), Branding::version(), true );
		wp_localize_script(
			'publishrank-seo-admin',
			'publishrankSeo',
			array(
				'chooseImage' => __( 'Choose image', 'publishrank' ),
				'useImage'    => __( 'Use this image', 'publishrank' ),
				'copied'      => __( 'Copied', 'publishrank' ),
				'confirmInsert' => __( 'Insert this approved internal link into published content?', 'publishrank' ),
			)
		);
	}

	/** Branded page header using the PublishRank icon. */
	public static function header( $title, $subtitle = '' ) {
		$icon = 'dashicons-chart-line';
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$icons = array(
			'publishrank-seo-dashboard' => 'dashicons-dashboard',
			'publishrank-onboarding' => 'dashicons-admin-generic',
			'publishrank-seo-settings' => 'dashicons-admin-settings',
			'publishrank-seo-diagnostics' => 'dashicons-visibility',
			'publishrank-seo-redirects' => 'dashicons-randomize',
			'publishrank-seo-404' => 'dashicons-warning',
			'publishrank-seo-robots' => 'dashicons-shield',
			'publishrank-seo-sitemap-health' => 'dashicons-networking',
			'publishrank-seo-insights' => 'dashicons-chart-bar',
			'publishrank-seo-config-history' => 'dashicons-backup',
			'publishrank-llms-txt' => 'dashicons-editor-code',
		);
		if ( isset( $icons[ $page ] ) ) {
			$icon = $icons[ $page ];
		}
		echo '<div class="rhs-header">';
		echo '<div class="rhs-header__icon" aria-hidden="true"><span class="dashicons ' . esc_attr( $icon ) . '"></span></div>';
		echo '<img class="rhs-header__logo" src="' . esc_url( Branding::image( 'publishrank-header-icon-v2.png' ) ) . '" width="40" height="40" alt="" />';
		echo '<div class="rhs-header__text"><h1 class="rhs-header__title">' . esc_html( $title ) . '</h1>';
		if ( '' !== (string) $subtitle ) {
			echo '<p class="rhs-header__sub">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div><span class="rhs-header__badge">v' . esc_html( Branding::version() ) . '</span></div><hr class="wp-header-end" />';
		do_action( 'publishrank_seo_after_admin_header', $page, $title );
	}

	/** Store a one-time notice for the current user. */
	public static function flash( $type, $message ) {
		set_transient( 'publishrank_seo_flash_' . get_current_user_id(), array( 'type' => 'error' === $type ? 'error' : 'success', 'message' => (string) $message ), 120 );
	}

	/** Print and clear the stored notice. */
	public static function render_flash() {
		$key   = 'publishrank_seo_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-' . esc_attr( $flash['type'] ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p></div>';
	}

	/** Verify capability and nonce for an admin-post handler. */
	public static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/** Flash, then return to a PublishRank page. */
	public static function done( $page, $type, $message ) {
		self::flash( $type, $message );
		wp_safe_redirect( Branding::page_url( $page ) );
		exit;
	}
}
