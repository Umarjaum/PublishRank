# 2.26.2 test scripts

Run against a disposable WordPress site only: they create users and posts.

    WP_LOAD=/path/to/wordpress/wp-load.php php rest-api-and-permissions.php

- `rest-api-and-permissions.php`, `media-tab-endpoints.php`, `image-licence-metadata.php` expect post ID 9 with featured image ID 7 (edit the IDs at the top for your site).
- `schema-fixtures.php` creates one published post per schema option and writes the URL list to the temp directory; then run `python3 validate-schema-org.py`, `php google-eligibility.php` and `php schema-studio-templates.php`.
- The browser checks (block editor bridge counter, Classic Editor dock, previews) were run with Playwright and are described in `../VALIDATION-REPORT.md`.
