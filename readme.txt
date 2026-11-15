=== PublishRank ===
Contributors: umarjaum
Tags: seo, local-seo, sitemap, schema, redirects
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 2.21.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: publishrank

WordPress SEO for every website: metadata, schema, sitemaps, redirects, Local SEO and publishing tools.

== Description ==

PublishRank is a free, open-source WordPress SEO toolkit for businesses and websites of every kind—not only blogs and news sites. It combines sitewide SEO controls with Local SEO for 134 selectable business-profile options across 14 niches. Publisher and newsroom tools are included as specialist workflows, not a requirement to use the plugin.

**What's new in 2.21.0**

* **Movable SEO editor:** Open the SEO fields, previews and analysis in one draggable, resizable window in the block or Classic Editor; it can snap, minimize and adapt to a phone-sized bottom sheet.
* **Consistent scoring:** The editor, toolbar, post list, SEO Score screen, Insights and scheduled audits use the same score.
* **404 Monitor:** Control recording from the screen, review clearer visitor/referrer details, and create redirects from logged paths.
* **RSS and navigation SEO:** Configure feed attribution and previews, and review important pages for indexability, sitemap coverage, menu/internal links, canonicals and descriptions.
* **WooCommerce SEO:** When WooCommerce is active, connect product data to the schema graph, use product metadata and avoid marking product pages as news articles.
* **Redirect updates:** Import/export CSV rules, bulk-delete rules, trace redirect chains and loops, and use regex captures in destinations.
* **Language analysis:** SEO analysis recognizes English, Arabic and Urdu; optional English inclusive-language suggestions are dismissible and do not change text or the score.

**Core WordPress SEO**

* Edit titles, descriptions, focus keyphrases, canonical URLs, robots directives, Open Graph and X/Twitter metadata, with live search and social previews.
* Review content checks and recommendations in the editor, alongside the SEO score and structured-data preview.
* Generate connected JSON-LD for supported site, organization, person, article, product, local-business and media entities.
* Maintain XML sitemaps for supported posts, pages, taxonomies, authors and media, plus Google News sitemap options, robots.txt, breadcrumbs and crawl controls.

**Local SEO for businesses**

* Choose from 134 selectable business profiles across 14 niches, including food and drink, health, beauty, home services, automotive, retail, lodging, legal and finance, education, entertainment, public services and community services.
* Configure business identity, address or service area, hours, holiday hours, contact details, service areas, services, booking links, logo and photos. Supported profiles can add industry-specific details such as restaurant menus, hotel details, medical specialties, bank fee pages, shop brands and venue capacity.
* The 134 entries are profile options mapped to Schema.org types—not 134 independent Schema.org classes. Structured data does not guarantee rankings or rich results.

**Publishing, operations and integrations**

* Use newsroom planning, assignments, editorial stages, calendars, roles and notifications when you manage a publishing team.
* Configure redirects, review local 404 logs, check sitemap health and manage technical site controls.
* Use supported editor, commerce, community, translation and migration integrations. Optional services such as Search Console, IndexNow and Mastodon require administrator configuration and may require third-party accounts.

Back up your site and test updates on staging. Compatibility and output can vary with WordPress, themes, plugins and third-party integrations.

== Installation ==

1. Upload the `publishrank` folder to `/wp-content/plugins/`, or install the ZIP through **Plugins → Add New → Upload Plugin**.
2. Activate **PublishRank** from the Plugins screen.
3. Open **PublishRank** in the WordPress dashboard and complete the onboarding steps.
4. Review metadata ownership, schema, sitemap and robots settings before using the plugin on a production site.
5. Submit the sitemap index in your search engine webmaster tools when ready.

== Screenshots ==

1. The real PublishRank Dashboard in the WordPress admin.
2. The real PublishRank Features screen with the available SEO tools.
3. The real PublishRank Schema settings screen.
4. The real PublishRank SEO Score screen.
5. The real PublishRank Newsroom interface.

== Changelog ==

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

== Upgrade Notice ==

= 2.21.0 =
Adds a movable SEO editor, consistent scoring, 404 monitor improvements, RSS and navigation controls, WooCommerce SEO and redirect updates. Review your theme, feeds and product output on staging after upgrading.
