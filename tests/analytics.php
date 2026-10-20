<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/** Regression checks for retained local analytics and removed GSC OAuth UI. */
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' );
$collector = file_get_contents( $root . '/src/AnalyticsCollector.php' );
$help = file_get_contents( $root . '/src/Admin/HelpDocumentationPage.php' );
$checks = array(
    'daily snapshot collector is registered' => false !== strpos( $plugin, 'AnalyticsCollector::class' ),
    'Google Search Console OAuth admin page is not registered' => false === strpos( $plugin, 'AnalyticsPage::class' ),
    'Google Search Console OAuth implementation is removed' => ! file_exists( $root . '/src/Admin/AnalyticsPage.php' ),
    'daily snapshots are capped to 30 days' => false !== strpos( $collector, 'const MAX_DAYS = 30' ),
    'IndexNow accepted and failed data are recorded' => false !== strpos( $collector, 'indexnow_accepted' ) && false !== strpos( $collector, 'indexnow_failed' ),
    'feature documentation screen is registered' => false !== strpos( $plugin, 'HelpDocumentationPage::class' ),
    'documentation includes contextual info icon system' => false !== strpos( $help, 'pr-help-icon' ) && false !== strpos( $help, 'aria-label' ),
    'documentation explains the removed Google connection' => false !== strpos( $help, 'does not currently provide the Google Search Console OAuth connection screen' ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . PHP_EOL; if ( ! $passed ) { $failed++; } }
if ( $failed ) { exit( 1 ); }
echo "Analytics/help checks passed.\n";
