# PublishRank 2.25.1 — Performance report

Measured on the test site (PHP 8.3, SQLite, PHP built-in server, no page cache). Absolute timings on this setup are slower than a tuned production stack; the relative costs are what matter.

## Per-request cost on public pages

| Item | Measurement / behaviour |
|---|---|
| Traffic Guard decision (balanced level, 2 custom rules, monitor mode) | **8.9 µs per request** (2,000 evaluations in a loop) |
| Traffic Guard database writes | Only when a request matches (logged, would-block, blocked, rate-limited); capped per minute |
| Access Shield firewall | A handful of regular expressions on the request URI, only for signed-out visitors |
| Page response time (home, FAQ page, plain page; 3 runs each) | 0.14–0.17 s on this unoptimised local stack |
| FAQ block assets | `faq.css` (7.3 KB) and `faq.js` (1.9 KB) load **only on pages containing an FAQ** (checked: 0 on home and a plain page, loaded on the FAQ page) |
| Source Directory | No front-end code at all; editor script (9.8 KB) loads only in the block editor for public post types |
| Traffic Guard / country picker / sign-in designer / SEO Inspector assets | Admin screens only |
| FAQ structured data | Built from the parsed blocks once per request (memoised) |

## Editor and admin

* Source check of an article with 100 external links: **0.70 ms** (server side, on save). Links scanned per article are capped at 300.
* Source Directory list: one usage count query per row shown (20 rows by default).
* Traffic Guard overview statistics are aggregated in SQL over indexed date columns; the event log is paginated (25 per page by default).
* SEO Inspector requests run only when an editor clicks Inspect (15 s timeout, 3 MB cap).

## Background work

* One daily task purges Traffic Guard events older than the retention setting and expired temporary blocks.
* Update and news checks reuse WordPress's own update data and the cached news feed; nothing is fetched on public page views.

## Not measured

* Behaviour under real concurrent traffic, MySQL/MariaDB query plans, and interaction with page-cache plugins (a cached page bypasses PHP, so Traffic Guard only sees uncached requests — noted on the Protection screen).
