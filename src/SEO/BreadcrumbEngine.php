<?php
namespace PublishRankSEO\SEO;

use PublishRankSEO\Core\PublicationProfile;
use PublishRankSEO\Meta\MetaGenerator;

final class BreadcrumbEngine {
	const ERROR_TRANSIENT = 'publishrank_breadcrumb_errors';

	public function register() {
		add_shortcode( 'publishrank_breadcrumbs', array( $this, 'shortcode' ) );
		add_filter( 'the_content', array( $this, 'prepend' ), 20 );
	}
	public function prepend( $content ) {
		$original = $content;
		if ( is_admin() || is_feed() || ! is_singular() || ! get_option( 'publishrank_breadcrumbs_enabled', true ) || ! in_the_loop() || ! is_main_query() ) { return $original; }
		return $this->render() . $content;
	}
	public function shortcode() { return $this->render(); }

	private function mode() {
		$mode = sanitize_key( (string) get_option( 'publishrank_breadcrumb_error_mode', 'skip' ) );
		return in_array( $mode, array( 'skip', 'fallback', 'text', 'disable' ), true ) ? $mode : 'skip';
	}

	private function error_key() {
		$key = sanitize_key( (string) get_option( 'publishrank_breadcrumb_error_key', 'breadcrumb_error' ) );
		return $key ?: 'breadcrumb_error';
	}

	private function record_error( $reason, $context = array() ) {
		if ( ! get_option( 'publishrank_breadcrumb_log_errors', false ) ) { return; }
		$errors = get_transient( self::ERROR_TRANSIENT );
		$errors = is_array( $errors ) ? $errors : array();
		$errors[] = array( 'key' => $this->error_key(), 'reason' => sanitize_key( $reason ), 'context' => array_map( 'sanitize_text_field', (array) $context ), 'time' => current_time( 'mysql' ) );
		set_transient( self::ERROR_TRANSIENT, array_slice( $errors, -20 ), DAY_IN_SECONDS );
	}

	private function add_item( &$items, $name, $url, $context = array() ) {
		$name = sanitize_text_field( (string) $name );
		$url  = esc_url_raw( (string) $url );
		if ( '' !== $name && '' !== $url ) { $items[] = array( 'name' => $name, 'url' => $url ); return true; }
		$this->record_error( 'invalid_item', $context );
		$mode = $this->mode();
		if ( 'disable' === $mode ) { return false; }
		if ( 'fallback' === $mode ) {
			$label = sanitize_text_field( (string) get_option( 'publishrank_breadcrumb_fallback_label', 'Home' ) );
			$fallback = esc_url_raw( (string) get_option( 'publishrank_breadcrumb_fallback_url', home_url( '/' ) ) );
			if ( $label && $fallback ) { $items[] = array( 'name' => $label, 'url' => $fallback ); return true; }
		}
		if ( 'text' === $mode && $name ) { $items[] = array( 'name' => $name, 'url' => '' ); return true; }
		return true;
	}

