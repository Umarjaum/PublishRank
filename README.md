# PublishRank

**Free, open-source WordPress SEO plugin for news publishers, editorial teams, blogs, and content-heavy websites.**  
*Enterprise SEO Infrastructure for WordPress Publishers*

PublishRank brings technical SEO tools for WordPress into a publisher-focused plugin. It is built for news websites, newspapers, magazines, digital publishers, editorial teams, blogs, and general WordPress sites. If you are researching a **free WordPress SEO plugin**, a **Yoast SEO alternative**, a **Rank Math alternative**, or a plugin with **News SEO and XML news sitemaps**, review PublishRank's feature set below.

> PublishRank is an independent project. It is not affiliated with, endorsed by, or a clone of Yoast or Rank Math. Features and workflows differ, so evaluate compatibility before switching from another SEO plugin.

## Current version

- **Version:** 2.9.43
- **License:** GPL-2.0-or-later
- **WordPress:** Requires 6.3 or later
- **PHP:** Requires 7.4 or later
- **Tested up to:** WordPress 6.7
- **Official website and documentation:** https://publishrank.netlify.app/
- **Developer website:** https://umarjaum.netlify.app/
- **Source repository:** https://github.com/Umarjaum/PublishRank

## Features

- **Technical SEO metadata:** SEO titles and descriptions, canonical URLs, robots directives, Open Graph, and X/Twitter metadata.
- **Structured data:** Connected JSON-LD for organizations, people, webpages, articles, breadcrumbs, and supported media/content types.
- **XML sitemaps:** Sitemaps for posts, pages, taxonomies, authors, images, and videos, plus a Google News sitemap.
- **Redirects and 404 monitoring:** Manage redirects and review locally recorded 404 paths and referrer information.
- **Publisher and article SEO:** Article schema options and per-article author metadata overrides.
- **Robots and crawling controls:** Global and per-content index/follow directives, robots.txt controls, hreflang support, and breadcrumbs.
- **IndexNow:** Optional notifications to the IndexNow service for eligible public URLs. IndexNow does **not** guarantee indexing and is **not supported by Google**.
- **Internal-link tools:** Internal-link recommendations, orphan-page insights, and Publisher Link Intelligence for broken or redirected links, with optional queued external URL checks.
- **Publisher Toolkit:** Permission-aware bulk SEO editing and optional assistance for missing image alt text.
- **Local SEO:** Optional local business profile and LocalBusiness structured data.
- **LLMs discovery files:** Configurable `llms.txt` and optional `llms-full.txt`.
- **Diagnostics and WP-CLI:** Sitemap diagnostics and supported command-line workflows.
- **No external AI API required** for the core plugin features described here.

## Install in WordPress

1. Download the ZIP from the project's Releases page (when a release is published) or obtain the plugin ZIP from the project maintainer.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP, install it, and activate **PublishRank**.
4. Open **PublishRank** in the dashboard and complete onboarding.
5. Review metadata, schema, sitemap, and robots settings before using it on a production site.
6. If replacing another SEO plugin, plan the migration and ensure only one plugin controls each metadata/schema output to avoid duplicates.

For source-code development, clone or download the [PublishRank source repository](https://github.com/Umarjaum/PublishRank) and place the `publishrank` plugin directory in `wp-content/plugins/`. The main plugin file is `publishrank.php`.

## Is PublishRank a Yoast SEO or Rank Math alternative?

PublishRank is an independent **free WordPress SEO plugin** option focused on technical SEO and publisher-oriented workflows, including News SEO, news sitemaps, redirects, robots controls, schema, and internal-link auditing. It may suit publishers who want these tools in a focused plugin, but it is not a feature-for-feature replacement for Yoast SEO or Rank Math. Check the exact features you rely on and test on staging before switching.

## Compatibility and limitations

- Do not run multiple plugins that output the same title, canonical, robots, or schema data without configuring ownership; duplicate output can occur.
- Search Console reporting, where configured, requires the site administrator's own Google Cloud OAuth credentials and API setup.
- External URL checks may be affected by server restrictions, rate limits, redirects, or remote sites blocking automated requests; review findings before acting on them.
- PublishRank does not guarantee rankings, crawling, indexing, traffic, or Google News inclusion.
- IndexNow is not a Google indexing API.

## Privacy

Ordinary front-end rendering does not send visitor analytics to a PublishRank-owned service. Optional integrations may make external requests when enabled. For example, IndexNow submits eligible public URLs and the required site/key information to the IndexNow service. Review the plugin's `readme.txt` and documentation before enabling integrations.

## Development and tests

The repository includes PHP test scripts in `tests/`. Run them in an environment with PHP CLI:

```bash
for test in tests/*.php; do php "$test" || exit 1; done
```

These lightweight scripts are not a substitute for testing in a real WordPress installation. Test plugin activation, settings, redirects, sitemaps, integrations, and theme/plugin compatibility on a staging site before production deployment.

## Documentation and support

- Project website and documentation: https://publishrank.netlify.app/
- Developer website: https://umarjaum.netlify.app/
- Email: umarjaum@gmail.com

## License

PublishRank is distributed under the GNU General Public License, version 2 or later. See [`LICENSE.txt`](LICENSE.txt).

## Search terms

WordPress SEO plugin · free WordPress SEO plugin · SEO plugin for WordPress · Yoast SEO alternative · Rank Math alternative · WordPress technical SEO · News SEO · Google News sitemap · XML sitemap plugin · schema markup · structured data · redirect manager · 404 monitor · internal link audit · orphan pages · IndexNow · robots.txt · llms.txt · SEO plugin for news websites.
