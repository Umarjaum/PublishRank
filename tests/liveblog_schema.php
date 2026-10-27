<?php
/**
 * LiveBlogPosting, FAQ de-duplication and attribution tests (stub based; no WordPress install required).
 * Run: php tests/liveblog_schema.php
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
$T = array( 'meta' => array(), 'opts' => array() ); $GLOBALS['T'] = &$T;
class WP_Post { public $ID = 1; public $post_status = 'publish'; }
function get_option( $k, $d = false ) { global $T; return $T['opts'][ $k ] ?? $d; }
function get_post_meta( $id, $k, $single = true ) { global $T; return $T['meta'][ $id ][ $k ] ?? ''; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function get_permalink( $p ) { return 'https://example.test/live-1/'; } function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function get_the_category( $id ) { return array(); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function is_admin() { return $GLOBALS['ctx']['admin'] ?? false; } function wp_doing_ajax() { return $GLOBALS['ctx']['ajax'] ?? false; }
function is_feed() { return $GLOBALS['ctx']['feed'] ?? false; } function is_robots() { return false; } function is_embed() { return false; }
function get_query_var( $v ) { return $GLOBALS['ctx']['sitemap'] ?? ''; }
require_once __DIR__ . '/../src/Meta/PostMeta.php';
require_once __DIR__ . '/../src/Schema/SchemaTypes.php';
require_once __DIR__ . '/../src/Schema/LiveBlogSchema.php';
require_once __DIR__ . '/../src/Schema/GraphOutput.php';
require_once __DIR__ . '/../src/SEO/NewsroomSignals.php';
use PublishRankSEO\Schema\LiveBlogSchema as L; use PublishRankSEO\Schema\GraphOutput as G;

$failed = array(); $n = 0;
$check = function ( $label, $ok ) use ( &$failed, &$n ) { ++$n; echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; if ( ! $ok ) { $failed[] = $label; } };
$post = new WP_Post();

// 1. Ordinary articles are never LiveBlogPosting, including titles that merely contain "live".
$post->post_title = 'Live music festival returns'; $post->post_type = 'post';
$check( '1. ordinary article is not a live blog', false === L::is_live_blog( $post ) );
$check( '1b. "live" in the title does not change the news format', 'live' !== PublishRankSEO\SEO\NewsroomSignals::format( $post ) );
// 2. Explicit schema type or live format enables it.
$T['meta'][1]['_publishrank_seo_schema_type'] = 'LiveBlogPosting';
$check( '2. explicit Live Blog Post schema type enables LiveBlogPosting', true === L::is_live_blog( $post ) );
$T['meta'][1]['_publishrank_seo_schema_type'] = 'NewsArticle'; $T['meta'][1]['_publishrank_seo_news_format'] = 'live';
$check( '2b. explicit live news format enables LiveBlogPosting', true === L::is_live_blog( $post ) );
$T['meta'][1]['_publishrank_seo_news_format'] = '';

// 3-5. Updates, timestamps, missing values.
$base = array( '@type' => 'NewsArticle', '@id' => 'https://example.test/live-1/#article', 'url' => 'https://example.test/live-1/', 'headline' => 'Election night', 'datePublished' => '2026-10-13T18:00:00+00:00', 'coverageStartTime' => '2026-10-13T18:00:00+00:00' );
$bare = L::decorate( $base, $post );
$check( '5. no updates and no coverage times: no liveBlogUpdate and no fabricated coverage times', 'LiveBlogPosting' === $bare['@type'] && ! isset( $bare['liveBlogUpdate'] ) && ! isset( $bare['coverageStartTime'] ) && ! isset( $bare['coverageEndTime'] ) );
$T['meta'][1][ L::UPDATES ] = array(
	array( 'time' => '2026-10-13 20:30:00', 'body' => 'Polls close.', 'headline' => 'Polls closed' ),
	array( 'time' => '2026-10-13 19:05:00', 'body' => 'First results expected soon.' ),
	array( 'time' => 'not a date', 'body' => 'Invalid time is dropped.' ),
	array( 'time' => '2026-10-13 21:00:00', 'body' => '   ' ),
	'garbage',
);
$out = L::decorate( $base, $post );
$check( '4. only valid updates are emitted, newest first, with their real timestamps', 2 === count( $out['liveBlogUpdate'] ) && '2026-10-13T20:30:00+00:00' === $out['liveBlogUpdate'][0]['datePublished'] && '2026-10-13T19:05:00+00:00' === $out['liveBlogUpdate'][1]['datePublished'] );
$check( '4b. updates are BlogPosting nodes with stable fragment URLs', 'BlogPosting' === $out['liveBlogUpdate'][0]['@type'] && 0 === strpos( $out['liveBlogUpdate'][0]['url'], 'https://example.test/live-1/#live-update-' ) );
$check( '3. coverageStartTime defaults to the earliest real update, never the publish time', '2026-10-13T19:05:00+00:00' === $out['coverageStartTime'] );
$T['meta'][1][ L::COVERAGE_END ] = '2026-10-13T23:30:00+00:00';
$check( '3b. explicit coverage end is emitted', '2026-10-13T23:30:00+00:00' === L::decorate( $base, $post )['coverageEndTime'] );
$T['meta'][1][ L::COVERAGE_END ] = '2000-01-01T00:00:00+00:00';
$check( '5b. a coverage end before the start is discarded', ! isset( L::decorate( $base, $post )['coverageEndTime'] ) );

// 8. Malicious input.
$T['meta'][1][ L::UPDATES ] = array( array( 'time' => '2026-10-13 19:00:00', 'body' => '<script>alert(1)</script>Safe text', 'headline' => '<img src=x onerror=1>Head', 'author' => '<b>Eve</b>' ), array( 'time' => str_repeat( '9', 500 ), 'body' => 'x' ) );
$u = L::updates( 1 );
$json = json_encode( L::decorate( $base, $post ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
$check( '8. tags are stripped, overlong timestamps rejected, JSON cannot break out of a script tag', 1 === count( $u ) && false === strpos( $u[0]['body'], '<' ) && false === stripos( $json, '</script' ) && 'Eve' === $u[0]['author'] );
$check( '8b. update count is bounded', L::MAX_UPDATES === 100 );
$T['meta'][1][ L::UPDATES ] = array();

// 6/9. Graph de-duplication.
$news = array( '@type' => 'NewsArticle', '@id' => 'https://example.test/live-1/#article', 'url' => 'https://example.test/live-1/', 'mainEntityOfPage' => array( '@id' => 'https://example.test/live-1/#webpage' ) );
$live = array( '@type' => 'LiveBlogPosting', '@id' => 'https://example.test/live-1/#live', 'url' => 'https://example.test/live-1/', 'mainEntityOfPage' => array( '@id' => 'https://example.test/live-1/#webpage' ) );
$g = G::dedupe_graph( array( array( '@type' => 'WebPage', '@id' => 'https://example.test/live-1/#webpage' ), $news, $live ) );
$types = array_column( $g, '@type' );
$check( '6. NewsArticle and LiveBlogPosting for the same page collapse to one primary entity (LiveBlogPosting)', in_array( 'LiveBlogPosting', $types, true ) && ! in_array( 'NewsArticle', $types, true ) );
$g = G::dedupe_graph( array( $news, array( '@type' => 'NewsArticle', '@id' => 'https://example.test/live-1/#article', 'wordCount' => 5 ) ) );
$check( '9. nodes sharing an @id are merged once', 1 === count( $g ) && 5 === $g[0]['wordCount'] );
$g = G::dedupe_graph( array( array( '@type' => 'FAQPage', '@id' => 'https://x/#faq', 'mainEntity' => array() ) ) );
$check( '9b. revoked FAQPage output is not deduplication-managed', 1 === count( $g ) );
$g = G::dedupe_graph( array( array( '@type' => 'NewsArticle', '@id' => 'https://x/a/#article', 'url' => 'https://x/a/' ), array( '@type' => 'NewsArticle', '@id' => 'https://x/b/#article', 'url' => 'https://x/b/' ) ) );
$check( '7. different pages (e.g. a listing) keep their own article nodes', 2 === count( $g ) );

// Source-level and runtime regression checks for the current article allow-list.
$graph = file_get_contents( __DIR__ . '/../src/Schema/GraphOutput.php' );
$check( '7b. ordinary article type fallback is still NewsArticle', false !== strpos( $graph, "'NewsArticle'" ) );
$check( '7c. only article-allow-listed types reach article JSON-LD', false !== strpos( $graph, 'SchemaTypes::resolve' ) && false !== strpos( $graph, 'SchemaTypes::article_types()' ) && 'Report' === PublishRankSEO\Schema\SchemaTypes::resolve_in( 'Report', PublishRankSEO\Schema\SchemaTypes::article_types(), 'NewsArticle' ) && 'NewsArticle' === PublishRankSEO\Schema\SchemaTypes::resolve_in( 'Review', PublishRankSEO\Schema\SchemaTypes::article_types(), 'NewsArticle' ) );

// Technical provenance behavior is covered by tests/source_attribution.php.
$plugin = file_get_contents( __DIR__ . '/../src/Core/Plugin.php' );
$check( '10. always-on source provenance is registered by the current plugin bootstrap', false !== strpos( $plugin, 'TraceIntegrity::class' ) && is_file( __DIR__ . '/../src/Security/TraceIntegrity.php' ) );

echo "\n$n checks, " . count( $failed ) . " failed\n";
exit( $failed ? 1 : 0 );