	public function items() {
		$items = array();
		if ( ! $this->add_item( $items, PublicationProfile::publication_name(), PublicationProfile::primary_url(), array( 'source' => 'publication' ) ) && 'disable' === $this->mode() ) { return array(); }
		if ( is_page() ) {
			$ancestors = array_reverse( get_post_ancestors( get_queried_object_id() ) );
			foreach ( $ancestors as $ancestor ) {
				if ( ! $this->add_item( $items, get_the_title( $ancestor ), get_permalink( $ancestor ), array( 'source' => 'page_ancestor', 'id' => $ancestor ) ) && 'disable' === $this->mode() ) { return array(); }
			}
		} elseif ( is_singular() ) {
			$post_type = get_post_type(); $object = get_post_type_object( $post_type );
			if ( $object && 'post' !== $post_type ) {
				if ( ! $this->add_item( $items, $object->labels->singular_name, get_post_type_archive_link( $post_type ), array( 'source' => 'post_type_archive', 'type' => $post_type ) ) && 'disable' === $this->mode() ) { return array(); }
			}
			$taxonomy = $this->primary_taxonomy( $post_type );
			if ( $taxonomy ) {
				$terms = get_the_terms( get_queried_object_id(), $taxonomy->name );
				if ( is_array( $terms ) && ! empty( $terms ) ) { $items = $this->append_term_trail( $items, $terms[0], $taxonomy->name ); if ( 'disable' === $this->mode() && empty( $items ) ) { return array(); } }
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object(); if ( $term instanceof \WP_Term ) { $items = $this->append_term_trail( $items, $term, $term->taxonomy ); }
		} elseif ( is_post_type_archive() ) {
			$object = get_queried_object(); if ( $object && ! empty( $object->labels->name ) ) { $this->add_item( $items, $object->labels->name, get_post_type_archive_link( $object->name ), array( 'source' => 'archive', 'type' => $object->name ) ); }
		}
		$current = wp_get_document_title(); $canonical = ( new MetaGenerator() )->canonical();
		if ( $current ) {
			if ( '' === esc_url_raw( (string) $canonical ) ) {
				$this->record_error( 'invalid_canonical', array( 'source' => 'current' ) );
				if ( 'disable' === $this->mode() ) { return array(); }
				if ( 'text' === $this->mode() ) { $items[] = array( 'name' => sanitize_text_field( $current ), 'url' => '' ); }
				elseif ( 'fallback' === $this->mode() ) { $this->add_item( $items, $current, get_option( 'publishrank_breadcrumb_fallback_url', home_url( '/' ) ), array( 'source' => 'current_fallback' ) ); }
			} elseif ( empty( $items ) || end( $items )['name'] !== $current ) { $this->add_item( $items, $current, $canonical, array( 'source' => 'current' ) ); }
		}
		return array_values( $items );
	}
	private function primary_taxonomy( $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) { if ( $taxonomy->public && $taxonomy->hierarchical ) { return $taxonomy; } }
		return null;
	}
	private function append_term_trail( $items, $term, $taxonomy ) {
		$ancestors = array_reverse( get_ancestors( $term->term_id, $taxonomy ) );
		foreach ( array_merge( $ancestors, array( $term->term_id ) ) as $term_id ) {
			$ancestor = get_term( $term_id, $taxonomy );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$this->add_item( $items, $ancestor->name, get_term_link( $ancestor ), array( 'source' => 'term', 'taxonomy' => $taxonomy, 'id' => $term_id ) );
			}
		}
		return $items;
	}
	public function render() {
		$items = $this->items(); if ( empty( $items ) ) { return ''; }
		$separator = sanitize_text_field( (string) get_option( 'publishrank_breadcrumbs_separator', '→' ) ); $html = '<nav class="publishrank-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumbs', 'publishrank' ) . '"><ol>';
		$last = count( $items ) - 1;
		foreach ( $items as $index => $item ) {
			$is_link = $index < $last && ! empty( $item['url'] );
			$html .= '<li>' . ( $is_link ? '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>' : '<span' . ( $index === $last ? ' aria-current="page"' : '' ) . '>' . esc_html( $item['name'] ) . '</span>' ) . ( $index < $last ? '<span class="publishrank-breadcrumb-separator" aria-hidden="true"> ' . esc_html( $separator ) . ' </span>' : '' ) . '</li>';
		}
		return $html . '</ol></nav>';
	}
	public function schema() {
		$list = array(); foreach ( $this->items() as $position => $item ) { $entry = array( '@type' => 'ListItem', 'position' => $position + 1, 'name' => wp_strip_all_tags( $item['name'] ) ); if ( ! empty( $item['url'] ) ) { $entry['item'] = esc_url_raw( $item['url'] ); } $list[] = $entry; }
		return array( '@type' => 'BreadcrumbList', '@id' => trailingslashit( ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical() ) . '#breadcrumb', 'itemListElement' => $list );
	}
}
