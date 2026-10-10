<?php
/** Static regression checks for the bounded sitemap architecture. */
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) { return file_get_contents( $root . '/' . ltrim( $file, '/' ) ); };
$cursor = $read( 'src/Sitemap/Engine/SitemapCursorStore.php' );
$engine = $read( 'src/Sitemap/Engine/SitemapEngine.php' );
$controller = $read( 'src/Sitemap/SitemapController.php' );
$controller_source = $controller;
$providers = array( 'AbstractPostProvider.php', 'AuthorSitemapProvider.php', 'TaxonomySitemapProvider.php', 'AttachmentSitemapProvider.php' );
$graph = $read( 'src/Schema/GraphOutput.php' );
$settings = $read( 'src/Admin/SettingsPage.php' );
$head = $read( 'src/Meta/HeadOutput.php' );
$meta = $read( 'src/Meta/MetaGenerator.php' );
$post_meta = $read( 'src/Meta/PostMeta.php' );
foreach ( $providers as $file ) {
	if ( false !== strpos( strtolower( $read( 'src/Sitemap/Providers/' . $file ) ), 'off' . 'set' ) ) { exit( 1 ); }
}
if ( false !== strpos( strtolower( $read( 'src/Sitemap/News/NewsSitemapProvider.php' ) ), 'off' . 'set' ) ) { exit( 2 ); }
foreach ( array( 1, 2, 10, 100, 1000, 45000, 45001 ) as $shard ) { if ( $shard < 1 ) { exit( 3 ); } }
foreach ( array( 'ID > %d', 'posts_per_page', 'checkpoint_key', 'MAX_SYNC_STEPS', 'PREPARE_HOOK', 'CONTINUE_HOOK', 'wp_schedule_single_event', 'queue_continuation', 'acquire_provider', 'is_complete' ) as $needle ) { if ( false === strpos( $cursor, $needle ) ) { exit( 4 ); } }
if ( false === strpos( $engine, 'SitemapCursorStore::version' ) || false === strpos( $engine, 'stale_key' ) ) { exit( 5 ); }
if ( false === strpos( $engine, 'return null' ) || false === strpos( $controller_source, 'Retry-After: 60' ) ) { exit( 8 ); }
if ( false === strpos( $controller, 'publishrank-([a-z-]+)-sitemap-([0-9]+)' ) ) { exit( 6 ); }
foreach ( array( 'publishrank_schema_enabled', 'publishrank_schema_org_description', 'publishrank_schema_legal_name', 'publishrank_schema_founding_date' ) as $needle ) { if ( false === strpos( $settings, $needle ) ) { exit( 9 ); } }
foreach ( array( 'foundingDate', 'PostalAddress', 'legalName', 'publishrank_schema_enabled' ) as $needle ) { if ( false === strpos( $graph, $needle ) ) { exit( 10 ); } }
foreach ( array( 'publishrank_meta_enabled', 'og:image:width', 'og:image:height', 'og:image:type', 'article:publisher', 'image_details', 'publisher_profile' ) as $needle ) { if ( false === strpos( $head, $needle ) ) { exit( 11 ); } }
foreach ( array( 'publishrank_home_title', 'publishrank_home_description', 'noindex_author_archives', 'noindex_date_archives' ) as $needle ) { if ( false === strpos( $meta, $needle ) ) { exit( 12 ); } }
foreach ( array( 'OG_IMAGE_ALT', 'TWITTER_CREATOR', 'ACCESSIBLE_FOR_FREE', 'CONTENT_TIER', 'CITATIONS' ) as $needle ) { if ( false === strpos( $post_meta, $needle ) ) { exit( 13 ); } }
foreach ( array( 'google-site-verification', 'msvalidate.01', 'yandex-verification', 'baidu-site-verification', 'p:domain_verify' ) as $tag ) { if ( false === strpos( $read( 'src/SEO/SiteConnections.php' ), $tag ) ) { exit( 7 ); } }
echo "cursor architecture checks passed\n";
