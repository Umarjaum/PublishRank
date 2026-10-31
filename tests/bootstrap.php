<?php
/**
 * PublishRank integration-test bootstrap.
 *
 * Boots a REAL WordPress installation (any database driver WordPress supports, including the SQLite integration)
 * with the plugin under test activated, and provides a tiny assertion library. No mocks of WordPress are used.
 *
 * Usage: PR_WP_PATH=/path/to/wordpress php tests/run.php [filter]
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

$pr_wp = getenv( 'PR_WP_PATH' );
if ( ! $pr_wp || ! is_readable( rtrim( $pr_wp, '/' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set PR_WP_PATH to a WordPress install that has the PublishRank plugin directory available.\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']      = 'example.test';
$_SERVER['SERVER_NAME']    = 'example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTPS']          = 'on';
require rtrim( $pr_wp, '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

if ( ! class_exists( '\PublishRankSEO\Core\Plugin' ) ) {
	fwrite( STDERR, "PublishRank is not loaded: activate it in the test WordPress first.\n" );
	exit( 2 );
}

/** Assertion failure. */
class PR_Assertion_Failed extends Exception {}

function pr_fail( $message ) {
	throw new PR_Assertion_Failed( $message );
}
function pr_assert( $condition, $message = 'assertion failed' ) {
	if ( ! $condition ) {
		pr_fail( $message );
	}
}
function pr_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		pr_fail( ( $message ? $message . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ' but got ' . var_export( $actual, true ) );
	}
}
function pr_contains( $needle, $haystack, $message = '' ) {
	if ( false === strpos( (string) $haystack, (string) $needle ) ) {
		pr_fail( ( $message ? $message . ': ' : '' ) . 'expected to find ' . var_export( $needle, true ) . ' in ' . var_export( mb_substr( (string) $haystack, 0, 600 ), true ) );
	}
}
function pr_not_contains( $needle, $haystack, $message = '' ) {
	if ( false !== strpos( (string) $haystack, (string) $needle ) ) {
		pr_fail( ( $message ? $message . ': ' : '' ) . 'did not expect ' . var_export( $needle, true ) . ' in ' . var_export( mb_substr( (string) $haystack, 0, 600 ), true ) );
	}
}
function pr_count( $expected, $needle, $haystack, $message = '' ) {
	$actual = substr_count( (string) $haystack, (string) $needle );
	if ( $actual !== $expected ) {
		pr_fail( ( $message ? $message . ': ' : '' ) . "expected {$expected} occurrence(s) of " . var_export( $needle, true ) . " but found {$actual}" );
	}
}

/** Remove every PublishRank option and restore the shipped defaults (fresh-install state). */
function pr_reset_state() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'publishrank\\_%'" ); // phpcs:ignore
	// Content too: every test starts from an empty site.
	foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $pid ) {
		wp_delete_post( $pid, true );
	}
	foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids' ) ) as $tid ) {
		if ( (int) $tid !== (int) get_option( 'default_category' ) ) {
			wp_delete_term( $tid, 'category' );
		}
	}
	wp_update_user( array( 'ID' => 1, 'display_name' => 'admin' ) );
	wp_cache_flush();
	\PublishRankSEO\Core\Installer::install_defaults();
	\PublishRankSEO\Meta\SeoData::flush();
	\PublishRankSEO\SEO\BreadcrumbEngine::reset();
	remove_all_filters( 'publishrank_schema_graph' );
	remove_all_filters( 'publishrank_breadcrumb_items' );
	remove_all_filters( 'publishrank_breadcrumb_schema_external_owner' );
	update_option( 'blogname', 'Test Site' );
	update_option( 'blogdescription', 'Just a tagline' );
	update_option( 'date_format', 'F j, Y' );
	update_option( 'permalink_structure', '/%postname%/' );
	update_option( 'show_on_front', 'posts' );
	delete_option( 'page_for_posts' );
	wp_set_current_user( 1 );
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	$wp_rewrite->flush_rules( false );
	// The register_setting() sanitizers live in the admin settings page; register them as options.php would.
	( new \PublishRankSEO\Admin\SettingsPage() )->settings();
}

