<?php
/**
 * 2.24.0 rules: WooCommerce SEO, visitor analytics and Theme SEO cleanup.
 * Run with: wp eval-file tests/woocommerce-analytics-integration.php (isolated WordPress; WooCommerce optional).
 */
defined( 'ABSPATH' ) || exit;
use PublishRankSEO\WooCommerce\ProductData;
use PublishRankSEO\WooCommerce\Settings as WooSettings;
use PublishRankSEO\WooCommerce\Audit;
use PublishRankSEO\Analytics\Classifier;
use PublishRankSEO\Analytics\Tracker;
use PublishRankSEO\Compatibility\ThemeSeo;

$failures = 0;
$checks   = 0;
$expect   = static function ( $name, $actual, $expected ) use ( &$failures, &$checks ) {
	$checks++;
	$ok = $actual === $expected;
	if ( ! $ok ) {
		$failures++;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : ' => got ' . var_export( $actual, true ) . ', want ' . var_export( $expected, true ) ) . "\n";
};

// Barcodes: only 8/12/13/14-digit codes with a correct check digit are used.
$expect( 'GTIN-13 with correct check digit is valid', ProductData::check_gtin( '4006381333931' ), true );
$expect( 'GTIN-13 with wrong check digit is rejected', true === ProductData::check_gtin( '4006381333932' ), false );
$expect( 'GTIN-12 (UPC) valid', ProductData::check_gtin( '036000291452' ), true );
$expect( 'GTIN-8 valid', ProductData::check_gtin( '96385074' ), true );
$expect( 'GTIN-14 valid', ProductData::check_gtin( '10012345678902' ), true );
$expect( 'Wrong length rejected', true === ProductData::check_gtin( '12345' ), false );
$expect( 'Spaces and dashes ignored', ProductData::check_gtin( '400-6381 333931' ), true );

// Audit bands: 80+ good, 50-79 fair, under 50 poor.
$expect( 'Score 80 is good', Audit::band( 80 ), 'good' );
$expect( 'Score 50 is ok', Audit::band( 50 ), 'ok' );
$expect( 'Score 49 is poor', Audit::band( 49 ), 'poor' );

// Feed is private: wrong key is refused.
$key = WooSettings::feed_key();
$expect( 'Feed key is at least 24 characters', strlen( $key ) >= 24, true );
// The feed must be switched on for this check: a disabled feed answers 404 so it does not reveal that it exists.
$feed_was = WooSettings::get( 'feed_enabled' );
WooSettings::update( array( 'feed_enabled' => 1 ) );
$res = wp_remote_get( add_query_arg( array( 'publishrank_feed' => 'google', 'key' => 'wrong' ), home_url( '/' ) ), array( 'timeout' => 30 ) );
WooSettings::update( array( 'feed_enabled' => $feed_was ? 1 : 0 ) );
$off = wp_remote_get( add_query_arg( array( 'publishrank_feed' => 'google', 'key' => 'wrong' ), home_url( '/' ) ), array( 'timeout' => 30 ) );
if ( WooSettings::active() && ! $feed_was && ! is_wp_error( $off ) ) {
	$expect( 'Disabled feed answers 404', (int) wp_remote_retrieve_response_code( $off ), 404 );
}
if ( WooSettings::active() && ! is_wp_error( $res ) ) {
	$expect( 'Feed with a wrong key returns 403', (int) wp_remote_retrieve_response_code( $res ), 403 );
}

