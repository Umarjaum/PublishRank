<?php
/**
 * Builds the consistent identifier map used by theme masking.
 *
 * Every theme identifier (slug, name, text domain, adapter prefixes) is mapped to a neutral, site-specific
 * pseudo-word with the same "shape" in every spelling — kebab-case, snake_case, camelCase, PascalCase,
 * UPPER_CASE and flat — so a selector, a JavaScript global and a file name keep matching each other after the
 * rewrite. Neutral words are derived from the site's salt: stable across requests, different on every site, and
 * unique, so the reverse mapping (masked request → real file or AJAX action) is unambiguous.
 *
 * This class is pure PHP (no WordPress calls) so the early asset server can use it before WordPress loads themes.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility\Masking;

final class TokenMap {

	/** Single words too generic to rewrite on their own (a theme literally called "news"). */
	const GENERIC = array( 'theme', 'child', 'news', 'blog', 'magazine', 'starter', 'pro', 'lite', 'free', 'base', 'main', 'site', 'page', 'post', 'shop', 'store', 'menu', 'header', 'footer', 'content', 'wordpress', 'block', 'blocks', 'default', 'classic', 'simple', 'minimal', 'hello', 'elementor', 'portfolio', 'business' );

	/** Split "Hello Elementor", "hello-elementor", "helloElementor" into lower-case parts. @return string[] */
	public static function parts( $s ) {
		$s = preg_replace( '/([a-z0-9])([A-Z])/', '$1 $2', (string) $s );
		$p = preg_split( '/[^A-Za-z0-9]+/', strtolower( (string) $s ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( (array) $p );
	}

	/** Deterministic pronounceable pseudo-word of a given length. */
	public static function word( $seed, $len ) {
		$c   = 'bcdfghjklmnprstvz';
		$v   = 'aeiou';
		$h   = hash( 'sha256', $seed );
		$out = '';
		$len = max( 2, min( 12, (int) $len ) );
		for ( $i = 0; $i < $len; $i++ ) {
			$n    = hexdec( substr( $h, ( $i * 2 ) % 60, 2 ) );
			$out .= ( 0 === $i % 2 ) ? $c[ $n % strlen( $c ) ] : $v[ $n % strlen( $v ) ];
		}
		return $out;
	}

	/**
	 * All spellings of a parts list.
	 *
	 * @param string[] $p Parts.
	 * @return array<string,string> kind => spelling
	 */
	public static function spellings( array $p ) {
		$title = array_map( 'ucfirst', $p );
		$out   = array(
			'kebab'  => implode( '-', $p ),
			'snake'  => implode( '_', $p ),
			'flat'   => implode( '', $p ),
			'camel'  => $p[0] . implode( '', array_slice( $title, 1 ) ),
			'pascal' => implode( '', $title ),
			'upper'  => strtoupper( implode( '_', $p ) ),
		);
		return $out;
	}

	/**
	 * Build the map.
	 *
	 * @param string[] $words    Full identifiers (slugs, names, text domains).
	 * @param string[] $prefixes Short class prefixes that only count when followed by - or _.
	 * @param string   $salt     Site secret.
	 * @return array{pairs:array<int,array{0:string,1:string,2:string}>,reverse:array<string,string>,sources:string[],skipped:string[]}
	 */
	public static function build( array $words, array $prefixes, $salt ) {
		$seen    = array();
		$pairs   = array();
		$sources = array();
		$skipped = array();
		$used    = array();
		foreach ( $words as $w ) {
			$p = self::parts( $w );
			if ( ! $p ) {
				continue;
			}
			$key = implode( ' ', $p );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( 1 === count( $p ) && ( strlen( $p[0] ) < 4 || in_array( $p[0], self::GENERIC, true ) ) ) {
				$skipped[] = $key;
				continue;
			}
			$n = array();
			foreach ( $p as $i => $part ) {
				$try = 0;
				do {
					$cand = self::word( $salt . '|' . $key . '|' . $i . '|' . $try, strlen( $part ) );
					++$try;
				} while ( isset( $used[ $cand ] ) && $try < 50 );
				$used[ $cand ] = true;
				$n[]           = $cand;
			}
			$sources[] = $key;
			$from      = self::spellings( $p );
			$to        = self::spellings( $n );
			foreach ( $from as $kind => $f ) {
				$pairs[ $f ] = array( $f, $to[ $kind ], 'word' );
			}
			// The literal spelling too ("OceanWP", "GeneratePress"): copy its letter case onto the neutral word.
			$lit = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $w );
			$flat = implode( '', $n );
			$letters = (string) preg_replace( '/[^A-Za-z0-9]/', '', $lit );
			if ( '' !== $lit && ! isset( $pairs[ $lit ] ) && strlen( $letters ) === strlen( $flat ) && strlen( $lit ) >= 4 ) {
				$neutral = '';
				$j       = 0;
				for ( $i = 0, $len = strlen( $lit ); $i < $len; $i++ ) {
					$ch = $lit[ $i ];
					if ( ctype_alnum( $ch ) ) {
						$neutral .= ctype_upper( $ch ) ? strtoupper( $flat[ $j ] ) : $flat[ $j ];
						++$j;
					} else {
						$neutral .= $ch;
					}
				}
				$pairs[ $lit ] = array( $lit, $neutral, 'word' );
			}
		}
		foreach ( $prefixes as $pre ) {
			$pre = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $pre ) );
			if ( strlen( $pre ) < 2 || isset( $pairs[ $pre ] ) ) {
				continue;
			}
			$try = 0;
			do {
				$cand = self::word( $salt . '|prefix|' . $pre . '|' . $try, max( 3, strlen( $pre ) + 1 ) );
				++$try;
			} while ( isset( $used[ $cand ] ) && $try < 50 );
			$used[ $cand ] = true;
			$pairs[ $pre ] = array( $pre, $cand, 'prefix' );
			$sources[]     = $pre . '-';
		}
		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$reverse = array();
		foreach ( $pairs as $pair ) {
			$reverse[ $pair[1] ] = $pair[0];
		}
		return array( 'pairs' => array_values( $pairs ), 'reverse' => $reverse, 'sources' => $sources, 'skipped' => $skipped );
	}
}
