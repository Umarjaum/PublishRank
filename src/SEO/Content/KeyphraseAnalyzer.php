<?php
/**
 * Multi-keyphrase analysis.
 *
 * Scores the primary focus keyphrase and up to ten secondary keyphrases independently. It is fully local:
 * no external API. It receives the already-parsed article (paragraphs, headings, image alts, link anchors,
 * tokens) from ArticleIntelligence, so the content is parsed once.
 *
 * Matching is by word stems, so "recipes" matches "recipe". A phrase also counts as a looser match when all of
 * its words appear close together. Counting is not enough on its own: each phrase is also checked for natural use
 * (stuffing inside one sentence, runs of consecutive sentences, keyword lists, mechanical repetition).
 *
 * The primary keyphrase stays the main optimization target: the article score is unchanged and secondary
 * scores contribute a fixed, small share of the combined score.
 */
namespace PublishRankSEO\SEO\Content;

use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\SEO\ArticleIntelligence;

defined( 'ABSPATH' ) || exit;

final class KeyphraseAnalyzer {
	const MAX_SECONDARY    = 10;
	const SECONDARY_WEIGHT = 0.2;

	/**
	 * Analyze all keyphrases of an article.
	 *
	 * @param array<string,mixed> $in Parsed article. Keys: primary, secondary, synonyms, related, title, serp_title,
	 *                                description, slug, paragraphs, headings, images, links, words, post_id, site.
	 * @return array<string,mixed>
	 */
	public static function analyze( array $in ) {
		$in = array_merge(
			array(
				'primary'     => '',
				'secondary'   => array(),
				'synonyms'    => array(),
				'related'     => array(),
				'title'       => '',
				'serp_title'  => '',
				'description' => '',
				'slug'        => '',
				'paragraphs'  => array(),
				'headings'    => array(),
				'images'      => array(),
				'links'       => array(),
				'words'       => array(),
				'post_id'     => 0,
				'site'        => false,
			),
			$in
		);

		$primary   = trim( (string) $in['primary'] );
		$warnings  = array();
		$secondary = self::dedupe_secondary( $primary, (array) $in['secondary'], $warnings );

		$ctx = array(
			'hay'        => self::stem_tokens( (array) $in['words'] ),
			'word_count' => count( (array) $in['words'] ),
			'title'      => self::stems_of( (string) $in['title'] ),
			'serp'       => self::stems_of( (string) $in['serp_title'] ),
			'desc'       => self::stems_of( (string) $in['description'] ),
			'slug'       => self::slug_stems( (string) $in['slug'] ),
			'paragraphs' => array_values( (array) $in['paragraphs'] ),
			'headings'   => (array) $in['headings'],
			'images'     => (array) $in['images'],
			'links'      => (array) $in['links'],
			'sentences'  => self::sentence_stems( (array) $in['paragraphs'] ),
		);

		$cards = array();
		if ( '' !== $primary ) {
			$cards[] = self::card( $primary, 'primary', (array) $in['synonyms'], (array) $in['related'], $ctx );
		}
		foreach ( $secondary as $phrase ) {
			$cards[] = self::card( $phrase, 'secondary', array(), array(), $ctx );
		}

		if ( count( $secondary ) > 5 ) {
			$warnings[] = array(
				'id'      => 'too_many_secondary',
				'message' => __( 'Many secondary keyphrases spread one article thin. Keep the ones this article can answer fully and give the rest their own article.', 'publishrank' ),
			);
		}
		if ( $in['site'] && $secondary ) {
			foreach ( self::used_elsewhere( $secondary, (int) $in['post_id'] ) as $phrase => $count ) {
				$warnings[] = array(
					'id'      => 'secondary_used_elsewhere',
					/* translators: 1: keyphrase, 2: number of other published articles. */
					'message' => sprintf( _n( '"%1$s" is the focus keyword of %2$d other published article, so the two may compete. Link to it instead of targeting the same phrase.', '"%1$s" is the focus keyword of %2$d other published articles, so they may compete. Link to them instead of targeting the same phrase.', $count, 'publishrank' ), $phrase, $count ),
				);
			}
		}

		$primary_score = null;
		$secondary_sum = 0;
		$secondary_n   = 0;
		foreach ( $cards as $card ) {
			if ( 'primary' === $card['role'] ) {
				$primary_score = (int) $card['score'];
			} else {
				$secondary_sum += (int) $card['score'];
				++$secondary_n;
			}
		}

		return array(
			'cards'             => $cards,
			'primary_score'     => $primary_score,
			'secondary_average' => $secondary_n ? (int) round( $secondary_sum / $secondary_n ) : null,
			'warnings'          => $warnings,
		);
	}

	/** Combined article score: the article score stays dominant; secondary keyphrases add a fixed share. */
	public static function combine( $article_score, array $result ) {
		$article_score = (int) $article_score;
		if ( ! isset( $result['secondary_average'] ) || null === $result['secondary_average'] ) {
			return $article_score;
		}
		return (int) round( $article_score * ( 1 - self::SECONDARY_WEIGHT ) + (int) $result['secondary_average'] * self::SECONDARY_WEIGHT );
	}

	/* ------------------------------------------------------------------ *
	 * One scorecard
	 * ------------------------------------------------------------------ */

