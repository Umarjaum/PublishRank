<?php
/** Regression checks retained from the 2.9.45 release, updated to validate current release invariants. */
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) {
	return (string) file_get_contents( $root . '/' . ltrim( $file, '/' ) );
};
$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
};
$main    = $read( 'publishrank.php' );
$readme  = $read( 'readme.txt' );
$js      = $read( 'assets/js/article-intel.js' );
$toolbar = $read( 'assets/css/admin-menu.css' );
$score   = $read( 'src/Admin/EditorScoreCard.php' );
$drag_js = $read( 'assets/js/editor-score-card.js' );
$drag_css = $read( 'assets/css/editor-score-card.css' );
$types   = $read( 'src/Schema/SchemaTypes.php' );
$license = $read( 'LICENSE' );
$header  = array();
$stable  = array();
$assert( 1 === preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $main, $header ) && 1 === preg_match( '/^Stable tag:\s*([0-9.]+)/m', $readme, $stable ) && $header[1] === $stable[1], 'plugin header and stable tag are not synchronized' );
$assert( strpos( $js, "tinymce.get('content')" ) !== false && strpos( $js, 'getEditedPostContent' ) !== false, 'editor analyzer content sources are incomplete' );
$assert( strpos( $js, '#publishrank-editor-score-refresh' ) !== false && strpos( $js, 'window.setTimeout(analyze, 600)' ) !== false, 'score-card analyzer refresh/debounce wiring is missing' );
$assert( strpos( $score, "wp_enqueue_script( 'publishrank-editor-score-card'" ) !== false && strpos( $score, 'assets/js/editor-score-card.js' ) !== false && strpos( $drag_js, "'pointerdown'" ) !== false && strpos( $drag_js, "'pointermove'" ) !== false && strpos( $drag_css, 'touch-action:none' ) !== false, 'editor score card enqueues pointer/touch drag support' );
$assert( strpos( $toolbar, 'inline-flex' ) !== false && strpos( $toolbar, 'gap:4px' ) !== false, 'toolbar icon and label alignment is not explicit' );
foreach ( array( 'NewsArticle', 'Article', 'LiveBlogPosting', 'VideoObject', 'ImageGallery' ) as $type ) {
	$assert( strpos( $types, "'{$type}'" ) !== false, "schema type {$type} is missing" );
}
$assert( strpos( $license, 'Muhammad Umar Jabbar' ) !== false && strpos( $license, 'GNU GENERAL PUBLIC LICENSE' ) !== false, 'rights/license notice is inconsistent' );
echo "Current release invariants passed.\n";
