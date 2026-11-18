# PublishRank 2.25.1 — Feature catalogue and module guide

New or rebuilt in 2.25.1 are marked **(2.25.1)**. Everything else continues from earlier releases unchanged.

## Security

### Access Shield
PublishRank's own sign-in protection module (PublishRank → Access Shield).
* Private sign-in address; default routes (`wp-login.php`, `wp-admin` for signed-out visitors, `wp-signup.php`, guessable slugs) return 404. Password-protected post forms and `admin-ajax.php` keep working. **(2.25.1: address no longer exposed in public page code.)**
* Brute-force lockouts, two-factor (TOTP with QR code, backup codes), CAPTCHA (Turnstile, hCaptcha, reCAPTCHA), honeypot, IP and **country (2.25.1 picker)** allow/deny rules, session limits and idle sign-out for privileged roles, password strength and optional breached-password check, request firewall, security headers, XML-RPC and user-enumeration controls, security log, live verification with signed reports.
* **Sign-in page designer (2.25.1):** logo, background image with darkening, colours, typeface, form position, corner radius, shadow, heading, welcome message, footer note, hide "Go to site" and language switcher; live preview with contrast warning. Never changes form fields or protections.
* Recovery: `define( 'PUBLISHRANK_DISABLE_ACCESS_SHIELD', true );` in `wp-config.php`, or `wp publishrank shield disable`; `wp publishrank shield unlock <ip>`; `wp publishrank shield 2fa-reset <user>`.

### Traffic Guard (2.25.1, rebuilt)
PublishRank → Traffic Guard. Tabs: Overview, Events, Rules, IPs & countries, Protection, Test a request.
* **Modes:** Monitor (records "would block", blocks nothing) and Enforce.
* **Protection levels:** Off · Low (logs probes for secret, backup and admin-tool files without blocking) · Balanced (blocks those probes plus injection payloads and scanner tools) · Strict (+ empty user agents and script clients on page requests).
* **Rule builder:** up to 10 conditions (IP/CIDR, country, path, query, user agent, referrer, method, visitor type) with AND/OR, operators (equals, starts with, ends with, contains, does not contain, matches pattern, and IP/CIDR or country lists), priority, action (block, temporary block for N minutes, allow, log only) and Test mode that records matches without acting.
* **Lists:** allow list (checked first), block list, country block/allow-only modes with the country picker, temporary blocks with expiry.
* **Rate limiting:** requests per window per IP with temporary block on breach.
* **Visitor types:** person (browser), search crawler (DNS-verified Google, Bing and others are never blocked), AI crawler, tool or script, other bot, empty user agent.
* **Event log:** search, filters (decision, country, dates, false positives), sorting, pagination, CSV export, per-event false-positive marking, block-24h and allow-IP actions, references shown on the block page. Access Shield firewall refusals also appear here **(2.25.1)**.
* **Overview:** status banner, KPIs with period comparison, hourly/daily timeline, anomaly alert, reasons chart, country map and list, most active IPs, visitor types, quality checks.
* **Rule tester:** enter IP, country, path, user agent, method and referrer to see the decision and the step-by-step reasoning, without logging.
* **Safety:** never runs for wp-admin, AJAX, cron, REST, XML-RPC, feeds, robots.txt, previews or signed-in administrators; new installs start in Monitor with level Off; unknown country is allowed by default.

### Country database (2.25.1)
`PublishRankSEO\Core\Countries` — 249 ISO 3166-1 entries (English name, alpha-2, alpha-3, numeric). Lookup by name, alpha-2 or alpha-3; list parsing for pasted values (`RU, KP, IRN`). Searchable multi-select picker (type a name or code, paste a list, remove chips, clear all, keyboard support). Country of a visitor comes only from trusted server-side sources (server GeoIP variables, a CDN header from a trusted proxy, or the `publishrank_ip_country` filter) — never from the browser. Maintenance: names and codes are in one PHP array; update from the ISO 3166 maintenance agency notices.

## Content and newsroom

