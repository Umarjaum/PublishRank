<?php
/** Every version marker in the package must match the plugin header. */

use PublishRankSEO\Core\Branding;

final class VersionConsistencyTest extends PublishRankTestCase {
	private function root() {
		return dirname( __DIR__, 2 ) . '/';
	}

	private function header_version() {
		$source = file_get_contents( $this->root() . 'publishrank.php' );
		$this->assertTrue( 1 === preg_match( '/^\s*\*?\s*Version:\s*([0-9.]+)/m', $source, $match ), 'Plugin header has no Version.' );
		return $match[1];
	}

	public function test_constant_and_branding_match_the_plugin_header() {
		$this->assertSame( $this->header_version(), PUBLISHRANK_SEO_VERSION );
		$this->assertSame( $this->header_version(), Branding::version() );
	}

	public function test_readme_and_changelog_match_the_plugin_header() {
		$readme = file_get_contents( $this->root() . 'readme.txt' );
		$this->assertTrue( 1 === preg_match( '/^Stable tag:\s*([0-9.]+)/m', $readme, $stable ) );
		$this->assertSame( $this->header_version(), $stable[1] );

		$changelog = file_get_contents( $this->root() . 'changelog.txt' );
		$this->assertTrue( 1 === preg_match( '/^= ([0-9.]+) =/m', $changelog, $top ) );
		$this->assertSame( $this->header_version(), $top[1] );
	}

	public function test_official_website_is_the_vercel_address() {
		$this->assertSame( 'https://publishrankseo.vercel.app/', Branding::website_url() );
	}
}
