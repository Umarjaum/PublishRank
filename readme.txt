=== PublishRank ===
Contributors: umarjaum
Tags: seo, sitemap, schema, news, redirects
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 2.9.48
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: publishrank

Free SEO plugin for news publishers with News SEO, XML sitemaps, schema, metadata, redirects and technical SEO tools.

Compatibility target: WordPress 7.1. This package includes a compatibility test plan in docs/WORDPRESS-7.1.3-COMPATIBILITY.md. The plugin remains installable on PHP 7.4+ and passed syntax checks on PHP 8.3.6; PHP 8.5 is the forward compatibility target. Complete staging checks before production use.

== Description ==

PublishRank is a free, open-source WordPress SEO plugin for site owners, bloggers, news publishers, newspapers, magazines and editorial teams. It brings core technical SEO workflows into one publisher-focused plugin and can be evaluated as an alternative to Yoast SEO, Rank Math and other WordPress SEO plugins. Compare the feature set with your current setup before switching; features and workflows are not identical across plugins.

Copyright and project rights: Muhammad Umar Jabbar.

Features include:

* SEO titles, descriptions, canonicals, robots directives, Open Graph and X/Twitter metadata.
* Connected JSON-LD schema for organizations, people, webpages, articles, breadcrumbs and supported media/content types.
* XML sitemaps for posts, pages, taxonomies, authors, images and videos, including a Google News sitemap.
* Redirect management and a local 404 monitor with grouped URL hits, referrer paths, clearable logs, and redirect creation.
* Per-article author schema overrides for author name, job title and image, plus selectable Article, NewsArticle and BlogPosting types.
* Global and per-content robots directives, including index/noindex and follow/nofollow controls, plus configurable robots.txt rules.
* Robots.txt controls, hreflang support and breadcrumbs.
* IndexNow notifications and manual URL submission (optional; sends eligible public URLs to the IndexNow service when enabled).
* Configurable llms.txt and optional llms-full.txt resources.
* Editorial SEO analysis, internal-link recommendations, sitemap diagnostics and WP-CLI commands.
* Publisher Toolkit with permission-aware bulk SEO metadata editing, role-based access for the toolkit, and optional image alt-text assistance that preserves existing descriptions.
* Optional source attribution and optional footer credit, both disabled by default.

PublishRank does not guarantee rankings, crawling, indexing, traffic, or inclusion in Google News. IndexNow is not supported by Google.

