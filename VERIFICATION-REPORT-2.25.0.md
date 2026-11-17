# PublishRank 2.25.0 — Delivery & Verification Report

Date: 16 November 2026 · Plugin version: **2.25.0** · Website: PublishRank official site, 2.25.0 content

## What is in this package

| Item | Contents |
|---|---|
| `PublishRank-2.25.0.zip` | Installable WordPress plugin (upload via Plugins → Add New → Upload). |
| `PublishRank-Official-website-2.25.0/` (zipped) | Website source, without installed packages or history. Run `bun install` then `bun run dev`. |
| `screenshots/` | 32 real admin screens captured from the test site, plus a contact sheet. |
| `VERIFICATION-REPORT.md` | This report. |

## Test environment (important)

- WordPress 7.1.4, PHP 8.3, **SQLite** database, no persistent object cache, PHP built-in web server.
- **Not tested here:** MySQL/MariaDB, PHP 7.4 (none available; a scan of new files found no PHP 8-only syntax), Apache/Nginx rules, premium themes, real production traffic, and the live Vercel deployment.
- All performance figures are measurements from this test site, not guarantees for every host.

## Automated test results (all passing)

| Suite | Result |
|---|---|
| Identity & country | 53 checks, 0 failures |
| Languages | All passed |
| Upgrade path | 19 checks, 0 failures |
| WooCommerce analytics | 33 checks, 0 failures |
| 2.25 features (Publishing Desk, Access Shield, preloader, sitemap cursor) | 15 checks, 0 failures |
| News & What's New (secure feed, cache, fallback, dismissals) | 42 checks, 0 failures (against both the live local website feed and an offline sample) |
| **Roles & permissions (new)** | 15 checks, 0 failures |
| PHP syntax | 237 files, 0 errors |
| Website test suite | 91 tests, 0 failures |

## Feature verification

### Feature access rules (role-based system)
- **Bug found and fixed:** the Roles & permissions screen existed but was never switched on (also true in the original 2.24.0), so admins had no way to assign features. It is now registered, and its data endpoint loads correctly (anonymous requests get *401 not authorised*).
- Verified in a real browser: admin denied *News* and granted *Theme Masking* to one editor → that editor lost News and gained Theme Masking; other users were unaffected; resetting restored defaults.
- Role matrix (34 permissions × every role), 8 presets, per-user Grant/Deny/Inherit, "why can/can't they" explainer and audit log.
- Safety: administrators can never be locked out; non-admin managers cannot remove their own manage-roles permission.
- Layout fixed: permission names were squashed with 11 roles; the name column is now fixed and roles scroll sideways. Administrator boxes now show as ticked and locked.

### Theme masking
- Live homepage source (154 KB): theme name “astra” found **0** times, `wp-content/themes` **0** times; generator shows PublishRank.
- Tested on Astra, Astra child, GeneratePress, Kadence, Blocksy, Neve, OceanWP, Hello Elementor and Twenty Twenty-Five.
- **Known limit:** someone who already guesses the theme can still request `/wp-content/themes/<theme>/style.css` directly. A plugin cannot block static files; the Theme Masking screen shows the exact Apache/Nginx rule to add.

### Sitemaps at scale
- 200,015 unique URLs split into 5 shards of up to 50,000.
- Publishing a new post rebuilt only the last shard; editing an old post rebuilt only its shard; comments rebuilt nothing.

### Bloat check (200,000-post test site)
- Request-local option preloader cut PublishRank database queries from about 96 to 2–6 per page.
- A controlled multi-run timing benchmark was **not** repeated.

### Access Shield
- Private login address, brute-force lockout, 2FA, CAPTCHA, hardening, breach-checked passwords (Have I Been Pwned range API, disclosed in readme), session limits, idle sign-out.
- Live verification produces a signed report that can be re-checked.

### Newsroom & Publishing Desk
- Duplicate-headline warning in the editor, missed-schedule detection and auto-fix, freshness column, news diagnostics, publishing velocity.

### Logo & site identity sync
- PublishRank logo syncs to WordPress Site Logo and Site Icon, alongside title, tagline and description.

### Advanced SEO
- 15 additional SEO/trust checks feeding one authoritative score.

### News & What's New (plugin ↔ website)
- Plugin reads only `https://publishrankseo.vercel.app/publishrank-feed.json`: certificate verified, no redirects followed, 8-second timeout, 256 KB cap, strict validation (HTML, http:// and other-site links dropped), cached with an offline fallback to the bundled changelog. It never installs updates.
- **Not yet live:** the Vercel domain still returns 404 for the feed until the updated website is deployed. Until then the plugin shows its bundled changelog.

## Clean-install test
- Fresh WordPress (no other plugins), installed **from the ZIP** with WordPress's installer and activated: success.
- **Bug found and fixed:** the first visit after install logged WordPress's “translations loaded too early” notice (a background-task label was translated before WordPress was ready). After the fix, a fresh install and first visits log **0** notices, warnings or errors.
- Dashboard, Setup, Roles, Theme Masking, Access Shield, News, Publishing Desk and Sitemap Hub all open without errors.
- With *pretty links* on, robots.txt, sitemaps and llms.txt respond normally. With WordPress's default *plain* links, `/robots.txt` redirects to `/robots.txt/`. This also happens with PublishRank switched off, so it comes from WordPress itself.

## Website
- Content synced to 2.25.0: release notes / What's New page, 2.25 news story, feature catalogue, download page.
- Enterprise visual refresh: animated hero, verification console, proof band, spotlight cards, timeline. Animations respect reduced-motion settings.
- The screenshot gallery now uses the 32 new 2.25.0 captures. Only the setup-wizard screens come from the older 2.12.1 set, and they are labelled that way.
- Download button serves `/downloads/PublishRank-2.25.0.zip` (the same plugin package, included in the site source).
- Public feed `/publishrank-feed.json` with caching headers; contract tests reject unsafe content.

## Remaining actions for you
1. **Deploy the website** (Vercel) so the live feed and the 2.25.0 download become available.
2. Optionally re-test on your production stack (MySQL, your PHP version, Apache/Nginx) and add the server rule from the Theme Masking screen.
