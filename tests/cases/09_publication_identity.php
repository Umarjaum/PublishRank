<?php
/** 2.9.98: publication title/description come from PublishRank only; the theme's visible copy is hidden by default. */

use PublishRankSEO\Core\FrontendVisibility;
use PublishRankSEO\Core\PublicationProfile;
use PublishRankSEO\Meta\TemplateResolver;

function pr09_set_wp_identity() {
	update_option( 'blogname', 'WordPress Site Title' );
	update_option( 'blogdescription', 'WordPress Tagline' );
}

return array(
	'nothing is read from the WordPress Site Title or Tagline when PublishRank has no values' => function () {
		pr09_set_wp_identity();
		delete_option( 'publishrank_seo_publication_name' );
		delete_option( 'publishrank_identity_name' );
		delete_option( 'publishrank_identity_description' );
		delete_option( 'publishrank_schema_org_description' );
		pr_same( '', PublicationProfile::publication_name(), 'name' );
		pr_same( '', PublicationProfile::publication_description(), 'description' );
		pr_same( '', TemplateResolver::resolve( '%%sitename%%', array() ), 'title variable' );
		pr_same( '', TemplateResolver::resolve( '%%site_description%%', array() ), 'tagline variable' );
	},
	'PublishRank values win and WordPress values never appear' => function () {
		pr09_set_wp_identity();
		update_option( 'publishrank_seo_publication_name', 'Plugin Name' );
		update_option( 'publishrank_identity_description', 'Plugin Tagline' );
		pr_same( 'Plugin Name', PublicationProfile::publication_name() );
		pr_same( 'Plugin Tagline', PublicationProfile::publication_description() );
		pr_same( 'Plugin Name', TemplateResolver::resolve( '%%sitename%%', array() ) );
		pr_same( 'Plugin Tagline', TemplateResolver::resolve( '%%site_description%%', array() ) );
		delete_option( 'publishrank_seo_publication_name' );
		update_option( 'publishrank_identity_name', 'Identity Name' );
		pr_same( 'Identity Name', PublicationProfile::publication_name(), 'falls back to the PublishRank identity name only' );
	},
	'home page meta, Open Graph and WebSite schema use the PublishRank values' => function () {
		pr09_set_wp_identity();
		update_option( 'publishrank_seo_publication_name', 'Plugin Name' );
		update_option( 'publishrank_identity_description', 'Plugin Tagline' );
		pr_go_to( home_url( '/' ) );
		$head = pr_head();
		pr_not_contains( 'WordPress Site Title', $head, 'WordPress title absent' );
		pr_not_contains( 'WordPress Tagline', $head, 'WordPress tagline absent' );
		pr_contains( 'Plugin Name', $head );
		$site = pr_node_of_type( pr_nodes( $head ), 'WebSite' );
		if ( $site && isset( $site['description'] ) ) {
			pr_same( 'Plugin Tagline', $site['description'], 'WebSite description' );
		}
	},
	'source guard: no code reads the WordPress title or tagline' => function () {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PUBLISHRANK_SEO_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() || false !== strpos( $file->getPathname(), '/src/assets/' ) ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() );
			pr_assert( ! preg_match( "/get_bloginfo\\(\\s*'(name|description)'\\s*\\)/", $code ), basename( $file->getPathname() ) . ' reads the WordPress title/tagline' );
			pr_assert( ! preg_match( "/get_option\\(\\s*'blog(name|description)'/", $code ), basename( $file->getPathname() ) . ' reads blogname/blogdescription' );
		}
	},
	'new installs seed no WordPress values and hide the visible identity by default' => function () {
		pr09_set_wp_identity();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'publishrank\\_%'" ); // phpcs:ignore
		wp_cache_flush();
		\PublishRankSEO\Core\Installer::install_defaults();
		pr_same( '', (string) get_option( 'publishrank_seo_publication_name' ), 'no copied name' );
		pr_same( '', (string) get_option( 'publishrank_llms_txt_summary' ), 'no copied tagline' );
		pr_same( 1, (int) get_option( 'publishrank_hide_visible_site_title' ) );
		pr_same( 1, (int) get_option( 'publishrank_hide_visible_site_description' ) );
	},
	'upgrades keep existing stored values and explicit visibility choices' => function () {
		update_option( 'publishrank_seo_publication_name', 'Kept Name' );
		update_option( 'publishrank_hide_visible_site_title', 0 );
		\PublishRankSEO\Core\Installer::install_defaults();
		pr_same( 'Kept Name', (string) get_option( 'publishrank_seo_publication_name' ) );
		pr_same( 0, (int) get_option( 'publishrank_hide_visible_site_title' ) );
		pr_same( false, FrontendVisibility::hides_title() );
	},
	'visible title and tagline are hidden by default with a visually-hidden rule, not display:none' => function () {
		delete_option( 'publishrank_hide_visible_site_title' );
		delete_option( 'publishrank_hide_visible_site_description' );
		$css = FrontendVisibility::css();
		pr_contains( '.site-title', $css );
		pr_contains( '.site-description', $css );
		pr_contains( '.wp-block-site-title', $css );
		pr_contains( 'clip:rect(0,0,0,0)', $css );
		pr_not_contains( 'display:none', $css );
		$id = pr_make_post();
		pr_go_to( get_permalink( $id ) );
		pr_contains( 'id="publishrank-hide-publication-identity"', pr_head(), 'printed on single posts' );
		pr_go_to( home_url( '/' ) );
		pr_contains( 'id="publishrank-hide-publication-identity"', pr_head(), 'printed on the home page' );
	},
	'each switch can be turned off independently and then prints nothing' => function () {
		update_option( 'publishrank_hide_visible_site_title', 0 );
		update_option( 'publishrank_hide_visible_site_description', 1 );
		pr_not_contains( '.site-title', FrontendVisibility::css() );
		pr_contains( '.site-description', FrontendVisibility::css() );
		update_option( 'publishrank_hide_visible_site_description', 0 );
		pr_same( '', FrontendVisibility::css() );
		pr_go_to( home_url( '/' ) );
		pr_not_contains( 'publishrank-hide-publication-identity', pr_head() );
	},
	'hiding never removes SEO output' => function () {
		update_option( 'publishrank_seo_publication_name', 'Plugin Name' );
		update_option( 'publishrank_identity_description', 'Plugin Tagline' );
		$id = pr_make_post();
		pr_go_to( get_permalink( $id ) );
		$head = pr_head();
		pr_contains( '<title>', $head );
		pr_contains( 'og:site_name', $head );
		pr_assert( null !== pr_node_of_type( pr_nodes( $head ), 'Organization' ) || null !== pr_node_of_type( pr_nodes( $head ), 'NewsMediaOrganization' ) || count( pr_nodes( $head ) ) > 0, 'schema graph present' );
	},
	'admin notice asks for the PublishRank identity when it is empty, on PublishRank screens only' => function () {
		delete_option( 'publishrank_seo_publication_name' );
		delete_option( 'publishrank_identity_name' );
		set_current_screen( 'toplevel_page_publishrank' );
		ob_start();
		( new FrontendVisibility() )->missing_identity_notice();
		pr_contains( 'PublishRank uses its own publication identity', ob_get_clean() );
		set_current_screen( 'dashboard' );
		ob_start();
		( new FrontendVisibility() )->missing_identity_notice();
		pr_same( '', ob_get_clean(), 'not on other screens' );
	},
);
