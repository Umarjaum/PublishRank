# PublishRank 2.25.1 — Compatibility report

| Area | Status | Notes |
|---|---|---|
| WordPress 7.1.4 | Tested | All suites, browser checks |
| WordPress 6.3–7.0 | Not tested | Declared minimum 6.3; new editor code uses `wp.editor.PluginDocumentSettingPanel` with a fallback to `wp.editPost` |
| PHP 8.3.36 | Tested | Runtime |
| PHP 8.2.35 | Syntax-checked | All 249 PHP files lint clean |
| PHP 7.4–8.1, 8.4 | Not run | Static scan found no PHP 8-only syntax or functions in plugin code; not executed |
| Block editor | Tested | FAQ block, Sources panel |
| Classic editor | Partially | Existing 2.25.0 paths unchanged; new "Sources in this article" box not opened in a browser |
| WooCommerce 11.3.0 | Tested (activation + analytics suite) | Product schema on a real catalogue not re-tested |
| Twenty Twenty-Five | Tested | FAQ front end, breadcrumbs, sign-in page |
| Other themes | Not tested for new features | Theme masking was tested on 9 themes in 2.25.0 |
| Multisite | Not tested | Per-site options and `get_blog_option` paths reviewed in code |
| MySQL / MariaDB | Not tested | SQLite used; schema uses `dbDelta` with standard column types |
| Page cache / object cache plugins | Not tested | Traffic Guard only sees requests that reach PHP |
| Fresh install | Tested | Clean site, activation, DB version 6 |
| Upgrade from 2.25.0 | Tested | Settings, lists, sign-in address and FAQ content preserved; DB 5 → 6 |
| Uninstall | Tested | Via WordPress `delete_plugins()` |
| Browsers (admin) | Chromium tested | Admin screens use standard CSS (grid/flex) |
