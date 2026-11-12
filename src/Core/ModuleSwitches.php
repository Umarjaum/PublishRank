<?php
/**
 * One place that lists PublishRank's switchable modules and reads/writes their on/off state.
 *
 * Every module maps to the option the feature itself already reads, so a switch flipped here behaves
 * exactly like the checkbox on that feature's own settings screen. The only new option is
 * publishrank_sitemap_enabled (default on), the XML sitemap master switch.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class ModuleSwitches {
	const SITEMAP = 'publishrank_sitemap_enabled';

	/** Modules whose switch changes public URLs, so rewrite rules are refreshed after a change. */
	const REWRITE_MODULES = array( 'sitemap', 'news_sitemap', 'llms', 'bbpress', 'buddypress', 'podcast', 'web_stories', 'hide_login' );

	/**
	 * Module definitions in display order.
	 *
	 * @return array<string,array{option:string,default:bool,icon:string,group:string,page:string,title:string,description:string}>
	 */
	public static function definitions() {
		$modules = array(
			'sitemap'        => array(
				'option'      => self::SITEMAP,
				'default'     => true,
				'icon'        => 'fa-sitemap',
				'group'       => 'discovery',
				'page'        => 'publishrank-sitemap-health',
				'title'       => __( 'XML Sitemap', 'publishrank' ),
				'description' => __( 'Generate XML sitemaps so search engines find and index your content. When off, the WordPress built-in sitemap is used instead.', 'publishrank' ),
			),
			'news_sitemap'   => array(
				'option'      => 'publishrank_seo_news_enabled',
				'default'     => true,
				'icon'        => 'fa-newspaper',
				'group'       => 'discovery',
				'page'        => 'publishrank-sitemap-health',
				'title'       => __( 'Google News Sitemap', 'publishrank' ),
				'description' => __( 'List fresh articles from the last 48 hours in a dedicated News sitemap for Google News.', 'publishrank' ),
			),
			'schema'         => array(
				'option'      => OutputSettings::SCHEMA,
				'default'     => true,
				'icon'        => 'fa-code',
				'group'       => 'discovery',
				'page'        => 'publishrank-schema',
				'title'       => __( 'Schema Markup', 'publishrank' ),
				'description' => __( 'Add structured data to your pages so search results can show rich snippets.', 'publishrank' ),
			),
			'faq'            => array(
				'option'      => OutputSettings::FAQ,
				'default'     => true,
				'icon'        => 'fa-circle-question',
				'group'       => 'discovery',
				'page'        => 'publishrank-schema',
				'title'       => __( 'FAQ Schema', 'publishrank' ),
				'description' => __( 'Add complete question-and-answer pairs, valid FAQPage structured data, and an accessible accordion with [publishrank_faq].', 'publishrank' ),
			),
			'social'         => array(
				'option'      => OutputSettings::META,
				'default'     => true,
				'icon'        => 'fa-share-nodes',
				'group'       => 'content',
				'page'        => 'publishrank',
				'title'       => __( 'Meta & Social Previews', 'publishrank' ),
				'description' => __( 'Output SEO titles, descriptions and Open Graph / X cards that control how posts look in Google, Facebook, X and LinkedIn.', 'publishrank' ),
			),
			'seo_score'      => array(
				'option'      => 'publishrank_article_intel_enabled',
				'default'     => true,
				'icon'        => 'fa-gauge-high',
				'group'       => 'content',
				'page'        => 'publishrank-seo-score',
				'title'       => __( 'Live SEO Score', 'publishrank' ),
				'description' => __( 'Score every article out of 100 while you write, with passed checks and clear fixes in the editor.', 'publishrank' ),
			),
			'internal_links' => array(
				'option'      => 'publishrank_internal_link_enabled',
				'default'     => true,
				'icon'        => 'fa-link',
				'group'       => 'content',
				'page'        => 'publishrank-seo-internal-links',
				'title'       => __( 'Content Graph', 'publishrank' ),
				'description' => __( 'Map article relationships, find orphaned content and review internal-link opportunities before approving them.', 'publishrank' ),
			),
			'breadcrumbs'    => array(
				'option'      => OutputSettings::BREADCRUMBS,
				'default'     => true,
				'icon'        => 'fa-signs-post',
				'group'       => 'content',
				'page'        => 'publishrank-breadcrumbs',
				'title'       => __( 'Breadcrumbs', 'publishrank' ),
				'description' => __( 'Show a breadcrumb trail on your pages so readers and search engines understand your site structure.', 'publishrank' ),
			),
			'redirects'      => array(
				'option'      => 'publishrank_redirect_enabled',
				'default'     => true,
				'icon'        => 'fa-right-left',
				'group'       => 'maintain',
				'page'        => 'publishrank-seo-redirects',
				'title'       => __( 'Redirects', 'publishrank' ),
				'description' => __( 'Manage 301 and 302 redirects to keep the value of moved pages and avoid broken links.', 'publishrank' ),
			),
			'monitor_404'    => array(
				'option'      => 'publishrank_seo_404_monitor',
				'default'     => false,
				'icon'        => 'fa-triangle-exclamation',
				'group'       => 'maintain',
				'page'        => 'publishrank-seo-404',
				'title'       => __( '404 Monitor', 'publishrank' ),
				'description' => __( 'Log broken URLs visitors and crawlers hit, then fix them with a redirect in one click.', 'publishrank' ),
			),
			'robots'         => array(
				'option'      => 'publishrank_robots_enabled',
				'default'     => true,
				'icon'        => 'fa-robot',
				'group'       => 'discovery',
				'page'        => 'publishrank-seo-robots',
				'title'       => __( 'Robots.txt Manager', 'publishrank' ),
				'description' => __( 'Control which crawlers may visit which parts of your site, and list your sitemaps in robots.txt.', 'publishrank' ),
			),
			'llms'           => array(
				'option'      => 'publishrank_llms_txt_enabled',
				'default'     => true,
				'icon'        => 'fa-brain',
				'group'       => 'discovery',
				'page'        => 'publishrank-llms-txt',
				'title'       => __( 'AI & llms.txt', 'publishrank' ),
				'description' => __( 'Publish an llms.txt file that helps AI assistants understand and cite your site.', 'publishrank' ),
			),
			'indexnow'       => array(
				'option'      => 'publishrank_indexnow_enabled',
				'default'     => false,
				'icon'        => 'fa-bolt',
				'group'       => 'discovery',
				'page'        => 'publishrank-indexnow',
				'title'       => __( 'IndexNow', 'publishrank' ),
				'description' => __( 'Notify Bing, Yandex and other IndexNow engines the moment you publish or update a post.', 'publishrank' ),
			),
			'mastodon'       => array(
				'option'      => 'publishrank_mastodon_enabled',
				'default'     => false,
				'icon'        => 'fa-comment-dots',
				'group'       => 'content',
				'page'        => 'publishrank-mastodon',
				'title'       => __( 'Mastodon', 'publishrank' ),
				'description' => __( 'Verify your Mastodon profile (green check), credit authors in link previews, and share new posts to Mastodon automatically.', 'publishrank' ),
			),
			'hide_login'     => array(
				'option'      => \PublishRankSEO\Security\WPHideLogin::OPTION,
				'default'     => true,
				'icon'        => 'fa-key',
				'group'       => 'security',
				'page'        => 'publishrank-wp-hide-login',
				'title'       => __( 'Hide Login', 'publishrank' ),
				'description' => __( 'Move the WordPress login page to a private address and hide wp-login.php and wp-admin from visitors who are not signed in. Switch off to restore the standard login.', 'publishrank' ),
			),

			'podcast'        => array(
				'option'      => 'publishrank_integration_podcast',
				'default'     => true,
				'icon'        => 'fa-podcast',
				'group'       => 'integrations',
				'page'        => 'publishrank-schema',
				'title'       => __( 'Podcast SEO', 'publishrank' ),
				'description' => __( 'Podcast episode structured data, audio share tags and an episodes sitemap. Works with Seriously Simple Podcasting, PowerPress, Podlove, or any post with an audio file.', 'publishrank' ),
			),
			'web_stories'    => array(
				'option'      => 'publishrank_integration_web_stories',
				'default'     => true,
				'icon'        => 'fa-mobile-screen',
				'group'       => 'integrations',
				'page'        => 'publishrank-sitemap-health',
				'title'       => __( 'Web Stories', 'publishrank' ),
				'description' => __( 'Web Stories sitemap, PublishRank titles, social tags and schema on story pages, and large image previews for Google Discover. Needs the Web Stories plugin.', 'publishrank' ),
			),
			'bbpress'        => array(
				'option'      => 'publishrank_integration_bbpress',
				'default'     => true,
				'icon'        => 'fa-comments',
				'group'       => 'integrations',
				'page'        => 'publishrank-sitemap-health',
				'requires'    => 'bbpress',
				'title'       => __( 'bbPress Forums', 'publishrank' ),
				'description' => __( 'Forum and topic sitemap, discussion-forum rich results for topics, and noindex for thin forum pages (single replies, search, profile tabs). Needs the bbPress plugin.', 'publishrank' ),
			),
			'buddypress'     => array(
				'option'      => 'publishrank_integration_buddypress',
				'default'     => true,
				'icon'        => 'fa-users',
				'group'       => 'integrations',
				'page'        => 'publishrank-sitemap-health',
				'requires'    => 'buddypress',
				'title'       => __( 'BuddyPress Community', 'publishrank' ),
				'description' => __( 'Sitemap of public groups and member profiles, profile-page structured data, and noindex for activity, settings and messages screens. Needs the BuddyPress plugin.', 'publishrank' ),
			),
		);
		/**
		 * Filters the modules shown on the Features screen.
		 *
		 * @param array $modules Module definitions keyed by module id.
		 */
		$filtered = apply_filters( 'publishrank_module_switches', $modules );
		return is_array( $filtered ) ? $filtered : $modules;
	}

	/** Translated group labels, in display order. */
	public static function groups() {
		return array(
			'discovery' => __( 'Search & discovery', 'publishrank' ),
			'content'   => __( 'Content & social', 'publishrank' ),
			'security'  => __( 'Security', 'publishrank' ),
			'maintain'  => __( 'Maintenance', 'publishrank' ),
			'integrations' => __( 'Integrations', 'publishrank' ),
		);
	}


	/**
	 * Host plugins that locked integrations need. Each entry: display name, detection, plugin file slugs to look for.
	 *
	 * @return array<string,array{name:string,files:string[]}>
	 */
	private static function requirements() {
		return array(
			'bbpress'    => array(
				'name'  => 'bbPress',
				'files' => array( 'bbpress/bbpress.php' ),
			),
			'buddypress' => array(
				'name'  => 'BuddyPress',
				'files' => array( 'buddypress/bp-loader.php' ),
			),
		);
	}

	/** Whether the host plugin of a requirement is active right now. */
	private static function requirement_active( $need ) {
		if ( 'bbpress' === $need ) {
			return class_exists( 'bbPress' ) || function_exists( 'bbpress' );
		}
		if ( 'buddypress' === $need ) {
			return function_exists( 'buddypress' ) || class_exists( 'BuddyPress' );
		}
		return true;
	}

	/**
	 * Lock state of a module: empty when it is usable, otherwise why it is locked.
	 *
	 * @return array{plugin:string,installed:bool}|null Null when the module is not locked.
	 */
	public static function lock( $key ) {
		$modules = self::definitions();
		if ( ! is_string( $key ) || ! isset( $modules[ $key ] ) || empty( $modules[ $key ]['requires'] ) ) {
			return null;
		}
		$need  = (string) $modules[ $key ]['requires'];
		$known = self::requirements();
		if ( ! isset( $known[ $need ] ) || self::requirement_active( $need ) ) {
			return null;
		}
		$installed = false;
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			$plugins = get_plugins();
			foreach ( $known[ $need ]['files'] as $file ) {
				if ( isset( $plugins[ $file ] ) ) {
					$installed = true;
				}
			}
		}
		return array(
			'plugin'    => $known[ $need ]['name'],
			'installed' => $installed,
		);
	}

	/** Whether a module is locked because its host plugin is missing. */
	public static function is_locked( $key ) {
		return null !== self::lock( $key );
	}

	/** Whether a module id exists. */
	public static function exists( $key ) {
		$modules = self::definitions();
		return is_string( $key ) && isset( $modules[ $key ] );
	}

	/** Whether a module is on. Unknown modules are off. */
	public static function is_on( $key ) {
		$modules = self::definitions();
		if ( ! is_string( $key ) || ! isset( $modules[ $key ] ) ) {
			return false;
		}
		return OutputSettings::is_on( $modules[ $key ]['option'], ! empty( $modules[ $key ]['default'] ) );
	}

	/** Whether the PublishRank XML sitemap is on (default on). */
	public static function sitemap_enabled() {
		return OutputSettings::is_on( self::SITEMAP, true );
	}

	/**
	 * Switch a module on or off.
	 *
	 * @param string $key Module id.
	 * @param bool   $on  New state.
	 * @return bool True when the module exists.
	 */
	public static function set( $key, $on ) {
		$modules = self::definitions();
		if ( ! is_string( $key ) || ! isset( $modules[ $key ] ) ) {
			return false;
		}
		if ( self::is_locked( $key ) ) {
			return false; // Locked until the host plugin is installed and active.
		}
		$on      = (bool) $on;
		$changed = self::is_on( $key ) !== $on;
		self::write( $modules[ $key ]['option'], $on );
		if ( $changed && in_array( $key, self::REWRITE_MODULES, true ) ) {
			// Routes are registered on init; flushing on the next request lets the new state register first.
			update_option( 'publishrank_modules_flush_rewrite', 1, false );
		}
		if ( $changed ) {
			/**
			 * Fires after a PublishRank module was switched on or off.
			 *
			 * @param string $key Module id.
			 * @param bool   $on  New state.
			 */
			do_action( 'publishrank_module_toggled', $key, $on );
		}
		return true;
	}

	/**
	 * Store a switch value so it always lands in the database.
	 *
	 * A setting registered with a default makes get_option() return that default while the row is missing; update_option()
	 * then sees "no change" and skips the write. Removing the registered-default filter for the write avoids that.
	 */
	public static function write( $option, $on ) {
		$hook     = 'default_option_' . $option;
		$priority = function_exists( 'has_filter' ) ? has_filter( $hook, 'filter_default_option' ) : false;
		if ( false !== $priority ) {
			remove_filter( $hook, 'filter_default_option', $priority );
		}
		update_option( $option, $on ? 1 : 0 );
		if ( false !== $priority ) {
			add_filter( $hook, 'filter_default_option', $priority, 3 );
		}
	}

	/** Count of modules that are on. */
	public static function active_count() {
		$count = 0;
		foreach ( array_keys( self::definitions() ) as $key ) {
			if ( self::is_on( $key ) && ! self::is_locked( $key ) ) {
				++$count;
			}
		}
		return $count;
	}

	/** Refresh rewrite rules once after a URL-changing module was toggled. Hooked late on init. */
	public static function maybe_flush_rewrite_rules() {
		if ( ! get_option( 'publishrank_modules_flush_rewrite', 0 ) ) {
			return;
		}
		delete_option( 'publishrank_modules_flush_rewrite' );
		flush_rewrite_rules( false );
	}
}
