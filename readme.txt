=== PublishRank ===
Contributors: umarjaum
Tags: seo, sitemap, schema, news, redirects
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 2.20.20
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: publishrank

SEO tools for news publishers: News SEO, sitemaps, schema, metadata, redirects, and technical controls.

== Description ==

PublishRank is a free, open-source WordPress SEO plugin for site owners, bloggers, news publishers, newspapers, magazines and editorial teams. It brings core technical SEO workflows into one publisher-focused plugin and can be evaluated as an alternative to Yoast SEO, Rank Math and other WordPress SEO plugins. Compare the feature set with your current setup before switching; features and workflows are not identical across plugins.

Compatibility target: WordPress 7.1. The plugin remains installable on PHP 7.4+; complete staging checks before production use.

Copyright and project rights: Muhammad Umar Jabbar.

Feature catalog: this release includes the following areas and capabilities. Optional integrations may require administrator configuration and third-party accounts.

**SEO Editor and Content Optimization**
* SEO title editor.
* Meta description editor.
* Primary focus keyword.
* Up to ten secondary keyphrases.
* Synonyms and related keyphrases.
* Live SEO score out of 100.
* Keyword placement analysis.
* Keyword density.
* Keyword distribution.
* Title keyword analysis.
* Description keyword analysis.
* URL slug analysis.
* Opening paragraph analysis.
* Subheading analysis.
* Image alt-text analysis.
* Content readability.
* Article intelligence.
* Content context.
* SEO recommendations.
* Search snippet preview.
* Google desktop and mobile previews.
* Facebook and LinkedIn previews.
* X/Twitter previews.
* Canonical URL.
* Robots directives.
* Open Graph metadata.
* X/Twitter metadata.
* Automatic metadata fallbacks.
* Metadata template variables.
* Per-post SEO overrides.
* Language-specific SEO metadata.
* Gutenberg integration.
* Classic Editor integration.
* Floating SEO analysis panel.
* Movable SEO editor panel.
* SEO analysis sidebar.
* Tabbed SEO editor interface.
* Live keyword field saving.

**Structured Data and Schema Engine**
* Organization.
* Person.
* WebSite.
* WebPage.
* Article.
* NewsArticle.
* BlogPosting.
* ReportageNewsArticle.
* AnalysisNewsArticle.
* OpinionNewsArticle.
* BackgroundNewsArticle.
* Opinion and review-oriented article types.
* ScholarlyArticle.
* TechnicalArticle.
* Sponsored article markup.
* LiveBlogPosting.
* VideoObject.
* ImageGallery.
* AudioObject.
* PodcastEpisode.
* FAQPage.
* Event.
* Product.
* Recipe.
* Review.
* JobPosting.
* LocalBusiness.
* BreadcrumbList.
* Author-related structured data.
* Connected JSON-LD graph.
* Schema aggregation.
* Schema discovery endpoint.
* Schema validation tools.
* Live JSON-LD preview.

**Local SEO — Business Types**
* 134 selectable business options across 14 niches. The options are not 134 independent Schema.org classes.
* 14 business niches.
* Business profile management.
* Business name and legal name.
* Business description.
* Address and geographical coordinates.
* Service-area businesses.
* Hidden street addresses.
* Opening hours.
* Holiday and special hours.
* Telephone and email.
* Website.
* Business logo and photographs.
* Price range.
* Payment methods.
* Accepted currencies.
* Languages.
* Areas served.
* Services and OfferCatalog.
* Booking links and ReserveAction.
* Founding date.
* Tax identification number.
* Parent organization.
* Business-specific properties.
* Restaurants: cuisine, menus and reservations.
* Hotels: check-in, check-out, rooms, official star rating and pets.
* Medical businesses: medical specialties and new-patient status.
* Banks: fees page.
* Shops and car dealerships: brands.
* Venues: capacity and free-entry information.
* Schools and veterinary businesses: appropriate LocalBusiness mappings.
* Funeral homes: LocalBusiness with additionalType where applicable.

