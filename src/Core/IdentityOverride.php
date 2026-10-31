<?php
/**
 * Makes PublishRank's publication title and description the Site Title and Tagline that WordPress prints on the public site.
 *
 * Only the VALUE returned for the public side is replaced (option_blogname / option_blogdescription), so themes, block themes,
 * Elementor widgets, feeds, the REST index and get_bloginfo() all show the PublishRank identity after a single setup. The values
 * stored in WordPress General Settings are never written or changed, the override is not applied in wp-admin (so General
 * Settings still shows and saves the real WordPress values) nor on the settings REST route, and an empty PublishRank value
 * leaves the WordPress value in place (there is nothing to override it with).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class IdentityOverride {
	const OPTION = 'publishrank_override_wp_identity';

	public function register() {
		add_filter( 'option_blogname', array( $this, 'name' ), 20 );
		add_filter( 'option_blogdescription', array( $this, 'description' ), 20 );
		add_action( 'admin_notices', array( $this, 'general_settings_notice' ) );
	}

	/** Missing option means on. */
	public static function enabled() {
		return (bool) get_option( self::OPTION, 1 );
	}

	/** Whether this request may show the override (public side only; never wp-admin, AJAX/admin-post or the settings REST route). */
	private function applies() {
		if ( is_admin() ) {
			return false; }
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false !== strpos( $uri, '/wp/v2/settings' ) || false !== strpos( $route, '/wp/v2/settings' ) ) {
			return false; }
		return self::enabled();
	}

	public function name( $value ) {
		if ( ! $this->applies() ) {
			return $value; }
		$mine = PublicationProfile::publication_name();
		return '' !== $mine ? $mine : $value;
	}

	public function description( $value ) {
		if ( ! $this->applies() ) {
			return $value; }
		$mine = PublicationProfile::publication_description();
		return '' !== $mine ? $mine : $value;
	}

	/** On Settings > General, say that the public site shows the PublishRank values. */
	public function general_settings_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'options-general' !== $screen->id || ! current_user_can( 'manage_options' ) || ! self::enabled() ) {
			return; }
		if ( '' === PublicationProfile::publication_name() && '' === PublicationProfile::publication_description() ) {
			return; }
		echo '<div class="notice notice-info"><p>' . esc_html__( 'PublishRank is overriding the Site Title and Tagline on the public website with the publication title and description set in PublishRank. The values below are left unchanged. Turn this off in PublishRank Settings > Visible publication identity.', 'publishrank' ) . '</p></div>';
	}
}
