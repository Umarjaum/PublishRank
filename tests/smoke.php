<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
function esc_url_raw( $value ) { return filter_var( $value, FILTER_SANITIZE_URL ); }
function esc_xml( $value ) { return htmlspecialchars( (string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function get_option( $key, $default = false ) { return $default; }
function get_post_meta( $post_id, $key, $single = true ) { return '_publishrank_seo_focus_keyword' === $key ? 'climate news' : ''; }
function wp_parse_url( $url, $component = -1 ) { return $component === -1 ? parse_url( $url ) : ( parse_url( $url, $component ) ?: null ); }
function set_url_scheme( $url ) { return $url; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $text, $domain = '' ) { return $text; }
require_once __DIR__ . '/../src/Sitemap/Providers/SitemapProviderInterface.php';
require_once __DIR__ . '/../src/Sitemap/Engine/SitemapRenderer.php';
require_once __DIR__ . '/../src/Sitemap/SitemapUrls.php';
require_once __DIR__ . '/../src/Sitemap/Engine/SitemapIndex.php';
require_once __DIR__ . '/../src/Core/PublicationProfile.php';
require_once __DIR__ . '/../src/SEO/KeywordDensityChecker.php';
require_once __DIR__ . '/../src/Meta/MetaGenerator.php';
$xml = \PublishRankSEO\Sitemap\Engine\SitemapRenderer::url( 'https://example.test/a?x=1&y=2', '2026-01-01T00:00:00+00:00' );
if ( false === strpos( $xml, '&amp;' ) || false === strpos( $xml, '<lastmod>' ) ) { exit( 1 ); }
$image_xml = \PublishRankSEO\Sitemap\Engine\SitemapRenderer::image( 'https://example.test/image.jpg', 'Headline image', 'Caption text', '', 'https://example.test/license' );
if ( false === strpos( $image_xml, '<image:caption>Caption text</image:caption>' ) || false === strpos( $image_xml, '<image:license>https://example.test/license</image:license>' ) ) { exit( 8 ); }
$provider = new class implements \PublishRankSEO\Sitemap\Providers\SitemapProviderInterface { public function get_name() { return 'Test'; } public function get_slug() { return 'test'; } public function is_enabled() { return true; } public function get_shard_count() { return 2; } public function render_shard( $shard ) { return ''; } };
$index = ( new \PublishRankSEO\Sitemap\Engine\SitemapIndex() )->render( array( $provider ) );
if ( substr_count( $index, '<sitemap>' ) !== 2 || false === strpos( $index, 'publishrank-test-sitemap-2.xml' ) ) { exit( 2 ); }
if ( '' !== \PublishRankSEO\Core\PublicationProfile::normalize_url( 'https://example.test/wp-admin/' ) ) { exit( 3 ); }
if ( 'http://example.test/media/a.jpg' !== \PublishRankSEO\Core\PublicationProfile::normalize_url( 'http://example.test/media/a.jpg' ) ) { exit( 4 ); }
$analysis = ( new \PublishRankSEO\SEO\KeywordDensityChecker() )->analyze( (object) array( 'ID' => 1, 'post_title' => 'Climate news', 'post_content' => 'Climate news is important. ' . str_repeat( 'editorial context ', 150 ) . 'Climate news affects policy.' ) );
if ( 3 !== $analysis['count'] || 'healthy' !== $analysis['status'] ) { exit( 5 ); }
require_once __DIR__ . '/../src/Admin/ImportExport.php';
$meta = new \PublishRankSEO\Meta\MetaGenerator();
if ( 'noindex,nofollow,noarchive' !== $meta->normalize_robots( 'index,noindex,follow,nofollow,noarchive,unsupported' ) ) { exit( 6 ); }
$reflection = new ReflectionClass( '\PublishRankSEO\Admin\ImportExport' );
$property = $reflection->getProperty( 'allowed' ); $property->setAccessible( true );
$allowed = $property->getValue( $reflection->newInstanceWithoutConstructor() );
if ( in_array( 'publishrank_indexnow_key', $allowed, true ) ) { exit( 7 ); }

// 2.0.0 additions: pure-string helpers.
require_once __DIR__ . '/../src/Robots/RobotsTxt.php';
require_once __DIR__ . '/../src/Redirect/RedirectStore.php';
require_once __DIR__ . '/../src/Admin/FrontendScanner.php';
$rules = \PublishRankSEO\Robots\RobotsTxt::clean_rules( "user-agent: *\nDISALLOW: /private/\nNoindex: /x\n<script>alert(1)</script>\nSitemap: javascript:alert(1)\nSitemap: https://example.test/s.xml\nCrawl-delay: abc" );
if ( "User-agent: *\nDisallow: /private/\nSitemap: https://example.test/s.xml" !== $rules ) { fwrite( STDERR, $rules . "\n" ); exit( 9 ); }
$bulk = \PublishRankSEO\Redirect\RedirectStore::parse_bulk( "# comment\n/old-1 /new-1\n/old-2,/new-2,302\n/gone 410\n" );
if ( 3 !== count( $bulk ) || 302 !== $bulk[1]['status'] || 410 !== $bulk[2]['status'] || '' !== $bulk[2]['target'] ) { exit( 10 ); }
$counts = \PublishRankSEO\Admin\FrontendScanner::analyze( '<html><head><title>x</title><link rel="canonical" href="a"><link rel="canonical" href="b"><meta name="description" content="d"><!-- PublishRank: begin --></head></html>' );
if ( 2 !== $counts['canonical'] || 1 !== $counts['description'] || 1 !== $counts['publishrank'] ) { exit( 11 ); }
echo "smoke tests passed\n";
