<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
/**
 * Integration test: runs against a real WordPress install (any DB, e.g. SQLite).
 *   WP_LOAD=/path/to/wp-load.php php tests/integration/wp-seo-data.php
 * Verifies automatic SEO data (title, description, canonical, images, alt text) and that per-post overrides win.
 */
$mode = isset( $argv[1] ) ? $argv[1] : 'run';
$self = escapeshellarg( __FILE__ );
$wp_load = getenv( 'WP_LOAD' ) ?: dirname( __DIR__, 5 ) . '/wp-load.php';
putenv( 'WP_ENVIRONMENT_TYPE=local' ); // allows localhost URLs in the test site.
// Run activation separately when needed: php tests/integration/wp-seo-data.php activate
$_SERVER['HTTP_HOST']   = 'localhost:8765';
$_SERVER['REQUEST_URI'] = '/';
putenv( 'WP_LOAD=' . $wp_load );
require $wp_load;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

if ( 'head' === $mode ) { // Print the <head> a visitor would get for one post.
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'p' => (int) $argv[2], 'post_type' => 'any' ) );
	$GLOBALS['wp_query']->the_post();
	do_action( 'wp_head' ); echo "\n<!--TITLE:" . wp_get_document_title() . "-->";
	exit;
}
if ( 'activate' === $mode ) {
	wp_set_current_user( 1 );
	$activate = activate_plugin( 'publishrank/publishrank.php' );
	echo ( is_wp_error( $activate ) ? 'FAIL' : 'PASS' ) . ': plugin activates on WordPress ' . get_bloginfo( 'version' ) . ( is_wp_error( $activate ) ? ' [' . $activate->get_error_message() . ']' : '' ) . "\n";
	exit( is_wp_error( $activate ) ? 1 : 0 );
}

use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Meta\SeoData;

$fails = 0;
function check( $label, $ok, $detail = '' ) { global $fails; echo ( $ok ? 'PASS' : 'FAIL' ) . ": $label" . ( $ok ? '' : " [$detail]" ) . "\n"; if ( ! $ok ) { $fails++; } }
function head_for( $post_id ) {
	global $self;
	return (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . $self . ' head ' . (int) $post_id . ' 2>/dev/null' );
}
function meta_in( $html, $needle ) { return preg_match( '/' . $needle . '=["\']([^"\']*)["\']/', $html, $m ) ? html_entity_decode( $m[1] ) : null; }

wp_set_current_user( 1 );
do_action( 'init' );

// Real image attachment with alt text.
$src = dirname( __DIR__, 2 ) . '/assets/images/icon-256x256.png';
$tmp = wp_upload_dir()['path'] . '/pr-test-' . wp_rand() . '.png'; copy( $src, $tmp );
$att = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Photo', 'post_status' => 'inherit' ), $tmp );
wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $tmp ) );
update_post_meta( $att, '_wp_attachment_image_alt', 'A cat on a sofa' );

$body = '<!-- wp:paragraph --><p>Hello <strong>world</strong> [gallery ids="1"] this is the story body with enough words to make a decent automatic description for the search result snippet shown by engines so that we can verify truncation works properly at a word boundary.</p><!-- /wp:paragraph -->';

// 1) Post: everything automatic.
$auto = wp_insert_post( array( 'post_title' => 'Auto Post', 'post_content' => $body, 'post_status' => 'publish' ) );
set_post_thumbnail( $auto, $att );
$d = SeoData::for_post( $auto );
check( 'auto description strips shortcodes/blocks/tags', false === strpos( $d['description'], '[gallery' ) && false === strpos( $d['description'], '<' ) && 0 === strpos( $d['description'], 'Hello world' ), $d['description'] );
check( 'auto description <= 160 chars, ends with ellipsis', mb_strlen( $d['description'] ) <= 160 && '…' === mb_substr( $d['description'], -1 ), mb_strlen( $d['description'] ) . ' ' . mb_substr( $d['description'], -5 ) );
check( 'canonical = permalink (works with plain permalinks too)', get_permalink( $auto ) === $d['canonical'] || trailingslashit( $d['canonical'] ) === trailingslashit( get_permalink( $auto ) ), $d['canonical'] . ' vs ' . get_permalink( $auto ) );
check( 'featured image + alt fetched', ! empty( $d['image']['url'] ) && 'A cat on a sofa' === $d['image']['alt'] && 'featured' === $d['image']['source'] && $d['image']['width'] > 0 );
check( 'sources report auto', 'auto' === $d['sources']['title'] && 'auto' === $d['sources']['description'] );
$h = head_for( $auto );
check( 'head: meta description', 0 === strpos( (string) meta_in( $h, '<meta name="description" content' ), 'Hello world' ) );
check( 'head: canonical link', false !== strpos( $h, 'rel="canonical"' ) );
check( 'head: og:image + og:image:alt + width', false !== strpos( $h, 'og:image"' ) && 'A cat on a sofa' === meta_in( $h, '<meta property="og:image:alt" content' ) && null !== meta_in( $h, '<meta property="og:image:width" content' ) );
check( 'head: twitter:image:alt', 'A cat on a sofa' === meta_in( $h, '<meta name="twitter:image:alt" content' ) );

