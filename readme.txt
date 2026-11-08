=== PublishRank ===
Contributors: umarjaum
Tags: seo, sitemap, schema, news, redirects
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 2.18.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: publishrank

Free SEO plugin for news publishers with News SEO, XML sitemaps, schema, metadata, redirects and technical SEO tools.

Compatibility target: WordPress 7.1. This package includes a compatibility test plan in docs/maintenance/WORDPRESS-7.1.3-COMPATIBILITY.md. The plugin remains installable on PHP 7.4+ and passed syntax checks on PHP 8.3.6; PHP 8.5 is the forward compatibility target. Complete staging checks before production use.

== Description ==

PublishRank is a free, open-source WordPress SEO plugin for site owners, bloggers, news publishers, newspapers, magazines and editorial teams. It brings core technical SEO workflows into one publisher-focused plugin and can be evaluated as an alternative to Yoast SEO, Rank Math and other WordPress SEO plugins. Compare the feature set with your current setup before switching; features and workflows are not identical across plugins.

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
* Optional built-in WP Hide Login module with a custom login endpoint, configurable slug, administration URL rewriting where Apache rules are available, and 404 protection for direct default paths.
* Always-on technical source provenance markers in page source; they do not add a promotional link or visible footer credit.

PublishRank does not guarantee rankings, crawling, indexing, traffic, or inclusion in Google News. IndexNow is not supported by Google.

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

= 2.15.0 =
* **Review articles: proper reviewed item.** Review and Review News Article now ask for the type of item (Product, Book, Movie, TV series, app, game, course, restaurant, hotel …), its page, image, brand or maker, author/director/artist, and pros and cons. itemReviewed is no longer a generic "Thing" (which Google ignores for review stars), and ReviewNewsArticle now carries itemReviewed, reviewRating, positiveNotes and negativeNotes.
* **Live coverage now works end to end.** Live updates are shown on the article (newest first, each with its own anchor) so the LiveBlogPosting markup matches what readers see, or place them with the [publishrank_live_updates] shortcode. Each update always has a headline, plus publisher and image; the article's dateModified follows the latest update.
* **Paywalled articles**: when "accessible for free" is unticked, the article gets the hasPart / cssSelector markup Google requires (new "Paywalled section CSS class" field, default .paywall).
* **Fact-checks**: new ClaimReview fields (claim, who made it, where and when it appeared, verdict and 1–5 rating).
* **Block editor sidebar**: "PublishRank SEO" sidebar with SEO title and meta description, live character counts, and Show SEO analysis / Add internal links buttons. The floating card also shows title and description lengths.
* **Same focus keyword warning**: the analysis now warns when another published article already uses the same focus keyword.
* **WordPress.org guidelines**: Newsroom form input is unslashed and sanitized everywhere; Crawl Optimization uses wp_add_inline_script / wp_add_inline_style instead of printed tags; new switch to hide the PublishRank credit comments and generator tag.

= 2.14.0 =
* **One SEO panel in the editor, with Fix buttons.** The floating SEO Analysis panel is now the single score summary; the full report box below the editor ("PublishRank Fix Assistant & Full Report") starts collapsed on first use instead of showing the same score twice, and remembers each editor's open/closed choice. Every item under "To improve" has a Fix button: SEO title, meta description and focus keyword are generated from the article and saved in one click; other fixes open the matching step in the full report.
* **One-click internal links.** "Add internal links" in the floating panel, and "Insert" / "Insert all internal links" in the full report, place links to related published articles on a matching phrase in your text (block editor, classic editor or text mode). Suggestions with no matching phrase go into one short "Related coverage:" line at the end. Nothing is saved until you update the post.
* **Reportage and other news articles**: new Dateline, Reported from, Story location, Backstory and Correction notice fields. NewsArticle-family schema now outputs dateline, locationCreated, contentLocation, backstory, a CorrectionComment, thumbnailUrl and publishingPrinciples, with contentLocation defaulting to the reporting location for ReportageNewsArticle.
* **Advanced Organization schema**: top-level email and telephone, editorial / advertising / news-tips contact points with language and area served, VAT ID, tax ID, DUNS, LEI, ISO 6523, NAICS, slogan, number of employees and image. NewsMediaOrganization adds ethicsPolicy, correctionsPolicy, masthead, verificationFactCheckingPolicy, unnamedSourcesPolicy, actionableFeedbackPolicy, diversityPolicy, diversityStaffingReport, ownershipFundingInfo, noBylinesPolicy and missionCoveragePrioritiesPolicy (set them in the Setup Wizard, Details step). New `publishrank_schema_organization` filter.
* **Podcast schema fixes**: PodcastEpisode is only output for real episodes (podcast post types, podcast plugin audio, feed enclosures, the Podcast Episode schema choice or the Podcast category) instead of every article with an audio clip; uses `duration` and `audio` instead of `timeRequired`; uses the SEO description; reads more episode-number fields; author has a URL; merges with the Schema Selection episode instead of printing two. New `publishrank_podcast_is_episode` filter.
* **Web Stories schema fixes**: story posters, sections and publisher logo now apply to every Article subtype (NewsArticle, ReportageNewsArticle, BlogPosting …), not only three types; the fallback Article always has a publisher, an author URL, a description and isAccessibleForFree.
* **Advanced meta tags**: robots adds max-snippet:-1 and max-video-preview:-1 next to max-image-preview:large (editor values are respected); articles get article:content_tier, meta author, X labels for author and reading time, and og:video when a video URL is set.

