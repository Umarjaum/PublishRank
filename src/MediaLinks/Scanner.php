<?php
/**
 * Media reference discovery for the Media Link Manager.
 *
 * Finds where an attachment is used: URL references (absolute, protocol-relative, uploads-relative, every image
 * size, the "-scaled" original, srcset entries, URLs with query strings, JSON-escaped and serialized copies) and
 * ID references (featured images, block attributes, galleries, WooCommerce product galleries and variations,
 * term images, site icon and logos). Only the WordPress database is searched; theme files, CSS, page-builder
 * data stored in base64 and external sites are reported as "not searched" rather than guessed at.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\MediaLinks;

defined( 'ABSPATH' ) || exit;

final class Scanner {
	const LIMIT = 1000;

	/** Keys never rewritten: WordPress manages them through the attachment itself. */
	const SKIP_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_edit_lock', '_edit_last', '_publishrank_media_refs' );

	/** Option name patterns never searched (caches, sessions, cron, transients, our own logs). */
	const SKIP_OPTION_LIKE = array( '\_transient\_%', '\_site\_transient\_%', 'cron', 'rewrite\_rules', 'publishrank\_media\_%' );

	/** Uploads base URL and dir. */
	public static function uploads() {
		$u = wp_get_upload_dir();
		return array(
			'baseurl' => untrailingslashit( set_url_scheme( (string) $u['baseurl'] ) ),
			'basedir' => untrailingslashit( wp_normalize_path( (string) $u['basedir'] ) ),
		);
	}

	/**
	 * Every file of an attachment: original, sizes and the pre-scaled original.
	 *
	 * @return array<int,array{kind:string,rel:string,path:string,url:string,width:int,height:int}>
	 */
	public static function files( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$up            = self::uploads();
		$rel           = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $rel ) {
			return array();
		}
		$rel  = ltrim( wp_normalize_path( $rel ), '/' );
		if ( 0 === strpos( $rel, $up['basedir'] . '/' ) ) {
			$rel = substr( $rel, strlen( $up['basedir'] ) + 1 );
		}
		$dir  = dirname( $rel );
		$dir  = '.' === $dir ? '' : $dir . '/';
		$meta = wp_get_attachment_metadata( $attachment_id );
		$out  = array();
		$add  = static function ( $kind, $file_rel, $w = 0, $h = 0 ) use ( &$out, $up ) {
			foreach ( $out as $f ) {
				if ( $f['rel'] === $file_rel ) {
					return;
				}
			}
			$out[] = array(
				'kind'   => $kind,
				'rel'    => $file_rel,
				'path'   => $up['basedir'] . '/' . $file_rel,
				'url'    => $up['baseurl'] . '/' . $file_rel,
				'width'  => (int) $w,
				'height' => (int) $h,
			);
		};
		$add( 'full', $rel, is_array( $meta ) && isset( $meta['width'] ) ? $meta['width'] : 0, is_array( $meta ) && isset( $meta['height'] ) ? $meta['height'] : 0 );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) ) {
				$add( 'original_image', $dir . basename( (string) $meta['original_image'] ) );
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $name => $s ) {
					if ( ! empty( $s['file'] ) ) {
						$add( 'size:' . sanitize_key( (string) $name ), $dir . basename( (string) $s['file'] ), isset( $s['width'] ) ? $s['width'] : 0, isset( $s['height'] ) ? $s['height'] : 0 );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * URL spellings for one file URL: https, http, protocol-relative and uploads-relative.
	 *
	 * @return string[]
	 */
	public static function spellings( $url ) {
		$url  = (string) $url;
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$auth = $host . ( $port ? ':' . $port : '' );
		$out  = array( 'https://' . $auth . $path, 'http://' . $auth . $path, '//' . $auth . $path );
		if ( '' !== $path ) {
			$out[] = $path;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * old spelling => new spelling for a set of (old URL => new URL) pairs, keeping each spelling's form.
	 *
	 * @param array<string,string> $pairs Old file URL => new file URL.
	 * @return array<string,string>
	 */
	public static function spelling_map( array $pairs ) {
		$map = array();
		foreach ( $pairs as $old => $new ) {
			$old_s   = self::spellings( $old );
			$new_s   = self::spellings( $new );
			$new_far = (string) wp_parse_url( $new, PHP_URL_HOST ) !== (string) wp_parse_url( $old, PHP_URL_HOST );
			foreach ( $old_s as $i => $sp ) {
				// A relative old reference only becomes relative again when the new URL lives on the same host.
				if ( 3 === $i && $new_far ) {
					$map[ $sp ] = $new_s[0];
					continue;
				}
				$map[ $sp ] = isset( $new_s[ $i ] ) ? $new_s[ $i ] : $new;
			}
		}
		return $map;
	}

	/** LIKE patterns for an arbitrary text (plain and JSON-escaped). */
	public static function likes_for( $text ) {
		global $wpdb;
		$out = array( '%' . $wpdb->esc_like( $text ) . '%' );
		$esc = str_replace( '/', '\\/', $text );
		if ( $esc !== $text ) {
			$out[] = '%' . $wpdb->esc_like( $esc ) . '%';
		}
		return $out;
	}

	/** Prefilter LIKE patterns: uploads-relative directory + filename stem (covers every size and -scaled). */
	private static function like_terms( $attachment_id ) {
		global $wpdb;
		$files = self::files( $attachment_id );
		if ( ! $files ) {
			return array();
		}
		$rel  = $files[0]['rel'];
		$dir  = dirname( $rel );
		$stem = preg_replace( '/(-scaled|-rotated)?\.[A-Za-z0-9]+$/', '', basename( $rel ) );
		$term = ( '.' === $dir ? '' : $dir . '/' ) . $stem;
		$out  = array( '%' . $wpdb->esc_like( $term ) . '%' );
		$esc  = str_replace( '/', '\\/', $term );
		if ( $esc !== $term ) {
			$out[] = '%' . $wpdb->esc_like( $esc ) . '%';
		}
		return $out;
	}

	/** Replacer that maps every URL spelling of the attachment to itself (used for counting only). */
	public static function counter( $attachment_id ) {
		$map = array();
		foreach ( self::files( $attachment_id ) as $f ) {
			foreach ( self::spellings( $f['url'] ) as $sp ) {
				$map[ $sp ] = $sp . "\x00"; // Different value, never written.
			}
		}
		return new Replacer( $map );
	}

	/**
	 * Every URL-bearing database record that mentions the attachment's files.
	 *
	 * @param int      $attachment_id Attachment.
	 * @param Replacer $replacer      Replacer used to count (and later rewrite) matches.
	 * @return array<int,array<string,mixed>>
	 */
	public static function url_records( $attachment_id, Replacer $replacer ) {
		global $wpdb;
		$likes = is_array( $attachment_id ) ? $attachment_id : self::like_terms( $attachment_id );
		if ( ! $likes ) {
			return array();
		}
		$rows = array();
		$or   = static function ( $col ) use ( $likes, $wpdb ) {
			$parts = array();
			foreach ( $likes as $l ) {
				$parts[] = $wpdb->prepare( "{$col} LIKE %s", $l ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column name is a constant.
			}
			return '(' . implode( ' OR ', $parts ) . ')';
		};
		$lim = self::LIMIT;
		// Posts (content + excerpt), every post type except revisions and auto-drafts.
		$posts = $wpdb->get_results( "SELECT ID, post_type, post_title, post_status, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status <> 'auto-draft' AND ( " . $or( 'post_content' ) . ' OR ' . $or( 'post_excerpt' ) . " ) LIMIT {$lim}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $posts as $p ) {
			foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
				$err = '';
				$n   = $replacer->count_raw( $p->$field, $err );
				if ( $n || $err ) {
					$rows[] = array( 'object_type' => 'post', 'object_id' => (int) $p->ID, 'sub_id' => 0, 'field' => $field, 'label' => self::post_label( $p ), 'location' => self::post_location( $p, $field ), 'format' => Replacer::format_of( $p->$field ), 'matches' => $n, 'error' => $err, 'raw' => $p->$field );
				}
			}
		}
		$skip = "'" . implode( "','", array_map( 'esc_sql', self::SKIP_META ) ) . "'";
		$meta_tables = array(
			'postmeta'    => array( $wpdb->postmeta, 'meta_id', 'post_id' ),
			'termmeta'    => array( $wpdb->termmeta, 'meta_id', 'term_id' ),
			'usermeta'    => array( $wpdb->usermeta, 'umeta_id', 'user_id' ),
			'commentmeta' => array( $wpdb->commentmeta, 'meta_id', 'comment_id' ),
		);
		foreach ( $meta_tables as $type => $t ) {
			list( $table, $idcol, $obj ) = $t;
			$extra = 'usermeta' === $type ? " AND meta_key NOT IN ('session_tokens') AND meta_key NOT LIKE '%capabilities'" : '';
			$res   = $wpdb->get_results( "SELECT {$idcol} AS mid, {$obj} AS oid, meta_key, meta_value FROM {$table} WHERE meta_key NOT IN ({$skip}){$extra} AND " . $or( 'meta_value' ) . " LIMIT {$lim}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $res as $m ) {
				$err = '';
				$n   = $replacer->count_raw( $m->meta_value, $err );
				if ( $n || $err ) {
					$rows[] = array( 'object_type' => $type, 'object_id' => (int) $m->oid, 'sub_id' => (int) $m->mid, 'field' => (string) $m->meta_key, 'label' => self::object_label( $type, (int) $m->oid ), 'location' => self::meta_location( $type, (string) $m->meta_key, (int) $m->oid ), 'format' => Replacer::format_of( $m->meta_value ), 'matches' => $n, 'error' => $err, 'raw' => $m->meta_value );
				}
			}
		}
		$not = array();
		foreach ( self::SKIP_OPTION_LIKE as $pat ) {
			$not[] = "option_name NOT LIKE '" . esc_sql( $pat ) . "'";
		}
		$opts = $wpdb->get_results( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE " . implode( ' AND ', $not ) . ' AND ' . $or( 'option_value' ) . " LIMIT {$lim}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $opts as $o ) {
			$err = '';
			$n   = $replacer->count_raw( $o->option_value, $err );
			if ( $n || $err ) {
				$rows[] = array( 'object_type' => 'option', 'object_id' => (int) $o->option_id, 'sub_id' => 0, 'field' => (string) $o->option_name, 'label' => (string) $o->option_name, 'location' => self::option_location( (string) $o->option_name ), 'format' => Replacer::format_of( $o->option_value ), 'matches' => $n, 'error' => $err, 'raw' => $o->option_value );
			}
		}
		$coms = $wpdb->get_results( "SELECT comment_ID, comment_post_ID, comment_content FROM {$wpdb->comments} WHERE " . $or( 'comment_content' ) . " LIMIT {$lim}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $coms as $c ) {
			$n = $replacer->count_text( $c->comment_content );
			if ( $n ) {
				$rows[] = array( 'object_type' => 'comment', 'object_id' => (int) $c->comment_ID, 'sub_id' => 0, 'field' => 'comment_content', 'label' => sprintf( /* translators: %d: comment id */ __( 'Comment #%d', 'publishrank' ), (int) $c->comment_ID ), 'location' => __( 'Comment', 'publishrank' ), 'format' => 'html', 'matches' => $n, 'error' => '', 'raw' => $c->comment_content );
			}
		}
		return $rows;
	}

	/**
	 * References by attachment ID. These keep working when a file moves (WordPress resolves the URL from the ID);
	 * they are listed so publishers see the full picture.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function id_records( $attachment_id ) {
		global $wpdb;
		$id   = (int) $attachment_id;
		$rows = array();
		$res  = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, p.post_type, p.post_title, p.post_status, p.post_parent FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s AND p.post_type <> 'revision' LIMIT 1000", (string) $id ) );
		foreach ( (array) $res as $r ) {
			$rows[] = array( 'object_type' => 'post', 'object_id' => (int) $r->post_id, 'label' => self::post_label( $r ), 'location' => 'product_variation' === $r->post_type ? __( 'WooCommerce variation image', 'publishrank' ) : __( 'Featured image', 'publishrank' ), 'by' => 'id' );
		}
		$gal = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, p.post_type, p.post_title, p.post_status FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_product_image_gallery' AND ( m.meta_value = %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s ) LIMIT 1000", (string) $id, $id . ',%', '%,' . $id, '%,' . $id . ',%' ) );
		foreach ( (array) $gal as $r ) {
			$rows[] = array( 'object_type' => 'post', 'object_id' => (int) $r->post_id, 'label' => self::post_label( $r ), 'location' => __( 'WooCommerce product gallery', 'publishrank' ), 'by' => 'id' );
		}
		$blocks = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_title, post_status FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status <> 'auto-draft' AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) LIMIT 1000", '%"id":' . $id . '%', '%wp-image-' . $id . '"%', '%ids="%' . $id . '%' ) );
		foreach ( (array) $blocks as $r ) {
			$rows[] = array( 'object_type' => 'post', 'object_id' => (int) $r->ID, 'label' => self::post_label( $r ), 'location' => __( 'Block attribute, image class or gallery shortcode', 'publishrank' ), 'by' => 'id' );
		}
		$terms = $wpdb->get_results( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_value = %s LIMIT 500", (string) $id ) );
		foreach ( (array) $terms as $r ) {
			$rows[] = array( 'object_type' => 'term', 'object_id' => (int) $r->term_id, 'label' => self::object_label( 'termmeta', (int) $r->term_id ), 'location' => __( 'Category / term image', 'publishrank' ), 'by' => 'id' );
		}
		if ( (int) get_option( 'site_icon' ) === $id ) {
			$rows[] = array( 'object_type' => 'option', 'object_id' => 0, 'label' => 'site_icon', 'location' => __( 'Site icon', 'publishrank' ), 'by' => 'id' );
		}
		if ( (int) get_option( 'site_logo' ) === $id || (int) get_theme_mod( 'custom_logo' ) === $id ) {
			$rows[] = array( 'object_type' => 'option', 'object_id' => 0, 'label' => 'custom_logo', 'location' => __( 'Site / publisher logo', 'publishrank' ), 'by' => 'id' );
		}
		return $rows;
	}

	/**
	 * Full reference report for an attachment.
	 *
	 * @return array<string,mixed>
	 */
	public static function report( $attachment_id ) {
		$files = self::files( $attachment_id );
		$url   = self::url_records( $attachment_id, self::counter( $attachment_id ) );
		$ids   = self::id_records( $attachment_id );
		$total = 0;
		$amb   = array();
		foreach ( $url as $i => $r ) {
			$total += (int) $r['matches'];
			if ( $r['error'] ) {
				$amb[] = $r;
			}
			unset( $url[ $i ]['raw'] );
			$url[ $i ]['edit'] = self::edit_link( $r['object_type'], (int) $r['object_id'] );
		}
		foreach ( $ids as $i => $r ) {
			$ids[ $i ]['edit'] = self::edit_link( 'termmeta' === $r['object_type'] || 'term' === $r['object_type'] ? 'termmeta' : $r['object_type'], (int) $r['object_id'] );
		}
		$out = array(
			'attachment_id' => (int) $attachment_id,
			'files'         => $files,
			'url_refs'      => array_values( $url ),
			'id_refs'       => $ids,
			'url_matches'   => $total,
			'ambiguous'     => count( $amb ),
			'derived'       => self::derived( $attachment_id ),
			'not_searched'  => self::not_searched(),
			'limit_hit'     => count( $url ) >= self::LIMIT,
			'scanned_at'    => time(),
		);
		update_post_meta( (int) $attachment_id, '_publishrank_media_refs', array( 'url' => count( $url ), 'id' => count( $ids ), 'at' => time() ) );
		return $out;
	}

	/** Outputs PublishRank generates from the attachment at request time (no stored URL to change). */
	public static function derived( $attachment_id ) {
		return array(
			__( 'Open Graph and X/Twitter images, schema "image" properties, author and publisher logos built from this attachment ID', 'publishrank' ),
			__( 'Image sitemap and RSS media entries (rebuilt after a change)', 'publishrank' ),
			__( 'srcset and sizes attributes WordPress adds to images at display time', 'publishrank' ),
		);
	}

	/** What the scanner does not look at, stated plainly. */
	public static function not_searched() {
		return array(
			__( 'Theme and plugin files, CSS and JavaScript files on disk', 'publishrank' ),
			__( 'Page-builder data stored encoded (for example base64) and URL-encoded references', 'publishrank' ),
			__( 'Custom database tables created by other plugins', 'publishrank' ),
			__( 'Caches, CDNs, other websites, emails and social posts that already copied the URL', 'publishrank' ),
		);
	}

	public static function edit_link( $type, $id ) {
		if ( ! $id ) {
			return '';
		}
		switch ( $type ) {
			case 'post':
			case 'postmeta':
				return (string) get_edit_post_link( $id, 'raw' );
			case 'termmeta':
				return (string) get_edit_term_link( $id );
			case 'usermeta':
				return (string) get_edit_user_link( $id );
			case 'comment':
				return admin_url( 'comment.php?action=editcomment&c=' . (int) $id );
		}
		return '';
	}

	private static function post_label( $p ) {
		$t = isset( $p->post_title ) && '' !== $p->post_title ? $p->post_title : __( '(no title)', 'publishrank' );
		return sprintf( '%s — %s #%d', wp_strip_all_tags( (string) $t ), isset( $p->post_type ) ? $p->post_type : 'post', isset( $p->ID ) ? (int) $p->ID : ( isset( $p->post_id ) ? (int) $p->post_id : 0 ) );
	}

	private static function post_location( $p, $field ) {
		if ( 'post_excerpt' === $field ) {
			return __( 'Excerpt', 'publishrank' );
		}
		if ( 'product' === $p->post_type || 'product_variation' === $p->post_type ) {
			return __( 'Product description', 'publishrank' );
		}
		if ( 'wp_template' === $p->post_type || 'wp_template_part' === $p->post_type || 'wp_block' === $p->post_type || 'wp_navigation' === $p->post_type ) {
			return __( 'Site editor template / pattern', 'publishrank' );
		}
		return false !== strpos( (string) $p->post_content, '<!-- wp:' ) ? __( 'Block content', 'publishrank' ) : __( 'Content', 'publishrank' );
	}

	private static function meta_location( $type, $key, $oid ) {
		$k = strtolower( $key );
		if ( false !== strpos( $k, 'og_image' ) || false !== strpos( $k, 'opengraph' ) || false !== strpos( $k, 'facebook' ) ) {
			return __( 'Open Graph image', 'publishrank' );
		}
		if ( false !== strpos( $k, 'twitter' ) ) {
			return __( 'X/Twitter image', 'publishrank' );
		}
		if ( false !== strpos( $k, 'schema' ) ) {
			return __( 'Schema property', 'publishrank' );
		}
		if ( false !== strpos( $k, 'elementor' ) ) {
			return __( 'Elementor data', 'publishrank' );
		}
		if ( 'usermeta' === $type ) {
			return __( 'User profile field', 'publishrank' );
		}
		if ( 'termmeta' === $type ) {
			return __( 'Term field', 'publishrank' );
		}
		return 0 === strpos( $key, '_' ) ? __( 'Post metadata', 'publishrank' ) : __( 'Custom field', 'publishrank' );
	}

	private static function option_location( $name ) {
		if ( 0 === strpos( $name, 'theme_mods_' ) ) {
			return __( 'Theme Customizer setting', 'publishrank' );
		}
		if ( 0 === strpos( $name, 'widget_' ) ) {
			return __( 'Widget', 'publishrank' );
		}
		if ( 0 === strpos( $name, 'publishrank' ) ) {
			return __( 'PublishRank setting (logo, social or schema image)', 'publishrank' );
		}
		return __( 'Site option', 'publishrank' );
	}

	private static function object_label( $type, $id ) {
		if ( 'postmeta' === $type ) {
			$p = get_post( $id );
			return $p ? self::post_label( $p ) : '#' . $id;
		}
		if ( 'termmeta' === $type ) {
			$t = get_term( $id );
			return $t && ! is_wp_error( $t ) ? $t->name . ' — ' . $t->taxonomy : '#' . $id;
		}
		if ( 'usermeta' === $type ) {
			$u = get_userdata( $id );
			return $u ? $u->display_name : '#' . $id;
		}
		return '#' . $id;
	}
}
