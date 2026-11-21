<?php
/**
 * Schema Studio admin screen: templates, visual editor, live JSON-LD preview, graph view, diagnostics,
 * the Schema.org type catalog and template import/export. The screen is a client-side app backed by the
 * publishrank/v1/schema-studio REST routes.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Schema\Studio\Vocabulary;

defined( 'ABSPATH' ) || exit;

final class SchemaStudioPage {

	const SLUG = 'publishrank-schema-studio';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 1003 );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Schema Studio', 'publishrank' ), __( 'Schema Studio', 'publishrank' ), 'publishrank_manage_schema', self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ver  = static function ( $rel ) {
			return PUBLISHRANK_SEO_VERSION . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . $rel );
		};
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'post' === $screen->base ) {
			wp_enqueue_style( 'publishrank-schema-studio-post', PUBLISHRANK_SEO_URL . 'assets/css/schema-studio.css', array(), $ver( 'assets/css/schema-studio.css' ) );
		}
		if ( self::SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( 'publishrank-design-system', PUBLISHRANK_SEO_URL . 'assets/css/design-system.css', array(), $ver( 'assets/css/design-system.css' ) );
		wp_enqueue_style( 'publishrank-schema-studio', PUBLISHRANK_SEO_URL . 'assets/css/schema-studio.css', array( 'publishrank-design-system' ), $ver( 'assets/css/schema-studio.css' ) );
		wp_enqueue_script( 'publishrank-schema-studio', PUBLISHRANK_SEO_URL . 'assets/js/schema-studio.js', array( 'wp-api-fetch' ), $ver( 'assets/js/schema-studio.js' ), true );
		$sample = array();
		if ( isset( $_GET['sample_kind'], $_GET['sample_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sample = array( 'kind' => sanitize_key( wp_unslash( $_GET['sample_kind'] ) ), 'id' => absint( $_GET['sample_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		wp_localize_script(
			'publishrank-schema-studio',
			'publishrankSchemaStudio',
			array(
				'root'    => esc_url_raw( rest_url( 'publishrank/v1/schema-studio' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'home'    => home_url( '/' ),
				'sample'  => $sample,
				'vocab'   => Vocabulary::version(),
				'i18n'    => array(
					'unsaved'        => __( 'Unsaved changes', 'publishrank' ),
					'saved'          => __( 'Saved', 'publishrank' ),
					'previewNote'    => __( 'Preview of this page\'s structured data with your unsaved template applied. It shows markup, not a Google search result: Google decides whether any rich result appears.', 'publishrank' ),
					'confirmDelete'  => __( 'Delete this template? Its last version stays in the revision history.', 'publishrank' ),
					'confirmDiscard' => __( 'Discard your unsaved changes?', 'publishrank' ),
				),
			)
		);
	}

	public function render() {
		if ( ! Capabilities::can( 'manage_schema' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage schema.', 'publishrank' ) );
		}
		echo '<div class="wrap pr-ss">';
		AdminAssets::header( __( 'Schema Studio', 'publishrank' ), __( 'Build structured data from templates: pick a Schema.org type, map each property to a fixed value or a dynamic field, decide where it applies, and check the result live on a real page.', 'publishrank' ) );
		echo '<nav class="pr-ds-tabs" role="tablist" aria-label="' . esc_attr__( 'Schema Studio sections', 'publishrank' ) . '">';
		$tabs = array(
			'templates'   => __( 'Templates', 'publishrank' ),
			'editor'      => __( 'Editor', 'publishrank' ),
			'diagnostics' => __( 'Diagnostics', 'publishrank' ),
			'catalog'     => __( 'Type catalog', 'publishrank' ),
			'transfer'    => __( 'Import & export', 'publishrank' ),
			'settings'    => __( 'Settings', 'publishrank' ),
		);
		foreach ( $tabs as $id => $label ) {
			echo '<button type="button" role="tab" class="pr-ds-tab" id="pr-ss-tab-' . esc_attr( $id ) . '" aria-controls="pr-ss-panel-' . esc_attr( $id ) . '" data-tab="' . esc_attr( $id ) . '" aria-selected="false" tabindex="-1">' . esc_html( $label ) . '</button>';
		}
		echo '</nav>';
		foreach ( array_keys( $tabs ) as $id ) {
			echo '<section class="pr-ss-panel" role="tabpanel" id="pr-ss-panel-' . esc_attr( $id ) . '" aria-labelledby="pr-ss-tab-' . esc_attr( $id ) . '" hidden></section>';
		}
		echo '<noscript><div class="notice notice-warning inline"><p>' . esc_html__( 'Schema Studio needs JavaScript.', 'publishrank' ) . '</p></div></noscript>';
		echo '<div class="pr-ds-toast" role="status" aria-live="polite" id="pr-ss-toast" hidden></div>';
		echo '</div>';
	}
}
