<?php
/**
 * News & What's New integration checks. Run with: wp eval-file tests/whats-new-integration.php (isolated WordPress).
 * HTTP is simulated with the pre_http_request filter; the feed body is the real website feed when
 * PUBLISHRANK_TEST_FEED points to it (e.g. http://localhost:8080/publishrank-feed.json), else a fixture.
 */
use PublishRankSEO\News\WhatsNew as W;

$n = 0; $fail = 0;
$expect = static function ( $label, $got, $want ) use ( &$n, &$fail ) {
	++$n;
	$ok = $got === $want;
	$fail += $ok ? 0 : 1;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok ? '' : ' => got ' . var_export( $got, true ) . ', want ' . var_export( $want, true ) ) . "\n";
};

$backup = array( W::CACHE => get_option( W::CACHE, null ), W::SUMMARY => get_option( W::SUMMARY, null ), W::OPT_REMOTE => get_option( W::OPT_REMOTE, null ) );
$admin  = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$uid    = (int) $admin[0];
wp_set_current_user( $uid );
$meta_backup = array( get_user_meta( $uid, W::META_SEEN, true ), get_user_meta( $uid, W::META_DISMISSED, true ) );

$src  = getenv( 'PUBLISHRANK_TEST_FEED' );
$body = $src ? @file_get_contents( $src ) : false; // phpcs:ignore
if ( ! $body ) {
	$body = wp_json_encode( array(
		'schema_version' => 1, 'product' => 'PublishRank', 'updated_at' => '2026-11-16T00:00:00Z', 'latest_version' => '2.25.0',
		'website' => 'https://publishrankseo.vercel.app/',
		'releases' => array( array( 'version' => '2.25.0', 'date' => '2026-11-16', 'title' => 'PublishRank 2.25.0', 'summary' => 'S', 'url' => 'https://publishrankseo.vercel.app/changelog#v2-25-0', 'minimum_wp' => '6.3', 'minimum_php' => '7.4', 'tested_up_to' => '7.1', 'changes' => array( array( 'type' => 'feature', 'text' => 'A' ) ) ), array( 'version' => '2.24.0', 'date' => '2026-11-01', 'title' => 'PublishRank 2.24.0', 'summary' => 'S', 'url' => 'https://publishrankseo.vercel.app/changelog#v2-24-0', 'minimum_wp' => '6.3', 'minimum_php' => '7.4', 'tested_up_to' => '7.0', 'changes' => array( array( 'type' => 'fix', 'text' => 'B' ) ) ) ),
		'news' => array( array( 'id' => 'publishrank-2-25-enterprise-hardening', 'title' => 'T', 'summary' => 'S', 'category' => 'Product updates', 'published_at' => '2026-11-16T00:00:00Z', 'url' => 'https://publishrankseo.vercel.app/news/x', 'featured' => true ) ),
		'announcements' => array( array( 'id' => 'release-2-25-0', 'title' => 'PublishRank 2.25.0 is available', 'summary' => 'S', 'level' => 'important', 'published_at' => '2026-11-16T00:00:00Z', 'url' => 'https://publishrankseo.vercel.app/changelog#v2-25-0' ) ),
		'links' => array(),
	) );
	echo "NOTE feed fixture used (set PUBLISHRANK_TEST_FEED to test the live website feed)\n";
} else {
	echo 'NOTE feed body from ' . $src . ' (' . strlen( $body ) . " bytes)\n";
}
$feed_json = json_decode( $body, true );

