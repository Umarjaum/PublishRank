=== PublishRank ===
Contributors: umarjaum
Tags: seo, sitemap, schema, news, redirects
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 2.20.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: publishrank

SEO tools for news publishers: News SEO, sitemaps, schema, metadata, redirects, and technical controls.

== Description ==

PublishRank is a free, open-source WordPress SEO plugin for site owners, bloggers, news publishers, newspapers, magazines and editorial teams. It brings core technical SEO workflows into one publisher-focused plugin and can be evaluated as an alternative to Yoast SEO, Rank Math and other WordPress SEO plugins. Compare the feature set with your current setup before switching; features and workflows are not identical across plugins.

Compatibility target: WordPress 7.1. The plugin remains installable on PHP 7.4+; complete staging checks before production use.

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
* **New: WP Hide Login.** Optional custom sign-in URL, default-login-route handling, redirect behavior and multisite defaults, managed from its own PublishRank submenu screen. Based on a port of WPS Hide Login 1.9.19 (GPLv2+).
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
A classic theme that hard-codes a <title> tag and does not declare title-tag support can print two titles. Per-post templates that were saved by an older release with %%date%% or %%category%% were stored damaged and must be re-entered. Global meta-description templates are not available; use per-content SEO overrides and the configured description fallbacks instead. Open Graph and X/Twitter title and description overrides support the same registered template variables as SEO titles and descriptions.

= What does the XML sitemap page in my browser show? =
A readable view of the same sitemap search engines fetch as plain XML: totals, a content showcase bar with each type's share, one card per content type, and URL tables with filtering for child sitemaps. Use View raw XML to see exactly what crawlers receive. The view never changes the XML.

= How does the 404 Monitor work? =
Enable **Record a capped rolling 404 log** in **PublishRank → Settings**, then review entries at **PublishRank → 404 Monitor**. The monitor aggregates normalized paths by browser, mobile or crawler category, records hit counts and first/last seen times, and keeps only the latest useful referrer path without storing IP addresses, cookies, request bodies or full query strings. It ignores admin, AJAX, cron, REST, non-GET/HEAD, common static-asset and infrastructure-probe requests. The log is rate-limited, protected against concurrent write loss, retained for 90 days and capped at 500 entries. Administrators can search paths, filter visitor categories, sort by hits or recency, dismiss entries, clear the log, or create validated same-site redirects. It is a bounded diagnostic aid, not a complete access log, analytics system, WAF or DDoS defense.

= Where is the complete feature list? =
The package guide `docs/features/FEATURES.md` is the complete dashboard inventory through version 2.10.1. It includes every card under Search & discovery, Content & publishing, Measure & maintain, and Settings & help, plus every Technical Health check: Schema studio, Sitemap health, IndexNow, Search Console, Search engines, Google Preferred Sources, Robots.txt, Crawl optimization, Breadcrumbs, Site verification, AI & llms.txt, Schema Aggregator, SEO score, SEO Overrides, Google Discover image, Author expertise, Local SEO, Image optimizer, Publisher toolkit, Newsroom, Planning board, Newsroom calendar, Newsroom activity, My notifications, Newsroom configuration, Newsroom roles, Internal links, Link intelligence, Publisher analytics, Insights, Redirects, 404 monitor, Traffic Guard, WP Hide Login, Configuration history, Diagnostics, Setup wizard, Settings, Titles & Meta, Import / Export, Documentation & help, SEO plugin conflicts, Search engine visibility, Pretty permalinks, HTTPS, News sitemap, Structured data coverage, Publisher logo, Default social image, Robots.txt and JNews compatibility.

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

**IndexNow (optional).** When enabled, eligible public URLs are submitted after supported publish/update/delete events, manual submission, or a retry. The request to `https://api.indexnow.org/indexnow/` includes the site's host, IndexNow key, public key-location URL and submitted URL list. The plugin keeps a bounded local submission history including URLs and response statuses. Acceptance is not proof of crawling or indexing, and Google does not participate in IndexNow. The official [IndexNow Terms of Service](https://www.indexnow.org/terms) identify Yandex, Seznam and Microsoft as sponsors. See their [Yandex Privacy Policy](https://yandex.com/legal/privacy/), [Seznam Privacy Policy](https://o-seznam.cz/ochrana-udaju/) and [Microsoft Privacy Statement](https://privacy.microsoft.com/en-us/privacystatement).

