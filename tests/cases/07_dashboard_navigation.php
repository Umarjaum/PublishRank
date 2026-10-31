<?php
/** Grouped admin navigation, direct settings destinations and shared title settings. */

use PublishRankSEO\Admin\DashboardPage;
use PublishRankSEO\Admin\OnboardingPage;
use PublishRankSEO\Admin\SettingsPage;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Plugin;
use PublishRankSEO\Meta\MetaGenerator;

/** Boot the real admin modules once in this test process and return the registered dashboard. */
function pr_dashboard_navigation() {
	static $dashboard = null;
	if ( $dashboard instanceof DashboardPage ) {
		return $dashboard;
	}
	pr_as_admin();
	// The test bootstrap initially loads WordPress as a frontend request. Boot the
	// actual plugin again with an admin screen so its real admin_menu callbacks run.
	unset( $GLOBALS['wp_actions']['publishrank_seo_loaded'] );
	( new Plugin() )->boot();
	do_action( 'admin_menu' );
	global $wp_filter;
	foreach ( (array) $wp_filter['admin_menu']->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof DashboardPage && 'menu' === $callback['function'][1] ) {
				$dashboard = $callback['function'][0];
				return $dashboard;
			}
		}
	}
	pr_fail( 'DashboardPage was not registered by the real plugin boot' );
}

/** Render one existing admin page and return its HTML. */
function pr_admin_page_html( $callback ) {
	ob_start();
	call_user_func( $callback );
	return (string) ob_get_clean();
}

