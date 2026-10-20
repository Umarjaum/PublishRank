<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) { return file_get_contents( $root . '/' . ltrim( $file, '/' ) ); };
$head = $read( 'src/Meta/HeadOutput.php' );
$meta = $read( 'src/Meta/MetaGenerator.php' );
$graph = $read( 'src/Schema/GraphOutput.php' );
$urls = $read( 'src/Sitemap/SitemapUrls.php' );
$index = $read( 'src/Sitemap/Engine/SitemapIndex.php' );
$controller = $read( 'src/Sitemap/SitemapController.php' );
foreach ( array( $head, $meta, $graph ) as $source ) {
	if ( false === strpos( $source, 'is_editorial_article' ) ) { fwrite( STDERR, "editorial custom post type support missing\n" ); exit( 1 ); }
}
if ( false === strpos( $urls, 'shard_prefix' ) || false === strpos( $urls, 'shard_url' ) ) { exit( 2 ); }
if ( false === strpos( $index, 'SitemapUrls::shard_url' ) || false === strpos( $controller, 'SitemapUrls::shard_prefix' ) ) { exit( 3 ); }
echo "compatibility checks passed\n";
