# PublishRank 2.26.1 — release report

Package: `PublishRank-2.26.1.zip` (exact bytes from the supplied `publishrank-2.26.1-v4.zip`; 470 regular files, 530 total ZIP entries including directories; installs as `publishrank/`). SHA-256: `e22d7913961df7f2b0f2c4642e04350888400b264801db731560a0a0b0c6af23`. Based on the supplied 2.26.0. Database schema version 7 → 8 (adds tables; nothing removed or renamed).

Test environment: WordPress 7.1.5, PHP 8.3, SQLite database, PHP built-in web server, headless Chromium. Everything below was run on this sandbox site, not on a production host.

Legend: **PASS** tested and passed · **FAIL** tested and failed · **NOT TESTED** · **BLOCKED** needs credentials, a paid service or a plugin not available here.

---

## 1. Priority 1 acceptance criteria

| # | Criterion | Result | Evidence |
|---|-----------|--------|----------|
| 1 | One coherent PublishRank SEO interface in Gutenberg | PASS | One sidebar with tabs SEO · Social · Schema · Advanced · Insights, sections for score, search appearance, title/description, keywords, social previews, canonical/robots, Schema Studio, advanced, internal links, Article Intelligence, newsroom, diagnostics. `screenshots/gutenberg/` |
| 2 | Sidebar permanently discoverable and user-controllable | PASS | Toolbar pin + Options (⋮) menu entries "PublishRank SEO editor", "PublishRank SEO panel" and preferences, at desktop, tablet and phone widths. Preferences: show in sidebar, remember last section, default expanded/collapsed, compact/comfortable, reset. Closing is remembered across reloads. `options_menu_*.png`, `prefs.png` |
| 3 | No PublishRank SEO boxes below Gutenberg | PASS | Boxes are removed through `remove_meta_box` for block-editor screens (not CSS). Verified on posts and pages at 13 widths, and on a clean install (`fresh_editor.png`). Before: `before/gutenberg_metaboxes_before.png` |
| 4 | Existing values load | PASS | Pre-filled posts load into sidebar fields |
| 5 | Every SEO field saves | PASS | Title, description, focus + secondary keywords, social fields, canonical, robots, entity topic, author job title, schema type, cornerstone saved and confirmed in the database. Tab switching no longer marks the post as changed |
| 6 | Schema Studio inside the editor | PASS | Schema tab in the sidebar; full Studio also as admin screen |
| 7 | Complex schema properties supported | PASS | 246 types kept; nested objects, arrays, repeatable properties, @id links, templates and conditions. Event template built, validated, previewed, saved |
| 8 | Media URLs managed safely | PASS | Media Link Manager: search, filters, references, host/CDN rewrite, relocation, rename |
| 9 | References update in the database | PASS | Post content, block attributes, srcset, featured image, meta, options updated by attachment ID with format-aware (serialized/JSON-safe) replacement |
| 10 | Dry run and rollback | PASS | Plan → preview → apply → verify → finalize; rollback restored content and meta byte-for-byte |
| 11 | No database corruption | PASS (in tests) | Serialized/JSON values re-parsed after every change; GUIDs untouched; no database-wide replace |
| 12 | No broken media links from migration | PASS | Old address answers a PublishRank 301; new file 200 `image/jpeg`; after rollback old 200, new 404, no leftover copies |
| 13 | Responsive at all widths | PASS | 10 admin screens × 13 widths (320–1920): no page overflow. Editor post/page × 13 widths. Landscape, dark, forced-colours, reduced-motion checked on key screens. `responsive-audit.json` |
| 14 | Classic Editor works | PASS | Classic Editor plugin active: consolidated SEO box saved a field; no script errors |
| 15 | No feature silently removed | PASS (by review) | Removed below-editor boxes were moved into the sidebar; Mastodon and newsroom source tools kept |

Known limits: WordPress core's own admin-bar "Menu" item has no visible focus ring (not PublishRank). Touch-target and contrast checks were automated spot checks, not a full WCAG audit. Real phones and Safari/Firefox were not tested.

## 2. What was improved in 2.26.x

