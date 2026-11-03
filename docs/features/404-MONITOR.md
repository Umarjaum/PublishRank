# 404 Monitor

PublishRank's 404 Monitor records a bounded, privacy-conscious history of public URLs that return a WordPress 404. It is designed to help editors find broken internal links, old campaign URLs, mistyped addresses and crawler-discovered failures without becoming a visitor analytics system.

## Enable the monitor

Open **PublishRank → Settings**, enable **Record a capped rolling 404 log**, and save. The monitor is opt-in. When it is disabled, existing entries remain available until an administrator clears them, but no new entries are captured.

Review captured requests at **PublishRank → 404 Monitor**. The screen is available to administrators and is also linked from the dashboard's broken-links card.

## What is recorded

Requests are aggregated by:

- the normalized URL path, such as `/news/example-story`;
- broad visitor category: **browser**, **mobile** or **crawler**;
- total hit count;
- first and last seen timestamps;
- the latest useful referrer URL, with its query string and fragment removed.

The monitor does not store visitor IP addresses, cookies, request bodies or full query strings. Referrers are limited in length and retain only the scheme, host, port and path. Treat referrer paths as site data: an external site may still include identifying information in a path, so follow your privacy and retention policies.

## Robustness safeguards

The monitor is deliberately bounded and best-effort:

- URL paths are normalized, duplicate slashes are collapsed, and stored paths are length-limited.
- Only `GET` and `HEAD` 404 requests are considered. Admin, AJAX, cron and REST requests are excluded.
- Common static assets and infrastructure probes are ignored, including CSS, JavaScript, source maps, images, fonts, XML/text assets, `wp-json`, `xmlrpc.php`, `.env`, `.git` and vendor probe paths.
- User agents matching common bot, spider, crawler, uptime-monitor, headless, `curl` or `wget` patterns are grouped as crawlers rather than stored individually.
- A short-lived write lock reduces lost updates when several 404 responses arrive at the same time.
- A global rate limit prevents a scanner from causing an option write for every request.
- Entries are retained for 90 days after their last sighting and the log stores no more than 500 entries. When the cap is reached, the least recently seen entries are evicted.
- Repeated hits for the same path and visitor category are counted rather than stored as separate rows.

Because it is a bounded monitor, it may intentionally skip requests during a flood or while another request is updating the log. It should not be treated as a complete access log or security event log.

## Review and filter entries

The monitor shows the most frequent paths first by default. Use the controls above the table to:

- search URL paths;
- show all visitors, browsers, mobile visitors or crawlers;
- sort by most hits or most recent activity;
- move through paginated results while retaining the selected filters.

The summary reports the number of matching paths and the aggregated hit count. Use **Open URL** to inspect the public response and **Create page** when the missing address represents genuinely new content rather than a moved URL.

A 404 is not automatically a problem. Some URLs should remain 404, such as deleted content with no useful replacement, malformed scanner requests and retired endpoints that should not be recreated.

## Create a redirect

For a URL that has moved permanently:

1. Enter a destination path such as `/new-section/story` or a full URL on the same site.
2. Select **301**.
3. Submit the row.

PublishRank validates the destination through the Redirect Manager. It rejects empty destinations, off-site targets and direct redirect loops. After the redirect is created, the 404 entry is removed from the monitor.

Use a redirect only when a replacement page exists. Avoid chains such as old URL → intermediate URL → final URL. For content that is permanently gone and should not be replaced, use the Redirect Manager's appropriate gone status instead of inventing a page.

## Dismiss and clear

Use **Dismiss** to remove one reviewed entry without creating a redirect. Use **Clear entire log** to remove all current entries. Both actions require administrator capability and WordPress nonce verification.

Clearing the log does not disable the monitor. Disable it separately in Settings if you do not want new entries captured.

## Troubleshooting

If no new rows appear:

1. Confirm the monitor is enabled in **PublishRank → Settings**.
2. Test a genuinely missing HTML URL with a `GET` request in a private browser window.
3. Confirm the response is a real 404 and not a redirect, cached 200 page or firewall response.
4. Remember that static assets, REST requests, admin/AJAX/cron requests and common probe paths are intentionally excluded.
5. Purge page/CDN caches if a previously cached response is being served.

If the monitor fills with crawler traffic, use the visitor filter and prioritize paths with a meaningful referrer or a high repeated hit count. The monitor groups crawlers and applies throttling, but it is not a replacement for a WAF, rate limiter or server access log.
