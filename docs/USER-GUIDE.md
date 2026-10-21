# PublishRank advanced publisher guide

PublishRank is a technical SEO and news-publishing infrastructure plugin for WordPress. It is designed for editorial websites that need consistent metadata, NewsArticle structured data, Google News discovery, XML sitemaps, redirects, diagnostics, and operational controls without modifying theme templates.

> PublishRank provides SEO infrastructure. It does not guarantee rankings, indexing, Google News inclusion, Discover visibility, traffic, revenue, or eligibility for any third-party platform.

## 1. Requirements and recommended deployment

- WordPress 6.3 or newer.
- PHP 7.4 or newer; PHP 8.1+ is recommended for new production sites.
- Pretty permalinks enabled.
- HTTPS enabled for the public site, media, and canonical URLs.
- A current WordPress, theme, and plugin backup.
- One primary SEO output owner. Do not run multiple plugins that emit competing title, canonical, robots, Open Graph, or JSON-LD tags.

Install on staging first. Test with the active theme, caching layer, CDN, multilingual plugin, editorial workflow, and any existing SEO plugin before production activation.

## 2. Installation and first launch

1. Upload `publishrank` to `wp-content/plugins/`, or install the ZIP from **Plugins → Add New → Upload Plugin**.
2. Activate **PublishRank**.
3. Go to **PublishRank → Settings**.
4. Configure the publication profile before submitting sitemaps to search engines.
5. Save settings once, then open **PublishRank → Diagnostics** and run the frontend scan.
6. Verify these endpoints in a browser and with a crawler:
   - `/publishrank-sitemap.xml`
	   - the configured News Sitemap endpoint shown in PublishRank Sitemap Health
   - `/publishrank-sitemap.xsl`
   - `/publishrank-indexnow-key.txt` when IndexNow is enabled.
7. Submit the sitemap index in Google Search Console and Bing Webmaster Tools.

Activation creates missing defaults without overwriting existing values and flushes rewrite rules once. Deactivation does not delete content. Uninstall removes PublishRank-owned options and transients; export settings before uninstall if a rollback is required.

## 3. Publication profile

Set these values under **Settings → Publication profile**:

| Setting | Recommended value | Why it matters |
| --- | --- | --- |
| Publication name | Official newsroom name | Used in organization schema and News sitemap publication name. |
| Primary website URL | HTTPS canonical site root | Controls organization identity and safe canonical validation. |
| Publisher name | Legal/editorial publisher name | Used by NewsArticle publisher schema. |
| Publisher logo | Stable HTTPS logo URL | Used in publisher and article structured data. |
| Default social image | 1200×630 image | Fallback Open Graph and X/Twitter image. |
| Publisher type | `NewsMediaOrganization` for newsrooms | Matches the structured-data organization type. |
| Language / locale | Site language and locale | Used in schema, Open Graph, and News sitemap output. |
| Country / contact | Accurate publication details | Adds organization context; do not enter sensitive personal data. |
| Social profiles | One public profile URL per line | Adds `sameAs` organization signals. |

Use a stable, crawlable logo. Do not use a CDN or media host as the primary site URL. CDN/media hosts are for assets, not article canonicals.

## 4. Titles, descriptions, canonicals, and robots

PublishRank owns one technical output slot per document and emits:

- Document title.
- Meta description.
- Canonical URL.
- Robots directives.
- Open Graph and X/Twitter tags.
- Article publication and modification metadata.

Available title variables include `%%title%%`, `%%sitename%%`, `%%category%%`, `%%author%%`, `%%date%%`, `%%excerpt%%`, `%%sep%%`, and `%%page%%`. Keep templates readable and avoid repeating the site name multiple times.

Recommended defaults:

- Article: `%%title%% %%sep%% %%sitename%% %%page%%`
- Page: `%%title%% %%sep%% %%sitename%%`
- Archive: `%%title%% %%sep%% %%sitename%%`
- Home: `%%sitename%% %%page%%`

Search, 404, and feed views are automatically treated as `noindex,follow`. `max-image-preview:large` is added to indexable documents to support image previews.

Per-post controls are available in the **PublishRank** editor box:

