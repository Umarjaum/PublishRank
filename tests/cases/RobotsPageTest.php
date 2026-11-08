<?php
/** Robots.txt screen: renders to the end, so its save button, live preview and URL tester appear. */

use PublishRankSEO\Admin\RobotsPage;

final class RobotsPageTest extends PublishRankTestCase {
	public function test_screen_renders_completely() {
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		if ( ! function_exists( 'settings_errors' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		wp_set_current_user( 1 );
		ob_start();
		try {
			( new RobotsPage() )->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		$this->assertTrue( false !== strpos( $html, 'publishrank_robots_override' ), 'The full-override switch is printed.' );
		$this->assertTrue( false !== strpos( $html, 'Save robots.txt settings' ), 'The save button is printed after the override card.' );
		$this->assertTrue( false !== strpos( $html, 'pr-test-url' ), 'The URL tester at the end of the screen is printed.' );
	}
}
