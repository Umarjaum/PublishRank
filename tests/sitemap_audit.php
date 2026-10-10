<?php
function __( $text, $domain = '' ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : ( parse_url( $url, $component ) ?: null ); }
require_once __DIR__ . '/../src/Sitemap/Engine/SitemapAudit.php';

$reflection = new ReflectionClass( '\PublishRankSEO\Sitemap\Engine\SitemapAudit' );
$method = $reflection->getMethod( 'url_key' );
$method->setAccessible( true );

$instance = $reflection->newInstanceWithoutConstructor();
$a = $method->invoke( $instance, 'https://EXAMPLE.test/hello-world/' );
$b = $method->invoke( $instance, 'https://example.test/hello-world' );
$c = $method->invoke( $instance, 'https://example.test/hello-world/?ref=1' );
if ( $a !== $b ) { exit( 1 ); }
if ( $a === $c ) { exit( 2 ); }
if ( '' !== $method->invoke( $instance, '' ) ) { exit( 3 ); }
echo "sitemap audit tests passed\n";
