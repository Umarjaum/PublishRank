# PublishRank Development and Troubleshooting Guide

This guide documents the plugin's development workflow, admin UI behavior, release checks, troubleshooting steps, security expectations, and test limitations. It complements the user guide and feature-specific references in `docs/`.

## Project overview

PublishRank is a WordPress plugin for editorial SEO and publisher workflows. The plugin entry point is `publishrank.php`; production PHP code is under `src/`, browser assets are under `assets/`, and the PHP source tree's CSS assets are under `src/assets/` (the packaging layout also includes the public asset directory). WordPress-facing classes use the `PublishRankSEO` namespace. The plugin loads classes through its Composer autoloader when available and a namespace-aware fallback loader otherwise.

### Main code areas

- `src/Admin/`: dashboard pages, settings, onboarding, editor integrations, and admin asset registration.
- `src/SEO/` and `src/Meta/`: editorial analysis, metadata storage, and SEO integrations.
- `src/Schema/`: JSON-LD generation and schema-type support.
- `src/Sitemap/`: sitemap routing, rendering, and sitemap-specific presentation.
- `src/Redirect/` and `src/Monitor/`: redirects and 404 monitoring.
- `src/Core/`: plugin-wide branding, configuration, and shared functionality.
- `assets/js/` and `assets/css/`: browser-side behavior and admin styling.
- `tests/`: standalone regression checks and WordPress function stubs. Tests are not a substitute for a clean WordPress installation test.

For subsystem detail see `ARCHITECTURE.md`, `DEVELOPER-HOOKS.md`, `FEATURES.md`, and `USER-GUIDE.md`.

## Local setup

1. Install the PHP version required by the plugin header and a WordPress development site matching the declared minimum version.
2. Put the `publishrank` directory in `wp-content/plugins/` and activate PublishRank.
3. Enable `WP_DEBUG` and `WP_DEBUG_LOG` in a non-production environment.
4. Exercise both the block editor and classic editor where supported by the feature under test.
5. Keep the database disposable or backed up when testing migrations, onboarding, imports, redirects, and settings changes.

The repository's `composer.json` describes optional development dependencies. Install those using Composer if available; do not ship development dependencies unless the packaging process explicitly requires them.

## Admin asset and interaction rules

Admin scripts and styles should be registered/enqueued with the WordPress enqueue APIs and scoped to the smallest applicable admin screen. Use `admin_enqueue_scripts` for dashboard/editor assets and `wp_enqueue_scripts` only for front-end presentation. Keep third-party libraries bundled locally unless they are part of WordPress Core or the plugin has a clearly documented service integration.

Admin JavaScript should:

- Bind interactions through stable, plugin-prefixed selectors and delegated handlers where WordPress may replace DOM nodes.
- Prevent default navigation only when the handler successfully implements the intended action.
- Preserve ordinary form submission and keyboard activation for buttons that do not require JavaScript.
- Provide feedback for copy, media selection, confirmation, and asynchronous actions.
- Avoid depending on a script being present unless it is enqueued on that exact screen.
- Avoid throwing when optional WordPress editor APIs, browser storage, clipboard APIs, or elements are missing.

### SEO score card on the editor

The floating PublishRank SEO score card is rendered only in supported post editor screens. Its position is stored in browser local storage for the current browser profile. Mouse, pen, and touch users should be able to drag it; controls within the card (for example, buttons) should remain clickable rather than initiating a drag. After viewport resizing, the card must remain within the visible editor area. Test on both desktop and a touch device, and verify that changing the saved position does not alter the post or its SEO data.

If dragging does not work, verify in order:

1. The current screen is a supported post editor, not a plugin settings page.
2. The score-card stylesheet and interaction script appear in the page's enqueued assets.
3. The browser console has no syntax/runtime error before the drag handler initializes.
4. No editor overlay or browser extension is intercepting pointer events.
5. Clear the `publishrank-editor-score-position` local-storage item to reset the saved location.

