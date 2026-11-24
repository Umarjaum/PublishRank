# PublishRank 2.26.2 — validation report

Environment: WordPress 7.1.5 (SQLite drop-in), PHP 8.3, Chromium (Playwright), Twenty Twenty-Five theme, Classic Editor plugin for the Classic checks. WP_DEBUG on. One sample article (featured image + content image) plus 52 generated fixture posts.

## Performed — and the result

| Area | Check | Result |
|---|---|---|
| Syntax | `php -l` on all 266 PHP files | 0 errors |
| PHP 7.4+ | PHPCompatibility 9.3 (`testVersion 7.4-`) on all PHP files | 1 finding fixed (WooCommerce/ProductSchema closure); 0 remaining |
| JS | `node --check` on every changed script | pass |
| P0 save bridge | Block editor: change a Schema Studio choice, click Save; a test `save_post` counter logs the request type | Third-party `save_post` ran **once** (core REST save); bridge ran once and the Schema Studio value was saved |
| P0 Edit SEO | Contributor with `edit_post` but without Edit SEO: PATCH `/publishrank/v1/posts/{id}/seo` and core `/wp/v2/posts/{id}` with SEO meta | 403 on both; nothing saved |
| Edit SEO grant | Same contributor with the cap (default after upgrade) | 200, saved |
| Classic Editor | Dock tabs present; regular meta-box areas contain no PublishRank SEO box (only Mastodon/source review by design); edit SEO title on Search appearance and click Update | Tabs: Fields, Preview, Analysis, Schema, Multilingual, Media; title saved; third-party `save_post` ran once |
| Classic UX bug | Clicking the active main tab used to fold the dock | Fixed: a tab click always opens; the arrow folds |
| Media tab | List images for the article; save alt/title/caption with HTML; stale `modified` | 2 images listed; script tag stripped, caption HTML kept; stale save → 409, value unchanged |
| Media permissions | Anonymous list; author editing someone else's attachment | 401; 403 |
| URL checks | `javascript:` URL; https URL; dry run to a missing file; dry run to an existing file | rejected; accepted; 400 "does not load (404)", nothing planned; 200 planned with precheck "HTTP 200 · image/jpeg" |
| Image licence | Save credit/creator/copyright/licence/licence page; invalid licence URL | 400 for invalid; saved values appear on ImageObject; Google "Image metadata" requirement then met |
| REST API | GET/PATCH seo, analysis, head, schema, sitemaps as anonymous, contributor, admin; draft as anonymous | Matches REST-API.md; unknown field → 400; draft head anonymous → 401 |
| REST privacy | Core `/wp/v2/posts/{id}` anonymous | `publishrank_seo` has no editorial fields |
| Analytics | 5 beacons with limit set to 3; 1100-char path | 204,204,204,429,429; 400 |
| Schema.org conformance | 52 fixture pages (15 article types, 5 page types, 19 Schema Library types, 9 Schema Studio templates, home, category, search): every JSON-LD node's type, every property's existence and domain (incl. ancestors), superseded properties, empty values, local `@id` references | 67 distinct types; after the fix below, **all pages clean** |
| Schema bug found | `SatireNewsArticle` is not a schema.org type (every property was reported as invalid for it) | Replaced by `SatiricalArticle`; old saved value maps automatically |
| Google requirements | PublishRank's eligibility check on every fixture graph | All required properties present except ImageObject licence metadata, which needs per-image data (now editable) |
| Schema Studio | Each built-in template on its fixture | Article, Service, Course, MedicalWebPage, Recipe, Event (with venue/date fields), VideoObject (with thumbnail) printed; Product enrichment and Location page correctly skipped without data ("no stubs" rule) |
| Previews | Classic Preview tab: Google/Bing switch, Desktop/Mobile; Social previews | Bing card renders with Bing styling; Facebook, LinkedIn, WhatsApp, X cards show 1200×630 · 1.90:1 |
| Block editor | Toolbar SEO button with score; sidebar drag-resize; Media tab | Button visible; sidebar 280 → 404 px; Media tab lists images and opens details |

## Not performed

- Real Google Rich Results Test, Schema Markup Validator, Facebook/LinkedIn debuggers (no public URL). Conformance was checked against the bundled schema.org vocabulary instead.
- WordPress 6.3 and PHP 7.4 runtime (syntax compatibility only, via PHPCompatibility).
- MySQL/MariaDB (SQLite only), multisite, WooCommerce, WPML/Polylang, object cache.
- Screen reader and full keyboard-only pass; RTL; mobile editor.
- The bridge's Save-button lock was implemented but not separately observed.
- Load/performance testing.

## Known limitations

- With plain permalinks (`?p=9`) entity IDs look like `?p=9/#primaryimage` (pre-existing; valid and unique, cosmetic). Pretty permalinks are unaffected.
- `?author=N` returns 404 when Access Shield's "Block author enumeration" is on (by design).
- Pre-existing editor deprecation notices: the breadcrumbs block uses block API v2; `core/edit-post` interface scope.
- Google requirement tables show a review date (2026-11-19, the vocabulary build); they were not re-verified against Google's live documentation in this release.
- The integrity notice re-baselines on update (BUILD r25); a site that edits plugin files afterwards will still be warned.
