# Archived WordPress.org readme changelog entries

Older release entries were moved out of `readme.txt` to keep the directory changelog within WordPress.org’s section limit. The official listing retains 2.25.1, 2.25.0 and 2.24.0. This archive preserves all earlier entries as supplied.

= 2.23.0 =
* Access Shield, AI search visibility (crawler control, verified bot log, Markdown delivery, citation tracker), Schema Library (18 extra types), 404 Monitor v2, Redirect Engine v2, Newsroom v2, verified large-site sitemaps, advanced RSS and JSON Feed, Notification Center, 12-page Site Navigation, granular roles and permissions, multisite-safe install and opt-in data removal. See changelog.txt.


= 2.21.0 =
* **404 Monitor screen.** Turn recording on or off right on the screen (same switch as Features), clear messages when the list is empty or a filter matches nothing, readable visitor badges (Browser, Mobile, Crawler), last and first seen in one column, the referring site's name, a one-line redirect form that needs a target, a confirmation before clearing the whole log, and creating a redirect now clears that address for every visitor type.
* **Movable SEO editor window.** Open the SEO fields, live previews and analysis in one floating window in the block and classic editors. Drag it by its title bar (mouse, touch or arrow keys), resize it, snap it to the left or right, minimize it, and it reopens where you left it. On phones it becomes a bottom sheet. It uses the same fields as the editor, so nothing is saved twice.
* **One SEO score everywhere.** The editor panel, toolbar badge, post list column, SEO Score page, Insights and scheduled audits now read the same score, so a post never shows two different numbers.
* **RSS Feeds screen.** Add author, publisher, category, copyright and custom attribution lines to feed items (with a live preview), publish a recent-news feed at /feed/news/, set the item limit, print feed discovery links only when the theme does not, and send an optional cache header. Every feed can be validated and previewed the way a feed reader shows it. Feed titles no longer repeat the site name ("Daily Beacon | Daily Beacon").
* **Site Navigation SEO.** Mark your important pages (About, Contact, Services, editorial policy, legal pages and more), see for each one whether it is published, indexable, in the sitemap, linked from a menu and from other pages, and has a self canonical and description. The homepage describes them as a SiteNavigationElement list. Shows breadcrumb trails and orphaned content. It cannot force sitelinks; the screen says so.
* **WooCommerce SEO** (only when WooCommerce is active). Product pages are no longer marked up as news articles; WooCommerce's Product schema is linked to the PublishRank graph (one breadcrumb, seller is your organization, brand, condition, gallery images); og:type product with price, currency, availability, SKU and brand; cart, checkout, account pages, sorted or filtered shop listings and hidden products are noindex; products get their own sitemap without hidden products. A product audit and search-result preview show what is missing. While WooCommerce shows its "coming soon" screen to visitors, those pages are noindex and carry no product schema or product Open Graph tags.
* **Redirects.** Import redirects from CSV (PublishRank, Yoast SEO Premium, Rank Math and Redirection exports are recognised), export all rules as CSV, delete several at once, and trace an address to see every hop, chains and loops. Regex redirects now pass captured parts ($1, $2 ...) to the new address.
* **Arabic and Urdu analysis.** The SEO analysis detects English, Arabic and Urdu. Arabic-script text is normalised (diacritics, tatweel, letter variants) and lightly stemmed, so a focus keyword matches with or without "ال" or plural endings. Arabic and Urdu use their own sentence marks, connective words and sentence-length limits; English formulas (Flesch, passive voice) are no longer applied to other languages.
* **Inclusive language (optional).** English suggestions with an explanation and alternatives, configurable rules, and a Dismiss button for each post. It never changes your text and does not affect the score.
* **Classic Editor tip.** An optional, dismissible notice explains that the movable SEO window also works in the Classic Editor. PublishRank never installs or activates other plugins.
* **Setup Wizard logo preview.** Choosing a logo or image from the Media Library shows the preview immediately, saves the image ID and keeps the preview after reload.
* **Private Login.** Settings carried over from older sign-in address plugins are read through a separate migration step and are no longer deleted when another plugin still uses them.
* **Fixes.** Sitemaps added by integrations (bbPress forums, podcasts, Web Stories, products) are now listed and served instead of returning 404. Automatic descriptions keep a space between a heading and the following text. Table headers on newer PublishRank screens are readable, and date fields no longer overflow narrow cards.


