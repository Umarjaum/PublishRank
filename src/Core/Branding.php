<?php
/** Brand assets and shared admin URLs. */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class Branding {
	const MENU_SLUG     = 'publishrank-seo-dashboard';
	const WEBSITE_URL   = 'https://publishrankseo.vercel.app';
	const DEVELOPER_URL = 'https://umarjaum.netlify.app';
	const CONTACT_EMAIL = 'umarjaum@gmail.com';
	/** The one official PublishRank logo (white mark on a transparent background) in assets/images. */
	const LOGO_FILE     = 'publishrank-brand-mark.png';
	/** Animated PublishRank logo used as the admin loader. */
	const LOADER_FILE   = 'publishrank-logo-animated.gif';
	/** Deep bordeaux tile colour that keeps the white mark readable on light screens. */
	const LOGO_TILE     = '#650A24';

	/** @param string $path Path inside the plugin assets directory. */
	public static function asset_url( $path ) {
		return defined( 'PUBLISHRANK_SEO_URL' ) ? PUBLISHRANK_SEO_URL . 'assets/' . ltrim( (string) $path, '/' ) : '';
	}

	/** @param string $name File name inside assets/images. */
	public static function image( $name ) {
		return self::asset_url( 'images/' . ltrim( (string) $name, '/' ) );
	}

	/** URL of the official PublishRank logo, cache-busted with the plugin version. */
	public static function logo_url() {
		return add_query_arg( 'ver', rawurlencode( self::version() ), self::image( self::LOGO_FILE ) );
	}

	/** URL of the animated loader GIF, cache-busted with the plugin version. */
	public static function loader_url() {
		return add_query_arg( 'ver', rawurlencode( self::version() ), self::image( self::LOADER_FILE ) );
	}

	/** The official PublishRank website (https://publishrankseo.vercel.app/). */
	public static function website_url() {
		return trailingslashit( self::WEBSITE_URL );
	}

	public static function version() {
		return defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '2.18.4';
	}

	/** @param string $page Admin page slug. */
	public static function page_url( $page ) {
		return admin_url( 'admin.php?page=' . rawurlencode( (string) $page ) );
	}

	/** True on any PublishRank admin screen. */
	public static function is_plugin_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0 === strpos( $page, 'publishrank' ) || 0 === strpos( $page, 'uj-newsroom' );
	}
}
