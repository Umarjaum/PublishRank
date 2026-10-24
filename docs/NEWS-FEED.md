# PublishRank News feed

## Overview

The **PublishRank → News** administration screen displays current product announcements and publishing guidance from the public feed at `https://publishrank.netlify.app/announcements.json`. It is an optional read-only feature. The feed is not fetched on plugin activation, on public front-end requests, or while the option is disabled.

The screen displays the announcement category, publication date, title, summary, optional image, expandable body sections, and approved action links. The featured item is presented prominently when the feed marks one as featured. The page does not execute feed HTML, JavaScript, popup instructions, or other display commands.

## Administrator controls

The feature is **disabled by default**. A user with the WordPress `manage_options` capability can choose **Enable live News** on the News screen. This stores the opt-in locally; the feed is fetched on the following screen load if no usable cache exists. This avoids making an unexpected network request as a side effect of installing or activating the plugin.

While enabled, **Refresh feed** deliberately bypasses the fresh cache and requests the public feed immediately. **Disable feed** turns the feature off and deletes both the normal and stale-fallback cache entries. Disabling does not contact the feed provider or delete any remote data. If a request fails, the screen uses the last saved sanitized copy for up to 24 hours, if one exists; otherwise it shows a retry message.

## Feed validation and rendering

The client requests the fixed HTTPS endpoint with TLS verification, an eight-second timeout, redirects disabled, an explicit generic User-Agent, and a maximum response size of 512 KiB. It accepts only a successful response whose content type is absent or identifies JSON, whose schema version is `1.0`, whose feed status is `active`, and whose `announcements` member is an array. The request sends no authentication token or WordPress cookie.

Before caching, the plugin sanitizes announcement text and retains only the fields used by the screen. It ignores remote markup and popup/display instructions, including `site_popup` and `display.show_popup`. The screen then filters out inactive entries, entries marked `example`, announcements published in the future, expired items, and items outside their minimum/maximum plugin-version bounds. Remaining items are ordered newest first.

Images and action links are accepted only when they use HTTPS on the exact host `publishrank.netlify.app` (default HTTPS port only). This prevents a feed entry from loading a tracking image or sending an administrator to an arbitrary action target. Links open in a new tab with `noopener noreferrer`. All text is escaped for its output context; no feed field is treated as trusted HTML. The page caps displayed body sections at eight and action links at three per announcement.

## Caching

The normal cache interval comes from `feed.cache_ttl`, defaults to one hour, and is clamped to 300–86,400 seconds. The stale-fallback cache expires after 86,400 seconds. A manual refresh is an explicit administrator-triggered request and can refresh the data before the normal cache expires. Disabling the feature deletes both transient values.

## Privacy and external services

Enabling the News feed causes the WordPress server to make a GET request to the endpoint above when the screen is opened without a fresh cache. The plugin sets a generic User-Agent and sends no post content, visitor data, or account details. The feed provider receives the server IP address and ordinary HTTP request metadata. The WordPress HTTP API request has no cookie list configured.

If an announcement contains an approved image, the administrator’s browser requests the image from `publishrank.netlify.app` and may disclose the browser IP address and ordinary browser metadata, including origin/referrer information. No remote JavaScript is loaded. The screen explains this before opt-in, the plugin adds suggested privacy-policy text when WordPress supports the API, and `readme.txt` contains the WordPress.org **External Services** disclosure plus the Netlify privacy and terms links. Administrators can disable the feed at any time; this only clears local cache and does not delete remote data.

## Access and request security

The submenu and all feed actions require `manage_options`. Enable, refresh, and disable use a matching WordPress action nonce and the shared PublishRank admin guard. The feature is read-only with respect to site content and settings other than its own local enable option and transients. It has no scheduled job or background polling.

## Validation and troubleshooting

Run the deterministic regression test from the repository root:

```sh
php tests/news_feed.php
```

It checks no network request before opt-in, the fixed endpoint and User-Agent, schema parsing, cache behavior, stale fallback, active/current/version filtering, ordering, and URL allowlisting.

If the feed cannot load, confirm that the server permits outbound HTTPS to `publishrank.netlify.app`, TLS verification is working, and no firewall is blocking the request. The page does not expose internal connection errors to site users. Use **Refresh feed** to retry; the last saved copy remains available only until its 24-hour expiry. If the feed format or `schema_version` changes, the client intentionally rejects it and should be updated to support the new schema before it is rendered.
