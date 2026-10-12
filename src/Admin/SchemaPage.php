<?php
/** Schema Suite settings: global defaults per context and page-type assignment. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Schema\SchemaTypes;

defined( 'ABSPATH' ) || exit;

final class SchemaPage {
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	public function menu() {
		add_submenu_page( 'publishrank-seo-dashboard', __( 'Schema', 'publishrank' ), __( 'Schema', 'publishrank' ), 'manage_options', 'publishrank-schema', array( $this, 'render' ) );
	}

	public function settings() {
		register_setting( 'publishrank_schema', 'publishrank_schema_default_post_type', array( 'sanitize_callback' => array( $this, 'clean_type' ) ) );
		register_setting( 'publishrank_schema', 'publishrank_schema_default_page_type', array( 'sanitize_callback' => array( $this, 'clean_type' ) ) );
		register_setting( 'publishrank_schema', 'publishrank_schema_home_type', array( 'sanitize_callback' => array( $this, 'clean_type' ) ) );
		register_setting( 'publishrank_schema', 'publishrank_schema_author_enabled', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
		register_setting( 'publishrank_schema', 'publishrank_schema_faq_auto', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
		register_setting( 'publishrank_schema', 'publishrank_schema_page_map', array( 'sanitize_callback' => array( $this, 'clean_map' ) ) );
	}

	public function clean_type( $value ) {
		$resolved = SchemaTypes::resolve( $value );
		return $resolved ? $resolved : 'WebPage';
	}

	public function clean_map( $value ) {
		$clean = array();
		foreach ( array( 'about', 'contact', 'privacy', 'terms' ) as $key ) {
			$clean[ $key ] = isset( $value[ $key ] ) ? absint( $value[ $key ] ) : 0;
		}
		return $clean;
	}

	private function select( $name, $types, $current, $auto_label = '' ) {
		echo '<select name="' . esc_attr( $name ) . '">';
		if ( $auto_label ) { echo '<option value="">' . esc_html( $auto_label ) . '</option>'; }
		foreach ( $types as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$pages = get_pages( array( 'number' => 500 ) );
		$map   = (array) get_option( 'publishrank_schema_page_map', array() );
		echo '<div class="wrap"><h1>' . esc_html__( 'PublishRank Schema', 'publishrank' ) . '</h1>';
		echo '<p>' . esc_html__( 'Smart defaults below apply site-wide. Editors can override any single page from its editor using the “Schema (Structured Data)” panel — most pages need nothing at all.', 'publishrank' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'publishrank_schema' );
		echo '<h2>' . esc_html__( 'Smart defaults', 'publishrank' ) . '</h2><table class="form-table">';
		echo '<tr><th scope="row">' . esc_html__( 'Default schema for posts', 'publishrank' ) . '</th><td>';
		$this->select( 'publishrank_schema_default_post_type', SchemaTypes::article_types(), (string) get_option( 'publishrank_schema_default_post_type', 'NewsArticle' ) );
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Default schema for pages', 'publishrank' ) . '</th><td>';
		$this->select( 'publishrank_schema_default_page_type', SchemaTypes::page_types(), (string) get_option( 'publishrank_schema_default_page_type', 'WebPage' ) );
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Homepage webpage type', 'publishrank' ) . '</th><td>';
		$this->select( 'publishrank_schema_home_type', array_intersect_key( SchemaTypes::page_types(), array_flip( array( 'WebPage', 'CollectionPage', 'AboutPage', 'FAQPage' ) ) ), (string) get_option( 'publishrank_schema_home_type', 'WebPage' ) );
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Author schema', 'publishrank' ) . '</th><td><label><input type="checkbox" name="publishrank_schema_author_enabled" value="1" ' . checked( (bool) get_option( 'publishrank_schema_author_enabled', true ), true, false ) . ' /> ' . esc_html__( 'Output ProfilePage + Person schema on author archives (recommended — builds author E-E-A-T)', 'publishrank' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Auto-detect FAQ', 'publishrank' ) . '</th><td><label><input type="checkbox" name="publishrank_schema_faq_auto" value="1" ' . checked( (bool) get_option( 'publishrank_schema_faq_auto', true ), true, false ) . ' /> ' . esc_html__( 'Build FAQPage schema automatically from &lt;details&gt; blocks when no manual FAQ is set', 'publishrank' ) . '</label></td></tr>';
		echo '</table>';
		echo '<h2>' . esc_html__( 'Page assignment', 'publishrank' ) . '</h2><p class="description">' . esc_html__( 'Assign special pages once — their WebPage schema type is set automatically. Individual pages can still be overridden in the editor.', 'publishrank' ) . '</p><table class="form-table">';
		foreach ( array( 'about' => __( 'About page', 'publishrank' ), 'contact' => __( 'Contact page', 'publishrank' ), 'privacy' => __( 'Privacy policy', 'publishrank' ), 'terms' => __( 'Terms of service', 'publishrank' ) ) as $key => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><select name="publishrank_schema_page_map[' . esc_attr( $key ) . ']"><option value="0">' . esc_html__( '— None —', 'publishrank' ) . '</option>';
			foreach ( $pages as $page ) {
				echo '<option value="' . (int) $page->ID . '"' . selected( absint( isset( $map[ $key ] ) ? $map[ $key ] : 0 ), $page->ID, false ) . '>' . esc_html( $page->post_title ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}
}