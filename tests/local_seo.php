<?php
/** Focused unit checks for Local SEO profile validation and JSON-LD construction. */
define( 'ABSPATH', __DIR__ );
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function esc_url_raw( $v ) { $v = trim( (string) $v ); return filter_var( $v, FILTER_VALIDATE_URL ) ? $v : ''; }
function sanitize_email( $v ) { return filter_var( (string) $v, FILTER_SANITIZE_EMAIL ); }
function is_email( $v ) { return (bool) filter_var( (string) $v, FILTER_VALIDATE_EMAIL ); }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/' ) . '/'; }
function home_url( $p = '/' ) { return 'https://example.test' . $p; }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }
require_once __DIR__ . '/../src/SEO/LocalSEO.php';
use PublishRankSEO\SEO\LocalSEO;

$disabled = LocalSEO::build_schema( array( 'enabled' => 0, 'name' => 'Example Business' ), 'https://example.test' );
if ( $disabled ) { fwrite( STDERR, "Disabled profile unexpectedly generated schema.\n" ); exit( 1 ); }
$profile = LocalSEO::sanitize_profile( array(
    'enabled' => '1', 'name' => '<b>Example Shop</b>', 'type' => 'Store',
    'description' => 'A local shop', 'url' => 'https://example.test/shop',
    'phone' => '+92 300 0000000', 'email' => 'hello@example.test',
    'street' => '1 Main Road', 'city' => 'Karachi', 'country' => 'PK',
    'latitude' => '24.8607', 'longitude' => '67.0011', 'price_range' => '$$',
    'has_map' => 'https://maps.example.test/place', 'service_area' => 'Karachi',
    'opening_hours' => "Mo-Fr 09:00-17:00\n<script>alert(1)</script>\nSa 10:00-14:00",
    'same_as' => "https://social.example.test/shop\njavascript:alert(1)",
) );
if ( 'Example Shop' !== $profile['name'] || 'Store' !== $profile['type'] ) { exit( 2 ); }
if ( 2 !== count( $profile['opening_hours'] ) || 'Mo-Fr 09:00-17:00' !== $profile['opening_hours'][0] ) { fwrite( STDERR, "Opening hours validation failed.\n" ); exit( 3 ); }
if ( '' !== $profile['latitude'] && (float) $profile['latitude'] > 90 ) { exit( 4 ); }
if ( 1 !== count( $profile['same_as'] ) ) { exit( 5 ); }
if ( 'PK' !== $profile['country'] ) { exit( 12 ); }
$schema = LocalSEO::build_schema( $profile, 'https://example.test' );
$required = array( '@type', '@id', 'name', 'url', 'telephone', 'email', 'address', 'geo', 'openingHours', 'sameAs', 'hasMap', 'areaServed' );
foreach ( $required as $key ) { if ( ! isset( $schema[ $key ] ) ) { fwrite( STDERR, "Missing schema property: {$key}\n" ); exit( 6 ); } }
if ( 'Store' !== $schema['@type'] || 'https://example.test/#local-business' !== $schema['@id'] ) { exit( 7 ); }
if ( 24.8607 !== $schema['geo']['latitude'] || 67.0011 !== $schema['geo']['longitude'] ) { exit( 8 ); }
if ( 'PK' !== $schema['address']['addressCountry'] || 2 !== count( $schema['openingHours'] ) ) { exit( 9 ); }
$bad = LocalSEO::sanitize_profile( array( 'enabled' => 1, 'name' => 'Bad coordinates', 'type' => 'FakeSchemaType', 'latitude' => '200', 'longitude' => '-999' ) );
if ( 'LocalBusiness' !== $bad['type'] || '' !== $bad['latitude'] || '' !== $bad['longitude'] ) { exit( 10 ); }
if ( LocalSEO::build_schema( array( 'enabled' => 1, 'name' => '' ), 'https://example.test' ) ) { exit( 11 ); }
// Static wiring and admin-security assertions complement pure-function checks.
$module = file_get_contents( __DIR__ . '/../src/Core/Plugin.php' );
$source = file_get_contents( __DIR__ . '/../src/SEO/LocalSEO.php' );
if ( false === strpos( $module, 'LocalSEO::class' ) || false === strpos( $source, "add_filter( 'publishrank_schema_graph'" ) ) { exit( 13 ); }
if ( false === strpos( $source, "check_admin_referer( 'publishrank_local_seo_save' )" ) || false === strpos( $source, "current_user_can( 'manage_options' )" ) ) { exit( 14 ); }
echo "Local SEO tests passed.\n";
