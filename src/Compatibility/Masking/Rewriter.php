<?php
/**
 * Theme masking rewriter: URLs, identifiers, credits — for full pages, fragments, CSS and JavaScript.
 *
 * Pure PHP (no WordPress calls) so the early asset server can use it before themes load.
 *
 * Rules that keep sites working:
 * - Identifiers are rewritten only where code reads them: class/id/for/style/aria-* reference attributes,
 *   data-* attributes (lower-case spellings only), in-page #anchors, <style>, <script> (not JSON-LD), HTML
 *   comments and the theme's own CSS/JS served through the neutral address. Visible text, links to content,
 *   form field names and JSON-LD are never changed.
 * - The same map is applied to HTML and to the served CSS/JS, so selectors, globals and file names still match.
 * - Words only match on identifier boundaries ("astra" never matches inside "fastrak").
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility\Masking;

final class Rewriter {

	const REF_ATTRS = 'class|id|for|style|headers|list|form|aria-controls|aria-labelledby|aria-describedby|aria-owns|aria-activedescendant|aria-flowto|aria-details|aria-errormessage';

	/** @var array<string,array<string,mixed>> compiled regex cache keyed by map hash */
	private static $compiled = array();

	/** @param array<string,mixed> $cfg */
	private static function compile( array $cfg ) {
		$key = isset( $cfg['hash'] ) ? (string) $cfg['hash'] : md5( serialize( $cfg['pairs'] ) );
		if ( isset( self::$compiled[ $key ] ) ) {
			return self::$compiled[ $key ];
		}
		$words = array();
		$safe  = array();
		$pre   = array();
		$map   = array();
		foreach ( (array) $cfg['pairs'] as $p ) {
			$map[ $p[0] ] = $p[1];
			if ( 'prefix' === $p[2] ) {
				$pre[] = preg_quote( $p[0], '#' );
				continue;
			}
			$words[] = preg_quote( $p[0], '#' );
			if ( ctype_lower( $p[0][0] ) ) {
				$safe[] = preg_quote( $p[0], '#' );
			}
		}
		$c = array(
			'map'   => $map,
			'words' => $words ? '#(?<![A-Za-z0-9])(?:' . implode( '|', $words ) . ')(?![a-z0-9])#' : '',
			'safe'  => $safe ? '#(?<![A-Za-z0-9])(?:' . implode( '|', $safe ) . ')(?![a-z0-9])#' : '',
			'pre'   => $pre ? '#(?<![A-Za-z0-9])(?:' . implode( '|', $pre ) . ')(?=[-_][A-Za-z0-9])#' : '',
		);
		self::$compiled[ $key ] = $c;
		return $c;
	}

	/** Rewrite identifiers in code. $safe = lower-case spellings only (data attributes, AJAX payloads). */
	public static function identifiers( $text, array $cfg, $safe = false ) {
		if ( '' === (string) $text || empty( $cfg['pairs'] ) ) {
			return (string) $text;
		}
		$c   = self::compile( $cfg );
		$map = $c['map'];
		$cb  = static function ( $m ) use ( $map ) {
			return isset( $map[ $m[0] ] ) ? $map[ $m[0] ] : $m[0];
		};
		$re = $safe ? $c['safe'] : $c['words'];
		if ( $re ) {
			$out  = preg_replace_callback( $re, $cb, (string) $text );
			$text = null === $out ? $text : $out;
		}
		if ( $c['pre'] ) {
			$out  = preg_replace_callback( $c['pre'], $cb, (string) $text );
			$text = null === $out ? $text : $out;
		}
		return (string) $text;
	}

	/** Theme identifiers inside a relative path (file names) → neutral. */
	public static function path_out( $rel, array $cfg ) {
		if ( empty( $cfg['identifiers'] ) ) {
			return $rel;
		}
		$segs = explode( '/', (string) $rel );
		foreach ( $segs as &$s ) {
			$s = self::identifiers( $s, $cfg );
		}
		return implode( '/', $segs );
	}

	/** Neutral path → real path (reverse map). */
	public static function path_in( $rel, array $cfg ) {
		if ( empty( $cfg['reverse'] ) ) {
			return $rel;
		}
		return strtr( (string) $rel, (array) $cfg['reverse'] );
	}

	/** Short opaque version for cache busting (theme version never appears). */
	public static function vtag( $ver, array $cfg ) {
		return substr( md5( (string) $ver . '|' . (string) $cfg['vhash'] ), 0, 8 );
	}

	/** Map one wp-content relative path to [root key, sub path] or null when it is outside the masked scope. */
	public static function classify( $rel, array $cfg ) {
		$rel = ltrim( (string) $rel, '/' );
		foreach ( array( 'c', 'p' ) as $k ) {
			if ( empty( $cfg['roots'][ $k ]['slug'] ) ) {
				continue;
			}
			$base = 'themes/' . $cfg['roots'][ $k ]['slug'];
			if ( $rel === $base ) {
				return array( $k, '' );
			}
			if ( 0 === strpos( $rel, $base . '/' ) ) {
				return array( $k, (string) substr( $rel, strlen( $base ) + 1 ) );
			}
		}
		if ( ! empty( $cfg['identifiers'] ) && ! empty( $cfg['scope'] ) ) {
			foreach ( (array) $cfg['scope'] as $prefix ) {
				if ( 0 === strpos( $rel, $prefix ) ) {
					return array( 'x', $rel );
				}
			}
		}
		return null;
	}

	/** Rewrite every theme (and in-scope) URL to the neutral address. Handles absolute, protocol- and root-relative and JSON-escaped forms. */
	public static function urls( $text, array $cfg ) {
		$S     = '(?:\\\\/|/)';
		$cpath = implode( $S, array_map( static function ( $p ) { return preg_quote( $p, '#' ); }, array_filter( explode( '/', (string) $cfg['content_path'] ) ) ) );
		$hosts = array();
		foreach ( (array) $cfg['hosts'] as $h ) {
			$hosts[] = preg_quote( $h, '#' );
		}
		$tail  = '(?<rel>(?:[A-Za-z0-9._~%@+\-*]+)(?:' . $S . '[A-Za-z0-9._~%@+\-*]*)*)(?<q>\?[^"\'\s<>)\\\\]*)?';
		$abs   = '#(?<pre>(?:https?:)?' . $S . $S . '(?:' . implode( '|', $hosts ) . '))' . $S . $cpath . $S . $tail . '#';
		$rel   = '#(?<pre>)(?<=["\'(\s=,]|^)' . $S . $cpath . $S . $tail . '#';
		$home  = (string) $cfg['origin'];
		$cb    = static function ( $m ) use ( $cfg, $home ) {
			$escaped = false !== strpos( $m[0], '\\/' );
			$r       = str_replace( '\\/', '/', $m['rel'] );
			$hit     = self::classify( $r, $cfg );
			if ( ! $hit ) {
				return $m[0];
			}
			$path = rtrim( (string) $cfg['alias_path'], '/' ) . '/' . $hit[0] . '/' . self::path_out( $hit[1], $cfg );
			$q    = isset( $m['q'] ) ? (string) $m['q'] : '';
			$base = basename( $hit[1] );
			if ( ! empty( $cfg['versions'] ) ) {
				if ( '' !== $q ) {
					$q = (string) preg_replace_callback(
						'#([?&;])ver=([^&\#"\'\s]*)#',
						static function ( $v ) use ( $cfg ) {
							return $v[1] . 'v=' . self::vtag( $v[2], $cfg );
						},
						$q
					);
				} elseif ( false !== strpos( $base, '.' ) && '*' !== substr( $hit[1], -1 ) ) {
					$q = '?v=' . self::vtag( '', $cfg );
				}
			}
			$pre = '' !== $m['pre'] ? ( 0 === strpos( str_replace( '\\/', '/', $m['pre'] ), '//' ) ? '//' . preg_replace( '#^https?://#', '', $home ) : $home ) : '';
			$out = $pre . $path . $q;
			return $escaped ? str_replace( '/', '\\/', $out ) : $out;
		};
		$out  = preg_replace_callback( $abs, $cb, (string) $text );
		$text = null === $out ? $text : $out;
		$out  = preg_replace_callback( $rel, $cb, (string) $text );
		return null === $out ? (string) $text : (string) $out;
	}

	/** Rewrite the reference attributes inside one opening tag. */
	public static function tag( $tag, array $cfg ) {
		$refs = self::REF_ATTRS;
		$out  = preg_replace_callback(
			'#(\s)([A-Za-z_:][-A-Za-z0-9_:.]*)(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'>]+)#',
			static function ( $m ) use ( $cfg, $refs ) {
				$name = strtolower( $m[2] );
				$val  = $m[4];
				if ( preg_match( '#^(?:' . $refs . ')$#', $name ) ) {
					$val = self::identifiers( $val, $cfg );
				} elseif ( 0 === strpos( $name, 'data-' ) || 'name' === $name ) {
					// Form field names are mapped back on the server by RequestMapper.
					$val = self::identifiers( $val, $cfg, true );
				} elseif ( ( 'href' === $name || 'xlink:href' === $name ) && preg_match( '#^["\']?\##', $val ) ) {
					$val = self::identifiers( $val, $cfg );
				}
				return $m[1] . $m[2] . $m[3] . $val;
			},
			(string) $tag
		);
		return null === $out ? (string) $tag : (string) $out;
	}

	/** Rewrite identifiers in an HTML document or fragment (scripts, styles, comments, reference attributes). */
	public static function markup( $html, array $cfg ) {
		$store = array();
		$keep  = static function ( $s ) use ( &$store ) {
			$k           = "\x1APRM" . count( $store ) . "\x1A";
			$store[ $k ] = $s;
			return $k;
		};
		$html = preg_replace_callback(
			'#(<script\b[^>]*>)(.*?)(</script\s*>)#is',
			static function ( $m ) use ( $cfg, $keep ) {
				$open = self::tag( $m[1], $cfg );
				$body = $m[2];
				if ( ! preg_match( '#type\s*=\s*["\']?application/(?:ld\+json|json)#i', $m[1] ) ) {
					$body = self::identifiers( $body, $cfg );
				}
				return $keep( $open . $body . $m[3] );
			},
			(string) $html
		);
		$html = preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
			static function ( $m ) use ( $cfg, $keep ) {
				return $keep( self::tag( $m[1], $cfg ) . self::identifiers( $m[2], $cfg ) . $m[3] );
			},
			(string) $html
		);
		$html = preg_replace_callback(
			'#<!--(?!\x1APRM)(.*?)-->#s',
			static function ( $m ) use ( $cfg, $keep ) {
				return $keep( '<!--' . self::identifiers( $m[1], $cfg ) . '-->' );
			},
			(string) $html
		);
		$html = preg_replace_callback(
			'#<[A-Za-z][^\s/>\x1A]*(?:\s+[^\s"\'>/=\x1A]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?)*+\s*/?>#',
			static function ( $m ) use ( $cfg ) {
				return self::tag( $m[0], $cfg );
			},
			(string) $html
		);
		// Placeholders can nest (a comment inside a script was kept by the script first), so restore until stable.
		for ( $i = 0; $i < 3 && false !== strpos( (string) $html, "\x1APRM" ); $i++ ) {
			$html = strtr( (string) $html, $store );
		}
		return (string) $html;
	}

	/** Remove footer credit links that point at the theme vendor. */
	public static function credits( $html, array $cfg ) {
		// Only in the footer area, never in article text that discusses or links to a theme.
		$html = (string) $html;
		$at   = strripos( $html, '<footer' );
		if ( false === $at ) {
			$end = strripos( $html, '</body' );
			$at  = max( 0, ( false === $end ? strlen( $html ) : $end ) - 12000 );
		}
		return substr( $html, 0, $at ) . self::credits_in( substr( $html, $at ), $cfg );
	}

	private static function credits_in( $html, array $cfg ) {
		if ( ! empty( $cfg['credit_hosts'] ) ) {
		$hosts = implode( '|', array_map( static function ( $h ) { return preg_quote( $h, '#' ); }, (array) $cfg['credit_hosts'] ) );
		$words = (array) $cfg['credit_words'];
		$re    = '#(?<lead>\s*(?:\||&\#124;|&middot;|·|–|&ndash;|&\#8211;)\s*)?(?<pre>(?:Powered by|Theme by|Designed by|Developed by|Built with|Theme:|WordPress Theme by)\s*)?<a\b[^>]*\bhref=["\']https?://(?:[a-z0-9-]+\.)*(?:' . $hosts . ')(?:[/:?\#][^"\']*)?["\'][^>]*>(?<text>.*?)</a>(?<trail>\s*(?:\||&\#124;|&middot;|·|–|&ndash;|&\#8211;)\s*)?#is';
		$out   = preg_replace_callback(
			$re,
			static function ( $m ) use ( $words ) {
				$text = strtolower( trim( strip_tags( $m['text'] ) ) );
				$hit  = '' !== $m['pre'] || false !== strpos( $text, 'theme' );
				foreach ( $words as $w ) {
					if ( '' !== $w && false !== strpos( $text, $w ) ) {
						$hit = true;
					}
				}
				if ( ! $hit ) {
					return $m[0];
				}
				// Keep one separator when the credit sat between two other items.
				return ( '' !== $m['lead'] && ! empty( $m['trail'] ) ) ? ' | ' : '';
			},
			(string) $html
		);
		$html = null === $out ? (string) $html : (string) $out;
		}
		// Plain-text credits such as "WordPress Theme by OceanWP" or "Powered by Astra".
		$names = array();
		foreach ( (array) $cfg['credit_words'] as $w ) {
			if ( strlen( $w ) >= 4 ) {
				$names[] = str_replace( ' ', '[\\s-]+', preg_quote( $w, '#' ) );
			}
		}
		if ( $names ) {
			$out  = preg_replace( '#(?:\s*(?:-|\||–|&ndash;|&\#8211;|·)\s*)?(?:(?:WordPress\s+)?Theme\s*(?:by|:)|Powered\s+by|Designed\s+by|Built\s+with)\s+(?:' . implode( '|', $names ) . ')(?:\s+(?:WordPress\s+)?Theme)?(?![A-Za-z0-9])#i', '', $html );
			$html = null === $out ? $html : $out;
			// A footer element whose whole text is the theme name (block-theme footer patterns).
			$out  = preg_replace( '#<(p|span|div|h[1-6]|a|small)\b[^>]*>\s*(?:' . implode( '|', $names ) . ')\s*</\1>#i', '', $html );
			$html = null === $out ? $html : $out;
		}
		return (string) $html;
	}

	/** Drop source-map references. */
	public static function strip_sourcemaps( $code ) {
		$code = preg_replace( '#[ \t]*//[\#@]\s*sourceMappingURL=[^\r\n]*#', '', (string) $code );
		return (string) preg_replace( '#/\*[\#@]\s*sourceMappingURL=[^*]*\*/#', '', (string) $code );
	}

	/** Strip the theme stylesheet header comment. */
	public static function strip_header( $css ) {
		return (string) preg_replace( '#^\s*/\*.*?(Theme Name|Theme URI|Template)\s*:.*?\*/\s*#is', '', (string) $css, 1 );
	}

	/** Process a served stylesheet. */
	public static function css( $css, array $cfg ) {
		$css = self::strip_header( $css );
		if ( ! empty( $cfg['identifiers'] ) ) {
			// Comments carry theme names, authors and build paths; CSS never needs them.
			$out = preg_replace( '#/\*(?!\x1A).*?\*/#s', '', (string) $css );
			$css = null === $out ? $css : $out;
		}
		if ( 'strip' === $cfg['sourcemaps'] ) {
			$css = self::strip_sourcemaps( $css );
		}
		$css = self::urls( $css, $cfg );
		if ( ! empty( $cfg['identifiers'] ) ) {
			$css = self::identifiers( $css, $cfg );
		}
		return (string) $css;
	}

	/** Process a served script. */
	public static function js( $js, array $cfg ) {
		if ( 'strip' === $cfg['sourcemaps'] ) {
			$js = self::strip_sourcemaps( $js );
		}
		if ( ! empty( $cfg['identifiers'] ) ) {
			// Licence/banner comments (/*! ... */) and a leading file docblock name the theme and its author.
			$out = preg_replace( '#/\*!.*?\*/\s*#s', '', (string) $js );
			$js  = null === $out ? $js : $out;
			$out = preg_replace( '#^\s*/\*\*?(?:(?!\*/).)*?(?:@package|@author|Theme|Version)(?:(?!\*/).)*\*/\s*#s', '', (string) $js, 1 );
			$js  = null === $out ? $js : $out;
		}
		$js = self::urls( $js, $cfg );
		if ( ! empty( $cfg['identifiers'] ) ) {
			$js = self::identifiers( $js, $cfg );
		}
		return (string) $js;
	}

	/** Process an AJAX/REST JSON payload: selectors in keys, HTML in values. */
	public static function payload( $data, array $cfg ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$nk         = is_string( $k ) && preg_match( '#[.\#\s>]#', $k ) ? self::identifiers( $k, $cfg ) : $k;
				$out[ $nk ] = self::payload( $v, $cfg );
			}
			return $out;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = self::payload( $v, $cfg );
			}
			return $data;
		}
		if ( is_string( $data ) && false !== strpos( $data, '<' ) ) {
			return self::markup( self::urls( $data, $cfg ), $cfg );
		}
		return $data;
	}
}
