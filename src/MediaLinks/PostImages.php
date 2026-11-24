<?php
/**
 * Images one post uses, with their role(s), for the Media tab of both SEO editors.
 *
 * Roles: featured image, social image (_publishrank_seo_og_image), author image, video thumbnail, and images in
 * the content (core/image, core/gallery, core/cover blocks and <img> tags with a wp-image-ID class or an uploads
 * URL). The editor passes its unsaved featured image, social image and content so the list follows unsaved edits.
 *
 * Attachment metadata (alt text, title, caption, description) is edited through a separate, narrowly permissioned
 * endpoint with optimistic concurrency. File URL changes never happen here: they reuse the Media Link Manager's
 * dry-run / apply / rollback flow.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\MediaLinks;

use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class PostImages {
	const MAX_ITEMS = 100;

	/**
	 * Build the image list.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $state   Optional unsaved editor state: featured (int), og_image (string), content (string).
	 * @return array{post:int,items:array,permissions:array}
	 */
	public static function collect( $post_id, array $state = array() ) {
		$post_id = (int) $post_id;
		$items   = array();
		$add     = static function ( $id, $url, $role ) use ( &$items ) {
			$id  = (int) $id;
			$url = trim( (string) $url );
			if ( ! $id && '' !== $url ) {
				$id = (int) Library::id_from_url( $url );
			}
			if ( $id && 'attachment' !== get_post_type( $id ) ) {
				$id = 0;
			}
			$key = $id ? 'a' . $id : 'u' . md5( strtok( $url, '?#' ) );
			if ( ! $id && '' === $url ) {
				return;
			}
			if ( ! isset( $items[ $key ] ) ) {
				if ( count( $items ) >= self::MAX_ITEMS ) {
					return;
				}
				$items[ $key ] = $id ? self::attachment_row( $id ) : self::external_row( $url );
			}
			if ( ! in_array( $role, $items[ $key ]['roles'], true ) ) {
				$items[ $key ]['roles'][] = $role;
			}
			$items[ $key ]['uses']++;
		};

		$featured = array_key_exists( 'featured', $state ) && null !== $state['featured'] ? (int) $state['featured'] : (int) get_post_thumbnail_id( $post_id );
		if ( $featured ) {
			$add( $featured, '', 'featured' );
		}
		$og = array_key_exists( 'og_image', $state ) && null !== $state['og_image'] ? (string) $state['og_image'] : (string) get_post_meta( $post_id, PostMeta::OG_IMAGE, true );
		if ( '' !== trim( $og ) ) {
			$add( 0, $og, 'social' );
		}
		foreach ( array( PostMeta::AUTHOR_IMAGE => 'author', PostMeta::VIDEO_THUMBNAIL => 'video_thumbnail' ) as $key => $role ) {
			$u = (string) get_post_meta( $post_id, $key, true );
			if ( '' !== trim( $u ) ) {
				$add( 0, $u, $role );
			}
		}
		$content = array_key_exists( 'content', $state ) && null !== $state['content'] ? (string) $state['content'] : (string) get_post_field( 'post_content', $post_id );
		foreach ( self::content_images( $content ) as $img ) {
			$add( $img['id'], $img['url'], 'content' );
		}
		return array(
			'post'        => $post_id,
			'items'       => array_values( $items ),
			'permissions' => array(
				'edit_metadata' => Capabilities::can_edit_seo( $post_id ),
				'change_urls'   => Capabilities::can( 'manage_media_links' ) && current_user_can( 'upload_files' ),
				'site_wide'     => Capabilities::can( 'manage_settings' ),
			),
			'links'       => array(
				'manager' => admin_url( 'admin.php?page=publishrank-media-links' ),
			),
		);
	}

	/**
	 * Image references in post content.
	 *
	 * @return array<int,array{id:int,url:string}>
	 */
	public static function content_images( $content ) {
		$out     = array();
		$content = (string) $content;
		if ( '' === $content ) {
			return $out;
		}
		if ( function_exists( 'parse_blocks' ) && false !== strpos( $content, '<!-- wp:' ) ) {
			$walk = static function ( $blocks ) use ( &$walk, &$out ) {
				foreach ( (array) $blocks as $b ) {
					$name  = isset( $b['blockName'] ) ? (string) $b['blockName'] : '';
					$attrs = isset( $b['attrs'] ) && is_array( $b['attrs'] ) ? $b['attrs'] : array();
					if ( in_array( $name, array( 'core/image', 'core/cover', 'core/media-text' ), true ) ) {
						$id = isset( $attrs['id'] ) ? (int) $attrs['id'] : ( isset( $attrs['mediaId'] ) ? (int) $attrs['mediaId'] : 0 );
						$url = isset( $attrs['url'] ) ? (string) $attrs['url'] : ( isset( $attrs['mediaUrl'] ) ? (string) $attrs['mediaUrl'] : '' );
						if ( $id || $url ) {
							$out[] = array( 'id' => $id, 'url' => $url );
						}
					}
					if ( 'core/gallery' === $name && ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
						foreach ( $attrs['ids'] as $gid ) {
							$out[] = array( 'id' => (int) $gid, 'url' => '' );
						}
					}
					if ( ! empty( $b['innerBlocks'] ) ) {
						$walk( $b['innerBlocks'] );
					}
				}
			};
			$walk( parse_blocks( $content ) );
		}
		// Classic content and anything the blocks did not describe: <img> tags.
		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$id  = preg_match( '/\bwp-image-(\d+)\b/', $tag, $c ) ? (int) $c[1] : 0;
				$url = preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/i', $tag, $s ) ? html_entity_decode( $s[2], ENT_QUOTES ) : '';
				if ( $id || $url ) {
					$out[] = array( 'id' => $id, 'url' => $url );
				}
			}
		}
		return $out;
	}

	/** @return array Row for a local attachment. */
	public static function attachment_row( $id ) {
		$post = get_post( $id );
		$meta = wp_get_attachment_metadata( $id );
		$refs = get_post_meta( $id, '_publishrank_media_refs', true );
		return array(
			'id'          => (int) $id,
			'local'       => true,
			'url'         => (string) wp_get_attachment_url( $id ),
			'thumb'       => (string) wp_get_attachment_image_url( $id, 'thumbnail', true ),
			'mime'        => $post ? (string) $post->post_mime_type : '',
			'width'       => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'      => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'       => $post ? (string) $post->post_title : '',
			'caption'     => $post ? (string) $post->post_excerpt : '',
			'description' => $post ? (string) $post->post_content : '',
			'modified'    => $post ? (string) $post->post_modified_gmt : '',
			'rights'      => ImageRights::stored( $id ),
			'refs'        => is_array( $refs ) && isset( $refs['total'] ) ? (int) $refs['total'] : null,
			'can_edit'    => current_user_can( 'edit_post', $id ) && Capabilities::can( 'edit_seo' ),
			'edit'        => (string) get_edit_post_link( $id, 'raw' ),
			'roles'       => array(),
			'uses'        => 0,
		);
	}

	/** @return array Row for an image that is not in the Media Library. */
	public static function external_row( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return array(
			'id'          => 0,
			'local'       => false,
			'external'    => '' !== $host && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'url'         => esc_url_raw( $url ),
			'thumb'       => esc_url_raw( $url ),
			'mime'        => (string) wp_check_filetype( strtok( $url, '?#' ) )['type'],
			'width'       => 0,
			'height'      => 0,
			'alt'         => '',
			'title'       => '',
			'caption'     => '',
			'description' => '',
			'modified'    => '',
			'refs'        => null,
			'can_edit'    => false,
			'edit'        => '',
			'roles'       => array(),
			'uses'        => 0,
		);
	}

	/**
	 * Update attachment metadata with optimistic concurrency.
	 *
	 * @param int   $id     Attachment ID.
	 * @param array $fields alt, title, caption, description (any subset) and modified (post_modified_gmt seen by the client).
	 * @return array|\WP_Error Updated row.
	 */
	public static function update_metadata( $id, array $fields ) {
		$id   = (int) $id;
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'publishrank_media_missing', __( 'Attachment not found.', 'publishrank' ), array( 'status' => 404 ) );
		}
		if ( isset( $fields['modified'] ) && '' !== (string) $fields['modified'] && (string) $fields['modified'] !== (string) $post->post_modified_gmt ) {
			return new \WP_Error(
				'publishrank_media_conflict',
				__( 'Someone else changed this image after you opened it. Review the current values and save again.', 'publishrank' ),
				array( 'status' => 409, 'current' => self::attachment_row( $id ) )
			);
		}
		$rights = array_intersect_key( $fields, ImageRights::keys() );
		if ( $rights ) {
			$errors = ImageRights::validate( $rights );
			if ( $errors ) {
				return new \WP_Error( 'publishrank_media_rights', reset( $errors ), array( 'status' => 400, 'fields' => $errors ) );
			}
		}
		if ( array_key_exists( 'alt', $fields ) && null !== $fields['alt'] ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $fields['alt'] ) );
		}
		$update = array();
		if ( array_key_exists( 'title', $fields ) && null !== $fields['title'] ) {
			$update['post_title'] = sanitize_text_field( (string) $fields['title'] );
		}
		if ( array_key_exists( 'caption', $fields ) && null !== $fields['caption'] ) {
			$update['post_excerpt'] = wp_kses_post( (string) $fields['caption'] );
		}
		if ( array_key_exists( 'description', $fields ) && null !== $fields['description'] ) {
			$update['post_content'] = wp_kses_post( (string) $fields['description'] );
		}
		if ( $update ) {
			$update['ID'] = $id;
			$res          = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		} elseif ( array_key_exists( 'alt', $fields ) || $rights ) {
			// Alt text alone does not touch the post row; bump the modified time so concurrent editors notice.
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $id );
		}
		if ( $rights ) {
			ImageRights::save( $id, $rights );
		}
		clean_post_cache( $id );
		return self::attachment_row( $id );
	}

	/**
	 * Validate an external image URL: http/https only, a real host, optionally reachable.
	 *
	 * @return array{ok:bool,url:string,message:string,status:int}
	 */
	public static function check_url( $url, $verify = false ) {
		$url    = trim( (string) $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( '' === $url || ! in_array( $scheme, array( 'http', 'https' ), true ) || ! wp_http_validate_url( $url ) ) {
			return array( 'ok' => false, 'url' => '', 'message' => __( 'Use a full http:// or https:// image address.', 'publishrank' ), 'status' => 0 );
		}
		$out = array( 'ok' => true, 'url' => esc_url_raw( $url ), 'message' => '', 'status' => 0, 'local' => (bool) Library::id_from_url( $url ) );
		if ( $verify ) {
			$check          = Migrator::http_check( $url, 'image/*' );
			$out['status']  = isset( $check['code'] ) ? (int) $check['code'] : 0;
			$out['ok']      = isset( $check['status'] ) && 'fail' !== $check['status'];
			$out['message'] = isset( $check['detail'] ) ? (string) $check['detail'] : '';
		}
		return $out;
	}
}
