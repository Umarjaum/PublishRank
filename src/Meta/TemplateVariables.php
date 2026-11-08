<?php
/**
 * Registry of the SEO template variables (%%token%%) that PublishRank actually resolves.
 *
 * This is the single source of truth for the variable list:
 *  - TemplateResolver::variables() reads the supported names from here, so a token can never be resolvable
 *    without being listed, or listed without being resolvable.
 *  - The editor and settings dropdowns, the help text and the REST/JS payload are generated from it.
 *  - The values themselves are still produced by TemplateResolver::value(); this class only describes them.
 *
 * Only variables that TemplateResolver::value() implements may be registered. tests/cases/05_template_variable_registry.php
 * fails when the registry and the resolver disagree.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Meta;

defined( 'ABSPATH' ) || exit;

final class TemplateVariables {
	/** Contexts a template can be resolved in. */
	const CONTEXT_POST    = 'post';    // Per-post fields and the global post/page templates (a post is available).
	const CONTEXT_HOME    = 'home';    // Global homepage template (no post).
	const CONTEXT_ARCHIVE = 'archive'; // Global archive/search/404 template (no post).

	/** Template fields that accept variables. */
	const FIELD_TITLE       = 'title';
	const FIELD_DESCRIPTION = 'description';

	/**
	 * Variable definitions, in display order within each group.
	 *
	 * Keys: id (name between the percent signs), group, contexts, requires (taxonomy a post type must support, or ''),
	 * aliases (legacy tokens that resolve to the same value), label/description/fallback (translatable text, see all()).
	 *
	 * @return array<int,array<string,mixed>> Static definitions without translations.
	 */
	private static function definitions() {
		$post  = array( self::CONTEXT_POST );
		$every = array( self::CONTEXT_POST, self::CONTEXT_HOME, self::CONTEXT_ARCHIVE );
		return array(
			array( 'id' => 'sitename', 'group' => 'site', 'contexts' => $every, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'site_description', 'group' => 'site', 'contexts' => $every, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'sep', 'group' => 'site', 'contexts' => $every, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'title', 'group' => 'content', 'contexts' => $every, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'excerpt', 'group' => 'content', 'contexts' => $every, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'permalink', 'group' => 'content', 'contexts' => $every, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'id', 'group' => 'content', 'contexts' => $post, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'page', 'group' => 'content', 'contexts' => $every, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'author', 'group' => 'author', 'contexts' => $post, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'category', 'group' => 'taxonomy', 'contexts' => $post, 'requires' => 'category', 'aliases' => array() ),
			array( 'id' => 'primary_category', 'group' => 'taxonomy', 'contexts' => $post, 'requires' => 'category', 'aliases' => array() ),
			array( 'id' => 'date', 'group' => 'dates', 'contexts' => $post, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'modified', 'group' => 'dates', 'contexts' => $post, 'requires' => '', 'aliases' => array() ),
			array( 'id' => 'focus_keyword', 'group' => 'seo', 'contexts' => $post, 'requires' => '', 'aliases' => array( 'focuskw' ) ),
		);
	}

	/**
	 * Translatable label, description and fallback for each variable id.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function texts() {
		return array(
			'sitename'         => array(
				'label'       => __( 'Site name', 'publishrank' ),
				'description' => __( 'The site title from Settings → General.', 'publishrank' ),
				'fallback'    => __( 'Empty when the site has no title.', 'publishrank' ),
			),
			'site_description' => array(
				'label'       => __( 'Site tagline', 'publishrank' ),
				'description' => __( 'The tagline from Settings → General.', 'publishrank' ),
				'fallback'    => __( 'Empty when no tagline is set.', 'publishrank' ),
			),
			'sep'              => array(
				'label'       => __( 'Title separator', 'publishrank' ),
				'description' => __( 'The separator chosen in PublishRank settings.', 'publishrank' ),
				'fallback'    => __( 'Dangling separators left by empty variables are removed automatically.', 'publishrank' ),
			),
			'title'            => array(
				'label'       => __( 'Post title', 'publishrank' ),
				'description' => __( 'The title of the post or page. On archives it is the archive title.', 'publishrank' ),
				'fallback'    => __( 'Empty when there is no title.', 'publishrank' ),
			),
			'excerpt'          => array(
				'label'       => __( 'Excerpt', 'publishrank' ),
				'description' => __( 'The post excerpt, or an automatic summary of the content when there is none. On archives it is the site tagline.', 'publishrank' ),
				'fallback'    => __( 'Empty for password-protected posts so protected content is never published.', 'publishrank' ),
			),
			'permalink'        => array(
				'label'       => __( 'Canonical URL', 'publishrank' ),
				'description' => __( 'The canonical address of the page.', 'publishrank' ),
				'fallback'    => __( 'Empty when no valid URL is available.', 'publishrank' ),
			),
			'id'               => array(
				'label'       => __( 'Post ID', 'publishrank' ),
				'description' => __( 'The numeric ID of the post.', 'publishrank' ),
				'fallback'    => __( 'Empty outside posts.', 'publishrank' ),
			),
			'page'             => array(
				'label'       => __( 'Page number', 'publishrank' ),
				'description' => __( 'Adds “Page 2”, “Page 3” … on paginated posts and archives.', 'publishrank' ),
				'fallback'    => __( 'Empty on the first page.', 'publishrank' ),
			),
			'author'           => array(
				'label'       => __( 'Author name', 'publishrank' ),
				'description' => __( 'The display name of the post author.', 'publishrank' ),
				'fallback'    => __( 'Empty when the author no longer exists.', 'publishrank' ),
			),
			'category'         => array(
				'label'       => __( 'Category', 'publishrank' ),
				'description' => __( 'The first category assigned to the post.', 'publishrank' ),
				'fallback'    => __( 'Empty when the post has no category.', 'publishrank' ),
			),
			'primary_category' => array(
				'label'       => __( 'Primary category', 'publishrank' ),
				'description' => __( 'The primary category chosen in the SEO panel, otherwise the first category.', 'publishrank' ),
				'fallback'    => __( 'Empty when the post has no category.', 'publishrank' ),
			),
			'date'             => array(
				'label'       => __( 'Publish date', 'publishrank' ),
				'description' => __( 'The publication date, in the site date format.', 'publishrank' ),
				'fallback'    => __( 'Empty outside posts.', 'publishrank' ),
			),
			'modified'         => array(
				'label'       => __( 'Modified date', 'publishrank' ),
				'description' => __( 'The last-modified date, in the site date format.', 'publishrank' ),
				'fallback'    => __( 'Empty outside posts.', 'publishrank' ),
			),
			'focus_keyword'    => array(
				'label'       => __( 'Focus keyword', 'publishrank' ),
				'description' => __( 'The focus keyword entered in the SEO panel of this post.', 'publishrank' ),
				'fallback'    => __( 'Empty when no focus keyword is set.', 'publishrank' ),
			),
		);
	}

	/** @return array<string,string> Group id => translated group label, in display order. */
	public static function groups() {
		return array(
			'site'     => __( 'Site', 'publishrank' ),
			'content'  => __( 'Content', 'publishrank' ),
			'author'   => __( 'Author', 'publishrank' ),
			'taxonomy' => __( 'Taxonomy', 'publishrank' ),
			'dates'    => __( 'Dates', 'publishrank' ),
			'seo'      => __( 'SEO', 'publishrank' ),
		);
	}

	/**
	 * Every registered variable with its metadata.
	 *
	 * @return array<string,array<string,mixed>> id => definition (id, token, label, description, fallback, group, contexts, requires, aliases).
	 */
	public static function all() {
		$texts = self::texts();
		$out   = array();
		foreach ( self::definitions() as $def ) {
			$id = $def['id'];
			$t  = isset( $texts[ $id ] ) ? $texts[ $id ] : array(
				'label'       => $id,
				'description' => '',
				'fallback'    => '',
			);
			$out[ $id ] = array_merge(
				$def,
				array(
					'token'       => self::token( $id ),
					'label'       => $t['label'],
					'description' => $t['description'],
					'fallback'    => $t['fallback'],
				)
			);
		}
		return $out;
	}

	/** Exact token text for a variable id: %%id%%. */
	public static function token( $id ) {
		return '%%' . $id . '%%';
	}

	/**
	 * Every name the resolver accepts: registered ids plus legacy aliases (without percent signs).
	 *
	 * @return string[]
	 */
	public static function names() {
		$names = array();
		foreach ( self::definitions() as $def ) {
			$names[] = $def['id'];
			foreach ( $def['aliases'] as $alias ) {
				$names[] = $alias;
			}
		}
		return $names;
	}

	/**
	 * Variables that make sense in a context.
	 *
	 * @param string $context One of the CONTEXT_* constants.
	 * @return array<string,array<string,mixed>>
	 */
	public static function for_context( $context ) {
		return array_filter(
			self::all(),
			static function ( $def ) use ( $context ) {
				return in_array( $context, $def['contexts'], true );
			}
		);
	}

	/**
	 * Human-readable list of tokens for help text, generated from the registry so it cannot drift.
	 *
	 * @param string $context One of the CONTEXT_* constants.
	 * @param string $field   FIELD_TITLE or FIELD_DESCRIPTION.
	 * @return string e.g. "%%title%%, %%sitename%% …"
	 */
	public static function token_list( $context = self::CONTEXT_POST, $field = self::FIELD_TITLE ) {
		$tokens = array();
		foreach ( self::for_context( $context ) as $def ) {
			$tokens[] = $def['token'];
		}
		return implode( ', ', $tokens );
	}

	/**
	 * Data for the editor and settings dropdowns (assets/js/template-variables.js).
	 *
	 * @param string $post_type Post type being edited, or '' when not editing a post.
	 * @return array<string,mixed>
	 */
	public static function js_payload( $post_type = '' ) {
		$taxonomies = $post_type && function_exists( 'get_object_taxonomies' ) ? (array) get_object_taxonomies( $post_type, 'names' ) : array();
		$vars       = array();
		foreach ( self::all() as $def ) {
			$available = true;
			$reason    = '';
			if ( '' !== $def['requires'] && '' !== $post_type && ! in_array( $def['requires'], $taxonomies, true ) ) {
				$available = false;
				/* translators: %s: taxonomy name. */
				$reason = sprintf( __( 'Not available: this content type has no “%s” taxonomy.', 'publishrank' ), $def['requires'] );
			}
			$vars[] = array(
				'id'          => $def['id'],
				'token'       => $def['token'],
				'label'       => $def['label'],
				'description' => $def['description'],
				'fallback'    => $def['fallback'],
				'group'       => $def['group'],
				'contexts'    => array_values( $def['contexts'] ),
				'available'   => $available,
				'reason'      => $reason,
			);
		}
		$groups = array();
		foreach ( self::groups() as $id => $label ) {
			$groups[] = array(
				'id'    => $id,
				'label' => $label,
			);
		}
		return array(
			'variables' => $vars,
			'groups'    => $groups,
			'i18n'      => array(
				'placeholder' => __( 'Insert variable…', 'publishrank' ),
				'label'       => __( 'Insert a dynamic variable', 'publishrank' ),
				/* translators: %s: token such as %%title%%. */
				'inserted'    => __( 'Inserted %s', 'publishrank' ),
				'noTarget'    => __( 'Could not find the field to insert into.', 'publishrank' ),
				'tooLong'     => __( 'Not inserted: the field is already at its maximum length.', 'publishrank' ),
				'fallback'    => __( 'If empty:', 'publishrank' ),
				'empty'       => __( 'No variables are available for this field.', 'publishrank' ),
			),
		);
	}

	/**
	 * Consistency problems in the registry (empty when healthy). Used by the automated tests and Diagnostics.
	 *
	 * @return string[]
	 */
	public static function validate() {
		$problems = array();
		$seen     = array();
		$groups   = self::groups();
		$valid    = array( self::CONTEXT_POST, self::CONTEXT_HOME, self::CONTEXT_ARCHIVE );
		foreach ( self::all() as $def ) {
			$id = (string) $def['id'];
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,40}$/', $id ) ) {
				$problems[] = 'invalid id: ' . $id;
			}
			foreach ( array_merge( array( $id ), $def['aliases'] ) as $name ) {
				if ( isset( $seen[ $name ] ) ) {
					$problems[] = 'duplicate name: ' . $name;
				}
				$seen[ $name ] = true;
			}
			if ( '%%' . $id . '%%' !== $def['token'] ) {
				$problems[] = 'token mismatch: ' . $id;
			}
			if ( ! isset( $groups[ $def['group'] ] ) ) {
				$problems[] = 'unknown group for ' . $id;
			}
			if ( empty( $def['contexts'] ) || array_diff( $def['contexts'], $valid ) ) {
				$problems[] = 'invalid contexts for ' . $id;
			}
			foreach ( array( 'label', 'description', 'fallback' ) as $key ) {
				if ( '' === trim( (string) $def[ $key ] ) ) {
					$problems[] = 'missing ' . $key . ' for ' . $id;
				}
			}
		}
		return $problems;
	}
}
