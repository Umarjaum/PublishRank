<?php
/**
 * Format-aware, boundary-safe URL replacement for the Media Link Manager.
 *
 * - Never a blind search-and-replace: each needle is a complete media URL (or uploads-relative path) and must be
 *   followed by a URL boundary, so "photo.jpg" never matches "photo.jpg.webp" or "photo.jpg-old".
 * - Plain text, HTML, Gutenberg block comments and JSON (slashes escaped or not) are edited as text: a URL inside a
 *   JSON string can change length without breaking the document.
 * - Serialized PHP is unserialized (no classes allowed), edited recursively and serialized again, so every length
 *   prefix stays correct. Values that contain objects are left untouched and reported as ambiguous.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\MediaLinks;

defined( 'ABSPATH' ) || exit;

final class Replacer {
	/** @var array<string,string> needle => replacement, longest first. */
	private $map = array();
	/** @var string[] */
	public $notes = array();

	/** @param array<string,string> $map old => new. JSON-escaped variants are added automatically. */
	public function __construct( array $map ) {
		$full = array();
		foreach ( $map as $old => $new ) {
			$old = (string) $old;
			$new = (string) $new;
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$full[ $old ] = $new;
			if ( false !== strpos( $old, '/' ) ) {
				$full[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
			}
		}
		uksort(
			$full,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$this->map = $full;
	}

	/** @return array<string,string> */
	public function map() {
		return $this->map;
	}

	private static function pattern( $needle ) {
		// Not preceded by a host/path character (so a relative path never matches inside an absolute URL of another host)
		// and not followed by a filename character or a further extension.
		$before = ( 0 === strpos( $needle, 'http' ) || 0 === strpos( $needle, '//' ) || 0 === strpos( $needle, '\\/\\/' ) ) ? '(?<![A-Za-z0-9._%\-:])' : '(?<![A-Za-z0-9._%\-:\/])';
		return '~' . $before . preg_quote( $needle, '~' ) . '(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])~';
	}

	/**
	 * Replace inside a text value.
	 *
	 * @param string $text  Text.
	 * @param int    $count Replacements made (out).
	 */
	public function replace_text( $text, &$count = 0 ) {
		$text = (string) $text;
		foreach ( $this->map as $needle => $replacement ) {
			if ( false === strpos( $text, $needle ) ) {
				continue;
			}
			$n    = 0;
			$text = (string) preg_replace_callback(
				self::pattern( $needle ),
				static function () use ( $replacement ) {
					return $replacement;
				},
				$text,
				-1,
				$n
			);
			$count += $n;
		}
		return $text;
	}

	/** Count matches without changing anything. */
	public function count_text( $text ) {
		$c = 0;
		$this->replace_text( $text, $c );
		return $c;
	}

	/** Detected storage format of a raw database value. */
	public static function format_of( $raw ) {
		$raw = (string) $raw;
		if ( is_serialized( $raw ) ) {
			return 'serialized';
		}
		$t = ltrim( $raw );
		if ( '' !== $t && ( '{' === $t[0] || '[' === $t[0] ) && null !== json_decode( $raw ) ) {
			return 'json';
		}
		if ( false !== strpos( $raw, '<!-- wp:' ) ) {
			return 'blocks';
		}
		return false !== strpos( $raw, '<' ) ? 'html' : 'text';
	}

	/**
	 * Replace inside a raw database value, respecting its format.
	 *
	 * @param string $raw   Raw value exactly as stored.
	 * @param int    $count Replacements (out).
	 * @param string $error Why the value cannot be changed safely (out).
	 * @return string New raw value (unchanged when $error is set).
	 */
	public function replace_raw( $raw, &$count = 0, &$error = '' ) {
		$raw   = (string) $raw;
		$error = '';
		if ( ! is_serialized( $raw ) ) {
			return $this->replace_text( $raw, $count );
		}
		$data = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions -- safe: classes are disabled.
		if ( false === $data && 'b:0;' !== $raw ) {
			$error = __( 'Serialized value could not be read safely; left unchanged.', 'publishrank' );
			return $raw;
		}
		if ( self::has_object( $data ) ) {
			$error = __( 'Serialized value contains PHP objects; it is reported but never rewritten.', 'publishrank' );
			return $raw;
		}
		$n   = 0;
		$new = $this->walk( $data, $n );
		if ( 0 === $n ) {
			return $raw;
		}
		$out   = serialize( $new ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- format-preserving rewrite of a value that was serialized.
		$check = @unserialize( $out, array( 'allowed_classes' => false ) ); // phpcs:ignore
		if ( false === $check && 'b:0;' !== $out ) {
			$error = __( 'The rewritten serialized value did not verify; left unchanged.', 'publishrank' );
			return $raw;
		}
		$count += $n;
		return $out;
	}

	/** Count matches in a raw value (format-aware). */
	public function count_raw( $raw, &$error = '' ) {
		$c = 0;
		$this->replace_raw( $raw, $c, $error );
		return $c;
	}

	private function walk( $v, &$n ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $item ) {
				$v[ $k ] = $this->walk( $item, $n );
			}
			return $v;
		}
		if ( is_string( $v ) ) {
			if ( is_serialized( $v ) ) {
				$err = '';
				return $this->replace_raw( $v, $n, $err );
			}
			return $this->replace_text( $v, $n );
		}
		return $v;
	}

	private static function has_object( $v ) {
		if ( is_object( $v ) ) {
			return true;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $item ) {
				if ( self::has_object( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
