# PublishRank audit inventory

Release audited: **2.9.40**.

This inventory records the source-level trace used for this maintenance release. It does not claim a live WordPress installation test; the sandbox has no connected WordPress site.

| Capability | Administration / editor UI | Persistence | Frontend or operational effect | Disable behavior | Regression coverage |
|---|---|---|---|---|---|
| Site identity and publisher | `src/Admin/OnboardingPage.php`, `src/Admin/SettingsPage.php` | WordPress options under `publishrank_identity_*` and publisher options | `PublicationProfile`, organization/person JSON-LD, publisher fields | Identity nodes use safe site defaults; optional fields are omitted | `schema_meta.php`, `onboarding.php` |
| Article SEO metadata | `src/SEO/AdvancedFeatures.php` | `PostMeta` keys in `src/Meta/PostMeta.php` | `HeadOutput`, `MetaGenerator`, Open Graph, X/Twitter, robots | Empty overrides fall back to current article data | `schema_meta.php`, `smoke.php`, `compatibility.php` |
| Automatic article SEO | `src/SEO/AutomaticArticleSEO.php` | Auto values are computed live (Meta\SeoData), never stored; a one-time cleanup removes legacy stored copies | Title, description, schema and social fallbacks | Manual values win; automatic values remain content-derived | `schema_meta.php` |
| Article schema | `src/Schema/GraphOutput.php`, `SchemaAutomation.php` | Post metadata plus WordPress post/author/media data | One connected JSON-LD graph | `publishrank_schema_enabled` prevents output | `404_schema_robots.php`, `schema_meta.php`, `compatibility.php` |
| News Sitemap | Settings and onboarding news controls | `publishrank_seo_news_*` options and post eligibility metadata | Timestamp-filtered XML provider with a maximum 1,000 URLs per shard and a configurable rolling window | Provider omitted when disabled; regular sitemap remains independent | `sitemap_audit.php`, `sitemap_types_sharding.php`, `compatibility.php` |
| Regular/image/video sitemaps | Settings and sitemap health screens | Sitemap options, post metadata, cache/cursor state | Index, shards, XML response headers, robots discovery | Individual providers can be disabled | `cursor_architecture.php`, `sitemap_types_sharding.php`, `smoke.php` |
| Canonical and robots | Settings and editor fields | Site options and `PostMeta` overrides | One canonical and one robots meta output when owned by PublishRank | Output module can be disabled; invalid cross-host overrides fall back safely | `schema_meta.php`, `404_schema_robots.php` |
| Breadcrumbs | Settings and frontend shortcode/hooks | Breadcrumb options | Accessible breadcrumb markup and connected schema | Disabled or unavailable contexts are skipped | `schema_meta.php` |
| LLMS.txt | LLMS settings page | LLMS options | Endpoint/discovery output where enabled | Endpoint and optional full file are omitted when disabled | `llms_txt.php` |
| Redirects and 404 monitor | Redirects and 404 admin screens | Bounded WordPress options | Redirect controller and grouped diagnostics | Monitoring is opt-in | `404_schema_robots.php` |
| IndexNow | IndexNow admin screen | Key, queue, bounded history options | Optional external submission queue | No requests are sent until explicitly enabled | `indexnow.php` |
| Compatibility | Onboarding, dashboard, diagnostics, JNews adapter | No destructive changes to other plugins | Ownership/conflict status and recommendations | Other plugins are not disabled automatically | `onboarding.php`, `analytics.php` |

## Important implementation decisions

- **Product branding:** visible branding is **PublishRank**. Internal PHP namespaces, constants, option names, and slugs containing `SEO` remain unchanged to preserve upgrades and saved settings.
- **News eligibility:** News Sitemap post types are explicitly configured; public custom post types are not silently treated as news articles. New articles are eligible by default, with an editor opt-out.
- **News window:** the provider and all settings/import paths support up to 168 hours, with a 48-hour default and an available 72-hour choice. Older content remains eligible for the regular sitemap when its provider settings permit it.
- **Sitemap endpoints:** the configured index endpoint is used for the index, shard URLs, rewrite rules, dashboard links, and robots discovery.
- **Conflict handling:** the plugin does not automatically deactivate JNews or another SEO provider. Ownership is surfaced for administrator review.

## Validation boundary

The release was validated with standalone PHP lint and source-level regression tests. A live WordPress request, Gutenberg editor session, JNews installation, rewrite flush, database persistence cycle, and browser-rendered HTML were not available in this sandbox and therefore are not represented as completed live tests.
