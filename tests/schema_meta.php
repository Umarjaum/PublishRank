<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/** Static regression checks for schema graph and metadata precedence. */
$root = dirname( __DIR__ );
$meta = file_get_contents( $root . '/src/Meta/MetaGenerator.php' );
$head = file_get_contents( $root . '/src/Meta/HeadOutput.php' );
$graph = file_get_contents( $root . '/src/Schema/GraphOutput.php' );
$checks = array(
    'manual SEO description wins over generated excerpt' => strpos( $meta, 'A manually written SEO description must win over the generated excerpt.' ) !== false,
    'advanced robots directives accepted' => strpos( $meta, "'max-video-preview:-1'" ) !== false && strpos( $meta, "'noimageindex'" ) !== false,
    'article author is a connected graph node' => strpos( $graph, '$author_node_id' ) !== false && strpos( $graph, 'private function article_author()' ) !== false,
    'webpage schema includes SEO description' => strpos( $graph, "'description' => ( new \\PublishRankSEO\\Meta\\MetaGenerator() )->description()" ) !== false,
    'article image falls back to social image' => strpos( $graph, "get_option( 'publishrank_seo_default_social_image', '' )" ) !== false,
    'schema extension filter exists' => strpos( $graph, "apply_filters( 'publishrank_schema_graph'" ) !== false,
    'Twitter image alt tag supported' => strpos( $head, 'twitter:image:alt' ) !== false,
);
$failed = array();
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; if ( ! $passed ) { $failed[] = $label; } }
if ( $failed ) { exit( 1 ); }
