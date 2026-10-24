# PublishRank feature reference

## Technical metadata

| Feature | Output or location | Notes |
| --- | --- | --- |
| SEO titles | HTML `<title>` | Template variables plus per-post override. |
| Meta descriptions | HTML `<meta name="description">` | Per-post override with content fallback. |
| Canonicals | HTML `<link rel="canonical">` | Primary-host validation and paginated archive support. |
| Robots | HTML robots meta | Search/404/feed noindex defaults and image preview support. |
| Social metadata | Open Graph and X/Twitter | Featured image, default image, and per-post overrides. |
| Public attribution | Always-on technical version/fingerprint provenance markers; only the visible footer credit is optional and controlled in Settings. No promotional head comment or link is forced into public pages. |
| Provenance (optional) | HTML comment and namespaced meta tags | Product, version, and deterministic release fingerprint; no tracking. |

## News publisher features

| Feature | Purpose |
| --- | --- |
| News sitemap | Fresh article discovery for Google News workflows. |
| Article schema | Connected publisher, author, article, image, date, and section graph. Supports NewsArticle variants, technical and scholarly Articles, disclosed sponsored Articles, standard Article, BlogPosting and LiveBlogPosting. |
| Page and archive schema | WebPage, AboutPage, ContactPage, CollectionPage, FAQPage, ProfilePage and SearchResultsPage; defaults are constrained to the right schema family. |
| Advanced content schema | Event, Product, Recipe and Review output with guarded required fields; incomplete data does not produce a misleading primary entity. VideoObject, ImageGallery, AudioObject and PodcastEpisode are also supported. External HTTPS media/CDN URLs are permitted after scheme and credential validation. |
| Editorial controls | Keyphrase, cornerstone, primary category, author, schema, video, and News eligibility fields. |
| Publisher profile | Organization name, type, logo, locale, country, contact, and social profiles. |
| Image/video sitemaps | Additional media discovery for eligible public content. |
| Site Verification | First item in the PublishRank menu. Meta-tag verification for Google, Bing, Yandex, Baidu, Naver, Seznam, Pinterest and Facebook, safe custom tags, and verification-file hosting (upload or paste) with a live crawler-view check, cache purge and conflict detection. |
| IndexNow | Optional automatic publication/update and deletion notifications, key-file verification, individual and bulk post-list actions, manual URL submission, wildcard path exclusions, CSV history export, and bounded retries. Background notifications are debounced and queued into batches of up to 100 URLs per API request, with a bounded queue and batched log writes for lower database overhead. Notifications are shared among participating engines; indexing is not guaranteed and Google does not support IndexNow. |

## Operations

| Feature | Purpose |
| --- | --- |
| Redirect manager | Safe 301/302/303/307/308/410 redirects, bulk import, optional regex. |
| 404 monitor | Bounded broken-URL log and redirect workflow. |
| Robots manager | Sitemap lines, safe custom directives, AI crawler policy, preview. |
| Diagnostics | Conflict and compatibility checks; generated sitemap audit verifies enabled-provider child references and duplicate URLs; administrator-triggered live scan parses homepage/latest-article JSON-LD, checks Article-family dates/fields/canonical alignment, and validates the live sitemap index plus up to ten same-origin children. Network/parser/size-limit cases are Not Tested, not Passed. |
| Publisher Analytics | Local 7/30/90-day SVG graphs for sampled editorial scores and IndexNow responses, daily detail and CSV export; no visitor tracking. It is not a Search Console report. |
| Search Console | Optional Google OAuth using `webmasters.readonly`; verified-property selection, daily performance data, read-only sitemap status inventory and URL Inspection. Requires a Google Cloud project/API and per-site OAuth client; no service account, sitemap write operation or indexing request. |
| Publisher Account | Optional central Netlify Identity linking (Google/GitHub) for an existing WordPress administrator; encrypted session token, per-admin identity binding, and local emergency recovery. It does not create WordPress accounts or change roles. |
| Search Engines & Baidu | Site-specific encrypted HTTPS Baidu ordinary-inclusion endpoint, manual batches up to 2,000 URLs, opt-in first-publication submission and URL-free quota/response history. IndexNow remains the shared notification route for participating engines; Baidu analytics are not claimed. |
| Import/export | Schema-validated settings portability without exporting the IndexNow secret. |
| WP-CLI | Sitemap status, regeneration, and cache clearing. |
| Admin banner | Supplied PublishRank product branding on the dashboard. |

## Integration boundaries

PublishRank owns technical SEO output. The active theme owns templates, layout, navigation, and visual presentation. A second SEO plugin should not emit overlapping technical tags. Verify the final rendered HTML after theme, cache, CDN, and multilingual changes.

Optional external integrations and the specific transmitted data, request triggers, and official policy links are listed under **External Services** in `readme.txt`. Core metadata output makes no remote requests; Publisher Link Intelligence in this release does not check remote link availability.

## Article SEO Intelligence

PublishRank 2.6.0 adds a local Article SEO Intelligence engine. It scores editorial articles from 0–100 across headline, keyphrase, description, structure, readability, media, links and newsroom signals; detects duplicate headlines and focus-keyphrase competition; uses existing Content Graph inbound-link data; generates content-derived suggestions; and supports live editor re-analysis plus progressive batch processing. No third-party API is required. Eligible public editorial custom post types are supported automatically.


### v2.9.50 schema and dashboard updates

Every public page and post receives a connected `ImageObject` when an eligible image fallback exists. Pages default to `WebPage` / Standard Web Page, while posts keep the configured news-article default. Organization identities accept multiple alternate-name variants, one per line or comma-separated. The dashboard and PublishRank toolbar expose the major plugin tools.
