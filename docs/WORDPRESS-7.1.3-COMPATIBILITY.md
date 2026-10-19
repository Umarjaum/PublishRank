# WordPress 7.1.3 compatibility test

The development repository may use automated CI to target WordPress 7.1.3. CI configuration is intentionally excluded from the production plugin ZIP because WordPress.org flags `.github` directories in submitted plugin packages.

## Local sandbox verification — October 17, 2026

The plugin was installed and activated through WordPress's non-silent activation API on WordPress 7.1.3, PHP 8.3.6, and MariaDB 10.11 with dummy publisher content. Activation created all four internal-link tables and registered the `llms.txt` and `llms-full.txt` routes. The standalone regression suite passed 35/35 tests, PHP lint passed for all 146 plugin PHP files, and the live HTTP suite passed 77/77 checks. That suite covered representative advanced schema, sitemap, robots, redirects, IndexNow key-file verification, LLMS responses, and the registered admin screens. The fresh debug log after the final HTTP run contained no notices, warnings, deprecations, or database errors.

These are local tests, not authenticated provider tests. Google OAuth, Supabase sign-in, Baidu submission, and outbound IndexNow submissions were not run against live accounts or search engines. Local testing also does not replace staging checks with the publisher's active theme, cache/CDN, and other plugins.

## Important status note

Adding a workflow does not itself prove compatibility. Review successful CI results and complete staging checks before treating compatibility as verified. The WordPress.org `Tested up to` header uses the major version (`7.1`), not the patch version (`7.1.3`). The workflow currently provides an activation smoke test plus standalone tests; it is not a full end-to-end test of every admin screen, sitemap response, theme, or third-party plugin.

For release sign-off, also test a staging site on WordPress 7.1.3 with the PHP version used by the target host. Verify activation/deactivation, saving settings, News Sitemap output, standard sitemaps, schema output, redirects, and compatibility with the site's active theme and other SEO plugins.
