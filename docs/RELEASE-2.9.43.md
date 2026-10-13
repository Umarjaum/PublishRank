# PublishRank SEO 2.9.43 - release notes and verification status

## Confirmed root causes (from reading the 2.9.42 source)
1. **News sitemap depended on the cursor store / WP-Cron.** It was built for 50,000-URL catalogues; shard 2+ needed cron-prepared checkpoints and returned HTTP 503 until they existed, and `complete` cursors were never recomputed as the rolling window moved. Fixed: News is a bounded one-query build with no cursor.
2. **Window filter ran in the site timezone** (`date_query` with a relative string) while freshness was checked in UTC. Fixed: `post_date_gmt` bounds computed in UTC.
3. **Titles / language / name mishandled.** Texturized entities were passed to `esc_xml` unchanged; locale-style language values (`en_US`, `en-US`) were emitted as invalid codes; names kept parentheses. Fixed in `NewsSitemapProvider` and `SitemapRenderer::text`.
4. **Stale data:** trash, unpublish, scheduled-publish and eligibility/robots/title meta changes did not bump the sitemap version. Fixed in `Plugin::register_cache_invalidation`.
5. **HTTP semantics:** unknown shards fell through to the theme, errors were indistinguishable from preparation delays, charset followed the site setting while the declaration said UTF-8, and `redirect_canonical` could interfere. Fixed in `SitemapController`.
6. **Implicit live blogs:** `NewsroomSignals::format()` inferred `live` from any title/category containing "live", turning ordinary articles into LiveBlogPosting. Fixed.
7. **Duplicate FAQPage:** manual FAQ (`#faq`) and auto-detected FAQ (`#faq`) could both be emitted. Fixed (manual wins; graph is de-duplicated).
8. **Cursor robustness:** no inline progress, no stale-cursor expiry, no overdue-cron handling. Fixed.

## Verification status

- PHP 8.3 syntax lint completed for every PHP file.
- The complete lightweight regression suite completed successfully after enabling the PHP SimpleXML extension required by the XML test.
- ZIP integrity, readme metadata, source parity and remote executable-asset scans completed successfully.
- The optional source-attribution comment is disabled by default and now has an explicit opt-in guard for WordPress.org Guideline 10 compliance.
- WordPress runtime and HTTP behavior should still be checked in a clean WordPress Playground before production use.

## Known limitations
- Cases 10, 11, 12, 16 of the News test list are covered by static source assertions only; end-to-end HTTP behaviour needs a WordPress install.
- LiveBlogPosting output has not been checked with Google's Rich Results Test or the Schema.org validator.
- Only FAQPage (hardened), LiveBlogPosting and opt-in Speakable were added. Review, Recipe, HowTo, ClaimReview and similar types remain selectable labels without dedicated markup. Google limits FAQ rich results to a small set of well-known government and health sites.
- Source attribution is explicitly opt-in and follows WordPress.org Guideline 10 (see `docs/ATTRIBUTION.md`).
- Image/video sitemap files now hold 5,000 URLs, so sites above that will see more files; the index lists them automatically.
