<?php
/** Sitemap Hub switches include or exclude content types from the sitemap index. */

use PublishRankSEO\Admin\SitemapHealthPage;
use PublishRankSEO\Core\ModuleSwitches;

final class SitemapHubTest extends PublishRankTestCase {
	private $saved = array();

	protected function setUp(): void {
		parent::setUp();
		foreach ( SitemapHealthPage::parts() as $part ) {
			$this->saved[ $part['option'] ] = get_option( $part['option'], null );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $option => $value ) {
			null === $value ? delete_option( $option ) : update_option( $option, $value );
		}
		parent::tearDown();
	}

	public function test_eight_switchable_content_types_plus_posts() {
		$this->assertSame( 8, count( SitemapHealthPage::parts() ) );
	}

	public function test_authors_switch_removes_and_restores_author_sitemap() {
		$engine = ( new \PublishRankSEO\Sitemap\SitemapController() )->engine();
		SitemapHealthPage::set_part( 'author', false );
		$this->assertFalse( SitemapHealthPage::part_on( 'author' ) );
		$this->assertFalse( $engine->provider( 'author' )->is_enabled() );
		SitemapHealthPage::set_part( 'author', true );
		$this->assertTrue( $engine->provider( 'author' )->is_enabled() );
	}

	public function test_attachment_pages_are_off_by_default() {
		delete_option( 'publishrank_sitemap_attachments_enabled' );
		$this->assertFalse( SitemapHealthPage::part_on( 'attachment' ) );
	}

	public function test_news_switch_is_the_same_as_the_news_module() {
		SitemapHealthPage::set_part( 'news', false );
		$this->assertFalse( ModuleSwitches::is_on( 'news_sitemap' ) );
		SitemapHealthPage::set_part( 'news', true );
		$this->assertTrue( ModuleSwitches::is_on( 'news_sitemap' ) );
	}

	public function test_included_count_counts_posts_as_always_on() {
		foreach ( array_keys( SitemapHealthPage::parts() ) as $key ) {
			SitemapHealthPage::set_part( $key, false );
		}
		$this->assertSame( 1, SitemapHealthPage::included_count() );
	}

	public function test_unknown_content_type_is_rejected() {
		$this->assertFalse( SitemapHealthPage::set_part( 'not_a_type', true ) );
	}
}
