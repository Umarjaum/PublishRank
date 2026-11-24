# PublishRank SEO REST API (2.26.2)

Namespace: `publishrank/v1`. All routes are additive; existing routes and the `publishrank_seo` field are unchanged except for the privacy change below.

| Route | Method | Who | Returns |
|---|---|---|---|
| `/posts/{id}/seo` | GET | Anyone for published, non-password posts of public types; otherwise users who can edit the post | Resolved title, description, canonical, robots, social fields, image, sources, reading time, link counts. Editors also get the editorial fields and `stored` (raw saved values; empty = automatic). |
| `/posts/{id}/seo` | PATCH | `edit_post` **and** PublishRank "Edit SEO" | Same as GET after saving. Header `X-PublishRank-Changed` lists changed fields. |
| `/posts/{id}/analysis` | GET | `edit_post` | Article Intelligence report (score, keyphrases, groups, checks, stats). |
| `/posts/{id}/head` | GET | as GET `/seo` | `title`, `meta[]`, `links[]`, `jsonld[]` — the real `<head>` output for headless front ends. |
| `/posts/{id}/schema` | GET | as GET `/seo` | `jsonld[]` only. |
| `/sitemaps` | GET | public | Sitemap index URL and every listed sitemap file. |

## PATCH fields

`title`, `description`, `robots`, `canonical`, `og_title`, `og_description`, `og_image`, `og_image_alt`, `x_title`, `x_description`, `focus_keyword`, `primary_keyphrase`, `secondary_keyphrases` (array or comma list), `keyphrase_synonyms`, `related_terms`, `entity_topic`, `cornerstone` (boolean), `schema_type`.

- Values go through the same sanitizers as the editor (registered post meta).
- An empty string or empty array removes the override (back to automatic).
- Unknown fields reject the whole request with HTTP 400 and `data.fields`; nothing is saved.
- Filter `publishrank_rest_seo_fields` adds or removes fields.

```bash
curl -X PATCH https://example.com/wp-json/publishrank/v1/posts/42/seo \
  -u editor:APPLICATION_PASSWORD -H 'Content-Type: application/json' \
  -d '{"title":"Solar panels: the 2026 guide","focus_keyword":"solar panels"}'
```

## Privacy change

The `publishrank_seo` REST field on core post routes no longer returns `focus_keyword`, `primary_keyphrase`, `secondary_keyphrases`, `keyphrase_synonyms`, `related_terms`, `entity_topic` and `cornerstone` to visitors who cannot edit the post. To restore the old behaviour: `add_filter( 'publishrank_rest_public_editorial_fields', '__return_true' );`

## Media tab routes (editor use)

| Route | Method | Who |
|---|---|---|
| `/media-links/post/{id}/images` | GET/POST | `edit_post` + Edit SEO |
| `/media-links/attachments/{id}/metadata` | POST | edit the attachment + Edit SEO. Fields: `alt`, `title`, `caption`, `description`, `credit`, `creator`, `copyright`, `license`, `license_page`, `modified` (send the value you loaded; a mismatch returns 409 with the current values). |
| `/media-links/check-url` | POST | Edit SEO. `url` must be http(s). |

## Analytics

`POST /collect` is limited to 120 requests per minute per visitor (`publishrank_rate_limit` filter, bucket `collect`); over the limit returns 429 with `Retry-After`. Paths longer than 1024 characters return 400.
