# PublishRank

**Advanced technical SEO and publishing controls for WordPress newsrooms, publishers, and content teams.**

PublishRank keeps title generation, canonical URLs, Open Graph metadata, X/Twitter cards, connected JSON-LD, News SEO, sitemaps, redirects, editorial signals, and publishing diagnostics in one WordPress plugin.

> PublishRank is a technical publishing layer. Search engines and social networks may rewrite titles, descriptions, images, snippets, and rich-result presentations.

## Highlights

- Real-time **Website View** and Google SERP Preview for posts, pages, and public custom post types.
- Unsaved editor previews that use the same PublishRank generation path as the public page.
- Desktop and mobile search-result simulations with title pixel and description-length indicators.
- Connected schema graph output with Organization, Person, WebSite, WebPage, Article, NewsArticle, BlogPosting, BreadcrumbList, VideoObject, Product, LocalBusiness, FAQ, Event, Recipe, Review, and related supported entities.
- Per-content schema selection with type-specific fields and JSON-LD preview/validation checks.
- Facebook publisher Page and per-author profile attribution through existing Open Graph output.
- Optional non-destructive social-share image watermarking with cached output, transparent logos, placement, opacity, size, and margin controls.
- Canonical URLs, robots directives, hreflang, News Sitemap, image/video sitemap support, IndexNow, redirects, 404 monitoring, and diagnostics.
- Publisher identity, social profiles, author expertise, and connected `sameAs` data.
- Responsive WordPress admin interfaces with capability checks, nonces, sanitization, and graceful fallbacks.

## Installation

1. Download the latest release ZIP from [GitHub Releases](https://github.com/Umarjaum/PublishRank/releases).
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP, install it, and activate PublishRank.
4. Complete the Setup Wizard to configure publisher identity, site details, language, social profiles, and verification settings.
5. Open **PublishRank → Settings** for ongoing technical SEO controls.

PublishRank does not require a paid API or mandatory external service. Optional integrations can be configured only when needed.

## Editor workflow

Open any supported post or page. PublishRank provides the existing SEO fields, schema controls, editorial fields, and **PublishRank Live Preview**.

The Live Preview includes:

- **Search snippet** — Google-style desktop and mobile preview.
- **Website view** — visitor-facing heading and summary, canonical URL, social image, structured-data entities, and consistency indicators.
- **Social card** — Facebook/LinkedIn and X/Twitter simulations.
- **Meta tags** — resolved metadata and canonical links.
- **JSON-LD** — parsed connected entities, validity status, and copyable JSON-LD.
- **Checks** — actionable warnings and validation information.

Preview requests use unsaved Gutenberg or Classic Editor values and do not save content. Updates are debounced for responsiveness and can be manually refreshed.

## Schema architecture

PublishRank emits one connected JSON-LD graph rather than unrelated duplicate blocks. Existing Organization, WebSite, WebPage, publisher, author, article, image, and breadcrumb entities are reused through `@id` references wherever applicable.

Schema selection is content-aware. PublishRank does not output every schema type on every page, and type-specific entities are emitted only when their required content is complete. Valid JSON-LD is not a guarantee of Google rich-result eligibility.

## Social image watermarking

Watermarking is disabled by default. When enabled under **PublishRank → Settings → Social share image watermark**, PublishRank:

- Preserves the original media file.
- Generates a separate cached copy for social metadata only.
- Supports top-left, top-right, bottom-left, bottom-right, and center placement.
- Supports opacity, relative logo size, and pixel margins.
- Falls back to the original image when the image library or source file is unavailable.
- Regenerates output when the source image or watermark settings change.

The feature processes social metadata images only; it does not alter images displayed in article content.

## Facebook attribution

Configure the official Facebook Page URL in **PublishRank → Settings**. Author profile URLs can be configured in each WordPress user profile under **PublishRank Author Schema**.

PublishRank uses the existing Open Graph output path and avoids duplicate attribution tags. Facebook may ignore authorship metadata or display it differently, so the plugin does not promise a visible Facebook authorship label.

## Developer notes

The plugin keeps its public slug as `publishrank` and its main bootstrap file as `publishrank.php`.

Important namespaces and components:

```text
src/Core/       bootstrap, branding, installer, output registry
src/Meta/       title, description, canonical, robots, social metadata
src/Schema/     connected JSON-LD graph and editor schema suite
src/SEO/        editorial and social SEO features
src/Admin/      dashboard, settings, editor panels, diagnostics
assets/js/      admin and Live Preview behavior
assets/css/     responsive admin and preview styles
```

Useful extension points include:

- `publishrank_seo_post_data`
- `publishrank_schema_graph`
- `publishrank_seo_robots`
- `publishrank_seo_meta_box_post_types`
- `publishrank_schema_speakable_selectors`

## Security and compatibility

PublishRank follows WordPress capability, nonce, sanitization, escaping, and public-URL validation practices. Preview REST requests require permission to edit the selected post. Watermark output is cached in the WordPress uploads directory and never overwrites original media.

Always review generated metadata on a staging site before deploying changes to a high-traffic publication. Keep only one plugin responsible for each public title, canonical, Open Graph, robots, and schema output layer to avoid conflicts.

## License

PublishRank is distributed under the license files included in this repository. See [`LICENSE`](LICENSE) and [`LICENSE.txt`](LICENSE.txt).
