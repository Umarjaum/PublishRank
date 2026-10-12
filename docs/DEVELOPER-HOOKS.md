# PublishRank developer integration hooks

PublishRank uses native WordPress actions and filters so themes and other plugins can extend it without editing plugin files. Hooks follow the WordPress Plugin Handbook conventions: actions perform work; filters receive and return the value being modified.

## Add a toolbar shortcut

Use `publishrank_seo_admin_toolbar_items` to add items to the PublishRank toolbar menu. Each item is an array containing a visible label and a URL. Use a trusted URL and escape output in your own callbacks.

```php
add_filter( 'publishrank_seo_admin_toolbar_items', function ( $items ) {
    $items['my-tool'] = array( __( 'My editorial tool', 'my-text-domain' ), admin_url( 'tools.php' ) );
    return $items;
} );
```

## Add dashboard quick actions

Use `publishrank_seo_dashboard_actions` to render additional WordPress admin buttons inside the PublishRank dashboard Quick actions card. Restrict sensitive actions with capability checks and use nonces for state-changing requests.

```php
add_action( 'publishrank_seo_dashboard_actions', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    echo '<a class="button" href="' . esc_url( admin_url( 'tools.php' ) ) . '">' . esc_html__( 'Open WordPress tools', 'my-text-domain' ) . '</a>';
} );
```

## Add content after PublishRank admin headers

`publishrank_seo_after_admin_header` fires after a PublishRank admin page header. It passes the current page slug and title.

```php
add_action( 'publishrank_seo_after_admin_header', function ( $page_slug, $title ) {
    // Add a contextual integration panel when $page_slug matches your target page.
}, 10, 2 );
```

## Existing extension points

Other existing filters include `publishrank_sitemap_providers`, `publishrank_schema_graph`, `publishrank_seo_robots`, `publishrank_article_intelligence_enabled`, and `publishrank_rest_post_types`. Search the source for `apply_filters( 'publishrank_` to discover additional hooks.

For WordPress core APIs, see the official [Plugin Handbook](https://developer.wordpress.org/plugins/) and [Hooks guide](https://developer.wordpress.org/plugins/hooks/).
