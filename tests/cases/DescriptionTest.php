<?php
/**
 * Gap 1: page-specific automatic descriptions and the fallback hierarchy.
 */

use PublishRankSEO\Meta\DescriptionResolver;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Meta\SeoData;

final class DescriptionTest extends PublishRankTestCase {
	const PUB_DESC = 'Test Daily is an independent news publication covering Karachi, Sindh and Pakistan.';

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( 'publishrank_seo_publication_name', 'Test Daily' );
		$this->set_option( 'publishrank_identity_description', self::PUB_DESC );
		$this->set_option( DescriptionResolver::OPTION_FALLBACK, 'page_type' );
		$this->set_option( 'publishrank_home_description', '' );
	}

	private function description_of( $post_id ) {
		return $this->meta( $this->head_for_post( $post_id ), 'description' );
	}

	public function test_populated_page_gets_its_own_description() {
		$id = $this->make_page( array( 'post_title' => 'About', 'post_content' => '<!-- wp:paragraph --><p>We are an independent newsroom covering the province since 2015 with a focus on civic reporting.</p><!-- /wp:paragraph -->' ) );
		$this->assertSame( 'We are an independent newsroom covering the province since 2015 with a focus on civic reporting.', $this->description_of( $id ) );
		$this->assertSame( 'content', DescriptionResolver::for_post( $id )['source'] );
	}

	public function test_form_only_contact_page_does_not_show_raw_shortcode_or_the_global_text() {
		$id   = $this->make_page( array( 'post_title' => 'Contact', 'post_content' => '[contact-form-7 id="12" title="Contact"]' ) );
		$desc = $this->description_of( $id );
		$this->assertStringNotContainsString( 'contact-form-7', $desc, 'Raw shortcode text leaked into the description.' );
		$this->assertStringStartsWith( 'Contact — Test Daily', $desc );
		$this->assertNotSame( self::PUB_DESC, $desc, 'A page with no text must not reuse the global description verbatim.' );
		$this->assertSame( 'page_type', DescriptionResolver::for_post( $id )['source'] );
	}

	public function test_blank_page_uses_the_page_type_fallback() {
		$id = $this->make_page( array( 'post_title' => 'Corrections', 'post_content' => '' ) );
		$this->assertSame( 'page_type', DescriptionResolver::for_post( $id )['source'] );
		$this->assertStringStartsWith( 'Corrections — Test Daily', $this->description_of( $id ) );
	}

	public function test_global_mode_uses_the_publication_description_as_last_resort() {
		$this->set_option( DescriptionResolver::OPTION_FALLBACK, 'global' );
		$id = $this->make_page( array( 'post_title' => 'Contact', 'post_content' => '[contact-form-7 id="1"]' ) );
		$this->assertSame( self::PUB_DESC, $this->description_of( $id ) );
		$this->assertSame( 'global', DescriptionResolver::for_post( $id )['source'] );
	}

	public function test_builder_style_shortcodes_keep_their_text() {
		$id = $this->make_page( array( 'post_content' => '[et_pb_section][et_pb_row][et_pb_text]Reach our editorial desk by phone or email for corrections, tips and press enquiries.[/et_pb_text][/et_pb_row][/et_pb_section]' ) );
		$this->assertSame( 'Reach our editorial desk by phone or email for corrections, tips and press enquiries.', $this->description_of( $id ) );
	}

	public function test_elementor_data_is_extracted_when_post_content_is_empty() {
		$id   = $this->make_page( array( 'post_content' => '' ) );
		$data = array( array( 'elType' => 'section', 'elements' => array( array( 'elType' => 'column', 'elements' => array( array( 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>Our newsroom desk handles tips, corrections and press releases around the clock.</p>' ) ) ) ) ) ) );
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		SeoData::flush();
		$result = DescriptionResolver::for_post( $id );
		$this->assertSame( 'builder', $result['source'] );
		$this->assertSame( 'Our newsroom desk handles tips, corrections and press releases around the clock.', $result['text'] );
	}

	public function test_password_protected_content_never_leaks() {
		$id   = $this->make_page( array( 'post_title' => 'Staff only', 'post_password' => 'secret', 'post_content' => 'The confidential budget figures are listed in full detail below this line.' ) );
		$desc = $this->description_of( $id );
		$this->assertStringNotContainsString( 'confidential', $desc );
		$this->assertSame( 'page_type', DescriptionResolver::for_post( $id )['source'] );
	}

	public function test_manual_description_always_wins_and_reports_override() {
		$id = $this->make_page( array( 'post_content' => 'Some perfectly usable page text that is long enough to be extracted.' ) );
		update_post_meta( $id, PostMeta::DESCRIPTION, 'Hand written summary for search results.' );
		$this->assertSame( 'Hand written summary for search results.', $this->description_of( $id ) );
		$this->assertSame( 'override', DescriptionResolver::for_post( $id )['source'] );
		$this->assertSame( 'override', SeoData::for_post( $id )['sources']['description'] );
	}

	public function test_excerpt_beats_extracted_content() {
		$id = $this->make_post( array( 'post_excerpt' => 'A short hand-written excerpt.', 'post_content' => 'Body text that is long enough to serve as an automatic description on its own.' ) );
		$this->assertSame( 'A short hand-written excerpt.', $this->description_of( $id ) );
		$this->assertSame( 'excerpt', DescriptionResolver::for_post( $id )['source'] );
	}

	public function test_literal_brackets_in_news_copy_are_preserved() {
		$id = $this->make_post( array( 'post_content' => 'The minister said the plan [Reuters] would cover all districts by the end of the year, officials added.' ) );
		$this->assertStringContainsString( '[Reuters]', $this->description_of( $id ) );
	}

	public function test_bare_urls_are_not_used_as_description_text() {
		$id   = $this->make_post( array( 'post_content' => "https://example.com/watch?v=abc123\n\nThe committee published its findings on Thursday after months of hearings." ) );
		$desc = $this->description_of( $id );
		$this->assertStringNotContainsString( 'http', $desc );
		$this->assertStringStartsWith( 'The committee published', $desc );
	}

	public function test_description_is_never_longer_than_160_characters() {
		$id = $this->make_post( array( 'post_content' => str_repeat( 'Long sentence about the budget debate. ', 30 ) ) );
		$this->assertLessThanOrEqual( 160, mb_strlen( $this->description_of( $id ) ) );
	}

	public function test_editor_panel_and_head_agree() {
		$id   = $this->make_page( array( 'post_title' => 'Contact', 'post_content' => '[form id=1]' ) );
		$head = $this->description_of( $id );
		$this->assertSame( $head, SeoData::for_post( $id )['description'], 'Editor/REST/schema description differs from the <head> tag.' );
	}

	public function test_category_archive_without_description_gets_a_page_type_description() {
		$term = $this->make_term( 'Politics', 'category' );
		$head = $this->head_for_url( get_term_link( $term, 'category' ) );
		$desc = $this->meta( $head, 'description' );
		$this->assertStringContainsString( ' — Test Daily', $desc );
		$this->assertNotSame( self::PUB_DESC, $desc );
	}

	public function test_category_archive_with_its_own_description_uses_it() {
		$term = $this->make_term( 'Business', 'category', array( 'description' => 'Markets, trade and the economy across Pakistan.' ) );
		$this->assertSame( 'Markets, trade and the economy across Pakistan.', $this->meta( $this->head_for_url( get_term_link( $term, 'category' ) ), 'description' ) );
	}

	public function test_homepage_uses_the_publication_description_when_no_home_description_is_set() {
		$this->assertSame( self::PUB_DESC, $this->meta( $this->head_for_url( home_url( '/' ) ), 'description' ) );
	}

	public function test_homepage_setting_wins_on_the_homepage() {
		$this->set_option( 'publishrank_home_description', 'The homepage description.' );
		$this->assertSame( 'The homepage description.', $this->meta( $this->head_for_url( home_url( '/' ) ), 'description' ) );
	}
}