= 2.12.1 =
* **Every tool screen now matches Features, Help & documentation and the Setup Wizard.** Inside the shared frame, older screens (Schema, IndexNow, Search engines, Redirects, 404 monitor, Analytics, Insights, Newsroom, Traffic Guard and the rest) use the same white cards, borders, corner radius and shadow, the same light table headers, the same field height and focus ring, brand-coloured tick boxes and switches, and the Features-style segmented tabs. The warm beige backgrounds and red-to-green stripes on top of cards are gone. Settings, fields and saving are unchanged.
* **Fixed: the Robots.txt screen stopped halfway.** A missing default value made the "Full robots.txt override" switch crash the page, so the Save button, the live preview, the checks, the crawlability matrix and the URL tester never appeared, and the screen was not shown inside the shared frame. It now loads completely, inside the same frame as every other tool, and stacks into one column on narrower screens.
* General Settings and Sitemap Hub show the same small "Control center" label above their title as Features.
* Settings rows on phones no longer show doubled divider lines.

= 2.12.0 =
* **The Setup Wizard now looks and works like Features**: the same branded header, the same side menu on the left and the wizard in the main column, instead of a separate pop-up window. On a fresh install the other menu entries are shown with a lock until the required publisher fields are saved, so you can see what unlocks next.
* **Clearer step track**: one dot per step that fills exactly up to the step you are on and animates from the previous step. Finished steps are links, so you can jump back to any of them. On phones the track shows dots only, with "Step 2 of 8 · Publisher" above it.
* **Back keeps what you typed**: Back now saves the current step before returning, and pressing Enter in a field always continues instead of going back. Optional steps (Details, Social, Search, Content, Local) have **Skip for now**.
* **Unsaved-changes guard**, the same as General Settings: a sticky action bar says when a step has unsaved changes, and leaving through the menu, the step track, Skip or Exit asks first.
* **Friendlier fields**: Organization / Person cards with icons that update the form instantly, a live thumbnail next to every image field, content types as tick chips, Features-style switches, required fields and errors announced to screen readers, and 16 px fields on phones so iOS no longer zooms in.
* **Review and complete screens**: summary tiles with an icon, a clear "set / not set" look and an Edit button that jumps straight to the right step; a success banner with the completion date; shortcuts to Dashboard, General Settings and Features. The welcome step shows a three-step guide and a site readiness check with "Fix" links for permalinks and conflicting SEO plugins.
* **Fixed: sticky bars did not stick.** The shared screen frame used overflow: hidden, which stopped the General Settings section bar and save bar from staying on screen while scrolling. It now uses overflow: clip, so they stick as intended.
* **Faster screens**: the full-screen animated-logo loader is replaced with a slim brand-coloured progress bar under the toolbar. Screens are usable as soon as they are parsed instead of waiting for every image, and the animated GIF is no longer downloaded on each page.
* All new animations use only transform and opacity and are switched off when the system asks for reduced motion. The "Finish setting up PublishRank" reminder no longer uses inline styles.

= 2.11.0 =
* **SEO Modules is now called Features**, a name every publisher understands. The menu entry, screen title, Dashboard control center, filters (All / On / Off), search box and switch labels were renamed. The page address and every saved switch are unchanged.
* **Every PublishRank screen now shares one layout**, matching Features, General Settings and Sitemap Hub: the branded header on top, the same side menu on the left and the tool in the main column. The side menu lists Dashboard, Features, General Settings and Sitemap Hub, then groups every other tool under Search & discovery, Content & publishing, Newsroom, Measure & maintain and Help. The group you are in opens automatically and only screens you may open are listed. Each tool screen starts with a short title and a plain-language description. Tool settings, fields and saving are unchanged.
* **Redesigned Help & documentation**: a three-step "start here" guide, topic filters and search, one card per topic with a short summary and the full explanation behind "Read more", a direct link to the matching screen, and a "Common problems & quick fixes" section.
* **Clearer sitemap index page in the browser**: a plain-language explanation, the one address to submit with Copy and Google Search Console / Bing shortcuts, a three-step "how it works" strip, totals with icons, and a "What's in your sitemap" list with one row per content type showing its icon, description, number of addresses, share of the total, last change and an Open button. Each content type has its own colour, empty rows explain that they fill up automatically, files split into parts are listed in their row and singular/plural wording is correct. Child sitemap pages use the same look. Search engines still receive exactly the same XML.
* Table headers on PublishRank screens are readable again (dark text on a light header).

