<?php
namespace PublishRankSEO\Sitemap\Providers;

use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;

defined( 'ABSPATH' ) || exit;

abstract class AbstractPostProvider implements SitemapProviderInterface {
	const SHARD_SIZE = 50000;
	protected $post_types = array();
	protected $slug = 'post';
	protected $name = 'Posts';
	public function get_name() { return $this->name; }
	public function get_slug() { return $this->slug; }
	public function is_enabled() { return true; }
	public function get_shard_count() {
		$query = new \WP_Query( array( 'post_type' => $this->post_types, 'post_status' => 'publish', 'posts_per_page' => 1, 'no_found_rows' => false, 'fields' => 'ids', 'meta_query' => $this->robots_query() ) );
		return max( 1, (int) ceil( (int) $query->found_posts / self::SHARD_SIZE ) );
	}
	public function get_lastmod() {
		$query = new \WP_Query( array( 'post_type' => $this->post_types, 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'fields' => 'ids' ) );
		return ! empty( $query->posts ) ? get_post_modified_time( DATE_W3C, true, (int) $query->posts[0] ) : '';
	}
	public function prepare_cursor( $steps = 2 ) { SitemapCursorStore::prepare_posts( $this->get_slug(), $this->query_args(), self::SHARD_SIZE, $steps ); }
	public function render_shard( $shard ) {
		$post_ids = SitemapCursorStore::post_page( $this->get_slug(), $this->query_args(), $shard, self::SHARD_SIZE );
		$entries = array();
		if ( null === $post_ids ) { return SitemapRenderer::urlset( $entries, $this->namespaces() ); }
		foreach ( $post_ids as $post_id ) {
			if ( ! $this->is_indexable( $post_id ) ) { continue; }
			$url = get_permalink( $post_id );
			if ( ! $url || $this->is_bad_url( $url ) ) { continue; }
			$extra = $this->extra( $post_id );
			if ( $this->requires_extra() && '' === trim( (string) $extra ) ) { continue; }
			$lastmod = get_post_modified_time( DATE_W3C, true, $post_id ) ?: get_post_time( DATE_W3C, true, $post_id );
			$entries[] = SitemapRenderer::url( $url, $lastmod, $extra );
		}
		return SitemapRenderer::urlset( $entries, $this->namespaces() );
	}
	protected function query_args() { return array( 'post_type' => $this->post_types, 'post_status' => 'publish', 'ignore_sticky_posts' => true, 'meta_query' => $this->robots_query() ); }
	protected function robots_query() { return array( 'relation' => 'OR', array( 'key' => '_publishrank_seo_robots', 'compare' => 'NOT EXISTS' ), array( 'key' => '_publishrank_seo_robots', 'value' => 'noindex', 'compare' => 'NOT LIKE' ) ); }
	protected function is_indexable( $post_id ) { $robots = strtolower( (string) get_post_meta( $post_id, '_publishrank_seo_robots', true ) ); return false === strpos( $robots, 'noindex' ) && ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ); }
	protected function is_bad_url( $url ) { $parsed = wp_parse_url( $url ); $home = wp_parse_url( home_url( '/' ) ); return empty( $parsed['host'] ) || empty( $home['host'] ) || strtolower( $parsed['host'] ) !== strtolower( $home['host'] ); }
	protected function extra( $post_id ) { return ''; }
	protected function requires_extra() { return false; }
	/** Resolve the media sitemap content-type setting; 'all' means every public, queryable non-attachment type. */
	protected function media_post_types() {
		$raw = trim( (string) get_option( 'publishrank_seo_media_post_types', 'all' ) );
		if ( '' === $raw || 'all' === strtolower( $raw ) ) {
			$types = get_post_types( array( 'public' => true, 'publicly_queryable' => true ), 'names' );
			return array_values( array_diff( (array) $types, array( 'attachment', 'revision', 'nav_menu_item' ) ) );
		}
		$types = array();
		foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $type ) {
			$type = sanitize_key( $type ); $object = get_post_type_object( $type );
			if ( $object && $object->public && $object->publicly_queryable && 'attachment' !== $type ) { $types[] = $type; }
		}
		return array_values( array_unique( $types ? $types : array( 'post', 'page' ) ) );
	}
	protected function namespaces() { return array(); }
}
