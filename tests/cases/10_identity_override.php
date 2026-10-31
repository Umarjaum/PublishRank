<?php
/** 2.9.99: PublishRank identity overrides the WordPress Site Title/Tagline on the public side only. */

use PublishRankSEO\Core\IdentityOverride;

function pr10_setup() {
	update_option( 'blogname', 'WordPress Site Title' );
	update_option( 'blogdescription', 'WordPress Tagline' );
	update_option( 'publishrank_seo_publication_name', 'Plugin Name' );
	update_option( 'publishrank_identity_description', 'Plugin Tagline' );
	wp_cache_flush();
}

function pr10_raw( $name ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore
}

return array(
	'public side shows the PublishRank title and description through get_bloginfo, bloginfo() and get_option' => function () {
		pr10_setup();
		pr_go_to( home_url( '/' ) );
		pr_same( 'Plugin Name', get_bloginfo( 'name' ) );
		pr_same( 'Plugin Tagline', get_bloginfo( 'description' ) );
		ob_start();
		bloginfo( 'name' );
		pr_same( 'Plugin Name', ob_get_clean(), 'bloginfo() echo' );
		pr_same( 'Plugin Name', get_option( 'blogname' ) );
	},
	'the stored WordPress values are never changed' => function () {
		pr10_setup();
		pr_go_to( home_url( '/' ) );
		get_bloginfo( 'name' );
		pr_same( 'WordPress Site Title', pr10_raw( 'blogname' ) );
		pr_same( 'WordPress Tagline', pr10_raw( 'blogdescription' ) );
	},
	'wp-admin keeps showing the real WordPress values (General Settings is not polluted)' => function () {
		pr10_setup();
		set_current_screen( 'options-general' );
		pr_same( 'WordPress Site Title', get_option( 'blogname' ) );
		pr_same( 'WordPress Tagline', get_option( 'blogdescription' ) );
		set_current_screen( 'front' );
	},
	'the settings REST route is not overridden' => function () {
		pr10_setup();
		pr_go_to( home_url( '/' ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/settings';
		pr_same( 'WordPress Site Title', get_option( 'blogname' ) );
	},
	'turning the switch off restores WordPress values' => function () {
		pr10_setup();
		update_option( IdentityOverride::OPTION, 0 );
		pr_go_to( home_url( '/' ) );
		pr_same( 'WordPress Site Title', get_bloginfo( 'name' ) );
		pr_same( 'WordPress Tagline', get_bloginfo( 'description' ) );
	},
	'an empty PublishRank value leaves the WordPress value in place' => function () {
		pr10_setup();
		delete_option( 'publishrank_seo_publication_name' );
		delete_option( 'publishrank_identity_name' );
		delete_option( 'publishrank_identity_description' );
		delete_option( 'publishrank_schema_org_description' );
		pr_go_to( home_url( '/' ) );
		pr_same( 'WordPress Site Title', get_bloginfo( 'name' ) );
		pr_same( 'WordPress Tagline', get_bloginfo( 'description' ) );
	},
	'default is on, and PublishRank still reads only its own values' => function () {
		delete_option( IdentityOverride::OPTION );
		pr_assert( IdentityOverride::enabled(), 'missing option means on' );
		pr10_setup();
		pr_same( 'Plugin Name', \PublishRankSEO\Core\PublicationProfile::publication_name() );
	},
	'document title and feed use the PublishRank name' => function () {
		pr10_setup();
		pr_go_to( home_url( '/' ) );
		pr_contains( 'Plugin Name', wp_get_document_title() );
		pr_same( 'Plugin Name', get_bloginfo_rss( 'name' ) );
	},
	'General Settings shows an explanatory notice only while overriding' => function () {
		pr10_setup();
		set_current_screen( 'options-general' );
		ob_start();
		( new IdentityOverride() )->general_settings_notice();
		pr_contains( 'overriding the Site Title', ob_get_clean() );
		update_option( IdentityOverride::OPTION, 0 );
		ob_start();
		( new IdentityOverride() )->general_settings_notice();
		pr_same( '', ob_get_clean() );
		set_current_screen( 'front' );
	},
);
