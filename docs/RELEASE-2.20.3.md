# PublishRank 2.20.3

- Added a generated, copyable `<link rel="me" href="…">` HTML-head snippet to the Mastodon page. It refreshes from the Mastodon profile URL/handle field; PublishRank continues to emit the reciprocal link automatically when enabled.
- Clarified the two-way verification steps: add the website URL to a Mastodon profile field, and make sure the website HTML contains a direct `rel="me"` link back to the Mastodon profile. No one-time verification code is issued by Mastodon.
- Refreshed WP Hide Login’s PublishRank look with burgundy callout styling, clearer URL input groups, responsive controls, improved keyboard focus and touch targets.
