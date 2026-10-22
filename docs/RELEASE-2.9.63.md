# PublishRank 2.9.63

## Robustness audit and fixes

- Verified there is only one PublishRank custom-admin implementation and one module registration. The existing feature was not duplicated, but its previous behavior had recovery and lockout edge cases.
- Password recovery now remains available for `lostpassword`, `retrievepassword`, `rp`, `resetpass`, `checkemail`, confirmation, recovery-mode, post-password and registration actions.
- Any already-authenticated WordPress user can continue using normal WordPress admin routing after the short entry gate expires; WordPress capabilities still control what that user can do.
- Direct 404 responses now send no-cache headers so a CDN or reverse proxy cannot cache an unauthenticated `/wp-admin/` response and serve it to an authorized browser.
- The native `/wp-admin/*.php` path remains unchanged for authorized users. Only unauthenticated direct probes are rejected.
- AJAX, admin-post, uploads, script/style loading, REST and cron exceptions remain intact.

## Verified overlap

The audit found no second custom-admin gate in PublishRank. The separate Supabase module is an optional gate for PublishRank screens only; it does not replace or duplicate the native WordPress login/admin gate. Traffic Guard explicitly excludes `/wp-admin`, `/wp-login.php`, REST and cron paths, so it does not compete with this feature.
