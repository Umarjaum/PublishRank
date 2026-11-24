<?php
// Runs inside WP via wp-load; exercises REST through rest_do_request (real dispatch, real permission callbacks).
require ( getenv( 'WP_LOAD' ) ?: '/path/to/wordpress/wp-load.php' );
$ids = array_map('trim', file('/dev/null')); $pid = 9;
function req($m,$r,$body=null){ $q=new WP_REST_Request($m,$r); if($body!==null){$q->set_header('content-type','application/json');$q->set_body(wp_json_encode($body));} $res=rest_do_request($q); return array($res->get_status(), $res->get_data(), $res->get_headers()); }
$out = array();
wp_set_current_user(0);
list($s,$d) = req('GET',"/publishrank/v1/posts/$pid/seo"); $out['anon_get_status']=$s; $out['anon_has_focus']=array_key_exists('focus_keyword',(array)$d);
list($s,$d) = req('PATCH',"/publishrank/v1/posts/$pid/seo",array('title'=>'x')); $out['anon_patch']=$s;
list($s,$d) = req('GET',"/publishrank/v1/posts/$pid/analysis"); $out['anon_analysis']=$s;
list($s,$d) = req('GET',"/wp/v2/posts/$pid"); $out['core_anon_focus']= isset($d['publishrank_seo']) ? array_key_exists('focus_keyword',$d['publishrank_seo']) : 'nofield';
// contributor without edit_seo
$c = get_user_by('login','contrib'); if(!$c){ $cid=wp_insert_user(array('user_login'=>'contrib','user_pass'=>wp_generate_password(),'role'=>'contributor','user_email'=>'c@example.test')); } else $cid=$c->ID;
$u=new WP_User($cid); $u->add_cap(\PublishRankSEO\Core\Capabilities::cap('edit_seo'), false); $out['cap_name']=\PublishRankSEO\Core\Capabilities::cap('edit_seo');
$draft = wp_insert_post(array('post_title'=>'Contrib draft','post_status'=>'draft','post_author'=>$cid,'post_content'=>'<p>Hello world</p>'));
wp_set_current_user($cid);
$out['contrib_caps_edit_post']=current_user_can('edit_post',$draft); $out['contrib_can_edit_seo']=\PublishRankSEO\Core\Capabilities::can_edit_seo($draft);
list($s,$d)=req('PATCH',"/publishrank/v1/posts/$draft/seo",array('title'=>'Contrib title')); $out['contrib_patch']=$s;
list($s,$d)=req('POST',"/wp/v2/posts/$draft",array('meta'=>array('_publishrank_seo_title'=>'via core'))); $out['contrib_core_meta']=$s; $out['contrib_core_meta_saved']=get_post_meta($draft,'_publishrank_seo_title',true);
// admin
wp_set_current_user(1);
list($s,$d,$h)=req('PATCH',"/publishrank/v1/posts/$pid/seo",array('title'=>'REST test title <b>x</b>','focus_keyword'=>'solar panels','secondary_keyphrases'=>array('pv','inverter'),'cornerstone'=>true)); $out['admin_patch']=$s; $out['changed']=isset($h['X-PublishRank-Changed'])?$h['X-PublishRank-Changed']:null;
$out['stored_title']=get_post_meta($pid,'_publishrank_seo_title',true); $out['stored_sec']=get_post_meta($pid,'_publishrank_seo_secondary_keyphrases',true);
list($s,$d)=req('PATCH',"/publishrank/v1/posts/$pid/seo",array('bogus'=>1)); $out['unknown_field']=$s;
list($s,$d)=req('GET',"/publishrank/v1/posts/$pid/analysis"); $out['analysis']=$s; $out['analysis_keys']=array_slice(array_keys((array)$d['report']),0,8);
list($s,$d)=req('GET',"/publishrank/v1/posts/$pid/head"); $out['head']=$s; $out['head_title']=$d['title']??null; $out['head_meta_n']=count($d['meta']??array()); $out['head_jsonld_n']=count($d['jsonld']??array());
$types=array(); foreach(($d['jsonld']??array()) as $j){ foreach(($j['@graph']??array($j)) as $n){ $types[]= is_array($n['@type']??null)?implode('+',$n['@type']):($n['@type']??'?'); } } $out['graph_types']=$types;
list($s,$d)=req('GET',"/publishrank/v1/posts/$draft/head"); $out['admin_draft_head']=$s;
wp_set_current_user(0);
list($s,$d)=req('GET',"/publishrank/v1/posts/$draft/head"); $out['anon_draft_head']=$s;
list($s,$d)=req('GET',"/publishrank/v1/posts/$pid/schema"); $out['anon_schema']=$s;
list($s,$d)=req('GET',"/publishrank/v1/sitemaps"); $out['sitemaps']=$s; $out['sitemap_index']=$d['index']??null; $out['sitemap_files']=count($d['files']??array());
// analytics rate limit
add_filter('publishrank_rate_limit', function($l,$b){ return 'collect'===$b?3:$l; },10,2);
$codes=array(); for($i=0;$i<5;$i++){ list($s)=req('POST','/publishrank/v1/collect',array('e'=>'pv','p'=>'/hello/')); $codes[]=$s; } $out['collect_codes']=$codes;
remove_all_filters('publishrank_rate_limit');
list($s)=req('POST','/publishrank/v1/collect',array('e'=>'pv','p'=>'/'.str_repeat('a',1100))); $out['collect_long']=$s;
wp_delete_post($draft,true);
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