// Store rules: a binned product redirects to its category; restoring removes the redirect.
if ( WooSettings::woo_active() && class_exists( 'WC_Product_Simple' ) ) {
	WooSettings::update( array( 'trash_redirect' => 1 ) );
	$term = wp_insert_term( 'Test Rules Cat ' . wp_rand(), 'product_cat' );
	$p    = new WC_Product_Simple();
	$p->set_name( 'Rules Test Product' );
	$p->set_status( 'publish' );
	$p->set_regular_price( '10' );
	$p->set_category_ids( array( (int) $term['term_id'] ) );
	$pid  = $p->save();
	$path = wp_parse_url( get_permalink( $pid ), PHP_URL_PATH );
	wp_trash_post( $pid );
	$r = wp_remote_get( home_url( $path ), array( 'redirection' => 0, 'timeout' => 30 ) );
	$expect( 'Binned product answers 301', (int) wp_remote_retrieve_response_code( $r ), 301 );
	$expect( 'Binned product redirects to its category', false !== strpos( (string) wp_remote_retrieve_header( $r, 'location' ), get_term( (int) $term['term_id'] )->slug ), true );
	wp_untrash_post( $pid );
	wp_publish_post( $pid );
	$r = wp_remote_get( get_permalink( $pid ), array( 'redirection' => 0, 'timeout' => 30 ) );
	$expect( 'Restored product answers 200', (int) wp_remote_retrieve_response_code( $r ), 200 );
	wp_delete_post( $pid, true );
	wp_delete_term( (int) $term['term_id'], 'product_cat' );
}

// Visitor analytics: source classification and privacy.
$expect( 'Google referrer is organic search', Classifier::source( 'https://www.google.com/' )['source'], 'organic' );
$expect( 'Bing referrer is organic search', Classifier::source( 'https://www.bing.com/search?q=x' )['source'], 'organic' );
$expect( 'ChatGPT referrer is AI', Classifier::source( 'https://chatgpt.com/' )['source'], 'ai' );
$expect( 'Facebook referrer is social', Classifier::source( 'https://m.facebook.com/' )['source'], 'social' );
$expect( 'No referrer is direct', Classifier::source( '' )['source'], 'direct' );
$expect( 'utm_medium=email is email', Classifier::source( '', 'newsletter', 'email' )['source'], 'email' );
$expect( 'utm_medium=cpc is paid', Classifier::source( 'https://www.google.com/', 'google', 'cpc' )['source'], 'paid' );
$expect( 'Googlebot is a bot', Classifier::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ), true );
$expect( 'Chrome is not a bot', Classifier::is_bot( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36' ), false );
$expect( 'Query strings are dropped from paths', Tracker::clean_path( '/shop/?email=a@b.c&utm_source=x' ), '/shop/' );
global $wpdb;
$cols = $wpdb->get_col( 'SELECT name FROM pragma_table_info(\'' . $wpdb->prefix . 'publishrank_traffic_seen\')' );
if ( ! $cols ) {
	$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $wpdb->prefix . 'publishrank_traffic_seen' );
}
$expect( 'Visitor table has no IP column', (bool) array_intersect( array( 'ip', 'ip_address', 'remote_addr' ), array_map( 'strtolower', (array) $cols ) ), false );

// Theme SEO cleanup: theme duplicates go, PublishRank's own tags stay, kinds PublishRank does not print stay.
$html = "<html><head><title>Theme</title>\n<meta name=\"description\" content=\"theme\">\n<link rel=\"canonical\" href=\"/theme\">\n"
	. "<!-- PublishRank: begin; version=; output=technical -->\n<link rel=\"canonical\" href=\"/own\">\n<meta property=\"og:title\" content=\"own\">\n<!-- PublishRank: end -->\n"
	. "<meta property=\"og:title\" content=\"theme\">\n</head><body></body></html>";
$out  = ThemeSeo::process( $html, ThemeSeo::defaults() );
$expect( 'Theme canonical removed', false === strpos( $out, 'href="/theme"' ), true );
$expect( 'PublishRank canonical kept', false !== strpos( $out, 'href="/own"' ), true );
$expect( 'Theme og:title removed', false === strpos( $out, 'content="theme">' . "\n</head>" ) && 1 === substr_count( $out, 'og:title' ), true );
$expect( 'Description kept when PublishRank prints none', false !== strpos( $out, 'name="description"' ), true );
$expect( 'style.css header is stripped', ThemeSeo::strip_header( "/*\nTheme Name: X\nAuthor: Y\n*/\nbody{}" ), 'body{}' );
$expect( 'Masking alias cannot climb out with ..', false === strpos( (string) ThemeSeo::clean_alias( '../wp-admin' ), '..' ), true );

echo "\n{$checks} checks, {$failures} failures\n";