### FAQ block and collections (2.25.1)
* Block **PublishRank FAQ**: add/remove/duplicate/reorder (drag-and-drop or buttons), templates, bulk edit, accordion or list layout, open first/all, one open at a time, icons (chevron, plus/minus, triangle, none) and position, separators, colours, radius, spacing, padding, question size and weight, heading levels, style presets (Boxed, Minimal, Bold), optional search box and expand/collapse-all buttons, structured-data switch, editor preview.
* **Collections** (PublishRank → FAQ Collections): reusable sets with categories, duplication, JSON import (as drafts) and export, shortcode column.
* Shortcode: `[publishrank_faq collection="ID" title="" open="first|all|none" limit="" layout="accordion|list" search="1" controls="1" schema="1"]`. PHP: `\PublishRankSEO\Schema\FAQModule::render_items( $items, $args )`.
* Structured data: the page's WebPage also becomes FAQPage when visible questions exist; questions are deduplicated and printed once per page; no Google rich-result promise is made.

### Source Directory (2.25.1, new)
PublishRank → Source Directory.
* Fields: name, website address (domain + subdomains matched), description, categories, logo, editorial classification (Primary source, Official or government body, News agency, News publisher, Research or academic, Reference, Company or organisation, Use with caution, Do not cite), priority, internal notes, active (published) or inactive (draft).
* List: search by name, domain or notes; filter by classification and category; sort by name, domain or priority; usage counts; duplicate-domain notice; bulk actions; JSON import (as inactive, duplicates skipped) and export.
* Block editor "Sources" panel: summary of linked websites, warnings for "Use with caution"/"Do not cite", "Not in your directory" flags, topic-aware suggestions, search, Insert link (adds a paragraph only on click), Copy address.
* Classic editor: "Sources in this article" box.
* Classifications are the newsroom's own labels; PublishRank never calls a source verified or authoritative. No structured data is added from the directory.
* Not to be confused with **Preferred Sources** (the Google Search "preferred source" reader button), which is a separate screen.

### Breadcrumbs (existing; reviewed)
Block **PublishRank Breadcrumbs**, `[publishrank_breadcrumbs]`, `publishrank_breadcrumbs()`; separators and presets, home label, post type and taxonomy trails, archives, search, author and date, WooCommerce trails. The BreadcrumbList JSON-LD is built from the same items as the visible trail and printed once.

## Search and structured data

### SEO Inspector (2.25.1)
PublishRank → SEO Inspector, or **Inspect SEO** in the toolbar on any page. Search-result and social-card previews, HTTP status, canonical, robots, language, hreflang count, H1, and validation of every JSON-LD entity (required/recommended properties, URL/date/duration formats, duplicate singletons). "Check code" mode validates pasted JSON-LD or HTML. Links to Google's Rich Results Test and the Schema.org validator. Only fetches pages on this site.

### Schema engine
Organization/NewsMediaOrganization, LocalBusiness, WebSite, WebPage family, Article/NewsArticle/BlogPosting, BreadcrumbList, FAQPage, HowTo, Event, Person, VideoObject, ImageObject, Recipe, JobPosting, Course, Product/ProductGroup/Offer with WooCommerce, Review/AggregateRating where eligible, live-blog, **Custom schema (JSON-LD) (2.25.1)** with validation. Duplicate detection keeps a single WebSite, BreadcrumbList and FAQPage.

### Other SEO modules (unchanged in 2.25.1)
Titles, descriptions, canonicals, robots, Open Graph/X, XML/news/image/video sitemaps, RSS controls, IndexNow, crawl optimisation, internal links, broken links, redirects and 404 monitor, local SEO, hreflang, llms.txt, editorial and article analysis, Publishing Desk, analytics, WooCommerce SEO, migration from other SEO plugins, roles and permissions, theme masking, WP-CLI and REST.

## Notifications (2.25.1)
Update-available notice (WordPress.org data or website announcement) with per-version dismissal; live dot on the menu, News & What's New and the toolbar for unread news, announcements or updates; announcements about versions you already run are hidden; question-mark help icons throughout.
