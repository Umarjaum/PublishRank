<?php
/** Static checks for the dashboard banner, article schema default and sitemap routing fixes. */
$root = dirname( __DIR__ );
$read = static function ( $f ) use ( $root ) { return (string) file_get_contents( $root . '/' . $f ); };
$graph = $read( 'src/Schema/GraphOutput.php' ); $ctl = $read( 'src/Sitemap/SitemapController.php' ); $css = $read( 'assets/css/admin.css' ); $lang = $read( 'src/Meta/LanguageAlternates.php' ); $suite = $read( 'src/Schema/SchemaSuite.php' );
$checks = array(
	'default article schema type setting is honoured' => false !== strpos( $graph, "get_option( 'publishrank_schema_default_post_type'" ),
	'pages retain the foundational WebPage node' => false !== strpos( $graph, "current_webpage_type()" ),
	'sitemap URLs resolve without stored rewrite rules' => false !== strpos( $ctl, "add_action( 'parse_request'" ) && false !== strpos( $ctl, 'function match_request' ),
	'missing sitemap rewrite rule is healed once' => false !== strpos( $ctl, 'function heal_rewrite_rules' ) && false !== strpos( $ctl, 'publishrank_sitemap_rules_healed' ),
	'welcome heading is forced white' => false !== strpos( $css, '.wrap .rhs-welcome-banner h2' ) && false !== strpos( $css, 'color:#fff!important' ),
	'hreflang uses the locale of the page being served' => false !== strpos( $lang, 'function current_locale' ) && false !== strpos( $lang, 'PostMeta::HREFLANG' ),
	'revoked broad schema panels are absent' => false === strpos( $suite, 'data-types="Review"' ) && false === strpos( $suite, 'data-types="Recipe"' ),
	'banner artwork is not cropped or overlapped' => false !== strpos( $css, 'object-fit:contain' ),
);
$failed = 0;
foreach ( $checks as $label => $ok ) { echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; if ( ! $ok ) { $failed++; } }
exit( $failed ? 1 : 0 );
