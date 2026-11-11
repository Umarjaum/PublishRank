<?php
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\MetaGenerator;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class BreadcrumbEngine {
	const ERROR_TRANSIENT = 'publishrank_breadcrumb_errors';
	const PLACEMENTS      = array( 'auto', 'content', 'hook', 'manual', 'schema_only' );
	const STYLE_HANDLE    = 'publishrank-breadcrumbs';
	const BLOCK_NAME      = 'publishrank/breadcrumbs';
	const BLOCK_SCRIPT    = 'publishrank-breadcrumbs-block';
	const BLOCK_EDITOR_CSS = 'publishrank-breadcrumbs-editor';
	const SIZES           = array( 'small', 'medium', 'large' );
	const ALIGNMENTS      = array( 'left', 'center', 'right' );

	/**
	 * Every look the visible trail can use: value => array( full label, short label ).
	 * The four ribbon-style looks come first; the settings screen, the block and the renderer all read this list.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function looks() {
		return array(
			'arrows'    => array( __( 'Arrow ribbon (chevron steps)', 'publishrank' ), __( 'Arrows', 'publishrank' ) ),
			'minimal'   => array( __( 'Minimal (lowercase, underlined current)', 'publishrank' ), __( 'Minimal', 'publishrank' ) ),
			'steps'     => array( __( 'Progress steps (numbered circles)', 'publishrank' ), __( 'Steps', 'publishrank' ) ),
			'pills'     => array( __( 'Pills (highlighted current page)', 'publishrank' ), __( 'Pills', 'publishrank' ) ),
			'plain'     => array( __( 'Plain text', 'publishrank' ), __( 'Plain', 'publishrank' ) ),
			'chips'     => array( __( 'Rounded chips', 'publishrank' ), __( 'Chips', 'publishrank' ) ),
			'bar'       => array( __( 'Soft background bar', 'publishrank' ), __( 'Bar', 'publishrank' ) ),
			'underline' => array( __( 'Underline', 'publishrank' ), __( 'Underline', 'publishrank' ) ),
		);
	}

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
		// Automatic: block themes get a title/content-block insertion so the trail is below the header/menu
		// and above the article headline. Classic themes use loop_start for the same position, with the_content
		// retained as a fallback for templates that bypass the main loop.
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			add_filter( 'render_block', array( $this, 'before_title_block' ), 10, 3 );
			self::$registered[] = 'render_block';
		} else {
			if ( ! $singular ) {
				// Classic archives and the posts page: before the first post of the main loop.
				add_action( 'loop_start', array( $this, 'on_loop_start' ), 5 );
				self::$registered[] = 'loop_start';
			} else {
				// Classic themes normally print the headline inside the main loop. Prefer loop_start
				// so the trail is above that headline, while the content fallback supports themes
				// that render the queried post without firing loop_start.
				add_action( 'loop_start', array( $this, 'on_loop_start' ), 5 );
				self::$registered[] = 'loop_start';
				add_filter( 'the_content', array( $this, 'prepend' ), 20 );
				self::$registered[] = 'the_content';
			}
		}
		// Elementor (documented hooks): title widgets of theme-builder templates, and the Canvas / Header-Footer page templates.
		add_filter( 'elementor/widget/render_content', array( $this, 'before_elementor_title' ), 10, 2 );
		add_action( 'elementor/page_templates/canvas/before_content', array( $this, 'echo_once' ), 5 );
		add_action( 'elementor/page_templates/header-footer/before_content', array( $this, 'echo_once' ), 5 );
		self::$registered[] = 'elementor/widget/render_content';
		self::$registered[] = 'elementor/page_templates/*/before_content';
	}

	public function register_style() {
		$this->register_style_asset();
		if ( ! is_admin() && self::visible_enabled() && self::context_supported() ) {
			wp_enqueue_style( self::STYLE_HANDLE ); }
	}

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; }
		$this->register_style_asset();
		$script_path = PUBLISHRANK_SEO_PATH . 'assets/js/breadcrumbs-block.js';
		wp_register_script(
			self::BLOCK_SCRIPT,
			\PublishRankSEO\Core\Branding::asset_url( 'js/breadcrumbs-block.js' ),
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-core-data' ),
			\PublishRankSEO\Core\Branding::version() . '.' . (int) @filemtime( $script_path ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			true
		);
		wp_add_inline_script( self::BLOCK_SCRIPT, 'window.publishrankBreadcrumbsBlock = ' . wp_json_encode( $this->editor_data() ) . ';', 'before' );
		if ( function_exists( 'wp_set_script_translations' ) ) {
			// WordPress generates the wp.i18n.setLocaleData() bootstrap from the bundled MO catalog.
			wp_set_script_translations( self::BLOCK_SCRIPT, 'publishrank', PUBLISHRANK_SEO_PATH . 'languages' );
		}
		wp_register_style(
			self::BLOCK_EDITOR_CSS,
			\PublishRankSEO\Core\Branding::asset_url( 'css/breadcrumbs-editor.css' ),
			array( self::STYLE_HANDLE ),
			\PublishRankSEO\Core\Branding::version() . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/css/breadcrumbs-editor.css' ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		);
		register_block_type(
			self::BLOCK_NAME,
			array(
				'api_version'         => 2,
				'editor_script'       => self::BLOCK_SCRIPT,
				'style_handles'       => array( self::STYLE_HANDLE ),
				'editor_style_handles' => array( self::STYLE_HANDLE, self::BLOCK_EDITOR_CSS ),
				'attributes'          => array(
					'look'        => array( 'type' => 'string' ),
					'size'        => array( 'type' => 'string' ),
					'position'    => array( 'type' => 'string' ), // Not "align": that name is reserved for the core wide/full alignment support.
					'separator'   => array( 'type' => 'string' ),
					'offsetX'     => array( 'type' => 'number' ),
					'offsetY'     => array( 'type' => 'number' ),
					'icons'       => array( 'type' => 'boolean' ),
					'colorText'   => array( 'type' => 'string' ),
					'colorLink'   => array( 'type' => 'string' ),
					'colorBg'     => array( 'type' => 'string' ),
					'colorAccent' => array( 'type' => 'string' ),
				),
				'render_callback'     => array( $this, 'render_block' ),
			)
		);
	}

	/** Registers the front-end stylesheet once; safe to call from several hooks. */
	private function register_style_asset() {
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return; }
		wp_register_style( self::STYLE_HANDLE, \PublishRankSEO\Core\Branding::asset_url( 'css/breadcrumbs.css' ), array(), \PublishRankSEO\Core\Branding::version() . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/css/breadcrumbs.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Site-wide breadcrumb settings handed to the block editor, so the live preview of a block that keeps
	 * "Site default" looks exactly like the front end.
	 *
	 * @return array<string,mixed>
	 */
	private function editor_data() {
		$looks = array();
		foreach ( self::looks() as $value => $labels ) {
			$looks[] = array(
				'value' => $value,
				'label' => $labels[0],
				'short' => $labels[1],
			);
		}
		$colors = array();
		foreach ( array( 'text' => 'publishrank_breadcrumb_text_color', 'link' => 'publishrank_breadcrumb_link_color', 'bg' => 'publishrank_breadcrumb_bg_color' ) as $key => $option ) {
			$color          = sanitize_hex_color( (string) get_option( $option, '' ) );
			$colors[ $key ] = $color ? $color : '';
		}
		$accent         = sanitize_hex_color( (string) get_option( 'publishrank_breadcrumb_accent_color', '' ) );
		$colors['accent'] = $accent ? $accent : '';
		$style = sanitize_key( (string) get_option( 'publishrank_breadcrumb_style', 'plain' ) );
		$size  = sanitize_key( (string) get_option( 'publishrank_breadcrumb_size', 'medium' ) );
		$align = sanitize_key( (string) get_option( 'publishrank_breadcrumb_align', 'left' ) );
		$home  = trim( (string) get_option( 'publishrank_breadcrumb_home_label', '' ) );
		return array(
			'looks'       => $looks,
			'defaults'    => array(
				'look'      => isset( self::looks()[ $style ] ) ? $style : 'plain',
				'size'      => in_array( $size, self::SIZES, true ) ? $size : 'medium',
				'align'     => in_array( $align, self::ALIGNMENTS, true ) ? $align : 'left',
				'separator' => BreadcrumbSeparators::current(),
				'colors'    => $colors,
				'home'      => '' !== $home ? $home : __( 'Home', 'publishrank' ),
				'offset'    => max( -600, min( 600, (int) get_option( 'publishrank_breadcrumb_offset', 0 ) ) ),
				'offsetY'   => max( 0, min( 600, (int) get_option( 'publishrank_breadcrumb_offset_y', 0 ) ) ),
				'icons'     => (bool) get_option( 'publishrank_breadcrumb_icons', 0 ),
			),
			'visible'     => (bool) \PublishRankSEO\Core\OutputSettings::visible_breadcrumbs(),
			'settingsUrl' => admin_url( 'admin.php?page=publishrank-breadcrumbs' ),
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
			if ( ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
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
	 * Shortcode output: prints where the editor placed it, but only while visible breadcrumbs are switched on.
	 * (The "Structured data only" placement still allows a hand-placed trail, as before.)
	 *
	 * Optional attributes: separator, look, size, align (left, center or right), offset (pixels, -600 to 600), offset_y (positive pixels down), icons (1 or 0), text, link, bg, accent (colors are #hex values).
	 * Example: [publishrank_breadcrumbs look="arrows" accent="#c9184a" link="#590d22"]
	 */
	public function shortcode( $atts = array() ) {
		if ( ! \PublishRankSEO\Core\OutputSettings::visible_breadcrumbs() ) {
			return ''; }
		$html = $this->render( $this->args_from_input( is_array( $atts ) ? $atts : array(), false ) );
		if ( '' !== $html ) {
			self::mark_printed(); }
		return $html;
	}

	/** Block output. Blank attributes fall back to the site-wide Breadcrumbs settings. */
	public function render_block( $attributes = array() ) {
		if ( ! \PublishRankSEO\Core\OutputSettings::visible_breadcrumbs() ) {
			return ''; }
		$html = $this->render( $this->args_from_input( is_array( $attributes ) ? $attributes : array(), true ) );
		if ( '' !== $html ) {
			self::mark_printed(); }
		return $html;
	}

	/**
	 * Turn shortcode attributes or block attributes into render() arguments. Everything is validated again in render().
	 *
	 * @param array $input    Raw attributes.
	 * @param bool  $is_block Blocks send an empty separator when it was never set, so only the shortcode treats "" as "no separator".
	 * @return array
	 */
	private function args_from_input( $input, $is_block ) {
		$args = array();
		if ( isset( $input['separator'] ) && is_string( $input['separator'] ) && ( ! $is_block || '' !== $input['separator'] ) ) {
			$args['separator'] = $input['separator']; // Validated by BreadcrumbSeparators::normalize() in render().
		}
		foreach ( array( 'look', 'size', 'align' ) as $key ) {
			$raw = isset( $input[ $key ] ) ? $input[ $key ] : ( 'align' === $key && isset( $input['position'] ) ? $input['position'] : '' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$args[ $key ] = sanitize_key( $raw );
			}
		}
		$offset = isset( $input['offsetX'] ) ? $input['offsetX'] : ( isset( $input['offset'] ) ? $input['offset'] : null );
		if ( is_numeric( $offset ) ) {
			$args['offset'] = max( -600, min( 600, (int) $offset ) ); // Pixels; negative moves the trail left, positive moves it right.
		}
		$offset_y = isset( $input['offsetY'] ) ? $input['offsetY'] : ( isset( $input['offset_y'] ) ? $input['offset_y'] : null );
		if ( is_numeric( $offset_y ) ) {
			$args['offsetY'] = max( 0, min( 600, (int) $offset_y ) ); // Positive pixels move the trail down from its normal flow position.
		}
		if ( isset( $input['icons'] ) ) {
			$args['icons'] = filter_var( $input['icons'], FILTER_VALIDATE_BOOLEAN ); // true, "1", "yes" and "true" turn the crumb icons on.
		}
		$colors = array();
		foreach ( array( 'text' => 'colorText', 'link' => 'colorLink', 'bg' => 'colorBg', 'accent' => 'colorAccent' ) as $key => $block_key ) {
			$raw = isset( $input[ $block_key ] ) ? $input[ $block_key ] : ( isset( $input[ $key ] ) ? $input[ $key ] : '' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$colors[ $key ] = $raw; // Only #hex values survive sanitize_hex_color() in render().
			}
		}
		if ( $colors ) {
			$args['colors'] = $colors; }
		return $args;
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
		$unique = array();
		$seen   = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue; }
			$name = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			$url  = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			if ( '' === $name ) {
				continue; }
			$item_key = strtolower( $name ) . '|' . $url;
			if ( isset( $seen[ $item_key ] ) ) {
				continue; }
			$seen[ $item_key ] = true;
			$unique[] = array( 'name' => $name, 'url' => $url );
		}
		$items = $unique;
		self::$cache[ $key ] = $items;
		return $items;
	}

	private function build_items( $object_id ) {
		$items       = array();
		$label       = trim( (string) get_option( 'publishrank_breadcrumb_home_label', '' ) );
		// Home is the breadcrumb root. Publication identity remains available to document titles,
		// publisher/WebSite schema and other SEO output, but is not a breadcrumb item by default.
		$home_label = '' !== $label ? $label : __( 'Home', 'publishrank' );
		if ( ! $this->add_item( $items, $home_label, home_url( '/' ), array( 'source' => 'home' ) ) && 'disable' === $this->mode() ) {
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
	 * @param array $args Optional: separator, look, size, align, offset (px), offsetY (positive px down), icons (bool), colors (text, link, bg, accent as #hex).
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
		// Keep spacing in CSS, not inside the separator text; this prevents doubled gaps when themes add flex spacing.
		$separator = trim( (string) $separator );
		$separator_class = 'publishrank-breadcrumb-separator' . ( BreadcrumbSeparators::is_directional( $separator ) ? ' publishrank-breadcrumb-separator--directional' : '' );
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::STYLE_HANDLE ); }
		$style = ! empty( $args['look'] ) ? sanitize_key( (string) $args['look'] ) : sanitize_key( (string) get_option( 'publishrank_breadcrumb_style', 'plain' ) );
		$style = isset( self::looks()[ $style ] ) ? $style : 'plain';
		$size  = ! empty( $args['size'] ) ? sanitize_key( (string) $args['size'] ) : sanitize_key( (string) get_option( 'publishrank_breadcrumb_size', 'medium' ) );
		$size  = in_array( $size, self::SIZES, true ) ? $size : 'medium';
		$align = ! empty( $args['align'] ) ? sanitize_key( (string) $args['align'] ) : sanitize_key( (string) get_option( 'publishrank_breadcrumb_align', 'left' ) );
		$align = in_array( $align, self::ALIGNMENTS, true ) ? $align : 'left';
		// Colors: the site-wide settings first, then any value set on this block/shortcode (accent only exists per trail).
		$colors = array();
		foreach ( array( 'text' => 'publishrank_breadcrumb_text_color', 'link' => 'publishrank_breadcrumb_link_color', 'bg' => 'publishrank_breadcrumb_bg_color' ) as $var => $option ) {
			$colors[ $var ] = sanitize_hex_color( (string) get_option( $option, '' ) );
		}
		$colors['accent'] = sanitize_hex_color( (string) get_option( 'publishrank_breadcrumb_accent_color', '' ) );
		if ( ! empty( $args['colors'] ) && is_array( $args['colors'] ) ) {
			foreach ( array( 'text', 'link', 'bg', 'accent' ) as $var ) {
				$override = isset( $args['colors'][ $var ] ) ? sanitize_hex_color( (string) $args['colors'][ $var ] ) : '';
				if ( $override ) {
					$colors[ $var ] = $override; }
			}
		}
		$vars = '';
		foreach ( $colors as $var => $color ) {
			if ( $color ) {
				$vars .= '--pr-bc-' . $var . ':' . $color . ';'; }
		}
		$offset = isset( $args['offset'] ) && is_numeric( $args['offset'] ) ? (int) $args['offset'] : (int) get_option( 'publishrank_breadcrumb_offset', 0 );
		$offset = max( -600, min( 600, $offset ) );
		$offset_y = isset( $args['offsetY'] ) && is_numeric( $args['offsetY'] ) ? (int) $args['offsetY'] : (int) get_option( 'publishrank_breadcrumb_offset_y', 0 );
		$offset_y = max( 0, min( 600, $offset_y ) );
		$icons  = array_key_exists( 'icons', $args ) ? (bool) $args['icons'] : (bool) get_option( 'publishrank_breadcrumb_icons', 0 );
		$icons  = $icons && 'steps' !== $style;
		if ( 0 !== $offset ) {
			$vars .= '--pr-bc-x:' . $offset . 'px;'; }
		if ( 0 !== $offset_y ) {
			$vars .= '--pr-bc-y:' . $offset_y . 'px;'; }
		$html = '<nav class="publishrank-breadcrumbs publishrank-breadcrumbs--' . esc_attr( $style ) . ( 0 !== $offset ? ' publishrank-breadcrumbs--offset' : '' ) . ( 0 !== $offset_y ? ' publishrank-breadcrumbs--offset-y' : '' ) . ( $icons ? ' publishrank-breadcrumbs--icons' : '' ) . ' publishrank-breadcrumbs--size-' . esc_attr( $size ) . ' publishrank-breadcrumbs--align-' . esc_attr( $align ) . '"' . ( '' !== $vars ? ' style="' . esc_attr( $vars ) . '"' : '' ) . ' aria-label="' . esc_attr__( 'Breadcrumbs', 'publishrank' ) . '"><ol class="publishrank-breadcrumbs__list">';
		$last = count( $items ) - 1;
		foreach ( $items as $index => $item ) {
			$is_link = $index < $last && ! empty( $item['url'] );
			$html   .= '<li class="publishrank-breadcrumbs__item' . ( $index === $last ? ' is-current' : ' is-done' ) . '" data-step="' . esc_attr( (string) ( $index + 1 ) ) . '">';
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
