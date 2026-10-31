# PublishRank 2.9.104 release notes

## Summary

PublishRank 2.9.104 is a navigation, visibility, and reliability release. It updates the uploaded 2.9.103 code baseline, resolves the dashboard deep-link authorization regression, makes the WordPress admin menu easier to scan, and prevents unresolved SEO template tokens from leaking into the public Schema Aggregator.

The follow-up 2.9.104 maintenance commit also makes the breadcrumb root `Home` by default, linked to the actual site homepage. Explicit custom first-label values remain supported, while publication identity stays unchanged in titles, Open Graph, Organization/WebSite schema, and other SEO output.

## User-visible changes

### Expandable admin navigation

The PublishRank sidebar is organized into five task groups:

1. Content & publishing
2. Newsroom
3. Search & discovery
4. Measure & maintain
5. Settings & support

Each group is opened and closed with a real button. The control exposes `aria-expanded`, supports keyboard focus, and does not change the destination URLs or capabilities of the underlying WordPress submenu items.

### Visible tool icons

Every known PublishRank tool receives a WordPress-native Dashicon in a colored tile. Colors are intentionally varied by tool category so icons do not disappear into the WordPress admin menu background. Text labels remain visible and are the accessible names; icons are marked decorative.

### Dashboard access fix

The dashboard cards use direct `admin.php?page=...` links. WordPress authorizes those links by consulting its registered menu and submenu arrays. The previous navigation organizer removed most registered submenu entries to make the native menu compact. That caused WordPress to return its generic “Sorry, you are not allowed to access this page” screen even for administrators with `manage_options`.

2.9.104 keeps all registered destinations in the submenu registry while the JavaScript layer provides the visual grouping and collapse behavior. This separates authorization from presentation and keeps dashboard, toolbar, and native submenu links consistent.

### Schema Aggregator fix

The Schema Aggregator now resolves descriptions through `Meta\TemplateResolver` with a post context. Values such as `%%focus_keyword%%` and `%%excerpt%%` are therefore converted to their real content before the public JSON-LD response is cached. Empty or punctuation-only results fall back to the post excerpt/automatic description path.

### Home-first breadcrumbs

The breadcrumb engine now starts with `Home → ...` rather than the publication name. Category ancestors, parent pages, archive labels, current-page labels, separators, visibility controls, custom root labels, and the shared BreadcrumbList source remain intact. The front page still omits a redundant trail by default.

## Files changed

- `publishrank.php` — version 2.9.104.
- `src/Admin/AdminAssets.php` — enqueue the navigation script.
- `src/Admin/DashboardPage.php` — preserve registered submenu destinations.
- `src/Schema/SchemaAggregator.php` — resolve public description templates.
- `assets/js/admin-menu.js` — dropdown groups and icon mapping.
- `src/assets/js/admin-menu.js` — source mirror of the shipped script.
- `assets/css/admin-menu.css` — menu contrast, dropdown, focus, and icon styles.
- `src/assets/css/admin-menu.css` — source mirror of the shipped stylesheet.
- `assets/images/publishrank-brand-mark.png` — supplied brand asset.
- `README.md`, `readme.txt`, `changelog.txt` — documentation and release metadata.

## Validation

Completed in the release workspace:

- JavaScript syntax validation with `node --check assets/js/admin-menu.js`.
- Existing admin JavaScript syntax validation with `node --check assets/js/admin.js`.
- Python build-script compilation with `python3 -m py_compile tools/build_plugin_zip.py`.
- Version consistency check for the plugin header, runtime constant, WordPress stable tag, and changelog.
- Asset mirror synchronization check.
- Git working-tree review before commit.

PHP runtime checks should be run on a PHP-enabled WordPress/staging environment because the current build sandbox does not include the `php` executable. The repository’s `tests/run.php` and the PHP syntax commands are documented in the main README.

## Upgrade instructions

1. Back up the site and database.
2. Install the 2.9.104 ZIP from the GitHub release.
3. Activate the updated plugin if WordPress deactivated it during replacement.
4. Clear page/object/CDN caches.
5. Open **PublishRank → Dashboard** and expand each navigation group.
6. Open at least one dashboard card from each group to confirm direct links work.
7. Inspect the public Schema Aggregator and confirm no `%%...%%` tokens appear.
8. Review the sitemap index and submit the canonical sitemap URL if needed.
