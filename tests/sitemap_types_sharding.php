<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/** Regression checks for sitemap provider types and shard limits. */
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) { return file_get_contents( $root . '/' . ltrim( $file, '/' ) ); };
$abstract = $read( 'src/Sitemap/Providers/AbstractPostProvider.php' );
$page = $read( 'src/Sitemap/Providers/PageSitemapProvider.php' );
$image = $read( 'src/Sitemap/Images/ImageSitemapProvider.php' );
$video = $read( 'src/Sitemap/Video/VideoSitemapProvider.php' );
$author = $read( 'src/Sitemap/Providers/AuthorSitemapProvider.php' );
$attachment = $read( 'src/Sitemap/Providers/AttachmentSitemapProvider.php' );
$taxonomy = $read( 'src/Sitemap/Providers/TaxonomySitemapProvider.php' );
$news = $read( 'src/Sitemap/News/NewsSitemapProvider.php' );
$cpt = $read( 'src/Sitemap/Providers/CPTSitemapProvider.php' );
$robots = $read( 'src/Robots/RobotsTxt.php' );
$settings = $read( 'src/Admin/SettingsPage.php' );
$installer = $read( 'src/Core/Installer.php' );
foreach ( array( $abstract, $author, $attachment, $taxonomy ) as $source ) {
    if ( false === strpos( $source, 'const SHARD_SIZE = 50000;' ) ) { fwrite( STDERR, "standard sitemap shard limit missing\n" ); exit( 1 ); }
}
if ( false === strpos( $page, "protected \$post_types = array( 'page' );" ) ) { exit( 2 ); }
foreach ( array( $image, $video ) as $source ) {
    if ( false === strpos( $source, 'media_post_types()' ) || false === strpos( $source, 'requires_extra()' ) ) { exit( 3 ); }
}
if ( false === strpos( $abstract, "'publishrank_seo_media_post_types', 'all'" ) || false === strpos( $settings, 'sanitize_media_post_types' ) ) { exit( 4 ); }
if ( false === strpos( $news, 'const SHARD_SIZE = 1000;' ) || false === strpos( $news, 'min( self::SHARD_SIZE' ) ) { exit( 5 ); }
if ( false === strpos( $installer, 'SitemapCursorStore::bump_many' ) ) { exit( 6 ); }
if ( false === strpos( $cpt, "add_action( 'init'" ) || false !== strpos( $news, "get_post_types( array( 'public' => true" ) ) { exit( 7 ); }
if ( false === strpos( $robots, 'SitemapUrls::index_url()' ) ) { exit( 8 ); }
echo "sitemap type and sharding tests passed\n";
