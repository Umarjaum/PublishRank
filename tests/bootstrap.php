<?php
/**
 * Test bootstrap: loads an existing WordPress install (SQLite or MySQL) with PublishRank active.
 *
 * Point it at WordPress with the WP_PATH environment variable (the folder that contains wp-load.php).
 * When the plugin sits in wp-content/plugins/publishrank the path is found automatically.
 *
 * WARNING: tests create and delete posts, terms and options. Use a disposable site, never production.
 *
 * @package PublishRankSEO
 */

$publishrank_wp_path = getenv( 'WP_PATH' ) ?: '';
if ( '' === $publishrank_wp_path ) {
	$candidate = dirname( __DIR__, 4 ); // wp-content/plugins/publishrank/tests -> site root.
	if ( is_readable( $candidate . '/wp-load.php' ) ) {
		$publishrank_wp_path = $candidate;
	}
}
if ( '' === $publishrank_wp_path || ! is_readable( rtrim( $publishrank_wp_path, '/' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set WP_PATH to a disposable WordPress install (the folder containing wp-load.php).\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = getenv( 'WP_TEST_HOST' ) ?: 'localhost:8080';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

if ( false === getenv( 'WP_ENVIRONMENT_TYPE' ) ) {
	putenv( 'WP_ENVIRONMENT_TYPE=local' ); // WordPress reads this natively; lets localhost URLs validate.
}
require rtrim( $publishrank_wp_path, '/' ) . '/wp-load.php';

if ( ! defined( 'PUBLISHRANK_SEO_VERSION' ) ) {
	require_once dirname( __DIR__ ) . '/publishrank.php';
}
if ( ! class_exists( 'PHPUnit\\Framework\\TestCase' ) ) {
	require __DIR__ . '/shim/phpunit-shim.php';
}
require_once __DIR__ . '/PublishRankTestCase.php';
