<?php
/** Exposes PublishRank fields on the REST API for headless publishers. */
namespace PublishRankSEO\API;

use PublishRankSEO\Links\LinkManager;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Meta\SeoData;
use PublishRankSEO\SEO\ReadingExperience;

defined( 'ABSPATH' ) || exit;

final class RestFields {
	public function register() {
		add_action( 'rest_api_init', array( $this, 'fields' ) );
	}

	public function fields() {
		foreach ( apply_filters(
			'publishrank_rest_post_types',
			array_values(
				get_post_types(
					array(
						'public'       => true,
						'show_in_rest' => true,
					),
					'names'
				)
			)
		) as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue; }
			register_rest_field(
				$post_type,
				'publishrank_seo',
				array(
					'get_callback' => array( $this, 'get' ),
					'schema'       => array(
						'description' => __( 'PublishRank metadata.', 'publishrank' ),
						'type'        => 'object',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
				)
			);
		}
	}

	/** @param array<string,mixed> $object @return array<string,mixed> */
	public function get( $object ) {
		$post_id = (int) ( $object['id'] ?? 0 );
		if ( ! $post_id ) {
			return array(); }
		$status = get_post_status( $post_id );
		if ( 'publish' !== $status && ! current_user_can( 'read_post', $post_id ) ) {
			return array(); }
		$seo   = SeoData::for_post( $post_id );
		$image = isset( $seo['image'] ) && is_array( $seo['image'] ) ? $seo['image'] : array();
		return array(
			'title'                => isset( $seo['title'] ) ? $seo['title'] : '',
			'description'          => isset( $seo['description'] ) ? $seo['description'] : '',
			'canonical'            => isset( $seo['canonical'] ) ? $seo['canonical'] : '',
			'robots'               => isset( $seo['robots'] ) ? $seo['robots'] : '',
			'og_title'             => isset( $seo['og_title'] ) ? $seo['og_title'] : '',
			'og_description'       => isset( $seo['og_description'] ) ? $seo['og_description'] : '',
			'og_image'             => isset( $image['url'] ) ? $image['url'] : '',
			'image'                => array(
				'url'    => isset( $image['url'] ) ? $image['url'] : '',
				'alt'    => isset( $image['alt'] ) ? $image['alt'] : '',
				'width'  => isset( $image['width'] ) ? (int) $image['width'] : 0,
				'height' => isset( $image['height'] ) ? (int) $image['height'] : 0,
				'type'   => isset( $image['type'] ) ? $image['type'] : '',
			),
			'sources'              => isset( $seo['sources'] ) ? $seo['sources'] : array(),
			'focus_keyword'        => PostMeta::get( $post_id, PostMeta::FOCUS_KEYWORD, '' ),
			'primary_keyphrase'    => PostMeta::get( $post_id, PostMeta::PRIMARY_KEYPHRASE, '' ),
			'secondary_keyphrases' => PostMeta::get_list( $post_id, PostMeta::SECONDARY_KEYPHRASES ),
			'related_terms'        => PostMeta::get_list( $post_id, PostMeta::RELATED_TERMS ),
			'entity_topic'         => PostMeta::get( $post_id, PostMeta::ENTITY_TOPIC, '' ),
			'cornerstone'          => '1' === PostMeta::get( $post_id, PostMeta::CORNERSTONE, '0' ),
			'reading_minutes'      => ReadingExperience::reading_time( $post_id ),
			'links'                => LinkManager::counts( $post_id ),
			'schema_enabled'       => (bool) get_option( 'publishrank_schema_enabled', 1 ),
		);
	}
}
