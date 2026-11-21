<?php
/**
 * Schema.org vocabulary used by Schema Studio.
 *
 * Data lives in assets/schema/vocabulary.json, generated from the official schema.org release file
 * (schemaorg-current-https.jsonld). It holds every non-retired class with its parents, the properties
 * that apply to the catalog types (domains, expected ranges, short descriptions), enumeration members,
 * and — separately — Google Search feature notes. Schema.org validity and Google eligibility are kept
 * apart on purpose: valid markup is not a promise of a rich result.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Studio;

defined( 'ABSPATH' ) || exit;

final class Vocabulary {

	/** Schema.org data types (values, not entities). */
	const DATATYPES = array( 'Text', 'URL', 'Date', 'DateTime', 'Time', 'Number', 'Integer', 'Float', 'Boolean', 'CssSelectorType', 'XPathType', 'PronounceableText', 'DataType' );

	/** @var array|null */
	private static $data = null;
	/** @var array<string,array<int,string>> */
	private static $anc = array();

	/** @return array{types:array,props:array,classes:array,enums:array,excluded:array,version:string} */
	public static function data() {
		if ( null === self::$data ) {
			$file       = PUBLISHRANK_SEO_PATH . 'assets/schema/vocabulary.json';
			$raw        = is_readable( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.
			$decoded    = $raw ? json_decode( $raw, true ) : null;
			self::$data = is_array( $decoded ) ? $decoded : array( 'types' => array(), 'props' => array(), 'classes' => array(), 'enums' => array(), 'excluded' => array(), 'version' => '' );
		}
		return self::$data;
	}

	/** Catalog types offered in the editor. @return array<string,array> */
	public static function catalog() {
		return self::data()['types'];
	}

	/** Whether $type is a schema.org class (any class, not only catalog types). */
	public static function exists( $type ) {
		return is_string( $type ) && isset( self::data()['classes'][ $type ] );
	}

	public static function in_catalog( $type ) {
		return is_string( $type ) && isset( self::data()['types'][ $type ] );
	}

	public static function is_datatype( $type ) {
		return in_array( $type, self::DATATYPES, true );
	}

	/** All superclasses, nearest first. @return array<int,string> */
	public static function ancestors( $type ) {
		$type = (string) $type;
		if ( isset( self::$anc[ $type ] ) ) {
			return self::$anc[ $type ];
		}
		$classes = self::data()['classes'];
		$out     = array();
		$queue   = isset( $classes[ $type ] ) ? (array) $classes[ $type ] : array();
		while ( $queue ) {
			$parent = array_shift( $queue );
			if ( in_array( $parent, $out, true ) ) {
				continue;
			}
			$out[] = $parent;
			if ( isset( $classes[ $parent ] ) ) {
				$queue = array_merge( $queue, (array) $classes[ $parent ] );
			}
		}
		self::$anc[ $type ] = $out;
		return $out;
	}

	/** $type equals $parent or inherits from it. */
	public static function is_a( $type, $parent ) {
		return (string) $type === (string) $parent || in_array( (string) $parent, self::ancestors( $type ), true );
	}

	/** Any of several (multi-typed) types is_a $parent. */
	public static function any_is_a( $types, $parent ) {
		foreach ( (array) $types as $t ) {
			if ( self::is_a( $t, $parent ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{d:array,r:array,c:string,pending?:int}|null */
	public static function property( $name ) {
		$props = self::data()['props'];
		return isset( $props[ $name ] ) ? $props[ $name ] : null;
	}

	/** Whether a property is defined for at least one of the given types (directly or by inheritance). */
	public static function applies( $name, $types ) {
		$prop = self::property( $name );
		if ( ! $prop ) {
			return false;
		}
		foreach ( (array) $types as $t ) {
			$chain = array_merge( array( (string) $t ), self::ancestors( $t ) );
			if ( array_intersect( $chain, $prop['d'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Properties for a type, grouped by the class that declares them (nearest first).
	 *
	 * @return array<string,array<string,array>> declaring class => property => definition
	 */
	public static function properties_for( $type ) {
		$chain  = array_merge( array( (string) $type ), self::ancestors( $type ) );
		$groups = array_fill_keys( $chain, array() );
		foreach ( self::data()['props'] as $name => $prop ) {
			foreach ( $chain as $class ) {
				if ( in_array( $class, $prop['d'], true ) ) {
					$groups[ $class ][ $name ] = $prop;
					break;
				}
			}
		}
		foreach ( $groups as $class => $list ) {
			if ( ! $list ) {
				unset( $groups[ $class ] );
			} else {
				ksort( $groups[ $class ] );
				$groups[ $class ] = $groups[ $class ];
			}
		}
		return $groups;
	}

	/** Members of an enumeration (e.g. ItemAvailability => InStock, OutOfStock ...). @return array<int,string> */
	public static function enum_members( $enum ) {
		$enums = self::data()['enums'];
		$out   = isset( $enums[ $enum ] ) ? $enums[ $enum ] : array();
		// Members of sub-enumerations count too (e.g. MedicalSpecialty under Specialty).
		foreach ( $enums as $name => $members ) {
			if ( $name !== $enum && self::is_a( $name, $enum ) ) {
				$out = array_merge( $out, $members );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Whether a class is an enumeration. */
	public static function is_enum( $type ) {
		return self::is_a( $type, 'Enumeration' );
	}

	/**
	 * Google Search feature notes for a type: its own entry, otherwise the nearest ancestor's.
	 *
	 * @return array|null
	 */
	public static function google( $type ) {
		$types = self::catalog();
		foreach ( array_merge( array( (string) $type ), self::ancestors( $type ) ) as $t ) {
			if ( isset( $types[ $t ]['google'] ) ) {
				return $types[ $t ]['google'] + array( 'via' => $t );
			}
		}
		return null;
	}

	/** Note for names that are often requested but are not schema.org types. */
	public static function excluded_note( $type ) {
		$ex = self::data()['excluded'];
		return isset( $ex[ $type ] ) ? $ex[ $type ] : '';
	}

	/** Property that replaces a superseded one, or ''. */
	public static function superseded_by( $name ) {
		$s = isset( self::data()['superseded'] ) ? self::data()['superseded'] : array();
		return isset( $s[ $name ] ) ? implode( ', ', (array) $s[ $name ] ) : '';
	}

	public static function version() {
		return (string) self::data()['version'];
	}
}
