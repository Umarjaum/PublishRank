# PublishRank 2.25.1 — Security review

Scope: all code added or rewritten in 2.25.1 (Traffic Guard, country database and picker, Access Shield routing and sign-in designer, FAQ block and collections, Source Directory, SEO Inspector, schema validator, custom JSON-LD, update notifier) plus high-risk existing paths touched by them (request firewall, page-preloading rules, notification centre). Method: code reading, automated checks and live HTTP tests against a running site.

## Findings fixed in this release

| # | Severity | Finding | Fix | Verified by |
|---|---|---|---|---|
| 1 | High | The private sign-in address was printed in the page-preloading (speculation rules) exclusion list on every public page, so anyone could read it from page source. | The address is only added for signed-in users, who already see it in logout links. | http-enforcement: 8 public URLs checked for the address. |
| 2 | Medium | SEO Inspector followed up to three redirects automatically. A redirect on the site (for example an open redirect) could make the server fetch another host, including internal or cloud-metadata addresses. | Redirects are followed by hand, only to addresses on the same site; anything else is reported and not fetched. | v2251-integration (simulated redirect to 169.254.169.254). |
| 3 | Low (availability) | Access Shield's request firewall refused any request containing "union select", including ordinary searches. | Pattern now requires an actual payload after SELECT (column list, NULL, CONCAT, 0x, `x FROM`). Same change in Traffic Guard. | http-enforcement + traffic-guard tests. |
| 4 | Low | FAQ answers could keep the text content of a removed `<script>`/`<style>` element. | Contents of script, style, iframe, object and template elements are removed before `wp_kses`. | faq-integration. |
| 5 | Low | Traffic Guard probe pattern failed to compile, so the probe check silently never matched. | Pattern corrected. | traffic-guard tests (probe blocked). |

## Checks performed

* **Request forgery and permissions.** Every new `admin-post` handler (8 Traffic Guard, 10 Access Shield, 3 FAQ, 2 Source Directory, 1 update notice) checks both a nonce and a capability. The FAQ bulk-action handler relies on WordPress core's list-table nonce. Duplicating an FAQ collection also checks `edit_post` on that collection.
* **REST.** `/publishrank/v1/sources` requires `edit_posts` (visitor 401, subscriber 403, editor 200 — tested). Source data exposed is limited to directory fields; internal notes are not returned.
* **SQL.** New queries use `$wpdb->prepare()` or `$wpdb->insert()` with typed values; table names come from the plugin's own table map. Event search uses `esc_like()`.
* **Output escaping.** Admin screens escape with `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`; JSON-LD is encoded with `wp_json_encode` and tag-safe flags; the block page shows a reference made only of A–Z and 0–9.
* **Input handling.** IP lists and CIDR ranges are validated (networks wider than /8 IPv4 or /16 IPv6 refused); country lists accept only known ISO codes; colours accept hex, rgb(a) or CSS variables only; custom JSON-LD rejects markup, `@graph`, non-https URLs and reserved site-wide types; uploads are size-limited (2 MB), checked with `is_uploaded_file`, parsed as JSON only, and imported content is sanitised and created as drafts/inactive.
* **No unsafe deserialisation.** New code uses JSON, never `unserialize` on user input.
* **Country data trust.** Country is taken only from a server GeoIP variable, a CDN header from a configured trusted proxy, or a developer filter — never from the browser. Client IP from forwarding headers is only trusted when the connecting address is a configured proxy.
* **Never blocking the site owner.** Traffic Guard skips wp-admin, AJAX, cron, REST, XML-RPC, WP-CLI, feeds, robots.txt, previews and signed-in users with security permissions; verified search crawlers are exempt; new installs start in Monitor mode with level Off; upgrades keep previous behaviour without new automatic blocking.
* **Resource limits.** Event logging is capped per minute (excess is counted, not stored); old events and expired blocks are purged by a daily task; rule count, conditions per rule (10), FAQ items (100), scanned links per article (300) and imported records (2,000) are bounded; SEO Inspector responses are limited to 3 MB with a 15-second timeout.
* **Emergency recovery.** `define( 'PUBLISHRANK_DISABLE_ACCESS_SHIELD', true );` or `wp publishrank shield disable` turns Access Shield off; Traffic Guard can be switched to Monitor from the dashboard or by removing the `publishrank_traffic_guard` option.

## Known limitations

* No backend rate-limiting service exists for the admin screens themselves; Traffic Guard's rate limiting protects public pages.
* Traffic Guard and Access Shield's firewall are application-level filters running inside WordPress; they complement, not replace, a server or CDN firewall.
* Multisite-specific permission paths were reviewed in code but not run on a network.
