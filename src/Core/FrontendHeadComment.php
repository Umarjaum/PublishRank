<?php
/** Optional source comment identifying PublishRank when the administrator opts in. */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class FrontendHeadComment {
	public function register() {
		// WordPress.org guideline 10: public-facing credits are opt-in and off by default.
		if ( ! get_option( 'publishrank_source_attribution', false ) ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'render' ), 0 );
	}

	public function render() {
		if ( is_admin() || wp_doing_ajax() || is_feed() ) {
			return;
		}
		echo "<!-- Powered by PublishRank SEO -->\n";
	}
}
