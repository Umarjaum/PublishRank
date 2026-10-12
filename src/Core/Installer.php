<?php
/** Activation defaults and version upgrades. */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class Installer {
	const VERSION_OPTION = 'publishrank_seo_version';

	/** Options that are only read on rare requests and should not be autoloaded. */
	private static function no_autoload() {
		return array( 'publishrank_seo_redirects', 'publishrank_seo_404_log', 'publishrank_robots_custom', 'publishrank_seo_last_invalidation', 'publishrank_indexnow_last_status', 'publishrank_indexnow_log', 'publishrank_internal_links_scan_id', 'publishrank_seo_config_snapshots' );
	}

	/** @return array<string,mixed> */
	public static function defaults() {
			return array(
				'publishrank_sitemap_endpoint'          => 'publishrank-sitemap',
				'publishrank_sitemap_aliases'           => '',
					'publishrank_seo_cache_epoch'            => time(),
				'publishrank_seo_last_invalidation'      => '',
			'publishrank_indexnow_enabled'           => 0,
			'publishrank_indexnow_key'               => wp_generate_password( 32, false, false ),
			'publishrank_indexnow_post_types'        => 'post',
			'publishrank_indexnow_key_location'      => home_url( '/publishrank-indexnow-key.txt' ),
			'publishrank_seo_news_enabled'           => 1,
			'publishrank_seo_news_window'            => 48,
			'publishrank_seo_news_post_types'        => 'post',
			'publishrank_seo_news_max_urls'          => 1000,
			'publishrank_seo_news_exclude_ids'       => '',
			'publishrank_seo_news_exclude_categories' => '',
			'publishrank_seo_news_keywords'          => 1,
			'publishrank_seo_news_genre'             => '',
			'publishrank_editorial_genre'            => '',
					'publishrank_seo_title_separator'        => '|',
					'publishrank_meta_enabled'               => 1,
					'publishrank_schema_enabled'             => 1,
					'publishrank_twitter_card'                => 'summary_large_image',
					'publishrank_facebook_app_id'             => '',
					'publishrank_home_title'                  => '',
					'publishrank_home_description'            => '',
					'publishrank_noindex_author_archives'     => 0,
					'publishrank_noindex_date_archives'       => 0,
				'publishrank_seo_default_robots'         => 'index,follow',
			'publishrank_seo_title_template_home'    => '%%sitename%% %%page%%',
			'publishrank_seo_title_template_post'    => '%%title%% %%sep%% %%sitename%% %%page%%',
			'publishrank_seo_title_template_page'    => '%%title%% %%sep%% %%sitename%%',
			'publishrank_seo_title_template_archive' => '%%title%% %%sep%% %%sitename%%',
			'publishrank_seo_image_sitemap'          => 1,
			'publishrank_image_license_url'          => '',
			'publishrank_seo_video_sitemap'          => 1,
			'publishrank_seo_media_post_types'       => 'all',
			'publishrank_source_attribution'         => 0,
			'publishrank_public_attribution'         => 0,
			'publishrank_sitemap_post_types'         => 'post',
			'publishrank_sitemap_pages_enabled'      => 1,
			'publishrank_sitemap_cpt_enabled'        => 1,
			'publishrank_sitemap_taxonomies_enabled' => 1,
			'publishrank_sitemap_authors_enabled'    => 1,
			'publishrank_sitemap_attachments_enabled' => 0,
			'publishrank_sitemap_stylesheet'         => 1,
			'publishrank_breadcrumbs_enabled'        => 1,
			'publishrank_breadcrumbs_separator'      => '→',
			'publishrank_breadcrumb_error_mode'       => 'skip',
			'publishrank_breadcrumb_error_key'        => 'breadcrumb_error',
			'publishrank_breadcrumb_fallback_label'  => 'Home',
			'publishrank_breadcrumb_fallback_url'    => home_url( '/' ),
			'publishrank_breadcrumb_log_errors'     => 0,
			'publishrank_seo_publication_name'       => get_bloginfo( 'name' ),
			'publishrank_seo_publisher_name'         => get_bloginfo( 'name' ),
			'publishrank_seo_publisher_logo'         => '',
				'publishrank_seo_publisher_type'         => 'NewsMediaOrganization',
				'publishrank_schema_org_description'      => '',
				'publishrank_schema_legal_name'           => '',
				'publishrank_schema_founder'              => '',
				'publishrank_schema_founding_date'        => '',
				'publishrank_schema_phone'                => '',
				'publishrank_schema_street_address'       => '',
				'publishrank_schema_address_locality'     => '',
				'publishrank_schema_address_region'       => '',
				'publishrank_schema_postal_code'           => '',
				'publishrank_schema_knows_about'            => '',
				'publishrank_schema_parent_organization'   => '',
				'publishrank_schema_founding_location'     => '',
			'publishrank_seo_default_social_image'   => '',
			'publishrank_seo_twitter_site'           => '',
			'publishrank_primary_url'                => home_url( '/' ),
			'publishrank_media_host'                 => '',
			'publishrank_cdn_enabled'                => 0,
			'publishrank_cdn_host'                   => '',
			'publishrank_video_host'                 => '',
			'publishrank_api_host'                   => '',
			'publishrank_publication_short_name'     => '',
			'publishrank_default_language'           => substr( (string) get_locale(), 0, 2 ),
			'publishrank_seo_multilingual_enabled'   => 1,
			'publishrank_seo_hreflang_map'           => '',
			'publishrank_seo_x_default_url'          => '',
			'publishrank_default_locale'             => get_locale(),
			'publishrank_timezone'                   => wp_timezone_string(),
			'publishrank_country'                    => '',
			'publishrank_copyright'                  => '',
			'publishrank_contact'                    => '',
			'publishrank_social_profiles'            => '',
			'publishrank_author_mode'                => 'wordpress',
			'publishrank_robots_enabled'             => 1,
			'publishrank_robots_sitemaps'            => 1,
			'publishrank_robots_block_ai'            => 0,
			'publishrank_robots_custom'              => '',
			'publishrank_internal_link_enabled'      => 1,
			'publishrank_internal_link_min_inbound'  => 1,
			'publishrank_internal_link_max_recommendations' => 5,
			'publishrank_internal_link_post_types'   => '',
			'publishrank_internal_links_scan_id'     => time(),
			'publishrank_redirect_enabled'           => 1,
			'publishrank_redirect_only_404'          => 1,
			'publishrank_redirect_preserve_query'    => 1,
			'publishrank_redirect_x_header'          => 1,
			'publishrank_redirect_max_hops'          => 10,
			'publishrank_seo_config_snapshots'      => array(),
			'publishrank_llms_txt_enabled'        => 1,
			'publishrank_llms_txt_title'          => get_bloginfo( 'name' ),
			'publishrank_llms_txt_summary'        => get_bloginfo( 'description' ),
			'publishrank_llms_txt_intro'          => '',
			'publishrank_llms_txt_home'           => 1,
			'publishrank_llms_txt_posts'          => 1,
			'publishrank_llms_txt_pages'          => 1,
			'publishrank_llms_txt_categories'     => 1,
			'publishrank_llms_txt_post_count'     => 20,
			'publishrank_llms_txt_custom'         => '',
			'publishrank_llms_full_enabled'       => 0,
			'publishrank_llms_full_words'         => 8000,
		);
	}

	/** Add any missing option without overwriting what the site owner configured. */
	public static function install_defaults() {
		$no_autoload = self::no_autoload();
		foreach ( self::defaults() as $key => $value ) {
			add_option( $key, $value, '', in_array( $key, $no_autoload, true ) ? 'no' : 'yes' );
		}
	}

	/** Activation: defaults plus every rewrite rule the plugin serves. */
	public static function activate() {
		add_option( 'publishrank_article_intel_enabled', 1, '', false );
		self::install_defaults();
		\PublishRankSEO\InternalLinks\Store::install();
		update_option( 'publishrank_seo_cache_epoch', time(), false );
		update_option( self::VERSION_OPTION, PUBLISHRANK_SEO_VERSION, true );
		( new \PublishRankSEO\Sitemap\SitemapController() )->rewrite();
		( new \PublishRankSEO\Sitemap\SitemapStylesheet() )->rewrite();
		( new \PublishRankSEO\Indexing\IndexNow() )->rewrite();
		flush_rewrite_rules();
	}

	/** Runs once after the plugin files are updated (admin requests only). */
	public static function maybe_upgrade() {
		if ( ! is_admin() || PUBLISHRANK_SEO_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}
		self::install_defaults();
		\PublishRankSEO\InternalLinks\Store::install();
		( new \PublishRankSEO\Indexing\IndexNow() )->rewrite();
		// Shard boundaries changed in 2.9.20; bump versions so old cursor checkpoints and cached XML cannot be reused.
		if ( version_compare( (string) get_option( self::VERSION_OPTION, '0' ), '2.9.20', '<' ) ) {
			\PublishRankSEO\Sitemap\Engine\SitemapCursorStore::bump_many( array( 'post', 'page', 'cpt', 'taxonomy', 'author', 'attachment', 'news', 'image', 'video' ) );
		}
		update_option( 'publishrank_seo_cache_epoch', time(), false );
		update_option( self::VERSION_OPTION, PUBLISHRANK_SEO_VERSION, true );
		flush_rewrite_rules( false );
	}
}
