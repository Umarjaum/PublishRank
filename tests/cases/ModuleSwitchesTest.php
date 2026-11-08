<?php
/** SEO Modules switches write the feature's own option and read back the saved state. */

use PublishRankSEO\Core\ModuleSwitches;
use PublishRankSEO\Robots\RobotsTxt;

final class ModuleSwitchesTest extends PublishRankTestCase {
	private $saved = array();

	protected function setUp(): void {
		parent::setUp();
		foreach ( ModuleSwitches::definitions() as $module ) {
			$this->saved[ $module['option'] ] = get_option( $module['option'], null );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $option => $value ) {
			null === $value ? delete_option( $option ) : update_option( $option, $value );
		}
		parent::tearDown();
	}

	public function test_twelve_modules_are_listed() {
		$this->assertSame( 12, count( ModuleSwitches::definitions() ) );
	}

	public function test_404_monitor_turns_on_even_though_its_registered_default_is_true() {
		delete_option( 'publishrank_seo_404_monitor' );
		ModuleSwitches::set( 'monitor_404', true );
		$this->assertTrue( ModuleSwitches::is_on( 'monitor_404' ) );
		$this->assertTrue( (bool) get_option( 'publishrank_seo_404_monitor', false ) );
		ModuleSwitches::set( 'monitor_404', false );
		$this->assertFalse( ModuleSwitches::is_on( 'monitor_404' ) );
	}

	public function test_redirects_switch_writes_the_redirect_engine_option() {
		ModuleSwitches::set( 'redirects', false );
		$this->assertFalse( (bool) get_option( 'publishrank_redirect_enabled', true ) );
		ModuleSwitches::set( 'redirects', true );
		$this->assertTrue( (bool) get_option( 'publishrank_redirect_enabled', false ) );
	}

	public function test_sitemap_off_removes_robots_sitemap_lines() {
		ModuleSwitches::set( 'sitemap', false );
		$this->assertSame( array(), RobotsTxt::sitemap_urls() );
		ModuleSwitches::set( 'sitemap', true );
		$this->assertTrue( count( RobotsTxt::sitemap_urls() ) >= 1 );
	}

	public function test_unknown_module_is_rejected() {
		$this->assertFalse( ModuleSwitches::set( 'not_a_module', true ) );
		$this->assertFalse( ModuleSwitches::is_on( 'not_a_module' ) );
	}
}
