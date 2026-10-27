<?php
/** Admin-only footer branding. */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class FrontendBranding {
	public function register() {
			add_action( 'admin_footer', array( $this, 'render_admin' ), 30 );
	}

	public function render_admin() {
		if ( ! is_admin() ) {
			return; }
		$url = esc_url( 'https://publishrank.netlify.app/' );
		echo '<div style="text-align:center;margin:12px 0 18px;font-size:11px;opacity:.72">' . esc_html__( 'PublishRank — Enterprise SEO Infrastructure for WordPress Publishers', 'publishrank' ) . ' · <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">publishrank.netlify.app</a></div>';
	}
}
