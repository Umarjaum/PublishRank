<?php
// Creates one test post per schema option (article types, page types, Library types, Studio templates) and prints their URLs.
require ( getenv( 'WP_LOAD' ) ?: '/path/to/wordpress/wp-load.php' );
use PublishRankSEO\Schema\SchemaTypes as S; use PublishRankSEO\Schema\Library\Definitions as D; use PublishRankSEO\Schema\Library\Library as L; use PublishRankSEO\Meta\PostMeta as M;
wp_set_current_user(1);
foreach ( get_posts(array('meta_key'=>'_pr_schema_test','post_type'=>'any','post_status'=>'any','numberposts'=>-1)) as $p ) wp_delete_post($p->ID,true);
$img = 7; $out = array();
$body = '<!-- wp:paragraph --><p>Solar panels convert sunlight into electricity. This test article explains installation, costs and maintenance for homeowners in detail.</p><!-- /wp:paragraph --><!-- wp:image {"id":8} --><figure class="wp-block-image"><img src="'.wp_get_attachment_url(8).'" alt="Panel" class="wp-image-8"/></figure><!-- /wp:image -->';
function mk($title,$type='post',$meta=array()){ global $body,$img; $id=wp_insert_post(array('post_title'=>$title,'post_type'=>$type,'post_status'=>'publish','post_content'=>$body,'post_excerpt'=>'Short summary of '.$title.'.','post_author'=>1)); set_post_thumbnail($id,$img); update_post_meta($id,'_pr_schema_test',1); foreach($meta as $k=>$v) update_post_meta($id,$k,$v); return $id; }
foreach ( array_keys(S::article_types()) as $t ) { $out['article:'.$t] = mk("Article type $t",'post',array(M::SCHEMA_TYPE=>$t)); }
foreach ( array_keys(S::page_types()) as $t ) { $m=array(M::SCHEMA_TYPE=>$t); if('FAQPage'===$t){ $m[M::FAQ_ITEMS]=wp_json_encode(array(array('question'=>'How long do panels last?','answer'=>'Around 25 to 30 years.'),array('question'=>'Do they work in winter?','answer'=>'Yes, with lower output.'))); } $out['page:'.$t] = mk("Page type $t",'page',$m); }
$sample = function($key,$f){
	switch($f['kind']){
		case 'url': return 'https://example.com/'.$key; case 'date': return '2026-12-01'; case 'datetime': return '2026-12-01T10:00:00+00:00';
		case 'duration': return 'PT45M'; case 'number': return '12'; case 'money': return '19.99'; case 'currency': return 'USD'; case 'country': return 'US';
		case 'lines': return "First step text\nSecond step text"; case 'rating': return '4.5'; case 'select': return $f['options'][0];
		case 'json': return '{"@type":"Thing","name":"Custom thing"}'; case 'textarea': return 'Longer description for '.$key.'.';
		default: return 'Sample '.$key;
	}
};
$lib_report = array();
foreach ( D::all() as $type => $def ) {
	$data = array(); foreach ( $def['fields'] as $k => $f ) { $data[$k] = $sample($k,$f); }
	if ( 'ClaimReview' === $type ) { foreach ( $def['fields'] as $k => $f ) { if ( false !== stripos($k,'rating') && 'rating'!==$f['kind'] && 'number'!==$f['kind'] && 'select'!==$f['kind'] ) $data[$k]='False'; } }
	if ( 'Dataset' === $type ) { $data['description'] = 'Hourly solar generation readings from 120 residential rooftop systems, 2024 to 2026, with weather.'; }
	if ( 'Quiz' === $type ) { $data['questions'] = "What do solar panels convert? | Sunlight into electricity\nHow long do they last? | About 25 years"; }
	$n = L::normalise( $type, $data );
	$lib_report[$type] = array_keys( $n['errors'] );
	$out['library:'.$type] = mk("Library $type",'post',array(L::META=>array(array('type'=>$n['type'],'data'=>$n['data']))));
}
foreach ( \PublishRankSEO\Schema\Studio\Templates::all() as $t ) { $tk=($t['key']??$t['id']); $out['studio:'.$tk] = mk('Studio '.$tk,'post',array('_publishrank_schema_studio'=>array('mode'=>'inherit','add'=>array($tk),'remove'=>array(),'overrides'=>array()))); }
$urls = array(); foreach ( $out as $k => $id ) { $urls[$k] = get_permalink($id); }
$urls['home'] = home_url('/'); $urls['author'] = get_author_posts_url(1); $urls['category'] = get_category_link(1); $urls['search'] = home_url('/?s=solar');
file_put_contents('' . sys_get_temp_dir() . '/schema_urls.json', wp_json_encode(array('urls'=>$urls,'ids'=>$out,'library_errors'=>$lib_report), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo count($urls)," urls\n"; echo wp_json_encode(array_filter($lib_report));
