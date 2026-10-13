# PublishRank

[![Latest release](https://img.shields.io/github/v/release/Umarjaum/PublishRank?display_name=tag&sort=semver)](https://github.com/Umarjaum/PublishRank/releases)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE.txt)
[![WordPress plugin](https://img.shields.io/badge/WordPress-plugin-21759B?logo=wordpress&logoColor=white)](https://github.com/Umarjaum/PublishRank)

**Free, open-source WordPress SEO infrastructure for news publishers, editorial teams, blogs, and content-heavy websites.**

PublishRank brings technical SEO, News SEO, XML sitemaps, structured data, redirects, robots controls, internal-link intelligence, and editorial workflows into one publisher-focused WordPress plugin. It is designed for newspapers, magazines, digital publishers, independent newsrooms, agencies, and site owners who need a clear and maintainable SEO foundation.

> PublishRank is an independent project. It is not affiliated with, endorsed by, or a clone of Yoast or Rank Math. Features and workflows differ, so evaluate compatibility before switching.

## Current release

- **Version:** 2.9.43
- **Latest release:** [v2.9.43](https://github.com/Umarjaum/PublishRank/releases/tag/v2.9.43)
- **License:** GPL-2.0-or-later
- **WordPress:** Requires 6.3 or later
- **PHP:** Requires 7.4 or later
- **Tested up to:** WordPress 6.7
- **Official website and documentation:** https://publishrank.netlify.app/
- **Source repository:** https://github.com/Umarjaum/PublishRank

## Why publishers use PublishRank

Publishers need more than a title field. They need dependable publication signals, clean crawl paths, structured editorial data, fast sitemap updates, and workflows that can be understood by editors and administrators.

PublishRank is built around these principles:

- **Publisher-first defaults:** News, article, author, organization, and editorial signals are treated as first-class SEO data.
- **Explicit ownership:** The plugin detects overlapping SEO output and documents how to avoid duplicate titles, canonicals, robots directives, or schema.
- **Human-readable source:** PHP, JavaScript, and CSS sources are included and documented in the repository.
- **Local-first core:** Core metadata, schema, sitemaps, redirects, and diagnostics run on the WordPress site without a required AI API or PublishRank account.
- **Permission-aware controls:** Administrative actions use WordPress capabilities, nonces, sanitization, and scoped admin assets.
- **Transparent integrations:** Optional external services are documented and only contacted when an administrator enables the related feature.

## Feature architecture

### Technical SEO and metadata

- SEO titles and descriptions with automatic fallbacks and per-content overrides.
- Canonical URLs, robots directives, Open Graph, and X/Twitter metadata.
- Language alternates, breadcrumbs, title templates, and site-identity controls.
- Automatic image and alt-text fallbacks that preserve explicit editorial values.

### News SEO and publisher signals

- Google News sitemap with configurable publication windows and post-type coverage.
- UTC-based publication filtering and cache invalidation on status and eligibility changes.
- News Sitemap Health diagnostics, shard visibility, XML validation, and error reporting.
- NewsArticle, Article, BlogPosting, and explicitly selected LiveBlogPosting schema.
- Live coverage updates with real timestamps and bounded output.
- Publisher, author, organization, article section, genre, and newsroom signals.

### Structured data

- Connected JSON-LD graphs for organizations, people, websites, webpages, articles, breadcrumbs, media, and local businesses.
- Schema graph de-duplication using stable identifiers.
- FAQ handling that avoids duplicate FAQPage nodes.
- Optional Speakable markup through documented filters; no selectors are invented automatically.
- Custom schema JSON with validation and capability checks.

### Sitemaps and crawl controls

- Sitemap index plus post, page, custom-post-type, taxonomy, author, image, video, and News Sitemap providers.
- Bounded generation, shard limits, UTF-8 XML, correct HTTP status codes, and safe output buffering.
- Robots.txt controls, sitemap discovery, AI crawler directives, and hreflang support.
- IndexNow notifications are optional and do not guarantee indexing or Google inclusion.

### Editorial and operational tools

- Redirect management and locally recorded 404 monitoring.
- Internal-link recommendations, orphan-page insights, and Publisher Link Intelligence.
- Permission-aware bulk SEO editing and optional missing-alt-text assistance.
- Setup wizard, configuration history, import/export, diagnostics, WP-CLI support, and feature documentation.
- Optional LocalBusiness schema and configurable `llms.txt` / `llms-full.txt` discovery files.

## Install in WordPress

1. Download the ZIP from the [Releases page](https://github.com/Umarjaum/PublishRank/releases).
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate **PublishRank**.
4. Open **PublishRank** in the dashboard and complete onboarding.
5. Review metadata ownership, schema, sitemap, robots, and integration settings before production use.
6. If replacing another SEO plugin, plan the migration and ensure only one plugin owns each output type.

## Development setup

Clone the repository and place the plugin directory in `wp-content/plugins/`:

```bash
git clone https://github.com/Umarjaum/PublishRank.git
cd PublishRank
# The repository root is the plugin directory.
```

The main plugin file is `publishrank.php`. The distributed WordPress ZIP keeps the plugin in a `publishrank/` directory so it can be uploaded directly through the WordPress admin.

## Testing and quality checks

Run the lightweight static and behavior tests with PHP CLI:

```bash
# Syntax check every PHP file.
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l

# Run the complete regression suite.
for test in tests/*.php; do php "$test" || exit 1; done
```

The XML sitemap test uses PHP SimpleXML. On Ubuntu/Debian, install it with:

```bash
sudo apt install php-xml
```

Before production deployment, also test activation and frontend output in a clean WordPress installation with `WP_DEBUG` enabled. The lightweight scripts are not a substitute for testing with the active theme, caching layer, security plugins, and editorial post types used by a real publication.

## Source disclosure and build policy

The repository contains the reviewable PHP, JavaScript, and CSS source. Runtime assets are shipped locally and are mirrored in `src/assets/` for review. This release does not require a build step or a third-party JavaScript/CSS CDN. See [`src/assets/README.md`](src/assets/README.md) for the asset-source mapping.

## Privacy and external services

Ordinary frontend rendering does not send visitor analytics to a PublishRank-owned service. Optional integrations are disabled until an administrator enables them:

- **IndexNow:** sends the publisher host, IndexNow key, key-location URL, and eligible public URL when enabled.
- **External link checks:** send requests only to external URLs already present in the site’s own content when an administrator manually starts that audit.
- **Source attribution:** HTML-source attribution and visible footer credit are both disabled by default and require explicit administrator opt-in.

See [`readme.txt`](readme.txt) for the complete External Services disclosure and privacy details.

## Compatibility and limitations

- Do not run multiple plugins that output the same title, canonical, robots, or schema data without configuring ownership.
- Search Console reporting, where configured, requires the site administrator’s own Google Cloud OAuth credentials and API setup.
- External URL checks can be affected by rate limits, redirects, server restrictions, or remote sites blocking automated requests.
- PublishRank does not guarantee rankings, crawling, indexing, traffic, or Google News inclusion.
- IndexNow is not a Google indexing API.

## Contributing and pull requests

Pull requests are welcome. Please read [`CONTRIBUTING.md`](CONTRIBUTING.md) before opening one. Use the repository’s pull-request template, describe the user-visible impact, include focused tests, and document any WordPress compatibility or migration considerations.

Suggested contribution areas include News SEO edge cases, sitemap correctness, schema interoperability, editor usability, accessibility, performance, documentation, and compatibility with publisher themes.

[Open a pull request](https://github.com/Umarjaum/PublishRank/compare) · [Report a bug](https://github.com/Umarjaum/PublishRank/issues/new/choose) · [Read the documentation](https://publishrank.netlify.app/)

## Support

- Project website and documentation: https://publishrank.netlify.app/
- Developer website: https://umarjaum.netlify.app/
- Email: umarjaum@gmail.com

## License

PublishRank is distributed under the GNU General Public License, version 2 or later. See [`LICENSE.txt`](LICENSE.txt).
