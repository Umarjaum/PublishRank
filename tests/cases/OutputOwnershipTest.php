<?php
/** Regression coverage for opt-in ownership handoff with supported SEO plugins. */

use PublishRankSEO\Compatibility\ConflictDetector;
use PublishRankSEO\Core\OutputSettings;
use PublishRankSEO\Schema\GraphOutput;

final class OutputOwnershipTest extends PublishRankTestCase {
	private $detected_plugin_filter;

	protected function tearDown(): void {
		if ( $this->detected_plugin_filter ) {
			remove_filter( 'publishrank_detected_seo_plugins', $this->detected_plugin_filter );
		}
		parent::tearDown();
	}

	public function test_handoff_is_off_by_default_and_existing_metadata_switches_remain_unchanged() {
		$this->set_option( OutputSettings::EXTERNAL_SEO_HANDOFF, 0 );
		$this->set_option( OutputSettings::META, 1 );
		$this->set_option( OutputSettings::SCHEMA, 1 );

		$this->assertFalse( OutputSettings::external_seo_handoff_preference() );
		$this->assertSame( OutputSettings::meta(), OutputSettings::metadata_output() );
		$this->assertSame( OutputSettings::schema(), OutputSettings::schema_graph_output() );
	}

	public function test_opted_in_handoff_suppresses_only_publishrank_metadata_and_page_graph_when_plugin_detected() {
		$this->detected_plugin_filter = static function ( $active ) {
			$active[] = 'Example SEO Plugin';
			return $active;
		};
		add_filter( 'publishrank_detected_seo_plugins', $this->detected_plugin_filter );
		$this->set_option( OutputSettings::EXTERNAL_SEO_HANDOFF, 1 );
		$this->set_option( OutputSettings::META, 1 );
		$this->set_option( OutputSettings::SCHEMA, 1 );

		$this->assertTrue( OutputSettings::external_seo_handoff() );
		$this->assertFalse( OutputSettings::metadata_output() );
		$this->assertFalse( OutputSettings::schema_graph_output() );
		$this->assertTrue( OutputSettings::meta(), 'Handoff must not rewrite the saved PublishRank metadata preference.' );
		$this->assertTrue( OutputSettings::schema(), 'Handoff must not rewrite the saved PublishRank schema preference.' );
		$this->assertSame( 'PASS', ( new ConflictDetector() )->diagnostics()['status'] );
	}

	public function test_handoff_preference_alone_does_not_suppress_publishrank_when_no_plugin_is_detected() {
		$this->detected_plugin_filter = static function ( $active ) {
			return array();
		};
		add_filter( 'publishrank_detected_seo_plugins', $this->detected_plugin_filter );

		$this->set_option( OutputSettings::EXTERNAL_SEO_HANDOFF, 1 );
		$this->set_option( OutputSettings::META, 1 );
		$this->set_option( OutputSettings::SCHEMA, 1 );

		$this->assertFalse( OutputSettings::external_seo_handoff() );
		$this->assertTrue( OutputSettings::metadata_output() );
		$this->assertTrue( OutputSettings::schema_graph_output() );
	}

	public function test_frontend_head_omits_publishrank_metadata_and_page_graph_during_handoff() {
		$this->detected_plugin_filter = static function ( $active ) {
			$active[] = 'Example SEO Plugin';
			return $active;
		};
		add_filter( 'publishrank_detected_seo_plugins', $this->detected_plugin_filter );
		$this->set_option( OutputSettings::EXTERNAL_SEO_HANDOFF, 1 );
		$this->set_option( OutputSettings::META, 1 );
		$this->set_option( OutputSettings::SCHEMA, 1 );
		$post_id = $this->make_post( array( 'post_title' => 'Ownership handoff check' ) );
		$head    = $this->head_for_post( $post_id );

		$this->assertStringNotContainsString( '<!-- PublishRank: begin;', $head );
		$this->assertStringNotContainsString( 'property="og:title"', $head );
		$this->assertStringNotContainsString( 'application/ld+json', $head );
	}

	public function test_graph_dedupes_breadcrumb_lists_even_when_ids_differ() {
		$graph = array(
			array(
				'@type'      => 'WebPage',
				'@id'        => home_url( '/story/' ) . '#webpage',
				'breadcrumb' => array( '@id' => home_url( '/story/' ) . '#breadcrumb-old' ),
			),
			array(
				'@type'           => 'BreadcrumbList',
				'@id'             => home_url( '/story/' ) . '#breadcrumb-old',
				'itemListElement' => array( array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home' ) ),
			),
			array(
				'@type'           => 'BreadcrumbList',
				'@id'             => home_url( '/story/' ) . '#breadcrumb-new',
				'itemListElement' => array(
					array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home' ),
					array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Story' ),
				),
			),
		);

		$deduped = GraphOutput::dedupe_graph( $graph );
		$lists   = array_values( array_filter( $deduped, static function ( $node ) {
			return isset( $node['@type'] ) && 'BreadcrumbList' === $node['@type'];
		} ) );

		$this->assertCount( 1, $lists );
		$this->assertSame( home_url( '/story/' ) . '#breadcrumb-new', $lists[0]['@id'] );
		$this->assertSame( $lists[0]['@id'], $deduped[0]['breadcrumb']['@id'] );
	}
}
