<?php
namespace PublishRankSEO\Sitemap\Providers;

use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;

defined( 'ABSPATH' ) || exit;

final class TaxonomySitemapProvider implements SitemapProviderInterface {
	const SHARD_SIZE = 50000;
	public function get_name() { return 'Taxonomies'; }
	public function get_slug() { return 'taxonomy'; }
	public function is_enabled() { return (bool) get_option( 'publishrank_sitemap_taxonomies_enabled', true ); }
	private function taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( $taxonomy->publicly_queryable ) { $out[] = $taxonomy->name; }
		}
		return $out;
	}
	public function get_shard_count() {
		$count = 0;
		foreach ( $this->taxonomies() as $taxonomy ) {
			$count += (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
		}
		return max( 1, (int) ceil( $count / self::SHARD_SIZE ) );
	}
	public function prepare_cursor( $steps = 2 ) { SitemapCursorStore::prepare_terms( $this->get_slug(), $this->query_args(), self::SHARD_SIZE, $steps ); }
	public function render_shard( $shard ) {
		$terms = SitemapCursorStore::term_page( $this->get_slug(), $this->query_args(), $shard, self::SHARD_SIZE );
		$entries = array();
		if ( null === $terms ) { return SitemapRenderer::urlset( $entries ); }
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( is_wp_error( $url ) || ! $url || false !== strpos( strtolower( (string) get_term_meta( $term->term_id, '_publishrank_seo_robots', true ) ), 'noindex' ) ) { continue; }
					$lastmod = ! empty( $term->term_id ) ? get_term_meta( $term->term_id, '_publishrank_last_modified', true ) : '';
					if ( ! $lastmod ) { $lastmod = $this->latest_post_modified( $term ); }
					$entries[] = SitemapRenderer::url( $url, $lastmod );
			}
		}
		return SitemapRenderer::urlset( $entries );
	}
	private function query_args() { return array( 'taxonomy' => $this->taxonomies(), 'hide_empty' => true ); }
	private function latest_post_modified( $term ) {
		$query = new \WP_Query( array( 'post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => $term->taxonomy, 'terms' => array( $term->term_id ) ) ) ) );
		return ! empty( $query->posts ) ? get_post_modified_time( DATE_W3C, true, (int) $query->posts[0] ) : '';
	}
}
