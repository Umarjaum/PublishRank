# PublishRank 2.9.62

- Added a highlighted **NEW** Admin access protection dropdown in PublishRank Settings.
- Publishers can enable a unique private entry slug or disable the protection without deleting the saved slug.
- Direct unauthenticated `/wp-admin/` and `/wp-admin/next-admin-slug` requests now return 404 before WordPress can redirect bots to the login form.
- Direct unauthenticated `/wp-login.php` login requests return 404, while password-recovery actions remain available so publishers cannot be locked out.
- The private entry link establishes a short-lived HttpOnly gate, after which WordPress continues using normal `/wp-admin/*.php` routes for the authorized browser.
- Active administrators remain allowed after the short gate expires, and AJAX, REST, cron, admin-post and upload service paths remain protected from accidental breakage.
- WordPress-generated login links now use the private entry URL, and the setting is included in PublishRank settings export/import.
- Added explicit guidance that hiding the entry point is defense-in-depth, not a replacement for strong passwords, two-factor authentication, updates or a WAF.

## WordPress guidance reviewed

- WordPress hardening guidance warns that security through obscurity is not a primary security strategy and recommends updates, least privilege, strong account practices and firewall controls: https://developer.wordpress.org/advanced-administration/security/hardening/
- WordPress documents `admin_init` as running for admin screens as well as admin-ajax.php and admin-post.php, so those service paths are explicitly preserved: https://developer.wordpress.org/reference/hooks/admin_init/
- WordPress documents `login_url` as the filter for generated login URLs, which PublishRank uses for the private entry link: https://developer.wordpress.org/reference/hooks/login_url/
- WordPress documents `login_init` as the login-form initialization hook, which PublishRank uses for the native-login 404 gate and recovery exceptions: https://developer.wordpress.org/reference/hooks/login_init/
- WordPress requires safe redirects to be followed by `exit`, which the private-entry redirect follows: https://developer.wordpress.org/reference/functions/wp_safe_redirect/
