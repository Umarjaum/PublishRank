# PublishRank 2.9.87 — synchronized publisher workflow release

PublishRank 2.9.87 synchronizes the repaired plugin build with the PublishRank website release channel.

## Included fixes and features

- Frontend breadcrumb visibility is independent from BreadcrumbList SEO/schema output.
- The Fix Assistant preserves a saved meta description when the editor meta panel has not mounted or synchronized yet.
- Missing article-image alt suggestions reuse the latest Media Library alt text before falling back to a caption or cleaned filename. Publishers can edit and override the value.
- Frontend HTML provenance now starts with `<!-- SEO Powered by PublishRank -->` and generated SEO and structured-data sections close with `<!-- end of SEO data Powered by PublishRank -->`.
- Dashboard destinations remain registered and accessible to administrators.

## Validation

- PHP syntax lint: 118 files passed.
- JavaScript syntax validation: 19 files passed.
- The verified release archive contains 217 entries and passes `unzip -t`.
- Website release asset: `downloads/publishrank-2.9.87.zip`.
- Website release asset SHA-256: `52d43a5ffb0c4353c607b8c4305fac7792e7bb5b6af38df0d9d1a4ad47a58684`.

This release still requires WordPress 6.3+ and PHP 7.4+.
