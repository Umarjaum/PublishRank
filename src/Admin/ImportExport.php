<?php
/**
 * Safe settings import and export.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

defined( 'ABSPATH' ) || exit;

final class ImportExport {
	/** @var string[] */
		private $allowed = array(
			'publishrank_indexnow_enabled',
			'publishrank_indexnow_key_location',
			'publishrank_indexnow_post_types',
			'publishrank_seo_publication_name',
			'publishrank_seo_publisher_name',
			'publishrank_seo_publisher_type',
			'publishrank_seo_publisher_logo',
			'publishrank_publication_short_name',
			'publishrank_primary_url',
			'publishrank_media_host',
			'publishrank_cdn_enabled',
			'publishrank_cdn_host',
			'publishrank_video_host',
			'publishrank_api_host',
			'publishrank_default_language',
			'publishrank_default_locale',
			'publishrank_timezone',
			'publishrank_country',
			'publishrank_copyright',
			'publishrank_contact',
			'publishrank_social_profiles',
			'publishrank_seo_title_separator',
			'publishrank_seo_default_robots',
			'publishrank_seo_title_template_home',
			'publishrank_seo_title_template_post',
			'publishrank_seo_title_template_page',
			'publishrank_seo_title_template_archive',
			'publishrank_sitemap_post_types',
			'publishrank_sitemap_pages_enabled',
			'publishrank_sitemap_cpt_enabled',
			'publishrank_sitemap_taxonomies_enabled',
			'publishrank_sitemap_authors_enabled',
			'publishrank_sitemap_attachments_enabled',
			'publishrank_seo_image_sitemap',
			'publishrank_seo_video_sitemap',
			'publishrank_image_license_url',
			'publishrank_breadcrumbs_enabled',
			'publishrank_breadcrumbs_separator',
			'publishrank_error_mode',
			'publishrank_breadcrumb_error_mode',
			'publishrank_breadcrumb_error_key',
			'publishrank_breadcrumb_fallback_label',
			'publishrank_breadcrumb_fallback_url',
			'publishrank_breadcrumb_log_errors',
			'publishrank_seo_multilingual_enabled',
			'publishrank_seo_hreflang_map',
			'publishrank_seo_hreflang_mirror',
			'publishrank_seo_x_default_url',
			'publishrank_seo_news_enabled',
			'publishrank_seo_news_window',
			'publishrank_seo_news_post_types',
			'publishrank_seo_news_max_urls',
			'publishrank_seo_news_exclude_ids',
			'publishrank_seo_news_exclude_categories',
			'publishrank_seo_news_keywords',
			'publishrank_seo_news_genre',
			'publishrank_editorial_genre',
		'publishrank_seo_default_social_image',
		'publishrank_seo_twitter_site',
		'publishrank_sitemap_stylesheet',
		'publishrank_robots_enabled',
		'publishrank_robots_sitemaps',
		'publishrank_robots_block_ai',
		'publishrank_robots_custom',
		'publishrank_seo_redirects',
		'publishrank_seo_404_monitor',
		'publishrank_seo_404_log',
	);

	/** Register admin actions. */
	public function register() {
		add_action( 'admin_post_publishrank_seo_export', array( $this, 'export' ) );
		add_action( 'admin_post_publishrank_seo_import', array( $this, 'import' ) );
	}

	/** Export only PublishRank-owned options. */
	public function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export PublishRank settings.', 'publishrank' ), 403 );
		}
		check_admin_referer( 'publishrank_seo_export' );
		$data = array();
		foreach ( $this->allowed as $key ) {
			$value = get_option( $key, null );
			if ( null !== $value ) {
				$data[ $key ] = $value;
			}
		}
			$data['_schema'] = 'publishrank/v2';
		header( 'Content-Type: application/json; charset=' . get_bloginfo( 'charset' ) );
		header( 'Content-Disposition: attachment; filename=publishrank-seo-settings.json' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT );
		exit;
	}

	/** Import a schema-validated JSON payload. */
	public function import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to import PublishRank settings.', 'publishrank' ), 403 );
		}
		check_admin_referer( 'publishrank_seo_import' );
		$raw = isset( $_POST['publishrank_seo_json'] ) ? wp_unslash( $_POST['publishrank_seo_json'] ) : '';
		$data = json_decode( $raw, true );
			if ( ! is_array( $data ) || ! in_array( ( $data['_schema'] ?? '' ), array( 'publishrank/v1', 'publishrank/v2' ), true ) ) {
			wp_die( esc_html__( 'The import file is invalid.', 'publishrank' ), 400 );
		}
		foreach ( $data as $key => $value ) {
			if ( '_schema' === $key || ! in_array( $key, $this->allowed, true ) ) {
				continue;
			}
			update_option( $key, $this->sanitize( $key, $value ), false );
		}
		update_option( 'publishrank_seo_cache_epoch', time(), false );
		wp_safe_redirect( add_query_arg( 'publishrank_seo_imported', '1', admin_url( 'admin.php?page=publishrank-seo' ) ) );
		exit;
	}

	/** @param string $key @param mixed $value @return mixed */
	private function sanitize( $key, $value ) {
		if ( 'publishrank_indexnow_enabled' === $key ) {
				return (bool) $value;
			}
			if ( 'publishrank_indexnow_key_location' === $key ) {
				return esc_url_raw( (string) $value );
			}
			if ( 'publishrank_seo_x_default_url' === $key ) {
				return esc_url_raw( (string) $value );
			}
			if ( 'publishrank_seo_multilingual_enabled' === $key || 'publishrank_seo_news_enabled' === $key || 'publishrank_seo_news_keywords' === $key ) {
				return (bool) $value;
			}
			if ( 'publishrank_seo_news_max_urls' === $key ) {
				return max( 1, min( 1000, absint( $value ) ) );
			}
			if ( 'publishrank_seo_news_window' === $key ) {
				return max( 1, min( 168, absint( $value ) ) );
		}
		if ( in_array( $key, array( 'publishrank_seo_404_monitor', 'publishrank_sitemap_stylesheet', 'publishrank_robots_enabled', 'publishrank_robots_sitemaps', 'publishrank_robots_block_ai' ), true ) ) {
			return (bool) $value;
		}
		if ( 'publishrank_seo_default_social_image' === $key ) {
			return \PublishRankSEO\Core\PublicationProfile::normalize_url( (string) $value );
		}
		if ( 'publishrank_seo_twitter_site' === $key ) {
			return substr( preg_replace( '/[^A-Za-z0-9_]/', '', ltrim( trim( (string) $value ), '@' ) ), 0, 15 );
		}
		if ( 'publishrank_robots_custom' === $key ) {
			return \PublishRankSEO\Robots\RobotsTxt::clean_rules( (string) $value );
		}
		if ( 'publishrank_seo_redirects' === $key ) {
			if ( ! is_array( $value ) ) {
				return array();
			}
			$redirects = array();
			foreach ( $value as $redirect ) {
				if ( ! is_array( $redirect ) ) {
					continue;
				}
				$rule = \PublishRankSEO\Redirect\RedirectStore::build_rule(
					isset( $redirect['source'] ) ? (string) $redirect['source'] : '',
					isset( $redirect['target'] ) ? (string) $redirect['target'] : '',
					isset( $redirect['status'] ) ? absint( $redirect['status'] ) : 301,
					! empty( $redirect['regex'] )
				);
				if ( ! is_wp_error( $rule ) ) {
					$redirects[] = $rule;
				}
			}
			return array_slice( $redirects, 0, \PublishRankSEO\Redirect\RedirectStore::MAX_RULES );
		}
		if ( 'publishrank_seo_404_log' === $key ) {
			return is_array( $value ) ? array_slice( array_map( static function ( $entry ) {
				return is_array( $entry ) ? array(
					'path' => sanitize_text_field( (string) ( $entry['path'] ?? '/' ) ),
					'time' => sanitize_text_field( (string) ( $entry['time'] ?? '' ) ),
				) : array();
			}, $value ), -500 ) : array();
		}
		return sanitize_text_field( (string) $value );
	}
}
/* EOF */
