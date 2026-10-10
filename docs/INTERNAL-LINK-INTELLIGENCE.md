# Content Graph / Internal Link Intelligence

PublishRank 2.5.1 adds a local content graph for orphan detection and intelligent internal-link recommendations.

## Orphan detection

The graph analyzes every published post, page and eligible public custom post type. A meaningful inbound link is an in-content internal anchor discovered in post content; navigation, attachment and self links are not counted as content-graph edges.

The **Internal Links** report shows:

- Orphan and weak-link counts based on the configurable inbound threshold.
- Inbound and outbound link counts.
- Critical, high, medium or low priority.
- The affected public URL.
- Post type and edit link.

Use **Run graph scan** after changing post types or importing content. Scanning uses keyset batches rather than offset pagination.

## Recommendations

Recommendations combine:

- Title and topic token overlap.
- PublishRank focus keywords.
- Category and tag overlap.
- Term-frequency content similarity.
- Existing related links.
- Content freshness.

Each suggestion includes a source article, target article, suggested anchor, score and human-readable signals. Existing source-to-target links and self-links are excluded, and duplicate recommendations are prevented by a database unique key.

## Approval and insertion

PublishRank never modifies published content automatically.

1. Review a pending recommendation.
2. Select **Approve**. This changes only recommendation status.
3. Select **Insert approved link** to add the approved contextual link.

Before insertion, PublishRank revalidates permissions, published status and the source content. If the source already links to the target, insertion is rejected.

## Storage and cleanup

Four local tables store nodes, edges, topic terms and recommendations. They are created with `dbDelta()` and removed when the plugin is deleted. No content or graph data is sent to an external service.
