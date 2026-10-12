<?php
namespace PublishRankSEO\Sitemap\Providers;

use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;

defined( 'ABSPATH' ) || exit;

final class AuthorSitemapProvider implements SitemapProviderInterface {
	const SHARD_SIZE = 50000;
	public function get_name() { return 'Authors'; }
	public function get_slug() { return 'author'; }
	public function is_enabled() { return (bool) get_option( 'publishrank_sitemap_authors_enabled', true ); }
	public function get_shard_count() { $counts = count_users(); return max( 1, (int) ceil( (int) ( isset( $counts['total_users'] ) ? $counts['total_users'] : 0 ) / self::SHARD_SIZE ) ); }
	public function get_lastmod() { return ''; }
	public function prepare_cursor( $steps = 2 ) { SitemapCursorStore::prepare_users( $this->get_slug(), $this->query_args(), self::SHARD_SIZE, $steps ); }
	public function render_shard( $shard ) {
		$users = SitemapCursorStore::user_page( $this->get_slug(), $this->query_args(), $shard, self::SHARD_SIZE );
		$entries = array();
		if ( null === $users ) { return SitemapRenderer::urlset( $entries ); }
		foreach ( $users as $user ) {
			$url = get_author_posts_url( $user->ID, $user->user_nicename );
			if ( ! $url || ! count_user_posts( $user->ID, get_post_types( array( 'public' => true ) ) ) ) { continue; }
			$latest = new \WP_Query( array( 'author' => $user->ID, 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'fields' => 'ids' ) );
			$lastmod = ! empty( $latest->posts ) ? get_post_modified_time( DATE_W3C, true, (int) $latest->posts[0] ) : '';
			$entries[] = SitemapRenderer::url( $url, $lastmod );
		}
		return SitemapRenderer::urlset( $entries );
	}
		private function query_args() { return array( 'has_published_posts' => true, 'fields' => array( 'ID', 'user_nicename' ) ); }
}
