# PublishRank 2.25.1 — Feature verification report

Test environment: WordPress 7.1.4 (SQLite database drop-in), PHP 8.3.36, Twenty Twenty-Five theme, WooCommerce 11.3.0, PHP built-in web server with 6 workers. A second clean site was used for the fresh-install and upgrade tests. Browser checks used Chromium (Playwright) at 1440 px wide.

Status key: **Passed** = exercised and behaved as expected · **Partial** = core path exercised, some paths not · **Unverified** = not exercised in this environment · **Failed** = defect found and not fixed.

## Automated suites (all run against the live test site)

| Suite | Checks | Result |
|---|---:|---|
| traffic-guard-integration | 70 | Passed |
| identity-country-integration | 53 | Passed |
| v2251-integration (identity scan, icons, updates, custom schema, validator, SEO Inspector incl. redirect safety, Access Shield countries, sign-in designer) | 51 | Passed |
| whats-new-integration | 42 | Passed |
| faq-integration (fetches real pages over HTTP) | 33 | Passed |
| language-test | 29 | Passed |
| source-directory-integration | 29 | Passed |
| woocommerce-analytics-integration | 28 | Passed |
| http-enforcement (live HTTP: blocking, private sign-in address, no address leaks) | 27 | Passed |
| upgrade-integration | 17 | Passed |
| roles-integration | 15 | Passed |
| v225-integration | 15 | Passed |
| **Total** | **409** | **0 failures** |

The suites ship in the development package (`tests/`) and run with `wp eval-file tests/<suite>.php`.

## Feature-by-feature

### Product identity — Passed
* Whole-tree scan (PHP, JS, CSS, docs, translations) finds no third-party login-plugin names or prefixes and no wording that misdescribes where the code comes from. Automated in v2251-integration.
* PublishRank's own older option names (`publishrank_hide_login_*`, `publishrank_wp_hide_login*`) and the `PUBLISHRANK_DISABLE_HIDE_LOGIN` recovery constant are kept only so existing installs migrate and recovery instructions keep working.

### Notifications and update notice — Passed
* Update notice appears from the WordPress.org update data (simulated 2.26.0), with "Go to updates", "What's new" and per-version dismissal; live dot on the menu and toolbar (screenshot).
* New in this round: announcements saying "version X is available" for a version already installed are no longer shown or counted (found on the dashboard during testing, fixed, covered by tests).
* Question-mark help icons: scan confirms no "i" information icons remain.

### Country database — Passed
* 249 ISO 3166-1 entries with alpha-2, alpha-3 and numeric codes; lookups by name, alpha-2 and alpha-3; list parsing; picker renders in Traffic Guard and Access Shield (screenshot).
* Upgrade: old lists containing `XX` keep their valid countries; `XX` is ignored (unknown location has its own setting).

### Traffic Guard — Passed (Partial for real GeoIP)
* Rule evaluation, priorities, AND/OR, test mode, temporary blocks with expiry, rate limiting, levels, crawler handling, allow/block lists, false-positive marking, CSV export: automated.
* Live HTTP: monitor mode never blocks; enforce mode returns 403 with a reference for a UNION SELECT payload and a script payload, a custom rule (curl user agent on /checkout) temporarily blocks the IP, the next normal request is refused until the block is lifted; ordinary pages stay 200.
* Fixed this round: a search for the words "union select" was refused by Access Shield's request firewall; now allowed in both filters. Firewall refusals now appear in Traffic Guard's log with a reference.
* Partial: country rules were tested with simulated country values; no real GeoIP module or CDN header was available.

### Access Shield — Passed (Partial for 2FA/CAPTCHA UI)
* Private sign-in address: the private address shows the sign-in form; `/wp-login.php` (all actions), `/wp-login`, `/wp-admin/`, `/wp-admin/profile.php`, `/wp-signup.php`, `/login`, `/admin`, `/dashboard` return 404 without revealing the address; `admin-ajax.php` stays usable.
* **Security fix found during this verification:** the private address was printed in the page-preloading (speculation rules) exclusions on every public page. It is now only printed for signed-in users. Verified on home, a page, robots.txt, sitemap, llms.txt, feed, REST index and a 404 page.
* `wp-activate.php` on a single site redirects to `wp-login.php?action=register` (WordPress core behaviour), which then returns 404; the private address is not revealed.
* Sign-in page designer: settings save, CSS applied on the real sign-in page (automated fetch), live preview (screenshot).
* Partial: two-factor and CAPTCHA were not exercised end to end in a browser this round (existing 2.25.0 features, unchanged).

### FAQ block and collections — Passed
* Front end: accordion, search, expand/collapse all, one FAQPage per page, questions deduplicated, answers sanitised (automated + screenshot).
* Editor: block renders with drag handles, toolbar, "Add question" and completeness counter (screenshot).
* Collections: list, shortcode column, categories, import/export tools (screenshot). Fixed: the tools panel was being pulled into the notification list.

### Source Directory (new) — Passed
* Matching (domain and subdomains, look-alike domains rejected), classifications, inactive sources ignored, duplicate domains flagged with priority tie-break, usage counting and recount after edits, saving never changes the article, REST permissions (editor 200, subscriber 403, visitor 401), import/export with duplicate skipping and inactive import: automated.
* Block editor panel: summary, "Do not cite" warning, per-link status, suggestions, "Insert link" adds a paragraph only when clicked (browser test + screenshot).
* Unverified: the Classic editor "Sources in this article" box was not opened in a browser.

### SEO Inspector, schema validator and custom JSON-LD — Passed
* Real page inspection with SERP/social previews, page checks and per-entity validation (screenshot).
* Fixed this round: redirects are now followed only within the site; a redirect to another host (e.g. a cloud metadata address) is reported and not fetched (automated).

### Breadcrumbs — Partial (review only)
* Existing 2.25.0 implementation reviewed: block, `publishrank_breadcrumbs()` PHP function, separators, and a BreadcrumbList built from the same items as the visible trail. On the test page the visible trail "Home → Help centre" and the BreadcrumbList validated together in the SEO Inspector.
* No new breadcrumb features were added in 2.25.1.

### WooCommerce — Partial
* Fresh activation alongside WooCommerce 11.3.0 with no PublishRank errors; WooCommerce analytics suite passes.
* Not re-tested: product schema on a real product catalogue with variations.

### Install, upgrade, uninstall — Passed
* Fresh install on a clean site: activates, database version 6, Traffic Guard defaults to Monitor with level Off (nothing blocked until configured).
* Upgrade from the original 2.25.0 with Traffic Guard enabled, blocked IPs and countries, a private sign-in address and an existing FAQ block: version 2.25.1, database 5 → 6, Traffic Guard keeps enforcing (level Off so no new automatic blocking), IP list kept, country lists kept, sign-in address kept, FAQ schema still produced.
* Uninstall: WordPress's own `delete_plugins()` removes the plugin cleanly. (WP-CLI's `plugin uninstall` failed to delete files in this sandbox for every plugin, including an empty test plugin — an environment limitation.)

### Not verified in this environment
* PHP 7.4, 8.0, 8.1 and 8.4 runtime (PHP 8.2 syntax check of all 249 PHP files passed; PHP 7.4 checked by a static scan for 8.x-only syntax and functions only).
* Multisite networks.
* MySQL/MariaDB (tests used SQLite).
* Page-caching and object-caching plugins, CDNs.
* Themes other than Twenty Twenty-Five for the new features.
* Screen-reader testing with real assistive technology (keyboard and markup semantics were reviewed).
