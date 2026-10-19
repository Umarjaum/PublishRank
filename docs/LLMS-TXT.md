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

## 2.9.51 additions

* **Safe by construction.** Password-protected and noindex posts are never listed or quoted in either file.
* **Authority first.** About, Contact, Editorial policy, Corrections, Ethics, Team and Privacy pages are detected by slug (and by WordPress' privacy-policy setting) and listed under "About and editorial standards" before the articles.
* **More sources.** Authors (with bios), tags, public custom post types and a "Machine-readable resources" section (sitemap index, News sitemap, robots.txt, RSS, llms-full.txt). Categories, tags and authors sit under the format's `## Optional` section unless you switch that off.
* **llms-full.txt.** Each entry carries URL, publish and update dates, author and section. Authority pages come first, and a per-article word cap keeps one long story from using the whole budget.
* **Performance.** Both files are cached until content, terms, authors or settings change, and they answer `If-None-Match` with 304.
* **Review.** The settings screen previews the saved file and flags a physical `llms.txt` in the web root, credentials in the text, oversized files, a missing summary and robots.txt conflicts.
