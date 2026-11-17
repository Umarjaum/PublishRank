<?php
namespace PublishRankSEO\Sitemap\Providers;

use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;

defined( 'ABSPATH' ) || exit;

final class AuthorSitemapProvider implements SitemapProviderInterface {
	const SHARD_SIZE = 50000;
	public function get_name() {
		return 'Authors'; }
	public function get_slug() {
		return 'author'; }
	public function is_enabled() {
		return (bool) get_option( 'publishrank_sitemap_authors_enabled', true ); }
	/** Shards are counted from authors who actually have published public content, so no file is ever empty. */
	public function get_shard_count() {
		return max( 1, (int) ceil( $this->author_total() / self::SHARD_SIZE ) ); }
	private function public_types() {
		return array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ); }
	public function author_total() {
		global $wpdb;
		$types = array_map( 'sanitize_key', $this->public_types() );
		if ( ! $types ) {
			return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.post_author) FROM {$wpdb->posts} p JOIN {$wpdb->users} u ON u.ID = p.post_author WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', $types ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}
	public function get_lastmod() {
		$q = get_posts(
			array(
				'post_type'        => 'post',
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
	public function prepare_cursor( $steps = 2 ) {
		SitemapCursorStore::prepare_users( $this->get_slug(), $this->query_args(), self::SHARD_SIZE, $steps ); }
	public function render_shard( $shard ) {
		$users   = SitemapCursorStore::user_page( $this->get_slug(), $this->query_args(), $shard, self::SHARD_SIZE );
		$entries = array();
		if ( null === $users ) {
			return SitemapRenderer::urlset( $entries ); }
		global $wpdb;
		$types = array_map( 'sanitize_key', $this->public_types() );
		// One grouped query per 1,000 authors: published-post count and newest modification (was 3 queries per author).
		foreach ( array_chunk( (array) $users, 1000 ) as $chunk ) {
			$ids   = array_map( 'intval', wp_list_pluck( $chunk, 'ID' ) );
			$stats = array();
			if ( $ids && $types ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_author a, MAX(post_modified_gmt) m FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_author IN (' . implode( ',', $ids ) . ') GROUP BY post_author', $types ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				foreach ( (array) $rows as $r ) {
					$stats[ (int) $r->a ] = $r->m; }
			}
			update_meta_cache( 'user', array_keys( $stats ) );
			foreach ( $chunk as $user ) {
				if ( ! isset( $stats[ (int) $user->ID ] ) || false !== strpos( strtolower( (string) get_user_meta( $user->ID, '_publishrank_seo_robots', true ) ), 'noindex' ) ) {
					continue; }
				$url = get_author_posts_url( $user->ID, $user->user_nicename );
				if ( ! $url ) {
					continue; }
				$m         = $stats[ (int) $user->ID ];
				$lastmod   = $m && '0000-00-00 00:00:00' !== $m ? gmdate( DATE_W3C, strtotime( $m . ' UTC' ) ) : '';
				$entries[] = SitemapRenderer::url( $url, $lastmod );
			}
		}
		return SitemapRenderer::urlset( $entries );
	}
	private function query_args() {
		return array(
			'has_published_posts' => true,
			'fields'              => array( 'ID', 'user_nicename' ),
		); }
}
