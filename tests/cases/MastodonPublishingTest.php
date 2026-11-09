<?php
/**
 * Regression coverage for Mastodon publishing helpers.
 *
 * @package PublishRankSEO
 */

use PublishRankSEO\Social\Mastodon;

final class MastodonPublishingTest extends PublishRankTestCase {
	public function test_status_length_counts_each_link_as_mastodons_fixed_link_weight() {
		$this->assertSame( 32, Mastodon::length( 'Read https://example.com/page now' ) );
	}

	public function test_media_multipart_payload_includes_alt_text_and_sanitizes_header_fields() {
		$method = new ReflectionMethod( Mastodon::class, 'multipart_body' );
		$method->setAccessible( true );
		$body = $method->invoke( null, 'image-bytes', "cover\r\nX-Injected: yes.jpg", 'image/jpeg', "Alt text\r\nInjected field", 'BOUNDARY' );

		$this->assertStringContainsString( 'name="file"', $body );
		$this->assertStringContainsString( 'Content-Type: image/jpeg', $body );
		$this->assertStringContainsString( 'image-bytes', $body );
		$this->assertStringContainsString( 'name="description"', $body );
		$this->assertStringContainsString( 'Alt text Injected field', $body );
		$this->assertStringNotContainsString( "filename=\"cover\r\n", $body );
		$this->assertStringNotContainsString( "\r\nInjected field\r\n", $body );
		$this->assertStringContainsString( '--BOUNDARY--', $body );
	}
}
