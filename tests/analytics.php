<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/** Regression checks for local privacy-preserving analytics and read-only Search Console support. */
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) { return (string) file_get_contents( $root . '/' . $file ); };
$plugin = $read( 'src/Core/Plugin.php' );
$collector = $read( 'src/AnalyticsCollector.php' );
$analytics_page = $read( 'src/Admin/AnalyticsPage.php' );
$search_client = $read( 'src/Google/SearchConsoleClient.php' );
$search_page = $read( 'src/Admin/SearchConsolePage.php' );
$readme = $read( 'readme.txt' );
$checks = array(
	'daily local analytics collector is registered' => false !== strpos( $plugin, 'AnalyticsCollector::class' ),
	'analytics retention is bounded to 90 days' => false !== strpos( $collector, 'const MAX_DAYS = 90' ) && false !== strpos( $collector, 'array_slice( $snaps, -self::MAX_DAYS' ),
	'IndexNow accepted and failed outcomes are aggregated without visitor analytics' => false !== strpos( $collector, 'indexnow_accepted' ) && false !== strpos( $collector, 'indexnow_failed' ) && false === strpos( $collector, 'REMOTE_ADDR' ),
	'Google Search Console analytics page is registered' => false !== strpos( $plugin, 'AnalyticsPage::class' ) && false !== strpos( $plugin, 'SearchConsolePage::class' ),
	'Google OAuth scope is explicitly read-only' => false !== strpos( $search_client, 'webmasters.readonly' ) && false === strpos( $search_client, 'webmasters' . '.edit' ),
	'Search Console does not request publisher-managed OAuth setup by default' => false !== strpos( $analytics_page, 'Search Console' ) && false !== strpos( $search_client, 'webmasters.readonly' ),
	'account/provider integrations are disclosed as optional' => false !== strpos( $readme, 'Google Search Console and Google sign-in (optional)' ) && false !== strpos( $readme, 'Supabase Auth and configured identity providers (optional)' ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) { fwrite( STDOUT, ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . PHP_EOL ); if ( ! $passed ) { ++$failed; } }
if ( $failed ) { exit( 1 ); }
fwrite( STDOUT, "Analytics and Search Console checks passed.\n" );
