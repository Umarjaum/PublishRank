# PublishRank 2.9.76 — WordPress.org submission candidate

## Summary

Version 2.9.76 packages the WordPress.org review corrections carried into 2.9.75 and makes the repository test suite agree with the shipped runtime. The plugin continues to require a valid request nonce and the `manage_options` capability for the reviewed admin actions. Requests without a nonce are intentionally rejected; valid, authenticated form submissions proceed to the corresponding handler.

This maintenance release also corrects the Settings and readme attribution disclosure. Technical provenance markers are always-on source metadata; they do not add a visible footer credit or promotional link.

## Review items covered

- WordPress.org directory icon GIFs are absent from the installable ZIP.
- Sitemap XSL output embeds the plugin's bundled local CSS and JavaScript rather than printing direct asset tags.
- The optional remote News announcements feed, admin screen, and associated front-end assets are absent; News sitemap functionality remains.
- Site Verification upload, configuration snapshot restore, and redirect settings handlers use the capability-and-nonce guard and explicitly verify the matching action nonce.

## Validation performed

- `composer test`: **33 standalone PHP test files passed**, followed by PHP syntax lint across repository PHP files.
- `tests/release_2_9_76.php`: release header/runtime/stable-tag consistency, ZIP exclusions, local sitemap asset handling, and nonce/capability wiring all pass.
- Installed and activated the generated ZIP on a disposable **WordPress 7.1.3** site running PHP 8.3.6, with `WP_DEBUG`, `WP_DEBUG_LOG`, and `SCRIPT_DEBUG` enabled.
- Frontend homepage, robots.txt, sitemap index, post sitemap, and sitemap XSL returned HTTP 200. PublishRank Settings, Site Verification, Redirects, Configuration History, and Sitemap Health admin pages returned HTTP 200. Ten enqueued PublishRank styles/scripts were served locally with HTTP 200.
- End-to-end handler matrix using the site's actual authenticated admin form nonces:

  | Handler | Missing nonce | Valid nonce |
  | --- | ---: | ---: |
  | Site Verification upload | 403 | 302 redirect after handler validation |
  | Configuration snapshot restore | 403 | 302 redirect after handler validation |
  | Redirect settings | 403 | 302 redirect after handler validation |

  The valid redirect-settings request updated the test site's max-hops option to the submitted value. The valid Verification/Restore requests used empty upload data and a deliberately nonexistent snapshot, respectively, so they tested nonce acceptance without performing a real upload or configuration rollback.
- `wp-content/debug.log` remained empty after the frontend/admin requests and all six POST tests.
- The built submission ZIP has **169 files** under a single `publishrank/` root; it contains the plugin readme/bootstrap, omits tests/developer files, and excludes both flagged WordPress.org directory GIFs.

## Submission artifact

Build with `python3 tools/build_plugin_zip.py`; the reviewed archive was saved as `PublishRank-v2.9.76.zip`. Upload the ZIP through the WordPress.org plugin submission/review workflow. Directory artwork should be uploaded separately through the repository's SVN assets area after approval.

## Scope note

The test environment was a disposable local WordPress instance, not a production site. No live WordPress.org submission or SVN asset upload was performed.
