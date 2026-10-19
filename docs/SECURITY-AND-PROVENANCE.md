# Public attribution and release identification

## Public output is opt-in

PublishRank does not force a promotional comment, link, hidden SEO link, or footer credit into publisher pages. Both **Version provenance metadata** and **PublishRank footer attribution** are disabled by default and controlled by an administrator in **PublishRank > Settings**.

PublishRank always adds local product/version/fingerprint markers to public HTML. When footer attribution is enabled, the configured footer credit is visible to visitors. These settings do not alter canonical, robots, sitemap, or structured-data output. The theme compatibility fallback restores only missing SEO head and JSON-LD output; it never adds attribution.

The neutral `SEO metadata output begin` and structured-data delimiters are implementation markers, not promotional links or hidden ranking content. They contain no remote request, visitor identifier, or tracking pixel.

## Verification checklist

1. With both attribution settings disabled, view a public page's source and confirm there is no forced `SEO powered by PublishRank` comment, PublishRank generator tag, or footer credit.
2. Enable one setting at a time and confirm only the selected provenance markers or visible footer credit appear.
3. Disable the setting again and confirm the optional output is removed from fresh uncached page output.
4. Confirm themes missing `wp_head()` can still receive missing SEO metadata and JSON-LD through the fallback, without attribution being injected.

The plugin version is reported separately in the WordPress administration area and package metadata. Public provenance output is not a license certificate.
