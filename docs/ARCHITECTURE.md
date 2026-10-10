# PublishRank Architecture

PublishRank is an update-safe WordPress plugin. Presentation remains owned by JNews Twenty; PublishRank owns technical SEO output.

- PSR-4 namespace: `PublishRankSEO\` mapped to `src/`.
- `Core\Plugin` is the composition root and registers hooks.
- `Core\OutputRegistry` owns one emission slot per SEO element.
- One JSON-LD `@graph` is emitted through the registry.
- One sitemap index is emitted; news sitemap is a registered child endpoint.
- No custom database tables. Persistent keys are prefixed `publishrank_seo_`.
- Compatibility is feature-detected and isolated in `Compatibility\JNewsAdapter`.
- Capture/neutralization is disabled unless a verified emitter is detected; generic WordPress output remains the fallback.

Phases are intentionally incremental: bootstrap, registry/meta, schema/sitemaps, redirects/admin, then quality gates and tests.

## Ownership

JNews owns templates, layouts, modules, headers, footers, archives, and visual breadcrumbs. PublishRank emits head metadata, technical robots/canonical directives, structured data, XML sitemaps, redirects, and diagnostics.

## Caching

Sitemap payloads and expensive derivations use transients. `publishrank_seo_cache_epoch` invalidates them after relevant content/settings changes.

## Security

All admin writes require capabilities and nonces; all stored values are sanitized and validated; all output is escaped; imports accept only schema-validated JSON and PublishRank-prefixed keys.

## Unverified JNews behavior

See `docs/JNEWS-COMPATIBILITY.md`. No undocumented JNews hook or option is an active dependency.

## Phases completed in this increment

1. Architecture and plugin bootstrap
2. Output registry and generic metadata services
3. Schema and sitemap service boundaries
4. Redirect and diagnostics boundaries
5. Quality-gate, test, and installation documentation scaffolding

The supplied workspace is not a WordPress runtime, so execution of PHP quality gates remains environment-blocked until installed in a WordPress plugin repository.

*This document makes no claim about rankings, indexing, or Google News inclusion.*

## Sources and verification notes

An external documentation search was attempted for official JNews Twenty material. The available result described the dashboard labels above, but the source pages and exact JNews version were not retrievable in this workspace. Treat section A as a lead to verify, not as a substitute for source inspection.

The report itself contains no executable code and no production integration. Any future implementation must replace provisional JNews references with source-backed evidence and exact paths/line numbers.

### Source status

No JNews source, WordPress installation, or PHP repository is mounted in this workspace. Therefore no JNews file paths or line numbers can be cited, and all JNews mechanisms remain assumptions.

*This report makes no claim about rankings, indexing, or Google News inclusion.*
