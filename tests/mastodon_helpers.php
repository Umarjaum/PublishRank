<?php
/** Standalone checks for Mastodon text and media helpers. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
function sanitize_file_name( $name ) {
	$name = preg_replace( '/[\x00-\x1f\x7f]/', '', (string) $name );
	return preg_replace( '/[^A-Za-z0-9_.-]/', '-', $name );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}
function __( $text, $domain = '' ) {
	return $text;
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
function get_option( $key, $default = false ) {
	return 'publishrank_mastodon_settings' === $key ? array( 'instance' => 'https://mastodon.example' ) : $default;
}
function get_transient( $key ) {
	return 640;
}
require_once __DIR__ . '/../src/Social/Mastodon.php';

use PublishRankSEO\Social\Mastodon;

if ( 32 !== Mastodon::length( 'Read https://example.com/page now' ) ) {
	fwrite( STDERR, "Mastodon link weighting failed.\n" );
	exit( 1 );
}
if ( 1 !== Mastodon::length( '📰' ) || 640 !== Mastodon::character_limit() ) {
	fwrite( STDERR, "Mastodon Unicode or instance-limit handling failed.\n" );
	exit( 4 );
}
$sample = "headline\n\n" . str_repeat( 'a', 20 ) . "\n\nhttps://example.com/story";
$fit    = Mastodon::fit( $sample, 40 );
if ( Mastodon::length( $fit ) > 40 || false === strpos( $fit, 'https://example.com/story' ) ) {
	fwrite( STDERR, "Mastodon text fitting failed.\n" );
	exit( 2 );
}
$method = new ReflectionMethod( Mastodon::class, 'multipart_body' );
$method->setAccessible( true );
$body = $method->invoke( null, 'image-bytes', "cover\r\nX-Injected: yes.jpg", 'image/jpeg', "Alt text\r\nInjected field", 'BOUNDARY' );
if ( false === strpos( $body, 'name="file"' ) || false === strpos( $body, 'Content-Type: image/jpeg' ) || false === strpos( $body, 'image-bytes' ) || false === strpos( $body, 'name="description"' ) || false === strpos( $body, 'Alt text Injected field' ) || false !== strpos( $body, "filename=\"cover\r\n" ) || false !== strpos( $body, "\r\nInjected field\r\n" ) || false === strpos( $body, '--BOUNDARY--' ) ) {
	fwrite( STDERR, "Mastodon media multipart construction failed.\n" );
	exit( 3 );
}
echo "Mastodon helper checks passed\n";
