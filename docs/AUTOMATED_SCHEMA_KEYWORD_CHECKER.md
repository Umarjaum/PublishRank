# PublishRank 1.5 automated schema and keyword analysis

## Automated schema markup

PublishRank now adds schema for published singular public content from the current WordPress query. Public custom post types receive a `WebPage` node with publication and modification dates, canonical URL, and an `Article` main entity when the post type supports editorial content. Existing post `NewsArticle` output and the single PublishRank graph remain unchanged.

Content using real HTML `<details><summary>Question</summary>Answer</details>` FAQ structures receives a `FAQPage` node. Empty or incomplete details are ignored, so the plugin does not manufacture FAQ data.

## Keyword density checker

The post editor's PublishRank panel now analyzes the saved focus keyphrase automatically. It counts exact normalized phrase occurrences across the title and content, reports total words and density, and labels the result low, healthy, or high. The guidance range is 0.5%–3% and is explicitly presented as an editorial signal rather than a ranking guarantee.

The analyzer does not alter content, insert keywords, or claim search-engine ranking outcomes.
