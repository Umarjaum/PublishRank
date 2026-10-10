<?php
namespace PublishRankSEO\Sitemap\Images;

use PublishRankSEO\Sitemap\Providers\AbstractPostProvider;
use PublishRankSEO\Sitemap\Engine\SitemapRenderer;

final class ImageSitemapProvider extends AbstractPostProvider {
	protected $slug = 'image'; protected $name = 'Images'; protected $post_types = array( 'post' );
	public function is_enabled() { return (bool) get_option( 'publishrank_seo_image_sitemap', true ); }
	protected function extra( $post_id ) {
		$ids = array(); $featured = get_post_thumbnail_id( $post_id ); if ( $featured ) { $ids[] = $featured; }
		foreach ( get_attached_media( 'image', $post_id ) as $attachment ) { $ids[] = $attachment->ID; }
		$images = array(); $seen = array(); $license = esc_url_raw( (string) get_option( 'publishrank_image_license_url', '' ) );
		foreach ( array_unique( $ids ) as $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' ); if ( ! $url || isset( $seen[ $url ] ) ) { continue; } $seen[ $url ] = true;
			$attachment = get_post( $id ); $title = get_the_title( $id ); $caption = $attachment instanceof \WP_Post ? ( $attachment->post_excerpt ?: $attachment->post_content ) : '';
			$images[] = SitemapRenderer::image( $url, $title, wp_trim_words( wp_strip_all_tags( $caption ), 40, '' ), '', $license );
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( array_slice( $matches, 0, 20 ) as $match ) {
				$url = esc_url_raw( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) );
				if ( ! $url || isset( $seen[ $url ] ) ) { continue; } $seen[ $url ] = true;
				$title = ''; if ( preg_match( '/\balt=["\']([^"\']*)["\']/i', $match[0], $alt ) ) { $title = sanitize_text_field( $alt[1] ); }
				$images[] = SitemapRenderer::image( $url, $title, '', '', $license );
			}
		}
		return implode( '', array_slice( $images, 0, 20 ) );
	}
	protected function namespaces() { return array( 'image' => 'http://www.google.com/schemas/sitemap-image/1.1' ); }
}
