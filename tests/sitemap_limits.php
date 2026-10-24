<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/** Static checks for per-type sitemap limits. */
$root = dirname( __DIR__ ); $r = static function ( $f ) use ( $root ) { return file_get_contents( $root . '/src/' . $f ); };
$checks = array(
	'standard providers use the 50,000 URL limit' => 1 === preg_match( '/const\s+SHARD_SIZE\s*=\s*50000\s*;/', $r( 'Sitemap/Providers/AbstractPostProvider.php' ) ),
	'news is capped at 1,000 entries per file and is not tied to 50,000' => false !== strpos( $r( 'Sitemap/News/NewsSitemapProvider.php' ), 'const SHARD_SIZE = 1000;' ),
	'media providers use smaller files' => false !== strpos( $r( 'Sitemap/Images/ImageSitemapProvider.php' ), 'return 5000;' ) && false !== strpos( $r( 'Sitemap/Video/VideoSitemapProvider.php' ), 'return 5000;' ),
	'engine enforces the 50 MB uncompressed limit' => 1 === preg_match( '/const\s+MAX_BYTES\s*=\s*52428800\s*;/', $r( 'Sitemap/Engine/SitemapEngine.php' ) ),
);
$failed = 0; foreach ( $checks as $label => $ok ) { echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; $failed += $ok ? 0 : 1; }
exit( $failed ? 1 : 0 );