// Simulated HTTP.
$GLOBALS['prt_reply'] = null;
$GLOBALS['prt_seen']  = array();
$filter = static function ( $pre, $args, $url ) {
	$GLOBALS['prt_seen'][] = array( $url, $args );
	return $GLOBALS['prt_reply'];
};
add_filter( 'pre_http_request', $filter, 10, 3 );
$reply = static function ( $code, $body, $type = 'application/json; charset=utf-8', $extra = array() ) {
	return array( 'headers' => array_merge( array( 'content-type' => $type, 'etag' => '"abc123"', 'last-modified' => 'Mon, 16 Nov 2026 00:00:00 GMT' ), $extra ), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
};

delete_option( W::CACHE ); delete_option( W::SUMMARY ); update_option( W::OPT_REMOTE, 1 );

// 1. Valid feed.
$GLOBALS['prt_reply'] = $reply( 200, $body );
$r = W::refresh();
$expect( 'Valid feed is accepted', true === $r, true );
$c = W::cache();
$expect( 'Latest version stored', $c['feed']['latest_version'] ?? '', $feed_json['latest_version'] );
$expect( 'All releases kept', count( $c['feed']['releases'] ), count( $feed_json['releases'] ) );
$expect( 'All news kept', count( $c['feed']['news'] ), count( $feed_json['news'] ) );
$expect( 'ETag stored for the next check', $c['etag'], '"abc123"' );
$expect( 'Source reported as live', W::data()['source'], 'live' );
list( $url, $args ) = end( $GLOBALS['prt_seen'] );
$expect( 'Requests only the official https feed address', $url, 'https://publishrankseo.vercel.app/publishrank-feed.json' );
$expect( 'Redirects are not followed', $args['redirection'], 0 );
$expect( 'Certificate verification on', $args['sslverify'], true );
$expect( 'Response size is capped', $args['limit_response_size'] <= 262145, true );
$expect( 'Site address not sent in User-Agent', false === strpos( $args['user-agent'], wp_parse_url( home_url(), PHP_URL_HOST ) ), true );
$expect( 'No cookies sent', $args['cookies'], array() );
$expect( 'No query string or site data in the URL', false === strpos( $url, '?' ), true );

// 2. Not modified: conditional headers sent, copy kept.
$GLOBALS['prt_reply'] = $reply( 304, '' );
$r = W::refresh();
list( , $args ) = end( $GLOBALS['prt_seen'] );
$expect( 'If-None-Match sent', $args['headers']['If-None-Match'] ?? '', '"abc123"' );
$expect( '304 keeps the saved feed', true === $r && ! empty( W::cache()['feed'] ), true );

// 3. Failures keep the saved copy (stale fallback).
foreach ( array(
	'redirect'   => $reply( 301, '', 'text/html', array( 'location' => 'https://evil.example/feed.json' ) ),
	'server 500' => $reply( 500, 'oops', 'text/plain' ),
	'not JSON'   => $reply( 200, '<html>hi</html>', 'text/html' ),
	'too large'  => $reply( 200, str_repeat( ' ', 262145 ) . '{}' ),
	'bad JSON'   => $reply( 200, '{"schema_version":1,' ),
	'wrong schema' => $reply( 200, wp_json_encode( array( 'schema_version' => 2, 'product' => 'PublishRank' ) ) ),
	'network error' => new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ),
) as $label => $rep ) {
	$GLOBALS['prt_reply'] = $rep;
	$r = W::refresh();
	$expect( "Rejects $label and keeps the saved copy", is_wp_error( $r ) && 'stale' === W::data()['source'] && W::cache()['feed']['latest_version'] === $feed_json['latest_version'], true );
}
$redirect_hits = count( array_filter( $GLOBALS['prt_seen'], static function ( $s ) { return false !== strpos( $s[0], 'evil.example' ); } ) );
$expect( 'Redirect target was never requested', $redirect_hits, 0 );

