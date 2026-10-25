# PublishRank 2.9.72 — GitHub News source and activation consent

## Summary

This release changes the optional News feed to the official `announcements.json` file in the PublishRank website repository, adds an activation-time consent dialog, improves external-service disclosures, and renders the top-level WordPress sidebar icon as a solid-white masked silhouette.

## Changes

- Fetches the raw JSON corresponding to [the official GitHub source file](https://github.com/Umarjaum/publishrank_website/blob/main/announcements.json) from `raw.githubusercontent.com`. The raw endpoint currently serves `text/plain; charset=utf-8`; the reader accepts that media type as well as JSON media types and still parses/validates the JSON schema before use.
- Adds a branded modal on first activation when News is not already enabled. **Enable live News** is the only choice that permits a feed request; **No thanks** records an opt-out, and **Not now**/Escape dismisses without connecting. The prompt is shown to an administrator with `manage_options`, remains pending until a choice is made, and links to the official source and service privacy policies.
- Keeps the feed disabled by default, requests nothing on activation itself, preserves any prior enabled preference, and stores the modal decision locally. The existing News screen remains available for later opt-in.
- Discloses GitHub Raw server-IP/request metadata and the distinct Netlify image/link traffic in the activation prompt, News consent screen, suggested WordPress privacy-policy text, this guide, and `readme.txt`.
- Uses versioned cache keys for the provider change and clears the legacy Netlify-feed cache when News is declined or disabled.
- Applies a local alpha mask to the sidebar icon, filled with `#fff`, because WordPress renders the `add_menu_page()` icon URL as a background image rather than an `<img>` element.

## Validation performed

- All **32 standalone PHP regression tests passed**, including no request before consent, activation prompt state, the exact GitHub Raw endpoint, `text/plain` and `application/json` responses, schema validation, caching, stale fallback, item filtering, and URL allowlisting.
- PHP syntax lint passed across production and test PHP, `publishrank.php`, and `uninstall.php`.
- `node --check` passed for all shipped/source JavaScript assets.
- `composer run phpcs`: **0 errors**. **177 warnings** remain visible across the existing project and remain non-blocking.
- The official raw endpoint returned HTTP 200, `text/plain; charset=utf-8`, and a 23,730-byte valid schema `1.0` active feed; it contained 10 announcements, including 8 active non-example entries at validation time.
- `dist/PublishRank-v2.9.72.zip` was verified to contain 198 files under one `publishrank/` directory, including the activation modal assets, and excluding repository metadata, tests, developer tools, local dependencies, and documentation.
- `git diff --check` passed.

## Manual interaction gap

No live WordPress staging dashboard was available in this task. The activation hook, protected form actions, modal keyboard handling, local styling, and CSS alpha-mask rule were statically reviewed and covered where applicable by regression/syntax checks, but the activation popup has not been clicked through in a browser and the rendered sidebar icon has not been visually checked in a live WordPress admin theme. Verify first-activation prompt display, Enable/No thanks/Not now/Escape behavior, no request after decline/dismiss, and the sidebar icon on a clean staging site before production deployment.
