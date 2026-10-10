# PublishRank — JNews Twenty Compatibility Report

**Phase:** 0 — inspection only  
**Inspection date:** 2026-10-05  
**Repository:** `/vercel/share/v0-project`

## Scope and inspection result

The supplied workspace is a minimal Next.js v16 frontend scaffold, not a WordPress plugin repository. No WordPress installation, PHP source, Composer manifest, PHPUnit configuration, theme directory, or JNews Twenty source was present. No JNews source or WordPress runtime was modified.

Inspected paths:

- `package.json` — JavaScript dependencies and Next.js scripts only.
- `app/page.tsx` — default Next.js placeholder page; no WordPress or SEO integration.
- Repository-wide filename/content searches for PHP, JNews, `wp_head`, JSON-LD, and SEO settings — no relevant source found.
- `user_read_only_context/` — no theme or WordPress source was available.

Because the JNews Twenty source is unavailable, this document intentionally does **not** assert any JNews hook, filter, option key, PHP class, markup contract, or implementation detail.

## A. Confirmed documented behavior

The following are external documentation leads only and are not source-verified against an installed JNews Twenty version:

1. JNews documentation/search results describe a JSON-LD feature with a dashboard control labelled **Enable JSON LD Schema**.
2. The reported location is **JNews Dashboard → Customize Style → JNews: JSON LD Schema Setting**.
3. Breadcrumb configuration is reported under **JNews Dashboard → Customize Style → JNews: Single Post Option → Breadcrumb Setting**.
4. JNews Meta Header is reported as handling Open Graph and Twitter Card metadata.

These labels must be verified against the supplied JNews version before they appear as required installation steps. No JNews option key or hook is treated as confirmed.

## B. Observed behavior

| Observation | Evidence |
|---|---|
| No WordPress plugin source exists | Repository-wide `*.php` search returned no files. |
| No JNews Twenty source exists | Repository-wide `*jnews*` search returned no files. |
| No hooks or output emitters are available to inspect | Content searches for `wp_head`, `JNews`, `jnews`, JSON-LD, and SEO Schema returned no matches. |
| Existing project is a Next.js scaffold | `package.json` declares Next.js, React, and frontend dependencies only. |
| Existing page is unrelated to WordPress | `app/page.tsx` renders only the default Next.js placeholder. |

No JNews file paths or line numbers can be cited because no JNews source was supplied.

## C. Engineering assumptions and compatibility plan

These are explicitly unverified assumptions retained only to guide implementation:

1. PublishRank will use documented WordPress APIs and hooks as the generic baseline.
2. JNews-specific behavior will be isolated behind a feature-detected compatibility adapter.
3. No undocumented JNews hook, option, class, or markup contract will be used as an active dependency.
4. Duplicate handling will prefer verified documented-option detection, then verified filters, then buffered-output neutralization as a last resort.
5. JNews remains responsible for templates, layouts, modules, headers, footers, archives, and visual breadcrumbs.
6. PublishRank may emit technical breadcrumb schema without replacing JNews breadcrumb presentation, but only from verified data or a generic fallback.
7. PublishRank will emit one JSON-LD `@graph` only when page context and publisher/author data are available.
8. A fake JNews-like `wp_head` emitter fixture will be required in the WordPress test suite before compatibility behavior is considered verified.
9. Exact dashboard paths and toggle instructions will be versioned in installation documentation only after the target JNews source or site is inspected.

## Blocker for implementation

Provide the WordPress plugin repository and, if available, the JNews Twenty theme/plugin source or a read-only export of the target installation. The requested Phase 0 inspection is complete; no implementation phase should claim JNews compatibility until that evidence is available.

## Phase 0 change boundary

- Added: `docs/JNEWS-COMPATIBILITY.md`
- Modified: no theme, vendor, third-party plugin, or application source files
- Tests/quality gates: not run; no PHP/WordPress plugin exists in this workspace
- Remaining risk: all JNews integration mechanisms remain unverified
- JNews assumptions: listed explicitly in section C

*This report makes no claim about rankings, indexing, or Google News inclusion.*

## Sources and verification notes

An external documentation search was attempted for official JNews Twenty material. The available result described the dashboard labels above, but the source pages and exact JNews version were not retrievable in this workspace. Treat section A as a lead to verify, not as a substitute for source inspection.

The report contains no executable code and no production integration. Future implementation must replace provisional JNews references with source-backed evidence and exact paths/line numbers.

*This report makes no claim about rankings, indexing, or Google News inclusion.*
