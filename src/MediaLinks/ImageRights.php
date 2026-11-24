<?php
/**
 * Image credit and licence metadata (2.26.2).
 *
 * Stored per attachment and printed on ImageObject nodes as creator / creditText / copyrightNotice / license /
 * acquireLicensePage, the properties Google documents for image licence metadata. Falls back to the IPTC credit and
 * copyright WordPress already extracts on upload, so existing libraries gain the data without re-editing.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\MediaLinks;

defined( 'ABSPATH' ) || exit;

final class ImageRights {

	const CREDIT       = '_publishrank_image_credit';
	const CREATOR      = '_publishrank_image_creator';
	const COPYRIGHT    = '_publishrank_image_copyright';
	const LICENSE      = '_publishrank_image_license';
	const LICENSE_PAGE = '_publishrank_image_license_page';

	/** Field name => meta key. */
	public static function keys() {
		return array(
			'credit'       => self::CREDIT,
			'creator'      => self::CREATOR,
			'copyright'    => self::COPYRIGHT,
			'license'      => self::LICENSE,
			'license_page' => self::LICENSE_PAGE,
		);
	}

	/** Raw stored values (no fallback), for editing. @return array<string,string> */
	public static function stored( $attachment_id ) {
		$out = array();
		foreach ( self::keys() as $field => $key ) {
			$out[ $field ] = (string) get_post_meta( (int) $attachment_id, $key, true );
		}
		return $out;
	}

	/** Effective values: stored, else IPTC credit/copyright. @return array<string,string> */
	public static function effective( $attachment_id ) {
		$v    = self::stored( $attachment_id );
		$meta = wp_get_attachment_metadata( (int) $attachment_id );
		$iptc = is_array( $meta ) && isset( $meta['image_meta'] ) && is_array( $meta['image_meta'] ) ? $meta['image_meta'] : array();
		if ( '' === $v['credit'] && ! empty( $iptc['credit'] ) ) {
			$v['credit'] = sanitize_text_field( (string) $iptc['credit'] );
		}
		if ( '' === $v['copyright'] && ! empty( $iptc['copyright'] ) ) {
			$v['copyright'] = sanitize_text_field( (string) $iptc['copyright'] );
		}
		return (array) apply_filters( 'publishrank_image_rights', $v, (int) $attachment_id );
	}

	/**
	 * Save fields that are present in $input. URLs must be http(s).
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,string> Field errors (empty when all saved).
	 */
	public static function save( $attachment_id, array $input ) {
		$errors = array();
		foreach ( self::keys() as $field => $key ) {
			if ( ! array_key_exists( $field, $input ) || ! is_scalar( $input[ $field ] ) ) {
				continue;
			}
			$raw = trim( (string) $input[ $field ] );
			if ( in_array( $field, array( 'license', 'license_page' ), true ) ) {
				$val = '' === $raw ? '' : esc_url_raw( $raw, array( 'http', 'https' ) );
				if ( '' !== $raw && '' === $val ) {
					$errors[ $field ] = __( 'Use a full http:// or https:// address.', 'publishrank' );
					continue;
				}
			} else {
				$val = substr( sanitize_text_field( $raw ), 0, 300 );
			}
			if ( '' === $val ) {
				delete_post_meta( (int) $attachment_id, $key );
			} else {
				update_post_meta( (int) $attachment_id, $key, $val );
			}
		}
		return $errors;
	}

	/** Errors for invalid URL fields, without saving. @return array<string,string> */
	public static function validate( array $input ) {
		$errors = array();
		foreach ( array( 'license', 'license_page' ) as $field ) {
			if ( isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) && '' !== trim( (string) $input[ $field ] ) && '' === esc_url_raw( trim( (string) $input[ $field ] ), array( 'http', 'https' ) ) ) {
				$errors[ $field ] = __( 'Use a full http:// or https:// address.', 'publishrank' );
			}
		}
		return $errors;
	}

	/** Add licence properties to an ImageObject node. @param array<string,mixed> $node */
	public static function apply( array $node, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return $node;
		}
		$v = self::effective( $attachment_id );
		if ( '' !== $v['creator'] ) {
			$node['creator'] = array(
				'@type' => 'Person',
				'name'  => $v['creator'],
			);
		}
		if ( '' !== $v['credit'] ) {
			$node['creditText'] = $v['credit'];
		}
		if ( '' !== $v['copyright'] ) {
			$node['copyrightNotice'] = $v['copyright'];
		}
		if ( '' !== $v['license'] ) {
			$node['license'] = $v['license'];
		}
		if ( '' !== $v['license_page'] ) {
			$node['acquireLicensePage'] = $v['license_page'];
		}
		return $node;
	}

	/** Attachment ID for a URL (cached per request). */
	public static function id_for_url( $url ) {
		static $cache = array();
		$url = (string) $url;
		if ( '' === $url ) {
			return 0;
		}
		if ( ! array_key_exists( $url, $cache ) ) {
			$cache[ $url ] = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
		}
		return $cache[ $url ];
	}
}
