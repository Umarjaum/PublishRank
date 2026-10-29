<?php
/**
 * PublishRank administrator dashboard.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Compatibility\ConflictDetector;
use PublishRankSEO\Compatibility\JNewsAdapter;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Sitemap\SitemapUrls;
use PublishRankSEO\Redirect\RedirectStore;

final class DashboardPage {
	/** @var OutputRegistry */
	private $registry;

	/** @var JNewsAdapter */
	private $jnews;

	public function __construct( OutputRegistry $registry, JNewsAdapter $jnews ) {
		$this->registry = $registry;
		$this->jnews    = $jnews;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		// Keep every registered destination in WordPress's submenu registry. The
		// dashboard cards link directly to these pages and WordPress uses that
		// registry when authorizing admin.php?page=... requests.
	}

	/**
	 * Legacy compatibility shim. Older releases removed valid submenu pages,
	 * which caused the generic WordPress "not allowed" screen for administrators.
	 */
	public function shorten_submenu() {
		return;
	}

	/** Top-level menu with the PublishRank icon, plus the Dashboard entry. */
	public function menu() {
		add_menu_page(
			__( 'PublishRank', 'publishrank' ),
			__( 'PublishRank', 'publishrank' ),
			'manage_options',
			Branding::MENU_SLUG,
			array( $this, 'render' ),
			add_query_arg( 'ver', rawurlencode( Branding::version() ), Branding::image( 'publishrank-menu-icon-20.png' ) ),
			58
		);
		add_submenu_page( Branding::MENU_SLUG, __( 'Dashboard', 'publishrank' ), __( 'Dashboard', 'publishrank' ), 'manage_options', Branding::MENU_SLUG, array( $this, 'render' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$checks = $this->checks();
		$passed = count(
			array_filter(
				$checks,
				static function ( $check ) {
					return 'PASS' === $check['status'];
				}
			)
		);
		$total  = count( $checks );
		$score  = $total ? (int) round( ( $passed / $total ) * 100 ) : 0;
		$blockers = count(
			array_filter(
				$checks,
				static function ( $check ) {
					return 'search_visibility' === $check['id'] && 'WARNING' === $check['status'];
				}
			)
		);
		$recommendations = max( 0, $total - $passed - $blockers );
		$blocker_summary = sprintf( _n( '%d blocking issue', '%d blocking issues', $blockers, 'publishrank' ), $blockers );
		$recommendation_summary = sprintf( _n( '%d recommendation', '%d recommendations', $recommendations, 'publishrank' ), $recommendations );
		$log    = NotFoundPage::entries();
		$hits   = 0;
		foreach ( $log as $entry ) {
			$hits += (int) ( isset( $entry['count'] ) ? $entry['count'] : 1 );
		}
		$counts       = wp_count_posts( 'post' );
		$published    = $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
			$indexnow = get_option( 'publishrank_indexnow_enabled', false ) ? __( 'On', 'publishrank' ) : __( 'Off', 'publishrank' );
			$urls     = array( SitemapUrls::index_url() => __( 'Sitemap index', 'publishrank' ) );
		if ( get_option( 'publishrank_seo_news_enabled', true ) ) {
				$urls[ SitemapUrls::shard_url( 'news', 1 ) ] = __( 'Google News sitemap', 'publishrank' );
		}

				echo '<div class="wrap">';
				AdminAssets::header( __( 'PublishRank', 'publishrank' ), __( 'Advanced Sitemap & SEO Plugin — built for news sites.', 'publishrank' ) );
				AdminAssets::render_flash();
		?>
					<section class="rhs-welcome-banner" aria-labelledby="rhs-welcome-title">
						<div class="rhs-welcome-banner__copy">
							<p class="rhs-welcome-banner__eyebrow"><span aria-hidden="true"></span><?php esc_html_e( 'Publisher-first SEO workspace', 'publishrank' ); ?></p>
							<h2 id="rhs-welcome-title"><?php esc_html_e( 'Welcome to PublishRank.', 'publishrank' ); ?></h2>
							<p><?php esc_html_e( 'Your newsroom’s tools for technical SEO, search discovery, structured data, and redirects are together in one place. Start with a setup review, then keep every story discoverable.', 'publishrank' ); ?></p>
							<a class="button button-primary" href="<?php echo esc_url( Branding::page_url( 'publishrank-onboarding' ) ); ?>"><?php esc_html_e( 'Review your setup', 'publishrank' ); ?><span aria-hidden="true">→</span></a>
						</div>
						<div class="rhs-welcome-banner__visual" aria-hidden="true">
							<span class="rhs-welcome-banner__orbit rhs-welcome-banner__orbit--outer"></span>
							<span class="rhs-welcome-banner__orbit rhs-welcome-banner__orbit--inner"></span>
							<span class="rhs-welcome-banner__spark rhs-welcome-banner__spark--one"></span>
							<span class="rhs-welcome-banner__spark rhs-welcome-banner__spark--two"></span>
							<div class="rhs-welcome-banner__tile">
								<span class="rhs-welcome-banner__tile-kicker"><?php esc_html_e( 'PUBLISHER SEO', 'publishrank' ); ?></span>
								<strong><?php esc_html_e( 'Publish.', 'publishrank' ); ?><br /><?php esc_html_e( 'Discover.', 'publishrank' ); ?><br /><?php esc_html_e( 'Grow.', 'publishrank' ); ?></strong>
								<span class="rhs-welcome-banner__tile-signals"><i></i><i></i><i></i></span>
							</div>
						</div>
					</section>
				<div class="rhs-grid">
			<div class="rhs-card rhs-score rhs-score--<?php echo esc_attr( $score >= 90 ? 'good' : ( $score >= 70 ? 'ok' : 'bad' ) ); ?>" style="--rhs-score:<?php echo (int) $score; ?>">
				<p class="rhs-label"><?php esc_html_e( 'Configuration checklist', 'publishrank' ); ?></p>
				<div class="pr-ring" style="--pr-score:<?php echo (int) $score; ?>"><span class="pr-ring__num"><?php echo esc_html( (string) $score ); ?><small>%</small></span></div>
				<h2><?php esc_html_e( 'Site checks', 'publishrank' ); ?></h2>
				<p><?php echo esc_html( sprintf( /* translators: 1: passing checks, 2: total checks, 3: blocking issues, 4: recommendations. */ __( '%1$d of %2$d checks pass; %3$s and %4$s. Every check is weighted equally; this is not a search-ranking score.', 'publishrank' ), $passed, $total, $blocker_summary, $recommendation_summary ) ); ?></p>
			</div>
			<div class="rhs-card">
				<p class="rhs-label"><?php esc_html_e( 'Published articles', 'publishrank' ); ?></p>
				<p class="rhs-stat"><?php echo esc_html( number_format_i18n( $published ) ); ?></p>
				<p><?php echo esc_html( sprintf( /* translators: %d: freshness window in hours. */ __( 'News sitemap window: %d hours', 'publishrank' ), (int) get_option( 'publishrank_seo_news_window', 48 ) ) ); ?></p>
			</div>
			<div class="rhs-card">
				<p class="rhs-label"><?php esc_html_e( 'Broken URLs (404)', 'publishrank' ); ?></p>
				<p class="rhs-stat"><?php echo esc_html( number_format_i18n( count( $log ) ) ); ?> <small><?php echo esc_html( sprintf( /* translators: %s: hit count. */ __( '%s hits', 'publishrank' ), number_format_i18n( $hits ) ) ); ?></small></p>
				<p><a href="<?php echo esc_url( Branding::page_url( NotFoundPage::SLUG ) ); ?>"><?php esc_html_e( 'Review and redirect', 'publishrank' ); ?></a></p>
			</div>
			<div class="rhs-card">
				<p class="rhs-label"><?php esc_html_e( 'Redirect rules', 'publishrank' ); ?></p>
				<p class="rhs-stat"><?php echo esc_html( number_format_i18n( count( RedirectStore::all() ) ) ); ?></p>
				<p><?php echo esc_html( sprintf( /* translators: %s: On or Off. */ __( 'IndexNow: %s', 'publishrank' ), $indexnow ) ); ?></p>
			</div>
		</div>
		<div class="rhs-grid">
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Sitemap URLs', 'publishrank' ); ?></h2>
				<?php foreach ( $urls as $url => $label ) : ?>
					<div class="rhs-url-row">
						<span><strong><?php echo esc_html( $label ); ?></strong><br /><code><?php echo esc_html( $url ); ?></code></span>
						<span><a class="button button-small" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'publishrank' ); ?></a> <button type="button" class="button button-small rhs-copy" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'publishrank' ); ?></button></span>
					</div>
				<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Submit these in Google Search Console and Bing Webmaster Tools.', 'publishrank' ); ?></p>
				</div>
			</div>
			<section class="pr-dashboard-workspace" aria-labelledby="pr-dashboard-workspace-title">
				<div class="pr-dashboard-workspace__heading"><div><p class="pr-dashboard-kicker"><?php esc_html_e( 'Publisher workspace', 'publishrank' ); ?></p><h2 id="pr-dashboard-workspace-title"><?php esc_html_e( 'Move the work forward', 'publishrank' ); ?></h2><p><?php esc_html_e( 'Choose a focused workspace. Every card opens its settings directly—no nested action menus.', 'publishrank' ); ?></p></div></div>
						<?php
						$tool_groups = array(
							'discovery' => array(
								'title'       => __( 'Search & discovery', 'publishrank' ),
								'description' => __( 'Manage how search engines find, understand, and verify your publication.', 'publishrank' ),
								'cards'       => array(
									array( 'slug' => 'publishrank-schema', 'icon' => 'fa-diagram-project', 'tone' => 'sage', 'kicker' => __( 'Structured data', 'publishrank' ), 'title' => __( 'Schema studio', 'publishrank' ), 'description' => __( 'Configure publisher, article, and media structured data for your site.', 'publishrank' ), 'button' => __( 'Manage schema', 'publishrank' ) ),
									array( 'slug' => 'publishrank-sitemap-health', 'icon' => 'fa-sitemap', 'tone' => 'brass', 'kicker' => __( 'Technical SEO', 'publishrank' ), 'title' => __( 'Sitemap health', 'publishrank' ), 'description' => __( 'Check sitemap endpoints, article eligibility, and generation status.', 'publishrank' ), 'button' => __( 'Review sitemaps', 'publishrank' ) ),
									array( 'slug' => 'publishrank-indexnow', 'icon' => 'fa-bolt', 'tone' => 'sage', 'kicker' => __( 'Fast discovery', 'publishrank' ), 'title' => __( 'IndexNow', 'publishrank' ), 'description' => __( 'Configure supported search-engine notifications when content changes.', 'publishrank' ), 'button' => __( 'Configure IndexNow', 'publishrank' ) ),
									array( 'slug' => 'publishrank-search-console', 'icon' => 'fa-magnifying-glass-chart', 'tone' => 'plum', 'kicker' => __( 'Search visibility', 'publishrank' ), 'title' => __( 'Search Console', 'publishrank' ), 'description' => __( 'Review connected property status and available search performance data.', 'publishrank' ), 'button' => __( 'Open Search Console', 'publishrank' ) ),
									array( 'slug' => 'publishrank-baidu', 'icon' => 'fa-earth-asia', 'tone' => 'ink', 'kicker' => __( 'Search engines', 'publishrank' ), 'title' => __( 'Search engines', 'publishrank' ), 'description' => __( 'Review additional search-engine submission and discovery options.', 'publishrank' ), 'button' => __( 'View search engines', 'publishrank' ) ),
									array( 'slug' => 'publishrank-seo-robots', 'icon' => 'fa-robot', 'tone' => 'ink', 'kicker' => __( 'Crawler access', 'publishrank' ), 'title' => __( 'Robots.txt', 'publishrank' ), 'description' => __( 'Review crawl directives and the sitemap reference exposed to crawlers.', 'publishrank' ), 'button' => __( 'Review robots rules', 'publishrank' ) ),
									array( 'slug' => 'publishrank-verification', 'icon' => 'fa-circle-check', 'tone' => 'sage', 'kicker' => __( 'Site ownership', 'publishrank' ), 'title' => __( 'Site verification', 'publishrank' ), 'description' => __( 'Manage verification codes used to confirm ownership with supported services.', 'publishrank' ), 'button' => __( 'Manage verification', 'publishrank' ) ),
									array( 'slug' => 'publishrank-llms-txt', 'icon' => 'fa-robot', 'tone' => 'plum', 'kicker' => __( 'AI discovery', 'publishrank' ), 'title' => __( 'AI & llms.txt', 'publishrank' ), 'description' => __( 'Review the optional llms.txt file and AI-crawler guidance for your site.', 'publishrank' ), 'button' => __( 'Review AI guidance', 'publishrank' ) ),
								),
							),
							'publishing' => array(
								'title'       => __( 'Content & publishing', 'publishrank' ),
								'description' => __( 'Improve content signals and support everyday publishing workflows.', 'publishrank' ),
								'cards'       => array(
									array( 'slug' => 'publishrank-seo-score', 'icon' => 'fa-chart-simple', 'tone' => 'plum', 'kicker' => __( 'Editorial quality', 'publishrank' ), 'title' => __( 'SEO score', 'publishrank' ), 'description' => __( 'Review practical content and technical checks across the site.', 'publishrank' ), 'button' => __( 'Open SEO score', 'publishrank' ) ),
									array( 'url' => admin_url( 'edit.php?post_type=post' ), 'icon' => 'fa-pen-to-square', 'tone' => 'plum', 'kicker' => __( 'Post editor', 'publishrank' ), 'title' => __( 'SEO Overrides', 'publishrank' ), 'description' => __( 'Open a post and use PublishRank SEO Overrides to manage its search, social, and indexing fields.', 'publishrank' ), 'button' => __( 'Open Posts', 'publishrank' ), 'capability' => 'edit_posts' ),
									array( 'url' => admin_url( 'edit.php?post_type=post' ), 'icon' => 'fa-images', 'tone' => 'sage', 'kicker' => __( 'Image workflow', 'publishrank' ), 'title' => __( 'Google Discover image', 'publishrank' ), 'description' => __( 'Open a post’s SEO Overrides to inspect image dimensions and create a resized copy without replacing the original.', 'publishrank' ), 'button' => __( 'Open image controls', 'publishrank' ), 'capability' => 'edit_posts' ),
									array( 'slug' => 'publishrank-author-expertise', 'icon' => 'fa-user-pen', 'tone' => 'sage', 'kicker' => __( 'Trust signals', 'publishrank' ), 'title' => __( 'Author expertise', 'publishrank' ), 'description' => __( 'Manage author credentials and publication expertise information.', 'publishrank' ), 'button' => __( 'Manage author profiles', 'publishrank' ) ),
									array( 'slug' => 'publishrank-local-seo', 'icon' => 'fa-location-dot', 'tone' => 'brass', 'kicker' => __( 'Local visibility', 'publishrank' ), 'title' => __( 'Local SEO', 'publishrank' ), 'description' => __( 'Configure local business identity and location-oriented search signals.', 'publishrank' ), 'button' => __( 'Manage local SEO', 'publishrank' ) ),
									array( 'slug' => 'publishrank-image-optimizer', 'icon' => 'fa-images', 'tone' => 'sage', 'kicker' => __( 'Media workflow', 'publishrank' ), 'title' => __( 'Image optimizer', 'publishrank' ), 'description' => __( 'Review media optimization tools and image delivery settings.', 'publishrank' ), 'button' => __( 'Open image tools', 'publishrank' ), 'capability' => 'upload_files' ),
									array( 'slug' => 'publishrank-publisher-toolkit', 'icon' => 'fa-toolbox', 'tone' => 'ink', 'kicker' => __( 'Publisher tools', 'publishrank' ), 'title' => __( 'Publisher toolkit', 'publishrank' ), 'description' => __( 'Access focused utilities for newsroom publishing and site maintenance.', 'publishrank' ), 'button' => __( 'Open toolkit', 'publishrank' ) ),
									array( 'slug' => 'publishrank-seo-internal-links', 'icon' => 'fa-link', 'tone' => 'plum', 'kicker' => __( 'Content connections', 'publishrank' ), 'title' => __( 'Internal links', 'publishrank' ), 'description' => __( 'Review internal-link opportunities and strengthen related content paths.', 'publishrank' ), 'button' => __( 'Review internal links', 'publishrank' ) ),
									array( 'slug' => 'publishrank-link-intelligence', 'icon' => 'fa-diagram-project', 'tone' => 'brass', 'kicker' => __( 'Link strategy', 'publishrank' ), 'title' => __( 'Link intelligence', 'publishrank' ), 'description' => __( 'Explore publisher-focused link recommendations and relationship insights.', 'publishrank' ), 'button' => __( 'Open link intelligence', 'publishrank' ) ),
								),
							),
							'operations' => array(
								'title'       => __( 'Measure & maintain', 'publishrank' ),
								'description' => __( 'Monitor performance, resolve broken links, and review site safeguards.', 'publishrank' ),
								'cards'       => array(
									array( 'slug' => 'publishrank-analytics', 'icon' => 'fa-chart-line', 'tone' => 'sage', 'kicker' => __( 'Performance', 'publishrank' ), 'title' => __( 'Publisher analytics', 'publishrank' ), 'description' => __( 'Review local publishing trends and connected search performance.', 'publishrank' ), 'button' => __( 'View analytics', 'publishrank' ) ),
									array( 'slug' => 'publishrank-insights', 'icon' => 'fa-lightbulb', 'tone' => 'brass', 'kicker' => __( 'Content insights', 'publishrank' ), 'title' => __( 'Insights', 'publishrank' ), 'description' => __( 'Explore editorial and site-level signals that can guide next steps.', 'publishrank' ), 'button' => __( 'View insights', 'publishrank' ) ),
									array( 'slug' => 'publishrank-seo-redirects', 'icon' => 'fa-arrow-right-arrow-left', 'tone' => 'ink', 'kicker' => __( 'Link maintenance', 'publishrank' ), 'title' => __( 'Redirects', 'publishrank' ), 'description' => __( 'Create and review permanent or temporary redirects for changed URLs.', 'publishrank' ), 'button' => __( 'Manage redirects', 'publishrank' ) ),
									array( 'slug' => 'publishrank-seo-404', 'icon' => 'fa-triangle-exclamation', 'tone' => 'rose', 'kicker' => __( 'Broken links', 'publishrank' ), 'title' => __( '404 monitor', 'publishrank' ), 'description' => __( 'Review not-found requests and decide which URLs need redirects.', 'publishrank' ), 'button' => __( 'Review 404s', 'publishrank' ) ),
									array( 'slug' => 'publishrank-traffic-guard', 'icon' => 'fa-shield-halved', 'tone' => 'rose', 'kicker' => __( 'Access policy', 'publishrank' ), 'title' => __( 'Traffic Guard', 'publishrank' ), 'description' => __( 'Review optional IP, proxy, and country-based access rules.', 'publishrank' ), 'button' => __( 'Configure Traffic Guard', 'publishrank' ) ),
									array( 'slug' => 'publishrank-seo-config-history', 'icon' => 'fa-clock-rotate-left', 'tone' => 'ink', 'kicker' => __( 'Change control', 'publishrank' ), 'title' => __( 'Configuration history', 'publishrank' ), 'description' => __( 'Review recorded SEO configuration changes and prior values.', 'publishrank' ), 'button' => __( 'View configuration history', 'publishrank' ) ),
									array( 'slug' => 'publishrank-seo-diagnostics', 'icon' => 'fa-stethoscope', 'tone' => 'brass', 'kicker' => __( 'Troubleshooting', 'publishrank' ), 'title' => __( 'Diagnostics', 'publishrank' ), 'description' => __( 'Check plugin health, compatibility notices, and technical configuration.', 'publishrank' ), 'button' => __( 'Run diagnostics', 'publishrank' ) ),
								),
							),
							'setup' => array(
								'title'       => __( 'Settings & help', 'publishrank' ),
								'description' => __( 'Set up the plugin, adjust global defaults, or find guidance.', 'publishrank' ),
								'cards'       => array(
									array( 'slug' => 'publishrank-onboarding', 'icon' => 'fa-wand-magic-sparkles', 'tone' => 'plum', 'kicker' => __( 'Guided setup', 'publishrank' ), 'title' => __( 'Setup wizard', 'publishrank' ), 'description' => __( 'Review the core site identity and SEO settings in a guided sequence.', 'publishrank' ), 'button' => __( 'Review setup', 'publishrank' ) ),
									array( 'slug' => 'publishrank', 'icon' => 'fa-sliders', 'tone' => 'ink', 'kicker' => __( 'Global defaults', 'publishrank' ), 'title' => __( 'Settings', 'publishrank' ), 'description' => __( 'Manage site-wide SEO defaults, integrations, and plugin preferences.', 'publishrank' ), 'button' => __( 'Open settings', 'publishrank' ) ),
									array( 'slug' => 'publishrank-documentation', 'icon' => 'fa-book-open', 'tone' => 'sage', 'kicker' => __( 'Guidance', 'publishrank' ), 'title' => __( 'Documentation & help', 'publishrank' ), 'description' => __( 'Find explanations of PublishRank features and their recommended use.', 'publishrank' ), 'button' => __( 'Browse help', 'publishrank' ) ),
								),
							),
						);
						foreach ( $tool_groups as $group_id => $group ) :
							$heading_id = 'pr-dashboard-tools-' . sanitize_html_class( $group_id );
							?>
							<section class="pr-tool-group" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
								<div class="pr-tool-group__heading"><h3 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $group['title'] ); ?></h3><p><?php echo esc_html( $group['description'] ); ?></p></div>
								<div class="pr-dashboard-actions">
									<?php foreach ( $group['cards'] as $card ) : ?>
										<?php if ( isset( $card['capability'] ) && ! current_user_can( $card['capability'] ) ) { continue; } ?>
										<article class="pr-action-card pr-action-card--<?php echo esc_attr( $card['tone'] ); ?>">
											<div class="pr-action-card__icon" aria-hidden="true"><i class="fa-solid <?php echo esc_attr( $card['icon'] ); ?>"></i></div>
											<p class="pr-action-card__kicker"><?php echo esc_html( $card['kicker'] ); ?></p>
											<h4><?php echo esc_html( $card['title'] ); ?></h4>
											<p class="pr-action-card__description"><?php echo esc_html( $card['description'] ); ?></p>
											<a class="button button-secondary pr-action-card__button" href="<?php echo esc_url( isset( $card['url'] ) ? $card['url'] : Branding::page_url( $card['slug'] ) ); ?>"><?php echo esc_html( $card['button'] ); ?><span class="fa-solid fa-arrow-right" aria-hidden="true"></span></a>
										</article>
									<?php endforeach; ?>
								</div>
							</section>
						<?php endforeach; ?>
						</section>

			<?php do_action( 'publishrank_seo_dashboard_actions' ); ?>
