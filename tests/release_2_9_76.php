<?php
/** Release gate for the WordPress.org submission package. */
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) {
	return is_file( $root . '/' . $file ) ? (string) file_get_contents( $root . '/' . $file ) : '';
};
$plugin = $read( 'publishrank.php' );
$readme = $read( 'readme.txt' );
$changelog = $read( 'changelog.txt' );
$stylesheet = $read( 'src/Sitemap/SitemapStylesheet.php' );
$admin_assets = $read( 'src/Admin/AdminAssets.php' );
$tests = array(
	'plugin header and runtime version agree at 2.9.76' => false !== strpos( $plugin, '* Version: 2.9.76' ) && false !== strpos( $plugin, "define( 'PUBLISHRANK_SEO_VERSION', '2.9.76' );" ),
	'WordPress.org stable tag matches plugin version' => false !== strpos( $readme, 'Stable tag: 2.9.76' ),
	'changelog starts with the current release' => 1 === preg_match( '/^= 2\.9\.76 =/m', $changelog ),
	'directory icon GIFs are excluded from plugin runtime assets' => ! is_file( $root . '/assets/icon-128x128.gif' ) && ! is_file( $root . '/assets/icon-256x256.gif' ),
	'sitemap stylesheet embeds both assets from local plugin files' => false !== strpos( $stylesheet, "self::inline_asset( 'css/sitemap.css', 'style' )" ) && false !== strpos( $stylesheet, "self::inline_asset( 'js/sitemap.js', 'script' )" ) && false !== strpos( $stylesheet, 'PUBLISHRANK_SEO_PATH . \'assets/\' . $relative' ),
	'sitemap XSL template has no remote CSS or JavaScript source links' => false === strpos( $stylesheet, 'href="https://' ) && false === strpos( $stylesheet, '<script src=' ),
	'admin guard checks both capability and request nonce' => false !== strpos( $admin_assets, "current_user_can( 'manage_options' )" ) && false !== strpos( $admin_assets, 'check_admin_referer( $action )' ),
);
$handlers = array(
	'src/Admin/SiteVerificationPage.php' => 'publishrank_verification_upload',
	'src/Admin/ConfigHistoryPage.php' => 'publishrank_snapshot_restore',
	'src/Admin/RedirectsPage.php' => 'publishrank_seo_redirect_settings',
);
foreach ( $handlers as $file => $action ) {
	$source = $read( $file );
	$tests[ $action . ' handler uses the shared capability-and-nonce guard' ] = false !== strpos( $source, "AdminAssets::guard( '$action' )" ) && false !== strpos( $source, "check_admin_referer( '$action' )" );
}
$failed = 0;
foreach ( $tests as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}
if ( $failed ) {
	exit( 1 );
}
echo "Release 2.9.76 checks passed.\n";
