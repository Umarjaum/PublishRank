<?php
/**
 * One shared meta-description resolver.
 *
 * The <head> output, the editor panel, the REST fields and the JSON-LD graph all ask this class,
 * so they can never disagree. Every description reports WHERE it came from.
 *
 * Fallback hierarchy for a post or page:
 *  1. override  – the description typed into the PublishRank editor field.
 *  2. excerpt   – the hand-written WordPress excerpt.
 *  3. content   – text extracted from the post content (blocks and classic content; shortcodes unwrapped).
 *  4. builder   – text extracted from page-builder data stored outside post_content.
 *  5. page_type – "{Page title} — {Publication}. {Publication description}" (can be switched off).
 *  6. global    – the publication-wide description, as a last resort.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\PublicationProfile;

defined( 'ABSPATH' ) || exit;

final class DescriptionResolver {
	const OPTION_FALLBACK = 'publishrank_seo_description_fallback';
	const MODE_PAGE_TYPE  = 'page_type';
	const MODE_GLOBAL     = 'global';
	/** Smallest amount of extracted text that is treated as a real description. */
	const MIN_CHARS = 25;

	/** @var array<string,array{text:string,source:string}> Per-request cache. */
	private static $cache = array();

	/** Forget cached results (after saves, and in tests). */
	public static function flush( $post_id = 0 ) {
		if ( $post_id ) {
			foreach ( array_keys( self::$cache ) as $key ) {
				if ( 0 === strpos( $key, (int) $post_id . ':' ) ) {
					unset( self::$cache[ $key ] );
				}
			}
			return;
		}
		self::$cache = array();
	}

	/**
	 * Every source a description can report, with a human label.
	 *
	 * @return array<string,string>
	 */
	public static function source_labels() {
		return array(
			'override'  => __( 'Your override', 'publishrank' ),
			'excerpt'   => __( 'Post excerpt', 'publishrank' ),
			'content'   => __( 'Automatically extracted from page content', 'publishrank' ),
			'builder'   => __( 'Extracted from page-builder content', 'publishrank' ),
			'page_type' => __( 'Page-type fallback (page title + publication)', 'publishrank' ),
			'archive'   => __( 'Archive description', 'publishrank' ),
			'homepage'  => __( 'Homepage description setting', 'publishrank' ),
			'global'    => __( 'Global publication description (last resort)', 'publishrank' ),
			'none'      => __( 'Not available', 'publishrank' ),
		);
	}

	/** Which fallback the site uses when a page has no usable text of its own. */
	public static function fallback_mode() {
		$mode = (string) get_option( self::OPTION_FALLBACK, self::MODE_PAGE_TYPE );
		return self::MODE_GLOBAL === $mode ? self::MODE_GLOBAL : self::MODE_PAGE_TYPE;
	}

	/**
	 * The complete description for a post, with its source.
	 *
	 * @param int|\WP_Post $post   Post.
	 * @param int          $length Maximum characters.
	 * @return array{text:string,source:string}
	 */
	public static function for_post( $post, $length = SeoData::DESCRIPTION_LENGTH ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return array( 'text' => '', 'source' => 'none' );
		}
		$key = (int) $post->ID . ':' . (int) $length . ':' . self::fallback_mode() . ':' . (string) $post->post_modified_gmt . ':' . md5( (string) PostMeta::get( $post->ID, PostMeta::DESCRIPTION, '' ) . '|' . (string) get_option( 'publishrank_identity_name', '' ) . '|' . (string) get_option( 'publishrank_seo_publication_name', '' ) . '|' . PublicationProfile::publication_description() );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$result = self::resolve_post( $post, $length );
		/**
		 * Filter the resolved description and its source.
		 *
		 * @param array{text:string,source:string} $result Text and source key.
		 * @param \WP_Post                         $post   Post.
		 */
		$result             = (array) apply_filters( 'publishrank_seo_description', $result, $post );
		$result             = array(
			'text'   => isset( $result['text'] ) ? (string) $result['text'] : '',
			'source' => isset( $result['source'] ) ? (string) $result['source'] : 'none',
		);
		self::$cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Only the automatic tiers (excerpt, content, builder): no override, no fallback.
	 * Used by the %%excerpt%% variable and anywhere "what does this page say?" is the question.
	 *
	 * @return array{text:string,source:string}
	 */
	public static function automatic( $post, $length = SeoData::DESCRIPTION_LENGTH ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return array( 'text' => '', 'source' => 'none' );
		}
		$excerpt = trim( (string) $post->post_excerpt );
		if ( '' !== $excerpt ) {
			$text = self::plain( $excerpt );
			if ( '' !== $text ) {
				return array( 'text' => SeoData::truncate( $text, $length ), 'source' => 'excerpt' );
			}
		}
		$text = self::content_text( $post );
		if ( self::is_usable( $text ) ) {
			return array( 'text' => SeoData::truncate( $text, $length ), 'source' => 'content' );
		}
		$text = self::builder_text( $post );
		if ( self::is_usable( $text ) ) {
			return array( 'text' => SeoData::truncate( $text, $length ), 'source' => 'builder' );
		}
		return array( 'text' => '', 'source' => 'none' );
	}

	/**
	 * Tally where the descriptions of the most recently changed public posts and pages come from.
	 * Pages that rely on a fallback are listed so an editor can write a real description or add text.
	 *
	 * @param int $limit Maximum number of posts to inspect.
	 * @return array{counts:array<string,int>,weak:array<int,array{id:int,title:string,source:string,type:string}>,inspected:int}
	 */
	public static function audit( $limit = 100 ) {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		$ids    = get_posts(
			array(
				'post_type'              => array_values( $types ),
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, min( 300, (int) $limit ) ),
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);
		$counts = array();
		$weak   = array();
		foreach ( $ids as $id ) {
			$result                    = self::for_post( (int) $id );
			$counts[ $result['source'] ] = isset( $counts[ $result['source'] ] ) ? $counts[ $result['source'] ] + 1 : 1;
			if ( in_array( $result['source'], array( 'page_type', 'global', 'none' ), true ) ) {
				$weak[] = array(
					'id'     => (int) $id,
					'title'  => (string) get_the_title( (int) $id ),
					'source' => $result['source'],
					'type'   => (string) get_post_type( (int) $id ),
				);
			}
		}
		return array( 'counts' => $counts, 'weak' => $weak, 'inspected' => count( $ids ) );
	}

	/** @return array{text:string,source:string} */
	private static function resolve_post( \WP_Post $post, $length ) {
		$id       = (int) $post->ID;
		$override = (string) PostMeta::get( $id, PostMeta::DESCRIPTION, '' );
		if ( '' !== $override ) {
			$text = SeoData::clean( ( new MetaGenerator() )->resolve_template( $override, $id, 'description' ) );
			if ( '' !== $text ) {
				return array( 'text' => SeoData::truncate( $text, $length ), 'source' => 'override' );
			}
			// An override that resolves to nothing (only unknown variables, say) must not blank the tag.
		}
		$auto = self::automatic( $post, $length );
		if ( '' !== $auto['text'] ) {
			return $auto;
		}
		if ( self::MODE_PAGE_TYPE === self::fallback_mode() ) {
			// The title of a protected post is already public, so it is safe to describe the page with it.
			$text = self::page_type_text( (string) $post->post_title );
			if ( '' !== $text ) {
				return array( 'text' => SeoData::truncate( $text, $length ), 'source' => 'page_type' );
			}
		}
		return self::global_result( $length );
	}

	/** @return array{text:string,source:string} */
	public static function global_result( $length = SeoData::DESCRIPTION_LENGTH ) {
		$text = SeoData::clean( PublicationProfile::publication_description() );
		return '' !== $text
			? array( 'text' => SeoData::truncate( $text, $length ), 'source' => 'global' )
			: array( 'text' => '', 'source' => 'none' );
	}

	/**
	 * "{Subject} — {Publication}. {Publication description}": specific to the page, never blank.
	 *
	 * @param string $subject Page, term or author name.
	 * @return string Empty when there is no subject.
	 */
	public static function page_type_text( $subject ) {
		$subject = SeoData::clean( $subject );
		if ( '' === $subject ) {
			return '';
		}
		$name = SeoData::clean( PublicationProfile::publication_name() );
		$head = $subject;
		if ( '' !== $name && 0 !== strcasecmp( $name, $subject ) ) {
			/* translators: 1: page title, 2: publication name. */
			$head = sprintf( _x( '%1$s — %2$s', 'page-type meta description fallback', 'publishrank' ), $subject, $name );
		}
		$tail = SeoData::clean( PublicationProfile::publication_description() );
		$text = '' !== $tail ? rtrim( $head, ". \t" ) . '. ' . $tail : $head;
		/**
		 * Filter the page-type fallback description.
		 *
		 * @param string $text    Fallback text.
		 * @param string $subject Page, term or author name.
		 */
		return SeoData::clean( (string) apply_filters( 'publishrank_seo_description_page_type_fallback', $text, $subject ) );
	}

	/** Enough real text to be worth publishing as a description. */
	public static function is_usable( $text ) {
		$text = (string) $text;
		$min  = (int) apply_filters( 'publishrank_seo_description_min_chars', self::MIN_CHARS );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		return $len >= max( 1, $min ) && 1 === preg_match( '/[\p{L}\p{N}]/u', $text );
	}

	/** Text from post content: blocks reduced to their text blocks, shortcodes unwrapped. */
	private static function content_text( \WP_Post $post ) {
		$source = (string) $post->post_content;
		if ( '' === trim( $source ) ) {
			return '';
		}
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$source = excerpt_remove_blocks( $source );
		}
		return self::plain( $source );
	}

	/**
	 * Plain text from HTML that may contain shortcodes. Registered and builder-style shortcodes are unwrapped
	 * (their tags are removed and the text between them is kept) instead of being deleted with their content,
	 * so a Divi or WPBakery page keeps its words while a form shortcode simply disappears.
	 */
	public static function plain( $html ) {
		$text = self::unwrap_shortcodes( (string) $html );
		$text = SeoData::clean( $text );
		// Bare URLs (embeds, link lists) make poor descriptions.
		$text = (string) preg_replace( '~\b(?:https?://|www\.)\S+~iu', ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** Remove shortcode tags but keep the text between them. Literal brackets such as "[Reuters]" are left alone. */
	public static function unwrap_shortcodes( $text ) {
		if ( false === strpos( (string) $text, '[' ) ) {
			return (string) $text;
		}
		$known = isset( $GLOBALS['shortcode_tags'] ) && is_array( $GLOBALS['shortcode_tags'] ) ? array_keys( $GLOBALS['shortcode_tags'] ) : array();
		$out   = preg_replace_callback(
			'/\[(\/?)([A-Za-z][A-Za-z0-9_\-]*)((?:\s[^\[\]]*)?)(\/?)\]/',
			static function ( $m ) use ( $known ) {
				$tag = $m[2];
				if ( '' !== $m[1] || '' !== $m[4] || in_array( $tag, $known, true ) || false !== strpos( $tag, '_' ) || false !== strpos( $tag, '-' ) || false !== strpos( $m[3], '=' ) ) {
					return ' ';
				}
				return $m[0];
			},
			(string) $text
		);
		return null === $out ? (string) $text : $out;
	}

	/**
	 * Text kept by page builders outside post_content (Elementor, Bricks, Beaver Builder, Oxygen).
	 * Other builders can contribute through the publishrank_seo_builder_text filter.
	 */
	public static function builder_text( \WP_Post $post ) {
		$keys   = (array) apply_filters( 'publishrank_seo_builder_meta_keys', array( '_elementor_data', '_bricks_page_content_2', '_fl_builder_data', 'ct_builder_shortcodes' ), $post );
		$chunks = array();
		foreach ( $keys as $meta_key ) {
			$raw = get_post_meta( $post->ID, (string) $meta_key, true );
			if ( '' === $raw || null === $raw || false === $raw ) {
				continue;
			}
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$raw = $decoded;
				} else {
					$chunks[] = self::plain( $raw );
					continue;
				}
			}
			$budget = 60;
			self::collect_builder_text( $raw, $chunks, 0, $budget );
			if ( strlen( implode( ' ', $chunks ) ) > 1200 ) {
				break;
			}
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', implode( ' ', array_filter( $chunks ) ) ) );
		return SeoData::clean( (string) apply_filters( 'publishrank_seo_builder_text', $text, $post ) );
	}

	/**
	 * Walk builder data and gather the values of text-like keys.
	 *
	 * @param mixed    $node    Array, object or scalar.
	 * @param string[] $chunks  Collected text (by reference).
	 * @param int      $depth   Current depth.
	 * @param int      $budget  Remaining text values to collect (by reference).
	 */
	private static function collect_builder_text( $node, array &$chunks, $depth, &$budget ) {
		if ( $depth > 18 || $budget <= 0 ) {
			return;
		}
		if ( $node instanceof \stdClass ) {
			$node = (array) $node;
		}
		if ( ! is_array( $node ) ) {
			return;
		}
		static $wanted = array( 'editor', 'text', 'title', 'description', 'content', 'heading', 'heading_title', 'title_text', 'description_text', 'subtitle', 'sub_title', 'paragraph', 'caption' );
		foreach ( $node as $key => $value ) {
			if ( $budget <= 0 ) {
				return;
			}
			if ( is_string( $value ) && in_array( (string) $key, $wanted, true ) ) {
				$plain = self::plain( $value );
				if ( '' !== $plain ) {
					$chunks[] = $plain;
					--$budget;
				}
			} elseif ( is_array( $value ) || $value instanceof \stdClass ) {
				self::collect_builder_text( $value, $chunks, $depth + 1, $budget );
			}
		}
	}
}