- SEO title and meta description.
- Canonical URL, restricted to the configured primary host.
- Robots directive.
- Social title, description, and image.
- Focus keyphrase and keyword-density guidance.
- Primary category and cornerstone flag.
- Schema type: NewsArticle/Article subtypes, `BlogPosting`, `LiveBlogPosting`, `FAQPage`, `VideoObject`, `ImageGallery`, `AudioObject`, `PodcastEpisode`, `Event`, `Product`, `Recipe`, or `Review`.
- Type-specific schema details. Event requires a valid start date, venue, city and two-letter country code; Product offer markup requires price and a three-letter currency; Recipe requires ingredients and steps; Review requires the reviewed item and a 0–5 rating. Incomplete advanced details intentionally omit that entity rather than emit misleading JSON-LD.
- Public media URLs may use HTTPS CDN, video, audio or image hosts; unsafe schemes and credentialed URLs are rejected.
- Video thumbnail and ISO 8601 duration.
- News sitemap eligibility.

Do not use `noindex` as a substitute for removing private data. Use WordPress permissions and access controls for confidential content.

## Sitemap types and automatic splitting

The sitemap index separates posts, pages, public custom post types, taxonomies, author archives, attachments, news, images, and videos into their respective provider URLs. Standard providers split at a maximum of 50,000 URL entries per child sitemap. The News Sitemap is intentionally capped at 1,000 news articles per shard. In **Settings → News SEO and sitemaps → Media sitemaps**, choose `all` to scan every public, queryable content type or provide a comma-separated list such as `post,page,news`. Image and video sitemaps include only content URLs with corresponding image or valid video data; they do not merely repeat every post URL.

## 5. News SEO and Google News sitemap

The News Sitemap endpoint is configurable and is displayed by PublishRank Sitemap Health and the dashboard. It includes explicitly configured, recently published, public, indexable news content within the two-day eligibility window.

Recommended newsroom settings:

- Enable the News sitemap.
- Use a 24–48 hour freshness window for normal publishing.
- Use only public post types that contain original news content.
- Keep the maximum URL count within the configured limit.
- Exclude press-release, archive, or non-news categories when appropriate.
- Use the News genre only when it accurately describes the content.
- Enable keywords only when tags/categories are editorially meaningful.

Google News eligibility is determined by Google, not by a sitemap alone. Keep article pages crawlable, clearly authored, timestamped, accessible, and compliant with the publication's policies.

## 5A. Editorial SEO audit

The post editor includes a local editorial audit that checks headline length, meta-description length, article depth, subheadings, featured image, byline, publication/modification dates, and relevant links. It also reports word count and a lightweight reading-ease signal. These are editorial prompts, not ranking rules; journalists should review accuracy, originality, sourcing, legal risk, and reader value before publishing.

## 5B. Publisher Analytics

Open **PublishRank → Analytics** for local 7-, 30-, or 90-day SVG trends, an accessible daily table, and CSV export. Daily score trends are calculated from a capped sample of recent published articles; IndexNow graphs show endpoint responses, not engine-specific indexing. No visitor beacon or article body is sent to an analytics provider. This local dashboard does not report Search Console clicks, impressions, queries, positions, or coverage.

## 6. Structured data

PublishRank emits one connected JSON-LD graph containing organization, website, webpage, and—on posts—an article entity. Depending on the page, it can include:

- `NewsMediaOrganization` or `Organization` publisher.
- Author person, author URL, job title, and image.
- `NewsArticle`, `Article`, or `BlogPosting`.
- Headline, description, dates, section, language, word count, keywords, image, and video.
- BreadcrumbList.
- ItemList for collection pages.
- FAQ entities when supported by the configured automation.

Validate representative homepage, article, category, author, and paginated archive URLs with Google's Rich Results Test and Schema Markup Validator. Resolve warnings that reflect missing publisher logo, author, image, date, or canonical data.

## 7. XML sitemap architecture

The sitemap index is provider-based and can include:

- Posts.
- Pages.
- Public custom post types.
- Taxonomies.
- Authors.
- Attachments, disabled by default.
- News.
- Images.
- Videos.

Sitemaps are sharded, cached, and invalidated after relevant content or setting changes. Use **PublishRank → Diagnostics** to inspect provider status and cache state. For large sites, regenerate through WP-CLI instead of repeatedly loading URLs in a browser.

```bash
wp publishrank sitemap status
wp publishrank sitemap regenerate
wp publishrank sitemap regenerate --type=news
wp publishrank sitemap clear-cache
wp publishrank sitemap validate
wp publishrank sitemap audit --format=json
```