**Business options by niche**
* General (3): Local business (generic) (`LocalBusiness`), Professional service (generic) (`ProfessionalService`), Store / shop (generic) (`Store`).
* Food & drink (10): Food establishment (generic) (`FoodEstablishment`), Restaurant (`Restaurant`), Fast food restaurant (`FastFoodRestaurant`), Cafe / coffee shop (`CafeOrCoffeeShop`), Bakery (`Bakery`), Ice cream shop (`IceCreamShop`), Bar or pub (`BarOrPub`), Brewery (`Brewery`), Winery (`Winery`), Distillery (`Distillery`).
* Health & medical (8): Medical business (generic) (`MedicalBusiness`), Medical clinic (`MedicalClinic`), Doctor / physician practice (`Physician`), Hospital (`Hospital`), Dentist (`Dentist`), Optician (`Optician`), Pharmacy / drugstore (`Pharmacy`), Veterinary care (`VeterinaryCare`).
* Beauty & wellness (7): Health & beauty (generic) (`HealthAndBeautyBusiness`), Beauty salon (`BeautySalon`), Hair salon (`HairSalon`), Nail salon (`NailSalon`), Day spa (`DaySpa`), Tattoo parlor (`TattooParlor`), Health club / gym (`HealthClub`).
* Home & construction services (9): Home & construction (generic) (`HomeAndConstructionBusiness`), Electrician (`Electrician`), Plumber (`Plumber`), Heating / ventilation / AC (HVAC) (`HVACBusiness`), Roofing contractor (`RoofingContractor`), House painter (`HousePainter`), General contractor (`GeneralContractor`), Locksmith (`Locksmith`), Moving company (`MovingCompany`).
* Automotive (10): Automotive (generic) (`AutomotiveBusiness`), Auto body shop (`AutoBodyShop`), Car dealership (`AutoDealer`), Auto parts store (`AutoPartsStore`), Car rental (`AutoRental`), Auto repair shop (`AutoRepair`), Car wash (`AutoWash`), Gas station (`GasStation`), Motorcycle dealer (`MotorcycleDealer`), Motorcycle repair (`MotorcycleRepair`).
* Legal, finance & real estate (11): Legal service (generic) (`LegalService`), Attorney / law firm (`Attorney`), Notary (`Notary`), Accountant / bookkeeping (`AccountingService`), Financial service (generic) (`FinancialService`), Bank or credit union (`BankOrCreditUnion`), ATM (`AutomatedTeller`), Insurance agency (`InsuranceAgency`), Real estate agency (`RealEstateAgent`), Employment / staffing agency (`EmploymentAgency`), Travel agency (`TravelAgency`).
* Lodging & travel stays (8): Lodging (generic) (`LodgingBusiness`), Hotel (`Hotel`), Motel (`Motel`), Hostel (`Hostel`), Bed & breakfast (`BedAndBreakfast`), Vacation rental (`VacationRental`), Campground (`Campground`), Resort (`Resort`).
* Retail & shopping (30): Shopping center / mall (`ShoppingCenter`), Bike store (`BikeStore`), Book store (`BookStore`), Clothing store (`ClothingStore`), Computer store (`ComputerStore`), Convenience store (`ConvenienceStore`), Department store (`DepartmentStore`), Electronics store (`ElectronicsStore`), Florist (`Florist`), Furniture store (`FurnitureStore`), Garden store (`GardenStore`), Grocery store (`GroceryStore`), Hardware store (`HardwareStore`), Hobby shop (`HobbyShop`), Home goods store (`HomeGoodsStore`), Jewelry store (`JewelryStore`), Liquor store (`LiquorStore`), Men's clothing store (`MensClothingStore`), Mobile phone store (`MobilePhoneStore`), Movie rental store (`MovieRentalStore`), Music store (`MusicStore`), Office equipment store (`OfficeEquipmentStore`), Outlet store (`OutletStore`), Pawn shop (`PawnShop`), Pet store (`PetStore`), Shoe store (`ShoeStore`), Sporting goods store (`SportingGoodsStore`), Tire shop (`TireShop`), Toy store (`ToyStore`), Wholesale store (`WholesaleStore`).
* Entertainment & nightlife (8): Entertainment (generic) (`EntertainmentBusiness`), Amusement park (`AmusementPark`), Art gallery (`ArtGallery`), Casino (`Casino`), Comedy club (`ComedyClub`), Movie theater / cinema (`MovieTheater`), Nightclub (`NightClub`), Internet cafe (`InternetCafe`).
* Sports & recreation (9): Sports venue (generic) (`SportsActivityLocation`), Bowling alley (`BowlingAlley`), Exercise gym (`ExerciseGym`), Golf course (`GolfCourse`), Public swimming pool (`PublicSwimmingPool`), Ski resort (`SkiResort`), Sports club (`SportsClub`), Stadium / arena (`StadiumOrArena`), Tennis complex (`TennisComplex`).
* Education & child care (7): School / academy / tuition centre (generic) (`EducationalOrganization`), Child care / daycare (`ChildCare`), Preschool / nursery (`Preschool`), Primary / elementary school (`ElementarySchool`), Middle school (`MiddleSchool`), High school (`HighSchool`), College / university (`CollegeOrUniversity`).
* Services & community (7): Funeral home (`FuneralHome`), Dry cleaner / laundry (`DryCleaningOrLaundry`), Self storage (`SelfStorage`), Recycling center (`RecyclingCenter`), Animal shelter (`AnimalShelter`), Library (`Library`), Tourist information center (`TouristInformationCenter`).
* Public services & media (7): Government office (`GovernmentOffice`), Post office (`PostOffice`), Police station (`PoliceStation`), Fire station (`FireStation`), Emergency service (generic) (`EmergencyService`), Radio station (`RadioStation`), Television station (`TelevisionStation`).

