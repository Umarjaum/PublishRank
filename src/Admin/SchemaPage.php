<?php
/** Focused schema settings for news publishers. */
namespace PublishRankSEO\Admin;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Schema\SchemaTypes;
defined( 'ABSPATH' ) || exit;
final class SchemaPage {
public function register() { add_action( 'admin_menu', array( $this, 'menu' ) ); add_action( 'admin_init', array( $this, 'settings' ) ); }
public function menu() { add_submenu_page( Branding::MENU_SLUG, __( 'Schema', 'publishrank' ), __( 'Schema', 'publishrank' ), 'manage_options', 'publishrank-schema', array( $this, 'render' ) ); }
public function settings() {
register_setting( 'publishrank_schema', 'publishrank_schema_default_post_type', array( 'sanitize_callback' => array( $this, 'clean_type' ) ) );
	register_setting( 'publishrank_schema', 'publishrank_schema_default_page_type', array( 'sanitize_callback' => array( $this, 'clean_page_type' ), 'default' => 'WebPage' ) );
		register_setting( 'publishrank_schema', 'publishrank_schema_home_type', array( 'sanitize_callback' => array( $this, 'clean_page_type' ), 'default' => 'WebPage' ) );
register_setting( 'publishrank_schema', 'publishrank_schema_author_enabled', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
	register_setting( 'publishrank_schema', 'publishrank_schema_category_type', array( 'sanitize_callback' => array( $this, 'clean_archive_type' ), 'default' => 'CollectionPage' ) );
	register_setting( 'publishrank_schema', 'publishrank_schema_tag_type', array( 'sanitize_callback' => array( $this, 'clean_archive_type' ), 'default' => 'CollectionPage' ) );
	register_setting( 'publishrank_schema', 'publishrank_schema_archive_type', array( 'sanitize_callback' => array( $this, 'clean_archive_type' ), 'default' => 'CollectionPage' ) );
	register_setting( 'publishrank_schema', 'publishrank_schema_author_type', array( 'sanitize_callback' => array( $this, 'clean_author_type' ), 'default' => 'ProfilePage' ) );
	register_setting( 'publishrank_schema', 'publishrank_schema_search_type', array( 'sanitize_callback' => array( $this, 'clean_search_type' ), 'default' => 'SearchResultsPage' ) );
}
public function clean_type( $value ) { return SchemaTypes::resolve_in( $value, SchemaTypes::default_article_types(), 'NewsArticle' ); }
public function clean_archive_type( $value ) { return SchemaTypes::resolve_in( $value, SchemaTypes::archive_types(), 'CollectionPage' ); }
public function clean_author_type( $value ) { return SchemaTypes::resolve_in( $value, SchemaTypes::author_types(), 'ProfilePage' ); }
public function clean_search_type( $value ) { return SchemaTypes::resolve_in( $value, SchemaTypes::search_types(), 'SearchResultsPage' ); }
public function clean_page_type( $value ) { return SchemaTypes::resolve_in( $value, SchemaTypes::page_default_types(), 'WebPage' ); }
private function select( $name, $types, $current ) {
echo '<select name="' . esc_attr( $name ) . '">';
foreach ( $types as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
echo '</select>';
}
public function render() {
if ( ! current_user_can( 'manage_options' ) ) { return; }
echo '<div class="wrap"><h1>' . esc_html__( 'PublishRank News Schema', 'publishrank' ) . '</h1>';
echo '<p>' . esc_html__( 'The schema editor supports NewsArticle and specialist Article subtypes, FAQPage, VideoObject, ImageGallery, AudioObject, PodcastEpisode, Event, Product, Recipe and Review. Advanced entities emit only when their type-specific fields are complete; publisher, WebSite, WebPage, author, image and breadcrumb nodes remain connected automatically. Use the Schema Test action to validate live output.', 'publishrank' ) . '</p>';
echo '<form method="post" action="options.php">'; settings_fields( 'publishrank_schema' );
echo '<table class="form-table"><tr><th scope="row">' . esc_html__( 'Default schema for news posts', 'publishrank' ) . '</th><td>';
$this->select( 'publishrank_schema_default_post_type', SchemaTypes::default_article_types(), (string) get_option( 'publishrank_schema_default_post_type', 'NewsArticle' ) );
echo '<p class="description">' . esc_html__( 'Use News Article for standard newsroom content. Choose Article for non-news editorial content or Live Blog Post for timestamped coverage.', 'publishrank' ) . '</p></td></tr>';
echo '<tr><th scope="row">' . esc_html__( 'Default schema for pages', 'publishrank' ) . '</th><td>'; $this->select( 'publishrank_schema_default_page_type', SchemaTypes::page_default_types(), (string) get_option( 'publishrank_schema_default_page_type', 'WebPage' ) ); echo '<p class="description">' . esc_html__( 'Every public page receives this WebPage-family node by default. Standard Web Page is the safe default for About, Contact, Privacy, Terms and other normal pages.', 'publishrank' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Homepage schema', 'publishrank' ) . '</th><td>'; $this->select( 'publishrank_schema_home_type', SchemaTypes::page_default_types(), (string) get_option( 'publishrank_schema_home_type', 'WebPage' ) ); echo '<p class="description">' . esc_html__( 'Select a WebPage-family type only; static front pages can still use their per-page override.', 'publishrank' ) . '</p></td></tr>';
	$rows = array(
	array( 'publishrank_schema_category_type', __( 'Category archives', 'publishrank' ), SchemaTypes::archive_types(), 'CollectionPage', __( 'Individual categories can override this on their edit screen.', 'publishrank' ) ),
	array( 'publishrank_schema_tag_type', __( 'Tag archives', 'publishrank' ), SchemaTypes::archive_types(), 'CollectionPage', __( 'Individual tags can override this on their edit screen.', 'publishrank' ) ),
	array( 'publishrank_schema_archive_type', __( 'Other archives', 'publishrank' ), SchemaTypes::archive_types(), 'CollectionPage', __( 'Date, post-type, custom-taxonomy and posts-page archives.', 'publishrank' ) ),
	array( 'publishrank_schema_author_type', __( 'Author pages', 'publishrank' ), SchemaTypes::author_types(), 'ProfilePage', __( 'Applies when author schema is enabled below.', 'publishrank' ) ),
	array( 'publishrank_schema_search_type', __( 'Search results', 'publishrank' ), SchemaTypes::search_types(), 'SearchResultsPage', '' ),
);
foreach ( $rows as $row ) {
	echo '<tr><th scope="row">' . esc_html( $row[1] ) . '</th><td>'; $this->select( $row[0], $row[2], (string) get_option( $row[0], $row[3] ) );
	if ( '' !== $row[4] ) { echo '<p class="description">' . esc_html( $row[4] ) . '</p>'; }
	echo '</td></tr>';
}
echo '<tr><th scope="row">' . esc_html__( 'FAQ pages', 'publishrank' ) . '</th><td><p class="description">' . esc_html__( 'Choose FAQ Page in the schema box of any post or page and enter the questions there. Pages without complete questions fall back to a standard web page.', 'publishrank' ) . '</p></td></tr>';
echo '<tr><th scope="row">' . esc_html__( 'Author schema', 'publishrank' ) . '</th><td><label><input type="checkbox" name="publishrank_schema_author_enabled" value="1" ' . checked( (bool) get_option( 'publishrank_schema_author_enabled', true ), true, false ) . ' /> ' . esc_html__( 'Output ProfilePage and Person connections on author archives', 'publishrank' ) . '</label></td></tr></table>';
submit_button(); echo '</form></div>';
}
}
