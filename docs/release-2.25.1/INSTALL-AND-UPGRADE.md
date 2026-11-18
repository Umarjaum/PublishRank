# PublishRank 2.25.1 — Installation and upgrade

## Requirements
WordPress 6.3 or later, PHP 7.4 or later (tested on WordPress 7.1.4 with PHP 8.3).

## Fresh installation
1. WordPress admin → Plugins → Add New → Upload Plugin → choose `PublishRank-2.25.1.zip` → Install Now → Activate.
2. Follow the setup wizard (PublishRank → Complete Setup), or skip it and use PublishRank → Features.
3. Traffic Guard starts in **Monitor** mode with protection level **Off** — nothing is blocked. Review the Overview and Events tabs for a few days before switching to Enforce under Protection.
4. If your site is behind Cloudflare or another proxy, add its addresses under Traffic Guard → Protection → Client IP so visitor IPs and countries are read correctly.

## Upgrading from 2.25.0 or earlier
* Upload the ZIP over the existing plugin (WordPress offers "Replace current with uploaded"), or update from Plugins when 2.25.1 is published on WordPress.org.
* Database tables upgrade automatically (version 5 → 6, adds the Traffic Guard event and block tables).
* All settings are kept. If Traffic Guard was switched on, it keeps enforcing your existing IP and country lists; no new automatic blocking is added (protection level stays Off until you choose one).
* Access Shield's private sign-in address, lockouts, two-factor and country rules are kept. Lists that contained the old `XX` "unknown" code keep their real countries; unknown locations are handled by their own setting.
* FAQ blocks created in earlier versions keep working and gain the new style options.
* After upgrading, clear any page cache so the updated block styles and scripts load.

## Locked out?
* Add `define( 'PUBLISHRANK_DISABLE_ACCESS_SHIELD', true );` to `wp-config.php`, or run `wp publishrank shield disable`.
* Unlock an IP: `wp publishrank shield unlock 203.0.113.7`. Reset two-factor: `wp publishrank shield 2fa-reset username`.
* Traffic Guard blocking you: sign in as an administrator (administrators are never blocked) and switch to Monitor, or delete the `publishrank_traffic_guard` option.

## Uninstalling
Deactivate and delete from Plugins. Data is kept unless "Delete data on uninstall" is switched on (PublishRank → Roles & permissions).
