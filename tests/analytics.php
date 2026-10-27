<?php
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
/** Regression checks for local analytics, the separate Search Console connection page, and help content. */
$root      = dirname( __DIR__ );
$plugin    = file_get_contents( $root . '/src/Core/Plugin.php' );
$collector = file_get_contents( $root . '/src/AnalyticsCollector.php' );
$analytics = file_get_contents( $root . '/src/Admin/AnalyticsPage.php' );
$console   = file_get_contents( $root . '/src/Admin/SearchConsolePage.php' );
$help      = file_get_contents( $root . '/src/Admin/HelpDocumentationPage.php' );
$checks    = array(
	'daily snapshot collector is registered' => false !== strpos( $plugin, 'AnalyticsCollector::class' ),
	'local analytics admin page is registered' => false !== strpos( $plugin, 'AnalyticsPage::class' ) && is_file( $root . '/src/Admin/AnalyticsPage.php' ),
	'local snapshots retain up to 90 days' => 1 === preg_match( '/const\s+MAX_DAYS\s*=\s*90\s*;/', $collector ),
	'IndexNow accepted and failed data are recorded' => false !== strpos( $collector, 'indexnow_accepted' ) && false !== strpos( $collector, 'indexnow_failed' ),
	'analytics page renders Search Console metrics and links to its connection screen' => false !== strpos( $analytics, 'render_search_console()' ) && false !== strpos( $analytics, 'publishrank-search-console' ) && false !== strpos( $analytics, 'Connect Search Console' ),
	'Search Console connection screen is registered' => false !== strpos( $plugin, 'SearchConsolePage::class' ) && false !== strpos( $console, 'webmasters.readonly' ),
	'feature documentation screen is registered' => false !== strpos( $plugin, 'HelpDocumentationPage::class' ),
	'documentation includes accessible contextual help' => false !== strpos( $help, 'pr-help-icon' ) && false !== strpos( $help, 'aria-label' ),
	'documentation explains OAuth scopes and local analytics privacy' => false !== strpos( $help, 'webmasters.readonly' ) && false !== strpos( $help, 'does not track visitors' ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}
if ( $failed ) {
	exit( 1 );
}
echo "Analytics/help checks passed.\n";
