<?php
/**
 * Language adapters for the SEO analysis (no AI, no external services).
 *
 * - English ("en"): light plural stemming, Flesch reading ease, passive-voice and transition-word checks.
 * - Arabic ("ar") and Urdu ("ur"): Arabic-script normalisation (diacritics, tatweel, alef/ya/kaf variants),
 *   light prefix/suffix stemming, the Arabic full stop "۔" and question mark "؟", language-specific
 *   connective words and a sentence-length threshold tuned to each language. Flesch and passive-voice
 *   checks are English formulas and are never applied to these languages.
 * - Anything else ("generic"): tokens are compared as written and only language-neutral checks run.
 *
 * Further languages can be added with the publishrank_language_rules filter.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\SEO\Language;

defined( 'ABSPATH' ) || exit;

final class LanguageRules {

	/** Language of the analysis in progress (set by ArticleIntelligence::analyze()). */
	private static $current = 'en';

	public static function set_current( $lang ) {
		self::$current = in_array( $lang, array( 'en', 'ar', 'ur', 'generic' ), true ) ? $lang : 'generic';
	}

	public static function current() {
		return self::$current;
	}

	/**
	 * Detect the analysis language from the text itself, using the post/site locale only as a tie-breaker
	 * between Arabic and Urdu (both use the Arabic script).
	 */
	public static function detect( $text, $locale = '' ) {
		$text    = (string) $text;
		$letters = max( 1, (int) preg_match_all( '/\p{L}/u', $text ) );
		$latin   = (int) preg_match_all( '/[A-Za-z]/', $text );
		$arabic  = (int) preg_match_all( '/\p{Arabic}/u', $text );
		$locale  = strtolower( substr( (string) $locale, 0, 2 ) );
		if ( $arabic / $letters >= 0.5 ) {
			// Letters used by Urdu but not by standard Arabic: ٹ ڈ ڑ ں ھ ے ۓ ہ ۂ گ چ پ ژ ک ی (پ چ ژ گ are shared with Persian).
			$urdu_only = (int) preg_match_all( '/[\x{0679}\x{0688}\x{0691}\x{06BA}\x{06BE}\x{06D2}\x{06D3}\x{06C1}\x{06C2}]/u', $text );
			if ( 'ur' === $locale || ( 'ar' !== $locale && $urdu_only >= 2 ) ) {
				return 'ur';
			}
			return 'ar';
		}
		if ( $latin / $letters >= 0.7 ) {
			return 'en' === $locale || '' === $locale || ! in_array( $locale, array( 'de', 'fr', 'es', 'it', 'nl', 'pt', 'sv', 'da', 'nb', 'fi', 'pl', 'cs', 'tr', 'id', 'ro', 'hu' ), true ) ? 'en' : 'generic';
		}
		return 'generic';
	}

	/** Remove Arabic diacritics and tatweel and unify letter variants so spellings compare equal. */
	public static function normalize( $text ) {
		$text = (string) $text;
		if ( ! preg_match( '/\p{Arabic}/u', $text ) ) {
			return $text;
		}
		// Harakat, superscript alef, Quranic marks and tatweel.
		$text = (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text );
		$text = strtr(
			$text,
			array(
				'أ' => 'ا',
				'إ' => 'ا',
				'آ' => 'ا',
				'ٱ' => 'ا',
				'ى' => 'ي',
				'ي' => 'ی', // Arabic ya → Farsi/Urdu ya: one form for both languages.
				'ك' => 'ک',
				'ۀ' => 'ه',
				'ة' => 'ه',
				'ۃ' => 'ہ',
			)
		);
		return $text;
	}

	/** Light stemming for one lower-cased, normalised token. */
	public static function stem( $token, $lang = null ) {
		$lang  = null === $lang ? self::$current : $lang;
		$token = (string) $token;
		if ( preg_match( '/^[a-z\'’]+$/', $token ) ) {
			$t = str_replace( array( "'", '’' ), '', $token );
			if ( strlen( $t ) > 3 ) {
				if ( 'ies' === substr( $t, -3 ) ) {
					$t = substr( $t, 0, -3 ) . 'y';
				} elseif ( 's' === substr( $t, -1 ) && 'ss' !== substr( $t, -2 ) ) {
					$t = substr( $t, 0, -1 );
				}
			}
			return $t;
		}
		if ( ! preg_match( '/\p{Arabic}/u', $token ) ) {
			return str_replace( array( "'", '’' ), '', $token );
		}
		$len = static function ( $s ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
		};
		$cut_prefix = static function ( $s, array $list, $min ) use ( $len ) {
			foreach ( $list as $p ) {
				if ( 0 === strpos( $s, $p ) && $len( $s ) - $len( $p ) >= $min ) {
					return substr( $s, strlen( $p ) );
				}
			}
			return $s;
		};
		$cut_suffix = static function ( $s, array $list, $min ) use ( $len ) {
			foreach ( $list as $x ) {
				if ( strlen( $s ) > strlen( $x ) && substr( $s, -strlen( $x ) ) === $x && $len( $s ) - $len( $x ) >= $min ) {
					return substr( $s, 0, -strlen( $x ) );
				}
			}
			return $s;
		};
		if ( 'ur' === $lang ) {
			// Urdu plural / oblique endings (کتابیں → کتاب, لڑکوں → لڑک, خبروں → خبر, معلومات → معلوم).
			return $cut_suffix( $token, array( 'یوں', 'وں', 'یں', 'ات', 'ے' ), 3 );
		}
		// Arabic: conjunction/preposition + article prefixes, then common suffixes (light10-style).
		$token = $cut_prefix( $token, array( 'وال', 'بال', 'کال', 'فال', 'لل', 'ال' ), 2 );
		$token = $cut_prefix( $token, array( 'و' ), 3 );
		return $cut_suffix( $token, array( 'ها', 'ان', 'ات', 'ون', 'ین', 'یه', 'یہ', 'ه', 'ی' ), 2 );
	}

	/** Words per sentence above which a sentence counts as long. */
	public static function long_sentence_words( $lang = null ) {
		$lang = null === $lang ? self::$current : $lang;
		$map  = array( 'en' => 25, 'ar' => 20, 'ur' => 30, 'generic' => 25 );
		return (int) apply_filters( 'publishrank_language_long_sentence', isset( $map[ $lang ] ) ? $map[ $lang ] : 25, $lang );
	}

	/** Regex matching sentence-level connective words, or '' when the language has no list. */
	public static function transition_pattern( $lang = null ) {
		$lang  = null === $lang ? self::$current : $lang;
		$lists = array(
			'ar' => array( 'لذلك', 'لكن', 'ولكن', 'بالاضافه', 'علاوه علی ذلک', 'اولا', 'ثانیا', 'اخیرا', 'مثلا', 'لان', 'بینما', 'رغم', 'بالتالی', 'ایضا', 'کما', 'ثم', 'اذن', 'فی المقابل', 'من ناحیه اخری', 'باختصار' ),
			'ur' => array( 'لیکن', 'اس لیے', 'مزید', 'اس کے علاوہ', 'پہلے', 'آخر میں', 'مثال کے طور پر', 'کیونکہ', 'جبکہ', 'تاہم', 'چنانچہ', 'دوسری طرف', 'بہرحال', 'یعنی', 'لہذا', 'نیز', 'پھر', 'خلاصہ یہ کہ' ),
		);
		$lists = (array) apply_filters( 'publishrank_language_transitions', $lists );
		if ( empty( $lists[ $lang ] ) ) {
			return '';
		}
		$words = array_map(
			static function ( $w ) {
				return preg_quote( self::normalize( $w ), '/' );
			},
			$lists[ $lang ]
		);
		// \p{Arabic} letters are word characters only with /u; use explicit non-letter boundaries.
		return '/(?<![\p{L}])(?:' . implode( '|', $words ) . ')(?![\p{L}])/u';
	}

	/** Human label for messages. */
	public static function label( $lang ) {
		$labels = array(
			'en'      => __( 'English', 'publishrank' ),
			'ar'      => __( 'Arabic', 'publishrank' ),
			'ur'      => __( 'Urdu', 'publishrank' ),
			'generic' => __( 'this language', 'publishrank' ),
		);
		return isset( $labels[ $lang ] ) ? $labels[ $lang ] : $labels['generic'];
	}
}
