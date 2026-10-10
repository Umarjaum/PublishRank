# PublishRank installation

1. Copy the plugin directory to `wp-content/plugins/publishrank` and run `composer install --no-dev` in production builds that ship the autoloader.
2. Activate PublishRank. Activation registers its sitemap, stylesheet and IndexNow URLs and flushes rewrite rules once; it never alters theme files and never overwrites existing settings.
3. Open **PublishRank → Settings** (new top-level menu with the PublishRank icon) and configure the News sitemap freshness window, publisher logo and default social image.
4. Verify the generated endpoints `/publishrank-sitemap.xml` and `/publishrank-news-sitemap.xml`.
5. Before enabling PublishRank on JNews Twenty, verify the installed version's schema, breadcrumb, and native meta-header controls against `docs/JNEWS-COMPATIBILITY.md`. Do not disable undocumented features.

PublishRank emits technical metadata only. JNews continues to own visual presentation, templates, layouts, headers, footers, archives, and breadcrumb markup. Deactivation is harmless; uninstall removes only `publishrank_seo_*` options.

6. Run **PublishRank → Diagnostics → Run frontend scan** after installing, to confirm your theme or another plugin is not printing duplicate tags.

## Documentation map

- Read `USER-GUIDE.md` for advanced configuration, news SEO, schema, sitemap, redirects, IndexNow, multilingual SEO, provenance, troubleshooting, and the production launch checklist.
- Read `FEATURES.md` for a concise administrator and agency feature reference.
- Read `SECURITY-AND-PROVENANCE.md` for the GPL-compatible attribution and release-integrity model.
- The complete GPLv2 license text is included at the plugin root as `LICENSE.txt`.