= 2.20.20 =
* **Local SEO for every kind of business.** 134 business types across 14 niches, including new Education & child care and Public services & media groups, hospitals, doctors, notaries, ATMs, vacation rentals, ice cream shops, shopping centres, libraries and more.
* **Fields for each business type.** Restaurants get cuisine, menu and reservations; hotels get check-in and check-out, rooms, official star rating and pets; clinics, dentists, pharmacies and vets get medical specialties and new-patient status; banks get a fees page; shops and car dealers get brands; venues get capacity and free entry. They appear only for the types schema.org defines them on.
* **Richer, valid LocalBusiness schema.** Opening hours are published as OpeningHoursSpecification, with holiday and special hours; services become an OfferCatalog; amenities, languages, areas served, payment methods, currencies, booking link (ReserveAction), legal name, founding date, tax ID, logo and photo are supported. Service-area businesses can hide their street address, map and coordinates.
* **Schema fixes.** Veterinary care and schools are now typed with LocalBusiness so hours and price range are valid; Funeral home (not a schema.org type) is published as LocalBusiness with a matching additionalType; the deprecated branchOf link is replaced by parentOrganization. Every type is checked against the schema.org vocabulary.
* **Redesigned Local SEO screen.** Card sections that match the Setup Wizard, a Schema health checklist based on Google's guidelines, a live JSON-LD preview with copy, one-click hour presets and links to Google's Rich Results Test and the Schema.org validator. Works on phones.
* The Setup Wizard no longer erases the extra Local SEO details when it saves.


= 2.20.19 =
* **Mastodon works end to end.** Posts you opt in to sharing from the editor are now shared reliably (the choice was read before it was saved), "Share again now" really posts again, the server field accepts a plain address like mastodon.social, and the access token is never printed back into the page. The status only says Connected once Mastodon accepts the token, and failed checks explain what to fix.
* **New Mastodon screen.** A clear status bar and five numbered steps: connect your account, verify your profile, choose what to share, write the post, and review authors and recent shares. Works on phones.
* **Editor in PublishRank colors.** The SEO fields, tabs, buttons, checkboxes, help icons and analysis in the block and classic editors now use the PublishRank crimson palette instead of WordPress blue.
* **Clearer Sitemap Hub.** The full sitemap address is shown and can be copied or opened in one click, and a new Sitemap files list shows every file search engines will read. Navigation scrolls to the current screen on phones.
* **Private Login.** The custom sign-in address module has been rebuilt under its own PublishRank name, screen and settings. Existing sign-in addresses and redirect settings carry over automatically, and the old settings link forwards to the new screen. Emergency switch: `define( 'PUBLISHRANK_DISABLE_PRIVATE_LOGIN', true );`.


= 2.20.18 =
* **Editor layout fixed.** Block editor: the SEO fields no longer float over the article. They now sit inside the PublishRank sidebar under the SEO analysis (tabbed: Published values, Search appearance, Article context, Social sharing, ...) and go back to the Meta Boxes area at the bottom when the sidebar is closed. Classic editor: the section tabs have their own row under the "PublishRank SEO fields" header, so the title, move and collapse buttons no longer get squeezed; on phones the tabs scroll in one line and the "What will be published" table stacks.
* **SEO panel shows the real description.** When no meta description is written, the score panel and the analysis now count the automatic description PublishRank publishes (marked "auto") instead of reporting 0/160 and a missing description.
* **No duplicate site description.** The site description is now used only on the home page. It is no longer appended to the fallback description of posts, pages and archives, and the "use the publication-wide description everywhere" option was replaced by "leave the description out". Duplicate meta / og / twitter description tags printed by a theme next to PublishRank's own are removed.
* **Preload and prefetch work on the live site.** The featured image of articles and pages is preloaded at high priority (with srcset) and never lazy-loaded; same-site article links are prefetched on hover or tap with Speculation Rules (configures core speculative loading on WordPress 6.8+); CDN and media hosts get preconnect, video and API hosts dns-prefetch. A filter no longer strips the configured hosts. Each part can be switched off under Settings > Infrastructure, speed and syndication.
* **Syndication works.** The Canonical URL field now accepts the original article on another site (it was silently dropped before), and the canonical, og:url and feed link follow it. RSS items carry the featured image (media:content and in the item) and a "The post ... appeared first on ..." source line; the source line was never added before because of a wrong check.
* **Canonical on local sites.** Sites running on localhost, 127.0.0.1 or *.local no longer show "/" as the canonical URL.


