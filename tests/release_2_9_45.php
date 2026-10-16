<?php
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) { return (string) file_get_contents( $root . '/' . ltrim( $file, '/' ) ); };
$assert = static function ( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } };
$main = $read( 'publishrank.php' );
$js = $read( 'assets/js/article-intel.js' );
$toolbar = $read( 'assets/css/admin-menu.css' );
$score = $read( 'src/Admin/EditorScoreCard.php' );
$types = $read( 'src/Schema/SchemaTypes.php' );
$license = $read( 'LICENSE' );
$assert( strpos( $main, "PUBLISHRANK_SEO_VERSION', '2.9.47'" ) !== false, 'release version is not 2.9.47' );
$assert( strpos( $js, "tinymce.get('content')" ) !== false && strpos( $js, "getEditedPostContent" ) !== false, 'editor analyzer content sources are incomplete' );
$assert( strpos( $js, '#publishrank-editor-score-refresh' ) !== false && strpos( $js, 'setTimeout(analyze,700)' ) !== false, 'score-card analyzer refresh/debounce wiring is missing' );
$assert( strpos( $score, "wp_enqueue_script" ) === false, 'duplicate score-card AJAX script is still enqueued' );
$assert( strpos( $toolbar, 'inline-flex' ) !== false && strpos( $toolbar, 'gap:4px' ) !== false, 'toolbar icon and label alignment is not explicit' );
foreach ( array( 'NewsArticle', 'Article', 'LiveBlogPosting', 'VideoObject', 'ImageGallery' ) as $type ) { $assert( strpos( $types, "'{$type}'" ) !== false, "schema type {$type} is missing" ); }
$assert( strpos( $license, 'Muhammad Umar Jabbar' ) !== false && strpos( $license, 'GNU GENERAL PUBLIC LICENSE' ) !== false, 'rights/license notice is inconsistent' );
echo "release 2.9.47 checks passed\n";
