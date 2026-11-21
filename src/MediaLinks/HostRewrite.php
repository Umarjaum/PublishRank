<?php
/**
 * Optional front-end media host rewriting. PublishRank's existing "Media host" and "CDN host" settings already
 * apply to social images and schema; when the publisher also opts in here, attachment URLs, srcset entries and
 * uploads URLs inside post content are served from that host. Nothing is rewritten in the database, so turning
 * the option off restores the original addresses immediately.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\MediaLinks;

use PublishRankSEO\Core\PublicationProfile;

defined( 'ABSPATH' ) || exit;

final class HostRewrite {
	const OPTION = 'publishrank_media_host_content';

	public static function register() {
		if ( ! get_option( self::OPTION, false ) || is_admin() ) {
			return;
		}
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'url' ), 99 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'srcset' ), 99 );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 99 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'content' ), 99 );
	}

	private static function active() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() || is_customize_preview() ) {
			return false;
		}
		return true;
	}

	/** Mapped URL for one uploads URL, or the URL unchanged. */
	public static function map( $url ) {
		$url = (string) $url;
		$up  = Scanner::uploads();
		if ( '' === $url || ( 0 !== strpos( set_url_scheme( $url ), $up['baseurl'] . '/' ) ) ) {
			return $url;
		}
		$out = PublicationProfile::asset_url( $url );
		return $out ? $out : $url;
	}

	public static function url( $url ) {
		return self::active() ? self::map( $url ) : $url;
	}

	public static function srcset( $sources ) {
		if ( ! self::active() || ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $w => $s ) {
			if ( isset( $s['url'] ) ) {
				$sources[ $w ]['url'] = self::map( $s['url'] );
			}
		}
		return $sources;
	}

	public static function content( $html ) {
		if ( ! self::active() || ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$up   = Scanner::uploads();
		$test = self::map( $up['baseurl'] . '/x.jpg' );
		if ( $test === $up['baseurl'] . '/x.jpg' ) {
			return $html;
		}
		$new_base = substr( $test, 0, -strlen( '/x.jpg' ) );
		$map      = array();
		foreach ( Scanner::spellings( $up['baseurl'] ) as $i => $sp ) {
			if ( $i < 3 ) {
				$map[ $sp . '/' ] = $new_base . '/';
			}
		}
		return strtr( $html, $map );
	}

	/** Compare a sample image through the configured host with the local original. */
	public static function test() {
		$ids = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ) );
		if ( ! $ids ) {
			return array( 'status' => 'warn', 'detail' => __( 'Upload an image first so there is something to test.', 'publishrank' ) );
		}
		$files = Scanner::files( (int) $ids[0] );
		if ( ! $files ) {
			return array( 'status' => 'warn', 'detail' => __( 'The sample image has no local file.', 'publishrank' ) );
		}
		$local  = $files[0]['url'];
		$mapped = PublicationProfile::asset_url( $local );
		if ( $mapped === $local ) {
			return array( 'status' => 'warn', 'local' => $local, 'mapped' => $mapped, 'detail' => __( 'No media host or CDN host is set (or it points to a local address), so URLs are not changed.', 'publishrank' ) );
		}
		$r    = Migrator::http_check( $mapped, (string) get_post_mime_type( (int) $ids[0] ) );
		$size = file_exists( $files[0]['path'] ) ? (int) filesize( $files[0]['path'] ) : 0;
		return array_merge( $r, array( 'local' => $local, 'mapped' => $mapped, 'local_size' => $size ) );
	}
}
