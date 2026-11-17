<?php
namespace PublishRankSEO\Sitemap\Images;

use PublishRankSEO\Sitemap\Providers\AbstractPostProvider;
use PublishRankSEO\Sitemap\Engine\SitemapRenderer;

defined( 'ABSPATH' ) || exit;

final class ImageSitemapProvider extends AbstractPostProvider {
	/** Media blocks make each URL large; 5,000 URLs per file keeps every file well under the 50 MB limit. */
	protected function shard_size() {
		return 5000; }
	protected $slug       = 'image';
	protected $name       = 'Images';
	protected $post_types = array( 'post', 'page' );
	public function __construct() {
		$this->post_types = $this->media_post_types(); }
	protected function requires_extra() {
		return true; }
	public function is_enabled() {
		return (bool) get_option( 'publishrank_seo_image_sitemap', true ); }
	/** @var array<int,int[]> parent post ID => attached image IDs (prefetched per chunk). */
	private $children = array();
	/** Only posts that can carry an image: featured image, attached image or an <img> in the content. */
	protected function candidate_where() {
		global $wpdb;
		return "( EXISTS (SELECT 1 FROM {$wpdb->postmeta} prx_m WHERE prx_m.post_id = {$wpdb->posts}.ID AND prx_m.meta_key = '_thumbnail_id' AND prx_m.meta_value > 0)"
			. " OR EXISTS (SELECT 1 FROM {$wpdb->posts} prx_a WHERE prx_a.post_parent = {$wpdb->posts}.ID AND prx_a.post_type = 'attachment' AND prx_a.post_mime_type LIKE 'image/%')"
			. " OR {$wpdb->posts}.post_content LIKE '%<img%' )";
	}
	protected function prefetch( array $ids ) {
		global $wpdb;
		$this->children = array();
		$ids            = array_map( 'intval', $ids );
		if ( ! $ids ) {
			return; }
		$rows = $wpdb->get_results( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_parent IN (" . implode( ',', $ids ) . ') ORDER BY menu_order, ID' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		$need = array();
		foreach ( (array) $rows as $r ) {
			$this->children[ (int) $r->post_parent ][] = (int) $r->ID;
			$need[]                                     = (int) $r->ID;
		}
		foreach ( $ids as $id ) {
			$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
			if ( $thumb ) {
				$need[] = $thumb; }
		}
		if ( $need ) {
			_prime_post_caches( array_values( array_unique( $need ) ), false, true ); }
	}
	protected function extra( $post_id ) {
		$ids      = array();
		$featured = get_post_thumbnail_id( $post_id );
		if ( $featured ) {
			$ids[] = $featured; }
		foreach ( isset( $this->children[ $post_id ] ) ? $this->children[ $post_id ] : array() as $attachment_id ) {
			$ids[] = $attachment_id; }
		$images  = array();
		$seen    = array();
		$license = esc_url_raw( (string) get_option( 'publishrank_image_license_url', '' ) );
		foreach ( array_unique( $ids ) as $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( ! $url || isset( $seen[ $url ] ) ) {
				continue;
			} $seen[ $url ] = true;
			$attachment     = get_post( $id );
			$title          = get_the_title( $id );
			$caption        = $attachment instanceof \WP_Post ? ( ! empty( $attachment->post_excerpt ) ? $attachment->post_excerpt : $attachment->post_content ) : '';
			$images[]       = SitemapRenderer::image( $url, $title, wp_trim_words( wp_strip_all_tags( $caption ), 40, '' ), '', $license );
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( array_slice( $matches, 0, 20 ) as $match ) {
				$url = esc_url_raw( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) );
				if ( ! $url || isset( $seen[ $url ] ) ) {
					continue;
				} $seen[ $url ] = true;
				$title          = '';
				if ( preg_match( '/\balt=["\']([^"\']*)["\']/i', $match[0], $alt ) ) {
					$title = sanitize_text_field( $alt[1] ); }
				$images[] = SitemapRenderer::image( $url, $title, '', '', $license );
			}
		}
		return implode( '', array_slice( $images, 0, 20 ) );
	}
	protected function namespaces() {
		return array( 'image' => 'http://www.google.com/schemas/sitemap-image/1.1' ); }
}