// 4. Validation strips unsafe content.
$evil = $feed_json;
$evil['releases'][0]['title']   = '<script>alert(1)</script>';
$evil['news'][0]['url']         = 'https://evil.example/phish';
$evil['news'][] = array( 'id' => 'http-link', 'title' => 'Plain http', 'summary' => '', 'category' => 'x', 'published_at' => '2026-11-16T00:00:00Z', 'url' => 'http://publishrankseo.vercel.app/x', 'featured' => false );
$evil['news'][] = array( 'id' => 'js-link', 'title' => 'JS', 'summary' => '', 'category' => 'x', 'published_at' => '2026-11-16T00:00:00Z', 'url' => 'javascript:alert(1)', 'featured' => false );
$evil['announcements'][0]['level'] = 'execute';
$evil['settings'] = array( 'blog_public' => 0 );
$evil['php'] = '<?php system("id"); ?>';
$v = W::validate( $evil );
$expect( 'Release with HTML title is dropped', in_array( '<script>alert(1)</script>', wp_list_pluck( $v['releases'], 'title' ), true ), false );
$expect( 'News linking to another site is dropped', in_array( 'https://evil.example/phish', wp_list_pluck( $v['news'], 'url' ), true ), false );
$expect( 'Plain http link is dropped', in_array( 'http-link', wp_list_pluck( $v['news'], 'id' ), true ), false );
$expect( 'javascript: link is dropped', in_array( 'js-link', wp_list_pluck( $v['news'], 'id' ), true ), false );
$expect( 'Unknown level falls back to info', $v['announcements'][0]['level'], 'info' );
$expect( 'Unknown top-level fields are ignored', array_keys( $v ), array( 'updated_at', 'latest_version', 'releases', 'news', 'announcements', 'links' ) );
$before = get_option( 'blog_public' );
$GLOBALS['prt_reply'] = $reply( 200, wp_json_encode( $evil ) );
W::refresh();
$expect( 'Feed cannot change site settings', get_option( 'blog_public' ), $before );

// 5. Unread and dismiss.
$GLOBALS['prt_reply'] = $reply( 200, $body );
W::refresh();
update_user_meta( $uid, W::META_SEEN, strtotime( '2026-01-01' ) );
delete_user_meta( $uid, W::META_DISMISSED );
$unread = W::unread_count( $uid );
$expect( 'News newer than the last visit is unread', $unread > 0, true );
update_user_meta( $uid, W::META_DISMISSED, array( $feed_json['announcements'][0]['id'] ) );
$expect( 'Dismissed announcement stops counting', W::unread_count( $uid ), $unread - 1 );
ob_start(); ( new W() )->render(); $html = ob_get_clean();
$expect( 'Opening the screen marks everything read', W::unread_count( $uid ), 0 );
$expect( 'Screen shows the latest release', false !== strpos( $html, esc_html( $feed_json['releases'][0]['title'] ) ), true );
$expect( 'External links open safely', false !== strpos( $html, 'rel="noopener noreferrer"' ), true );
$expect( 'No script tags rendered from the feed', false === stripos( $html, '<script' ), true );

// 6. Update notice is informational only.
$expect( 'Newer feed version is reported', W::update_available( array( 'latest_version' => '99.0.0' ) ), true );
$expect( 'Same version is not reported', W::update_available( array( 'latest_version' => PUBLISHRANK_SEO_VERSION ) ), false );

// 7. Local fallback and off switch.
delete_option( W::CACHE );
$d = W::data();
$expect( 'Without a saved copy the bundled changelog is shown', $d['source'] === 'local' && count( $d['feed']['releases'] ) > 0, true );
$expect( 'Bundled changelog starts with a version', (bool) preg_match( '/^\d+\.\d+/', $d['feed']['releases'][0]['version'] ), true );
update_option( W::OPT_REMOTE, 0 );
$count = count( $GLOBALS['prt_seen'] );
$r = W::refresh();
$expect( 'Turned off: no request is made', is_wp_error( $r ) && count( $GLOBALS['prt_seen'] ) === $count, true );
$expect( 'Turned off: source reported as off', W::data()['source'], 'off' );

// Cleanup.
remove_filter( 'pre_http_request', $filter, 10 );
foreach ( $backup as $k => $v ) {
	null === $v ? delete_option( $k ) : update_option( $k, $v, false );
}
'' === $meta_backup[0] ? delete_user_meta( $uid, W::META_SEEN ) : update_user_meta( $uid, W::META_SEEN, $meta_backup[0] );
'' === $meta_backup[1] ? delete_user_meta( $uid, W::META_DISMISSED ) : update_user_meta( $uid, W::META_DISMISSED, $meta_backup[1] );
echo "\n$n checks, $fail failures\n";
