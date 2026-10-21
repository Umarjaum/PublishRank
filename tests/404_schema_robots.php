<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/**
 * Static regression checks for 404 monitoring, schema author controls and robots directives.
 * Run with: php tests/404_schema_robots.php
 */
$root = dirname( __DIR__ );
$read = static function ( $relative ) use ( $root ) {
	$path = $root . '/' . $relative;
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "FAIL: missing file {$relative}\n" );
		exit( 1 );
	}
	return file_get_contents( $path );
};
$checks = array();
$assert = static function ( $condition, $message ) use ( &$checks ) {
	$checks[] = array( (bool) $condition, $message );
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
};
$monitor = $read( 'src/Monitor/NotFoundMonitor.php' );
$not_found = $read( 'src/Admin/NotFoundPage.php' );
$post_meta = $read( 'src/Meta/PostMeta.php' );
$graph = $read( 'src/Schema/GraphOutput.php' );
$meta_generator = $read( 'src/Meta/MetaGenerator.php' );
$robots_page = $read( 'src/Admin/RobotsPage.php' );
$robots_txt = $read( 'src/Robots/RobotsTxt.php' );
$settings = $read( 'src/Admin/SettingsPage.php' );
$plugin = $read( 'src/Core/Plugin.php' );
$llms = $read( 'src/AI/LLMSTxt.php' );
$llms_page = $read( 'src/Admin/LLMSTxtPage.php' );

$assert( strpos( $monitor, "add_action( 'template_redirect'" ) !== false && strpos( $monitor, 'is_404()' ) !== false, '404 capture is hooked into frontend requests and checks actual 404 responses' );
$assert( strpos( $monitor, "'publishrank_seo_404_monitor', false" ) !== false, '404 logging is opt-in by default' );
$assert( strpos( $monitor, 'array_slice( $log, 0, 500 )' ) !== false, '404 log is bounded to 500 grouped entries' );
$assert( strpos( $monitor, 'HTTP_REFERER' ) !== false && strpos( $monitor, 'REMOTE_ADDR' ) === false, '404 entries retain referrer context without storing visitor IP addresses' );
$assert( strpos( $not_found, 'publishrank_seo_404_clear' ) !== false && strpos( $not_found, "update_option( self::OPTION, array(), false )" ) !== false, 'administrator can clear the entire 404 log' );
$assert( strpos( $not_found, 'publishrank_seo_404_redirect' ) !== false && strpos( $not_found, 'RedirectStore::add( $path, $target, 301, false )' ) !== false, '404 entries can create validated 301 redirects' );
$assert( strpos( $not_found, 'publishrank_seo_404_dismiss' ) !== false && strpos( $not_found, 'Create page' ) !== false, '404 entries can be dismissed or used to start creating a page' );
$assert( strpos( $post_meta, 'AUTHOR_NAME' ) !== false && strpos( $post_meta, 'AUTHOR_JOB_TITLE' ) !== false && strpos( $post_meta, 'AUTHOR_IMAGE' ) !== false, 'per-article author schema fields exist' );
$assert( strpos( $post_meta, 'normalize_robots' ) !== false && strpos( $post_meta, 'self::ROBOTS' ) !== false, 'per-article robots directives are sanitized before saving' );
$assert( strpos( $graph, 'PostMeta::AUTHOR_NAME' ) !== false && strpos( $graph, 'PostMeta::AUTHOR_JOB_TITLE' ) !== false && strpos( $graph, 'PostMeta::AUTHOR_IMAGE' ) !== false, 'custom author metadata is used in JSON-LD author schema' );
$assert( strpos( $graph, 'PostMeta::SCHEMA_TYPE' ) !== false && strpos( $graph, 'SchemaTypes::article_types' ) !== false, 'article schema type can be selected from an allow-list' );
$assert( strpos( $meta_generator, "'noindex'" ) !== false && strpos( $meta_generator, "'index'" ) !== false && strpos( $meta_generator, 'normalize_robots' ) !== false, 'index/noindex and follow/nofollow directives are normalized safely' );
$assert( strpos( $settings, 'publishrank_seo_default_robots' ) !== false && strpos( $settings, 'publishrank_noindex_author_archives' ) !== false, 'global default robots and archive noindex settings are available' );
$assert( strpos( $robots_page, 'publishrank_robots_custom' ) !== false && strpos( $robots_txt, 'clean_rules' ) !== false, 'robots.txt supports sanitized custom rules' );
$assert( strpos( $plugin, 'NotFoundMonitor::class' ) !== false && strpos( $plugin, 'NotFoundPage::class' ) !== false && strpos( $plugin, 'RobotsPage::class' ) !== false, '404 monitor, admin screen and robots settings are wired into plugin boot' );
$assert( strpos( $plugin, 'LLMSTxt::class' ) !== false && strpos( $plugin, 'LLMSTxtPage::class' ) !== false && strpos( $llms, 'llms-full.txt' ) !== false, 'existing configurable llms.txt and optional llms-full.txt remain wired in' );

$failed = count( array_filter( $checks, static function ( $row ) { return ! $row[0]; } ) );
echo $failed ? "404/schema/robots checks failed: {$failed}\n" : "404/schema/robots checks passed.\n";
exit( $failed ? 1 : 0 );
