<?php
/**
 * Visual-only suppression of the theme's visible site title and tagline.
 *
 * The publication title and description are owned by PublishRank (see PublicationProfile) and reach search engines through
 * titles, meta tags, Open Graph and JSON-LD. The theme's own visible copy of the WordPress Site Title/Tagline is therefore hidden
 * on screen by default (both switches default to on; the publisher can turn each off). The text stays in the page for
 * assistive technology and crawlers (visually-hidden technique, not display:none), and SEO output is untouched.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class FrontendVisibility {
	const TITLE_OPTION       = 'publishrank_hide_visible_site_title';
	const DESCRIPTION_OPTION = 'publishrank_hide_visible_site_description';

	public function register() {
		add_action( 'wp_head', array( $this, 'styles' ), 3 );
		add_action( 'admin_notices', array( $this, 'missing_identity_notice' ) );
	}

	/** Missing options mean "default": hidden. Only an explicit 0 shows the element. */
	public static function hides_title() {
		return (bool) get_option( self::TITLE_OPTION, 1 );
	}

	public static function hides_description() {
		return (bool) get_option( self::DESCRIPTION_OPTION, 1 );
	}

	/** @return string[] CSS selectors of the theme elements to hide. */
	public static function selectors() {
		$selectors = array();
		if ( self::hides_title() ) {
			$selectors = array_merge(
				$selectors,
				array( 'body .site-title', 'body #site-title', 'body .wp-block-site-title', 'body header .site-branding > a.site-title', 'body .elementor-widget-theme-site-title', 'body .elementor-widget-site-title' )
			);
		}
		if ( self::hides_description() ) {
			$selectors = array_merge(
				$selectors,
				array( 'body .site-description', 'body #site-description', 'body .wp-block-site-tagline', 'body header .site-branding > p.site-description' )
			);
		}
		/**
		 * Filter the selectors hidden by the publication-identity switches.
		 *
		 * @param string[] $selectors CSS selectors.
		 */
		$selectors = (array) apply_filters( 'publishrank_hidden_identity_selectors', array_values( array_unique( $selectors ) ) );
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $selectors ) ) ) );
	}

	/** Visually-hidden CSS for the selectors (kept readable by screen readers and crawlers). */
	public static function css() {
		$selectors = self::selectors();
		if ( empty( $selectors ) ) {
			return ''; }
		return implode( ',', $selectors ) . '{position:absolute!important;width:1px!important;height:1px!important;margin:-1px!important;padding:0!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important;}';
	}

	public function styles() {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || ( function_exists( 'is_embed' ) && is_embed() ) ) {
			return; }
		$css = self::css();
		if ( '' === $css ) {
			return; }
		echo '<style id="publishrank-hide-publication-identity">' . esc_html( $css ) . '</style>' . "\n";
	}

	/** Titles, meta and schema use the PublishRank values only, so say so when they are empty (PublishRank screens only). */
	public function missing_identity_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'publishrank' ) ) {
			return; }
		$missing = array();
		if ( '' === PublicationProfile::publication_name() ) {
			$missing[] = __( 'publication title', 'publishrank' ); }
		if ( '' === PublicationProfile::publication_description() ) {
			$missing[] = __( 'publication description', 'publishrank' ); }
		if ( empty( $missing ) ) {
			return; }
		echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: %s: list of missing settings. */ __( 'PublishRank uses its own publication identity, not the WordPress Site Title or Tagline. Set the %s in the setup wizard or Identity settings so titles, meta tags and structured data are complete.', 'publishrank' ), implode( ' / ', $missing ) ) ) . '</p></div>';
	}
}
