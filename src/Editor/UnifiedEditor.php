<?php
/**
 * One PublishRank SEO editing experience for the block editor.
 *
 * How it works (2.26.1):
 * - The PublishRank SEO meta boxes (SEO Overrides, Live Preview, Fix Assistant, Schema Selection, Schema Library,
 *   Schema Studio, Multilingual SEO) are taken out of the block editor's meta box registry with remove_meta_box()
 *   on the add_meta_boxes hook. They are never printed in the "Meta Boxes" area below the content, so no
 *   duplicate controls exist and nothing is hidden with CSS.
 * - Their original render callbacks are printed once into a PublishRank-owned host element in the admin footer
 *   (one <form> per box). Gutenberg does not manage or re-render this host, so the fields keep their names, ids,
 *   nonces and scripts. The PublishRank sidebar (PluginSidebar) and document panel (PluginDocumentSettingPanel)
 *   display these sections in organised tabs and collapsible groups.
 * - Values that are registered post meta (SEO title, description, focus keyword, social text, canonical, robots,
 *   keyphrase lists) are mirrored into the editor's own meta store, so they save natively with the post.
 * - Every other field is saved by a nonce- and capability-checked AJAX request that runs right after a successful,
 *   non-autosave post save. It replays the same save_post callbacks the classic meta box flow uses, so every value
 *   keeps its original storage location.
 * - The Classic Editor is not affected: it keeps the consolidated SEO fields dock (EditorPlacement).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Editor;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class UnifiedEditor {
	const NONCE_ACTION = 'publishrank_editor_save';
	const NONCE_FIELD  = '_pr_ue_nonce';
	const AJAX_ACTION  = 'publishrank_editor_save';

	/** @var array<string,array> Boxes captured for the current block editor screen, keyed by box id. */
	private $boxes = array();

	/** @var bool */
	private static $bridge_request = false;

	/**
	 * PublishRank SEO meta boxes that move into the unified sidebar. Non-SEO boxes (Mastodon sharing, newsroom
	 * source review/details) stay where they are because they are not part of SEO editing.
	 *
	 * @return string[]
	 */
	public static function box_ids() {
		$ids = array(
			'publishrank-seo-overrides',
			'publishrank-live-preview',
			'publishrank_article_intelligence',
			'publishrank-schema',
			'publishrank-schema-studio',
			'publishrank-schema-library',
			'publishrank-localized-seo',
		);
		return array_values( array_unique( array_filter( array_map( 'strval', (array) apply_filters( 'publishrank_unified_editor_boxes', $ids ) ) ) ) );
	}

	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'capture' ), 9999, 2 );
		add_action( 'admin_footer', array( $this, 'render_host' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 40 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_save' ) );
	}

	/** True while the AJAX save bridge replays the save callbacks. */
	public static function is_bridge_request() {
		return self::$bridge_request;
	}

	/** Whether the post (or current screen) uses the block editor. */
	public static function is_block_editor( $post = null ) {
		if ( ! $post instanceof \WP_Post ) {
			$post = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ? $GLOBALS['post'] : null;
		}
		if ( $post instanceof \WP_Post && function_exists( 'use_block_editor_for_post' ) ) {
			return (bool) use_block_editor_for_post( $post );
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
	}

	/** True on a block editor screen for a post type PublishRank manages. */
	public static function on_block_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $screen->post_type, PostMeta::post_types(), true ) ) {
			return false;
		}
		return self::is_block_editor();
	}

	/**
	 * Take the PublishRank SEO boxes out of the block editor's meta box registry.
	 *
	 * @param string        $post_type Post type.
	 * @param \WP_Post|null $post      Post.
	 */
	public function capture( $post_type = '', $post = null ) {
		global $wp_meta_boxes;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $post_type, PostMeta::post_types(), true ) ) {
			return;
		}
		if ( ! self::is_block_editor( $post instanceof \WP_Post ? $post : null ) ) {
			return; // Classic Editor keeps its consolidated dock.
		}
		$page = $screen->id;
		foreach ( self::box_ids() as $id ) {
			foreach ( array( 'normal', 'advanced', 'side' ) as $context ) {
				foreach ( array( 'high', 'core', 'default', 'low', 'sorted' ) as $priority ) {
					if ( isset( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] ) && is_array( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] ) ) {
						if ( ! isset( $this->boxes[ $id ] ) ) {
							$this->boxes[ $id ] = $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ];
						}
					}
				}
				// Native API: marks the box removed for this screen so it can never be printed below the editor.
				remove_meta_box( $id, $screen, $context );
			}
		}
	}

	/** Boxes captured for this request (for tests and diagnostics). @return string[] */
	public function captured_ids() {
		return array_keys( $this->boxes );
	}

	/** Print the captured sections into the PublishRank-owned host. */
	public function render_host() {
		if ( empty( $this->boxes ) || ! self::on_block_screen() ) {
			return;
		}
		$post = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ? $GLOBALS['post'] : null;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		echo '<div id="publishrank-ue-host" class="pr-ue-host" hidden>';
		foreach ( self::box_ids() as $id ) {
			if ( empty( $this->boxes[ $id ] ) ) {
				continue;
			}
			$box   = $this->boxes[ $id ];
			$title = wp_strip_all_tags( isset( $box['title'] ) ? (string) $box['title'] : $id );
			// One form per section: FormData can serialise it wherever the sidebar currently displays it.
			echo '<form id="' . esc_attr( $id ) . '" class="pr-ue-box pr-seodock-root" data-pr-box="' . esc_attr( $id ) . '" data-pr-title="' . esc_attr( $title ) . '" action="#" method="post" novalidate onsubmit="return false">';
			if ( isset( $box['callback'] ) && is_callable( $box['callback'] ) ) {
				call_user_func( $box['callback'], $post, $box );
			}
			echo '</form>';
		}
		echo '</div>';
	}

	/** Registered post meta keys that are mirrored into the block editor's meta store. @return array<string,string> key => kind */
	public static function rest_keys() {
		$keys = array();
		foreach ( array_keys( PostMeta::overridable() ) as $key ) {
			$keys[ $key ] = 'string';
		}
		$keys[ PostMeta::FOCUS_KEYWORD ] = 'string';
		foreach ( array_keys( PostMeta::keyphrase_list_limits() ) as $key ) {
			$keys[ $key ] = 'list';
		}
		return $keys;
	}

	public function enqueue( $hook ) {
		if ( ( 'post.php' !== $hook && 'post-new.php' !== $hook ) || ! self::on_block_screen() ) {
			return;
		}
		global $post;
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$js      = PUBLISHRANK_SEO_PATH . 'assets/js/seo-editor.js';
		$css     = PUBLISHRANK_SEO_PATH . 'assets/css/seo-editor.css';
		$deps    = array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-editor', 'wp-preferences', 'wp-notices', 'wp-compose' );
		foreach ( array( 'wp-edit-post', 'wp-commands', 'wp-keyboard-shortcuts' ) as $optional ) {
			if ( wp_script_is( $optional, 'registered' ) ) {
				$deps[] = $optional;
			}
		}
		foreach ( array( \PublishRankSEO\Admin\EditorPlacement::HANDLE, 'publishrank-editor-score-card' ) as $optional ) {
			if ( wp_script_is( $optional, 'registered' ) || wp_script_is( $optional, 'enqueued' ) ) {
				$deps[] = $optional;
			}
		}
		wp_enqueue_style( 'publishrank-seo-editor', Branding::asset_url( 'css/seo-editor.css' ), array( 'wp-components' ), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_enqueue_script( 'publishrank-seo-editor', Branding::asset_url( 'js/seo-editor.js' ), $deps, Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_set_script_translations( 'publishrank-seo-editor', 'publishrank' );
		wp_localize_script(
			'publishrank-seo-editor',
			'publishrankSeoEditor',
			array(
				'postId'       => $post_id,
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'action'       => self::AJAX_ACTION,
				'nonceField'   => self::NONCE_FIELD,
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'touchKey'     => PostMeta::EDITOR_TOUCH,
				'restKeys'     => self::rest_keys(),
				'boxes'        => array_keys( $this->boxes ),
				'logo'         => Branding::logo_url(),
				'siteName'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'homeUrl'      => home_url( '/' ),
				'canSchema'    => Capabilities::can( 'manage_schema' ),
				'canMedia'     => Capabilities::can( 'manage_media_links' ),
				'links'        => array(
					'schemaStudio' => admin_url( 'admin.php?page=publishrank-schema-studio' ),
					'inspector'    => add_query_arg( array( 'page' => 'publishrank-seo-inspector', 'post_id' => $post_id ), admin_url( 'admin.php' ) ),
					'linkHealth'   => admin_url( 'admin.php?page=publishrank-link-intelligence' ),
					'contentGraph' => admin_url( 'admin.php?page=publishrank-seo-internal-links' ),
					'mediaLinks'   => admin_url( 'admin.php?page=publishrank-media-links' ),
					'settings'     => admin_url( 'admin.php?page=publishrank' ),
				),
			)
		);
	}

	/**
	 * Save bridge: replays the save_post callbacks with the PublishRank section fields, exactly as the classic
	 * meta box submission does. Each callback still verifies its own nonce and capability.
	 */
	public function ajax_save() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the editor and save again.', 'publishrank' ) ), 403 );
		}
		$post_id = isset( $_POST['post_ID'] ) ? absint( wp_unslash( $_POST['post_ID'] ) ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this item.', 'publishrank' ) ), 403 );
		}
		if ( ! in_array( $post->post_type, PostMeta::post_types(), true ) || wp_is_post_revision( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'PublishRank SEO fields are not available for this content type.', 'publishrank' ) ), 400 );
		}
		self::$bridge_request = true;
		$before               = self::fingerprint( $post_id );
		/**
		 * Fires before the PublishRank editor fields are saved from the block editor.
		 *
		 * @param int      $post_id Post ID.
		 * @param \WP_Post $post    Post.
		 */
		do_action( 'publishrank_editor_bridge_before_save', $post_id, $post );
		// Same hooks, in the same order, as wp_insert_post() fires for the classic meta box form.
		do_action( "save_post_{$post->post_type}", $post_id, $post, true );
		do_action( 'save_post', $post_id, $post, true );
		do_action( 'wp_insert_post', $post_id, $post, true );
		do_action( 'publishrank_editor_bridge_after_save', $post_id, $post );
		self::$bridge_request = false;
		clean_post_cache( $post_id );
		$after   = self::fingerprint( $post_id );
		$changed = array();
		foreach ( $after as $key => $hash ) {
			if ( ! isset( $before[ $key ] ) || $before[ $key ] !== $hash ) {
				$changed[] = $key;
			}
		}
		foreach ( array_keys( $before ) as $key ) {
			if ( ! isset( $after[ $key ] ) ) {
				$changed[] = $key;
			}
		}
		wp_send_json_success(
			array(
				'saved'   => true,
				'changed' => array_values( array_unique( $changed ) ),
			)
		);
	}

	/** Hashes of every PublishRank meta value on a post, used to report which fields a save changed. @return array<string,string> */
	private static function fingerprint( $post_id ) {
		$out = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, '_publishrank' ) || 0 === strpos( $key, 'publishrank' ) || 0 === strpos( $key, '_prx' ) ) {
				$out[ $key ] = md5( (string) wp_json_encode( $values ) );
			}
		}
		return $out;
	}
}
