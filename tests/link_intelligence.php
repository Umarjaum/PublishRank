<?php
/** Standalone tests for Publisher Link Intelligence parsing and classification. */
$GLOBALS['pr_test_options'] = array( 'publishrank_seo_redirects' => array() );
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : ( parse_url( $url, $component ) ?: null ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['pr_test_options'] ) ? $GLOBALS['pr_test_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['pr_test_options'][ $key ] = $value; return true; }
function esc_url_raw( $url ) { return trim( (string) $url ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function __( $text, $domain = '' ) { return $text; }
function url_to_postid( $url ) { return false !== strpos( (string) $url, '/known-page/' ) ? 42 : 0; }
function get_post_status( $id ) { return 42 === (int) $id ? 'publish' : false; }
require_once __DIR__ . '/../src/Redirect/RedirectStore.php';
require_once __DIR__ . '/../src/InternalLinks/PublisherLinkIntelligence.php';
use PublishRankSEO\InternalLinks\PublisherLinkIntelligence;
$links = PublisherLinkIntelligence::extract_links( '<p><a href="/known-page/">Known page</a><a href="/missing-page/">Missing</a><a href="https://other.test/page">External</a><a href="mailto:test@example.test">Email</a></p>' );
if ( 3 !== count( $links ) || 'Known page' !== $links[0]['anchor'] ) { fwrite( STDERR, "Link extraction failed.\n" ); exit( 1 ); }
if ( 'valid_internal' !== PublisherLinkIntelligence::classify_link( '/known-page/' ) ) { fwrite( STDERR, "Valid internal link classification failed.\n" ); exit( 2 ); }
if ( 'broken_internal' !== PublisherLinkIntelligence::classify_link( '/missing-page/' ) ) { fwrite( STDERR, "Broken internal link classification failed.\n" ); exit( 3 ); }
$GLOBALS['pr_test_options']['publishrank_seo_redirects'] = array( array( 'source' => '/old-page', 'target' => '/known-page/', 'status' => 301, 'regex' => 0, 'enabled' => 1 ) );
if ( 'redirected_internal' !== PublisherLinkIntelligence::classify_link( '/old-page/' ) ) { fwrite( STDERR, "Redirect-aware classification failed.\n" ); exit( 4 ); }
if ( 'external' !== PublisherLinkIntelligence::classify_link( 'https://other.test/page' ) ) { fwrite( STDERR, "External link classification failed.\n" ); exit( 5 ); }
$plugin = file_get_contents( __DIR__ . '/../src/Core/Plugin.php' );
if ( false === strpos( $plugin, 'PublisherLinkIntelligence::class' ) ) { fwrite( STDERR, "Module registration missing.\n" ); exit( 6 ); }
echo "Publisher Link Intelligence tests passed.\n";
