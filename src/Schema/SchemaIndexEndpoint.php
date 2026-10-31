<?php
/** Public, paginated discovery index for URLs carrying PublishRank schema. */
namespace PublishRankSEO\Schema;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class SchemaIndexEndpoint {
	const REST_NAMESPACE = 'publishrank/v1';
	const CACHE_TTL      = 300;

	public function __construct() {}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'save_post', array( $this, 'invalidate_cache' ) );
		add_action( 'deleted_post', array( $this, 'invalidate_cache' ) );
		add_action( 'transition_post_status', array( $this, 'invalidate_on_status_change' ), 10, 3 );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/schema-index',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_index' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page' => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) { return is_numeric( $value ) && (int) $value >= 1; },
					),
					'per_page' => array(
						'default'           => 50,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) { return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 100; },
					),
				),
			)
		);
	}

	public function get_index( \WP_REST_Request $request ) {
		if ( ! \PublishRankSEO\Core\OutputSettings::schema() ) {
			return new \WP_Error( 'publishrank_schema_disabled', __( 'PublishRank schema output is disabled.', 'publishrank' ), array( 'status' => 404 ) );
		}

		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$generation = (int) get_option( 'publishrank_schema_index_generation', 1 );
		$cache_key  = 'pr_schema_idx_' . md5( $page . ':' . $per_page . ':' . get_current_blog_id() . ':' . $generation );
		$payload   = get_transient( $cache_key );

		if ( false === $payload ) {
			$post_types = get_post_types( array( 'public' => true, 'publicly_queryable' => true ), 'names' );
			unset( $post_types['attachment'] );
			$query = new \WP_Query(
				array(
					'post_type'              => array_values( $post_types ),
					'post_status'            => 'publish',
					'posts_per_page'         => $per_page,
					'paged'                  => $page,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'no_found_rows'          => false,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
					'has_password'           => false,
					'meta_query'             => array(
						'relation' => 'OR',
						array( 'key' => PostMeta::ROBOTS, 'value' => 'noindex', 'compare' => 'NOT LIKE' ),
						array( 'key' => PostMeta::ROBOTS, 'compare' => 'NOT EXISTS' ),
					),
				)
			);

			$items = array();
			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
					continue;
				}

				$canonical = trim( (string) get_post_meta( $post->ID, PostMeta::CANONICAL, true ) );
				$url       = $canonical && wp_http_validate_url( $canonical ) ? esc_url_raw( $canonical ) : get_permalink( $post );
				if ( ! $url ) {
					continue;
				}

				$robots = strtolower( (string) get_post_meta( $post->ID, PostMeta::ROBOTS, true ) );
				if ( false !== strpos( $robots, 'noindex' ) ) {
					continue;
				}

				$is_page = 'page' === $post->post_type;
				$allowed = $is_page ? SchemaTypes::page_types() : SchemaTypes::article_types();
				$fallback = $is_page ? 'WebPage' : (string) get_option( 'publishrank_schema_default_post_type', 'NewsArticle' );
				if ( ! $is_page ) {
					$fallback = SchemaTypes::resolve_in( $fallback, SchemaTypes::default_article_types(), 'NewsArticle' );
				}
				$selected = (string) get_post_meta( $post->ID, PostMeta::SCHEMA_TYPE, true );
				$type     = SchemaTypes::resolve_in( $selected, SchemaTypes::selectable(), '' );
				if ( '' === $type ) {
					$type = SchemaTypes::resolve_in( $fallback, $allowed, $fallback );
				}
				if ( 'WebStory' === $type ) {
					$type = 'Article';
				}

				$item = array(
					'@type'            => 'DataFeedItem',
					'dateModified'     => get_post_modified_time( DATE_W3C, true, $post ),
					'item'             => array(
						'@type'            => $type,
						'url'              => esc_url_raw( $url ),
						'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
						'datePublished'    => get_post_time( DATE_W3C, true, $post ),
						'dateModified'     => get_post_modified_time( DATE_W3C, true, $post ),
						'mainEntityOfPage' => esc_url_raw( $url ),
					),
				);
				$description = (string) get_post_meta( $post->ID, PostMeta::DESCRIPTION, true );
				if ( '' === trim( $description ) ) {
					$description = (string) $post->post_excerpt;
				}
				if ( '' !== trim( $description ) ) {
					$item['item']['description'] = wp_trim_words( wp_strip_all_tags( $description ), 60, '' );
				}
				$image = get_the_post_thumbnail_url( $post, 'full' );
				if ( $image ) {
					$item['item']['image'] = esc_url_raw( $image );
				}
				$items[] = $item;
			}

			$payload = array(
				'@context'    => 'https://schema.org',
				'@type'       => 'DataFeed',
				'name'        => sprintf( __( '%s structured-data discovery index', 'publishrank' ), \PublishRankSEO\Core\PublicationProfile::publication_name() ),
				'description' => __( 'A paginated index of public URLs and their schema types. Fetch each listed canonical URL to read its complete JSON-LD graph.', 'publishrank' ),
				'url'         => rest_url( self::REST_NAMESPACE . '/schema-index' ),
				'dateModified'=> current_time( 'c', true ),
				'numberOfItems' => (int) $query->found_posts,
				'page'        => $page,
				'perPage'     => $per_page,
				'totalPages'  => (int) $query->max_num_pages,
				'dataFeedElement' => $items,
			);
			set_transient( $cache_key, $payload, self::CACHE_TTL );
		}

		$response = new \WP_REST_Response( $payload );
		$response->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=60' );
		if ( $page < (int) ( $payload['totalPages'] ?? 0 ) ) {
			$response->header( 'Link', '<' . esc_url_raw( rest_url( self::REST_NAMESPACE . '/schema-index?page=' . ( $page + 1 ) . '&per_page=' . $per_page ) ) . '>; rel="next"' );
		}
		return $response;
	}

	public function invalidate_cache() {
		$this->delete_index_transients();
	}

	public function invalidate_on_status_change( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status ) {
			$this->delete_index_transients();
		}
	}

	private function delete_index_transients() {
		update_option( 'publishrank_schema_index_generation', (int) get_option( 'publishrank_schema_index_generation', 1 ) + 1, false );
	}
}
