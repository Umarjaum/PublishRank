<?php
require ( getenv( 'WP_LOAD' ) ?: '/path/to/wordpress/wp-load.php' );
function req($m,$r,$body=null){ $q=new WP_REST_Request($m,$r); if($body!==null){$q->set_header('content-type','application/json');$q->set_body(wp_json_encode($body));} $res=rest_do_request($q); return array($res->get_status(), $res->get_data()); }
wp_set_current_user(1); $o=array();
list($s,$d)=req('POST','/publishrank/v1/media-links/attachments/7/metadata',array('license'=>'javascript:alert(1)')); $o['bad_license']=$s;
list($s,$d)=req('POST','/publishrank/v1/media-links/attachments/7/metadata',array('credit'=>'Jane Doe / PR Test','creator'=>'Jane Doe','copyright'=>'© 2026 PR Test','license'=>'https://creativecommons.org/licenses/by/4.0/','license_page'=>'https://example.com/licensing')); $o['save']=$s; $o['rights']=$d['rights']??null;
wp_set_current_user(0);
$h=\PublishRankSEO\API\PostSeoController::render_head(get_post(9)); $g=array(); foreach($h['jsonld'] as $j){ $g=array_merge($g,$j['@graph']??array($j)); }
foreach($g as $n){ if(($n['@type']??'')==='ImageObject'){ $o['imageobject']=$n; } }
$e=\PublishRankSEO\Schema\Studio\Validator::eligibility($g); foreach($e as $x){ $o['elig'][]=$x['type'].':'.$x['status']; }
echo json_encode($o,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
