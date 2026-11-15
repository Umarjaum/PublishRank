<?php
/** Shared styles for PublishRank 2.21 module screens (RSS Feeds, Site Navigation, WooCommerce, Image SEO). */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class ModuleUi {
	public static function enqueue() {
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/modules-221.css';
		wp_enqueue_style( 'publishrank-modules-221', Branding::asset_url( 'css/modules-221.css' ), array(), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
	}
}
