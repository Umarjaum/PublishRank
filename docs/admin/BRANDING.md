# Branding and the PublishRank mark

PublishRank ships exactly one logo file:

```
assets/images/publishrank-brand-mark.png
```

It is a white mark on a transparent background. No SVG or alternative icon sizes are packaged. The constant `PublishRankSEO\Core\Branding::LOGO_FILE` names the file and `Branding::logo_url()` returns its versioned URL, so every screen uses the same source.

## Where it appears

| Place | How it is shown |
| --- | --- |
| Admin sidebar top-level icon | CSS background image (white; darkened automatically in the Light admin scheme) |
| Sidebar dropdown, Dashboard entry | CSS background image, same treatment |
| WordPress toolbar item | White mark on the dark toolbar |
| Toolbar WordPress-logo dropdown entry | Small white mark |
| PublishRank screen headers | White mark on a dark tile |
| Setup wizard and reminder notice | White mark on a deep bordeaux tile (`#590d22`) |
| Sitemap view header and hero | White mark on the bordeaux gradient |

Because the mark is white, it is always placed on a dark or coloured surface.

## Replacing the logo

Overwrite `assets/images/publishrank-brand-mark.png` with a white-on-transparent PNG of the same name. Keep the file readable (permissions 644) and purge caches afterwards.

## Animated loader

`assets/images/publishrank-logo-animated.gif` is the animated PublishRank logo. It is shown as a loader on every PublishRank screen while the page loads and while a form or PublishRank link is opening (`assets/css/loader.css`, `assets/js/loader.js`, `Branding::loader_url()`). A CSS-only failsafe hides it after seven seconds even if scripts are blocked. Add `data-pr-no-loader` to a form to skip it.

## Official website

The official PublishRank website is <https://publishrankseo.vercel.app/> (`Branding::WEBSITE_URL`). It is linked from the plugin header, the Plugins screen (**Official Website**), the dashboard hero, Documentation & Help and the readme.
