# WordPress 7.1.3 compatibility checklist

This is a staging checklist, not a statement of test results. Complete it on a copy of your site before production use.

## Environment

- [ ] WordPress 7.1.x, PHP 7.4+ (PHP 8.3 recommended; PHP 8.5 is the forward target)
- [ ] Theme under test and a default block theme
- [ ] Caching or CDN enabled as on production

## Activation and admin

- [ ] Activate without notices; setup wizard opens
- [ ] PublishRank sidebar menu, groups and Dashboard icon render in at least two admin colour schemes
- [ ] Toolbar item and WordPress-logo dropdown entry show the PublishRank mark
- [ ] Screen headers show the version `2.20.3`

## Output

- [ ] Titles, descriptions, canonicals and robots tags on posts, pages and archives
- [ ] JSON-LD graph validates; Live Blog posts output LiveBlogPosting only when chosen
- [ ] Breadcrumbs appear once (no theme duplicate)

## Sitemaps

- [ ] Index and every child sitemap return valid XML
- [ ] Browser view loads styled in light and dark modes
- [ ] News sitemap respects the freshness window
- [ ] Sitemap Hub reports no errors

## Redirects and robots

- [ ] Redirect created, tested and removed
- [ ] robots.txt lists the sitemap index

## Uninstall

- [ ] Deactivate and reactivate cleanly; uninstall removes plugin data as documented
