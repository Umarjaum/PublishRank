<?php
/** Standalone tests for pure internal-link intelligence helpers. */
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function get_the_title( $post ) { return $post->post_title; }
function get_post_meta( $id, $key, $single = true ) { return 1 === $id ? 'climate policy, renewable energy' : ''; }
function get_object_taxonomies( $post, $output = 'names' ) { return array( 'category' => (object) array( 'public' => true, 'hierarchical' => true ), 'post_tag' => (object) array( 'public' => true, 'hierarchical' => false ) ); }
function wp_get_post_terms( $id, $taxonomy, $args = array() ) { return 'category' === $taxonomy ? array( (object) array( 'term_id' => 10, 'name' => 'Climate' ) ) : array( (object) array( 'term_id' => 20, 'name' => 'Policy' ) ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : ( parse_url( $url, $component ) ?: null ); }
function url_to_postid( $url ) { return false !== strpos( $url, '/target/' ) ? 2 : 0; }
function is_ssl() { return true; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function __( $text, $domain = '' ) { return $text; }
class WP_Error { private $message; public function __construct( $code = '', $message = '' ) { $this->message = $message; } public function get_error_message() { return $this->message; } }

require_once __DIR__ . '/../src/InternalLinks/FeatureExtractor.php';
require_once __DIR__ . '/../src/InternalLinks/Scanner.php';
require_once __DIR__ . '/../src/InternalLinks/LinkInserter.php';

$post = (object) array(
	'ID' => 1,
	'post_title' => 'Climate policy update',
	'post_content' => 'Climate policy and renewable energy shape this report.',
	'post_modified_gmt' => '2026-01-01 00:00:00',
	'post_type' => 'post',
);
$features = \PublishRankSEO\InternalLinks\FeatureExtractor::from_post( $post );
if ( empty( $features['vector']['climate'] ) || empty( $features['terms']['climate policy'] ) ) { exit( 1 ); }
if ( empty( $features['taxonomy_ids']['category'] ) || 10 !== (int) $features['taxonomy_ids']['category'][0] ) { exit( 2 ); }

$content = '<p>Climate policy remains important.</p><p>Second paragraph.</p>';
$linked  = \PublishRankSEO\InternalLinks\LinkInserter::insert( $content, 'https://example.test/target/', 'climate policy' );
if ( is_wp_error( $linked ) || 1 !== substr_count( $linked, 'href=' ) ) { exit( 3 ); }
if ( ! \PublishRankSEO\InternalLinks\LinkInserter::content_links_to_post( $linked, 2 ) ) { exit( 4 ); }
$duplicate = \PublishRankSEO\InternalLinks\LinkInserter::insert( $linked, 'https://example.test/target/', 'climate policy' );
if ( ! is_wp_error( $duplicate ) ) { exit( 5 ); }
if ( 2 !== \PublishRankSEO\InternalLinks\Scanner::resolve_post_id( '/target/' ) ) { exit( 6 ); }

$root = dirname( __DIR__ );
foreach ( array( 'src/InternalLinks/Store.php', 'src/InternalLinks/Scanner.php', 'src/InternalLinks/Recommender.php', 'src/InternalLinks/InternalLinksPage.php' ) as $file ) {
	if ( ! is_readable( $root . '/' . $file ) ) { exit( 7 ); }
}
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' );
if ( false === strpos( $plugin, 'InternalLinksModule' ) ) { exit( 8 ); }
$uninstall = file_get_contents( $root . '/uninstall.php' );
if ( false === strpos( $uninstall, 'Store::drop' ) ) { exit( 9 ); }
echo "internal link tests passed\n";
