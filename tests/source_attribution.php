<?php
/** Standalone regression checks for always-on technical source provenance. */
if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
define( 'PUBLISHRANK_SEO_VERSION', '2.9.76' );

$GLOBALS['publishrank_test_hooks'] = array();
$GLOBALS['publishrank_test_admin'] = false;
$GLOBALS['publishrank_test_ajax'] = false;
$GLOBALS['publishrank_test_feed'] = false;

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['publishrank_test_hooks'][ $hook ] = $callback;
}
function is_admin() { return $GLOBALS['publishrank_test_admin']; }
function wp_doing_ajax() { return $GLOBALS['publishrank_test_ajax']; }
function is_feed() { return $GLOBALS['publishrank_test_feed']; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_kses( $value, $allowed_html = array() ) { return (string) $value; }

require dirname( __DIR__ ) . '/src/Security/TraceIntegrity.php';

$passed = 0;
$failed = 0;
function check_result( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "PASS {$label}\n";
		++$passed;
	} else {
		echo "FAIL {$label}\n";
		++$failed;
	}
}

$trace = new \PublishRankSEO\Security\TraceIntegrity();
$trace->register();
check_result( isset( $GLOBALS['publishrank_test_hooks']['wp_head'] ), 'source provenance hook registers without an enable option' );

ob_start();
$GLOBALS['publishrank_test_hooks']['wp_head']();
$output = ob_get_clean();
check_result( false !== strpos( $output, 'name="publishrank-brand" content="PublishRank"' ), 'brand marker is emitted' );
check_result( false !== strpos( $output, 'name="publishrank-version" content="2.9.76"' ), 'version marker is emitted' );
check_result( false !== strpos( $output, 'fingerprint=' . \PublishRankSEO\Security\TraceIntegrity::fingerprint() ), 'deterministic fingerprint is emitted' );
check_result( false !== strpos( \PublishRankSEO\Security\TraceIntegrity::markup(), 'name="publishrank-brand"' ), 'shared markup is available for fallback injection' );

$GLOBALS['publishrank_test_admin'] = true;
ob_start();
$trace->frontend_trace();
$admin_output = ob_get_clean();
check_result( '' === $admin_output, 'source markers are omitted from admin requests' );
$GLOBALS['publishrank_test_admin'] = false;
$GLOBALS['publishrank_test_feed'] = true;
ob_start();
$trace->frontend_trace();
$feed_output = ob_get_clean();
check_result( '' === $feed_output, 'source markers are omitted from feeds' );
$GLOBALS['publishrank_test_feed'] = false;

$settings = file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' );
check_result( false === strpos( $settings, 'name="publishrank_source_attribution"' ), 'source provenance has no settings toggle' );
check_result( false !== strpos( $settings, 'Always enabled' ) && false === strpos( $settings, 'publishrank_public_attribution' ), 'technical markers are disclosed without a nonexistent visible-credit toggle' );

echo "\nSource attribution checks: {$passed} passed, {$failed} failed.\n";
exit( $failed ? 1 : 0 );
