<?php
namespace PublishRankSEO\Core;

use PublishRankSEO\Meta\HeadOutput;
use PublishRankSEO\Meta\FrontendOutputRecovery;
use PublishRankSEO\Meta\LanguageAlternates;
use PublishRankSEO\Schema\GraphOutput;
use PublishRankSEO\Schema\SchemaIndexEndpoint;
use PublishRankSEO\Schema\SchemaAggregator;
use PublishRankSEO\Schema\SchemaSuite;
use PublishRankSEO\Admin\SchemaPage;
use PublishRankSEO\Sitemap\SitemapController;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;
use PublishRankSEO\Redirect\RedirectController;
use PublishRankSEO\Admin\SettingsPage;
use PublishRankSEO\Admin\OnboardingPage;
use PublishRankSEO\Admin\DiagnosticsPage;
use PublishRankSEO\Admin\ImportExport;
use PublishRankSEO\Admin\SeoPortability;
use PublishRankSEO\Admin\DashboardPage;
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
use PublishRankSEO\Core\FrontendVisibility;
use PublishRankSEO\Core\IdentityOverride;
use PublishRankSEO\Security\IntegrityManager;
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
use PublishRankSEO\Admin\IndexNowPage;
use PublishRankSEO\Admin\PublisherToolkitPage;
use PublishRankSEO\Admin\HelpDocumentationPage;
use PublishRankSEO\Admin\SEOScorePage;
use PublishRankSEO\Admin\ExpertisePage;
use PublishRankSEO\Admin\AnalyticsPage;
use PublishRankSEO\Admin\SearchConsolePage;
use PublishRankSEO\Admin\BaiduSubmissionPage;
use PublishRankSEO\Admin\PreferredSourcesPage;
use PublishRankSEO\Admin\SiteVerificationPage;
use PublishRankSEO\AnalyticsCollector;
use PublishRankSEO\Google\LiveSync;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private $registry;
	private $loader;
	public function registry() {
		return $this->registry; }
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
			$this->loader->register( FrontendBranding::class, false );
			$this->loader->register( FrontendVisibility::class, false );
			$this->loader->register( IdentityOverride::class, false );
			$this->loader->register( \PublishRankSEO\Core\LogoSync::class, false );
		$this->registry = new OutputRegistry();
		// Lets the automated tests (and Live Preview style tools) render several requests in one PHP process.
		add_action( 'publishrank_seo_reset_output_registry', array( $this->registry, 'reset' ) );
		$this->loader->register( HeadOutput::class, true, array( $this->registry ) );
		$this->loader->register( FrontendOutputRecovery::class, true, array( $this->registry ) );
		$this->loader->register( LanguageAlternates::class, false );
		$this->loader->register( \PublishRankSEO\Meta\MultilingualStrings::class, false );
		$this->loader->register( \PublishRankSEO\Meta\LocalizedSeoMeta::class, false );
		$this->loader->register( GraphOutput::class, true, array( $this->registry ) );
		$this->loader->register( SchemaIndexEndpoint::class, false );
		$this->loader->register( SchemaAggregator::class, false );
		$this->loader->register( SchemaSuite::class, false );
		$this->loader->register( \PublishRankSEO\Schema\FAQModule::class, false );
		$this->loader->register( \PublishRankSEO\Newsroom\SourceDirectory::class, false );
		$this->loader->register( SitemapController::class, true );
		$this->loader->register( SitemapStylesheet::class, false );
			$this->loader->register( RobotsTxt::class, false );
			$this->loader->register( \PublishRankSEO\Admin\CrawlOptimizationPage::class, false );
			$this->loader->register( SiteConnections::class, false );
		$this->loader->register( LocalSEO::class, false );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 99 );
		add_action( 'init', array( Installer::class, 'ensure_site' ), 3 );
		add_action( 'wp_initialize_site', array( Installer::class, 'install_new_site' ), 30, 1 );
		add_action( 'init', array( ModuleSwitches::class, 'maybe_flush_rewrite_rules' ), 100 );
				$this->loader->register( IndexNow::class, false );
			$this->loader->register( BaiduSubmission::class, false );
		$this->loader->register( \PublishRankSEO\Social\Mastodon::class, false );
		$this->loader->register( AnalyticsCollector::class, false );
		$this->loader->register( \PublishRankSEO\Analytics\Tracker::class, false );
		$this->loader->register( \PublishRankSEO\Compatibility\ThemeSeo::class, false );
		$this->loader->register( \PublishRankSEO\Newsroom\PublishingDesk::class, false );
		$this->loader->register( \PublishRankSEO\News\WhatsNew::class, false );
		$this->loader->register( \PublishRankSEO\News\UpdateNotifier::class, false );
		$this->loader->register( \PublishRankSEO\Admin\SeoInspectorPage::class, false );
		$this->loader->register( LiveSync::class, false );
		$this->loader->register( \PublishRankSEO\Admin\LivePreview::class, false );
		$this->loader->register( RedirectController::class, false );
		$this->loader->register( \PublishRankSEO\Redirect\RestApi::class, false );
		$this->loader->register( \PublishRankSEO\Redirect\AutomaticPermalinkRedirects::class, false );
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
		// Third-party integrations detect their host plugin lazily, so load order does not matter.
		$this->loader->register( \PublishRankSEO\Integrations\BbPressIntegration::class, false );
		$this->loader->register( \PublishRankSEO\Integrations\BuddyPressIntegration::class, false );
		$this->loader->register( \PublishRankSEO\Integrations\PodcastIntegration::class, false );
		$this->loader->register( \PublishRankSEO\Integrations\WebStoriesIntegration::class, false );
		$this->loader->register( ScheduledAudit::class, false );
		$this->loader->register( ConfigHistory::class, false );
		$this->loader->register( LLMSTxt::class, false );
		$this->loader->register( \PublishRankSEO\Schema\LiveBlogSchema::class, false );
		$this->loader->register( \PublishRankSEO\Feeds\FeedManager::class, false );
		$this->loader->register( \PublishRankSEO\Feeds\FeedEnhancer::class, false );
		$this->loader->register( \PublishRankSEO\Schema\Library\Library::class, false );
		$this->loader->register( \PublishRankSEO\SEO\SiteNavigation::class, false );
		$this->loader->register( \PublishRankSEO\Integrations\WooCommerceSEO::class, false );
		$this->loader->register( \PublishRankSEO\SEO\Language\InclusiveLanguage::class, false );
		$this->register_cache_invalidation();
		// Register this module on every request: its frontend hooks and shortcode must exist outside wp-admin.
		$this->loader->register( PreferredSourcesPage::class, false );
		// Roles & permissions (feature access rules): loaded outside is_admin() so its REST route
		// (GET publishrank/v1/capabilities, manage_roles only) registers; menu/save hooks stay admin-only.
		$this->loader->register( \PublishRankSEO\Admin\RolesPage::class, false );
		if ( is_admin() ) {
			$this->loader->register( \PublishRankSEO\Compatibility\ConflictNotice::class, false );
			$this->loader->register( DashboardPage::class, false, array( $this->registry ) );
			$this->loader->register( SettingsPage::class, false );
			$this->loader->register( \PublishRankSEO\Admin\ModulesPage::class, false );
			$this->loader->register( \PublishRankSEO\Admin\AdminShell::class, false );
			$this->loader->register( \PublishRankSEO\Admin\EditorPlacement::class, false );
			$this->loader->register( \PublishRankSEO\Admin\EditorScoreCard::class, false );
			$this->loader->register( SiteVerificationPage::class, false );
			$this->loader->register( \PublishRankSEO\Admin\VariablePicker::class, false );
			$this->loader->register( \PublishRankSEO\Admin\BreadcrumbsPage::class, false );
			$this->loader->register( OnboardingPage::class, false );
			$this->loader->register( DiagnosticsPage::class, false, array( $this->registry ) );
			$this->loader->register( ImportExport::class, false );
			$this->loader->register( SeoPortability::class, false );
			$this->loader->register( AdminAssets::class, false );
			$this->loader->register( RedirectsPage::class, false );
			$this->loader->register( NotFoundPage::class, false );
			$this->loader->register( RobotsPage::class, false );
				$this->loader->register( PostListColumns::class, false );
				$this->loader->register( SitemapHealthPage::class, false );
				$this->loader->register( InsightsPage::class, false );
				$this->loader->register( AnalyticsPage::class, false );
				$this->loader->register( SearchConsolePage::class, false );
				$this->loader->register( BaiduSubmissionPage::class, false );
			$this->loader->register( ConfigHistoryPage::class, false );
				$this->loader->register( LLMSTxtPage::class, false );
				$this->loader->register( ImageOptimizerPage::class, false );
			$this->loader->register( IndexNowPage::class, false );
			$this->loader->register( \PublishRankSEO\Admin\MastodonPage::class, false );
			$this->loader->register( \PublishRankSEO\Admin\NoticeCenter::class, false );
			$this->loader->register( \PublishRankSEO\Admin\RatingNotice::class, false );
			$this->loader->register( \PublishRankSEO\Admin\ClassicEditorNotice::class, false );
			$this->loader->register( \PublishRankSEO\Admin\FeedsPage::class, false );
			$this->loader->register( \PublishRankSEO\Admin\SiteNavigationPage::class, false );
			$this->loader->register( \PublishRankSEO\Admin\WooCommerceSeoPage::class, false );
			$this->loader->register( \PublishRankSEO\Admin\ThemeSeoPage::class, false );
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

	/**
	 * Refresh everything derived from publication-identity, schema, breadcrumb and SEO-template settings whenever one of
	 * those options is added, changed or deleted. Structured data and metadata are rendered from the saved options on every
	 * request, so nothing in PublishRank itself can serve stale identity data; this clears the per-request caches, bumps the
	 * cache epoch and the schema-index generation, and tells page-cache plugins to purge through an action.
	 */
	private function register_output_cache_invalidation() {
		$prefixes = array( 'publishrank_identity_', 'publishrank_schema_', 'publishrank_social_', 'publishrank_breadcrumb', 'publishrank_org_website_', 'publishrank_seo_publication_', 'publishrank_seo_publisher_', 'publishrank_seo_title_', 'publishrank_home_', 'publishrank_local_seo_' );
		$exact    = array( 'publishrank_meta_enabled', 'publishrank_external_seo_handoff', 'publishrank_primary_url', 'publishrank_publication_short_name', 'publishrank_default_locale', 'publishrank_country', 'publishrank_copyright', 'publishrank_contact' );
		$skip     = array( 'publishrank_schema_index_generation', 'publishrank_seo_cache_epoch', 'publishrank_seo_last_invalidation' );
		$refresh  = static function ( $option ) use ( $prefixes, $exact, $skip ) {
			static $running = false;
			if ( $running || ! is_string( $option ) || 0 !== strpos( $option, 'publishrank_' ) || in_array( $option, $skip, true ) ) {
				return;
			}
			$matches = in_array( $option, $exact, true );
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $option, $prefix ) ) {
					$matches = true;
					break;
				}
			}
			if ( ! $matches ) {
				return;
			}
			$running = true;
			\PublishRankSEO\Meta\SeoData::flush();
			BreadcrumbEngine::reset();
			update_option( 'publishrank_seo_cache_epoch', time(), false );
			update_option( 'publishrank_schema_index_generation', (int) get_option( 'publishrank_schema_index_generation', 1 ) + 1, false );
			/**
			 * Fires after a setting that changes public SEO output was saved, so page-cache plugins can purge.
			 *
			 * @param string $option Option name.
			 */
			do_action( 'publishrank_seo_output_settings_changed', $option );
			$running = false;
		};
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, $refresh, 20, 1 );
		}
	}

	/** Invalidate derived sitemap payloads when content or settings change. */
	private function register_cache_invalidation() {
		$invalidate          = static function () {
			SitemapCursorStore::bump_many( array( 'post', 'page', 'cpt', 'taxonomy', 'author', 'attachment', 'news', 'image', 'video' ) );
			update_option( 'publishrank_seo_last_invalidation', gmdate( 'c' ), false );
		};
		$invalidate_news     = static function () {
			SitemapCursorStore::bump( 'news' );
		};
		$invalidate_image    = static function () {
			SitemapCursorStore::bump( 'image' );
		};
		$invalidate_video    = static function () {
			SitemapCursorStore::bump( 'video' );
		};
		$invalidate_post     = static function ( $post_id = 0, $status_changed = false ) {
			$post_id = (int) $post_id;
			$type    = $post_id ? get_post_type( $post_id ) : '';
			if ( $type && ( wp_is_post_revision( $post_id ) || 'auto-draft' === get_post_status( $post_id ) ) ) {
				return; }
			if ( 'attachment' === $type ) {
				$main = 'attachment';
			} elseif ( 'page' === $type ) {
				$main = 'page';
			} elseif ( $type && 'post' !== $type ) {
				$main = 'cpt';
			} else {
				$main = 'post'; }
			// 2.25: refresh only the sitemap file that holds this post; a full rebuild walks every post on the site.
			foreach ( array( $main, 'image', 'video' ) as $provider ) {
				SitemapCursorStore::touch( $provider, $post_id );
			}
			SitemapCursorStore::bump( 'news' );
			if ( $status_changed ) {
				SitemapCursorStore::bump( 'author' ); }
		};
			$invalidate_term = static function () {
				SitemapCursorStore::bump( 'taxonomy' );
			};
			add_action( 'save_post', $invalidate_post, 20, 1 );
			add_action( 'deleted_post', $invalidate_post, 20, 1 );
			// Publish, unpublish, trash, restore and scheduled-publish transitions do not all fire save_post.
			add_action(
				'transition_post_status',
				static function ( $new, $old, $post ) use ( $invalidate_post ) {
					if ( $new !== $old && $post instanceof \WP_Post ) {
						$invalidate_post( (int) $post->ID, true );
					} },
				20,
				3
			);
			// Editor-controlled eligibility/robots/title metadata changes must refresh the News sitemap immediately.
			$news_meta_keys = array( '_publishrank_seo_news_eligible', '_publishrank_seo_news_stock_tickers', '_publishrank_seo_robots', '_publishrank_seo_title', '_publishrank_seo_canonical', '_yoast_wpseo_meta-robots-noindex', 'rank_math_robots' );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $meta_hook ) {
			add_action(
				$meta_hook,
				static function ( $meta_id, $object_id, $meta_key ) use ( $news_meta_keys ) {
					if ( in_array( $meta_key, $news_meta_keys, true ) ) {
						SitemapCursorStore::bump( 'news' );
						if ( in_array( $meta_key, array( '_publishrank_seo_robots', '_publishrank_seo_canonical', '_yoast_wpseo_meta-robots-noindex', 'rank_math_robots' ), true ) ) {
							$type = get_post_type( (int) $object_id );
							SitemapCursorStore::touch( 'page' === $type ? 'page' : ( 'post' === $type ? 'post' : ( 'attachment' === $type ? 'attachment' : 'cpt' ) ), (int) $object_id );
						}
					} },
				20,
				3
			);
		}
			// Not clean_post_cache: it also fires for every comment and meta update and would rebuild sitemaps constantly.
			add_action( 'add_attachment', $invalidate_post, 20, 1 );
			add_action( 'edit_attachment', $invalidate_post, 20, 1 );
			add_action( 'delete_attachment', $invalidate_post, 20, 1 );
			add_action( 'created_term', $invalidate_term, 20 );
			add_action( 'edited_term', $invalidate_term, 20 );
			add_action( 'delete_term', $invalidate_term, 20 );
			add_action( 'set_object_terms', $invalidate_term, 20 );
			$touch_term = static function ( $term_id ) {
				if ( function_exists( 'update_term_meta' ) && $term_id ) {
					update_term_meta( (int) $term_id, '_publishrank_last_modified', gmdate( 'c' ) );
				} };
			add_action( 'created_term', $touch_term, 20, 1 );
			add_action( 'edited_term', $touch_term, 20, 1 );
			$refresh_sitemap_routes = static function () {
				flush_rewrite_rules( false );
			};
			add_action( 'update_option_publishrank_sitemap_endpoint', $refresh_sitemap_routes, 20 );
			add_action( 'update_option_publishrank_sitemap_aliases', $refresh_sitemap_routes, 20 );
		foreach ( array( 'publishrank_seo_news_enabled', 'publishrank_seo_news_window', 'publishrank_seo_news_include_modified', 'publishrank_seo_news_modified_window', 'publishrank_seo_news_post_types', 'publishrank_seo_news_max_urls', 'publishrank_seo_news_exclude_ids', 'publishrank_seo_news_exclude_categories', 'publishrank_seo_news_stock_tickers', 'publishrank_seo_news_keywords', 'publishrank_seo_news_genre' ) as $setting ) {
			add_action( 'update_option_' . $setting, $invalidate_news, 20 ); }
			add_action( 'update_option_publishrank_seo_image_sitemap', $invalidate_image, 20 );
			add_action( 'update_option_publishrank_seo_media_post_types', $invalidate, 20 );
			add_action( 'update_option_publishrank_image_license_url', $invalidate_image, 20 );
			add_action( 'update_option_publishrank_seo_video_sitemap', $invalidate_video, 20 );
			add_action( 'update_option_publishrank_seo_publisher_name', $invalidate, 20 );
			add_action( 'update_option_publishrank_seo_publisher_logo', $invalidate, 20 );
			add_action( 'update_option_publishrank_seo_publisher_type', $invalidate, 20 );
		$this->register_output_cache_invalidation();
		foreach ( array( 'publishrank_primary_url', 'publishrank_media_host', 'publishrank_cdn_enabled', 'publishrank_cdn_host', 'publishrank_video_host', 'publishrank_api_host', 'publishrank_publication_short_name', 'publishrank_default_language', 'publishrank_indexnow_enabled', 'publishrank_indexnow_key', 'publishrank_indexnow_key_location', 'publishrank_indexnow_post_types', 'publishrank_seo_multilingual_enabled', 'publishrank_seo_hreflang_map', 'publishrank_seo_x_default_url', 'publishrank_default_locale', 'publishrank_timezone', 'publishrank_country', 'publishrank_copyright', 'publishrank_contact', 'publishrank_social_profiles', 'publishrank_author_mode', 'publishrank_seo_title_separator', 'publishrank_seo_default_robots', 'publishrank_seo_default_social_image', 'publishrank_seo_twitter_site', 'publishrank_sitemap_stylesheet', 'publishrank_robots_enabled', 'publishrank_robots_sitemaps', 'publishrank_robots_block_ai', 'publishrank_robots_custom', 'publishrank_seo_title_template_home', 'publishrank_seo_title_template_post', 'publishrank_seo_title_template_page', 'publishrank_seo_title_template_archive' ) as $setting ) {
			add_action( 'update_option_' . $setting, $invalidate, 20 ); }
	}
}