Use **PublishRank → Sitemap Health** for a generated-XML audit, provider/shard/URL counts, lastmod coverage, and a clickable URL preview. The audit also verifies index child references against enabled provider shards and flags duplicate URLs. Run the administrator-triggered live scan under **PublishRank → Diagnostics** to fetch and parse the real sitemap index and up to ten same-origin child sitemaps, including News Sitemap publication dates in the previous 48-hour window. Network failures and unavailable XML parsers are marked Not Tested; oversized responses and off-origin child URLs are not fetched. Under **Settings → News SEO and sitemaps**, configure the canonical endpoint shown in the sitemap UI. Submit only the canonical endpoint to search engines; aliases are for migration compatibility.

## 8. Redirects and 404 monitoring

Use **Redirects** for deliberate URL migrations. Supported response types are 301, 302, 303, 307, 308, and 410. Redirect destinations are validated to prevent open redirects; regex rules are opt-in and should be tested on staging.

Use **404 Monitor** to identify recurring broken paths and create redirects after confirming the correct destination. Keep the log bounded and review retention against the site's privacy policy. Do not redirect every 404 to the homepage; map old content to the closest relevant replacement or return 410 when it is permanently gone.

## 9. Robots.txt and crawler controls

The virtual robots.txt manager can add sitemap discovery lines, validated custom rules, and optional AI-training crawler blocks. Use **Robots.txt** to preview the effective output.

Best practices:

- Never disallow CSS, JavaScript, or image assets needed to render pages.
- Do not use robots.txt to hide confidential information.
- Confirm that a physical `robots.txt` file is not overriding the virtual output.
- Review AI crawler rules with legal, editorial, and licensing stakeholders.
- Check the final file from the public site after cache/CDN changes.

## 10. IndexNow

IndexNow is an optional notification protocol for participating search engines. It does not replace XML sitemaps or Google Search Console, does not guarantee crawling/indexing, and is not supported by Google. Notifications sent to the shared endpoint can be shared among participating engines.

Open **PublishRank → IndexNow** to:

1. Enable or disable automatic notifications and select public post types.
2. View the generated key and public verification URL (`/publishrank-indexnow-key.txt`).
3. Test whether the public key URL returns the expected key.
4. Submit up to 100 same-host URLs in one bulk request.
5. Review the latest 50 URL attempts, HTTP status and response summary, and retry failed URLs.

When enabled, PublishRank queues eligible published content after saves and sends deletion notifications for eligible public posts. Password-protected, non-public and detected noindex content is excluded from automatic publication submissions. Temporary network, rate-limit and server failures receive bounded retries. Hosting must allow WordPress cron and outbound HTTPS requests to `https://api.indexnow.org/indexnow/`.

The key is excluded from settings exports. Do not paste it into public issue trackers or support messages. IndexNow sends the site's host, key, key-location URL and submitted public URL(s); visitor analytics fields and article body text are not part of the request, though the endpoint can observe standard network metadata such as the server IP. Review the [IndexNow terms](https://www.indexnow.org/terms), [Yandex privacy policy](https://yandex.com/legal/privacy/), [Seznam privacy policy](https://o-seznam.cz/ochrana-udaju/) and [Microsoft privacy statement](https://privacy.microsoft.com/en-us/privacystatement). Disable IndexNow in **PublishRank → IndexNow** if the publisher does not want these outbound requests.

## 10A. Google Search Console connection

Open **PublishRank → Search Console** and configure a Google OAuth Web client for this WordPress site. Google requires a Google Cloud project, the Search Console API enabled, and the exact callback URI shown on the screen; the connected Google account must already have access to the property. PublishRank requests only `https://www.googleapis.com/auth/webmasters.readonly`, encrypts OAuth tokens at rest, lists only properties returned to that account, and refreshes aggregated daily clicks/impressions/CTR/position and a read-only sitemap status inventory only when an administrator asks. URL Inspection reads Google’s known index version; it does not inspect the live page or request indexing. This read-only scope does not submit/delete sitemaps. Google data is delayed, aggregated, and may omit low-volume rows. Disconnect deletes local tokens/data; revoke Google consent separately in your Google Account.

## 10B. Publisher Account sign-in