= 2.10.1 =
* Redesigned **Settings** (now titled General Settings) to match the SEO Modules screen: shared side menu, one card per section with an icon and summary, a sticky section bar that highlights where you are, a "Find a setting" search, switch-style checkboxes and a sticky save bar that tells you when you have unsaved changes (and warns before you leave the page). Every field, option name and save behaviour is unchanged.
* **Sitemap Health is now the Sitemap Hub**: XML sitemap master switch; your sitemap address with Copy and Open buttons; one-click links to submit it in Google Search Console and Bing Webmaster Tools; whether robots.txt lists it; headline numbers (unique URLs, sitemap files, content types included, last-updated coverage); and instant switches to include or exclude Pages, Custom post types, Categories & tags, Authors, Google News, Images, Videos and Attachment pages. URL preview, News sitemap diagnostics and error reporting are kept, in the same new style.
* The Sitemap Hub only flags background sitemap preparation when it has stalled or WP-Cron is overdue, instead of listing routine progress for every sitemap.
* Fixed the unreadable table header on the old Background preparation table (now part of the Sitemap Hub rows).

= 2.10.0 =
* Added the **SEO Modules** screen (PublishRank → SEO Modules): switch XML Sitemap, Google News Sitemap, Schema Markup, Meta & Social Previews, Live SEO Score, Internal Links, Breadcrumbs, Redirects, 404 Monitor, Robots.txt, AI & llms.txt and IndexNow on or off from toggle cards. Changes save instantly, with filter, search and a no-JavaScript fallback. Each switch uses the same option as the feature's own settings screen.
* Added a compact SEO Modules control center to the Dashboard.
* Added an XML sitemap master switch (`publishrank_sitemap_enabled`, default on). When off, PublishRank's sitemaps and robots.txt sitemap lines are removed and the WordPress built-in sitemap is used.
* Redesigned the floating editor panel as **SEO Analysis**: a live score ring out of 100 with an Excellent / Good / Needs improvement / Needs work rating, word, image and link counts, the focus keyword, a "To improve" list and a "Passed checks" list. It can be minimized to a small score pill, dragged, and re-analyzed on demand.
* The SEO score now updates live while writing in the block editor, not only in the classic editor.
* Live analysis keeps the saved focus keyword when the editor screen has no keyword field, so the score no longer drops after the first refresh.
* Live Preview → **Social previews** now shows Google, Facebook & LinkedIn, and X cards side by side, each with title and description length checks and a social-image tip.
* Added `publishrank_module_switches` (filter) and `publishrank_module_toggled` (action) for developers.

= 2.9.110 =
* Added **Setup Wizard** directly under Dashboard in the PublishRank sidebar (also kept in the dashboard hero, workflow card, Plugins row and toolbar).
* Added the animated PublishRank logo as a loader on every PublishRank admin screen, with a CSS-only failsafe and an opt-out (`data-pr-no-loader`) for forms.
* Redesigned the Dashboard and screen headers to match the official website: white header bar, pink hero with a live preview of your site checks, pill buttons, feature strip and a three-step publishing workflow (`assets/css/website-theme.css`).
* Added the official website, https://publishrankseo.vercel.app/, to the plugin header, Plugins screen (Official Website), dashboard, Documentation & Help, front-end credit and readme, replacing the old netlify address.
* Made the version consistent: plugin header, `PUBLISHRANK_SEO_VERSION`, readme stable tag, Branding fallback, docs and in-dashboard documentation all read 2.9.110 (documentation previously still said 2.9.105 and 2.9.108).
* Restructured `docs/` into getting-started, features, admin and maintenance folders with a new index, installation guide and setup-wizard guide.
* Synchronised the reviewable `src/assets` copies with the shipped `assets` files.
* Fixed the loader staying on screen after export/download actions; it now skips download links and forms and always hides after 12 seconds.
* Newsroom attachments notice now only appears on PublishRank screens and explains the fix; added the optional `PUBLISHRANK_PRIVATE_DIR` constant (and `publishrank_private_storage_base` filter) for hosts that need a custom private folder outside the web root.
* Uninstall now also removes Newsroom (`uj_newsroom_*`) options and the weekly-audit and sitemap-cursor scheduled tasks.
* Added `tools/check-version.sh` and `VersionConsistencyTest` to keep versions consistent.

= 2.9.109 =
* Align the WordPress-loaded admin palette with the PublishRank website brand colors.
* Replace the plugin menu mark with the supplied PublishRank menu icon and refresh asset cache keys.
* Render a blank 404 log table body after the log is cleared.

