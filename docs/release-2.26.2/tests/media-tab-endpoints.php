<?php
require ( getenv( 'WP_LOAD' ) ?: '/path/to/wordpress/wp-load.php' );
function req($m,$r,$body=null){ $q=new WP_REST_Request($m,$r); if($body!==null){$q->set_header('content-type','application/json');$q->set_body(wp_json_encode($body));} $res=rest_do_request($q); return array($res->get_status(), $res->get_data()); }
$o=array(); wp_set_current_user(1);
$routes = array_keys(rest_get_server()->get_routes()); $o['routes']=array_values(array_filter($routes,function($r){return false!==strpos($r,'media-links') && (false!==strpos($r,'posts')||false!==strpos($r,'meta')||false!==strpos($r,'check'));}));
list($s,$d)=req('GET','/publishrank/v1/media-links/post/9/images'); $o['list']=$s; $o['n']=count($d['items']??$d['images']??array()); $first=($d['items']??$d['images']??array())[0]??null; $o['first_keys']=$first?array_keys($first):null;
$att=7; $mod=get_post_field('post_modified_gmt',$att);
list($s,$d)=req('POST',"/publishrank/v1/media-links/attachments/$att/metadata",array('alt'=>'Solar <script>x</script> roof','title'=>'Roof','caption'=>'Cap <em>ok</em>','description'=>'Desc','modified'=>$d['modified']??($first['modified']??''))); $o['meta_save']=$s; $o['meta_resp']=is_array($d)?array_intersect_key($d,array_flip(array('alt','title','caption','modified','code'))):$d;
$o['alt_stored']=get_post_meta($att,'_wp_attachment_image_alt',true); $o['caption_stored']=get_post_field('post_excerpt',$att);
list($s,$d)=req('POST',"/publishrank/v1/media-links/attachments/$att/metadata",array('alt'=>'stale','modified'=>'2000-01-01 00:00:00')); $o['stale']=$s; $o['alt_after_stale']=get_post_meta($att,'_wp_attachment_image_alt',true);
list($s,$d)=req('POST','/publishrank/v1/media-links/check-url',array('url'=>'javascript:alert(1)')); $o['js_url']=$s;
list($s,$d)=req('POST','/publishrank/v1/media-links/check-url',array('url'=>'https://example.com/a.jpg')); $o['https_url']=$s; $o['https_resp']=$d;
list($s,$d)=req('POST',"/publishrank/v1/media-links/attachments/$att/plan",array('url'=>'http://localhost:8090/wp-content/uploads/new-name.jpg')); $o['plan']=$s; $o['plan_keys']=is_array($d)?array_keys($d):$d;
// contributor
$c=get_user_by('login','contrib2'); $cid=$c?$c->ID:wp_insert_user(array('user_login'=>'contrib2','user_pass'=>wp_generate_password(),'role'=>'author','user_email'=>'c2@example.test'));
wp_set_current_user($cid); list($s)=req('POST',"/publishrank/v1/media-links/attachments/$att/metadata",array('alt'=>'hijack')); $o['author_other_att']=$s;
wp_set_current_user(0); list($s)=req('GET','/publishrank/v1/media-links/post/9/images'); $o['anon_list']=$s;
echo json_encode($o,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
