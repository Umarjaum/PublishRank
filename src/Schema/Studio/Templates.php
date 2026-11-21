<?php
/**
 * Schema Studio templates: storage, sanitising, built-in starters, assignment rules, revisions,
 * import and export.
 *
 * A template describes one schema.org entity:
 *   id, name, description, type (schema.org class), status (enabled|disabled), builtin (bool),
 *   priority (int), is_default (bool), mode (merge|add|replace), main_entity (bool), replace_article (bool),
 *   conditions { post_types[], posts[], exclude[], taxonomies[{taxonomy,terms[]}], archives[], front (bool), business_types[] },
 *   properties [ Property ]
 * Property:
 *   name, source (static|dynamic|object|list|ref), value, field, fallback, format, required (bool),
 *   type (object @type), props [Property] (object), items [Property] (list)
 *
 * Built-in starter templates ship disabled, so upgrading never changes existing output.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Studio;

defined( 'ABSPATH' ) || exit;

final class Templates {

	const OPTION     = 'publishrank_schema_templates';
	const REVISIONS  = 'publishrank_schema_template_revisions';
	const SETTINGS   = 'publishrank_schema_studio_settings';
	const MAX        = 100;
	const MAX_DEPTH  = 5;
	const FORMATS    = array( '', 'text', 'url', 'date', 'datetime', 'number', 'integer', 'boolean', 'duration_minutes', 'enum', 'truncate160', 'lines' );
	const SOURCES    = array( 'static', 'dynamic', 'object', 'list', 'ref' );
	const MODES      = array( 'merge', 'add', 'replace' );

	/** @return array<string,mixed> */
	public static function settings() {
		$s = get_option( self::SETTINGS, array() );
		return array_merge(
			array(
				'enabled'        => 1,
				'connect_orphans'=> 1,
			),
			is_array( $s ) ? $s : array()
		);
	}

	/** All templates (stored + built-ins not yet stored), keyed by id. */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( self::builtins() as $id => $tpl ) {
			$out[ $id ] = isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ? array_merge( $tpl, self::sanitize( $stored[ $id ] ), array( 'builtin' => true, 'id' => $id ) ) : $tpl;
		}
		foreach ( $stored as $id => $tpl ) {
			if ( ! isset( $out[ $id ] ) && is_array( $tpl ) ) {
				$out[ $id ] = self::sanitize( $tpl );
				$out[ $id ]['id'] = (string) $id;
			}
		}
		return $out;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** Save a template (create when id is empty). Returns the saved template or a WP_Error. */
	public static function save( array $input ) {
		$tpl = self::sanitize( $input );
		if ( '' === $tpl['type'] || ! Vocabulary::exists( $tpl['type'] ) ) {
			return new \WP_Error( 'publishrank_schema_type', __( 'Choose a valid schema.org type.', 'publishrank' ) );
		}
		if ( '' === $tpl['name'] ) {
			$tpl['name'] = $tpl['type'];
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$id     = isset( $input['id'] ) ? sanitize_key( (string) $input['id'] ) : '';
		if ( '' === $id ) {
			$id = self::new_id( $tpl['name'] );
		}
		if ( ! isset( $stored[ $id ] ) && ! isset( self::builtins()[ $id ] ) && count( $stored ) >= self::MAX ) {
			return new \WP_Error( 'publishrank_schema_limit', __( 'You have reached the maximum number of templates.', 'publishrank' ) );
		}
		if ( isset( $stored[ $id ] ) || isset( self::builtins()[ $id ] ) ) {
			$previous = self::get( $id );
			if ( $previous ) {
				self::push_revision( $id, $previous );
			}
		}
		$tpl['id']       = $id;
		$tpl['builtin']  = isset( self::builtins()[ $id ] );
		$tpl['modified'] = gmdate( 'c' );
		if ( $tpl['is_default'] ) {
			// One default per schema type and post type: clear the flag on competing templates.
			foreach ( self::all() as $other_id => $other ) {
				if ( $other_id !== $id && ! empty( $other['is_default'] ) && $other['type'] === $tpl['type'] && array_intersect( $other['conditions']['post_types'], $tpl['conditions']['post_types'] ) ) {
					$other['is_default'] = false;
					$stored[ $other_id ] = $other;
				}
			}
		}
		$stored[ $id ] = $tpl;
		update_option( self::OPTION, $stored, false );
		return self::get( $id );
	}

	public static function delete( $id ) {
		if ( isset( self::builtins()[ $id ] ) ) {
			return new \WP_Error( 'publishrank_schema_builtin', __( 'Built-in templates cannot be deleted. Disable it, or duplicate it and edit the copy.', 'publishrank' ) );
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored[ $id ] ) ) {
			return new \WP_Error( 'publishrank_schema_missing', __( 'Template not found.', 'publishrank' ) );
		}
		self::push_revision( $id, $stored[ $id ] );
		unset( $stored[ $id ] );
		update_option( self::OPTION, $stored, false );
		return true;
	}

	public static function duplicate( $id ) {
		$tpl = self::get( $id );
		if ( ! $tpl ) {
			return new \WP_Error( 'publishrank_schema_missing', __( 'Template not found.', 'publishrank' ) );
		}
		/* translators: %s: template name */
		$tpl['name']       = sprintf( __( '%s (copy)', 'publishrank' ), $tpl['name'] );
		$tpl['id']         = '';
		$tpl['builtin']    = false;
		$tpl['is_default'] = false;
		$tpl['status']     = 'disabled';
		return self::save( $tpl );
	}

	/** Restore the template to a stored revision (index 0 = most recent). */
	public static function restore( $id, $index = 0 ) {
		$revs = self::revisions( $id );
		if ( ! isset( $revs[ $index ]['template'] ) ) {
			return new \WP_Error( 'publishrank_schema_revision', __( 'That revision no longer exists.', 'publishrank' ) );
		}
		$tpl       = $revs[ $index ]['template'];
		$tpl['id'] = $id;
		return self::save( $tpl );
	}

	/** Reset a built-in template to its shipped definition. */
	public static function reset_builtin( $id ) {
		$b = self::builtins();
		if ( ! isset( $b[ $id ] ) ) {
			return new \WP_Error( 'publishrank_schema_builtin', __( 'Only built-in templates can be reset to defaults.', 'publishrank' ) );
		}
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( isset( $stored[ $id ] ) ) {
			self::push_revision( $id, self::get( $id ) );
			unset( $stored[ $id ] );
			update_option( self::OPTION, $stored, false );
		}
		return self::get( $id );
	}

	/** @return array<int,array{saved:string,template:array}> */
	public static function revisions( $id ) {
		$all = get_option( self::REVISIONS, array() );
		return is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? array_values( $all[ $id ] ) : array();
	}

	private static function push_revision( $id, $tpl ) {
		$all = get_option( self::REVISIONS, array() );
		$all = is_array( $all ) ? $all : array();
		$list = isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
		array_unshift( $list, array( 'saved' => gmdate( 'c' ), 'template' => $tpl ) );
		$all[ $id ] = array_slice( $list, 0, 10 );
		update_option( self::REVISIONS, $all, false );
	}

	private static function new_id( $name ) {
		$base = sanitize_key( str_replace( ' ', '-', strtolower( remove_accents( (string) $name ) ) ) );
		$base = '' === $base ? 'template' : substr( $base, 0, 40 );
		$all  = self::all();
		$id   = $base;
		$i    = 2;
		while ( isset( $all[ $id ] ) ) {
			$id = $base . '-' . $i++;
		}
		return $id;
	}

	/* ---------------------------------------------------------------- Sanitising */

	public static function sanitize( array $in ) {
		$text = static function ( $k, $max = 200 ) use ( $in ) {
			return isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? substr( sanitize_text_field( (string) $in[ $k ] ), 0, $max ) : '';
		};
		$type = $text( 'type', 80 );
		$cond = isset( $in['conditions'] ) && is_array( $in['conditions'] ) ? $in['conditions'] : array();
		$tpl  = array(
			'id'          => isset( $in['id'] ) ? sanitize_key( (string) $in['id'] ) : '',
			'name'        => $text( 'name', 120 ),
			'description' => $text( 'description', 300 ),
			'type'        => preg_match( '/^[A-Z][A-Za-z0-9]{1,60}$/', $type ) ? $type : '',
			'status'      => isset( $in['status'] ) && 'enabled' === $in['status'] ? 'enabled' : 'disabled',
			'builtin'     => ! empty( $in['builtin'] ),
			'priority'    => isset( $in['priority'] ) ? max( -100, min( 100, (int) $in['priority'] ) ) : 10,
			'is_default'  => ! empty( $in['is_default'] ),
			'mode'        => isset( $in['mode'] ) && in_array( $in['mode'], self::MODES, true ) ? $in['mode'] : 'merge',
			'main_entity' => ! empty( $in['main_entity'] ),
			'replace_article' => ! empty( $in['replace_article'] ),
			'conditions'  => array(
				'post_types'     => self::keys( isset( $cond['post_types'] ) ? $cond['post_types'] : array() ),
				'posts'          => self::ints( isset( $cond['posts'] ) ? $cond['posts'] : array() ),
				'exclude'        => self::ints( isset( $cond['exclude'] ) ? $cond['exclude'] : array() ),
				'taxonomies'     => self::tax_rules( isset( $cond['taxonomies'] ) ? $cond['taxonomies'] : array() ),
				'archives'       => self::keys( isset( $cond['archives'] ) ? $cond['archives'] : array() ),
				'front'          => ! empty( $cond['front'] ),
				'business_types' => array_values( array_filter( array_map( static function ( $t ) { return is_string( $t ) && preg_match( '/^[A-Z][A-Za-z0-9]{1,60}$/', $t ) ? $t : ''; }, isset( $cond['business_types'] ) ? (array) $cond['business_types'] : array() ) ) ),
			),
			'properties'  => self::props( isset( $in['properties'] ) ? $in['properties'] : array(), 0 ),
			'modified'    => isset( $in['modified'] ) && is_string( $in['modified'] ) ? substr( sanitize_text_field( $in['modified'] ), 0, 40 ) : '',
		);
		return $tpl;
	}

	private static function keys( $v ) {
		return array_values( array_unique( array_filter( array_map( static function ( $x ) { return is_scalar( $x ) ? sanitize_key( (string) $x ) : ''; }, is_array( $v ) ? $v : preg_split( '/[\s,]+/', (string) $v ) ) ) ) );
	}

	private static function ints( $v ) {
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $v ) ? $v : preg_split( '/[\s,]+/', (string) $v ) ) ) ) );
	}

	private static function tax_rules( $v ) {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['taxonomy'] ) ) {
				continue;
			}
			$out[] = array( 'taxonomy' => sanitize_key( (string) $rule['taxonomy'] ), 'terms' => self::ints( isset( $rule['terms'] ) ? $rule['terms'] : array() ) );
		}
		return array_slice( $out, 0, 10 );
	}

	/** @return array<int,array> */
	private static function props( $list, $depth ) {
		$out = array();
		if ( ! is_array( $list ) || $depth > self::MAX_DEPTH ) {
			return $out;
		}
		foreach ( array_slice( $list, 0, 80 ) as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$name = isset( $p['name'] ) && is_scalar( $p['name'] ) ? preg_replace( '/[^A-Za-z0-9@]/', '', (string) $p['name'] ) : '';
			if ( '' === $name && 0 === $depth ) {
				continue;
			}
			$source = isset( $p['source'] ) && in_array( $p['source'], self::SOURCES, true ) ? $p['source'] : 'static';
			$type   = isset( $p['type'] ) && is_string( $p['type'] ) && preg_match( '/^[A-Z][A-Za-z0-9]{1,60}$/', $p['type'] ) ? $p['type'] : '';
			$value  = isset( $p['value'] ) && is_scalar( $p['value'] ) ? (string) $p['value'] : '';
			$out[]  = array(
				'name'     => substr( $name, 0, 80 ),
				'source'   => $source,
				'value'    => substr( sanitize_textarea_field( $value ), 0, 5000 ),
				'field'    => isset( $p['field'] ) && is_scalar( $p['field'] ) ? preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) $p['field'] ) : '',
				'fallback' => isset( $p['fallback'] ) && is_scalar( $p['fallback'] ) ? substr( sanitize_text_field( (string) $p['fallback'] ), 0, 500 ) : '',
				'format'   => isset( $p['format'] ) && in_array( $p['format'], self::FORMATS, true ) ? $p['format'] : '',
				'required' => ! empty( $p['required'] ),
				'type'     => $type,
				'props'    => 'object' === $source ? self::props( isset( $p['props'] ) ? $p['props'] : array(), $depth + 1 ) : array(),
				'items'    => 'list' === $source ? self::props( isset( $p['items'] ) ? $p['items'] : array(), $depth + 1 ) : array(),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------- Import / export */

	public static function export( $ids = array() ) {
		$all = self::all();
		if ( $ids ) {
			$all = array_intersect_key( $all, array_flip( (array) $ids ) );
		}
		return array(
			'format'    => 'publishrank-schema-templates',
			'version'   => 1,
			'plugin'    => defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '',
			'exported'  => gmdate( 'c' ),
			'templates' => array_values( $all ),
		);
	}

	/** @return array{imported:int,skipped:array<int,string>}|\WP_Error */
	public static function import( $data, $overwrite = false ) {
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== 'publishrank-schema-templates' || empty( $data['templates'] ) || ! is_array( $data['templates'] ) ) {
			return new \WP_Error( 'publishrank_schema_import', __( 'This file is not a PublishRank schema template export.', 'publishrank' ) );
		}
		$done    = 0;
		$skipped = array();
		$all     = self::all();
		foreach ( array_slice( $data['templates'], 0, self::MAX ) as $tpl ) {
			if ( ! is_array( $tpl ) ) {
				continue;
			}
			$id = isset( $tpl['id'] ) ? sanitize_key( (string) $tpl['id'] ) : '';
			if ( '' !== $id && isset( $all[ $id ] ) && ! $overwrite ) {
				$tpl['id'] = '';
			}
			$saved = self::save( $tpl );
			if ( is_wp_error( $saved ) ) {
				$skipped[] = ( isset( $tpl['name'] ) ? (string) $tpl['name'] : '?' ) . ': ' . $saved->get_error_message();
			} else {
				++$done;
			}
		}
		return array( 'imported' => $done, 'skipped' => $skipped );
	}

	/* ---------------------------------------------------------------- Matching */

	/**
	 * Templates that apply to a context, highest priority first. Per-post assignments (post meta) can add,
	 * remove or switch off templates for one item.
	 *
	 * @return array<int,array>
	 */
	public static function for_context( Context $ctx, $include_disabled = false ) {
		$assign = $ctx->post ? self::post_assignment( $ctx->post->ID ) : array( 'mode' => 'inherit', 'add' => array(), 'remove' => array() );
		if ( 'none' === $assign['mode'] ) {
			return array();
		}
		$matches = array();
		foreach ( self::all() as $id => $tpl ) {
			$forced = in_array( $id, $assign['add'], true );
			if ( ! $forced && ( 'enabled' !== $tpl['status'] && ! $include_disabled ) ) {
				continue;
			}
			if ( in_array( $id, $assign['remove'], true ) ) {
				continue;
			}
			if ( $forced || self::matches( $tpl, $ctx ) ) {
				$tpl['_forced'] = $forced;
				$matches[]      = $tpl;
			}
		}
		// Default templates win ties; then priority; then name for stable output.
		usort(
			$matches,
			static function ( $a, $b ) {
				$pa = $a['priority'] + ( $a['is_default'] ? 1000 : 0 ) + ( $a['_forced'] ? 2000 : 0 );
				$pb = $b['priority'] + ( $b['is_default'] ? 1000 : 0 ) + ( $b['_forced'] ? 2000 : 0 );
				return $pb === $pa ? strcmp( $a['name'], $b['name'] ) : $pb - $pa;
			}
		);
		// Only the highest-ranked template per schema type and mode=replace/merge applies; "add" templates stack.
		$seen = array();
		$out  = array();
		foreach ( $matches as $tpl ) {
			$key = $tpl['type'] . '|' . ( 'add' === $tpl['mode'] ? $tpl['id'] : 'single' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $tpl;
		}
		return $out;
	}

	/** Whether a template's conditions match a context. */
	public static function matches( array $tpl, Context $ctx ) {
		$c = $tpl['conditions'];
		if ( $c['business_types'] ) {
			$profile = class_exists( '\\PublishRankSEO\\SEO\\LocalSEO' ) ? \PublishRankSEO\SEO\LocalSEO::profile() : array();
			if ( empty( $profile['type'] ) || ! in_array( $profile['type'], $c['business_types'], true ) ) {
				return false;
			}
		}
		if ( $ctx->post ) {
			$id = (int) $ctx->post->ID;
			if ( in_array( $id, $c['exclude'], true ) ) {
				return false;
			}
			if ( in_array( $id, $c['posts'], true ) ) {
				return true;
			}
			if ( $ctx->is_front && $c['front'] ) {
				return true;
			}
			if ( ! in_array( $ctx->post->post_type, $c['post_types'], true ) ) {
				return false;
			}
			foreach ( $c['taxonomies'] as $rule ) {
				if ( ! $rule['terms'] ) {
					continue;
				}
				if ( ! has_term( $rule['terms'], $rule['taxonomy'], $ctx->post ) ) {
					return false;
				}
			}
			return true;
		}
		if ( $ctx->term ) {
			if ( ! in_array( $ctx->term->taxonomy, $c['archives'], true ) ) {
				return false;
			}
			foreach ( $c['taxonomies'] as $rule ) {
				if ( $rule['taxonomy'] === $ctx->term->taxonomy && $rule['terms'] && ! in_array( (int) $ctx->term->term_id, $rule['terms'], true ) ) {
					return false;
				}
			}
			return true;
		}
		return $ctx->is_front && $c['front'];
	}

	const POST_META = '_publishrank_schema_studio';

	/** @return array{mode:string,add:array<int,string>,remove:array<int,string>,overrides:array<string,array<string,string>>} */
	public static function post_assignment( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::POST_META, true );
		$raw = is_array( $raw ) ? $raw : array();
		$ids = static function ( $v ) {
			return array_values( array_filter( array_map( 'sanitize_key', is_array( $v ) ? $v : array() ) ) );
		};
		$overrides = array();
		foreach ( isset( $raw['overrides'] ) && is_array( $raw['overrides'] ) ? $raw['overrides'] : array() as $tid => $props ) {
			if ( ! is_array( $props ) ) {
				continue;
			}
			foreach ( $props as $name => $value ) {
				$name = preg_replace( '/[^A-Za-z0-9]/', '', (string) $name );
				if ( '' !== $name && is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$overrides[ sanitize_key( $tid ) ][ $name ] = substr( sanitize_textarea_field( (string) $value ), 0, 2000 );
				}
			}
		}
		return array(
			'mode'      => isset( $raw['mode'] ) && 'none' === $raw['mode'] ? 'none' : 'inherit',
			'add'       => $ids( isset( $raw['add'] ) ? $raw['add'] : array() ),
			'remove'    => $ids( isset( $raw['remove'] ) ? $raw['remove'] : array() ),
			'overrides' => $overrides,
		);
	}

	/* ---------------------------------------------------------------- Built-ins */

	private static function p( $name, $source, $a = '', $extra = array() ) {
		$base = array( 'name' => $name, 'source' => $source, 'value' => '', 'field' => '', 'fallback' => '', 'format' => '', 'required' => false, 'type' => '', 'props' => array(), 'items' => array() );
		if ( 'dynamic' === $source || 'ref' === $source ) {
			$base['field'] = $a;
		} elseif ( 'static' === $source ) {
			$base['value'] = $a;
		} elseif ( 'object' === $source ) {
			$base['type'] = $a;
		}
		return array_merge( $base, $extra );
	}

	private static function cond( $post_types = array(), $extra = array() ) {
		return array_merge( array( 'post_types' => $post_types, 'posts' => array(), 'exclude' => array(), 'taxonomies' => array(), 'archives' => array(), 'front' => false, 'business_types' => array() ), $extra );
	}

	/** Starter templates. All ship disabled. @return array<string,array> */
	public static function builtins() {
		static $b = null;
		if ( null !== $b ) {
			return $b;
		}
		$t = static function ( $id, $name, $desc, $type, $cond, $props, $extra = array() ) {
			return array_merge(
				array( 'id' => $id, 'name' => $name, 'description' => $desc, 'type' => $type, 'status' => 'disabled', 'builtin' => true, 'priority' => 10, 'is_default' => false, 'mode' => 'merge', 'main_entity' => true, 'replace_article' => ! Vocabulary::is_a( $type, 'Article' ) && ! Vocabulary::is_a( $type, 'WebPage' ), 'conditions' => $cond, 'properties' => $props, 'modified' => '' ),
				$extra
			);
		};
		$p = array( __CLASS__, 'p' );
		$b = array(
			'article-enrichment' => $t(
				'article-enrichment',
				__( 'Article enrichment', 'publishrank' ),
				__( 'Adds word count, language, keywords and section to the Article PublishRank already prints for posts.', 'publishrank' ),
				'Article',
				self::cond( array( 'post' ) ),
				array( $p( 'wordCount', 'dynamic', 'post.word_count', array( 'format' => 'integer' ) ), $p( 'inLanguage', 'dynamic', 'post.language' ), $p( 'articleSection', 'dynamic', 'post.primary_category' ), $p( 'keywords', 'dynamic', 'post.tags' ) ),
				array( 'mode' => 'merge' )
			),
			'service-page' => $t(
				'service-page',
				__( 'Service page', 'publishrank' ),
				__( 'A Service provided by your business, for service landing pages.', 'publishrank' ),
				'Service',
				self::cond( array( 'page' ) ),
				array( $p( 'name', 'dynamic', 'post.title', array( 'required' => true ) ), $p( 'description', 'dynamic', 'post.description' ), $p( 'url', 'dynamic', 'post.canonical' ), $p( 'image', 'dynamic', 'post.featured_image' ), $p( 'provider', 'ref', 'ref.local_business', array( 'fallback' => '' ) ), $p( 'areaServed', 'dynamic', 'local.area_served' ), $p( 'serviceType', 'dynamic', 'meta:service_type' ) )
			),
			'course' => $t(
				'course',
				__( 'Course', 'publishrank' ),
				__( 'A course offered by your school, college, university or training institute.', 'publishrank' ),
				'Course',
				self::cond( array() ),
				array( $p( 'name', 'dynamic', 'post.title', array( 'required' => true ) ), $p( 'description', 'dynamic', 'post.description', array( 'required' => true ) ), $p( 'url', 'dynamic', 'post.canonical' ), $p( 'provider', 'ref', 'ref.organization' ), $p( 'courseCode', 'dynamic', 'meta:course_code' ), $p( 'educationalCredentialAwarded', 'dynamic', 'meta:credential' ), $p( 'inLanguage', 'dynamic', 'post.language' ), $p( 'hasCourseInstance', 'object', 'CourseInstance', array( 'props' => array( $p( 'courseMode', 'dynamic', 'meta:course_mode', array( 'fallback' => 'Onsite' ) ), $p( 'startDate', 'dynamic', 'meta:course_start', array( 'format' => 'date' ) ), $p( 'endDate', 'dynamic', 'meta:course_end', array( 'format' => 'date' ) ), $p( 'location', 'dynamic', 'local.address' ) ) ) ) )
			),
			'event' => $t(
				'event',
				__( 'Event', 'publishrank' ),
				__( 'An event with date and place, mapped from custom fields (event_start, event_end, venue_name).', 'publishrank' ),
				'Event',
				self::cond( array() ),
				array( $p( 'name', 'dynamic', 'post.title', array( 'required' => true ) ), $p( 'description', 'dynamic', 'post.description' ), $p( 'image', 'dynamic', 'post.featured_image' ), $p( 'startDate', 'dynamic', 'meta:event_start', array( 'required' => true, 'format' => 'datetime' ) ), $p( 'endDate', 'dynamic', 'meta:event_end', array( 'format' => 'datetime' ) ), $p( 'eventStatus', 'static', 'https://schema.org/EventScheduled' ), $p( 'eventAttendanceMode', 'static', 'https://schema.org/OfflineEventAttendanceMode' ), $p( 'location', 'object', 'Place', array( 'required' => true, 'props' => array( $p( 'name', 'dynamic', 'meta:venue_name', array( 'required' => true ) ), $p( 'address', 'dynamic', 'meta:venue_address' ) ) ) ), $p( 'organizer', 'ref', 'ref.organization' ) )
			),
			'medical-web-page' => $t(
				'medical-web-page',
				__( 'Medical web page', 'publishrank' ),
				__( 'Marks health information pages of hospitals and clinics as MedicalWebPage with review date and audience.', 'publishrank' ),
				'MedicalWebPage',
				self::cond( array(), array( 'business_types' => array( 'Hospital', 'MedicalClinic', 'Physician', 'Dentist', 'Pharmacy', 'MedicalBusiness' ) ) ),
				array( $p( 'name', 'dynamic', 'post.title', array( 'required' => true ) ), $p( 'lastReviewed', 'dynamic', 'meta:last_reviewed', array( 'format' => 'date' ) ), $p( 'reviewedBy', 'ref', 'ref.local_business' ), $p( 'about', 'object', 'MedicalCondition', array( 'props' => array( $p( 'name', 'dynamic', 'meta:condition_name', array( 'required' => true ) ) ) ) ) ),
				array( 'mode' => 'merge', 'main_entity' => false )
			),
			'product-enrichment' => $t(
				'product-enrichment',
				__( 'WooCommerce product enrichment', 'publishrank' ),
				__( 'Adds brand, GTIN, weight and category to the Product WooCommerce prints. Ratings are added only when real reviews exist.', 'publishrank' ),
				'Product',
				self::cond( array( 'product' ) ),
				array( $p( 'brand', 'object', 'Brand', array( 'props' => array( $p( 'name', 'dynamic', 'woo.brand', array( 'required' => true ) ) ) ) ), $p( 'gtin', 'dynamic', 'woo.gtin' ), $p( 'weight', 'dynamic', 'woo.weight' ), $p( 'category', 'dynamic', 'woo.category' ), $p( 'aggregateRating', 'dynamic', 'woo.aggregate_rating' ) )
			),
			'recipe' => $t(
				'recipe',
				__( 'Recipe', 'publishrank' ),
				__( 'Recipes for food blogs and restaurants, from custom fields (ingredients, steps, cook time).', 'publishrank' ),
				'Recipe',
				self::cond( array() ),
				array( $p( 'name', 'dynamic', 'post.title', array( 'required' => true ) ), $p( 'image', 'dynamic', 'post.featured_image', array( 'required' => true ) ), $p( 'author', 'dynamic', 'post.author_person' ), $p( 'datePublished', 'dynamic', 'post.date_published' ), $p( 'description', 'dynamic', 'post.description' ), $p( 'recipeIngredient', 'dynamic', 'meta:recipe_ingredients', array( 'format' => 'lines' ) ), $p( 'recipeInstructions', 'dynamic', 'meta:recipe_steps', array( 'format' => 'lines' ) ), $p( 'totalTime', 'dynamic', 'meta:recipe_minutes', array( 'format' => 'duration_minutes' ) ), $p( 'recipeYield', 'dynamic', 'meta:recipe_yield' ), $p( 'recipeCuisine', 'dynamic', 'meta:recipe_cuisine' ) )
			),
			'location-page' => $t(
				'location-page',
				__( 'Business location page', 'publishrank' ),
				__( 'Connects a location landing page to your Local SEO business entity.', 'publishrank' ),
				'WebPage',
				self::cond( array() ),
				array( $p( 'about', 'ref', 'ref.local_business' ), $p( 'mainEntity', 'ref', 'ref.local_business' ) ),
				array( 'mode' => 'merge', 'main_entity' => false )
			),
			'video-post' => $t(
				'video-post',
				__( 'Video post', 'publishrank' ),
				__( 'A VideoObject from the PublishRank video fields on the post.', 'publishrank' ),
				'VideoObject',
				self::cond( array() ),
				array( $p( 'name', 'dynamic', 'post.title', array( 'required' => true ) ), $p( 'description', 'dynamic', 'post.description', array( 'required' => true ) ), $p( 'thumbnailUrl', 'dynamic', 'pr:video_thumbnail', array( 'required' => true, 'fallback' => '' ) ), $p( 'uploadDate', 'dynamic', 'post.date_published', array( 'required' => true ) ), $p( 'contentUrl', 'dynamic', 'pr:video_url', array( 'format' => 'url' ) ), $p( 'embedUrl', 'dynamic', 'pr:video_embed_url', array( 'format' => 'url' ) ), $p( 'duration', 'dynamic', 'pr:video_duration' ) )
			),
		);
		foreach ( $b as $id => $tpl ) {
			$b[ $id ]['properties'] = self::props( $tpl['properties'], 0 );
		}
		return $b;
	}
}
