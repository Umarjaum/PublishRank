<?php
/**
 * Bulk SEO Editor engine (2.26.1): filters, duplicate detection, validation, preview plans, change log, undo, CSV.
 *
 * Nothing is written until the publisher confirms a reviewed plan. Every applied change keeps the previous value;
 * undo restores it only when the field still holds the value the batch wrote (later edits are never overwritten).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Database;
use PublishRankSEO\Meta\MetaGenerator;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class BulkSeo {
	const MAX_ROWS = 500;
	const PLAN_TTL = 1800;

	/** @return array<string,array{meta:string,label:string,max:int}> */
	public static function fields() {
		return array(
			'title'       => array( 'meta' => PostMeta::TITLE, 'label' => __( 'SEO title', 'publishrank' ), 'max' => 255 ),
			'description' => array( 'meta' => PostMeta::DESCRIPTION, 'label' => __( 'Meta description', 'publishrank' ), 'max' => 320 ),
			'robots'      => array( 'meta' => PostMeta::ROBOTS, 'label' => __( 'Robots', 'publishrank' ), 'max' => 255 ),
			'canonical'   => array( 'meta' => PostMeta::CANONICAL, 'label' => __( 'Canonical URL', 'publishrank' ), 'max' => 2048 ),
			'focus'       => array( 'meta' => PostMeta::FOCUS_KEYWORD, 'label' => __( 'Focus keyword', 'publishrank' ), 'max' => 191 ),
		);
	}

	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( array_map( 'sanitize_key', $types ) );
	}

	/* ------------------------------------------------------------ Listing */

	/** @return array{type:string,status:string,cat:int,s:string,issue:string,paged:int} */
	public static function filters( array $src ) {
		$types = self::post_types();
		$type  = isset( $src['ptype'] ) ? sanitize_key( (string) $src['ptype'] ) : '';
		$st    = isset( $src['pstatus'] ) ? sanitize_key( (string) $src['pstatus'] ) : '';
		$issue = isset( $src['issue'] ) ? sanitize_key( (string) $src['issue'] ) : '';
		return array(
			'type'   => in_array( $type, $types, true ) ? $type : '',
			'status' => in_array( $st, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $st : '',
			'cat'    => isset( $src['cat'] ) ? absint( $src['cat'] ) : 0,
			's'      => isset( $src['s'] ) ? sanitize_text_field( (string) $src['s'] ) : '',
			'issue'  => in_array( $issue, array( 'no_title', 'no_desc', 'dup_title', 'dup_desc', 'noindex', 'canonical_other', 'long_title', 'long_desc' ), true ) ? $issue : '',
			'paged'  => isset( $src['paged'] ) ? max( 1, absint( $src['paged'] ) ) : 1,
		);
	}

	/** @return \WP_Query */
	public static function query( array $f, $per_page = 30 ) {
		$args = array(
			'post_type'              => $f['type'] ? array( $f['type'] ) : self::post_types(),
			'post_status'            => $f['status'] ? array( $f['status'] ) : array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => (int) $per_page,
			'paged'                  => (int) $f['paged'],
			's'                      => $f['s'],
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'update_post_term_cache' => false,
		);
		if ( $f['cat'] ) {
			$args['cat'] = $f['cat'];
		}
		$meta = array();
		switch ( $f['issue'] ) {
			case 'no_title':
			case 'no_desc':
				$key    = 'no_title' === $f['issue'] ? PostMeta::TITLE : PostMeta::DESCRIPTION;
				$meta[] = array( 'relation' => 'OR', array( 'key' => $key, 'compare' => 'NOT EXISTS' ), array( 'key' => $key, 'value' => '', 'compare' => '=' ) );
				break;
			case 'noindex':
				$meta[] = array( 'key' => PostMeta::ROBOTS, 'value' => 'noindex', 'compare' => 'LIKE' );
				break;
			case 'canonical_other':
				$meta[] = array( 'key' => PostMeta::CANONICAL, 'value' => '', 'compare' => '!=' );
				break;
			case 'dup_title':
			case 'dup_desc':
				$ids              = self::duplicate_ids( 'dup_title' === $f['issue'] ? PostMeta::TITLE : PostMeta::DESCRIPTION, $args['post_type'] );
				$args['post__in'] = $ids ? $ids : array( 0 );
				$args['orderby']  = 'post__in';
				break;
			case 'long_title':
			case 'long_desc':
				$ids              = self::long_ids( 'long_title' === $f['issue'] ? PostMeta::TITLE : PostMeta::DESCRIPTION, 'long_title' === $f['issue'] ? 60 : 160, $args['post_type'] );
				$args['post__in'] = $ids ? $ids : array( 0 );
				break;
		}
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return new \WP_Query( $args );
	}

	/** Post IDs that share a non-empty SEO title or description with another post (ordered so duplicates sit together). */
	public static function duplicate_ids( $meta_key, array $types, $limit = 1000 ) {
		global $wpdb;
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql  = "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			JOIN (SELECT LOWER(TRIM(m.meta_value)) AS v FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} q ON q.ID = m.post_id
				WHERE m.meta_key = %s AND TRIM(m.meta_value) <> '' AND q.post_status = 'publish' AND q.post_type IN ($in) GROUP BY LOWER(TRIM(m.meta_value)) HAVING COUNT(*) > 1) d
			ON d.v = LOWER(TRIM(pm.meta_value))
			WHERE pm.meta_key = %s AND p.post_status = 'publish' AND p.post_type IN ($in) ORDER BY LOWER(TRIM(pm.meta_value)), pm.post_id LIMIT %d";
		$args = array_merge( array( $meta_key ), $types, array( $meta_key ), $types, array( (int) $limit ) );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	public static function long_ids( $meta_key, $max, array $types, $limit = 1000 ) {
		global $wpdb;
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args = array_merge( array( $meta_key, (int) $max ), $types, array( (int) $limit ) );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND CHAR_LENGTH(pm.meta_value) > %d AND p.post_type IN ($in) LIMIT %d", $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** Counts for the issue filters (published content only). */
	public static function issue_counts() {
		$types = self::post_types();
		return array(
			'dup_title' => count( self::duplicate_ids( PostMeta::TITLE, $types ) ),
			'dup_desc'  => count( self::duplicate_ids( PostMeta::DESCRIPTION, $types ) ),
		);
	}

	public static function values( $post_id ) {
		$out = array();
		foreach ( self::fields() as $k => $def ) {
			$v         = get_post_meta( (int) $post_id, $def['meta'], true );
			$out[ $k ] = is_scalar( $v ) ? (string) $v : '';
		}
		return $out;
	}

	/* ------------------------------------------------------------ Validation & planning */

	/** @return array{0:bool,1:string} [ok, normalised value or error message] */
	public static function validate( $field, $value ) {
		$value = trim( (string) $value );
		$defs  = self::fields();
		if ( ! isset( $defs[ $field ] ) ) {
			return array( false, __( 'Unknown field.', 'publishrank' ) );
		}
		if ( '' === $value ) {
			return array( true, '' ); // Clearing a field means "use the template / site default".
		}
		switch ( $field ) {
			case 'robots':
				$norm = ( new MetaGenerator() )->normalize_robots( str_replace( array( ' ', ';' ), array( '', ',' ), $value ) );
				$in   = array_filter( array_map( 'trim', explode( ',', strtolower( str_replace( ';', ',', $value ) ) ) ) );
				$kept = explode( ',', $norm );
				$lost = array_diff( $in, $kept, array( 'index', 'follow' ) );
				if ( $lost ) {
					/* translators: %s: invalid directives */
					return array( false, sprintf( __( 'Unknown robots directive: %s', 'publishrank' ), implode( ', ', $lost ) ) );
				}
				return array( true, $norm );
			case 'canonical':
				// Must already be a full address: esc_url_raw() would silently turn "not a url" into "http://not%20a%20url".
				$host = (string) wp_parse_url( $value, PHP_URL_HOST );
				$url  = esc_url_raw( $value, array( 'http', 'https' ) );
				if ( preg_match( '/\s/', $value ) || ! preg_match( '#^https?://#i', $value ) || '' === $url || '' === $host || ( false === strpos( $host, '.' ) && 'localhost' !== strtolower( $host ) ) ) {
					return array( false, __( 'Canonical must be a full http(s) address.', 'publishrank' ) );
				}
				return array( true, $url );
			default:
				$clean = 'description' === $field ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
				$clean = preg_replace( '/\s+/u', ' ', $clean );
				if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $clean ) : strlen( $clean ) ) > $defs[ $field ]['max'] ) {
					/* translators: %d: max characters */
					return array( false, sprintf( __( 'Longer than %d characters.', 'publishrank' ), $defs[ $field ]['max'] ) );
				}
				return array( true, $clean );
		}
	}

	/**
	 * Build a reviewable plan from requested values.
	 *
	 * @param array<int,array<string,string>> $requested post_id => field => new value.
	 * @return array{rows:array<int,array>,errors:int,changes:int,skipped:int}
	 */
	public static function plan( array $requested, $source = 'editor' ) {
		$rows   = array();
		$errors = 0;
		$skip   = 0;
		$defs   = self::fields();
		$types  = self::post_types();
		foreach ( array_slice( $requested, 0, self::MAX_ROWS, true ) as $post_id => $fields ) {
			$post_id = absint( $post_id );
			$post    = $post_id ? get_post( $post_id ) : null;
			foreach ( (array) $fields as $field => $value ) {
				$field = sanitize_key( (string) $field );
				if ( ! isset( $defs[ $field ] ) ) {
					continue;
				}
				$row = array( 'post_id' => $post_id, 'field' => $field, 'old' => '', 'new' => '', 'error' => '' );
				if ( ! $post || ! in_array( $post->post_type, $types, true ) ) {
					$row['error'] = __( 'Content not found.', 'publishrank' );
				} elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
					$row['error'] = __( 'You cannot edit this content.', 'publishrank' );
				} else {
					$cur        = get_post_meta( $post_id, $defs[ $field ]['meta'], true );
					$row['old'] = is_scalar( $cur ) ? (string) $cur : '';
					list( $ok, $v ) = self::validate( $field, $value );
					if ( ! $ok ) {
						$row['error'] = $v;
						$row['new']   = (string) $value;
					} else {
						$row['new'] = $v;
						if ( $v === $row['old'] ) {
							++$skip;
							continue; // No change.
						}
					}
				}
				$errors += '' !== $row['error'] ? 1 : 0;
				$rows[]  = $row;
			}
		}
		return array( 'rows' => $rows, 'errors' => $errors, 'changes' => count( $rows ) - $errors, 'skipped' => $skip, 'source' => $source );
	}

	/** Keep a plan for review; returns its token. Plans are per user and expire. */
	public static function stash( array $plan ) {
		$token = strtolower( wp_generate_password( 16, false, false ) );
		set_transient( 'publishrank_bulk_plan_' . get_current_user_id() . '_' . $token, $plan, self::PLAN_TTL );
		return $token;
	}

	public static function unstash( $token, $delete = false ) {
		$key  = 'publishrank_bulk_plan_' . get_current_user_id() . '_' . preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $token ) );
		$plan = get_transient( $key );
		if ( $delete ) {
			delete_transient( $key );
		}
		return is_array( $plan ) ? $plan : null;
	}

	/* ------------------------------------------------------------ Apply & undo */

	/**
	 * Apply the valid rows of a plan. A row whose field changed since the preview is skipped (reported as conflict).
	 *
	 * @return array{batch:string,applied:int,conflicts:int,errors:int}
	 */
	public static function apply( array $plan ) {
		global $wpdb;
		$defs  = self::fields();
		$batch = strtolower( wp_generate_password( 16, false, false ) );
		$now   = current_time( 'mysql', true );
		$out   = array( 'batch' => $batch, 'applied' => 0, 'conflicts' => 0, 'errors' => 0 );
		$log   = Database::ready( 'bulk_changes' );
		foreach ( $plan['rows'] as $r ) {
			if ( '' !== $r['error'] ) {
				++$out['errors'];
				continue;
			}
			$pid = (int) $r['post_id'];
			$key = $defs[ $r['field'] ]['meta'];
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				++$out['errors'];
				continue;
			}
			$had = metadata_exists( 'post', $pid, $key );
			$cur = get_post_meta( $pid, $key, true );
			$cur = is_scalar( $cur ) ? (string) $cur : '';
			if ( $cur !== (string) $r['old'] ) {
				++$out['conflicts']; // Someone edited it after the preview.
				continue;
			}
			if ( '' === $r['new'] ) {
				delete_post_meta( $pid, $key );
			} else {
				update_post_meta( $pid, $key, wp_slash( $r['new'] ) );
			}
			$now_val = get_post_meta( $pid, $key, true );
			if ( (string) $now_val !== (string) $r['new'] ) {
				++$out['errors']; // A sanitizer rejected or changed the value; record nothing as applied.
				if ( $had ) {
					update_post_meta( $pid, $key, wp_slash( $cur ) );
				} else {
					delete_post_meta( $pid, $key );
				}
				continue;
			}
			if ( $log ) {
				$wpdb->insert( Database::table( 'bulk_changes' ), array( 'batch_id' => $batch, 'post_id' => $pid, 'meta_key' => $key, 'had_old' => $had ? 1 : 0, 'old_value' => $cur, 'new_value' => $r['new'], 'source' => substr( sanitize_key( (string) ( $plan['source'] ?? 'editor' ) ), 0, 16 ), 'user_id' => get_current_user_id(), 'status' => 'applied', 'created_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			clean_post_cache( $pid );
			++$out['applied'];
		}
		do_action( 'publishrank_bulk_seo_applied', $out, $plan );
		return $out;
	}

	/** @return array{restored:int,conflicts:int} */
	public static function rollback( $batch ) {
		global $wpdb;
		$out = array( 'restored' => 0, 'conflicts' => 0 );
		if ( ! Database::ready( 'bulk_changes' ) ) {
			return $out;
		}
		$t    = Database::table( 'bulk_changes' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE batch_id = %s AND status = 'applied' ORDER BY id DESC", (string) $batch ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $r ) {
			$pid = (int) $r['post_id'];
			if ( ! current_user_can( 'edit_post', $pid ) ) {
				++$out['conflicts'];
				continue;
			}
			$cur = get_post_meta( $pid, $r['meta_key'], true );
			$cur = is_scalar( $cur ) ? (string) $cur : '';
			if ( $cur !== (string) $r['new_value'] ) {
				$wpdb->update( $t, array( 'status' => 'conflict' ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$out['conflicts']; // Edited again since; keep the newer value.
				continue;
			}
			if ( (int) $r['had_old'] ) {
				update_post_meta( $pid, $r['meta_key'], wp_slash( (string) $r['old_value'] ) );
			} else {
				delete_post_meta( $pid, $r['meta_key'] );
			}
			clean_post_cache( $pid );
			$wpdb->update( $t, array( 'status' => 'rolled_back' ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			++$out['restored'];
		}
		return $out;
	}

	public static function batches( $limit = 30 ) {
		global $wpdb;
		if ( ! Database::ready( 'bulk_changes' ) ) {
			return array();
		}
		$t = Database::table( 'bulk_changes' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT batch_id, MIN(created_at) AS created_at, MAX(user_id) AS user_id, MAX(source) AS source, COUNT(*) AS total, SUM(status = 'applied') AS active, SUM(status = 'rolled_back') AS undone, SUM(status = 'conflict') AS conflicts FROM {$t} GROUP BY batch_id ORDER BY MIN(id) DESC LIMIT %d", (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------------------------------------ CSV */

	public static function csv_header() {
		return array( 'id', 'post_type', 'status', 'url', 'post_title', 'seo_title', 'meta_description', 'robots', 'canonical', 'focus_keyword' );
	}

	/** Rows for export (bounded). */
	public static function export_rows( array $f ) {
		$f['paged'] = 1;
		$q          = self::query( $f, 2000 );
		$out        = array( self::csv_header() );
		foreach ( $q->posts as $p ) {
			if ( ! current_user_can( 'edit_post', $p->ID ) ) {
				continue;
			}
			$v     = self::values( $p->ID );
			$out[] = array( $p->ID, $p->post_type, $p->post_status, get_permalink( $p ), get_the_title( $p ), $v['title'], $v['description'], $v['robots'], $v['canonical'], $v['focus'] );
		}
		return $out;
	}

	/** Neutralise spreadsheet formula injection in exported cells. */
	public static function csv_cell( $v ) {
		$v = (string) $v;
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	/**
	 * Parse an uploaded CSV into requested changes. Only columns present in the file are considered;
	 * a present-but-empty cell clears the field.
	 *
	 * @return array{requested:array,error:string,rows:int}
	 */
	public static function parse_csv( $path ) {
		$map = array( 'seo_title' => 'title', 'meta_description' => 'description', 'robots' => 'robots', 'canonical' => 'canonical', 'focus_keyword' => 'focus' );
		$fh  = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return array( 'requested' => array(), 'error' => __( 'The file could not be read.', 'publishrank' ), 'rows' => 0 );
		}
		$head = fgetcsv( $fh, 0, ',', '"', '\\' );
		if ( ! is_array( $head ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array( 'requested' => array(), 'error' => __( 'The file is empty.', 'publishrank' ), 'rows' => 0 );
		}
		$head = array_map(
			static function ( $h ) {
				return sanitize_key( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) );
			},
			$head
		);
		$idc  = array_search( 'id', $head, true );
		if ( false === $idc ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return array( 'requested' => array(), 'error' => __( 'The file needs an "id" column (export a CSV first to get the right format).', 'publishrank' ), 'rows' => 0 );
		}
		$req = array();
		$n   = 0;
		while ( ( $line = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false && $n < self::MAX_ROWS ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$n;
			$id = isset( $line[ $idc ] ) ? absint( $line[ $idc ] ) : 0;
			if ( ! $id ) {
				continue;
			}
			foreach ( $map as $col => $field ) {
				$c = array_search( $col, $head, true );
				if ( false !== $c && array_key_exists( $c, $line ) ) {
					$cell                 = (string) $line[ $c ];
					$req[ $id ][ $field ] = 0 === strpos( $cell, "'" ) && preg_match( '/^\'[=+\-@]/', $cell ) ? substr( $cell, 1 ) : $cell;
				}
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array( 'requested' => $req, 'error' => '', 'rows' => $n );
	}
}