PublishRank ships with the shared Supabase project URL and public publishable key configured centrally; site owners do not need to enter keys. Under **PublishRank → Publisher Account**, the screen shows the exact callback URL for this WordPress site. The shared project must allow that callback in Supabase Auth, and OAuth provider credentials must be configured in the shared project before the corresponding buttons can complete sign-in. Available provider choices include Google, Apple, Microsoft/Azure, Bitbucket, Discord, Facebook, Figma, GitHub, GitLab, Kakao, Keycloak, LinkedIn, Notion, Slack, Spotify, X/Twitter, Twitch, WorkOS and Zoom. A first-time provider sign-in may create a Supabase identity if project sign-ups are enabled; each existing WordPress administrator links that identity through PKCE. PublishRank never creates WordPress users or assigns roles based on email. The extra sign-in gate is optional; local SEO, metadata, schema and sitemap features remain available when it is disabled. If enabled, the gate applies only to PublishRank admin screens; WordPress, the public site and unrelated wp-admin screens remain available. Use the nonce-protected emergency recovery action if Supabase is unavailable. Never use or enter a Supabase `service_role` key.

## 10C. Baidu and shared search-engine notifications

Open **PublishRank → Search Engines** after verifying the site in Baidu Search Resource Platform. Copy the current ordinary-inclusion endpoint from the account’s own submission tool. PublishRank accepts only HTTPS Baidu endpoints for this exact site, encrypts the endpoint (which contains the token), and never shows it again. Manual batches allow up to 2,000 unique same-host URLs; optional automatic submission is limited to newly published public/indexable posts, not edits or deletions. Recent responses retain counts and quota metadata, not the URLs or token. Baidu’s quotas vary and duplicate submissions can consume quota. A successful submission assists discovery; it does not guarantee crawling, indexing or ranking. Baidu analytics and sitemap dashboard data are not exposed by a documented public read API, so PublishRank does not claim to synchronize those metrics. You can separately submit the displayed XML sitemap in Baidu’s platform where your account supports it. IndexNow is a different shared protocol for participating engines and does not include Google or Baidu.

## 10D. Traffic Guard: local IP and country rules

Open **PublishRank → Traffic Guard** to configure the optional network rules. Traffic Guard is off by default. When enabled, it can deny exact IPv4/IPv6 addresses or CIDR networks, allowlist exceptions, and selected country codes. An allowlisted address takes precedence over IP and country denies. Administrator accounts bypass the rules; non-administrator requests follow the configured policy. The feature runs in WordPress and does not store visitor IPs or call an external GeoIP service. Hosting and web-server access logs may still record network addresses under their own retention policies.

