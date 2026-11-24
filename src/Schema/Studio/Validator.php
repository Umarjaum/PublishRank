<?php
/**
 * Structured-data diagnostics.
 *
 * Two separate questions are answered and never mixed:
 *  1. Is the markup valid Schema.org? (JSON syntax, known types, properties defined for the type,
 *     expected value types, URLs, dates, enumerations, nesting, references, duplicates, conflicts.)
 *  2. What does Google document for this type? (required / recommended properties, retired or
 *     restricted features). Eligibility is never guaranteed; it must be confirmed with Google's
 *     Rich Results Test on the live URL.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Studio;

defined( 'ABSPATH' ) || exit;

final class Validator {

	const RICH_RESULTS_TEST = 'https://search.google.com/test/rich-results?url=';
	const SCHEMA_VALIDATOR  = 'https://validator.schema.org/#url=';

	/** @var array<int,array> */
	private $issues = array();
	/** @var array<string,int> */
	private $defined = array();
	/** @var array<int,array{id:string,path:string}> */
	private $refs = array();

	/**
	 * Validate a list of top-level nodes (an @graph) or a single node.
	 *
	 * @param array $graph Nodes.
	 * @return array<int,array{level:string,code:string,path:string,message:string}>
	 */
	public static function validate( array $graph ) {
		$v = new self();
		if ( isset( $graph['@type'] ) || isset( $graph['@graph'] ) ) {
			$graph = isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ? $graph['@graph'] : array( $graph );
		}
		foreach ( array_values( $graph ) as $i => $node ) {
			if ( ! is_array( $node ) ) {
				$v->add( 'error', 'invalid-node', '@graph[' . $i . ']', __( 'A graph entry is not an object.', 'publishrank' ) );
				continue;
			}
			$v->node( $node, self::label( $node, $i ), true );
		}
		$v->references();
		$v->duplicates( $graph );
		$v->conflicts( $graph );
		$v->connectivity( $graph );
		return $v->issues;
	}

	/** Parse JSON text and validate it. Reports syntax errors with the parser message. */
	public static function validate_json( $json ) {
		$data = json_decode( (string) $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array( array( 'level' => 'error', 'code' => 'json-syntax', 'path' => '', 'message' => sprintf( /* translators: %s: parser message */ __( 'Invalid JSON: %s', 'publishrank' ), json_last_error_msg() ) ) );
		}
		if ( ! is_array( $data ) ) {
			return array( array( 'level' => 'error', 'code' => 'json-syntax', 'path' => '', 'message' => __( 'JSON-LD must be an object or a list of objects.', 'publishrank' ) ) );
		}
		$issues = array();
		$ctx    = isset( $data['@context'] ) ? $data['@context'] : ( isset( $data[0]['@context'] ) ? $data[0]['@context'] : '' );
		if ( is_string( $ctx ) && ! preg_match( '#^https?://schema\.org/?$#', $ctx ) ) {
			$issues[] = array( 'level' => 'warning', 'code' => 'context', 'path' => '@context', 'message' => __( '@context should be https://schema.org', 'publishrank' ) );
		}
		return array_merge( $issues, self::validate( $data ) );
	}

	private static function label( array $node, $i ) {
		$t = isset( $node['@type'] ) ? implode( '/', (array) $node['@type'] ) : '?';
		return $t . ( isset( $node['@id'] ) ? ' ' . $node['@id'] : ' #' . ( $i + 1 ) );
	}

	private function add( $level, $code, $path, $message ) {
		$this->issues[] = array( 'level' => $level, 'code' => $code, 'path' => $path, 'message' => $message );
	}

	private function node( array $node, $path, $top = false ) {
		if ( isset( $node['@id'] ) && 1 === count( $node ) ) {
			$this->refs[] = array( 'id' => (string) $node['@id'], 'path' => $path );
			return;
		}
		if ( ! isset( $node['@type'] ) || array() === $node['@type'] || '' === $node['@type'] ) {
			$this->add( $top ? 'error' : 'warning', 'missing-type', $path, __( 'Entity has no @type.', 'publishrank' ) );
			$types = array();
		} else {
			$types = array();
			foreach ( (array) $node['@type'] as $t ) {
				if ( ! is_string( $t ) ) {
					continue;
				}
				$t = preg_replace( '#^https?://schema\.org/#', '', $t );
				if ( ! Vocabulary::exists( $t ) ) {
					$note = Vocabulary::excluded_note( $t );
					/* translators: %s: type */
					$this->add( 'error', 'unknown-type', $path, sprintf( __( '"%s" is not a Schema.org type.', 'publishrank' ), $t ) . ( $note ? ' ' . $note : '' ) );
					continue;
				}
				$types[] = $t;
				if ( Vocabulary::in_catalog( $t ) && ! empty( Vocabulary::catalog()[ $t ]['pending'] ) ) {
					/* translators: %s: type */
					$this->add( 'notice', 'pending-type', $path, sprintf( __( '%s is in Schema.org\'s "pending" area: valid, but still under review and less widely understood.', 'publishrank' ), $t ) );
				}
			}
		}
		if ( isset( $node['@id'] ) ) {
			$id = (string) $node['@id'];
			if ( '' === $id ) {
				$this->add( 'warning', 'empty-id', $path, __( '@id is empty.', 'publishrank' ) );
			} elseif ( ! preg_match( '#^(https?://|_:|\#)#', $id ) ) {
				$this->add( 'warning', 'invalid-id', $path, __( '@id should be an absolute URL (for example https://example.com/page/#product).', 'publishrank' ) );
			}
			$this->defined[ $id ] = isset( $this->defined[ $id ] ) ? $this->defined[ $id ] + 1 : 1;
		}
		foreach ( $node as $prop => $value ) {
			if ( '@' === substr( (string) $prop, 0, 1 ) ) {
				continue;
			}
			$this->property( $types, (string) $prop, $value, $path . '.' . $prop );
		}
	}

	private function property( array $types, $prop, $value, $path ) {
		if ( preg_match( '/^[a-z][A-Za-z]*-(input|output)$/', $prop ) ) {
			return; // Schema.org Action annotations such as "query-input" (https://schema.org/docs/actions.html).
		}
		$def = Vocabulary::property( $prop );
		if ( ! $def ) {
			$by = Vocabulary::superseded_by( $prop );
			if ( $by ) {
				/* translators: 1: property 2: replacement */
				$this->add( 'warning', 'superseded-property', $path, sprintf( __( '"%1$s" is superseded in Schema.org. Use %2$s instead.', 'publishrank' ), $prop, $by ) );
			} else {
				/* translators: %s: property */
				$this->add( 'error', 'unknown-property', $path, sprintf( __( '"%s" is not a Schema.org property.', 'publishrank' ), $prop ) );
			}
			return;
		}
		if ( $types && ! Vocabulary::applies( $prop, $types ) ) {
			/* translators: 1: property 2: type */
			$this->add( 'warning', 'unsupported-property', $path, sprintf( __( '"%1$s" is not defined for %2$s. Consumers may ignore it.', 'publishrank' ), $prop, implode( '/', $types ) ) );
		}
		if ( ! empty( $def['pending'] ) ) {
			/* translators: %s: property */
			$this->add( 'notice', 'pending-property', $path, sprintf( __( '"%s" is a pending Schema.org property.', 'publishrank' ), $prop ) );
		}
		$this->value( $prop, $def['r'], $value, $path );
	}

	private function value( $prop, array $ranges, $value, $path ) {
		if ( null === $value || '' === $value || array() === $value ) {
			/* translators: %s: property */
			$this->add( 'warning', 'empty-property', $path, sprintf( __( '"%s" is empty. Remove it or give it a value.', 'publishrank' ), $prop ) );
			return;
		}
		if ( is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
			foreach ( $value as $i => $item ) {
				$this->value( $prop, $ranges, $item, $path . '[' . $i . ']' );
			}
			return;
		}
		$datatypes = array_values( array_intersect( $ranges, Vocabulary::DATATYPES ) );
		$classes   = array_values( array_diff( $ranges, Vocabulary::DATATYPES ) );
		if ( is_array( $value ) ) {
			if ( isset( $value['@id'] ) && 1 === count( $value ) ) {
				$this->refs[] = array( 'id' => (string) $value['@id'], 'path' => $path );
				return;
			}
			if ( isset( $value['@id'] ) ) {
				// An embedded entity with an @id (also listed at the top level) is a link to that entity too.
				$this->refs[] = array( 'id' => (string) $value['@id'], 'path' => $path );
			}
			if ( ! $classes ) {
				/* translators: 1: property 2: expected */
				$this->add( 'error', 'invalid-nesting', $path, sprintf( __( '"%1$s" expects %2$s, not a nested object.', 'publishrank' ), $prop, implode( ' or ', $datatypes ) ) );
				return;
			}
			if ( isset( $value['@type'] ) ) {
				$ok = false;
				foreach ( (array) $value['@type'] as $t ) {
					foreach ( $classes as $c ) {
						if ( Vocabulary::is_a( (string) $t, $c ) ) {
							$ok = true;
						}
					}
				}
				if ( ! $ok && Vocabulary::exists( (string) current( (array) $value['@type'] ) ) ) {
					/* translators: 1: property 2: given type 3: expected types */
					$this->add( 'warning', 'type-mismatch', $path, sprintf( __( '"%1$s" holds a %2$s, but Schema.org expects %3$s.', 'publishrank' ), $prop, implode( '/', (array) $value['@type'] ), implode( ' or ', $classes ) ) );
				}
			}
			$this->node( $value, $path );
			return;
		}
		if ( is_bool( $value ) ) {
			if ( ! in_array( 'Boolean', $ranges, true ) && ! in_array( 'Text', $ranges, true ) ) {
				/* translators: %s: property */
				$this->add( 'error', 'invalid-value', $path, sprintf( __( '"%s" does not accept true/false.', 'publishrank' ), $prop ) );
			}
			return;
		}
		$s = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $s ) {
			$this->add( 'warning', 'empty-property', $path, sprintf( __( '"%s" is empty. Remove it or give it a value.', 'publishrank' ), $prop ) );
			return;
		}
		// Enumerations: the value must be a member (as URL or short name).
		foreach ( $classes as $c ) {
			if ( Vocabulary::is_enum( $c ) && ! in_array( 'Text', $ranges, true ) && ! in_array( 'URL', $ranges, true ) ) {
				$member = preg_replace( '#^https?://schema\.org/#', '', $s );
				$all    = array();
				foreach ( $classes as $c2 ) {
					$all = array_merge( $all, Vocabulary::enum_members( $c2 ) );
				}
				if ( $all && ! in_array( $member, $all, true ) ) {
					/* translators: 1: value 2: property 3: examples */
					$this->add( 'error', 'invalid-enum', $path, sprintf( __( '"%1$s" is not a valid value for "%2$s". Use one of: %3$s', 'publishrank' ), $s, $prop, implode( ', ', array_slice( $all, 0, 8 ) ) . ( count( $all ) > 8 ? '…' : '' ) ) );
				}
				return;
			}
		}
		$looks_url = (bool) preg_match( '#^https?://[^\s/$.?\#].[^\s]*$#i', $s );
		if ( $datatypes && ! array_diff( $datatypes, array( 'URL' ) ) && ! $classes ) {
			if ( ! $looks_url ) {
				/* translators: %s: property */
				$this->add( 'error', 'invalid-url', $path, sprintf( __( '"%s" must be a full URL starting with https://', 'publishrank' ), $prop ) );
			}
			return;
		}
		if ( $datatypes && ! array_diff( $datatypes, array( 'Date', 'DateTime' ) ) ) {
			if ( ! self::is_iso_date( $s ) ) {
				/* translators: %s: property */
				$this->add( 'error', 'invalid-date', $path, sprintf( __( '"%s" must be an ISO 8601 date such as 2026-11-19 or 2026-11-19T09:30:00+05:00.', 'publishrank' ), $prop ) );
			}
			return;
		}
		if ( $datatypes && ! array_diff( $datatypes, array( 'Time' ) ) && ! $classes ) {
			if ( ! preg_match( '/^\d{2}:\d{2}(:\d{2})?([+-]\d{2}:\d{2}|Z)?$/', $s ) ) {
				$this->add( 'error', 'invalid-time', $path, sprintf( __( '"%s" must be a time such as 09:00 or 09:00:00.', 'publishrank' ), $prop ) );
			}
			return;
		}
		if ( $datatypes && ! array_diff( $datatypes, array( 'Number', 'Integer', 'Float' ) ) && ! $classes ) {
			if ( ! is_numeric( $s ) ) {
				/* translators: %s: property */
				$this->add( 'error', 'invalid-number', $path, sprintf( __( '"%s" must be a number.', 'publishrank' ), $prop ) );
			} elseif ( in_array( 'Integer', $datatypes, true ) && 1 === count( $datatypes ) && (string) (int) $s !== $s && ! is_int( $value ) ) {
				$this->add( 'warning', 'invalid-number', $path, sprintf( __( '"%s" should be a whole number.', 'publishrank' ), $prop ) );
			}
			return;
		}
		if ( $datatypes && ! array_diff( $datatypes, array( 'Boolean' ) ) && ! $classes ) {
			if ( ! in_array( strtolower( $s ), array( 'true', 'false', 'https://schema.org/true', 'https://schema.org/false' ), true ) ) {
				$this->add( 'error', 'invalid-value', $path, sprintf( __( '"%s" must be true or false.', 'publishrank' ), $prop ) );
			}
			return;
		}
		// Pixel sizes (ImageObject width/height as plain numbers) are the documented, widely read form.
		if ( ! $datatypes && $classes && ! $looks_url && ! ( in_array( $prop, array( 'width', 'height' ), true ) && is_numeric( $s ) ) ) {
			/* translators: 1: property 2: classes */
			$this->add( 'notice', 'text-for-entity', $path, sprintf( __( '"%1$s" expects a %2$s. Plain text is allowed by Schema.org, but a nested object is understood better.', 'publishrank' ), $prop, implode( ' or ', $classes ) ) );
		}
		if ( in_array( 'URL', $datatypes, true ) && $looks_url && ! wp_http_validate_url( $s ) && false === strpos( $s, 'localhost' ) ) {
			$this->add( 'warning', 'invalid-url', $path, sprintf( __( '"%s" contains a URL that does not look valid.', 'publishrank' ), $prop ) );
		}
	}

	public static function is_iso_date( $s ) {
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $s, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
		}
		return false;
	}

	private function references() {
		foreach ( $this->refs as $r ) {
			if ( ! isset( $this->defined[ $r['id'] ] ) && 0 === strpos( $r['id'], '#' ) ) {
				$this->add( 'error', 'missing-relationship', $r['path'], sprintf( __( 'Points to %s, which is not defined in this graph.', 'publishrank' ), $r['id'] ) );
			} elseif ( ! isset( $this->defined[ $r['id'] ] ) ) {
				/* translators: %s: @id */
				$this->add( 'warning', 'missing-relationship', $r['path'], sprintf( __( 'Points to %s, which is not defined on this page. Search engines may not resolve it.', 'publishrank' ), $r['id'] ) );
			}
		}
	}

	private function duplicates( array $graph ) {
		$seen_id = array();
		$seen_nm = array();
		foreach ( $graph as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$label = self::label( $node, $i );
			if ( ! empty( $node['@id'] ) ) {
				$id = (string) $node['@id'];
				if ( isset( $seen_id[ $id ] ) ) {
					$same = wp_json_encode( $seen_id[ $id ] ) === wp_json_encode( $node );
					/* translators: %s: @id */
					$this->add( $same ? 'warning' : 'error', 'duplicate-entity', $label, sprintf( $same ? __( '%s is printed twice.', 'publishrank' ) : __( '%s is defined twice with different values. Merge them into one entity.', 'publishrank' ), $id ) );
				}
				$seen_id[ $id ] = $node;
			}
			if ( isset( $node['@type'], $node['name'] ) && is_scalar( $node['name'] ) ) {
				$key = implode( '/', (array) $node['@type'] ) . '|' . strtolower( (string) $node['name'] );
				if ( isset( $seen_nm[ $key ] ) && ( $seen_nm[ $key ] !== ( $node['@id'] ?? '' ) || empty( $node['@id'] ) ) ) {
					/* translators: %s: label */
					$this->add( 'warning', 'duplicate-entity', $label, sprintf( __( 'Another %s with the same name exists. If they describe the same thing, give them one @id.', 'publishrank' ), implode( '/', (array) $node['@type'] ) ) );
				}
				$seen_nm[ $key ] = (string) ( $node['@id'] ?? '' );
			}
		}
	}

	private function conflicts( array $graph ) {
		$count = static function ( $family ) use ( $graph ) {
			$n = 0;
			foreach ( $graph as $node ) {
				if ( is_array( $node ) && isset( $node['@type'] ) && Vocabulary::any_is_a( (array) $node['@type'], $family ) ) {
					++$n;
				}
			}
			return $n;
		};
		foreach ( array( 'WebPage' => __( 'Only one WebPage should describe a URL.', 'publishrank' ), 'WebSite' => __( 'Only one WebSite entity should be printed.', 'publishrank' ), 'BreadcrumbList' => __( 'More than one breadcrumb trail is printed.', 'publishrank' ) ) as $family => $msg ) {
			if ( $count( $family ) > 1 ) {
				$this->add( 'warning', 'conflicting-schema', $family, $msg );
			}
		}
		$products = $count( 'Product' );
		if ( $products > 1 ) {
			$this->add( 'warning', 'conflicting-schema', 'Product', __( 'Several Product entities are printed. On a single product page, use one Product (or a ProductGroup with variants).', 'publishrank' ) );
		}
		$articles = 0;
		foreach ( $graph as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) && Vocabulary::any_is_a( (array) $node['@type'], 'Article' ) && isset( $node['mainEntityOfPage'] ) ) {
				++$articles;
			}
		}
		if ( $articles > 1 ) {
			$this->add( 'warning', 'conflicting-schema', 'Article', __( 'Several Article entities claim to be the main entity of the page.', 'publishrank' ) );
		}
	}

	private function connectivity( array $graph ) {
		if ( count( $graph ) < 2 ) {
			return;
		}
		$referenced = array();
		foreach ( $this->refs as $r ) {
			$referenced[ $r['id'] ] = true;
		}
		foreach ( $graph as $i => $node ) {
			if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
				continue;
			}
			$types = (array) $node['@type'];
			if ( Vocabulary::any_is_a( $types, 'WebSite' ) || Vocabulary::any_is_a( $types, 'Organization' ) ) {
				continue;
			}
			$id       = (string) ( $node['@id'] ?? '' );
			$out_refs = false !== strpos( (string) wp_json_encode( $node ), '"@id"' ) && substr_count( (string) wp_json_encode( $node ), '"@id"' ) > ( '' !== $id ? 1 : 0 );
			if ( ( '' === $id || empty( $referenced[ $id ] ) ) && ! $out_refs ) {
				$this->add( 'notice', 'disconnected-entity', self::label( $node, $i ), __( 'This entity is not connected to the rest of the graph (no reference in or out).', 'publishrank' ) );
			}
		}
	}

	/* --------------------------------------------------------------- Google */

	/**
	 * Google Search feature notes for each top-level node. This is documentation-based guidance, not a
	 * guarantee: Google decides eligibility and display. Confirm with the Rich Results Test.
	 *
	 * @return array<int,array>
	 */
	public static function eligibility( array $graph ) {
		$out = array();
		foreach ( array_values( $graph ) as $i => $node ) {
			if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
				continue;
			}
			foreach ( (array) $node['@type'] as $type ) {
				$g = Vocabulary::google( (string) $type );
				if ( ! $g ) {
					continue;
				}
				$has     = static function ( $p ) use ( $node ) {
					return isset( $node[ $p ] ) && '' !== $node[ $p ] && array() !== $node[ $p ];
				};
				$missing = array_values( array_filter( $g['req'], static function ( $p ) use ( $has ) { return ! $has( $p ); } ) );
				foreach ( isset( $g['oneof'] ) ? $g['oneof'] : array() as $group ) {
					if ( ! array_filter( $group, $has ) ) {
						$missing[] = implode( ' | ', $group );
					}
				}
				$rec    = array_values( array_filter( $g['rec'], static function ( $p ) use ( $has ) { return ! $has( $p ); } ) );
				$status = ! empty( $g['retired'] ) ? 'retired' : ( $missing ? 'incomplete' : 'meets-documented-requirements' );
				$out[]  = array(
					'node'        => self::label( $node, $i ),
					'type'        => (string) $type,
					'feature'     => $g['feature'],
					'via'         => $g['via'],
					'status'      => $status,
					'missing'     => $missing,
					'recommended' => $rec,
					'note'        => isset( $g['note'] ) ? $g['note'] : '',
					'doc'         => $g['doc'],
					'reviewed'    => isset( $g['reviewed'] ) ? (string) $g['reviewed'] : '',
				);
				break;
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------- Page HTML */

	/**
	 * Extract every JSON-LD block from page HTML and tell who printed it.
	 *
	 * @return array<int,array{source:string,raw:string,data:mixed,error:string}>
	 */
	public static function blocks_from_html( $html ) {
		$out = array();
		if ( ! preg_match_all( '#(<!--[^>]*-->\s*)?<script\b([^>]*)type=["\']application/ld\+json["\']([^>]*)>(.*?)</script>#is', (string) $html, $m, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $m as $match ) {
			$attrs  = $match[2] . ' ' . $match[3];
			$raw    = trim( html_entity_decode( $match[4], ENT_QUOTES | ENT_HTML5 ) );
			$raw    = trim( preg_replace( '#^\s*<!\[CDATA\[|\]\]>\s*$#', '', $raw ) );
			$data   = json_decode( $raw, true );
			$error  = JSON_ERROR_NONE === json_last_error() ? '' : json_last_error_msg();
			$source = 'third-party';
			if ( false !== stripos( $match[1], 'PublishRank' ) ) {
				$source = 'publishrank';
			} elseif ( false !== stripos( $attrs, 'yoast' ) ) {
				$source = 'yoast';
			} elseif ( false !== stripos( $attrs, 'rank-math' ) ) {
				$source = 'rank-math';
			} elseif ( is_array( $data ) && ( false !== strpos( $raw, '"Product"' ) || false !== strpos( $raw, 'schema.org\/"' ) || false !== strpos( $raw, '"https://schema.org/"' ) ) && class_exists( 'WooCommerce' ) ) {
				$source = 'woocommerce';
			}
			$out[] = array( 'source' => $source, 'raw' => $raw, 'data' => $data, 'error' => $error );
		}
		return $out;
	}

	/** Flatten blocks into top-level nodes, tagging each with its source. @return array<int,array> */
	public static function nodes_from_blocks( array $blocks ) {
		$nodes = array();
		foreach ( $blocks as $b ) {
			if ( ! is_array( $b['data'] ) ) {
				continue;
			}
			$list = isset( $b['data']['@graph'] ) ? $b['data']['@graph'] : ( isset( $b['data']['@type'] ) ? array( $b['data'] ) : $b['data'] );
			foreach ( (array) $list as $n ) {
				if ( is_array( $n ) ) {
					unset( $n['@context'] );
					$n['__source'] = $b['source'];
					$nodes[]       = $n;
				}
			}
		}
		return $nodes;
	}

	/**
	 * Full page diagnostics: syntax errors per block, vocabulary checks, cross-plugin conflicts and the
	 * Google notes. @return array
	 */
	public static function page_report( $html, $url = '' ) {
		$blocks = self::blocks_from_html( $html );
		$issues = array();
		foreach ( $blocks as $i => $b ) {
			if ( '' !== $b['error'] ) {
				/* translators: 1: block number 2: source 3: parser message */
				$issues[] = array( 'level' => 'error', 'code' => 'json-syntax', 'path' => 'block ' . ( $i + 1 ), 'message' => sprintf( __( 'JSON-LD block %1$d (%2$s) is not valid JSON: %3$s', 'publishrank' ), $i + 1, $b['source'], $b['error'] ) );
			}
		}
		$nodes   = self::nodes_from_blocks( $blocks );
		$sources = array_values( array_unique( wp_list_pluck( $blocks, 'source' ) ) );
		$clean   = array_map( static function ( $n ) { unset( $n['__source'] ); return $n; }, $nodes );
		$issues  = array_merge( $issues, self::validate( $clean ) );
		// Cross-source conflicts.
		$by_type = array();
		foreach ( $nodes as $n ) {
			foreach ( (array) ( $n['@type'] ?? array() ) as $t ) {
				$by_type[ (string) $t ][ $n['__source'] ] = true;
			}
		}
		foreach ( $by_type as $type => $src ) {
			if ( count( $src ) < 2 ) {
				continue;
			}
			$level = in_array( $type, array( 'Product', 'Organization', 'WebSite', 'WebPage', 'BreadcrumbList', 'Article', 'NewsArticle', 'LocalBusiness', 'FAQPage' ), true ) ? 'error' : 'warning';
			$code  = isset( $src['woocommerce'] ) ? 'woocommerce-conflict' : 'third-party-conflict';
			/* translators: 1: type 2: sources */
			$issues[] = array( 'level' => $level, 'code' => $code, 'path' => $type, 'message' => sprintf( __( '%1$s is printed by more than one source (%2$s). Keep one source for each entity.', 'publishrank' ), $type, implode( ', ', array_keys( $src ) ) ) );
		}
		foreach ( array( 'yoast', 'rank-math' ) as $seo ) {
			if ( in_array( $seo, $sources, true ) ) {
				/* translators: %s: plugin */
				$issues[] = array( 'level' => 'warning', 'code' => 'third-party-conflict', 'path' => $seo, 'message' => sprintf( __( 'Another SEO plugin (%s) also prints structured data on this page.', 'publishrank' ), $seo ) );
			}
		}
		return array(
			'url'         => $url,
			'blocks'      => array_map( static function ( $b ) { return array( 'source' => $b['source'], 'valid_json' => '' === $b['error'], 'bytes' => strlen( $b['raw'] ) ); }, $blocks ),
			'nodes'       => $clean,
			'issues'      => $issues,
			'eligibility' => self::eligibility( $clean ),
			'tools'       => array(
				'rich_results' => self::RICH_RESULTS_TEST . rawurlencode( $url ),
				'schema_org'   => self::SCHEMA_VALIDATOR . rawurlencode( $url ),
			),
		);
	}

	/** Count issues by level. @return array{error:int,warning:int,notice:int} */
	public static function summary( array $issues ) {
		$s = array( 'error' => 0, 'warning' => 0, 'notice' => 0 );
		foreach ( $issues as $i ) {
			if ( isset( $s[ $i['level'] ] ) ) {
				++$s[ $i['level'] ];
			}
		}
		return $s;
	}
}
