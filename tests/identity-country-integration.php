<?php
/** Run through wp eval-file on isolated WordPress. */
defined( 'ABSPATH' ) || exit;
use PublishRankSEO\Core\IdentityOverride;
use PublishRankSEO\Core\Countries;
use PublishRankSEO\Core\PublicationProfile;

$checks = 0;
$failures = 0;
$expect = static function ( $name, $actual, $expected ) use ( &$checks, &$failures ) {
	$checks++;
	if ( $actual !== $expected ) { $failures++; echo 'FAIL ' . $name . "\n"; }
	else { echo 'PASS ' . $name . "\n"; }
};
$saved = array();
foreach ( IdentityOverride::groups() as $group ) {
	foreach ( $group as $option ) { $saved[ $option ] = get_option( $option, null ); }
}
try {
	$expect( 'All ISO countries and territories available', count( Countries::all() ), 249 );
	$expect( 'Pakistan listed', Countries::all()['PK'], 'Pakistan' );
	$expect( 'United States listed', Countries::all()['US'], 'United States' );
	$expect( 'Country selections normalized', Countries::sanitize( 'pk' ), 'PK' );
	$expect( 'Unknown country rejected', Countries::sanitize( 'ZZ' ), '' );
	foreach ( IdentityOverride::groups() as $group ) {
		foreach ( $group as $source ) {
			$value = 'QA shared ' . $source;
			update_option( $source, $value );
			foreach ( $group as $peer ) {
				$expect( $source . ' synchronizes ' . $peer, get_option( $peer ), $value );
			}
		}
	}
	update_option( 'blogname', 'QA unified publication' );
	update_option( 'blogdescription', 'QA unified description' );
	$expect( 'Publication resolver uses shared title', PublicationProfile::publication_name(), 'QA unified publication' );
	$expect( 'Publication resolver uses shared description', PublicationProfile::publication_description(), 'QA unified description' );
	$expect( 'WordPress title uses shared value', get_bloginfo( 'name' ), 'QA unified publication' );
	$expect( 'WordPress tagline uses shared value', get_bloginfo( 'description' ), 'QA unified description' );
	global $wp_query;
	$original_query = $wp_query;
	$wp_query = new WP_Query( array( 'post_type' => 'post' ) );
	$wp_query->is_home = true;
	$generator = new \PublishRankSEO\Meta\MetaGenerator();
	$expect( 'Homepage output uses shared title', $generator->title(), 'QA unified publication' );
	$expect( 'Homepage output uses shared description', $generator->description(), 'QA unified description' );
	$wp_query = $original_query;
	$id = wp_insert_post( array( 'post_title' => 'QA article', 'post_status' => 'draft' ) );
	update_post_meta( $id, '_publishrank_seo_title', 'QA article-specific SEO' );
	$expect( 'Article-specific SEO remains independent', $generator->title_for_post( $id ), 'QA article-specific SEO' );
	wp_delete_post( $id, true );
} finally {
	// Suspend only identity hooks while restoring potentially divergent original values.
	$removed = array();
	global $wp_filter;
	foreach ( array( 'updated_option', 'added_option', 'deleted_option' ) as $hook ) {
		foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && $fn[0] instanceof IdentityOverride ) {
					$removed[] = array( $hook, $fn, $priority, $callback['accepted_args'] );
					remove_action( $hook, $fn, $priority );
				}
			}
		}
	}
	foreach ( $saved as $option => $value ) {
		if ( null === $value ) { delete_option( $option ); } else { update_option( $option, $value ); }
	}
	foreach ( $removed as $args ) { add_action( ...$args ); }
}
echo "$checks checks; $failures failures\n";
if ( $failures ) { WP_CLI::error( 'Identity/country checks failed.' ); }