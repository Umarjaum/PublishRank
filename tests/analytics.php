<?php
/** Static regression checks for local and Google Search Console analytics. */
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' );
$collector = file_get_contents( $root . '/src/AnalyticsCollector.php' );
$page = file_get_contents( $root . '/src/Admin/AnalyticsPage.php' );
$checks = array(
	'daily snapshot collector is registered' => false !== strpos( $plugin, 'AnalyticsCollector::class' ),
	'analytics page is registered in admin' => false !== strpos( $plugin, 'AnalyticsPage::class' ),
	'daily snapshots are capped to 30 days' => false !== strpos( $collector, 'const MAX_DAYS = 30' ),
	'IndexNow accepted and failed data are recorded' => false !== strpos( $collector, 'indexnow_accepted' ) && false !== strpos( $collector, 'indexnow_failed' ),
	'local charts use lightweight SVG' => false !== strpos( $page, '<svg viewBox=' ) && false !== strpos( $page, '<polyline' ),
	'Search Console uses read-only OAuth scope' => false !== strpos( $page, 'https://www.googleapis.com/auth/webmasters.readonly' ) && false === strpos( $page, "'scope' => 'https://www.googleapis.com/auth/webmasters'" ),
	'OAuth state is random, hashed, user-bound and short-lived' => false !== strpos( $page, 'wp_generate_password( 48' ) && false !== strpos( $page, "hash( 'sha256', \$state )" ) && false !== strpos( $page, '10 * MINUTE_IN_SECONDS' ),
	'Google tokens are refreshed without asking user each time' => false !== strpos( $page, 'grant_type' ) && false !== strpos( $page, 'refresh_token' ),
	'Google Search Console performance query includes dates and metrics' => false !== strpos( $page, "'dimensions' => array( 'date' )" ) && false !== strpos( $page, "'clicks' => (float)" ) && false !== strpos( $page, "'impressions' => (float)" ),
	'property must come from the connected account property list' => false !== strpos( $page, '$valid = array_column( $sites, \'url\' )' ),
	'Google results are cached' => false !== strpos( $page, '6 * HOUR_IN_SECONDS' ),
	'disconnect attempts to revoke refresh token' => false !== strpos( $page, 'oauth2.googleapis.com/revoke' ),
	'no fabricated Search Console values' => false !== strpos( $page, 'Google Search Console could not return performance data' ) && false !== strpos( $page, 'No data returned for this period' ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . PHP_EOL; if ( ! $passed ) { $failed++; } }
if ( $failed ) { exit( 1 ); }
echo "Analytics checks passed.\n";
