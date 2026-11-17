=== PublishRank ===
Contributors: umarjaum
Tags: seo, sitemap, schema, news, redirects
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 2.25.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: publishrank

WordPress SEO for businesses, online stores, publishers, and websites of every kind.

== Description ==



PublishRank is a free, open-source WordPress SEO toolkit for businesses, online stores, service providers, creators, publishers, newsrooms and websites of every kind. Manage technical SEO and publishing workflows in one WordPress plugin: metadata, structured data, sitemaps, redirects, Local SEO, WooCommerce, analytics, access controls and editorial tools. Compare features and workflows with your current setup before switching; they are not identical across plugins.

Compatibility target: WordPress 7.1. The plugin remains installable on PHP 7.4+; complete staging checks before production use.

Copyright and project rights: Muhammad Umar Jabbar.

Features include:
* **Theme Masking (Maximum):** rewrite theme identifiers in page HTML and served CSS/JavaScript, remove theme credits and source-map references, replace version strings with content hashes, and scan fingerprints as Protected, Partial or Review. Direct requests to guessed static theme-file URLs still require a server rule.
* **Roles & permissions:** role matrix, 8 permission presets, per-user Grant/Deny/Inherit, feature access controls and audit log. Administrators retain full access; non-admin managers cannot remove their own manage-roles permission.
* **Access Shield:** signed live verification report covering private login, user enumeration, XML-RPC, headers, exposed files, PHP in uploads, brute-force lockout, accounts and two-factor; available through the admin screen, REST and WP-CLI. Includes optional breached-password range checks, simultaneous-session limits and idle sign-out.
* **Publishing Desk:** duplicate-headline warnings in block and Classic editors, missed-schedule detection and repair, freshness column, Google News diagnostics and publishing velocity.
* **Site identity synchronization:** sync the PublishRank logo with WordPress Site Logo and Site Icon, plus site title, tagline and description.
* **Advanced SEO & trust checks:** 15 additional checks, including snippet readiness, outdated years, echoed descriptions, weak alt text, unsafe affiliate links, attribution and mixed content.
* **News & What's New:** validated, cached official announcements and release notes with per-user read state and bundled changelog fallback; this feed never installs updates.
* **Large-site performance:** individually rebuilt sitemap shards and request-local option preloading. The report verified 200,015 unique URLs across five shards and measured lower plugin-query counts on its test site; timings are not a host-wide guarantee.

* Features screen (formerly SEO Modules) with instant on/off toggle cards for sitemaps, schema, social metadata, the live SEO score, internal links, breadcrumbs, redirects, the 404 monitor, robots.txt, llms.txt and IndexNow.
* Floating SEO Analysis panel in the editor with a live score ring out of 100, passed checks and items to improve, plus side-by-side Google, Facebook/LinkedIn and X previews.
* SEO titles, descriptions, canonicals, robots directives, Open Graph and X/Twitter metadata.
* Connected JSON-LD schema for organizations, people, webpages, articles, breadcrumbs and supported media/content types.
* XML sitemaps for posts, pages, taxonomies, authors, images and videos, including a Google News sitemap.
* Redirect management and a privacy-conscious local 404 monitor with normalized paths, grouped browser/mobile/crawler hits, referrer paths without query strings, noise suppression for assets and infrastructure probes, throttled and locked bounded writes, 90-day retention, searchable/sortable review, clearable logs, and same-site redirect creation.
* Per-article author schema overrides for author name, job title and image, plus selectable Article, NewsArticle and BlogPosting types.
* Migration & Portability workspace for Yoast SEO, Rank Math, All in One SEO, SEOPress and The SEO Framework, with preflight scans, safe merge/overwrite imports, preserved source metadata and normalized JSON exports.
* Global and per-content robots directives, including index/noindex and follow/nofollow controls, plus configurable robots.txt rules.
* Robots.txt controls, hreflang support and breadcrumbs.
* IndexNow notifications and manual URL submission (optional; sends eligible public URLs to the IndexNow service when enabled).
* Configurable llms.txt and optional llms-full.txt resources, with a public paginated schema-discovery endpoint linked from llms.txt.
* Editorial SEO analysis, internal-link recommendations, sitemap diagnostics and WP-CLI commands.
* Publisher Toolkit with permission-aware bulk SEO metadata editing, role-based access for the toolkit, and optional image alt-text assistance that preserves existing descriptions.
* **Private Login controls** are part of Access Shield, including custom sign-in URL and login-route handling; review the Access Shield settings and test recovery before enabling.
* Always-on technical source provenance markers in page source; they do not add a promotional link or visible footer credit.

PublishRank does not guarantee rankings, crawling, indexing, traffic, or inclusion in Google News. IndexNow is not supported by Google.