== Source code ==
PublishRank includes reviewable PHP, JavaScript and CSS source. The maintained source repository is [github.com/Umarjaum/PublishRank](https://github.com/Umarjaum/PublishRank). All JavaScript and CSS in `assets/` is shipped unminified and human-readable; identical copies are kept in `src/assets/`. There is no build step. The plugin does not download executable assets from third-party CDNs.

== Installation ==

1. Upload the `publishrank` folder to `/wp-content/plugins/`, or install the ZIP through **Plugins → Add New → Upload Plugin**.
2. Activate **PublishRank** from the Plugins screen.
3. Open **PublishRank** in the WordPress dashboard and complete the onboarding steps.
4. Review metadata ownership, schema, sitemap and robots settings before using the plugin on a production site.
5. Submit the sitemap index in your search engine webmaster tools when ready.

== Frequently Asked Questions ==

= Does PublishRank add anything to my page source? =
Only if explicitly enabled. The optional HTML-source attribution comment and visible footer credit are both disabled by default. They are not added to admin screens, feeds, REST/AJAX responses or sitemaps.

= Which articles appear in the News sitemap? =
Published, public, indexable articles from the configured post types whose original publish time is inside the publication window (48 hours by default). Drafts, scheduled, private and password-protected posts, noindex posts and posts you excluded are left out. An empty sitemap is valid.

= How do I mark a live blog? =
Edit the post, open the Schema box, choose "Live Blog Post" as the schema type, and add timestamped updates in the Live coverage panel. Ordinary posts are never marked up as live blogs automatically.

= Is PublishRank free? =
Yes. PublishRank is released under the GPLv2-or-later license. The plugin does not require a paid AI API key for its core functionality. Optional integrations such as IndexNow require an administrator to enable them.

= Is PublishRank an alternative to Yoast SEO or Rank Math? =
PublishRank is another free WordPress SEO plugin option, with a focus on technical SEO and publisher workflows such as News XML sitemaps, redirects, robots controls, schema, and link auditing. It is not a drop-in clone of Yoast SEO or Rank Math, and feature coverage differs. Review compatibility and avoid letting multiple plugins output duplicate titles, canonicals, robots directives or schema.

= Is PublishRank only for news websites? =
No. It is designed with publishers in mind but can also be used on blogs, business websites and other WordPress sites.

= Does PublishRank require a paid API key? =
No. The core plugin runs locally. IndexNow is optional and uses the public IndexNow service when enabled by an administrator.

= Does IndexNow guarantee indexing or submit URLs to Google? =
No. IndexNow requests are shared with participating search engines, but acceptance does not guarantee crawling or indexing. Google does not support IndexNow.

= Can I use PublishRank with another SEO plugin? =
The plugin can detect several SEO plugins, but two plugins outputting titles, canonicals, robots tags or schema at the same time can create duplicates. Configure only one plugin to own each output.

= Does PublishRank add a public credit by default? =
No. Both HTML-source attribution and visible footer attribution are optional and disabled by default. Administrators can explicitly enable either under PublishRank Settings.

= Where can I find documentation and support? =
Visit the [PublishRank website](https://publishrank.netlify.app/) for documentation and support information.

== Privacy ==

The plugin does not send visitor analytics or make remote calls during ordinary frontend rendering. If IndexNow is enabled, PublishRank sends the publisher host, an IndexNow key, the public key-location URL and eligible public URL(s) to `api.indexnow.org`. The plugin also offers a diagnostics scan of the site's own URLs. Review these features and your site's privacy policy before enabling integrations.

== External Services ==

PublishRank integrates optionally with IndexNow at `https://api.indexnow.org/indexnow/`. It connects when an administrator enables IndexNow and eligible content is published, updated, deleted, manually submitted or retried. It sends the site's host, IndexNow key, public key-location URL and submitted public URL(s). IndexNow's terms are available at https://www.indexnow.org/en_gb/terms and Microsoft's privacy statement is at https://privacy.microsoft.com/en-us/privacystatement. IndexNow does not guarantee crawling or indexing and is not supported by Google.

Optional external link check: when an administrator ticks "check external links" while running the Publisher link audit, PublishRank sends HEAD (and, if needed, GET) requests from the site's server to the external URLs already linked in the site's own content, only to see whether they respond. Nothing else is sent, and no data is sent to PublishRank or any other third party. The check never runs automatically.

== Changelog ==
= 2.9.48 =
* Fixed the blank white XML sitemap page: the sitemap stylesheet request ended in a PHP fatal error ("Cannot use output buffering in output buffering display handlers"), so the browser had nothing to render. The head-recovery fallback no longer opens an output buffer inside a buffer callback; it renders at shutdown instead.
* Fixed the same fatal error blanking every front-end page whenever PublishRank's head output was off or missing, which also removed all JSON-LD from those pages.
* The sitemap stylesheet now has the same stale-rewrite-rule safety net as the sitemap XML, discards stray output before the XML declaration and is served as text/xsl.
* Every front-end document now gets a WebPage-family schema node: author archives output ProfilePage, search results SearchResultsPage, 404 pages WebPage, and the "Homepage webpage type" setting is honored on blog-style home pages (previously always CollectionPage).
* Fixed "Run graph scan": the follow-up auto-continue script threw "form.submit is not a function" (WordPress names the button "submit"), leaving the scan stuck at 0% until "Continue graph scan" was clicked by hand.
* Fixed the Graph settings form, which printed settings_fields() and so posted action=update instead of its own admin-post action.
* The content graph and image/video sitemaps now include pages (pages are public but not publicly_queryable, so they were silently excluded).
= 2.9.43 =
* Fixed News sitemap freshness, UTC filtering, XML escaping, cache invalidation, HTTP semantics and diagnostics.
* Added explicit LiveBlogPosting controls, coverage updates, speakable filters and schema graph de-duplication.
* Made optional source attribution explicitly opt-in and documented privacy and external-service boundaries.
