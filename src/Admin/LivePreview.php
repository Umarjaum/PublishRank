<?php
/**
 * Editor "Live Preview": shows, for the post being edited and BEFORE it is saved, exactly what PublishRank will
 * output in <head> — search snippet, social card, meta tags and JSON-LD — plus validation checks.
 *
 * It does not re-implement any generator. The endpoint:
 *   1. runs the real PostMeta::save() sanitizers with database writes intercepted (nothing is stored),
 *   2. swaps the unsaved title/content/excerpt/slug/featured image onto an in-memory copy of the post,
 *   3. runs the real wp_head callbacks that belong to PublishRank and parses their output.
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Meta\SeoData;

defined( 'ABSPATH' ) || exit;

final class LivePreview {
	/** @var array<string,mixed> unsaved meta captured during a preview request */
	private $overlay = array();
	/** @var int */
	private $overlay_post = 0;
	/** @var \PublishRankSEO\Core\Plugin|null */
	private $plugin = null;

	public function register() {
		add_action( 'publishrank_seo_loaded', function ( $plugin ) { $this->plugin = $plugin; } );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 5, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 30 );
	}

	/* ---------- editor UI ---------- */
	public function add_meta_box( $post_type = '', $post = null ) {
		if ( $post_type && in_array( $post_type, PostMeta::post_types(), true ) ) {
			add_meta_box( 'publishrank-live-preview', __( 'PublishRank Live Preview', 'publishrank' ), array( $this, 'render_meta_box' ), $post_type, 'normal', 'high' );
		}
	}

	public function render_meta_box( $post ) {
			$tabs = array( 'search' => __( 'Search snippet', 'publishrank' ), 'website' => __( 'Website view', 'publishrank' ), 'social' => __( 'Social card', 'publishrank' ), 'meta' => __( 'Meta tags', 'publishrank' ), 'json' => __( 'JSON-LD', 'publishrank' ), 'checks' => __( 'Checks', 'publishrank' ) );
		echo '<div id="pr-preview" class="pr-preview" data-post-id="' . esc_attr( (int) $post->ID ) . '">';
		echo '<div class="pr-preview__bar"><span class="pr-live-pill" data-pr-pv-pill><i class="pr-live-dot"></i><span>' . esc_html__( 'Live', 'publishrank' ) . '</span></span><span class="pr-preview__status" data-pr-pv-status>' . esc_html__( 'Starting…', 'publishrank' ) . '</span><button type="button" class="button" data-pr-pv-refresh>' . esc_html__( 'Refresh', 'publishrank' ) . '</button></div>';
		echo '<p class="pr-preview__note">' . esc_html__( 'Built from your unsaved edits using the same code that outputs your page. Nothing is saved by previewing.', 'publishrank' ) . '</p>';
		echo '<div class="pr-preview__tabs" role="tablist">';
		$first = true;
		foreach ( $tabs as $key => $label ) {
			echo '<button type="button" role="tab" class="pr-preview__tab' . ( $first ? ' is-active' : '' ) . '" data-pr-pv-tab="' . esc_attr( $key ) . '" aria-selected="' . ( $first ? 'true' : 'false' ) . '">' . esc_html( $label ) . ( 'checks' === $key ? ' <span class="pr-preview__count" data-pr-pv-count hidden></span>' : '' ) . '</button>';
			$first = false;
		}
		echo '</div>';
		$first = true;
		foreach ( $tabs as $key => $label ) {
			echo '<div class="pr-preview__panel" role="tabpanel" data-pr-pv-panel="' . esc_attr( $key ) . '"' . ( $first ? '' : ' hidden' ) . '></div>';
			$first = false;
		}
		echo '</div>';
	}

	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) { return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( (string) $screen->post_type, PostMeta::post_types(), true ) ) { return; }
		$css = Branding::version() . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/css/live-preview.css' );
		$js  = Branding::version() . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/js/live-preview.js' );
		wp_enqueue_style( 'publishrank-live-preview', Branding::asset_url( 'css/live-preview.css' ), array(), $css );
		wp_enqueue_script( 'publishrank-live-preview', Branding::asset_url( 'js/live-preview.js' ), array( 'jquery' ), $js, true );
		wp_localize_script( 'publishrank-live-preview', 'publishrankPreview', array(
			'url'   => esc_url_raw( rest_url( 'publishrank/v1/preview/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'  => array(
				'updated' => __( 'Updated', 'publishrank' ), 'justNow' => __( 'just now', 'publishrank' ), 'ago' => __( 's ago', 'publishrank' ), 'updating' => __( 'Updating…', 'publishrank' ),
				'failed' => __( 'Preview unavailable. Save a draft and press Refresh.', 'publishrank' ), 'copy' => __( 'Copy', 'publishrank' ), 'copied' => __( 'Copied', 'publishrank' ),
				'none' => __( 'None found', 'publishrank' ), 'noJson' => __( 'No JSON-LD is being output for this post. Check that Schema output is enabled in PublishRank settings.', 'publishrank' ),
				'allGood' => __( 'No problems found.', 'publishrank' ), 'rich' => __( 'Open Google Rich Results Test', 'publishrank' ), 'richCode' => __( 'Open Rich Results Test (paste code)', 'publishrank' ),
				'desktop' => __( 'Desktop', 'publishrank' ), 'mobile' => __( 'Mobile', 'publishrank' ), 'facebook' => __( 'Facebook / LinkedIn', 'publishrank' ), 'x' => __( 'X / Twitter', 'publishrank' ),
				'chars' => __( 'characters', 'publishrank' ), 'pixels' => __( 'px', 'publishrank' ), 'title' => __( 'Title', 'publishrank' ), 'description' => __( 'Description', 'publishrank' ),
				'noImage' => __( 'No social image', 'publishrank' ), 'unsaved' => __( 'This post has not been published yet, so Google cannot test its URL. Copy the JSON-LD below instead.', 'publishrank' ),
				'nodes' => __( 'Entities', 'publishrank' ), 'invalid' => __( 'Invalid JSON', 'publishrank' ),
			),
		) );
	}

	/* ---------- REST ---------- */
	public function routes() {
		register_rest_route( 'publishrank/v1', '/preview/(?P<id>\d+)', array(
			'methods'             => 'POST',
			'permission_callback' => static function ( $request ) { return current_user_can( 'edit_post', (int) $request['id'] ); },
			'callback'            => array( $this, 'preview' ),
		) );
	}

	public function preview( $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) { return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 ); }
		try {
			return rest_ensure_response( $this->build( $post ) );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( array( 'error' => 'preview_failed', 'message' => substr( $e->getMessage(), 0, 200 ) ), 500 );
		}
	}

	/** Run the preview, then put every global, filter and cache entry back exactly as it was. */
	private function build( \WP_Post $orig ) {
		global $wp_query, $wp_the_query, $post;
		$saved = array( $wp_query, $wp_the_query, $post );
		$registry = $this->plugin && method_exists( $this->plugin, 'registry' ) ? $this->plugin->registry() : null;
		if ( $registry ) { $registry->reset(); }
		try {
			return $this->build_inner( $orig );
		} finally {
			remove_filter( 'update_post_metadata', array( $this, 'capture_write' ), 1 );
			remove_filter( 'get_post_metadata', array( $this, 'serve_overlay' ), 1 );
			$this->overlay = array(); $this->overlay_post = 0;
			clean_post_cache( (int) $orig->ID );
			SeoData::flush( (int) $orig->ID );
			if ( $registry ) { $registry->reset(); }
			list( $wp_query, $wp_the_query, $post ) = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring request state.
		}
	}

	private function build_inner( \WP_Post $orig ) {
		$id = (int) $orig->ID;
		$this->overlay_post = $id;
		$p = static function ( $key ) { return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null; }; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST cookie auth + capability checked in permission_callback.

		// 1) Capture the real, sanitised meta values without storing them.
		add_filter( 'update_post_metadata', array( $this, 'capture_write' ), 1, 4 );
		add_filter( 'get_post_metadata', array( $this, 'serve_overlay' ), 1, 4 );
		PostMeta::save( $id ); // verifies its own nonce + capability; writes are intercepted above.
		remove_filter( 'update_post_metadata', array( $this, 'capture_write' ), 1 );
		if ( null !== $p( 'pr_thumb' ) && '' !== (string) $p( 'pr_thumb' ) ) { $this->overlay['_thumbnail_id'] = (string) absint( $p( 'pr_thumb' ) ); }

		// 2) Unsaved core fields on an in-memory copy.
		$clone = clone $orig;
		$unfiltered = current_user_can( 'unfiltered_html' );
		if ( null !== $p( 'pr_title' ) ) { $clone->post_title = sanitize_text_field( (string) $p( 'pr_title' ) ); }
		if ( null !== $p( 'pr_content' ) ) { $c = (string) $p( 'pr_content' ); $clone->post_content = $unfiltered ? $c : wp_kses_post( $c ); }
		if ( null !== $p( 'pr_excerpt' ) ) { $e = (string) $p( 'pr_excerpt' ); $clone->post_excerpt = $unfiltered ? $e : wp_kses_post( $e ); }
		if ( null !== $p( 'pr_slug' ) && '' !== (string) $p( 'pr_slug' ) ) { $clone->post_name = wp_unique_post_slug( sanitize_title( (string) $p( 'pr_slug' ) ), (int) $clone->ID, 'publish' === $clone->post_status ? 'publish' : 'draft', $clone->post_type, (int) $clone->post_parent ); } // Same collision handling as saving, so the preview shows the URL WordPress will really use.
		$clone->filter = 'raw';
		// Keep the unsaved copy out of any persistent object cache.
		if ( function_exists( 'wp_cache_add_non_persistent_groups' ) ) { wp_cache_add_non_persistent_groups( array( 'posts' ) ); }
		wp_cache_set( $id, $clone, 'posts' );
		SeoData::flush( $id );

		// 3) Make this the main singular query and run PublishRank's own head output.
		global $wp_query, $wp_the_query, $post;
		$q = new \WP_Query();
		$q->posts = array( $clone ); $q->post = $clone; $q->post_count = 1; $q->found_posts = 1; $q->max_num_pages = 1;
		$q->queried_object = $clone; $q->queried_object_id = $id;
		$q->is_singular = true; $q->is_single = 'page' !== $clone->post_type; $q->is_page = 'page' === $clone->post_type;
		$q->is_home = false; $q->is_archive = false; $q->is_404 = false; $q->is_search = false;
		$wp_the_query = $q; $wp_query = $q; $post = $clone; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- request-scoped preview context.
		setup_postdata( $clone );

		$this->keep_only_publishrank_head_output();
		ob_start();
		do_action( 'wp_head' );
		$head = (string) ob_get_clean();
		$title = html_entity_decode( wp_get_document_title(), ENT_QUOTES, 'UTF-8' );

		$parsed = $this->parse_head( $head );
		$jsonld = $this->analyze_jsonld( $parsed['jsonld'] );
		$meta   = $parsed['meta'];
		$get    = static function ( $k ) use ( $meta ) { return isset( $meta[ $k ] ) ? $meta[ $k ][0] : ''; };
		$canonical = $parsed['canonical'];
		$issues = array_merge( $this->check_meta( $title, $meta, $parsed, $clone ), $jsonld['issues'] );
		$permalink = get_permalink( $clone );

		SeoData::flush( $id );
			return array(
				'title' => $title, 'description' => $get( 'name:description' ), 'visible_title' => (string) $clone->post_title, 'visible_description' => wp_trim_words( wp_strip_all_tags( get_the_excerpt( $clone ) ), 45, '…' ), 'canonical' => $canonical ? $canonical : $permalink, 'robots' => $get( 'name:robots' ),
			'site_name' => (string) get_bloginfo( 'name' ), 'favicon' => (string) get_site_icon_url( 32 ), 'date' => get_post_time( 'c', true, $clone ),
			'permalink' => $permalink, 'published' => 'publish' === $clone->post_status,
			'social' => array(
				'title' => $get( 'property:og:title' ), 'description' => $get( 'property:og:description' ), 'image' => $get( 'property:og:image' ), 'site_name' => $get( 'property:og:site_name' ),
				'url' => $get( 'property:og:url' ), 'type' => $get( 'property:og:type' ), 'card' => $get( 'name:twitter:card' ), 'x_title' => $get( 'name:twitter:title' ), 'x_description' => $get( 'name:twitter:description' ), 'x_image' => $get( 'name:twitter:image' ),
			),
			'meta' => $parsed['meta_list'], 'links' => $parsed['links'], 'jsonld' => $jsonld['blocks'], 'nodes' => $jsonld['nodes'], 'issues' => $issues,
			'head_raw' => $head, 'generated_at' => time(),
		);
	}

	/* ---------- in-memory meta overlay ---------- */
	public function capture_write( $check, $object_id, $meta_key, $meta_value ) {
		if ( (int) $object_id === $this->overlay_post && is_string( $meta_key ) && 0 === strpos( $meta_key, '_publishrank_seo_' ) ) {
			$this->overlay[ $meta_key ] = $meta_value;
			return true; // short-circuits the database write
		}
		return $check;
	}
	public function serve_overlay( $value, $object_id, $meta_key, $single ) {
		if ( (int) $object_id === $this->overlay_post && is_string( $meta_key ) && array_key_exists( $meta_key, $this->overlay ) ) { return array( $this->overlay[ $meta_key ] ); }
		return $value;
	}

	/** Remove every wp_head callback that does not belong to PublishRank, so the preview shows only what PublishRank outputs. */
	private function keep_only_publishrank_head_output() {
		global $wp_filter;
		if ( empty( $wp_filter['wp_head'] ) || ! $wp_filter['wp_head'] instanceof \WP_Hook ) { return; }
		foreach ( $wp_filter['wp_head']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn = $cb['function'];
				$ours = is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) && 0 === strpos( get_class( $fn[0] ), 'PublishRankSEO\\' );
				if ( ! $ours ) { remove_action( 'wp_head', $fn, $priority ); }
			}
		}
	}

	/* ---------- parsing ---------- */
	private function parse_head( $html ) {
		$out = array( 'meta' => array(), 'meta_list' => array(), 'links' => array(), 'jsonld' => array(), 'canonical' => '' );
		if ( '' === trim( $html ) ) { return $out; }
		$dom = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?><html><head>' . $html . '</head><body></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors(); libxml_use_internal_errors( $prev );
		foreach ( $dom->getElementsByTagName( 'meta' ) as $m ) {
			$key = $m->hasAttribute( 'property' ) ? 'property:' . $m->getAttribute( 'property' ) : ( $m->hasAttribute( 'name' ) ? 'name:' . $m->getAttribute( 'name' ) : '' );
			if ( '' === $key ) { continue; }
			$val = $m->getAttribute( 'content' );
			$out['meta'][ $key ][] = $val;
			$out['meta_list'][] = array( 'k' => substr( $key, strpos( $key, ':' ) + 1 ), 'kind' => substr( $key, 0, strpos( $key, ':' ) ), 'v' => $val );
		}
		foreach ( $dom->getElementsByTagName( 'link' ) as $l ) {
			$rel = $l->getAttribute( 'rel' );
			$out['links'][] = array( 'rel' => $rel, 'href' => $l->getAttribute( 'href' ), 'hreflang' => $l->getAttribute( 'hreflang' ) );
			if ( 'canonical' === $rel ) { $out['canonical'] = $l->getAttribute( 'href' ); $out['canonical_count'] = ( $out['canonical_count'] ?? 0 ) + 1; }
		}
		foreach ( $dom->getElementsByTagName( 'script' ) as $s ) {
			if ( 'application/ld+json' === strtolower( $s->getAttribute( 'type' ) ) ) { $out['jsonld'][] = trim( $s->textContent ); }
		}
		return $out;
	}

	private function analyze_jsonld( $raw_blocks ) {
		$blocks = array(); $nodes = array(); $issues = array(); $defined = array(); $refs = array();
		$walk = static function ( $value, $cb ) use ( &$walk ) { if ( is_array( $value ) ) { $cb( $value ); foreach ( $value as $v ) { $walk( $v, $cb ); } } };
		foreach ( $raw_blocks as $i => $raw ) {
			$data = json_decode( $raw, true );
			if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
				$blocks[] = array( 'pretty' => $raw, 'valid' => false, 'error' => json_last_error_msg(), 'types' => array() );
				$issues[] = array( 'level' => 'error', 'area' => 'JSON-LD', 'msg' => sprintf( /* translators: 1: JSON-LD block number, 2: JSON parsing error message. */ __( 'JSON-LD block %1$d is not valid JSON (%2$s). Google will ignore it.', 'publishrank' ), $i + 1, json_last_error_msg() ) );
				continue;
			}
			$list = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : array( $data );
			$types = array();
			foreach ( $list as $node ) {
				if ( ! is_array( $node ) ) { continue; }
				$t = isset( $node['@type'] ) ? (array) $node['@type'] : array();
				$types = array_merge( $types, array_map( 'strval', $t ) );
				$nodes[] = array( 'type' => implode( ', ', array_map( 'strval', $t ) ), 'id' => (string) ( $node['@id'] ?? '' ), 'name' => (string) ( is_scalar( $node['headline'] ?? null ) ? $node['headline'] : ( is_scalar( $node['name'] ?? null ) ? $node['name'] : '' ) ) );
				if ( isset( $node['@id'] ) && is_string( $node['@id'] ) ) {
					if ( isset( $defined[ $node['@id'] ] ) && count( $node ) > 1 ) { $issues[] = array( 'level' => 'warn', 'area' => 'JSON-LD', 'msg' => sprintf( /* translators: %s: duplicated JSON-LD @id value. */ __( 'Duplicate @id: %s is defined more than once.', 'publishrank' ), $node['@id'] ) ); }
					if ( count( $node ) > 1 ) { $defined[ $node['@id'] ] = $node; }
				}
				$walk( $node, static function ( $arr ) use ( &$refs ) { if ( isset( $arr['@id'] ) && 1 === count( $arr ) && is_string( $arr['@id'] ) ) { $refs[ $arr['@id'] ] = true; } } );
				$issues = array_merge( $issues, $this->check_node( $node, $list ) );
			}
			$blocks[] = array( 'pretty' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'valid' => true, 'error' => '', 'types' => array_values( array_unique( $types ) ) );
		}
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array_keys( $refs ) as $ref ) {
			if ( ! isset( $defined[ $ref ] ) && (string) wp_parse_url( $ref, PHP_URL_HOST ) === $home ) { $issues[] = array( 'level' => 'warn', 'area' => 'JSON-LD', 'msg' => sprintf( /* translators: %s: referenced entity identifier. */ __( 'A node refers to %s but that entity is not in the graph.', 'publishrank' ), $ref ) ); }
		}
		return array( 'blocks' => $blocks, 'nodes' => $nodes, 'issues' => $issues );
	}

	private function check_node( $node, $graph ) {
		$types = isset( $node['@type'] ) ? array_map( 'strval', (array) $node['@type'] ) : array();
		$has = static function ( $field ) use ( $node ) { return ! empty( $node[ $field ] ); };
		$out = array(); $label = $types ? $types[0] : 'Node';
		$add = static function ( $level, $msg ) use ( &$out, $label ) { $out[] = array( 'level' => $level, 'area' => $label, 'msg' => $msg ); };
		$is_article = (bool) array_filter( $types, static function ( $t ) { return false !== stripos( $t, 'Article' ) || 'BlogPosting' === $t; } );
		if ( $is_article ) {
			if ( ! $has( 'headline' ) ) { $add( 'error', __( 'Missing headline.', 'publishrank' ) ); }
			elseif ( function_exists( 'mb_strlen' ) && mb_strlen( (string) $node['headline'] ) > 110 ) { $add( 'warn', __( 'Headline is over 110 characters; Google may truncate it.', 'publishrank' ) ); }
			if ( ! $has( 'datePublished' ) ) { $add( 'error', __( 'Missing datePublished.', 'publishrank' ) ); }
			if ( ! $has( 'dateModified' ) ) { $add( 'info', __( 'dateModified is recommended for news freshness.', 'publishrank' ) ); }
			if ( ! $has( 'image' ) ) { $add( 'warn', __( 'No image on the article. Google News and Discover need a large image.', 'publishrank' ) ); }
			if ( ! $has( 'author' ) ) { $add( 'warn', __( 'No author on the article.', 'publishrank' ) ); }
			if ( ! $has( 'publisher' ) ) { $add( 'warn', __( 'No publisher on the article.', 'publishrank' ) ); }
		}
		if ( in_array( 'FAQPage', $types, true ) ) {
			$items = isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ? $node['mainEntity'] : array();
			if ( ! $items ) { $add( 'error', __( 'FAQPage has no questions.', 'publishrank' ) ); }
			foreach ( $items as $q ) { if ( ! is_array( $q ) || empty( $q['name'] ) || empty( $q['acceptedAnswer']['text'] ) ) { $add( 'error', __( 'An FAQ item is missing its question or answer text.', 'publishrank' ) ); break; } }
		}
		if ( in_array( 'BreadcrumbList', $types, true ) && empty( $node['itemListElement'] ) ) { $add( 'error', __( 'BreadcrumbList has no items.', 'publishrank' ) ); }
		if ( array_intersect( $types, array( 'Organization', 'NewsMediaOrganization' ) ) ) {
			if ( ! $has( 'name' ) ) { $add( 'warn', __( 'Organization has no name.', 'publishrank' ) ); }
			if ( ! $has( 'logo' ) ) { $add( 'warn', __( 'Organization has no logo; Google News prefers a publisher logo.', 'publishrank' ) ); }
		}
		if ( in_array( 'VideoObject', $types, true ) ) { foreach ( array( 'name', 'thumbnailUrl', 'uploadDate' ) as $f ) { if ( ! $has( $f ) ) { $add( 'error', sprintf( /* translators: %s: missing VideoObject property name. */ __( 'VideoObject is missing %s.', 'publishrank' ), $f ) ); } } }
		return $out;
	}

	private function check_meta( $title, $meta, $parsed, $post ) {
		$out = array();
		$add = static function ( $level, $msg, $area = 'Meta' ) use ( &$out ) { $out[] = array( 'level' => $level, 'area' => $area, 'msg' => $msg ); };
		$len = static function ( $s ) { return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s ); };
		$desc = isset( $meta['name:description'][0] ) ? $meta['name:description'][0] : '';
		if ( '' === trim( $title ) ) { $add( 'error', __( 'No title.', 'publishrank' ), 'Title' ); }
		elseif ( $len( $title ) > 60 ) { $add( 'warn', sprintf( /* translators: %d: title character count. */ __( 'Title is %d characters; Google usually shows about 60.', 'publishrank' ), $len( $title ) ), 'Title' ); }
		elseif ( $len( $title ) < 25 ) { $add( 'info', sprintf( /* translators: %d: title character count. */ __( 'Title is short (%d characters). A fuller headline often earns more clicks.', 'publishrank' ), $len( $title ) ), 'Title' ); }
		if ( '' === $desc ) { $add( 'warn', __( 'No meta description. Google will pick its own snippet.', 'publishrank' ), 'Description' ); }
		elseif ( $len( $desc ) > 160 ) { $add( 'warn', sprintf( /* translators: %d: description character count. */ __( 'Description is %d characters; Google usually shows about 155–160.', 'publishrank' ), $len( $desc ) ), 'Description' ); }
		elseif ( $len( $desc ) < 70 ) { $add( 'info', sprintf( /* translators: %d: description character count. */ __( 'Description is short (%d characters).', 'publishrank' ), $len( $desc ) ), 'Description' ); }
		foreach ( array( 'name:description' => 'description', 'name:robots' => 'robots' ) as $key => $name ) { if ( isset( $meta[ $key ] ) && count( $meta[ $key ] ) > 1 ) { $add( 'error', sprintf( /* translators: %s: metadata tag name. */ __( 'More than one %s tag is output.', 'publishrank' ), $name ) ); } }
		if ( ( $parsed['canonical_count'] ?? 0 ) > 1 ) { $add( 'error', __( 'More than one canonical link is output.', 'publishrank' ), 'Canonical' ); }
		if ( empty( $parsed['canonical'] ) ) { $add( 'warn', __( 'No canonical link.', 'publishrank' ), 'Canonical' ); }
		elseif ( 0 !== strpos( $parsed['canonical'], 'https://' ) && is_ssl() ) { $add( 'warn', __( 'Canonical is not https.', 'publishrank' ), 'Canonical' ); }
		$robots = isset( $meta['name:robots'][0] ) ? strtolower( $meta['name:robots'][0] ) : '';
		if ( false !== strpos( $robots, 'noindex' ) ) { $add( 'warn', __( 'This article is set to noindex and will not appear in Google Search or News.', 'publishrank' ), 'Robots' ); }
		elseif ( false === strpos( $robots, 'max-image-preview:large' ) ) { $add( 'info', __( 'Add max-image-preview:large to be eligible for large Discover images.', 'publishrank' ), 'Robots' ); }
		if ( empty( $meta['property:og:image'][0] ) ) { $add( 'warn', __( 'No social image. Add a featured image or a social image override.', 'publishrank' ), 'Social' ); }
		else {
			$w = isset( $meta['property:og:image:width'][0] ) ? (int) $meta['property:og:image:width'][0] : 0;
			if ( $w && $w < 1200 ) { $add( 'info', sprintf( /* translators: %d: social image width in pixels. */ __( 'Social image is %d px wide; Discover recommends at least 1200 px.', 'publishrank' ), $w ), 'Social' ); }
		}
		if ( empty( $meta['name:twitter:card'][0] ) ) { $add( 'info', __( 'No X/Twitter card tag.', 'publishrank' ), 'Social' ); }
		if ( empty( $out ) || ! array_filter( $out, static function ( $i ) { return in_array( $i['level'], array( 'error', 'warn' ), true ); } ) ) { $add( 'ok', __( 'Title, description, canonical, robots and social tags look good.', 'publishrank' ), 'Meta' ); }
		return $out;
	}
}