// 2) Overrides win.
$ov = wp_insert_post( array( 'post_title' => 'Override Post', 'post_content' => $body, 'post_status' => 'publish' ) );
set_post_thumbnail( $ov, $att );
update_post_meta( $ov, PostMeta::TITLE, 'Custom SEO Title' );
update_post_meta( $ov, PostMeta::DESCRIPTION, 'Custom description.' );
update_post_meta( $ov, PostMeta::CANONICAL, home_url( '/custom-canonical/' ) );
update_post_meta( $ov, PostMeta::OG_IMAGE_ALT, 'Custom alt' );
update_post_meta( $ov, PostMeta::ROBOTS, 'noindex,follow' );
$h = head_for( $ov );
check( 'override: description', 'Custom description.' === meta_in( $h, '<meta name="description" content' ) );
check( 'override: canonical', false !== strpos( $h, home_url( '/custom-canonical/' ) ) );
check( 'override: og:image:alt', 'Custom alt' === meta_in( $h, '<meta property="og:image:alt" content' ) );
check( 'override: robots', false !== strpos( (string) meta_in( $h, '<meta name="robots" content' ), 'noindex' ) );
check( 'override: document title', false !== strpos( (string) $h, 'TITLE:Custom SEO Title' ), (string) substr( $h, -80 ) );
check( 'override: sources report override', 'override' === SeoData::for_post( $ov )['sources']['description'] );

// 3) Page (not only articles) uses featured image; first-content-image fallback; CPT.
$page = wp_insert_post( array( 'post_title' => 'About', 'post_type' => 'page', 'post_content' => 'About us text.', 'post_status' => 'publish' ) );
set_post_thumbnail( $page, $att );
check( 'page gets featured image for social', 'featured' === SeoData::for_post( $page )['image']['source'] );
$inline = wp_insert_post( array( 'post_title' => 'Inline', 'post_content' => '<!-- wp:image {"id":' . $att . '} --><figure class="wp-block-image"><img src="x.png" class="wp-image-' . $att . '"/></figure><!-- /wp:image -->', 'post_status' => 'publish' ) );
check( 'first content image is used when no featured image', 'content' === SeoData::for_post( $inline )['image']['source'] && 'A cat on a sofa' === SeoData::for_post( $inline )['image']['alt'] );
register_post_type( 'recipe', array( 'public' => true, 'show_in_rest' => true ) );
do_action( 'init' );
$cpt = wp_insert_post( array( 'post_title' => 'Soup', 'post_type' => 'recipe', 'post_content' => 'Boil water.', 'post_status' => 'publish' ) );
check( 'custom post type resolves', 'Boil water.' === SeoData::for_post( $cpt )['description'] );

// 4) Privacy: password-protected content is never exposed.
$pw = wp_insert_post( array( 'post_title' => 'Secret', 'post_content' => 'top secret text here', 'post_status' => 'publish', 'post_password' => 'x' ) );
check( 'password-protected post: no description leak', '' === SeoData::for_post( $pw )['description'] );

// 5) Alt fallbacks for image with no alt.
$att2 = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'IMG_001', 'post_status' => 'inherit', 'post_excerpt' => 'Caption text' ), $tmp );
wp_update_attachment_metadata( $att2, wp_generate_attachment_metadata( $att2, $tmp ) );
$p2 = wp_insert_post( array( 'post_title' => 'Alt Fallback', 'post_content' => 'x', 'post_status' => 'publish' ) ); set_post_thumbnail( $p2, $att2 );
check( 'alt falls back to caption', 'Caption text' === SeoData::for_post( $p2 )['image']['alt'] );

