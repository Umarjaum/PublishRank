<?php
/**
 * Standalone regression test for SiteConnections parsing and validation.
 * Run: php tests/site_verification.php   (no WordPress required; WP functions are stubbed)
 */
if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
$GLOBALS['pr_opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['pr_opts'] ) ? $GLOBALS['pr_opts'][ $k ] : $d; } }
require __DIR__ . '/../src/SEO/SiteConnections.php';
use PublishRankSEO\SEO\SiteConnections as S;

$fail = 0;
function check( $label, $ok ) { global $fail; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { $fail++; } }

check( 'bare token kept', 'AbC-123_x.y' === S::normalize_token( ' AbC-123_x.y ' ) );
check( 'full meta tag (double quotes)', 'AbC123' === S::normalize_token( '<meta name="google-site-verification" content="AbC123" />' ) );
check( 'full meta tag (single quotes)', 'ABCDEF' === S::normalize_token( "<meta name='msvalidate.01' content='ABCDEF'>" ) );
check( 'DNS style prefix removed', 'AbC123' === S::normalize_token( 'google-site-verification=AbC123' ) );
check( 'quotes stripped', 'tok' === S::normalize_token( '"tok"' ) );
check( 'length capped at 256', 256 === strlen( S::normalize_token( str_repeat( 'a', 400 ) ) ) );

$p = S::parse_meta_tags( '<meta name="robots" content="noindex"><meta name="ahrefs-site-verification" content="abc123"><meta property="fb:x" content="1">' );
check( 'blocked names dropped, others kept', 2 === count( $p ) && 'ahrefs-site-verification' === $p[0]['name'] && 'property' === $p[1]['attr'] );
check( 'blocked names allowed for token extraction', 3 === count( S::parse_meta_tags( '<meta name="robots" content="x"><meta name="a" content="b"><meta name="c" content="d">', true ) ) );
check( 'tag without content ignored', array() === S::parse_meta_tags( '<meta name="x">' ) );
check( 'engine lookup', 'bing' === S::engine_for_meta_name( 'MSVALIDATE.01' ) && '' === S::engine_for_meta_name( 'nope' ) );

foreach ( array( 'google1234abcd.html', 'BingSiteAuth.xml', 'yandex_4f5e.html', 'baidu_verify_codeva-Ab.html', 'naver0a1b.html' ) as $n ) { check( "valid file name $n", '' === S::filename_error( $n ) ); }
foreach ( array( '', '../x.html', '.htaccess', 'a/b.html', 'shell.php', 'x.html.php', 'robots.txt', 'wp-config.txt', 'sitemap.xml', 'index.html' ) as $n ) { check( "rejected file name '$n'", '' !== S::filename_error( $n ) ); }

check( 'google file content ok', '' === S::content_error( 'google-site-verification: google1234abcd.html' ) );
check( 'bing xml content ok', '' === S::content_error( '<?xml version="1.0"?><users><user>ABCDEF</user></users>' ) );
check( 'php refused', '' !== S::content_error( '<?php echo 1;' ) );
check( 'script refused', '' !== S::content_error( '<html><script>alert(1)</script></html>' ) );
check( 'empty refused', '' !== S::content_error( "  \n" ) );
check( 'oversize refused', '' !== S::content_error( str_repeat( 'a', S::MAX_BYTES + 1 ) ) );
check( 'BOM stripped, CRLF normalised', "a\nb" === S::normalize_content( "\xEF\xBB\xBFa\r\nb" ) );

$GLOBALS['pr_opts']['publishrank_verification_google'] = 'AbC123';
$GLOBALS['pr_opts']['publishrank_verification_custom'] = '<meta name="ahrefs-site-verification" content="zz9" />';
$m = S::markup();
check( 'markup has google tag', false !== strpos( $m, '<meta name="google-site-verification" content="AbC123" />' ) );
check( 'markup has custom tag', false !== strpos( $m, 'name="ahrefs-site-verification" content="zz9"' ) );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
