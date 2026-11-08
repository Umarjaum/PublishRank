# Sitemaps

PublishRank serves one **sitemap index** and one child sitemap per content type. Search engines read the plain XML. People who open the same address in a browser see a readable page generated from `/publishrank-sitemap.xsl` (the XSL only dresses the view; it never changes the XML).

## Content types

| Type | Lists |
| --- | --- |
| Articles | Published posts |
| Pages | Static pages |
| Custom content | Custom post types |
| Categories & tags | Categories, tags and other taxonomies |
| Authors | Author archive pages |
| Attachment pages | Individual media attachment pages |
| Google News | Recent eligible articles (48-hour window by default) |
| Images / Videos | Media referenced by eligible content |

Large types are split into numbered parts (Part 1, Part 2, ...).

## The index view

* **Intro**: what the sitemap is in plain language, plus the one address to submit with a Copy button, Google Search Console and Bing Webmaster Tools shortcuts and View raw XML.
* **How it works**: a three-step strip (you publish, PublishRank updates the files, search engines read them).
* **Totals**: addresses listed, types of content, sitemap files and the newest change, each with an icon.
* **What's in your sitemap**: one row per content type with its own colour and icon, a short description, the number of addresses, its share of the total, the last change and an Open button. Empty rows explain that they fill up automatically; types split into several files list their parts in the row; the Google News row notes the freshness window. Indexes with more than 40 files skip address counting and show file counts instead.
* **Good to know**: submit just one address, empty rows are normal, and this page is for people while search engines read the XML.

The view follows the visitor's light or dark colour preference, prints cleanly and works on phones.

## Child sitemap view

Shows a filterable, pageable URL table, quick checks (URL limit, last-modified dates, HTTPS, news titles) and links to sibling sitemaps. News, image and video columns appear only when the file contains that data.

## Notes

* An empty sitemap is valid.
* A sitemap is a discovery hint, not an indexing guarantee.
* Use **PublishRank → Sitemap Hub** after changing permalinks, caching or sitemap settings.
