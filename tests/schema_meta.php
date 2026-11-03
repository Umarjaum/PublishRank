<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/** Static regression checks for schema graph and metadata precedence. */
$root = dirname( __DIR__ );
$meta = file_get_contents( $root . '/src/Meta/MetaGenerator.php' );
$resolver = file_get_contents( $root . '/src/Meta/DescriptionResolver.php' );
$head = file_get_contents( $root . '/src/Meta/HeadOutput.php' );
$graph = file_get_contents( $root . '/src/Schema/GraphOutput.php' );
$override_at = strpos( $resolver, '$override = (string) PostMeta::get( $id, PostMeta::DESCRIPTION' );
$override_return_at = false === $override_at ? false : strpos( $resolver, "'source' => 'override'", $override_at );
$automatic_at = false === $override_at ? false : strpos( $resolver, '$auto = self::automatic( $post, $length );', $override_at );
$checks = array(
    'manual SEO description wins over generated excerpt' => false !== $override_at && false !== $override_return_at && false !== $automatic_at && $override_return_at < $automatic_at,
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
