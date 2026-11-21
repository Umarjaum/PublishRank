<?php
/**
 * Source Directory: the newsroom's own list of sources it cites, with an editorial classification,
 * priority, notes and usage tracking, plus writing help in the block editor and Classic editor.
 *
 * Classifications are the site's own editorial labels. PublishRank never verifies a source or
 * describes one as verified or authoritative, and it never changes post content on its own: the
 * editor panel only suggests and inserts a link when the writer clicks.
 *
 * Not to be confused with "Preferred Sources" (the Google Search reader button), which is a
 * separate screen.
 */

namespace PublishRankSEO\Newsroom;

use PublishRankSEO\Core\Branding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SourceDirectory {

	const CPT       = 'publishrank_source';
	const TAX       = 'publishrank_source_cat';
	const META_URL  = '_publishrank_source_url';
	const META_HOST = '_publishrank_source_domain';
	const META_TYPE = '_publishrank_source_class';
	const META_NOTE = '_publishrank_source_notes';
	const CITES     = '_publishrank_cites_source';
	const MAX_SCAN  = 300;

	public function register() {
		add_action( 'init', array( __CLASS__, 'register_types' ), 9 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'save_post', array( __CLASS__, 'track_usage' ), 30, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ), 10, 2 );
			add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save_source' ), 10, 2 );
			add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
			add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
			add_filter( 'manage_edit-' . self::CPT . '_sortable_columns', array( __CLASS__, 'sortable' ) );
			add_action( 'pre_get_posts', array( __CLASS__, 'admin_query' ) );
			add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
			add_filter( 'display_post_states', array( __CLASS__, 'states' ), 10, 2 );
			add_action( 'admin_post_publishrank_sources_export', array( __CLASS__, 'handle_export' ) );
			add_action( 'admin_post_publishrank_sources_import', array( __CLASS__, 'handle_import' ) );
			add_action( 'admin_notices', array( __CLASS__, 'tools_panel' ) );
			add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		}
	}

	/** Editorial labels. Descriptive only — none of them claims verification. */
	public static function classes() {
		return array(
			'primary'   => __( 'Primary source', 'publishrank' ),
			'official'  => __( 'Official or government body', 'publishrank' ),
			'wire'      => __( 'News agency', 'publishrank' ),
			'media'     => __( 'News publisher', 'publishrank' ),
			'research'  => __( 'Research or academic', 'publishrank' ),
			'reference' => __( 'Reference', 'publishrank' ),
			'company'   => __( 'Company or organisation', 'publishrank' ),
			'caution'   => __( 'Use with caution', 'publishrank' ),
			'avoid'     => __( 'Do not cite', 'publishrank' ),
		);
	}

	/* ------------------------------------------------------------------ Registration */

	public static function register_types() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'               => __( 'Source Directory', 'publishrank' ),
					'singular_name'      => __( 'Source', 'publishrank' ),
					'add_new'            => __( 'Add source', 'publishrank' ),
					'add_new_item'       => __( 'Add source', 'publishrank' ),
					'edit_item'          => __( 'Edit source', 'publishrank' ),
					'search_items'       => __( 'Search sources', 'publishrank' ),
					'not_found'          => __( 'No sources yet. Add the publications, agencies and bodies your newsroom cites so writers get suggestions and warnings while editing.', 'publishrank' ),
					'not_found_in_trash' => __( 'No sources in the bin.', 'publishrank' ),
					'menu_name'          => __( 'Source Directory', 'publishrank' ),
					'featured_image'     => __( 'Logo', 'publishrank' ),
					'set_featured_image' => __( 'Set logo', 'publishrank' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'title', 'excerpt', 'thumbnail', 'page-attributes' ),
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
			)
		);
		register_taxonomy(
			self::TAX,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => __( 'Source categories', 'publishrank' ),
					'singular_name' => __( 'Source category', 'publishrank' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'rewrite'           => false,
			)
		);
	}

	public static function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Source Directory', 'publishrank' ), __( 'Source Directory', 'publishrank' ), 'edit_pages', 'edit.php?post_type=' . self::CPT );
	}

	public static function title_placeholder( $text, $post ) {
		return $post instanceof \WP_Post && self::CPT === $post->post_type ? __( 'Source name, for example Reuters', 'publishrank' ) : $text;
	}

	/* --------------------------------------------------------------------- Helpers */

	/** Lowercase host without "www.", or '' when the value is not a usable web address or domain. */
	public static function normalize_host( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
			$value = 'https://' . ltrim( $value, '/' );
		}
		$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		return preg_match( '/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', (string) $host ) ? $host : '';
	}

	/** One source as plain data. */
	public static function source( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			return null;
		}
		$class = (string) get_post_meta( $post->ID, self::META_TYPE, true );
		$cats  = get_the_terms( $post->ID, self::TAX );
		return array(
			'id'          => (int) $post->ID,
			'name'        => get_the_title( $post ),
			'url'         => (string) get_post_meta( $post->ID, self::META_URL, true ),
			'domain'      => (string) get_post_meta( $post->ID, self::META_HOST, true ),
			'class'       => isset( self::classes()[ $class ] ) ? $class : '',
			'class_label' => self::classes()[ $class ] ?? '',
			'description' => (string) $post->post_excerpt,
			'priority'    => (int) $post->menu_order,
			'active'      => 'publish' === $post->post_status,
			'categories'  => is_array( $cats ) ? array_values( wp_list_pluck( $cats, 'name' ) ) : array(),
			'logo'        => (string) get_the_post_thumbnail_url( $post, 'thumbnail' ),
		);
	}

	/** Active sources, highest priority first. Cached per request. */
	public static function active_sources() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$ids   = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => 'publish',
				'numberposts'      => 2000,
				'orderby'          => array( 'menu_order' => 'DESC', 'title' => 'ASC' ),
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$cache = array();
		foreach ( $ids as $id ) {
			$s = self::source( $id );
			if ( $s && '' !== $s['domain'] ) {
				$cache[] = $s;
			}
		}
		return $cache;
	}

	/** Source whose domain matches a host (exact or a subdomain of it). */
	public static function match_host( $host, ?array $sources = null ) {
		$host = preg_replace( '/^www\./', '', strtolower( (string) $host ) );
		if ( '' === $host ) {
			return null;
		}
		$best = null;
		foreach ( null === $sources ? self::active_sources() : $sources as $s ) {
			$d = $s['domain'];
			if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) {
				if ( null === $best || strlen( $d ) > strlen( $best['domain'] ) ) {
					$best = $s;
				}
			}
		}
		return $best;
	}

	/** External links in HTML: list of [url, host, text]. */
	public static function links( $html ) {
		$out  = array();
		$home = self::normalize_host( home_url( '/' ) );
		if ( ! preg_match_all( '#<a\s[^>]*href=(["\'])(https?://[^"\']+)\1[^>]*>(.*?)</a>#is', (string) $html, $m, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( array_slice( $m, 0, self::MAX_SCAN ) as $a ) {
			$url  = html_entity_decode( $a[2], ENT_QUOTES );
			$host = self::normalize_host( $url );
			if ( '' === $host || $host === $home || substr( $host, -strlen( '.' . $home ) ) === '.' . $home ) {
				continue;
			}
			$out[] = array( 'url' => $url, 'host' => $host, 'text' => trim( wp_strip_all_tags( $a[3] ) ) );
		}
		return $out;
	}

	/** Review of the links in a piece of content against the directory. */
	public static function review( $html ) {
		$sources = self::active_sources();
		$rows    = array();
		foreach ( self::links( $html ) as $l ) {
			$s      = self::match_host( $l['host'], $sources );
			$rows[] = $l + array(
				'source' => $s ? $s['id'] : 0,
				'name'   => $s ? $s['name'] : '',
				'class'  => $s ? $s['class'] : '',
				'status' => ! $s ? 'unknown' : ( in_array( $s['class'], array( 'avoid', 'caution' ), true ) ? $s['class'] : 'listed' ),
			);
		}
		return $rows;
	}

	/* ---------------------------------------------------------------------- Usage */

	/** Record which directory sources a published or draft post links to (one meta row per source). */
	public static function track_usage( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || self::CPT === $post->post_type ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || empty( $type->public ) || 'attachment' === $post->post_type ) {
			return;
		}
		$ids = array();
		if ( in_array( $post->post_status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
			foreach ( self::review( $post->post_content ) as $r ) {
				if ( $r['source'] ) {
					$ids[ $r['source'] ] = true;
				}
			}
		}
		delete_post_meta( $post_id, self::CITES );
		foreach ( array_keys( $ids ) as $id ) {
			add_post_meta( $post_id, self::CITES, (int) $id );
		}
	}

	/** Whether another source (any status except the bin) already uses this domain. */
	public static function duplicates( $host, $exclude = 0 ) {
		return (bool) get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'exclude'     => array( (int) $exclude ),
				'meta_key'    => self::META_HOST, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => (string) $host, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/** Number of posts that link to a source. */
	public static function usage_count( $source_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status IN ('publish','future','draft','pending','private')", self::CITES, (string) (int) $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------------------------------------------------ REST */

	public static function rest() {
		register_rest_route(
			'publishrank/v1',
			'/sources',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => static function () {
					return rest_ensure_response(
						array(
							'sources' => self::active_sources(),
							'classes' => self::classes(),
						)
					);
				},
			)
		);
	}

	/* ---------------------------------------------------------------- Editor panel */

	public static function editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::CPT === $screen->post_type || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$type = get_post_type_object( (string) $screen->post_type );
		if ( ! $type || empty( $type->public ) ) {
			return;
		}
		wp_enqueue_script( 'publishrank-sources-editor', PUBLISHRANK_SEO_URL . 'assets/js/sources-editor.js', array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ), PUBLISHRANK_SEO_VERSION, true );
		wp_localize_script(
			'publishrank-sources-editor',
			'publishrankSources',
			array(
				'path'      => '/publishrank/v1/sources',
				'manageUrl' => current_user_can( 'edit_pages' ) ? admin_url( 'edit.php?post_type=' . self::CPT ) : '',
				'home'      => self::normalize_host( home_url( '/' ) ),
			)
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'publishrank-sources-editor', 'publishrank', PUBLISHRANK_SEO_PATH . 'languages' );
		}
	}

	/* ------------------------------------------------------------ Admin: meta boxes */

	public static function meta_boxes( $post_type, $post ) {
		if ( self::CPT === $post_type ) {
			add_meta_box( 'publishrank-source-details', __( 'Source details', 'publishrank' ), array( __CLASS__, 'details_box' ), self::CPT, 'normal', 'high' );
			return;
		}
		$type = get_post_type_object( (string) $post_type );
		if ( $type && ! empty( $type->public ) && 'attachment' !== $post_type && ! ( function_exists( 'use_block_editor_for_post' ) && $post instanceof \WP_Post && use_block_editor_for_post( $post ) ) ) {
			add_meta_box( 'publishrank-source-review', __( 'Sources in this article', 'publishrank' ), array( __CLASS__, 'review_box' ), $post_type, 'side', 'default' );
		}
	}

	public static function details_box( $post ) {
		wp_nonce_field( 'publishrank_source_save', 'publishrank_source_nonce' );
		$url   = (string) get_post_meta( $post->ID, self::META_URL, true );
		$class = (string) get_post_meta( $post->ID, self::META_TYPE, true );
		$note  = (string) get_post_meta( $post->ID, self::META_NOTE, true );
		echo '<p><label for="prsrc-url"><strong>' . esc_html__( 'Website address', 'publishrank' ) . '</strong></label><br /><input type="url" class="widefat" id="prsrc-url" name="prsrc_url" value="' . esc_attr( $url ) . '" placeholder="https://www.example.com" required /></p>';
		echo '<p class="description">' . esc_html__( 'Links to this domain and its subdomains are matched to this source.', 'publishrank' ) . '</p>';
		echo '<p><label for="prsrc-class"><strong>' . esc_html__( 'Editorial classification', 'publishrank' ) . '</strong></label><br /><select id="prsrc-class" name="prsrc_class"><option value="">' . esc_html__( '— Not classified —', 'publishrank' ) . '</option>';
		foreach ( self::classes() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $class, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html__( 'Your newsroom\'s own label. PublishRank does not verify sources; writers see a warning when they link to sources marked "Use with caution" or "Do not cite".', 'publishrank' ) . '</p>';
		echo '<p><label for="prsrc-note"><strong>' . esc_html__( 'Internal notes', 'publishrank' ) . '</strong></label><br /><textarea class="widefat" rows="3" id="prsrc-note" name="prsrc_note">' . esc_textarea( $note ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Use the Excerpt box for a short description, "Order" under Page attributes for priority (higher shows first), the Logo box for a logo, and Publish/Draft to make the source active or inactive.', 'publishrank' ) . '</p>';
		if ( 'publish' === $post->post_status ) {
			/* translators: %d: number of articles */
			echo '<p><strong>' . esc_html( sprintf( _n( 'Linked from %d article', 'Linked from %d articles', self::usage_count( $post->ID ), 'publishrank' ), self::usage_count( $post->ID ) ) ) . '</strong></p>';
		}
	}

	public static function save_source( $post_id, $post ) {
		if ( ! isset( $_POST['publishrank_source_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_source_nonce'] ) ), 'publishrank_source_save' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$url   = isset( $_POST['prsrc_url'] ) ? esc_url_raw( wp_unslash( $_POST['prsrc_url'] ), array( 'http', 'https' ) ) : '';
		$class = isset( $_POST['prsrc_class'] ) ? sanitize_key( wp_unslash( $_POST['prsrc_class'] ) ) : '';
		$note  = isset( $_POST['prsrc_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prsrc_note'] ) ) : '';
		self::save_fields( $post_id, $url, $class, $note );
	}

	public static function save_fields( $post_id, $url, $class, $note = '' ) {
		$host = self::normalize_host( $url );
		update_post_meta( $post_id, self::META_URL, '' !== $host ? esc_url_raw( $url ) : '' );
		update_post_meta( $post_id, self::META_HOST, $host );
		update_post_meta( $post_id, self::META_TYPE, isset( self::classes()[ $class ] ) ? $class : '' );
		update_post_meta( $post_id, self::META_NOTE, substr( (string) $note, 0, 2000 ) );
	}

	public static function review_box( $post ) {
		$rows = self::review( $post->post_content );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No links to other websites yet. Links are checked against your Source Directory when you save.', 'publishrank' ) . '</p>';
		} else {
			echo '<ul style="margin:0">';
			foreach ( $rows as $r ) {
				$label = 'unknown' === $r['status'] ? __( 'Not in your directory', 'publishrank' ) : ( self::classes()[ $r['class'] ] ?? __( 'Listed', 'publishrank' ) );
				$color = in_array( $r['status'], array( 'avoid', 'caution' ), true ) ? '#b32d2e' : ( 'unknown' === $r['status'] ? '#996800' : '#00733b' );
				echo '<li style="margin:0 0 8px"><strong>' . esc_html( '' !== $r['name'] ? $r['name'] : $r['host'] ) . '</strong><br /><span style="color:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</span></li>';
			}
			echo '</ul>';
		}
		if ( current_user_can( 'edit_pages' ) ) {
			echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT ) ) . '">' . esc_html__( 'Open the Source Directory', 'publishrank' ) . '</a></p>';
		}
	}

	/* ------------------------------------------------------------ Admin: list table */

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				continue;
			}
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['prsrc_domain']   = __( 'Domain', 'publishrank' );
				$out['prsrc_class']    = __( 'Classification', 'publishrank' );
				$out['prsrc_priority'] = __( 'Priority', 'publishrank' );
				$out['prsrc_usage']    = __( 'Linked from', 'publishrank' );
			}
		}
		return $out;
	}

	public static function column( $col, $post_id ) {
		switch ( $col ) {
			case 'prsrc_domain':
				$host = (string) get_post_meta( $post_id, self::META_HOST, true );
				echo '' !== $host ? '<code>' . esc_html( $host ) . '</code>' : '<em>' . esc_html__( 'Missing address', 'publishrank' ) . '</em>';
				if ( '' !== $host && self::duplicates( $host, (int) $post_id ) ) {
					echo '<br /><span style="color:#8a4600">' . esc_html__( 'Another source uses this domain; the one with the higher priority is matched.', 'publishrank' ) . '</span>';
				}
				break;
			case 'prsrc_class':
				$c = (string) get_post_meta( $post_id, self::META_TYPE, true );
				echo esc_html( self::classes()[ $c ] ?? '—' );
				break;
			case 'prsrc_priority':
				echo (int) get_post_field( 'menu_order', $post_id );
				break;
			case 'prsrc_usage':
				$n = self::usage_count( $post_id );
				/* translators: %d: number of articles */
				echo esc_html( sprintf( _n( '%d article', '%d articles', $n, 'publishrank' ), $n ) );
				break;
		}
	}

	public static function sortable( $cols ) {
		$cols['prsrc_priority'] = 'menu_order';
		$cols['prsrc_domain']   = 'prsrc_domain';
		return $cols;
	}

	public static function admin_query( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || self::CPT !== $q->get( 'post_type' ) ) {
			return;
		}
		if ( 'prsrc_domain' === $q->get( 'orderby' ) ) {
			$q->set( 'meta_key', self::META_HOST );
			$q->set( 'orderby', 'meta_value' );
		} elseif ( ! $q->get( 'orderby' ) ) {
			$q->set( 'orderby', array( 'menu_order' => 'DESC', 'title' => 'ASC' ) );
		}
		$class = isset( $_GET['prsrc_class'] ) ? sanitize_key( wp_unslash( $_GET['prsrc_class'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		if ( '' !== $class && isset( self::classes()[ $class ] ) ) {
			$q->set( 'meta_query', array( array( 'key' => self::META_TYPE, 'value' => $class ) ) );
		}
		// Search also matches the domain and notes, not only the name.
		$s = (string) $q->get( 's' );
		if ( '' !== $s ) {
			$ids = get_posts(
				array(
					'post_type'   => self::CPT,
					'post_status' => 'any',
					'numberposts' => 500,
					'fields'      => 'ids',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						'relation' => 'OR',
						array( 'key' => self::META_HOST, 'value' => $s, 'compare' => 'LIKE' ),
						array( 'key' => self::META_NOTE, 'value' => $s, 'compare' => 'LIKE' ),
					),
				)
			);
			if ( $ids ) {
				$q->set( 's', '' );
				$by_title = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids', 's' => $s ) );
				$q->set( 'post__in', array_values( array_unique( array_merge( $ids, $by_title ) ) ) );
			}
		}
	}

	public static function filters( $post_type ) {
		if ( self::CPT !== $post_type ) {
			return;
		}
		$cur = isset( $_GET['prsrc_class'] ) ? sanitize_key( wp_unslash( $_GET['prsrc_class'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<label class="screen-reader-text" for="prsrc-class-filter">' . esc_html__( 'Filter by classification', 'publishrank' ) . '</label><select name="prsrc_class" id="prsrc-class-filter"><option value="">' . esc_html__( 'All classifications', 'publishrank' ) . '</option>';
		foreach ( self::classes() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function states( $states, $post ) {
		if ( $post instanceof \WP_Post && self::CPT === $post->post_type && 'draft' === $post->post_status ) {
			$states['draft'] = __( 'Inactive', 'publishrank' );
		}
		return $states;
	}

	/* ------------------------------------------------------------- Import / export */

	public static function export_data() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 2000, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$s = self::source( $p );
			unset( $s['id'], $s['logo'], $s['class_label'] );
			$s['notes'] = (string) get_post_meta( $p->ID, self::META_NOTE, true );
			$out[]      = $s;
		}
		return array( 'format' => 'publishrank-sources', 'version' => 1, 'sources' => $out );
	}

	/** Import sources; existing domains are skipped. Imported entries start inactive so an editor reviews them. */
	public static function import_data( $data ) {
		if ( ! is_array( $data ) || 'publishrank-sources' !== ( $data['format'] ?? '' ) || ! is_array( $data['sources'] ?? null ) ) {
			return new \WP_Error( 'publishrank_sources_format', __( 'This is not a PublishRank source export.', 'publishrank' ) );
		}
		$existing = array();
		foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'numberposts' => 5000, 'fields' => 'ids' ) ) as $id ) {
			$existing[ (string) get_post_meta( $id, self::META_HOST, true ) ] = true;
		}
		$added = 0;
		foreach ( array_slice( $data['sources'], 0, 2000 ) as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$host = self::normalize_host( $s['url'] ?? ( $s['domain'] ?? '' ) );
			$name = sanitize_text_field( (string) ( $s['name'] ?? '' ) );
			if ( '' === $host || '' === $name || isset( $existing[ $host ] ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => self::CPT,
					'post_status'  => 'draft',
					'post_title'   => $name,
					'post_excerpt' => sanitize_textarea_field( (string) ( $s['description'] ?? '' ) ),
					'menu_order'   => (int) ( $s['priority'] ?? 0 ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			$url = esc_url_raw( (string) ( $s['url'] ?? '' ), array( 'http', 'https' ) );
			self::save_fields( $id, '' !== $url ? $url : 'https://' . $host, sanitize_key( (string) ( $s['class'] ?? '' ) ), sanitize_textarea_field( (string) ( $s['notes'] ?? '' ) ) );
			if ( ! empty( $s['categories'] ) && is_array( $s['categories'] ) ) {
				wp_set_object_terms( $id, array_map( 'sanitize_text_field', array_slice( $s['categories'], 0, 10 ) ), self::TAX );
			}
			$existing[ $host ] = true;
			++$added;
		}
		return $added;
	}

	public static function handle_export() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to export sources.', 'publishrank' ), 403 );
		}
		check_admin_referer( 'publishrank_sources_export' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="publishrank-sources-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( self::export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	public static function handle_import() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to import sources.', 'publishrank' ), 403 );
		}
		check_admin_referer( 'publishrank_sources_import' );
		$back = admin_url( 'edit.php?post_type=' . self::CPT );
		$file = isset( $_FILES['sources_file']['tmp_name'] ) ? (string) $_FILES['sources_file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$size = isset( $_FILES['sources_file']['size'] ) ? (int) $_FILES['sources_file']['size'] : 0;
		if ( '' === $file || ! is_uploaded_file( $file ) || $size > 2 * MB_IN_BYTES ) {
			\PublishRankSEO\Admin\AdminAssets::flash( __( 'Choose a JSON file under 2 MB.', 'publishrank' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}
		$result = self::import_data( json_decode( (string) file_get_contents( $file ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_wp_error( $result ) ) {
			\PublishRankSEO\Admin\AdminAssets::flash( $result->get_error_message(), 'error' );
		} else {
			/* translators: %d: number of sources */
			\PublishRankSEO\Admin\AdminAssets::flash( sprintf( _n( '%d source imported as inactive. Review and publish it to make it active.', '%d sources imported as inactive. Review and publish them to make them active.', $result, 'publishrank' ), $result ) );
		}
		wp_safe_redirect( $back );
		exit;
	}

	public static function tools_panel() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . self::CPT !== $screen->id || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		echo '<div class="card publishrank-source-tools" data-prn-passthrough style="max-width:none;margin:12px 0 4px"><p><strong>' . esc_html__( 'Your newsroom\'s source list', 'publishrank' ) . '</strong> — ' . esc_html__( 'Writers see these sources as suggestions in the editor, get a warning when they link to a source marked "Use with caution" or "Do not cite", and see which links are not in the directory yet. Classifications are your own editorial labels; PublishRank never marks a source as verified. Publish = active, Draft = inactive.', 'publishrank' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px"><input type="hidden" name="action" value="publishrank_sources_import" />';
		wp_nonce_field( 'publishrank_sources_import' );
		echo '<label for="prsrc-file">' . esc_html__( 'Import JSON:', 'publishrank' ) . '</label> <input type="file" id="prsrc-file" name="sources_file" accept="application/json,.json" required /> <button class="button" type="submit">' . esc_html__( 'Import as inactive', 'publishrank' ) . '</button> <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_sources_export' ), 'publishrank_sources_export' ) ) . '">' . esc_html__( 'Export all', 'publishrank' ) . '</a></form><script>(function(){var c=document.currentScript&&document.currentScript.parentNode;document.addEventListener(\'DOMContentLoaded\',function(){var h=document.querySelector(\'.wrap .wp-header-end\');if(c&&h&&h.parentNode){h.parentNode.insertBefore(c,h.nextSibling);}});}());</script></div>';
		\PublishRankSEO\Admin\AdminAssets::render_flash();
	}
}
