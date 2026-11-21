<?php
namespace PublishRankSEO\Sitemap\Providers;

use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;

defined( 'ABSPATH' ) || exit;

final class AttachmentSitemapProvider implements SitemapProviderInterface {
	const SHARD_SIZE = 50000;
	public function get_name() {
		return 'Attachments'; }
	public function get_slug() {
		return 'attachment'; }
	public function is_enabled() {
		return (bool) get_option( 'publishrank_sitemap_attachments_enabled', false ); }
	public function get_shard_count() {
		$counts = wp_count_posts( 'attachment' );
		$total  = $counts && isset( $counts->inherit ) ? (int) $counts->inherit : 0;
		return max( 1, (int) ceil( $total / self::SHARD_SIZE ) );
	}
	public function prepare_cursor( $steps = 2 ) {
		SitemapCursorStore::prepare_posts( $this->get_slug(), $this->query_args(), self::SHARD_SIZE, $steps ); }
	/**
	 * Images are listed the way Google's image sitemap expects: each entry is the web page the image appears on
	 * (the page it is attached to), with the image nested inside it. The image file itself is never a page address,
	 * and attachment pages redirect to the file, so neither is listed as a page. Images that are not attached to a
	 * published, indexable page have no page to belong to and are left out; the Images sitemap already covers
	 * featured images and images used in content.
	 */
	public function render_shard( $shard ) {
		$ids = SitemapCursorStore::post_page( $this->get_slug(), $this->query_args(), $shard, self::SHARD_SIZE );
		if ( null === $ids ) {
			return SitemapRenderer::urlset( array(), $this->namespaces() ); }
		$license = esc_url_raw( (string) get_option( 'publishrank_image_license_url', '' ) );
		$pages   = array(); // Parent ID => page address ('' when the parent cannot be listed).
		$groups  = array(); // Parent ID => image entries.
		foreach ( $ids as $id ) {
			$parent_id = (int) wp_get_post_parent_id( $id );
			if ( $parent_id <= 0 ) {
				continue; }
			if ( ! isset( $pages[ $parent_id ] ) ) {
				$pages[ $parent_id ] = $this->parent_url( $parent_id ); }
			if ( '' === $pages[ $parent_id ] ) {
				continue; }
			$image_url = wp_get_attachment_image_url( $id, 'full' );
			if ( ! $image_url ) {
				continue; }
			if ( isset( $groups[ $parent_id ] ) && count( $groups[ $parent_id ] ) >= 1000 ) {
				continue; } // Google reads at most 1,000 images per page address.
			$attachment = get_post( $id );
			$caption    = $attachment instanceof \WP_Post ? ( ! empty( $attachment->post_excerpt ) ? $attachment->post_excerpt : $attachment->post_content ) : '';
			$groups[ $parent_id ][] = SitemapRenderer::image( $image_url, get_the_title( $id ), wp_trim_words( wp_strip_all_tags( $caption ), 40, '' ), '', $license );
		}
		$entries = array();
		foreach ( $groups as $parent_id => $images ) {
			$entries[] = SitemapRenderer::url( $pages[ $parent_id ], SitemapRenderer::post_lastmod( $parent_id ), implode( '', $images ) );
		}
		return SitemapRenderer::urlset( $entries, $this->namespaces() );
	}
	/** @return array<string,string> */
	private function namespaces() {
		return array( 'image' => 'http://www.google.com/schemas/sitemap-image/1.1' ); }
	/** Public address of a page an image is attached to, or '' when that page should not be listed. */
	private function parent_url( $parent_id ) {
		$parent = get_post( $parent_id );
		if ( ! $parent instanceof \WP_Post || 'publish' !== $parent->post_status || '' !== (string) $parent->post_password || 'attachment' === $parent->post_type ) {
			return ''; }
		$type = get_post_type_object( $parent->post_type );
		if ( ! $type || ! $type->public ) {
			return ''; }
		if ( false !== strpos( strtolower( (string) get_post_meta( $parent_id, '_publishrank_seo_robots', true ) ), 'noindex' ) ) {
			return ''; }
		$url    = (string) get_permalink( $parent );
		$parsed = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( empty( $parsed['host'] ) || empty( $home['host'] ) || strtolower( $parsed['host'] ) !== strtolower( $home['host'] ) ) {
			return ''; }
		return $url;
	}
	private function query_args() {
		return array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
		); }
}