	private static function card( $phrase, $role, array $synonyms, array $related, array $ctx ) {
		$is_primary = 'primary' === $role;
		$stems      = self::stems_of( $phrase );
		$core       = self::core( $stems );
		$syn_forms  = array();
		$syn_counts = array();
		foreach ( $synonyms as $syn ) {
			$syn       = trim( (string) $syn );
			$syn_stems = self::stems_of( $syn );
			if ( '' === $syn || ! $syn_stems || $syn_stems === $stems ) {
				continue; }
			$syn_forms[]         = $syn_stems;
			$syn_counts[ $syn ] = self::count_phrase( $ctx['hay'], $syn_stems );
		}
		$forms = array_merge( array( $stems ), $syn_forms );

		$occurrences = self::count_forms( $ctx['hay'], $forms );
		$density     = $ctx['word_count'] > 0 ? round( ( $occurrences * count( $stems ) / $ctx['word_count'] ) * 100, 2 ) : 0.0;

		/* Placements: exact (or synonym), loose (all words close together) or none. */
		$title_hit = self::best( self::present( $ctx['serp'], $forms, $core ), self::present( $ctx['title'], $forms, $core ) );
		$place     = array(
			'title'       => $title_hit,
			'description' => self::present( $ctx['desc'], $forms, $core ),
			'slug'        => self::slug_present( $ctx['slug'], $stems, $core ),
			'intro'       => self::present( self::intro_stems( $ctx['paragraphs'] ), $forms, $core ),
			'headings'    => 'none',
			'images'      => 'none',
			'links'       => 'none',
			'body'        => $occurrences > 0 ? 'exact' : self::present( $ctx['hay'], $forms, $core ),
		);

		$heading_hits = 0;
		foreach ( $ctx['headings'] as $h ) {
			if ( 'none' !== self::present( self::stems_of( (string) $h['text'] ), $forms, $core ) ) {
				++$heading_hits; }
		}
		$heading_total = count( $ctx['headings'] );
		if ( $heading_hits ) {
			$place['headings'] = 'exact'; }

		$alt_hits   = 0;
		$alt_exact  = 0;
		$alt_values = array();
		foreach ( $ctx['images'] as $img ) {
			$alt = trim( (string) $img['alt'] );
			if ( '' !== $alt ) {
				$alt_values[] = self::lower( $alt ); }
			if ( 'none' !== self::present( self::stems_of( $alt ), $forms, $core ) ) {
				++$alt_hits; }
			if ( '' !== $alt && self::stems_of( $alt ) === $stems ) {
				++$alt_exact; }
		}
		if ( $alt_hits ) {
			$place['images'] = 'exact'; }

		$internal      = 0;
		$anchor_hits   = 0;
		$anchor_exact  = 0;
		foreach ( $ctx['links'] as $link ) {
			if ( empty( $link['internal'] ) ) {
				continue; }
			++$internal;
			$anchor = self::stems_of( (string) $link['text'] );
			if ( 'none' !== self::present( $anchor, $forms, $core ) ) {
				++$anchor_hits; }
			if ( $anchor === $stems ) {
				++$anchor_exact; }
		}
		if ( $anchor_hits ) {
			$place['links'] = 'exact'; }

		if ( ! $heading_total ) {
			unset( $place['headings'] ); }
		if ( ! $ctx['images'] ) {
			unset( $place['images'] ); }
		if ( ! $internal ) {
			unset( $place['links'] ); }

		/* Natural-use analysis. */
		$use = self::usage( $ctx['sentences'], $forms );

		$stuffing_reasons = array();
		if ( $density > 3.5 && $occurrences >= 5 ) {
			/* translators: %s: keyword density percentage. */
			$stuffing_reasons[] = sprintf( __( 'the phrase makes up %s%% of the text', 'publishrank' ), number_format_i18n( $density, 1 ) );
		}
		if ( $use['repeat_in_sentence'] ) {
			$stuffing_reasons[] = __( 'it repeats inside single sentences', 'publishrank' ); }
		if ( $use['consecutive'] ) {
			$stuffing_reasons[] = __( 'it appears in three or more sentences in a row', 'publishrank' ); }
		if ( $use['keyword_list'] ) {
			$stuffing_reasons[] = __( 'it sits in a comma-separated list of keywords', 'publishrank' ); }
		if ( $alt_hits >= 3 && $alt_exact >= 3 && count( array_unique( $alt_values ) ) <= 1 ) {
			$stuffing_reasons[] = __( 'the same alt text repeats on several images', 'publishrank' ); }
		if ( $heading_total >= 4 && $heading_hits / $heading_total > 0.6 ) {
			$stuffing_reasons[] = __( 'most headings contain it', 'publishrank' ); }
		if ( $internal >= 3 && $anchor_exact / $internal > 0.5 ) {
			$stuffing_reasons[] = __( 'most internal link anchors are this exact phrase', 'publishrank' ); }

		$natural = 'unknown';
		if ( $occurrences >= 1 ) {
			$natural = 'natural';
			if ( $use['mechanical'] || $use['forced_start'] ) {
				$natural = 'repetitive'; }
			if ( $stuffing_reasons ) {
				$natural = 'stuffed'; }
		}

		/* Checks. */
		$checks = array();
		$add    = static function ( $id, $status, $weight, $message, $tip = '' ) use ( &$checks ) {
			$checks[] = array(
				'id'      => $id,
				'status'  => $status,
				'weight'  => (int) $weight,
				'message' => $message,
				'tip'     => $tip,
			);
		};

		$stat = static function ( $hit ) {
			return 'exact' === $hit ? 'good' : ( 'loose' === $hit ? 'ok' : 'bad' );
		};

		$n = count( $stems );
		if ( $is_primary ) {
			$add( 'length', ( $n >= 1 && $n <= 4 ) ? 'good' : ( $n <= 6 ? 'ok' : 'bad' ), 1,
				( $n <= 4 ) ? __( 'The phrase length is focused.', 'publishrank' ) : __( 'The phrase is long. Shorter phrases are easier to use naturally.', 'publishrank' ),
				( $n <= 4 ) ? '' : __( 'Shorten the focus keyword to the core topic, then add the rest as secondary keyphrases.', 'publishrank' )
			);
		}

		$add( 'title', ( ! $is_primary && 'none' === $place['title'] ) ? 'info' : $stat( $place['title'] ), $is_primary ? 3 : 1,
			self::placement_message( 'title', $place['title'] ),
			self::placement_tip( 'title', $place['title'], $is_primary )
		);
		$add( 'description', '' === trim( implode( '', $ctx['desc'] ) ) ? 'info' : $stat( $place['description'] ), $is_primary ? 2 : 1,
			self::placement_message( 'description', $place['description'] ),
			self::placement_tip( 'description', $place['description'], $is_primary )
		);
		if ( $is_primary ) {
			$add( 'slug', '' === trim( implode( '', $ctx['slug'] ) ) ? 'info' : ( 'none' === $place['slug'] ? 'ok' : $stat( $place['slug'] ) ), 1,
				self::placement_message( 'slug', $place['slug'] ),
				self::placement_tip( 'slug', $place['slug'], true )
			);
		}
		$add( 'intro', $ctx['paragraphs'] ? $stat( $place['intro'] ) : 'info', $is_primary ? 2 : 1,
			self::placement_message( 'intro', $place['intro'] ),
			self::placement_tip( 'intro', $place['intro'], $is_primary )
		);

		if ( $heading_total ) {
			$add( 'headings', $heading_hits ? 'good' : ( $is_primary ? 'ok' : 'bad' ), $is_primary ? 1 : 2,
				$heading_hits ? __( 'A subheading reflects this phrase.', 'publishrank' ) : __( 'No subheading reflects this phrase.', 'publishrank' ),
				$heading_hits ? '' : __( 'If a section is genuinely about this topic, say so in its subheading. Do not add the phrase to unrelated headings.', 'publishrank' )
			);
		}

		if ( $ctx['word_count'] < 100 ) {
			$add( 'body', 'info', 0, __( 'The text is too short to judge how the phrase is used.', 'publishrank' ), __( 'Write more of the article before relying on the keyphrase checks.', 'publishrank' ) );
		} elseif ( 0 === $occurrences ) {
			$add( 'body', 'loose' === $place['body'] ? 'ok' : 'bad', $is_primary ? 2 : 3,
				'loose' === $place['body'] ? __( 'The words appear, but never as the phrase itself.', 'publishrank' ) : __( 'The phrase does not appear in the text.', 'publishrank' ),
				__( 'Cover this topic in a section of the article and name it plainly where it fits.', 'publishrank' )
			);
		} else {
			$low = $density < 0.3 && $ctx['word_count'] >= 400;
			/* translators: 1: number of times the phrase is used, 2: density percentage. */
			$add( 'body', $low ? 'ok' : 'good', $is_primary ? 2 : 3, sprintf( _n( 'The phrase is used %1$d time (%2$s%% of the text).', 'The phrase is used %1$d times (%2$s%% of the text).', $occurrences, 'publishrank' ), $occurrences, number_format_i18n( $density, 1 ) ),
				$low ? __( 'The phrase is mentioned very lightly for an article this long. Use it where the sentence needs it, not more.', 'publishrank' ) : ''
			);

			if ( $ctx['word_count'] >= 300 && $occurrences >= 3 ) {
				$spread = self::spread( $ctx['hay'], $forms, 4 );
				$add( 'distribution', $spread >= 3 ? 'good' : ( 2 === $spread ? 'ok' : 'bad' ), 1,
					/* translators: %d: number of article quarters containing the phrase. */
					sprintf( __( 'The phrase appears in %d of 4 parts of the article.', 'publishrank' ), $spread ),
					$spread >= 3 ? '' : __( 'The phrase is bunched together. Check that later sections still connect back to this topic.', 'publishrank' )
				);
			}
		}

		if ( $ctx['images'] ) {
			$add( 'images', $alt_hits ? 'good' : 'info', $is_primary ? 1 : 0,
				$alt_hits ? __( 'An image alt text describes this topic.', 'publishrank' ) : __( 'No image alt text mentions this phrase.', 'publishrank' ),
				$alt_hits ? '' : __( 'Only where an image truly shows the topic, describe it in the alt text. Alt text is for people who cannot see the image.', 'publishrank' )
			);
		}

		if ( $internal ) {
			$add( 'links', $anchor_hits ? 'good' : 'info', 0,
				$anchor_hits ? __( 'An internal link uses this phrase or a variant as its anchor.', 'publishrank' ) : __( 'No internal link anchor uses this phrase.', 'publishrank' ),
				$anchor_hits ? '' : __( 'When you link to a related article, a descriptive anchor that includes the topic helps readers and crawlers.', 'publishrank' )
			);
		}

		if ( $occurrences >= 1 ) {
			if ( $stuffing_reasons ) {
				/* translators: %s: list of reasons separated by commas. */
				$add( 'stuffing', 'bad', 3, sprintf( __( 'This looks like keyword stuffing: %s.', 'publishrank' ), implode( '; ', $stuffing_reasons ) ),
					__( 'Rewrite the repeated spots with pronouns, synonyms or simply the next point. Read it aloud; it should sound like a person wrote it.', 'publishrank' ) );
			} elseif ( $density > 2.5 && $occurrences >= 5 ) {
				$add( 'stuffing', 'ok', 3, __( 'The phrase is used often. That is fine if it reads naturally.', 'publishrank' ), __( 'Replace a few mentions with synonyms or a pronoun.', 'publishrank' ) );
			} else {
				$add( 'stuffing', 'good', 3, __( 'No keyword stuffing detected.', 'publishrank' ) );
			}
			if ( 'repetitive' === $natural ) {
				$add( 'natural', 'ok', 2, __( 'The phrase is used in the same pattern each time.', 'publishrank' ), __( 'Vary the sentences around it, or use a synonym or a related term.', 'publishrank' ) );
			} elseif ( 'natural' === $natural ) {
				$add( 'natural', 'good', 2, __( 'The phrase fits naturally into the text.', 'publishrank' ) );
			}
		}

		/* Score. */
		$earned = 0.0;
		$max    = 0.0;
		foreach ( $checks as $c ) {
			if ( 'info' === $c['status'] || $c['weight'] <= 0 ) {
				continue; }
			$max    += $c['weight'];
			$earned += 'good' === $c['status'] ? $c['weight'] : ( 'ok' === $c['status'] ? $c['weight'] * 0.5 : 0.0 );
		}
		$score = $max > 0 ? (int) round( $earned / $max * 100 ) : 0;

		$recs = array();
		foreach ( $checks as $c ) {
			if ( in_array( $c['status'], array( 'bad', 'ok' ), true ) && '' !== $c['tip'] ) {
				$recs[] = array(
					'status'   => $c['status'],
					'weight'   => $c['weight'],
					'text'     => $c['tip'],
				);
			}
		}
		usort(
			$recs,
			static function ( $a, $b ) {
				$sa = 'bad' === $a['status'] ? 1 : 0;
				$sb = 'bad' === $b['status'] ? 1 : 0;
				return $sb <=> $sa ?: $b['weight'] <=> $a['weight'];
			}
		);
		$recs = array_map(
			static function ( $r ) {
				return $r['text'];
			},
			$recs
		);

		if ( $is_primary && $occurrences >= 6 && ! array_sum( $syn_counts ) ) {
			$recs[] = __( 'You use this phrase often. Add synonyms in the settings and vary your wording with them.', 'publishrank' );
		}

		$card = array(
			'phrase'          => $phrase,
			'role'            => $role,
			'score'           => $score,
			'rating'          => $score >= 80 ? 'good' : ( $score >= 55 ? 'ok' : 'bad' ),
			'occurrences'     => $occurrences,
			'density'         => $density,
			'placements'      => $place,
			'natural'         => $natural,
			'stuffing'        => $stuffing_reasons,
			'checks'          => $checks,
			'recommendations' => array_slice( $recs, 0, 5 ),
			'intent'          => self::intent( $phrase ),
			'synonyms'        => array(),
			'related'         => array(),
		);
		foreach ( $syn_counts as $syn => $count ) {
			$card['synonyms'][] = array(
				'term'  => $syn,
				'count' => (int) $count,
			);
		}
		foreach ( $related as $term ) {
			$term = trim( (string) $term );
			$rs   = self::stems_of( $term );
			if ( '' === $term || ! $rs ) {
				continue; }
			$card['related'][] = array(
				'term'  => $term,
				'found' => 'none' !== self::present( $ctx['hay'], array( $rs ), self::core( $rs ) ),
			);
		}
		return $card;
	}