// 6) JSON-LD cannot be broken out of.
$xss = wp_insert_post( array( 'post_title' => 'T', 'post_content' => 'x', 'post_status' => 'publish' ) );
add_filter( 'publishrank_seo_post_data', '__return_empty_array', 99 ); remove_filter( 'publishrank_seo_post_data', '__return_empty_array', 99 );
update_post_meta( $xss, PostMeta::DESCRIPTION, 'a</script><script>alert(1)</script>' );
$h = head_for( $xss );
check( 'JSON-LD contains no raw </script> breakout', 1 === preg_match_all( '#</script>#i', substr( $h, (int) strpos( $h, 'application/ld+json' ) ), $m ) || 0 === preg_match( '#<script>alert#', $h ), (string) substr_count( $h, '<script>alert' ) );

// 6b) No auto values are persisted; stale snapshots from <=2.9.37 are cleaned, real overrides kept.
check( 'saving a post does not copy auto values into post meta', '' === (string) get_post_meta( $auto, PostMeta::TITLE, true ) && '' === (string) get_post_meta( $pw, PostMeta::DESCRIPTION, true ) );
$legacy = wp_insert_post( array( 'post_title' => 'Legacy', 'post_content' => 'Legacy body text.', 'post_status' => 'publish' ) );
update_post_meta( $legacy, PostMeta::TITLE, 'Legacy | ' . get_bloginfo( 'name' ) );
update_post_meta( $legacy, PostMeta::DESCRIPTION, 'Legacy body text.' );
update_post_meta( $legacy, PostMeta::OG_TITLE, 'Hand written social title' );
delete_option( PublishRankSEO\SEO\AutomaticArticleSEO::MIGRATION_OPTION );
( new PublishRankSEO\SEO\AutomaticArticleSEO() )->migrate(); ( new PublishRankSEO\SEO\AutomaticArticleSEO() )->migrate();
check( 'migration removes stored auto snapshots', '' === (string) get_post_meta( $legacy, PostMeta::TITLE, true ) && '' === (string) get_post_meta( $legacy, PostMeta::DESCRIPTION, true ) );
check( 'migration keeps genuine overrides', 'Hand written social title' === get_post_meta( $legacy, PostMeta::OG_TITLE, true ) );
wp_update_post( array( 'ID' => $legacy, 'post_title' => 'Legacy renamed' ) ); SeoData::flush();
check( 'title follows later post edits (stays automatic)', false !== strpos( SeoData::for_post( $legacy )['title'], 'Legacy renamed' ) );

// 7) REST + register_meta (Gutenberg).
$req = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $auto );
$res = rest_do_request( $req )->get_data();
check( 'REST exposes resolved seo data incl. image alt', ! empty( $res['publishrank_seo']['image']['alt'] ) && ! empty( $res['publishrank_seo']['description'] ) );
$req = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $auto ); $req->set_body_params( array( 'meta' => array( PostMeta::DESCRIPTION => 'Set via REST <b>x</b>' ) ) );
rest_do_request( $req );
check( 'override writable via REST meta (sanitized)', 'Set via REST x' === get_post_meta( $auto, PostMeta::DESCRIPTION, true ), (string) get_post_meta( $auto, PostMeta::DESCRIPTION, true ) );
wp_set_current_user( 0 );
$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $auto ) )->get_data();
check( 'REST seo data visible to anonymous for published post', ! empty( $res['publishrank_seo']['title'] ) );

// 8) Meta box renders for pages and post types, not duplicated audit.
wp_set_current_user( 1 );
ob_start(); ( new PublishRankSEO\SEO\AdvancedFeatures() )->render_meta_box( get_post( $page ) ); $box = ob_get_clean();
check( 'meta box renders for pages with automatic summary', false !== strpos( $box, 'What will be published' ) );
check( 'editorial audit shown once', 1 === substr_count( $box, 'Editorial SEO audit' ), (string) substr_count( $box, 'Editorial SEO audit' ) );
check( 'blank robots stays automatic (placeholder shown)', false !== strpos( $box, 'name="' . PostMeta::ROBOTS . '" value=""' ) );

$log = @file_get_contents( WP_CONTENT_DIR . '/debug.log' );
check( 'no PHP warnings/notices/deprecations from PublishRank', ! $log || false === stripos( $log, 'publishrank' ), (string) substr( (string) $log, 0, 400 ) );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