/** Make the next request look like wp-admin (is_admin() true) for upgrade code. */
function pr_as_admin() {
	set_current_screen( 'dashboard' );
}
function pr_as_frontend() {
	$GLOBALS['current_screen'] = null; // phpcs:ignore
	unset( $GLOBALS['current_screen'] );
}

/** Simulate a front-end request (the same approach as the WordPress core test suite's go_to()). */
function pr_go_to( $url ) {
	pr_as_frontend();
	$_GET  = array();
	$_POST = array();
	foreach ( array( 'query_string', 'id', 'postdata', 'authordata', 'day', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages', 'pagenow' ) as $v ) {
		unset( $GLOBALS[ $v ] );
	}
	$parts = parse_url( $url );
	$req   = isset( $parts['path'] ) ? $parts['path'] : '/';
	if ( isset( $parts['query'] ) ) {
		$req .= '?' . $parts['query'];
		parse_str( $parts['query'], $_GET );
	}
	$_SERVER['REQUEST_URI'] = $req;
	$GLOBALS['wp']->query_vars = array();
	unset( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] );
	$GLOBALS['wp_the_query'] = new WP_Query();
	$GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
	$GLOBALS['wp']           = new WP();
	$GLOBALS['wp']->main( isset( $parts['query'] ) ? $parts['query'] : '' );
	\PublishRankSEO\SEO\BreadcrumbEngine::reset();
	\PublishRankSEO\Meta\SeoData::flush();
	if ( pr_registry() ) {
		pr_registry()->reset();
	}
}

/** The OutputRegistry instance PublishRank booted with (found through its wp_head callbacks). */
function pr_registry() {
	global $wp_filter;
	foreach ( (array) ( isset( $wp_filter['wp_head'] ) ? $wp_filter['wp_head']->callbacks : array() ) as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof \PublishRankSEO\Meta\HeadOutput ) {
				$prop = new ReflectionProperty( $cb['function'][0], 'registry' );
				$prop->setAccessible( true );
				return $prop->getValue( $cb['function'][0] );
			}
		}
	}
	return null;
}

/** Output of wp_head() for the current request. Output slots are released first so it can be called repeatedly. */
function pr_head( $fresh = true ) {
	if ( $fresh && pr_registry() ) {
		pr_registry()->reset();
	}
	ob_start();
	wp_head();
	return (string) ob_get_clean();
}

/** The_content for the main post with filters applied (where automatic breadcrumbs print). */
function pr_content() {
	ob_start();
	if ( have_posts() ) {
		the_post();
		the_content();
	}
	return (string) ob_get_clean();
}

/** All JSON-LD blocks in a string, decoded. */
function pr_jsonld( $html ) {
	$blocks = array();
	if ( preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m ) ) {
		foreach ( $m[1] as $json ) {
			$data = json_decode( $json, true );
			pr_assert( is_array( $data ), 'JSON-LD block is not valid JSON: ' . json_last_error_msg() );
			$blocks[] = $data;
		}
	}
	return $blocks;
}

/** Flattened @graph nodes of every JSON-LD block. */
function pr_nodes( $html ) {
	$nodes = array();
	foreach ( pr_jsonld( $html ) as $block ) {
		foreach ( (array) ( $block['@graph'] ?? array() ) as $node ) {
			$nodes[] = $node;
		}
	}
	return $nodes;
}

function pr_node_of_type( $nodes, $type ) {
	foreach ( $nodes as $node ) {
		$types = (array) ( $node['@type'] ?? array() );
		if ( in_array( $type, $types, true ) ) {
			return $node;
		}
	}
	return null;
}

/** Create a published post with a category and return its ID. */
function pr_make_post( $args = array(), $category = 'News' ) {
	$cat = term_exists( $category, 'category' );
	$cat = $cat ? (int) $cat['term_id'] : (int) wp_insert_term( $category, 'category' )['term_id'];
	$id  = wp_insert_post(
		array_merge(
			array(
				'post_title'    => 'Hello World',
				'post_name'     => 'hello-world',
				'post_status'   => 'publish',
				'post_content'  => 'This is the body of the article with enough words to be summarised.',
				'post_author'   => 1,
				'post_category' => array( $cat ),
			),
			$args
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		pr_fail( 'could not create post: ' . $id->get_error_message() );
	}
	return (int) $id;
}