	/* ------------------------------------------------------------------ *
	 * Natural-use detection
	 * ------------------------------------------------------------------ */

	/** @param array<int,array<int,string>> $sentences Stemmed sentences. @param array<int,array<int,string>> $forms Phrase forms. */
	private static function usage( array $sentences, array $forms ) {
		$out = array(
			'repeat_in_sentence' => false,
			'consecutive'        => false,
			'keyword_list'       => false,
			'mechanical'         => false,
			'forced_start'       => false,
		);
		$run      = 0;
		$matches  = 0;
		$starts   = 0;
		$contexts = array();
		foreach ( $sentences as $s ) {
			$tokens = $s['stems'];
			$c      = self::count_forms( $tokens, $forms );
			if ( $c > 0 ) {
				++$matches;
				++$run;
				if ( $c >= 2 ) {
					$out['repeat_in_sentence'] = true; }
				if ( $run >= 3 ) {
					$out['consecutive'] = true; }
				$pos = self::first_position( $tokens, $forms );
				if ( 0 === $pos ) {
					++$starts; }
				$contexts[] = implode( ' ', array_slice( $tokens, max( 0, $pos - 2 ), min( 2, $pos ) ) );
				if ( self::is_keyword_list( $s['raw'] ) ) {
					$out['keyword_list'] = true; }
			} else {
				$run = 0;
			}
		}
		if ( $matches >= 4 ) {
			if ( count( array_unique( $contexts ) ) / $matches < 0.5 ) {
				$out['mechanical'] = true; }
			if ( $starts / $matches > 0.4 ) {
				$out['forced_start'] = true; }
		}
		return $out;
	}

