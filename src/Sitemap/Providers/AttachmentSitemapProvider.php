<?php
namespace PublishRankSEO\Sitemap\Providers;

use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;

defined( 'ABSPATH' ) || exit;

final class AttachmentSitemapProvider implements SitemapProviderInterface {
	const SHARD_SIZE = 50000;
	public function get_name() { return 'Attachments'; }
	public function get_slug() { return 'attachment'; }
	public function is_enabled() { return (bool) get_option( 'publishrank_sitemap_attachments_enabled', false ); }
	public function get_shard_count() {
		$counts = wp_count_posts( 'attachment' );
		$total = $counts && isset( $counts->inherit ) ? (int) $counts->inherit : 0;
		return max( 1, (int) ceil( $total / self::SHARD_SIZE ) );
	}
	public function prepare_cursor( $steps = 2 ) { SitemapCursorStore::prepare_posts( $this->get_slug(), $this->query_args(), self::SHARD_SIZE, $steps ); }
	public function render_shard( $shard ) {
		$ids = SitemapCursorStore::post_page( $this->get_slug(), $this->query_args(), $shard, self::SHARD_SIZE );
		$entries = array();
		if ( null === $ids ) { return SitemapRenderer::urlset( $entries ); }
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_url( $id );
			if ( $url ) { $entries[] = SitemapRenderer::url( $url, get_post_modified_time( DATE_W3C, true, $id ) ); }
		}
		return SitemapRenderer::urlset( $entries );
	}
	private function query_args() { return array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image' ); }
}
