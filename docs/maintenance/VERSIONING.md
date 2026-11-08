# Versioning

The release number is **2.18.2**. It appears in:

1. `publishrank.php` plugin header (`Version:`)
2. `PUBLISHRANK_SEO_VERSION` constant (source of truth at runtime)
3. `readme.txt` `Stable tag:`
4. `Branding::version()` fallback (used only if the constant is missing)
5. `PublishRank_Newsroom::VERSION` (now reads the constant)
6. Admin screen header badge, sitemap view header and footer (read from `Branding::version()`)
7. Top entries of `readme.txt` and `changelog.txt`
8. Version references in `docs/` (index, FEATURES, compatibility checklist) and the in-dashboard documentation heading

When releasing a new version, change items 1, 2, 3 and 4 together, add the changelog entry, and search the package for the previous number. Historical numbers in changelog entries and in `Installer.php` upgrade checks (for example `2.9.91`) are intentional and must stay.

## Automated checks

- `tools/check-version.sh` compares the plugin header with the constant, readme stable tag, both changelogs, the Branding fallback and the version text in `docs/` and the help page. It exits non-zero on any mismatch.
- `tests/cases/VersionConsistencyTest.php` runs the same comparison inside the PHP test suite (`php tests/run.php --filter=Version`).
