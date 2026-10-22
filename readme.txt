=== PublishRank ===
Contributors: umarjaum
Tags: seo, sitemap, schema, news, redirects
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 2.9.61
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
* Always-on technical source provenance markers; the visible footer credit remains optional and administrator-controlled.

PublishRank does not guarantee rankings, crawling, indexing, traffic, or inclusion in Google News. IndexNow is not supported by Google.

== Source code ==
PublishRank includes reviewable PHP, JavaScript and CSS source. The maintained source repository is [github.com/Umarjaum/PublishRank](https://github.com/Umarjaum/PublishRank). First-party runtime assets in `assets/` are shipped unminified and human-readable, with corresponding first-party sources in `src/assets/`. Font Awesome Free 7.3.1 is bundled locally under `assets/vendor/fontawesome-free-7.3.1/` with its upstream license. There is no build step or third-party asset CDN request.

== Installation ==

1. Upload the `publishrank` folder to `/wp-content/plugins/`, or install the ZIP through **Plugins → Add New → Upload Plugin**.
2. Activate **PublishRank** from the Plugins screen.
3. Open **PublishRank** in the WordPress dashboard and complete the onboarding steps.
4. Review metadata ownership, schema, sitemap and robots settings before using the plugin on a production site.
5. Submit the sitemap index in your search engine webmaster tools when ready.

== Frequently Asked Questions ==

= Does PublishRank add anything to my page source? =
Technical HTML-source provenance markers are always enabled on public HTML pages and cannot be disabled in settings. The visible public-site footer credit is optional and can be turned off by an administrator. Source markers are not added to feeds, REST/AJAX responses or sitemaps. Separately, PublishRank displays a small branded link in the WordPress admin footer.

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
Technical source provenance is disclosed in PublishRank Settings and always remains enabled on public HTML pages. Only the visible footer credit can be disabled under PublishRank Settings.

= Where can I find documentation and support? =
Visit the [PublishRank website](https://publishrank.netlify.app/) for documentation and support information.

== Privacy ==

PublishRank does not include visitor analytics or send publisher content to PublishRank. Core metadata and schema are generated locally. On fresh installs, the visible footer credit, “Powered by PublishRank”, starts enabled and is rendered transparently; an administrator can disable it at any time in Settings. The technical source marker is likewise configurable and does not contain an external link. Optional integrations below make requests only when configured or explicitly run; remote services and OAuth providers may receive the originating server or browser IP address and standard connection metadata. Review the relevant provider policies and your own privacy obligations before enabling an integration.

== Terms and Conditions ==

PublishRank is provided under the GPLv2-or-later license. The plugin does not guarantee rankings, crawling, indexing, traffic, or inclusion in Google News. Technical provenance markers are always present in public HTML source. The clearly disclosed “Powered by PublishRank” footer credit remains optional and can be disabled from the WordPress dashboard.

== External Services ==

**Google Search Console and Google sign-in (optional).** When an administrator selects **Sign in with Google** on the Search Console screen, the browser is sent to the PublishRank sign-in service (a Supabase Edge Function at `pgduryvupdltsqzqoiav.supabase.co`) and from there to Google's OAuth page. PublishRank requests only the `openid`, `email` and `webmasters.readonly` scopes, so no Google OAuth client ID has to be created by the site owner. The service receives this site's admin URL, a random state value and a PKCE challenge; after Google returns, it stores the Google account email, an encrypted refresh token and a hash of a random connection secret, and it verifies that the Google account has a Search Console property for this site's domain before handing over a connection. This site stores only the connection id, the connection secret (encrypted with WordPress salts) and short-lived access tokens; Google refresh tokens and the OAuth client secret never reach the WordPress site. On connection, then hourly (WP-Cron) and when an administrator clicks Refresh, the plugin asks the service for a short-lived access token and sends the selected property, date ranges and search type to the Google Search Console API (daily, hourly, query, page, Discover and Google News totals). A URL Inspection action also sends the entered public page URL, property and WordPress locale. Returned metrics are cached locally. **Disconnect** revokes the grant at Google, deletes the connection at the sign-in service and deletes local cached data. Administrators may instead save their own Google OAuth client under Advanced, in which case tokens are exchanged directly with Google as before. Google may also be opened in the administrator's browser for the optional Rich Results Test, which receives the public URL the administrator chose to test. Google policies: [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy), [Google APIs Terms](https://developers.google.com/terms), and [Google Privacy Policy](https://policies.google.com/privacy). PublishRank's use of information received from Google APIs adheres to the Google API Services User Data Policy, including the Limited Use requirements.

**Supabase Auth and configured identity providers (optional).** PublishRank includes a centrally managed Supabase project URL and public publishable key; site owners do not enter a Supabase key. When an existing WordPress administrator chooses a provider, the browser is redirected to the shared Supabase project and the selected provider, site callback URL and PKCE challenge are sent. The plugin's server exchanges the returned authorization code and verifier with Supabase and requests the authenticated profile. Supabase therefore receives its publishable key, provider choice, authorization code/verifier, and the identity/session data required by Auth. The plugin stores an encrypted session and the linked subject locally; a provider may share profile information such as email according to its settings. Only providers configured in the shared project can work, and each WordPress site's exact callback URL must be in the Supabase Auth redirect allow-list. The additional gate is optional; local SEO tools remain available when it is disabled. If enabled, it gates PublishRank admin screens only and does not replace WordPress login, create WordPress users, or affect the public site. Supabase policies: [Terms of Service](https://supabase.com/terms) and [Privacy Policy](https://supabase.com/privacy). The publisher must also review the selected identity provider's terms and privacy policy.

**IndexNow (optional).** When enabled, eligible public URLs are submitted after supported publish/update/delete events, manual submission, or a retry. The request to `https://api.indexnow.org/indexnow/` includes the site's host, IndexNow key, public key-location URL and submitted URL list. The plugin keeps a bounded local submission history including URLs and response statuses. Acceptance is not proof of crawling or indexing, and Google does not participate in IndexNow. The official [IndexNow Terms of Service](https://www.indexnow.org/terms) identify Yandex, Seznam and Microsoft as sponsors. See their [Yandex Privacy Policy](https://yandex.com/legal/privacy/), [Seznam Privacy Policy](https://o-seznam.cz/ochrana-udaju/) and [Microsoft Privacy Statement](https://privacy.microsoft.com/en-us/privacystatement).

**Baidu Search Resource Platform submission (optional).** An administrator can submit up to 2,000 same-host public URLs manually, or explicitly enable automatic submission for newly published eligible posts. The request is sent over HTTPS to the Baidu endpoint configured by the publisher and includes the submitted URL list; the endpoint URL itself contains the publisher's Baidu site identifier and token. The endpoint is encrypted in the WordPress database; Baidu receives the endpoint credentials and URLs to process the request. A bounded local history stores response counts/status, not the submitted URLs or response body. A submission is not an indexing guarantee. See the [Baidu Search Resource Platform Agreement](https://ziyuan.baidu.com/college/articleinfo?id=1613), [Baidu Privacy Protection Statement](https://www.baidu.com/duty/yinsiquan.html), and [Baidu Disclaimer](https://www.baidu.com/duty/index.html).

**Publisher diagnostics (optional, administrator-triggered).** The live frontend scan requests the site's own homepage, latest article, sitemap index and up to ten same-origin child sitemaps from the site's server and analyzes the returned HTML/XML locally. It does not follow HTTP redirects, and sitemap child URLs on another origin are not requested. The IndexNow key check requests the site's own public key file. Publisher Link Intelligence currently analyzes locally available site data; this release does not perform an external-link availability check or send linked URLs to third parties.

**Traffic Guard (optional, local-only).** When enabled, validated request IPs and configured IP/CIDR and country rules are compared locally. The plugin reads `CF-Connecting-IP`, `X-Forwarded-For`, or `CF-IPCountry` only when the direct network peer is inside an administrator-configured trusted-proxy CIDR. It makes no GeoIP API requests, stores no per-visitor IP address, and sends no Traffic Guard data to PublishRank or another service. Country rules require a trusted proxy that supplies a valid country header; missing or invalid country data is allowed through. Country geolocation is approximate, and this feature is not a WAF or DDoS defense. Administrator requests and key WordPress recovery, API, feed, robots, and sitemap routes are exempt.

Other website links in the administration interface (including the Schema.org vocabulary and links to provider documentation) are ordinary references, not background API integrations. The optional public footer credit, if enabled, links to the PublishRank website. No promotional credit is inserted by default.

== Changelog ==
= 2.9.55 =
* Fixed fallback injection of technical provenance and SEO head output for themes that omit `wp_head()`, without duplicate markers.
* Explicitly flush PublishRank-owned frontend fallback buffers at shutdown when safe, without closing buffers owned by other plugins.
* Added direct-web-access guards to standalone regression scripts and aligned version fixtures/fallbacks with 2.9.55.

= 2.9.54 =
* Made technical source provenance markers always-on and removed the administrator toggle.
* Kept the visible “Powered by PublishRank” footer credit optional and administrator-controlled.
* Updated settings copy and documentation to distinguish source markers from visible footer branding.

= 2.9.52 =
* Added a responsive activation welcome screen with a quick-start guide and clearer legal disclosure for optional public attribution.
* Improved PublishRank toolbar and dashboard menu alignment across desktop, folded-sidebar and mobile admin layouts; shipped a white icon treatment for dark WordPress chrome.
* New one-click **Sign in with Google** for Search Console: no OAuth client ID or secret to create. The site is matched to its Search Console property automatically.
* Live data: Search Console clicks, impressions, 48-hour hourly chart, top queries and articles, Google Discover and Google News totals refresh hourly in the background and update on screen every 15 seconds through WordPress Heartbeat, alongside a newsroom feed (articles published, SEO scores, IndexNow queue, 404s). A Live status bar appears under every PublishRank header.
* Publisher Account now offers Continue with Google first and lists only the sign-in providers that are enabled on the Supabase project.
* Updated the admin visual system to warm editorial paper, deep plum, evergreen and brass, with high-contrast controls and accessible status colors.
* Replaced the dashboard's duplicate action lists with direct-action cards and added a dedicated Traffic Guard workspace.
* Added opt-in local IP/CIDR deny and allow rules plus Cloudflare country blocking. Forwarded IP and country headers are accepted only from explicitly configured trusted proxies; unknown country data fails open, administrator and sitemap/API routes are exempt, and no visitor IP is stored by Traffic Guard.
* Disconnecting Search Console now revokes Google access as well as deleting local data.
* Live Preview in the post editor (block and classic): search snippet with pixel and character meters, Facebook and X cards, every meta tag, the exact JSON-LD (syntax-highlighted, copyable, with a Rich Results Test link) and a checks list. It is built from your unsaved edits by the same code that outputs the page, nothing is saved by previewing, and it refreshes as you type. REST: POST /publishrank/v1/preview/{id} (users who can edit the post).
* Fixed: the article score card now reads the real SEO title, description and focus keyword fields and the block-editor title.
* Fixed: JSON-LD identifiers could mix http and https on sites behind a proxy that does not forward HTTPS, leaving references unresolved. The configured scheme is now kept.
* XML sitemaps: styled index and child pages gain a type switcher, and Topics and Authors now carry a lastmod in the index.
* robots.txt: new SEO-tool crawler group (Ahrefs, Semrush, Majestic, Moz, DataForSEO, BLEXBot, PetalBot) with a one-click preset, optional WordPress housekeeping rules (xmlrpc.php, wp-login.php, ?author= enumeration, REST users list, comment feeds), WooCommerce cart and account rules, and protected paths that keep AI crawlers out of paywalled or members-only areas. Per-crawler groups now repeat the low-value and tracking rules instead of silently dropping them. Each section carries its own explanatory comment.
* llms.txt: password-protected and noindex posts are never listed; About, Contact, Editorial policy, Corrections, Ethics, Team and Privacy pages are detected and listed first; authors, tags, custom post types and a machine-readable resources section (sitemaps, news sitemap, robots.txt, RSS) were added; archives move under the Optional section defined by the format; descriptions use the PublishRank meta description and carry the publish date. llms-full.txt now has per-article metadata (date, update, author, section), authority pages first, a per-article word cap, a clean header and no empty summary line. Both files are cached until content changes and answer conditional requests (ETag, Last-Modified, 304). The settings screen shows a preview with a review of common mistakes (physical file in the web root, credentials in text, size, robots.txt conflicts).

= 2.9.50 =
* Added publisher schema types: ReportageNewsArticle, AnalysisNewsArticle, OpinionNewsArticle, BackgroundNewsArticle, ReviewNewsArticle, SatireNewsArticle, AskPublicNewsArticle and BlogPosting, plus FAQPage, AboutPage, ContactPage, CollectionPage, AudioObject and PodcastEpisode.
* Schema type is now selectable per post, page, category, tag and other taxonomy term (edit screen), plus site-wide defaults for category, tag, other archives, author pages and search results (PublishRank > Schema). FAQ Page takes questions and answers in the schema box and falls back to a standard web page when none are complete, so an empty FAQPage is never published.
* Public attribution is optional and disabled by default. No promotional HTML comment, link or footer credit is forced into public pages; administrators can opt in to version/fingerprint markers or a visible footer credit in Settings.
* Removed the forced head-comment module and its theme-recovery path; the SEO fallback now restores only missing metadata and JSON-LD. Generated sitemap audits verify child references against enabled providers and report duplicate URLs. Expanded the live diagnostics scan to validate actual JSON-LD and check the accessible sitemap index and up to ten same-origin child sitemap endpoints, including News Sitemap date freshness.
* Expanded external-service disclosures for Google OAuth/Search Console, Supabase Auth, IndexNow and Baidu, including transmitted data, request triggers, optionality and official terms/privacy links. Corrected the external-link-audit description; this build does not make external link-check requests.
* Fixed the admin palette runtime asset path; refreshed the dashboard and sitemap viewer with the Aurora violet/teal/gold theme, improved the dashboard review-action contrast, and bundled Font Awesome Free 7.3.1 locally under its upstream licenses.
* Added Font Awesome navigation, onboarding and provider icons. Expanded Supabase sign-in choices to the currently documented providers, including Google, Microsoft/Azure, Bitbucket, Figma, Kakao, Keycloak, WorkOS and X/Twitter.
* Bundled the shared Supabase project URL and public publishable key so publishers do not need to enter project credentials. Kept the identity gate optional for WordPress.org local-first use; OAuth provider credentials and each site's callback URL must still be configured in the shared project before sign-in can complete.
* Robustness: schema and head output are now fail-safe (a PHP error while building them can no longer break the public page); the integrity baseline rebuilds automatically so this update does not trigger a false tamper warning; fixed the stale 2.9.49 fallback version string.
* Fixed the deprecated author query on WordPress 7.1 and made llms.txt routes self-repair when rewrite rules are missing; activation and upgrades register both LLMS routes before flushing.

= 2.9.49 =
* Synchronized the package version metadata across the plugin header, runtime constant, readme stable tag and release checks.
* Removed the standalone local integration harness that invoked shell_exec and hard-coded localhost; it is not required for normal plugin operation.

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
* Earlier versions allowed source attribution to be disabled; v2.9.54 supersedes that behavior with disclosed, always-on technical provenance markers.
