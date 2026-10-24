# PublishRank 2.9.71 — opt-in News feed

## Release contents

- Adds an opt-in **PublishRank → News** screen that reads the public Netlify JSON announcements feed only after an administrator enables it.
- Displays validated announcement content with summaries, optional same-host HTTPS images and links, expandable details, and publication dates.
- Adds nonce- and capability-protected enable, refresh, and disable actions. Disabling removes both local feed caches.
- Bounds remote requests, validates the feed schema and status, sanitizes and escapes rendered values, ignores popup/HTML instructions, and supports a limited stale-cache fallback.
- Adds privacy-policy disclosure, WordPress.org external-service documentation, and a dedicated News feed operations guide.
- Synchronizes plugin header, runtime version, stable tag, and regression fixture to 2.9.71.

## Validation

All 32 standalone PHP regression scripts passed. Recursive PHP syntax checks passed, JavaScript syntax checks passed, `git diff --check` passed, and the Composer PHPCS command exited successfully with **0 errors**. It reports **177 warnings across 50 files**; these are non-blocking and remain a visible backlog. The new News screen file itself passes the project PHPCS ruleset without warnings.

The live endpoint returned HTTP 200 with `application/json`; its 23,730-byte payload parsed as schema 1.0 with an active feed, a one-hour TTL, 10 announcements, eight active non-example items, and unique announcement IDs. The two supplied text copies are incomplete and invalid JSON at line 447, so they were not treated as production feed payloads; the live endpoint itself validated successfully.

The WordPress.org submission archive was built as `dist/PublishRank-v2.9.71.zip`. It contains 195 files under one `publishrank/` directory and excludes repository metadata, development tools, tests, and documentation.

## Manual interaction gap

A live staging WordPress dashboard was not available for browser testing. The score-card drag script and `touch-action: none` styling are present and the script is enqueued on supported post editor screens, but mouse, pen, and touch movement have not been verified in a browser. The new News actions submit to the registered `admin-post.php` handler with a nonce and capability check; the earlier general admin-screen buttons have not been validated interactively. Test these behaviors on a clean staging site before production rollout.
