<?php
/**
 * Resolves SEO data for a post: values are fetched automatically from WordPress
 * (title, excerpt/content, permalink, featured image, attachment alt text) and any
 * per-post override saved by an editor always wins.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Meta;

defined( 'ABSPATH' ) || exit;

final class SeoData {
	const DESCRIPTION_LENGTH = 160;

	/** @var array<int,array<string,mixed>> Per-request cache. */
	private static $cache = array();

	/** Forget cached values (used after saves and in tests). */
	public static function flush( $post_id = 0 ) {
		if ( $post_id ) {
			unset( self::$cache[ (int) $post_id ] );
		} else {
			self::$cache = array(); }
	}

	/**
	 * Resolved SEO data for a post, with the source of each value.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return array<string,mixed> Empty array when the post does not exist.
	 */
	public static function for_post( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return array(); }
		if ( isset( self::$cache[ $post->ID ] ) ) {
			return self::$cache[ $post->ID ]; }

		$id   = (int) $post->ID;
		$meta = new MetaGenerator();

		$title_override = (string) PostMeta::get( $id, PostMeta::TITLE, '' );
		$desc_override  = (string) PostMeta::get( $id, PostMeta::DESCRIPTION, '' );
		$canon_override = (string) PostMeta::get( $id, PostMeta::CANONICAL, '' );
		$ogt_override   = (string) PostMeta::get( $id, PostMeta::OG_TITLE, '' );
		$ogd_override   = (string) PostMeta::get( $id, PostMeta::OG_DESCRIPTION, '' );
		$robots_set     = (string) PostMeta::get( $id, PostMeta::ROBOTS, '' );

		$title       = $meta->title_for_post( $id );
		$description = '' !== $desc_override ? self::clean( $desc_override, 320 ) : self::auto_description( $post );
		$canonical   = $meta->canonical_for_post( $id );
		$image       = self::image_for_post( $id );

		$data = array(
			'id'             => $id,
			'title'          => $title,
			'description'    => $description,
			'canonical'      => $canonical,
			'robots'         => '' !== $robots_set ? $robots_set : (string) get_option( 'publishrank_seo_default_robots', 'index,follow' ),
			'og_title'       => '' !== $ogt_override ? self::clean( $ogt_override, 255 ) : $title,
			'og_description' => '' !== $ogd_override ? self::clean( $ogd_override, 320 ) : $description,
			'image'          => $image,
			'sources'        => array(
				'title'       => '' !== $title_override ? 'override' : 'auto',
				'description' => '' !== $desc_override ? 'override' : 'auto',
				'canonical'   => '' !== $canon_override && self::same_host( $canon_override ) ? 'override' : 'auto',
				'robots'      => '' !== $robots_set ? 'override' : 'auto',
				'image'       => isset( $image['source'] ) ? $image['source'] : 'none',
				'image_alt'   => isset( $image['alt_source'] ) ? $image['alt_source'] : 'none',
			),
		);
		/**
		 * Filter the resolved SEO data for a post.
		 *
		 * @param array<string,mixed> $data Resolved data.
		 * @param \WP_Post            $post Post.
		 */
		$data               = (array) apply_filters( 'publishrank_seo_post_data', $data, $post );
		self::$cache[ $id ] = $data;
		return $data;
	}

	/** Automatic meta description: excerpt, else the start of the content. Never leaks protected content. */
	public static function auto_description( $post, $length = self::DESCRIPTION_LENGTH ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return ''; }
		$source = '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : (string) $post->post_content;
		if ( '' === trim( $source ) ) {
			return ''; }
		if ( function_exists( 'excerpt_remove_blocks' ) && '' === trim( (string) $post->post_excerpt ) ) {
			$source = excerpt_remove_blocks( $source );
		}
		return self::truncate( self::clean( $source ), $length );
	}

	/**
	 * Automatic social image: featured image, then the attachment itself, then the first
	 * image in the content. Works for every public post type, not only articles.
	 *
	 * @return array<string,mixed>|null url,id,width,height,type,alt,alt_source,source.
	 */
	public static function image_for_post( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return null; }

		$override = (string) PostMeta::get( $post->ID, PostMeta::OG_IMAGE, '' );
		if ( '' !== $override ) {
			$id             = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $override ) ) : 0;
			$info           = $id ? self::attachment_info( $id, $post ) : array(
				'url'        => $override,
				'id'         => 0,
				'width'      => 0,
				'height'     => 0,
				'type'       => '',
				'alt'        => '',
				'alt_source' => 'none',
			);
			$info['url']    = $override;
			$info['source'] = 'override';
			return self::apply_alt_override( $info, $post );
		}

		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id && wp_attachment_is_image( $thumb_id ) ) {
			$info = self::attachment_info( $thumb_id, $post );
			if ( $info ) {
				$info['source'] = 'featured';
				return self::apply_alt_override( $info, $post ); }
		}
		if ( 'attachment' === $post->post_type && wp_attachment_is_image( $post ) ) {
			$info = self::attachment_info( (int) $post->ID, $post );
			if ( $info ) {
				$info['source'] = 'attachment';
				return self::apply_alt_override( $info, $post ); }
		}
		if ( ! post_password_required( $post ) && '' !== (string) $post->post_content ) {
			$content = (string) $post->post_content;
			if ( preg_match( '/<!--\s*wp:image\s+\{[^}]*"id"\s*:\s*(\d+)/', $content, $m ) || preg_match( '/wp-image-(\d+)/', $content, $m ) ) {
				$id = (int) $m[1];
				if ( $id && wp_attachment_is_image( $id ) ) {
					$info = self::attachment_info( $id, $post );
					if ( $info ) {
						$info['source'] = 'content';
						return self::apply_alt_override( $info, $post ); }
				}
			}
			if ( preg_match( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $content, $m ) ) {
				$url = esc_url_raw( html_entity_decode( $m[1] ) );
				if ( $url ) {
					$id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
					if ( $id ) {
						$info = self::attachment_info( $id, $post );
						if ( $info ) {
							$info['source'] = 'content';
							return self::apply_alt_override( $info, $post ); }
					}
					$alt = preg_match( '/\balt=["\']([^"\']*)["\']/i', $m[0], $a ) ? self::clean( $a[1], 255 ) : '';
					return self::apply_alt_override(
						array(
							'url'        => $url,
							'id'         => 0,
							'width'      => 0,
							'height'     => 0,
							'type'       => '',
							'alt'        => $alt,
							'alt_source' => '' !== $alt ? 'auto' : 'none',
							'source'     => 'content',
						),
						$post
					);
				}
			}
		}
		return null;
	}

	/**
	 * Attachment details: URL, dimensions, MIME type and alt text.
	 * Alt text order: attachment alt, caption, then the post title (so og:image:alt is never empty).
	 *
	 * @return array<string,mixed>
	 */
	public static function attachment_info( $attachment_id, $context_post = null ) {
		$attachment_id = (int) $attachment_id;
		$src           = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! $src || empty( $src[0] ) ) {
			return array(); }
		$alt    = self::clean( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), 255 );
		$source = '' !== $alt ? 'auto' : 'none';
		if ( '' === $alt ) {
			$caption = self::clean( (string) wp_get_attachment_caption( $attachment_id ), 255 );
			if ( '' !== $caption ) {
				$alt    = $caption;
				$source = 'auto'; }
		}
		if ( '' === $alt && $context_post instanceof \WP_Post && (int) $context_post->ID !== $attachment_id ) {
			$alt    = self::clean( get_the_title( $context_post ), 255 );
			$source = '' !== $alt ? 'auto' : 'none';
		}
		$mime = (string) get_post_mime_type( $attachment_id );
		return array(
			'url'        => (string) $src[0],
			'id'         => $attachment_id,
			'width'      => isset( $src[1] ) ? absint( $src[1] ) : 0,
			'height'     => isset( $src[2] ) ? absint( $src[2] ) : 0,
			'type'       => sanitize_text_field( $mime ),
			'alt'        => $alt,
			'alt_source' => $source,
		);
	}

	/** An explicit "social image alt text" override always wins over the automatic alt text. */
	private static function apply_alt_override( $info, $post ) {
		$alt = (string) PostMeta::get( $post->ID, PostMeta::OG_IMAGE_ALT, '' );
		if ( '' !== $alt ) {
			$info['alt']        = self::clean( $alt, 255 );
			$info['alt_source'] = 'override'; }
		return $info;
	}

	/** Plain text: no shortcodes, tags or HTML entities; collapsed whitespace. */
	public static function clean( $text, $max = 0 ) {
		$text = strip_shortcodes( (string) $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( $max > 0 ) {
			$text = self::cut( $text, $max ); }
		return $text;
	}

	/** Truncate on a word boundary and add an ellipsis when text was shortened. */
	public static function truncate( $text, $length = self::DESCRIPTION_LENGTH ) {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $length ) {
			return $text; }
		$cut   = self::cut( $text, $length - 1 );
		$space = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ' ) : strrpos( $cut, ' ' );
		if ( false !== $space && $space > (int) ( $length * 0.6 ) ) {
			$cut = function_exists( 'mb_substr' ) ? mb_substr( $cut, 0, $space ) : substr( $cut, 0, $space ); }
		return rtrim( $cut, " \t\n\r\0\x0B,;:-–—" ) . '…';
	}

	private static function cut( $text, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	private static function same_host( $url ) {
		$home         = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host         = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$primary_url  = \PublishRankSEO\Core\PublicationProfile::primary_url();
		$primary_host = strtolower( (string) wp_parse_url( $primary_url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		return '' === $home || 0 === strcasecmp( $host, $home ) || ( '' !== $primary_host && 0 === strcasecmp( $host, $primary_host ) );
	}
}
