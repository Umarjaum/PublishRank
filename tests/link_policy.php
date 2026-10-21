<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
$GLOBALS['pr_test_options'] = array( 'publishrank_links_enabled' => 1, 'publishrank_links_follow_domains' => 'partner.example', 'publishrank_links_nofollow_domains' => '', 'publishrank_links_sponsored' => 0, 'publishrank_primary_url' => 'https://news.example.com' );
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['pr_test_options'] ) ? $GLOBALS['pr_test_options'][ $key ] : $default; }
function home_url( $path = '/' ) { return 'https://news.example.com' . $path; }
function site_url( $path = '/' ) { return 'https://news.example.com' . $path; }
function wp_parse_url( $url, $component = -1 ) { $v = parse_url( $url ); if ( -1 === $component ) { return $v; } if ( PHP_URL_HOST === $component ) { return $v['host'] ?? null; } return null; }
function is_admin() { return false; } function is_feed() { return false; } function is_singular() { return true; }
function apply_filters( $tag, $value ) { return $value; } function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
require_once __DIR__ . '/../src/Links/LinkManager.php';
$ref = new ReflectionClass( 'PublishRankSEO\\Links\\LinkManager' ); $method = $ref->getMethod( 'process_anchor' ); $method->setAccessible( true ); $obj = $ref->newInstance();
$external = $method->invoke( $obj, array( '<a href="https://outside.example/">' ) );
$subdomain = $method->invoke( $obj, array( '<a href="https://cdn.news.example.com/">' ) );
$approved = $method->invoke( $obj, array( '<a href="https://partner.example/">' ) );
if ( false === strpos( $external, 'nofollow' ) || false !== strpos( $subdomain, 'nofollow' ) || false !== strpos( $approved, 'nofollow' ) ) { exit( 1 ); }
echo "link policy runtime checks passed\n";
