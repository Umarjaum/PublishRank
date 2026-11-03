# Troubleshooting

| Symptom | Try |
| --- | --- |
| Sitemap is blank, plain text or 404 | **Settings → Permalinks → Save** to refresh rewrite rules, then reload. |
| Sitemap view looks unstyled | Purge page, CDN and browser caches. The stylesheet is generated at `/publishrank-sitemap.xsl`; make sure no cache or firewall rewrites or blocks it. |
| Sitemap index shows no URL counts | Indexes with more than 40 files skip counting by design. |
| News sitemap is empty | Only eligible, recent, indexable articles appear. An empty sitemap is valid. |
| Menu or toolbar icon missing | Purge caches; confirm `assets/images/publishrank-brand-mark.png` exists and is readable. |
| Old admin colours after an update | Hard-refresh the browser and purge caches. Asset URLs carry the plugin version. |
| Duplicate titles, canonicals or schema | Let only one plugin own each output (see Diagnostics → conflicts). |
| 404 Monitor has no new rows | Confirm the monitor is enabled, test a real missing HTML URL with GET, and check that the response is a true 404 rather than a redirect, cached 200 or firewall response. REST, admin/AJAX/cron, non-GET/HEAD, static-asset and common probe requests are intentionally ignored. |
| 404 Monitor contains fewer entries than expected | It keeps a 90-day rolling history and a maximum of 500 aggregated path/category entries. A global rate limit and short write lock can skip requests during a flood; use server access logs for complete traffic records. |
| 404 Monitor shows many crawler hits | Filter Visitor by **Crawler**, sort by **Most hits**, and prioritize repeated paths with useful referrers. The monitor groups common bot user agents but is not a WAF or DDoS protection system. |
| A redirect cannot be created from a 404 row | Use a same-site path or URL, check that it is not the same as the source, and avoid redirect chains. Off-site targets, empty targets and loops are rejected. |
| Version differs between screens | Re-upload the full ZIP; all version markers are meant to be identical (see VERSIONING.md). |
