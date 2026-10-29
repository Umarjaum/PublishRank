<?php
/** Secure, bounded SEO configuration snapshots. */
namespace PublishRankSEO\ChangeControl;

defined( 'ABSPATH' ) || exit;

final class SnapshotStore {
	const OPTION = 'publishrank_seo_config_snapshots';
	const MAX    = 20;

	public static function tracked_options() {
		return array(
			'publishrank_meta_enabled',
			'publishrank_schema_enabled',
			'publishrank_seo_title_separator',
			'publishrank_seo_default_robots',
			'publishrank_seo_default_social_image',
			'publishrank_seo_twitter_site',
			'publishrank_primary_url',
			'publishrank_media_host',
			'publishrank_cdn_enabled',
			'publishrank_cdn_host',
			'publishrank_video_host',
			'publishrank_api_host',
			'publishrank_publication_short_name',
			'publishrank_default_language',
			'publishrank_default_locale',
			'publishrank_timezone',
			'publishrank_country',
			'publishrank_copyright',
			'publishrank_contact',
			'publishrank_social_profiles',
			'publishrank_author_mode',
			'publishrank_robots_enabled',
			'publishrank_robots_sitemaps',
			'publishrank_robots_block_ai',
			'publishrank_robots_custom',
			'publishrank_sitemap_endpoint',
			'publishrank_sitemap_aliases',
			'publishrank_seo_news_enabled',
			'publishrank_seo_news_window',
			'publishrank_seo_news_post_types',
			'publishrank_seo_news_max_urls',
			'publishrank_seo_news_exclude_ids',
			'publishrank_seo_news_exclude_categories',
			'publishrank_seo_news_keywords',
			'publishrank_seo_news_genre',
			'publishrank_seo_image_sitemap',
			'publishrank_seo_video_sitemap',
			'publishrank_indexnow_enabled',
			'publishrank_indexnow_key',
			'publishrank_indexnow_key_location',
			'publishrank_indexnow_post_types',
			'publishrank_seo_multilingual_enabled',
			'publishrank_seo_hreflang_map',
			'publishrank_seo_hreflang_mirror',
			'publishrank_seo_x_default_url',
			'publishrank_sitemap_stylesheet',
			'publishrank_breadcrumb_style',
			'publishrank_breadcrumb_size',
			'publishrank_breadcrumb_text_color',
			'publishrank_breadcrumb_link_color',
			'publishrank_breadcrumb_bg_color',
			'publishrank_breadcrumb_placement',
			'publishrank_breadcrumb_hook',
			'publishrank_breadcrumb_home_label',
			'publishrank_breadcrumb_error_mode',
			'publishrank_breadcrumb_error_key',
			'publishrank_breadcrumb_fallback_label',
			'publishrank_breadcrumb_fallback_url',
			'publishrank_breadcrumb_log_errors',
			'publishrank_internal_link_enabled',
			'publishrank_internal_link_min_inbound',
			'publishrank_internal_link_max_recommendations',
			'publishrank_internal_link_post_types',
			'publishrank_redirect_enabled',
			'publishrank_redirect_only_404',
			'publishrank_redirect_preserve_query',
			'publishrank_redirect_x_header',
			'publishrank_redirect_max_hops',
			'publishrank_seo_redirects',
			'publishrank_traffic_guard',
		);
	}

	public static function all() {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function snapshot( $label = '', $actor = '' ) {
		$data = array();
		foreach ( self::tracked_options() as $key ) {
			$value = get_option( $key, null );
			if ( null !== $value ) {
				$data[ $key ] = self::safe_value( $value ); }
		}
		$event = array(
			'id'    => wp_generate_uuid4(),
			'time'  => gmdate( 'c' ),
			'label' => sanitize_text_field( ! empty( $label ) ? $label : __( 'SEO configuration snapshot', 'publishrank' ) ),
			'actor' => sanitize_text_field( ! empty( $actor ) ? $actor : ( is_user_logged_in() ? wp_get_current_user()->user_login : 'system' ) ),
			'data'  => $data,
		);
		$all   = self::all();
		array_unshift( $all, $event );
		update_option( self::OPTION, array_slice( $all, 0, self::MAX ), false );
		return $event;
	}

	public static function get( $id ) {
		foreach ( self::all() as $event ) {
			if ( isset( $event['id'] ) && hash_equals( (string) $event['id'], (string) $id ) ) {
				return $event; }
		}
		return null;
	}

	public static function restore( $id ) {
		$event = self::get( $id );
		if ( ! $event || empty( $event['data'] ) || ! is_array( $event['data'] ) ) {
			return new \WP_Error( 'publishrank_snapshot_missing', __( 'Snapshot not found.', 'publishrank' ) ); }
		self::snapshot( __( 'Pre-restore safety snapshot', 'publishrank' ) );
		foreach ( $event['data'] as $key => $value ) {
			if ( in_array( $key, self::tracked_options(), true ) ) {
				update_option( $key, $value, false ); }
		}
		return true;
	}

	private static function safe_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return $value; }
		return is_array( $value ) ? array_slice( $value, 0, 500, true ) : (string) $value;
	}
}