= 2.9.108 =
* Improved dashboard tool-search feedback: the live result count shows matching tools out of the available total, with localized announcements for assistive technology.
* Corrected the WordPress readme stable tag and outdated guidance about template variables in social metadata.
* Organization/WebSite JSON-LD is now omitted until publication and publisher names are configured, avoiding empty identity nodes and broken graph references; the Schema screen links to the setup wizard when identity is incomplete.
* Kept story-specific Web Story logos out of the site-wide Organization entity and preserved alt text and dimensions for attachment-backed default social images, including CDN URL rewriting.
* Redirect cycle checks now include active regex rules and fail closed when the configured chain-analysis depth is exceeded. Foreign-host source URLs are rejected, and the supported 303 status is selectable in the form.
* Added regression tests for publisher graph readiness and redirect source/cycle safety.
* Added an opt-in SEO ownership handoff: when enabled, PublishRank suppresses its overlapping frontend metadata, hreflang and page JSON-LD only when a supported SEO plugin is detected. Warning-only behavior remains the default; external plugin settings, redirects, sitemaps and standalone schema feeds are not changed.
* Added regression tests for handoff defaults, supported-plugin detection and the separation between saved preferences and effective frontend output.
* Newsroom private attachments now use a writable directory outside DOCUMENT_ROOT, migrate existing uploads without changing stored file IDs, serve only through the authorized download handler, and fail closed for new uploads if private storage is unavailable.
* Added sitewide wp-admin Newsroom pop-up alerts and a recent-notifications inbox for assignment, stage, overdue-reminder and discussion events, with per-user email, in-app and pop-up preferences. AJAX polling and read actions require Newsroom access and a nonce; notification records are scoped to stories the user can still access.
* Added Newsroom export-filter and adjacent-stage regression coverage and stage-change success feedback.
* Moved Newsroom notifications into a dedicated bell tab with server-rendered and live unread counts, clearer dashboard naming, and keyboard-friendly notification badges.
* Restricted role-matrix changes to administrators, added a separately delegable setup-wizard capability, preserved administrator access, and hardened nested role input handling against malformed requests.
* Fixed duplicate breadcrumb schema output by collapsing multiple BreadcrumbList nodes to one canonical list and removing repeated breadcrumb items with the same label and URL.
* Extended the 404 monitor with request normalization, common probe suppression, GET/HEAD filtering, crawler classification, short-lived write locking, 90-day retention, safer referrer/path limits, and searchable/sortable admin summaries.
* Added dedicated 404 Monitor documentation covering privacy, capture exclusions, throttling, retention, filters, redirect validation, dismissal, clearing and troubleshooting in the package, readme and in-dashboard help.
* Added a complete versioned feature inventory for PublishRank 2.9.108, covering all dashboard cards and all Technical Health checks shown in the supplied dashboard reference image.

