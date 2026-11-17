<?php
/** Run with wp eval-file; these are actual WordPress operations, not mocked APIs. */
defined( 'ABSPATH' ) || exit;

use PublishRankSEO\Redirect\RedirectStore;

$failures = 0;
$checks = 0;
$expect = static function ( $name, $actual, $expected ) use ( &$failures, &$checks ) {
	$checks++;
	if ( $actual !== $expected ) {
		$failures++;
		echo 'FAIL ' . $name . "\n";
	} else {
		echo 'PASS ' . $name . "\n";
	}
};
$options = array( 'publishrank_seo_redirects', 'publishrank_redirect_auto_enabled', 'publishrank_redirect_enabled', 'publishrank_redirect_auto_history', 'permalink_structure' );
$saved = array();
foreach ( $options as $option ) {
	$saved[ $option ] = get_option( $option, null );
}
$ids = array();
$qa_rules = static function () {
	return array_values( array_filter( RedirectStore::all(), static function ( $r ) { return 0 === strpos( (string) ( $r['source'] ?? '' ), '/qa-' ) || 0 === strpos( (string) ( $r['source'] ?? '' ), '/product/qa-' ); } ) );
};
$purge_qa = static function () use ( $qa_rules ) {
	foreach ( $qa_rules() as $r ) {
		RedirectStore::delete( isset( $r['id'] ) ? $r['id'] : RedirectStore::id( $r ) );
	}
};
$purge_qa();
try {
	update_option( 'permalink_structure', '/%postname%/' );
	update_option( 'publishrank_seo_redirects', array() );
	update_option( 'publishrank_redirect_enabled', 1 );
	update_option( 'publishrank_redirect_auto_enabled', 1 );
	$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'QA automatic permalink', 'post_name' => 'qa-before' ) );
	$ids[] = $id;
	wp_update_post( array( 'ID' => $id, 'post_name' => 'qa-after' ) );
	$rules = $qa_rules();
	$expect( 'Published slug creates one rule', count( $rules ), 1 );
	$expect( 'Automatic redirect status is 301', $rules[0]['status'] ?? null, 301 );
	$expect( 'Captured original path is preserved', $rules[0]['source'] ?? null, '/qa-before' );
	$expect( 'Actual new permalink is the target', $rules[0]['target'] ?? null, get_permalink( $id ) );
	wp_update_post( array( 'ID' => $id, 'post_title' => 'QA title update only' ) );
	$expect( 'Title update creates no duplicate', count( $qa_rules() ), 1 );
	$draft = wp_insert_post( array( 'post_status' => 'draft', 'post_title' => 'QA draft', 'post_name' => 'qa-draft-old' ) );
	$ids[] = $draft;
	wp_update_post( array( 'ID' => $draft, 'post_name' => 'qa-draft-new' ) );
	$expect( 'Draft slug changes create no redirect', count( $qa_rules() ), 1 );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'trash', 'post_name' => 'qa-trashed' ) );
	$expect( 'Trashing creates no redirect', count( $qa_rules() ), 1 );
	update_option( 'publishrank_redirect_auto_enabled', 0 );
	$disabled = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'QA disabled', 'post_name' => 'qa-disabled-old' ) );
	$ids[] = $disabled;
	wp_update_post( array( 'ID' => $disabled, 'post_name' => 'qa-disabled-new' ) );
	$expect( 'Automatic redirect switch is honored', count( $qa_rules() ), 1 );
	update_option( 'publishrank_redirect_auto_enabled', 1 );
	RedirectStore::add( '/qa-manual-old', '/editorial-destination', 302 );
	$manual = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'QA manual', 'post_name' => 'qa-manual-old' ) );
	$ids[] = $manual;
	wp_update_post( array( 'ID' => $manual, 'post_name' => 'qa-manual-new' ) );
	$manual_rule = current( array_filter( $qa_rules(), static function ( $r ) { return '/qa-manual-old' === $r['source']; } ) );
	$expect( 'Existing manual rule is never overwritten', $manual_rule['target'] ?? null, '/editorial-destination' );
	$expect( 'Existing manual status is preserved', (int) ( $manual_rule['status'] ?? 0 ), 302 );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA page', 'post_name' => 'qa-page-old' ) );
	$ids[] = $page;
	wp_update_post( array( 'ID' => $page, 'post_name' => 'qa-page-new' ) );
	$expect( 'Published page slug creates redirect', count( $qa_rules() ), 3 );
	RedirectStore::add( '/qa-loop-new', '/qa-loop-old', 301 );
	$loop = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'QA loop', 'post_name' => 'qa-loop-old' ) );
	$ids[] = $loop;
	wp_update_post( array( 'ID' => $loop, 'post_name' => 'qa-loop-new' ) );
	$expect( 'Back-to-source redirect loop is rejected', count( $qa_rules() ), 4 );
	$bulk = RedirectStore::parse_bulk( '/legally-blocked 451' );
	$expect( 'Bulk import preserves legal status 451', $bulk[0]['status'], 451 );
	$expect( 'Legal response has no destination', $bulk[0]['target'], '' );
	$expect( 'Foreign redirect target rejected', is_wp_error( RedirectStore::build_rule( '/old', 'https://attacker.invalid/' ) ), true );
	$expect( 'Self redirect rejected', is_wp_error( RedirectStore::build_rule( '/same', '/same' ) ), true );
	$expect( 'Homepage redirect rejected', is_wp_error( RedirectStore::build_rule( '/', '/elsewhere' ) ), true );
	if ( class_exists( 'WC_Product_Simple' ) ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'QA product' );
		$product->set_slug( 'qa-product-old' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '29.00' );
		$product_id = $product->save();
		$ids[] = $product_id;
		$old_product_url = get_permalink( $product_id );
		$product->set_slug( 'qa-product-new' );
		$product->save();
		$product_trace = RedirectStore::trace( $old_product_url );
		$expect( 'WooCommerce product slug creates 301', $product_trace[0]['status'] ?? null, 301 );
		$expect( 'WooCommerce target is actual permalink', $product_trace[0]['target'] ?? null, get_permalink( $product_id ) );
	}
} finally {
	$purge_qa();
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $saved as $option => $value ) {
		if ( null === $value ) { delete_option( $option ); } else { update_option( $option, $value ); }
	}
}
echo "Tests: $checks; failures: $failures\n";
if ( $failures ) {
	throw new RuntimeException( 'Upgrade integration tests failed.' );
}