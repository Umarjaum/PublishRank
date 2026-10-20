<?php
/** Schema reachability and editor compatibility checks. */
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) {
	$path = $root . '/' . $file;
	if ( ! is_file( $path ) ) { fwrite( STDERR, "Missing {$file}\n" ); exit( 1 ); }
	return file_get_contents( $path );
};
$types = $read( 'src/Schema/SchemaTypes.php' );
$builder = $read( 'src/Schema/SchemaBuilder.php' );
$graph = $read( 'src/Schema/GraphOutput.php' );
$suite = $read( 'src/Schema/SchemaSuite.php' );
$post_meta = $read( 'src/Meta/PostMeta.php' );
$dashboard = $read( 'src/Admin/DashboardPage.php' );
$checks = array(
	'news-only schema types are registered' => false !== strpos( $types, "'NewsArticle'" ) && false !== strpos( $types, "'Article'" ) && false !== strpos( $types, "'LiveBlogPosting'" ) && false !== strpos( $types, "'VideoObject'" ) && false !== strpos( $types, "'ImageGallery'" ),
	'page type is emitted through the WebPage node' => false !== strpos( $graph, "'@type' => ( new SchemaBuilder() )->current_webpage_type()" ),
	'pages retain foundational WebPage behavior' => false !== strpos( $builder, "return 'WebPage';" ),
	'revoked page subtypes are absent' => false === strpos( $types, "'QAPage'" ) && false === strpos( $types, "'AboutPage'" ),
	'image gallery emits image objects' => false !== strpos( $builder, "'ImageGallery' === \$type" ) && false !== strpos( $builder, "'#gallery-image-'" ),
	'video and image gallery nodes remain reachable' => false !== strpos( $builder, "'VideoObject' === \$type" ) && false !== strpos( $builder, "'ImageGallery' === \$type" ),
	'page and post schema editor has a verified nonce' => false !== strpos( $suite, "wp_nonce_field( 'publishrank_seo_meta', 'publishrank_seo_meta_nonce' )" ) && false !== strpos( $post_meta, "'publishrank_seo_meta_nonce'" ),
	'revoked FAQ panel is absent' => false === strpos( $suite, 'data-types="FAQPage,QAPage"' ),
	'dashboard links directly to new post and page editors' => false !== strpos( $dashboard, "admin_url( 'post-new.php' )" ) && false !== strpos( $dashboard, "post-new.php?post_type=page" ),
);
$failed = 0;
foreach ( $checks as $label => $ok ) { echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; if ( ! $ok ) { $failed++; } }
echo $failed ? "Schema/editor coverage checks failed: {$failed}\n" : "Schema/editor coverage checks passed.\n";
exit( $failed ? 1 : 0 );
