# PublishRank

![PublishRank](assets/images/publishrank-brand-mark.png)

**Publisher-first technical SEO, structured data, sitemaps, and newsroom controls for WordPress.**

PublishRank is an open-source WordPress plugin for news publishers, magazines, editorial teams, blogs, and content-heavy websites. It brings technical SEO controls, connected JSON-LD, Google News and XML sitemaps, redirects, robots management, author expertise, editorial tooling, and diagnostics into one capability-aware administration workspace.

> PublishRank generates and validates technical publishing signals. Search engines and social networks may still rewrite titles, descriptions, images, snippets, and rich-result presentations.

## Current release

- **Version:** 2.9.104
- **License:** GPLv2 or later
- **Requires WordPress:** 6.3 or newer
- **Requires PHP:** 7.4 or newer
- **Repository:** <https://github.com/Umarjaum/PublishRank>
- **Release downloads:** <https://github.com/Umarjaum/PublishRank/releases>

Version 2.9.104 adds an expandable, keyboard-accessible admin navigation, distinct colored Dashicons for every tool, the dashboard authorization fix, and safe resolution of schema description template variables.

## Highlights

### SEO and metadata

- SEO titles and descriptions with shared template variables.
- Canonical URLs, robots directives, Open Graph, and X/Twitter metadata.
- Hreflang and language alternates.
- Live Search, Website, Social, Meta, and JSON-LD previews.
- Per-post and per-page overrides with capability and nonce checks.

### Connected structured data

PublishRank produces a connected JSON-LD graph rather than unrelated duplicate blocks. Depending on content and configuration it can publish Organization, Person, WebSite, WebPage, Article, NewsArticle, BlogPosting, BreadcrumbList, ImageObject, VideoObject, FAQ, Event, Recipe, Review, Product, LocalBusiness, and related entities.

The public Schema Aggregator is available at:

```text
/wp-json/publishrank/v1/schema-aggregator
```

The endpoint includes only public, published, indexable content. Template variables such as `%%focus_keyword%%` and `%%excerpt%%` are resolved before descriptions are returned; unresolved editor tokens are never exposed in the public feed.

### XML and News sitemaps

PublishRank provides a human-readable XSLT view backed by standards-compliant XML:

```text
/publishrank-sitemap.xml
/publishrank-post-sitemap-1.xml
/publishrank-news-sitemap-1.xml
/publishrank-sitemap.xsl
```

The sitemap index includes responsive cards, content-type badges, child-file counts, URL counts, last-modified information, a copy-link action, raw XML access, and a robots.txt shortcut. The readable view is for people; search engines receive the underlying XML.

### Redirects, crawl controls, and diagnostics

- Redirect rules with safe validation.
- Local 404 monitoring and grouped hit data.
- Robots.txt controls and crawl-budget hygiene.
- Sitemap health checks and frontend diagnostics.
- IndexNow and optional Baidu submission workflows.
- Optional traffic controls and WordPress login protection.

### Publisher and newsroom workflows

- Publisher identity and social profiles.
- Author expertise and author schema fields.
- Newsroom assignments, stages, deadlines, calendar, activity, and notifications.
- SEO score cards, internal-link recommendations, and publisher toolkit controls.

## WordPress installation