## Security and WordPress coding standards

Treat all request, database, filesystem, and server inputs as untrusted. Sanitize at input, validate against expected types and allowed values, and escape at output using the context-appropriate WordPress function. For state-changing actions, combine a nonce check with a capability check; nonces are not authorization. Use `wp_unslash()` before sanitization for WordPress request values. Validate imported JSON structurally and reject unknown or malformed values rather than assuming `json_decode()` makes data safe.

Use `wp_enqueue_script()`, `wp_enqueue_style()`, and their registration/localization APIs for browser assets. Inline CSS/JS should be avoided for admin presentation/behavior; where a non-HTML endpoint necessarily emits a stylesheet or structured data, document why enqueue APIs do not apply and escape dynamic values appropriately. Avoid changing global WordPress behavior outside the plugin's own feature scope. Prefix plugin-owned names and avoid reserved WordPress prefixes. PHP files with executable code must guard direct access with `ABSPATH`.

The plugin's test support may define WordPress constants and provide stub functions in isolated CLI processes. Such definitions must remain in test-only files and must never run as part of the production plugin bootstrap.

## Testing and verification

### Fast checks

From the plugin root:

```sh
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
find assets/js -name '*.js' -print0 | xargs -0 -n1 node --check
```

Run the existing standalone regression scripts individually with the PHP version available in CI. Check each process exit status; a printed `PASS` line alone is not sufficient if later checks fail.

### WordPress integration checks

On a clean disposable WordPress installation with debugging enabled:

1. Activate the plugin and inspect PHP logs for warnings, notices, deprecations, and fatals.
2. Visit every PublishRank submenu and confirm page content actions, forms, import/export, tabs, media pickers, copy controls, and confirmation flows.
3. Check plugin pages and post editor screens at desktop and narrow/mobile viewport widths.
4. Exercise the score card using mouse and touch, including drag near viewport edges and after a reload.
5. Test with at least one supported post type, an editor role, and an administrator role to confirm capability boundaries.
6. Test fresh activation and upgrade from the previous release using a database snapshot.
7. Check that no assets are fetched from unapproved remote hosts.

The fast checks do not establish full WordPress.org Plugin Check or PHPCS compliance. Before a directory resubmission, run current Plugin Check and WordPress Coding Standards tooling, review every finding (including test-only findings and false positives), and record the exact WordPress/PHP versions used.

## Release and versioning checklist

1. Choose one release version and synchronize the plugin header, version constant, `readme.txt` stable tag, changelog heading, and release documentation.
2. Review the full diff, including generated files and bundled assets.
3. Run PHP syntax checks, JavaScript syntax checks, and the regression suite.
4. Perform the clean WordPress integration checks above; do not claim checks that were not run.
5. Verify the installable ZIP has one top-level `publishrank/` directory and excludes Git metadata and development-only files.
6. Commit the release with a descriptive message and push the intended branch. Tag only after final validation.
7. Keep WordPress.org submission separate from a GitHub push; a GitHub push does not upload or approve a directory release.

## Known diagnostic boundaries

A source archive alone cannot prove that a button works in a live WordPress dashboard: page-specific PHP rendering, browser console output, WordPress capabilities, active theme/plugins, and cached assets affect behavior. A static or CLI test can confirm syntax and that an asset is enqueued in code, but it cannot replace interaction testing in WordPress. Report these distinctions in release notes.

## Further references

- `INSTALLATION.md` — installation and initial setup.
- `USER-GUIDE.md` — end-user workflows.
- `ARCHITECTURE.md` — code structure and runtime flow.
- `DEVELOPER-HOOKS.md` — extension hooks and integration points.
- `SECURITY-AND-PROVENANCE.md` — security and provenance approach.
- `QUALITY-GATE.md` — existing release-quality checks.
- `WORDPRESS-7.1.3-COMPATIBILITY.md` — compatibility planning and test matrix.
