<?php
/**
 * Plugin Name: PublishRank
 * Plugin URI: https://publishrank.netlify.app/
 * Description: Advanced SEO, News SEO, XML sitemaps, schema, redirects and robots controls for WordPress publishers.
 * Version: 2.9.69
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Muhammad Umar Jabbar
 * Author URI: https://umarjaum.netlify.app
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: publishrank
 */

defined( 'ABSPATH' ) || exit;

define( 'PUBLISHRANK_SEO_VERSION', '2.9.69' );
define( 'PUBLISHRANK_SEO_FILE', __FILE__ );
define( 'PUBLISHRANK_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'PUBLISHRANK_SEO_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'PublishRankSEO\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		// Keep autoloading O(1): resolve the expected class path directly. Never
		// recursively scan/read every source file during a live request.
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI && is_readable( __DIR__ . '/src/CLI/PublishRankCommand.php' ) ) {
	require_once __DIR__ . '/src/CLI/PublishRankCommand.php';
}

register_activation_hook(
	__FILE__,
	static function () {
		if ( class_exists( '\\PublishRankSEO\\Core\\Installer' ) ) {
			\PublishRankSEO\Core\Installer::activate();
		}
		if ( class_exists( '\\PublishRankSEO\\Indexing\\IndexNow' ) ) {
			\PublishRankSEO\Indexing\IndexNow::activate();
		}
		// Redirect an activating administrator to the first-run setup wizard.
		set_transient( 'publishrank_seo_activation_redirect', 1, 120 );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
			if ( class_exists( '\\PublishRankSEO\\Sitemap\\Engine\\SitemapCursorStore' ) ) {
				\PublishRankSEO\Sitemap\Engine\SitemapCursorStore::unregister();
			}
			if ( class_exists( '\\PublishRankSEO\\Monitor\\ScheduledAudit' ) ) {
				\PublishRankSEO\Monitor\ScheduledAudit::clear_schedule();
			}
			wp_clear_scheduled_hook( 'publishrank_daily_analytics_snapshot' );
			flush_rewrite_rules();
	}
);


