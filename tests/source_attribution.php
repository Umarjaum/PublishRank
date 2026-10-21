<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
define( 'PUBLISHRANK_SEO_VERSION', '2.9.58' );

$GLOBALS['publishrank_test_hooks'] = array();
$GLOBALS['publishrank_test_options'] = array();
$GLOBALS['publishrank_test_admin'] = false;
$GLOBALS['publishrank_test_ajax'] = false;
$GLOBALS['publishrank_test_feed'] = false;
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['publishrank_test_hooks'][ $hook ][] = $callback; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['publishrank_test_options'] ) ? $GLOBALS['publishrank_test_options'][ $key ] : $default; }
function is_admin() { return $GLOBALS['publishrank_test_admin']; }
function wp_doing_ajax() { return $GLOBALS['publishrank_test_ajax']; }
function is_feed() { return $GLOBALS['publishrank_test_feed']; }
function is_robots() { return false; }
function is_embed() { return false; }
function get_query_var( $key ) { return ''; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_kses( $html, $allowed ) { return $html; }

require dirname( __DIR__ ) . '/src/Security/TraceIntegrity.php';
require dirname( __DIR__ ) . '/src/Core/FrontendHeadComment.php';

$passed = 0;
$failed = 0;
function check_result( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) { fwrite( STDOUT, "PASS {$label}\n" ); ++$passed; }
	else { fwrite( STDOUT, "FAIL {$label}\n" ); ++$failed; }
}

$trace = new \PublishRankSEO\Security\TraceIntegrity();
$comment = new \PublishRankSEO\Core\FrontendHeadComment();
$trace->register();
$comment->register();
ob_start();
$trace->frontend_trace();
$comment->render();
$default_output = ob_get_clean();
check_result( '' === $default_output, 'public attribution output is disabled by default' );
check_result( false === \PublishRankSEO\Security\TraceIntegrity::enabled(), 'technical markers are opt-in by default' );
check_result( false === \PublishRankSEO\Core\FrontendHeadComment::should_emit(), 'powered-by comment is opt-in by default' );
check_result( '' === \PublishRankSEO\Security\TraceIntegrity::markup(), 'theme recovery has no attribution markup by default' );

$GLOBALS['publishrank_test_options']['publishrank_source_attribution'] = 1;
$trace->register();
ob_start();
$trace->frontend_trace();
$trace_output = ob_get_clean();
check_result( false !== strpos( $trace_output, 'name="publishrank-brand" content="PublishRank"' ), 'brand marker is emitted after opt-in' );
check_result( false !== strpos( $trace_output, 'name="publishrank-version" content="2.9.58"' ), 'version marker reflects the unchanged plugin version' );
check_result( false !== strpos( $trace_output, 'fingerprint=' . \PublishRankSEO\Security\TraceIntegrity::fingerprint() ), 'deterministic fingerprint is emitted after opt-in' );
check_result( false !== strpos( \PublishRankSEO\Security\TraceIntegrity::markup(), 'name="publishrank-brand"' ), 'shared recovery markup is available after opt-in' );
ob_start();
$comment->render();
$comment_output = ob_get_clean();
check_result( false !== strpos( $comment_output, 'SEO of this website is Powered by PublishRank SEO' ), 'powered-by comment appears only after opt-in' );

$GLOBALS['publishrank_test_admin'] = true;
ob_start();
$trace->frontend_trace();
$comment->render();
$admin_output = ob_get_clean();
check_result( '' === $admin_output, 'source attribution is omitted from admin requests' );
$GLOBALS['publishrank_test_admin'] = false;
$GLOBALS['publishrank_test_feed'] = true;
ob_start();
$trace->frontend_trace();
$comment->render();
$feed_output = ob_get_clean();
check_result( '' === $feed_output, 'source attribution is omitted from feeds' );
$GLOBALS['publishrank_test_feed'] = false;

$settings = file_get_contents( dirname( __DIR__ ) . '/src/Admin/SettingsPage.php' );
$installer = file_get_contents( dirname( __DIR__ ) . '/src/Core/Installer.php' );
check_result( false !== strpos( $settings, 'name="publishrank_source_attribution"' ) && false !== strpos( $settings, 'checked( $val( \'publishrank_source_attribution\', false )' ), 'source attribution is an explicit opt-in control' );
check_result( false !== strpos( $settings, 'name="publishrank_public_attribution"' ) && false !== strpos( $settings, 'checked( $val( \'publishrank_public_attribution\', false )' ), 'visible footer credit is an explicit opt-in control' );
check_result( 1 === preg_match( "/'publishrank_public_attribution'\s*=>\s*0/", $installer ), 'fresh installs default the footer credit off' );
check_result( false !== strpos( $installer, 'publishrank_attribution_optin_migrated' ), 'upgrades migrate earlier forced-default credits off once' );

fwrite( STDOUT, "\nSource attribution checks: {$passed} passed, {$failed} failed.\n" );
exit( $failed ? 1 : 0 );
