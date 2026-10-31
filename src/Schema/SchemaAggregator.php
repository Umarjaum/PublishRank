<?php
/** Public, cacheable JSON-LD content graph for AI crawlers and knowledge systems. */
namespace PublishRankSEO\Schema;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Meta\TemplateResolver;
use PublishRankSEO\Core\OutputSettings;
defined( 'ABSPATH' ) || exit;
final class SchemaAggregator {
const REST_NAMESPACE = 'publishrank/v1';
const OPTION_ENABLED = 'publishrank_schema_aggregator_enabled';
const OPTION_CUSTOM = 'publishrank_schema_aggregator_custom';
const OPTION_OVERRIDE = 'publishrank_schema_aggregator_override';
public function register() {
add_action( 'rest_api_init', array( $this, 'routes' ) );
foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'transition_post_status' ) as $hook ) { add_action( $hook, array( $this, 'invalidate' ) ); }
}
public function routes() {
register_rest_route( self::REST_NAMESPACE, '/schema-aggregator', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'get' ), 'permission_callback' => '__return_true' ) );
}
public function get() {
if ( ! get_option( self::OPTION_ENABLED, 1 ) || ! OutputSettings::schema() ) return new \WP_Error( 'publishrank_schema_aggregator_disabled', __( 'The Schema Aggregator is disabled.', 'publishrank' ), array( 'status' => 404 ) );
$custom = trim( (string) get_option( self::OPTION_CUSTOM, '' ) );
if ( get_option( self::OPTION_OVERRIDE, 0 ) && '' !== $custom ) { $json = json_decode( $custom, true ); if ( is_array( $json ) ) return $this->response( $json ); }
$key = 'publishrank_schema_aggregator_' . (string) get_option( 'publishrank_schema_index_generation', 1 );
$data = get_transient( $key );
if ( false === $data ) { $data = $this->build(); set_transient( $key, $data, 600 ); }
return $this->response( $data );
}
private function response( $data ) { $r = new \WP_REST_Response( $data ); $r->header( 'Content-Type', 'application/ld+json; charset=UTF-8' ); $r->header( 'Cache-Control', 'public, max-age=600, stale-while-revalidate=120' ); return $r; }
private function build() {
$items = array(); $types = get_post_types( array( 'public' => true, 'publicly_queryable' => true ), 'names' ); unset( $types['attachment'] );
$q = new \WP_Query( array( 'post_type' => array_values( $types ), 'post_status' => 'publish', 'posts_per_page' => 1000, 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'ignore_sticky_posts' => true, 'has_password' => false ) );
foreach ( $q->posts as $post ) {
if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) continue;
$robots = strtolower( (string) get_post_meta( $post->ID, PostMeta::ROBOTS, true ) ); if ( false !== strpos( $robots, 'noindex' ) ) continue;
	$url = get_permalink( $post ); if ( ! $url ) continue;
	$desc = get_post_meta( $post->ID, PostMeta::DESCRIPTION, true );
	// Resolve SEO templates before exposing the public feed. Raw editor tokens such
	// as %%focus_keyword%% and %%excerpt%% are implementation details and should
	// never leak into machine-readable schema output.
	if ( '' !== trim( (string) $desc ) ) {
		$desc = TemplateResolver::resolve( $desc, array( 'post_id' => $post->ID, 'field' => 'description' ) );
	}
	if ( '' === trim( (string) $desc ) ) {
		$desc = TemplateResolver::value( 'excerpt', array( 'post_id' => $post->ID, 'field' => 'description' ) );
	}
	$desc = trim( (string) $desc, " \t\n\r\0\x0B:|•-" );
$type = 'page' === $post->post_type ? 'WebPage' : 'Article';
$node = array( '@type' => $type, '@id' => esc_url_raw( $url ) . '#schema', 'url' => esc_url_raw( $url ), 'name' => wp_strip_all_tags( get_the_title( $post ) ), 'headline' => wp_strip_all_tags( get_the_title( $post ) ), 'datePublished' => get_post_time( DATE_W3C, true, $post ), 'dateModified' => get_post_modified_time( DATE_W3C, true, $post ), 'isPartOf' => array( '@id' => trailingslashit( home_url( '/' ) ) . '#website' ) );
if ( '' !== trim( (string) $desc ) ) $node['description'] = wp_trim_words( wp_strip_all_tags( $desc ), 80, '' );
$image = get_the_post_thumbnail_url( $post, 'full' ); if ( $image ) $node['image'] = esc_url_raw( $image );
$items[] = $node;
}
return array( '@context' => 'https://schema.org', '@type' => 'DataFeed', 'name' => sprintf( __( '%s complete schema content index', 'publishrank' ), \PublishRankSEO\Core\PublicationProfile::publication_name() ), 'description' => __( 'A machine-readable index of public, indexable website content for AI crawlers and knowledge systems.', 'publishrank' ), 'url' => rest_url( self::REST_NAMESPACE . '/schema-aggregator' ), 'dateModified' => current_time( 'c', true ), 'numberOfItems' => count( $items ), 'publisher' => array( '@type' => 'Organization', 'name' => \PublishRankSEO\Core\PublicationProfile::publication_name(), 'url' => home_url( '/' ) ), 'dataFeedElement' => $items );
}
public function invalidate() { delete_transient( 'publishrank_schema_aggregator_' . (string) get_option( 'publishrank_schema_index_generation', 1 ) ); }
public static function verify() { $enabled = (bool) get_option( self::OPTION_ENABLED, 1 ); $override = (bool) get_option( self::OPTION_OVERRIDE, 0 ); $custom = trim( (string) get_option( self::OPTION_CUSTOM, '' ) ); if ( $override && ( '' === $custom || null === json_decode( $custom, true ) ) ) return array( 'level' => 'error', 'msg' => __( 'Schema Aggregator override is enabled but the custom JSON is invalid or empty.', 'publishrank' ) ); return array( 'level' => $enabled ? 'ok' : 'warn', 'msg' => $enabled ? __( 'Schema Aggregator endpoint is enabled and available to AI crawlers.', 'publishrank' ) : __( 'Schema Aggregator endpoint is disabled.', 'publishrank' ) ); }
}
