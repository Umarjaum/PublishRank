<?php
/** Output switches, organization/website schema, cache invalidation and upgrade migration. */

use PublishRankSEO\Core\Installer;
use PublishRankSEO\Core\OutputSettings;
use PublishRankSEO\Meta\PostMeta;

function pr_oc_post() {
	update_option( 'publishrank_seo_publication_name', 'Test Site' );
	$id = pr_make_post( array( 'post_title' => 'Hello World', 'post_name' => 'hello-world' ), 'News' );
	pr_go_to( get_permalink( $id ) );
	return $id;
}

/** Every { "@id": X } reference to the identity/website/breadcrumb nodes must point at a node that is present. */
function pr_oc_assert_no_dangling( $nodes ) {
	$ids = array();
	foreach ( $nodes as $n ) {
		if ( isset( $n['@id'] ) ) {
			$ids[ $n['@id'] ] = true;
		}
	}
	$walk = function ( $value ) use ( &$walk, $ids ) {
		if ( ! is_array( $value ) ) {
			return;
		}
		if ( 1 === count( $value ) && isset( $value['@id'] ) && preg_match( '/#(organization|person|website|breadcrumb|webpage)$/', $value['@id'] ) ) {
			pr_assert( isset( $ids[ $value['@id'] ] ), 'dangling reference to ' . $value['@id'] );
		}
		foreach ( $value as $child ) {
			$walk( $child );
		}
	};
	foreach ( $nodes as $n ) {
		$walk( $n );
	}
}

function pr_oc_count_types( $nodes, $types ) {
	$c = 0;
	foreach ( $nodes as $n ) {
		if ( array_intersect( (array) ( $n['@type'] ?? array() ), $types ) ) {
			++$c;
		}
	}
	return $c;
}

function pr_oc_upgrade_from( $version ) {
	update_option( Installer::VERSION_OPTION, $version );
	pr_as_admin();
	Installer::maybe_upgrade();
	pr_as_frontend();
}

