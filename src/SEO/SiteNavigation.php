<?php
/**
 * Site Navigation SEO.
 *
 * Lets administrators mark the pages that matter most (About, Contact, Services, editorial policies,
 * locations, legal and trust pages, key landing pages) and then:
 *  - describes them on the homepage as a schema.org SiteNavigationElement list and as `significantLink`
 *    of the homepage WebPage node (only real, published, indexable pages; nothing is invented);
 *  - audits each one: indexable, expected in the XML sitemap, linked from a menu, internal links
 *    pointing to it, canonical consistency, title and description;
 *  - lists orphaned content from the content graph.
 *
 * It does not and cannot force search engines to show sitelinks. A clear hierarchy, consistent internal
 * links, breadcrumbs and descriptive titles only make relevant sitelinks more likely.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class SiteNavigation {

	const OPTION = 'publishrank_site_navigation';

	/** Hard limit of important pages. */
	const MAX_PAGES = 12;

	/** @return array<string,string> role => label */
	public static function roles() {
		return array(
			'about'      => __( 'About', 'publishrank' ),
			'contact'    => __( 'Contact', 'publishrank' ),
			'services'   => __( 'Services', 'publishrank' ),
			'products'   => __( 'Products', 'publishrank' ),
			'category'   => __( 'Category hub', 'publishrank' ),
			'editorial'  => __( 'Editorial policy', 'publishrank' ),
			'authors'    => __( 'Authors / team', 'publishrank' ),
			'location'   => __( 'Business location', 'publishrank' ),
			'landing'    => __( 'Landing page', 'publishrank' ),
			'legal'      => __( 'Legal / trust', 'publishrank' ),
			'other'      => __( 'Other important page', 'publishrank' ),
		);
	}

	/** @return array{pages:array<int,array{id:int,role:string}>,schema:int} */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$pages = array();
		foreach ( (array) ( $saved['pages'] ?? array() ) as $row ) {
			$id   = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$role = isset( $row['role'] ) ? sanitize_key( $row['role'] ) : 'other';
			if ( $id ) {
				$pages[] = array( 'id' => $id, 'role' => isset( self::roles()[ $role ] ) ? $role : 'other' );
			}
		}
		return array(
			'pages'  => array_slice( $pages, 0, self::MAX_PAGES ),
			'schema' => isset( $saved['schema'] ) ? (int) (bool) $saved['schema'] : 1,
		);
	}

	public function register() {
		add_filter( 'publishrank_schema_graph', array( $this, 'graph' ), 30 );
	}

	/** Published, public, indexable important pages in their saved order. @return \WP_Post[] keyed by role order */
	public static function live_pages() {
		$out = array();
		foreach ( self::settings()['pages'] as $row ) {
			$post = get_post( $row['id'] );
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status && '' === $post->post_password && is_post_type_viewable( $post->post_type ) && self::indexable( $post->ID ) ) {
				$out[] = array( 'post' => $post, 'role' => $row['role'] );
			}
		}
		return $out;
	}

	public static function indexable( $post_id ) {
		$robots = strtolower( (string) get_post_meta( $post_id, PostMeta::ROBOTS, true ) );
		return false === strpos( $robots, 'noindex' );
	}

	/** Add the navigation list to the homepage graph. */
	public function graph( $graph ) {
		if ( ! is_array( $graph ) || ! \PublishRankSEO\Meta\MetaGenerator::is_homepage() || is_paged() ) {
			return $graph;
		}
		$s = self::settings();
		if ( ! $s['schema'] ) {
			return $graph;
		}
		$pages = self::live_pages();
		if ( ! $pages ) {
			return $graph;
		}
		$items = array();
		$urls  = array();
		foreach ( $pages as $i => $row ) {
			$url     = (string) get_permalink( $row['post'] );
			$urls[]  = $url;
			$items[] = array(
				'@type'    => 'SiteNavigationElement',
				'position' => $i + 1,
				'name'     => wp_strip_all_tags( get_the_title( $row['post'] ) ),
				'url'      => $url,
			);
		}
		$home = home_url( '/' );
		$list = array(
			'@type'           => 'ItemList',
			'@id'             => $home . '#site-navigation',
			'name'            => __( 'Site navigation', 'publishrank' ),
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);
		$nodes = isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ? $graph['@graph'] : ( array_values( $graph ) === $graph ? $graph : null );
		if ( null === $nodes ) {
			return $graph;
		}
		foreach ( $nodes as &$node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) && in_array( 'WebPage', (array) $node['@type'], true ) && ! isset( $node['significantLink'] ) ) {
				$node['significantLink'] = $urls;
				break;
			}
		}
		unset( $node );
		$nodes[] = $list;
		if ( isset( $graph['@graph'] ) ) {
			$graph['@graph'] = $nodes;
			return $graph;
		}
		return $nodes;
	}

	/** Pages whose slug or title suggests an important role. @return array<int,array{id:int,role:string,title:string}> */
	public static function suggestions() {
		$patterns = array(
			'about'     => '/^(about|about-us|who-we-are|our-story|company)$/',
			'contact'   => '/^(contact|contact-us|get-in-touch)$/',
			'services'  => '/^(services|our-services|what-we-do)$/',
			'products'  => '/^(products|shop|store)$/',
			'editorial' => '/(editorial|ethics|corrections|fact-check|standards)/',
			'authors'   => '/^(team|our-team|authors|staff|people)$/',
			'legal'     => '/(privacy|terms|cookie|disclaimer|imprint|legal)/',
			'location'  => '/(location|locations|find-us|visit)/',
		);
		$taken = wp_list_pluck( self::settings()['pages'], 'id' );
		$out   = array();
		foreach ( get_pages( array( 'post_status' => 'publish', 'number' => 300 ) ) as $page ) {
			if ( in_array( (int) $page->ID, $taken, true ) ) {
				continue;
			}
			foreach ( $patterns as $role => $re ) {
				if ( preg_match( $re, (string) $page->post_name ) ) {
					$out[] = array( 'id' => (int) $page->ID, 'role' => $role, 'title' => get_the_title( $page ) );
					break;
				}
			}
		}
		return $out;
	}

	/** Page IDs that appear in any navigation menu. @return array<int,true> */
	public static function menu_targets() {
		$out = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				if ( isset( $item->object_id ) && 'post_type' === $item->type ) {
					$out[ (int) $item->object_id ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Audit one page. Each check: id, ok (bool|null for unknown), label, detail.
	 *
	 * @return array<int,array{id:string,ok:?bool,label:string,detail:string}>
	 */
	public static function audit( \WP_Post $post, array $menus ) {
		$checks    = array();
		$permalink = (string) get_permalink( $post );
		$checks[]  = array( 'id' => 'published', 'ok' => 'publish' === $post->post_status && '' === $post->post_password, 'label' => __( 'Published and public', 'publishrank' ), 'detail' => '' );
		$checks[]  = array( 'id' => 'indexable', 'ok' => self::indexable( $post->ID ), 'label' => __( 'Indexable (no noindex)', 'publishrank' ), 'detail' => '' );
		$types     = array_map( 'trim', explode( ',', (string) get_option( 'publishrank_sitemap_post_types', 'post' ) ) );
		$in_map    = self::indexable( $post->ID ) && ( 'page' === $post->post_type || in_array( $post->post_type, $types, true ) );
		$checks[]  = array( 'id' => 'sitemap', 'ok' => $in_map, 'label' => __( 'Expected in the XML sitemap', 'publishrank' ), 'detail' => '' );
		$checks[]  = array( 'id' => 'menu', 'ok' => isset( $menus[ (int) $post->ID ] ), 'label' => __( 'Linked from a navigation menu', 'publishrank' ), 'detail' => '' );
		$inbound   = null;
		if ( class_exists( '\\PublishRankSEO\\InternalLinks\\Store' ) ) {
			$node = \PublishRankSEO\InternalLinks\Store::node_for_object( $post->ID );
			if ( $node ) {
				$inbound = (int) ( is_object( $node ) ? $node->inbound_count : ( $node['inbound_count'] ?? 0 ) );
			}
		}
		$checks[] = array(
			'id'     => 'inbound',
			'ok'     => null === $inbound ? null : $inbound > 0,
			'label'  => __( 'Internal links point to it', 'publishrank' ),
			/* translators: %d: number of internal links. */
			'detail' => null === $inbound ? __( 'Not scanned yet: run the Content Graph scan.', 'publishrank' ) : sprintf( _n( '%d internal link', '%d internal links', $inbound, 'publishrank' ), $inbound ),
		);
		$canonical = (string) PostMeta::get( $post->ID, PostMeta::CANONICAL, '' );
		$checks[]  = array( 'id' => 'canonical', 'ok' => '' === $canonical || untrailingslashit( $canonical ) === untrailingslashit( $permalink ), 'label' => __( 'Canonical points to itself', 'publishrank' ), 'detail' => '' === $canonical ? '' : $canonical );
		$desc      = (string) PostMeta::get( $post->ID, PostMeta::DESCRIPTION, '' );
		$checks[]  = array( 'id' => 'description', 'ok' => '' !== trim( $desc ) || '' !== trim( (string) $post->post_excerpt ), 'label' => __( 'Has a written meta description or excerpt', 'publishrank' ), 'detail' => '' );
		return $checks;
	}
}
