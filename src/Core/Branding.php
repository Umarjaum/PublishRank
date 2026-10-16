<?php
/** Brand assets and shared admin URLs. */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class Branding {
	const MENU_SLUG = 'publishrank-seo-dashboard';
	const WEBSITE_URL = 'https://publishrank.netlify.app';
	const DEVELOPER_URL = 'https://umarjaum.netlify.app';
	const CONTACT_EMAIL = 'umarjaum@gmail.com';

	/** @param string $path Path inside the plugin assets directory. */
	public static function asset_url( $path ) {
		return defined( 'PUBLISHRANK_SEO_URL' ) ? PUBLISHRANK_SEO_URL . 'assets/' . ltrim( (string) $path, '/' ) : '';
	}

	/** @param string $name File name inside assets/images. */
	public static function image( $name ) {
		return self::asset_url( 'images/' . ltrim( (string) $name, '/' ) );
	}

	public static function version() {
		return defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '2.9.48';
	}

	/** @param string $page Admin page slug. */
	public static function page_url( $page ) {
		return admin_url( 'admin.php?page=' . rawurlencode( (string) $page ) );
	}

	/** True on any PublishRank admin screen. */
	public static function is_plugin_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0 === strpos( $page, 'publishrank' );
	}
}
