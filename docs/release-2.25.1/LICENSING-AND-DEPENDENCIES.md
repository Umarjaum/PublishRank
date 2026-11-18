# PublishRank 2.25.1 — Licensing and dependency report

**Licence:** GNU General Public License v2 or later (`LICENSE.txt`). Developer: Muhammad Umar Jabbar.

All modules are first-party PublishRank code, including Access Shield (private sign-in address, routing, two-factor, CAPTCHA, lockouts, sign-in page designer), Traffic Guard, the country database code, the FAQ block, the Source Directory, the SEO Inspector and the schema engine. The private sign-in address routing was written for PublishRank and does not contain code, identifiers or branding from other login plugins. PublishRank's own older option names are kept internally so existing sites migrate.

## Bundled third-party components

| Component | Version | Purpose | Licence | GPL-compatible | Notice shipped |
|---|---|---|---|---|---|
| Font Awesome Free | 7.3.1 | Admin icons (solid + brands subsets, WOFF2 only) | Icons CC BY 4.0 · Fonts SIL OFL 1.1 · Code MIT | Yes | `assets/vendor/fontawesome-free-7.3.1/LICENSE.txt` |
| qrcode-generator (Kazuhiko Arase) | 1.4.4 | Two-factor setup QR code | MIT | Yes | `assets/vendor/qrcode-generator/LICENSE.txt` |
| umpirsky/country-list (English names) | data | Country names | MIT | Yes | `COUNTRY-LIST-LICENSE.txt` |
| Natural Earth Admin 0 (1:110m) | data | World map shapes (rasterised) | Public domain | Yes | Credited in `CREDITS.md` |

ISO 3166-1 codes are facts defined by the standard; alpha-3 and numeric codes were cross-checked against Debian `iso-codes`.

## Runtime dependencies

* WordPress 6.3+ and PHP 7.4+ (declared). Block-editor features use only packages shipped with WordPress (`wp-element`, `wp-components`, `wp-data`, `wp-blocks`, `wp-plugins`, `wp-edit-post`/`wp-editor`, `wp-api-fetch`, `wp-i18n`, `wp-server-side-render`).
* No Composer or npm runtime packages, no build step, no CDN-loaded scripts, styles or fonts.
* 2.25.1 added **no new third-party dependencies**.

## Optional external services (off until switched on)

IndexNow, CAPTCHA providers (Cloudflare Turnstile, hCaptcha, reCAPTCHA), breached-password check (k-anonymity range API), Google Search Console, and the PublishRank news/update feed. The SEO Inspector only fetches pages on the site's own domain. Details are in `readme.txt` → External services.
