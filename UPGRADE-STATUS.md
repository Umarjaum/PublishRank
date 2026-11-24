# PublishRank 2.26.2 — release status

2.26.2 is the audit-driven release: SEO editing is gated by the Edit SEO permission, the block-editor save bridge no longer replays other plugins' save actions, the Classic Editor gets the full seven-box dock, a Media tab with image details and licence metadata ships in both editors, previews add Bing, LinkedIn and WhatsApp, and a first-class SEO REST API is added. Upgrading keeps every setting and saved value. Evidence: `docs/release-2.26.2/VALIDATION-REPORT.md`.

## Previous release: 2.26.0


2.26.0 adds Schema Studio (templates, dynamic fields, visual editor, graph, diagnostics), multiple Local SEO locations with a live preview, and fixes found by testing across 29 content types, 40 industries, all 134 Local SEO business types and WooCommerce. Upgrading keeps every setting; Schema Studio built-in templates ship switched off, so structured data does not change until you enable a template. Test evidence is in `docs/release-2.26.0/`.

## Previous release: 2.25.1

2.25.1 builds on 2.25.0. It rebuilds Traffic Guard (monitor/enforce modes, protection levels, rule builder with test mode, temporary blocks, rate limiting, bot classification, event log with export and false-positive reporting, rule tester), adds a full ISO 3166-1 country database with a searchable country picker used by Traffic Guard and Access Shield, a native FAQ block with reusable FAQ collections, an SEO Inspector, validated custom JSON-LD, a sign-in page designer for Access Shield, update and What's New notifications with a live indicator, and question-mark help icons throughout.

Upgrading from 2.25.0 keeps every setting. A Traffic Guard that was switched on keeps blocking exactly as before (enforce mode, no new automatic heuristics until you choose a protection level). Two new database tables are created automatically on the first page load after the update.

What was tested, how, and what was not verified is listed in `docs/release-2.25.1/VERIFICATION-REPORT.md`, together with the security, performance, compatibility, licensing and installation reports.