**Baidu Search Resource Platform submission (optional).** An administrator can submit up to 2,000 same-host public URLs manually, or explicitly enable automatic submission for newly published eligible posts. The request is sent over HTTPS to the Baidu endpoint configured by the publisher and includes the submitted URL list; the endpoint URL itself contains the publisher's Baidu site identifier and token. The endpoint is encrypted in the WordPress database; Baidu receives the endpoint credentials and URLs to process the request. A bounded local history stores response counts/status, not the submitted URLs or response body. A submission is not an indexing guarantee. See the [Baidu Search Resource Platform Agreement](https://ziyuan.baidu.com/college/articleinfo?id=1613), [Baidu Privacy Protection Statement](https://www.baidu.com/duty/yinsiquan.html), and [Baidu Disclaimer](https://www.baidu.com/duty/index.html).

**Publisher diagnostics (optional, administrator-triggered).** The live frontend scan requests the site's own homepage, latest article, sitemap index and up to ten same-origin child sitemaps from the site's server and analyzes the returned HTML/XML locally. It does not follow HTTP redirects, and sitemap child URLs on another origin are not requested. The IndexNow key check requests the site's own public key file. Publisher Link Intelligence analyzes locally available site data. Only when an administrator ticks **Also check external links** and starts a scan, the site's server sends HEAD/GET requests to the external URLs already linked in the site's own content to read their HTTP status; nothing else is sent and no data is shared with PublishRank.

**Traffic Guard (optional, local-only).** When enabled, validated request IPs and configured IP/CIDR and country rules are compared locally. The plugin reads `CF-Connecting-IP`, `X-Forwarded-For`, or `CF-IPCountry` only when the direct network peer is inside an administrator-configured trusted-proxy CIDR. It makes no GeoIP API requests, stores no per-visitor IP address, and sends no Traffic Guard data to PublishRank or another service. Country rules require a trusted proxy that supplies a valid country header; missing or invalid country data is allowed through. Country geolocation is approximate, and this feature is not a WAF or DDoS defense. Administrator requests and key WordPress recovery, API, feed, robots, and sitemap routes are exempt. When a request would be blocked and its User-Agent claims to be a major search-engine crawler, the server performs a reverse DNS lookup of the visiting IP and a forward lookup of the returned host name to confirm it (the verdict is cached under a salted hash, not the IP). These are DNS lookups made by your server, not HTTP requests, and no data is sent to PublishRank.

Other website links in the administration interface (including the Schema.org vocabulary and links to provider documentation) are ordinary references, not background API integrations. PublishRank's source-provenance markers are technical metadata only; the plugin does not insert a visible footer credit or promotional link.

