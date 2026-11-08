<?php
/**
 * Shared frame for every PublishRank screen: the branded header on top, the left section
 * navigation and the main content column. Features, General Settings, Sitemap Hub and Help &
 * documentation print the frame themselves; every other PublishRank tool screen is wrapped in
 * the same frame automatically, so all screens look and navigate the same way.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class AdminShell {
	/** Screens that print the frame themselves (the Setup Wizard included, so it looks and navigates like Features). */
	const NATIVE = array( 'publishrank-modules', 'publishrank', 'publishrank-sitemap-health', 'publishrank-documentation', 'publishrank-onboarding' );

	/** Screens that never get the frame (the dashboard has its own full-width layout). */
	const EXCLUDED = array( 'publishrank-seo-dashboard' );

	/** Legacy nav keys used by older callers. */
	const KEYS = array(
		'modules'   => 'publishrank-modules',
		'settings'  => 'publishrank',
		'sitemap'   => 'publishrank-sitemap-health',
		'help'      => 'publishrank-documentation',
		'dashboard' => 'publishrank-seo-dashboard',
	);

	/** Whether a frame wrapper is currently buffering page output. */
	private static $buffering = false;

	public function register() {
		add_action( 'admin_menu', array( $this, 'attach_frames' ), 1000 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 1001 );
	}

	/**
	 * Navigation groups. Each tool has an icon, a short label and a one-line description that is
	 * reused as the intro on framed screens.
	 *
	 * @return array<string,array{label:string,items:array<string,array{icon:string,label:string,desc:string}>}>
	 */
	public static function groups() {
		$groups = array(
			'start'      => array(
				'label' => '',
				'items' => array(
					'publishrank-seo-dashboard'  => array( 'icon' => 'fa-gauge-high', 'label' => __( 'Dashboard', 'publishrank' ), 'desc' => __( 'Your PublishRank overview.', 'publishrank' ) ),
					'publishrank-modules'        => array( 'icon' => 'fa-toggle-on', 'label' => __( 'Features', 'publishrank' ), 'desc' => __( 'Turn PublishRank features on or off.', 'publishrank' ) ),
					'publishrank'                => array( 'icon' => 'fa-sliders', 'label' => __( 'General Settings', 'publishrank' ), 'desc' => __( 'Site-wide SEO defaults and preferences.', 'publishrank' ) ),
					'publishrank-sitemap-health' => array( 'icon' => 'fa-sitemap', 'label' => __( 'Sitemap Hub', 'publishrank' ), 'desc' => __( 'Choose what goes in your sitemap and check it is valid.', 'publishrank' ) ),
				),
			),
			'discovery'  => array(
				'label' => __( 'Search & discovery', 'publishrank' ),
				'items' => array(
					'publishrank-schema'                   => array( 'icon' => 'fa-diagram-project', 'label' => __( 'Schema', 'publishrank' ), 'desc' => __( 'Configure publisher, article and media structured data for your site.', 'publishrank' ) ),
					'publishrank-search-console'           => array( 'icon' => 'fa-magnifying-glass-chart', 'label' => __( 'Search Console', 'publishrank' ), 'desc' => __( 'Connect Google Search Console and review search performance data.', 'publishrank' ) ),
					'publishrank-indexnow'                 => array( 'icon' => 'fa-bolt', 'label' => __( 'IndexNow', 'publishrank' ), 'desc' => __( 'Tell supported search engines the moment content changes.', 'publishrank' ) ),
					'publishrank-baidu'                    => array( 'icon' => 'fa-earth-asia', 'label' => __( 'Search engines', 'publishrank' ), 'desc' => __( 'Additional search-engine submission and discovery options.', 'publishrank' ) ),
					'publishrank-google-preferred-sources' => array( 'icon' => 'fa-star', 'label' => __( 'Preferred Sources', 'publishrank' ), 'desc' => __( 'Create your Google Preferred Sources link and button.', 'publishrank' ) ),
					'publishrank-seo-robots'               => array( 'icon' => 'fa-robot', 'label' => __( 'Robots.txt', 'publishrank' ), 'desc' => __( 'Decide which crawlers may visit which parts of your site.', 'publishrank' ) ),
					'publishrank-crawl-optimization'       => array( 'icon' => 'fa-filter', 'label' => __( 'Crawl optimization', 'publishrank' ), 'desc' => __( 'Clean up feeds, search pages and extra URLs crawlers do not need.', 'publishrank' ) ),
					'publishrank-breadcrumbs'              => array( 'icon' => 'fa-chevron-right', 'label' => __( 'Breadcrumbs', 'publishrank' ), 'desc' => __( 'Show a navigation trail and its structured data.', 'publishrank' ) ),
					'publishrank-verification'             => array( 'icon' => 'fa-circle-check', 'label' => __( 'Site verification', 'publishrank' ), 'desc' => __( 'Verification codes that prove you own the site.', 'publishrank' ) ),
					'publishrank-llms-txt'                 => array( 'icon' => 'fa-brain', 'label' => __( 'AI & llms.txt', 'publishrank' ), 'desc' => __( 'Guidance for AI assistants and the llms.txt file.', 'publishrank' ) ),
				),
			),
			'publishing' => array(
				'label' => __( 'Content & publishing', 'publishrank' ),
				'items' => array(
					'publishrank-seo-score'          => array( 'icon' => 'fa-chart-simple', 'label' => __( 'SEO score', 'publishrank' ), 'desc' => __( 'Practical content and technical checks across the site.', 'publishrank' ) ),
					'publishrank-author-expertise'   => array( 'icon' => 'fa-user-pen', 'label' => __( 'Author expertise', 'publishrank' ), 'desc' => __( 'Author credentials and expertise that build reader trust.', 'publishrank' ) ),
					'publishrank-local-seo'          => array( 'icon' => 'fa-location-dot', 'label' => __( 'Local SEO', 'publishrank' ), 'desc' => __( 'Your business location and local search details.', 'publishrank' ) ),
					'publishrank-image-optimizer'    => array( 'icon' => 'fa-images', 'label' => __( 'Image optimizer', 'publishrank' ), 'desc' => __( 'Media optimization tools and image delivery settings.', 'publishrank' ) ),
					'publishrank-publisher-toolkit'  => array( 'icon' => 'fa-toolbox', 'label' => __( 'Publisher toolkit', 'publishrank' ), 'desc' => __( 'Bulk SEO editing and everyday newsroom utilities.', 'publishrank' ) ),
					'publishrank-seo-internal-links' => array( 'icon' => 'fa-link', 'label' => __( 'Internal links', 'publishrank' ), 'desc' => __( 'Find and add links between your related stories.', 'publishrank' ) ),
					'publishrank-link-intelligence'  => array( 'icon' => 'fa-diagram-project', 'label' => __( 'Link intelligence', 'publishrank' ), 'desc' => __( 'Link recommendations and relationship insights for publishers.', 'publishrank' ) ),
				),
			),
			'newsroom'   => array(
				'label' => __( 'Newsroom', 'publishrank' ),
				'items' => array(
					'uj-newsroom'               => array( 'icon' => 'fa-newspaper', 'label' => __( 'Newsroom', 'publishrank' ), 'desc' => __( 'Story pitches, assignments and workflow stages.', 'publishrank' ) ),
					'uj-newsroom-board'         => array( 'icon' => 'fa-table-columns', 'label' => __( 'Planning board', 'publishrank' ), 'desc' => __( 'Plan and move stories across the editorial board.', 'publishrank' ) ),
					'uj-newsroom-calendar'      => array( 'icon' => 'fa-calendar-days', 'label' => __( 'Calendar', 'publishrank' ), 'desc' => __( 'Scheduled and planned stories by date.', 'publishrank' ) ),
					'uj-newsroom-activity'      => array( 'icon' => 'fa-clock-rotate-left', 'label' => __( 'Activity', 'publishrank' ), 'desc' => __( 'The editorial activity history.', 'publishrank' ) ),
					'uj-newsroom-notifications' => array( 'icon' => 'fa-bell', 'label' => __( 'Notifications', 'publishrank' ), 'desc' => __( 'Your newsroom notifications and alert preferences.', 'publishrank' ) ),
					'uj-newsroom-settings'      => array( 'icon' => 'fa-gears', 'label' => __( 'Newsroom settings', 'publishrank' ), 'desc' => __( 'Workflow stages and newsroom configuration.', 'publishrank' ) ),
					'uj-newsroom-roles'         => array( 'icon' => 'fa-user-shield', 'label' => __( 'Newsroom roles', 'publishrank' ), 'desc' => __( 'Who can do what in the newsroom.', 'publishrank' ) ),
				),
			),
			'operations' => array(
				'label' => __( 'Measure & maintain', 'publishrank' ),
				'items' => array(
					'publishrank-analytics'          => array( 'icon' => 'fa-chart-line', 'label' => __( 'Analytics', 'publishrank' ), 'desc' => __( 'Local publishing trends and connected search performance.', 'publishrank' ) ),
					'publishrank-insights'           => array( 'icon' => 'fa-lightbulb', 'label' => __( 'Insights', 'publishrank' ), 'desc' => __( 'Editorial and site-level signals that suggest next steps.', 'publishrank' ) ),
					'publishrank-seo-redirects'      => array( 'icon' => 'fa-arrow-right-arrow-left', 'label' => __( 'Redirects', 'publishrank' ), 'desc' => __( 'Send visitors from old addresses to the right page.', 'publishrank' ) ),
					'publishrank-seo-404'            => array( 'icon' => 'fa-triangle-exclamation', 'label' => __( '404 monitor', 'publishrank' ), 'desc' => __( 'See which broken links people hit and fix them with a redirect.', 'publishrank' ) ),
					'publishrank-traffic-guard'      => array( 'icon' => 'fa-shield-halved', 'label' => __( 'Traffic Guard', 'publishrank' ), 'desc' => __( 'Optional IP, proxy and country-based access rules.', 'publishrank' ) ),
					'publishrank-wp-hide-login'      => array( 'icon' => 'fa-key', 'label' => __( 'Hide login', 'publishrank' ), 'desc' => __( 'Move the WordPress login page to a custom address.', 'publishrank' ) ),
					'publishrank-seo-config-history' => array( 'icon' => 'fa-clock-rotate-left', 'label' => __( 'Change history', 'publishrank' ), 'desc' => __( 'Recorded SEO setting changes and their earlier values.', 'publishrank' ) ),
					'publishrank-seo-diagnostics'    => array( 'icon' => 'fa-stethoscope', 'label' => __( 'Diagnostics', 'publishrank' ), 'desc' => __( 'Plugin health, compatibility notices and technical checks.', 'publishrank' ) ),
				),
			),
			'help'       => array(
				'label' => __( 'Help', 'publishrank' ),
				'items' => array(
					'publishrank-documentation' => array( 'icon' => 'fa-circle-question', 'label' => __( 'Help & docs', 'publishrank' ), 'desc' => __( 'Plain-language guides to every PublishRank feature.', 'publishrank' ) ),
					'publishrank-onboarding'    => array( 'icon' => 'fa-wand-magic-sparkles', 'label' => __( 'Setup wizard', 'publishrank' ), 'desc' => __( 'Review your core site identity and SEO settings step by step.', 'publishrank' ) ),
				),
			),
		);
		return (array) apply_filters( 'publishrank_seo_shell_groups', $groups );
	}

	/**
	 * Look up one tool and the group it belongs to.
	 *
	 * @return array{icon:string,label:string,desc:string,group:string,group_label:string}|null
	 */
	public static function tool( $slug ) {
		foreach ( self::groups() as $group_id => $group ) {
			if ( isset( $group['items'][ $slug ] ) ) {
				return $group['items'][ $slug ] + array(
					'group'       => (string) $group_id,
					'group_label' => (string) $group['label'],
				);
			}
		}
		return null;
	}

	/** Whether a page slug is wrapped automatically. */
	public static function is_framed( $slug ) {
		$slug = (string) $slug;
		return null !== self::tool( $slug ) && ! in_array( $slug, self::NATIVE, true ) && ! in_array( $slug, self::EXCLUDED, true );
	}

	/** Whether the current admin request shows the shared frame (native or automatic). */
	public static function is_shell_page( $page = null ) {
		if ( null === $page ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$page = (string) $page;
		return in_array( $page, self::NATIVE, true ) || self::is_framed( $page );
	}

	/** Wrap every registered PublishRank tool screen in the shared frame. */
	public function attach_frames() {
		global $submenu;
		if ( empty( $submenu[ Branding::MENU_SLUG ] ) || ! is_array( $submenu[ Branding::MENU_SLUG ] ) || ! function_exists( 'get_plugin_page_hookname' ) ) {
			return;
		}
		foreach ( $submenu[ Branding::MENU_SLUG ] as $item ) {
			if ( ! is_array( $item ) || empty( $item[2] ) ) {
				continue;
			}
			$slug = (string) $item[2];
			if ( ! self::is_framed( $slug ) ) {
				continue;
			}
			$hook = get_plugin_page_hookname( $slug, Branding::MENU_SLUG );
			if ( '' === (string) $hook || has_action( $hook, array( __CLASS__, 'frame_open' ) ) ) {
				continue;
			}
			add_action( $hook, array( __CLASS__, 'frame_open' ), 0 );
			add_action( $hook, array( __CLASS__, 'frame_close' ), PHP_INT_MAX );
		}
	}

	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::is_shell_page( $page ) && Branding::MENU_SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( 'publishrank-fa-core', Branding::asset_url( 'vendor/fontawesome-free-7.3.1/css/fontawesome.css' ), array(), '7.3.1' );
		wp_enqueue_style( 'publishrank-fa-solid', Branding::asset_url( 'vendor/fontawesome-free-7.3.1/css/solid.css' ), array( 'publishrank-fa-core' ), '7.3.1' );
		wp_enqueue_style( 'publishrank-modules', Branding::asset_url( 'css/modules.css' ), array(), self::ver( 'css/modules.css' ) );
		wp_enqueue_style( 'publishrank-shell', Branding::asset_url( 'css/shell.css' ), array( 'publishrank-modules' ), self::ver( 'css/shell.css' ) );
	}

	/** Start buffering an automatically framed screen. */
	public static function frame_open() {
		self::$buffering = true;
		ob_start();
	}

	/** Print the buffered screen inside the shared frame. */
	public static function frame_close() {
		if ( ! self::$buffering ) {
			return;
		}
		self::$buffering = false;
		$html = (string) ob_get_clean();
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo self::frame( $html, $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the screen escaped its own output; the frame escapes its own values.
	}

	/**
	 * Move the screen's header above the frame and put the rest of the screen in the main column.
	 *
	 * @param string $html Screen output.
	 * @param string $page Page slug.
	 */
	public static function frame( $html, $page ) {
		if ( '' === trim( $html ) ) {
			return $html;
		}
		$tool   = self::tool( $page );
		$header = '';
		if ( preg_match( '#<!--pr-header-->(.*?)<!--/pr-header-->#s', $html, $match ) ) {
			$header = $match[1];
			$html   = str_replace( $match[0], '', $html );
		} elseif ( preg_match( '#<h1\b[^>]*>(.*?)</h1>#s', $html, $match ) ) {
			$title = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $match[1] ) ) );
			if ( '' === $title && $tool ) {
				$title = $tool['label'];
			}
			$pos  = strpos( $html, $match[0] );
			$html = substr_replace( $html, '', $pos, strlen( $match[0] ) );
			ob_start();
			AdminAssets::header( $title );
			$header = str_replace( array( '<!--pr-header-->', '<!--/pr-header-->' ), '', (string) ob_get_clean() );
		}

		ob_start();
		echo '<div class="wrap pr-modules-wrap pr-shell-wrap">';
		echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- produced by AdminAssets::header().
		echo '<div class="pr-modules-layout">';
		self::nav( $page );
		echo '<div class="pr-modules-main pr-shell-main">';
		if ( $tool ) {
			self::intro( $tool );
		}
		echo '<div class="pr-shell-content">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the screen.
		echo '</div></div></div>';
		return (string) ob_get_clean();
	}

	/** Title block at the top of the main column. */
	private static function intro( array $tool ) {
		echo '<div class="pr-modules-intro pr-shell-intro"><div class="pr-shell-intro__text">';
		if ( '' !== $tool['group_label'] ) {
			echo '<p class="pr-shell-kicker">' . esc_html( $tool['group_label'] ) . '</p>';
		}
		echo '<h2 class="pr-modules-title">' . esc_html( $tool['label'] ) . '</h2>';
		echo '<p class="pr-modules-lead">' . esc_html( $tool['desc'] ) . '</p></div>';
		echo '<span class="pr-shell-intro__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $tool['icon'] ) . '"></i></span></div>';
	}

	/** Capabilities of the registered PublishRank screens, keyed by slug. */
	private static function registered() {
		global $submenu;
		$caps = array();
		if ( ! empty( $submenu[ Branding::MENU_SLUG ] ) && is_array( $submenu[ Branding::MENU_SLUG ] ) ) {
			foreach ( $submenu[ Branding::MENU_SLUG ] as $item ) {
				if ( is_array( $item ) && isset( $item[1], $item[2] ) ) {
					$caps[ (string) $item[2] ] = (string) $item[1];
				}
			}
		}
		return $caps;
	}

	/**
	 * Print the left navigation.
	 *
	 * @param string $current Current page slug (legacy keys modules, settings, sitemap and help also work).
	 * @param bool   $locked  First-run lock: every screen except the current one is shown but not linked,
	 *                        so people see what unlocks after setup without being bounced back.
	 */
	public static function nav( $current, $locked = false ) {
		$current    = isset( self::KEYS[ $current ] ) ? self::KEYS[ $current ] : (string) $current;
		$registered = self::registered();
		echo '<nav class="pr-modules-nav' . ( $locked ? ' is-locked' : '' ) . '" aria-label="' . esc_attr__( 'PublishRank sections', 'publishrank' ) . '">';
		foreach ( self::groups() as $group_id => $group ) {
			$links = '';
			$has   = false;
			foreach ( $group['items'] as $slug => $item ) {
				// Only list screens that exist on this site and that the current user may open.
				if ( $registered && ( ! isset( $registered[ $slug ] ) || ! current_user_can( $registered[ $slug ] ) ) ) {
					continue;
				}
				$is  = $slug === $current;
				$has = $has || $is;
				if ( $locked && ! $is ) {
					$links .= '<li><span class="pr-nav-locked" aria-disabled="true" title="' . esc_attr__( 'Unlocks when setup is finished', 'publishrank' ) . '"><i class="fa-solid ' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></i><span>' . esc_html( $item['label'] ) . '</span><i class="fa-solid fa-lock pr-nav-locked__lock" aria-hidden="true"></i><span class="screen-reader-text">' . esc_html__( '(unlocks when setup is finished)', 'publishrank' ) . '</span></span></li>';
					continue;
				}
				$links .= '<li><a href="' . esc_url( Branding::page_url( $slug ) ) . '"' . ( $is ? ' class="is-current" aria-current="page"' : '' ) . '><i class="fa-solid ' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></i><span>' . esc_html( $item['label'] ) . '</span></a></li>';
			}
			if ( '' === $links ) {
				continue;
			}
			if ( '' === $group['label'] ) {
				echo '<ul class="pr-nav-main">' . $links . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				continue;
			}
			echo '<details class="pr-nav-group" data-group="' . esc_attr( (string) $group_id ) . '"' . ( $has ? ' open' : '' ) . '>';
			echo '<summary>' . esc_html( $group['label'] ) . '</summary>';
			echo '<ul>' . $links . '</ul></details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '</nav>';
	}

	/** Version-stamped URL args for an asset in assets/. */
	public static function ver( $relative ) {
		$file = PUBLISHRANK_SEO_PATH . 'assets/' . ltrim( $relative, '/' );
		return Branding::version() . '.' . ( file_exists( $file ) ? (string) filemtime( $file ) : '0' );
	}
}
