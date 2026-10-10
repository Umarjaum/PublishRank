<?php
/**
 * Plugin Name: PublishRank SEO
 * Plugin URI: https://publishrank.netlify.app/
 * Description: Advanced SEO, News SEO, XML sitemaps, schema, redirects and robots controls for WordPress publishers.
 * Version: 2.9.15
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Muhammad Umar Jabbar
 * Author URI: https://umarjaum.netlify.app
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: publishrank
 */

defined( 'ABSPATH' ) || exit;

define( 'PUBLISHRANK_SEO_VERSION', '2.9.15' );
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

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( '\\PublishRankSEO\\Core\\Plugin' ) ) {
			( new \PublishRankSEO\Core\Plugin() )->boot();
		}
	}
);
