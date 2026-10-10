# PublishRank llms.txt

## What it is

`llms.txt` is a proposed Markdown convention for giving compatible AI agents and retrieval systems a concise, curated map of a website. It is an orientation/index file, not an access-control file.

PublishRank serves it at `/llms.txt` when enabled. An optional extended `/llms-full.txt` can be enabled for sites that want to provide a larger generated resource.

## Configuration

Open **PublishRank → AI & llms.txt**. Configure:

- Site/project name and short summary.
- Optional additional context.
- Homepage, recent articles, pages and categories.
- Number of recent articles in the concise file.
- Curated authoritative resources such as editorial policy, corrections policy, About, contact and documentation.
- Optional `llms-full.txt` generation and its word limit.

## Important distinction

`llms.txt` does not replace `robots.txt`, does not grant crawler permission and does not guarantee that an AI system will fetch, use or cite a URL. It is best treated as a curated orientation layer for compatible clients.

## Recommended publisher setup

Keep the concise file focused on authoritative resources. Include your homepage, key editorial/authority pages, topic hubs and a sensible number of recent articles. Never put passwords, private URLs, API keys or confidential information in the file.
