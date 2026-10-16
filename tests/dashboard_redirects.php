<?php
/** Static checks for dashboard presentation, redirect safety, and release invariants. */
$root = dirname( __DIR__ );
$read = static function ( $relative ) use ( $root ) {
	$path = $root . '/' . $relative;
	if ( ! is_file( $path ) ) { fwrite( STDERR, "FAIL: missing {$relative}\n" ); exit( 1 ); }
	return file_get_contents( $path );
};
$checks = array();
$assert = static function ( $condition, $message ) use ( &$checks ) {
	$checks[] = (bool) $condition;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
};
$dashboard = $read( 'src/Admin/DashboardPage.php' );
$assets = $read( 'src/Admin/AdminAssets.php' );
$css = $read( 'assets/css/admin.css' );
$store = $read( 'src/Redirect/RedirectStore.php' );
$controller = $read( 'src/Redirect/RedirectController.php' );
$plugin = $read( 'publishrank.php' );

$assert( strpos( $dashboard, 'Your newsroom SEO is ready to grow.' ) !== false && strpos( $dashboard, 'publishrank-banner-publisher.png' ) !== false, 'dashboard welcome copy and publisher artwork remain present' );
$assert( strpos( $assets, 'publishrank-header-icon-v2.png' ) !== false && strpos( $assets, 'Branding::version()' ) !== false, 'branded admin header uses cache-busted PublishRank artwork' );
$assert( strpos( $css, '.wrap .rhs-welcome-banner h2' ) !== false && strpos( $css, 'color:#fff!important' ) !== false && strpos( $css, '.wrap .rhs-header__title' ) !== false, 'dashboard header and welcome copy have explicit high-contrast guards' );
$assert( strpos( $store, "array( 301, 302, 303, 307, 308, 410, 451 )" ) !== false, 'redirect status allow-list is explicit' );
$assert( strpos( $store, 'if ( $host && ( ! $home || strtolower( $host ) !== strtolower( $home ) ) )' ) !== false, 'stored redirect targets reject off-site hosts' );
$assert( strpos( $store, 'publishrank_redirect_loop' ) !== false && strpos( $controller, 'is_loop' ) !== false, 'redirect creation and runtime both guard against loops' );
$assert( strpos( $controller, 'wp_safe_redirect' ) !== false && strpos( $controller, 'nocache_headers' ) !== false, 'runtime redirects use safe redirect handling and no-cache responses for removals' );
$assert( preg_match( '/Version:\s*2\.9\.47/', $plugin ) === 1 && strpos( $plugin, "PUBLISHRANK_SEO_VERSION', '2.9.47'" ) !== false, 'plugin version is 2.9.47' );

$failed = count( array_filter( $checks, static function ( $ok ) { return ! $ok; } ) );
echo $failed ? "Dashboard/redirect checks failed: {$failed}\n" : "Dashboard/redirect checks passed.\n";
exit( $failed ? 1 : 0 );