	/** A sentence that is mostly short comma-separated fragments ("best shoes, cheap shoes, buy shoes, shoes online"). */
	private static function is_keyword_list( $raw ) {
		$parts = preg_split( '/\s*,\s*/u', (string) $raw );
		if ( ! is_array( $parts ) || count( $parts ) < 4 ) {
			return false; }
		$short = 0;
		foreach ( $parts as $p ) {
			if ( count( ArticleIntelligence::tokens( $p ) ) <= 3 ) {
				++$short; }
		}
		return $short / count( $parts ) >= 0.75;
	}

	/* ------------------------------------------------------------------ *
	 * Search intent (local cue words)
	 * ------------------------------------------------------------------ */

	/** @return array<string,string> type, label, advice. type "unknown" when the phrase has no clear cue. */
	public static function intent( $phrase ) {
		$text   = ' ' . implode( ' ', ArticleIntelligence::tokens( (string) $phrase ) ) . ' ';
		$cues   = array(
			'transactional' => array( 'buy', 'price', 'prices', 'pricing', 'cheap', 'discount', 'coupon', 'deal', 'deals', 'order', 'purchase', 'subscribe', 'hire', 'quote', 'for sale', 'near me', 'خریدیں', 'قیمت', 'سستا', 'سعر', 'شراء' ),
			'commercial'    => array( 'best', 'top', 'review', 'reviews', 'vs', 'versus', 'compare', 'comparison', 'alternative', 'alternatives', 'ranking', 'بہترین', 'موازنہ', 'جائزہ', 'أفضل' ),
			'navigational'  => array( 'login', 'log in', 'sign in', 'official', 'homepage', 'contact', 'portal' ),
			'informational' => array( 'how', 'what', 'why', 'when', 'who', 'where', 'guide', 'tutorial', 'tips', 'learn', 'meaning', 'definition', 'examples', 'ideas', 'steps', 'explained', 'difference between', 'کیا', 'کیسے', 'کیوں', 'کب', 'کون', 'کہاں', 'طریقہ', 'كيف', 'لماذا' ),
		);
		$found = array();
		foreach ( $cues as $type => $words ) {
			foreach ( $words as $w ) {
				if ( false !== strpos( $text, ' ' . $w . ' ' ) ) {
					$found[ $type ] = true;
					break;
				}
			}
		}
		$labels = array(
			'informational' => __( 'Informational: readers want an answer or an explanation.', 'publishrank' ),
			'commercial'    => __( 'Commercial research: readers are comparing options before they decide.', 'publishrank' ),
			'transactional' => __( 'Transactional: readers are ready to buy or sign up.', 'publishrank' ),
			'navigational'  => __( 'Navigational: readers are looking for a specific site or page.', 'publishrank' ),
		);
		$advice = array(
			'informational' => __( 'Answer the question early, then use clear subheadings for the details.', 'publishrank' ),
			'commercial'    => __( 'Compare options fairly with criteria, pros and cons, and who each option suits.', 'publishrank' ),
			'transactional' => __( 'Make price, availability and the next step easy to find.', 'publishrank' ),
			'navigational'  => __( 'Make sure the page clearly shows which site or page it is.', 'publishrank' ),
		);
		if ( ! $found ) {
			return array(
				'type'   => 'unknown',
				'label'  => __( 'No clear intent signal in the phrase itself.', 'publishrank' ),
				'advice' => '',
			);
		}
		// Priority when several cues appear: transactional, commercial, navigational, informational.
		foreach ( array( 'transactional', 'commercial', 'navigational', 'informational' ) as $type ) {
			if ( isset( $found[ $type ] ) ) {
				return array(
					'type'   => $type,
					'label'  => $labels[ $type ],
					'advice' => $advice[ $type ],
				);
			}
		}
		return array(
			'type'   => 'unknown',
			'label'  => '',
			'advice' => '',
		);
	}