Country rules use the `CF-IPCountry` header. They work only when the request's direct peer (`REMOTE_ADDR`) is inside a trusted-proxy CIDR entered on the screen and the proxy supplies a valid country header. For Cloudflare, enable its IP Geolocation setting and confirm the current proxy ranges from [Cloudflare's IP ranges page](https://www.cloudflare.com/ips/). PublishRank reads `CF-Connecting-IP` or a trusted `X-Forwarded-For` chain only when the direct peer is in your configured trusted CIDRs; never add ranges unless your host or CDN confirms them. When the peer is not trusted or country data is missing or malformed, country blocking fails open. Cloudflare's `XX` unknown-country code is blocked only if you explicitly add `XX` to the rules. Cloudflare notes that IP geolocation is an estimate, so do not use it as the sole control for compliance-critical or precise location restrictions; see its [IP geolocation guidance](https://developers.cloudflare.com/network/ip-geolocation/) and [header reference](https://developers.cloudflare.com/fundamentals/reference/http-headers/).

Rules are enforced on public front-end page requests. wp-admin, administrator requests, login and recovery, REST, XML-RPC, cron, feeds, robots.txt, llms.txt, and XML sitemap URLs are exempt to protect publisher operations and search discovery. A broad public-page restriction can still prevent readers and search crawlers from reaching articles, so keep rules narrow and review crawl/indexing reports after enabling them. Traffic Guard is not a WAF or DDoS defense; use the CDN or hosting firewall for volumetric attacks. Test a small rule on staging first and keep administrator access available.

## 11. Multilingual and international SEO

Enable multilingual output only when the site has real language or regional alternates. Provide one `locale=URL` entry per line and an optional `x-default` URL. Validate reciprocal hreflang links and ensure each alternate page has a self-referencing canonical appropriate to the language version.

WPML and Polylang integrations are feature-detected. Always verify generated alternate URLs on representative pages.

## 12. Conflict prevention and diagnostics

PublishRank detects common SEO plugins including Yoast SEO, Rank Math, All in One SEO, SEOPress, and The SEO Framework. A warning means you must choose one technical output owner or disable overlapping modules.

The administrator-triggered frontend scanner requests the site's own homepage and latest article without following redirects. It counts titles/canonicals/descriptions/robots/social tags, parses JSON-LD, checks Article-family fields, date formats and canonical consistency, detects malformed or duplicate entities, and checks live sitemap endpoints. Sitemap checks are limited to ten same-origin children and 2 MiB per response. Run the scan after:

- Theme changes.
- SEO plugin changes.
- CDN/cache changes.
- Multilingual configuration changes.
- Major permalink migrations.

Expected output is one canonical, one description, one title, one robots directive, and one primary JSON-LD graph unless another active integration intentionally owns a slot.

## 13. Provenance and official branding

When enabled in **PublishRank → Settings**, optional technical source provenance adds local-only PublishRank markers to public HTML pages:

- `publishrank-brand` meta tag.
- `publishrank-version` meta tag.
- HTML provenance comment with release fingerprint.

These markers are off by default, do not track visitors or make network requests, and can be disabled at any time. They help publishers and support teams identify the active SEO layer. The administrator dashboard includes the supplied PublishRank banner.

Because the plugin is GPLv2-or-later WordPress software, source copying cannot be technically prevented by PHP. For controlled commercial releases, publish checksums, sign release archives in CI, protect the private source repository, and maintain trademark/copyright notices. See `docs/SECURITY-AND-PROVENANCE.md`.

## 14. Import, export, and rollback

Export settings before major changes. Imports accept PublishRank-prefixed, schema-validated JSON and intentionally exclude the IndexNow secret key. Review imported URLs, redirects, robots rules, and multilingual mappings before saving.

Recommended rollback procedure:

1. Export current settings.
2. Disable cache/CDN page caching for the test window.
3. Restore the previous plugin package.
4. Clear sitemap cache with WP-CLI or diagnostics.
5. Flush permalinks once under **Settings → Permalinks**.
6. Run the frontend scan and validate sitemap endpoints.

## 15. Production launch checklist

- [ ] HTTPS and pretty permalinks are enabled.
- [ ] Publication identity, logo, language, and social profiles are correct.
- [ ] One SEO plugin owns titles, canonicals, robots, social tags, and JSON-LD.
- [ ] Homepage, article, archive, author, search, 404, and feed outputs were reviewed.
- [ ] News sitemap contains only fresh, public, indexable articles.
- [ ] Sitemap index and robots.txt are reachable without authentication.
- [ ] Representative JSON-LD was validated.
- [ ] Redirects and 404 handling were tested.
- [ ] CDN and cache purge rules include SEO head and sitemap responses.
- [ ] IndexNow was tested only if intentionally enabled.
- [ ] Import/export backup exists.
- [ ] Staging scan passes before production activation.
- [ ] Google Search Console and Bing Webmaster Tools have the sitemap index.
- [ ] If you explicitly enabled provenance markers or the footer credit, verify that output in view-source/public pages.

## 16. Support boundaries

PublishRank does not modify theme files, guarantee search results, or replace WordPress security, backups, editorial review, legal review, or platform-specific webmaster tools. Test all production changes on staging and keep a rollback path.

Public HTML source provenance, the optional “SEO Powered by PublishRank” head comment, and the visible “Powered by PublishRank” footer credit are all disabled by default. Each requires an explicit administrator opt-in in **PublishRank → Settings** and can be turned off at any time. These features make no external network requests.


### Publisher editorial genre

PublishRank includes a broad **Publisher editorial genre** taxonomy for newsroom classification, covering breaking news, investigations, exclusives, explainers, opinion, interviews, profiles, live coverage, reviews, service journalism, specialist desks, press releases, transcripts, longform, multimedia, corrections and other publisher workflows.

This editorial taxonomy is intentionally separate from the **Google News genre** field. The Google News field remains available for Google-compatible content-property labeling; the broader publisher taxonomy is stored as a newsroom classification and is not emitted as an arbitrary `news:genres` value.
