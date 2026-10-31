<?php
/**
 * Visible breadcrumb separators: preset catalogue, validation and storage rules.
 *
 * The separator is a presentation setting only. It is stored as the literal text that is printed between crumbs
 * (option "publishrank_breadcrumbs_separator") and is never written into BreadcrumbList JSON-LD.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class BreadcrumbSeparators {
	const OPTION  = 'publishrank_breadcrumbs_separator';
	const DEFAULT = '→';
	/** Longest separator accepted when saving, in characters. */
	const MAX_LENGTH = 10;
	/** Longest stored separator still rendered, so a longer value saved by an older release keeps working. */
	const MAX_STORED_LENGTH = 40;
	/** Non-breaking space (U+00A0), written as an escape so the source stays unambiguous. */
	const NBSP = "\u{00A0}";

	/**
	 * Preset catalogue, grouped for the settings screen. Keys are the literal separator text.
	 * Includes every value earlier releases offered so an existing choice keeps showing as a preset.
	 *
	 * @return array<string, array<string,string>> Group label => ( separator => label ).
	 */
	public static function groups() {
		return array(
			__( 'Directional arrows', 'publishrank' )   => array(
				'→' => 'Arrow →',
				'➜' => 'Heavy arrow ➜',
				'➝' => 'Arrow ➝',
				'➞' => 'Arrow ➞',
				'➡' => 'Black right arrow ➡',
				'➤' => 'Arrowhead ➤',
				'⇒' => 'Double arrow ⇒',
				'⇢' => 'Dashed arrow ⇢',
				'⇨' => 'White arrow ⇨',
				'⟶' => 'Long arrow ⟶',
			),
			__( 'Angles and chevrons', 'publishrank' )  => array(
				'›' => 'Single angle ›',
				'»' => 'Double angle »',
				'»»' => 'Quadruple angle »»',
				'≫' => 'Much greater than ≫',
				'❯' => 'Heavy angle ❯',
				'❱' => 'Heavy angle variant ❱',
				'⟩' => 'Mathematical angle ⟩',
				'⟫' => 'Mathematical double angle ⟫',
				'〉' => 'CJK angle 〉',
				'>' => 'Greater than >',
			),
			__( 'Slashes and lines', 'publishrank' )    => array(
				'/'  => 'Slash /',
				'//' => 'Double slash //',
				'\\' => 'Backslash \\',
				'|'  => 'Pipe |',
				'¦'  => 'Broken bar ¦',
				'‖'  => 'Double vertical line ‖',
				':'  => 'Colon :',
				'::' => 'Double colon ::',
				'⁄'  => 'Fraction slash ⁄',
				'／'  => 'Fullwidth slash ／',
			),
			__( 'Dots and bullets', 'publishrank' )     => array(
				'·' => 'Middle dot ·',
				'•' => 'Bullet •',
				'‧' => 'Hyphenation point ‧',
				'⋅' => 'Dot operator ⋅',
				'…' => 'Ellipsis …',
				'∙' => 'Bullet operator ∙',
				'●' => 'Black circle ●',
				'○' => 'White circle ○',
				'◦' => 'White bullet ◦',
				'‣' => 'Triangle bullet ‣',
				'▪' => 'Small square ▪',
				'▫' => 'White small square ▫',
			),
			__( 'Dashes', 'publishrank' )               => array(
				'-' => 'Hyphen -',
				'–' => 'En dash –',
				'—' => 'Em dash —',
				'−' => 'Minus sign −',
				'~' => 'Tilde ~',
				'≈' => 'Approximately ≈',
			),
			__( 'Other symbols', 'publishrank' )        => array(
				'∷' => 'Proportion ∷',
				'※' => 'Reference mark ※',
				'✦' => 'Four-pointed star ✦',
				'✧' => 'White four-pointed star ✧',
			),
			__( 'Spaced variants', 'publishrank' )      => array(
				' › '              => 'Spaced angle ›',
				'» '               => 'Double angle with trailing space »',
				' · '              => 'Spaced middle dot',
				' | '              => 'Spaced pipe',
				' / '              => 'Spaced slash',
				' - '              => 'Spaced hyphen',
				' — '              => 'Spaced em dash',
				"\u{3000}›\u{3000}" => 'Wide spaced angle',
			),
			__( 'Spacing only', 'publishrank' )         => array(
				' '        => 'Regular space',
				self::NBSP => 'Non-breaking space',
				''         => 'No visible separator',
			),
		);
	}

	/** @return array<string,string> Flat map of preset separator => label. */
	public static function presets() {
		$flat = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group as $value => $label ) {
				$flat[ (string) $value ] = $label;
			}
		}
		return $flat;
	}

	/** @return bool Whether the value is one of the presets. */
	public static function is_preset( $value ) {
		return array_key_exists( (string) $value, self::presets() );
	}

	/**
	 * Validate and normalise a separator typed by an administrator, imported or passed to the shortcode.
	 *
	 * Rules: plain text only (markup is removed, control and invisible bidi characters are dropped), runs of ASCII
	 * whitespace collapse to one space, leading/trailing spaces are kept on purpose (spaced separators), and the
	 * result may be empty ("no visible separator") or whitespace-only. Longer than MAX_LENGTH is rejected.
	 *
	 * @param mixed $value Raw value (already unslashed).
	 * @param int   $max   Longest accepted length in characters.
	 * @return string|null Normalised separator, or null when the value is not acceptable.
	 */
	public static function normalize( $value, $max = self::MAX_LENGTH ) {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return null;
		}
		$value = wp_check_invalid_utf8( (string) $value );
		// Remove real markup (including the content of <script>/<style>) but keep a lone "<" or ">" as text.
		if ( preg_match( '/<[A-Za-z\/!?]/', $value ) ) {
			$value = wp_strip_all_tags( $value );
			if ( '' === trim( $value, " \t\n\r\0\x0B" ) ) {
				return null; // Nothing but markup: reject instead of silently saving "no separator".
			}
		}
		// Control characters, zero-width characters and bidirectional overrides cannot be a visible separator.
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value );
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = preg_replace( '/[ \t\r\n]+/', ' ', $value );
		if ( ! is_string( $value ) ) {
			return null;
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		return $length > $max ? null : $value;
	}

	/**
	 * Separator currently configured for the visible trail.
	 *
	 * A stored empty string means "no visible separator" and is honoured; the default only applies when
	 * the option has never been saved.
	 */
	public static function current() {
		$stored = get_option( self::OPTION, self::DEFAULT );
		$value  = is_string( $stored ) ? self::normalize( $stored, self::MAX_STORED_LENGTH ) : null;
		return null === $value ? self::DEFAULT : $value;
	}

	/**
	 * register_setting() sanitize callback: invalid input keeps the previous value and tells the administrator.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_setting( $value ) {
		$normalized = self::normalize( $value );
		if ( null !== $normalized ) {
			return $normalized;
		}
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'publishrank_breadcrumb_separator_invalid',
				sprintf(
					/* translators: %d: maximum number of characters. */
					__( 'The breadcrumb separator was not saved: use plain text of at most %d characters.', 'publishrank' ),
					self::MAX_LENGTH
				)
			);
		}
		return self::current();
	}

	/** Separators that point in the reading direction and therefore flip in right-to-left layouts. */
	public static function is_directional( $value ) {
		return (bool) preg_match( '/[→➜➝➞➡➤⇒⇢⇨⟶›»≫❯❱⟩⟫〉>]/u', (string) $value );
	}
}
