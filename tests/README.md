# PublishRank integration tests

These tests run against a **real WordPress installation** (no WordPress mocks).

```
PR_WP_PATH=/path/to/wordpress php tests/run.php            # all tests
PR_WP_PATH=/path/to/wordpress php tests/run.php breadcrumb # only tests whose name contains "breadcrumb"
```

Requirements: a WordPress install with this plugin activated, PHP 7.4+. Use a **throw-away** site: every test
deletes all posts, categories (except the default) and `publishrank_*` options. Each file in `tests/cases/` runs in its own PHP process.

Coverage: shared template resolver (01), breadcrumb separators (02), output switches, organization/WebSite schema,
cache invalidation, upgrade migration and REST discovery endpoint (03), capabilities, nonces, escaping and other-SEO-plugin
compatibility (04).

Coverage added in 2.9.92: template-variable registry and social-field resolution (05), breadcrumb override, separators, renderer detector and Breadcrumbs screen (06). `node tests/js/template-variables.test.js` exercises the dropdown's insertion logic against a fake DOM (no WordPress needed).

Coverage added in 2.9.97: tests/cases/08_breadcrumb_placement_and_diagnostics.php (placement, duplicates, visibility, CSS, schema references, diagnostic analyzer and probe). Elementor itself is not installed in the test environment, so Elementor tests call PublishRank's callbacks for the documented hooks directly.

Coverage added in 2.9.98: tests/cases/09_publication_identity.php (publication identity ownership, source guard against reading WordPress title/tagline, default-hidden visible identity).

Coverage added in 2.9.99: tests/cases/10_identity_override.php.
