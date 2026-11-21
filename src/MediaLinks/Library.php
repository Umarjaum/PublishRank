<?php
/**
 * Media Link Manager library queries and the media health scan (missing files, duplicates, broken media URLs).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\MediaLinks;

defined( 'ABSPATH' ) || exit;

final class Library {
	const HEALTH_OPT = 'publishrank_media_health';

	/** Resolve a pasted URL (any size, any spelling, with or without query string) to an attachment id. */
	public static function id_from_url( $url ) {
		global $wpdb;
		$url  = strtok( trim( (string) $url ), '?#' );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return 0;
		}
		$up   = Scanner::uploads();
		$base = (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH );
		$rel  = 0 === strpos( $path, $base . '/' ) ? substr( $path, strlen( $base ) + 1 ) : ltrim( $path, '/' );
		$id   = (int) attachment_url_to_postid( $up['baseurl'] . '/' . $rel );
		if ( $id ) {
			return $id;
		}
		// Size variant: strip -123x456 and -scaled, then match the main file.
		$guess = preg_replace( '/-(\d+x\d+|scaled|rotated)(?=\.[A-Za-z0-9]+$)/', '', $rel );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value = %s OR meta_value = %s ) LIMIT 1", $guess, preg_replace( '/(\.[A-Za-z0-9]+)$/', '-scaled$1', $guess ) ) );
		return $id;
	}

	/** @return array{items:array,total:int,pages:int} */
	public static function query( array $args ) {
		global $wpdb;
		$per    = max( 5, min( 100, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page   = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$search = trim( (string) ( isset( $args['search'] ) ? $args['search'] : '' ) );
		$type   = sanitize_key( (string) ( isset( $args['type'] ) ? $args['type'] : '' ) );
		$filter = sanitize_key( (string) ( isset( $args['filter'] ) ? $args['filter'] : '' ) );
		$q      = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => $per, 'paged' => $page, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids' );
		if ( in_array( $type, array( 'image', 'video', 'audio', 'application', 'text' ), true ) ) {
			$q['post_mime_type'] = $type;
		}
		if ( 'unattached' === $filter ) {
			$q['post_parent'] = 0;
		}
		$ids = array();
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$ids[] = (int) $search;
			}
			if ( preg_match( '~^(https?:)?//|^/~', $search ) ) {
				$hit = self::id_from_url( $search );
				if ( $hit ) {
					$ids[] = $hit;
				}
			}
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' AND ( p.post_title LIKE %s OR p.post_name LIKE %s OR m.meta_value LIKE %s OR p.guid LIKE %s ) ORDER BY p.post_date DESC LIMIT 500", $like, $like, $like, $like ) );
			$ids   = array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) $found ) ) ) );
			$q['post__in'] = $ids ? $ids : array( 0 );
			$q['orderby']  = 'post__in';
		}
		if ( 'missing' === $filter ) {
			$h             = get_option( self::HEALTH_OPT, array() );
			$q['post__in'] = ! empty( $h['missing'] ) ? array_map( 'intval', array_keys( $h['missing'] ) ) : array( 0 );
		}
		if ( 'duplicates' === $filter ) {
			$h   = get_option( self::HEALTH_OPT, array() );
			$dup = array();
			foreach ( (array) ( isset( $h['duplicates'] ) ? $h['duplicates'] : array() ) as $group ) {
				$dup = array_merge( $dup, (array) $group );
			}
			$q['post__in'] = $dup ? array_map( 'intval', $dup ) : array( 0 );
		}
		$wq    = new \WP_Query( $q );
		$items = array();
		foreach ( $wq->posts as $id ) {
			$items[] = self::item( (int) $id );
		}
		return array( 'items' => $items, 'total' => (int) $wq->found_posts, 'pages' => (int) $wq->max_num_pages );
	}

	public static function item( $id ) {
		$post  = get_post( $id );
		$files = Scanner::files( $id );
		$main  = $files ? $files[0] : null;
		$meta  = wp_get_attachment_metadata( $id );
		$refs  = get_post_meta( $id, '_publishrank_media_refs', true );
		$size  = $main && file_exists( $main['path'] ) ? (int) filesize( $main['path'] ) : 0;
		$miss  = array();
		foreach ( $files as $f ) {
			if ( ! file_exists( $f['path'] ) ) {
				$miss[] = $f['rel'];
			}
		}
		$current = (string) wp_get_attachment_url( $id );
		return array(
			'id'           => (int) $id,
			'title'        => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES ) : '',
			'filename'     => $main ? basename( $main['rel'] ) : basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
			'path'         => $main ? $main['rel'] : '',
			'mime'         => $post ? (string) $post->post_mime_type : '',
			'url'          => $current,
			'stored_url'   => $main ? $main['url'] : '',
			'original_url' => $post ? (string) $post->guid : '',
			'host_mapped'  => $main && $current !== $main['url'],
			'thumb'        => (string) wp_get_attachment_image_url( $id, 'thumbnail', true ),
			'width'        => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'       => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'filesize'     => $size,
			'sizes'        => max( 0, count( $files ) - 1 ),
			'missing'      => $miss,
			'exists'       => $main && ! $miss,
			'uploaded'     => $post ? get_the_date( 'Y-m-d', $post ) : '',
			'parent'       => $post ? (int) $post->post_parent : 0,
			'page_link'    => (string) get_attachment_link( $id ),
			'slug'         => $post ? (string) $post->post_name : '',
			'pages_on'     => Migrator::attachment_pages_enabled(),
			'refs'         => is_array( $refs ) ? $refs : null,
			'edit'         => (string) get_edit_post_link( $id, 'raw' ),
			'can_edit'     => current_user_can( 'edit_post', $id ),
		);
	}

	/**
	 * One step of the health scan. Steps walk attachments (missing files, duplicates) and then content
	 * (img/src references to uploads files that do not exist).
	 *
	 * @return array Progress and partial results.
	 */
	public static function health_step( $phase, $offset, $reset = false ) {
		global $wpdb;
		$h = $reset ? array() : get_option( self::HEALTH_OPT, array() );
		if ( ! is_array( $h ) ) {
			$h = array();
		}
		$h += array( 'missing' => array(), 'hashes' => array(), 'duplicates' => array(), 'broken' => array(), 'started' => time(), 'finished' => 0 );
		$up   = Scanner::uploads();
		$step = 150;
		if ( 'attachments' === $phase ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC LIMIT %d OFFSET %d", $step, (int) $offset ) );
			foreach ( (array) $ids as $id ) {
				$id    = (int) $id;
				$files = Scanner::files( $id );
				if ( ! $files ) {
					continue;
				}
				$miss = array();
				foreach ( $files as $f ) {
					if ( ! file_exists( $f['path'] ) ) {
						$miss[] = $f['rel'];
					}
				}
				if ( $miss ) {
					$h['missing'][ $id ] = array_slice( $miss, 0, 10 );
				} else {
					unset( $h['missing'][ $id ] );
					$p = $files[0]['path'];
					$s = (int) filesize( $p );
					if ( $s > 0 ) {
						$fh  = @fopen( $p, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
						$buf = $fh ? (string) fread( $fh, 1048576 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
						if ( $fh ) {
							fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
						}
						$key                   = $s . ':' . md5( $buf );
						$h['hashes'][ $key ][] = $id;
					}
				}
			}
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
			$next  = $offset + $step;
			if ( $next >= $total ) {
				$h['duplicates'] = array();
				foreach ( $h['hashes'] as $group ) {
					if ( count( $group ) > 1 ) {
						$h['duplicates'][] = array_values( array_unique( array_map( 'intval', $group ) ) );
					}
				}
				update_option( self::HEALTH_OPT, $h, false );
				return array( 'phase' => 'content', 'offset' => 0, 'total' => self::content_total(), 'done' => false, 'summary' => self::health_summary( $h ) );
			}
			update_option( self::HEALTH_OPT, $h, false );
			return array( 'phase' => 'attachments', 'offset' => $next, 'total' => $total, 'done' => false, 'summary' => self::health_summary( $h ) );
		}
		// Content phase: uploads URLs in post content whose file does not exist.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','nav_menu_item') AND post_status IN ('publish','future','draft','pending','private') ORDER BY ID ASC LIMIT %d OFFSET %d", $step, (int) $offset ) );
		$base = (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH );
		$host = (string) wp_parse_url( $up['baseurl'], PHP_URL_HOST );
		foreach ( (array) $rows as $r ) {
			unset( $h['broken'][ (int) $r->ID ] );
			if ( ! preg_match_all( '~(?:https?:)?(?://[^/"\'\s<>]+)?' . preg_quote( $base, '~' ) . '/([^"\'\s<>?#)]+\.[A-Za-z0-9]{2,5})~', (string) $r->post_content, $m, PREG_SET_ORDER ) ) {
				continue;
			}
			$bad = array();
			foreach ( $m as $hit ) {
				$full = $hit[0];
				$h2   = (string) wp_parse_url( 0 === strpos( $full, '//' ) ? 'http:' . $full : $full, PHP_URL_HOST );
				if ( $h2 && $h2 !== $host ) {
					continue; // Another site's uploads URL: not checked on disk.
				}
				$rel = rawurldecode( $hit[1] );
				if ( false !== strpos( $rel, '..' ) ) {
					continue;
				}
				if ( ! file_exists( $up['basedir'] . '/' . $rel ) ) {
					$bad[ $rel ] = true;
				}
			}
			if ( $bad ) {
				$h['broken'][ (int) $r->ID ] = array( 'title' => wp_strip_all_tags( (string) $r->post_title ), 'type' => $r->post_type, 'files' => array_slice( array_keys( $bad ), 0, 10 ), 'edit' => (string) get_edit_post_link( (int) $r->ID, 'raw' ) );
			}
		}
		$total = self::content_total();
		$next  = $offset + $step;
		$done  = $next >= $total;
		if ( $done ) {
			$h['finished'] = time();
			unset( $h['hashes'] );
		}
		update_option( self::HEALTH_OPT, $h, false );
		return array( 'phase' => 'content', 'offset' => $next, 'total' => $total, 'done' => $done, 'summary' => self::health_summary( $h ) );
	}

	private static function content_total() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment','nav_menu_item') AND post_status IN ('publish','future','draft','pending','private')" );
	}

	public static function health_summary( $h = null ) {
		if ( null === $h ) {
			$h = get_option( self::HEALTH_OPT, array() );
		}
		$h       = is_array( $h ) ? $h : array();
		$missing = array();
		foreach ( (array) ( isset( $h['missing'] ) ? $h['missing'] : array() ) as $id => $files ) {
			$missing[] = array( 'id' => (int) $id, 'title' => get_the_title( (int) $id ), 'files' => $files, 'edit' => (string) get_edit_post_link( (int) $id, 'raw' ) );
		}
		$dups = array();
		foreach ( (array) ( isset( $h['duplicates'] ) ? $h['duplicates'] : array() ) as $group ) {
			$dups[] = array_map(
				static function ( $id ) {
					return array( 'id' => (int) $id, 'title' => get_the_title( (int) $id ), 'file' => basename( (string) get_post_meta( (int) $id, '_wp_attached_file', true ) ), 'thumb' => (string) wp_get_attachment_image_url( (int) $id, 'thumbnail', true ) );
				},
				(array) $group
			);
		}
		$broken = array();
		foreach ( (array) ( isset( $h['broken'] ) ? $h['broken'] : array() ) as $pid => $row ) {
			$broken[] = array_merge( array( 'id' => (int) $pid ), (array) $row );
		}
		return array( 'missing' => array_slice( $missing, 0, 200 ), 'duplicates' => array_slice( $dups, 0, 100 ), 'broken' => array_slice( $broken, 0, 200 ), 'finished' => isset( $h['finished'] ) ? (int) $h['finished'] : 0, 'started' => isset( $h['started'] ) ? (int) $h['started'] : 0 );
	}
}
