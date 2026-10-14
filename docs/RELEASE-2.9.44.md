# PublishRank 2.9.44

## Changes

- Consolidated the editor SEO analyzer into one AJAX path shared by the live analysis panel and score card.
- Added reliable Gutenberg, TinyMCE Classic Editor, and textarea content extraction.
- Added debounced analysis while editors change the title, content, description, or focus keyphrase.
- Fixed editor score-card availability for supported public editorial post types.
- Fixed WordPress admin-toolbar icon/label alignment and reduced the left admin-menu icon footprint.
- Kept the expanded schema registry and schema suite: NewsArticle, Article, BlogPosting, LiveBlogPosting, Report, TechArticle, ScholarlyArticle, sponsored content, Review, Recipe, WebPage variants, FAQ/Q&A, media, and Event types with type-specific detail panels and validation links.
- Corrected the root license notice to GPLv2 or later with rights attributed to Muhammad Umar Jabbar.
- Compatibility target: WordPress 7.1 and PHP 8.5; this sandbox verification used PHP 8.3.6, and the plugin remains installable on PHP 7.4+.

## Verification

The release is checked with PHP syntax lint, the repository's standalone regression tests, static analyzer-wiring assertions, schema-type assertions, archive integrity checks, and version/license consistency checks. A complete staging test with the target WordPress installation remains recommended before production deployment.
