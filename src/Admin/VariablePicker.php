<?php
/**
 * Dynamic-variable dropdown: assets, localized registry data and the markup slot used next to template fields.
 *
 * The dropdown contents come from Meta\TemplateVariables (the same registry the resolver uses), and
 * assets/js/template-variables.js fills every ".pr-var-slot" with a grouped list that inserts the exact token.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Meta\TemplateVariables;

defined( 'ABSPATH' ) || exit;

final class VariablePicker {
	const HANDLE = 'publishrank-template-variables';

	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	/** Post editor screens for the post types that carry PublishRank SEO fields. */
	public function maybe_enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( (string) $screen->post_type, PostMeta::post_types(), true ) ) {
			return;
		}
		self::enqueue( (string) $screen->post_type );
	}

	/**
	 * Enqueue the picker script and style once.
	 *
	 * @param string $post_type Post type being edited, or '' outside the post editor.
	 */
	public static function enqueue( $post_type = '' ) {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		$js  = Branding::version() . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/js/template-variables.js' );
		$css = Branding::version() . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/css/template-variables.css' );
		wp_enqueue_style( self::HANDLE, Branding::asset_url( 'css/template-variables.css' ), array(), $css );
		wp_enqueue_script( self::HANDLE, Branding::asset_url( 'js/template-variables.js' ), array(), $js, true );
		wp_localize_script( self::HANDLE, 'publishrankVariables', TemplateVariables::js_payload( $post_type ) );
	}

	/**
	 * Markup placeholder that the script replaces with the dropdown for one field.
	 *
	 * @param string $target_id DOM id of the input or textarea.
	 * @param string $field     'title' or 'description'.
	 * @param string $context   'post', 'home' or 'archive'.
	 * @return string HTML (already escaped).
	 */
	public static function slot( $target_id, $field = 'title', $context = 'post' ) {
		return '<span class="pr-var-slot" data-target="' . esc_attr( $target_id ) . '" data-field="' . esc_attr( 'description' === $field ? 'description' : 'title' ) . '" data-context="' . esc_attr( $context ) . '"></span>';
	}
}
