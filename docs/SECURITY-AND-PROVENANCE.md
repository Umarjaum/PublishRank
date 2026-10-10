# Optional source attribution and release identification

PublishRank's public HTML source attribution is optional and disabled by default to respect WordPress.org guideline 10. Administrators can explicitly enable it under **PublishRank → Settings → HTML source attribution**.

When enabled, the plugin emits:

- A `publishrank-brand` meta tag.
- A `publishrank-version` meta tag.
- An HTML comment with the product, release version, and deterministic release fingerprint.
- The separate `<!-- Powered by PublishRank SEO -->` comment.

These markers are generated locally. They do not track visitors, make network requests, or act as a license certificate. They can be disabled by turning off the setting; the plugin's SEO features continue to work normally.

## Verification checklist

1. Enable HTML source attribution in PublishRank Settings if you want the markers.
2. View the source of a public page and search for `Powered by PublishRank SEO`.
3. Confirm the version and fingerprint correspond to the installed release.
4. Turn the setting off to confirm the markers are no longer emitted.
