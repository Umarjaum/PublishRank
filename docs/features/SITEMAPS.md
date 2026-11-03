# Sitemaps

PublishRank serves one **sitemap index** and one child sitemap per content type. Search engines read the plain XML. People who open the same address in a browser see a readable page generated from `/publishrank-sitemap.xsl` (the XSL only dresses the view; it never changes the XML).

## Content types

| Type | Lists |
| --- | --- |
| Articles | Published posts |
| Pages | Static pages |
| Custom content | Custom post types |
| Topics | Categories, tags and other taxonomies |
| Authors | Author archive pages |
| Media files | Uploaded image attachments |
| Google News | Recent eligible articles (48-hour window by default) |
| Images / Videos | Media referenced by eligible content |

Large types are split into numbered parts (Part 1, Part 2, ...).

## The index view

* **Hero** with the PublishRank mark, copy-URL and raw-XML buttons.
* **Summary tiles**: sitemap files, content types, URLs listed and the newest change.
* **Content showcase**: a coloured bar showing each type's share of listed URLs, with a legend that links to the matching card. Indexes with more than 40 files skip URL counting and show the share of files instead.
* **Type cards**: one card per type with its files, URL counts and last-modified times. The Google News card notes the freshness window.
* **Submit box**: the exact address to give search engines, plus a link to robots.txt.

The view follows the visitor's light or dark colour preference, prints cleanly and works on phones.

## Child sitemap view

Shows a filterable, pageable URL table, quick checks (URL limit, last-modified dates, HTTPS, news titles) and links to sibling sitemaps. News, image and video columns appear only when the file contains that data.

## Notes

* An empty sitemap is valid.
* A sitemap is a discovery hint, not an indexing guarantee.
* Use **PublishRank → Sitemap Health** after changing permalinks, caching or sitemap settings.
