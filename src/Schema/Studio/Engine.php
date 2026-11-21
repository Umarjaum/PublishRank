<?php
/**
 * Resolves a Schema Studio template against a context into one JSON-LD node.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Studio;

defined( 'ABSPATH' ) || exit;

final class Engine {

	/**
	 * @param array                $tpl       Sanitised template.
	 * @param Context              $ctx       Document.
	 * @param array<string,string> $overrides Per-post values (property => text) for top-level properties.
	 * @return array{node:array|null,skipped:string,trace:array<string,string>,missing:array<int,string>}
	 */
	public static function build( array $tpl, Context $ctx, array $overrides = array() ) {
		$trace   = array();
		$missing = array();
		$node    = array( '@type' => $tpl['type'] );
		foreach ( $tpl['properties'] as $p ) {
			if ( isset( $overrides[ $p['name'] ] ) ) {
				$value = self::format( Fields::interpolate( $overrides[ $p['name'] ], $ctx ), $p['format'] ? $p['format'] : self::auto_format( $p['name'] ) );
				$trace[ $p['name'] ] = __( 'Per-post override', 'publishrank' );
			} else {
				$value = self::resolve( $p, $ctx, $missing, $p['name'] );
				$trace[ $p['name'] ] = self::describe( $p );
			}
			if ( self::is_empty( $value ) ) {
				if ( $p['required'] ) {
					$missing[] = $p['name'];
				}
				unset( $trace[ $p['name'] ] );
				continue;
			}
			$node[ $p['name'] ] = isset( $node[ $p['name'] ] ) ? array_merge( (array) $node[ $p['name'] ], (array) $value ) : $value;
		}
		$missing = array_values( array_unique( $missing ) );
		if ( $missing ) {
			return array(
				'node'    => null,
				/* translators: %s: property names */
				'skipped' => sprintf( __( 'Not printed: required properties are empty for this page (%s).', 'publishrank' ), implode( ', ', $missing ) ),
				'trace'   => $trace,
				'missing' => $missing,
			);
		}
		if ( count( $node ) < 2 ) {
			return array( 'node' => null, 'skipped' => __( 'Not printed: no property has a value for this page.', 'publishrank' ), 'trace' => $trace, 'missing' => array() );
		}
		return array( 'node' => $node, 'skipped' => '', 'trace' => $trace, 'missing' => array() );
	}

	/** Human description of a property source for the preview trace. */
	public static function describe( array $p ) {
		switch ( $p['source'] ) {
			case 'dynamic':
				$all = Fields::all();
				return isset( $all[ $p['field'] ] ) ? $all[ $p['field'] ][0] : $p['field'];
			case 'ref':
				return __( 'Relationship', 'publishrank' ) . ': ' . $p['field'];
			case 'object':
				return __( 'Nested', 'publishrank' ) . ' ' . $p['type'];
			case 'list':
				return __( 'List', 'publishrank' );
		}
		return false !== strpos( (string) $p['value'], '{{' ) ? __( 'Text with dynamic values', 'publishrank' ) : __( 'Fixed value', 'publishrank' );
	}

	/** @param mixed $v */
	public static function is_empty( $v ) {
		return null === $v || '' === $v || array() === $v || ( is_array( $v ) && isset( $v['@type'] ) && 1 === count( $v ) );
	}

	/** @return mixed */
	private static function resolve( array $p, Context $ctx, array &$missing, $path, $depth = 0 ) {
		$format = $p['format'] ? $p['format'] : self::auto_format( $p['name'] );
		switch ( $p['source'] ) {
			case 'dynamic':
				$v = Fields::resolve( $p['field'], $ctx );
				if ( self::is_empty( $v ) && '' !== $p['fallback'] ) {
					$v = Fields::interpolate( $p['fallback'], $ctx );
				}
				return self::format( $v, $format );
			case 'ref':
				$v = Fields::resolve( $p['field'], $ctx );
				if ( self::is_empty( $v ) && '' !== $p['fallback'] && preg_match( '#^https?://#', $p['fallback'] ) ) {
					$v = array( '@id' => esc_url_raw( $p['fallback'] ) );
				}
				return $v;
			case 'object':
				if ( $depth >= Templates::MAX_DEPTH ) {
					return '';
				}
				$obj   = array( '@type' => '' !== $p['type'] ? $p['type'] : 'Thing' );
				$inner = array();
				foreach ( $p['props'] as $child ) {
					$cv = self::resolve( $child, $ctx, $inner, $path . '.' . $child['name'], $depth + 1 );
					if ( self::is_empty( $cv ) ) {
						if ( $child['required'] ) {
							$inner[] = $path . '.' . $child['name'];
						}
						continue;
					}
					$obj[ $child['name'] ] = $cv;
				}
				if ( $inner ) {
					// A nested entity without its required values is dropped; the parent reports it only when the parent itself requires it.
					if ( $p['required'] ) {
						$missing = array_merge( $missing, $inner );
					}
					return '';
				}
				return count( $obj ) > 1 ? $obj : '';
			case 'list':
				$out = array();
				foreach ( $p['items'] as $item ) {
					$iv = self::resolve( $item + array( 'name' => $p['name'] ), $ctx, $missing, $path . '[]', $depth + 1 );
					if ( self::is_empty( $iv ) ) {
						continue;
					}
					if ( is_array( $iv ) && ! isset( $iv['@type'] ) && ! isset( $iv['@id'] ) ) {
						$out = array_merge( $out, array_values( $iv ) );
					} else {
						$out[] = $iv;
					}
				}
				return $out ? $out : '';
		}
		$v = Fields::interpolate( (string) $p['value'], $ctx );
		if ( '' === $v && '' !== $p['fallback'] ) {
			$v = Fields::interpolate( $p['fallback'], $ctx );
		}
		return self::format( $v, $format );
	}

	/** Pick a format from the property's expected types when the template does not set one. */
	public static function auto_format( $name ) {
		$prop = Vocabulary::property( $name );
		if ( ! $prop ) {
			return '';
		}
		$r = $prop['r'];
		if ( array( 'URL' ) === $r ) {
			return 'url';
		}
		if ( array( 'Date' ) === $r ) {
			return 'date';
		}
		if ( in_array( 'DateTime', $r, true ) && ! array_diff( $r, array( 'Date', 'DateTime' ) ) ) {
			return 'datetime_or_date';
		}
		if ( ! array_diff( $r, array( 'Integer' ) ) ) {
			return 'integer';
		}
		if ( ! array_diff( $r, array( 'Number', 'Integer', 'Float' ) ) ) {
			return 'number';
		}
		if ( array( 'Boolean' ) === $r ) {
			return 'boolean';
		}
		return '';
	}

	/**
	 * @param mixed  $v
	 * @param string $format
	 * @return mixed
	 */
	public static function format( $v, $format ) {
		if ( is_array( $v ) ) {
			if ( isset( $v['@type'] ) || isset( $v['@id'] ) ) {
				return $v;
			}
			$out = array();
			foreach ( $v as $item ) {
				$f = self::format( $item, $format );
				if ( ! self::is_empty( $f ) ) {
					$out[] = $f;
				}
			}
			return 1 === count( $out ) ? $out[0] : ( $out ? $out : '' );
		}
		if ( is_bool( $v ) ) {
			return 'boolean' === $format || '' === $format ? $v : ( $v ? 'true' : 'false' );
		}
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$s = trim( (string) $v );
		if ( '' === $s ) {
			return '';
		}
		switch ( $format ) {
			case 'text':
				return trim( wp_strip_all_tags( $s ) );
			case 'url':
				$u = esc_url_raw( $s, array( 'http', 'https' ) );
				return $u && preg_match( '#^https?://#i', $u ) ? $u : '';
			case 'date':
				$ts = strtotime( $s );
				return $ts ? gmdate( 'Y-m-d', $ts ) : '';
			case 'datetime':
				$ts = strtotime( $s );
				return $ts ? wp_date( 'c', $ts ) : '';
			case 'datetime_or_date':
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ) {
					return $s;
				}
				$ts = strtotime( $s );
				return $ts ? wp_date( 'c', $ts ) : '';
			case 'number':
				return is_numeric( $s ) ? 0 + $s : '';
			case 'integer':
				return is_numeric( $s ) ? (int) $s : '';
			case 'boolean':
				return in_array( strtolower( $s ), array( '1', 'yes', 'true', 'on' ), true );
			case 'duration_minutes':
				if ( preg_match( '/^P/i', $s ) ) {
					return strtoupper( $s );
				}
				if ( ! is_numeric( $s ) || (float) $s <= 0 ) {
					return '';
				}
				$m = (int) round( (float) $s );
				return 'PT' . ( $m >= 60 ? intdiv( $m, 60 ) . 'H' : '' ) . ( $m % 60 ? ( $m % 60 ) . 'M' : '' );
			case 'enum':
				return preg_match( '#^https?://#', $s ) ? $s : 'https://schema.org/' . preg_replace( '/[^A-Za-z0-9]/', '', $s );
			case 'truncate160':
				$s = trim( wp_strip_all_tags( $s ) );
				return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $s, 0, 160, '…' ) : substr( $s, 0, 160 );
			case 'lines':
				$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( $s ) ) ), 'strlen' ) );
				return $lines ? $lines : '';
		}
		return $s;
	}
}