= 2.9.105 =
* New: Google Preferred Sources tool (PublishRank → Google Preferred Sources), shown with a "New" badge in the menu and on the dashboard until you open it.
* Generates your Preferred Sources link (https://www.google.com/preferences/source?q=DOMAIN) from the site's home URL or an address you enter; paths, queries and fragments are removed, unsafe schemes are rejected and internationalized domains are preserved.
* Shows Google's documented button code (div with google-add-preferred-source-btn plus the official publisher.js script) with Copy, a placeholder preview, optional documented data-theme and data-lang, and Reset to Default.
* Custom Button Builder generates an accessible, independently styled link to your Preferred Sources URL, clearly labelled as a custom link and not Google's official button, because Google documents no custom styling for its own button.
* Advanced HTML editor with warnings, strict allow-list validation, no code execution, and exact saving (code that fails validation is not saved or rewritten).
* Optional automatic placement (off by default): top of page, above, middle or below the article, or footer, on the page types you choose. The button is printed in the normal page flow with its own spacing so it does not overlap theme content. Google's script is added only when Google's button is shown, never in wp-admin.
* One simple panel: choose Default or Custom once, then choose where it appears.
* Shortened the PublishRank sidebar to Dashboard, Newsroom, Settings and Documentation. Every other tool, including all Newsroom screens, is reached from a Dashboard card and remains registered, so direct links keep working.

= 2.9.104 =
* Corrected the default breadcrumb root to "Home" with the site's actual homepage URL. Publication identity remains unchanged in document titles, publisher/WebSite schema, Open Graph, and other SEO output.
* Added an expandable PublishRank admin navigation with keyboard-accessible dropdown groups for Content & publishing, Newsroom, Search & discovery, Measure & maintain, and Settings & support.
* Added distinct Dashicons for PublishRank tools; they follow the menu text color with no custom tile or background.
* Fixed the dashboard authorization regression by retaining all registered PublishRank submenu destinations in WordPress's submenu registry.
* Fixed Schema Aggregator descriptions so SEO template variables such as `%%focus_keyword%%` and `%%excerpt%%` are resolved before public JSON-LD is generated.
* Added the supplied PublishRank brand mark to the packaged assets and documented the navigation/accessibility changes.
* Replaced every icon with the single official PublishRank mark (`assets/images/publishrank-brand-mark.png`) in the admin sidebar, toolbar and its dropdown, screen headers, setup wizard and sitemap view. The extra PNG icon sizes and `publishrank.svg` were removed from the package.
* Removed the custom dark background and the coloured icon tiles from the PublishRank admin dropdown; it now uses WordPress's native submenu colours, so it follows every admin colour scheme. The Dashboard entry shows the PublishRank mark.
* Redesigned the XML sitemap view in the bordeaux-to-pink brand palette: branded header, showcase hero with the PublishRank mark, summary tiles, a content showcase bar with per-type URL shares, colour-coded type cards with URL counts, light and dark modes, and a version footer.
* Made the version consistent everywhere: the Branding fallback and the Newsroom module now follow `PUBLISHRANK_SEO_VERSION` (2.9.104); sitemap view and admin headers display the same number; the readme changelog headings were corrected.
* Fixed the sidebar and dropdown icons not updating or not showing: the menu stylesheet and onboarding stylesheet are now cache-busted by file time (they were cached under the unchanged version number), and the brand mark is drawn as a plain background image instead of a CSS mask, which browsers refuse to paint for cross-origin assets.
* Bumped the internal integrity build marker (not the plugin version) so the file-integrity baseline is rebuilt after this package replaces the earlier 2.9.104 files, which otherwise reported changed PHP files.
* Expanded the documentation: new in-dashboard sections (menu and toolbar, sitemap view, live blogs and Newsroom, versions, troubleshooting) and a new `docs/` folder with guides for sitemaps, branding, navigation, troubleshooting, versioning and a compatibility checklist.

= 2.9.103 =
* Updated the admin palette using a navy/teal analogous foundation with coral action accents and gold attention states.
* Improved contrast and focus colors across dashboard, onboarding, status badges, and tool filters.

= 2.9.102 =
* Added a dashboard Tools Showcase search and category filter and reduced the native PublishRank submenu to essential destinations while preserving direct tool access from the dashboard.
* Changed: visible PublishRank breadcrumbs are enabled after upgrading to 2.9.102, including sites that had the visibility option saved as off. Publishers can still switch them off later from PublishRank > Breadcrumbs.
* Improved: the Breadcrumbs screen now shows a clear recommendation to disable the theme or page-builder's own visible breadcrumb renderer when using PublishRank, to avoid duplicate trails. PublishRank does not silently disable theme settings.
* Added: regression coverage for the visibility checkbox and duplicate-breadcrumb recommendation.

= 2.9.101 =
* Fixed homepage SEO template variables appearing literally in wizard and dashboard configuration summaries.
* Unified administrative homepage-title previews with the frontend template resolver and added a responsive dashboard identity/title summary.

= 2.9.99 =
* Added: PublishRank's publication title and description now override the WordPress Site Title and Tagline on the public website (on by default; Settings > Visible publication identity). Themes, block themes, Elementor widgets, feeds, the REST index and get_bloginfo() show the PublishRank values after a single setup. The values stored in Settings > General are never written or changed, wp-admin and the settings REST route still see the real WordPress values, and a PublishRank value that is empty leaves the WordPress value in place. A notice on Settings > General explains the override.
* Note: the visible-identity hiding from 2.9.98 is a separate switch and is still on by default, so the theme's visible title and tagline stay hidden on screen. Turn "Hide the visible site/publication title" off to show the (now PublishRank-provided) title in the header.
* Added: tests/cases/10_identity_override.php.

= 2.9.98 =
* Changed: the publication (website) title and description are owned by PublishRank. They are read only from PublishRank settings (publication name / Identity name, Identity description) and are never taken from the WordPress Site Title or Tagline: titles and the %%sitename%% / %%site_description%% variables, meta description, Open Graph, WebSite/Organization schema, breadcrumbs, sitemaps and stylesheet, llms.txt, Live Preview and audit e-mails all use the same PublicationProfile values. Nothing is copied from WordPress on install or in the wizard any more; values already saved by earlier versions are kept untouched. When a value is empty the dependent output is left out and PublishRank screens show a notice asking for it.
* Changed: the visible site title and tagline printed by the theme are hidden on screen by default on every public page (both switches default to on; an explicit earlier choice is kept, and each can be turned off in Settings). The rule is visually-hidden instead of display:none, so the text stays available to screen readers and crawlers; SEO output is unchanged. Added Elementor site-title widget selectors and the `publishrank_hidden_identity_selectors` filter.
* Note: sites that never saved the two switches become hidden after the update; turn them off in Settings > Visible publication identity if your header uses the site title as its logo text.
* Added: tests/cases/09_publication_identity.php.

= 2.9.97 =
* Fixed (root causes of missing visible trails): automatic placement only existed for singular views via the content filter, so archives, the posts page and any template that does not run the main-loop content filter (Elementor theme-builder templates, Canvas/Header-Footer page templates) printed nothing while BreadcrumbList JSON-LD was still output. Added placement for classic archives (main loop start), block-theme archives (above the archive title) and block-theme templates without a title block (above post content), plus the documented Elementor hooks `elementor/widget/render_content` (Pro title widgets) and `elementor/page_templates/canvas|header-footer/before_content`. With Elementor loaded, the content filter is also accepted outside the main loop (never during wp_head).
* Fixed: duplicate prevention used one request-wide flag; it is now a per-context registry (main-query object), and the manual-trail check also reads Elementor `_elementor_data` shortcode widgets. A trail printed by a shortcode no longer blocks unrelated contexts.
* Fixed: the shortcode and Breadcrumbs block now respect "Print the breadcrumb trail on the site" (off = nothing). Placement "Structured data only" still allows a hand-placed trail, as before.
* Fixed: the static front page no longer gets a "Home › Home" trail or BreadcrumbList (opt in with the `publishrank_breadcrumb_show_on_front_page` filter); the posts page now gets Home › Posts page.
* Fixed: BreadcrumbList suppression assumed another SEO plugin emitted breadcrumb data whenever its function existed. Yoast SEO and Rank Math are now checked against their own breadcrumb setting; All in One SEO is only released when its option explicitly reads off (option path not verified on a live install). Use the `publishrank_breadcrumb_schema_external_owner` filter to override.
* Fixed: the site check counted the separator `<span class="publishrank-breadcrumb-separator">` as a second renderer (false "multiple trails" warning) and counted the word BreadcrumbList inside text; it now decodes the JSON-LD.
* Improved: the Breadcrumbs site check tests an article, a page and a category archive, shows HTTP status and the settings in force, and separates confirmed facts (settings, markup, a one-time probe note printed by the plugin during that request) from unconfirmed candidate causes. It reports disabled/manual/schema-only settings, unregistered placement, too few items, hooks that never ran, PublishRank markup, other plugin or theme markup, stylesheet/inline-hiding checks, multiple trails, multiple or dangling BreadcrumbList, cache hints and fetch errors. Administrator-only, nonce-protected, rate-limited to one run per 20 seconds, own-site URLs only, never on visitor requests.
* Improved: breadcrumb stylesheet is versioned by file time and scoped to `.publishrank-breadcrumbs` (own row in Elementor/flex containers); no `!important`.
* Added: tests/cases/08_breadcrumb_placement_and_diagnostics.php.

= 2.9.96 =
* Hardened frontend visibility detection for WordPress installations where `is_rest()` is not available.
* Retained the breadcrumb detector improvements for quoted markup and page/post site checks.

= 2.9.95 =
* Breadcrumb site checks now detect both single- and double-quoted class attributes and inspect the latest published post or page.
* Added optional visual-only controls to hide the visible publication/site title and/or description on the home page, pages and posts. SEO metadata, schema, feeds and General Settings values are unchanged.
* Added the new visibility settings to installer defaults, Settings, import/export and configuration snapshots; both controls are off by default.

= 2.9.94 =
* Breadcrumbs screen: added an actual latest-published-post preview using the site's real publication, category, title and URLs, plus a JSON-LD item preview that never includes the visual separator.
* Breadcrumbs screen: exposed look, size, colors, invalid-item recovery, fallback URL/label and local error logging in the same save form.
* Breadcrumb settings now validate and persist all appearance and recovery controls together with the visible trail, separator, placement and schema switches.

= 2.9.93 =
* Fixed literal `\\n` provenance text and duplicate SEO branding markers in frontend HTML.
* Resolved article schema descriptions through the same template resolver as meta, Open Graph and X/Twitter descriptions so `%%variables%%` never reach JSON-LD.
* Treat `%%sep%%` as title-only in descriptions, including older saved description templates, so separators cannot leak into meta descriptions.

= 2.9.92 =
* Added: a grouped "Insert variable" dropdown under the SEO title, meta description, social title and social description fields in the post editor, in the Live Preview snippet editor and on the setup wizard's homepage title and description. It lists every variable the plugin resolves (Site, Content, Author, Taxonomy, Dates, SEO), explains each one and what it returns when empty, inserts the exact token at the cursor without touching the rest of the text, is a native select (keyboard and mobile friendly) and also works in fields the block editor adds later.
* Added: Meta\TemplateVariables, one registry of variables (token, label, description, contexts, fallback). The resolver's list of supported names is now read from it, so the dropdown, help text and resolver cannot disagree. Variables unavailable for a content type (for example categories on pages) are shown disabled with the reason.
* Fixed: social title and social description overrides did not resolve variables, so a template such as %%title%% was printed literally in og:title, og:description, twitter:title, twitter:description and in the REST/preview data. They now go through the same resolver; unknown tokens are dropped and an empty result falls back to the SEO title/description.
* Fixed: the Live Preview snippet editor offered only a legacy "Insert %focus_keyword%" button; it now uses the full dropdown. Help text lists are generated from the registry.
* Added: Breadcrumbs screen (dashboard card "Breadcrumbs"): visible trail on/off, frontend override, separator preset list and custom Unicode separator with live preview, placement and theme hook, first-crumb label, BreadcrumbList on/off, and a site check that reports which breadcrumb renderers the live page prints and warns about duplicate trails or duplicate BreadcrumbList blocks.
* Added: frontend override option (publishrank_breadcrumb_frontend_override, on by default, existing choices untouched). When on, PublishRank's separator is applied through the separator filters of Yoast SEO, Rank Math, WooCommerce and All in One SEO instead of editing HTML. Off leaves those plugins untouched. Extend with the publishrank_breadcrumb_override_filters filter.
* Added: separator presets "»»" and "…".
* Note: PublishRank contains no code that reads All in One SEO settings, so a separator that survives that plugin's removal comes from the theme, cached pages or leftover content. Use Breadcrumbs > Site check to identify the source.
* Added: tests 05 (registry, resolver agreement, social resolution) and 06 (override, separators, detector, screen).

= 2.9.91 =
* Fixed: global SEO title templates and per-post SEO titles/descriptions now share one resolver (Meta\TemplateResolver). Global templates previously supported only 8 variables; both places now support the same 15: %%title%%, %%sitename%%, %%site_description%%, %%category%%, %%primary_category%%, %%author%%, %%date%%, %%modified%%, %%excerpt%%, %%sep%%, %%page%%, %%id%%, %%permalink%%, %%focus_keyword%% and %%focuskw%% (the legacy %focus_keyword% / %focuskw% forms still work). Unknown variables are dropped, values are never re-expanded, and dangling separators left by empty values are removed.
* Fixed: saving %%date%% or %%category%% in a title/description template (post editor, REST, settings, import) corrupted it to "%te%%" / "%tegory%%" because sanitize_text_field() strips percent-encoded sequences. Templates are now sanitized token-safely. The upgrade repairs these two corrupted forms in the global templates and homepage title/description; per-post values already damaged on a site are not rewritten automatically.
* Fixed: the REST/dashboard view of a post's meta description did not resolve variables and could differ from the front end.
* Fixed: spaced breadcrumb separators (for example " › ", "» ") lost their spaces on save, and "no separator" and a non-breaking space could not be stored. Separators are now validated by a dedicated sanitizer: plain text only, up to 10 characters, markup/control/bidi characters removed, invalid input rejected with a notice and the previous value kept.
* Added: grouped breadcrumb separator presets (arrows, angles, slashes and lines, dots and bullets, dashes, other symbols, spaced variants, space / non-breaking space / none), a custom field and a live preview. Existing saved choices keep showing as presets.
* Changed: right-to-left mirroring now applies only to directional separators (arrows and angle brackets), and separators keep their spacing (white-space: pre).
* Fixed: when BreadcrumbList output was off, the WebPage node still pointed at a BreadcrumbList that was not printed. The reference is now only emitted when the list is.
* Added: separate "Organization and WebSite entities" switch (publishrank_org_website_schema_enabled, default on). Turning it off removes those two entities and every reference to them while keeping the WebPage/Article/BreadcrumbList entities valid.
* Changed: every output switch (metadata, schema, organization/website, visible breadcrumbs, BreadcrumbList) is read through one helper, is honoured at run time (including the document title), and treats a missing option as enabled and a saved false-like value as disabled.
* Changed: BreadcrumbList is not added when Rank Math or All in One SEO already prints breadcrumbs (as was already the case for Yoast SEO); the filter publishrank_breadcrumb_schema_external_owner can override this.
* Fixed: with the block-theme automatic placement, a trail placed by hand (block or shortcode) in the post no longer produces a second trail.
* Added: saving any identity, schema, social, breadcrumb, title-template or output-switch option clears per-request caches, bumps the cache epoch and schema-index generation, and fires the publishrank_seo_output_settings_changed action for page-cache plugins.
* Upgrade: safe to repeat; existing explicit on/off choices are kept, missing switches are created enabled.
* Added: automated integration tests (tests/), run against a real WordPress install.

= 2.9.90 =
* Expanded dynamic variables for per-post SEO titles and meta descriptions, including title, site name, category, author, dates, excerpt, separator, page number, post ID, permalink and focus keyword.
* Improved post-editor help and token-based placeholders so reusable templates are easier to compose.
* Expanded title-separator and visible breadcrumb-separator presets while preserving custom separator support.
* Existing frontend schema and breadcrumb controls remain configurable and default to enabled.


= 2.9.89 =
* Added Crawl Optimization settings for removing optional WordPress head metadata, feed discovery links, emoji assets, and selected response headers.
* Added opt-in internal-search cleanup, pretty-search URL normalization, search query length/special-character/spam filters, UTM URL-bar cleanup using the History API, and conservative unknown-query-parameter redirects.
* Added optional robots.txt rules for the WordPress core REST content API namespace and internal search URLs. PublishRank's schema index endpoint remains outside the blocked core REST namespace.
* All new crawl optimization controls default to off; feed endpoints are not forcibly disabled.

= 2.9.88 =
* Added a public, paginated REST schema-discovery index at `/wp-json/publishrank/v1/schema-index` for public, published, non-password-protected content. It lists canonical URLs, schema types, titles, publication/modification dates, and available summaries/images without exposing full article bodies or private content.
* Linked the schema-discovery endpoint from generated `llms.txt` resources when schema output is enabled. The complete JSON-LD graph remains on each canonical content URL.
* Added short-lived response caching and cache-generation invalidation when content changes.
* Deferred Newsroom-related work; this release focuses on AI-oriented schema discovery.

= 2.9.76 =
* Corrected the attribution disclosure to describe the always-on technical source markers accurately and remove references to a visible footer-credit toggle.
* Updated release and security regression tests to match the 2.9.75 package, including the removed remote News feed and admin nonce requirements.

= 2.9.75 =
* Removed the optional live News feed (remote announcements.json request) and its admin screen, prompt, styles and script; the plugin no longer requests remote announcement data or remote images.
* Removed WordPress.org directory asset files (icons, banners, social images) from the plugin package; they are uploaded separately through SVN.
* The sitemap stylesheet (XSL) now embeds the bundled sitemap CSS and JavaScript from the plugin's own local files instead of linking them.
* Removed the optional Publisher Account sign-in (Netlify Identity / Supabase) screen, its admin-screen gate and the related settings, and the hosted Google sign-in broker. Search Console now connects only with your own Google OAuth client ID and secret; Disconnect also revokes the grant at Google.
* Traffic Guard: IPv4-mapped IPv6 addresses now match IPv4 rules; networks broader than /8 (IPv4) or /16 (IPv6) are rejected; genuine search-engine crawlers (verified by reverse and forward DNS) are never blocked unless this is switched off; blocked responses are sent with no-store headers and DONOTCACHEPAGE; the settings screen shows the detected IP/country and warns when your own address would match a block rule.
* Added explicit nonce and capability verification inside every handler that reads submitted data, and the live-preview request.

= 2.9.74 =
* Sitemap stylesheet (XSL) no longer prints inline style/script blocks; its CSS and JavaScript are served from bundled assets/css/sitemap.css and assets/js/sitemap.js.
* Sanitized all remaining $_POST, $_GET, $_REQUEST, $_COOKIE, $_SERVER and $_FILES reads at the point of use (AdminAccess, TrafficGuard, redirects, 404 monitor, import, Baidu, post meta, local SEO, onboarding, live blog, internal links, authentication and Search Console forms).
* Verification files are filtered through a strict tag allow-list before saving and when served, and are output with wp_kses().
* Escaped output late for the editor score ring, live bar, verification meta tags, schema-type dropdown and JSON-LD (JSON-LD no longer uses JSON_UNESCAPED_SLASHES).

= 2.9.73 =
* Losslessly recompressed four shipped PNG/GIF assets while preserving original formats, dimensions, exact decoded pixels, and GIF frame timing.
* Reduced the combined size of the 20 shipped PNG/GIF assets by 134,976 bytes (3.31%); no external image or font services were added.
* Synchronized the plugin header, runtime version constant, and stable tag to 2.9.73.

= 2.9.72 =
* Changed the optional live News JSON source to the official public `announcements.json` in the PublishRank website repository and accepted GitHub Raw’s `text/plain` response while continuing to validate JSON schema.
* Added a branded, dismissible first-activation consent dialog with explicit enable, decline, and not-now choices; no News request occurs unless the administrator opts in.
* Disclosed GitHub Raw, the canonical GitHub source, and the separate Netlify image/link host before opt-in and in WordPress privacy-policy/readme documentation.
* Rendered the top-level sidebar icon as an alpha-masked, solid-white silhouette to match the PublishRank header icon.
* Synchronized the plugin version header, runtime constant, and stable tag to 2.9.72.

= 2.9.71 =
* Added the explicitly opt-in PublishRank News admin screen, backed by a validated and cached live JSON announcements feed, with images, summaries, expandable details and approved links.
* Added capability- and nonce-protected enable, refresh and disable actions, safe feed parsing, strict same-host HTTPS media/link allowlisting, stale-cache fallback and opt-out cache cleanup.
* Added a detailed News feed operations/privacy guide and disclosed the external service in the privacy policy text and WordPress.org readme.
* Synchronized the plugin header, runtime constant and stable tag to 2.9.71.

= 2.9.70 =
* Added optional Netlify Identity linking for existing WordPress administrators; the feature does not create WordPress users or change roles.
* Corrected error-level WordPress coding-standard findings across PHP source.
* Added a PHP-scoped project PHPCS ruleset; warnings stay visible and non-blocking after errors are cleared.
* Updated source-level regression assertions to tolerate harmless formatting changes.

= 2.9.55 =
* Fixed fallback injection of technical provenance and SEO head output for themes that omit `wp_head()`, without duplicate markers.
* Explicitly flush PublishRank-owned frontend fallback buffers at shutdown when safe, without closing buffers owned by other plugins.
* Added direct-web-access guards to standalone regression scripts and aligned version fixtures/fallbacks with 2.9.55.

= 2.9.54 =
* Made technical source provenance markers always-on and removed the administrator toggle.
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


== PublishRank Newsroom ==
PublishRank includes a built-in editorial newsroom for story pitches and assignments, configurable workflow stages, reporter/editor assignments, planning board, calendar, private notes and discussion, attachments, audit history, CSV export, notifications, and publishing handoff to WordPress drafts. The module uses dedicated newsroom capabilities and retains newsroom records and settings when updated. Attachments are stored outside the public document root when the server provides a writable private directory; legacy uploads are migrated on an authorized admin request. New uploads are blocked if private storage cannot be established. Assignment, stage, overdue-reminder, and discussion alerts appear in a dismissible wp-admin pop-up and the Newsroom notification inbox; users can manage email, in-app, and pop-up preferences under Newsroom → My Notifications. Alerts poll while logged into wp-admin and do not request browser notification permission.
