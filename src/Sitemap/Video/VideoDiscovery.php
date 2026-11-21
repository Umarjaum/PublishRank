<?php
/**
 * Video discovery for sitemaps (2.26.1): HTML5 video, iframes, Gutenberg embed blocks, classic auto-embeds and
 * attached video files. Only facts present in the content are returned; nothing is guessed.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Sitemap\Video;

defined( 'ABSPATH' ) || exit;

final class VideoDiscovery {

	/** YouTube video ID from any common URL form, or ''. */
	public static function youtube_id( $url ) {
		if ( preg_match( '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~', (string) $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	public static function vimeo_id( $url ) {
		if ( preg_match( '~vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d{6,12})~', (string) $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * @return array<int,array{url:string,poster:string,type:string,provider:string}>
	 */
	public static function from_content( $content ) {
		$content = (string) $content;
		$items   = array();
		if ( preg_match_all( '/<video\b([^>]*)>(.*?)<\/video>/is', $content, $videos, PREG_SET_ORDER ) ) {
			foreach ( $videos as $video ) {
				$poster = preg_match( '/\bposter=["\']([^"\']+)["\']/i', $video[1], $m ) ? esc_url_raw( $m[1] ) : '';
				$srcs   = array();
				if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $video[1], $m ) ) {
					$srcs[] = $m[1];
				}
				if ( preg_match_all( '/<source\b[^>]*src=["\']([^"\']+)["\']/i', $video[2], $sources ) ) {
					$srcs = array_merge( $srcs, $sources[1] );
				}
				foreach ( $srcs as $src ) {
					$items[] = array( 'url' => esc_url_raw( $src ), 'poster' => $poster, 'type' => 'content', 'provider' => 'self' );
				}
			}
		}
		$players = array();
		if ( preg_match_all( '/<iframe\b[^>]*src=["\']([^"\']*(?:youtube(?:-nocookie)?\.com|youtu\.be|vimeo\.com)[^"\']*)["\']/i', $content, $iframes ) ) {
			$players = array_merge( $players, $iframes[1] );
		}
		// Gutenberg embed blocks store only the URL; the iframe is produced by oEmbed at render time.
		if ( preg_match_all( '/<!--\s+wp:(?:core-)?embed(?:\/(?:youtube|vimeo))?\s+(\{.*?\})\s+\/?-->/s', $content, $blocks ) ) {
			foreach ( $blocks[1] as $json ) {
				$attrs = json_decode( $json, true );
				if ( is_array( $attrs ) && ! empty( $attrs['url'] ) ) {
					$players[] = (string) $attrs['url'];
				}
			}
		}
		// Classic editor auto-embeds: a video URL alone on its own line.
		if ( preg_match_all( '~^\s*(https?://(?:www\.)?(?:youtube\.com/watch\?[^\s<]+|youtu\.be/[^\s<]+|vimeo\.com/\d+[^\s<]*))\s*$~mi', $content, $bare ) ) {
			$players = array_merge( $players, $bare[1] );
		}
		foreach ( $players as $p ) {
			$p = html_entity_decode( (string) $p, ENT_QUOTES );
			if ( '' !== ( $yt = self::youtube_id( $p ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
				// The official, keyless thumbnail and player addresses YouTube publishes for every public video.
				$items[] = array( 'url' => 'https://www.youtube.com/embed/' . $yt, 'poster' => 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg', 'type' => 'player', 'provider' => 'youtube' );
			} elseif ( '' !== ( $vm = self::vimeo_id( $p ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
				$items[] = array( 'url' => 'https://player.vimeo.com/video/' . $vm, 'poster' => '', 'type' => 'player', 'provider' => 'vimeo' );
			}
		}
		$seen = array();
		$out  = array();
		foreach ( $items as $i ) {
			if ( '' === $i['url'] || isset( $seen[ $i['url'] ] ) ) {
				continue;
			}
			$seen[ $i['url'] ] = true;
			$out[]             = $i;
		}
		return $out;
	}
}
