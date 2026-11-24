<?php
require ( getenv( 'WP_LOAD' ) ?: '/path/to/wordpress/wp-load.php' );
use PublishRankSEO\Schema\Studio as St;
$d=json_decode(file_get_contents('' . sys_get_temp_dir() . '/schema_urls.json'),true);
foreach(St\Templates::all() as $t){ $id=$d['ids']['studio:'.$t['id']]; $ctx=St\Context::for_post($id);
  $b=St\Engine::build($t,$ctx); echo str_pad($t['id'],20), $b['node']?('printed '.$b['node']['@type'].' props='.count($b['node'])):('SKIPPED: '.$b['skipped']), "\n"; }
