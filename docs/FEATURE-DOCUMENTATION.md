# PublishRank — Feature Documentation

This guide complements the in-dashboard **Documentation & Help** page. Supported admin labels and headings include an `i` help icon with a short explanation.

## Metadata
- **SEO titles and descriptions:** generated from content/templates when custom values are not supplied; editor overrides should be preserved.
- **Canonical URLs:** identify the preferred public URL.
- **Robots controls:** configure index/follow behavior carefully.
- **Social metadata:** Open Graph and X/Twitter preview metadata.

## Sitemaps
- **XML sitemap index:** entry point for enabled sitemap types.
- **News sitemap:** eligible recent news content; inclusion does not guarantee indexing.
- **Image/video sitemaps:** media discovery for eligible content.
- **Sitemap Health:** inspect the generated endpoints after permalink or cache changes.

## Structured data and publishing identity
- **Schema graph:** organization, article, author, breadcrumb and related entity data where eligible.
- **Publisher profile:** publication name, logo, locale, contact and social profile details.
- **Breadcrumbs:** hierarchical navigation; ensure the active theme does not duplicate visible output.

## Publishing tools
- **Article SEO Intelligence:** editorial signals and content-derived suggestions, not ranking guarantees.
- **Redirect manager and 404 monitor:** handle changed URLs and find broken requests. Test destinations and avoid redirect loops.
- **IndexNow:** sends URL change notifications to participating engines; does not guarantee indexing and is not a Google indexing API.
- **Image optimizer:** configure supported image-processing options and verify quality and CDN behavior.
- **Robots and LLMs.txt tools:** manage crawler guidance files and preview the generated directives.

## Diagnostics and maintenance
- **Diagnostics:** inspect compatibility and SEO output after changes.
- **Import/export:** use for controlled settings migration; keep exported files private.
- **Configuration history:** review setting changes; not a substitute for a database backup.
- **Cache refresh:** generated outputs may also be cached by the host, theme, cache plugin or CDN.

## Google Search Console connection removed
The in-plugin Google Search Console OAuth connection screen is removed in this version. Use Google Search Console directly for performance reports and property management. This avoids asking each site owner to create and store OAuth client credentials in PublishRank.

## Compatibility notes
PublishRank should be the owner of the SEO metadata and schema it generates. Avoid duplicate output from other SEO plugins. Theme layout and visual components remain the theme's responsibility. Always check rendered HTML and sitemap responses after major updates.
