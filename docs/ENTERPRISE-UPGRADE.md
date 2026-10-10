# PublishRank Enterprise Sitemap and Breadcrumb Upgrade

## Sitemap architecture

The sitemap layer is provider-based. `SitemapEngine` discovers the built-in post, page, custom-post-type, taxonomy, author, attachment, news, image, and video providers and allows additional providers through `publishrank_sitemap_providers`.

Each provider reports its slug, enabled state, shard count, and renders one bounded shard. The index is generated from the active provider registry; sitemap URLs are not hard-coded in the index.

## Scalability strategy

Post-like providers use indexed WordPress queries with IDs only, deterministic ID ordering, `no_found_rows`, and a 45,000-entry shard size. Taxonomy, author, and attachment providers also use bounded keyset cursor requests. The 45,000 limit stays below the XML sitemap URL limit and leaves room for entries that are filtered after querying.

The engine never loads the complete publication dataset into one PHP request. A shard can be cached and regenerated independently. Sites with more content receive more index entries automatically.

## Cache and invalidation

Cache keys include a site-wide epoch, provider, and shard. A short transient lock prevents concurrent requests from regenerating the same shard. When a lock is held, the last stale shard is returned when available. The existing post/settings invalidation hooks advance the epoch, making old cache keys unreachable without requiring an expensive delete scan.

## Breadcrumb behavior

`BreadcrumbEngine` generates a homepage item, page ancestors, post-type archive item, hierarchical taxonomy ancestors, and the current item from the active WordPress query. It provides accessible `<nav>`/`<ol>` markup, a shortcode (`[publishrank_breadcrumbs]`), and one `BreadcrumbList` graph integrated into PublishRank's existing JSON-LD graph.

## URL safety

Primary publication URLs are normalized to HTTP(S) site roots and local/admin destinations are rejected. Media and CDN transformations validate configured hosts. Canonical overrides are accepted only when their host matches the configured primary publication host, preventing an image/CDN host from becoming an article canonical.

## Operational controls

Administrators can enable or disable provider families and configure standard sitemap post types. WP-CLI commands are registered as:

- `wp publishrank sitemap regenerate`
- `wp publishrank sitemap regenerate --type=post`
- `wp publishrank sitemap regenerate --type=news`
- `wp publishrank sitemap clear-cache`
- `wp publishrank sitemap status`

Diagnostics report provider count, shard count, cache epoch, and the last invalidation marker.
