# WordPress 7.1.3 compatibility test

The development repository may use automated CI to target WordPress 7.1.3. CI configuration is intentionally excluded from the production plugin ZIP because WordPress.org flags `.github` directories in submitted plugin packages.

## Important status note

Adding a workflow does not itself prove compatibility. Review successful CI results and complete staging checks before treating compatibility as verified. The WordPress.org `Tested up to` header uses the major version (`7.1`), not the patch version (`7.1.3`). The workflow currently provides an activation smoke test plus standalone tests; it is not a full end-to-end test of every admin screen, sitemap response, theme, or third-party plugin.

For release sign-off, also test a staging site on WordPress 7.1.3 with the PHP version used by the target host. Verify activation/deactivation, saving settings, News Sitemap output, standard sitemaps, schema output, redirects, and compatibility with the site's active theme and other SEO plugins.