1. **Unified Gutenberg SEO sidebar** with menu entries, preferences and no duplicate boxes below the editor.
2. **Schema Studio**: searchable type categories with descriptions, Basic/Advanced modes, guided searchable properties with examples, required markers, validation that separates Schema.org validity / Google requirements / recommendations, readable + JSON-LD preview with copy. Never says "eligible for rich results" just because JSON-LD is valid.
3. **Media Link Manager** (new): library search by name/title/ID/URL, type filters, dimensions/size, missing files, duplicates, broken URLs, reference finder (content, blocks, srcset, featured, meta, options, WooCommerce galleries, schema/OG/Twitter images, logos), dry run, preview, collision checks, change log, verification, redirects for moved local files, rollback, permission and nonce checks. Ambiguous or unsupported references are reported, not guessed.
4. **Advanced 404 Monitor**: stores the referring page (host and path only, no IP), separates own-site broken links from external ones, links to the post to fix, gives a cause for each address (old permalink, moved media, typo, probe, etc.), and a one-click redirect only for high-confidence (≥90%) fixes. Missing uploads now return a true 404 instead of a soft 404 / homepage.
5. **Bulk SEO Editor** (upgraded in the existing Publisher Toolkit): inline title/description/robots/canonical/focus keyword, filters (type, status, category, missing, duplicate, too long, noindex, custom canonical), review before saving, conflict protection, change history with undo, CSV export/import with review, spreadsheet-formula protection. Fixed a bug where bulk "noindex" cleared the robots setting.
6. **Video sitemap**: now finds YouTube/Vimeo added with the block editor's Embed block or pasted links; uses YouTube's own thumbnail; skips videos with no thumbnail rather than inventing one; no publication date claimed for third-party videos.
7. **Responsive/accessibility**: breakpoints consolidated across 45 stylesheets; focus-visible, 24 px targets, reduced-motion and forced-colours rules.
8. Score card "Full report" now opens Article Intelligence in the sidebar.

## 3. Competitive feature matrix (vs. Rank Math's public feature list)

Status: **Verified** = workflow run end-to-end here · **Implemented (code review)** = code present and read, workflow not run this release · **Partial** · **Missing** · **Blocked** · **N/A**.

### A. Internal link intelligence
| Feature | Status | Notes |
|---|---|---|
| Internal link discovery / content graph | Implemented (code review) | `InternalLinks` scanner and store |
| External link policy (nofollow/sponsored) | Implemented (code review) | `Links/LinkManager` |
| Broken internal link detection | Partial — Verified for visited links | 404 Monitor lists the source pages of broken links from real visits. No proactive crawler of every outbound link (gap) |
| Redirect chains / loops | Implemented (code review) | Chains flattened, loops refused |
| Orphan content | Implemented (code review) | Site navigation report |
| Link recommendations with approval | Implemented (code review) | Approve first, then insert; never automatic |
| Bulk link editing with rollback | Missing | Media Link Manager covers media only |
| Anchor text analysis / link export | Partial | Anchor data in graph; export not verified |

### B. Image SEO and media
| Feature | Status | Notes |
|---|---|---|
| Attachment URL management | Verified | Media Link Manager |
| Duplicate / broken / missing media | Verified | Health scan |
| Missing alt detection, fill from title | Implemented (code review) | Rule-based, never overwrites existing alt |
| Image sitemap | Verified | Child sitemap lists images |
| Bulk caption/title editing | Missing | |
| Automatic AI alt text | N/A by rule | Would need a paid/external AI service |

### C. Search Console and Analytics
| Feature | Status | Notes |
|---|---|---|
| Search Console clicks/impressions/CTR/position by query, page, country, device | Blocked | Needs the publisher's own Google OAuth client; no credentials here, not tested |
| Search appearance report | Missing | Not requested from the API today |
| Google Analytics 4 | Missing | Not added: needs a Google account; out of scope under the no-new-dependency rule |
| Local traffic analytics | Implemented (code review) | Built-in Analytics screen (opened without errors) |

### D. Bulk SEO automation
| Feature | Status | Notes |
|---|---|---|
| Bulk title / description / robots / canonical / focus keyword | Verified | Preview, validation, undo |
| Filters by type, status, category, problems | Verified | |
| CSV export/import | Verified | Formula-safe |
| Bulk redirects / redirect CSV import | Implemented (code review) | Redirect Manager |
| Scheduled SEO audits | Implemented (code review) | Weekly audit cron |
| Quick Edit in post list | Missing | SEO columns only |

