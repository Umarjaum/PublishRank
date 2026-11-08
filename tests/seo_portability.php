<?php
/** Focused migration fixtures for Yoast SEO and Rank Math portability. */
if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) { exit; }
define( 'ABSPATH', '/tmp/' );
$GLOBALS['pr_meta'] = array();
$GLOBALS['pr_posts'] = array( 101 => array( 'post_name' => 'yoast-story', 'post_type' => 'post' ), 202 => array( 'post_name' => 'rank-math-story', 'post_type' => 'post' ) );
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['pr_meta'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['pr_meta'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['pr_meta'][ (int) $id ][ $key ] ); return true; }
function get_posts( $args = array() ) { $out = array(); foreach ( $GLOBALS['pr_posts'] as $id => $post ) { if ( ! empty( $args['name'] ) && $args['name'] === $post['post_name'] ) { $out[] = (object) array( 'ID' => $id ); } } return $out; }
function get_post( $id ) { return isset( $GLOBALS['pr_posts'][ (int) $id ] ) ? (object) array( 'ID' => $id ) : null; }
function get_option( $key, $default = false ) { return $default; }
function update_option() { return true; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return sanitize_text_field( $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function sanitize_title( $v ) { return strtolower( preg_replace( '/[^a-z0-9\-]/i', '-', (string) $v ) ); }
function esc_url_raw( $v ) { return filter_var( trim( (string) $v ), FILTER_VALIDATE_URL ) ? trim( $v ) : ''; }
function wp_validate_redirect( $v, $fallback = '' ) { return esc_url_raw( $v ) ? $v : $fallback; }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function wp_parse_url( $v, $component = -1 ) { $parsed = parse_url( $v ); if ( -1 === $component ) { return $parsed; } $names = array( PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PORT => 'port', PHP_URL_USER => 'user', PHP_URL_PASS => 'pass', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query', PHP_URL_FRAGMENT => 'fragment' ); return isset( $names[ $component ] ) && isset( $parsed[ $names[ $component ] ] ) ? $parsed[ $names[ $component ] ] : null; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function current_time( $type ) { return '2026-11-08 11:00:00'; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_multisite() { return false; }
function __( $v ) { return $v; }
function esc_html__( $v ) { return $v; }
function esc_attr( $v ) { return (string) $v; }
function esc_html( $v ) { return (string) $v; }
function esc_url( $v ) { return (string) $v; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
spl_autoload_register( function ( $class ) { $prefix = 'PublishRankSEO\\'; if ( 0 !== strpos( $class, $prefix ) ) { return; } $file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php'; if ( is_readable( $file ) ) { require_once $file; } } );
require_once dirname( __DIR__ ) . '/src/Admin/SeoPortability.php';
require_once dirname( __DIR__ ) . '/src/Meta/PostMeta.php';

$passed = 0; $failed = 0;
function portability_check( $condition, $label ) { global $passed, $failed; echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n"; $condition ? ++$passed : ++$failed; }
$engine = new \PublishRankSEO\Admin\SeoPortability();
$ref = new ReflectionClass( $engine );
$call = static function ( $name, $args = array() ) use ( $ref, $engine ) { $method = $ref->getMethod( $name ); $method->setAccessible( true ); return $method->invokeArgs( $engine, $args ); };
$adapters = $engine->adapters();

$GLOBALS['pr_meta'][101] = array(
	'_yoast_wpseo_title' => 'Yoast %%title%%', '_yoast_wpseo_metadesc' => 'Yoast description', '_yoast_wpseo_focuskw' => 'news',
	'_yoast_wpseo_canonical' => 'https://example.test/yoast-story', '_yoast_wpseo_opengraph-title' => 'Yoast social title',
	'_yoast_wpseo_opengraph-description' => 'Yoast social description', '_yoast_wpseo_opengraph-image' => 'https://cdn.example.test/yoast.jpg',
	'_yoast_wpseo_meta-robots-noindex' => '1',
);
$yoast = $call( 'read_row', array( 101, $adapters['yoast'] ) );
portability_check( 'Yoast %%title%%' === $yoast['title'] && 'Yoast description' === $yoast['description'], 'Yoast title and description import mapping' );
portability_check( 'https://example.test/yoast-story' === $yoast['canonical'] && 'noindex' === $yoast['robots'], 'Yoast canonical and robots normalization' );
$GLOBALS['pr_meta'][101]['_publishrank_seo_title'] = 'Existing title';
$call( 'write_row', array( 101, $yoast, 'merge', false, array( 'title', 'description', 'canonical', 'robots' ) ) );
portability_check( 'Existing title' === $GLOBALS['pr_meta'][101]['_publishrank_seo_title'] && 'Yoast description' === $GLOBALS['pr_meta'][101]['_publishrank_seo_description'], 'Yoast merge preserves existing values and imports empty fields' );
$call( 'write_row', array( 101, $yoast, 'overwrite', false, array( 'title', 'description', 'canonical', 'robots' ) ) );
portability_check( 'Yoast %%title%%' === $GLOBALS['pr_meta'][101]['_publishrank_seo_title'], 'Yoast overwrite replaces selected fields' );

$GLOBALS['pr_meta'][202] = array(
	'rank_math_title' => 'Rank Math title', 'rank_math_description' => 'Rank Math description', 'rank_math_focus_keyword' => 'technology, AI',
	'rank_math_canonical_url' => 'https://example.test/rank-math-story', 'rank_math_facebook_title' => 'Rank Math OG',
	'rank_math_facebook_description' => 'Rank Math OG description', 'rank_math_facebook_image' => 'https://cdn.example.test/rank.jpg',
	'rank_math_robots' => array( 'noindex', 'nofollow' ),
);
$rank = $call( 'read_row', array( 202, $adapters['rank_math'] ) );
portability_check( 'Rank Math title' === $rank['title'] && 'Rank Math description' === $rank['description'], 'Rank Math title and description import mapping' );
portability_check( 'technology, AI' === $rank['focus_keyword'] && 'noindex,nofollow' === $rank['robots'], 'Rank Math keyword array and robots normalization' );
$call( 'write_row', array( 202, $rank, 'overwrite', false, array( 'title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description', 'og_image', 'robots' ) ) );
portability_check( 'Rank Math title' === $GLOBALS['pr_meta'][202]['_publishrank_seo_title'] && 'https://cdn.example.test/rank.jpg' === $GLOBALS['pr_meta'][202]['_publishrank_seo_og_image'], 'Rank Math fields write into PublishRank metadata' );
$rank_target = $call( 'target_fields', array( $call( 'read_row', array( 202, null ) ), 'rank_math' ) );
portability_check( isset( $rank_target['rank_math_title'] ) && isset( $rank_target['rank_math_description'] ), 'PublishRank export produces Rank Math-compatible keys' );
$yoast_target = $call( 'target_fields', array( $call( 'read_row', array( 101, null ) ), 'yoast' ) );
portability_check( isset( $yoast_target['_yoast_wpseo_title'] ) && isset( $yoast_target['_yoast_wpseo_metadesc'] ), 'PublishRank export produces Yoast-compatible keys' );
$matched = $call( 'match_record', array( array( 'post_id' => 999, 'post_url' => 'https://old.example.test/rank-math-story' ) ) );
portability_check( 202 === $matched, 'Cross-site JSON import matches by URL slug when IDs differ' );
$unsafe = $call( 'sanitize_field', array( 'canonical', 'javascript:alert(1)' ) );
portability_check( '' === $unsafe, 'Import sanitization rejects unsafe canonical URLs' );
echo "\nMigration fixture checks: {$passed} passed, {$failed} failed.\n";
exit( $failed ? 1 : 0 );
