# PublishRank News feed

## Overview

The **PublishRank → News** administration screen displays current product announcements and publishing guidance from the public JSON file in the PublishRank website repository: [announcements.json on GitHub](https://github.com/Umarjaum/publishrank_website/blob/main/announcements.json). The WordPress server fetches its raw content from `https://raw.githubusercontent.com/Umarjaum/publishrank_website/main/announcements.json`.

The feature is optional and read-only. The activation dialog is a local consent UI; it does not fetch or preview the feed. The plugin makes no News request until an administrator explicitly selects **Enable live News**. It does not fetch the feed on ordinary front-end requests, plugin activation itself, or while News is disabled.

The News screen can show category, date, title, summary, optional approved image, expandable body sections, and approved action links. Featured entries receive a larger presentation. Feed markup, JavaScript, popup instructions, and display commands are never executed; fields such as `site_popup` and `display.show_popup` are ignored.

## First-activation consent

After activation, the first administrator with `manage_options` sees a branded modal over the initial admin screen (including the setup wizard screen). The pending prompt remains available until an administrator acts. A keyboard user can close it with **Escape**; the close control and choices are also buttons.

- **Enable live News** stores consent and sends the administrator to PublishRank → News. Only then may that page request GitHub Raw, and only when its local cache is not fresh.
- **No thanks — keep News off** records the decline, leaves the feature disabled, and clears News cache entries. No feed request is made. The prompt does not repeat on later activation.
- **Not now** (the close button or Escape) dismisses the prompt without opting in; News remains off on a fresh installation. The administrator can enable it later from PublishRank → News.

A previously enabled News preference is preserved. The user can still disable the feed from the News screen. Prompt choice and feed preference are stored locally in WordPress options. Prompt UI assets are local plugin files; no remote script, image, or font is loaded to display the dialog.

## Feed source and validation

The endpoint is fixed in plugin code; it cannot be supplied through request parameters or settings. The request uses `wp_safe_remote_get()` with TLS verification, an eight-second timeout, redirects disabled, a generic User-Agent, JSON Accept header, and a 512 KiB maximum response size. GitHub Raw currently serves this JSON file as `text/plain; charset=utf-8`; the client accepts `text/plain`, `application/json`, and JSON `+json` media types, then validates the response body as JSON. A missing Content-Type is also accepted. Other media types are rejected.

The parsed document must have `schema_version` `1.0`, an active feed status, and an `announcements` array. Before caching, the plugin sanitizes announcement text and retains only the fields used by this screen. It filters out inactive entries, entries marked `example`, entries published in the future, expired items, and announcements outside their minimum/maximum plugin-version bounds. Remaining items are ordered newest first.

Images and action links are accepted only when they use HTTPS on the exact host `publishrank.netlify.app` and the default HTTPS port. This prevents arbitrary third-party tracking images or action destinations. Action links open in a new tab with `noopener noreferrer`. Text is escaped for its output context; no feed field is treated as trusted HTML. The page caps body sections at eight and action links at three per announcement.

## Caching and requests

The normal cache interval comes from `feed.cache_ttl`, defaults to one hour, and is clamped to 300–86,400 seconds. A separate stale-fallback copy expires after 86,400 seconds. Ordinary News screen visits reuse the fresh local cache. **Refresh feed** is an explicit administrator-triggered request that bypasses the fresh cache. If a request or validation fails, the last sanitized copy is shown as stale until its 24-hour expiration, if one exists. Otherwise the screen shows a retry message.

The cache keys were versioned when the source moved from Netlify to GitHub Raw. Disabling News clears the current and legacy transient keys. It makes no provider request and does not attempt to delete or change any remote content.

## Privacy and external services

After opt-in, the **WordPress server** requests the JSON file from `raw.githubusercontent.com` when an administrator opens News without a fresh cache, or when an administrator clicks **Refresh feed**. GitHub may receive the server IP address and ordinary HTTP request metadata. The request uses a generic User-Agent and does not send post content, visitor data, account details, or configured cookies.

The official source file is in the public `Umarjaum/publishrank_website` repository. Review GitHub’s [Privacy Statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement) and [Terms of Service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service). The feed’s approved image URLs are hosted by `publishrank.netlify.app`; when an image is displayed, the **administrator’s browser** requests that image from Netlify, which may receive the browser IP and ordinary browser metadata such as origin/referrer. Clicking an action link also opens the PublishRank website. Review Netlify’s [Privacy Statement](https://www.netlify.com/privacy/) and [Website Terms of Use](https://www.netlify.com/legal/terms-of-use/).

The consent modal and News page show these external-service details before the administrator opts in. The plugin also adds suggested privacy-policy wording through WordPress’s privacy-policy API when available, and `readme.txt` contains the WordPress.org External Services disclosure. No remote JavaScript is loaded, and no announcement popup is shown to the administrator.

## Access and request security

The submenu and all feed actions require `manage_options`. The activation prompt choices and enable, refresh, and disable actions use WordPress action nonces and the shared PublishRank admin capability guard. Only the prompt’s explicit enable choice or the News screen’s **Enable live News** action sets the local consent option. The feature does not modify posts, run a scheduled job, or poll in the background.

## Validation and troubleshooting

Run the deterministic regression test from the repository root:

```sh
php tests/news_feed.php
```

The test covers no request before opt-in, activation prompt state, the fixed GitHub Raw URL, `text/plain` and `application/json` handling, schema parsing, cache bounds, stale fallback, item filtering/order, and same-host HTTPS media/link validation.

If the feed cannot load, confirm the WordPress server permits outbound HTTPS to `raw.githubusercontent.com`, TLS verification is working, and the response body remains within 512 KiB. The screen intentionally does not expose internal connection errors. Use **Refresh feed** to retry; the last saved copy remains available only until its 24-hour expiry. A new schema version or unsupported MIME type is intentionally rejected until the plugin is updated.