return array(
	'fresh installation: every output switch defaults to enabled and is honoured' => function () {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'publishrank\\_%'" ); // phpcs:ignore
		wp_cache_flush();
		Installer::activate();
		foreach ( OutputSettings::keys() as $key ) {
			pr_same( '1', (string) get_option( $key ), "{$key} stored enabled on a fresh install" );
			pr_assert( OutputSettings::is_on( $key ), "{$key} on" );
		}
		pr_same( PUBLISHRANK_SEO_VERSION, get_option( Installer::VERSION_OPTION ) );
		$id = pr_oc_post();
		$head = pr_head();
		pr_contains( '<meta name="description"', $head );
		pr_contains( '<link rel="canonical"', $head );
		$nodes = pr_nodes( $head );
		pr_assert( pr_oc_count_types( $nodes, array( 'NewsMediaOrganization', 'Organization' ) ) === 1, 'organization present' );
		pr_assert( null !== pr_node_of_type( $nodes, 'WebSite' ), 'website present' );
		pr_assert( null !== pr_node_of_type( $nodes, 'BreadcrumbList' ), 'breadcrumb list present' );
	},

	'switch semantics: missing means on, saved false-like values mean off' => function () {
		foreach ( OutputSettings::keys() as $key ) {
			remove_all_filters( 'sanitize_option_' . $key ); // test the stored value itself, not the settings-form sanitizer
			delete_option( $key );
			pr_assert( OutputSettings::is_on( $key ), "{$key} missing => on" );
			foreach ( array( 0, '0', '', false, 'false', 'off', 'no', ' 0 ' ) as $off ) {
				update_option( $key, $off );
				pr_assert( ! OutputSettings::is_on( $key ), "{$key} = " . var_export( $off, true ) . ' => off' );
			}
			foreach ( array( 1, '1', true, 'yes', 'on' ) as $on ) {
				update_option( $key, $on );
				pr_assert( OutputSettings::is_on( $key ), "{$key} = " . var_export( $on, true ) . ' => on' );
			}
		}
	},

	'saving a disabled switch through the settings sanitizer really disables it (no missing-row trap)' => function () {
		global $wpdb;
		foreach ( OutputSettings::keys() as $key ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore
			wp_cache_flush();
			update_option( $key, false ); // what options.php does with the hidden "0" field.
			pr_assert( ! OutputSettings::is_on( $key ), "{$key} stays off after being saved as unchecked" );
		}
	},

	'upgrade from 2.9.90 keeps explicit disabled choices and creates the missing switch enabled' => function () {
		delete_option( 'publishrank_org_website_schema_enabled' );
		update_option( 'publishrank_breadcrumbs_enabled', 0 );
		update_option( 'publishrank_breadcrumb_schema_enabled', '0' );
		update_option( 'publishrank_schema_enabled', 0 );
		update_option( 'publishrank_meta_enabled', 1 );
		pr_oc_upgrade_from( '2.9.90' );
		pr_same( PUBLISHRANK_SEO_VERSION, get_option( Installer::VERSION_OPTION ) );
		pr_assert( ! OutputSettings::visible_breadcrumbs(), 'visible breadcrumbs stay off' );
		pr_assert( ! OutputSettings::breadcrumb_schema(), 'breadcrumb schema stays off' );
		pr_assert( ! OutputSettings::schema(), 'schema stays off' );
		pr_assert( OutputSettings::meta(), 'metadata stays on' );
		pr_same( '1', (string) get_option( 'publishrank_org_website_schema_enabled' ), 'new switch defaults to on' );
	},

	'upgrade from 2.9.90 keeps explicit enabled choices and is idempotent' => function () {
		foreach ( OutputSettings::keys() as $key ) {
			update_option( $key, 1 );
		}
		update_option( 'publishrank_org_website_schema_enabled', 0 );
		update_option( 'publishrank_breadcrumbs_separator', ' › ' );
		update_option( 'publishrank_seo_title_template_post', '%%title%% %%sep%% %%sitename%%' );
		for ( $i = 0; $i < 3; $i++ ) {
			pr_oc_upgrade_from( '2.9.90' );
			pr_assert( ! OutputSettings::org_website_schema(), 'run ' . $i . ': an administrator who turned it off keeps it off' );
			foreach ( array( 'meta', 'schema', 'visible_breadcrumbs', 'breadcrumb_schema' ) as $m ) {
				pr_assert( OutputSettings::$m(), "run {$i}: {$m} stays on" );
			}
			pr_same( ' › ', get_option( 'publishrank_breadcrumbs_separator' ), 'separator untouched' );
			pr_same( '%%title%% %%sep%% %%sitename%%', get_option( 'publishrank_seo_title_template_post' ) );
		}
	},

	'upgrade handles missing legacy rows and non-canonical stored values' => function () {
		global $wpdb;
		foreach ( OutputSettings::keys() as $key ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore
		}
		wp_cache_flush();
		update_option( 'publishrank_breadcrumbs_enabled', 'false' ); // imported / hand-edited legacy value
		pr_oc_upgrade_from( '2.9.50' );
		foreach ( array( 'meta', 'schema', 'org_website_schema', 'breadcrumb_schema' ) as $m ) {
			pr_assert( OutputSettings::$m(), "{$m}: missing legacy option becomes enabled" );
		}
		pr_assert( ! OutputSettings::visible_breadcrumbs(), '"false" keeps meaning off' );
		pr_assert( in_array( (string) get_option( 'publishrank_breadcrumbs_enabled' ), array( '0', '' ), true ), 'rewritten to a canonical false value' );
	},

	'upgrade repairs templates damaged by the old sanitizer, only where the damage is unambiguous' => function () {
		update_option( 'publishrank_seo_title_template_post', '%%title%% %te%% %tegory%% %%sitename%%' );
		update_option( 'publishrank_home_title', 'Plain 100% title' );
		remove_all_filters( 'sanitize_option_publishrank_seo_title_template_post' );
		update_option( 'publishrank_seo_title_template_post', '%%title%% %te%% %tegory%% %%sitename%%' );
		pr_oc_upgrade_from( '2.9.90' );
		pr_same( '%%title%% %%date%% %%category%% %%sitename%%', get_option( 'publishrank_seo_title_template_post' ) );
		pr_same( 'Plain 100% title', get_option( 'publishrank_home_title' ), 'unrelated percent signs untouched' );
	},

	'organization changes appear on every page without editing posts, with stable ids and no duplicates' => function () {
		$id = pr_oc_post();
		$a  = pr_make_post( array( 'post_title' => 'Second', 'post_name' => 'second' ), 'News' );
		$read = function ( $url ) {
			pr_go_to( $url );
			return pr_nodes( pr_head() );
		};
		update_option( 'publishrank_identity_name', 'Acme Daily' );
		update_option( 'publishrank_identity_description', 'All the news.' );
		update_option( 'publishrank_identity_logo', 'https://example.test/logo.png' );
		update_option( 'publishrank_seo_publication_name', 'Acme Daily Site' );
		foreach ( array( get_permalink( $id ), get_permalink( $a ), home_url( '/' ) ) as $url ) {
			$nodes = $read( $url );
			$org   = pr_node_of_type( $nodes, 'NewsMediaOrganization' );
			pr_assert( null !== $org, 'organization on ' . $url );
			pr_same( 'Acme Daily', $org['name'] );
			pr_same( 'All the news.', $org['description'] );
			pr_same( 'https://example.test/logo.png', $org['logo']['url'] ?? $org['logo']['contentUrl'] ?? '', 'logo' );
			pr_same( 'https://example.test/#organization', $org['@id'], 'stable @id' );
			pr_same( 'Acme Daily Site', pr_node_of_type( $nodes, 'WebSite' )['name'], 'website name' );
			pr_same( 1, pr_oc_count_types( $nodes, array( 'NewsMediaOrganization', 'Organization', 'Person' ) ) - pr_oc_count_types( $nodes, array( 'Person' ) ), 'exactly one organization' );
			pr_same( 1, pr_oc_count_types( $nodes, array( 'WebSite' ) ), 'exactly one WebSite' );
			pr_assert( ! isset( $org['legalName'] ) && ! isset( $org['address'] ) && ! isset( $org['contactPoint'] ), 'empty optional fields are omitted' );
		}
		// Change again: the next request shows the new value immediately (no stale copy anywhere).
		update_option( 'publishrank_identity_name', 'Acme Weekly' );
		pr_same( 'Acme Weekly', pr_node_of_type( $read( get_permalink( $id ) ), 'NewsMediaOrganization' )['name'] );
		// Organization type switch and Person identity keep the relationships consistent.
		update_option( 'publishrank_seo_publisher_type', 'Organization' );
		$nodes = $read( get_permalink( $id ) );
		pr_assert( null !== pr_node_of_type( $nodes, 'Organization' ) && null === pr_node_of_type( $nodes, 'NewsMediaOrganization' ), 'type switched' );
		update_option( 'publishrank_identity_type', 'person' );
		update_option( 'publishrank_identity_person_name', 'Jane Writer' );
		$nodes = $read( get_permalink( $id ) );
		pr_same( 'https://example.test/#person', pr_node_of_type( $nodes, 'WebSite' )['publisher']['@id'], 'website publisher follows the identity type' );
		pr_assert( null === pr_node_of_type( $nodes, 'Organization' ), 'no organization when Person is selected' );
		pr_oc_assert_no_dangling( $nodes );
	},

	'organization/website switch off removes only those entities and leaves a valid graph' => function () {
		$id = pr_oc_post();
		update_option( 'publishrank_org_website_schema_enabled', 0 );
		pr_go_to( get_permalink( $id ) );
		$head  = pr_head();
		$nodes = pr_nodes( $head );
		pr_same( 0, pr_oc_count_types( $nodes, array( 'NewsMediaOrganization', 'Organization', 'WebSite' ) ), 'identity and WebSite removed' );
		pr_assert( null !== pr_node_of_type( $nodes, 'WebPage' ), 'WebPage kept' );
		pr_assert( null !== pr_node_of_type( $nodes, 'NewsArticle' ) || null !== pr_node_of_type( $nodes, 'Article' ), 'article kept' );
		pr_assert( null !== pr_node_of_type( $nodes, 'BreadcrumbList' ), 'breadcrumbs kept' );
		pr_oc_assert_no_dangling( $nodes );
		pr_contains( '<meta name="description"', $head, 'metadata unaffected' );
		pr_contains( 'entities=webpage', $head );
		update_option( 'publishrank_org_website_schema_enabled', 1 );
		pr_go_to( get_permalink( $id ) );
		pr_assert( null !== pr_node_of_type( pr_nodes( pr_head() ), 'WebSite' ), 'back on' );
	},

	'schema switch off keeps SEO metadata; metadata off keeps schema' => function () {
		$id = pr_oc_post();
		update_option( 'publishrank_schema_enabled', 0 );
		$head = pr_head();
		pr_not_contains( 'application/ld+json', $head );
		pr_not_contains( 'structured data begin', $head );
		foreach ( array( '<meta name="description"', '<link rel="canonical"', '<meta name="robots"', '<meta property="og:title"', '<meta name="twitter:card"' ) as $tag ) {
			pr_contains( $tag, $head, 'metadata still output: ' . $tag );
		}
		update_option( 'publishrank_schema_enabled', 1 );
		update_option( 'publishrank_meta_enabled', 0 );
		pr_go_to( get_permalink( $id ) );
		$head = pr_head();
		foreach ( array( '<meta name="description"', '<link rel="canonical"', '<meta name="robots"', '<meta property="og:title"', '<meta name="twitter:card"', 'PublishRank: begin' ) as $tag ) {
			pr_not_contains( $tag, $head, 'metadata off: ' . $tag );
		}
		pr_assert( null !== pr_node_of_type( pr_nodes( $head ), 'WebSite' ), 'schema still output' );
		pr_not_contains( 'Hello World | Test Site', wp_get_document_title(), 'PublishRank no longer rewrites the document title' );
		pr_contains( 'Hello World', wp_get_document_title(), 'core title still works' );
	},

	'post-specific overrides drive title, description, social and canonical output' => function () {
		$id = pr_oc_post();
		update_post_meta( $id, PostMeta::TITLE, 'Custom %%title%% for %%sitename%%' );
		update_post_meta( $id, PostMeta::DESCRIPTION, 'Custom description about %%title%%' );
		update_post_meta( $id, PostMeta::CANONICAL, 'https://example.test/canonical-override/' );
		update_post_meta( $id, PostMeta::ROBOTS, 'noindex,follow' );
		pr_go_to( get_permalink( $id ) );
		$head = pr_head();
		pr_contains( '<title>Custom Hello World for Test Site</title>', $head );
		pr_contains( '<meta property="og:title" content="Custom Hello World for Test Site" />', $head );
		pr_contains( '<meta name="twitter:title" content="Custom Hello World for Test Site" />', $head );
		pr_contains( '<meta name="description" content="Custom description about Hello World" />', $head );
		pr_contains( '<link rel="canonical" href="https://example.test/canonical-override/" />', $head );
		pr_contains( 'content="noindex,follow', $head );
		pr_count( 1, '<link rel="canonical"', $head );
		$page = pr_node_of_type( pr_nodes( $head ), 'WebPage' );
		pr_same( 'Custom Hello World for Test Site', $page['name'], 'schema uses the same title' );
		pr_same( 'Custom description about Hello World', $page['description'] );
	},

	'theme-generated metadata is neither removed nor duplicated by PublishRank' => function () {
		$id = pr_oc_post();
		add_action( 'wp_head', function () { echo '<meta name="theme-color" content="#123456" />' . "\n" . '<meta name="author" content="Theme Author" />' . "\n"; }, 2 );
		$head = pr_head();
		pr_count( 1, '<meta name="theme-color" content="#123456" />', $head, 'theme tag untouched' );
		pr_count( 1, '<meta name="author" content="Theme Author" />', $head );
		pr_count( 1, '<meta name="description"', $head, 'PublishRank adds its own description once' );
		pr_count( 1, '<link rel="canonical"', $head, 'core rel_canonical removed so only PublishRank prints one' );
		pr_count( 1, '<title>', $head );
	},

	'saving an identity, schema or breadcrumb setting invalidates derived caches' => function () {
		$id       = pr_oc_post();
		$fired    = array();
		add_action( 'publishrank_seo_output_settings_changed', function ( $option ) use ( &$fired ) { $fired[] = $option; } );
		$epoch    = (int) get_option( 'publishrank_seo_cache_epoch' );
		$gen      = (int) get_option( 'publishrank_schema_index_generation', 1 );
		update_option( 'publishrank_seo_cache_epoch', $epoch - 100 );
		\PublishRankSEO\Meta\SeoData::for_post( $id ); // warm the per-request cache
		update_post_meta( $id, PostMeta::TITLE, 'Changed' );
		update_option( 'publishrank_identity_name', 'New Name' );
		pr_assert( (int) get_option( 'publishrank_seo_cache_epoch' ) > $epoch - 100, 'cache epoch bumped' );
		pr_assert( (int) get_option( 'publishrank_schema_index_generation' ) > $gen, 'schema index generation bumped' );
		pr_assert( in_array( 'publishrank_identity_name', $fired, true ), 'page-cache action fired' );
		pr_same( 'Changed', \PublishRankSEO\Meta\SeoData::for_post( $id )['title'], 'per-request SEO data cache was flushed' );
		$fired = array();
		foreach ( array( 'publishrank_breadcrumbs_separator' => '·', 'publishrank_org_website_schema_enabled' => 0, 'publishrank_schema_legal_name' => 'Acme Ltd', 'publishrank_social_x' => 'acme', 'publishrank_seo_title_template_post' => '%%title%%' ) as $opt => $val ) {
			update_option( $opt, $val );
			pr_assert( in_array( $opt, $fired, true ), "{$opt} triggers invalidation" );
		}
		$fired = array();
		update_option( 'publishrank_seo_cache_epoch', time() );
		update_option( 'blogname', 'Unrelated' );
		pr_same( array(), $fired, 'unrelated options and the cache bookkeeping options do not loop or fire' );
	},

	'the schema discovery endpoint stays public, hides private content and respects the schema switch' => function () {
		wp_set_current_user( 0 );
		$pub   = pr_make_post( array( 'post_title' => 'Public', 'post_name' => 'public' ) );
		$draft = pr_make_post( array( 'post_title' => 'Draft', 'post_name' => 'draft', 'post_status' => 'draft' ) );
		$priv  = pr_make_post( array( 'post_title' => 'Private', 'post_name' => 'private', 'post_status' => 'private' ) );
		$pw    = pr_make_post( array( 'post_title' => 'Protected', 'post_name' => 'protected', 'post_password' => 'x' ) );
		$ni    = pr_make_post( array( 'post_title' => 'Noindex', 'post_name' => 'noindex' ) );
		update_post_meta( $ni, PostMeta::ROBOTS, 'noindex,follow' );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/publishrank/v1/schema-index' ) );
		pr_same( 200, $res->get_status(), 'anonymous access works' );
		$json = wp_json_encode( $res->get_data() );
		pr_contains( 'Public', $json );
		foreach ( array( 'Draft', 'Private', 'Protected', 'Noindex' ) as $hidden ) {
			pr_not_contains( '"headline":"' . $hidden . '"', $json, "{$hidden} must not be exposed" );
		}
		pr_not_contains( 'user_email', $json );
		update_option( 'publishrank_schema_enabled', 0 );
		pr_same( 404, rest_do_request( new WP_REST_Request( 'GET', '/publishrank/v1/schema-index' ) )->get_status(), 'disabled schema hides the index' );
		update_option( 'publishrank_schema_enabled', 1 );
	},

	'REST API stays available with crawl-optimization REST link removal enabled' => function () {
		update_option( 'publishrank_crawl_optimization', array( 'remove_rest_links' => 1, 'disallow_wp_rest_content_api' => 1 ) );
		pr_make_post( array( 'post_title' => 'Rest', 'post_name' => 'rest' ) );
		wp_set_current_user( 0 );
		pr_same( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) )->get_status(), 'core REST API works' );
		pr_same( 200, rest_do_request( new WP_REST_Request( 'GET', '/publishrank/v1/schema-index' ) )->get_status(), 'schema index works' );
		$robots = apply_filters( 'robots_txt', "User-agent: *\n", 1 );
		pr_not_contains( 'Disallow: /wp-json/publishrank', $robots, 'robots rules never cover the PublishRank namespace' );
	},
);
