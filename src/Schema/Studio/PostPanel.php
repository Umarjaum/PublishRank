<?php
/**
 * Per-post Schema Studio controls in the editor: see which templates apply, switch individual templates
 * off, assign extra templates to this item and override single property values.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Studio;

use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class PostPanel {

	const NONCE = 'publishrank_schema_studio_post';

	public function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( $this, 'box' ), 30, 2 );
		add_action( 'save_post', array( $this, 'save' ), 25, 2 );
	}

	public function box( $post_type, $post = null ) {
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->public ) || 'attachment' === $post_type || ! Capabilities::can( 'manage_schema' ) ) {
			return;
		}
		add_meta_box( 'publishrank-schema-studio', __( 'Schema Studio', 'publishrank' ), array( $this, 'render' ), $post_type, 'normal', 'low', array( '__back_compat_meta_box' => false ) );
	}

	public function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		$assign  = Templates::post_assignment( $post->ID );
		$ctx     = Context::for_post( $post->ID );
		$all     = Templates::all();
		$matched = array();
		if ( $ctx ) {
			$saved_remove     = $assign['remove'];
			foreach ( $all as $id => $tpl ) {
				if ( 'enabled' === $tpl['status'] && Templates::matches( $tpl, $ctx ) ) {
					$matched[ $id ] = $tpl;
				}
			}
		}
		$studio = admin_url( 'admin.php?page=publishrank-schema-studio' );
		echo '<div class="pr-ss-post">';
		echo '<p class="description">' . esc_html__( 'Templates add or enrich structured data for this item. Values come from the template; override a single value here when this item needs something different.', 'publishrank' ) . ' <a href="' . esc_url( $studio ) . '">' . esc_html__( 'Open Schema Studio', 'publishrank' ) . '</a></p>';
		echo '<p><label><input type="checkbox" name="pr_ss[mode]" value="none" ' . checked( 'none', $assign['mode'], false ) . ' /> ' . esc_html__( 'Do not apply any Schema Studio template to this item', 'publishrank' ) . '</label></p>';
		echo '<table class="widefat striped pr-ss-post__table"><thead><tr><th>' . esc_html__( 'Template', 'publishrank' ) . '</th><th>' . esc_html__( 'Type', 'publishrank' ) . '</th><th>' . esc_html__( 'Applies', 'publishrank' ) . '</th><th>' . esc_html__( 'Overrides', 'publishrank' ) . '</th></tr></thead><tbody>';
		$rows = $matched;
		foreach ( $assign['add'] as $id ) {
			if ( isset( $all[ $id ] ) ) {
				$rows[ $id ] = $all[ $id ];
			}
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No enabled template matches this item.', 'publishrank' ) . '</td></tr>';
		}
		foreach ( $rows as $id => $tpl ) {
			$forced = in_array( $id, $assign['add'], true );
			echo '<tr><td><strong>' . esc_html( $tpl['name'] ) . '</strong>' . ( $forced ? ' <span class="pr-ss-chip">' . esc_html__( 'assigned here', 'publishrank' ) . '</span>' : '' ) . '</td><td><code>' . esc_html( $tpl['type'] ) . '</code></td>';
			echo '<td><label><input type="checkbox" name="pr_ss[keep][]" value="' . esc_attr( $id ) . '" ' . checked( ! in_array( $id, $assign['remove'], true ), true, false ) . ' /> ' . esc_html__( 'Use on this item', 'publishrank' ) . '</label>';
			if ( $forced ) {
				echo '<input type="hidden" name="pr_ss[add][]" value="' . esc_attr( $id ) . '" />';
			}
			echo '<input type="hidden" name="pr_ss[listed][]" value="' . esc_attr( $id ) . '" /></td><td>';
			$ov = isset( $assign['overrides'][ $id ] ) ? $assign['overrides'][ $id ] : array();
			foreach ( $tpl['properties'] as $p ) {
				if ( in_array( $p['source'], array( 'static', 'dynamic' ), true ) ) {
					echo '<label class="pr-ss-post__ov"><span>' . esc_html( $p['name'] ) . '</span><input type="text" name="pr_ss[ov][' . esc_attr( $id ) . '][' . esc_attr( $p['name'] ) . ']" value="' . esc_attr( isset( $ov[ $p['name'] ] ) ? $ov[ $p['name'] ] : '' ) . '" placeholder="' . esc_attr( Engine::describe( $p ) ) . '" /></label>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$others = array_diff_key( $all, $rows );
		if ( $others ) {
			echo '<p><label>' . esc_html__( 'Assign another template to this item:', 'publishrank' ) . ' <select name="pr_ss[add][]"><option value="">—</option>';
			foreach ( $others as $id => $tpl ) {
				echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $tpl['name'] . ' (' . $tpl['type'] . ( 'enabled' === $tpl['status'] ? '' : ', ' . __( 'disabled', 'publishrank' ) ) . ')' ) . '</option>';
			}
			echo '</select></label></p>';
		}
		if ( 'publish' === $post->post_status ) {
			echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'sample_kind' => 'post', 'sample_id' => $post->ID ), $studio . '#diagnostics' ) ) . '">' . esc_html__( 'Check this item\'s structured data', 'publishrank' ) . '</a></p>';
		}
		echo '</div>';
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! Capabilities::can( 'manage_schema' ) ) {
			return;
		}
		$in     = isset( $_POST['pr_ss'] ) && is_array( $_POST['pr_ss'] ) ? wp_unslash( $_POST['pr_ss'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
		$listed = array_map( 'sanitize_key', isset( $in['listed'] ) ? (array) $in['listed'] : array() );
		$keep   = array_map( 'sanitize_key', isset( $in['keep'] ) ? (array) $in['keep'] : array() );
		$add    = array_values( array_unique( array_filter( array_map( 'sanitize_key', isset( $in['add'] ) ? (array) $in['add'] : array() ) ) ) );
		$remove = array_values( array_diff( $listed, $keep ) );
		$add    = array_values( array_diff( $add, $remove ) );
		$ov     = array();
		foreach ( isset( $in['ov'] ) && is_array( $in['ov'] ) ? $in['ov'] : array() as $tid => $props ) {
			foreach ( is_array( $props ) ? $props : array() as $name => $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$ov[ sanitize_key( $tid ) ][ preg_replace( '/[^A-Za-z0-9]/', '', (string) $name ) ] = sanitize_textarea_field( (string) $value );
				}
			}
		}
		$data = array( 'mode' => isset( $in['mode'] ) && 'none' === $in['mode'] ? 'none' : 'inherit', 'add' => $add, 'remove' => $remove, 'overrides' => $ov );
		if ( 'inherit' === $data['mode'] && ! $add && ! $remove && ! $ov ) {
			delete_post_meta( $post_id, Templates::POST_META );
		} else {
			update_post_meta( $post_id, Templates::POST_META, $data );
		}
	}
}
