<?php
/** Focused tests for the optional PublishRank News feed. */
if ( 'cli' !== PHP_SAPI ) {
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['pr_news_options']   = array();
$GLOBALS['pr_news_transients'] = array();
$GLOBALS['pr_news_http_calls'] = 0;
$GLOBALS['pr_news_request_args'] = array();
$GLOBALS['pr_news_response'] = null;

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

function __( $text, $domain = null ) {
	return $text;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['pr_news_options'] ) ? $GLOBALS['pr_news_options'][ $name ] : $default;
}

function get_transient( $name ) {
	return isset( $GLOBALS['pr_news_transients'][ $name ]['value'] ) ? $GLOBALS['pr_news_transients'][ $name ]['value'] : false;
}

function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['pr_news_transients'][ $name ] = array(
		'value'      => $value,
		'expiration' => $expiration,
	);
	return true;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['pr_news_http_calls']++;
	$GLOBALS['pr_news_request_args'] = array(
		'url'  => $url,
		'args' => $args,
	);
	return $GLOBALS['pr_news_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	$key = strtolower( $header );
	return isset( $response['headers'][ $key ] ) ? $response['headers'][ $key ] : '';
}

function esc_url_raw( $url, $protocols = array() ) {
	$parts = parse_url( (string) $url );
	if ( ! is_array( $parts ) || ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), $protocols, true ) ) {
		return '';
	}
	return (string) $url;
}

function wp_parse_url( $url ) {
	return parse_url( $url );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}

require_once __DIR__ . '/../src/Admin/NewsPage.php';

use PublishRankSEO\Admin\NewsPage;

$checks = 0;
$failures = array();
$assert = static function ( $condition, $message ) use ( &$checks, &$failures ) {
	++$checks;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$page = new NewsPage();
$method = new ReflectionMethod( NewsPage::class, 'get_feed' );
$method->setAccessible( true );
$disabled = $method->invoke( $page );
$assert( is_wp_error( $disabled ), 'Disabled feed returns a consent-required error.' );
$assert( 0 === $GLOBALS['pr_news_http_calls'], 'No remote request occurs before opt-in.' );

$now = strtotime( '2026-10-24T00:00:00Z' );
$item = static function ( $id, $title, $published, $extra = array() ) {
	return array_merge(
		array(
			'id'           => $id,
			'active'       => true,
			'title'        => $title,
			'summary'      => 'Summary for ' . $title,
			'published_at' => $published,
			'labels'       => array(),
			'actions'      => array(),
		),
		$extra
	);
};
$fixture = array(
	'schema_version' => '1.0',
	'publisher'      => array( 'name' => 'PublishRank SEO' ),
	'feed'           => array( 'status' => 'active', 'cache_ttl' => 3600 ),
	'announcements'  => array(
		$item( 'newest', 'Newest update', '2026-10-23T00:00:00Z', array( 'image' => 'https://publishrank.netlify.app/assets/news.jpg' ) ),
		$item( 'older', 'Older update', '2026-10-22T00:00:00Z' ),
		$item( 'inactive', 'Inactive item', '2026-10-23T00:00:00Z', array( 'active' => false ) ),
		$item( 'example', 'Example: placeholder', '2026-10-23T00:00:00Z', array( 'labels' => array( 'example' ) ) ),
		$item( 'future', 'Future item', '2026-10-25T00:00:00Z' ),
		$item( 'expired', 'Expired item', '2026-10-20T00:00:00Z', array( 'expires_at' => '2026-10-23T23:59:59Z' ) ),
		$item( 'too-new', 'Version-targeted item', '2026-10-23T00:00:00Z', array( 'targeting' => array( 'minimum_plugin_version' => '3.0.0' ) ) ),
		$item( 'string-false', 'String false is not active', '2026-10-23T00:00:00Z', array( 'active' => 'false' ) ),
	),
);
$GLOBALS['pr_news_options'][ NewsPage::ENABLED_OPTION ] = true;
$GLOBALS['pr_news_response'] = array(
	'response' => array( 'code' => 200 ),
	'headers'  => array( 'content-type' => 'application/json; charset=utf-8' ),
	'body'     => wp_json_encode( $fixture ),
);
$loaded = $method->invoke( $page );
$assert( is_array( $loaded ) && empty( $loaded['stale'] ), 'Valid JSON feed loads successfully.' );
$assert( 1 === $GLOBALS['pr_news_http_calls'], 'Opt-in makes exactly one request when the cache is empty.' );
$assert( NewsPage::FEED_URL === $GLOBALS['pr_news_request_args']['url'], 'Request uses the fixed documented feed URL.' );
$assert( 'PublishRank News Feed; WordPress' === $GLOBALS['pr_news_request_args']['args']['user-agent'], 'Request uses the generic User-Agent.' );
$assert( 3600 === $GLOBALS['pr_news_transients'][ NewsPage::CACHE_KEY ]['expiration'], 'Feed cache honors its one-hour interval.' );
$assert( 86400 === $GLOBALS['pr_news_transients'][ NewsPage::STALE_KEY ]['expiration'], 'Stale fallback expires within one day.' );

$again = $method->invoke( $page );
$assert( 1 === $GLOBALS['pr_news_http_calls'], 'Fresh cached feed avoids another remote request.' );
$visible = NewsPage::filter_announcements( $loaded['payload']['announcements'], $now, '2.9.71' );
$visible_ids = array_map(
	static function ( $entry ) {
		return $entry['id'];
	},
	$visible
);
$assert( array( 'newest', 'older' ) === $visible_ids, 'Only active, current, version-eligible, non-example items render in newest-first order.' );
$assert( 'https://publishrank.netlify.app/assets/news.jpg' === NewsPage::trusted_url( 'https://publishrank.netlify.app/assets/news.jpg' ), 'HTTPS URLs on the fixed feed host are accepted.' );
$assert( '' === NewsPage::trusted_url( 'https://attacker.example/pixel.png' ), 'Third-party image/action URLs are rejected.' );
$assert( '' === NewsPage::trusted_url( 'http://publishrank.netlify.app/image.jpg' ), 'HTTP image/action URLs are rejected.' );
$assert( '' === NewsPage::trusted_url( 'https://publishrank.netlify.app:444/image.jpg' ), 'Unexpected HTTPS ports are rejected.' );

$GLOBALS['pr_news_response'] = new WP_Error( 'network', 'simulated offline state' );
$stale = $method->invoke( $page, true );
$assert( is_array( $stale ) && ! empty( $stale['stale'] ), 'A cached feed is available as a marked stale fallback after a refresh failure.' );
$GLOBALS['pr_news_options'][ NewsPage::ENABLED_OPTION ] = false;
$disabled_again = $method->invoke( $page, true );
$assert( is_wp_error( $disabled_again ) && 2 === $GLOBALS['pr_news_http_calls'], 'Disabling the feed prevents further remote requests.' );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}
echo "PASS: {$checks} News feed checks.\n";
