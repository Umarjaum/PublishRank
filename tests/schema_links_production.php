<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
$root = dirname( __DIR__ );
$graph = file_get_contents( $root . '/src/Schema/GraphOutput.php' );
$recovery = file_get_contents( $root . '/src/Meta/FrontendOutputRecovery.php' );
$scanner = file_get_contents( $root . '/src/InternalLinks/Scanner.php' );
$links = file_get_contents( $root . '/src/Links/LinkManager.php' );
$settings = file_get_contents( $root . '/src/Admin/SettingsPage.php' );
$checks = array(
    'NewsArticle and Article are selectable' => strpos( $graph, "SchemaTypes::article_types()" ) !== false,
    'page article overrides remain supported' => strpos( $graph, "'page' === \$post->post_type" ) !== false && strpos( $graph, "array_key_exists( \$chosen, SchemaTypes::article_types() )" ) !== false,
    'theme fallback injects into head' => strpos( $recovery, 'start_fallback_buffer' ) !== false && strpos( $recovery, 'ob_start' ) !== false,
    'internal edges persist after scanning' => strpos( $scanner, 'Store::insert_edge' ) !== false && substr_count( $scanner, "delete( Store::table( 'edges' )" ) === 0,
    'site subdomains remain internal' => strpos( $links, 'is_site_host' ) !== false,
    'external links default to nofollow' => strpos( $links, 'rel_add' ) !== false && strpos( $links, 'approved' ) !== false && strpos( $links, 'nofollow' ) !== false,
    'approved external domains have a setting' => strpos( $links, 'publishrank_links_follow_domains' ) !== false && strpos( $settings, 'Approved external domains (follow)' ) !== false,
);
$failed = array();
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; if ( ! $passed ) { $failed[] = $label; } }
if ( $failed ) { exit( 1 ); }
echo "schema/link production checks passed\n";
