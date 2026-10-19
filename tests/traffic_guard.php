<?php
/** Standalone regression tests for Traffic Guard parsing and proxy trust. */
if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
$GLOBALS['pr_opts'] = array();
$GLOBALS['pr_test_flags'] = array();
if ( ! function_exists( '__' ) ) { function __( $text ) { return $text; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['pr_opts'] ) ? $GLOBALS['pr_opts'][ $key ] : $default; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return ! empty( $GLOBALS['pr_test_flags']['admin'] ); } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return ! empty( $GLOBALS['pr_test_flags']['logged_in'] ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $capability ) { return ! empty( $GLOBALS['pr_test_flags']['admin_cap'] ) && 'manage_options' === $capability; } }
if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax() { return false; } }
if ( ! function_exists( 'wp_doing_cron' ) ) { function wp_doing_cron() { return false; } }
if ( ! function_exists( 'is_feed' ) ) { function is_feed() { return false; } }
if ( ! function_exists( 'is_robots' ) ) { function is_robots() { return false; } }
if ( ! function_exists( 'is_preview' ) ) { function is_preview() { return false; } }
if ( ! function_exists( 'is_trackback' ) ) { function is_trackback() { return false; } }
if ( ! function_exists( 'is_embed' ) ) { function is_embed() { return false; } }
if ( ! function_exists( 'get_query_var' ) ) { function get_query_var() { return false; } }
require __DIR__ . '/../src/Security/TrafficGuard.php';

use PublishRankSEO\Security\TrafficGuard as Guard;

$failed = 0;
function check_guard( $label, $condition ) {
	global $failed;
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $condition ) { $failed++; }
}
function invoke_guard_private( $method, $args = array() ) {
	$reflection = new ReflectionMethod( Guard::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
}

check_guard( 'IPv4 exact match', Guard::matches_network( '192.0.2.10', '192.0.2.10' ) );
check_guard( 'IPv4 exact mismatch', ! Guard::matches_network( '192.0.2.11', '192.0.2.10' ) );
check_guard( 'IPv4 CIDR includes address', Guard::matches_network( '198.51.100.42', '198.51.100.0/24' ) );
check_guard( 'IPv4 CIDR excludes adjacent network', ! Guard::matches_network( '198.51.101.42', '198.51.100.0/24' ) );
check_guard( 'IPv6 CIDR includes address', Guard::matches_network( '2001:db8:abcd::5', '2001:db8:abcd::/48' ) );
check_guard( 'IPv6 CIDR excludes adjacent network', ! Guard::matches_network( '2001:db8:abce::5', '2001:db8:abcd::/48' ) );
check_guard( 'mixed IP families do not match', ! Guard::matches_network( '192.0.2.10', '2001:db8::/32' ) );
check_guard( 'invalid CIDR prefix does not match', ! Guard::matches_network( '192.0.2.10', '192.0.2.0/33' ) );

$networks = Guard::inspect_networks( "192.0.2.10\n2001:db8::/32\ninvalid\n192.0.2.10" );
check_guard( 'network list normalizes, deduplicates, and rejects invalid entries', 2 === count( $networks['valid'] ) && 1 === count( $networks['invalid'] ) );
$countries = Guard::inspect_country_codes( "us\nGB\nT1\nUSA\nxx" );
check_guard( 'country list uppercases and allows Cloudflare special codes', array( 'US', 'GB', 'T1', 'XX' ) === $countries['valid'] && array( 'USA' ) === $countries['invalid'] );
check_guard( 'Traffic Guard is disabled by default', empty( Guard::settings()['enabled'] ) );

$trusted = array( '198.51.100.0/24' );
$_SERVER = array( 'REMOTE_ADDR' => '203.0.113.44', 'HTTP_X_FORWARDED_FOR' => '192.0.2.77' );
check_guard( 'untrusted peer cannot spoof X-Forwarded-For', '203.0.113.44' === invoke_guard_private( 'client_ip', array( $trusted ) ) );
$_SERVER = array( 'REMOTE_ADDR' => '198.51.100.9', 'HTTP_CF_CONNECTING_IP' => '192.0.2.77', 'HTTP_CF_IPCOUNTRY' => 'us' );
check_guard( 'trusted proxy may provide a validated Cloudflare client IP', '192.0.2.77' === invoke_guard_private( 'client_ip', array( $trusted ) ) );
check_guard( 'country is read only from a trusted peer', 'US' === invoke_guard_private( 'country_code', array( $trusted ) ) );
$_SERVER = array( 'REMOTE_ADDR' => '203.0.113.44', 'HTTP_CF_IPCOUNTRY' => 'US' );
check_guard( 'untrusted country header fails open', '' === invoke_guard_private( 'country_code', array( $trusted ) ) );
$_SERVER = array( 'REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '192.0.2.1, 198.51.100.10' );
check_guard( 'trusted X-Forwarded-For chain skips known proxy hops from the right', '192.0.2.1' === invoke_guard_private( 'client_ip', array( $trusted ) ) );
$_SERVER = array( 'REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip' );
check_guard( 'invalid forwarded chain falls back to the immediate peer', '198.51.100.9' === invoke_guard_private( 'client_ip', array( $trusted ) ) );

$_SERVER = array( 'REQUEST_URI' => '/wp-sitemap.xml' );
$GLOBALS['pr_test_flags'] = array( 'logged_in' => true, 'admin_cap' => true );
check_guard( 'admin account is not traffic-blockable', ! invoke_guard_private( 'is_blockable_request' ) );
$GLOBALS['pr_test_flags'] = array( 'logged_in' => true, 'admin_cap' => false );
$_SERVER = array( 'REQUEST_URI' => '/article/' );
check_guard( 'non-administrator accounts follow the configured policy', invoke_guard_private( 'is_blockable_request' ) );
$GLOBALS['pr_test_flags'] = array();
$_SERVER = array( 'REQUEST_URI' => '/wp-sitemap.xml' );
check_guard( 'XML sitemap route is not traffic-blockable', ! invoke_guard_private( 'is_blockable_request' ) );
$_SERVER = array( 'REQUEST_URI' => '/index.php' );
$_GET = array( 'rest_route' => '/wp/v2/posts' );
check_guard( 'REST query route is not traffic-blockable', ! invoke_guard_private( 'is_blockable_request' ) );
$_GET = array();
$_SERVER = array( 'REQUEST_URI' => '/article/' );
check_guard( 'ordinary front-end path can be evaluated', invoke_guard_private( 'is_blockable_request' ) );

if ( $failed ) {
	echo "\n$failed Traffic Guard regression check(s) failed.\n";
	exit( 1 );
}
echo "\nAll Traffic Guard regression checks passed.\n";
