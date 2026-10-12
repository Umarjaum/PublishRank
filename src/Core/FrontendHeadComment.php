<?php
/** Optional source comment identifying PublishRank when the administrator opts in. */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class FrontendHeadComment {
	public function register() {
		add_action( 'wp_head', array( $this, 'render' ), 0 );
	}

	public function render() {
		if ( is_admin() || wp_doing_ajax() || is_feed() ) {
			return;
		}
		echo "<!-- SEO of this website is Powered by PublishRank -->\n";
	}
}
