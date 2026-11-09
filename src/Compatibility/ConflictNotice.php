<?php
/**
 * Clear admin warning when another SEO plugin is active.
 *
 * Shown to administrators on every PublishRank screen, the Dashboard and the Plugins screen. It is not dismissible while the
 * conflict exists; once PublishRank has been told to own the handoff it drops to a short informational note on PublishRank screens.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\OutputSettings;

defined( 'ABSPATH' ) || exit;

final class ConflictNotice {
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen && isset( $screen->id ) ? (string) $screen->id : '';
		$on_plugin = Branding::is_plugin_screen();
		$on_other  = in_array( $screen_id, array( 'dashboard', 'plugins' ), true );
		if ( ! $on_plugin && ! $on_other ) {
			return; }
		$detector = new ConflictDetector();
		$details  = $detector->details();
		if ( empty( $details ) ) {
			return; }
		$names = array_map( 'esc_html', array_keys( $details ) );
		if ( OutputSettings::external_seo_handoff() ) {
			if ( $on_plugin ) {
				echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Ownership handoff is on.', 'publishrank' ) . '</strong> ' . wp_kses( sprintf( /* translators: %s: plugin names. */ __( '%s is active, so PublishRank is not printing its overlapping title, meta and schema output.', 'publishrank' ), '<strong>' . implode( ', ', $names ) . '</strong>' ), array( 'strong' => array() ) ) . '</p></div>';
			}
			return;
		}
		echo '<div class="notice notice-warning" role="alert" style="border-left-color:#d63638;"><p><strong>' . esc_html__( '⚠️ Warning: another SEO plugin is active.', 'publishrank' ) . '</strong></p>';
		echo '<p>' . wp_kses( sprintf( /* translators: %s: plugin names. */ __( 'Detected: %s. Running two SEO plugins can print duplicate titles, meta descriptions, canonical tags, Open Graph tags, sitemaps and schema, which confuses search engines and can hurt how your pages appear. Use one plugin for SEO output.', 'publishrank' ), '<strong>' . implode( ', ', $names ) . '</strong>' ), array( 'strong' => array() ) ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Open Plugins to deactivate it', 'publishrank' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( Branding::page_url( 'publishrank-seo-portability' ) ) . '">' . esc_html__( 'Import its data first', 'publishrank' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( Branding::page_url( 'publishrank-seo-diagnostics' ) ) . '">' . esc_html__( 'See what was detected', 'publishrank' ) . '</a></p></div>';
	}
}
