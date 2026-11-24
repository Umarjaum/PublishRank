<?php
/**
 * Media Link Manager: admin screen, REST routes, media-library shortcuts and optional front-end media host rewriting.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\MediaLinks;

use PublishRankSEO\Admin\AdminAssets;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Module {
	const SLUG = 'publishrank-media-links';

	public function register() {
		RestApi::register();
		add_action( 'init', array( HostRewrite::class, 'register' ), 20 );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( $this, 'menu' ), 22 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 1003 );
		add_filter( 'media_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 20, 2 );
	}

	public static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Media Link Manager', 'publishrank' ), __( 'Media Links', 'publishrank' ), Capabilities::cap( 'manage_media_links' ), self::SLUG, array( $this, 'render' ) );
	}

	public function row_action( $actions, $post ) {
		if ( $post instanceof \WP_Post && Capabilities::can( 'manage_media_links' ) && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['publishrank_media_links'] = '<a href="' . esc_url( self::url( array( 'attachment' => $post->ID ) ) ) . '">' . esc_html__( 'Links & URL', 'publishrank' ) . '</a>';
		}
		return $actions;
	}

	public function attachment_field( $fields, $post ) {
		if ( $post instanceof \WP_Post && Capabilities::can( 'manage_media_links' ) && current_user_can( 'edit_post', $post->ID ) ) {
			$fields['publishrank_media_links'] = array(
				'label' => __( 'URL & references', 'publishrank' ),
				'input' => 'html',
				'html'  => '<a class="button" href="' . esc_url( self::url( array( 'attachment' => $post->ID ) ) ) . '">' . esc_html__( 'Open in Media Link Manager', 'publishrank' ) . '</a>',
				'helps' => __( 'See where this file is used and change its address safely with preview and rollback.', 'publishrank' ),
			);
		}
		return $fields;
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page ) {
			return;
		}
		$ver = static function ( $rel ) {
			return PUBLISHRANK_SEO_VERSION . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		};
		wp_enqueue_style( 'publishrank-design-system', \PublishRankSEO\Core\Branding::asset_url( 'css/design-system.css' ), array(), $ver( 'assets/css/design-system.css' ) );
		wp_enqueue_style( 'publishrank-media-links', \PublishRankSEO\Core\Branding::asset_url( 'css/media-links.css' ), array( 'publishrank-design-system' ), $ver( 'assets/css/media-links.css' ) );
		wp_enqueue_script( 'publishrank-media-links', \PublishRankSEO\Core\Branding::asset_url( 'js/media-links.js' ), array( 'wp-api-fetch' ), $ver( 'assets/js/media-links.js' ), true );
		$up = Scanner::uploads();
		wp_localize_script(
			'publishrank-media-links',
			'publishrankMediaLinks',
			array(
				'root'        => esc_url_raw( rest_url( RestApi::NS . RestApi::BASE ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'uploads'     => $up['baseurl'],
				'attachment'  => isset( $_GET['attachment'] ) ? absint( $_GET['attachment'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'canSite'     => Capabilities::can( 'manage_settings' ),
				'redirects'   => class_exists( '\\PublishRankSEO\\Redirect\\RedirectStore' ),
				'pagesOn'     => Migrator::attachment_pages_enabled(),
				'ready'       => Store::ready(),
				'settingsUrl' => Branding::page_url( 'publishrank' ),
			)
		);
	}

	public function render() {
		if ( ! Capabilities::can( 'manage_media_links' ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage media links.', 'publishrank' ) );
		}
		echo '<div class="wrap pr-ml">';
		AdminAssets::header( __( 'Media Link Manager', 'publishrank' ), __( 'Find where every media file is used, change its address with a preview first, check nothing broke, and undo any change.', 'publishrank' ) );
		$tabs = array(
			'library' => __( 'Media library', 'publishrank' ),
			'health'  => __( 'Media health', 'publishrank' ),
			'host'    => __( 'Media host & CDN', 'publishrank' ),
			'history' => __( 'Change history', 'publishrank' ),
		);
		echo '<nav class="pr-ds-tabs" role="tablist" aria-label="' . esc_attr__( 'Media Link Manager sections', 'publishrank' ) . '">';
		foreach ( $tabs as $id => $label ) {
			echo '<button type="button" role="tab" class="pr-ds-tab" id="pr-ml-tab-' . esc_attr( $id ) . '" aria-controls="pr-ml-panel-' . esc_attr( $id ) . '" data-tab="' . esc_attr( $id ) . '" aria-selected="false" tabindex="-1">' . esc_html( $label ) . '</button>';
		}
		echo '</nav>';
		foreach ( array_keys( $tabs ) as $id ) {
			echo '<section class="pr-ml-panel" role="tabpanel" id="pr-ml-panel-' . esc_attr( $id ) . '" aria-labelledby="pr-ml-tab-' . esc_attr( $id ) . '" tabindex="0" hidden></section>';
		}
		echo '<div class="pr-ml-drawer" id="pr-ml-drawer" role="dialog" aria-modal="true" aria-labelledby="pr-ml-drawer-title" hidden></div>';
		echo '<noscript><div class="notice notice-warning inline"><p>' . esc_html__( 'The Media Link Manager needs JavaScript.', 'publishrank' ) . '</p></div></noscript>';
		echo '<div class="pr-ds-toast" role="status" aria-live="polite" id="pr-ml-toast" hidden></div>';
		echo '</div>';
	}
}
