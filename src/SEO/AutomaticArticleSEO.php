<?php
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic SEO is computed live from the post (see Meta\SeoData); it is never copied into post meta.
 * Post meta holds only values an editor chose to override, so editing a post's title, excerpt or content
 * keeps its SEO title and description current.
 *
 * Earlier versions copied generated values into post meta on first save. migrate() removes those stored
 * copies, but only where they still equal what the old generator produced, so real overrides are kept.
 */
final class AutomaticArticleSEO {
	const MIGRATION_OPTION = 'publishrank_auto_snapshot_cleanup';
	const BATCH            = 200;

	public function register() {
		add_action( 'admin_init', array( $this, 'migrate' ) );
	}

	/** Batched, resumable cleanup of snapshots stored by versions before 2.9.38. */
	public function migrate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$state = get_option(
			self::MIGRATION_OPTION,
			array(
				'done'    => false,
				'last_id' => 0,
			)
		);
		if ( ! is_array( $state ) || ! empty( $state['done'] ) ) {
			return; }
		global $wpdb;
		$last = isset( $state['last_id'] ) ? absint( $state['last_id'] ) : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s,%s,%s) AND post_id > %d ORDER BY post_id ASC LIMIT %d", PostMeta::TITLE, PostMeta::DESCRIPTION, PostMeta::OG_TITLE, PostMeta::OG_DESCRIPTION, $last, self::BATCH ) );
		if ( empty( $ids ) ) {
			update_option(
				self::MIGRATION_OPTION,
				array(
					'done'    => true,
					'last_id' => $last,
				),
				false
			);
			return;
		}
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof \WP_Post ) {
				$this->clean_post( $post ); }
			$last = max( $last, (int) $id );
		}
		update_option(
			self::MIGRATION_OPTION,
			array(
				'done'    => false,
				'last_id' => $last,
			),
			false
		);
	}

	private function clean_post( $post ) {
		$legacy = array(
			PostMeta::TITLE          => $this->legacy_title( $post ),
			PostMeta::OG_TITLE       => $this->legacy_title( $post ),
			PostMeta::DESCRIPTION    => $this->legacy_description( $post ),
			PostMeta::OG_DESCRIPTION => $this->legacy_description( $post ),
		);
		foreach ( $legacy as $key => $generated ) {
			$stored = (string) get_post_meta( $post->ID, $key, true );
			if ( '' !== $stored && $stored === $generated ) {
				delete_post_meta( $post->ID, $key ); }
		}
	}

	private function legacy_title( $post ) {
		$raw       = wp_strip_all_tags( (string) $post->post_title );
		$site      = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$separator = (string) get_option( 'publishrank_seo_title_separator', '|' );
		$value     = $raw && $site ? $raw . ' ' . $separator . ' ' . $site : ( ! empty( $raw ) ? $raw : $site );
		return $this->limit( preg_replace( '/\s+/', ' ', trim( $value ) ), 180 );
	}

	private function legacy_description( $post ) {
		$text = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		if ( '' === $text ) {
			$text = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 30, '' ); }
		return $this->limit( preg_replace( '/\s+/', ' ', trim( $text ) ), 160 );
	}

	private function limit( $value, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, 0, $length ) : substr( (string) $value, 0, $length );
	}
}
