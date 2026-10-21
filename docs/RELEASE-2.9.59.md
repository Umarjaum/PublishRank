# PublishRank 2.9.59

- Fixed the editor SEO score number contrast: it is now a bold accessible green on the white score card background.
- Added protected custom administrator access with direct `/wp-admin/` and `/wp-login.php` gating when configured, while preserving AJAX, REST, cron, admin-post and recovery-safe integrations.
- Added Web Story editor support using standards-compliant `Article` structured data, portrait poster fields and optional story publisher logo fallback.
- Normalized schema publisher identity references so Organization/Person, WebSite, WebPage, article and media entities use one shared `@id` rather than conflicting inline publication values.
