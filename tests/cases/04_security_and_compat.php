<?php
/** Capability/nonce/escaping checks and other-SEO-plugin compatibility (runs in its own PHP process). */

use PublishRankSEO\Meta\PostMeta;

// Simulates Yoast SEO being active: PublishRank must not add a second BreadcrumbList.
if ( ! function_exists( 'yoast_breadcrumb' ) ) {
	function yoast_breadcrumb() {}
}

class PR_Died extends Exception {}

return array(
	'another SEO plugin that prints breadcrumbs: no second BreadcrumbList and no dangling reference, nothing of theirs removed' => function () {
		pr_same( 'yoast', \PublishRankSEO\SEO\BreadcrumbEngine::external_schema_owner() );
		update_option( 'publishrank_seo_publication_name', 'Test Site' );
		$id = pr_make_post( array( 'post_title' => 'Hello World', 'post_name' => 'hello-world' ) );
		add_action( 'wp_head', function () { echo '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"BreadcrumbList","@id":"https://example.test/#other"}]}</script>' . "\n"; }, 3 );
		pr_go_to( get_permalink( $id ) );
		$head  = pr_head();
		pr_count( 1, '"BreadcrumbList"', $head, 'only the other plugin\'s BreadcrumbList is present' );
		pr_contains( '#other', $head, 'the other plugin\'s output is left alone' );
		$page = pr_node_of_type( array_merge( ...array_map( function ( $b ) { return $b['@graph'] ?? array(); }, pr_jsonld( $head ) ) ), 'WebPage' );
		pr_assert( ! isset( $page['breadcrumb'] ), 'no reference to a BreadcrumbList PublishRank did not print' );
		// The visible trail is independent of structured-data ownership.
		update_option( 'publishrank_breadcrumb_placement', 'content' );
		pr_go_to( get_permalink( $id ) );
		pr_count( 1, '<nav class="publishrank-breadcrumbs', pr_content() );
		// Explicit opt-out of the detection.
		add_filter( 'publishrank_breadcrumb_schema_external_owner', '__return_empty_string' );
		pr_go_to( get_permalink( $id ) );
		pr_assert( null !== pr_node_of_type( array_merge( ...array_map( function ( $b ) { return $b['@graph'] ?? array(); }, pr_jsonld( pr_head() ) ) ), 'BreadcrumbList' ), 'filter forces PublishRank output' );
	},

	'settings screen and import require the manage_options capability' => function () {
		$sub = wp_insert_user( array( 'user_login' => 'sub' . wp_rand(), 'user_pass' => 'x', 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		ob_start();
		( new \PublishRankSEO\Admin\SettingsPage() )->page();
		pr_same( '', trim( (string) ob_get_clean() ), 'subscriber sees nothing' );
		add_filter( 'wp_die_handler', function () { return function () { throw new PR_Died( 'died' ); }; } );
		$_POST = array( 'publishrank_seo_json' => '{"_schema":"publishrank/v2","publishrank_breadcrumbs_separator":"x"}' );
		$died  = false;
		try {
			( new \PublishRankSEO\Admin\ImportExport() )->import();
		} catch ( PR_Died $e ) {
			$died = true;
		}
		$_POST = array();
		pr_assert( $died, 'import refused for a subscriber' );
		pr_same( '→', \PublishRankSEO\SEO\BreadcrumbSeparators::current(), 'nothing was imported' );
		// The options.php group is guarded by manage_options.
		pr_same( 'manage_options', apply_filters( 'option_page_capability_publishrank_seo', 'manage_options' ) );
	},

	'post SEO fields need a nonce and edit permission and are stored sanitized' => function () {
		$id  = pr_make_post();
		$sub = wp_insert_user( array( 'user_login' => 'sub' . wp_rand(), 'user_pass' => 'x', 'role' => 'subscriber' ) );
		$nonce = wp_create_nonce( 'publishrank_seo_meta' );
		wp_set_current_user( $sub );
		$_POST = array( 'publishrank_seo_meta_nonce' => $nonce, PostMeta::TITLE => 'Hacked' );
		PostMeta::save( $id );
		pr_same( '', (string) get_post_meta( $id, PostMeta::TITLE, true ), 'no edit_post capability' );
		wp_set_current_user( 1 );
		$_POST = array( PostMeta::TITLE => 'No nonce' );
		PostMeta::save( $id );
		pr_same( '', (string) get_post_meta( $id, PostMeta::TITLE, true ), 'missing nonce' );
		$_POST = array( 'publishrank_seo_meta_nonce' => wp_create_nonce( 'publishrank_seo_meta' ), PostMeta::TITLE => wp_slash( '<script>alert(1)</script>Safe %%date%%' ), PostMeta::CANONICAL => 'javascript:alert(1)' );
		PostMeta::save( $id );
		$_POST = array();
		pr_same( 'Safe %%date%%', get_post_meta( $id, PostMeta::TITLE, true ) );
		pr_same( '', (string) get_post_meta( $id, PostMeta::CANONICAL, true ), 'unsafe canonical rejected' );
	},

	'breadcrumb names and titles are escaped on output' => function () {
		update_option( 'publishrank_seo_publication_name', 'Test Site' );
		$id = pr_make_post( array( 'post_title' => 'A <img src=x onerror=alert(1)> "quoted" & more', 'post_name' => 'xss' ), 'Tag <b>x</b>' );
		pr_go_to( get_permalink( $id ) );
		$html = ( new \PublishRankSEO\SEO\BreadcrumbEngine() )->render();
		pr_not_contains( '<img', $html );
		pr_not_contains( 'onerror=alert(1)>', $html );
		pr_not_contains( '<b>', $html );
		$head = pr_head();
		pr_not_contains( '<img src=x', $head );
		pr_assert( 1 === preg_match( '#<title>[^<]*</title>#', $head ), 'title element contains no markup' );
	},
);
