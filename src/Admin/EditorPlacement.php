<?php
/**
 * Classic Editor placement of the PublishRank SEO editor.
 *
 * - Every PublishRank SEO meta box listed in Editor\BoxRegistry (SEO fields, Live Preview, Fix Assistant & Full
 *   Report, Schema Selection, Schema Studio, More structured data, Multilingual SEO) is taken out of the Classic
 *   Editor's meta box areas and rendered as one tabbed "PublishRank SEO" dock directly under the title
 *   (edit_form_after_title). Main tabs: Fields, Preview, Analysis, Schema, Multilingual and Media.
 * - The dock lives inside the post form, so every field keeps its name, id and nonce and the normal Classic
 *   submit (Save Draft, Update, autosave) posts exactly the same data to PostMeta::save() and the other owners.
 * - The dock can float (drag it anywhere) and the movable SEO window (seo-window.js) can host it with previews.
 * - Boxes that are not SEO editing (Mastodon, Newsroom sources, see BoxRegistry::kept()) stay where they are.
 * - The block editor is handled by Editor\UnifiedEditor, which reads the same BoxRegistry list.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Editor\BoxRegistry;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class EditorPlacement {
	const HANDLE = 'publishrank-seo-dock';

	/** @var array<string,array> Meta boxes captured for the classic dock, keyed by box id. */
	private $boxes = array();

	/** @return string[] Meta box ids that make up the SEO fields. */
	public static function box_ids() {
		return BoxRegistry::ids();
	}

	/** @return array<string,string> Main dock tabs, in order. */
	public static function tab_labels() {
		return array(
			'fields'       => __( 'Fields', 'publishrank' ),
			'preview'      => __( 'Preview', 'publishrank' ),
			'analysis'     => __( 'Analysis', 'publishrank' ),
			'schema'       => __( 'Schema', 'publishrank' ),
			'multilingual' => __( 'Multilingual', 'publishrank' ),
			'media'        => __( 'Media', 'publishrank' ),
		);
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
				foreach ( array( 'high', 'core', 'default', 'low', 'sorted' ) as $priority ) {
					if ( isset( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] ) && is_array( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] ) ) {
						$this->boxes[ $id ] = $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ];
						unset( $wp_meta_boxes[ $page ][ $context ][ $priority ][ $id ] );
					}
				}
			}
		}
	}

	/** Classic editor: the tabbed PublishRank SEO dock under the title. Tabs are added by seo-dock.js; without JS every section simply shows. */
	public function render_classic_dock( $post = null ) {
		if ( empty( $this->boxes ) || ! $post instanceof \WP_Post ) {
			return;
		}
		$map     = BoxRegistry::classic_tabs();
		$labels  = self::tab_labels();
		$can_edit = Capabilities::can_edit_seo( (int) $post->ID );
		echo '<div id="publishrank-seo-dock" class="pr-seodock pr-seodock--classic" data-pr-seodock' . ( $can_edit ? '' : ' data-pr-readonly="1"' ) . '>';
		echo '<div class="pr-seodock__head"><img class="pr-seodock__logo" src="' . esc_url( Branding::logo_url() ) . '" width="18" height="18" alt="" /><strong class="pr-seodock__title">' . esc_html__( 'PublishRank SEO', 'publishrank' ) . '</strong><span class="pr-seodock__score" data-pr-dock-score aria-hidden="true"></span><button type="button" class="pr-seodock__pop" data-pr-pop aria-pressed="false" title="' . esc_attr__( 'Float this panel so you can move it anywhere', 'publishrank' ) . '"><span class="dashicons dashicons-move" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Float or dock the SEO panel', 'publishrank' ) . '</span></button><button type="button" class="pr-seodock__toggle" data-pr-toggle aria-expanded="true" aria-controls="publishrank-seo-dock-panes"><span class="screen-reader-text">' . esc_html__( 'Show or hide the SEO panel', 'publishrank' ) . '</span><span class="pr-seodock__chev" aria-hidden="true"></span></button></div>';
		// Main tabs on their own row under the header so they never squeeze the title or the buttons.
		echo '<div class="pr-seodock__tabbar"><div class="pr-seodock__tabs" role="tablist" data-pr-tabs></div></div>';
		echo '<div class="pr-seodock__panes" id="publishrank-seo-dock-panes" data-pr-panes>';
		if ( ! $can_edit ) {
			echo '<div class="notice notice-info inline pr-seodock__readonly"><p>' . esc_html__( 'You can view these SEO fields, but your role is not allowed to change them. Changes here are not saved.', 'publishrank' ) . '</p></div>';
		}
		foreach ( $this->boxes as $id => $box ) {
			$tab = isset( $map[ $id ] ) ? $map[ $id ] : sanitize_key( $id );
			echo '<div id="' . esc_attr( $id ) . '" class="pr-seodock__box pr-seodock-root" data-pr-box="' . esc_attr( $id ) . '" data-pr-tab-group="' . esc_attr( $tab ) . '" data-pr-tab-label="' . esc_attr( isset( $labels[ $tab ] ) ? $labels[ $tab ] : '' ) . '" data-pr-title="' . esc_attr( wp_strip_all_tags( isset( $box['title'] ) ? (string) $box['title'] : $id ) ) . '">';
			if ( isset( $box['callback'] ) && is_callable( $box['callback'] ) ) {
				call_user_func( $box['callback'], $post, $box );
			}
			echo '</div>';
		}
		if ( $can_edit ) {
			// Media tab: filled by media-tab.js from the REST API (images this post uses, alt text, URL changes).
			echo '<div class="pr-seodock__box" data-pr-box="publishrank-media" data-pr-tab-group="media" data-pr-tab-label="' . esc_attr( $labels['media'] ) . '" data-pr-title="' . esc_attr( $labels['media'] ) . '"><div data-pr-media-root data-post-id="' . esc_attr( (int) $post->ID ) . '"></div></div>';
		}
		echo '</div></div>';
	}

	/** The movable SEO editor window (fields, previews and analysis in one draggable, resizable window). */
	private function enqueue_window( $post ) {
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/seo-window.css';
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/seo-window.js';
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'publishrank-seo-window', Branding::asset_url( 'css/seo-window.css' ), array( self::HANDLE ), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_enqueue_script( 'publishrank-seo-window', Branding::asset_url( 'js/seo-window.js' ), array( self::HANDLE ), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_localize_script(
			'publishrank-seo-window',
			'publishrankSeoWindow',
			array(
				'blockEditor' => self::is_block_editor( $post ),
				'i18n'        => array(
					'title'            => __( 'PublishRank SEO editor', 'publishrank' ),
					'fields'           => __( 'SEO fields', 'publishrank' ),
					'preview'          => __( 'Previews', 'publishrank' ),
					'analysis'         => __( 'Analysis', 'publishrank' ),
					'tabs'             => __( 'SEO editor sections', 'publishrank' ),
					'open'             => __( 'Open the movable SEO editor with previews', 'publishrank' ),
					'close'            => __( 'Dock the SEO editor back', 'publishrank' ),
					'minimize'         => __( 'Minimize', 'publishrank' ),
					'restore'          => __( 'Restore', 'publishrank' ),
					'snapLeft'         => __( 'Snap to the left edge', 'publishrank' ),
					'snapRight'        => __( 'Snap to the right edge', 'publishrank' ),
					'center'           => __( 'Centre the window', 'publishrank' ),
					'resize'           => __( 'Drag to resize', 'publishrank' ),
					'draggable'        => __( 'movable window header', 'publishrank' ),
					'dragHelp'         => __( 'Drag to move. Double-click to reset. Ctrl/Cmd + Shift + E opens or closes this window.', 'publishrank' ),
					'keyboardHelp'     => __( 'Use the arrow keys to move this window, Shift and an arrow key to move it faster, Alt and an arrow key to resize it, Home to centre it and Escape to minimize it.', 'publishrank' ),
					/* translators: %d: SEO score out of 100. */
					'scoreAria'        => __( 'SEO score %d out of 100', 'publishrank' ),
					'noFields'         => __( 'The SEO fields are not available for this post type.', 'publishrank' ),
					'noPreview'        => __( 'Live previews are switched off for this post type.', 'publishrank' ),
					'noAnalysis'       => __( 'The SEO analysis is not available for this post type.', 'publishrank' ),
					'analysisFloating' => __( 'The SEO analysis is open as its own floating panel.', 'publishrank' ),
					'moveHere'         => __( 'Move it into this window', 'publishrank' ),
				),
			)
		);
	}

	/** Media tab (both editors): the images this post uses, their details and safe URL changes. */
	private function enqueue_media_tab( $post_id ) {
		if ( ! Capabilities::can_edit_seo( (int) $post_id ) ) {
			return;
		}
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/media-tab.css';
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/media-tab.js';
		wp_enqueue_style( 'publishrank-media-tab', Branding::asset_url( 'css/media-tab.css' ), array(), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_enqueue_script( 'publishrank-media-tab', Branding::asset_url( 'js/media-tab.js' ), array(), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_localize_script(
			'publishrank-media-tab',
			'publishrankMediaTab',
			array(
				'restRoot' => esc_url_raw( rest_url( 'publishrank/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'canEdit'  => true,
				'i18n'     => array(
					'featured'     => __( 'Featured image', 'publishrank' ),
					'social'       => __( 'Social image', 'publishrank' ),
					'author'       => __( 'Author image', 'publishrank' ),
					'video'        => __( 'Video thumbnail', 'publishrank' ),
					'content'      => __( 'In content', 'publishrank' ),
					'pick'         => __( 'Choose social image', 'publishrank' ),
					'refresh'      => __( 'Refresh', 'publishrank' ),
					'altHelp'      => __( 'Alt text here is the Media Library default for the image. An image block can carry its own alt text; PublishRank never rewrites block attributes.', 'publishrank' ),
					'loading'      => __( 'Loading images…', 'publishrank' ),
					/* translators: %d: number of images. */
					'count'        => __( '%d images used by this item.', 'publishrank' ),
					'none'         => __( 'This item does not use any images yet.', 'publishrank' ),
					'local'        => __( 'Media Library', 'publishrank' ),
					'external'     => __( 'External', 'publishrank' ),
					'unlinked'     => __( 'Not in Media Library', 'publishrank' ),
					/* translators: %d: number of references. */
					'refs'         => __( '%d references', 'publishrank' ),
					'small'        => __( 'Below 1200×630: some networks show a small card.', 'publishrank' ),
					'noAlt'        => __( 'No default alt text.', 'publishrank' ),
					'edit'         => __( 'Edit details', 'publishrank' ),
					'details'      => __( 'Details', 'publishrank' ),
					'alt'          => __( 'Alt text (default)', 'publishrank' ),
					'title'        => __( 'Title', 'publishrank' ),
					'caption'      => __( 'Caption', 'publishrank' ),
					'description'  => __( 'Description', 'publishrank' ),
					'save'         => __( 'Save image details', 'publishrank' ),
					'noEdit'       => __( 'You cannot edit this image.', 'publishrank' ),
					'saving'       => __( 'Saving…', 'publishrank' ),
					'saved'        => __( 'Saved.', 'publishrank' ),
					'extNote'      => __( 'This image is on another website. It is stored only as a link in this post; no Media Library item is changed.', 'publishrank' ),
					'unlinkedNote' => __( 'This address is on your site but is not a Media Library item, so it has no editable details.', 'publishrank' ),
					'newUrl'       => __( 'Point every use of this file to a new address', 'publishrank' ),
					'dry'          => __( 'Preview change (dry run)', 'publishrank' ),
					'badUrl'       => __( 'Use a full http:// or https:// address.', 'publishrank' ),
					'planning'     => __( 'Building a dry run… nothing is changed.', 'publishrank' ),
					'dryDone'      => __( 'Dry run ready. Nothing has been changed yet.', 'publishrank' ),
					/* translators: %d: number of file addresses. */
					'variants'     => __( '%d file addresses (original and sizes) would be redirected to the new address', 'publishrank' ),
					'rights'       => __( 'Credit and licence (Google image metadata)', 'publishrank' ),
					'credit'       => __( 'Credit line', 'publishrank' ),
					'creator'      => __( 'Photographer / creator', 'publishrank' ),
					'copyright'    => __( 'Copyright notice', 'publishrank' ),
					'license'      => __( 'Licence URL', 'publishrank' ),
					'licensePage'  => __( 'Where to license it (URL)', 'publishrank' ),
					'scanNext'     => __( 'Every post, setting and block that uses it is scanned and listed for your review before anything is written.', 'publishrank' ),
					'mime'         => __( 'File check: ', 'publishrank' ),
					'review'       => __( 'Review and apply in Media Link Manager', 'publishrank' ),
					'useUrl'       => __( 'Check and use', 'publishrank' ),
					'checking'     => __( 'Checking…', 'publishrank' ),
					'extOk'        => __( 'Social image set. It stays a plain link; no Media Library item is changed.', 'publishrank' ),
					'extWarn'      => __( 'Set, but the server could not confirm the image: ', 'publishrank' ),
					'extTitle'     => __( 'Use an external social image address', 'publishrank' ),
					'extLabel'     => __( 'Image address (http or https)', 'publishrank' ),
					'noPicker'     => __( 'The Media Library is not available on this screen.', 'publishrank' ),
					'pickTitle'    => __( 'Choose the social image', 'publishrank' ),
					'pickButton'   => __( 'Use as social image', 'publishrank' ),
					'picked'       => __( 'Social image updated. Save the post to publish it.', 'publishrank' ),
					'detailsFor'   => __( 'Image details', 'publishrank' ),
				),
			)
		);
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
		$this->enqueue_media_tab( $post_id );
		// The movable window is a Classic Editor convenience only; the block editor uses the unified sidebar.
		if ( ! self::is_block_editor( $post instanceof \WP_Post ? $post : null ) ) {
			$this->enqueue_window( $post instanceof \WP_Post ? $post : null );
		}
		wp_localize_script(
			self::HANDLE,
			'publishrankSeoDock',
			array(
				'postId'     => $post_id,
				'blockEditor' => self::is_block_editor( $post instanceof \WP_Post ? $post : null ),
				'boxIds'     => self::box_ids(),
				'canEdit'    => $post_id ? Capabilities::can_edit_seo( $post_id ) : Capabilities::can( 'edit_seo' ),
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
					'sections' => __( 'SEO field groups', 'publishrank' ),
					'readonly' => __( 'Your role can view but not change these SEO fields.', 'publishrank' ),
				),
			)
		);
	}
}