1. Download the latest ZIP from [GitHub Releases](https://github.com/Umarjaum/PublishRank/releases).
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP, install it, and activate **PublishRank**.
4. Open **PublishRank → Setup Wizard** and complete the publisher identity, site, social, search, and content-scope steps.
5. Review **PublishRank → Settings**, **Schema**, **Sitemap Health**, and **Robots.txt** before production use.
6. Submit `/publishrank-sitemap.xml` to Google Search Console and other webmaster tools when ready.

For an update, back up the site, upload the new ZIP, and allow WordPress to replace the existing plugin. Clear page, object, CDN, and browser caches after activation.

## New admin navigation in 2.9.104

The PublishRank menu is organized into expandable groups:

- **Content & publishing** — SEO score, author expertise, publisher tools, images, internal links, and local SEO.
- **Newsroom** — editorial workspace, board, calendar, activity, notifications, settings, and roles.
- **Search & discovery** — Search Console, sitemap health, IndexNow, verification, schema, breadcrumbs, robots, crawl optimization, and llms.txt.
- **Measure & maintain** — analytics, insights, redirects, 404 monitoring, traffic guard, login protection, configuration history, and diagnostics.
- **Settings & support** — titles and meta, import/export, setup wizard, and documentation.

Each tool has a native Dashicon inside a high-contrast colored tile. The colors are decorative only; the text label remains the authoritative accessible name. Group toggles are real buttons with `aria-expanded` and `aria-controls`, and keyboard focus is visibly styled.

The navigation fix also keeps every registered submenu destination in WordPress's `$submenu` registry. Dashboard cards and toolbar shortcuts therefore remain authorized when they open a direct `admin.php?page=...` URL.

## Breadcrumbs

PublishRank breadcrumbs support Home-first trails, category and parent-page hierarchies, separators, visibility controls, placement, current-page labels, and BreadcrumbList JSON-LD. The visible trail and structured data are generated from the same hierarchy.

Typical article output:

```text
Home → Category → Article title
```

Typical nested page output:

```text
Home → About Us → Editorial Team
```

The publication name remains available for titles, publisher identity, Open Graph, and WebSite/Organization structured data; it is not required to be a breadcrumb item.

## Template variables

The shared resolver supports variables including:

```text
%%title%%
%%sitename%%
%%site_description%%
%%category%%
%%primary_category%%
%%author%%
%%date%%
%%modified%%
%%excerpt%%
%%sep%%
%%page%%
%%id%%
%%permalink%%
%%focus_keyword%%
```

Templates are sanitized without corrupting percent-delimited tokens. Unknown variables are removed rather than printed literally. Descriptions use a safe fallback when the resolved value is empty.

## Architecture

```text
publishrank.php                 Bootstrap, hooks, version metadata
src/Core/                       Installer, branding, output settings, plugin boot
src/Admin/                      Dashboard, settings, onboarding, diagnostics, tools
src/Meta/                       Titles, descriptions, canonicals, robots, templates
src/Schema/                     Connected JSON-LD and public schema endpoints
src/Sitemap/                    XML providers, cache, audit, XSL stylesheet
src/SEO/                        Breadcrumbs, article intelligence, social and editorial SEO
src/Newsroom/                   Newsroom workflow and editorial actions
src/Security/                   Admin access, traffic guard, integrity and headers
assets/css/                     Shipped admin, sitemap, editor and frontend styles
assets/js/                      Shipped admin, editor, preview and navigation scripts
assets/images/                  Local brand and interface assets
tests/                          Standalone regression and integration checks
docs/                           Feature, architecture, privacy and release documentation
```

The plugin has no mandatory build step and ships first-party JavaScript and CSS in readable form. Font Awesome Free is bundled locally where used; the refreshed admin menu uses WordPress-native Dashicons so it does not depend on a third-party icon CDN.

## Development

Clone the repository and inspect the current branch:

```bash
git clone https://github.com/Umarjaum/PublishRank.git
cd PublishRank
```

Useful checks:

```bash
# Run the standalone test suite when a WordPress test environment is configured.
php tests/run.php

# Build an installable ZIP.
python3 tools/build_plugin_zip.py

# PHP syntax check for changed files.
php -l publishrank.php
php -l src/Admin/AdminAssets.php
php -l src/Admin/DashboardPage.php
php -l src/Schema/SchemaAggregator.php
```

The plugin uses WordPress APIs for capabilities, nonces, settings, REST permissions, escaping, URL validation, scheduled work, and cache invalidation. Do not test destructive settings changes on a production database.

## Extension points

Common filters and actions include:

- `publishrank_seo_post_data`
- `publishrank_schema_graph`
- `publishrank_seo_robots`
- `publishrank_seo_meta_box_post_types`
- `publishrank_schema_speakable_selectors`
- `publishrank_breadcrumb_schema_external_owner`
- `publishrank_sitemap_providers`
- `publishrank_seo_after_admin_header`
- `publishrank_seo_admin_toolbar_items`

Review the source and `docs/DEVELOPER-HOOKS.md` before extending output behavior.

## Compatibility and ownership

Use only one plugin as the owner of each public title, canonical, robots, Open Graph, and schema output layer. Running multiple SEO plugins with overlapping output can create duplicate or conflicting metadata.

PublishRank is designed for WordPress 6.3+ and PHP 7.4+. Always stage upgrades, check active theme integrations, clear caches, and review rendered HTML, JSON-LD, robots.txt, and XML sitemaps after updating.

## Privacy and external services

Core metadata, schema, redirects, sitemap generation, and diagnostics run locally. Optional integrations such as Google Search Console, IndexNow, Baidu, or external-link diagnostics make network requests only after an administrator configures or explicitly starts them. Review the provider documentation and your site's privacy obligations before enabling them.

## Release process

1. Update the plugin header and `PUBLISHRANK_SEO_VERSION` in `publishrank.php`.
2. Update `readme.txt` stable tag and changelog.
3. Add a release entry to `changelog.txt` and relevant `docs/` notes.
4. Run syntax checks and the standalone test suite where available.
5. Build the ZIP with `python3 tools/build_plugin_zip.py`.
6. Commit with a descriptive message and create a matching Git tag.
7. Publish GitHub release notes with the ZIP attached.

## License

PublishRank is distributed under the GPLv2-or-later license. See [LICENSE](LICENSE) and [LICENSE.txt](LICENSE.txt).
