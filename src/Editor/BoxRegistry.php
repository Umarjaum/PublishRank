<?php
/**
 * The single list of PublishRank SEO meta boxes.
 *
 * Both editors read this list: the block editor (UnifiedEditor) takes these boxes out of the Meta Boxes area and
 * shows them in the PublishRank sidebar; the Classic Editor (EditorPlacement) lifts them into the tabbed SEO dock
 * under the title. Because both read the same list, the two editors cannot drift apart again.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Editor;

defined( 'ABSPATH' ) || exit;

final class BoxRegistry {

	/**
	 * PublishRank SEO meta boxes, in display order. Filterable through publishrank_unified_editor_boxes.
	 *
	 * @return string[]
	 */
	public static function ids() {
		$ids = array(
			'publishrank-seo-overrides',
			'publishrank-live-preview',
			'publishrank_article_intelligence',
			'publishrank-schema',
			'publishrank-schema-studio',
			'publishrank-schema-library',
			'publishrank-localized-seo',
		);
		return array_values( array_unique( array_filter( array_map( 'strval', (array) apply_filters( 'publishrank_unified_editor_boxes', $ids ) ) ) ) );
	}

	/**
	 * Boxes that intentionally stay where WordPress puts them, because they are not SEO editing: Mastodon sharing
	 * and the Newsroom source boxes.
	 *
	 * @return string[]
	 */
	public static function kept() {
		return array( 'publishrank-mastodon', 'publishrank-source-details', 'publishrank-source-review' );
	}

	/**
	 * Classic dock tab each box belongs to. Boxes without an entry get their own tab.
	 *
	 * @return array<string,string> box id => tab key
	 */
	public static function classic_tabs() {
		return array(
			'publishrank-seo-overrides'        => 'fields',
			'publishrank-live-preview'         => 'preview',
			'publishrank_article_intelligence' => 'analysis',
			'publishrank-schema'               => 'schema',
			'publishrank-schema-studio'        => 'schema',
			'publishrank-schema-library'       => 'schema',
			'publishrank-localized-seo'        => 'multilingual',
		);
	}
}