= 2.20.17 =
* **Movable SEO fields panel.** All SEO fields now open in a panel you can drag anywhere on the screen, with a button for each section (Search appearance, Social sharing, Article details, Indexing, Schema). Block editor: it appears on its own and minimizes to a small bar. Classic editor: use the move button in the SEO fields bar to float it. Position and open/closed state are remembered.


= 2.20.16 =
* **SEO fields are now at your fingertips.** Classic editor: the SEO Overrides, Schema Selection and Multilingual boxes are no longer buried in the footer; they sit in a tabbed "PublishRank SEO fields" bar directly under the title (Search appearance, Social sharing, Article details, Indexing, Schema). Block editor: the same fields now live inside the PublishRank sidebar below the SEO analysis. Click any "Fix" item in the analysis to jump straight to its field.
* **Values no longer get lost.** Edits made in the SEO fields now mark the block editor post as changed, so Update always saves them. The old duplicate title/description fields in the sidebar (which could overwrite what you typed in the footer box) are gone. Unsaved SEO field values are backed up in the browser and restored after a reload. SEO meta is now available to custom post types that did not support custom fields, and the cornerstone flag can no longer be reset by another box on the page.
* **Fixes.** Removed a stray closing tag in the SEO Overrides box, schema detail panels (Video, FAQ, Event...) work wherever the box is placed, the sidebar is available even when the SEO score analysis is switched off, and SEO title and meta description show live length counters in both editors.


= 2.20.15 =
* **SEO Analysis moved to the top of the editor.** The panel no longer floats over the bottom corner of your writing. In the block editor a live score badge now sits in the top toolbar next to Publish; it changes color and number as you write. Click it to open the PublishRank SEO sidebar, where the full analysis (score, metrics, focus keyword, items to fix with one-click Fix buttons) is docked at the top, followed by the SEO title and meta description.
* **Dock or pop out — your choice.** A new button in the panel header pops the analysis out into a draggable floating panel and docks it back. Your choice is remembered.
* **Classic editor.** The analysis is docked in the right column directly under the Publish box, so Publish is never pushed out of view.
* **Faster access.** Open the analysis from the score badge, the editor Options menu, or the command palette (Ctrl/Cmd + K → "Open PublishRank SEO analysis"). The sidebar opens by itself the first time so writers find it.


= 2.20.14 =
* **Graph scan no longer reloads in a loop.** The Content Graph scan now runs inside the page with a live progress bar and Pause, Resume and Cancel buttons instead of reloading the screen after every batch. It starts only when you press Run graph scan, never just because a paused scan exists, and it stops on errors, expired sessions, or when a batch makes no progress. Without JavaScript, Resume graph scan processes one batch per click.
* **Scan progress survives cache evictions.** Scan progress is kept in a transient plus a backup option, so an evicted transient can no longer silently lose or restart a scan.
* **Help icons rebuilt.** Each setting gets one icon with its own explanation (the most specific match wins), icons no longer double up beside the same setting, and the popover is no longer clipped by cards or tables. It supports keyboard, screen readers, touch, Escape and click-outside to close.
* **Features grouped by purpose.** The Features screen groups cards by purpose; bbPress and BuddyPress stay at the very bottom, and the settings link is now labelled Configure. Failed or mismatched toggles are reported as errors instead of success.
* **Sitemap Hub.** Off-by-default content types (attachment pages) now explain why, and the instant-save switches report server-side failures or unchanged values instead of a false success.
* **General Settings.** The unsaved-changes warning now ignores values adjusted by other scripts at load, notices list changes made with add/remove buttons, never stays disabled after a cancelled save, and blocks duplicate submissions.
* **Navigation.** The Internal Links submenu is now labelled Content Graph to match its page title, sidebar and dashboard card.

