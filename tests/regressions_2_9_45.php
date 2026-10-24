<?php
/** Regression checks for the 2.9.45 sitemap / schema / graph-scan fixes (source-level, no WordPress needed). */
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) { return (string) file_get_contents( $root . '/' . $file ); };
$recovery = $read( 'src/Meta/FrontendOutputRecovery.php' );
$inject = substr( $recovery, strpos( $recovery, 'public function inject' ) );
$xsl = $read( 'src/Sitemap/SitemapStylesheet.php' );
$builder = $read( 'src/Schema/SchemaBuilder.php' );
$graph = $read( 'src/Schema/GraphOutput.php' );
$page = $read( 'src/InternalLinks/InternalLinksPage.php' );
$store = $read( 'src/InternalLinks/Store.php' );
$checks = array(
	'inject() never starts an output buffer (PHP fatal inside buffer callbacks)' => false === strpos( $inject, 'ob_start' ) && false === strpos( $inject, 'ob_get_clean' ),
	'recovery markup is rendered at shutdown priority 0' => false !== strpos( $recovery, "add_action( 'shutdown', array( \$this, 'prepare_payload' ), 0 )" ),
	'sitemap stylesheet resolves without stored rewrite rules' => false !== strpos( $xsl, "add_action( 'parse_request'" ) && false !== strpos( $xsl, 'publishrank-sitemap.xsl' ),
	'sitemap stylesheet discards buffered output and is served as text/xsl' => false !== strpos( $xsl, 'ob_end_clean' ) && false !== strpos( $xsl, 'text/xsl' ),
	'webpage node type comes from current_webpage_type() in every context' => 1 === preg_match( "/'@type'\s*=>\s*\( new SchemaBuilder\(\) \)->current_webpage_type\(\)/", $graph ) && false !== strpos( $builder, "'SearchResultsPage'" ) && false !== strpos( $builder, "'ProfilePage'" ),
	'graph scan auto-continue uses the native form submit' => false !== strpos( $read( 'assets/js/admin.js' ), 'HTMLFormElement.prototype.submit.call(form)' ),
	'graph settings form does not print settings_fields()' => false === strpos( preg_replace( '#//.*#', '', $page ), 'settings_fields(' ),
	'content graph includes pages (public, not publicly_queryable)' => false === strpos( substr( $store, strpos( $store, 'function allowed_types' ), 400 ), "'publicly_queryable' => true" ),
);
$failed = 0;
foreach ( $checks as $label => $ok ) { echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; if ( ! $ok ) { ++$failed; } }
echo $failed ? "2.9.45 regression checks failed: {$failed}\n" : "2.9.45 regression checks passed.\n";
exit( $failed ? 1 : 0 );
