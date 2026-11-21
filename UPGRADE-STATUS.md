# PublishRank 2.26.1 — release status

PublishRank 2.26.1 consolidates Gutenberg SEO editing in one sidebar, adds movable-window and live-preview workflows, and improves media URL maintenance, 404 diagnosis, bulk SEO editing, Schema Studio guidance, video sitemap discovery, the dashboard and responsive/accessibility behavior. The release package's database schema advances from version 7 to 8; existing settings are retained and the upgrade adds tables without removing or renaming them.

The supplied verification report documents sandbox testing on WordPress 7.1.5, PHP 8.3 and SQLite. It does not claim production-host testing. PHP 7.4 received static syntax scanning only; WooCommerce, builders, multilingual plugins, real phones and Safari/Firefox were not tested. Automated accessibility checks were spot checks, not a full WCAG audit. See `docs/release-2.26.1/VERIFICATION-REPORT.md` for results, blocked items and limitations.

## Previous release: 2.26.0

2.26.0 added Schema Studio templates and diagnostics, multiple Local SEO locations with previews, and fixes found during its own report-defined testing. Built-in Schema Studio templates shipped switched off so existing structured-data output did not change until a template was enabled. Its test evidence is in `docs/release-2.26.0/`.

## Previous release: 2.25.1

2.25.1 rebuilt Traffic Guard, added the ISO 3166-1 country database, a native FAQ block with collections, SEO Inspector, validated custom JSON-LD, Access Shield refinements, update/news notifications and contextual help.

Upgrading from 2.25.0 retained settings. The 2.25.1 report and its security, performance, compatibility, licensing and installation reports are preserved under `docs/release-2.25.1/`.
