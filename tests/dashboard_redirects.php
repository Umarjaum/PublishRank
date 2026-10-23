<?php
/** Static checks for current dashboard presentation, redirect safety, and synchronized release metadata. */
$root = dirname( __DIR__ );
$read = static function ( $relative ) use ( $root ) {
	$path = $root . '/' . $relative;
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "FAIL: missing {$relative}\n" );
		exit( 1 );
	}
	return file_get_contents( $path );
};
$checks     = array();
$assert     = static function ( $condition, $message ) use ( &$checks ) {
	$checks[] = (bool) $condition;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
};
$dashboard = $read( 'src/Admin/DashboardPage.php' );
$assets    = $read( 'src/Admin/AdminAssets.php' );
$css       = $read( 'assets/css/admin.css' );
$store     = $read( 'src/Redirect/RedirectStore.php' );
$controller = $read( 'src/Redirect/RedirectController.php' );
$plugin    = $read( 'publishrank.php' );
$readme    = $read( 'readme.txt' );

$assert( false !== strpos( $dashboard, 'Welcome to PublishRank.' ) && false !== strpos( $dashboard, 'Publisher-first SEO workspace' ), 'current dashboard welcome copy remains present' );
$assert( false !== strpos( $assets, 'publishrank-header-icon-v2.png' ) && false !== strpos( $assets, 'Branding::version()' ), 'branded admin header uses cache-busted PublishRank artwork' );
$assert( false !== strpos( $css, '.wrap .rhs-welcome-banner h2' ) && false !== strpos( $css, 'color:#ffffff!important' ) && false !== strpos( $css, '.wrap .rhs-header__title' ), 'dashboard header and welcome copy have explicit high-contrast guards' );
$assert( false !== strpos( $store, '301' ) && false !== strpos( $store, '451' ), 'redirect status allow-list includes supported permanent and removal statuses' );
$assert( false !== strpos( $store, 'publishrank_redirect_loop' ) && false !== strpos( $store, 'home_url' ), 'redirect storage validates same-site targets and rejects loops' );
$assert( false !== strpos( $controller, 'wp_safe_redirect' ) && false !== strpos( $controller, 'nocache_headers' ), 'runtime redirects use safe handling and no-cache responses for removals' );
$header = array();
$stable = array();
$assert( 1 === preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $plugin, $header ) && 1 === preg_match( '/^Stable tag:\s*([0-9.]+)/m', $readme, $stable ) && $header[1] === $stable[1], 'plugin header and WordPress.org stable tag are synchronized' );

$failed = count( array_filter( $checks, static function ( $ok ) { return ! $ok; } ) );
echo $failed ? "Dashboard/redirect checks failed: {$failed}\n" : "Dashboard/redirect checks passed.\n";
exit( $failed ? 1 : 0 );
