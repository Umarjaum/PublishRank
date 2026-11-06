<?php
/** Regression checks for the bundled Urdu translation catalog and loader. */
if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) { exit; }

$root = dirname( __DIR__ );
$header = file_get_contents( $root . '/publishrank.php' );
$block = file_get_contents( $root . '/src/SEO/BreadcrumbEngine.php' );
$readme = file_get_contents( $root . '/readme.txt' );
$po = file_get_contents( $root . '/languages/publishrank-ur.po' );
$mo_path = $root . '/languages/publishrank-ur.mo';

$passed = 0;
$failed = 0;
function urdu_check( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "PASS {$label}\n";
		++$passed;
	} else {
		echo "FAIL {$label}\n";
		++$failed;
	}
}

urdu_check( false !== strpos( $header, 'Text Domain: publishrank' ) && false !== strpos( $header, 'Domain Path: /languages' ), 'plugin header declares the translation domain path' );
urdu_check( false !== strpos( $header, "load_plugin_textdomain( 'publishrank'" ), 'plugin loads the bundled text domain during init' );
urdu_check( false !== strpos( $po, '"Language: ur\\n"' ) && false !== strpos( $po, '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"' ), 'Urdu locale and plural rules are declared in the PO catalog' );
urdu_check( 2600 <= substr_count( $po, "\nmsgid " ), 'Urdu catalog covers the plugin interface strings' );
urdu_check( is_file( $mo_path ) && 100000 < filesize( $mo_path ), 'compiled Urdu MO catalog is bundled' );
urdu_check( false !== strpos( $block, 'wp.i18n.setLocaleData(' ) && false !== strpos( $block, "__( 'Breadcrumbs', 'publishrank' )" ), 'Gutenberg breadcrumbs labels use Urdu locale data' );
urdu_check( false !== strpos( $readme, 'An Urdu translation of the PublishRank interface is included.' ), 'plugin readme explains how to enable Urdu' );

echo "\nUrdu translation checks: {$passed} passed, {$failed} failed.\n";
exit( $failed ? 1 : 0 );
