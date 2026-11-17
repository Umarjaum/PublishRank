<?php
/**
 * PublishRank 2.25 integration checks. Run with: wp eval-file tests/v225-integration.php (isolated WordPress).
 * Real WordPress operations; every object created here is removed at the end.
 */
use PublishRankSEO\Newsroom\PublishingDesk as Desk;
use PublishRankSEO\AccessShield\AccountSecurity;
use PublishRankSEO\AccessShield\Settings as Shield;
use PublishRankSEO\AccessShield\Verifier;
use PublishRankSEO\Core\OptionPreloader;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;

$n = 0; $fail = 0;
$expect = static function ( $label, $got, $want ) use ( &$n, &$fail ) {
	++$n;
	$ok = $got === $want;
	$fail += $ok ? 0 : 1;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok ? '' : ' => got ' . var_export( $got, true ) . ', want ' . var_export( $want, true ) ) . "\n";
};
$cleanup = array();

// Duplicate headlines: reworded headline matches, unrelated one does not.
$a = wp_insert_post( array( 'post_title' => 'Central bank raises interest rates to fight inflation', 'post_status' => 'publish', 'post_content' => 'a' ) );
$cleanup[] = $a;
delete_transient( Desk::TITLES );
$hits = Desk::duplicates( 'Central bank raises rates to fight inflation' );
$expect( 'Reworded headline is flagged as duplicate', in_array( $a, wp_list_pluck( $hits, 'id' ), true ), true );
$expect( 'Unrelated headline is not flagged', count( Desk::duplicates( 'Storm closes highways across the north' ) ), 0 );
$expect( 'Editing a story never flags itself', in_array( $a, wp_list_pluck( Desk::duplicates( get_the_title( $a ), $a ), 'id' ), true ), false );
$expect( 'Identical wording scores 1.0', Desk::similarity( Desk::tokens( 'Mayor resigns' ), Desk::tokens( 'Mayor Resigns!' ) ), 1.0 );

// Missed schedule: a future post whose time has passed is detected and published.
global $wpdb;
$m = wp_insert_post( array( 'post_title' => 'Missed schedule probe', 'post_status' => 'draft', 'post_content' => 'm' ) );
$cleanup[] = $m;
$wpdb->update( $wpdb->posts, array( 'post_status' => 'future', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 600 ), 'post_date' => wp_date( 'Y-m-d H:i:s', time() - 600 ) ), array( 'ID' => $m ) );
clean_post_cache( $m );
$expect( 'Missed schedule is detected', in_array( $m, Desk::missed(), true ), true );
delete_transient( Desk::LOCK );
update_option( Desk::OPT_FIX, 1 );
Desk::maybe_fix_missed();
clean_post_cache( $m );
$expect( 'Missed schedule is auto-published', get_post_status( $m ), 'publish' );

// Password policy for privileged roles (breach lookup disabled: no network in tests).
$prev = Shield::all();
Shield::update( array( 'pw_policy' => 1, 'pw_min_length' => 12, 'pw_breach_check' => 0 ) );
$expect( 'Short password refused', count( AccountSecurity::problems( 'Ab1!short', null, false ) ) > 0, true );
$expect( 'Low-variety password refused', count( AccountSecurity::problems( 'alllowercaseletters', null, false ) ) > 0, true );
$expect( 'Strong password accepted', AccountSecurity::problems( 'Tr7#vq-Lm2!xQe9z', null, false ), array() );
$expect( '20+ character passphrase accepted', AccountSecurity::problems( 'correct horse battery staple again', null, false ), array() );
$sub = new WP_User(); $sub->roles = array( 'subscriber' );
$expect( 'Subscribers are not covered by the policy', AccountSecurity::privileged( $sub ), false );
Shield::update( array( 'pw_policy' => $prev['pw_policy'], 'pw_min_length' => $prev['pw_min_length'], 'pw_breach_check' => $prev['pw_breach_check'] ) );

// Verification report signature detects tampering.
$report = array( 'site' => home_url(), 'time' => gmdate( 'c' ), 'score' => 72, 'results' => array( array( 'id' => 'x', 'status' => 'fail' ) ) );
$report['signature'] = Verifier::sign( $report );
$expect( 'Unmodified report verifies', Verifier::check( $report ), true );
$tampered = $report; $tampered['score'] = 100;
$expect( 'Edited report is rejected', Verifier::check( $tampered ), false );

// Settings preloader: an option learned as absent and created later must never be hidden.
if ( ! wp_using_ext_object_cache() ) {
	$name = 'publishrank_test_preload_' . wp_generate_password( 6, false, false );
	update_option( OptionPreloader::INDEX, array_merge( (array) get_option( OptionPreloader::INDEX, array() ), array( $name ) ), true );
	add_option( $name, 'present', '', false );
	wp_cache_delete( $name, 'options' );
	OptionPreloader::preload();
	$expect( 'Preloader never hides an option created later', get_option( $name ), 'present' );
	delete_option( $name );
	wp_cache_delete( 'notoptions', 'options' );
	OptionPreloader::preload();
	$expect( 'Preloader reports a deleted option as absent', get_option( $name, 'default' ), 'default' );
	update_option( OptionPreloader::INDEX, array_values( array_diff( (array) get_option( OptionPreloader::INDEX, array() ), array( $name ) ) ), true );
}

// Sitemap: a change refreshes only the shard holding the post.
$g1 = SitemapCursorStore::gen( 'post', 1 );
SitemapCursorStore::touch( 'post', $a );
$shard = SitemapCursorStore::shard_for( 'post', $a );
if ( $shard ) {
	$expect( 'Touched shard generation advances', SitemapCursorStore::gen( 'post', $shard ) > ( 1 === $shard ? $g1 : -1 ), true );
}

foreach ( $cleanup as $id ) {
	wp_delete_post( $id, true );
}
delete_transient( Desk::TITLES );
echo "\n$n checks, $fail failures\n";