	/* ------------------------------------------------------------------ *
	 * Duplicates and site-wide overlap
	 * ------------------------------------------------------------------ */

	/** @return array<int,string> Distinct secondary phrases; adds warnings for duplicates and overlaps. */
	private static function dedupe_secondary( $primary, array $raw, array &$warnings ) {
		$seen = array();
		$p    = self::stems_of( $primary );
		if ( $p ) {
			$seen[ implode( ' ', $p ) ] = true; }
		$out = array();
		foreach ( $raw as $phrase ) {
			$phrase = trim( (string) $phrase );
			$stems  = self::stems_of( $phrase );
			if ( '' === $phrase || ! $stems ) {
				continue; }
			$key = implode( ' ', $stems );
			if ( isset( $seen[ $key ] ) ) {
				$warnings[] = array(
					'id'      => 'duplicate',
					/* translators: %s: keyphrase. */
					'message' => sprintf( __( '"%s" is listed more than once, or is the same as the focus keyword. It was analyzed once.', 'publishrank' ), $phrase ),
				);
				continue;
			}
			$seen[ $key ] = true;
			if ( $p && ( self::contains( $stems, $p ) || self::contains( $p, $stems ) ) ) {
				$warnings[] = array(
					'id'      => 'overlap',
					/* translators: %s: keyphrase. */
					'message' => sprintf( __( '"%s" overlaps with the focus keyword. Make sure it covers a different angle, not the same one.', 'publishrank' ), $phrase ),
				);
			}
			$out[] = $phrase;
			if ( count( $out ) >= self::MAX_SECONDARY ) {
				break; }
		}
		return $out;
	}

