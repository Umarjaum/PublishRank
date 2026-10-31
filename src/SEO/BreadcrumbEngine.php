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

	/** Context keys (see context_key()) whose trail was already printed in this request, so one context never gets two trails. */
	private static $printed = array();
	/** Placement hooks registered for this request (reported by the diagnostic probe). */
	private static $registered = array();
	/** Per-request trail cache: the visible trail and the BreadcrumbList schema share one source of truth. */
	private static $cache = array();

	/** Forget the per-request trail cache and the "already printed" flag (settings changes, editor preview, tests). */
	public static function reset() {
		self::$cache   = array();
		self::$printed = array();
	}

	public function register() {
		add_shortcode( 'publishrank_breadcrumbs', array( $this, 'shortcode' ) );
		add_action( 'wp', array( $this, 'setup_placement' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_style' ), 5 );
		$this->register_override();
		( new BreadcrumbDiagnostics() )->register_probe();
		if ( did_action( 'init' ) ) {
			$this->register_block();
		} else {
			add_action( 'init', array( $this, 'register_block' ) );
		}
	}

	/**
	 * Frontend override: make breadcrumb trails printed by supported plugins use PublishRank's configured separator.
	 *
	 * Only documented separator filters of the renderers are used (no output buffering or HTML search-and-replace),
	 * and nothing is changed when the override option is off. A renderer that is not listed here (for example a theme's
	 * own trail) keeps its separator: pick "Hook" placement and remove the theme trail, or use the
	 * publishrank_breadcrumb_override_filters filter to add a renderer. The Breadcrumbs screen reports what the site prints.
	 */
	public function register_override() {
		$filters = array(
			'wpseo_breadcrumb_separator'                => array( 'override_plain', 10, 1 ),          // Yoast SEO.
			'rank_math/frontend/breadcrumb/args'        => array( 'override_rank_math_args', 10, 1 ), // Rank Math.
			'woocommerce_breadcrumb_defaults'           => array( 'override_woocommerce_args', 10, 1 ), // WooCommerce.
			'aioseo_breadcrumbs_separator_symbol'       => array( 'override_plain', 10, 1 ),          // All in One SEO (hook name not verified on a live install).
		);
		/**
		 * Filter the third-party separator hooks PublishRank overrides.
		 *
		 * @param array $filters hook => array( method on this class, priority, accepted args ).
		 */
		$filters = (array) apply_filters( 'publishrank_breadcrumb_override_filters', $filters );
		foreach ( $filters as $hook => $cfg ) {
			if ( is_string( $hook ) && is_array( $cfg ) && isset( $cfg[0] ) && is_string( $cfg[0] ) && method_exists( $this, $cfg[0] ) ) {
				add_filter( $hook, array( $this, $cfg[0] ), isset( $cfg[1] ) ? (int) $cfg[1] : 10, isset( $cfg[2] ) ? (int) $cfg[2] : 1 );
			}
		}
	}

	/** The separator to force, or null when the override is off. */
	private function override_separator() {
		return \PublishRankSEO\Core\OutputSettings::breadcrumb_override() ? BreadcrumbSeparators::current() : null;
	}

	/** @param mixed $separator Separator printed by the other plugin. @return mixed */
	public function override_plain( $separator ) {
		$mine = $this->override_separator();
		return null === $mine ? $separator : $mine;
	}

	/** @param mixed $args Rank Math breadcrumb arguments. @return mixed */
	public function override_rank_math_args( $args ) {
		$mine = $this->override_separator();
		if ( null !== $mine && is_array( $args ) ) {
			$args['separator'] = $mine;
		}
		return $args;
	}

	/** @param mixed $args WooCommerce breadcrumb arguments (delimiter is printed as HTML). @return mixed */
	public function override_woocommerce_args( $args ) {
		$mine = $this->override_separator();
		if ( null !== $mine && is_array( $args ) ) {
			$text              = esc_html( $mine );
			$args['delimiter'] = '' !== trim( $mine ) && $mine === trim( $mine ) ? '&nbsp;' . $text . '&nbsp;' : $text;
		}
		return $args;
	}

	/** Where the visible trail is printed: auto, content, hook, manual or schema_only. */
	public static function placement() {
		$mode = sanitize_key( (string) get_option( 'publishrank_breadcrumb_placement', 'auto' ) );
		return in_array( $mode, self::PLACEMENTS, true ) ? $mode : 'auto';
	}

	/** Whether PublishRank prints the visible trail itself (the JSON-LD trail is always built from the same items). */
	public static function visible_enabled() {
		return \PublishRankSEO\Core\OutputSettings::visible_breadcrumbs() && 'schema_only' !== self::placement();
	}

	/** Whether PublishRank emits BreadcrumbList structured data independently of visible output. */
	public static function schema_enabled() {
		return \PublishRankSEO\Core\OutputSettings::breadcrumb_schema();
	}

	/**
	 * Another plugin that already prints a BreadcrumbList in its own JSON-LD, or an empty string.
	 * PublishRank then does not add a second BreadcrumbList; it never removes or edits the other plugin's output.
	 * Return an empty string from the filter to force PublishRank's BreadcrumbList regardless.
	 */
	public static function external_schema_owner() {
		$owner = '';
		try {
			if ( function_exists( 'yoast_breadcrumb' ) ) {
				// Yoast only adds a BreadcrumbList to its graph while its breadcrumbs feature is on. When the option cannot be read, assume it owns the list.
				$on    = class_exists( 'WPSEO_Options' ) ? (bool) \WPSEO_Options::get( 'breadcrumbs-enable', true ) : true;
				$owner = ( $on || current_theme_supports( 'yoast-seo-breadcrumbs' ) ) ? 'yoast' : '';
			} elseif ( function_exists( 'rank_math_the_breadcrumbs' ) ) {
				$on    = class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'get_settings' ) ? (bool) \RankMath\Helper::get_settings( 'general.breadcrumbs', true ) : true;
				$owner = $on ? 'rank_math' : '';
			} elseif ( function_exists( 'aioseo_breadcrumbs' ) ) {
				// The option path is not verified on a live install: only an explicit "off" releases ownership.
				$owner = 'aioseo';
				if ( function_exists( 'aioseo' ) ) {
					$enabled = @aioseo()->options->searchAppearance->breadcrumbs->enable; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( false === $enabled || 0 === $enabled || '0' === $enabled ) {
						$owner = '';
					}
				}
			}
		} catch ( \Throwable $e ) {
			$owner = '' !== $owner ? $owner : '';
		}
		return (string) apply_filters( 'publishrank_breadcrumb_schema_external_owner', $owner );
	}

	/**
	 * Pages that have a meaningful trail: posts, pages and other singular content, category/tag/taxonomy archives,
	 * post-type archives and the posts page. The front page and the homepage blog listing are deliberately excluded
	 * (their trail would be a single item); opt in with the publishrank_breadcrumb_show_on_front_page filter.
	 */
	public static function context_supported() {
		if ( is_front_page() ) {
			return (bool) apply_filters( 'publishrank_breadcrumb_show_on_front_page', false ) && is_singular();
		}
		return is_singular() || is_category() || is_tag() || is_tax() || is_post_type_archive() || ( is_home() && (int) get_option( 'page_for_posts' ) > 0 );
	}

	/** Stable key of the main-query context a trail belongs to; '' when the context has no trail. */
	public static function context_key() {
		if ( ! self::context_supported() ) {
			return '';
		}
		if ( is_singular() ) {
			return 'singular:' . (int) get_queried_object_id();
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return 'term:' . (int) get_queried_object_id();
		}
		if ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			return 'archive:' . ( is_array( $type ) ? implode( ',', $type ) : (string) $type );
		}
		return 'posts-page';
	}

	/** Whether this context already printed its trail in this request. */
	public static function was_printed() {
		$key = self::context_key();
		return '' !== $key && isset( self::$printed[ $key ] );
	}

	private static function mark_printed() {
		$key = self::context_key();
		if ( '' !== $key ) {
			self::$printed[ $key ] = true;
		}
	}

	/** Placement hooks registered for the current request (for the diagnostic probe). @return string[] */
	public static function registered_hooks() {
		return array_values( array_unique( self::$registered ) );
	}

	public function setup_placement() {
		self::$registered = array();
		if ( is_admin() || is_feed() || ! self::visible_enabled() || ! self::context_supported() ) {
			return; }
		$mode = self::placement();
		if ( 'manual' === $mode ) {
			return; }
		if ( 'hook' === $mode ) {
			$hook = trim( (string) preg_replace( '/[^A-Za-z0-9_:\\/\\-]/', '', (string) get_option( 'publishrank_breadcrumb_hook', '' ) ) );
			if ( '' !== $hook ) {
				add_action( $hook, array( $this, 'echo_once' ), 5 );
				self::$registered[] = 'hook:' . $hook;
			}
			return;
		}
		$singular = is_singular();
		if ( 'content' === $mode ) {
			// "Above the content": only the content filter, only on singular views (unchanged behaviour).
			if ( $singular ) {
				add_filter( 'the_content', array( $this, 'prepend' ), 20 );
				self::$registered[] = 'the_content';
			}
			return;
		}
		// Automatic: every supported integration point is registered; the per-context registry lets only the first one print.
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			add_filter( 'render_block', array( $this, 'before_title_block' ), 10, 3 );
			self::$registered[] = 'render_block';
		} elseif ( $singular ) {
			add_filter( 'the_content', array( $this, 'prepend' ), 20 );
			self::$registered[] = 'the_content';
		}
		if ( ! $singular ) {
			// Classic archives and the posts page: before the first post of the main loop.
			add_action( 'loop_start', array( $this, 'on_loop_start' ), 5 );
			self::$registered[] = 'loop_start';
		}
		// Elementor (documented hooks): title widgets of theme-builder templates, and the Canvas / Header-Footer page templates.
		add_filter( 'elementor/widget/render_content', array( $this, 'before_elementor_title' ), 10, 2 );
		add_action( 'elementor/page_templates/canvas/before_content', array( $this, 'echo_once' ), 5 );
		add_action( 'elementor/page_templates/header-footer/before_content', array( $this, 'echo_once' ), 5 );
		self::$registered[] = 'elementor/widget/render_content';
		self::$registered[] = 'elementor/page_templates/*/before_content';
	}

	public function register_style() {
		wp_register_style( self::STYLE_HANDLE, \PublishRankSEO\Core\Branding::asset_url( 'css/breadcrumbs.css' ), array(), \PublishRankSEO\Core\Branding::version() . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/css/breadcrumbs.css' ) );
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

	/**
	 * Block themes: print the trail above the main title block (post title on singular views, archive title on archives),
	 * and above the post content when a template has no title block. Blocks inside a Query Loop are never used.
	 */
	public function before_title_block( $content, $block = array(), $instance = null ) {
		$name = empty( $block['blockName'] ) ? '' : (string) $block['blockName'];
		if ( ! in_array( $name, array( 'core/post-title', 'core/query-title', 'core/post-content' ), true ) || self::was_printed() || ! self::context_supported() ) {
			return $content; }
		if ( 'core/query-title' === $name ) {
			if ( is_singular() || ( isset( $block['attrs']['type'] ) && 'archive' !== $block['attrs']['type'] ) ) {
				return $content; }
		} elseif ( ! is_singular() || $this->content_has_manual_trail() ) {
			return $content; }
		if ( $instance instanceof \WP_Block && ! empty( $instance->context['queryId'] ) && 'core/query-title' !== $name ) {
			return $content; // A title or content block inside a Query Loop belongs to another post.
		}
		$html = $this->render();
		if ( '' === $html ) {
			return $content; }
		self::mark_printed();
		return $html . $content;
	}

	/** @deprecated 2.9.97 Kept for callers of the old name. */
	public function before_post_title( $content, $block = array(), $instance = null ) {
		return $this->before_title_block( $content, $block, $instance );
	}

	/** Elementor Pro theme-builder title widgets: print above the post/archive title. */
	public function before_elementor_title( $content, $widget = null ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || self::was_printed() ) {
			return $content; }
		$name = (string) $widget->get_name();
		if ( 'theme-post-title' === $name ) {
			if ( ! is_singular() || (int) get_the_ID() !== (int) get_queried_object_id() || $this->content_has_manual_trail() ) {
				return $content; }
		} elseif ( 'theme-archive-title' !== $name || is_singular() ) {
			return $content; }
		$html = $this->render();
		if ( '' === $html ) {
			return $content; }
		self::mark_printed();
		return $html . $content;
	}

	/** Classic archives and the posts page: print before the first post of the MAIN loop only. */
	public function on_loop_start( $query = null ) {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || is_singular() ) {
			return; }
		$this->echo_once();
	}

	/** True when the editor placed the trail by hand (shortcode or block) in the current post, so automatic placement stays out. */
	private function content_has_manual_trail() {
		$post = get_post( is_singular() ? (int) get_queried_object_id() : 0 );
		if ( ! $post instanceof \WP_Post ) {
			return false; }
		$content = (string) $post->post_content;
		if ( has_shortcode( $content, 'publishrank_breadcrumbs' ) || ( function_exists( 'has_block' ) && has_block( self::BLOCK_NAME, $content ) ) ) {
			return true; }
		// Elementor keeps widget settings (a Shortcode widget) in post meta, not in post_content.
		$data = get_post_meta( $post->ID, '_elementor_data', true );
		return is_string( $data ) && false !== strpos( $data, 'publishrank_breadcrumbs' );
	}

	public function prepend( $content ) {
		$original = $content;
		if ( is_admin() || is_feed() || ! is_singular() || ! self::visible_enabled() || ! $this->in_main_content() || self::was_printed() ) {
			return $original; }
		if ( has_shortcode( (string) $content, 'publishrank_breadcrumbs' ) || ( function_exists( 'has_block' ) && has_block( self::BLOCK_NAME, (string) $content ) ) ) {
			return $original; // The editor placed the trail by hand.
		}
		$html = $this->render();
		if ( '' === $html ) {
			return $original; }
		self::mark_printed();
		return $html . $content;
	}

	/**
	 * Whether the content filter is running for the main article. Normally the main query's loop; Elementor theme-builder
	 * templates render the content outside that loop, so with Elementor active the queried post is accepted once the page
	 * head is done (never while meta tags are generated).
	 */
	private function in_main_content() {
		if ( in_the_loop() && is_main_query() ) {
			return true; }
		return did_action( 'elementor/loaded' ) && did_action( 'wp_head' ) && ! doing_action( 'wp_head' ) && (int) get_the_ID() === (int) get_queried_object_id();
	}

	/** Action callback for the "theme hook" placement. */
	public function echo_once() {
		if ( self::was_printed() ) {
			return; }
		$html = $this->render();
		if ( '' === $html ) {
			return; }
		self::mark_printed();
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building the markup.
	}

	/**
	 * Shortcode and block output: prints where the editor placed it, but only while visible breadcrumbs are switched on.
	 * (The "Structured data only" placement still allows a hand-placed trail, as before.)
	 */
	public function shortcode( $atts = array() ) {
		if ( ! \PublishRankSEO\Core\OutputSettings::visible_breadcrumbs() ) {
			return ''; }
		$atts = is_array( $atts ) ? $atts : array();
		$args = array();
		if ( isset( $atts['separator'] ) ) {
			$args['separator'] = (string) $atts['separator']; } // Validated by BreadcrumbSeparators::normalize() in render().
		$html = $this->render( $args );
		if ( '' !== $html ) {
			self::mark_printed(); }
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
		$key       = self::context_key();
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
			$publication = \PublishRankSEO\Core\PublicationProfile::publication_name(); }
		if ( ! $this->add_item( $items, $publication, PublicationProfile::primary_url(), array( 'source' => 'publication' ) ) && 'disable' === $this->mode() ) {
			return array(); }
		$current = '';
		if ( is_front_page() && ! is_singular() ) {
			return array(); }
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
		} elseif ( is_home() && ! is_singular() ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			$current    = $posts_page > 0 ? get_the_title( $posts_page ) : '';
			$canonical  = $posts_page > 0 ? get_permalink( $posts_page ) : '';
		} elseif ( is_post_type_archive() ) {
			$object = get_queried_object();
			if ( $object && ! empty( $object->labels->name ) ) {
				$current = $object->labels->name; }
		}
		$current = trim( html_entity_decode( wp_strip_all_tags( (string) $current ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $current ) {
			$current = trim( (string) wp_get_document_title() ); }
		if ( ! isset( $canonical ) ) {
			$canonical = ( new MetaGenerator() )->canonical();
		}
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
		$separator = BreadcrumbSeparators::current();
		if ( isset( $args['separator'] ) ) {
			$custom    = BreadcrumbSeparators::normalize( $args['separator'] );
			$separator = null === $custom ? $separator : $custom;
		}
		$separator_class = 'publishrank-breadcrumb-separator' . ( BreadcrumbSeparators::is_directional( $separator ) ? ' publishrank-breadcrumb-separator--directional' : '' );
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
				$html .= '<span class="' . esc_attr( $separator_class ) . '" aria-hidden="true">' . esc_html( $separator ) . '</span>'; }
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
