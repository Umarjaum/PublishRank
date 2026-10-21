<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); } }
if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
$root = dirname( __DIR__ );
$read = static function ( $path ) use ( $root ) { return (string) file_get_contents( $root . '/' . $path ); };
$assert = static function ( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); } };
require_once $root . '/src/SEO/ArticleIntelligence.php';
$php = $read( 'src/SEO/ArticleIntelligence.php' );
$js = $read( 'src/assets/js/article-intel.js' );
$card = $read( 'src/Admin/EditorScoreCard.php' );
$css = $read( 'src/assets/css/editor-score-card.css' );
$version_helper = $read( 'src/Core/Branding.php' );
$assert( strpos( $php, 'data-meta-key="_publishrank_seo_focus_keyword"' ) !== false && strpos( $php, 'data-meta-key="_publishrank_seo_title"' ) !== false && strpos( $php, 'data-meta-key="_publishrank_seo_description"' ) !== false, 'Quick-edit fields do not map to registered SEO metadata keys.' );
$assert( strpos( $js, "keyphrase:'_publishrank_seo_focus_keyword'" ) !== false && strpos( $js, "seo_title:'_publishrank_seo_title'" ) !== false && strpos( $js, "description:'_publishrank_seo_description'" ) !== false, 'Editor sync keys do not match PostMeta keys.' );
$assert( strpos( $js, 'dispatch.editPost({meta:next})' ) !== false && strpos( $js, 'wp.data.subscribe' ) !== false, 'Gutenberg metadata or live-content synchronization is missing.' );
$assert( strpos( $js, 'setSelectionRange(selection[0],selection[1])' ) !== false && strpos( $js, 'refreshedQuick.open=quickOpen' ) !== false, 'Live updates do not preserve quick-edit caret and panel disclosure state.' );
$assert( strpos( $php, 'filemtime( $css_path )' ) !== false && strpos( $card, 'filemtime( $path )' ) !== false && strpos( $version_helper, "'2.9.58'" ) !== false, 'Editor assets are not cache-busted while preserving the plugin version.' );
$assert( strpos( $js, "setTimeout(analyze,700)" ) !== false && strpos( $js, "window.setTimeout(analyze,250)" ) !== false, 'Analysis is not debounced and initialized automatically.' );
$assert( strpos( $php, "'groups' => " . chr( 36 ) . "report['groups']" ) !== false && strpos( $card, 'data-score-group=' ) !== false && strpos( $css, 'conic-gradient' ) !== false, 'Score gauge or category chart is not wired to analyzer output.' );
$assert( strpos( $php, 'pr-intel__apply-suggestion' ) !== false && strpos( $js, 'pr-intel__apply-suggestion' ) !== false && strpos( $js, 'pr-intel__use-keyphrase' ) !== false, 'One-click SEO suggestions are not connected to quick-edit fields.' );
$assert( strpos( $php, 'post_term_names( $id' ) !== false && strpos( $js, "currentTermNames('post_tag','tags')" ) !== false, 'Saved or currently selected WordPress tags are not fetched for keyphrase suggestions.' );
$assert( strpos( $css, 'max-height:min(42vh,340px)' ) !== false && strpos( $css, 'safe-area-inset-bottom' ) !== false, 'The score card is not constrained for mobile viewport and safe-area use.' );
$assert( strpos( $read( 'src/assets/css/article-intel.css' ), '.pr-intel__item--bad{color:#536174}' ) !== false, 'Issue checklist uses the deprecated bright red text treatment.' );
$phrase = \PublishRankSEO\SEO\ArticleIntelligence::suggest_title_keyphrase( 'One-Pan Lemon Herb Chicken with Roasted Vegetables' );
$assert( 'pan lemon herb' === $phrase, 'Headline keyphrase fallback did not produce a normalized, non-stopword suggestion.' );
$draft = \PublishRankSEO\SEO\ArticleIntelligence::blank_data();
$draft['title'] = 'Lemon Chicken Recipe';
$draft['content'] = 'Lemon chicken is easy to prepare. Lemon chicken uses fresh herbs and lemon. Serve lemon chicken with vegetables for a quick dinner.';
$draft['tags'] = array( 'lemon chicken', 'fresh herbs' );
$draft['categories'] = array( 'Dinner recipes' );
$report = \PublishRankSEO\SEO\ArticleIntelligence::analyze( $draft, false );
$assert( 'Lemon Chicken Recipe' === $report['fields']['title'], 'The analyzer headline is not the article title.' );
$assert( 'lemon chicken' === $report['stats']['focus_keyword'], 'The first article tag was not used as the default focus-keyphrase signal.' );
$assert( '' === $report['fields']['keyphrase'], 'A tag-derived suggestion was incorrectly persisted as a manual focus keyphrase.' );
$assert( 'tag' === $report['suggestions']['keyphrases'][0]['source'], 'WordPress tags are not first-class keyphrase suggestions.' );
$draft['keyphrase'] = 'quick dinner';
$report = \PublishRankSEO\SEO\ArticleIntelligence::analyze( $draft, false );
$assert( 'quick dinner' === $report['stats']['focus_keyword'], 'An explicit publisher focus keyphrase did not override taxonomy-derived phrases.' );
$draft['keyphrase'] = '';
$draft['tags'] = array();
$report = \PublishRankSEO\SEO\ArticleIntelligence::analyze( $draft, false );
$assert( 'Dinner recipes' === $report['stats']['focus_keyword'], 'The article category was not used as the fallback phrase when no tag is selected.' );
$draft['categories'] = array();
$report = \PublishRankSEO\SEO\ArticleIntelligence::analyze( $draft, false );
$assert( 'lemon chicken recipe' === $report['stats']['focus_keyword'] && 'headline' === $report['stats']['focus_keyword_source'], 'The article title was not used as the final focus-phrase fallback.' );
echo "article intelligence editor checks passed\n";
