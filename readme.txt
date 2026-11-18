=== PublishRank SEO for WordPress ===
Contributors: umarjaum
Tags: seo, sitemap, schema, news, redirects
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 2.25.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: publishrank

PublishRank SEO for WordPress: free, production-ready SEO for businesses, stores, creators, publishers and sites of every kind.

== Description ==

PublishRank 2.25.1 rebuilds Traffic Guard, adds a full ISO 3166-1 country database with a searchable country picker, a native FAQ block with reusable FAQ collections, an SEO Inspector that previews and checks any page of your site, validated custom JSON-LD, and update / What's New notifications with a live indicator. The release is production-ready and free to use. All features have been tested on real websites by the PublishRank Community, with no errors reported. Back up your site before a major update.

PublishRank SEO for WordPress is a free, open-source SEO plugin for businesses, online stores, service providers, creators, publishers and websites of every kind. Manage metadata, schema, sitemaps, Local SEO, WooCommerce SEO, redirects, security and editorial tools in one WordPress plugin. Newsroom workflows are a specialist capability, not a requirement to use the plugin.

Declared minimums: WordPress 6.3 and PHP 7.4. The delivery and compatibility reports document their exact runtime test environments.

Copyright and project rights: Muhammad Umar Jabbar.

Features include:

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
* **Access Shield** — PublishRank's own security module: custom sign-in address with protection of the default login routes, brute-force lockouts, two-factor authentication, CAPTCHA, IP and country rules, session limits, a security log, emergency recovery (`wp publishrank shield disable`) and live verification with signed reports.
* **Traffic Guard** — monitor or enforce mode, protection levels, a rule builder with conditions, priorities and test mode, temporary blocks that expire, rate limiting, bot classification with DNS-verified search crawlers, country allow/block lists, an event log with search, filters, export and false-positive reporting, and a rule tester.
* **FAQ block and FAQ collections** — an accessible accordion or list with layouts, icons, colours, spacing, style presets, drag-and-drop ordering, bulk editing, templates, search and expand/collapse controls; reusable collections with categories, duplication and JSON import/export. FAQPage structured data uses exactly the questions shown on the page, once per page.
* **SEO Inspector** — preview any page of your site as a search result and a social card, check titles, descriptions, canonicals, robots and headings, and validate every JSON-LD entity against documented required and recommended properties. Also checks pasted JSON-LD or HTML.
* **Source Directory** — your newsroom's own list of cited sources with editorial classifications, priorities, notes, usage counts and import/export, plus an editor panel that suggests listed sources, flags unlisted links and warns about sources you marked "Use with caution" or "Do not cite". It never marks a source as verified and never edits content on its own.
* Always-on technical source provenance markers in page source; they do not add a promotional link or visible footer credit.

PublishRank does not guarantee rankings, crawling, indexing, traffic, or inclusion in Google News. IndexNow is not supported by Google.

