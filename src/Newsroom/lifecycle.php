<?php
/**
 * Newsroom v2: safe story lifecycle and the paginated story manager.
 *
 * - Trash is the default delete. Stories stay recoverable until restored or purged.
 * - Permanent delete (type DELETE to confirm), Empty trash, and a daily auto-purge after N days (default 30).
 * - Private attachment files and the discussion are removed only on permanent delete; a tombstone
 *   (who, when, headline) is kept in the audit trail.
 * - Capabilities: newsroom_delete trashes/purges any story; reporters (no newsroom_delete) may trash only their
 *   own unpublished stories; newsroom_restore restores.
 * - Story Manager screen: server-side pagination, search and filters, multi-select (shift-click) and bulk actions:
 *   change stage, assign reporter/editor, desk, priority, archive/unarchive, trash, restore, delete permanently, export.
 *
 * @package PublishRankSEO
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PublishRank_Newsroom_Lifecycle {

	private static function nr_can( $key ) {
		return class_exists( '\\PublishRankSEO\\Core\\Capabilities' ) ? \PublishRankSEO\Core\Capabilities::can( $key ) : current_user_can( 'manage_options' );
	}

	private function register_lifecycle() {
		foreach ( array( 'trash', 'restore', 'purge', 'empty_trash', 'bulk_manage', 'regen_ics' ) as $a ) {
			add_action( 'admin_post_uj_' . $a, array( $this, 'lc_' . $a ) );
		}
		add_action( 'admin_menu', array( $this, 'lc_menu' ), 12 );
		add_action( 'before_delete_post', array( $this, 'lc_before_delete' ), 10, 2 );
		add_action( 'uj_newsroom_purge_trash', array( $this, 'lc_auto_purge' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'uj_newsroom_purge_trash' ) ) {
					wp_schedule_event( time() + 1800, 'daily', 'uj_newsroom_purge_trash' );
				}
			},
			20
		);
		add_action( 'admin_enqueue_scripts', array( $this, 'lc_assets' ) );
	}

	public function lc_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $page, array( 'uj-newsroom-manage', 'uj-newsroom-trash' ), true ) && class_exists( '\\PublishRankSEO\\Admin\\Suite' ) ) {
			\PublishRankSEO\Admin\Suite::enqueue();
			$js = PUBLISHRANK_SEO_PATH . 'assets/js/newsroom-manage.js';
			wp_enqueue_script( 'publishrank-newsroom-manage', plugins_url( 'assets/js/newsroom-manage.js', PUBLISHRANK_SEO_FILE ), array(), PUBLISHRANK_SEO_VERSION . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		}
	}

	public function lc_menu() {
		$parent = 'uj-newsroom';
		add_submenu_page( $parent, __( 'Story Manager', 'publishrank' ), __( 'Story Manager', 'publishrank' ), self::CAP_ACCESS, 'uj-newsroom-manage', array( $this, 'render_manage' ) );
		add_submenu_page( $parent, __( 'Newsroom Trash', 'publishrank' ), __( 'Trash', 'publishrank' ), self::CAP_ACCESS, 'uj-newsroom-trash', array( $this, 'render_trash' ) );
	}

	public static function purge_days() {
		return max( 1, min( 365, (int) get_option( 'uj_newsroom_purge_days', 30 ) ) );
	}

	/* ------------------------------------------------------------ Permissions */

	private function is_own_story( $id ) {
		$uid = get_current_user_id();
		return $uid && ( (int) get_post_field( 'post_author', $id ) === $uid || (int) $this->meta( $id, 'reporter', 0 ) === $uid );
	}

	private function can_trash( $id ) {
		if ( current_user_can( self::CAP_MANAGE ) || self::nr_can( 'newsroom_delete' ) ) {
			return true;
		}
		// Reporters: only their own stories that have not been published or handed off.
		return $this->is_own_story( $id ) && 'published' !== $this->meta( $id, 'stage', 'pitch' ) && ! (int) $this->meta( $id, 'wp_post_id', 0 );
	}

	private function can_restore( $id ) {
		return current_user_can( self::CAP_MANAGE ) || self::nr_can( 'newsroom_restore' ) || self::nr_can( 'newsroom_delete' ) || ( $this->is_own_story( $id ) && $this->can_trash( $id ) );
	}

	private function can_purge() {
		return current_user_can( self::CAP_MANAGE ) || self::nr_can( 'newsroom_delete' );
	}

	private function story_or_die( $id, $status = '' ) {
		$s = get_post( $id );
		if ( ! $s || self::POST_TYPE !== $s->post_type || ( $status && $status !== $s->post_status ) ) {
			wp_die( esc_html__( 'Story not found.', 'publishrank' ), '', array( 'response' => 404 ) );
		}
		return $s;
	}

	/* ------------------------------------------------------------ Single actions */

	/** Old "Delete story" button now moves to the trash. */
	public function delete_story() {
		$this->lc_trash();
	}

	public function lc_trash() {
		$id = isset( $_POST['story_id'] ) ? absint( $_POST['story_id'] ) : 0;
		if ( ! wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '', 'uj_trash_story_' . $id ) && ! wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '', 'uj_delete_story_' . $id ) ) {
			wp_die( esc_html__( 'The link has expired. Go back and try again.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$s = $this->story_or_die( $id );
		if ( ! $this->can_trash( $id ) ) {
			wp_die( esc_html__( 'You can only move your own unpublished stories to the trash.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		wp_trash_post( $id );
		$this->log_event( $id, 'trashed', '' );
		$undo = wp_nonce_url( add_query_arg( array( 'action' => 'uj_restore', 'story_id' => $id ), admin_url( 'admin-post.php' ) ), 'uj_restore_story_' . $id );
		set_transient( 'uj_newsroom_undo_' . get_current_user_id(), array( 'title' => $s->post_title, 'url' => $undo ), 120 );
		wp_safe_redirect( $this->url( isset( $_POST['return'] ) && 'manage' === $_POST['return'] ? 'uj-newsroom-manage' : 'uj-newsroom', array( 'uj_msg' => __( 'Story moved to the trash.', 'publishrank' ) ) ) );
		exit;
	}

	public function lc_restore() {
		$id = isset( $_REQUEST['story_id'] ) ? absint( $_REQUEST['story_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'uj_restore_story_' . $id );
		$this->story_or_die( $id, 'trash' );
		if ( ! $this->can_restore( $id ) ) {
			wp_die( esc_html__( 'You do not have permission to restore stories.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		wp_untrash_post( $id );
		// WordPress 5.6+ restores to draft; Newsroom stories live in "publish" status internally.
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->log_event( $id, 'restored', '' );
		wp_safe_redirect( $this->url( 'uj-newsroom', array( 'edit' => $id, 'uj_msg' => __( 'Story restored.', 'publishrank' ) ) ) );
		exit;
	}

	public function lc_purge() {
		$id = isset( $_POST['story_id'] ) ? absint( $_POST['story_id'] ) : 0;
		check_admin_referer( 'uj_purge_story_' . $id );
		$this->story_or_die( $id, 'trash' );
		if ( ! $this->can_purge() ) {
			wp_die( esc_html__( 'You do not have permission to delete stories permanently.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		if ( 'DELETE' !== ( isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '' ) ) {
			wp_safe_redirect( $this->url( 'uj-newsroom-trash', array( 'uj_msg' => __( 'Type DELETE to confirm a permanent delete.', 'publishrank' ) ) ) );
			exit;
		}
		wp_delete_post( $id, true );
		wp_safe_redirect( $this->url( 'uj-newsroom-trash', array( 'uj_msg' => __( 'Story deleted permanently.', 'publishrank' ) ) ) );
		exit;
	}

	public function lc_empty_trash() {
		check_admin_referer( 'uj_empty_trash' );
		if ( ! $this->can_purge() ) {
			wp_die( esc_html__( 'You do not have permission to empty the trash.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		if ( 'DELETE' !== ( isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '' ) ) {
			wp_safe_redirect( $this->url( 'uj-newsroom-trash', array( 'uj_msg' => __( 'Type DELETE to confirm emptying the trash.', 'publishrank' ) ) ) );
			exit;
		}
		$n = $this->purge_where( 0 );
		/* translators: %d: number of stories */
		wp_safe_redirect( $this->url( 'uj-newsroom-trash', array( 'uj_msg' => sprintf( __( '%d stories deleted permanently.', 'publishrank' ), $n ) ) ) );
		exit;
	}

	/** Delete trashed stories older than $days (0 = all), in batches. */
	private function purge_where( $days, $max = 2000 ) {
		$n = 0;
		do {
			$args = array( 'post_type' => self::POST_TYPE, 'post_status' => 'trash', 'posts_per_page' => 100, 'fields' => 'ids', 'no_found_rows' => true );
			if ( $days > 0 ) {
				$args['date_query'] = array( array( 'column' => 'post_modified_gmt', 'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
			}
			$ids = get_posts( $args );
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
				++$n;
			}
		} while ( count( $ids ) === 100 && $n < $max );
		return $n;
	}

	public function lc_auto_purge() {
		return $this->purge_where( self::purge_days() );
	}

	/** Runs for every permanent delete (ours, WordPress's own trash emptying, WP-CLI). */
	public function lc_before_delete( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		$removed = 0;
		$files   = get_post_meta( $post_id, '_uj_files', true );
		if ( is_array( $files ) ) {
			foreach ( $files as $f ) {
				$name = sanitize_file_name( isset( $f['file'] ) ? (string) $f['file'] : '' );
				$path = $name ? $this->private_attachment_path( $name ) : false;
				if ( $path && is_file( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
					++$removed;
				}
			}
		}
		delete_post_meta( $post_id, '_uj_discussion' );
		delete_post_meta( $post_id, '_uj_notes' );
		$tomb   = get_option( 'uj_newsroom_tombstones', array() );
		$tomb   = is_array( $tomb ) ? $tomb : array();
		$user   = wp_get_current_user();
		$tomb[] = array(
			'id'       => (int) $post_id,
			'headline' => wp_strip_all_tags( (string) $post->post_title ),
			'by'       => $user && $user->exists() ? $user->display_name : __( 'Automatic clean-up', 'publishrank' ),
			'by_id'    => $user ? (int) $user->ID : 0,
			'at'       => current_time( 'mysql' ),
			'files'    => $removed,
		);
		update_option( 'uj_newsroom_tombstones', array_slice( $tomb, -1000 ), false );
	}

	public function lc_regen_ics() {
		check_admin_referer( 'uj_regen_ics' );
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		update_option( 'uj_newsroom_ics_token', wp_generate_password( 40, false, false ), false );
		wp_safe_redirect( $this->url( 'uj-newsroom-settings', array( 'uj_msg' => __( 'A new calendar address was created. The old one stopped working.', 'publishrank' ) ) ) );
		exit;
	}

	/* ------------------------------------------------------------ Bulk */

	public function lc_bulk_manage() {
		check_admin_referer( 'uj_bulk_manage' );
		if ( ! current_user_can( self::CAP_ACCESS ) && ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$back   = isset( $_POST['return'] ) && 'trash' === $_POST['return'] ? 'uj-newsroom-trash' : 'uj-newsroom-manage';
		$ids    = isset( $_POST['story_ids'] ) && is_array( $_POST['story_ids'] ) ? array_slice( array_map( 'absint', wp_unslash( $_POST['story_ids'] ) ), 0, 500 ) : array();
		$action = isset( $_POST['bulk'] ) ? sanitize_key( wp_unslash( $_POST['bulk'] ) ) : '';
		$raw    = isset( $_POST['value_' . $action] ) && is_string( $_POST['value_' . $action] ) ? trim( wp_unslash( $_POST['value_' . $action] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value  = sanitize_text_field( $raw );
		if ( $value !== $raw ) {
			wp_safe_redirect( $this->url( $back, array( 'uj_msg' => __( 'That value is not allowed.', 'publishrank' ) ) ) );
			exit;
		}
		if ( 'export' === $action ) {
			$this->lc_export( $ids );
		}
		$done = 0;
		$skip = 0;
		$need_manage = array( 'stage', 'reporter', 'editor', 'desk', 'priority', 'archive', 'unarchive' );
		if ( in_array( $action, $need_manage, true ) && ! current_user_can( self::CAP_MANAGE ) && ! self::nr_can( 'newsroom_edit_any' ) && ! self::nr_can( 'newsroom_assign' ) ) {
			wp_die( esc_html__( 'You do not have permission for this bulk action.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		if ( in_array( $action, array( 'reporter', 'editor' ), true ) && ! current_user_can( self::CAP_MANAGE ) && ! self::nr_can( 'newsroom_assign' ) ) {
			wp_die( esc_html__( 'Assigning people needs the “assign reporters and editors” permission.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$stages = $this->stage_names();
		foreach ( $ids as $id ) {
			$s = get_post( $id );
			if ( ! $s || self::POST_TYPE !== $s->post_type ) {
				++$skip;
				continue;
			}
			switch ( $action ) {
				case 'stage':
					if ( ! isset( $stages[ $value ] ) ) {
						++$skip;
						continue 2;
					}
					update_post_meta( $id, '_uj_stage', $value );
					$this->log_event( $id, 'stage', $stages[ $value ] );
					break;
				case 'reporter':
				case 'editor':
					$uid = absint( $value );
					if ( $uid && ! user_can( $uid, self::CAP_ACCESS ) ) {
						++$skip;
						continue 2;
					}
					update_post_meta( $id, '_uj_' . $action, $uid );
					$this->log_event( $id, 'assigned', $action . ': ' . ( $uid ? get_the_author_meta( 'display_name', $uid ) : '—' ) );
					break;
				case 'desk':
					if ( '' !== $value && ! in_array( $value, $this->desks(), true ) ) {
						++$skip;
						continue 2;
					}
					update_post_meta( $id, '_uj_desk', $value );
					break;
				case 'priority':
					if ( ! in_array( $value, array( 'low', 'normal', 'high', 'urgent' ), true ) ) {
						++$skip;
						continue 2;
					}
					update_post_meta( $id, '_uj_priority', $value );
					break;
				case 'archive':
				case 'unarchive':
					update_post_meta( $id, '_uj_archived', 'archive' === $action ? 1 : 0 );
					$this->log_event( $id, $action . 'd', '' );
					break;
				case 'trash':
					if ( 'trash' === $s->post_status || ! $this->can_trash( $id ) ) {
						++$skip;
						continue 2;
					}
					wp_trash_post( $id );
					$this->log_event( $id, 'trashed', '' );
					break;
				case 'restore':
					if ( 'trash' !== $s->post_status || ! $this->can_restore( $id ) ) {
						++$skip;
						continue 2;
					}
					wp_untrash_post( $id );
					wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
					$this->log_event( $id, 'restored', '' );
					break;
				case 'purge':
					if ( 'trash' !== $s->post_status || ! $this->can_purge() || 'DELETE' !== ( isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '' ) ) {
						++$skip;
						continue 2;
					}
					wp_delete_post( $id, true );
					break;
				default:
					++$skip;
					continue 2;
			}
			++$done;
		}
		/* translators: 1: changed, 2: skipped */
		wp_safe_redirect( $this->url( $back, array( 'uj_msg' => sprintf( __( '%1$d stories updated, %2$d skipped (no permission or not applicable).', 'publishrank' ), $done, $skip ) ) ) );
		exit;
	}

	private function lc_export( array $ids ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=newsroom-selection-' . gmdate( 'Ymd' ) . '.csv' );
		$o = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $o, array( 'ID', 'Headline', 'Stage', 'Priority', 'Desk', 'Reporter', 'Editor', 'Deadline', 'Embargo' ), ',', '"', '\\' );
		foreach ( $ids as $id ) {
			$s = get_post( $id );
			if ( ! $s || self::POST_TYPE !== $s->post_type || ! $this->can_edit_story( $id ) ) {
				continue;
			}
			$cell = static function ( $v ) {
				$v = (string) $v;
				return '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) ? "'" . $v : $v;
			};
			fputcsv( $o, array_map( $cell, array( $id, $s->post_title, $this->meta( $id, 'stage', 'pitch' ), $this->meta( $id, 'priority', 'normal' ), $this->meta( $id, 'desk' ), get_the_author_meta( 'display_name', (int) $this->meta( $id, 'reporter', 0 ) ), get_the_author_meta( 'display_name', (int) $this->meta( $id, 'editor', 0 ) ), $this->meta( $id, 'deadline' ), $this->meta( $id, 'embargo' ) ) ), ',', '"', '\\' );
		}
		fclose( $o ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ------------------------------------------------------------ Queries */

	/** IDs the current user may see (SQL, bounded). Null = everything (managers). */
	private function accessible_ids( $status = 'publish' ) {
		global $wpdb;
		if ( current_user_can( self::CAP_MANAGE ) || self::nr_can( 'newsroom_edit_any' ) ) {
			return null;
		}
		$uid = get_current_user_id();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_uj_reporter','_uj_editor') WHERE p.post_type = %s AND p.post_status = %s AND (p.post_author = %d OR m.meta_value = %s) LIMIT 20000", self::POST_TYPE, $status, $uid, (string) $uid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $ids );
	}

	/** One page of stories for the manager or trash. @return array{rows:\WP_Post[],total:int,pages:int} */
	private function story_page( array $f, $status = 'publish', $paged = 1, $per = 50 ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => $status,
			'posts_per_page' => $per,
			'paged'          => max( 1, (int) $paged ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array( 'relation' => 'AND' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		$ids = $this->accessible_ids( $status );
		if ( is_array( $ids ) ) {
			$args['post__in'] = $ids ? $ids : array( 0 );
		}
		foreach ( array( 'stage', 'priority', 'desk' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$args['meta_query'][] = array( 'key' => '_uj_' . $k, 'value' => $f[ $k ] );
			}
		}
		if ( ! empty( $f['reporter'] ) ) {
			$args['meta_query'][] = array( 'key' => '_uj_reporter', 'value' => (string) absint( $f['reporter'] ) );
		}
		if ( 'publish' === $status ) {
			if ( ! empty( $f['archived'] ) ) {
				$args['meta_query'][] = array( 'key' => '_uj_archived', 'value' => '1' );
			} else {
				$args['meta_query'][] = array( 'relation' => 'OR', array( 'key' => '_uj_archived', 'compare' => 'NOT EXISTS' ), array( 'key' => '_uj_archived', 'value' => '1', 'compare' => '!=' ) );
			}
		}
		if ( ! empty( $f['q'] ) ) {
			$args['s'] = $f['q'];
		}
		if ( ! empty( $f['orderby'] ) && in_array( $f['orderby'], array( 'title', 'modified', 'deadline' ), true ) ) {
			if ( 'deadline' === $f['orderby'] ) {
				$args['meta_key'] = '_uj_deadline'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value';
			} else {
				$args['orderby'] = $f['orderby'];
			}
			$args['order'] = isset( $f['order'] ) && 'asc' === $f['order'] ? 'ASC' : 'DESC';
		}
		$q = new \WP_Query( $args );
		return array( 'rows' => $q->posts, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages );
	}

	/** Iterate every matching story in pages (for cron jobs and feeds); never loads everything at once. */
	private function each_story( array $args, $max = 20000 ) {
		$args  = array_merge( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'no_found_rows' => true ), $args );
		$args['posts_per_page'] = 200;
		$page  = 1;
		$seen  = 0;
		do {
			$args['paged'] = $page++;
			$rows          = get_posts( $args );
			foreach ( $rows as $r ) {
				yield $r;
				if ( ++$seen >= $max ) {
					return;
				}
			}
		} while ( 200 === count( $rows ) );
	}

	/* ------------------------------------------------------------ Screens */

	private function lc_notice() {
		$msg = isset( $_GET['uj_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['uj_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $msg ) {
			echo '<div class="prx-callout prx-callout--info" role="status"><p>' . esc_html( $msg ) . '</p></div>';
		}
		$this->lc_undo_notice();
	}

	/** One-time "Undo" toast after moving a story to the trash. */
	public function lc_undo_notice() {
		$undo = get_transient( 'uj_newsroom_undo_' . get_current_user_id() );
		if ( is_array( $undo ) ) {
			delete_transient( 'uj_newsroom_undo_' . get_current_user_id() );
			echo '<div class="notice notice-warning inline uj-undo" role="status"><p>' . esc_html( sprintf( /* translators: %s: headline */ __( '“%s” is in the trash.', 'publishrank' ), $undo['title'] ) ) . ' <a class="button button-small" href="' . esc_url( $undo['url'] ) . '">' . esc_html__( 'Undo', 'publishrank' ) . '</a></p></div>';
		}
	}

	private function lc_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$g = wp_unslash( $_GET );
		return array(
			'q'        => isset( $g['q'] ) ? sanitize_text_field( $g['q'] ) : '',
			'stage'    => isset( $g['stage'] ) ? sanitize_key( $g['stage'] ) : '',
			'priority' => isset( $g['priority'] ) ? sanitize_key( $g['priority'] ) : '',
			'desk'     => isset( $g['desk'] ) ? sanitize_text_field( $g['desk'] ) : '',
			'reporter' => isset( $g['reporter'] ) ? absint( $g['reporter'] ) : 0,
			'archived' => ! empty( $g['archived'] ),
			'orderby'  => isset( $g['orderby'] ) ? sanitize_key( $g['orderby'] ) : 'modified',
			'order'    => isset( $g['order'] ) ? sanitize_key( $g['order'] ) : 'desc',
		);
		// phpcs:enable
	}

	private function newsroom_users() {
		return get_users( array( 'capability' => self::CAP_ACCESS, 'fields' => array( 'ID', 'display_name' ), 'number' => 300, 'orderby' => 'display_name' ) );
	}

	private function pager( $res, $paged ) {
		if ( $res['pages'] <= 1 ) {
			return;
		}
		echo '<nav class="prx-pagination" aria-label="' . esc_attr__( 'Pagination', 'publishrank' ) . '"><span>' . esc_html( sprintf( /* translators: 1: page 2: pages 3: total */ __( 'Page %1$d of %2$d · %3$d stories', 'publishrank' ), $paged, $res['pages'], $res['total'] ) ) . '</span>';
		if ( $paged > 1 ) {
			echo '<a class="prx-btn prx-btn--sm" href="' . esc_url( add_query_arg( 'paged', $paged - 1 ) ) . '">' . esc_html__( '← Previous', 'publishrank' ) . '</a>';
		}
		if ( $paged < $res['pages'] ) {
			echo '<a class="prx-btn prx-btn--sm" href="' . esc_url( add_query_arg( 'paged', $paged + 1 ) ) . '">' . esc_html__( 'Next →', 'publishrank' ) . '</a>';
		}
		echo '</nav>';
	}

	public function render_manage() {
		if ( ! current_user_can( self::CAP_ACCESS ) && ! current_user_can( self::CAP_MANAGE ) ) {
			return;
		}
		$f      = $this->lc_filters();
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res    = $this->story_page( $f, 'publish', $paged );
		$stages = $this->stage_names();
		$users  = $this->newsroom_users();
		$manage = current_user_can( self::CAP_MANAGE ) || self::nr_can( 'newsroom_edit_any' ) || self::nr_can( 'newsroom_assign' );
		echo '<div class="wrap pr21 prx uj-manage">';
		\PublishRankSEO\Admin\AdminAssets::header( __( 'Story Manager', 'publishrank' ), __( 'Every story in one sortable list: filter, select many (shift-click selects a range) and act on them at once.', 'publishrank' ) );
		$this->lc_notice();
		echo '<form method="get" class="prx-filters"><input type="hidden" name="page" value="uj-newsroom-manage" />';
		echo '<input class="prx-input" type="search" name="q" value="' . esc_attr( $f['q'] ) . '" placeholder="' . esc_attr__( 'Search headlines and briefs', 'publishrank' ) . '" aria-label="' . esc_attr__( 'Search stories', 'publishrank' ) . '" />';
		echo '<select name="stage" aria-label="' . esc_attr__( 'Stage', 'publishrank' ) . '"><option value="">' . esc_html__( 'All stages', 'publishrank' ) . '</option>';
		foreach ( $stages as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['stage'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select><select name="desk" aria-label="' . esc_attr__( 'Desk', 'publishrank' ) . '"><option value="">' . esc_html__( 'All desks', 'publishrank' ) . '</option>';
		foreach ( $this->desks() as $d ) {
			echo '<option value="' . esc_attr( $d ) . '"' . selected( $f['desk'], $d, false ) . '>' . esc_html( $d ) . '</option>';
		}
		echo '</select><select name="priority" aria-label="' . esc_attr__( 'Priority', 'publishrank' ) . '"><option value="">' . esc_html__( 'Any priority', 'publishrank' ) . '</option>';
		foreach ( array( 'low', 'normal', 'high', 'urgent' ) as $p ) {
			echo '<option value="' . esc_attr( $p ) . '"' . selected( $f['priority'], $p, false ) . '>' . esc_html( ucfirst( $p ) ) . '</option>';
		}
		echo '</select><select name="reporter" aria-label="' . esc_attr__( 'Reporter', 'publishrank' ) . '"><option value="">' . esc_html__( 'Any reporter', 'publishrank' ) . '</option>';
		foreach ( $users as $u ) {
			echo '<option value="' . (int) $u->ID . '"' . selected( $f['reporter'], (int) $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select><label class="prx-check"><input type="checkbox" name="archived" value="1"' . checked( $f['archived'], true, false ) . ' /> ' . esc_html__( 'Archived', 'publishrank' ) . '</label><button class="prx-btn" type="submit">' . esc_html__( 'Filter', 'publishrank' ) . '</button></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="uj-manage-form"><input type="hidden" name="action" value="uj_bulk_manage" />';
		wp_nonce_field( 'uj_bulk_manage' );
		echo '<div class="prx-toolbar uj-bulkbar" data-uj-bulkbar><span class="uj-bulkbar__count" data-uj-count aria-live="polite">' . esc_html__( '0 selected', 'publishrank' ) . '</span><select name="bulk" aria-label="' . esc_attr__( 'Bulk action', 'publishrank' ) . '"><option value="">' . esc_html__( 'Bulk action…', 'publishrank' ) . '</option>';
		if ( $manage ) {
			echo '<option value="stage">' . esc_html__( 'Change stage', 'publishrank' ) . '</option><option value="reporter">' . esc_html__( 'Assign reporter', 'publishrank' ) . '</option><option value="editor">' . esc_html__( 'Assign editor', 'publishrank' ) . '</option><option value="desk">' . esc_html__( 'Set desk', 'publishrank' ) . '</option><option value="priority">' . esc_html__( 'Set priority', 'publishrank' ) . '</option><option value="' . ( $f['archived'] ? 'unarchive' : 'archive' ) . '">' . esc_html( $f['archived'] ? __( 'Unarchive', 'publishrank' ) : __( 'Archive', 'publishrank' ) ) . '</option>';
		}
		echo '<option value="trash">' . esc_html__( 'Move to trash', 'publishrank' ) . '</option><option value="export">' . esc_html__( 'Export selection (CSV)', 'publishrank' ) . '</option></select>';
		echo '<select name="value_stage" data-prx-show-when="bulk=stage" aria-label="' . esc_attr__( 'New stage', 'publishrank' ) . '">';
		foreach ( $stages as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
		}
		echo '</select>';
		foreach ( array( 'reporter', 'editor' ) as $who ) {
			echo '<select name="value_' . esc_attr( $who ) . '" data-prx-show-when="bulk=' . esc_attr( $who ) . '" aria-label="' . esc_attr( 'reporter' === $who ? __( 'Reporter', 'publishrank' ) : __( 'Editor', 'publishrank' ) ) . '"><option value="0">' . esc_html__( 'Nobody', 'publishrank' ) . '</option>';
			foreach ( $users as $u ) {
				echo '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name ) . '</option>';
			}
			echo '</select>';
		}
		echo '<select name="value_desk" data-prx-show-when="bulk=desk" aria-label="' . esc_attr__( 'Desk', 'publishrank' ) . '"><option value="">' . esc_html__( 'No desk', 'publishrank' ) . '</option>';
		foreach ( $this->desks() as $d ) {
			echo '<option value="' . esc_attr( $d ) . '">' . esc_html( $d ) . '</option>';
		}
		echo '</select><select name="value_priority" data-prx-show-when="bulk=priority" aria-label="' . esc_attr__( 'Priority', 'publishrank' ) . '">';
		foreach ( array( 'low', 'normal', 'high', 'urgent' ) as $p ) {
			echo '<option value="' . esc_attr( $p ) . '">' . esc_html( ucfirst( $p ) ) . '</option>';
		}
		echo '</select><button class="prx-btn prx-btn--primary" type="submit" data-uj-apply disabled>' . esc_html__( 'Apply', 'publishrank' ) . '</button></div>';

		$sort = static function ( $key, $label ) use ( $f ) {
			$dir = ( $f['orderby'] === $key && 'desc' === $f['order'] ) ? 'asc' : 'desc';
			return '<a href="' . esc_url( add_query_arg( array( 'orderby' => $key, 'order' => $dir, 'paged' => 1 ) ) ) . '">' . esc_html( $label ) . '</a>';
		};
		echo '<div class="prx-table-wrap"><table class="prx-table uj-table"><thead><tr><th class="prx-col-check"><input type="checkbox" data-prx-check-all aria-label="' . esc_attr__( 'Select all on this page', 'publishrank' ) . '" /></th><th>' . $sort( 'title', __( 'Headline', 'publishrank' ) ) . '</th><th>' . esc_html__( 'Stage', 'publishrank' ) . '</th><th>' . esc_html__( 'Desk', 'publishrank' ) . '</th><th>' . esc_html__( 'Reporter', 'publishrank' ) . '</th><th>' . $sort( 'deadline', __( 'Deadline', 'publishrank' ) ) . '</th><th>' . $sort( 'modified', __( 'Updated', 'publishrank' ) ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'publishrank' ) . '</span></th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="8"><div class="prx-empty"><span class="fa-solid fa-newspaper" aria-hidden="true"></span><p>' . esc_html__( 'No stories match.', 'publishrank' ) . '</p></div></td></tr>';
		}
		foreach ( $res['rows'] as $s ) {
			$id    = (int) $s->ID;
			$stage = $this->meta( $id, 'stage', 'pitch' );
			$due   = $this->meta( $id, 'deadline' );
			$late  = method_exists( $this, 'is_overdue' ) && $this->is_overdue( $s );
			$rep   = (int) $this->meta( $id, 'reporter', 0 );
			echo '<tr><td class="prx-col-check"><input type="checkbox" name="story_ids[]" value="' . (int) $id . '" data-uj-pick aria-label="' . esc_attr( sprintf( /* translators: %s: headline */ __( 'Select %s', 'publishrank' ), $s->post_title ) ) . '" /></td>';
			echo '<td><a href="' . esc_url( $this->url( 'uj-newsroom', array( 'edit' => $id ) ) ) . '"><strong>' . esc_html( $s->post_title ) . '</strong></a> ' . \PublishRankSEO\Admin\Suite::badge( ucfirst( $this->meta( $id, 'priority', 'normal' ) ), 'urgent' === $this->meta( $id, 'priority', 'normal' ) ? 'bad' : ( 'high' === $this->meta( $id, 'priority', 'normal' ) ? 'warn' : 'muted' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( isset( $stages[ $stage ] ) ? $stages[ $stage ] : $stage ) . '</td><td>' . esc_html( $this->meta( $id, 'desk' ) ) . '</td><td>' . esc_html( $rep ? get_the_author_meta( 'display_name', $rep ) : '—' ) . '</td>';
			echo '<td>' . ( $due ? esc_html( str_replace( 'T', ' ', $due ) ) . ( $late ? ' ' . \PublishRankSEO\Admin\Suite::badge( __( 'overdue', 'publishrank' ), 'bad' ) : '' ) : '—' ) . '</td><td>' . esc_html( \PublishRankSEO\Admin\Suite::ago( get_post_modified_time( 'U', true, $s ) ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="prx-actions"><a class="prx-icon-btn" href="' . esc_url( $this->url( 'uj-newsroom', array( 'edit' => $id ) ) ) . '" title="' . esc_attr__( 'Open', 'publishrank' ) . '"><span class="fa-solid fa-pen" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Open', 'publishrank' ) . '</span></a></td></tr>';
		}
		echo '</tbody></table></div></form>';
		$this->pager( $res, $paged );
		echo '</div>';
	}

	public function render_trash() {
		if ( ! current_user_can( self::CAP_ACCESS ) && ! current_user_can( self::CAP_MANAGE ) ) {
			return;
		}
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res   = $this->story_page( array( 'q' => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '' ), 'trash', $paged ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$purge = $this->can_purge();
		echo '<div class="wrap pr21 prx uj-trash">';
		\PublishRankSEO\Admin\AdminAssets::header( __( 'Newsroom Trash', 'publishrank' ), sprintf( /* translators: %d: days */ __( 'Trashed stories keep their notes, files and history. They are deleted for good after %d days, or when you delete them here.', 'publishrank' ), self::purge_days() ) );
		$this->lc_notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="uj-trash-form"><input type="hidden" name="action" value="uj_bulk_manage" /><input type="hidden" name="return" value="trash" />';
		wp_nonce_field( 'uj_bulk_manage' );
		echo '<div class="prx-toolbar uj-bulkbar" data-uj-bulkbar><span class="uj-bulkbar__count" data-uj-count aria-live="polite">' . esc_html__( '0 selected', 'publishrank' ) . '</span><select name="bulk" aria-label="' . esc_attr__( 'Bulk action', 'publishrank' ) . '"><option value="restore">' . esc_html__( 'Restore', 'publishrank' ) . '</option>' . ( $purge ? '<option value="purge">' . esc_html__( 'Delete permanently', 'publishrank' ) . '</option>' : '' ) . '</select>';
		if ( $purge ) {
			echo '<input class="prx-input prx-input--sm" type="text" name="confirm" placeholder="' . esc_attr__( 'Type DELETE', 'publishrank' ) . '" aria-label="' . esc_attr__( 'Type DELETE to confirm a permanent delete', 'publishrank' ) . '" data-prx-show-when="bulk=purge" autocomplete="off" />';
		}
		echo '<button class="prx-btn prx-btn--primary" type="submit" data-uj-apply disabled>' . esc_html__( 'Apply', 'publishrank' ) . '</button></div>';
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th class="prx-col-check"><input type="checkbox" data-prx-check-all aria-label="' . esc_attr__( 'Select all on this page', 'publishrank' ) . '" /></th><th>' . esc_html__( 'Headline', 'publishrank' ) . '</th><th>' . esc_html__( 'Trashed', 'publishrank' ) . '</th><th>' . esc_html__( 'Deleted for good', 'publishrank' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="5"><div class="prx-empty"><span class="fa-solid fa-trash-can" aria-hidden="true"></span><p>' . esc_html__( 'The trash is empty.', 'publishrank' ) . '</p></div></td></tr>';
		}
		foreach ( $res['rows'] as $s ) {
			$id   = (int) $s->ID;
			$when = (int) get_post_meta( $id, '_wp_trash_meta_time', true );
			echo '<tr><td class="prx-col-check"><input type="checkbox" name="story_ids[]" value="' . (int) $id . '" data-uj-pick aria-label="' . esc_attr( sprintf( /* translators: %s: headline */ __( 'Select %s', 'publishrank' ), $s->post_title ) ) . '" /></td><td><strong>' . esc_html( $s->post_title ) . '</strong></td><td>' . esc_html( $when ? \PublishRankSEO\Admin\Suite::ago( $when ) : '—' ) . '</td><td>' . esc_html( $when ? wp_date( get_option( 'date_format' ), $when + self::purge_days() * DAY_IN_SECONDS ) : '—' ) . '</td><td class="prx-actions">';
			if ( $this->can_restore( $id ) ) {
				echo '<a class="prx-btn prx-btn--sm" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'uj_restore', 'story_id' => $id ), admin_url( 'admin-post.php' ) ), 'uj_restore_story_' . $id ) ) . '">' . esc_html__( 'Restore', 'publishrank' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></form>';
		$this->pager( $res, $paged );
		if ( $purge && $res['total'] ) {
			echo '<section class="prx-card uj-empty-trash"><h2 class="prx-card__title">' . esc_html__( 'Empty the trash', 'publishrank' ) . '</h2><p class="prx-muted">' . esc_html__( 'Deletes every trashed story, its private files and its discussion. A record of the headline, who deleted it and when is kept.', 'publishrank' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="prx-filters"><input type="hidden" name="action" value="uj_empty_trash" />';
			wp_nonce_field( 'uj_empty_trash' );
			echo '<input class="prx-input prx-input--sm" type="text" name="confirm" required pattern="DELETE" placeholder="' . esc_attr__( 'Type DELETE', 'publishrank' ) . '" aria-label="' . esc_attr__( 'Type DELETE to empty the trash', 'publishrank' ) . '" autocomplete="off" /><button class="prx-btn prx-btn--danger" type="submit">' . esc_html__( 'Empty trash', 'publishrank' ) . '</button></form></section>';
		}
		$tomb = array_reverse( (array) get_option( 'uj_newsroom_tombstones', array() ) );
		if ( $tomb ) {
			echo '<section class="prx-card"><h2 class="prx-card__title">' . esc_html__( 'Permanently deleted', 'publishrank' ) . '</h2><ul class="prx-timeline">';
			foreach ( array_slice( $tomb, 0, 20 ) as $t ) {
				echo '<li><strong>' . esc_html( $t['headline'] ) . '</strong> · ' . esc_html( $t['by'] ) . ' · ' . esc_html( $t['at'] ) . ( ! empty( $t['files'] ) ? ' · ' . esc_html( sprintf( /* translators: %d: files */ _n( '%d file removed', '%d files removed', (int) $t['files'], 'publishrank' ), (int) $t['files'] ) ) : '' ) . '</li>';
			}
			echo '</ul></section>';
		}
		echo '</div>';
	}
}
