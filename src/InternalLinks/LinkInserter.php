<?php
/** Explicit, editor-approved insertion helpers. Never called automatically. */
namespace PublishRankSEO\InternalLinks;

defined( 'ABSPATH' ) || exit;

final class LinkInserter {
	public static function content_links_to_post( $content, $target_post_id ) {
		if ( ! preg_match_all( '/\bhref\s*=\s*(["\'])(.*?)\1/is', (string) $content, $matches ) ) {
			return false;
		}
		foreach ( (array) $matches[2] as $url ) {
			if ( absint( Scanner::resolve_post_id( $url ) ) === absint( $target_post_id ) ) {
				return true;
			}
		}
		return false;
	}

	public static function insert( $content, $url, $anchor ) {
		$content = (string) $content;
		$url     = trim( (string) $url );
		$anchor  = trim( (string) sanitize_text_field( $anchor ) );
		if ( '' === $url || '' === $anchor ) {
			return new \WP_Error( 'publishrank_invalid_link', __( 'A target URL and anchor text are required.', 'publishrank' ) );
		}
		// Keep the low-level inserter safe as well as the admin workflow: never add
		// a second link to the same target when the source content already links to it.
		$existing_target_id = absint( Scanner::resolve_post_id( $url ) );
		if ( $existing_target_id > 0 && self::content_links_to_post( $content, $existing_target_id ) ) {
			return new \WP_Error( 'publishrank_duplicate_link', __( 'The content already links to this target.', 'publishrank' ) );
		}
		$escaped_url = esc_url( $url );
		if ( '' === $escaped_url ) {
			return new \WP_Error( 'publishrank_invalid_url', __( 'The target URL is invalid.', 'publishrank' ) );
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $anchor, 'UTF-8' ) > 80 ) {
			$anchor = trim( mb_substr( $anchor, 0, 80, 'UTF-8' ) );
		}
		$tag = '<a href="' . $escaped_url . '">' . esc_html( $anchor ) . '</a>';
		if ( preg_match( '/<\/p\s*>/i', $content, $match, PREG_OFFSET_CAPTURE ) ) {
			$position = $match[0][1] + strlen( $match[0][0] );
			return substr( $content, 0, $position ) . "\n\n<p>" . $tag . '</p>' . substr( $content, $position );
		}
		return rtrim( $content ) . "\n\n<p>" . $tag . "</p>\n";
	}
}