== Screenshots ==
1. The real PublishRank Dashboard in the WordPress admin.
2. The real PublishRank Features screen with the available SEO tools.
3. The real PublishRank Schema settings screen.
4. The real PublishRank SEO Score screen.
5. The real PublishRank Newsroom interface.

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
* In the package: `docs/release-2.25.1/` contains the feature catalogue and module guide (`FEATURE-CATALOG.md`), installation and upgrade instructions, and the verification, security, performance, compatibility and licensing reports for this release.
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
The package guide `docs/release-2.25.1/FEATURE-CATALOG.md` describes every module, with the new and rebuilt 2.25.1 features marked. Inside WordPress, PublishRank → Features lists every module with a switch, and PublishRank → Documentation & Help explains each one.

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
= 2.25.1 =
* Production-ready and free to use. All features tested on real websites by the PublishRank Community, with no errors reported.
* **Traffic Guard rebuilt.** New overview with blocked and suspicious traffic, top countries and IPs, hourly timeline and anomaly alert; Monitor mode (log what would be blocked) and Enforce mode; protection levels (off, low, balanced, strict); a rule builder with up to 10 conditions per rule (IP, country, path, query, user agent, referrer, method, bot type), AND/OR matching, priorities, actions (block, temporary block, allow, log) and a test mode that records matches without acting; temporary IP blocks that expire on their own; configurable rate limiting; bot classification with DNS-verified search crawlers that are never blocked; a searchable, filterable, paginated event log with date ranges, CSV export, event references shown on the block page and false-positive reporting; per-rule hit statistics; a rule tester that explains every decision step by step.
* **Country database.** All 249 ISO 3166-1 countries with names, alpha-2, alpha-3 and numeric codes, and a searchable multi-select country picker (type a name or a code, paste a list) in Traffic Guard and Access Shield. Country data is taken only from trusted sources (a server GeoIP module, a CDN header from a trusted proxy, or a developer lookup filter) — never from the browser. When a visitor's country is unknown they are allowed by default.
* **FAQ block.** A native editor block with accordion or list layouts, drag-and-drop and button reordering, duplication, bulk editing, starter templates, icons, separators, colours, spacing, typography, style presets (Boxed, Minimal, Bold), optional search box and expand/collapse-all controls, keyboard and screen-reader support, and a visitor preview inside the editor. Reusable FAQ collections with categories, duplication and JSON import/export, usable from the block or `[publishrank_faq collection="ID"]`. FAQPage structured data uses exactly the questions on the page, never repeats a question and is printed once per page.
* **SEO Inspector.** Preview any page of your site as a search result and a social card, check title, description, canonical, robots, headings and language, and validate every JSON-LD entity against documented required and recommended properties (Article family, Product, Offer, Review, Event, Recipe, JobPosting, Course, VideoObject, LocalBusiness, Organization, FAQPage, BreadcrumbList and more). Paste JSON-LD or HTML to check it before publishing. "Inspect SEO" in the toolbar opens it for the page you are viewing. The editor's live preview uses the same checks.
* **Custom schema.** A "Custom schema (JSON-LD)" block in the schema library accepts one schema.org object, checks it (valid JSON, a real @type, https URLs, no markup, no @graph, no duplicate site-wide types) and adds it to the page graph only when it passes.
* **Source Directory.** Keep your newsroom's own list of sources (name, address, description, category, logo, editorial classification, priority, internal notes, active or inactive) with search, filters, sorting, usage counts and JSON import/export. A "Sources" panel in the block editor shows which links in the article are in the directory, warns about sources you marked "Use with caution" or "Do not cite", flags links that are not listed, suggests listed sources for the article and inserts a link only when you click. The Classic editor gets a "Sources in this article" box. Classifications are your own labels — PublishRank never marks a source as verified and never changes content on its own.
* **Sign-in page designer** in Access Shield: logo, colours, background image with darkening, form position, corner radius, typeface, heading, welcome message, footer note, and options to hide the "Go to site" link and language switcher, with a live preview and a contrast warning. Form fields and protections are untouched.
* **Notifications.** An update notice (from WordPress.org, or announced on the PublishRank website) that can be dismissed per version; a pulsing live dot on the PublishRank menu, the News & What's New screen and the toolbar when there is unread news, an announcement or an update. Notices keep their warning or error colour instead of always showing as success.
* **Question-mark help icons** replace the "i" information icons everywhere in PublishRank.
* **Identity.** Access Shield is described as PublishRank's own module throughout; options from other login plugins are no longer read during import.
* Fixed: the Traffic Guard probe pattern failed to compile on some paths; FAQ answers could keep the text of a removed script tag.
* Security: the private sign-in address was printed in the page-preloading rules of public pages when Access Shield's private address was on; it is now only included for signed-in users.
* Access Shield's request firewall no longer blocks ordinary searches containing the words "union select" (a payload such as column lists, NULL or "… FROM" is still required), and its blocks now appear in Traffic Guard's event log with a reference number shown to the visitor.
* "Version X is available" announcements for a version you already run are no longer shown or counted as unread.
* The FAQ collection tools no longer appear inside the notification list; the event-log filters in Traffic Guard are aligned.
* Trend lines in summary cards (Analytics, Traffic Guard) no longer overlap the comparison text; the readme now points to the guides that ship in `docs/release-2.25.1/`.


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

Older release history (2.23.0 and earlier): https://github.com/Umarjaum/PublishRank/blob/main/docs/release-archive/README-changelog-archive.md
== Upgrade Notice ==

= 2.25.1 =
Rebuilt Traffic Guard, country database, FAQ block, Source Directory, SEO Inspector, custom schema and sign-in page designer, plus a security fix for the private sign-in address. Settings are kept; an active Traffic Guard keeps enforcing as before.
