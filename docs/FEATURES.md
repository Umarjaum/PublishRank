# PublishRank feature reference

## Technical metadata

| Feature | Output or location | Notes |
| --- | --- | --- |
| SEO titles | HTML `<title>` | Template variables plus per-post override. |
| Meta descriptions | HTML `<meta name="description">` | Per-post override with content fallback. |
| Canonicals | HTML `<link rel="canonical">` | Primary-host validation and paginated archive support. |
| Robots | HTML robots meta | Search/404/feed noindex defaults and image preview support. |
| Social metadata | Open Graph and X/Twitter | Featured image, default image, and per-post overrides. |
| Provenance | HTML comment and namespaced meta tags | Product, version, and deterministic release fingerprint; no tracking. |

## News publisher features

| Feature | Purpose |
| --- | --- |
| News sitemap | Fresh article discovery for Google News workflows. |
| NewsArticle schema | Connected publisher, author, article, image, date, and section graph. |
| Editorial controls | Keyphrase, cornerstone, primary category, author, schema, video, and News eligibility fields. |
| Publisher profile | Organization name, type, logo, locale, country, contact, and social profiles. |
| Image/video sitemaps | Additional media discovery for eligible public content. |
| IndexNow | Optional automatic publication/update and deletion notifications, key-file verification, individual and bulk post-list actions, manual URL submission, wildcard path exclusions, CSV history export, and bounded retries. Background notifications are debounced and queued into batches of up to 100 URLs per API request, with a bounded queue and batched log writes for lower database overhead. Notifications are shared among participating engines; indexing is not guaranteed and Google does not support IndexNow. |

## Operations

| Feature | Purpose |
| --- | --- |
| Redirect manager | Safe 301/302/303/307/308/410 redirects, bulk import, optional regex. |
| 404 monitor | Bounded broken-URL log and redirect workflow. |
| Robots manager | Sitemap lines, safe custom directives, AI crawler policy, preview. |
| Diagnostics | Conflict detection, compatibility checks, sitemap status, frontend scan. |
| Import/export | Schema-validated settings portability without exporting the IndexNow secret. |
| WP-CLI | Sitemap status, regeneration, and cache clearing. |
| Admin banner | Supplied PublishRank product branding on the dashboard. |

## Integration boundaries

PublishRank owns technical SEO output. The active theme owns templates, layout, navigation, and visual presentation. A second SEO plugin should not emit overlapping technical tags. Verify the final rendered HTML after theme, cache, CDN, and multilingual changes.

## Article SEO Intelligence

PublishRank 2.6.0 adds a local Article SEO Intelligence engine. It scores editorial articles from 0–100 across headline, keyphrase, description, structure, readability, media, links and newsroom signals; detects duplicate headlines and focus-keyphrase competition; uses existing Content Graph inbound-link data; generates content-derived suggestions; and supports live editor re-analysis plus progressive batch processing. No third-party API is required. Eligible public editorial custom post types are supported automatically.