= 2.20.13 =
* **Breadcrumbs block with live preview and color pickers.** The PublishRank Breadcrumbs block now has its own settings in the editor sidebar: a visual picker for the Arrows, Minimal, Steps and Pills looks (plus Plain, Chips, Bar and Underline), a built-in color picker for text, links, background and accent, size, position and separator. The preview in the editor updates as you change anything and uses the post's real title and first category. Anything left on "Site default" follows PublishRank → Breadcrumbs.
* **Horizontal position in pixels.** A slider (-400 to 400 px) moves the trail from left to right; the shortcode uses `offset="40"`. It is ignored on small phones so the page never scrolls sideways.
* **Live designer on PublishRank → Breadcrumbs.** The settings screen now shows a gallery of every look (Arrows, Minimal, Steps and Pills as in the Daily UI #056 design, plus Plain, Chips, Bar and Underline) and a live preview that uses the same markup and stylesheet as the site. It changes instantly with the look, size, position, horizontal position in pixels, separator, icons and colors.
* **Color pickers.** Text, link, background and a new accent color use the built-in WordPress color picker on the settings screen and in the block.
* **Optional crumb icons.** Home, folder and page icons for the Arrows, Minimal, Pills, Chips, Bar and Plain looks (a site setting, a block toggle and `icons="1"` in the shortcode). Choosing Minimal or Pills brings the separator used in the reference design.
* **Site-wide defaults for the new options.** Accent color, horizontal position and icons are included in Import / Export and in change snapshots; the block and shortcode can override them per page.
* **Same options in the shortcode.** `[publishrank_breadcrumbs look="arrows" accent="#c9184a" link="#590d22"]` accepts look, size, align, separator, text, link, bg and accent (colors as #hex).
* The structured data (BreadcrumbList) is unchanged: it is still built from the same trail as the visible breadcrumbs.


= 2.20.10 =
* **Private Login emergency switch.** Add `define( 'PUBLISHRANK_DISABLE_PRIVATE_LOGIN', true );` to wp-config.php to restore the standard WordPress login at once if you are ever locked out. The Private Login screen explains when this is active.
* **Safer Private Login.** The notice-dismiss request now requires the "manage options" capability and accepts only the notices Private Login actually shows. The multisite network settings read their fields safely and use the same address check as the rest of the plugin.
* **New installs keep the standard login until you confirm.** Private Login now waits until you save the Security step (or the Private Login screen) so the login never moves before you have seen the new address. Sites that already used Private Login are not changed.
* **See and copy the new login address.** The Security step shows the exact address, updates it as you type, and has a Copy address button.
* **Login address in Import / Export.** The private login address and blocked-visitor redirect are now exported and imported, with the same validation as the settings screens. The on/off switch is deliberately not imported, so a settings file can never hide the login on a site that has not set it up.
* **Translations.** The translation template now lists every translatable string, and the Urdu translation covers the Private Login and Security step text.
* **Direct-access guards** added to two files that lacked them.
* **Faster setup wizard.** The wizard checks required fields once per request instead of repeatedly.
* **One version source.** The version number is read from the plugin header. The reviewable `src/assets` copies are synchronised with `assets`.
* **Note on step numbers.** The Setup Wizard now has nine steps, with Security at step 8 and the final Ready step at step 9. A bookmarked `&step=8` link opens Security.


= 2.20.9 =
* **Private Login in the Setup Wizard.** New "Security" step with a required private login address and an optional redirect for blocked visitors. The address is checked against reserved WordPress names before it is saved.
* **Private Login in General Settings.** A new Private Login card edits the same two options with the same validation.
* **Save & exit.** Once every required field (*) is filled in, the wizard unlocks and "Save & exit" saves the current step and leaves. Setup is marked complete, so PublishRank screens stay unlocked and the reminder disappears. Later steps can still be edited from the Setup Wizard.
* **Readable Private Login banner.** Text and links on the dark red banner are now always white, whatever other admin styles apply.


= 2.20.8 =
* **Separate social titles and descriptions.** The editor's Social sharing section now has Facebook, X and Mastodon fields. X cards use the X fields, then the Facebook fields, then the automatic text. The Mastodon fields replace {title} and {excerpt} in the post PublishRank shares to Mastodon. Blank fields stay automatic: the post title with the site name, and the post description. All fields support %%variables%% and are available in the REST API; SEO-plugin imports now carry X titles and descriptions across.


= 2.20.7 =
* **Mastodon author verification.** Every author can now verify their own Mastodon profile. Author pages publish a rel="me" link to the author's Mastodon profile; the WordPress profile screen shows a copy-ready author page address, and a "Check verification" button asks the author's Mastodon server whether the green check is showing. PublishRank → Mastodon lists all authors with their profile, author page and status. Switch it off under "Credit authors".
* **Rate PublishRank.** A "Rate on WordPress.org" link on the Plugins screen and a notice on PublishRank screens. A dismissed notice returns after five minutes (filter: publishrank_rate_notice_interval) until the administrator rates the plugin or chooses "I already rated it".


= 2.20.6 =
* **Google-style homepage preview in the Setup Wizard.** Step 5 now shows how the homepage will look in Google (site name, URL, title and description) with a Desktop and Mobile view. It updates as the publisher types, resolves the real %%variables%%, uses the title separator currently selected (even before saving) and applies the same fallbacks as the live site: homepage description, then the front page's own SEO description, then the publication description.
* **Plain-language hints under the preview.** Warns when the title is likely to be shortened, when the description is missing, too long or very short, and says which source the description is taken from when the homepage field is empty.
* The preview is read-only (administrators with setup access only), saves nothing and does not change any stored option or the HTML the site outputs.


= 2.20.5 =
* **Homepage title and description are now written by the publisher, not templated.** The Setup Wizard's Homepage SEO title field shows an example placeholder (site title, separator, short description) that is never saved, and its help text explains that the field is plain text with optional variables.
* **Dedicated homepage meta description.** WordPress has no excerpt for the homepage, so the Homepage meta description field is now explained as the dedicated description used for search results and social previews, with an example placeholder, a plain-language fallback note and a live 120 to 160 character guide.
* No option names, saved values or output logic changed; existing homepage settings keep working.


= 2.20.4 =
* **Mastodon connection UX and resilience.** Clarified server, token, profile, message and sharing settings. The encrypted saved token is hidden by default and can be revealed by an administrator with an accessible eye control. Failed verification no longer deletes a saved token; status sharing now requires a Mastodon status ID and records successful private posts even when the server omits a public URL.
* **Mastodon media readiness.** Allow additional time for asynchronous featured-image processing before reporting that a share should be retried.


= 2.20.3 =
* **Mastodon verification helper.** Added a generated, copyable `<link rel="me">` head snippet, with live updates from the profile URL field, plus clearer official verification steps.
* **Private Login visual refresh.** Improved branded login and redirect URL controls, contrast, keyboard focus and small-screen layout.

= 2.20.2 =
* **Mastodon site verification made easier.** Added a copy-ready Website URL field and clearer steps for the reciprocal rel="me" verification flow. Mastodon does not issue a separate one-time site-verification code.


= 2.20.1 =
* **New: Private Login module spotlight.** The module now carries a first-visit New badge in the PublishRank menu and dashboard; its dedicated settings screen remains separate from WordPress Settings.
* **Migration & Portability contrast and mobile layout.** Improved heading contrast and touch-friendly responsive controls.

= 2.20.0 =
* Added PublishRank Private Login: custom login URL, handling for wp-login.php, wp-register.php and wp-admin requests, custom redirect URL, and multisite network defaults. Settings live on the dedicated PublishRank > Private Login page.


= 2.19.2 =
* **Fix: keyword fields stay in the PublishRank SEO box.** Focus keyword, secondary keyphrases, synonyms and related keyphrases no longer redirect editors to Gutenberg’s sidebar or appear in duplicate locations.
* **Fix: background field saving and live updates.** Keyword fields autosave through a scoped AJAX request, refresh the resolved automatic SEO summary in place, and update live SEO analysis without a full editor reload.


= 2.19.1 =
* Mastodon publishing upgrades: optionally attach featured images with their Media Library alt text, add per-post content warnings, and adapt text length to each server's configured limit.
* Dashboard styling consistency: the served admin theme now styles the site overview, sitemap URLs and configuration panels in the same card language as the workflow section.


= 2.19.0 =
* **New: Mastodon.** Verify your Mastodon profile with the green check (rel="me" on every page), credit authors with the fediverse:creator tag, and share posts to Mastodon automatically on publish or with a "Share now" button. Custom message format, hashtags from tags, visibility, per-post opt-out and a share history. Free.


= 2.18.4 =
* **Maintenance release.** Version markers and package metadata updated to 2.18.4; no functional changes.

= 2.18.3 =
* **News sitemap completeness for busy publications.** News candidates are scanned in bounded pages up to a larger safety ceiling, so noindex, password-protected, excluded, or malformed stories cannot crowd valid recent articles out of the sitemap.
* **News-site setup clarity.** The onboarding summary now reports the actual News sitemap post types.
* **Styled Migration & Portability workspace.** The import page now has detected-plugin cards, grouped controls, dry-run emphasis, upload guidance, rollback presentation, responsive layouts, and clearer export/preflight tools.

= 2.18.2 =
* **Smarter migration from Yoast, Rank Math, AIOSEO, SEOPress and The SEO Framework.** Title and description variables (e.g. Rank Math %title%, AIOSEO #site_title, SEOPress %%post_title%%) are converted to PublishRank variables; noindex and nofollow flags are combined correctly (Yoast "2 = index" no longer imported as a robots value); primary category and cornerstone/pillar flags are migrated; AIOSEO 4 data is read from its own table; The SEO Framework social fields are included.
* **Translation-aware JSON import/export.** Exports now include each post's language and translation URLs; imports match by full URL first and use the language to tell apart translations that share a slug.
* **Multilingual fixes.** Language tags follow BCP 47 (zh-Hans-CN, es-419, sr-Latn); sitemaps add an x-default link to the default-language translation; TranslatePress and Weglot current language detected; invalid alternate URLs dropped; new publishrank_hreflang_alternates filter.
* **Fix:** the Migration & Portability screen caused a fatal error (missing Branding class import).


= 2.18.1 =
* **Broad multilingual SEO catalog.** Locale-specific SEO title, description, focus keyword, Open Graph and robots overrides now cover major LTR and RTL languages, including Urdu, Arabic, Persian, Hebrew, Bengali, Hindi, Chinese, Japanese, Korean, European languages and Southeast Asian languages. WPML, Polylang and custom hreflang locales are merged automatically.

= 2.17.0 =
* **SEO Analysis 3.0: focus-keyword scoring restored.** The live analysis now scores the focus keyword in the title, meta description, URL slug, opening paragraph, subheadings, image alt text, body density (0.5–3%) and distribution across the article. Plural/singular variants are matched.


= 2.16.0 =
* **Job Posting pages.** New "Job Posting" schema type with fields for hiring organization (name, website, logo), closing date, employment type, address or fully remote (with applicant country), salary range, currency and period, job reference and direct apply. The title is the job title and the post content the description. Markup is only output when the required details are complete, and stops automatically after the closing date.
* **Automatic sitewide broken-link checker.** Link Intelligence can now check every published article daily or weekly in the background (optionally including external links), and re-checks an article's internal links as soon as it is published or updated. Shows when the last full check finished.
* **Better multi-language support (Polylang and WPML).** Sitemaps list each article's translations (hreflang links); article schema uses the article's own language and links its translations (workTranslation); publication name, descriptions, title templates and separators can be translated in Polylang Strings / WPML String Translation.
* **Localized SEO handling.** Each post can store language-specific SEO title, meta description, focus keyword, Open Graph title/description/image and robots directives. PublishRank selects the current language first, then its base language, then the normal post SEO value. The built-in catalog covers major LTR and RTL languages including English, Urdu, Arabic, Persian, Hebrew, Bengali, Hindi, Chinese, Japanese, Korean, German, Spanish, French, Portuguese, Italian, Dutch, Russian, Turkish, Ukrainian, Vietnamese, Indonesian and more. Any valid locale supplied by WPML, Polylang or the hreflang map is also accepted.
* An Urdu translation of the PublishRank interface is included. Set the WordPress site language to Urdu (`ur`) or Urdu (Pakistan) (`ur_PK`) to use it; Urdu and all other RTL fields use the correct text direction.
