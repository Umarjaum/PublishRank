# PublishRank 2.9.55 Release Notes

**Release:** 2.9.55  
**Product:** PublishRank for WordPress  
**Release focus:** Real-time SERP Preview and connected schema integrations

## Overview

PublishRank 2.9.55 improves the editorial SEO workflow by bringing the plugin's search-preview and structured-data systems closer to the values that PublishRank resolves for the public page. Editors can review unsaved content changes before publishing, inspect the generated search snippet, review social metadata, examine JSON-LD entities, and identify common metadata or schema problems from the WordPress editor.

This release preserves PublishRank's existing title, description, canonical URL, Open Graph, X/Twitter, News SEO, sitemap, and schema-generation behavior. The preview is an editorial simulation and validation aid; it does not guarantee that Google or a social platform will use the exact same presentation.

## Real-time SERP Preview

### Unsaved editor state

The Live Preview reads the current editor state instead of requiring the editor to save a draft before checking the result. It is designed to work with both the WordPress block editor and the Classic Editor.

The preview can reflect changes to:

- Post or page title.
- Body content.
- Excerpt.
- Slug and resulting URL context.
- Featured image.
- PublishRank SEO title override.
- PublishRank meta description override.
- Canonical URL override.
- Robots directives.
- Open Graph title, description, image, and image alt text.
- Schema type and supported schema-specific fields.
- FAQ, video, audio, event, product, recipe, review, and related structured-data fields where available.

Previewing does not save the post or modify the database. PublishRank builds an in-memory preview context and restores the request state after the preview response is generated.

### Google-style search result simulation

The Search Snippet panel presents a simulated Google result containing:

- Site name and favicon when available.
- Breadcrumb-style URL path.
- Resolved search title.
- Resolved meta description.
- Article date context where applicable.
- Desktop and mobile preview modes.
- Title pixel-width guidance.
- Meta-description character guidance.
- Warnings for empty or potentially truncated values.

The preview uses PublishRank's existing title, description, canonical, and image-resolution paths rather than maintaining a separate duplicate SEO-generation system. When an editor has not entered a custom value, the preview uses PublishRank's normal fallback logic.

### Responsive editor behavior

The preview controls are designed for WordPress editing screens of different widths. The desktop and mobile modes are simulated inside the editor, while the surrounding preview interface remains responsive for smaller admin screens.

## Connected schema integrations

### Existing schema engine preserved

PublishRank 2.9.55 does not introduce a competing JSON-LD generator. It extends and exposes the existing connected schema architecture used by PublishRank's public output.

The graph connects compatible entities with stable references where appropriate, including:

- Publisher organization or person.
- Website.
- Web page.
- Article or NewsArticle.
- Author Person.
- BreadcrumbList.
- ImageObject.
- VideoObject and audio entities where configured.
- Type-specific rich-result entities when their required content is present.

The plugin avoids emitting every schema type on every page. The selected type, page context, content type, settings, exclusions, and completeness of required fields determine which entities are emitted.

### Per-content schema selection

The Schema meta box allows editors to choose an appropriate schema type for the current post, page, or supported public custom post type. Automatic mode remains available and uses the configured defaults and page context.

Type-specific panels expose fields only when they apply to the selected type. Examples include:

- FAQ questions and answers.
- Video URLs, embed URLs, thumbnails, and duration.
- Audio URL and duration.
- Live-blog coverage details.
- Event start time, location, address, and country.
- Recipe ingredients, preparation steps, duration, and yield.
- Review item and rating.
- Product SKU, brand, price, currency, and availability.

Incomplete type-specific information is handled conservatively. PublishRank avoids outputting an empty or misleading rich-result entity when the necessary data is not available.

### Author and publisher integration

Author profile data can feed the Person entity used by the connected graph. Existing author social profiles, expertise topics, job titles, and publisher identity settings remain part of the same schema-generation path.

The schema output continues to use the configured canonical URL, publisher information, article dates, author information, and image fallbacks instead of creating parallel values in the preview system.

## JSON-LD inspection and validation

The Live Preview JSON-LD panel provides an editor-facing view of the generated structured data. It can:

- Display each JSON-LD block.
- Show detected entity types.
- Highlight invalid JSON blocks.
- Surface basic structural issues.
- Show connected entity information.
- Provide copyable formatted JSON-LD.
- Link published content to Google's Rich Results Test when a public URL is available.

These checks are local and diagnostic. Valid JSON-LD does not guarantee rich-result eligibility, indexing, ranking improvement, or a particular Google presentation.

## Preview panels

PublishRank 2.9.55 organizes the editor preview into focused panels:

1. **Search snippet** — simulated Google desktop and mobile result.
2. **Social card** — Facebook/LinkedIn and X/Twitter image, title, and description preview.
3. **Meta tags** — resolved metadata and canonical links.
4. **JSON-LD** — structured-data blocks, entities, and validation information.
5. **Checks** — warnings and actionable editorial diagnostics.

The preview output is generated from the same PublishRank modules responsible for public output wherever possible. This reduces differences between what an editor reviews and what PublishRank publishes.

## Compatibility and safety

- Supports the WordPress block editor and Classic Editor workflow.
- Uses REST permission checks so a user must be allowed to edit the selected post.
- Uses WordPress nonces and capability checks in the preview request path.
- Escapes preview-rendered values and renders dynamic preview text through safe DOM text nodes.
- Preserves existing post metadata when the preview is requested.
- Does not require a paid external API.
- Does not change the active theme's templates or visual design.
- Keeps existing PublishRank schema and social-output ownership intact.

## Migration and upgrade notes

No manual migration is required for version 2.9.55. Existing SEO fields, schema selections, author profile settings, publisher identity settings, canonical URLs, and social metadata remain in place.

After upgrading:

1. Open an existing post or page.
2. Expand **PublishRank Live Preview**.
3. Edit the title, description, slug, image, or schema fields.
4. Confirm that the Search Snippet, Social Card, Meta Tags, JSON-LD, and Checks panels update.
5. Review the JSON-LD panel before publishing content with rich-result requirements.

## Known limitations

- Search engines can rewrite titles and descriptions, select different images, alter snippets, or omit rich results.
- The mobile and desktop cards are simulations, not screenshots of Google's production UI.
- Unsaved previews depend on the fields exposed by the active WordPress editor and supported PublishRank panels.
- Google Rich Results Test and other external validators require a publicly accessible URL for direct URL testing.
- Local preview validation cannot guarantee indexing, eligibility, ranking, or social-platform rendering.

## Release summary

PublishRank 2.9.55 makes the editorial review loop more immediate and more trustworthy by combining unsaved-content previews with the plugin's existing technical SEO and schema engines. Editors can make changes, see the expected search and social presentation, inspect the connected structured-data graph, and address warnings before publishing—without introducing duplicate metadata or a second schema system.
