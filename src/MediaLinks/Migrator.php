<?php
/**
 * Media Link Manager operations: plan (dry run), apply in batches, verify, finalize and roll back.
 *
 * Operations
 * - relocate:    rename or move an attachment's files inside the uploads folder. Files are copied first and the
 *                originals are kept, so old URLs keep loading until the publisher finalizes. Attachment metadata
 *                and every stored reference are updated; the GUID is never touched.
 * - replace_url: point stored references to a different URL (another host, a CDN copy, a replacement file).
 *                The attachment itself and its files are not changed.
 * - rebase:      site-wide media host change for stored references (for example after a domain migration):
 *                old uploads base URL => new uploads base URL.
 * - slug:        change the attachment page address (post_name), optionally with a redirect.
 *
 * Safety
 * - Nothing is written during planning. Each planned change stores the exact current raw value; when applying,
 *   a value that changed since the plan is marked "conflict" and skipped.
 * - Values are rewritten by Replacer (boundary-aware, serialized-safe, JSON-safe). Never a database-wide SQL REPLACE.
 * - Batches run in a transaction where supported. Rollback restores old values only where the current value is
 *   still the value this run wrote.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\MediaLinks;

defined( 'ABSPATH' ) || exit;

final class Migrator {
	const BATCH       = 40;
	const BACKUP_DIR  = 'publishrank-media-backup';

	/* -------------------------------------------------------------------- Planning */

	/**
	 * Build a dry-run plan and store it as a run.
	 *
	 * @return array|\WP_Error Run.
	 */
	public static function plan( $operation, $attachment_id, array $params ) {
		if ( ! Store::ready() ) {
			return new \WP_Error( 'publishrank_media_tables', __( 'The Media Link Manager tables are not installed yet. Reload the page and try again.', 'publishrank' ) );
		}
		switch ( $operation ) {
			case 'relocate':
				$built = self::plan_relocate( (int) $attachment_id, $params );
				break;
			case 'replace_url':
				$built = self::plan_replace( (int) $attachment_id, $params );
				break;
			case 'rebase':
				$built = self::plan_rebase( $params );
				break;
			case 'slug':
				$built = self::plan_slug( (int) $attachment_id, $params );
				break;
			default:
				return new \WP_Error( 'publishrank_media_op', __( 'Unknown operation.', 'publishrank' ) );
		}
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$id = Store::create_run( $built['run'], $built['changes'] );
		if ( ! $id ) {
			return new \WP_Error( 'publishrank_media_store', __( 'The plan could not be saved.', 'publishrank' ) );
		}
		return self::summary( $id );
	}

	/** References a URL map would change, as change rows. */
	private static function reference_changes( $likes, Replacer $replacer, $seq_start, $exclude_attachment = 0 ) {
		$changes = array();
		$seq     = $seq_start;
		foreach ( Scanner::url_records( $likes, $replacer ) as $r ) {
			if ( $exclude_attachment && 'postmeta' === $r['object_type'] && (int) $r['object_id'] === (int) $exclude_attachment && in_array( $r['field'], Scanner::SKIP_META, true ) ) {
				continue;
			}
			$n   = 0;
			$err = '';
			$new = $replacer->replace_raw( $r['raw'], $n, $err );
			$changes[] = array(
				'seq'         => $seq++,
				'object_type' => $r['object_type'],
				'object_id'   => $r['object_id'],
				'sub_id'      => $r['sub_id'],
				'field'       => $r['field'],
				'label'       => $r['location'] . ' · ' . $r['label'],
				'old_value'   => (string) $r['raw'],
				'new_value'   => $err ? (string) $r['raw'] : (string) $new,
				'matches'     => $err ? 0 : $n,
				'status'      => $err ? 'skipped' : ( $n ? 'pending' : 'skipped' ),
				'message'     => $err ? $err : ( $n ? '' : __( 'No exact URL match after boundary checks.', 'publishrank' ) ),
			);
		}
		return $changes;
	}

	/** Normalise a requested uploads-relative destination. @return string|\WP_Error */
	public static function clean_destination( $requested, $current_rel ) {
		$requested = trim( wp_normalize_path( (string) $requested ) );
		$requested = ltrim( $requested, '/' );
		if ( '' === $requested ) {
			return new \WP_Error( 'publishrank_media_dest', __( 'Enter a new file name.', 'publishrank' ) );
		}
		if ( false !== strpos( $requested, '..' ) || false !== strpos( $requested, "\0" ) || preg_match( '~^[a-z]+:~i', $requested ) ) {
			return new \WP_Error( 'publishrank_media_dest', __( 'The destination must be a path inside the uploads folder.', 'publishrank' ) );
		}
		if ( false === strpos( $requested, '/' ) ) {
			$dir       = dirname( $current_rel );
			$requested = ( '.' === $dir ? '' : $dir . '/' ) . $requested;
		}
		$parts = array();
		foreach ( explode( '/', $requested ) as $seg ) {
			$seg = sanitize_file_name( $seg );
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			$parts[] = $seg;
		}
		if ( ! $parts ) {
			return new \WP_Error( 'publishrank_media_dest', __( 'Enter a valid file name.', 'publishrank' ) );
		}
		$rel     = implode( '/', $parts );
		$old_ext = strtolower( pathinfo( $current_rel, PATHINFO_EXTENSION ) );
		$new_ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		if ( '' === $new_ext ) {
			$rel    .= '.' . $old_ext;
			$new_ext = $old_ext;
		}
		if ( $new_ext !== $old_ext ) {
			return new \WP_Error( 'publishrank_media_ext', sprintf( /* translators: %s: file extension */ __( 'Keep the .%s extension. Changing the extension does not convert the file and would serve it with the wrong type.', 'publishrank' ), $old_ext ) );
		}
		return $rel;
	}

	private static function plan_relocate( $id, array $params ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'publishrank_media_missing', __( 'Attachment not found.', 'publishrank' ) );
		}
		$files = Scanner::files( $id );
		if ( ! $files ) {
			return new \WP_Error( 'publishrank_media_external', __( 'This attachment has no file in the uploads folder, so it cannot be relocated. Use "Point references to another URL" instead.', 'publishrank' ) );
		}
		$full = $files[0];
		foreach ( $files as $f ) {
			if ( ! file_exists( $f['path'] ) ) {
				return new \WP_Error( 'publishrank_media_missing_file', sprintf( /* translators: %s: file */ __( 'A file of this attachment is missing (%s). Repair or regenerate it before moving the attachment.', 'publishrank' ), $f['rel'] ) );
			}
		}
		$dest = self::clean_destination( isset( $params['destination'] ) ? $params['destination'] : '', $full['rel'] );
		if ( is_wp_error( $dest ) ) {
			return $dest;
		}
		if ( $dest === $full['rel'] ) {
			return new \WP_Error( 'publishrank_media_same', __( 'The new location is the same as the current one.', 'publishrank' ) );
		}
		$meta = wp_get_attachment_metadata( $id );
		$ext  = '.' . pathinfo( $full['rel'], PATHINFO_EXTENSION );
		// Base stem shared by every file: the pre-scaled original when present, otherwise the main file minus -scaled.
		$base_src = is_array( $meta ) && ! empty( $meta['original_image'] ) ? (string) $meta['original_image'] : basename( $full['rel'] );
		$old_stem = preg_replace( '/\.[A-Za-z0-9]+$/', '', basename( $base_src ) );
		$new_main = basename( $dest );
		$new_stem = preg_replace( '/(-scaled|-rotated)?\.[A-Za-z0-9]+$/', '', $new_main );
		$new_dir  = dirname( $dest );
		$new_dir  = '.' === $new_dir ? '' : $new_dir . '/';
		$up       = Scanner::uploads();
		$pairs    = array();
		$moves    = array();
		$taken    = array();
		foreach ( $files as $f ) {
			$b = basename( $f['rel'] );
			if ( 0 !== strpos( $b, $old_stem ) ) {
				return new \WP_Error( 'publishrank_media_names', sprintf( /* translators: %s: file */ __( 'The file %s does not follow WordPress size naming, so its new name cannot be derived safely.', 'publishrank' ), $b ) );
			}
			$nb      = $new_stem . substr( $b, strlen( $old_stem ) );
			$new_rel = $new_dir . $nb;
			$path    = $up['basedir'] . '/' . $new_rel;
			if ( file_exists( $path ) ) {
				$taken[] = $new_rel;
			}
			$moves[]               = array( 'kind' => $f['kind'], 'old_rel' => $f['rel'], 'new_rel' => $new_rel, 'old_path' => $f['path'], 'new_path' => $path );
			$pairs[ $f['url'] ]    = $up['baseurl'] . '/' . $new_rel;
		}
		if ( $taken ) {
			return new \WP_Error( 'publishrank_media_collision', sprintf( /* translators: %s: list of files */ __( 'Files already exist at the destination, choose another name: %s', 'publishrank' ), implode( ', ', array_slice( $taken, 0, 6 ) ) ) );
		}
		$dest_dir = dirname( $up['basedir'] . '/' . $moves[0]['new_rel'] );
		$probe    = $dest_dir;
		while ( ! is_dir( $probe ) && strlen( $probe ) > strlen( $up['basedir'] ) ) {
			$probe = dirname( $probe );
		}
		if ( ! wp_is_writable( $probe ) ) {
			return new \WP_Error( 'publishrank_media_writable', __( 'The destination folder is not writable by WordPress.', 'publishrank' ) );
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $up['basedir'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$need = 0;
		foreach ( $moves as $m ) {
			$need += (int) @filesize( $m['old_path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( false !== $free && $free < $need * 2 ) {
			return new \WP_Error( 'publishrank_media_space', __( 'There is not enough free disk space to copy the files safely.', 'publishrank' ) );
		}
		$changes = array();
		$seq     = 0;
		foreach ( $moves as $m ) {
			$changes[] = array( 'seq' => $seq++, 'object_type' => 'file', 'object_id' => $id, 'sub_id' => 0, 'field' => $m['kind'], 'label' => sprintf( /* translators: 1: old, 2: new */ __( 'Copy file %1$s → %2$s', 'publishrank' ), $m['old_rel'], $m['new_rel'] ), 'old_value' => $m['old_rel'], 'new_value' => $m['new_rel'], 'matches' => 1 );
		}
		global $wpdb;
		// Attachment metadata: _wp_attached_file and every file name in _wp_attachment_metadata.
		$seq = 100;
		foreach ( array( '_wp_attached_file', '_wp_attachment_metadata' ) as $key ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $id, $key ) );
			if ( ! $row ) {
				continue;
			}
			if ( '_wp_attached_file' === $key ) {
				$new = $moves[0]['new_rel'];
			} else {
				$m = maybe_unserialize( $row->meta_value );
				if ( ! is_array( $m ) ) {
					continue;
				}
				$map = array();
				foreach ( $moves as $mv ) {
					$map[ basename( $mv['old_rel'] ) ] = basename( $mv['new_rel'] );
				}
				if ( isset( $m['file'] ) ) {
					$m['file'] = $moves[0]['new_rel'];
				}
				if ( ! empty( $m['original_image'] ) && isset( $map[ $m['original_image'] ] ) ) {
					$m['original_image'] = $map[ $m['original_image'] ];
				}
				if ( ! empty( $m['sizes'] ) && is_array( $m['sizes'] ) ) {
					foreach ( $m['sizes'] as $k => $s ) {
						if ( isset( $s['file'], $map[ $s['file'] ] ) ) {
							$m['sizes'][ $k ]['file'] = $map[ $s['file'] ];
						}
					}
				}
				$new = serialize( $m ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- stored exactly as WordPress stores it.
			}
			$changes[] = array( 'seq' => $seq++, 'object_type' => 'postmeta', 'object_id' => $id, 'sub_id' => (int) $row->meta_id, 'field' => $key, 'label' => __( 'Attachment metadata', 'publishrank' ) . ' · ' . $key, 'old_value' => (string) $row->meta_value, 'new_value' => (string) $new, 'matches' => 1 );
		}
		$replacer = new Replacer( Scanner::spelling_map( $pairs ) );
		$changes  = array_merge( $changes, self::reference_changes( $id, $replacer, 1000, $id ) );
		return array(
			'run'     => array(
				'attachment_id' => $id,
				'operation'     => 'relocate',
				'old_url'       => $full['url'],
				'new_url'       => $pairs[ $full['url'] ],
				'plan'          => array( 'pairs' => $pairs, 'moves' => $moves, 'redirect' => ! empty( $params['redirect'] ), 'mime' => (string) $post->post_mime_type, 'kind' => __( 'Physical file relocation inside uploads', 'publishrank' ) ),
			),
			'changes' => $changes,
		);
	}

	/** Validate an absolute http(s) URL. @return string|\WP_Error */
	public static function clean_url( $url ) {
		$url = trim( (string) $url );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean || ! wp_parse_url( $clean, PHP_URL_HOST ) || false === filter_var( $clean, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'publishrank_media_url', __( 'Enter a complete URL that starts with https:// or http://.', 'publishrank' ) );
		}
		return $clean;
	}

	private static function plan_replace( $id, array $params ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'publishrank_media_missing', __( 'Attachment not found.', 'publishrank' ) );
		}
		$new = self::clean_url( isset( $params['new_url'] ) ? $params['new_url'] : '' );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$files = Scanner::files( $id );
		if ( ! $files ) {
			return new \WP_Error( 'publishrank_media_external', __( 'This attachment has no uploads file to map from.', 'publishrank' ) );
		}
		foreach ( $files as $f ) {
			if ( $f['url'] === $new || in_array( $new, Scanner::spellings( $f['url'] ), true ) ) {
				return new \WP_Error( 'publishrank_media_same', __( 'The new URL is the attachment\'s own URL.', 'publishrank' ) );
			}
		}
		$check = self::http_check( $new, (string) $post->post_mime_type );
		if ( 'fail' === $check['status'] && empty( $params['force'] ) ) {
			return new \WP_Error( 'publishrank_media_dest_broken', sprintf( /* translators: %s: details */ __( 'The new URL does not load correctly (%s). Nothing was planned, so no broken link is introduced.', 'publishrank' ), $check['detail'] ) );
		}
		$pairs = array();
		foreach ( $files as $f ) {
			$pairs[ $f['url'] ] = $new;
		}
		$replacer = new Replacer( Scanner::spelling_map( $pairs ) );
		$changes  = self::reference_changes( $id, $replacer, 1000, $id );
		return array(
			'run'     => array(
				'attachment_id' => $id,
				'operation'     => 'replace_url',
				'old_url'       => $files[0]['url'],
				'new_url'       => $new,
				'plan'          => array( 'pairs' => $pairs, 'mime' => (string) $post->post_mime_type, 'precheck' => $check, 'kind' => __( 'Reference change only: the attachment and its files stay as they are', 'publishrank' ) ),
			),
			'changes' => $changes,
		);
	}

	private static function plan_rebase( array $params ) {
		$old = self::clean_url( isset( $params['old_base'] ) ? $params['old_base'] : '' );
		if ( is_wp_error( $old ) ) {
			return $old;
		}
		$up  = Scanner::uploads();
		$new = isset( $params['new_base'] ) && '' !== trim( (string) $params['new_base'] ) ? self::clean_url( $params['new_base'] ) : $up['baseurl'];
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$old = untrailingslashit( $old );
		$new = untrailingslashit( $new );
		$oh  = (string) wp_parse_url( $old, PHP_URL_HOST ) . ( wp_parse_url( $old, PHP_URL_PORT ) ? ':' . wp_parse_url( $old, PHP_URL_PORT ) : '' );
		$op  = (string) wp_parse_url( $old, PHP_URL_PATH );
		if ( '' === $op || '/' === $op ) {
			return new \WP_Error( 'publishrank_media_base', __( 'Include the uploads path, for example https://old.example.com/wp-content/uploads. A bare domain would also change links that are not media.', 'publishrank' ) );
		}
		if ( in_array( $new, array( 'https://' . $oh . $op, 'http://' . $oh . $op ), true ) ) {
			return new \WP_Error( 'publishrank_media_same', __( 'The old and new base URLs are the same.', 'publishrank' ) );
		}
		// Relative spellings are never part of a rebase: a relative path already resolves against this site.
		$map = array( 'https://' . $oh . $op => $new, 'http://' . $oh . $op => $new, '//' . $oh . $op => preg_replace( '~^https?:~', '', $new ) );
		$replacer = new Replacer( $map );
		$changes  = self::reference_changes( Scanner::likes_for( $oh . $op ), $replacer, 1000 );
		return array(
			'run'     => array(
				'attachment_id' => 0,
				'operation'     => 'rebase',
				'old_url'       => $old,
				'new_url'       => $new,
				'plan'          => array( 'map' => $map, 'kind' => __( 'Media host change for stored references across the site', 'publishrank' ), 'limit_note' => sprintf( /* translators: %d: limit */ __( 'Up to %d records per table are planned at a time. Plan again after applying to catch any remaining records.', 'publishrank' ), Scanner::LIMIT ) ),
			),
			'changes' => $changes,
		);
	}

	private static function plan_slug( $id, array $params ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'publishrank_media_missing', __( 'Attachment not found.', 'publishrank' ) );
		}
		$slug = sanitize_title( isset( $params['slug'] ) ? (string) $params['slug'] : '' );
		if ( '' === $slug || $slug === $post->post_name ) {
			return new \WP_Error( 'publishrank_media_slug', __( 'Enter a new, different attachment page slug.', 'publishrank' ) );
		}
		$unique = wp_unique_post_slug( $slug, $id, $post->post_status, 'attachment', (int) $post->post_parent );
		$old    = get_attachment_link( $id );
		return array(
			'run'     => array(
				'attachment_id' => $id,
				'operation'     => 'slug',
				'old_url'       => $old,
				'new_url'       => '',
				'plan'          => array( 'redirect' => ! empty( $params['redirect'] ), 'slug' => $unique, 'old_link' => $old, 'pages_enabled' => self::attachment_pages_enabled(), 'kind' => __( 'Attachment page address change', 'publishrank' ) ),
			),
			'changes' => array(
				array( 'seq' => 0, 'object_type' => 'post', 'object_id' => $id, 'sub_id' => 0, 'field' => 'post_name', 'label' => __( 'Attachment page slug', 'publishrank' ) . ( $unique !== $slug ? ' (' . __( 'made unique', 'publishrank' ) . ')' : '' ), 'old_value' => (string) $post->post_name, 'new_value' => $unique, 'matches' => 1 ),
			),
		);
	}

	public static function attachment_pages_enabled() {
		return ! function_exists( 'wp_attachment_pages_enabled' ) ? true : (bool) get_option( 'wp_attachment_pages_enabled', 1 );
	}

	/* -------------------------------------------------------------------- Reading / writing one change */

	/** Current raw value of a change target, or null when the target is gone. */
	public static function current( array $c ) {
		global $wpdb;
		switch ( $c['object_type'] ) {
			case 'post':
				$f = in_array( $c['field'], array( 'post_content', 'post_excerpt', 'post_name' ), true ) ? $c['field'] : '';
				return $f ? $wpdb->get_var( $wpdb->prepare( "SELECT {$f} FROM {$wpdb->posts} WHERE ID = %d", (int) $c['object_id'] ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			case 'postmeta':
				return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", (int) $c['sub_id'] ) );
			case 'termmeta':
				return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_id = %d", (int) $c['sub_id'] ) );
			case 'usermeta':
				return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE umeta_id = %d", (int) $c['sub_id'] ) );
			case 'commentmeta':
				return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->commentmeta} WHERE meta_id = %d", (int) $c['sub_id'] ) );
			case 'option':
				return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_id = %d", (int) $c['object_id'] ) );
			case 'comment':
				return $wpdb->get_var( $wpdb->prepare( "SELECT comment_content FROM {$wpdb->comments} WHERE comment_ID = %d", (int) $c['object_id'] ) );
		}
		return null;
	}

	/** Write a raw value exactly as given. */
	private static function write( array $c, $value ) {
		global $wpdb;
		$ok = false;
		switch ( $c['object_type'] ) {
			case 'post':
				$ok = false !== $wpdb->update( $wpdb->posts, array( $c['field'] => $value ), array( 'ID' => (int) $c['object_id'] ) );
				break;
			case 'postmeta':
				$ok = false !== $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $value ), array( 'meta_id' => (int) $c['sub_id'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				break;
			case 'termmeta':
				$ok = false !== $wpdb->update( $wpdb->termmeta, array( 'meta_value' => $value ), array( 'meta_id' => (int) $c['sub_id'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				break;
			case 'usermeta':
				$ok = false !== $wpdb->update( $wpdb->usermeta, array( 'meta_value' => $value ), array( 'umeta_id' => (int) $c['sub_id'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				break;
			case 'commentmeta':
				$ok = false !== $wpdb->update( $wpdb->commentmeta, array( 'meta_value' => $value ), array( 'meta_id' => (int) $c['sub_id'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				break;
			case 'option':
				$ok = false !== $wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_id' => (int) $c['object_id'] ) );
				break;
			case 'comment':
				$ok = false !== $wpdb->update( $wpdb->comments, array( 'comment_content' => $value ), array( 'comment_ID' => (int) $c['object_id'] ) );
				break;
		}
		self::clean_cache( $c );
		return $ok;
	}

	private static function clean_cache( array $c ) {
		switch ( $c['object_type'] ) {
			case 'post':
			case 'postmeta':
				clean_post_cache( (int) $c['object_id'] );
				wp_cache_delete( (int) $c['object_id'], 'post_meta' );
				if ( 'attachment' === get_post_type( (int) $c['object_id'] ) ) {
					clean_attachment_cache( (int) $c['object_id'] );
				}
				break;
			case 'termmeta':
				$t = get_term( (int) $c['object_id'] );
				wp_cache_delete( (int) $c['object_id'], 'term_meta' );
				if ( $t && ! is_wp_error( $t ) ) {
					clean_term_cache( (int) $c['object_id'], $t->taxonomy );
				}
				break;
			case 'usermeta':
				clean_user_cache( (int) $c['object_id'] );
				wp_cache_delete( (int) $c['object_id'], 'user_meta' );
				break;
			case 'commentmeta':
			case 'comment':
				clean_comment_cache( (int) $c['object_id'] );
				break;
			case 'option':
				wp_cache_delete( $c['field'], 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				break;
		}
	}

	private static function file_apply( array $c ) {
		$up  = Scanner::uploads();
		$src = $up['basedir'] . '/' . $c['old_value'];
		$dst = $up['basedir'] . '/' . $c['new_value'];
		if ( ! file_exists( $src ) ) {
			return array( 'failed', __( 'Source file is missing.', 'publishrank' ) );
		}
		if ( file_exists( $dst ) ) {
			return md5_file( $src ) === md5_file( $dst ) ? array( 'applied', __( 'Identical copy already present.', 'publishrank' ) ) : array( 'conflict', __( 'A different file already exists at the destination.', 'publishrank' ) );
		}
		if ( ! wp_mkdir_p( dirname( $dst ) ) ) {
			return array( 'failed', __( 'Could not create the destination folder.', 'publishrank' ) );
		}
		if ( ! @copy( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			return array( 'failed', __( 'Could not copy the file.', 'publishrank' ) );
		}
		if ( filesize( $src ) !== filesize( $dst ) || md5_file( $src ) !== md5_file( $dst ) ) {
			@unlink( $dst ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			return array( 'failed', __( 'The copy did not match the original and was removed.', 'publishrank' ) );
		}
		$stat  = @stat( dirname( $dst ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$perms = $stat ? $stat['mode'] & 0000666 : 0644;
		@chmod( $dst, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		return array( 'applied', '' );
	}

	private static function file_revert( array $c ) {
		$up  = Scanner::uploads();
		$src = $up['basedir'] . '/' . $c['old_value'];
		$dst = $up['basedir'] . '/' . $c['new_value'];
		if ( ! file_exists( $src ) ) {
			return array( 'conflict', __( 'The original file is missing; the new copy was kept so nothing breaks.', 'publishrank' ) );
		}
		if ( file_exists( $dst ) && md5_file( $src ) === md5_file( $dst ) ) {
			@unlink( $dst ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		return array( 'rolled_back', '' );
	}

	/* -------------------------------------------------------------------- Apply / rollback */

	/**
	 * Apply the next batch of a run.
	 *
	 * @return array|\WP_Error Summary with progress.
	 */
	public static function apply_batch( $run_id, $size = self::BATCH ) {
		$run = Store::run( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'publishrank_media_run', __( 'Run not found.', 'publishrank' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $run['status'], array( 'planned', 'applying', 'partial' ), true ) ) {
			return new \WP_Error( 'publishrank_media_state', __( 'This run cannot be applied in its current state.', 'publishrank' ) );
		}
		if ( 'planned' === $run['status'] ) {
			Store::update_run( $run_id, array( 'status' => 'applying' ) );
		}
		$batch = Store::changes( $run_id, 'pending', 'ASC', max( 1, min( 200, (int) $size ) ) );
		if ( $batch ) {
			Store::begin();
			foreach ( $batch as $c ) {
				if ( 'file' === $c['object_type'] ) {
					list( $st, $msg ) = self::file_apply( $c );
					Store::set_change( $c['id'], $st, $msg );
					continue;
				}
				$cur = self::current( $c );
				if ( null === $cur ) {
					Store::set_change( $c['id'], 'conflict', __( 'The record no longer exists.', 'publishrank' ) );
					continue;
				}
				if ( (string) $cur !== (string) $c['old_value'] ) {
					Store::set_change( $c['id'], 'conflict', __( 'The record changed after the preview. Plan again to include it.', 'publishrank' ) );
					continue;
				}
				if ( 'option' === $c['object_type'] ) {
					// Fire WordPress' option hooks so PublishRank (and page caches) refresh derived output.
					$ok = self::write( $c, $c['new_value'] );
					do_action( 'updated_option', $c['field'], maybe_unserialize( $c['old_value'] ), maybe_unserialize( $c['new_value'] ) );
				} else {
					$ok = self::write( $c, $c['new_value'] );
				}
				Store::set_change( $c['id'], $ok ? 'applied' : 'failed', $ok ? '' : __( 'The database rejected the update.', 'publishrank' ) );
			}
			Store::commit();
		}
		$counts  = Store::counts( $run_id );
		$pending = isset( $counts['pending'] ) ? $counts['pending'] : 0;
		$failed  = ( isset( $counts['failed'] ) ? $counts['failed'] : 0 ) + ( isset( $counts['conflict'] ) ? $counts['conflict'] : 0 );
		$done    = ( isset( $counts['applied'] ) ? $counts['applied'] : 0 );
		Store::update_run( $run_id, array( 'done' => $done, 'failed' => $failed ) );
		if ( 0 === $pending ) {
			$file_fail = self::file_failures( $run_id );
			if ( 'relocate' === $run['operation'] && $file_fail ) {
				// A file could not be copied: undo everything rather than point references at a missing file.
				self::rollback( $run_id, false );
				Store::update_run( $run_id, array( 'status' => 'failed' ) );
				return self::summary( $run_id, __( 'A file could not be copied, so the whole run was rolled back. No reference was changed.', 'publishrank' ) );
			}
			if ( 'slug' === $run['operation'] && $done ) {
				self::after_slug( $run );
			}
			self::refresh_outputs( $run_id );
			Store::update_run( $run_id, array( 'status' => $failed ? 'partial' : 'applied' ) );
			self::verify( $run_id );
		}
		return self::summary( $run_id );
	}

	private static function file_failures( $run_id ) {
		foreach ( Store::changes( $run_id, array( 'failed', 'conflict' ), 'ASC', 500, false ) as $c ) {
			if ( 'file' === $c['object_type'] ) {
				return true;
			}
		}
		return false;
	}

	/** Put failed / conflicting changes back in the queue (after the publisher fixed the cause). */
	public static function retry( $run_id ) {
		global $wpdb;
		$run = Store::run( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'partial', 'applying' ), true ) ) {
			return new \WP_Error( 'publishrank_media_state', __( 'Only partly applied runs can be retried.', 'publishrank' ) );
		}
		foreach ( Store::changes( $run_id, array( 'failed', 'conflict' ), 'ASC', 2000 ) as $c ) {
			$cur = self::current( $c );
			if ( 'file' !== $c['object_type'] && null !== $cur && (string) $cur !== (string) $c['old_value'] ) {
				// Re-base the change on the current value so later edits are preserved.
				$plan     = $run['plan'];
				$replacer = new Replacer( 'rebase' === $run['operation'] ? (array) $plan['map'] : Scanner::spelling_map( (array) $plan['pairs'] ) );
				$n        = 0;
				$err      = '';
				$new      = $replacer->replace_raw( (string) $cur, $n, $err );
				if ( $err || ! $n ) {
					continue;
				}
				$wpdb->update( \PublishRankSEO\Core\Database::table( 'media_changes' ), array( 'old_value' => (string) $cur, 'new_value' => $new, 'matches' => $n ), array( 'id' => (int) $c['id'] ) );
			}
			Store::set_change( $c['id'], 'pending', '' );
		}
		Store::update_run( $run_id, array( 'status' => 'applying' ) );
		return self::summary( $run_id );
	}

	/**
	 * Roll back every applied change of a run, newest first.
	 *
	 * @param bool $force Overwrite values edited after the run (default: keep them and report a conflict).
	 */
	public static function rollback( $run_id, $force = false ) {
		$run = Store::run( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'publishrank_media_run', __( 'Run not found.', 'publishrank' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $run['status'], array( 'applying', 'applied', 'partial', 'verified', 'finalized', 'failed' ), true ) ) {
			return new \WP_Error( 'publishrank_media_state', __( 'Nothing to roll back.', 'publishrank' ) );
		}
		$conflicts = 0;
		Store::begin();
		// Restore backed-up originals first (finalized runs).
		foreach ( Store::changes( $run_id, 'applied', 'DESC', 5000 ) as $c ) {
			if ( 'backup' === $c['object_type'] ) {
				$up  = Scanner::uploads();
				$bak = $up['basedir'] . '/' . $c['new_value'];
				$org = $up['basedir'] . '/' . $c['old_value'];
				if ( file_exists( $bak ) && ! file_exists( $org ) && wp_mkdir_p( dirname( $org ) ) && @rename( $bak, $org ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
					Store::set_change( $c['id'], 'rolled_back', '' );
				} else {
					Store::set_change( $c['id'], 'conflict', __( 'Backup could not be restored automatically.', 'publishrank' ) );
					++$conflicts;
				}
			} elseif ( 'redirect' === $c['object_type'] ) {
				if ( class_exists( '\\PublishRankSEO\\Redirect\\RedirectStore' ) && $c['new_value'] ) {
					\PublishRankSEO\Redirect\RedirectStore::delete( is_numeric( $c['new_value'] ) ? (int) $c['new_value'] : $c['new_value'] );
				}
				Store::set_change( $c['id'], 'rolled_back', '' );
			}
		}
		foreach ( Store::changes( $run_id, 'applied', 'DESC', 5000 ) as $c ) {
			if ( in_array( $c['object_type'], array( 'backup', 'redirect' ), true ) ) {
				continue;
			}
			if ( 'file' === $c['object_type'] ) {
				continue; // Files are removed after every reference points back to the original.
			}
			$cur = self::current( $c );
			if ( null === $cur ) {
				Store::set_change( $c['id'], 'conflict', __( 'Record deleted since the run.', 'publishrank' ) );
				++$conflicts;
				continue;
			}
			if ( (string) $cur !== (string) $c['new_value'] && ! $force ) {
				// Edited since: undo only this run's URL change inside the current value.
				$plan = $run['plan'];
				$back = array();
				if ( 'rebase' === $run['operation'] ) {
					foreach ( (array) $plan['map'] as $o => $n ) {
						$back[ $n ] = $o;
					}
				} elseif ( 'slug' !== $run['operation'] ) {
					foreach ( Scanner::spelling_map( (array) $plan['pairs'] ) as $o => $n ) {
						$back[ $n ] = $o;
					}
				}
				$n   = 0;
				$err = '';
				$val = $back && 'replace_url' !== $run['operation'] ? ( new Replacer( $back ) )->replace_raw( (string) $cur, $n, $err ) : $cur;
				if ( $n && ! $err ) {
					self::write( $c, $val );
					Store::set_change( $c['id'], 'rolled_back', __( 'Edited after the run: only the URL change was undone, later edits were kept.', 'publishrank' ) );
				} else {
					Store::set_change( $c['id'], 'conflict', __( 'Edited after the run; left as it is.', 'publishrank' ) );
					++$conflicts;
				}
				continue;
			}
			self::write( $c, $c['old_value'] );
			Store::set_change( $c['id'], 'rolled_back', '' );
		}
		foreach ( Store::changes( $run_id, 'applied', 'DESC', 500 ) as $c ) {
			if ( 'file' === $c['object_type'] ) {
				list( $st, $msg ) = self::file_revert( $c );
				Store::set_change( $c['id'], $st, $msg );
				if ( 'conflict' === $st ) {
					++$conflicts;
				}
			}
		}
		Store::commit();
		if ( 'slug' === $run['operation'] ) {
			clean_post_cache( (int) $run['attachment_id'] );
		}
		self::refresh_outputs( $run_id );
		Store::update_run( $run_id, array( 'status' => 'rolled_back' ) );
		$report                = $run['report'];
		$report['rolled_back'] = array( 'at' => Store::now(), 'by' => get_current_user_id(), 'conflicts' => $conflicts );
		Store::update_run( $run_id, array( 'report' => $report ) );
		return self::summary( $run_id, $conflicts ? sprintf( /* translators: %d: count */ _n( '%d record could not be restored automatically; see the report.', '%d records could not be restored automatically; see the report.', $conflicts, 'publishrank' ), $conflicts ) : __( 'Rolled back. Every changed record holds its previous value again.', 'publishrank' ) );
	}

	/** Relocate only: move the original files to a backup folder (recoverable) and optionally add redirects. */
	public static function finalize( $run_id, $redirect = true ) {
		$run = Store::run( $run_id );
		if ( ! $run || 'relocate' !== $run['operation'] || ! in_array( $run['status'], array( 'applied', 'verified' ), true ) ) {
			return new \WP_Error( 'publishrank_media_state', __( 'Only a verified relocation can be finalized.', 'publishrank' ) );
		}
		$report = $run['report'];
		if ( ! empty( $report['verify']['failed'] ) ) {
			return new \WP_Error( 'publishrank_media_verify', __( 'Verification found problems. Fix them or roll back before removing the old files.', 'publishrank' ) );
		}
		$up  = Scanner::uploads();
		$seq = 5000;
		foreach ( (array) $run['plan']['moves'] as $m ) {
			$org = $up['basedir'] . '/' . $m['old_rel'];
			$new = $up['basedir'] . '/' . $m['new_rel'];
			if ( ! file_exists( $org ) || ! file_exists( $new ) ) {
				continue;
			}
			$bak_rel = self::BACKUP_DIR . '/run-' . (int) $run_id . '/' . $m['old_rel'];
			$bak     = $up['basedir'] . '/' . $bak_rel;
			if ( wp_mkdir_p( dirname( $bak ) ) && @rename( $org, $bak ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				Store::add_change( $run_id, array( 'seq' => $seq++, 'object_type' => 'backup', 'object_id' => $run['attachment_id'], 'field' => $m['kind'], 'label' => sprintf( /* translators: %s: file */ __( 'Original moved to backup: %s', 'publishrank' ), $bak_rel ), 'old_value' => $m['old_rel'], 'new_value' => $bak_rel, 'status' => 'applied' ) );
			}
		}
		self::protect_backup_dir();
		$created = 0;
		if ( $redirect && class_exists( '\\PublishRankSEO\\Redirect\\RedirectStore' ) ) {
			foreach ( (array) $run['plan']['moves'] as $m ) {
				$from = (string) wp_parse_url( $up['baseurl'] . '/' . $m['old_rel'], PHP_URL_PATH );
				$to   = $up['baseurl'] . '/' . $m['new_rel'];
				$res  = \PublishRankSEO\Redirect\RedirectStore::add( $from, $to, 301, false, 0, 0, array( 'note' => sprintf( 'Media Link Manager run %d', (int) $run_id ), 'origin' => 'media', 'force' => true, 'flags' => array( 'force' => true ) ) );
				if ( ! is_wp_error( $res ) ) {
					$rid = self::find_redirect_id( $from );
					Store::add_change( $run_id, array( 'seq' => $seq++, 'object_type' => 'redirect', 'field' => 'redirect', 'label' => $from . ' → ' . $to, 'old_value' => $from, 'new_value' => (string) $rid, 'status' => 'applied' ) );
					++$created;
				}
			}
		}
		$report['finalized'] = array( 'at' => Store::now(), 'redirects' => $created, 'backup' => self::BACKUP_DIR . '/run-' . (int) $run_id );
		Store::update_run( $run_id, array( 'status' => 'finalized', 'report' => $report ) );
		return self::summary( $run_id, $created ? sprintf( /* translators: %d: count */ __( 'Old files moved to the backup folder and %d redirects added for the old file addresses.', 'publishrank' ), $created ) : __( 'Old files moved to the backup folder.', 'publishrank' ) );
	}

	private static function find_redirect_id( $from ) {
		if ( class_exists( '\\PublishRankSEO\\Redirect\\RedirectRepository' ) && \PublishRankSEO\Redirect\RedirectStore::v2() ) {
			global $wpdb;
			$t = \PublishRankSEO\Core\Database::table( 'redirects' );
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE source = %s ORDER BY id DESC LIMIT 1", \PublishRankSEO\Redirect\RedirectStore::normalise_path( $from ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( \PublishRankSEO\Redirect\RedirectStore::all() as $r ) {
			if ( isset( $r['source'] ) && \PublishRankSEO\Redirect\RedirectStore::normalise_path( $from ) === $r['source'] ) {
				return \PublishRankSEO\Redirect\RedirectStore::id( $r );
			}
		}
		return 0;
	}

	private static function protect_backup_dir() {
		$up  = Scanner::uploads();
		$dir = $up['basedir'] . '/' . self::BACKUP_DIR;
		if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}
	}

	private static function after_slug( array $run ) {
		clean_post_cache( (int) $run['attachment_id'] );
		$new_link = get_attachment_link( (int) $run['attachment_id'] );
		$plan     = $run['plan'];
		$plan['new_link'] = $new_link;
		if ( ! empty( $plan['redirect'] ) && ! empty( $plan['pages_enabled'] ) && class_exists( '\\PublishRankSEO\\Redirect\\RedirectStore' ) ) {
			$from = (string) wp_parse_url( (string) $plan['old_link'], PHP_URL_PATH );
			if ( $from && $from !== (string) wp_parse_url( $new_link, PHP_URL_PATH ) && ! is_wp_error( \PublishRankSEO\Redirect\RedirectStore::add( $from, $new_link, 301, false, 0, 0, array( 'note' => 'Media Link Manager', 'origin' => 'media' ) ) ) ) {
				Store::add_change( (int) $run['id'], array( 'seq' => 50, 'object_type' => 'redirect', 'field' => 'redirect', 'label' => $from . ' → ' . $new_link, 'old_value' => $from, 'new_value' => (string) self::find_redirect_id( $from ), 'status' => 'applied' ) );
			}
		}
		Store::update_run( (int) $run['id'], array( 'plan' => $plan, 'new_url' => $new_link ) );
	}

	/** Refresh everything PublishRank and WordPress derive from changed records. */
	private static function refresh_outputs( $run_id ) {
		if ( class_exists( '\\PublishRankSEO\\Meta\\SeoData' ) ) {
			\PublishRankSEO\Meta\SeoData::flush();
		}
		if ( class_exists( '\\PublishRankSEO\\Sitemap\\Engine\\SitemapCursorStore' ) ) {
			\PublishRankSEO\Sitemap\Engine\SitemapCursorStore::bump_many( array( 'image', 'attachment', 'post', 'page', 'cpt', 'video', 'news' ) );
		}
		update_option( 'publishrank_seo_cache_epoch', time(), false );
		update_option( 'publishrank_schema_index_generation', (int) get_option( 'publishrank_schema_index_generation', 1 ) + 1, false );
		/** This action is documented in src/Core/Plugin.php */
		do_action( 'publishrank_seo_output_settings_changed', 'publishrank_media_links' );
		/**
		 * Fires after the Media Link Manager applied or rolled back a run.
		 *
		 * @param int $run_id Run id.
		 */
		do_action( 'publishrank_media_links_changed', (int) $run_id );
	}

	/* -------------------------------------------------------------------- Verification */

	/** HEAD (then GET) a URL and compare status and content type. @return array{status:string,detail:string,code:int,type:string} */
	/**
	 * "2026/11/photo.jpg" → "2026/11/photo": matches the original and every size variant of the old location,
	 * whether the file was renamed, moved to another folder, or both (a bare file name would also match a move
	 * that keeps the name).
	 */
	private static function rel_stem( $rel ) {
		$rel = ltrim( (string) $rel, '/' );
		$dir = dirname( $rel );
		return ( '.' === $dir ? '' : $dir . '/' ) . pathinfo( $rel, PATHINFO_FILENAME );
	}

	public static function http_check( $url, $mime = '' ) {
		$args = array( 'timeout' => 8, 'redirection' => 3, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'user-agent' => 'PublishRank Media Link Manager; ' . home_url( '/' ) );
		$res  = wp_safe_remote_head( $url, $args );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( is_wp_error( $res ) && $host === (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$res = wp_remote_head( $url, $args ); // Own site: loopback addresses are expected.
		}
		if ( ! is_wp_error( $res ) && in_array( (int) wp_remote_retrieve_response_code( $res ), array( 403, 405, 501 ), true ) ) {
			$res = wp_safe_remote_get( $url, array_merge( $args, array( 'limit_response_size' => 65536 ) ) );
		}
		if ( is_wp_error( $res ) ) {
			return array( 'status' => 'warn', 'detail' => sprintf( /* translators: %s: error */ __( 'Could not be checked from the server: %s', 'publishrank' ), $res->get_error_message() ), 'code' => 0, 'type' => '' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
		if ( $code < 200 || $code >= 400 ) {
			return array( 'status' => 'fail', 'detail' => sprintf( /* translators: %d: HTTP status */ __( 'HTTP %d', 'publishrank' ), $code ), 'code' => $code, 'type' => $type );
		}
		if ( $mime && $type ) {
			$major = strtok( $mime, '/' );
			if ( 0 !== strpos( $type, $major . '/' ) && false === strpos( $type, 'octet-stream' ) ) {
				return array( 'status' => 'fail', 'detail' => sprintf( /* translators: 1: received type, 2: expected */ __( 'Served as %1$s, expected %2$s', 'publishrank' ), $type, $mime ), 'code' => $code, 'type' => $type );
			}
		}
		return array( 'status' => 'pass', 'detail' => sprintf( 'HTTP %d · %s', $code, $type ?: __( 'no content type', 'publishrank' ) ), 'code' => $code, 'type' => $type );
	}

	/** Verify an applied run and store the report. */
	public static function verify( $run_id ) {
		$run = Store::run( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'publishrank_media_run', __( 'Run not found.', 'publishrank' ), array( 'status' => 404 ) );
		}
		$checks = array();
		$add    = static function ( $label, $status, $detail = '' ) use ( &$checks ) {
			$checks[] = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
		};
		$plan = $run['plan'];
		$id   = (int) $run['attachment_id'];
		if ( 'relocate' === $run['operation'] ) {
			$up = Scanner::uploads();
			foreach ( (array) $plan['moves'] as $m ) {
				$p  = $up['basedir'] . '/' . $m['new_rel'];
				$ok = file_exists( $p ) && filesize( $p ) > 0;
				$add( sprintf( /* translators: %s: file */ __( 'File exists: %s', 'publishrank' ), $m['new_rel'] ), $ok ? 'pass' : 'fail' );
				if ( $ok && 'full' === $m['kind'] ) {
					$ft = wp_check_filetype( $p );
					$add( __( 'File type matches the attachment', 'publishrank' ), $ft['type'] === $plan['mime'] ? 'pass' : 'fail', (string) $ft['type'] );
					if ( 0 === strpos( (string) $plan['mime'], 'image/' ) && 'image/svg+xml' !== $plan['mime'] ) {
						$add( __( 'Image decodes', 'publishrank' ), @getimagesize( $p ) ? 'pass' : 'fail' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					}
				}
			}
			clean_attachment_cache( $id );
			$attached = wp_normalize_path( (string) get_attached_file( $id, true ) );
			$add( __( 'Attachment points to the new file', 'publishrank' ), $attached === wp_normalize_path( $up['basedir'] . '/' . $plan['moves'][0]['new_rel'] ) ? 'pass' : 'fail', $attached );
			$url = (string) wp_get_attachment_url( $id );
			$add( __( 'WordPress returns the new URL', 'publishrank' ), false !== strpos( $url, $plan['moves'][0]['new_rel'] ) ? 'pass' : 'fail', $url );
			$meta = wp_get_attachment_metadata( $id );
			$add( __( 'Size metadata is consistent', 'publishrank' ), is_array( $meta ) && isset( $meta['file'] ) && $meta['file'] === $plan['moves'][0]['new_rel'] ? 'pass' : 'fail' );
			$h = self::http_check( $run['new_url'], (string) $plan['mime'] );
			$add( __( 'New URL loads over HTTP', 'publishrank' ), $h['status'], $h['detail'] );
			if ( 'finalized' !== $run['status'] ) {
				$add( __( 'Old URL still loads (original kept until you finalize)', 'publishrank' ), file_exists( $plan['moves'][0]['old_path'] ) ? 'pass' : 'warn' );
			}
			$src = wp_get_attachment_image_srcset( $id, 'full' );
			if ( $src ) {
				$add( __( 'srcset uses the new files', 'publishrank' ), false === strpos( $src, '/' . self::rel_stem( $plan['moves'][0]['old_rel'] ) ) ? 'pass' : 'fail' );
			}
		} elseif ( 'replace_url' === $run['operation'] ) {
			$h = self::http_check( $run['new_url'], (string) $plan['mime'] );
			$add( __( 'New URL loads over HTTP', 'publishrank' ), $h['status'], $h['detail'] );
		} elseif ( 'slug' === $run['operation'] ) {
			clean_post_cache( $id );
			$add( __( 'Attachment page slug updated', 'publishrank' ), get_post_field( 'post_name', $id ) === $plan['slug'] ? 'pass' : 'fail', (string) get_attachment_link( $id ) );
		}
		// Every applied record: no old reference left, new reference present.
		$old_map = array();
		if ( 'rebase' === $run['operation'] ) {
			$old_map = (array) $plan['map'];
		} elseif ( ! empty( $plan['pairs'] ) ) {
			$old_map = Scanner::spelling_map( (array) $plan['pairs'] );
		}
		if ( $old_map ) {
			$probe   = new Replacer( $old_map );
			$left    = 0;
			$checked = 0;
			$bad     = array();
			foreach ( Store::changes( $run_id, 'applied', 'ASC', 5000 ) as $c ) {
				if ( in_array( $c['object_type'], array( 'file', 'backup', 'redirect' ), true ) || ( 'postmeta' === $c['object_type'] && in_array( $c['field'], array( '_wp_attached_file', '_wp_attachment_metadata' ), true ) ) ) {
					continue;
				}
				++$checked;
				$cur = (string) self::current( $c );
				$err = '';
				$n   = $probe->count_raw( $cur, $err );
				if ( $n ) {
					$left += $n;
					$bad[] = $c['label'];
				}
				if ( is_serialized( $cur ) && false === @unserialize( $cur, array( 'allowed_classes' => false ) ) && 'b:0;' !== $cur ) { // phpcs:ignore
					$add( __( 'Serialized value still readable', 'publishrank' ) . ': ' . $c['label'], 'fail' );
				}
				if ( 'json' === Replacer::format_of( $c['old_value'] ) && null === json_decode( $cur ) ) {
					$add( __( 'JSON value still valid', 'publishrank' ) . ': ' . $c['label'], 'fail' );
				}
			}
			$add( sprintf( /* translators: %d: count */ __( 'Updated records no longer use the old URL (%d checked)', 'publishrank' ), $checked ), $left ? 'fail' : 'pass', $left ? implode( '; ', array_slice( $bad, 0, 5 ) ) : '' );
			if ( $id && 'replace_url' !== $run['operation'] ) {
				$remaining = Scanner::url_records( $id, new Replacer( Scanner::spelling_map( (array) $plan['pairs'] ) ) );
				$add( __( 'No other stored reference to the old URL remains', 'publishrank' ), $remaining ? 'warn' : 'pass', $remaining ? sprintf( /* translators: %d: count */ __( '%d records still mention the old address (skipped, conflicting or ambiguous). Review them in the report.', 'publishrank' ), count( $remaining ) ) : '' );
			}
		}
		if ( $id ) {
			$feat = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'meta_key' => '_thumbnail_id', 'meta_value' => (string) $id, 'fields' => 'ids', 'posts_per_page' => 3, 'no_found_rows' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $feat as $pid ) {
				$img = (string) get_the_post_thumbnail_url( $pid, 'full' );
				$add( sprintf( /* translators: %s: post title */ __( 'Featured image / social image resolves: %s', 'publishrank' ), get_the_title( $pid ) ), '' !== $img && ( 'relocate' !== $run['operation'] || false !== strpos( $img, $plan['moves'][0]['new_rel'] ) ) ? 'pass' : 'fail', $img );
			}
		}
		$failed = 0;
		$warn   = 0;
		foreach ( $checks as $c ) {
			$failed += 'fail' === $c['status'] ? 1 : 0;
			$warn   += 'warn' === $c['status'] ? 1 : 0;
		}
		$report           = $run['report'];
		$report['verify'] = array( 'at' => Store::now(), 'checks' => $checks, 'failed' => $failed, 'warnings' => $warn );
		$fields           = array( 'report' => $report );
		if ( in_array( $run['status'], array( 'applied', 'verified' ), true ) || ( 'applying' === $run['status'] ) ) {
			$fields['status'] = $failed ? 'applied' : 'verified';
		}
		Store::update_run( $run_id, $fields );
		return self::summary( $run_id );
	}

	/* -------------------------------------------------------------------- Summary */

	public static function summary( $run_id, $message = '' ) {
		$run = Store::run( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'publishrank_media_run', __( 'Run not found.', 'publishrank' ), array( 'status' => 404 ) );
		}
		$counts = Store::counts( $run_id );
		$plan   = $run['plan'];
		unset( $plan['moves'] );
		$changes = array();
		foreach ( Store::changes( $run_id, '', 'ASC', 500, true ) as $c ) {
			$changes[] = array(
				'id'      => (int) $c['id'],
				'type'    => $c['object_type'],
				'object'  => (int) $c['object_id'],
				'field'   => $c['field'],
				'label'   => $c['label'],
				'matches' => (int) $c['matches'],
				'status'  => $c['status'],
				'message' => $c['message'],
				'edit'    => Scanner::edit_link( 'postmeta' === $c['object_type'] ? 'post' : $c['object_type'], (int) $c['object_id'] ),
				'diff'    => self::diff( (string) $c['old_value'], (string) $c['new_value'], $c['object_type'] ),
			);
		}
		$pending = isset( $counts['pending'] ) ? $counts['pending'] : 0;
		return array(
			'id'          => (int) $run['id'],
			'operation'   => $run['operation'],
			'status'      => $run['status'],
			'attachment'  => (int) $run['attachment_id'],
			'old_url'     => $run['old_url'],
			'new_url'     => $run['new_url'],
			'created_at'  => $run['created_at'],
			'updated_at'  => $run['updated_at'],
			'kind'        => isset( $run['plan']['kind'] ) ? $run['plan']['kind'] : '',
			'plan'        => $plan,
			'moves'       => isset( $run['plan']['moves'] ) ? array_map( static function ( $m ) { return array( 'kind' => $m['kind'], 'old' => $m['old_rel'], 'new' => $m['new_rel'] ); }, (array) $run['plan']['moves'] ) : array(),
			'counts'      => $counts,
			'total'       => array_sum( $counts ),
			'pending'     => $pending,
			'progress'    => array_sum( $counts ) ? round( 100 * ( array_sum( $counts ) - $pending ) / array_sum( $counts ) ) : 100,
			'changes'     => $changes,
			'report'      => $run['report'],
			'message'     => $message,
		);
	}

	/** Short before/after excerpt around the first changed position. */
	private static function diff( $old, $new, $type ) {
		if ( $old === $new || 'file' === $type || 'backup' === $type || 'redirect' === $type ) {
			return array( 'before' => '', 'after' => '' );
		}
		$len = min( strlen( $old ), strlen( $new ) );
		$i   = 0;
		while ( $i < $len && $old[ $i ] === $new[ $i ] ) {
			++$i;
		}
		$start = max( 0, $i - 60 );
		$o_end = strlen( $old ) - 1;
		$n_end = strlen( $new ) - 1;
		while ( $o_end > $i && $n_end > $i && $old[ $o_end ] === $new[ $n_end ] ) {
			--$o_end;
			--$n_end;
		}
		$b = substr( $old, $start, ( $o_end - $start ) + 60 );
		$a = substr( $new, $start, ( $n_end - $start ) + 60 );
		return array( 'before' => wp_check_invalid_utf8( mb_substr( $b, 0, 420 ), true ), 'after' => wp_check_invalid_utf8( mb_substr( $a, 0, 420 ), true ) );
	}
}
