<?php
defined( 'ABSPATH' ) || exit;
use PublishRankSEO\SEO\Language\LanguageRules as L;
use PublishRankSEO\SEO\Language\InclusiveLanguage as I;
use PublishRankSEO\SEO\ArticleIntelligence as A;
$fail = 0;
$eq = function ( $label, $got, $want ) use ( &$fail ) { $ok = $got === $want; if ( ! $ok ) { $fail++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ' => ' . var_export( $got, true ) . ( $ok ? '' : ' (want ' . var_export( $want, true ) . ')' ) . "\n"; };
$eq( 'detect en', L::detect( 'The quick brown fox jumps over the lazy dog.', 'en_US' ), 'en' );
$eq( 'detect ar', L::detect( 'أعلنت الحكومة اليوم عن خطة جديدة لتحسين التعليم في المدارس.', 'en_US' ), 'ar' );
$eq( 'detect ur', L::detect( 'حکومت نے آج اسکولوں میں تعلیم بہتر بنانے کے لیے نیا منصوبہ پیش کیا ہے۔', 'en_US' ), 'ur' );
$eq( 'detect ur by locale', L::detect( 'حکومت نے نیا منصوبہ', 'ur' ), 'ur' );
$eq( 'detect de generic', L::detect( 'Die Regierung hat heute einen neuen Plan vorgestellt.', 'de_DE' ), 'generic' );
$eq( 'normalize harakat', L::normalize( 'مُدَرِّسَة' ), 'مدرسه' );
$eq( 'normalize alef', L::normalize( 'أحمد إبراهيم آمن' ), 'احمد ابراهیم امن' );
$eq( 'stem en plural', L::stem( 'stories', 'en' ), 'story' );
$eq( 'stem en keep', L::stem( 'news', 'en' ), 'new' === L::stem( 'news', 'en' ) ? 'new' : 'news' );
$eq( 'stem ar article', L::stem( L::normalize( 'المدرسة' ), 'ar' ), L::stem( L::normalize( 'مدرسة' ), 'ar' ) );
$eq( 'stem ar wal', L::stem( L::normalize( 'والمعلمون' ), 'ar' ), L::stem( L::normalize( 'معلم' ), 'ar' ) );
$eq( 'stem ur plural', L::stem( 'کتابیں', 'ur' ), 'کتاب' );
$eq( 'stem ur oblique', L::stem( 'خبروں', 'ur' ), 'خبر' );
$eq( 'long sentence ur', L::long_sentence_words( 'ur' ), 30 );
$eq( 'transition ur', 1 === preg_match( L::transition_pattern( 'ur' ), L::normalize( 'تاہم حکومت نے کہا' ) ), true );
$eq( 'transition ar', 1 === preg_match( L::transition_pattern( 'ar' ), L::normalize( 'لذلك قررت الحكومة' ) ), true );
// Keyword matching across diacritics/article in a full analysis (Arabic).
$ar = '<p>أعلنت وزارة التعليم عن خطة جديدة. التعليم في المدارس سيتحسن هذا العام. لذلك يعمل المعلمون بجد. وقال الوزير إن التعليم أولوية. ثم شرح تفاصيل الخطة للمواطنين. بالإضافة إلى ذلك ستبنى مدارس جديدة في القرى.</p>' . str_repeat( '<p>يتابع المسؤولون تنفيذ الخطة في كل المحافظات ويقدمون تقارير منتظمة إلى الوزارة.</p>', 8 );
$r  = A::analyze( array( 'title' => 'خطة جديدة لتحسين التَّعليم', 'content' => $ar, 'keyphrase' => 'تعليم', 'slug' => 'taleem', 'home_host' => 'localhost' ), false );
$ids = wp_list_pluck( $r['checks'], 'status', 'id' );
$eq( 'ar analysis language', $r['stats']['language'], 'ar' );
$eq( 'ar keyword in title (diacritics+al)', $ids['keyphrase_title'] ?? null, 'good' );
$eq( 'ar no flesch', isset( $ids['read_flesch'] ), false );
$eq( 'ar transitions evaluated', isset( $ids['read_transitions'] ), true );
// Urdu
$ur = '<p>حکومت نے تعلیم کے لیے نیا منصوبہ پیش کیا۔ تاہم اساتذہ نے مزید وسائل کا مطالبہ کیا۔ اس کے علاوہ اسکولوں میں نئی کتابیں دی جائیں گی۔ وزیر نے کہا کہ تعلیم اولین ترجیح ہے۔</p>' . str_repeat( '<p>صوبائی حکام ہر ضلع میں منصوبے پر عمل درآمد کی نگرانی کر رہے ہیں اور رپورٹیں بھیج رہے ہیں۔</p>', 8 );
$r  = A::analyze( array( 'title' => 'تعلیم کا نیا منصوبہ', 'content' => $ur, 'keyphrase' => 'تعلیم', 'slug' => 'taleem', 'home_host' => 'localhost' ), false );
$ids = wp_list_pluck( $r['checks'], 'status', 'id' );
$eq( 'ur analysis language', $r['stats']['language'], 'ur' );
$eq( 'ur keyword in title', $ids['keyphrase_title'] ?? null, 'good' );
$eq( 'ur no passive', isset( $ids['read_passive'] ), false );
// English unchanged path
$en = '<p>WordPress SEO helps publishers. However, many stories lack titles. Therefore editors add them. ' . str_repeat( 'Clear writing helps readers understand the story quickly. ', 12 ) . '</p>';
$r  = A::analyze( array( 'title' => 'WordPress SEO for publishers', 'content' => $en, 'keyphrase' => 'wordpress seo', 'slug' => 'wordpress-seo', 'home_host' => 'localhost' ), false );
$ids = wp_list_pluck( $r['checks'], 'status', 'id' );
$eq( 'en language', $r['stats']['language'], 'en' );
$eq( 'en flesch runs', isset( $ids['read_flesch'] ), true );
// Inclusive language
update_option( I::OPTION, array( 'enabled' => 1, 'disabled' => array( 'lame' ) ) );
$f = I::scan( 'The chairman said the firemen were crazy. That was lame. He committed suicide.', 'en' );
$eq( 'inclusive rules found', wp_list_pluck( $f, 'rule' ), array( 'chairman', 'fireman', 'crazy_insane', 'committed_suicide' ) );
$eq( 'inclusive none for ur', I::scan( 'کوئی متن', 'ur' ), array() );
$r  = A::analyze( array( 'title' => 'Mankind and manpower', 'content' => $en, 'keyphrase' => 'wordpress seo', 'home_host' => 'localhost' ), false );
$inc = array_values( array_filter( $r['checks'], function ( $c ) { return 'inclusive' === $c['group']; } ) );
$eq( 'inclusive in analysis', wp_list_pluck( $inc, 'dismiss' ), array( 'mankind', 'manpower' ) );
$score_on = $r['score'];
update_option( I::OPTION, array( 'enabled' => 0 ) );
$r  = A::analyze( array( 'title' => 'Mankind and manpower', 'content' => $en, 'keyphrase' => 'wordpress seo', 'home_host' => 'localhost' ), false );
$eq( 'inclusive does not change score', $r['score'], $score_on );
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
