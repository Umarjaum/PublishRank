# Admin navigation

## Sidebar

The **PublishRank** menu opens to the Dashboard. The **Setup Wizard** entry sits directly under Dashboard so it is always one click away (administrators and anyone granted the setup capability see it). Its dropdown lists the tools in task groups. Select a group heading to collapse or expand it; headings are real buttons, so Tab and Enter or Space work.

The dropdown deliberately has **no custom background colour**. It uses WordPress's own submenu background and text colours, so it matches every admin colour scheme. Tool icons simply follow the text colour; the Dashboard entry shows the PublishRank mark (a plain background image, so it still shows when assets are served from a CDN).

## Screen layout and side menu

Every PublishRank screen except the Dashboard uses the same frame: the branded header on top, a side menu on the left and the tool in the main column. Features, General Settings, Sitemap Hub, Help & documentation and the Setup Wizard print the frame themselves; every other tool screen is wrapped in it automatically (`AdminShell`), so its own settings and forms are unchanged.

Inside the frame every tool screen also uses the same look as Features: white cards with the same border, radius and shadow, light table headers, fields of one height with a brand-coloured focus ring, brand-coloured tick boxes and switches, and segmented tabs. These styles live in `assets/css/shell.css` and only apply inside the frame, so the Dashboard and the WordPress screens are not affected.

The side menu lists **Dashboard**, **Features** (formerly SEO Modules), **General Settings** and **Sitemap Hub**, followed by collapsible groups: Search & discovery, Content & publishing, Newsroom, Measure & maintain and Help. The group of the current screen opens automatically, and only screens that exist and that the current user may open are listed. Developers can change the groups with the `publishrank_seo_shell_groups` filter.

## Toolbar

The **PublishRank** toolbar item links to the Dashboard and offers shortcuts to Newsroom, Titles & Meta, Settings, the Setup Wizard and Documentation & Help. A PublishRank entry also appears inside the WordPress-logo dropdown. Developers can adjust the shortcuts with the `publishrank_seo_admin_toolbar_items` filter.

## Plugins screen

The Installed Plugins row adds Settings, Setup Wizard and Sitemap Hub links.
