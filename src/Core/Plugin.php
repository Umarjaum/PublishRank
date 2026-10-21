<?php
namespace PublishRankSEO\Core;

use PublishRankSEO\Meta\HeadOutput;
use PublishRankSEO\Meta\FrontendOutputRecovery;
use PublishRankSEO\Meta\LanguageAlternates;
use PublishRankSEO\Schema\GraphOutput;
use PublishRankSEO\Schema\SchemaSuite;
use PublishRankSEO\Admin\SchemaPage;
use PublishRankSEO\Sitemap\SitemapController;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;
use PublishRankSEO\Redirect\RedirectController;
use PublishRankSEO\Admin\SettingsPage;
use PublishRankSEO\Admin\OnboardingPage;
use PublishRankSEO\Admin\DiagnosticsPage;
use PublishRankSEO\Admin\ImportExport;
use PublishRankSEO\Admin\DashboardPage;
use PublishRankSEO\Compatibility\JNewsAdapter;
use PublishRankSEO\Monitor\NotFoundMonitor;
use PublishRankSEO\SEO\AdvancedFeatures;
use PublishRankSEO\SEO\AutomaticArticleSEO;
use PublishRankSEO\SEO\YoastParityFeatures;
use PublishRankSEO\SEO\SiteConnections;
use PublishRankSEO\SEO\LocalSEO;
use PublishRankSEO\Indexing\IndexNow;
use PublishRankSEO\Indexing\BaiduSubmission;
use PublishRankSEO\SEO\BreadcrumbEngine;
use PublishRankSEO\Security\TraceIntegrity;
use PublishRankSEO\Security\IntegrityManager;
use PublishRankSEO\Security\AdminAccess;
use PublishRankSEO\Sitemap\SitemapStylesheet;
use PublishRankSEO\Robots\RobotsTxt;
use PublishRankSEO\Admin\AdminAssets;
use PublishRankSEO\Admin\RedirectsPage;
use PublishRankSEO\Admin\NotFoundPage;
use PublishRankSEO\Admin\RobotsPage;
use PublishRankSEO\Admin\PostListColumns;
use PublishRankSEO\Admin\SitemapHealthPage;
use PublishRankSEO\InternalLinks\InternalLinksModule;
use PublishRankSEO\InternalLinks\PublisherLinkIntelligence;
use PublishRankSEO\Admin\InsightsPage;
use PublishRankSEO\Security\SecurityHeaders;
use PublishRankSEO\Security\TrafficGuard;
use PublishRankSEO\SEO\ReadingExperience;
use PublishRankSEO\SEO\ArticleIntelligence;
use PublishRankSEO\Links\LinkManager;
use PublishRankSEO\Performance\ResourceHints;
use PublishRankSEO\API\RestFields;
use PublishRankSEO\Monitor\ScheduledAudit;
use PublishRankSEO\ChangeControl\ConfigHistory;
use PublishRankSEO\Admin\ConfigHistoryPage;
use PublishRankSEO\AI\LLMSTxt;
use PublishRankSEO\Admin\LLMSTxtPage;
use PublishRankSEO\Admin\ImageOptimizerPage;
use PublishRankSEO\Admin\EditorScoreCard;
use PublishRankSEO\Admin\IndexNowPage;
use PublishRankSEO\Admin\PublisherToolkitPage;
use PublishRankSEO\Admin\HelpDocumentationPage;
use PublishRankSEO\Admin\SEOScorePage;
use PublishRankSEO\Admin\ExpertisePage;
use PublishRankSEO\Admin\AnalyticsPage;
use PublishRankSEO\Admin\SearchConsolePage;
use PublishRankSEO\Admin\AuthPage;
use PublishRankSEO\Admin\BaiduSubmissionPage;
use PublishRankSEO\Admin\SiteVerificationPage;
use PublishRankSEO\AnalyticsCollector;
use PublishRankSEO\Google\LiveSync;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private $registry;
	private $loader;
	public function registry() { return $this->registry; }
	public function boot() {
		if ( did_action( 'publishrank_seo_loaded' ) ) {
			return;
		}
		// Note: the built-in 'post' post type is not registered until 'init', so
		// checking post_type_exists() here (on plugins_loaded) would abort the whole
		// boot and no SEO output would ever be injected. Output is already scoped
		// per-request via is_singular( 'post' ) inside the modules themselves.

		$this->loader = new ModuleLoader();
		$this->loader->register( IntegrityManager::class, false );
		$this->loader->register( AdminAccess::class, false );
		$this->loader->register( FrontendBranding::class, false );
		$this->registry = new OutputRegistry();
		$this->loader->register( HeadOutput::class, true, array( $this->registry ) );
		$this->loader->register( FrontendOutputRecovery::class, true, array( $this->registry ) );
		$this->loader->register( LanguageAlternates::class, false );
		$this->loader->register( GraphOutput::class, true, array( $this->registry ) );
		$this->loader->register( SchemaSuite::class, false );
		$this->loader->register( SitemapController::class, true );
		$this->loader->register( SitemapStylesheet::class, false );
			$this->loader->register( RobotsTxt::class, false );
			$this->loader->register( SiteConnections::class, false );
		$this->loader->register( LocalSEO::class, false );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 99 );
				$this->loader->register( IndexNow::class, false );
			$this->loader->register( BaiduSubmission::class, false );
		$this->loader->register( AnalyticsCollector::class, false );
		$this->loader->register( LiveSync::class, false );
		$this->loader->register( \PublishRankSEO\Admin\LivePreview::class, false );
		$this->loader->register( RedirectController::class, false );
		$this->loader->register( NotFoundMonitor::class, false );
			$this->loader->register( AdvancedFeatures::class, false );
			$this->loader->register( AutomaticArticleSEO::class, false );
		$this->loader->register( YoastParityFeatures::class, false );
		$this->loader->register( InternalLinksModule::class, false );
		$this->loader->register( PublisherLinkIntelligence::class, false );
		$this->loader->register( ArticleIntelligence::class, false );
				$this->loader->register( BreadcrumbEngine::class, false );
				$this->loader->register( TraceIntegrity::class, false );
			$this->loader->register( SecurityHeaders::class, false );
			$this->loader->register( TrafficGuard::class, false );
			$this->loader->register( ReadingExperience::class, false );
			$this->loader->register( LinkManager::class, false );
			$this->loader->register( ResourceHints::class, false );
		$this->loader->register( RestFields::class, false );
		$this->loader->register( ScheduledAudit::class, false );
		$this->loader->register( ConfigHistory::class, false );
		$this->loader->register( LLMSTxt::class, false );
		$this->loader->register( \PublishRankSEO\Schema\LiveBlogSchema::class, false );
		$this->register_cache_invalidation();
		if ( is_admin() ) {
			$this->loader->register( DashboardPage::class, false, array( $this->registry, new JNewsAdapter() ) );
			$this->loader->register( SettingsPage::class, false );
			$this->loader->register( SiteVerificationPage::class, false );
			$this->loader->register( OnboardingPage::class, false );
			$this->loader->register( DiagnosticsPage::class, false, array( $this->registry, new JNewsAdapter() ) );
			$this->loader->register( ImportExport::class, false );
			$this->loader->register( AdminAssets::class, false );
			$this->loader->register( RedirectsPage::class, false );
			$this->loader->register( NotFoundPage::class, false );
			$this->loader->register( RobotsPage::class, false );
				$this->loader->register( PostListColumns::class, false );
				$this->loader->register( SitemapHealthPage::class, false );
				$this->loader->register( InsightsPage::class, false );
				$this->loader->register( AnalyticsPage::class, false );
				$this->loader->register( SearchConsolePage::class, false );
				$this->loader->register( AuthPage::class, false );
				$this->loader->register( BaiduSubmissionPage::class, false );
			$this->loader->register( ConfigHistoryPage::class, false );
				$this->loader->register( LLMSTxtPage::class, false );
				$this->loader->register( ImageOptimizerPage::class, false );
				$this->loader->register( EditorScoreCard::class, false );
			$this->loader->register( IndexNowPage::class, false );
			$this->loader->register( PublisherToolkitPage::class, false );
			$this->loader->register( HelpDocumentationPage::class, false );
				$this->loader->register( SchemaPage::class, false );
				$this->loader->register( SEOScorePage::class, false );
				$this->loader->register( ExpertisePage::class, false );
				$this->loader->register( \PublishRankSEO\Admin\TrafficGuardPage::class, false );
				$this->loader->register( \PublishRankSEO\Schema\TermSchema::class, false );
		}

		do_action( 'publishrank_seo_loaded', $this );
	}

	/** Invalidate derived sitemap payloads when content or settings change. */
		private function register_cache_invalidation() {
$invalidate = static function () { SitemapCursorStore::bump_many( array( 'post', 'page', 'cpt', 'taxonomy', 'author', 'attachment', 'news', 'image', 'video' ) ); update_option( 'publishrank_seo_last_invalidation', gmdate( 'c' ), false ); };
			$invalidate_news = static function () { SitemapCursorStore::bump( 'news' ); };
			$invalidate_image = static function () { SitemapCursorStore::bump( 'image' ); };
			$invalidate_video = static function () { SitemapCursorStore::bump( 'video' ); };
$invalidate_post = static function ( $post_id = 0 ) {
$type = $post_id ? get_post_type( $post_id ) : '';
$providers = array( 'author', 'news', 'image', 'video' );
if ( 'attachment' === $type ) { $providers[] = 'attachment'; } elseif ( 'page' === $type ) { $providers[] = 'page'; } elseif ( $type && 'post' !== $type ) { $providers[] = 'cpt'; } else { $providers[] = 'post'; }
SitemapCursorStore::bump_many( $providers );
};
$invalidate_term = static function () { SitemapCursorStore::bump( 'taxonomy' ); };
add_action( 'save_post', $invalidate_post, 20, 1 );
add_action( 'deleted_post', $invalidate_post, 20, 1 );
// Publish, unpublish, trash, restore and scheduled-publish transitions do not all fire save_post.
add_action( 'transition_post_status', static function ( $new, $old, $post ) use ( $invalidate_post ) { if ( $new !== $old && $post instanceof \WP_Post ) { $invalidate_post( (int) $post->ID ); } }, 20, 3 );
// Editor-controlled eligibility/robots/title metadata changes must refresh the News sitemap immediately.
$news_meta_keys = array( '_publishrank_seo_news_eligible', '_publishrank_seo_robots', '_publishrank_seo_title', '_publishrank_seo_canonical', '_yoast_wpseo_meta-robots-noindex', 'rank_math_robots' );
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meta_hook ) {
	add_action( $meta_hook, static function ( $meta_id, $object_id, $meta_key ) use ( $news_meta_keys ) { if ( in_array( $meta_key, $news_meta_keys, true ) ) { SitemapCursorStore::bump( 'news' ); } }, 20, 3 );
}
add_action( 'clean_post_cache', $invalidate_post, 20, 1 );
add_action( 'add_attachment', $invalidate_post, 20, 1 );
add_action( 'edit_attachment', $invalidate_post, 20, 1 );
add_action( 'delete_attachment', $invalidate_post, 20, 1 );
add_action( 'created_term', $invalidate_term, 20 );
add_action( 'edited_term', $invalidate_term, 20 );
add_action( 'delete_term', $invalidate_term, 20 );
add_action( 'set_object_terms', $invalidate_term, 20 );
		$touch_term = static function ( $term_id ) { if ( function_exists( 'update_term_meta' ) && $term_id ) { update_term_meta( (int) $term_id, '_publishrank_last_modified', gmdate( 'c' ) ); } };
		add_action( 'created_term', $touch_term, 20, 1 );
		add_action( 'edited_term', $touch_term, 20, 1 );
		$refresh_sitemap_routes = static function () { flush_rewrite_rules( false ); };
		add_action( 'update_option_publishrank_sitemap_endpoint', $refresh_sitemap_routes, 20 );
		add_action( 'update_option_publishrank_sitemap_aliases', $refresh_sitemap_routes, 20 );
		foreach ( array( 'publishrank_seo_news_enabled', 'publishrank_seo_news_window', 'publishrank_seo_news_post_types', 'publishrank_seo_news_max_urls', 'publishrank_seo_news_exclude_ids', 'publishrank_seo_news_exclude_categories', 'publishrank_seo_news_keywords', 'publishrank_seo_news_genre' ) as $setting ) { add_action( 'update_option_' . $setting, $invalidate_news, 20 ); }
			add_action( 'update_option_publishrank_seo_image_sitemap', $invalidate_image, 20 );
			add_action( 'update_option_publishrank_seo_media_post_types', $invalidate, 20 );
			add_action( 'update_option_publishrank_image_license_url', $invalidate_image, 20 );
		add_action( 'update_option_publishrank_seo_video_sitemap', $invalidate_video, 20 );
		add_action( 'update_option_publishrank_seo_publisher_name', $invalidate, 20 );
		add_action( 'update_option_publishrank_seo_publisher_logo', $invalidate, 20 );
		add_action( 'update_option_publishrank_seo_publisher_type', $invalidate, 20 );
			foreach ( array( 'publishrank_primary_url', 'publishrank_media_host', 'publishrank_cdn_enabled', 'publishrank_cdn_host', 'publishrank_video_host', 'publishrank_api_host', 'publishrank_publication_short_name', 'publishrank_default_language', 'publishrank_indexnow_enabled', 'publishrank_indexnow_key', 'publishrank_indexnow_key_location', 'publishrank_indexnow_post_types', 'publishrank_seo_multilingual_enabled', 'publishrank_seo_hreflang_map', 'publishrank_seo_x_default_url', 'publishrank_default_locale', 'publishrank_timezone', 'publishrank_country', 'publishrank_copyright', 'publishrank_contact', 'publishrank_social_profiles', 'publishrank_author_mode', 'publishrank_seo_title_separator', 'publishrank_seo_default_robots', 'publishrank_seo_default_social_image', 'publishrank_seo_twitter_site', 'publishrank_sitemap_stylesheet', 'publishrank_robots_enabled', 'publishrank_robots_sitemaps', 'publishrank_robots_block_ai', 'publishrank_robots_custom', 'publishrank_seo_title_template_home', 'publishrank_seo_title_template_post', 'publishrank_seo_title_template_page', 'publishrank_seo_title_template_archive' ) as $setting ) { add_action( 'update_option_' . $setting, $invalidate, 20 ); }
	}
}
