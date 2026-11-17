<?php
/**
 * Advanced SEO & trust checks (2.25).
 *
 * Runs inside ArticleIntelligence::analyze(), so every PublishRank surface (editor panel, toolbar badge,
 * post list, SEO Score page, REST) keeps reading one authoritative score. Pure text analysis of the draft:
 * no database queries, so it is safe to run on every keystroke and on bulk audits of millions of posts.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class AdvancedChecks {
	const GROUP = 'advanced';

	/** @return array<string,string> check id => title */
	public static function titles() {
		return array(
			'adv_slug'           => __( 'URL slug quality', 'publishrank' ),
			'adv_title_year'     => __( 'Year in headline', 'publishrank' ),
			'adv_description_echo' => __( 'Meta description adds information', 'publishrank' ),
			'adv_snippet_ready'  => __( 'Featured snippet readiness', 'publishrank' ),
			'adv_lists'          => __( 'Lists and tables in long articles', 'publishrank' ),
			'adv_media_ratio'    => __( 'Images for article length', 'publishrank' ),
			'adv_empty_links'    => __( 'Empty or placeholder links', 'publishrank' ),
			'adv_mixed_content'  => __( 'Insecure (http://) media and links', 'publishrank' ),
			'adv_sponsored'      => __( 'Affiliate links marked sponsored', 'publishrank' ),
			'adv_attribution'    => __( 'Quotes are attributed', 'publishrank' ),
			'adv_heading_length' => __( 'Subheading length', 'publishrank' ),
			'adv_heading_repeat' => __( 'Repeated subheadings', 'publishrank' ),
			'adv_alt_quality'    => __( 'Descriptive alt text (not file names)', 'publishrank' ),
			'adv_reading_time'   => __( 'Reading time', 'publishrank' ),
			'adv_numbers'        => __( 'Specific facts and figures', 'publishrank' ),
		);
	}

	/** @return array<string,string> */
	public static function why() {
		return array(
			'adv_slug'           => __( 'Short, readable URLs are easier to share and show cleanly in results.', 'publishrank' ),
			'adv_title_year'     => __( 'An outdated year in a headline signals stale content and lowers clicks.', 'publishrank' ),
			'adv_snippet_ready'  => __( 'A question subheading followed by a concise 40–60 word answer is the format search engines lift into featured snippets and AI answers.', 'publishrank' ),
			'adv_empty_links'    => __( 'Placeholder links frustrate readers and waste crawl budget.', 'publishrank' ),
			'adv_mixed_content'  => __( 'Browsers block or warn about insecure resources on HTTPS pages.', 'publishrank' ),
			'adv_sponsored'      => __( 'Google requires paid and affiliate links to carry rel="sponsored" (or nofollow).', 'publishrank' ),
			'adv_attribution'    => __( 'Attributed quotes are a core trust (E-E-A-T) signal for news and expert content.', 'publishrank' ),
			'adv_alt_quality'    => __( 'File names like IMG_1234.jpg tell screen readers and image search nothing.', 'publishrank' ),
		);
	}

	private static function words( $text ) {
		return preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * @param array<string,mixed> $c Context from analyze(): html, paragraphs, headings, images, word_count,
	 *                               title, description, written_description, slug, lang, home.
	 * @return array<int,array<string,mixed>>
	 */
	public static function run( array $c ) {
		$out = array();
		$add = static function ( $id, $status, $weight, $message ) use ( &$out ) {
			$out[] = array( 'id' => $id, 'group' => self::GROUP, 'status' => $status, 'weight' => (int) $weight, 'message' => $message );
		};
		$html  = (string) $c['html'];
		$wc    = (int) $c['word_count'];
		$title = (string) $c['title'];

		// Slug.
		$slug = (string) $c['slug'];
		if ( '' !== $slug && 0 !== strpos( $slug, 'auto-draft' ) ) {
			$plain = rawurldecode( $slug );
			$parts = array_filter( explode( '-', $plain ) );
			$issue = '';
			if ( false !== strpos( $plain, '_' ) ) {
				$issue = __( 'Use hyphens instead of underscores in the URL.', 'publishrank' );
			} elseif ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $plain ) : strlen( $plain ) ) > 75 || count( $parts ) > 9 ) {
				/* translators: %d: words */
				$issue = sprintf( __( 'The URL slug has %d words; trim it to the 3–6 words that name the story.', 'publishrank' ), count( $parts ) );
			} elseif ( preg_match( '/^\d+(-\d+)*$/', $plain ) ) {
				$issue = __( 'The URL slug is only numbers; use words that describe the story.', 'publishrank' );
			}
			$add( 'adv_slug', '' === $issue ? 'good' : 'ok', 1, '' === $issue ? __( 'The URL slug is short and readable.', 'publishrank' ) : $issue );
		}

		// Outdated year in the headline.
		if ( preg_match_all( '/\b(19[89]\d|20\d\d)\b/', $title, $ym ) ) {
			$now  = (int) gmdate( 'Y' );
			$past = array_filter(
				array_map( 'intval', $ym[1] ),
				static function ( $y ) use ( $now ) {
					return $y >= $now - 3 && $y < $now; // Recent past years read as "not updated"; historical years are fine.
				}
			);
			if ( $past ) {
				/* translators: 1: year in title, 2: current year */
				$add( 'adv_title_year', 'ok', 1, sprintf( __( 'The headline mentions %1$d. If the article is meant to be current, update it for %2$d.', 'publishrank' ), max( $past ), $now ) );
			}
		}

		// Description echoes the title.
		$desc = trim( (string) $c['written_description'] );
		if ( '' !== $desc && '' !== $title ) {
			$a    = strtolower( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $title ) );
			$b    = strtolower( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $desc ) );
			$echo = trim( $a ) === trim( $b ) || ( strlen( trim( $a ) ) > 20 && 0 === strpos( trim( $b ), trim( $a ) ) && strlen( $b ) < strlen( $a ) + 30 );
			$add( 'adv_description_echo', $echo ? 'ok' : 'good', 1, $echo ? __( 'The meta description repeats the headline; use it to add the key fact or why it matters.', 'publishrank' ) : __( 'The meta description adds information beyond the headline.', 'publishrank' ) );
		}

		// Featured-snippet readiness: a question subheading followed by a 40–60 word answer.
		if ( $wc >= 300 ) {
			$sections = preg_split( '#(<h[2-4]\b[^>]*>.*?</h[2-4]>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			$question = 0;
			$ready    = 0;
			$q_re     = '/(\?|？)\s*$|^(who|what|when|where|why|how|which|can|is|are|does|do|should|will)\b/iu';
			for ( $i = 0, $n = count( $sections ); $i < $n; $i++ ) {
				if ( ! preg_match( '#^<h[2-4]#i', $sections[ $i ] ) ) {
					continue;
				}
				$h = trim( html_entity_decode( wp_strip_all_tags( $sections[ $i ] ), ENT_QUOTES, 'UTF-8' ) );
				if ( ! preg_match( $q_re, $h ) ) {
					continue;
				}
				++$question;
				if ( isset( $sections[ $i + 1 ] ) && preg_match( '#<p\b[^>]*>(.*?)</p>#is', $sections[ $i + 1 ], $pm ) ) {
					$len = count( self::words( wp_strip_all_tags( $pm[1] ) ) );
					if ( $len >= 30 && $len <= 70 ) {
						++$ready;
					}
				}
			}
			if ( $ready ) {
				/* translators: %d: count */
				$add( 'adv_snippet_ready', 'good', 1, sprintf( _n( '%d question subheading is answered in a concise paragraph, ready for featured snippets.', '%d question subheadings are answered in concise paragraphs, ready for featured snippets.', $ready, 'publishrank' ), $ready ) );
			} elseif ( $question ) {
				$add( 'adv_snippet_ready', 'ok', 1, __( 'Answer each question subheading in the first 40–60 words below it to qualify for featured snippets.', 'publishrank' ) );
			} else {
				$add( 'adv_snippet_ready', 'ok', 1, __( 'Phrase one subheading as the question readers ask (e.g. “What happens next?”) and answer it in 40–60 words.', 'publishrank' ) );
			}
		}

		// Lists or tables in long articles.
		if ( $wc >= 700 ) {
			$has = (bool) preg_match( '#<(ul|ol|table|dl)\b#i', $html );
			$add( 'adv_lists', $has ? 'good' : 'ok', 1, $has ? __( 'The article uses lists or tables for scannable facts.', 'publishrank' ) : __( 'Long article without a list or table; present steps, key facts or comparisons as a list or table.', 'publishrank' ) );
		}

		// Image ratio for long articles.
		if ( $wc >= 900 ) {
			$need = (int) floor( $wc / 900 );
			$have = count( (array) $c['images'] );
			/* translators: 1: images, 2: words */
			$add( 'adv_media_ratio', $have >= $need ? 'good' : 'ok', 1, $have >= $need ? sprintf( __( '%1$d images support %2$d words.', 'publishrank' ), $have, $wc ) : sprintf( __( 'Only %1$d images for %2$d words; add a chart, photo or illustration roughly every 900 words.', 'publishrank' ), $have, $wc ) );
		}

		// Anchors: empty/placeholder, insecure, affiliate without rel.
		$empty = 0;
		$http  = 0;
		$aff   = 0;
		if ( preg_match_all( '#<a\b([^>]*)>#i', $html, $am ) ) {
			foreach ( $am[1] as $attrs ) {
				$href = preg_match( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $hm ) ? trim( html_entity_decode( '' !== ( $hm[2] ?? '' ) ? $hm[2] : $hm[1], ENT_QUOTES, 'UTF-8' ) ) : null;
				if ( null === $href || '' === $href || '#' === $href || 0 === stripos( $href, 'javascript:' ) ) {
					++$empty;
					continue;
				}
				if ( 0 === stripos( $href, 'http://' ) ) {
					++$http;
				}
				$rel = preg_match( '/\brel\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $rm ) ? strtolower( ( $rm[2] ?? '' ) . ( $rm[1] ?? '' ) ) : '';
				if ( preg_match( '#(amzn\.to|amazon\.[a-z.]+/.*[?&]tag=|[?&](aff|affid|affiliate|ref_id|irclickid)=|shareasale\.com|awin1\.com|clickbank\.net|/go/|utm_medium=affiliate|rakuten|impact\.com|partnerize)#i', $href ) && ! preg_match( '/\b(sponsored|nofollow)\b/', $rel ) ) {
					++$aff;
				}
			}
		}
		if ( $empty ) {
			/* translators: %d: count */
			$add( 'adv_empty_links', 'bad', 1, sprintf( _n( '%d link has an empty or “#” address.', '%d links have an empty or “#” address.', $empty, 'publishrank' ), $empty ) );
		}
		if ( 0 === strpos( (string) $c['home'], 'https://' ) ) {
			$insecure_media = preg_match_all( '#<(img|source|video|audio|iframe)\b[^>]*\bsrc\s*=\s*["\']http://#i', $html );
			if ( $insecure_media || $http ) {
				/* translators: 1: media, 2: links */
				$add( 'adv_mixed_content', $insecure_media ? 'bad' : 'ok', 1, sprintf( __( '%1$d media files and %2$d links use http://; switch them to https://.', 'publishrank' ), (int) $insecure_media, $http ) );
			}
		}
		if ( $aff ) {
			/* translators: %d: count */
			$add( 'adv_sponsored', 'bad', 1, sprintf( _n( '%d affiliate link lacks rel="sponsored".', '%d affiliate links lack rel="sponsored".', $aff, 'publishrank' ), $aff ) );
		}

		// Quote attribution (English only).
		$text = implode( "\n", (array) $c['paragraphs'] );
		if ( 'en' === substr( (string) $c['lang'], 0, 2 ) && $wc >= 150 ) {
			$quotes = preg_match_all( '/[“"]([^”"]{25,})[”"]/u', $text );
			if ( $quotes ) {
				$attr = preg_match_all( '/\b(said|says|told|according to|stated|explained|added|wrote|reported|confirmed|noted)\b/i', $text );
				$add( 'adv_attribution', $attr >= 1 ? 'good' : 'ok', 1, $attr >= 1 ? __( 'Quotes are attributed to their sources.', 'publishrank' ) : __( 'The article quotes someone without saying who; attribute each quote (“…,” said …).', 'publishrank' ) );
			}
		}

		// Subheadings.
		$hs = array_filter(
			(array) $c['headings'],
			static function ( $h ) {
				return $h['level'] >= 2;
			}
		);
		if ( $hs ) {
			$long = 0;
			$seen = array();
			$dup  = 0;
			foreach ( $hs as $h ) {
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $h['text'] ) : strlen( $h['text'] );
				$long += $len > 70 ? 1 : 0;
				$key = strtolower( trim( $h['text'] ) );
				$dup += isset( $seen[ $key ] ) ? 1 : 0;
				$seen[ $key ] = true;
			}
			$add( 'adv_heading_length', $long ? 'ok' : 'good', 1, $long ? __( 'Some subheadings exceed 70 characters; keep them short labels readers can scan.', 'publishrank' ) : __( 'Subheadings are concise.', 'publishrank' ) );
			if ( $dup ) {
				$add( 'adv_heading_repeat', 'ok', 1, __( 'Two or more subheadings are identical; make each section heading distinct.', 'publishrank' ) );
			}
		}

		// Alt text that is a file name or generic.
		$weak = 0;
		foreach ( (array) $c['images'] as $img ) {
			$alt = strtolower( trim( (string) $img['alt'] ) );
			if ( '' !== $alt && preg_match( '/(\.(jpe?g|png|gif|webp|avif)$|^(img|dsc|pxl|screenshot|image|photo|picture|untitled)[\s_\-]*\d*$)/i', $alt ) ) {
				++$weak;
			}
		}
		if ( $weak ) {
			/* translators: %d: count */
			$add( 'adv_alt_quality', 'ok', 1, sprintf( _n( '%d image alt text is a file name or generic word; describe what the image shows.', '%d image alt texts are file names or generic words; describe what the images show.', $weak, 'publishrank' ), $weak ) );
		}

		// Specific facts: numbers signal concrete reporting.
		if ( $wc >= 300 ) {
			$nums = preg_match_all( '/\b\d[\d,.]*\s?(%|percent|million|billion|thousand)?\b/u', $text );
			$add( 'adv_numbers', $nums >= 2 ? 'good' : 'ok', 1, $nums >= 2 ? __( 'The article includes specific figures and data points.', 'publishrank' ) : __( 'Add specific figures (dates, amounts, percentages) to make the reporting concrete.', 'publishrank' ) );
		}

		if ( $wc > 0 ) {
			$min = max( 1, (int) round( $wc / 230 ) );
			/* translators: 1: minutes, 2: words */
			$add( 'adv_reading_time', 'info', 0, sprintf( _n( 'About %1$d minute to read (%2$d words).', 'About %1$d minutes to read (%2$d words).', $min, 'publishrank' ), $min, $wc ) );
		}
		return $out;
	}
}
