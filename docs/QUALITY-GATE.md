# PublishRank v2.2 quality gate

## 2.2.0 build note

Before release, run the following from the plugin root on a machine with PHP CLI:

```
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/smoke.php
php tests/local_seo.php
```

Then exercise the new screens on a staging site: Redirects, 404 Monitor, Robots.txt, Diagnostics scan, the styled sitemap and the posts-list SEO column.


## Verified in this build

- Optional Local SEO profile validates business type, address/contact fields, HTTP(S) profile URLs, coordinates, and opening-hours strings; LocalBusiness JSON-LD remains off until enabled and named.
- Local SEO profile is managed by administrators and saved with nonce protection; the graph node uses a stable `#local-business` ID and links back to the organization entity.

- PHP syntax check passes for the plugin bootstrap, all `src/` classes, and tests.
- Existing sitemap renderer, sitemap index, URL normalization, and keyword-density smoke checks pass.
- Robots directives normalize contradictory `index/noindex` and `follow/nofollow` values.
- Title generation supports configurable templates and safe variables.
- Canonical overrides are restricted to the primary site host and reject configured media/CDN/video hosts.
- News sitemap queries are freshness-bounded, capped, category/ID-excludable, and support per-post eligibility.
- Redirects support 301, 302, 303, 307, 308, and 410; regex rules are opt-in and destination hosts are restricted to the site host.
- 404 monitoring is bounded and stores only a coarse user-agent category, not the raw user agent.
- IndexNow keys are not included in settings exports.
- Existing PublishRank smoke coverage remains green after the changes.
- Archive/search title generation does not call `wp_get_document_title()` from inside its own filter path.
- IndexNow key settings use the WordPress Settings API with a restricted key sanitizer.
- Cache invalidation records a non-autoloaded timestamp instead of writing from a sanitizer callback.
- Public source attribution is optional and disabled by default; when explicitly enabled, local provenance markers identify the product, release version, and deterministic fingerprint without network requests.
- The supplied 1983x793 banner is packaged and rendered only on the administrator dashboard.
- Editorial audit helpers operate locally; no hosted-service entitlement policy or feature lock is included in this directory build.

## Not verified in this sandbox

A live WordPress runtime, database, WP-CLI, and WordPress integration-test suite were not available for this build validation. PHP CLI syntax checks and standalone tests were run. Plugin activation/deactivation, real admin form submissions, multisite isolation, database behaviour, and rendered XML/JSON-LD still require staging verification before production deployment.

## Scope note

This cycle hardens and extends the existing implementation. It does not claim that every item in the broad enterprise brief is complete; features should be advertised only after their corresponding WordPress integration and staging tests are verified.
