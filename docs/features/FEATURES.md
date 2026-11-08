# Features provided through PublishRank 2.17.0

This inventory documents every feature card and Technical Health check shown on the PublishRank dashboard in the current release. Names follow the dashboard labels. A feature may be optional, permission-gated, dependent on WordPress/theme configuration, or dependent on an external service; those conditions are noted where relevant.

## Search & discovery

| Dashboard feature | What it provides |
| --- | --- |
| **Schema studio** | Configure publisher, article, webpage, breadcrumb and supported media structured data. |
| **Sitemap health** | Check sitemap endpoints, article eligibility and sitemap generation status. |
| **IndexNow** | Configure supported search-engine notifications when eligible public content changes, with verification and submission history. IndexNow does not guarantee crawling or indexing and is not a Google indexing API. |
| **Search Console** | Connect the site's own Google OAuth client for read-only Search Console properties, search performance, Discover, Google News and URL inspection workflows. |
| **Search engines** | Review additional search-engine submission and discovery options. |
| **Google Preferred Sources** | Generate Google's Preferred Sources link and documented button code, with optional custom-link styling and placement controls. |
| **Robots.txt** | Review and manage crawler directives, sitemap references, AI usage guidance and supported robots rules. |
| **Crawl optimization** | Manage optional discovery-link, feed, search and URL-cleanup controls intended to reduce low-value crawl paths. |
| **Breadcrumbs** | Configure visible breadcrumbs, placement, separators, frontend overrides, BreadcrumbList structured data and diagnostics. |
| **Site verification** | Manage supported search-engine and service verification codes. |
| **AI & llms.txt** | Review and configure the optional `llms.txt` and `llms-full.txt` guidance for AI crawlers, including the public schema-discovery link where enabled. |
| **Schema Aggregator** | Publish and verify a complete JSON-LD content feed for AI discovery, with the aggregator endpoint and related controls. |

## Content & publishing

| Dashboard feature | What it provides |
| --- | --- |
| **SEO score** | Review practical content and technical SEO checks across the site. Editorial scores are guidance, not ranking guarantees. |
| **SEO Overrides** | Open a post and manage its search, social, indexing and related SEO fields through PublishRank. |
| **Google Discover image** | Review post SEO image dimensions and create a resized copy without replacing the original media file. |
| **Author expertise** | Manage author credentials and publication expertise information used by supported author and publisher workflows. |
| **Local SEO** | Configure local business identity and location-oriented search signals. |
| **Image optimizer** | Review supported media optimization tools and image-delivery settings. |
| **Publisher toolkit** | Access focused publisher utilities for newsroom publishing and site maintenance, including permission-aware bulk SEO tools where enabled. |
| **Newsroom** | Open the newsroom workspace for assignments, planning, calendar, activity and notifications. |
| **Planning board** | Plan and move stories across the editorial board. |
| **Newsroom calendar** | See scheduled and planned stories by date. |
| **Newsroom activity** | Review the editorial activity history. |
| **My notifications** | Review personal Newsroom notifications in the dedicated notification area. |
| **Newsroom configuration** | Configure Newsroom workflow settings. |
| **Newsroom roles** | Manage Newsroom roles and access. Role assignment and revocation are administrator-controlled; administrators can delegate setup-wizard access to permitted editors. |
| **Internal links** | Review internal-link opportunities and strengthen related content paths. |
| **Link intelligence** | Explore publisher-focused link recommendations and relationship insights. |

## Measure & maintain

| Dashboard feature | What it provides |
| --- | --- |
| **Publisher analytics** | Review local publishing trends and connected search performance. Connected Google data is optional, read-only and aggregated/delayed. |
| **Insights** | Explore editorial and site-level signals that can guide next steps. |
| **Redirects** | Create and review permanent or temporary redirects for changed URLs, with loop and destination validation. |
| **404 monitor** | Review bounded, privacy-conscious not-found history, filter paths and visitor categories, inspect referrers and create validated same-site redirects. The monitor includes normalization, common-probe suppression, crawler grouping, throttled writes, concurrency protection, 90-day retention and a 500-entry cap. |
| **Traffic Guard** | Review optional local IP, trusted-proxy and country-based access rules. This is a local access-control feature, not a replacement for a WAF or DDoS service. |
| **WP Hide Login** | Review optional custom login and administration URL settings, including direct default-path 404 protection where supported. |
| **Configuration history** | Review recorded SEO configuration changes and prior values for change control. |
| **Diagnostics** | Check plugin health, compatibility notices, output ownership and technical configuration. |

## Settings & help

| Dashboard feature | What it provides |
| --- | --- |
| **Setup wizard** | Review core site identity and SEO settings in a guided sequence. Access can be delegated by administrators through the role system; only administrators can grant or revoke delegated setup-wizard access. |
| **Settings** | Manage site-wide SEO defaults, integrations and plugin preferences, including titles, output, indexing, breadcrumbs, infrastructure, News/sitemaps and portability. |
| **Titles & Meta** | Change the shared title separator and edit global title templates without rerunning setup. |
| **Import / Export** | Back up or restore supported PublishRank settings from the existing portability controls. Exported configuration should be kept private. |
| **Documentation & help** | Find explanations of PublishRank features, recommended use, compatibility notes and troubleshooting guidance inside WordPress. |