### E. Advanced schema
| Feature | Status | Notes |
|---|---|---|
| Type coverage (246), templates, conditions, multiple entities, @id graph, validation | Verified | Schema Studio |
| Multiple business locations | Implemented (code review) | Local SEO |
| Product variations (ProductGroup) | Not tested | WooCommerce not installed |
| Automatic VideoObject from embeds | Missing by design | The video's real upload date is not in the content; Google requires it, and guessing is not allowed |

### F. WooCommerce
All rows (product schema, variations, GTIN/MPN/brand/SKU, availability, price, reviews, images, hidden products, category SEO): **Not tested** — WooCommerce is not installed in the sandbox. Code for GTIN/MPN/variations exists.

### G. Roles and permissions
| Feature | Status | Notes |
|---|---|---|
| Media Link Manager REST permissions | Verified | Anonymous 401; subscriber/author/editor 403; admin allowed |
| Bulk editor | Verified for admin | Also checks `edit_post` per item; other roles not run |
| Role manager for SEO settings/meta/schema/redirects/analytics | Implemented (code review) | Capability map |

### H. Integrations
| Integration | Status |
|---|---|
| Gutenberg, Classic Editor | Verified |
| Elementor, Divi, WPBakery, Beaver Builder, Oxygen, bbPress, BuddyPress, Web Stories, AMP, WPML, Polylang, TranslatePress | Not tested (code adapters exist for several; compatibility is **not** claimed) |

### I. Video SEO
| Feature | Status | Notes |
|---|---|---|
| Discovery: HTML5, iframe, Embed block, pasted link, attached file | Verified | Improved in 2.26.1 |
| Thumbnails | Verified | Poster → YouTube thumbnail → editor field → featured image; else skipped |
| Duration / upload date | Partial | Only from the editor field / self-hosted posts; never guessed |
| Unavailable video detection | Missing | Would need a request to YouTube/Vimeo per video |

### J. AI-search readiness
| Feature | Status | Notes |
|---|---|---|
| llms.txt | Verified | Answers 200 |
| robots.txt / AI crawler controls | Verified (robots.txt 200); controls by code review | |
| Structure, entity and author/publisher consistency checks | Implemented (code review) | Article Intelligence |
| Measuring ChatGPT/Gemini brand visibility | N/A | No real data source; not claimed |

### Additional checks
| Capability | Status |
|---|---|
| Duplicate title / description detection | Verified (Bulk Editor filters) |
| Redirect chains and loops | Implemented (code review) |
| Hreflang / language alternates | Implemented (code review), not tested |
| Sitemap health | Verified (index + post/page/taxonomy/author/news/image/video children 200) |
| Keyword cannibalisation (site-wide) | Missing — recommended next |
| Content decay monitoring | Missing — needs Search Console history |
| Structured-data change history | Partial |

## 4. Remaining gaps (recommended next)
1. Site-wide keyword cannibalisation report (local, no API).
2. Proactive broken-link crawler with bulk link replace + rollback (reusing Media Link Manager's safe replacer).
3. Quick Edit SEO fields in post lists; bulk caption/title for media.
4. Search appearance dimension in Search Console sync.
5. Test with WooCommerce, page builders and multilingual plugins before claiming compatibility.
6. Test on PHP 7.4 (minimum supported). Only a static scan for newer-PHP syntax was done.

## 5. Test log summary
| Check | Result |
|---|---|
| PHP syntax, all 259+ files (PHP 8.3) | PASS — rerun on final tree, see `test-log.txt` |
| JavaScript syntax | PASS |
| CSS parse | PASS |
| WordPress coding standards (PHPCS) | NOT TESTED |
| Clean install from ZIP, activation, table creation (DB v8) | PASS |
| 10 admin screens on clean install, no PHP errors | PASS |
| Block editor on clean install: sidebar present, nothing below editor | PASS |
| Field save/reload (block editor, Classic Editor) | PASS |
| REST permission + nonce matrix (Media Links) | PASS |
| Media relocation: plan/apply/verify/redirect/rollback | PASS |
| Bulk SEO: preview, apply, invalid canonical rejected, undo conflict, CSV | PASS |
| 404 Monitor diagnosis + confident redirect; missing uploads true 404 | PASS |
| Video sitemap with Embed blocks | PASS |
| Responsive 13 widths, modes | PASS |
| Search Console live sync | BLOCKED (no OAuth credentials) |
| WooCommerce, builders, multilingual | NOT TESTED |
| Upgrade on a real site with existing 2.26.0 data | PASS on sandbox (DB 7 → 8); not tested on production data |
