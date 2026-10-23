<?php
/** Schema reachability and editor compatibility checks. */
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) {
	$path = $root . '/' . $file;
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "Missing {$file}\n" );
		exit( 1 );
	}
	return file_get_contents( $path );
};
$types    = $read( 'src/Schema/SchemaTypes.php' );
$builder  = $read( 'src/Schema/SchemaBuilder.php' );
$graph    = $read( 'src/Schema/GraphOutput.php' );
$suite    = $read( 'src/Schema/SchemaSuite.php' );
$postmeta = $read( 'src/Meta/PostMeta.php' );
$core     = $read( 'src/Core/Plugin.php' );
$checks   = array(
	'supported news and media schema types are registered' => false !== strpos( $types, "'NewsArticle'" ) && false !== strpos( $types, "'Article'" ) && false !== strpos( $types, "'LiveBlogPosting'" ) && false !== strpos( $types, "'VideoObject'" ) && false !== strpos( $types, "'ImageGallery'" ),
	'page type is emitted through the WebPage node' => false !== strpos( $graph, 'current_webpage_type()' ),
	'pages retain foundational WebPage behavior' => false !== strpos( $builder, "return 'WebPage';" ),
	'supported page subtypes remain selectable while retired QAPage is absent' => false !== strpos( $types, "'AboutPage'" ) && false !== strpos( $types, "'ContactPage'" ) && false === strpos( $types, "'QAPage'" ),
	'image gallery emits image objects' => false !== strpos( $builder, "'ImageGallery' === \$type" ) && false !== strpos( $builder, "'#gallery-image-'" ),
	'video and image gallery nodes remain reachable' => false !== strpos( $builder, "'VideoObject' === \$type" ) && false !== strpos( $builder, "'ImageGallery' === \$type" ),
	'page and post schema editor has a verified nonce' => false !== strpos( $suite, "wp_nonce_field( 'publishrank_seo_meta', 'publishrank_seo_meta_nonce' )" ) && false !== strpos( $postmeta, "'publishrank_seo_meta_nonce'" ),
	'FAQPage has a dedicated supported panel, not a retired QAPage panel' => false !== strpos( $suite, 'data-types="FAQPage"' ) && false === strpos( $suite, 'QAPage' ),
	'schema editor suite is registered in the plugin module loader' => false !== strpos( $core, 'SchemaSuite::class' ),
);
$failed = 0;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	if ( ! $ok ) {
		++$failed;
	}
}
echo $failed ? "Schema/editor coverage checks failed: {$failed}\n" : "Schema/editor coverage checks passed.\n";
exit( $failed ? 1 : 0 );
