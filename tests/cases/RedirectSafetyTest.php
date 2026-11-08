<?php
/** Regression coverage for redirect source validation and cycle detection. */

use PublishRankSEO\Redirect\RedirectController;
use PublishRankSEO\Redirect\RedirectStore;

final class RedirectSafetyTest extends PublishRankTestCase {
	public function test_foreign_host_source_is_rejected_instead_of_becoming_a_local_path() {
		$result = RedirectStore::build_rule( 'https://foreign.example/old-page', '/new-page' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'publishrank_redirect_source_host', $result->get_error_code() );
	}

	public function test_full_source_url_on_this_site_is_accepted() {
		$result = RedirectStore::build_rule( home_url( '/old-page' ), '/new-page' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( '/old-page', $result['source'] );
	}

	public function test_cycle_beyond_configured_hop_limit_is_still_detected() {
		$this->set_option( 'publishrank_redirect_max_hops', 1 );
		$this->set_option(
			RedirectStore::OPTION,
			array(
				array( 'source' => '/a', 'target' => '/b', 'enabled' => 1, 'status' => 301 ),
				array( 'source' => '/b', 'target' => '/c', 'enabled' => 1, 'status' => 301 ),
				array( 'source' => '/c', 'target' => '/b', 'enabled' => 1, 'status' => 301 ),
			)
		);

		$controller = new RedirectController();
		$method     = new ReflectionMethod( $controller, 'is_loop' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( $controller, '/a', '/b' ) );
	}

	public function test_cycle_through_regex_rule_is_detected() {
		$this->set_option( 'publishrank_redirect_max_hops', 10 );
		$this->set_option(
			RedirectStore::OPTION,
			array(
				array( 'source' => '^/b$', 'target' => '/c', 'regex' => 1, 'enabled' => 1, 'status' => 301 ),
				array( 'source' => '/c', 'target' => '/b', 'regex' => 0, 'enabled' => 1, 'status' => 301 ),
			)
		);

		$controller = new RedirectController();
		$method     = new ReflectionMethod( $controller, 'is_loop' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( $controller, '/a', '/b' ) );
	}
}
