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
				<p class="rhs-label"><?php esc_html_e( 'SEO health', 'publishrank' ); ?></p>
				<div class="pr-ring" style="--pr-score:<?php echo (int) $score; ?>"><span class="pr-ring__num"><?php echo esc_html( (string) $score ); ?><small>%</small></span></div>
				<h2><?php echo esc_html( $this->level( $score ) ); ?></h2>
				<p><?php echo esc_html( sprintf( /* translators: 1: passed checks, 2: total checks. */ __( '%1$d of %2$d checks pass.', 'publishrank' ), $passed, $total ) ); ?></p>
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
				<div class="pr-dashboard-actions">
					<?php
					$cards = array(
						array(
							'slug'        => 'publishrank-seo-score',
							'icon'        => 'fa-chart-simple',
							'tone'        => 'plum',
							'kicker'      => __( 'Editorial quality', 'publishrank' ),
							'title'       => __( 'SEO score', 'publishrank' ),
							'description' => __( 'Review practical content and technical checks across the site.', 'publishrank' ),
							'button'      => __( 'Open SEO score', 'publishrank' ),
						),
						array(
							'slug'        => 'publishrank-schema',
							'icon'        => 'fa-diagram-project',
							'tone'        => 'sage',
							'kicker'      => __( 'Structured data', 'publishrank' ),
							'title'       => __( 'Schema studio', 'publishrank' ),
							'description' => __( 'Configure publisher, article, media, and eligible rich-result schemas.', 'publishrank' ),
							'button'      => __( 'Manage schema', 'publishrank' ),
						),
						array(
							'slug'        => 'publishrank-seo-sitemap-health',
							'icon'        => 'fa-sitemap',
							'tone'        => 'brass',
							'kicker'      => __( 'News discovery', 'publishrank' ),
							'title'       => __( 'Sitemap health', 'publishrank' ),
							'description' => __( 'Inspect sitemap endpoints and publisher-focused discovery signals.', 'publishrank' ),
							'button'      => __( 'Review sitemaps', 'publishrank' ),
						),
						array(
							'slug'        => 'publishrank-analytics',
							'icon'        => 'fa-chart-line',
							'tone'        => 'sage',
							'kicker'      => __( 'Performance', 'publishrank' ),
							'title'       => __( 'Publisher analytics', 'publishrank' ),
							'description' => __( 'See local publishing trends and connected search performance.', 'publishrank' ),
							'button'      => __( 'View analytics', 'publishrank' ),
						),
						array(
							'slug'        => 'publishrank-search-console',
							'icon'        => 'fa-magnifying-glass-chart',
							'tone'        => 'plum',
							'kicker'      => __( 'Search visibility', 'publishrank' ),
							'title'       => __( 'Search Console', 'publishrank' ),
							'description' => __( 'Review read-only search metrics and connected property status.', 'publishrank' ),
							'button'      => __( 'Open Search Console', 'publishrank' ),
						),
						array(
							'slug'        => 'publishrank-traffic-guard',
							'icon'        => 'fa-shield-halved',
							'tone'        => 'rose',
							'kicker'      => __( 'Access policy', 'publishrank' ),
							'title'       => __( 'Traffic Guard', 'publishrank' ),
							'description' => __( 'Set optional IP/CIDR and trusted-proxy country rules for public pages.', 'publishrank' ),
							'button'      => __( 'Configure Traffic Guard', 'publishrank' ),
						),
						array(
							'slug'        => RedirectsPage::SLUG,
							'icon'        => 'fa-arrow-right-arrow-left',
							'tone'        => 'ink',
							'kicker'      => __( 'Link maintenance', 'publishrank' ),
							'title'       => __( 'Redirects & 404s', 'publishrank' ),
							'description' => __( 'Resolve broken URLs and manage permanent or temporary redirects.', 'publishrank' ),
							'button'      => __( 'Review redirects', 'publishrank' ),
						),
					);
					foreach ( $cards as $card ) :
						?>
						<article class="pr-action-card pr-action-card--<?php echo esc_attr( $card['tone'] ); ?>">
							<div class="pr-action-card__icon" aria-hidden="true"><i class="fa-solid <?php echo esc_attr( $card['icon'] ); ?>"></i></div>
							<p class="pr-action-card__kicker"><?php echo esc_html( $card['kicker'] ); ?></p>
							<h3><?php echo esc_html( $card['title'] ); ?></h3>
							<p class="pr-action-card__description"><?php echo esc_html( $card['description'] ); ?></p>
							<a class="button button-primary pr-action-card__button" href="<?php echo esc_url( Branding::page_url( $card['slug'] ) ); ?>"><?php echo esc_html( $card['button'] ); ?><span class="fa-solid fa-arrow-right" aria-hidden="true"></span></a>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
			<?php do_action( 'publishrank_seo_dashboard_actions' ); ?>
			<h2><?php esc_html_e( 'Technical health', 'publishrank' ); ?></h2>
		<div class="rhs-table-wrap"><table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Area', 'publishrank' ); ?></th><th><?php esc_html_e( 'Status', 'publishrank' ); ?></th><th><?php esc_html_e( 'What to do', 'publishrank' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $checks as $check ) : ?>
				<tr><td><strong><?php echo esc_html( $check['label'] ); ?></strong></td><td><span class="rhs-pill rhs-pill--<?php echo esc_attr( strtolower( $check['status'] ) ); ?>"><?php echo esc_html( $check['status'] ); ?></span></td><td><?php echo esc_html( $check['message'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		</div>
		<?php
	}

	/** @return array<int,array<string,string>> */
	private function checks() {
		$checks    = array();
		$conflicts = ( new ConflictDetector() )->diagnostics();
		$checks[]  = array(
			'label'   => __( 'SEO plugin conflicts', 'publishrank' ),
			'status'  => strtoupper( $conflicts['status'] ),
			'message' => $conflicts['message'],
		);

		$public   = '0' !== (string) get_option( 'blog_public', '1' );
		$checks[] = array(
			'label'   => __( 'Search engine visibility', 'publishrank' ),
			'status'  => $public ? 'PASS' : 'WARNING',
			'message' => $public ? __( 'Search engines are allowed to index this site.', 'publishrank' ) : __( 'WordPress is set to discourage search engines. Change this under Settings → Reading.', 'publishrank' ),
		);

		$pretty   = '' !== (string) get_option( 'permalink_structure', '' );
		$checks[] = array(
			'label'   => __( 'Pretty permalinks', 'publishrank' ),
			'status'  => $pretty ? 'PASS' : 'WARNING',
			'message' => $pretty ? __( 'Sitemap and key-file URLs can be served.', 'publishrank' ) : __( 'Switch from Plain permalinks so sitemap URLs work.', 'publishrank' ),
		);

		$https    = 0 === strpos( (string) home_url(), 'https://' );
		$checks[] = array(
			'label'   => __( 'HTTPS', 'publishrank' ),
			'status'  => $https ? 'PASS' : 'WARNING',
			'message' => $https ? __( 'The site URL uses HTTPS.', 'publishrank' ) : __( 'Serve the site over HTTPS; Google News and Discover favour secure sites.', 'publishrank' ),
		);

			$news     = (bool) get_option( 'publishrank_seo_news_enabled', true );
			$checks[] = array(
				'label'   => __( 'News sitemap', 'publishrank' ),
				'status'  => $news ? 'PASS' : 'WARNING',
				'message' => $news ? __( 'The Google News sitemap is enabled.', 'publishrank' ) : __( 'Enable the News sitemap in settings.', 'publishrank' ),
			);

			$schema_enabled = (bool) get_option( 'publishrank_schema_enabled', true );
			$page_type      = sanitize_text_field( (string) get_option( 'publishrank_schema_default_page_type', 'WebPage' ) );
			/* translators: %s: schema page type. */
			$schema_message = $schema_enabled ? sprintf( __( 'Organization, WebSite, WebPage (%s), breadcrumbs and eligible article/media entities are enabled.', 'publishrank' ), ! empty( $page_type ) ? $page_type : 'WebPage' ) : __( 'Enable the structured-data graph so pages and eligible articles receive JSON-LD.', 'publishrank' );
			$checks[]       = array(
				'label'   => __( 'Structured data coverage', 'publishrank' ),
				'status'  => $schema_enabled ? 'PASS' : 'WARNING',
				'message' => $schema_message,
			);

			$logo     = (string) get_option( 'publishrank_seo_publisher_logo', '' ) || get_theme_mod( 'custom_logo' ) || ( function_exists( 'get_site_icon_url' ) && get_site_icon_url( 512 ) );
			$checks[] = array(
				'label'   => __( 'Publisher logo', 'publishrank' ),
				'status'  => $logo ? 'PASS' : 'WARNING',
				'message' => $logo ? __( 'A publisher logo is available for NewsArticle schema.', 'publishrank' ) : __( 'Add a publisher logo in settings.', 'publishrank' ),
			);

			$social   = '' !== (string) get_option( 'publishrank_seo_default_social_image', '' );
			$checks[] = array(
				'label'   => __( 'Default social image', 'publishrank' ),
				'status'  => $social ? 'PASS' : 'WARNING',
				'message' => $social ? __( 'Articles without a featured image still get a share image.', 'publishrank' ) : __( 'Set a fallback share image in settings.', 'publishrank' ),
			);

			$physical = file_exists( ABSPATH . 'robots.txt' );
			$checks[] = array(
				'label'   => __( 'Robots.txt', 'publishrank' ),
				'status'  => $physical ? 'WARNING' : 'PASS',
				'message' => $physical ? __( 'A physical robots.txt overrides the virtual one PublishRank manages.', 'publishrank' ) : __( 'PublishRank can manage the virtual robots.txt.', 'publishrank' ),
			);

			$compatibility = $this->jnews->diagnostics();
			$checks[]      = array(
				'label'   => __( 'JNews compatibility', 'publishrank' ),
				'status'  => strtoupper( $compatibility['status'] ),
				'message' => $compatibility['message'],
			);
			return $checks;
	}

	/** @return string */
	private function level( $score ) {
		if ( $score >= 90 ) {
			return __( 'SEO steward', 'publishrank' );
		}
		if ( $score >= 70 ) {
			return __( 'Confident publisher', 'publishrank' );
		}
		if ( $score >= 40 ) {
			return __( 'Growing practitioner', 'publishrank' );
		}
		return __( 'Getting started', 'publishrank' );
	}
}
