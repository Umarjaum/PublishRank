<?php
/** Optional, cached watermarking for images used by social metadata only. */
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class SocialWatermark {
	/** @return string Original URL when disabled or processing is unavailable. */
	public static function url( $url, array $info = array() ) {
		if ( ! get_option( 'publishrank_social_watermark_enabled', false ) || ! $url || ! function_exists( 'wp_upload_dir' ) ) {
			return (string) $url;
		}
		$source = self::source_path( $url, $info );
		$logo   = self::source_path( (string) get_option( 'publishrank_social_watermark_logo', '' ) );
		if ( ! $source || ! $logo || ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagecopymerge' ) ) {
			return (string) $url;
		}
		$settings = array(
			'logo' => $logo,
			'position' => self::position( get_option( 'publishrank_social_watermark_position', 'bottom-right' ) ),
			'opacity' => self::number( get_option( 'publishrank_social_watermark_opacity', 75 ), 10, 100 ),
			'size' => self::number( get_option( 'publishrank_social_watermark_size', 18 ), 5, 50 ),
			'margin' => self::number( get_option( 'publishrank_social_watermark_margin', 24 ), 0, 300 ),
		);
		$key = md5( $source . '|' . (string) @filemtime( $source ) . '|' . $logo . '|' . (string) @filemtime( $logo ) . '|' . wp_json_encode( $settings ) );
		$uploads = wp_upload_dir();
		$dir = trailingslashit( $uploads['basedir'] ) . 'publishrank-social-watermarks';
		$url_dir = trailingslashit( $uploads['baseurl'] ) . 'publishrank-social-watermarks';
		$ext = self::extension( $source );
		$dest = trailingslashit( $dir ) . $key . '.' . $ext;
		if ( file_exists( $dest ) && is_readable( $dest ) ) { return trailingslashit( $url_dir ) . $key . '.' . $ext; }
		if ( ! wp_mkdir_p( $dir ) ) { return (string) $url; }
		$base = @imagecreatefromstring( (string) @file_get_contents( $source ) );
		$mark = @imagecreatefromstring( (string) @file_get_contents( $logo ) );
		if ( ! $base || ! $mark ) { if ( $base ) { imagedestroy( $base ); } if ( $mark ) { imagedestroy( $mark ); } return (string) $url; }
		$bw = imagesx( $base ); $bh = imagesy( $base ); $mw = imagesx( $mark ); $mh = imagesy( $mark );
		$target_w = max( 1, (int) round( $bw * ( $settings['size'] / 100 ) ) );
		$target_h = max( 1, (int) round( $mh * ( $target_w / max( 1, $mw ) ) ) );
		$scaled = imagecreatetruecolor( $target_w, $target_h );
		imagealphablending( $scaled, false ); imagesavealpha( $scaled, true ); $clear = imagecolorallocatealpha( $scaled, 0, 0, 0, 127 ); imagefilledrectangle( $scaled, 0, 0, $target_w, $target_h, $clear );
		imagecopyresampled( $scaled, $mark, 0, 0, 0, 0, $target_w, $target_h, $mw, $mh );
		$margin = $settings['margin']; $pos = $settings['position'];
		$x = 'left' === $pos[1] ? $margin : ( 'right' === $pos[1] ? $bw - $target_w - $margin : (int) round( ( $bw - $target_w ) / 2 ) );
		$y = 'top' === $pos[0] ? $margin : ( 'bottom' === $pos[0] ? $bh - $target_h - $margin : (int) round( ( $bh - $target_h ) / 2 ) );
		imagecopymerge( $base, $scaled, max( 0, $x ), max( 0, $y ), 0, 0, $target_w, $target_h, $settings['opacity'] );
		$ok = self::save( $base, $dest, $ext );
		imagedestroy( $scaled ); imagedestroy( $mark ); imagedestroy( $base );
		return $ok ? trailingslashit( $url_dir ) . $key . '.' . $ext : (string) $url;
	}
	private static function source_path( $url, array $info = array() ) {
		$id = ! empty( $info['id'] ) ? absint( $info['id'] ) : ( function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0 );
		if ( $id ) { $path = get_attached_file( $id ); if ( $path && is_readable( $path ) ) { return $path; } }
		$uploads = wp_upload_dir(); $base = trailingslashit( $uploads['baseurl'] );
		if ( 0 === strpos( (string) $url, $base ) ) { $path = trailingslashit( $uploads['basedir'] ) . ltrim( substr( (string) $url, strlen( $base ) ), '/' ); return is_readable( $path ) ? $path : ''; }
		return '';
	}
	private static function number( $value, $min, $max ) { return min( $max, max( $min, absint( $value ) ) ); }
	private static function position( $value ) { $value = sanitize_key( $value ); if ( 'center' === $value ) { return array( 'center', 'center' ); } return in_array( $value, array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' ), true ) ? explode( '-', $value ) : array( 'bottom', 'right' ); }
	private static function extension( $path ) { $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ); return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ? ( 'jpeg' === $ext ? 'jpg' : $ext ) : 'jpg'; }
	private static function save( $image, $path, $ext ) { if ( 'png' === $ext ) { return imagepng( $image, $path, 6 ); } if ( 'webp' === $ext && function_exists( 'imagewebp' ) ) { return imagewebp( $image, $path, 88 ); } return imagejpeg( $image, $path, 88 ); }
}
