# PublishRank branding assets

All files are generated from the original PublishRank logo and live in `assets/images/`. The contact and credit lines from the source artwork are intentionally not included in any derived file.

| File | Size | Used for |
| --- | --- | --- |
| `publishrank-logo-full.png` | 1536x1536 | Official PublishRank logo artwork supplied in the project assets, with the outer white background removed; kept as the master icon artwork. |
| `publishrank-logo-lockup.png` | 1060x790 | Mark + wordmark + tagline for docs and websites. |
| `publishrank-logo-512x512.png` | 512x512 | Downscaled full logo for listings. |
| `publishrank-mark-transparent.png` | 512x512 | Official PublishRank logo on a transparent outer background. |
| `icon-128x128.png` | 128x128 | Admin page header and sitemap page; WordPress.org icon. |
| `icon-256x256.png` | 256x256 | WordPress.org high-DPI icon. |
| `icon-512x512.png` | 512x512 | App stores, social profiles. |
| `menu-icon-20x20.png` / `menu-icon-40x40.png` | 20 / 40 | Admin sidebar icon (the 40px file is used so it stays sharp on high-DPI screens). |
| `favicon-32x32.png` | 32x32 | Browser tab icon for product sites. |
| `apple-touch-icon-180x180.png` | 180x180 | iOS home-screen icon for product sites. |
| `banner-772x250.png` / `banner-1544x500.png` | 772x250 / 1544x500 | WordPress.org plugin page banner (normal and high-DPI). |
| `social-share-1200x630.png` | 1200x630 | Open Graph / X preview for the plugin's own site. |
| `publisher-logo-600x60.png` | 600x60 | Example of a Google-compliant publisher logo size. |
| `publishrank-banner-publisher.png` | 1983x793 | Supplied PublishRank banner shown on the administrator dashboard; kept as uploaded. |

PublishRank never uses its own artwork as a visitor-facing fallback image on a publisher's website. Publishers choose their own publisher logo and default social image in **PublishRank → Settings**. Optional source attribution is disabled by default. Administrators can explicitly enable product/version markers and a deterministic provenance fingerprint in PublishRank Settings; these contain no visitor tracking.

For WordPress.org, upload `icon-*`, `banner-*` files to the SVN `/assets` directory using exactly these names.