/** Add PublishRank branding and shortcuts to the WordPress toolbar. */
function publishrank_seo_admin_toolbar( $wp_admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) || ! is_object( $wp_admin_bar ) ) {
		return;
	}

	$icon_url = add_query_arg( 'ver', PUBLISHRANK_SEO_VERSION, plugins_url( 'assets/images/publishrank-toolbar-icon-v2.png', __FILE__ ) );
	$icon     = '<img class="publishrank-toolbar-icon" src="' . esc_url( $icon_url ) . '" alt="" width="18" height="18" /><span class="ab-label">' . esc_html__( 'PublishRank', 'publishrank' ) . '</span>';
	$dashboard = admin_url( 'admin.php?page=publishrank-seo-dashboard' );
	$settings  = admin_url( 'admin.php?page=publishrank' );

	// Add a branded entry to the top-left WordPress logo dropdown.
	$wp_admin_bar->add_node(
		array(
			'id'     => 'publishrank-wp-logo',
			'parent' => 'wp-logo',
			'title'  => esc_html__( 'PublishRank', 'publishrank' ),
			'href'   => $dashboard,
			'meta'   => array( 'class' => 'publishrank-wp-logo-item' ),
		)
	);

	// Add PublishRank to the horizontal admin toolbar with useful shortcuts.
	$wp_admin_bar->add_node(
		array(
			'id'    => 'publishrank-seo-toolbar',
			'title' => $icon,
			'href'  => $dashboard,
			'meta'  => array( 'class' => 'publishrank-toolbar-root', 'title' => esc_attr__( 'PublishRank shortcuts', 'publishrank' ) ),
		)
	);
		$items = array(
			'dashboard'  => array( __( 'Dashboard', 'publishrank' ), $dashboard ),
			'settings'   => array( __( 'Settings', 'publishrank' ), $settings ),
			'setup'      => array( __( 'Setup Wizard', 'publishrank' ), admin_url( 'admin.php?page=publishrank-onboarding' ) ),
			'seo-score'  => array( __( 'SEO Score', 'publishrank' ), admin_url( 'admin.php?page=publishrank-seo-score' ) ),
			'expertise'  => array( __( 'Author Expertise', 'publishrank' ), admin_url( 'admin.php?page=publishrank-author-expertise' ) ),
			'sitemaps'   => array( __( 'Sitemap Health', 'publishrank' ), admin_url( 'admin.php?page=publishrank-sitemap-health' ) ),
			'diagnostics'=> array( __( 'Diagnostics', 'publishrank' ), admin_url( 'admin.php?page=publishrank-seo-diagnostics' ) ),
			'schema'     => array( __( 'Schema', 'publishrank' ), admin_url( 'admin.php?page=publishrank-schema' ) ),
			'redirects'  => array( __( 'Redirects', 'publishrank' ), admin_url( 'admin.php?page=publishrank-seo-redirects' ) ),
			'robots'     => array( __( 'Robots.txt', 'publishrank' ), admin_url( 'admin.php?page=publishrank-seo-robots' ) ),
			'insights'   => array( __( 'Insights', 'publishrank' ), admin_url( 'admin.php?page=publishrank-insights' ) ),
			'internal-links' => array( __( 'Internal Links', 'publishrank' ), admin_url( 'admin.php?page=publishrank-seo-internal-links' ) ),
			'link-intelligence' => array( __( 'Link Intelligence', 'publishrank' ), admin_url( 'admin.php?page=publishrank-link-intelligence' ) ),
			'llms'       => array( __( 'AI & llms.txt', 'publishrank' ), admin_url( 'admin.php?page=publishrank-llms-txt' ) ),
			'indexnow'   => array( __( 'IndexNow', 'publishrank' ), admin_url( 'admin.php?page=publishrank-indexnow' ) ),
			'image-optimizer' => array( __( 'Image Optimizer', 'publishrank' ), admin_url( 'admin.php?page=publishrank-image-optimizer' ) ),
			'toolkit'    => array( __( 'Publisher Toolkit', 'publishrank' ), admin_url( 'admin.php?page=publishrank-publisher-toolkit' ) ),
			'404'        => array( __( '404 Monitor', 'publishrank' ), admin_url( 'admin.php?page=publishrank-seo-404' ) ),
			'config-history' => array( __( 'Configuration History', 'publishrank' ), admin_url( 'admin.php?page=publishrank-seo-config-history' ) ),
			'documentation' => array( __( 'Documentation & Help', 'publishrank' ), admin_url( 'admin.php?page=publishrank-documentation' ) ),
	);
	$items = apply_filters( 'publishrank_seo_admin_toolbar_items', $items );
	foreach ( (array) $items as $key => $item ) {
		if ( ! is_array( $item ) || ! isset( $item[0], $item[1] ) || ! is_scalar( $item[0] ) || ! is_string( $item[1] ) ) {
			continue;
		}
		$wp_admin_bar->add_node(
			array(
				'id'     => 'publishrank-toolbar-' . $key,
				'parent' => 'publishrank-seo-toolbar',
				'title'  => esc_html( $item[0] ),
				'href'   => esc_url( $item[1] ),
			)
		);
	}
}
add_action( 'admin_bar_menu', 'publishrank_seo_admin_toolbar', 100 );

/** Enqueue the local stylesheet needed to align the admin toolbar shortcut. */
function publishrank_seo_admin_toolbar_styles() {
	if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_enqueue_style(
		'publishrank-seo-admin-toolbar',
		plugins_url( 'assets/css/admin-toolbar.css', __FILE__ ),
		array(),
		PUBLISHRANK_SEO_VERSION . '.' . (int) @filemtime( __DIR__ . '/assets/css/admin-toolbar.css' )
	);
}
add_action( 'admin_enqueue_scripts', 'publishrank_seo_admin_toolbar_styles' );
add_action( 'wp_enqueue_scripts', 'publishrank_seo_admin_toolbar_styles' );

/** Add useful shortcuts to the Installed Plugins screen (including Multisite). */
function publishrank_seo_plugin_action_links( $links ) {
	$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=publishrank' ) ) . '">' . esc_html__( 'Settings', 'publishrank' ) . '</a>';
	$wizard = '<a href="' . esc_url( admin_url( 'admin.php?page=publishrank-onboarding' ) ) . '">' . esc_html__( 'Setup Wizard', 'publishrank' ) . '</a>';
	$sitemap = '<a href="' . esc_url( admin_url( 'admin.php?page=publishrank-sitemap-health' ) ) . '">' . esc_html__( 'Sitemap Health', 'publishrank' ) . '</a>';
	$diagnostics = '<a href="' . esc_url( admin_url( 'admin.php?page=publishrank-seo-diagnostics' ) ) . '">' . esc_html__( 'Diagnostics', 'publishrank' ) . '</a>';
	$support = '<a href="https://publishrank.netlify.app/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support', 'publishrank' ) . '</a>';
	return array_merge( array( $settings, $wizard, $sitemap, $diagnostics, $support ), $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'publishrank_seo_plugin_action_links' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), 'publishrank_seo_plugin_action_links' );

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( '\\PublishRankSEO\\Core\\Plugin' ) ) {
			( new \PublishRankSEO\Core\Plugin() )->boot();
		}
	}
);
