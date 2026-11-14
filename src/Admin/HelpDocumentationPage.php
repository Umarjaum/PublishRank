<?php
/**
 * Help & documentation screen and the contextual "i" help icons on PublishRank screens.
 *
 * The screen uses the shared PublishRank frame (header, left navigation, main column) and
 * presents each topic as a searchable card with a short summary and the full explanation
 * behind a "Read more" toggle.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class HelpDocumentationPage {
	const SLUG = 'publishrank-documentation';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Help & documentation', 'publishrank' ), __( 'Documentation & Help', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'publishrank' ) ) {
			return; }
		$css_file = PUBLISHRANK_SEO_PATH . 'assets/css/help-icons.css';
		$js_file  = PUBLISHRANK_SEO_PATH . 'assets/js/help-icons.js';
		$ver_css  = PUBLISHRANK_SEO_VERSION . '.' . ( file_exists( $css_file ) ? (string) filemtime( $css_file ) : '0' );
		$ver_js   = PUBLISHRANK_SEO_VERSION . '.' . ( file_exists( $js_file ) ? (string) filemtime( $js_file ) : '0' );
		wp_enqueue_style( 'publishrank-inline-help', Branding::asset_url( 'css/help-icons.css' ), array(), $ver_css );
		wp_enqueue_script( 'publishrank-inline-help', Branding::asset_url( 'js/help-icons.js' ), array(), $ver_js, true );
		// Keys are matched against on-screen setting names (longest match wins); each text explains that specific setting.
		$map = array(
			'SEO title'                    => __( 'The title search engines may show for this page. A custom value takes priority over the generated fallback.', 'publishrank' ),
			'Meta description'             => __( 'A concise summary for search snippets and social previews. Leave blank to let PublishRank derive a fallback from the content.', 'publishrank' ),
			'Canonical URL'                => __( 'The preferred URL for this content. Use only when you intentionally need to consolidate duplicate or alternate URLs.', 'publishrank' ),
			'Robots'                       => __( 'Controls whether search engines may index or follow links on eligible pages. Avoid noindex on pages you want searchable.', 'publishrank' ),
			'News sitemap'                 => __( 'Lists recently published eligible news articles for discovery. Inclusion does not guarantee Google News placement or indexing.', 'publishrank' ),
			'Image sitemap'                => __( 'Adds discoverable image URLs associated with eligible public content.', 'publishrank' ),
			'Video sitemap'                => __( 'Provides metadata for eligible embedded or hosted videos when the required video details are available.', 'publishrank' ),
			'Schema type'                  => __( 'Selects the structured-data type that best represents the content. Do not mark up content that is not actually present.', 'publishrank' ),
			'Schema'                       => __( 'Structured data describing your organization, articles, authors, breadcrumbs and related entities. Validate the rendered output after theme changes.', 'publishrank' ),
			'Breadcrumbs'                  => __( 'A hierarchical navigation trail. Check your theme before enabling visible output to avoid duplicate breadcrumbs.', 'publishrank' ),
			'Redirect'                     => __( 'Sends visitors and crawlers from an old URL to a new destination or returns a selected gone status. Check for loops before saving.', 'publishrank' ),
			'IndexNow'                     => __( 'Notifies participating search engines about URL changes. It does not guarantee indexing and is not a Google indexing API.', 'publishrank' ),
			'Publisher'                    => __( 'Your publication identity, logo, locale and social profiles used by relevant metadata and structured data.', 'publishrank' ),
			'Cache'                        => __( 'Clears or refreshes generated SEO/sitemap data. Site, plugin, CDN and host caches may also need to be purged.', 'publishrank' ),
			'404'                          => __( 'Records a privacy-conscious, bounded history of normalized not-found paths. It groups browser, mobile and crawler hits, removes query strings from referrers, ignores common asset and infrastructure probes, applies rate limiting and a short write lock, and retains at most 500 entries for 90 days.', 'publishrank' ),
			'Import'                       => __( 'Loads supported PublishRank settings. Review imported values before applying them; secrets may be deliberately excluded.', 'publishrank' ),
			'Export'                       => __( 'Downloads supported configuration for backup or migration. Keep exported files private because they may contain site configuration.', 'publishrank' ),
			'Keyphrase'                    => __( 'An editorial focus phrase used by content analysis. It is a writing aid, not a ranking guarantee.', 'publishrank' ),
			'Orphan threshold'             => __( 'Content with this many inbound internal links or fewer is reported as an orphan. 0 reports only pages nothing links to; 1 also reports pages with a single link.', 'publishrank' ),
			'Language / regional URL map'  => __( 'One locale=URL per line. PublishRank uses it to output hreflang alternates, so search engines can show the right language or regional version.', 'publishrank' ),
			'Mirror map on every page'     => __( 'Repeats the same language map on every page instead of only on matching pages. Use it when your translated site has no page-by-page equivalents.', 'publishrank' ),
			'Multilingual and international SEO' => __( 'Turns on hreflang output for language and regional versions of your site. This is separate from internal linking and link health tools.', 'publishrank' ),
			'SEO plugin ownership handoff' => __( 'Lets another SEO plugin keep control of overlapping output (metadata, hreflang, page JSON-LD) so PublishRank does not print it twice.', 'publishrank' ),
		);
		wp_localize_script(
			'publishrank-inline-help',
			'publishrankHelp',
			array(
				'map'  => $map,
				'i18n' => array(
					/* translators: %s: setting name. */
					'about' => __( 'More information about %s', 'publishrank' ),
				),
			)
		);

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === $page ) {
			wp_enqueue_script( 'publishrank-docs', Branding::asset_url( 'js/docs.js' ), array(), AdminShell::ver( 'js/docs.js' ), true );
			wp_localize_script(
				'publishrank-docs',
				'publishrankDocs',
				array(
					/* translators: 1: topics shown, 2: total topics. */
					'count' => __( 'Showing %1$d of %2$d topics', 'publishrank' ),
					/* translators: %d: total topics. */
					'all'   => __( '%d topics', 'publishrank' ),
				)
			);
		}
	}

	/** Topic categories, in display order. */
	public static function categories() {
		return array(
			'start'      => array( 'label' => __( 'Getting started', 'publishrank' ), 'icon' => 'fa-flag-checkered' ),
			'appearance' => array( 'label' => __( 'Search appearance', 'publishrank' ), 'icon' => 'fa-heading' ),
			'discovery'  => array( 'label' => __( 'Sitemaps & indexing', 'publishrank' ), 'icon' => 'fa-sitemap' ),
			'content'    => array( 'label' => __( 'Content & newsroom', 'publishrank' ), 'icon' => 'fa-newspaper' ),
			'maintain'   => array( 'label' => __( 'Maintenance & tools', 'publishrank' ), 'icon' => 'fa-screwdriver-wrench' ),
		);
	}

	/**
	 * Every documentation topic: category, icon, title, one-line summary, full explanation and an optional link.
	 *
	 * @return array<int,array{cat:string,icon:string,title:string,summary:string,body:string,url?:string,link?:string}>
	 */
	public static function topics() {
		$version = Branding::version();
		$topics  = array(
			array(
				'cat'     => 'start',
				'icon'    => 'fa-toggle-on',
				'title'   => __( 'Turning features on or off', 'publishrank' ),
				'summary' => __( 'Use the Features screen to choose which PublishRank tools run on your site.', 'publishrank' ),
				'body'    => __( 'Open PublishRank → Features (previously called SEO Modules). Each card is one feature, such as the XML sitemap, Google News sitemap, schema, social previews, breadcrumbs, redirects or IndexNow. The switch saves instantly: switch a feature off and its output stops straight away; switch it on again and your saved settings come back. Use the Settings link on a card to fine-tune that feature. The same switches appear in a compact form on the Dashboard.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-modules' ),
				'link'    => __( 'Open Features', 'publishrank' ),
			),
			array(
				'cat'     => 'start',
				'icon'    => 'fa-layer-group',
				'title'   => __( 'Everything PublishRank includes', 'publishrank' ),
				/* translators: %s: PublishRank version number. */
				'summary' => sprintf( __( 'A full list of the tools and health checks in version %s.', 'publishrank' ), $version ),
				/* translators: %s: PublishRank version number. */
				'body'    => sprintf( __( 'This inventory for version %s covers every dashboard tool card and Technical Health check. Search & discovery includes Schema studio, Sitemap health, IndexNow, Search Console, Search engines, Google Preferred Sources, Robots.txt, Crawl optimization, Breadcrumbs, Site verification, AI & llms.txt and Schema Aggregator. Content & publishing includes SEO score, SEO Overrides, Google Discover image, Author expertise, Local SEO, Image optimizer, Publisher toolkit, Newsroom, Planning board, Newsroom calendar, Newsroom activity, My notifications, Newsroom configuration, Newsroom roles, Content Graph and Link Health Audit. Measure & maintain includes Publisher analytics, Insights, Redirects, 404 monitor, Traffic Guard, WP Private Login, Configuration history and Diagnostics. Settings & help includes Setup wizard, Features, Settings, Titles & Meta, Import / Export and Help & documentation. Technical Health checks include SEO plugin conflicts, Search engine visibility, Pretty permalinks, HTTPS, News sitemap, Structured data coverage, Publisher logo, Default social image, Robots.txt and JNews compatibility. Some features are optional or permission-gated and external integrations require their own configuration; this list describes what the package provides, not a guarantee that every optional service is active on every site.', 'publishrank' ), $version ),
				'url'     => Branding::page_url( Branding::MENU_SLUG ) . '#pr-dashboard-workspace-title',
				'link'    => __( 'Browse all tools', 'publishrank' ),
			),
			array(
				'cat'     => 'start',
				'icon'    => 'fa-bars',
				'title'   => __( 'Finding your way around', 'publishrank' ),
				'summary' => __( 'Every PublishRank screen shares the same side menu, grouped by task.', 'publishrank' ),
				'body'    => __( 'Every PublishRank screen has the same layout: the page header on top, a side menu on the left and the tool itself on the right. The side menu lists Dashboard, Features, General Settings and Sitemap Hub first, followed by groups for Search & discovery, Content & publishing, Newsroom, Measure & maintain and Help. Select a group name to open or close it; the group of the screen you are on opens automatically. The WordPress sidebar keeps a short list of essentials, and the toolbar entry offers shortcuts to the Dashboard, Newsroom, Titles & Meta, Settings, Setup Wizard and this help screen. The PublishRank mark is the only logo the plugin uses.', 'publishrank' ),
			),
			array(
				'cat'     => 'appearance',
				'icon'    => 'fa-heading',
				'title'   => __( 'Titles, descriptions & canonical URLs', 'publishrank' ),
				'summary' => __( 'How the search title and snippet are chosen, and when to set your own.', 'publishrank' ),
				'body'    => __( 'PublishRank generates title and description fallbacks from WordPress content and templates. A custom title or description should be used when an editor needs a specific search snippet. Canonical URLs identify the preferred public URL; do not point them at unrelated pages.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank' ) . '#pr-settings-titles',
				'link'    => __( 'Edit Titles & Meta', 'publishrank' ),
			),
			array(
				'cat'     => 'appearance',
				'icon'    => 'fa-percent',
				'title'   => __( 'Title & description variables', 'publishrank' ),
				'summary' => __( 'Placeholders such as %%title%% and %%sitename%% that fill in automatically.', 'publishrank' ),
				'body'    => __( 'Dynamic variables work in per-post SEO titles, meta descriptions, social titles, social descriptions and the homepage title and description. Use the “Insert variable” list under each field; it shows every supported variable grouped by Site, Content, Author, Taxonomy, Dates and SEO, with what each returns and what happens when it is empty. Typed tokens look like %%title%% and %%sitename%%; %%sep%% is your configured title separator, %%page%% adds “Page 2” on later pages and %%permalink%% is the canonical URL. The older %focus_keyword% form also works. Variables are replaced once, unknown variables are dropped, and a separator left dangling by an empty value is removed. Results are plain text; they are escaped when printed.', 'publishrank' ),
			),
			array(
				'cat'     => 'appearance',
				'icon'    => 'fa-sliders',
				'title'   => __( 'Output controls & what PublishRank can override', 'publishrank' ),
				'summary' => __( 'Which parts of the page PublishRank controls, and which belong to your theme.', 'publishrank' ),
				'body'    => __( 'Settings > Output controls and Breadcrumbs switch PublishRank metadata, the JSON-LD graph, the Organization/WebSite entities, the visible breadcrumb trail and BreadcrumbList data on or off independently; all are on for new installs and your saved choices are kept on upgrade. Changes to your publication identity apply to every page automatically. PublishRank controls only its own head metadata, JSON-LD and breadcrumb trail: a logo, site title, breadcrumb trail or meta tags that your theme or another plugin hard-codes belong to that theme or plugin. If your theme prints its own breadcrumbs choose "Structured data only"; if it prints a second title or description, disable that in the theme. Pages cached by a caching plugin or CDN need a purge after a change (PublishRank fires the publishrank_seo_output_settings_changed action for cache plugins).', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank' ),
				'link'    => __( 'Open General Settings', 'publishrank' ),
			),
			array(
				'cat'     => 'appearance',
				'icon'    => 'fa-diagram-project',
				'title'   => __( 'Schema & publisher identity', 'publishrank' ),
				'summary' => __( 'Structured data that tells search engines who publishes each story.', 'publishrank' ),
				'body'    => __( 'Organization, NewsArticle, author, breadcrumb and media structured data help describe the publication and its content. Complete the publisher identity and logo fields accurately. Do not add custom JSON-LD that duplicates or contradicts generated entities.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-schema' ),
				'link'    => __( 'Open Schema', 'publishrank' ),
			),
			array(
				'cat'     => 'appearance',
				'icon'    => 'fa-chevron-right',
				'title'   => __( 'Breadcrumb separators', 'publishrank' ),
				'summary' => __( 'Pick the character shown between breadcrumb links.', 'publishrank' ),
				'body'    => __( 'Pick a preset (arrows, angles, slashes and lines, dots, dashes, symbols, spaced variants, a space, a non-breaking space, or none) or type your own up to 10 characters. The separator is plain text, so HTML is not allowed; leading and trailing spaces are kept. It is visual only and never appears in BreadcrumbList structured data.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-breadcrumbs' ),
				'link'    => __( 'Open Breadcrumbs', 'publishrank' ),
			),
			array(
				'cat'     => 'discovery',
				'icon'    => 'fa-sitemap',
				'title'   => __( 'XML, News, image & video sitemaps', 'publishrank' ),
				'summary' => __( 'The files that help search engines find every story you publish.', 'publishrank' ),
				'body'    => __( 'The sitemap index links to the enabled sitemap types. News sitemaps focus on eligible recent articles; image and video sitemaps help describe media attached to eligible content. Check Sitemap Hub after changing permalink, sitemap or cache settings. A sitemap is a discovery hint, not an indexing guarantee.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-sitemap-health' ),
				'link'    => __( 'Open Sitemap Hub', 'publishrank' ),
			),
			array(
				'cat'     => 'discovery',
				'icon'    => 'fa-eye',
				'title'   => __( 'Reading your sitemap in a browser', 'publishrank' ),
				'summary' => __( 'What the friendly sitemap page shows and how to read it.', 'publishrank' ),
				'body'    => __( 'Open your sitemap index in a browser to see a readable overview. The top explains what the file is and gives you the one address to submit, with copy and Search Console shortcuts. Below it, a short three-step strip explains how sitemaps work, four totals show how many addresses, content types and files are listed and when the newest change happened, and the “What’s in your sitemap” list shows one row per content type with its icon, a plain description, the number of addresses, its share of the total and when it last changed. An empty row is normal: it fills up automatically when matching content is published. Open a row to see every address in that file, with filtering, paging and quick checks (URL limit, last-modified dates, HTTPS and news data). Search engines never use this view; they read the plain XML. Use View raw XML to see exactly what crawlers receive. Very large indexes skip per-file URL counts to stay fast.', 'publishrank' ),
				'url'     => \PublishRankSEO\Sitemap\SitemapUrls::index_url(),
				'link'    => __( 'Open your sitemap', 'publishrank' ),
				'new_tab' => true,
			),
			array(
				'cat'     => 'discovery',
				'icon'    => 'fa-robot',
				'title'   => __( 'Robots, archives & indexing controls', 'publishrank' ),
				'summary' => __( 'Decide what search engines may crawl and index.', 'publishrank' ),
				'body'    => __( 'Robots settings influence crawling and indexing signals. Use noindex carefully, especially on article pages and category archives. robots.txt rules are not a substitute for access control and do not guarantee removal from search results.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-seo-robots' ),
				'link'    => __( 'Open Robots.txt', 'publishrank' ),
			),
			array(
				'cat'     => 'discovery',
				'icon'    => 'fa-bolt',
				'title'   => __( 'IndexNow', 'publishrank' ),
				'summary' => __( 'Tell Bing and other IndexNow engines as soon as a story changes.', 'publishrank' ),
				'body'    => __( 'IndexNow can notify supported participating search engines about URL changes after you enable it and configure its verification key. Notifications do not guarantee indexing, and Google does not use IndexNow.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-indexnow' ),
				'link'    => __( 'Open IndexNow', 'publishrank' ),
			),
			array(
				'cat'     => 'discovery',
				'icon'    => 'fa-earth-asia',
				'title'   => __( 'Baidu URL submission', 'publishrank' ),
				'summary' => __( 'Send new addresses to Baidu from a verified site.', 'publishrank' ),
				'body'    => __( 'Baidu requires a verified site and its current site-specific ordinary-inclusion endpoint. PublishRank stores that HTTPS endpoint encrypted, limits requests to the same host and batches up to 2,000 URLs. Baidu quota and eligibility vary; acceptance is not an indexing guarantee. Baidu dashboard analytics are not exposed through a documented public read API.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-baidu' ),
				'link'    => __( 'Open Search engines', 'publishrank' ),
			),
			array(
				'cat'     => 'discovery',
				'icon'    => 'fa-magnifying-glass-chart',
				'title'   => __( 'Google Search Console & local analytics', 'publishrank' ),
				'summary' => __( 'Connect Search Console and read your publishing trends.', 'publishrank' ),
				'body'    => __( 'The optional Search Console connection uses Google OAuth webmasters.readonly, requires a Google Cloud project with the Search Console API enabled, and stores tokens encrypted at rest. Refreshes are administrator-initiated; data is aggregated and delayed. Local Publisher Analytics is separate and does not track visitors. Never paste OAuth client secrets or tokens into public support requests.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-search-console' ),
				'link'    => __( 'Open Search Console', 'publishrank' ),
			),
			array(
				'cat'     => 'content',
				'icon'    => 'fa-chart-simple',
				'title'   => __( 'Editorial SEO intelligence', 'publishrank' ),
				'summary' => __( 'The live score and checks that help editors before publishing.', 'publishrank' ),
				'body'    => __( 'Article analysis reviews signals such as headline, keyphrase, description, structure, readability, media and links. Treat scores and suggestions as editorial guidance, not a promise of ranking performance. Always prioritize accuracy and reader value.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-seo-score' ),
				'link'    => __( 'Open SEO score', 'publishrank' ),
			),
			array(
				'cat'     => 'content',
				'icon'    => 'fa-newspaper',
				'title'   => __( 'Live blogs, Newsroom & news workflow', 'publishrank' ),
				'summary' => __( 'Live coverage markup and the built-in editorial workflow.', 'publishrank' ),
				'body'    => __( 'Choose Live Blog Post in the Schema box and add timestamped updates to mark a live blog; ordinary posts are never marked up as live blogs automatically. The built-in Newsroom handles story pitches, assignments, workflow stages, a planning board, a calendar and private notes. These are editorial aids and do not change what is published until you publish.', 'publishrank' ),
				'url'     => Branding::page_url( 'uj-newsroom' ),
				'link'    => __( 'Open Newsroom', 'publishrank' ),
			),
			array(
				'cat'     => 'content',
				'icon'    => 'fa-images',
				'title'   => __( 'Images, media & performance', 'publishrank' ),
				'summary' => __( 'Keep images sharp, light and ready for Google Discover.', 'publishrank' ),
				'body'    => __( 'Image optimization settings control supported image-processing behavior. Keep originals or backups according to your workflow, test output with your theme and CDN, and verify that image dimensions and quality remain suitable for publishing.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-image-optimizer' ),
				'link'    => __( 'Open Image optimizer', 'publishrank' ),
			),
			array(
				'cat'     => 'maintain',
				'icon'    => 'fa-arrow-right-arrow-left',
				'title'   => __( 'Redirects & 404 monitoring', 'publishrank' ),
				'summary' => __( 'Fix broken links and send old addresses to the right page.', 'publishrank' ),
				'body'    => __( 'Use permanent redirects for durable URL moves and temporary redirects when the move is temporary. Test the destination and avoid redirect loops/chains. The 404 Monitor is opt-in and records normalized GET/HEAD 404 paths only; admin, AJAX, cron, REST, non-HTML asset and common infrastructure-probe requests are excluded. Hits are aggregated by browser, mobile or crawler category without IP addresses, cookies, request bodies or full query strings. Administrators can search, filter, sort, dismiss, clear and create validated same-site redirects from the monitor. Its 90-day, 500-entry rolling limit, rate limiting and write lock make it a bounded diagnostic aid rather than a complete access log, analytics system or WAF.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-seo-redirects' ),
				'link'    => __( 'Open Redirects', 'publishrank' ),
			),
			array(
				'cat'     => 'maintain',
				'icon'    => 'fa-stethoscope',
				'title'   => __( 'Diagnostics & compatibility', 'publishrank' ),
				'summary' => __( 'Check plugin health and spot clashes with other SEO plugins.', 'publishrank' ),
				'body'    => __( 'Run diagnostics after installation or major configuration changes. Review conflicts with themes and other SEO plugins, then inspect the live page source and sitemap responses. Disable overlapping SEO output in other plugins only after confirming which plugin owns each output.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank-seo-diagnostics' ),
				'link'    => __( 'Run diagnostics', 'publishrank' ),
			),
			array(
				'cat'     => 'maintain',
				'icon'    => 'fa-file-import',
				'title'   => __( 'Settings import/export & configuration history', 'publishrank' ),
				'summary' => __( 'Back up your settings and see what changed and when.', 'publishrank' ),
				'body'    => __( 'Use exports for controlled migration and backups. Review imported settings before relying on them, and protect exported files. Configuration history can help identify changes; it is not a substitute for a full database backup.', 'publishrank' ),
				'url'     => Branding::page_url( 'publishrank' ) . '#pr-settings-portability',
				'link'    => __( 'Open Import / Export', 'publishrank' ),
			),
			array(
				'cat'     => 'maintain',
				'icon'    => 'fa-code-branch',
				'title'   => __( 'Versions, updates & support', 'publishrank' ),
				'summary' => __( 'Where the version number appears and what to do after updating.', 'publishrank' ),
				'body'    => __( 'The plugin version appears on every PublishRank screen header, in the sitemap view footer, in the plugin header and in the readme stable tag; all of them always show the same number. After updating, clear your site, plugin, CDN and browser caches so you see the current admin styles and sitemap stylesheet. If something looks wrong, run Diagnostics and Sitemap Hub first, then check for conflicts with other SEO plugins.', 'publishrank' ),
			),
		);
		return (array) apply_filters( 'publishrank_seo_documentation_topics', $topics );
	}

	/** Common problems and their fixes, shown as an accordion. */
	public static function faq() {
		return array(
			array(
				'q' => __( 'My sitemap shows a blank page or plain text', 'publishrank' ),
				'a' => __( 'Open Settings › Permalinks in WordPress and select Save Changes without changing anything. This refreshes the address rules. Then reload your sitemap.', 'publishrank' ),
			),
			array(
				'q' => __( 'The sitemap page looks unstyled', 'publishrank' ),
				'a' => __( 'Purge your page, CDN and browser caches, then reload. The sitemap design is generated by WordPress at /publishrank-sitemap.xsl, so a caching layer that rewrites or blocks that address can break it.', 'publishrank' ),
			),
			array(
				'q' => __( 'The Google News sitemap is empty', 'publishrank' ),
				'a' => __( 'That is normal when nothing was published recently. It only lists eligible articles from the news window (48 hours by default), and an empty sitemap is still valid.', 'publishrank' ),
			),
			array(
				'q' => __( 'I see duplicate titles or schema in my page source', 'publishrank' ),
				'a' => __( 'Two plugins (or your theme and a plugin) are printing the same thing. Leave only one of them responsible for each output, then purge caches.', 'publishrank' ),
			),
			array(
				'q' => __( 'I changed a setting but my site still shows the old version', 'publishrank' ),
				'a' => __( 'Clear your site, plugin, CDN and browser caches. Cached pages keep showing the previous output until they are refreshed.', 'publishrank' ),
			),
			array(
				'q' => __( 'The PublishRank icon is missing from the menu or toolbar', 'publishrank' ),
				'a' => __( 'Purge caches and confirm that assets/images/publishrank-brand-mark.png exists inside the plugin folder and is readable.', 'publishrank' ),
			),
		);
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$topics     = self::topics();
		$categories = self::categories();
		$counts     = array();
		foreach ( $topics as $topic ) {
			$counts[ $topic['cat'] ] = isset( $counts[ $topic['cat'] ] ) ? $counts[ $topic['cat'] ] + 1 : 1;
		}

		echo '<div class="wrap pr-modules-wrap pr-shell-wrap">';
		AdminAssets::header( __( 'Help & documentation', 'publishrank' ), __( 'Plain-language guides to every PublishRank feature.', 'publishrank' ) );
		AdminAssets::render_flash();
		echo '<div class="pr-modules-layout">';
		AdminShell::nav( self::SLUG );
		echo '<div class="pr-modules-main pr-docs">';

		echo '<div class="pr-modules-intro"><div><p class="pr-shell-kicker">' . esc_html__( 'Help', 'publishrank' ) . '</p><h2 class="pr-modules-title">' . esc_html__( 'Help & documentation', 'publishrank' ) . '</h2>';
		/* translators: %s: PublishRank version number. */
		echo '<p class="pr-modules-lead">' . esc_html( sprintf( __( 'Short answers first, details when you need them. Search for a feature or pick a topic below. This guide covers PublishRank %s.', 'publishrank' ), Branding::version() ) ) . '</p></div>';
		echo '<span class="pr-modules-count">v' . esc_html( Branding::version() ) . '</span></div>';

		// Start here.
		$start = array(
			array( 'n' => '1', 'icon' => 'fa-wand-magic-sparkles', 'title' => __( 'Complete the Setup Wizard', 'publishrank' ), 'text' => __( 'Add your publication name, logo and social profiles once.', 'publishrank' ), 'url' => Branding::page_url( 'publishrank-onboarding' ), 'link' => __( 'Open the wizard', 'publishrank' ) ),
			array( 'n' => '2', 'icon' => 'fa-toggle-on', 'title' => __( 'Choose your features', 'publishrank' ), 'text' => __( 'Switch on the tools you want, such as the News sitemap or redirects.', 'publishrank' ), 'url' => Branding::page_url( 'publishrank-modules' ), 'link' => __( 'Open Features', 'publishrank' ) ),
			array( 'n' => '3', 'icon' => 'fa-paper-plane', 'title' => __( 'Submit your sitemap', 'publishrank' ), 'text' => __( 'Copy one address into Google Search Console and Bing.', 'publishrank' ), 'url' => Branding::page_url( 'publishrank-sitemap-health' ), 'link' => __( 'Open Sitemap Hub', 'publishrank' ) ),
		);
		echo '<section class="pr-docs-start" aria-labelledby="pr-docs-start-title"><h3 id="pr-docs-start-title" class="pr-docs-h">' . esc_html__( 'New here? Start with these three steps', 'publishrank' ) . '</h3><ol class="pr-docs-steps">';
		foreach ( $start as $step ) {
			echo '<li class="pr-docs-step"><span class="pr-docs-step__no" aria-hidden="true">' . esc_html( $step['n'] ) . '</span><span class="pr-docs-step__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $step['icon'] ) . '"></i></span>';
			echo '<strong>' . esc_html( $step['title'] ) . '</strong><p>' . esc_html( $step['text'] ) . '</p>';
			echo '<a href="' . esc_url( $step['url'] ) . '">' . esc_html( $step['link'] ) . ' <span aria-hidden="true">→</span></a></li>';
		}
		echo '</ol></section>';

		// Search and filters.
		echo '<div class="pr-modules-toolbar pr-docs-toolbar">';
		echo '<div class="pr-docs-chips" role="group" aria-label="' . esc_attr__( 'Filter topics', 'publishrank' ) . '">';
		echo '<button type="button" class="pr-docs-chip is-active" data-pr-docs-cat="all" aria-pressed="true">' . esc_html__( 'All topics', 'publishrank' ) . ' <span>' . esc_html( (string) count( $topics ) ) . '</span></button>';
		foreach ( $categories as $cat_id => $cat ) {
			if ( empty( $counts[ $cat_id ] ) ) {
				continue;
			}
			echo '<button type="button" class="pr-docs-chip" data-pr-docs-cat="' . esc_attr( $cat_id ) . '" aria-pressed="false"><i class="fa-solid ' . esc_attr( $cat['icon'] ) . '" aria-hidden="true"></i>' . esc_html( $cat['label'] ) . ' <span>' . esc_html( (string) $counts[ $cat_id ] ) . '</span></button>';
		}
		echo '</div>';
		echo '<label class="screen-reader-text" for="pr-docs-search">' . esc_html__( 'Search help', 'publishrank' ) . '</label>';
		echo '<span class="pr-modules-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="pr-docs-search" placeholder="' . esc_attr__( 'Search help…', 'publishrank' ) . '" autocomplete="off" data-pr-docs-search /></span>';
		echo '</div>';
		echo '<p class="pr-docs-count" data-pr-docs-count role="status" aria-live="polite"></p>';

		// Topics grouped by category.
		foreach ( $categories as $cat_id => $cat ) {
			if ( empty( $counts[ $cat_id ] ) ) {
				continue;
			}
			echo '<section class="pr-docs-section" data-pr-docs-section="' . esc_attr( $cat_id ) . '" aria-labelledby="pr-docs-cat-' . esc_attr( $cat_id ) . '">';
			echo '<h3 class="pr-docs-h" id="pr-docs-cat-' . esc_attr( $cat_id ) . '"><i class="fa-solid ' . esc_attr( $cat['icon'] ) . '" aria-hidden="true"></i>' . esc_html( $cat['label'] ) . '</h3>';
			echo '<div class="pr-docs-grid">';
			foreach ( $topics as $index => $topic ) {
				if ( $cat_id !== $topic['cat'] ) {
					continue;
				}
				$id = 'pr-doc-' . (int) $index;
				echo '<article class="pr-doc" data-pr-doc data-cat="' . esc_attr( $cat_id ) . '" data-search="' . esc_attr( strtolower( wp_strip_all_tags( $topic['title'] . ' ' . $topic['summary'] . ' ' . $topic['body'] ) ) ) . '" aria-labelledby="' . esc_attr( $id ) . '">';
				echo '<span class="pr-doc__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $topic['icon'] ) . '"></i></span>';
				echo '<div class="pr-doc__body"><h4 class="pr-doc__title" id="' . esc_attr( $id ) . '">' . esc_html( $topic['title'] ) . '</h4>';
				echo '<p class="pr-doc__summary">' . esc_html( $topic['summary'] ) . '</p>';
				echo '<details class="pr-doc__more"><summary>' . esc_html__( 'Read more', 'publishrank' ) . '</summary><p>' . esc_html( $topic['body'] ) . '</p></details>';
				if ( ! empty( $topic['url'] ) && ! empty( $topic['link'] ) ) {
					$new_tab = ! empty( $topic['new_tab'] );
					echo '<a class="pr-doc__link" href="' . esc_url( $topic['url'] ) . '"' . ( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $topic['link'] ) . ' <i class="fa-solid ' . ( $new_tab ? 'fa-arrow-up-right-from-square' : 'fa-arrow-right' ) . '" aria-hidden="true"></i></a>';
				}
				echo '</div></article>';
			}
			echo '</div></section>';
		}
		echo '<p class="pr-modules-empty" data-pr-docs-empty hidden>' . esc_html__( 'No topics match your search. Try a different word, or check the common problems below.', 'publishrank' ) . '</p>';

		// Troubleshooting.
		echo '<section class="pr-docs-faq" aria-labelledby="pr-docs-faq-title"><h3 class="pr-docs-h" id="pr-docs-faq-title"><i class="fa-solid fa-life-ring" aria-hidden="true"></i>' . esc_html__( 'Common problems & quick fixes', 'publishrank' ) . '</h3>';
		foreach ( self::faq() as $item ) {
			echo '<details class="pr-faq"><summary>' . esc_html( $item['q'] ) . '</summary><p>' . esc_html( $item['a'] ) . '</p></details>';
		}
		echo '</section>';

		// Good to know and support.
		echo '<div class="pr-docs-foot">';
		echo '<div class="pr-docs-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><p><strong>' . esc_html__( 'Good to know:', 'publishrank' ) . '</strong> ' . esc_html__( 'Values you type yourself always win over automatic ones. Your theme, caching, multilingual plugins and other SEO plugins can also change what appears on a page, so keep only one plugin in charge of each output. Look for the blue “i” icons beside settings for quick explanations.', 'publishrank' ) . '</p></div>';
		echo '<div class="pr-docs-support"><strong>' . esc_html__( 'Still stuck?', 'publishrank' ) . '</strong><p>' . esc_html__( 'Online documentation and support are on the official website.', 'publishrank' ) . '</p><a class="button button-primary" href="' . esc_url( Branding::website_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Visit the PublishRank website', 'publishrank' ) . '</a></div>';
		echo '</div>';

		echo '</div></div></div>';
	}
}
