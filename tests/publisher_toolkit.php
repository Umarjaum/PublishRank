<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/** Source-level regression checks for PublishRank-native publisher toolkit workflows. */
$root = dirname( __DIR__ );
$page = file_get_contents( $root . '/src/Admin/PublisherToolkitPage.php' );
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' );
$changelog = file_get_contents( $root . '/changelog.txt' );
$checks = array(
    'toolkit module wired into plugin loader with correct namespace import' => false !== strpos( $plugin, 'use PublishRankSEO\\Admin\\PublisherToolkitPage;' ) && false !== strpos( $plugin, 'PublisherToolkitPage::class' ),
    'bulk SEO update handler registered' => false !== strpos( $page, 'publishrank_bulk_seo_update' ),
    'per-post edit permission enforced' => false !== strpos( $page, "current_user_can( 'edit_post', " . '$id' . " )" ),
    'bulk update bounded to 100 records' => false !== strpos( $page, 'array_slice( $ids, 0, 100 )' ),
    'title and description explicit clear controls' => false !== strpos( $page, 'clear_title' ) && false !== strpos( $page, 'clear_description' ),
    'robots values allow-listed' => false !== strpos( $page, '$valid_robots' ),
    'role configuration administrator-only' => false !== strpos( $page, "current_user_can( 'manage_options' )" ) && false !== strpos( $page, 'ROLES_OPTION' ),
    'custom capability maps configured roles' => false !== strpos( $page, 'function grant_toolkit_capability' ) && false !== strpos( $page, 'manage_publishrank_seo' ),
    'image alt fallback is opt-in' => false !== strpos( $page, 'publishrank_image_alt_fallback_enabled' ),
    'existing saved alt text is preserved' => false !== strpos( $page, "metadata_exists( 'post'" ),
    'media batch only targets missing metadata' => false !== strpos( $page, "'compare' => 'NOT EXISTS'" ),
    'media batch is bounded to 100' => false !== strpos( $page, "'posts_per_page' => 100" ),
    'no Rank Math namespace or code copied' => false === strpos( $page, 'RankMath' ) && false === strpos( $page, 'rank_math' ),
    'release notes document custom implementation' => false !== strpos( $changelog, 'No Rank Math source code' ),
);
$failed = array();
foreach ( $checks as $name => $passed ) {
    echo ( $passed ? 'PASS' : 'FAIL' ) . ' - ' . $name . PHP_EOL;
    if ( ! $passed ) { $failed[] = $name; }
}
if ( $failed ) { exit( 1 ); }
