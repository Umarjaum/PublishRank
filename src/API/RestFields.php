<?php
/** Exposes PublishRank fields on the REST API for headless publishers. */
namespace PublishRankSEO\API;

use PublishRankSEO\Links\LinkManager;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\SEO\ReadingExperience;

final class RestFields {
	public function register() {
		add_action( 'rest_api_init', array( $this, 'fields' ) );
	}

	public function fields() {
		foreach ( apply_filters( 'publishrank_rest_post_types', array( 'post', 'page' ) ) as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) { continue; }
			register_rest_field(
				$post_type,
				'publishrank_seo',
				array(
					'get_callback' => array( $this, 'get' ),
					'schema'       => array( 'description' => __( 'PublishRank metadata.', 'publishrank' ), 'type' => 'object', 'context' => array( 'view' ), 'readonly' => true ),
				)
			);
		}
	}

	/** @param array<string,mixed> $object @return array<string,mixed> */
	public function get( $object ) {
		$post_id = (int) ( $object['id'] ?? 0 );
		if ( ! $post_id ) { return array(); }
		$title = PostMeta::get( $post_id, PostMeta::TITLE, '' );
		$description = PostMeta::get( $post_id, PostMeta::DESCRIPTION, '' );
		$canonical = PostMeta::get( $post_id, PostMeta::CANONICAL, '' );
		$og_image = PostMeta::get( $post_id, PostMeta::OG_IMAGE, '' );
		if ( '' === $og_image && has_post_thumbnail( $post_id ) ) { $og_image = (string) get_the_post_thumbnail_url( $post_id, 'full' ); }
		return array(
			'title'            => '' !== $title ? $title : get_the_title( $post_id ),
			'description'      => '' !== $description ? $description : wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 30, '' ),
			'canonical'        => '' !== $canonical ? $canonical : get_permalink( $post_id ),
			'robots'           => PostMeta::get( $post_id, PostMeta::ROBOTS, (string) get_option( 'publishrank_seo_default_robots', 'index,follow' ) ),
			'og_image'         => $og_image,
			'focus_keyword'    => PostMeta::get( $post_id, PostMeta::FOCUS_KEYWORD, '' ),
			'primary_keyphrase' => PostMeta::get( $post_id, PostMeta::PRIMARY_KEYPHRASE, '' ),
			'secondary_keyphrases' => PostMeta::get_list( $post_id, PostMeta::SECONDARY_KEYPHRASES ),
			'related_terms' => PostMeta::get_list( $post_id, PostMeta::RELATED_TERMS ),
			'entity_topic' => PostMeta::get( $post_id, PostMeta::ENTITY_TOPIC, '' ),
			'cornerstone'      => '1' === PostMeta::get( $post_id, PostMeta::CORNERSTONE, '0' ),
			'reading_minutes'  => ReadingExperience::reading_time( $post_id ),
			'links'            => LinkManager::counts( $post_id ),
			'schema_enabled'   => (bool) get_option( 'publishrank_schema_enabled', 1 ),
		);
	}
}