== Screenshots ==
1. PublishRank Dashboard screen (version 2.25.0).
2. PublishRank What's New screen (version 2.25.0).
3. PublishRank Theme Masking screen (version 2.25.0).
4. PublishRank Access Shield screen (version 2.25.0).
5. PublishRank Publishing Desk screen (version 2.25.0).
6. PublishRank Roles & Permissions screen (version 2.25.0).
7. PublishRank Features screen (version 2.25.0).
8. PublishRank Analytics screen (version 2.25.0).
9. PublishRank WooCommerce SEO screen (version 2.25.0).
10. PublishRank SEO Score screen (version 2.25.0).
11. PublishRank Sitemap Health screen (version 2.25.0).
12. PublishRank Schema screen (version 2.25.0).
13. PublishRank AI Visibility screen (version 2.25.0).
14. PublishRank Site Navigation screen (version 2.25.0).
15. PublishRank Feeds screen (version 2.25.0).
16. PublishRank Redirects screen (version 2.25.0).
17. PublishRank 404 Monitor screen (version 2.25.0).
18. PublishRank Traffic Guard screen (version 2.25.0).
19. PublishRank Newsroom screen (version 2.25.0).
20. PublishRank Newsroom Board screen (version 2.25.0).
21. PublishRank Local SEO screen (version 2.25.0).
22. PublishRank Settings screen (version 2.25.0).
23. PublishRank Insights screen (version 2.25.0).
24. PublishRank Diagnostics screen (version 2.25.0).
25. PublishRank Search Console screen (version 2.25.0).
26. PublishRank IndexNow screen (version 2.25.0).
27. PublishRank llms.txt screen (version 2.25.0).
28. PublishRank Robots screen (version 2.25.0).
29. PublishRank Breadcrumbs screen (version 2.25.0).
30. PublishRank Mastodon screen (version 2.25.0).
31. PublishRank Configuration History screen (version 2.25.0).
32. PublishRank Documentation screen (version 2.25.0).
== Source code ==
PublishRank includes reviewable PHP, JavaScript and CSS source. The maintained source repository is [github.com/Umarjaum/PublishRank](https://github.com/Umarjaum/PublishRank). First-party runtime assets in `assets/` are shipped unminified and human-readable, with corresponding first-party sources in `src/assets/`. Font Awesome Free 7.3.1 is bundled locally under `assets/vendor/fontawesome-free-7.3.1/` with its upstream license. There is no build step or third-party asset CDN request.

== Installation ==

1. Upload the `publishrank` folder to `/wp-content/plugins/`, or install the ZIP through **Plugins → Add New → Upload Plugin**.
2. Activate **PublishRank** from the Plugins screen.
3. Open **PublishRank** in the WordPress dashboard and complete the onboarding steps.
4. Review metadata ownership, schema, sitemap and robots settings before using the plugin on a production site.
5. Submit the sitemap index in your search engine webmaster tools when ready.

== Documentation ==

* In WordPress: **PublishRank → Documentation & Help** explains titles, variables, output controls, breadcrumbs, sitemaps, schema, robots, redirects, the robust 404 Monitor, IndexNow, Baidu, diagnostics, the admin menu and toolbar, the sitemap browser view, versions and troubleshooting.
* In the package: `docs/features/FEATURES.md` lists every dashboard feature and Technical Health check provided through version 2.10.1. The `docs/` folder also contains guides for sitemaps, branding, admin navigation, the 404 Monitor, troubleshooting, versioning and a WordPress 7.1.3 compatibility checklist.
* Online: [publishrankseo.vercel.app](https://publishrankseo.vercel.app/).

== Frequently Asked Questions ==

= Does PublishRank add anything to my page source? =
Technical HTML-source provenance markers are always enabled on public HTML pages and cannot be disabled in settings. Source markers are not added to feeds, REST/AJAX responses or sitemaps. Separately, PublishRank displays a small branded link in the WordPress admin footer.

= Which articles appear in the News sitemap? =
Published, public, indexable articles from the configured post types whose original publish time is inside the publication window (48 hours by default). Drafts, scheduled, private and password-protected posts, noindex posts and posts you excluded are left out. An empty sitemap is valid.

= How do I mark a live blog? =
Edit the post, open the Schema box, choose "Live Blog Post" as the schema type, and add timestamped updates in the Live coverage panel. Ordinary posts are never marked up as live blogs automatically.

= Which title and description variables are supported? =
The same fifteen variables work in global title templates, per-post SEO titles and per-post meta descriptions: %%title%%, %%sitename%%, %%site_description%%, %%category%%, %%primary_category%%, %%author%%, %%date%%, %%modified%%, %%excerpt%%, %%sep%%, %%page%%, %%id%%, %%permalink%%, %%focus_keyword%% and %%focuskw%%. Unknown variables are removed.

= Can I turn off schema, metadata or breadcrumbs separately? =
Yes. Output controls (Settings) switch metadata, the JSON-LD graph, the Organization/WebSite entities, the visible breadcrumb trail and BreadcrumbList data independently. All are enabled on new installs; existing choices are kept on upgrade. PublishRank only changes its own output: a logo, title, breadcrumbs or meta tags hard-coded by your theme or another plugin are not rewritten. Choose "Structured data only" breadcrumb placement if your theme prints its own trail.

= Known limitations =
A classic theme that hard-codes a <title> tag and does not declare title-tag support can print two titles. Per-post templates that were saved by an older release with %%date%% or %%category%% were stored damaged and must be re-entered. Global meta-description templates are not available; use per-content SEO overrides and the configured description fallbacks instead. Open Graph and X/Twitter title and description overrides support the same registered template variables as SEO titles and descriptions. No plugin can force Google to show sitelinks; Site Navigation SEO only improves the signals. Arabic and Urdu use light, rule-based stemming (not full morphological analysis), and inclusive-language rules are available for English only. WooCommerce product schema is generated by WooCommerce and enriched by PublishRank, so variable-product offers follow WooCommerce's own output.

= What does the XML sitemap page in my browser show? =
A readable view of the same sitemap search engines fetch as plain XML: totals, a content showcase bar with each type's share, one card per content type, and URL tables with filtering for child sitemaps. Use View raw XML to see exactly what crawlers receive. The view never changes the XML.

= How does the 404 Monitor work? =
Turn it on with **Turn on 404 monitoring** on **PublishRank → 404 Monitor** (or in **Features** or **Settings**), then review entries at **PublishRank → 404 Monitor**. The monitor aggregates normalized paths by browser, mobile or crawler category, records hit counts and first/last seen times, and keeps only the latest useful referrer path without storing IP addresses, cookies, request bodies or full query strings. It ignores admin, AJAX, cron, REST, non-GET/HEAD, common static-asset and infrastructure-probe requests. The log is rate-limited, protected against concurrent write loss, retained for 90 days and capped at 500 entries. Administrators can search paths, filter visitor categories, sort by hits or recency, dismiss entries, clear the log, or create validated same-site redirects. It is a bounded diagnostic aid, not a complete access log, analytics system, WAF or DDoS defense.

= Where is the complete feature list? =
The package guide `docs/features/FEATURES.md` is the complete dashboard inventory through version 2.10.1. It includes every card under Search & discovery, Content & publishing, Measure & maintain, and Settings & help, plus every Technical Health check: Schema studio, Sitemap health, IndexNow, Search Console, Search engines, Google Preferred Sources, Robots.txt, Crawl optimization, Breadcrumbs, Site verification, AI & llms.txt, Schema Aggregator, SEO score, SEO Overrides, Google Discover image, Author expertise, Local SEO, Image optimizer, Publisher toolkit, Newsroom, Planning board, Newsroom calendar, Newsroom activity, My notifications, Newsroom configuration, Newsroom roles, Internal links, Link intelligence, Publisher analytics, Insights, Redirects, 404 monitor, Traffic Guard, Private Login, Configuration history, Diagnostics, Setup wizard, Settings, Titles & Meta, Import / Export, Documentation & help, SEO plugin conflicts, Search engine visibility, Pretty permalinks, HTTPS, News sitemap, Structured data coverage, Publisher logo, Default social image, Robots.txt and JNews compatibility.

= Which logo does PublishRank use? =
One file, `assets/images/publishrank-brand-mark.png`, shown in the admin sidebar, toolbar, screen headers, setup wizard and sitemap view. No SVG or alternate icon files are packaged.

= Why does the PublishRank admin dropdown have no custom background? =
It uses WordPress's native submenu colours so it matches every admin colour scheme.

= Is PublishRank free? =
Yes. PublishRank is released under the GPLv2-or-later license. The plugin does not require a paid AI API key for its core functionality. Optional integrations such as IndexNow require an administrator to enable them.

= What happens when I activate PublishRank? =
On first activation, an administrator sees a one-time PublishRank News prompt with **Enable live News**, **No thanks — keep News off**, and **Not now** options. Enabling the feed is the only choice that permits a News request. Declining or dismissing leaves News off; you can enable it later at **PublishRank → News**. If an administrator had already enabled News, that saved choice is preserved.

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

= Does PublishRank add a visible public credit? =
No. PublishRank adds no visible credit to your site footer. Technical source provenance is disclosed in PublishRank Settings and always remains enabled on public HTML pages.

= Where can I find documentation and support? =
Visit the [PublishRank website](https://publishrankseo.vercel.app/) for documentation and support information.

== Privacy ==

PublishRank does not include visitor analytics or send publisher content to PublishRank. Core metadata and schema are generated locally. The technical source marker does not contain an external link. Optional integrations below make requests only when configured or explicitly enabled; remote services and OAuth providers may receive the originating server or browser IP address and standard connection metadata. Review the relevant provider policies and your own privacy obligations before enabling an integration.

== Terms and Conditions ==

PublishRank is provided under the GPLv2-or-later license. The plugin does not guarantee rankings, crawling, indexing, traffic, or inclusion in Google News. Technical provenance markers are present in public HTML source by default and can be hidden under Crawl Optimization.

== External Services ==

**Google Search Console (optional, your own Google OAuth client).** Nothing is sent to Google until an administrator saves their own Google OAuth client ID and secret on the Search Console screen and selects **Sign in with Google**. The administrator's browser is then sent to Google's OAuth page (`accounts.google.com`) and returns to this site's callback URL; the authorization code is exchanged directly between this WordPress server and Google (`oauth2.googleapis.com`) using PKCE. PublishRank requests only the read-only `webmasters.readonly` scope. The client secret and the refresh token are stored encrypted with WordPress salts; no PublishRank-operated server is involved and no Google data is sent to PublishRank. On connection, then hourly (WP-Cron) and when an administrator clicks Refresh, the server asks the Google Search Console API (`www.googleapis.com`) for the verified property list and sends the selected property, date ranges and search type to retrieve daily, hourly, query, page, Discover and Google News totals. A URL Inspection action also sends the entered public page URL, property and WordPress locale. Returned metrics are cached locally. **Disconnect** asks Google to revoke the grant (`oauth2.googleapis.com/revoke`) and deletes the stored credentials and cached data. Google may also be opened in the administrator's browser for the optional Rich Results Test, which receives the public URL the administrator chose to test. Google policies: [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy), [Google APIs Terms](https://developers.google.com/terms), and [Google Privacy Policy](https://policies.google.com/privacy). PublishRank's use of information received from Google APIs adheres to the Google API Services User Data Policy, including the Limited Use requirements.

**PublishRank News & What's New (on by default, can be turned off).** Twice a day (WP-Cron) and when an administrator clicks **Check now**, the server requests the public file `https://publishrankseo.vercel.app/publishrank-feed.json` from the official PublishRank website to show announcements and release notes on the News & What's New screen. The request carries no site information: no site address (a neutral `PublishRank/<version>` User-Agent replaces WordPress's default), no cookies, no user, settings or statistics; only the standard HTTP connection data (such as the server's IP address) reaches the website host, Vercel. HTTPS certificate checks are enforced, redirects are not followed, and only plain text and links to the official website are accepted; the feed cannot run code, change settings or install updates. Turn it off under News & What's New → Settings or define `PUBLISHRANK_DISABLE_NEWS_FEED` in wp-config.php; the changelog bundled with the plugin is then shown. See the [PublishRank privacy policy](https://publishrankseo.vercel.app/privacy) and [Vercel Privacy Policy](https://vercel.com/legal/privacy-policy).

**Have I Been Pwned password check (Access Shield, optional).** When the breached-password check is on, setting a new password for a protected role sends only the first five characters of the password's SHA-1 hash to `https://api.pwnedpasswords.com/range/` (k-anonymity). The password itself, the full hash, the username and the site address are never sent; the comparison happens on your server. If the service cannot be reached, the password is accepted. See the [Have I Been Pwned privacy policy](https://haveibeenpwned.com/Privacy) and [Pwned Passwords API documentation](https://haveibeenpwned.com/API/v3#PwnedPasswords).

**IndexNow (optional).** When enabled, eligible public URLs are submitted after supported publish/update/delete events, manual submission, or a retry. The request to `https://api.indexnow.org/indexnow/` includes the site's host, IndexNow key, public key-location URL and submitted URL list. The plugin keeps a bounded local submission history including URLs and response statuses. Acceptance is not proof of crawling or indexing, and Google does not participate in IndexNow. The official [IndexNow Terms of Service](https://www.indexnow.org/terms) identify Yandex, Seznam and Microsoft as sponsors. See their [Yandex Privacy Policy](https://yandex.com/legal/privacy/), [Seznam Privacy Policy](https://o-seznam.cz/ochrana-udaju/) and [Microsoft Privacy Statement](https://privacy.microsoft.com/en-us/privacystatement).

**Baidu Search Resource Platform submission (optional).** An administrator can submit up to 2,000 same-host public URLs manually, or explicitly enable automatic submission for newly published eligible posts. The request is sent over HTTPS to the Baidu endpoint configured by the publisher and includes the submitted URL list; the endpoint URL itself contains the publisher's Baidu site identifier and token. The endpoint is encrypted in the WordPress database; Baidu receives the endpoint credentials and URLs to process the request. A bounded local history stores response counts/status, not the submitted URLs or response body. A submission is not an indexing guarantee. See the [Baidu Search Resource Platform Agreement](https://ziyuan.baidu.com/college/articleinfo?id=1613), [Baidu Privacy Protection Statement](https://www.baidu.com/duty/yinsiquan.html), and [Baidu Disclaimer](https://www.baidu.com/duty/index.html).

**Publisher diagnostics (optional, administrator-triggered).** The live frontend scan requests the site's own homepage, latest article, sitemap index and up to ten same-origin child sitemaps from the site's server and analyzes the returned HTML/XML locally. It does not follow HTTP redirects, and sitemap child URLs on another origin are not requested. The IndexNow key check requests the site's own public key file. Publisher Link Intelligence analyzes locally available site data. Only when an administrator ticks **Also check external links** and starts a scan, the site's server sends HEAD/GET requests to the external URLs already linked in the site's own content to read their HTTP status; nothing else is sent and no data is shared with PublishRank.

**Traffic Guard (optional, local-only).** When enabled, validated request IPs and configured IP/CIDR and country rules are compared locally. The plugin reads `CF-Connecting-IP`, `X-Forwarded-For`, or `CF-IPCountry` only when the direct network peer is inside an administrator-configured trusted-proxy CIDR. It makes no GeoIP API requests, stores no per-visitor IP address, and sends no Traffic Guard data to PublishRank or another service. Country rules require a trusted proxy that supplies a valid country header; missing or invalid country data is allowed through. Country geolocation is approximate, and this feature is not a WAF or DDoS defense. Administrator requests and key WordPress recovery, API, feed, robots, and sitemap routes are exempt. When a request would be blocked and its User-Agent claims to be a major search-engine crawler, the server performs a reverse DNS lookup of the visiting IP and a forward lookup of the returned host name to confirm it (the verdict is cached under a salted hash, not the IP). These are DNS lookups made by your server, not HTTP requests, and no data is sent to PublishRank.

**Feed validation (administrator-triggered).** The RSS Feeds screen requests the site's own feed URLs from the site's server to check that they are valid XML and to show a preview. No other site is contacted.

Other website links in the administration interface (including the Schema.org vocabulary and links to provider documentation) are ordinary references, not background API integrations. PublishRank's source-provenance markers are technical metadata only; the plugin does not insert a visible footer credit or promotional link.

== Changelog ==

= 2.25.0 =
* **Theme masking, Maximum level.** Theme identifiers are rewritten in page HTML and in the theme's served CSS and JavaScript; theme credits and source-map references are removed and version strings become content hashes. A fingerprint scanner rates each page Protected, Partial or Review. Tested on Astra, Astra child, GeneratePress, Kadence, Blocksy, Neve, OceanWP, Hello Elementor and Twenty Twenty-Five.
* **Feature access rules.** Administrators choose which roles and individual users can use each PublishRank feature; administrators always keep full access.
* **Logo sync.** The PublishRank logo syncs with the WordPress Site Logo and Site Icon, alongside site title, tagline and description.
* **Access Shield live verification.** Probes the site the way an anonymous visitor would (private login, user enumeration, XML-RPC, headers, exposed files, PHP in uploads, brute-force lockout, accounts and two-factor) and produces a signed, tamper-evident report. Admin screen, REST (`/publishrank/v1/shield/verify`) and `wp publishrank shield verify` / `verify-report`.
* **Account protection for privileged roles.** Minimum length and strength rules, optional breached-password check (k-anonymity, see External Services), a limit on simultaneous sign-ins and sign-out after inactivity.
* **Publishing Desk.** Live duplicate-headline warning in the block and Classic editors, missed-schedule detection with automatic publishing, a Freshness column, Google News diagnostics and publishing velocity.
* **Advanced SEO & trust checks.** Fifteen new checks join the single article score (snippet readiness, outdated years, echoed descriptions, weak alt text, empty or unsafe affiliate links, attribution, mixed content and more).
* **News & What's New.** A new screen, dashboard card and dismissible notices with official announcements and release notes from the PublishRank website, read/unread per user, cached with ETag support, and the bundled changelog as fallback. Never installs anything.
* **Large-site performance.** Sitemap shards rebuild individually (verified on 200,000 posts: each URL exactly once, only the changed 50,000-URL shard rebuilt). Small settings load in one query per prefix when no persistent object cache exists (about 96 plugin queries per page down to 2–6 on the test site). Configuration-history snapshots are read only on admin screens.
* **Fixes.** Access Shield self-tests no longer add simulated attacks to the security log; WooCommerce feed test corrected.

= 2.24.0 =
* **WooCommerce SEO, rebuilt.** Always visible in the PublishRank menu (and under WooCommerce), with its own switch on the Features page and a tabbed screen: overview, product data, shipping & returns, templates, store rules, product audit, Merchant feed and preview. The setup wizard no longer blocks it.
* **Richer Google product data.** Checked barcodes (GTIN-8/12/13/14 with check digit), part numbers, brand, condition, colour, size, material, pattern, weight, shipping cost and delivery times, return policy. Variable products become a ProductGroup with one entry per variation; shop and category pages list their products.
* **"PublishRank SEO" product tab** with barcode status, part number, brand, condition, Google category, five campaign labels and "leave out of feed"; a part-number field on each variation; a live product score.
* **Product and category templates** using title, price, brand, SKU, category and stock.
* **Store rules.** Optionally hide sold-out products from Google and the sitemap; a product moved to the bin redirects to its category, and the redirect is removed when it is restored.
* **Product audit** scored out of 100 with filters, search and CSV export. **Merchant Center / Meta catalogue feed** protected by a secret key. REST endpoints and `wp publishrank woo` commands.
* **Real visitor analytics.** A cookie-free first-party tracker (no IP addresses stored, daily-rotating visitor codes, Do Not Track honoured, bots excluded) with brand-coloured charts: visitors, page views, visits, time on site, engagement, pages per visit, traffic sources (search engines incl. Bing/DuckDuckGo, social, AI assistants, email, campaigns), world map, devices, browsers, weekday/hour heatmap, live visitors, 12-month growth and CSV export. Search Console clicks, impressions, CTR, position, top searches and top pages sit next to real search visits.
* **Access Shield analytics.** Login activity over time, successful / failed / blocked / two-factor counts with period comparison, event breakdown, attacks by country, sign-in methods, most targeted usernames, top attacking IPs, recent activity and an attack heatmap.
* **Theme SEO & masking.** Removes duplicate titles, descriptions, canonicals, robots, Open Graph/X tags, keywords and structured data printed by themes or other plugins (only kinds PublishRank already prints), switches off SEO features of popular themes, and can hide the theme: theme files served from a neutral address, theme names removed from classes, IDs, generator tags and style.css. Includes a before/after page scan.
* **Dashboard fixed.** The screen crashed on a removed 404 log call and a removed login constant.
* **WP Private Login removed.** Its settings are carried into Access Shield, which now owns the private login address.


= 2.23.0 =
* Access Shield, AI search visibility (crawler control, verified bot log, Markdown delivery, citation tracker), Schema Library (18 extra types), 404 Monitor v2, Redirect Engine v2, Newsroom v2, verified large-site sitemaps, advanced RSS and JSON Feed, Notification Center, 12-page Site Navigation, granular roles and permissions, multisite-safe install and opt-in data removal. See changelog.txt.

= 2.21.0 =
* **404 Monitor screen.** Turn recording on or off right on the screen (same switch as Features), clear messages when the list is empty or a filter matches nothing, readable visitor badges (Browser, Mobile, Crawler), last and first seen in one column, the referring site's name, a one-line redirect form that needs a target, a confirmation before clearing the whole log, and creating a redirect now clears that address for every visitor type.
* **Movable SEO editor window.** Open the SEO fields, live previews and analysis in one floating window in the block and classic editors. Drag it by its title bar (mouse, touch or arrow keys), resize it, snap it to the left or right, minimize it, and it reopens where you left it. On phones it becomes a bottom sheet. It uses the same fields as the editor, so nothing is saved twice.
* **One SEO score everywhere.** The editor panel, toolbar badge, post list column, SEO Score page, Insights and scheduled audits now read the same score, so a post never shows two different numbers.
* **RSS Feeds screen.** Add author, publisher, category, copyright and custom attribution lines to feed items (with a live preview), publish a recent-news feed at /feed/news/, set the item limit, print feed discovery links only when the theme does not, and send an optional cache header. Every feed can be validated and previewed the way a feed reader shows it. Feed titles no longer repeat the site name ("Daily Beacon | Daily Beacon").
* **Site Navigation SEO.** Mark your important pages (About, Contact, Services, editorial policy, legal pages and more), see for each one whether it is published, indexable, in the sitemap, linked from a menu and from other pages, and has a self canonical and description. The homepage describes them as a SiteNavigationElement list. Shows breadcrumb trails and orphaned content. It cannot force sitelinks; the screen says so.
* **WooCommerce SEO** (only when WooCommerce is active). Product pages are no longer marked up as news articles; WooCommerce's Product schema is linked to the PublishRank graph (one breadcrumb, seller is your organization, brand, condition, gallery images); og:type product with price, currency, availability, SKU and brand; cart, checkout, account pages, sorted or filtered shop listings and hidden products are noindex; products get their own sitemap without hidden products. A product audit and search-result preview show what is missing. While WooCommerce shows its "coming soon" screen to visitors, those pages are noindex and carry no product schema or product Open Graph tags.
* **Redirects.** Import redirects from CSV (PublishRank, Yoast SEO Premium, Rank Math and Redirection exports are recognised), export all rules as CSV, delete several at once, and trace an address to see every hop, chains and loops. Regex redirects now pass captured parts ($1, $2 ...) to the new address.
* **Arabic and Urdu analysis.** The SEO analysis detects English, Arabic and Urdu. Arabic-script text is normalised (diacritics, tatweel, letter variants) and lightly stemmed, so a focus keyword matches with or without "ال" or plural endings. Arabic and Urdu use their own sentence marks, connective words and sentence-length limits; English formulas (Flesch, passive voice) are no longer applied to other languages.
* **Inclusive language (optional).** English suggestions with an explanation and alternatives, configurable rules, and a Dismiss button for each post. It never changes your text and does not affect the score.
* **Classic Editor tip.** An optional, dismissible notice explains that the movable SEO window also works in the Classic Editor. PublishRank never installs or activates other plugins.
* **Setup Wizard logo preview.** Choosing a logo or image from the Media Library shows the preview immediately, saves the image ID and keeps the preview after reload.
* **Private Login.** Settings carried over from older sign-in address plugins are read through a separate migration step and are no longer deleted when another plugin still uses them.
* **Fixes.** Sitemaps added by integrations (bbPress forums, podcasts, Web Stories, products) are now listed and served instead of returning 404. Automatic descriptions keep a space between a heading and the following text. Table headers on newer PublishRank screens are readable, and date fields no longer overflow narrow cards.

= 2.20.20 =
* **Local SEO for every kind of business.** 134 business types across 14 niches, including new Education & child care and Public services & media groups, hospitals, doctors, notaries, ATMs, vacation rentals, ice cream shops, shopping centres, libraries and more.
* **Fields for each business type.** Restaurants get cuisine, menu and reservations; hotels get check-in and check-out, rooms, official star rating and pets; clinics, dentists, pharmacies and vets get medical specialties and new-patient status; banks get a fees page; shops and car dealers get brands; venues get capacity and free entry. They appear only for the types schema.org defines them on.
* **Richer, valid LocalBusiness schema.** Opening hours are published as OpeningHoursSpecification, with holiday and special hours; services become an OfferCatalog; amenities, languages, areas served, payment methods, currencies, booking link (ReserveAction), legal name, founding date, tax ID, logo and photo are supported. Service-area businesses can hide their street address, map and coordinates.
* **Schema fixes.** Veterinary care and schools are now typed with LocalBusiness so hours and price range are valid; Funeral home (not a schema.org type) is published as LocalBusiness with a matching additionalType; the deprecated branchOf link is replaced by parentOrganization. Every type is checked against the schema.org vocabulary.
* **Redesigned Local SEO screen.** Card sections that match the Setup Wizard, a Schema health checklist based on Google's guidelines, a live JSON-LD preview with copy, one-click hour presets and links to Google's Rich Results Test and the Schema.org validator. Works on phones.
* The Setup Wizard no longer erases the extra Local SEO details when it saves.

= 2.20.19 =
* **Mastodon works end to end.** Posts you opt in to sharing from the editor are now shared reliably (the choice was read before it was saved), "Share again now" really posts again, the server field accepts a plain address like mastodon.social, and the access token is never printed back into the page. The status only says Connected once Mastodon accepts the token, and failed checks explain what to fix.
* **New Mastodon screen.** A clear status bar and five numbered steps: connect your account, verify your profile, choose what to share, write the post, and review authors and recent shares. Works on phones.
* **Editor in PublishRank colors.** The SEO fields, tabs, buttons, checkboxes, help icons and analysis in the block and classic editors now use the PublishRank crimson palette instead of WordPress blue.
* **Clearer Sitemap Hub.** The full sitemap address is shown and can be copied or opened in one click, and a new Sitemap files list shows every file search engines will read. Navigation scrolls to the current screen on phones.
* **Private Login.** The custom sign-in address module has been rebuilt under its own PublishRank name, screen and settings. Existing sign-in addresses and redirect settings carry over automatically, and the old settings link forwards to the new screen. Emergency switch: `define( 'PUBLISHRANK_DISABLE_PRIVATE_LOGIN', true );`.

= 2.20.18 =
* **Editor layout fixed.** Block editor: the SEO fields no longer float over the article. They now sit inside the PublishRank sidebar under the SEO analysis (tabbed: Published values, Search appearance, Article context, Social sharing, ...) and go back to the Meta Boxes area at the bottom when the sidebar is closed. Classic editor: the section tabs have their own row under the "PublishRank SEO fields" header, so the title, move and collapse buttons no longer get squeezed; on phones the tabs scroll in one line and the "What will be published" table stacks.
* **SEO panel shows the real description.** When no meta description is written, the score panel and the analysis now count the automatic description PublishRank publishes (marked "auto") instead of reporting 0/160 and a missing description.
* **No duplicate site description.** The site description is now used only on the home page. It is no longer appended to the fallback description of posts, pages and archives, and the "use the publication-wide description everywhere" option was replaced by "leave the description out". Duplicate meta / og / twitter description tags printed by a theme next to PublishRank's own are removed.
* **Preload and prefetch work on the live site.** The featured image of articles and pages is preloaded at high priority (with srcset) and never lazy-loaded; same-site article links are prefetched on hover or tap with Speculation Rules (configures core speculative loading on WordPress 6.8+); CDN and media hosts get preconnect, video and API hosts dns-prefetch. A filter no longer strips the configured hosts. Each part can be switched off under Settings > Infrastructure, speed and syndication.
* **Syndication works.** The Canonical URL field now accepts the original article on another site (it was silently dropped before), and the canonical, og:url and feed link follow it. RSS items carry the featured image (media:content and in the item) and a "The post ... appeared first on ..." source line; the source line was never added before because of a wrong check.
* **Canonical on local sites.** Sites running on localhost, 127.0.0.1 or *.local no longer show "/" as the canonical URL.

= 2.20.17 =
* **Movable SEO fields panel.** All SEO fields now open in a panel you can drag anywhere on the screen, with a button for each section (Search appearance, Social sharing, Article details, Indexing, Schema). Block editor: it appears on its own and minimizes to a small bar. Classic editor: use the move button in the SEO fields bar to float it. Position and open/closed state are remembered.

= 2.20.16 =
* **SEO fields are now at your fingertips.** Classic editor: the SEO Overrides, Schema Selection and Multilingual boxes are no longer buried in the footer; they sit in a tabbed "PublishRank SEO fields" bar directly under the title (Search appearance, Social sharing, Article details, Indexing, Schema). Block editor: the same fields now live inside the PublishRank sidebar below the SEO analysis. Click any "Fix" item in the analysis to jump straight to its field.
* **Values no longer get lost.** Edits made in the SEO fields now mark the block editor post as changed, so Update always saves them. The old duplicate title/description fields in the sidebar (which could overwrite what you typed in the footer box) are gone. Unsaved SEO field values are backed up in the browser and restored after a reload. SEO meta is now available to custom post types that did not support custom fields, and the cornerstone flag can no longer be reset by another box on the page.
* **Fixes.** Removed a stray closing tag in the SEO Overrides box, schema detail panels (Video, FAQ, Event...) work wherever the box is placed, the sidebar is available even when the SEO score analysis is switched off, and SEO title and meta description show live length counters in both editors.

= 2.20.15 =
* **SEO Analysis moved to the top of the editor.** The panel no longer floats over the bottom corner of your writing. In the block editor a live score badge now sits in the top toolbar next to Publish; it changes color and number as you write. Click it to open the PublishRank SEO sidebar, where the full analysis (score, metrics, focus keyword, items to fix with one-click Fix buttons) is docked at the top, followed by the SEO title and meta description.
* **Dock or pop out — your choice.** A new button in the panel header pops the analysis out into a draggable floating panel and docks it back. Your choice is remembered.
* **Classic editor.** The analysis is docked in the right column directly under the Publish box, so Publish is never pushed out of view.
* **Faster access.** Open the analysis from the score badge, the editor Options menu, or the command palette (Ctrl/Cmd + K → "Open PublishRank SEO analysis"). The sidebar opens by itself the first time so writers find it.

= 2.20.14 =
* **Graph scan no longer reloads in a loop.** The Content Graph scan now runs inside the page with a live progress bar and Pause, Resume and Cancel buttons instead of reloading the screen after every batch. It starts only when you press Run graph scan, never just because a paused scan exists, and it stops on errors, expired sessions, or when a batch makes no progress. Without JavaScript, Resume graph scan processes one batch per click.
* **Scan progress survives cache evictions.** Scan progress is kept in a transient plus a backup option, so an evicted transient can no longer silently lose or restart a scan.
* **Help icons rebuilt.** Each setting gets one icon with its own explanation (the most specific match wins), icons no longer double up beside the same setting, and the popover is no longer clipped by cards or tables. It supports keyboard, screen readers, touch, Escape and click-outside to close.
* **Features grouped by purpose.** The Features screen groups cards by purpose; bbPress and BuddyPress stay at the very bottom, and the settings link is now labelled Configure. Failed or mismatched toggles are reported as errors instead of success.
* **Sitemap Hub.** Off-by-default content types (attachment pages) now explain why, and the instant-save switches report server-side failures or unchanged values instead of a false success.
* **General Settings.** The unsaved-changes warning now ignores values adjusted by other scripts at load, notices list changes made with add/remove buttons, never stays disabled after a cancelled save, and blocks duplicate submissions.
* **Navigation.** The Internal Links submenu is now labelled Content Graph to match its page title, sidebar and dashboard card.
= 2.20.13 =
* **Breadcrumbs block with live preview and color pickers.** The PublishRank Breadcrumbs block now has its own settings in the editor sidebar: a visual picker for the Arrows, Minimal, Steps and Pills looks (plus Plain, Chips, Bar and Underline), a built-in color picker for text, links, background and accent, size, position and separator. The preview in the editor updates as you change anything and uses the post's real title and first category. Anything left on "Site default" follows PublishRank → Breadcrumbs.
* **Horizontal position in pixels.** A slider (-400 to 400 px) moves the trail from left to right; the shortcode uses `offset="40"`. It is ignored on small phones so the page never scrolls sideways.
* **Live designer on PublishRank → Breadcrumbs.** The settings screen now shows a gallery of every look (Arrows, Minimal, Steps and Pills as in the Daily UI #056 design, plus Plain, Chips, Bar and Underline) and a live preview that uses the same markup and stylesheet as the site. It changes instantly with the look, size, position, horizontal position in pixels, separator, icons and colors.
* **Color pickers.** Text, link, background and a new accent color use the built-in WordPress color picker on the settings screen and in the block.
* **Optional crumb icons.** Home, folder and page icons for the Arrows, Minimal, Pills, Chips, Bar and Plain looks (a site setting, a block toggle and `icons="1"` in the shortcode). Choosing Minimal or Pills brings the separator used in the reference design.
* **Site-wide defaults for the new options.** Accent color, horizontal position and icons are included in Import / Export and in change snapshots; the block and shortcode can override them per page.
* **Same options in the shortcode.** `[publishrank_breadcrumbs look="arrows" accent="#c9184a" link="#590d22"]` accepts look, size, align, separator, text, link, bg and accent (colors as #hex).
* The structured data (BreadcrumbList) is unchanged: it is still built from the same trail as the visible breadcrumbs.

= 2.20.10 =
* **Private Login emergency switch.** Add `define( 'PUBLISHRANK_DISABLE_PRIVATE_LOGIN', true );` to wp-config.php to restore the standard WordPress login at once if you are ever locked out. The Private Login screen explains when this is active.
* **Safer Private Login.** The notice-dismiss request now requires the "manage options" capability and accepts only the notices Private Login actually shows. The multisite network settings read their fields safely and use the same address check as the rest of the plugin.
* **New installs keep the standard login until you confirm.** Private Login now waits until you save the Security step (or the Private Login screen) so the login never moves before you have seen the new address. Sites that already used Private Login are not changed.
* **See and copy the new login address.** The Security step shows the exact address, updates it as you type, and has a Copy address button.
* **Login address in Import / Export.** The private login address and blocked-visitor redirect are now exported and imported, with the same validation as the settings screens. The on/off switch is deliberately not imported, so a settings file can never hide the login on a site that has not set it up.
* **Translations.** The translation template now lists every translatable string, and the Urdu translation covers the Private Login and Security step text.
* **Direct-access guards** added to two files that lacked them.
* **Faster setup wizard.** The wizard checks required fields once per request instead of repeatedly.
* **One version source.** The version number is read from the plugin header. The reviewable `src/assets` copies are synchronised with `assets`.
* **Note on step numbers.** The Setup Wizard now has nine steps, with Security at step 8 and the final Ready step at step 9. A bookmarked `&step=8` link opens Security.

= 2.20.9 =
* **Private Login in the Setup Wizard.** New "Security" step with a required private login address and an optional redirect for blocked visitors. The address is checked against reserved WordPress names before it is saved.
* **Private Login in General Settings.** A new Private Login card edits the same two options with the same validation.
* **Save & exit.** Once every required field (*) is filled in, the wizard unlocks and "Save & exit" saves the current step and leaves. Setup is marked complete, so PublishRank screens stay unlocked and the reminder disappears. Later steps can still be edited from the Setup Wizard.
* **Readable Private Login banner.** Text and links on the dark red banner are now always white, whatever other admin styles apply.

= 2.20.8 =
* **Separate social titles and descriptions.** The editor's Social sharing section now has Facebook, X and Mastodon fields. X cards use the X fields, then the Facebook fields, then the automatic text. The Mastodon fields replace {title} and {excerpt} in the post PublishRank shares to Mastodon. Blank fields stay automatic: the post title with the site name, and the post description. All fields support %%variables%% and are available in the REST API; SEO-plugin imports now carry X titles and descriptions across.

= 2.20.7 =
* **Mastodon author verification.** Every author can now verify their own Mastodon profile. Author pages publish a rel="me" link to the author's Mastodon profile; the WordPress profile screen shows a copy-ready author page address, and a "Check verification" button asks the author's Mastodon server whether the green check is showing. PublishRank → Mastodon lists all authors with their profile, author page and status. Switch it off under "Credit authors".
* **Rate PublishRank.** A "Rate on WordPress.org" link on the Plugins screen and a notice on PublishRank screens. A dismissed notice returns after five minutes (filter: publishrank_rate_notice_interval) until the administrator rates the plugin or chooses "I already rated it".

= 2.20.6 =
* **Google-style homepage preview in the Setup Wizard.** Step 5 now shows how the homepage will look in Google (site name, URL, title and description) with a Desktop and Mobile view. It updates as the publisher types, resolves the real %%variables%%, uses the title separator currently selected (even before saving) and applies the same fallbacks as the live site: homepage description, then the front page's own SEO description, then the publication description.
* **Plain-language hints under the preview.** Warns when the title is likely to be shortened, when the description is missing, too long or very short, and says which source the description is taken from when the homepage field is empty.
* The preview is read-only (administrators with setup access only), saves nothing and does not change any stored option or the HTML the site outputs.

= 2.20.5 =
* **Homepage title and description are now written by the publisher, not templated.** The Setup Wizard's Homepage SEO title field shows an example placeholder (site title, separator, short description) that is never saved, and its help text explains that the field is plain text with optional variables.
* **Dedicated homepage meta description.** WordPress has no excerpt for the homepage, so the Homepage meta description field is now explained as the dedicated description used for search results and social previews, with an example placeholder, a plain-language fallback note and a live 120 to 160 character guide.
* No option names, saved values or output logic changed; existing homepage settings keep working.

= 2.20.4 =
* **Mastodon connection UX and resilience.** Clarified server, token, profile, message and sharing settings. The encrypted saved token is hidden by default and can be revealed by an administrator with an accessible eye control. Failed verification no longer deletes a saved token; status sharing now requires a Mastodon status ID and records successful private posts even when the server omits a public URL.
* **Mastodon media readiness.** Allow additional time for asynchronous featured-image processing before reporting that a share should be retried.

= 2.20.3 =
* **Mastodon verification helper.** Added a generated, copyable `<link rel="me">` head snippet, with live updates from the profile URL field, plus clearer official verification steps.
* **Private Login visual refresh.** Improved branded login and redirect URL controls, contrast, keyboard focus and small-screen layout.
= 2.20.2 =
* **Mastodon site verification made easier.** Added a copy-ready Website URL field and clearer steps for the reciprocal rel="me" verification flow. Mastodon does not issue a separate one-time site-verification code.

= 2.20.1 =
* **New: Private Login module spotlight.** The module now carries a first-visit New badge in the PublishRank menu and dashboard; its dedicated settings screen remains separate from WordPress Settings.
* **Migration & Portability contrast and mobile layout.** Improved heading contrast and touch-friendly responsive controls.
= 2.20.0 =
* Added PublishRank Private Login: custom login URL, handling for wp-login.php, wp-register.php and wp-admin requests, custom redirect URL, and multisite network defaults. Settings live on the dedicated PublishRank > Private Login page.

= 2.19.2 =
* **Fix: keyword fields stay in the PublishRank SEO box.** Focus keyword, secondary keyphrases, synonyms and related keyphrases no longer redirect editors to Gutenberg’s sidebar or appear in duplicate locations.
* **Fix: background field saving and live updates.** Keyword fields autosave through a scoped AJAX request, refresh the resolved automatic SEO summary in place, and update live SEO analysis without a full editor reload.

= 2.19.1 =
* Mastodon publishing upgrades: optionally attach featured images with their Media Library alt text, add per-post content warnings, and adapt text length to each server's configured limit.
* Dashboard styling consistency: the served admin theme now styles the site overview, sitemap URLs and configuration panels in the same card language as the workflow section.

= 2.19.0 =
* **New: Mastodon.** Verify your Mastodon profile with the green check (rel="me" on every page), credit authors with the fediverse:creator tag, and share posts to Mastodon automatically on publish or with a "Share now" button. Custom message format, hashtags from tags, visibility, per-post opt-out and a share history. Free.

= 2.18.4 =
* **Maintenance release.** Version markers and package metadata updated to 2.18.4; no functional changes.
= 2.18.3 =
* **News sitemap completeness for busy publications.** News candidates are scanned in bounded pages up to a larger safety ceiling, so noindex, password-protected, excluded, or malformed stories cannot crowd valid recent articles out of the sitemap.
* **News-site setup clarity.** The onboarding summary now reports the actual News sitemap post types.
* **Styled Migration & Portability workspace.** The import page now has detected-plugin cards, grouped controls, dry-run emphasis, upload guidance, rollback presentation, responsive layouts, and clearer export/preflight tools.
= 2.18.2 =
* **Smarter migration from Yoast, Rank Math, AIOSEO, SEOPress and The SEO Framework.** Title and description variables (e.g. Rank Math %title%, AIOSEO #site_title, SEOPress %%post_title%%) are converted to PublishRank variables; noindex and nofollow flags are combined correctly (Yoast "2 = index" no longer imported as a robots value); primary category and cornerstone/pillar flags are migrated; AIOSEO 4 data is read from its own table; The SEO Framework social fields are included.
* **Translation-aware JSON import/export.** Exports now include each post's language and translation URLs; imports match by full URL first and use the language to tell apart translations that share a slug.
* **Multilingual fixes.** Language tags follow BCP 47 (zh-Hans-CN, es-419, sr-Latn); sitemaps add an x-default link to the default-language translation; TranslatePress and Weglot current language detected; invalid alternate URLs dropped; new publishrank_hreflang_alternates filter.
* **Fix:** the Migration & Portability screen caused a fatal error (missing Branding class import).

= 2.18.1 =
* **Broad multilingual SEO catalog.** Locale-specific SEO title, description, focus keyword, Open Graph and robots overrides now cover major LTR and RTL languages, including Urdu, Arabic, Persian, Hebrew, Bengali, Hindi, Chinese, Japanese, Korean, European languages and Southeast Asian languages. WPML, Polylang and custom hreflang locales are merged automatically.
= 2.17.0 =
* **SEO Analysis 3.0: focus-keyword scoring restored.** The live analysis now scores the focus keyword in the title, meta description, URL slug, opening paragraph, subheadings, image alt text, body density (0.5–3%) and distribution across the article. Plural/singular variants are matched.

= 2.16.0 =
* **Job Posting pages.** New "Job Posting" schema type with fields for hiring organization (name, website, logo), closing date, employment type, address or fully remote (with applicant country), salary range, currency and period, job reference and direct apply. The title is the job title and the post content the description. Markup is only output when the required details are complete, and stops automatically after the closing date.
* **Automatic sitewide broken-link checker.** Link Intelligence can now check every published article daily or weekly in the background (optionally including external links), and re-checks an article's internal links as soon as it is published or updated. Shows when the last full check finished.
* **Better multi-language support (Polylang and WPML).** Sitemaps list each article's translations (hreflang links); article schema uses the article's own language and links its translations (workTranslation); publication name, descriptions, title templates and separators can be translated in Polylang Strings / WPML String Translation.
* **Localized SEO handling.** Each post can store language-specific SEO title, meta description, focus keyword, Open Graph title/description/image and robots directives. PublishRank selects the current language first, then its base language, then the normal post SEO value. The built-in catalog covers major LTR and RTL languages including English, Urdu, Arabic, Persian, Hebrew, Bengali, Hindi, Chinese, Japanese, Korean, German, Spanish, French, Portuguese, Italian, Dutch, Russian, Turkish, Ukrainian, Vietnamese, Indonesian and more. Any valid locale supplied by WPML, Polylang or the hreflang map is also accepted.
* An Urdu translation of the PublishRank interface is included. Set the WordPress site language to Urdu (`ur`) or Urdu (Pakistan) (`ur_PK`) to use it; Urdu and all other RTL fields use the correct text direction.
