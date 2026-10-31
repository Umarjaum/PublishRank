<?php
/**
 * Single, shared SEO template-variable resolver.
 *
 * Every template-based SEO field (global title templates, per-post SEO titles and meta descriptions)
 * goes through this class so the supported variables can never diverge between the two places.
 * The resolver produces PLAIN TEXT. Escaping happens at the output boundary (esc_attr(), esc_html(),
 * wp_json_encode()), never here, so values are not double-escaped.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Meta;

defined( 'ABSPATH' ) || exit;

final class TemplateResolver {
	/** Hard cap for any single template string that is resolved. */
	const MAX_TEMPLATE_LENGTH = 1000;

	/**
	 * Matches %%token%% and the legacy single-percent focus-keyword tokens used by the editor button.
	 * The whole template is scanned once, so a value that itself contains token-like text is never expanded again.
	 */
	const TOKEN_PATTERN = '/%%([A-Za-z][A-Za-z0-9_]{0,40})%%|%(focus_keyword|focuskw)%/';

	/** @return string[] Supported variable names (without percent signs), taken from the TemplateVariables registry. */
	public static function variables() {
		return TemplateVariables::names();
	}

	/**
	 * Resolve a template to plain text.
	 *
	 * Context keys (all optional):
	 *  - post_id (int)        Post the template belongs to; 0 for archives, home, search, 404.
	 *  - title (string)       Plain title to use when there is no post (archive title, "Page not found" ...).
	 *  - excerpt (string)     Excerpt to use when there is no post (defaults to the site tagline).
	 *  - permalink (string)   Canonical URL to use when there is no post.
	 *  - page (int)           Current pagination number; a suffix such as "Page 2" is produced for 2 and above.
	 *  - focus_keyword (string) Overrides the stored focus keyword (used by unsaved editor previews).
	 *  - field (string)       'title' (default) also tidies dangling separators; 'description' only collapses whitespace.
	 *
	 * @param string $template Template containing %%variables%%.
	 * @param array  $context  Resolution context.
	 * @return string Plain text (no markup, entities decoded, whitespace collapsed).
	 */
	public static function resolve( $template, $context = array() ) {
		$template = is_scalar( $template ) ? (string) $template : '';
		$template = self::cut( $template, self::MAX_TEMPLATE_LENGTH );
		if ( '' === trim( $template, " \t\n\r\0\x0B" ) ) {
			return '';
		}
		$context = is_array( $context ) ? $context : array();
		$cache   = array();
		$sep     = self::separator();

		$resolved = preg_replace_callback(
			self::TOKEN_PATTERN,
			static function ( $match ) use ( &$cache, $context, $sep ) {
				$name = strtolower( '' !== $match[1] ? $match[1] : $match[2] );
				if ( ! in_array( $name, TemplateResolver::variables(), true ) ) {
					return ''; // Unknown variable: drop it rather than print raw %%markup%% in a title.
				}
					if ( 'sep' === $name ) {
						// Descriptions use a neutral BBC-style bullet regardless of the title separator.
						return isset( $context['field'] ) && 'description' === $context['field'] ? '•' : $sep;
					}
				if ( ! array_key_exists( $name, $cache ) ) {
					$cache[ $name ] = TemplateResolver::value( $name, $context );
				}
				return $cache[ $name ];
			},
			$template
		);
		if ( ! is_string( $resolved ) ) { // preg failure (e.g. invalid UTF-8): never return null.
			return '';
		}
		return self::tidy( $resolved, $sep, ! isset( $context['field'] ) || 'description' !== $context['field'] );
	}

	/**
	 * Plain-text value of a single supported variable. Public only so the resolver closure can call it.
	 *
	 * @param string $name    Variable name without percent signs.
	 * @param array  $context Resolution context.
	 * @return string
	 */
	public static function value( $name, $context ) {
		$post_id = isset( $context['post_id'] ) ? absint( $context['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			$post_id = 0;
			$post    = null;
		}

		switch ( $name ) {
			case 'sitename':
				return self::plain( \PublishRankSEO\Core\PublicationProfile::publication_name() );
			case 'site_description':
				return self::plain( \PublishRankSEO\Core\PublicationProfile::publication_description() );
			case 'page':
				return self::page_suffix( isset( $context['page'] ) ? (int) $context['page'] : 0 );
			case 'focus_keyword':
			case 'focuskw':
				if ( isset( $context['focus_keyword'] ) ) {
					return self::plain( $context['focus_keyword'] );
				}
				return $post ? self::plain( PostMeta::get( $post_id, PostMeta::FOCUS_KEYWORD, '' ) ) : '';
		}

		if ( ! $post ) {
			switch ( $name ) {
				case 'title':
					return isset( $context['title'] ) ? self::plain( $context['title'] ) : '';
				case 'excerpt':
					return isset( $context['excerpt'] ) ? self::plain( $context['excerpt'] ) : self::plain( \PublishRankSEO\Core\PublicationProfile::publication_description() );
				case 'permalink':
					return isset( $context['permalink'] ) ? self::url( $context['permalink'] ) : '';
				default:
					return ''; // category, author, date, modified, id: nothing meaningful without a post.
			}
		}

		switch ( $name ) {
			case 'title':
				return self::plain( get_the_title( $post ) );
			case 'category':
				$term = self::first_category( $post_id );
				return $term ? self::plain( $term->name ) : '';
			case 'primary_category':
				$term = self::primary_category( $post_id );
				return $term ? self::plain( $term->name ) : '';
			case 'author':
				$author = get_userdata( (int) $post->post_author );
				return $author ? self::plain( $author->display_name ) : '';
			case 'date':
				return self::plain( get_the_date( '', $post ) );
			case 'modified':
				return self::plain( get_the_modified_date( '', $post ) );
			case 'excerpt':
				if ( post_password_required( $post ) ) {
					return ''; // Never leak protected content into metadata.
				}
				if ( '' !== trim( (string) $post->post_excerpt, " \t\n\r\0\x0B" ) ) {
					return SeoData::clean( $post->post_excerpt );
				}
				return SeoData::auto_description( $post );
			case 'id':
				return (string) $post_id;
			case 'permalink':
				$meta = new MetaGenerator();
				return self::url( $meta->canonical_for_post( $post_id ) );
		}
		return '';
	}

	/**
	 * Sanitize template text that an editor or administrator typed, WITHOUT damaging %%variables%%.
	 *
	 * sanitize_text_field() removes anything that looks like a percent-encoded octet. "%%date%%" contains "%da"
	 * and "%%category%%" contains "%ca", so both were silently corrupted ("%te%%", "%tegory%%").
	 * Well-formed variable tokens are therefore shielded while the rest of the string is sanitized.
	 *
	 * @param mixed $value  Raw value (already unslashed).
	 * @param int   $length Maximum length in characters; 0 for no limit.
	 * @return string
	 */
	public static function sanitize_template( $value, $length = 0 ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$map   = array();
		$index = 0;
		$value = preg_replace_callback(
			self::TOKEN_PATTERN,
			static function ( $match ) use ( &$map, &$index ) {
				$key         = '{{prtok' . $index . '}}';
				$map[ $key ] = $match[0];
				++$index;
				return $key;
			},
			$value
		);
		$value = sanitize_text_field( is_string( $value ) ? $value : '' );
		if ( $map ) {
			$value = strtr( $value, $map );
		}
		return $length > 0 ? self::cut( $value, $length ) : $value;
	}

	/** Configured SEO title separator (never a hard-coded symbol). */
	public static function separator() {
		return self::plain( get_option( 'publishrank_seo_title_separator', '|' ) );
	}

	/** First assigned category (WordPress order). */
	private static function first_category( $post_id ) {
		$terms = get_the_category( $post_id );
		if ( is_array( $terms ) && ! empty( $terms ) && $terms[0] instanceof \WP_Term ) {
			return $terms[0];
		}
		return null;
	}

	/** Editor-selected primary category when valid for the post, else the first assigned category. */
	private static function primary_category( $post_id ) {
		$selected = absint( PostMeta::get( $post_id, PostMeta::PRIMARY_CATEGORY, 0 ) );
		if ( $selected && has_category( $selected, $post_id ) ) {
			$term = get_term( $selected, 'category' );
			if ( $term instanceof \WP_Term ) {
				return $term;
			}
		}
		return self::first_category( $post_id );
	}

	/** "Page 2" style suffix, empty on the first page. */
	private static function page_suffix( $page ) {
		if ( $page < 2 ) {
			return '';
		}
		/* translators: %d: page number. */
		return sprintf( __( 'Page %d', 'publishrank' ), $page );
	}

	/** Current pagination number of the main query (paged archives and paginated posts). */
	public static function current_page() {
		return max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	}

	/** Normalise to plain text: no tags, decoded entities, collapsed whitespace. */
	private static function plain( $value ) {
		$value = is_scalar( $value ) ? wp_check_invalid_utf8( (string) $value ) : '';
		$value = wp_strip_all_tags( $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return is_string( $value ) ? trim( $value, " \t\n\r\0\x0B" ) : '';
	}

	/** Validate a URL; invalid values become an empty string. */
	private static function url( $value ) {
		return esc_url_raw( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Collapse whitespace, remove dangling or doubled separators left by empty variables
	 * (for example "Title | | Site" or "Site |"), and strip any markup.
	 */
	private static function tidy( $text, $sep, $is_title = true ) {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = is_string( $text ) ? trim( $text, " \t\n\r\0\x0B" ) : '';
		if ( '' === $text || ! $is_title ) {
			return $text;
		}
		// Only clean around separators that are punctuation/symbols; a word-like separator must be left alone.
		if ( '' !== $sep && ! preg_match( '/[\p{L}\p{N}]/u', $sep ) ) {
			$quoted = preg_quote( $sep, '/' );
			$text   = preg_replace( '/(?:\s*' . $quoted . '\s*){2,}/u', ' ' . $sep . ' ', $text );
			$text   = preg_replace( '/^(?:\s*' . $quoted . ')+\s*|\s*(?:' . $quoted . '\s*)+$/u', '', (string) $text );
		}
		// Legacy behaviour: a title never starts or ends with a bare dash or pipe.
		$text = trim( (string) $text, " \t\n\r\0\x0B|–—-" );
		return preg_replace( '/\s+/u', ' ', $text );
	}

	/** Multibyte-safe substring. */
	private static function cut( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}
