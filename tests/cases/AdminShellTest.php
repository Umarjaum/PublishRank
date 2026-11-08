<?php
/** Every PublishRank tool screen shares the frame; only the Dashboard keeps its own full-width layout. */

use PublishRankSEO\Admin\AdminShell;

final class AdminShellTest extends PublishRankTestCase {
	public function test_tool_screens_are_wrapped_automatically() {
		$this->assertTrue( AdminShell::is_framed( 'publishrank-schema' ) );
		$this->assertTrue( AdminShell::is_framed( 'publishrank-seo-redirects' ) );
		$this->assertTrue( AdminShell::is_framed( 'uj-newsroom' ) );
	}

	public function test_dashboard_keeps_its_own_layout() {
		$this->assertFalse( AdminShell::is_framed( 'publishrank-seo-dashboard' ) );
		$this->assertFalse( AdminShell::is_shell_page( 'publishrank-seo-dashboard' ) );
	}

	public function test_setup_wizard_uses_the_shared_frame_like_features() {
		$this->assertFalse( AdminShell::is_framed( 'publishrank-onboarding' ), 'The wizard prints the frame itself, so it is never wrapped twice.' );
		$this->assertTrue( AdminShell::is_shell_page( 'publishrank-onboarding' ) );
	}

	public function test_screens_that_print_the_frame_are_not_wrapped_twice() {
		foreach ( array( 'publishrank-modules', 'publishrank', 'publishrank-sitemap-health', 'publishrank-documentation', 'publishrank-onboarding' ) as $slug ) {
			$this->assertFalse( AdminShell::is_framed( $slug ) );
			$this->assertTrue( AdminShell::is_shell_page( $slug ) );
		}
	}

	public function test_unknown_screens_are_left_alone() {
		$this->assertFalse( AdminShell::is_framed( 'some-other-plugin-page' ) );
	}

	public function test_modules_screen_is_listed_as_features() {
		$tool = AdminShell::tool( 'publishrank-modules' );
		$this->assertSame( 'Features', $tool['label'] );
	}

	public function test_frame_lifts_the_header_and_keeps_the_screen_content() {
		$html = '<div class="wrap"><!--pr-header--><div class="rhs-header">HEAD</div><!--/pr-header--><form id="keep">BODY</form></div>';
		$out  = AdminShell::frame( $html, 'publishrank-schema' );
		$this->assertTrue( strpos( $out, 'HEAD' ) < strpos( $out, 'pr-modules-layout' ), 'Header sits above the frame.' );
		$this->assertTrue( strpos( $out, 'pr-modules-nav' ) < strpos( $out, '<form id="keep">BODY</form>' ), 'Screen content sits in the main column.' );
		$this->assertSame( 1, substr_count( $out, 'HEAD' ) );
	}

	public function test_plain_h1_becomes_the_shared_header() {
		$out = AdminShell::frame( '<div class="wrap"><h1>IndexNow</h1><p>BODY</p></div>', 'publishrank-indexnow' );
		$this->assertTrue( false !== strpos( $out, 'rhs-header__title' ) );
		$this->assertSame( 0, substr_count( $out, '<h1>IndexNow</h1>' ) );
		$this->assertTrue( false !== strpos( $out, '<p>BODY</p>' ) );
	}
}
