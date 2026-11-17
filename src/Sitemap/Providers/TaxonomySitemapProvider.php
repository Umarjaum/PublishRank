<?php
namespace PublishRankSEO\Sitemap\Providers;

use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;

defined( 'ABSPATH' ) || exit;

final class TaxonomySitemapProvider implements SitemapProviderInterface {
	/** 10,000 terms per file keeps peak memory around 30 MB on shared hosts (50,000 WP_Term objects needed ~150 MB). */
	const SHARD_SIZE = 10000;
	public function get_name() {
		return 'Taxonomies'; }
	public function get_slug() {
		return 'taxonomy'; }
	public function is_enabled() {
		return (bool) get_option( 'publishrank_sitemap_taxonomies_enabled', true ); }
	private function taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( $taxonomy->publicly_queryable ) {
				$out[] = $taxonomy->name; }
		}
		return $out;
	}
	/** Newest change among published posts: a cheap, honest lastmod for the index (term archives change when posts do). */
	public function get_lastmod() {
		$q = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return ! empty( $q ) ? (string) get_post_modified_time( DATE_W3C, true, (int) $q[0] ) : '';
	}
	/**
	 * Listable terms: has published content directly, or through a direct child (category archives include children).
	 * Done in SQL with hierarchical=false: with hierarchical+hide_empty WordPress ignores "number" and loads every
	 * term of the taxonomy into memory (60,000 terms needed ~150 MB per request).
	 */
	private static function nonempty_sql( $alias = 'tt' ) {
		global $wpdb;
		return "( {$alias}.count > 0 OR EXISTS (SELECT 1 FROM {$wpdb->term_taxonomy} prx_c WHERE prx_c.parent = {$alias}.term_id AND prx_c.taxonomy = {$alias}.taxonomy AND prx_c.count > 0) )";
	}
	public static function ensure_filter() {
		static $done = false;
		if ( $done ) {
			return; }
		$done = true;
		add_filter(
			'terms_clauses',
			static function ( $clauses, $taxonomies, $args ) {
				if ( ! empty( $args['publishrank_nonempty'] ) ) {
					$clauses['where'] .= ' AND ' . self::nonempty_sql( 'tt' ); }
				return $clauses;
			},
			10,
			3
		);
	}
	public function get_shard_count() {
		global $wpdb;
		$tax = array_map( 'sanitize_key', $this->taxonomies() );
		if ( ! $tax ) {
			return 1; }
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy IN (" . implode( ',', array_fill( 0, count( $tax ), '%s' ) ) . ') AND ' . self::nonempty_sql( 'tt' ), $tax ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		return max( 1, (int) ceil( $count / self::SHARD_SIZE ) );
	}
	public function prepare_cursor( $steps = 2 ) {
		SitemapCursorStore::prepare_terms( $this->get_slug(), $this->query_args(), self::SHARD_SIZE, $steps ); }
	public function render_shard( $shard ) {
		$terms   = SitemapCursorStore::term_page( $this->get_slug(), $this->query_args(), $shard, self::SHARD_SIZE );
		$entries = array();
		if ( null === $terms ) {
			return SitemapRenderer::urlset( $entries ); }
		if ( ! is_wp_error( $terms ) ) {
			$latest = $this->latest_by_term( $terms );
			foreach ( $terms as $i => $term ) {
				if ( 0 === $i % 1000 ) {
					update_termmeta_cache( wp_list_pluck( array_slice( $terms, $i, 1000 ), 'term_id' ) ); }
				$url = get_term_link( $term );
				if ( is_wp_error( $url ) || ! $url || false !== strpos( strtolower( (string) get_term_meta( $term->term_id, '_publishrank_seo_robots', true ) ), 'noindex' ) ) {
					continue; }
					$lastmod = ! empty( $term->term_id ) ? get_term_meta( $term->term_id, '_publishrank_last_modified', true ) : '';
				if ( ! $lastmod && isset( $latest[ (int) $term->term_taxonomy_id ] ) ) {
					$lastmod = $latest[ (int) $term->term_taxonomy_id ]; }
					$entries[] = SitemapRenderer::url( $url, $lastmod );
			}
		}
		return SitemapRenderer::urlset( $entries );
	}
	private function query_args() {
		self::ensure_filter();
		return array(
			'taxonomy'               => $this->taxonomies(),
			'hide_empty'             => false,
			'hierarchical'           => false,
			'publishrank_nonempty'   => true,
			'update_term_meta_cache' => false,
		); }
	/** Newest published-post modification per term, one grouped query per 1,000 terms. @return array<int,string> */
	private function latest_by_term( array $terms ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_map( 'intval', wp_list_pluck( $terms, 'term_taxonomy_id' ) ), 1000 ) as $chunk ) {
			$rows = $wpdb->get_results( "SELECT tr.term_taxonomy_id tt, MAX(p.post_modified_gmt) m FROM {$wpdb->term_relationships} tr JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_status = 'publish' AND p.post_password = '' WHERE tr.term_taxonomy_id IN (" . implode( ',', $chunk ) . ') GROUP BY tr.term_taxonomy_id' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- integer list.
			foreach ( (array) $rows as $r ) {
				if ( $r->m && '0000-00-00 00:00:00' !== $r->m ) {
					$out[ (int) $r->tt ] = gmdate( DATE_W3C, strtotime( $r->m . ' UTC' ) ); }
			}
		}
		return $out;
	}
	private function latest_post_modified( $term ) {
		$query = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => $term->taxonomy,
						'terms'    => array( $term->term_id ),
					),
				),
			)
		);
		return ! empty( $query->posts ) ? SitemapRenderer::post_lastmod( (int) $query->posts[0] ) : '';
	}
}
