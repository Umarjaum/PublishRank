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
	/** Only posts that can carry a video: <video>, a YouTube/Vimeo iframe, or an attached video file. */
	protected function candidate_where() {
		global $wpdb;
		return "( {$wpdb->posts}.post_content LIKE '%<video%' OR {$wpdb->posts}.post_content LIKE '%youtube.com%' OR {$wpdb->posts}.post_content LIKE '%youtube-nocookie.com%' OR {$wpdb->posts}.post_content LIKE '%youtu.be%' OR {$wpdb->posts}.post_content LIKE '%vimeo.com%'"
			. " OR EXISTS (SELECT 1 FROM {$wpdb->posts} prx_v WHERE prx_v.post_parent = {$wpdb->posts}.ID AND prx_v.post_type = 'attachment' AND prx_v.post_mime_type LIKE 'video/%') )";
	}
	protected function extra( $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		// 2.26.1: shared discovery also finds Gutenberg embed blocks and classic auto-embeds (previously missed).
		$items = VideoDiscovery::from_content( $content );
		foreach ( get_attached_media( 'video', $post_id ) as $attachment ) {
			$url = wp_get_attachment_url( $attachment->ID );
			if ( $url ) {
				$items[] = array( 'url' => $url, 'poster' => '', 'type' => 'content', 'provider' => 'self' );
			}
		}
		$default_thumb = get_post_meta( $post_id, PostMeta::VIDEO_THUMBNAIL, true );
		if ( empty( $default_thumb ) ) {
			$default_thumb = get_the_post_thumbnail_url( $post_id, 'full' );
		}
		// Plain text, decoded once: esc_xml() below escapes exactly once (no "&#038;" in titles).
		$title       = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = html_entity_decode( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_id ) ), 30 ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === trim( $description ) ) {
			$description = $title; // Google requires a non-empty video description.
		}
		$duration = absint( get_post_meta( $post_id, PostMeta::VIDEO_DURATION, true ) );
		$xml      = '';
		$seen     = array();
		foreach ( array_slice( $items, 0, 10 ) as $item ) {
			if ( ! $item['url'] || isset( $seen[ $item['url'] ] ) ) {
				continue;
			}
			$seen[ $item['url'] ] = true;
			$thumb                = ! empty( $item['poster'] ) ? $item['poster'] : $default_thumb;
			if ( ! $thumb ) {
				continue; // A thumbnail is required; never invent one.
			}
			$xml .= '<video:video><video:thumbnail_loc>' . esc_xml( esc_url_raw( $thumb ) ) . '</video:thumbnail_loc><video:title>' . esc_xml( $title ) . '</video:title><video:description>' . esc_xml( $description ) . '</video:description>';
			$xml .= 'player' === $item['type'] ? '<video:player_loc allow_embed="yes">' . esc_xml( esc_url_raw( $item['url'] ) ) . '</video:player_loc>' : '<video:content_loc>' . esc_xml( esc_url_raw( $item['url'] ) ) . '</video:content_loc>';
			if ( $duration && 1 === count( $items ) ) {
				$xml .= '<video:duration>' . esc_xml( (string) min( 28800, $duration ) ) . '</video:duration>'; // The editor's duration describes this post's single video.
			}
			if ( 'self' === $item['provider'] ) {
				// Self-hosted: first published with this post. Third-party videos keep their own (unknown) date, so none is claimed.
				$xml .= '<video:publication_date>' . esc_xml( get_post_time( DATE_W3C, true, $post_id ) ) . '</video:publication_date>';
			}
			$xml .= '</video:video>';
		}
		return $xml;
	}
	protected function namespaces() {
		return array( 'video' => 'http://www.google.com/schemas/sitemap-video/1.1' ); }
}