<h2><?php esc_html_e( 'Technical health', 'publishrank' ); ?></h2>
<?php $attention = $total - $passed; ?>
<p class="pr-health-summary"><?php echo esc_html( $attention ? sprintf( /* translators: %d: number of checks that need review. */ _n( '%d check needs review. Blocking issues and recommendations are labelled separately; use an action link to review each one.', '%d checks need review. Blocking issues and recommendations are labelled separately; use an action link to review each one.', $attention, 'publishrank' ), $attention ) : __( 'All technical checks are passing. Review this page again after major site or plugin changes.', 'publishrank' ) ); ?></p>
<div class="rhs-table-wrap pr-health-table-wrap" role="region" aria-label="<?php esc_attr_e( 'Technical health checks', 'publishrank' ); ?>" tabindex="0"><table class="widefat striped">
<thead><tr><th><?php esc_html_e( 'Area', 'publishrank' ); ?></th><th><?php esc_html_e( 'Status', 'publishrank' ); ?></th><th><?php esc_html_e( 'Details', 'publishrank' ); ?></th><th><?php esc_html_e( 'Next step', 'publishrank' ); ?></th></tr></thead>
<tbody>
<?php foreach ( $checks as $check ) : ?>
<tr><td><strong><?php echo esc_html( $check['label'] ); ?></strong><?php if ( 'PASS' !== $check['status'] ) : ?><br /><span class="pr-check-priority pr-check-priority--<?php echo esc_attr( $check['id'] ); ?>"><?php if ( 'search_visibility' === $check['id'] ) : esc_html_e( 'Blocking issue', 'publishrank' ); elseif ( 'seo_plugin_conflicts' === $check['id'] ) : esc_html_e( 'Potential conflict', 'publishrank' ); else : esc_html_e( 'Recommendation', 'publishrank' ); endif; ?></span><?php endif; ?></td><td><span class="rhs-pill rhs-pill--<?php echo esc_attr( strtolower( $check['status'] ) ); ?>"><?php echo esc_html( $check['status'] ); ?></span></td><td><?php echo esc_html( $check['message'] ); ?></td><td><?php $action = $this->remediation( $check ); if ( $action ) : ?><a class="button button-small" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a><?php if ( ! empty( $action['hint'] ) ) : ?><p class="description"><?php echo esc_html( $action['hint'] ); ?></p><?php endif; ?><?php else : ?><span class="pr-no-action">&mdash;</span><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody>
</table></div>
		</div>
		<?php
	}

	/** Return a safe, direct next step for dashboard findings. */
	private function remediation( $check ) {
		if ( 'PASS' === $check['status'] ) {
			return null;
		}
		$actions = array(
			'seo_plugin_conflicts' => array( 'url' => Branding::page_url( 'publishrank-seo-diagnostics' ), 'label' => __( 'Review diagnostics', 'publishrank' ) ),
			'search_visibility' => array( 'url' => admin_url( 'options-reading.php' ), 'label' => __( 'Reading settings', 'publishrank' ) ),
			'pretty_permalinks' => array( 'url' => admin_url( 'options-permalink.php' ), 'label' => __( 'Permalink settings', 'publishrank' ) ),
			'https' => array( 'url' => admin_url( 'options-general.php' ), 'label' => __( 'Review site URLs', 'publishrank' ), 'hint' => __( 'Enable HTTPS with your hosting provider first, then confirm both WordPress URLs use HTTPS.', 'publishrank' ) ),
			'news_sitemap' => array( 'url' => Branding::page_url( 'publishrank' ) . '#pr-settings-news', 'label' => __( 'Review News sitemap settings', 'publishrank' ) ),
			'structured_data' => array( 'url' => Branding::page_url( 'publishrank-schema' ), 'label' => __( 'Review schema settings', 'publishrank' ) ),
			'publisher_logo' => array( 'url' => add_query_arg( array( 'edit' => 1, 'step' => 3 ), Branding::page_url( 'publishrank-onboarding' ) ), 'label' => __( 'Review publisher logo', 'publishrank' ) ),
			'default_social_image' => array( 'url' => add_query_arg( array( 'edit' => 1, 'step' => 4 ), Branding::page_url( 'publishrank-onboarding' ) ), 'label' => __( 'Review social sharing settings', 'publishrank' ) ),
			'robots_txt' => array( 'url' => Branding::page_url( 'publishrank-seo-robots' ), 'label' => __( 'Review robots settings', 'publishrank' ) ),
			'jnews_compatibility' => array( 'url' => Branding::page_url( 'publishrank-seo-diagnostics' ), 'label' => __( 'Review diagnostics', 'publishrank' ) ),
		);
		return isset( $actions[ $check['id'] ] ) ? $actions[ $check['id'] ] : null;
	}
	private function checks() {
		$checks    = array();
		$conflicts = ( new ConflictDetector() )->diagnostics();
		$checks[]  = array(
			'label'   => __( 'SEO plugin conflicts', 'publishrank' ),
			'id'      => 'seo_plugin_conflicts',
			'status'  => strtoupper( $conflicts['status'] ),
			'message' => $conflicts['message'],
		);

		$public   = '0' !== (string) get_option( 'blog_public', '1' );
		$checks[] = array(
			'label'   => __( 'Search engine visibility', 'publishrank' ),
			'id'      => 'search_visibility',
			'status'  => $public ? 'PASS' : 'WARNING',
			'message' => $public ? __( 'Search engines are allowed to index this site.', 'publishrank' ) : __( 'WordPress is set to discourage search engines. Change this under Settings → Reading.', 'publishrank' ),
		);

		$pretty   = '' !== (string) get_option( 'permalink_structure', '' );
		$checks[] = array(
			'label'   => __( 'Pretty permalinks', 'publishrank' ),
			'id'      => 'pretty_permalinks',
			'status'  => $pretty ? 'PASS' : 'WARNING',
			'message' => $pretty ? __( 'Sitemap and key-file URLs can be served.', 'publishrank' ) : __( 'Switch from Plain permalinks so sitemap URLs work.', 'publishrank' ),
		);

		$https    = 0 === strpos( (string) home_url(), 'https://' );
		$checks[] = array(
			'label'   => __( 'HTTPS', 'publishrank' ),
			'id'      => 'https',
			'status'  => $https ? 'PASS' : 'WARNING',
			'message' => $https ? __( 'The site URL uses HTTPS.', 'publishrank' ) : __( 'Serve the site over HTTPS; Google News and Discover favour secure sites.', 'publishrank' ),
		);

			$news     = (bool) get_option( 'publishrank_seo_news_enabled', true );
			$checks[] = array(
				'label'   => __( 'News sitemap', 'publishrank' ),
				'id'      => 'news_sitemap',
				'status'  => $news ? 'PASS' : 'WARNING',
				'message' => $news ? __( 'The Google News sitemap is enabled.', 'publishrank' ) : __( 'Enable the News sitemap in settings.', 'publishrank' ),
			);

			$schema_enabled = (bool) get_option( 'publishrank_schema_enabled', true );
			$page_type      = sanitize_text_field( (string) get_option( 'publishrank_schema_default_page_type', 'WebPage' ) );
			/* translators: %s: schema page type. */
			$schema_message = $schema_enabled ? sprintf( __( 'Organization, WebSite, WebPage (%s), breadcrumbs and eligible article/media entities are enabled.', 'publishrank' ), ! empty( $page_type ) ? $page_type : 'WebPage' ) : __( 'Enable the structured-data graph so pages and eligible articles receive JSON-LD.', 'publishrank' );
			$checks[]       = array(
				'label'   => __( 'Structured data coverage', 'publishrank' ),
				'id'      => 'structured_data',
				'status'  => $schema_enabled ? 'PASS' : 'WARNING',
				'message' => $schema_message,
			);

			$logo     = (string) get_option( 'publishrank_seo_publisher_logo', '' ) || get_theme_mod( 'custom_logo' ) || ( function_exists( 'get_site_icon_url' ) && get_site_icon_url( 512 ) );
			$checks[] = array(
				'label'   => __( 'Publisher logo', 'publishrank' ),
				'id'      => 'publisher_logo',
				'status'  => $logo ? 'PASS' : 'WARNING',
				'message' => $logo ? __( 'A publisher logo is available for NewsArticle schema.', 'publishrank' ) : __( 'Add a publisher logo in settings.', 'publishrank' ),
			);

			$social   = '' !== (string) get_option( 'publishrank_seo_default_social_image', '' );
			$checks[] = array(
				'label'   => __( 'Default social image', 'publishrank' ),
				'id'      => 'default_social_image',
				'status'  => $social ? 'PASS' : 'WARNING',
				'message' => $social ? __( 'Articles without a featured image still get a share image.', 'publishrank' ) : __( 'Set a fallback share image in settings.', 'publishrank' ),
			);

			$physical = file_exists( ABSPATH . 'robots.txt' );
			$checks[] = array(
				'label'   => __( 'Robots.txt', 'publishrank' ),
				'id'      => 'robots_txt',
				'status'  => $physical ? 'WARNING' : 'PASS',
				'message' => $physical ? __( 'A physical robots.txt overrides the virtual one PublishRank manages.', 'publishrank' ) : __( 'PublishRank can manage the virtual robots.txt.', 'publishrank' ),
			);

			$compatibility = $this->jnews->diagnostics();
			$checks[]      = array(
				'label'   => __( 'JNews compatibility', 'publishrank' ),
				'id'      => 'jnews_compatibility',
				'status'  => strtoupper( $compatibility['status'] ),
				'message' => $compatibility['message'],
			);
			return $checks;
	}
}
