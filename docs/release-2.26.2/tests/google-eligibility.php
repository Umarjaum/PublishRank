<?php
// For each test post: render the real head through the new REST helper and run PublishRank's Google-eligibility check on the graph.
require ( getenv( 'WP_LOAD' ) ?: '/path/to/wordpress/wp-load.php' );
$d = json_decode(file_get_contents('' . sys_get_temp_dir() . '/schema_urls.json'), true); wp_set_current_user(0);
$rows = array();
foreach ( $d['ids'] as $k => $id ) {
	$h = \PublishRankSEO\API\PostSeoController::render_head( get_post($id) );
	if ( is_wp_error($h) ) { $rows[$k] = 'ERROR '.$h->get_error_message(); continue; }
	$graph = array(); foreach ( $h['jsonld'] as $j ) { $graph = array_merge($graph, isset($j['@graph']) ? $j['@graph'] : array($j)); }
	$e = \PublishRankSEO\Schema\Studio\Validator::eligibility( $graph );
	$bad = array(); foreach ( $e as $x ) { if ( 'incomplete' === $x['status'] ) $bad[] = $x['type'].'→'.$x['feature'].' missing '.implode(',',$x['missing']); }
	$rows[$k] = $bad ? $bad : 'ok ('.count($e).' Google features checked)';
}
echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
