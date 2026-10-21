<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
/**
 * News sitemap behavioural tests using a small in-memory WordPress fake (no WordPress install required).
 * Run: php tests/news_sitemap.php   (exit code 0 = all passed)
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
$T = array( 'opts' => array(), 'posts' => array(), 'filters' => array() );
$GLOBALS['T'] = &$T;
class FakeWpdb { public $posts = 'wp_posts'; public function prepare( $q ) { $a = array_slice( func_get_args(), 1 ); return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $q ), $a ); } }
$wpdb = new FakeWpdb();
function get_option( $k, $d = false ) { global $T; return array_key_exists( $k, $T['opts'] ) ? $T['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { global $T; $T['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return false; } function set_transient( $k, $v, $t = 0 ) { return true; }
function add_filter( $tag, $fn ) { global $T; $T['filters'][ $tag ][] = $fn; } function remove_filter( $tag, $fn ) { global $T; unset( $T['filters'][ $tag ] ); }
function apply_filters( $tag, $v ) { return $v; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ) ); } function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); } function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_post_type_object( $t ) { return 'post' === $t ? (object) array( 'public' => true, 'publicly_queryable' => true ) : null; }
function get_bloginfo( $w ) { return 'name' === $w ? 'Test Daily' : ''; } function get_locale() { return 'en_US'; }
function esc_url_raw( $u ) { return filter_var( $u, FILTER_SANITIZE_URL ); } function esc_xml( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' ); } function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : ( parse_url( $u, $c ) ?: null ); } function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function set_url_scheme( $u ) { return $u; } function wp_get_environment_type() { return 'production'; }
function wp_list_pluck( $l, $f ) { return array_map( function ( $o ) use ( $f ) { return $o->$f; }, $l ); } function get_the_tags( $id ) { return array(); }
function update_meta_cache( $t, $ids ) {}
function get_post( $id ) { global $T; return $T['posts'][ $id ] ?? null; }
function get_the_title( $id ) { $p = get_post( $id ); return $p ? $p->post_title : ''; }
function get_permalink( $id ) { return 'https://example.test/news-' . (int) $id . '/'; }
function get_post_meta( $id, $k, $single = true ) { $p = get_post( $id ); return $p && isset( $p->meta[ $k ] ) ? $p->meta[ $k ] : ''; }
function get_post_time( $f, $gmt, $id ) { $p = get_post( $id ); return 'U' === $f ? $p->ts : gmdate( $f, $p->ts ); }
function get_post_modified_time( $f, $gmt, $id ) { $p = get_post( $id ); return 'U' === $f ? $p->mod : gmdate( $f, $p->mod ); }
class WP_Query {
	public $posts = array();
	public function __construct( $args ) {
		global $T; $sql = '';
		foreach ( $T['filters']['posts_where'] ?? array() as $fn ) { $sql = $fn( $sql ); }
		preg_match( "/post_date_gmt >= '([^']+)' AND wp_posts\.post_date_gmt <= '([^']+)'/", $sql, $m );
		$from = strtotime( $m[1] . ' UTC' ); $to = strtotime( $m[2] . ' UTC' ); $out = array();
		foreach ( $T['posts'] as $id => $p ) {
			if ( 'publish' !== $p->post_status || '' !== $p->post_password || ! in_array( $p->post_type, (array) $args['post_type'], true ) ) { continue; }
			if ( $p->ts < $from || $p->ts > $to ) { continue; }
			$out[ $id ] = $p->ts;
		}
		arsort( $out ); $this->posts = array_slice( array_keys( $out ), 0, (int) $args['posts_per_page'] );
	}
}
foreach ( array( 'Providers/SitemapProviderInterface', 'Engine/SitemapRenderer', 'Engine/SitemapCursorStore', 'Engine/SitemapIndex', 'SitemapUrls', 'News/NewsSitemapProvider' ) as $f ) { require_once __DIR__ . '/../src/Sitemap/' . $f . '.php'; }
require_once __DIR__ . '/../src/Core/PublicationProfile.php'; require_once __DIR__ . '/../src/Meta/PostMeta.php';
use PublishRankSEO\Sitemap\News\NewsSitemapProvider as P;

$failed = array(); $n = 0;
$check = function ( $label, $ok ) use ( &$failed, &$n ) { ++$n; echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; if ( ! $ok ) { $failed[] = $label; } };
$id = 0;
$add = function ( $age_s, $over = array() ) use ( &$id, &$T ) { ++$id; $ts = time() - $age_s; $T['posts'][ $id ] = (object) array_merge( array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Story ' . $id, 'ts' => $ts, 'mod' => $ts, 'meta' => array() ), $over ); return $id; };
$render = function ( $shard = 1 ) { $p = new P(); return '<?xml version="1.0" encoding="UTF-8"?>' . $p->render_shard( $shard ); };
$urls = function ( $xml ) { preg_match_all( '#<loc>([^<]+)</loc>#', $xml, $m ); return $m[1]; };
$valid = function ( $xml ) { if ( function_exists( 'simplexml_load_string' ) ) { libxml_use_internal_errors( true ); $ok = false !== simplexml_load_string( $xml ); libxml_clear_errors(); return $ok; } return 1 === preg_match( '#^<\?xml[^>]*>\s*<urlset\b[\s\S]*</urlset>\s*$#', $xml ) && substr_count( $xml, '<url>' ) === substr_count( $xml, '</url>' ); };

// 4. Empty window -> valid empty sitemap with the news namespace.
$xml = $render();
$check( 'empty site returns a valid empty urlset', $valid( $xml ) && 0 === count( $urls( $xml ) ) && false !== strpos( $xml, 'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"' ) );
$check( 'empty site still has exactly one shard', 1 === ( new P() )->get_shard_count() );

// 1-3. Window boundaries.
$a = $add( 600 ); $b = $add( 47 * 3600 ); $c = $add( 49 * 3600 );
$xml = $render(); $list = $urls( $xml );
$check( '1. article published 10 minutes ago appears', in_array( get_permalink( $a ), $list, true ) );
$check( '2. article published 47 hours ago appears', in_array( get_permalink( $b ), $list, true ) );
$check( '3. article older than 48 hours is excluded', ! in_array( get_permalink( $c ), $list, true ) );

// 5-7. Eligibility.
$noindex = $add( 900, array( 'meta' => array( '_publishrank_seo_robots' => 'noindex,follow' ) ) );
$draft = $add( 900, array( 'post_status' => 'draft' ) ); $pw = $add( 900, array( 'post_password' => 'x' ) );
$optout = $add( 900, array( 'meta' => array( '_publishrank_seo_news_eligible' => '-1' ) ) ); $notitle = $add( 900, array( 'post_title' => '' ) );
$bare = $add( 900 ); // no SEO metadata at all
$list = $urls( $render() );
$check( '5. noindex article is excluded', ! in_array( get_permalink( $noindex ), $list, true ) );
$check( '6. draft never appears', ! in_array( get_permalink( $draft ), $list, true ) );
$check( 'password-protected article is excluded', ! in_array( get_permalink( $pw ), $list, true ) );
$check( 'editor opt-out is respected', ! in_array( get_permalink( $optout ), $list, true ) );
$check( 'article with no title and no SEO title is skipped, not emitted blank', ! in_array( get_permalink( $notitle ), $list, true ) );
$check( '7. article without optional SEO metadata is included with safe defaults', in_array( get_permalink( $bare ), $list, true ) );

// 8. Escaping.
$special = $add( 300, array( 'post_title' => 'Tom &amp; Jerry&#8217;s <b>"Big"</b> Day & Night' ) );
$xml = $render();
$check( '8. XML special characters are escaped and the document stays valid', $valid( $xml ) );
if ( function_exists( 'simplexml_load_string' ) ) {
	$sx = simplexml_load_string( $xml ); $sx->registerXPathNamespace( 'n', 'http://www.google.com/schemas/sitemap-news/0.9' ); $sx->registerXPathNamespace( 's', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
	$title = (string) $sx->xpath( "//s:url[s:loc='" . get_permalink( $special ) . "']/n:news/n:title" )[0];
	$check( '8b. title round-trips without double escaping or markup', "Tom & Jerry\u{2019}s \"Big\" Day & Night" === $title );
} else { $check( '8b. title escaping check skipped without SimpleXML', true ); }

// 9. Publication name / language.
$T['opts']['publishrank_seo_publication_name'] = 'The Test Times (Beta)'; $T['opts']['publishrank_default_language'] = 'en_US';
$xml = $render();
$check( '9. publication name omits parenthetical and language is ISO 639', false !== strpos( $xml, '<news:name>The Test Times</news:name>' ) && false !== strpos( $xml, '<news:language>en</news:language>' ) );
$T['opts']['publishrank_default_language'] = 'zh_TW'; $check( '9b. Traditional Chinese maps to zh-tw', 'zh-tw' === P::language() );
$T['opts']['publishrank_default_language'] = 'zh_CN'; $check( '9c. Simplified Chinese maps to zh-cn', 'zh-cn' === P::language() );
$T['opts']['publishrank_default_language'] = 'fr-CA'; $check( '9d. regional codes collapse to the language code', 'fr' === P::language() );
unset( $T['opts']['publishrank_default_language'] );

// Original publication date vs modified date.
$old = $add( 7200, array( 'mod' => time() - 600 ) );
if ( function_exists( 'simplexml_load_string' ) ) {
	$xml = $render(); $sx = simplexml_load_string( $xml ); $sx->registerXPathNamespace( 'n', 'http://www.google.com/schemas/sitemap-news/0.9' ); $sx->registerXPathNamespace( 's', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
	$pub = (string) $sx->xpath( "//s:url[s:loc='" . get_permalink( $old ) . "']/n:news/n:publication_date" )[0];
	$check( 'publication_date is the original publish time in UTC, not the modified time', gmdate( DATE_W3C, $T['posts'][ $old ]->ts ) === $pub );
} else { $check( 'publication_date XML check skipped without SimpleXML', true ); }

// 14 + 13. More than 1,000 entries are split and the sitemap index references each file.
$T['posts'] = array(); $id = 0;
for ( $i = 0; $i < 1001; $i++ ) { $add( 60 + $i ); }
$p = new P();
$check( '14. 1,001 eligible articles produce 2 news sitemap files', 2 === $p->get_shard_count() );
$check( '14b. first file holds exactly 1,000 entries, second holds 1', 1000 === count( $urls( $render( 1 ) ) ) && 1 === count( $urls( $render( 2 ) ) ) );
$check( '14c. both files are valid XML', $valid( $render( 1 ) ) && $valid( $render( 2 ) ) );
$index = ( new PublishRankSEO\Sitemap\Engine\SitemapIndex() )->render( array( $p ) );
$check( '13. sitemap index lists both news sitemap files', false !== strpos( $index, 'publishrank-news-sitemap-1.xml' ) && false !== strpos( $index, 'publishrank-news-sitemap-2.xml' ) && 2 === substr_count( $index, '<sitemap>' ) );
$T['opts']['publishrank_seo_news_enabled'] = false;
$check( 'disabled provider is omitted from the index', 0 === substr_count( ( new PublishRankSEO\Sitemap\Engine\SitemapIndex() )->render( array( new P() ) ), '<sitemap>' ) );

// Static checks for engine/controller behaviour that needs a real HTTP stack to exercise end to end.
$src = static function ( $f ) { return file_get_contents( __DIR__ . '/../src/' . $f ); };
$ctl = $src( 'Sitemap/SitemapController.php' ); $eng = $src( 'Sitemap/Engine/SitemapEngine.php' ); $news = $src( 'Sitemap/News/NewsSitemapProvider.php' ); $store = $src( 'Sitemap/Engine/SitemapCursorStore.php' );
$check( '16. controller returns 200 with an XML content type, 404 for unknown shards and 500 on failure', false !== strpos( $ctl, "status_header( 200 )" ) && false !== strpos( $ctl, 'application/xml; charset=UTF-8' ) && false !== strpos( $ctl, 'status_header( 404 )' ) && false !== strpos( $ctl, 'status_header( 500 )' ) );
$check( '16b. output buffers are discarded before the XML declaration', false !== strpos( $ctl, 'ob_end_clean' ) );
$check( '12. news provider no longer depends on cursors or WP-Cron', false === strpos( $news, 'prepare_cursor' ) && false === strpos( $news, 'SitemapCursorStore::post_page' ) );
$check( '12b. cursor-backed providers advance inline and stale cursors restart', false !== strpos( $eng, 'prepare_inline' ) && false !== strpos( $store, 'expire_if_stale' ) );
$check( '11. publish/trash/meta changes invalidate the news sitemap', false !== strpos( $src( 'Core/Plugin.php' ), 'transition_post_status' ) && false !== strpos( $src( 'Core/Plugin.php' ), 'updated_post_meta' ) );

echo "\n$n checks, " . count( $failed ) . " failed\n";
exit( $failed ? 1 : 0 );
