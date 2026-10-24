<?php
namespace PublishRankSEO\Sitemap\Video;

use PublishRankSEO\Sitemap\Providers\AbstractPostProvider;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class VideoSitemapProvider extends AbstractPostProvider {
	/** Media blocks make each URL large; 5,000 URLs per file keeps every file well under the 50 MB limit. */
	protected function shard_size() {
		return 5000; }
	protected $slug       = 'video';
	protected $name       = 'Videos';
	protected $post_types = array( 'post', 'page' );
	public function __construct() {
		$this->post_types = $this->media_post_types(); }
	protected function requires_extra() {
		return true; }
	public function is_enabled() {
		return (bool) get_option( 'publishrank_seo_video_sitemap', true ); }
	protected function extra( $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		$items   = array();
		if ( preg_match_all( '/<video\b([^>]*)>(.*?)<\/video>/is', $content, $videos, PREG_SET_ORDER ) ) {
			foreach ( $videos as $video ) {
				$poster = '';
				if ( preg_match( '/\bposter=["\']([^"\']+)["\']/i', $video[1], $m ) ) {
					$poster = esc_url_raw( $m[1] );
				} if ( preg_match_all( '/<source\b[^>]*src=["\']([^"\']+)["\']/i', $video[2], $sources ) ) {
					foreach ( $sources[1] as $source ) {
						$items[] = array(
							'url'    => esc_url_raw( $source ),
							'poster' => $poster,
							'type'   => 'content',
						); }
				}
			}
		}
		if ( preg_match_all( '/<iframe\b[^>]*src=["\']([^"\']*(?:youtube\.com|youtu\.be|vimeo\.com)[^"\']*)["\']/i', $content, $iframes ) ) {
			foreach ( $iframes[1] as $frame ) {
				$items[] = array(
					'url'    => esc_url_raw( $frame ),
					'poster' => '',
					'type'   => 'player',
				); }
		}
		foreach ( get_attached_media( 'video', $post_id ) as $attachment ) {
			$url = wp_get_attachment_url( $attachment->ID );
			if ( $url ) {
				$items[] = array(
					'url'    => $url,
					'poster' => '',
					'type'   => 'content',
				); }
		}
		$default_thumb = get_post_meta( $post_id, PostMeta::VIDEO_THUMBNAIL, true );
		if ( empty( $default_thumb ) ) {
			$default_thumb = get_the_post_thumbnail_url( $post_id, 'full' );
		}
		if ( ! $default_thumb ) {
			return ''; }
		$title       = wp_strip_all_tags( get_the_title( $post_id ) );
		$description = wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_id ) ), 30 );
		$duration    = absint( get_post_meta( $post_id, PostMeta::VIDEO_DURATION, true ) );
		$xml         = '';
		$seen        = array();
		foreach ( array_slice( $items, 0, 10 ) as $item ) {
			if ( ! $item['url'] || isset( $seen[ $item['url'] ] ) ) {
				continue;
			} $seen[ $item['url'] ] = true;
			$thumb                  = ! empty( $item['poster'] ) ? $item['poster'] : $default_thumb;
			$xml                   .= '<video:video><video:thumbnail_loc>' . esc_xml( esc_url_raw( $thumb ) ) . '</video:thumbnail_loc><video:title>' . esc_xml( $title ) . '</video:title><video:description>' . esc_xml( $description ) . '</video:description>';
			$xml                   .= 'player' === $item['type'] ? '<video:player_loc allow_embed="yes">' . esc_xml( esc_url_raw( $item['url'] ) ) . '</video:player_loc>' : '<video:content_loc>' . esc_xml( esc_url_raw( $item['url'] ) ) . '</video:content_loc>';
			if ( $duration ) {
				$xml .= '<video:duration>' . esc_xml( (string) min( 28800, $duration ) ) . '</video:duration>';
			} $xml .= '<video:publication_date>' . esc_xml( get_post_time( DATE_W3C, true, $post_id ) ) . '</video:publication_date></video:video>'; }
		return $xml;
	}
	protected function namespaces() {
		return array( 'video' => 'http://www.google.com/schemas/sitemap-video/1.1' ); }
}
