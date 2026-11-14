<?php
/**
 * Puts the PublishRank SEO fields where publishers can reach them instantly.
 *
 * - Classic editor: the SEO Overrides, Schema Selection and Multilingual boxes are lifted out of the footer
 *   and rendered as one tabbed "SEO fields" dock directly under the title.
 * - Block editor: the same boxes are moved into the PublishRank sidebar, under the SEO analysis, while it is open
 *   (see assets/js/seo-dock.js and block-editor-sidebar.js). When the sidebar is closed they go back to the
 *   editor's own Meta Boxes area at the bottom, so they never float over the article.
 *
 * Every field keeps its name, id and nonce, so PostMeta::save() still receives exactly the same POST data.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class EditorPlacement {
	const HANDLE = 'publishrank-seo-dock';

	/** @var array<string,array> Meta boxes captured for the classic dock, keyed by box id. */
	private $boxes = array();

	/** @return string[] Meta box ids that make up the SEO fields. */
	public static function box_ids() {
		return array( 'publishrank-seo-overrides', 'publishrank-schema', 'publishrank-localized-seo' );
	}

	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'relocate' ), 999, 2 );
		add_action( 'edit_form_after_title', array( $this, 'render_classic_dock' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 30 );
	}

	/** True when the current editing screen is a post editor for a post type PublishRank manages. */
	public static function on_editor_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'post' === $screen->base && in_array( (string) $screen->post_type, PostMeta::post_types(), true );
	}

	/** Whether the post being edited uses the block editor. */
	private static function is_block_editor( $post = null ) {
		if ( ! $post instanceof \WP_Post ) {
			$post = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ? $GLOBALS['post'] : null;
		}
		if ( $post instanceof \WP_Post && function_exists( 'use_block_editor_for_post' ) ) {
			return (bool) use_block_editor_for_post( $post );
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
	}

	/** Lift the SEO meta boxes out of the footer for the classic editor and hide the stock Custom Fields box we forced on. */
	public function relocate( $post_type = '', $post = null ) {
		global $wp_meta_boxes;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! in_array( (string) $post_type, PostMeta::post_types(), true ) ) {
			return;
		}
		if ( in_array( (string) $post_type, PostMeta::forced_custom_fields_types(), true ) ) {
			remove_meta_box( 'postcustom', $post_type, 'normal' );
		}
		if ( self::is_block_editor( $post instanceof \WP_Post ? $post : null ) ) {
			return;
		}
		$page = $screen->id;
		foreach ( self::box_ids() as $id ) {
			foreach ( array( 'normal', 'advanced', 'side' ) as $context ) {
				foreach ( array( 'high', 'core', 'default', 'low' ) as $priority ) {
					if ( isset( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] ) && is_array( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] ) ) {
						$this->boxes[ $id ] = $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ];
						unset( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] );
					}
				}
			}
		}
	}

	/** Classic editor: the tabbed SEO fields dock under the title. Tabs are added by seo-dock.js; without JS every section simply shows. */
	public function render_classic_dock( $post = null ) {
		if ( empty( $this->boxes ) || ! $post instanceof \WP_Post ) {
			return;
		}
		echo '<div id="publishrank-seo-dock" class="pr-seodock pr-seodock--classic" data-pr-seodock>';
		echo '<div class="pr-seodock__head"><img class="pr-seodock__logo" src="' . esc_url( Branding::logo_url() ) . '" width="18" height="18" alt="" /><strong class="pr-seodock__title">' . esc_html__( 'PublishRank SEO fields', 'publishrank' ) . '</strong><button type="button" class="pr-seodock__pop" data-pr-pop aria-pressed="false" title="' . esc_attr__( 'Float this panel so you can move it anywhere', 'publishrank' ) . '"><span class="dashicons dashicons-move" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Float or dock the SEO fields panel', 'publishrank' ) . '</span></button><button type="button" class="pr-seodock__toggle" data-pr-toggle aria-expanded="true" aria-controls="publishrank-seo-dock-panes"><span class="screen-reader-text">' . esc_html__( 'Show or hide the SEO fields', 'publishrank' ) . '</span><span class="pr-seodock__chev" aria-hidden="true"></span></button></div>';
		// Section tabs get their own row under the header so they never squeeze the title or the buttons.
		echo '<div class="pr-seodock__tabbar"><div class="pr-seodock__tabs" role="tablist" data-pr-tabs></div></div>';
		echo '<div class="pr-seodock__panes" id="publishrank-seo-dock-panes" data-pr-panes>';
		foreach ( $this->boxes as $id => $box ) {
			echo '<div class="pr-seodock__box pr-seodock-root" data-pr-box="' . esc_attr( $id ) . '" data-pr-title="' . esc_attr( wp_strip_all_tags( isset( $box['title'] ) ? (string) $box['title'] : $id ) ) . '">';
			if ( isset( $box['callback'] ) && is_callable( $box['callback'] ) ) {
				call_user_func( $box['callback'], $post, $box );
			}
			echo '</div>';
		}
		echo '</div></div>';
	}

	/** Register (not enqueue) the dock assets so the block editor sidebar can depend on them. */
	public function register_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/seo-dock.css';
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/seo-dock.js';
		wp_register_style( self::HANDLE, Branding::asset_url( 'css/seo-dock.css' ), array(), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_register_script( self::HANDLE, Branding::asset_url( 'js/seo-dock.js' ), array( 'jquery' ), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
	}

	public function enqueue( $hook ) {
		if ( ( 'post.php' !== $hook && 'post-new.php' !== $hook ) || ! self::on_editor_screen() ) {
			return;
		}
		global $post;
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : ( isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
		wp_localize_script(
			self::HANDLE,
			'publishrankSeoDock',
			array(
				'postId'     => $post_id,
				'blockEditor' => self::is_block_editor( $post instanceof \WP_Post ? $post : null ),
				'boxIds'     => self::box_ids(),
				'touchKey'   => PostMeta::EDITOR_TOUCH,
				'logo'       => Branding::logo_url(),
				'i18n'       => array(
					'summary'  => __( 'Published values', 'publishrank' ),
					'restored' => __( 'Unsaved SEO changes from before the reload were restored. Update the post to save them.', 'publishrank' ),
					'chars'    => __( 'characters', 'publishrank' ),
					'short'    => __( 'a little short', 'publishrank' ),
					'long'     => __( 'too long', 'publishrank' ),
					'auto'     => __( 'Automatic', 'publishrank' ),
					'tabs'     => __( 'SEO field sections', 'publishrank' ),
					'panel'    => __( 'PublishRank SEO fields', 'publishrank' ),
					'minimize' => __( 'Minimize SEO fields', 'publishrank' ),
					'expand'   => __( 'Expand SEO fields', 'publishrank' ),
				),
			)
		);
	}
}