	/** Other published articles whose focus keyword equals one of these phrases. One query. @return array<string,int> */
	private static function used_elsewhere( array $phrases, $post_id ) {
		global $wpdb;
		if ( ! $phrases || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array(); }
		$types  = ArticleIntelligence::supported_post_types();
		$lowers = array();
		foreach ( $phrases as $p ) {
			$lowers[ self::lower( $p ) ] = $p; }
		$in_types   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$in_phrases = implode( ',', array_fill( 0, count( $lowers ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- the IN lists contain only generated %s placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(m.meta_value) AS phrase, COUNT(*) AS used FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND LOWER(m.meta_value) IN ($in_phrases) AND p.post_type IN ($in_types) AND p.post_status = 'publish' AND p.ID <> %d GROUP BY LOWER(m.meta_value)",
				array_merge( array( PostMeta::FOCUS_KEYWORD ), array_keys( $lowers ), $types, array( (int) $post_id ) )
			)
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $row ) {
			$key = (string) $row->phrase;
			if ( isset( $lowers[ $key ] ) && (int) $row->used > 0 ) {
				$out[ $lowers[ $key ] ] = (int) $row->used; }
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Matching helpers
	 * ------------------------------------------------------------------ */

	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/** @return array<int,string> */
	private static function stems_of( $text ) {
		return self::stem_tokens( ArticleIntelligence::tokens( (string) $text ) );
	}

	/** Light English stemming (plurals only). Other scripts are compared as written. @param array<int,string> $tokens @return array<int,string> */
	private static function stem_tokens( array $tokens ) {
		$out = array();
		foreach ( $tokens as $t ) {
			$t = str_replace( array( "'", '’' ), '', (string) $t );
			if ( strlen( $t ) > 3 && preg_match( '/^[a-z]+$/', $t ) ) {
				if ( 'ies' === substr( $t, -3 ) ) {
					$t = substr( $t, 0, -3 ) . 'y';
				} elseif ( 's' === substr( $t, -1 ) && 'ss' !== substr( $t, -2 ) ) {
					$t = substr( $t, 0, -1 );
				}
			}
			$out[] = $t;
		}
		return $out;
	}

	/** Words that do not identify a topic; ignored for loose matching. @param array<int,string> $stems @return array<int,string> */
	private static function core( array $stems ) {
		static $stop = array( 'a', 'an', 'the', 'of', 'in', 'on', 'to', 'for', 'and', 'or', 'with', 'at', 'by', 'is', 'are', 'your', 'my', 'how', 'what' );
		$core = array_values( array_diff( array_unique( $stems ), $stop ) );
		return $core ? $core : array_values( array_unique( $stems ) );
	}

	private static function slug_stems( $slug ) {
		$slug = rawurldecode( (string) $slug );
		return self::stems_of( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/** @param array<int,array<int,string>> $forms */
	private static function count_forms( array $hay, array $forms ) {
		$total = 0;
		foreach ( $forms as $f ) {
			$total += self::count_phrase( $hay, $f ); }
		return $total;
	}

	private static function count_phrase( array $hay, array $needle ) {
		$n = count( $needle );
		$h = count( $hay );
		if ( 0 === $n || $h < $n ) {
			return 0; }
		$count = 0;
		for ( $i = 0; $i <= $h - $n; $i++ ) {
			if ( $hay[ $i ] === $needle[0] && array_slice( $hay, $i, $n ) === $needle ) {
				++$count;
				$i += $n - 1;
			}
		}
		return $count;
	}

	/** @param array<int,array<int,string>> $forms */
	private static function first_position( array $hay, array $forms ) {
		$best = PHP_INT_MAX;
		foreach ( $forms as $f ) {
			$n = count( $f );
			for ( $i = 0; $n > 0 && $i <= count( $hay ) - $n; $i++ ) {
				if ( array_slice( $hay, $i, $n ) === $f ) {
					$best = min( $best, $i );
					break;
				}
			}
		}
		return PHP_INT_MAX === $best ? 0 : $best;
	}

	/** True when $needle appears as a contiguous run inside $hay. */
	private static function contains( array $hay, array $needle ) {
		return count( $needle ) <= count( $hay ) && self::count_phrase( $hay, $needle ) > 0;
	}

	/**
	 * Does the text contain the phrase? "exact" for the phrase or a synonym, "loose" when every topic word
	 * appears within a small window, "none" otherwise.
	 *
	 * @param array<int,array<int,string>> $forms
	 * @param array<int,string>            $core
	 */
	private static function present( array $hay, array $forms, array $core ) {
		if ( ! $hay ) {
			return 'none'; }
		if ( self::count_forms( $hay, $forms ) > 0 ) {
			return 'exact'; }
		$need = count( $core );
		if ( $need < 2 ) {
			return 'none'; }
		$window = max( 6, $need + 4 );
		$h      = count( $hay );
		for ( $i = 0; $i < $h; $i++ ) {
			if ( ! in_array( $hay[ $i ], $core, true ) ) {
				continue; }
			$slice = array_slice( $hay, $i, $window );
			if ( ! array_diff( $core, $slice ) ) {
				return 'loose'; }
		}
		return 'none';
	}

	private static function slug_present( array $slug, array $stems, array $core ) {
		if ( ! $slug ) {
			return 'none'; }
		if ( self::contains( $slug, $stems ) ) {
			return 'exact'; }
		return ! array_diff( $core, $slug ) ? 'loose' : 'none';
	}

	private static function best( $a, $b ) {
		$rank = array(
			'none'  => 0,
			'loose' => 1,
			'exact' => 2,
		);
		return $rank[ $a ] >= $rank[ $b ] ? $a : $b;
	}

	/** Stems of the opening: the first paragraph, plus the second when the first is very short. @param array<int,string> $paragraphs @return array<int,string> */
	private static function intro_stems( array $paragraphs ) {
		if ( ! $paragraphs ) {
			return array(); }
		$text = (string) $paragraphs[0];
		if ( count( ArticleIntelligence::tokens( $text ) ) < 12 && isset( $paragraphs[1] ) ) {
			$text .= ' ' . $paragraphs[1]; }
		return array_slice( self::stems_of( $text ), 0, 120 );
	}

	/** @param array<int,string> $paragraphs @return array<int,array<string,mixed>> */
	private static function sentence_stems( array $paragraphs ) {
		$out = array();
		foreach ( $paragraphs as $p ) {
			foreach ( (array) preg_split( '/(?<=[.!?۔؟])\s+/u', (string) $p, -1, PREG_SPLIT_NO_EMPTY ) as $s ) {
				$stems = self::stems_of( $s );
				if ( count( $stems ) >= 2 ) {
					$out[] = array(
						'stems' => $stems,
						'raw'   => $s,
					);
				}
				if ( count( $out ) >= 1500 ) {
					return $out; }
			}
		}
		return $out;
	}

	/** In how many of $parts equal slices of the text does the phrase appear? */
	private static function spread( array $hay, array $forms, $parts ) {
		$h = count( $hay );
		if ( $h < $parts ) {
			return 0; }
		$size  = (int) ceil( $h / $parts );
		$found = 0;
		for ( $i = 0; $i < $parts; $i++ ) {
			$slice = array_slice( $hay, $i * $size, $size );
			if ( self::count_forms( $slice, $forms ) > 0 ) {
				++$found; }
		}
		return $found;
	}

	/* ------------------------------------------------------------------ *
	 * Messages
	 * ------------------------------------------------------------------ */

	private static function placement_message( $where, $hit ) {
		$map = array(
			'title'       => array( __( 'The phrase is in the title.', 'publishrank' ), __( 'The words appear in the title, but not as the phrase.', 'publishrank' ), __( 'The phrase is not in the title.', 'publishrank' ) ),
			'description' => array( __( 'The phrase is in the meta description.', 'publishrank' ), __( 'The words appear in the meta description, but not as the phrase.', 'publishrank' ), __( 'The phrase is not in the meta description.', 'publishrank' ) ),
			'slug'        => array( __( 'The phrase is in the URL slug.', 'publishrank' ), __( 'The words appear in the URL slug, but not as the phrase.', 'publishrank' ), __( 'The phrase is not in the URL slug.', 'publishrank' ) ),
			'intro'       => array( __( 'The phrase is in the opening paragraph.', 'publishrank' ), __( 'The words appear in the opening paragraph, but not as the phrase.', 'publishrank' ), __( 'The phrase is not in the opening paragraph.', 'publishrank' ) ),
		);
		$i = 'exact' === $hit ? 0 : ( 'loose' === $hit ? 1 : 2 );
		return $map[ $where ][ $i ];
	}

	private static function placement_tip( $where, $hit, $is_primary ) {
		if ( 'exact' === $hit ) {
			return ''; }
		$tips = array(
			'title'       => $is_primary ? __( 'Work the phrase into the title, ideally near the start, without making it awkward.', 'publishrank' ) : __( 'A secondary phrase does not have to be in the title. Add it only if the title still reads well.', 'publishrank' ),
			'description' => __( 'Mention the topic in the meta description so searchers see it echoed in the result.', 'publishrank' ),
			'slug'        => __( 'Use the main words of the phrase in the URL slug. Change a published URL only with a redirect in place.', 'publishrank' ),
			'intro'       => __( 'State the topic in the first paragraph so readers know right away they are in the right place.', 'publishrank' ),
		);
		return $tips[ $where ];
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	/** Scorecards for the full report. Every value is escaped here. */
	public static function render( array $result, $article_score = null ) {
		if ( empty( $result['cards'] ) ) {
			return ''; }
		$status_text = array(
			'good' => __( 'Good', 'publishrank' ),
			'ok'   => __( 'Needs improvement', 'publishrank' ),
			'bad'  => __( 'Needs work', 'publishrank' ),
			'info' => __( 'Note', 'publishrank' ),
		);
		$where_label = array(
			'title'       => __( 'Title', 'publishrank' ),
			'description' => __( 'Meta description', 'publishrank' ),
			'slug'        => __( 'URL slug', 'publishrank' ),
			'intro'       => __( 'First paragraph', 'publishrank' ),
			'headings'    => __( 'Headings', 'publishrank' ),
			'body'        => __( 'Content', 'publishrank' ),
			'images'      => __( 'Image alt text', 'publishrank' ),
			'links'       => __( 'Internal link anchors', 'publishrank' ),
		);
		$hit_text    = array(
			'exact' => __( 'found', 'publishrank' ),
			'loose' => __( 'partly', 'publishrank' ),
			'none'  => __( 'not found', 'publishrank' ),
		);

		$out  = '<section class="pr-kp" aria-labelledby="pr-kp-title"><h3 id="pr-kp-title" class="pr-kp__title">' . esc_html__( 'Keyphrase scorecards', 'publishrank' ) . '</h3>';
		$sec  = isset( $result['secondary_average'] ) ? $result['secondary_average'] : null;
		if ( null !== $article_score && null !== $sec ) {
			$out .= '<p class="pr-kp__combined">' . esc_html(
				sprintf(
					/* translators: 1: combined score, 2: article score, 3: average secondary score. */
					__( 'Combined score %1$d. The article score (%2$d) counts for 80%% and the average of your secondary keyphrases (%3$d) for 20%%. The focus keyword stays the main target.', 'publishrank' ),
					self::combine( $article_score, $result ),
					(int) $article_score,
					(int) $sec
				)
			) . '</p>';
		}
		foreach ( (array) $result['warnings'] as $w ) {
			$out .= '<p class="pr-kp__warn" role="note"><strong>' . esc_html__( 'Check:', 'publishrank' ) . '</strong> ' . esc_html( (string) $w['message'] ) . '</p>';
		}
		foreach ( $result['cards'] as $card ) {
			$role = 'primary' === $card['role'] ? __( 'Focus keyword', 'publishrank' ) : __( 'Secondary', 'publishrank' );
			$out .= '<article class="pr-kp__card pr-kp__card--' . esc_attr( $card['rating'] ) . '">';
			$out .= '<header class="pr-kp__head"><span class="pr-kp__phrase">' . esc_html( $card['phrase'] ) . '</span><span class="pr-kp__role">' . esc_html( $role ) . '</span>';
			$out .= '<span class="pr-kp__score" aria-label="' . esc_attr( sprintf( /* translators: 1: score, 2: rating. */ __( 'Score %1$d out of 100, %2$s', 'publishrank' ), (int) $card['score'], $status_text[ $card['rating'] ] ) ) . '">' . (int) $card['score'] . '<small>/100</small></span></header>';
			$out .= '<ul class="pr-kp__places">';
			foreach ( $where_label as $key => $label ) {
				if ( ! isset( $card['placements'][ $key ] ) ) {
					continue; }
				$hit  = $card['placements'][ $key ];
				$out .= '<li class="pr-kp__place pr-kp__place--' . esc_attr( $hit ) . '"><span>' . esc_html( $label ) . '</span> <b>' . esc_html( $hit_text[ $hit ] ) . '</b></li>';
			}
			$out .= '</ul>';
			if ( ! empty( $card['occurrences'] ) ) {
				$out .= '<p class="pr-kp__meta">' . esc_html( sprintf( /* translators: 1: count, 2: density. */ _n( 'Used %1$d time (%2$s%% of the text).', 'Used %1$d times (%2$s%% of the text).', (int) $card['occurrences'], 'publishrank' ), (int) $card['occurrences'], number_format_i18n( (float) $card['density'], 1 ) ) ) . '</p>';
			}
			if ( ! empty( $card['intent']['label'] ) && 'unknown' !== $card['intent']['type'] ) {
				$out .= '<p class="pr-kp__meta"><strong>' . esc_html__( 'Search intent:', 'publishrank' ) . '</strong> ' . esc_html( $card['intent']['label'] ) . ' ' . esc_html( $card['intent']['advice'] ) . '</p>';
			}
			if ( ! empty( $card['synonyms'] ) ) {
				$parts = array();
				foreach ( $card['synonyms'] as $s ) {
					$parts[] = $s['term'] . ' (' . (int) $s['count'] . ')'; }
				$out .= '<p class="pr-kp__meta"><strong>' . esc_html__( 'Synonyms used:', 'publishrank' ) . '</strong> ' . esc_html( implode( ', ', $parts ) ) . '</p>';
			}
			if ( ! empty( $card['related'] ) ) {
				$missing = array();
				foreach ( $card['related'] as $r ) {
					if ( empty( $r['found'] ) ) {
						$missing[] = $r['term']; }
				}
				$out .= '<p class="pr-kp__meta"><strong>' . esc_html__( 'Related keyphrases:', 'publishrank' ) . '</strong> ' . esc_html( $missing ? sprintf( /* translators: %s: missing terms. */ __( 'not yet mentioned: %s', 'publishrank' ), implode( ', ', $missing ) ) : __( 'all are mentioned.', 'publishrank' ) ) . '</p>';
			}
			if ( ! empty( $card['recommendations'] ) ) {
				$out .= '<ul class="pr-kp__recs">';
				foreach ( $card['recommendations'] as $r ) {
					$out .= '<li>' . esc_html( $r ) . '</li>'; }
				$out .= '</ul>';
			}
			$out .= '</article>';
		}
		return $out . '</section>';
	}
}
