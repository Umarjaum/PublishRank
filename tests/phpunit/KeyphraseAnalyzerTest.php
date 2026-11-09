<?php
/**
 * Tests for the multi-keyphrase analyzer. Needs the WordPress test suite (or stubs for __(), _n(),
 * number_format_i18n() and wp_strip_all_tags()). Written for PHPUnit; not run in the build sandbox, which has no PHP.
 */
use PublishRankSEO\SEO\Content\KeyphraseAnalyzer;

class KeyphraseAnalyzerTest extends WP_UnitTestCase {

	private function article( array $over = array() ) {
		$words = 'Baking sourdough bread at home takes patience. A good sourdough starter needs feeding every day. '
			. 'Flour and water are all you need to begin, and the starter will bubble within a week. '
			. 'When the starter is active, mix the dough and let it rest overnight. Shape the loaf in the morning. '
			. 'Bake it hot with steam for a crisp crust. Cool it before slicing so the crumb sets properly. '
			. 'Many beginners rush the rise, but a slow rise gives better flavour. Keep notes on temperature and timing.';
		$tokens = \PublishRankSEO\SEO\ArticleIntelligence::tokens( $words );
		return array_merge(
			array(
				'primary'     => 'sourdough starter',
				'secondary'   => array( 'slow rise' ),
				'title'       => 'How to make a sourdough starter',
				'serp_title'  => 'How to make a sourdough starter',
				'description' => 'Learn to feed a sourdough starter and bake better bread at home.',
				'slug'        => 'sourdough-starter',
				'paragraphs'  => explode( '. ', $words ),
				'headings'    => array( array( 'level' => 2, 'text' => 'Feeding the starter' ) ),
				'images'      => array( array( 'alt' => 'A jar of bubbling sourdough starter' ) ),
				'links'       => array(),
				'words'       => $tokens,
			),
			$over
		);
	}

	public function test_primary_and_secondary_get_separate_cards() {
		$r = KeyphraseAnalyzer::analyze( $this->article() );
		$this->assertCount( 2, $r['cards'] );
		$this->assertSame( 'primary', $r['cards'][0]['role'] );
		$this->assertSame( 'secondary', $r['cards'][1]['role'] );
	}

	public function test_placements_are_detected() {
		$p = KeyphraseAnalyzer::analyze( $this->article() )['cards'][0]['placements'];
		$this->assertSame( 'exact', $p['title'] );
		$this->assertSame( 'exact', $p['description'] );
		$this->assertSame( 'exact', $p['slug'] );
		$this->assertSame( 'exact', $p['headings'] );
		$this->assertSame( 'exact', $p['images'] );
	}

	public function test_plural_forms_match() {
		$r = KeyphraseAnalyzer::analyze( $this->article( array( 'primary' => 'sourdough starters' ) ) );
		$this->assertSame( 'exact', $r['cards'][0]['placements']['title'] );
	}

	public function test_missing_keyphrase_is_reported_not_scored_as_present() {
		$r = KeyphraseAnalyzer::analyze( $this->article( array( 'primary' => 'espresso machine' ) ) );
		$this->assertSame( 'none', $r['cards'][0]['placements']['title'] );
		$this->assertLessThan( 40, $r['cards'][0]['score'] );
	}

	public function test_keyword_stuffing_is_flagged() {
		$text   = 'Buy cheap shoes. Cheap shoes are cheap shoes. Our cheap shoes beat other cheap shoes. Cheap shoes, cheap shoes, cheap shoes, cheap shoes.';
		$tokens = \PublishRankSEO\SEO\ArticleIntelligence::tokens( $text );
		$r      = KeyphraseAnalyzer::analyze( $this->article( array( 'primary' => 'cheap shoes', 'secondary' => array(), 'paragraphs' => explode( '. ', $text ), 'words' => $tokens ) ) );
		$this->assertSame( 'stuffed', $r['cards'][0]['natural'] );
		$ids = wp_list_pluck( $r['cards'][0]['checks'], 'status', 'id' );
		$this->assertSame( 'bad', $ids['stuffing'] );
	}

	public function test_duplicate_secondary_is_dropped_with_warning() {
		$r = KeyphraseAnalyzer::analyze( $this->article( array( 'secondary' => array( 'Sourdough Starter', 'slow rise', 'slow rise' ) ) ) );
		$this->assertCount( 2, $r['cards'] );
		$this->assertNotEmpty( $r['warnings'] );
	}

	public function test_secondary_limit_is_ten() {
		$many = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$many[] = 'topic word' . $i; }
		$r = KeyphraseAnalyzer::analyze( $this->article( array( 'secondary' => $many ) ) );
		$this->assertCount( 11, $r['cards'] );
	}

	public function test_combined_score_keeps_primary_dominant() {
		$r = array( 'secondary_average' => 0 );
		$this->assertSame( 80, KeyphraseAnalyzer::combine( 100, $r ) );
		$this->assertSame( 100, KeyphraseAnalyzer::combine( 100, array( 'secondary_average' => null ) ) );
	}

	public function test_intent_heuristics() {
		$this->assertSame( 'informational', KeyphraseAnalyzer::intent( 'how to bake bread' )['type'] );
		$this->assertSame( 'commercial', KeyphraseAnalyzer::intent( 'best bread makers' )['type'] );
		$this->assertSame( 'transactional', KeyphraseAnalyzer::intent( 'buy bread flour' )['type'] );
		$this->assertSame( 'unknown', KeyphraseAnalyzer::intent( 'sourdough' )['type'] );
	}

	public function test_non_latin_phrase_is_compared_as_written() {
		$r = KeyphraseAnalyzer::analyze( $this->article( array( 'primary' => 'اردو زبان', 'secondary' => array(), 'title' => 'اردو زبان کی تاریخ', 'serp_title' => 'اردو زبان کی تاریخ' ) ) );
		$this->assertSame( 'exact', $r['cards'][0]['placements']['title'] );
	}

	public function test_parse_list_accepts_lines_commas_and_arrays() {
		$this->assertSame( array( 'a', 'b', 'c' ), \PublishRankSEO\Meta\PostMeta::parse_list( "a\nb, c" ) );
		$this->assertSame( array( 'a', 'b' ), \PublishRankSEO\Meta\PostMeta::parse_list( array( 'a', 'b', 'a' ) ) );
		$this->assertSame( array(), \PublishRankSEO\Meta\PostMeta::parse_list( null ) );
	}
}
