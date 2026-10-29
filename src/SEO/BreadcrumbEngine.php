<?php
namespace PublishRankSEO\SEO;

use PublishRankSEO\Core\PublicationProfile;
use PublishRankSEO\Meta\MetaGenerator;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class BreadcrumbEngine {
	const ERROR_TRANSIENT = 'publishrank_breadcrumb_errors';
	const PLACEMENTS      = array( 'auto', 'content', 'hook', 'manual', 'schema_only' );
	const STYLE_HANDLE    = 'publishrank-breadcrumbs';
	const BLOCK_NAME      = 'publishrank/breadcrumbs';

	/** True once an automatic placement printed the trail, so it is never printed twice. */
	private static $rendered = false;
	/** Per-request trail cache: the visible trail and the BreadcrumbList schema share one source of truth. */
	private static $cache = array();

	public function register() {
		add_shortcode( 'publishrank_breadcrumbs', array( $this, 'shortcode' ) );
		add_action( 'wp', array( $this, 'setup_placement' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_style' ), 5 );
		if ( did_action( 'init' ) ) {
			$this->register_block();
		} else {
			add_action( 'init', array( $this, 'register_block' ) );
		}
	}

	/** Where the visible trail is printed: auto, content, hook, manual or schema_only. */
	public static function placement() {
		$mode = sanitize_key( (string) get_option( 'publishrank_breadcrumb_placement', 'auto' ) );
		return in_array( $mode, self::PLACEMENTS, true ) ? $mode : 'auto';
	}

	/** Whether PublishRank prints the visible trail itself (the JSON-LD trail is always built from the same items). */
	public static function visible_enabled() {
		return (bool) get_option( 'publishrank_breadcrumbs_enabled', true ) && 'schema_only' !== self::placement();
	}

	/** Whether PublishRank emits BreadcrumbList structured data independently of visible output. */
	public static function schema_enabled() {
		return (bool) get_option( 'publishrank_breadcrumb_schema_enabled', true );
	}

	/** Pages that have a meaningful trail. */
	public static function context_supported() {
		return is_singular() || is_category() || is_tag() || is_tax() || is_post_type_archive();
	}

	public function setup_placement() {
		if ( is_admin() || is_feed() || ! self::visible_enabled() || ! self::context_supported() ) {
			return; }
		$mode = self::placement();
		if ( 'manual' === $mode ) {
			return; }
		if ( 'hook' === $mode ) {
			$hook = trim( (string) preg_replace( '/[^A-Za-z0-9_:\\/\\-]/', '', (string) get_option( 'publishrank_breadcrumb_hook', '' ) ) );
			if ( '' !== $hook ) {
				add_action( $hook, array( $this, 'echo_once' ), 5 ); }
			return;
		}
		if ( 'auto' === $mode && function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			// Block themes: print above the main post title instead of inside the content.
			add_filter( 'render_block', array( $this, 'before_post_title' ), 10, 3 );
			return;
		}
		if ( is_singular() ) {
			add_filter( 'the_content', array( $this, 'prepend' ), 20 ); }
	}

	public function register_style() {
		wp_register_style( self::STYLE_HANDLE, \PublishRankSEO\Core\Branding::asset_url( 'css/breadcrumbs.css' ), array(), \PublishRankSEO\Core\Branding::version() );
		if ( ! is_admin() && self::visible_enabled() && self::context_supported() ) {
			wp_enqueue_style( self::STYLE_HANDLE ); }
	}

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; }
		wp_register_script( 'publishrank-breadcrumbs-block', \PublishRankSEO\Core\Branding::asset_url( 'js/breadcrumbs-block.js' ), array( 'wp-blocks', 'wp-element', 'wp-i18n' ), \PublishRankSEO\Core\Branding::version(), true );
		register_block_type(
			self::BLOCK_NAME,
			array(
				'editor_script'   => 'publishrank-breadcrumbs-block',
				'render_callback' => array( $this, 'shortcode' ),
			)
		);
	}

	public function before_post_title( $content, $block = array(), $instance = null ) {
		if ( empty( $block['blockName'] ) || 'core/post-title' !== $block['blockName'] || self::$rendered || ! is_singular() ) {
			return $content; }
		if ( $instance instanceof \WP_Block && ! empty( $instance->context['queryId'] ) ) {
			return $content; // A post title inside a Query Loop belongs to another post.
		}
		$html = $this->render();
		if ( '' === $html ) {
			return $content; }
		self::$rendered = true;
		return $html . $content;
	}

	public function prepend( $content ) {
		$original = $content;
		if ( is_admin() || is_feed() || ! is_singular() || ! self::visible_enabled() || ! in_the_loop() || ! is_main_query() || self::$rendered ) {
			return $original; }
		if ( has_shortcode( (string) $content, 'publishrank_breadcrumbs' ) || ( function_exists( 'has_block' ) && has_block( self::BLOCK_NAME, (string) $content ) ) ) {
			return $original; // The editor placed the trail by hand.
		}
		$html = $this->render();
		if ( '' === $html ) {
			return $original; }
		self::$rendered = true;
		return $html . $content;
	}

	/** Action callback for the "theme hook" placement. */
	public function echo_once() {
		if ( self::$rendered ) {
			return; }
		$html = $this->render();
		if ( '' === $html ) {
			return; }
		self::$rendered = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building the markup.
	}

	/** Shortcode and block output. Always prints, wherever the editor placed it. */
	public function shortcode( $atts = array() ) {
		$atts = is_array( $atts ) ? $atts : array();
		$args = array();
		if ( isset( $atts['separator'] ) ) {
			$args['separator'] = sanitize_text_field( (string) $atts['separator'] ); }
		$html = $this->render( $args );
		if ( '' !== $html ) {
			self::$rendered = true; }
		return $html;
	}

	private function mode() {
		$mode = sanitize_key( (string) get_option( 'publishrank_breadcrumb_error_mode', 'skip' ) );
		return in_array( $mode, array( 'skip', 'fallback', 'text', 'disable' ), true ) ? $mode : 'skip';
	}

	private function error_key() {
		$key = sanitize_key( (string) get_option( 'publishrank_breadcrumb_error_key', 'breadcrumb_error' ) );
		return ! empty( $key ) ? $key : 'breadcrumb_error';
	}

	private function record_error( $reason, $context = array() ) {
		if ( ! get_option( 'publishrank_breadcrumb_log_errors', false ) ) {
			return; }
		$errors   = get_transient( self::ERROR_TRANSIENT );
		$errors   = is_array( $errors ) ? $errors : array();
		$errors[] = array(
			'key'     => $this->error_key(),
			'reason'  => sanitize_key( $reason ),
			'context' => array_map( 'sanitize_text_field', (array) $context ),
			'time'    => current_time( 'mysql' ),
		);
		set_transient( self::ERROR_TRANSIENT, array_slice( $errors, -20 ), DAY_IN_SECONDS );
	}

	private function add_item( &$items, $name, $url, $context = array() ) {
		$name = sanitize_text_field( (string) $name );
		$url  = esc_url_raw( (string) $url );
		if ( '' !== $name && '' !== $url ) {
			$items[] = array(
				'name' => $name,
				'url'  => $url,
			);
			return true; }
		$this->record_error( 'invalid_item', $context );
		$mode = $this->mode();
		if ( 'disable' === $mode ) {
			return false; }
		if ( 'fallback' === $mode ) {
			$label    = sanitize_text_field( (string) get_option( 'publishrank_breadcrumb_fallback_label', 'Home' ) );
			$fallback = esc_url_raw( (string) get_option( 'publishrank_breadcrumb_fallback_url', home_url( '/' ) ) );
			if ( $label && $fallback ) {
				$items[] = array(
					'name' => $label,
					'url'  => $fallback,
				);
				return true; }
		}
		if ( 'text' === $mode && $name ) {
			$items[] = array(
				'name' => $name,
				'url'  => '',
			);
			return true; }
		return true;
	}

	public function items() {
		if ( ! self::context_supported() ) {
			return array(); }
		$object_id = (int) get_queried_object_id();
		$key       = $object_id . ':' . ( is_singular() ? 'p' : ( is_post_type_archive() ? 'a' : 't' ) ) . ':' . ( is_post_type_archive() ? (string) get_query_var( 'post_type' ) : '' );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ]; }
		$items = $this->build_items( $object_id );
		/**
		 * Filter the trail used for BOTH the visible breadcrumbs and the BreadcrumbList JSON-LD.
		 *
		 * @param array $items List of array( 'name' => string, 'url' => string ).
		 */
		$items = apply_filters( 'publishrank_breadcrumb_items', $items );
		$items = is_array( $items ) ? array_values( $items ) : array();
		self::$cache[ $key ] = $items;
		return $items;
	}

	private function build_items( $object_id ) {
		$items       = array();
		$label       = trim( (string) get_option( 'publishrank_breadcrumb_home_label', '' ) );
		$publication = '' !== $label ? $label : PublicationProfile::publication_name();
		if ( '' === trim( (string) $publication ) ) {
			$publication = get_bloginfo( 'name' ); }
		if ( ! $this->add_item( $items, $publication, PublicationProfile::primary_url(), array( 'source' => 'publication' ) ) && 'disable' === $this->mode() ) {
			return array(); }
		$current = '';
		if ( is_singular() ) {
			$post_type = get_post_type( $object_id );
			if ( is_page() ) {
				$ancestors = array_reverse( get_post_ancestors( $object_id ) );
				foreach ( $ancestors as $ancestor ) {
					if ( ! $this->add_item( $items, get_the_title( $ancestor ), get_permalink( $ancestor ), array( 'source' => 'page_ancestor', 'id' => $ancestor ) ) && 'disable' === $this->mode() ) {
						return array(); }
				}
			} else {
				$object = get_post_type_object( $post_type );
				if ( 'post' === $post_type ) {
					$posts_page = (int) get_option( 'page_for_posts' );
					if ( 'page' === get_option( 'show_on_front' ) && $posts_page > 0 && 'publish' === get_post_status( $posts_page ) ) {
						$this->add_item( $items, get_the_title( $posts_page ), get_permalink( $posts_page ), array( 'source' => 'posts_page' ) ); }
				} elseif ( $object && get_post_type_archive_link( $post_type ) ) {
					if ( ! $this->add_item( $items, $object->labels->name, get_post_type_archive_link( $post_type ), array( 'source' => 'post_type_archive', 'type' => $post_type ) ) && 'disable' === $this->mode() ) {
						return array(); }
				}
				$taxonomy = $this->primary_taxonomy( $post_type );
				if ( $taxonomy ) {
					$term = $this->primary_term( $object_id, $taxonomy );
					if ( $term instanceof \WP_Term ) {
						$items = $this->append_term_trail( $items, $term, $taxonomy->name );
						if ( 'disable' === $this->mode() && empty( $items ) ) {
							return array(); }
					}
				}
			}
			$current = get_the_title( $object_id );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				// The trail already ends with the current term; it is the current page.
				$items = $this->append_term_trail( $items, $term, $term->taxonomy );
			}
			return array_values( $items );
		} elseif ( is_post_type_archive() ) {
			$object = get_queried_object();
			if ( $object && ! empty( $object->labels->name ) ) {
				$current = $object->labels->name; }
		}
		$current = trim( html_entity_decode( wp_strip_all_tags( (string) $current ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $current ) {
			$current = trim( (string) wp_get_document_title() ); }
		$canonical = ( new MetaGenerator() )->canonical();
		if ( '' !== $current ) {
			if ( '' === esc_url_raw( (string) $canonical ) ) {
				$this->record_error( 'invalid_canonical', array( 'source' => 'current' ) );
				if ( 'disable' === $this->mode() ) {
					return array(); }
				if ( 'text' === $this->mode() ) {
					$items[] = array(
						'name' => sanitize_text_field( $current ),
						'url'  => '',
					); } elseif ( 'fallback' === $this->mode() ) {
					$this->add_item( $items, $current, get_option( 'publishrank_breadcrumb_fallback_url', home_url( '/' ) ), array( 'source' => 'current_fallback' ) ); }
			} elseif ( empty( $items ) || end( $items )['name'] !== sanitize_text_field( $current ) ) {
				$this->add_item( $items, $current, $canonical, array( 'source' => 'current' ) ); }
		}
		return array_values( $items );
	}

	private function primary_taxonomy( $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy->public && $taxonomy->hierarchical ) {
				return $taxonomy; }
		}
		return null;
	}

	/** The editor's primary category when set, otherwise the first assigned term that is not the site's default placeholder. */
	private function primary_term( $post_id, $taxonomy ) {
		if ( 'category' === $taxonomy->name ) {
			$primary = absint( PostMeta::get( $post_id, PostMeta::PRIMARY_CATEGORY, 0 ) );
			if ( $primary ) {
				$term = get_term( $primary, 'category' );
				if ( $term instanceof \WP_Term && has_term( $primary, 'category', $post_id ) ) {
					return $term; }
			}
		}
		$terms = get_the_terms( $post_id, $taxonomy->name );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null; }
		$default = 'category' === $taxonomy->name ? (int) get_option( 'default_category' ) : 0;
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term && (int) $term->term_id !== $default ) {
				return $term; }
		}
		$first = reset( $terms );
		return $first instanceof \WP_Term ? $first : null;
	}

	private function append_term_trail( $items, $term, $taxonomy ) {
		$ancestors = array_reverse( get_ancestors( $term->term_id, $taxonomy ) );
		foreach ( array_merge( $ancestors, array( $term->term_id ) ) as $term_id ) {
			$ancestor = get_term( $term_id, $taxonomy );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$link = get_term_link( $ancestor );
				$this->add_item(
					$items,
					$ancestor->name,
					is_wp_error( $link ) ? '' : $link,
					array(
						'source'   => 'term',
						'taxonomy' => $taxonomy,
						'id'       => $term_id,
					)
				);
			}
		}
		return $items;
	}

	/**
	 * Visible trail. Built from the same items as the BreadcrumbList schema so the markup always matches the page.
	 *
	 * @param array $args Optional: separator.
	 */
	public function render( $args = array() ) {
		$items = $this->items();
		// A one-item trail has no navigation value and is not valid BreadcrumbList markup for Google.
		if ( count( $items ) < 2 ) {
			return ''; }
		$separator = isset( $args['separator'] ) ? sanitize_text_field( (string) $args['separator'] ) : sanitize_text_field( (string) get_option( 'publishrank_breadcrumbs_separator', '→' ) );
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::STYLE_HANDLE ); }
		$style = sanitize_key( (string) get_option( 'publishrank_breadcrumb_style', 'plain' ) );
		$style = in_array( $style, array( 'plain', 'chips', 'bar', 'underline' ), true ) ? $style : 'plain';
		$size  = sanitize_key( (string) get_option( 'publishrank_breadcrumb_size', 'medium' ) );
		$size  = in_array( $size, array( 'small', 'medium', 'large' ), true ) ? $size : 'medium';
		$vars  = '';
		foreach ( array( 'text' => 'publishrank_breadcrumb_text_color', 'link' => 'publishrank_breadcrumb_link_color', 'bg' => 'publishrank_breadcrumb_bg_color' ) as $var => $option ) {
			$color = sanitize_hex_color( (string) get_option( $option, '' ) );
			if ( $color ) {
				$vars .= '--pr-bc-' . $var . ':' . $color . ';'; }
		}
		$html = '<nav class="publishrank-breadcrumbs publishrank-breadcrumbs--' . esc_attr( $style ) . ' publishrank-breadcrumbs--size-' . esc_attr( $size ) . '"' . ( '' !== $vars ? ' style="' . esc_attr( $vars ) . '"' : '' ) . ' aria-label="' . esc_attr__( 'Breadcrumbs', 'publishrank' ) . '"><ol class="publishrank-breadcrumbs__list">';
		$last = count( $items ) - 1;
		foreach ( $items as $index => $item ) {
			$is_link = $index < $last && ! empty( $item['url'] );
			$html   .= '<li class="publishrank-breadcrumbs__item">';
			if ( $is_link ) {
				$html .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>';
			} else {
				$html .= '<span' . ( $index === $last ? ' aria-current="page"' : '' ) . '>' . esc_html( $item['name'] ) . '</span>'; }
			if ( $index < $last && '' !== $separator ) {
				$html .= '<span class="publishrank-breadcrumb-separator" aria-hidden="true">' . esc_html( $separator ) . '</span>'; }
			$html .= '</li>';
		}
		$html .= '</ol></nav>';
		return (string) apply_filters( 'publishrank_breadcrumbs_html', $html, $items );
	}

	public function schema() {
		$list = array();
		foreach ( $this->items() as $item ) {
			$name = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			if ( '' === $name ) {
				continue; }
			$entry = array(
				'@type'    => 'ListItem',
				'position' => count( $list ) + 1,
				'name'     => $name,
			);
			if ( ! empty( $item['url'] ) ) {
				$entry['item'] = esc_url_raw( $item['url'] ); }
			$list[] = $entry;
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => trailingslashit( ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical() ) . '#breadcrumb',
			'itemListElement' => $list,
		);
	}
}
