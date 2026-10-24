<?php
/** Per-term schema type for category, tag and custom-taxonomy archives. */
namespace PublishRankSEO\Schema;

defined( 'ABSPATH' ) || exit;

final class TermSchema {
	const META_KEY = '_publishrank_schema_type';
	const NONCE    = 'publishrank_term_schema';

	public function register() {
		add_action( 'admin_init', array( $this, 'hook_taxonomies' ) );
	}

	/** Attach the field to every public taxonomy (taxonomies are registered by the time admin_init runs). */
	public function hook_taxonomies() {
		foreach ( (array) get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit_field' ), 20, 2 );
			add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add_field' ), 20 );
			add_action( 'edited_' . $taxonomy, array( $this, 'save' ), 10, 1 );
			add_action( 'created_' . $taxonomy, array( $this, 'save' ), 10, 1 );
		}
	}

	private function select_html( $current ) {
		$html  = '<select name="' . esc_attr( self::META_KEY ) . '" id="' . esc_attr( self::META_KEY ) . '">';
		$html .= '<option value="">' . esc_html__( '— Follow the archive default —', 'publishrank' ) . '</option>';
		foreach ( SchemaTypes::archive_types() as $key => $label ) {
			$html .= '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/** @param \WP_Term $term */
	public function render_edit_field( $term, $taxonomy = '' ) {
		$current = is_object( $term ) && ! empty( $term->term_id ) ? (string) get_term_meta( (int) $term->term_id, self::META_KEY, true ) : '';
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr( self::META_KEY ) . '">' . esc_html__( 'PublishRank schema type', 'publishrank' ) . '</label></th><td>';
		echo $this->select_html( $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<p class="description">' . esc_html__( 'Schema type for this archive page. Leave on the default to use the setting from PublishRank → Schema.', 'publishrank' ) . '</p></td></tr>';
	}

	public function render_add_field() {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		echo '<div class="form-field"><label for="' . esc_attr( self::META_KEY ) . '">' . esc_html__( 'PublishRank schema type', 'publishrank' ) . '</label>';
		echo $this->select_html( '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';
	}

	public function save( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id < 1 || ! isset( $_POST[ self::NONCE . '_nonce' ] ) ) {
			return; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return; }
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return; }
		$raw   = isset( $_POST[ self::META_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) ) : '';
		$value = SchemaTypes::resolve_in( $raw, SchemaTypes::archive_types(), '' );
		if ( '' === $value ) {
			delete_term_meta( $term_id, self::META_KEY );
			return; }
		update_term_meta( $term_id, self::META_KEY, $value );
	}
}
