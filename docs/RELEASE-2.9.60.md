# PublishRank 2.9.60

- Made the editor SEO score card draggable with both mouse and touch/pointer input, constrained it to the viewport, and remembered its position in the current browser.
- Added editable Search title and Meta description controls directly to the live search preview.
- Added Rank Math-style `%focus_keyword%` and `%%focus_keyword%%` variables, plus `%focuskw%` aliases, which resolve to the post Focus Keyword in previews and frontend metadata.
- Added one-click Insert `%focus_keyword%` controls for both title and description.
- Live preview changes remain unsaved until the normal WordPress post save, while the preview uses the unsaved values immediately.