== Changelog ==
= 2.20.14 =
* **Breadcrumbs block with live preview and color pickers.** The PublishRank Breadcrumbs block now has its own settings in the editor sidebar: a visual picker for the Arrows, Minimal, Steps and Pills looks (plus Plain, Chips, Bar and Underline), a built-in color picker for text, links, background and accent, size, position and separator. The preview in the editor updates as you change anything and uses the post's real title and first category. Anything left on "Site default" follows PublishRank → Breadcrumbs.
* **Horizontal position in pixels.** A slider (-400 to 400 px) moves the trail from left to right; the shortcode uses `offset="40"`. It is ignored on small phones so the page never scrolls sideways.
* **Live designer on PublishRank → Breadcrumbs.** The settings screen now shows a gallery of every look (Arrows, Minimal, Steps and Pills as in the Daily UI #056 design, plus Plain, Chips, Bar and Underline) and a live preview that uses the same markup and stylesheet as the site. It changes instantly with the look, size, position, horizontal position in pixels, separator, icons and colors.
* **Color pickers.** Text, link, background and a new accent color use the built-in WordPress color picker on the settings screen and in the block.
* **Optional crumb icons.** Home, folder and page icons for the Arrows, Minimal, Pills, Chips, Bar and Plain looks (a site setting, a block toggle and `icons="1"` in the shortcode). Choosing Minimal or Pills brings the separator used in the reference design.
* **Site-wide defaults for the new options.** Accent color, horizontal position and icons are included in Import / Export and in change snapshots; the block and shortcode can override them per page.
* **Same options in the shortcode.** `[publishrank_breadcrumbs look="arrows" accent="#c9184a" link="#590d22"]` accepts look, size, align, separator, text, link, bg and accent (colors as #hex).
* The structured data (BreadcrumbList) is unchanged: it is still built from the same trail as the visible breadcrumbs.

= 2.20.10 =
* **Hide Login emergency switch.** Add `define( 'PUBLISHRANK_DISABLE_HIDE_LOGIN', true );` to wp-config.php to restore the standard WordPress login at once if you are ever locked out. The Hide Login screen explains when this is active.
* **Safer Hide Login.** The notice-dismiss request now requires the "manage options" capability and accepts only the notices Hide Login actually shows. The multisite network settings read their fields safely and use the same address check as the rest of the plugin.
* **New installs keep the standard login until you confirm.** Hide Login now waits until you save the Security step (or the Hide Login screen) so the login never moves before you have seen the new address. Sites that already used Hide Login are not changed.
* **See and copy the new login address.** The Security step shows the exact address, updates it as you type, and has a Copy address button.
* **Login address in Import / Export.** The private login address and blocked-visitor redirect are now exported and imported, with the same validation as the settings screens. The on/off switch is deliberately not imported, so a settings file can never hide the login on a site that has not set it up.
* **Translations.** The translation template now lists every translatable string, and the Urdu translation covers the Hide Login and Security step text.
* **Direct-access guards** added to two files that lacked them.
* **Faster setup wizard.** The wizard checks required fields once per request instead of repeatedly.
* **One version source.** The version number is read from the plugin header. The reviewable `src/assets` copies are synchronised with `assets`.
* **Note on step numbers.** The Setup Wizard now has nine steps, with Security at step 8 and the final Ready step at step 9. A bookmarked `&step=8` link opens Security.

= 2.20.9 =
* **Hide Login in the Setup Wizard.** New "Security" step with a required private login address and an optional redirect for blocked visitors. The address is checked against reserved WordPress names before it is saved.
* **Hide Login in General Settings.** A new Hide Login card edits the same two options with the same validation.
* **Save & exit.** Once every required field (*) is filled in, the wizard unlocks and "Save & exit" saves the current step and leaves. Setup is marked complete, so PublishRank screens stay unlocked and the reminder disappears. Later steps can still be edited from the Setup Wizard.
* **Readable Hide Login banner.** Text and links on the dark red banner are now always white, whatever other admin styles apply.

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
* **WP Hide Login visual refresh.** Improved branded login and redirect URL controls, contrast, keyboard focus and small-screen layout.
= 2.20.2 =
* **Mastodon site verification made easier.** Added a copy-ready Website URL field and clearer steps for the reciprocal rel="me" verification flow. Mastodon does not issue a separate one-time site-verification code.

= 2.20.1 =
* **New: WP Hide Login module spotlight.** The module now carries a first-visit New badge in the PublishRank menu and dashboard; its dedicated settings screen remains separate from WordPress Settings.
* **Migration & Portability contrast and mobile layout.** Improved heading contrast and touch-friendly responsive controls.
= 2.20.0 =
* Added PublishRank Hide Login, a port of WPS Hide Login 1.9.19 (GPLv2+): custom login URL, handling for wp-login.php, wp-register.php and wp-admin requests, custom redirect URL, and multisite network defaults. Settings live on the dedicated PublishRank > Hide Login page.

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
