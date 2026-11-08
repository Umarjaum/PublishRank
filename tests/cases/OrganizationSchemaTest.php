<?php
/** Regression coverage for connected publisher identity schema. */

use PublishRankSEO\Core\PublicationProfile;

final class OrganizationSchemaTest extends PublishRankTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->set_option( 'publishrank_schema_enabled', 1 );
		$this->set_option( 'publishrank_org_website_schema_enabled', 1 );
		$this->set_option( 'publishrank_identity_type', 'organization' );
		$this->set_option( 'publishrank_identity_name', '' );
		$this->set_option( 'publishrank_seo_publisher_name', '' );
		$this->set_option( 'publishrank_seo_publication_name', '' );
	}

	public function test_identity_is_not_ready_without_publication_and_publisher_names() {
		$this->assertFalse( PublicationProfile::identity_schema_ready() );
	}

	public function test_identity_schema_is_ready_for_a_named_organization_and_website() {
		$this->set_option( 'publishrank_identity_name', 'Example Newsroom' );
		$this->set_option( 'publishrank_seo_publication_name', 'Example Newsroom' );
		$this->assertTrue( PublicationProfile::identity_schema_ready() );
	}

	public function test_unconfigured_identity_does_not_emit_blank_organization_or_website_nodes() {
		$post_id = $this->make_post( array( 'post_title' => 'A sample report' ) );
		$nodes   = $this->schema_nodes( $this->head_for_post( $post_id ) );

		$this->assertNull( $this->node_of_type( $nodes, 'NewsMediaOrganization' ) );
		$this->assertNull( $this->node_of_type( $nodes, 'Organization' ) );
		$this->assertNull( $this->node_of_type( $nodes, 'WebSite' ) );
		$this->assertNotNull( $this->node_of_type( $nodes, 'WebPage' ) );
		$this->assertStringNotContainsString( '#organization', (string) wp_json_encode( $nodes ) );
		$this->assertStringNotContainsString( '#website', (string) wp_json_encode( $nodes ) );
	}

	public function test_named_organization_emits_identity_details_and_connected_website() {
		$this->set_option( 'publishrank_identity_name', 'Example Newsroom' );
		$this->set_option( 'publishrank_seo_publication_name', 'Example Newsroom' );
		$this->set_option( 'publishrank_identity_legal_name', 'Example Newsroom Limited' );
		$this->set_option( 'publishrank_identity_description', 'Independent reporting for the region.' );
		$this->set_option( 'publishrank_social_facebook', 'example-newsroom' );
		$post_id = $this->make_post( array( 'post_title' => 'A sample report' ) );
		$nodes   = $this->schema_nodes( $this->head_for_post( $post_id ) );
		$org     = $this->node_of_type( $nodes, 'NewsMediaOrganization' );
		$website = $this->node_of_type( $nodes, 'WebSite' );

		$this->assertSame( 'Example Newsroom', $org['name'] );
		$this->assertSame( 'Example Newsroom Limited', $org['legalName'] );
		$this->assertSame( 'Independent reporting for the region.', $org['description'] );
		$this->assertContains( 'https://www.facebook.com/example-newsroom', $org['sameAs'] );
		$this->assertSame( 'Example Newsroom', $website['name'] );
		$this->assertSame( array( '@id' => $org['@id'] ), $website['publisher'] );
	}
}