return array(
	'grouped submenu preserves unique registered pages and adds only two direct section links' => function () {
		pr_dashboard_navigation();
		global $menu, $submenu;
		$parent = Branding::MENU_SLUG;
		pr_assert( ! empty( $submenu[ $parent ] ), 'PublishRank submenu exists' );
		$items = $submenu[ $parent ];
		$slugs = array_map(
			static function ( $item ) {
				return (string) $item[2];
			},
			$items
		);
		pr_same( count( $slugs ), count( array_unique( $slugs ) ), 'no duplicate submenu destinations' );
		$top_level = 0;
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && $parent === $item[2] ) {
				++$top_level;
			}
		}
		pr_same( 1, $top_level, 'still exactly one PublishRank top-level menu' );

		$ordered = array(
			$parent,
			'publishrank-seo-score',
			'publishrank-search-console',
			'publishrank-analytics',
			'publishrank',
			'admin.php?page=publishrank#pr-settings-titles',
			'admin.php?page=publishrank#pr-settings-portability',
			'publishrank-onboarding',
			'publishrank-documentation',
		);
		$previous = -1;
		foreach ( $ordered as $slug ) {
			$position = array_search( $slug, $slugs, true );
			pr_assert( false !== $position, 'missing submenu destination: ' . $slug );
			pr_assert( $position > $previous, 'submenu group order is wrong at: ' . $slug );
			$previous = $position;
		}
		foreach ( $items as $item ) {
			pr_assert( is_string( $item[1] ) && '' !== $item[1], 'every destination retains its capability' );
			if ( 0 === strpos( $item[2], 'admin.php?' ) ) {
				pr_same( 'manage_options', $item[1], 'settings deep links retain manage_options' );
				continue;
			}
			$hook = get_plugin_page_hookname( $item[2], $parent );
			pr_assert( false !== has_action( $hook ), 'registered page has no render callback: ' . $item[2] );
		}
		pr_count( 5, 'class="pr-submenu-group"', implode( '', wp_list_pluck( $items, 0 ) ), 'five visual task groups, without extra links' );
	},

	'dashboard and new submenu destinations open existing sections with their anchors' => function () {
		$dashboard = pr_dashboard_navigation();
		$html      = pr_admin_page_html( array( $dashboard, 'render' ) );
		foreach ( array( 'publishrank-crawl-optimization', 'publishrank-wp-hide-login', 'uj-newsroom', 'publishrank#pr-settings-titles', 'publishrank#pr-settings-portability', 'publishrank-llms-txt#pr-schema-aggregator' ) as $destination ) {
			pr_contains( $destination, html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ), 'dashboard destination: ' . $destination );
		}
		$settings = pr_admin_page_html( array( new SettingsPage(), 'page' ) );
		pr_contains( 'id="pr-settings-titles"', $settings );
		pr_contains( 'id="pr-settings-portability"', $settings );
		pr_contains( 'href="#pr-settings-titles"', $settings );
		$llms = pr_admin_page_html( array( new \PublishRankSEO\Admin\LLMSTxtPage(), 'render' ) );
		pr_contains( 'id="pr-schema-aggregator"', $llms );
	},

	'settings and wizard render the same separator catalogue and retain legacy values' => function () {
		pr_dashboard_navigation();
		update_option( 'publishrank_seo_title_separator', '↔' );
		$html = pr_admin_page_html( array( new SettingsPage(), 'page' ) );
		pr_contains( 'name="publishrank_seo_title_separator"', $html );
		pr_contains( 'Current value: ↔', $html, 'legacy/custom saved value remains selectable' );
		foreach ( array( 'home', 'post', 'page', 'archive' ) as $context ) {
			pr_count( 1, 'name="publishrank_seo_title_template_' . $context . '"', $html );
			pr_contains( 'data-target="publishrank_seo_title_template_' . $context . '"', $html, 'existing variable picker is available' );
		}
		$separator_field = null;
		foreach ( OnboardingPage::fields( 5 ) as $field ) {
			if ( 'publishrank_seo_title_separator' === $field['key'] ) {
				$separator_field = $field;
				break;
			}
		}
		pr_assert( is_array( $separator_field ), 'wizard still has its separator field' );
		pr_same( OnboardingPage::title_separator_options(), $separator_field['options'], 'wizard and Settings share one catalogue' );
	},

	'title settings belong to the guarded Settings group and persist through their existing options' => function () {
		pr_dashboard_navigation();
		global $wp_registered_settings;
		$keys = array( 'publishrank_seo_title_separator', 'publishrank_seo_title_template_home', 'publishrank_seo_title_template_post', 'publishrank_seo_title_template_page', 'publishrank_seo_title_template_archive' );
		foreach ( $keys as $key ) {
			pr_assert( isset( $wp_registered_settings[ $key ] ), 'setting is registered: ' . $key );
			pr_same( 'publishrank_seo', $wp_registered_settings[ $key ]['group'], 'options.php manages ' . $key );
		}
		pr_same( 'manage_options', apply_filters( 'option_page_capability_publishrank_seo', 'manage_options' ) );
		update_option( 'publishrank_seo_title_separator', '—' );
		$templates = array(
			'home'    => '%%sitename%% %%page%%',
			'post'    => '%%title%% %%sep%% %%sitename%%',
			'page'    => 'Page %%title%% %%sep%% %%sitename%%',
			'archive' => 'Archive %%title%% %%sep%% %%sitename%%',
		);
		foreach ( $templates as $context => $template ) {
			update_option( 'publishrank_seo_title_template_' . $context, $template );
			pr_same( $template, get_option( 'publishrank_seo_title_template_' . $context ), 'template persists: ' . $context );
		}
		pr_same( '—', get_option( 'publishrank_seo_title_separator' ) );
		$id = pr_make_post( array( 'post_title' => 'Shared title' ) );
		pr_same( 'Shared title — Test Site', ( new MetaGenerator() )->title_for_post( $id ), 'frontend consumes the saved separator and template' );
		pr_assert( ! in_array( 'publishrank_seo_title_separator', OnboardingPage::setup_keys(), true ), 'saving Settings cannot blank the wizard-shared separator' );
	},

	'toolbar keeps essential shortcuts while specialized tools remain in the submenu' => function () {
		pr_dashboard_navigation();
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new WP_Admin_Bar();
		publishrank_seo_admin_toolbar( $bar );
		$nodes = $bar->get_nodes();
		foreach ( array( 'dashboard', 'newsroom', 'titles', 'settings', 'setup', 'help' ) as $key ) {
			pr_assert( isset( $nodes[ 'publishrank-toolbar-' . $key ] ), 'missing toolbar shortcut: ' . $key );
		}
		pr_assert( ! isset( $nodes['publishrank-toolbar-seo-score'] ), 'duplicate specialized toolbar shortcut removed' );
		global $submenu;
		$slugs = wp_list_pluck( $submenu[ Branding::MENU_SLUG ], 2 );
		pr_assert( in_array( 'publishrank-seo-score', $slugs, true ), 'specialized tool remains in the grouped submenu' );
	},
);