## Technical Health checks

The dashboard's Technical Health table reports the following checks. A check may show **PASS**, **WARNING** or another review state depending on the current site configuration.

| Check | What it verifies |
| --- | --- |
| **SEO plugin conflicts** | Whether another detected SEO plugin may also be outputting overlapping metadata, schema, canonicals or related SEO signals. |
| **Search engine visibility** | Whether WordPress/site settings allow search engines to index the site. |
| **Pretty permalinks** | Whether permalink and key generated URLs can be served correctly. |
| **HTTPS** | Whether the site URL uses HTTPS. |
| **News sitemap** | Whether the Google News sitemap is enabled and available for eligible content. |
| **Structured data coverage** | Whether Organization, WebSite, WebPage, breadcrumb and eligible media/entity schema coverage is enabled. |
| **Publisher logo** | Whether a publisher logo is available for supported article schema. |
| **Default social image** | Whether a fallback social-sharing image is configured. A warning recommends adding one when it is missing. |
| **Robots.txt** | Whether PublishRank can manage and serve the virtual robots.txt output. |
| **JNews compatibility** | Whether JNews-specific compatibility can be verified or generic WordPress output is being used. |

## Important feature conditions

PublishRank's dashboard is an operational index, not a promise that every feature is active on every site. Some tools require an administrator, saved settings, a supported WordPress route, a compatible theme or plugin, a verified external property, or an explicit opt-in. External integrations such as Google Search Console, IndexNow and Baidu make requests only after their relevant configuration is enabled. Search, ranking, indexing, News, Discover and AI-crawler features are discovery or editorial aids and do not guarantee inclusion, traffic or rankings.

For detailed workflows, see the individual guides in `docs/`, especially the sitemap, 404 Monitor, troubleshooting, navigation, branding, versioning and WordPress compatibility documents. The same information is available under **PublishRank → Help & documentation**.


## New in 2.10.0

- **SEO Modules** (PublishRank → SEO Modules, and a compact section on the Dashboard): toggle cards that switch XML Sitemap, Google News Sitemap, Schema Markup, Meta & Social Previews, Live SEO Score, Internal Links, Breadcrumbs, Redirects, 404 Monitor, Robots.txt Manager, AI & llms.txt and IndexNow on or off. Changes save instantly; each switch writes the same option the feature's own screen uses. Turning the XML Sitemap off hands sitemaps back to WordPress core.
- **SEO Analysis panel** (post editor): floating, draggable, minimizable panel with a live score ring out of 100, rating, content counts, focus keyword, "To improve" and "Passed checks" lists. It updates while you type in both the block and classic editors.
- **Social previews** (Live Preview box): Google, Facebook & LinkedIn and X cards side by side, with title/description length checks.

## New in 2.10.1

- **General Settings** redesign: shared side menu, one card per section, a sticky section bar, "Find a setting" search, switch-style checkboxes and a sticky save bar that flags unsaved changes. Fields and saving are unchanged.
- **Sitemap Hub** (formerly Sitemap Health): XML sitemap master switch, sitemap address with Copy/Open, links to submit in Google Search Console and Bing Webmaster Tools, robots.txt listing status, headline numbers, and instant switches for Pages, Custom post types, Categories & tags, Authors, Google News, Images, Videos and Attachment pages. URL preview and News sitemap diagnostics remain.

## New in 2.11.0

- **Features** (formerly SEO Modules): same toggle cards and switches under a clearer name; filters read All / On / Off.
- **One layout for every screen**: branded header, the same grouped side menu (Search & discovery, Content & publishing, Newsroom, Measure & maintain, Help) and a short plain-language intro on each tool screen.
- **Help & documentation** redesign: three-step start guide, topic filters and search, summary cards with "Read more", direct links to each screen and a common-problems section.
- **Sitemap index browser view** redesign: plain-language explanation, address to submit with Copy and Search Console / Bing shortcuts, "how it works" strip, totals with icons and a "What's in your sitemap" list with one coloured row per content type.

## New in 2.12.0

- **Setup Wizard in the shared layout**: same header and side menu as Features; locked menu entries on a fresh install; a step track that fills up to the current step and links back to finished steps.
- **Wizard usability**: Back saves the step, Enter continues, Skip for now on optional steps, unsaved-changes guard, live image thumbnails, Organization / Person cards that update the form instantly, review tiles with Edit links.
- **Sticky bars fixed** on General Settings (section bar and save bar now stay on screen).
- **Slim progress bar** replaces the full-screen loading overlay.

## New in 2.17.0

- **Focus-keyword scoring (SEO Analysis 3.0):** title, meta description, slug, introduction, subheadings, image alt text, density and distribution checks.

## Added in 2.14.0

- **One look on every tool screen**: cards, tables, fields, tick boxes, switches, tabs and notices inside the shared frame now match Features, Help & documentation and the Setup Wizard.
- **Robots.txt screen fixed**: it now loads completely (save button, live preview, checks, matrix and URL tester) inside the shared frame.
