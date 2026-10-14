<?php
/** Focused schema settings for news publishers. */
namespace PublishRankSEO\Admin;
use PublishRankSEO\Schema\SchemaTypes;
defined( 'ABSPATH' ) || exit;
final class SchemaPage {
public function register() { add_action( 'admin_menu', array( $this, 'menu' ) ); add_action( 'admin_init', array( $this, 'settings' ) ); }
public function menu() { add_submenu_page( 'publishrank-seo-dashboard', __( 'Schema', 'publishrank' ), __( 'Schema', 'publishrank' ), 'manage_options', 'publishrank-schema', array( $this, 'render' ) ); }
public function settings() {
register_setting( 'publishrank_schema', 'publishrank_schema_default_post_type', array( 'sanitize_callback' => array( $this, 'clean_type' ) ) );
register_setting( 'publishrank_schema', 'publishrank_schema_author_enabled', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
}
public function clean_type( $value ) { $resolved = SchemaTypes::resolve( $value ); return $resolved && array_key_exists( $resolved, SchemaTypes::article_types() ) ? $resolved : 'NewsArticle'; }
private function select( $name, $types, $current ) {
echo '<select name="' . esc_attr( $name ) . '">';
foreach ( $types as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
echo '</select>';
}
public function render() {
if ( ! current_user_can( 'manage_options' ) ) { return; }
echo '<div class="wrap"><h1>' . esc_html__( 'PublishRank News Schema', 'publishrank' ) . '</h1>';
echo '<p>' . esc_html__( 'PublishRank is intentionally focused on news publishers. The editor offers only News Article, Article, Live Blog Post, Video, and Image Gallery. Organization, WebSite, WebPage, author, image, and breadcrumb nodes are foundational connections generated automatically.', 'publishrank' ) . '</p>';
echo '<form method="post" action="options.php">'; settings_fields( 'publishrank_schema' );
echo '<table class="form-table"><tr><th scope="row">' . esc_html__( 'Default schema for news posts', 'publishrank' ) . '</th><td>';
$this->select( 'publishrank_schema_default_post_type', SchemaTypes::article_types(), (string) get_option( 'publishrank_schema_default_post_type', 'NewsArticle' ) );
echo '<p class="description">' . esc_html__( 'Use News Article for standard newsroom content. Choose Article for non-news editorial content or Live Blog Post for timestamped coverage.', 'publishrank' ) . '</p></td></tr>';
echo '<tr><th scope="row">' . esc_html__( 'Author schema', 'publishrank' ) . '</th><td><label><input type="checkbox" name="publishrank_schema_author_enabled" value="1" ' . checked( (bool) get_option( 'publishrank_schema_author_enabled', true ), true, false ) . ' /> ' . esc_html__( 'Output ProfilePage and Person connections on author archives', 'publishrank' ) . '</label></td></tr></table>';
submit_button(); echo '</form></div>';
}
}
