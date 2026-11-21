<?php
/**
 * 404 Monitor intelligence (2.26.1): root-cause diagnosis, high-confidence fixes and referring-page tracking.
 *
 * Every rule is local and deterministic: no external service is called. A diagnosis explains *why* an address is
 * missing (typo-like junk, wrong case, a trashed post, a moved media file, pagination past the end, an old date
 * permalink…) and, when it can be resolved with certainty, proposes the exact target.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Monitor;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class NotFoundDiagnosis {

	/* ------------------------------------------------------------ Referring pages */

	/** Store referrers for buffered hits: one row per (missing path, referring page). Called from NotFoundStore::flush(). */
	public static function store_refs( array $buffer ) {
		global $wpdb;
		if ( ! Database::ready( '404_refs' ) ) {
			return;
		}
		$t    = Database::table( '404_refs' );
		$now  = current_time( 'mysql', true );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		foreach ( $buffer as $h => $b ) {
			foreach ( isset( $b['refs'] ) ? (array) $b['refs'] : array() as $key => $ref ) {
				$internal = $ref['h'] === $home ? 1 : 0;
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"INSERT INTO {$t} (path_hash, ref_hash, ref_host, ref_path, internal, hits, first_seen, last_seen) VALUES (%s, %s, %s, %s, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), last_seen = VALUES(last_seen)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$h,
						md5( $key ),
						$ref['h'],
						$ref['p'],
						$internal,
						(int) $ref['n'],
						$now,
						$now
					)
				);
			}
		}
	}

	/** @return array<int,array<string,mixed>> Referring pages of one missing path. */
	public static function refs( $path_hash, $limit = 10 ) {
		global $wpdb;
		if ( ! Database::ready( '404_refs' ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ref_host, ref_path, internal, hits, last_seen FROM ' . Database::table( '404_refs' ) . ' WHERE path_hash = %s ORDER BY hits DESC LIMIT %d', $path_hash, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $i => $r ) {
			$rows[ $i ] += self::source_post( $r );
		}
		return (array) $rows;
	}

	/** The post behind an internal referring page, with edit link. */
	private static function source_post( array $r ) {
		if ( empty( $r['internal'] ) ) {
			return array( 'post_id' => 0, 'title' => '', 'edit' => '' );
		}
		$id = url_to_postid( home_url( (string) $r['ref_path'] ) );
		if ( ! $id && '/' === $r['ref_path'] ) {
			$id = (int) get_option( 'page_on_front' );
		}
		return array(
			'post_id' => (int) $id,
			'title'   => $id ? html_entity_decode( get_the_title( $id ), ENT_QUOTES ) : '',
			'edit'    => $id && current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id, 'raw' ) : '',
		);
	}

	/**
	 * Broken links on this site: internal pages that sent visitors to a missing address.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function internal_broken( $limit = 100 ) {
		global $wpdb;
		if ( ! Database::ready( '404_refs' ) || ! Database::ready( '404' ) ) {
			return array();
		}
		$r = Database::table( '404_refs' );
		$t = Database::table( '404' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT n.id, n.path, n.status, n.path_hash, r.ref_path, r.hits, r.last_seen, r.ref_host, r.internal FROM {$r} r JOIN {$t} n ON n.path_hash = r.path_hash WHERE r.internal = 1 AND n.status <> 'ignored' AND n.ua_class <> 'scanner' ORDER BY r.hits DESC LIMIT %d", (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $i => $row ) {
			$rows[ $i ] += self::source_post( $row );
			$rows[ $i ]['in_content'] = $rows[ $i ]['post_id'] ? false !== strpos( (string) get_post_field( 'post_content', $rows[ $i ]['post_id'] ), (string) $row['path'] ) : false;
		}
		return (array) $rows;
	}

	/* ------------------------------------------------------------ Diagnosis */

	/**
	 * Explain a missing path.
	 *
	 * @return array{type:string,label:string,hint:string,target:string,confidence:int}
	 */
	public static function diagnose( $path ) {
		$path = (string) $path;
		$out  = static function ( $type, $label, $hint, $target = '', $confidence = 0 ) {
			return array( 'type' => $type, 'label' => $label, 'hint' => $hint, 'target' => $target, 'confidence' => (int) $confidence );
		};
		$up   = wp_get_upload_dir();
		$base = (string) wp_parse_url( (string) $up['baseurl'], PHP_URL_PATH );
		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			$rel = rawurldecode( substr( $path, strlen( $base ) + 1 ) );
			$id  = class_exists( '\\PublishRankSEO\\MediaLinks\\Library' ) ? \PublishRankSEO\MediaLinks\Library::id_from_url( home_url( $path ) ) : 0;
			if ( $id ) {
				$url = (string) wp_get_attachment_url( $id );
				if ( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) === untrailingslashit( $path ) ) {
					return $out( 'media_lost', __( 'Attachment file missing from disk', 'publishrank' ), __( 'The media library still points here but the file is gone. Re-upload it or replace it in the Media Link Manager.', 'publishrank' ) );
				}
				return $out( 'media_moved', __( 'Media file moved', 'publishrank' ), __( 'This file belongs to an attachment whose file now lives elsewhere. Check it in the Media Link Manager.', 'publishrank' ), wp_make_link_relative( $url ), 90 );
			}
			unset( $rel );
			return $out( 'media_missing', __( 'Missing media file', 'publishrank' ), __( 'No attachment owns this uploads address. Run the Media health scan to find pages that still link to it.', 'publishrank' ) );
		}
		// Typing junk: trailing punctuation, encoded spaces, doubled slashes, "index.html/php", copy-paste quotes.
		$clean = preg_replace( array( '~(%20|%22|%27|%E2%80%9D|[\)\]\}\.,;:!\'"]|&quot;)+/?$~i', '~/index\.(html?|php)$~i', '~/{2,}~' ), array( '', '/', '/' ), $path );
		if ( $clean !== $path && '' !== $clean ) {
			$id = url_to_postid( home_url( $clean ) );
			if ( $id ) {
				return $out( 'junk', __( 'Extra characters in the address', 'publishrank' ), __( 'The link has trailing punctuation or encoding (often from a newsletter or chat app). Redirecting to the real page is safe.', 'publishrank' ), wp_make_link_relative( get_permalink( $id ) ), 98 );
			}
		}
		$lower = strtolower( $path );
		if ( $lower !== $path ) {
			$id = url_to_postid( home_url( $lower ) );
			if ( $id ) {
				return $out( 'case', __( 'Wrong letter case', 'publishrank' ), __( 'The same address in lower case exists.', 'publishrank' ), wp_make_link_relative( get_permalink( $id ) ), 97 );
			}
		}
		// Pagination past the last page.
		if ( preg_match( '~^(.*?)/page/(\d+)/?$~', $path, $m ) ) {
			return $out( 'pagination', __( 'Page number past the end', 'publishrank' ), __( 'Archives lose pages when posts are removed. Redirect to the first page of the archive.', 'publishrank' ), trailingslashit( '' === $m[1] ? '/' : $m[1] ), 80 );
		}
		$slug = sanitize_title( basename( untrailingslashit( (string) wp_parse_url( $path, PHP_URL_PATH ) ) ) );
		if ( '' !== $slug ) {
			global $wpdb;
			$trash = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE post_name IN (%s, %s) AND post_status IN ('trash','draft','pending','private','future') LIMIT 1", $slug, $slug . '__trashed' ) );
			if ( $trash ) {
				$labels = array( 'trash' => __( 'Post is in the trash', 'publishrank' ), 'draft' => __( 'Post was unpublished', 'publishrank' ), 'pending' => __( 'Post is pending review', 'publishrank' ), 'private' => __( 'Post is private', 'publishrank' ), 'future' => __( 'Post is scheduled', 'publishrank' ) );
				return $out( 'unpublished', $labels[ $trash->post_status ], __( 'The content still exists. Restore or republish it, or redirect readers to a related page.', 'publishrank' ), '', 0 );
			}
			// Same slug under a different path: changed permalink structure or moved category.
			$live = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type NOT IN ('revision','nav_menu_item','attachment') LIMIT 1", $slug ) );
			if ( $live ) {
				$is_date = (bool) preg_match( '~/\d{4}/\d{2}(/\d{2})?/~', $path );
				return $out( $is_date ? 'old_permalink' : 'moved', $is_date ? __( 'Old date-based address', 'publishrank' ) : __( 'Page moved', 'publishrank' ), __( 'A published page with the same slug lives at a different address.', 'publishrank' ), wp_make_link_relative( get_permalink( (int) $live ) ), 92 );
			}
		}
		if ( preg_match( '~\.(php|asp|aspx|jsp|cgi|env|ini|sql|bak|zip|tar|gz)$~i', $path ) ) {
			return $out( 'probe', __( 'Probe for a file that never existed', 'publishrank' ), __( 'Usually automated scanning. Ignoring it or answering 410 keeps the list clean.', 'publishrank' ) );
		}
		return $out( 'unknown', __( 'No known cause', 'publishrank' ), __( 'Check where it came from, then redirect to the closest page or leave it as a 404.', 'publishrank' ) );
	}

	/** Diagnosis counts for the open list (bounded). */
	public static function cause_summary( $limit = 300 ) {
		$rows = NotFoundStore::query( array( 'status' => 'open', 'per_page' => $limit, 'orderby' => 'hits' ) )['rows'];
		$out  = array();
		foreach ( $rows as $r ) {
			if ( 'scanner' === $r['ua_class'] ) {
				continue;
			}
			$d = self::diagnose( $r['path'] );
			if ( ! isset( $out[ $d['type'] ] ) ) {
				$out[ $d['type'] ] = array( 'label' => $d['label'], 'paths' => 0, 'hits' => 0, 'fixable' => 0 );
			}
			$out[ $d['type'] ]['paths']++;
			$out[ $d['type'] ]['hits'] += (int) $r['hits'];
			$out[ $d['type'] ]['fixable'] += $d['target'] && $d['confidence'] >= 90 ? 1 : 0;
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return $b['hits'] - $a['hits'];
			}
		);
		return $out;
	}

	/**
	 * Redirect every open 404 that has a high-confidence target (>= 90%). Returns the number of redirects created.
	 * Never applied to scanner traffic or to targets that are themselves missing.
	 */
	public static function fix_confident( $min = 90, $limit = 200 ) {
		$rows = NotFoundStore::query( array( 'status' => 'open', 'per_page' => $limit, 'orderby' => 'hits' ) )['rows'];
		$n    = 0;
		foreach ( $rows as $r ) {
			if ( 'scanner' === $r['ua_class'] ) {
				continue;
			}
			$d = self::diagnose( $r['path'] );
			if ( '' === $d['target'] || $d['confidence'] < $min || untrailingslashit( $d['target'] ) === untrailingslashit( $r['path'] ) ) {
				continue;
			}
			// Moved media files live under the protected uploads path; the redirect there is intentional and exact.
			$res = NotFoundStore::redirect( (int) $r['id'], $d['target'], 301, 'media_moved' === $d['type'] ? array( 'force' => true ) : array() );
			if ( ! is_wp_error( $res ) && false !== $res ) {
				++$n;
			}
		}
		return $n;
	}
}