**Technical SEO**
* XML sitemap generation.
* Sitemap index.
* Post sitemap.
* Page sitemap.
* Taxonomy sitemap.
* Author sitemap.
* Image sitemap.
* Video sitemap.
* Google News sitemap.
* Sitemap browser.
* Sitemap health checks.
* Sitemap diagnostics.
* Sitemap filtering.
* Sitemap caching.
* Sitemap pagination.
* Robots.txt management.
* Global robots directives.
* Per-post robots directives.
* Noindex and nofollow.
* Crawl optimization.
* Hreflang.
* Breadcrumbs.
* Featured image preloading.
* Resource hints.
* Preconnect.
* DNS prefetch.
* Link prefetching.
* Speculation Rules.
* Feed optimization.
* llms.txt.
* llms-full.txt.
* Schema discovery.
* Site verification.
* Technical health checks.

**Search Engine Integrations**
* Google Search Console.
* Google OAuth.
* Search performance.
* Search queries.
* Search pages.
* Daily and hourly metrics where implemented.
* Discover performance.
* Google News performance.
* URL Inspection.
* Property selection.
* IndexNow.
* Manual URL submission.
* Automatic IndexNow notifications.
* Baidu URL submission.
* Google Preferred Sources.

**Internal Linking and Link Intelligence**
* Internal link recommendations.
* Content relationship analysis.
* Content Graph.
* Orphaned content detection.
* Link opportunities.
* Broken-link detection.
* Redirecting-link detection.
* Redirect-chain analysis.
* Sitewide broken-link checks.
* Scheduled link auditing.
* Per-article link rechecking.
* External-link checking when enabled.
* Link health reporting.

**Newsroom and Editorial Management**
* Newsroom dashboard.
* Story pitches.
* Editorial assignments.
* Editorial workflow.
* Story planning.
* Editorial calendar.
* Planning board.
* Scheduled story management.
* Newsroom activity.
* Notifications.
* Newsroom configuration.
* Role-based newsroom permissions.
* Author expertise.
* Author credentials.
* Publisher Toolkit.
* Bulk SEO editing.
* Image alt-text assistance.
* Publishing workflow.
* Mastodon publishing.
* Mastodon profile verification.
* Author verification using rel="me".
* Fediverse author attribution.
* Sharing history.
* Per-post sharing controls.

**Redirects and 404 Monitoring**
* 301 redirects.
* 302 redirects.
* Redirect management.
* Regex redirects where implemented.
* Redirect validation.
* Redirect-loop detection.
* 404 monitoring.
* Normalized URL paths.
* Browser/mobile/crawler categories.
* Referrer tracking.
* Searchable and sortable logs.
* Noise suppression.
* Bounded storage.
* 90-day retention.
* Redirect creation from 404 entries.

**Private Login**
* Custom sign-in URL.
* Default login-route handling.
* wp-login.php handling.
* wp-register.php handling.
* wp-admin request handling.
* Custom redirect URL.
* Multisite defaults.
* Migration of existing settings.
* Emergency recovery constant:
* Dedicated administration screen.
* Login URL validation.
* Compatibility considerations.

**Integrations and Compatibility**
* Gutenberg.
* Classic Editor.
* WPML.
* Polylang.
* TranslatePress.
* Weglot.
* bbPress.
* BuddyPress.
* Web Stories.
* Podcast functionality.
* JNews adapter.
* Yoast migration.
* Rank Math migration.
* All in One SEO migration.
* SEOPress migration.
* The SEO Framework migration.
* Import/export.
* WP-CLI.

**Analytics, Diagnostics and Publisher Intelligence**
* Search Console performance dashboard.
* Search insights.
* SEO diagnostics.
* Sitemap diagnostics.
* Frontend scanning.
* Schema health.
* Link intelligence.
* Search engine visibility checks.
* Publisher diagnostics.
* Configuration history.
* Configuration snapshots.
* Technical health monitoring.

**Security and Administration**
* Traffic Guard.
* Trusted proxy configuration.
* IP/CIDR controls.
* Country rules.
* Security headers.
* Private Login.
* Role-based access.
* Feature toggles.
* Setup Wizard.
* Dashboard.
* Configuration management.
* Migration & Portability.
* Import/export.
* Admin toolbar.
* Documentation & Help.
* WP-CLI.
* REST API.

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
* Complete feature inventory for this release: [FEATURE-CATALOG-2.20.20.md](https://github.com/Umarjaum/PublishRank/blob/main/docs/FEATURE-CATALOG-2.20.20.md), including Local SEO options grouped into the 14 source-defined niches.
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
The complete category-by-category feature list and source-defined Local SEO business registry are included in the feature catalog above and at [FEATURE-CATALOG-2.20.20.md](https://github.com/Umarjaum/PublishRank/blob/main/docs/FEATURE-CATALOG-2.20.20.md).

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

= 2.20.20 =
* **Local SEO for every kind of business.** 134 selectable business-type options across 14 niches (not 134 independent Schema.org classes), including new Education & child care and Public services & media groups, hospitals, doctors, notaries, ATMs, vacation rentals, ice cream shops, shopping centres, libraries and more.
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
