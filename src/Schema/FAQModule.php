<?php
/**
 * PublishRank FAQ: native "FAQ" block, reusable FAQ collections, [publishrank_faq] shortcode and a
 * PHP API, all rendered by one function so visible questions and FAQPage structured data always come
 * from the same item list.
 *
 * Structured data is generated only when (a) FAQ schema is switched on, (b) the questions are visible
 * on the page (the block, the shortcode or an embedded collection is in the content) and (c) the
 * block's own "Add FAQ structured data" setting is on. Items are de-duplicated by question so a page
 * never emits two copies of the same question. Google currently shows FAQ rich results only for a
 * limited set of authoritative sites; PublishRank never promises one.
 *
 * PHP: echo \PublishRankSEO\Schema\FAQModule::render_items( $items, $args );
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Schema;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\OutputSettings;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class FAQModule {
	const BLOCK     = 'publishrank/faq';
	const CPT       = 'publishrank_faq';
	const TAX       = 'publishrank_faq_cat';
	const MAX_ITEMS = 100;

	/** @var array<int,array> Items per post, memoised for one request. */
	private static $memo = array();
	/** @var int */
	private static $instance = 0;

	public function register() {
		add_shortcode( 'publishrank_faq', array( $this, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_types' ), 9 );
		add_action( 'init', array( __CLASS__, 'register_block' ), 20 );
		if ( is_admin() ) {
			add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
			add_action( 'admin_post_publishrank_faq_duplicate', array( __CLASS__, 'handle_duplicate' ) );
			add_action( 'admin_post_publishrank_faq_export', array( __CLASS__, 'handle_export' ) );
			add_action( 'admin_post_publishrank_faq_import', array( __CLASS__, 'handle_import' ) );
			add_filter( 'bulk_actions-edit-' . self::CPT, array( __CLASS__, 'bulk_actions' ) );
			add_filter( 'handle_bulk_actions-edit-' . self::CPT, array( __CLASS__, 'handle_bulk' ), 10, 3 );
			add_action( 'admin_notices', array( __CLASS__, 'tools_panel' ) );
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
			add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
			add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		}
	}

	/* ------------------------------------------------------------------ Registration */

	public static function register_types() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'FAQ Collections', 'publishrank' ),
					'singular_name' => __( 'FAQ Collection', 'publishrank' ),
					'add_new_item'  => __( 'Add FAQ collection', 'publishrank' ),
					'edit_item'     => __( 'Edit FAQ collection', 'publishrank' ),
					'search_items'  => __( 'Search FAQ collections', 'publishrank' ),
					'not_found'     => __( 'No FAQ collections yet. Create one to reuse the same questions on several pages.', 'publishrank' ),
					'menu_name'     => __( 'FAQ Collections', 'publishrank' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'template'            => array( array( self::BLOCK, array() ) ),
				'template_lock'       => 'insert',
			)
		);
		register_taxonomy(
			self::TAX,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => __( 'FAQ Categories', 'publishrank' ),
					'singular_name' => __( 'FAQ Category', 'publishrank' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'rewrite'           => false,
			)
		);
	}

	public static function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'FAQ Collections', 'publishrank' ), __( 'FAQ Collections', 'publishrank' ), 'edit_pages', 'edit.php?post_type=' . self::CPT );
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$ver = Branding::version();
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/faq-block.js';
		$vjs = PUBLISHRANK_SEO_PATH . 'assets/js/faq.js';
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/faq.css';
		wp_register_script( 'publishrank-faq-block', Branding::asset_url( 'js/faq-block.js' ), array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-server-side-render' ), $ver . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_register_script( 'publishrank-faq-view', Branding::asset_url( 'js/faq.js' ), array(), $ver . '.' . ( file_exists( $vjs ) ? (string) filemtime( $vjs ) : '0' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_register_style( 'publishrank-faq', Branding::asset_url( 'css/faq.css' ), array(), $ver . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_localize_script(
			'publishrank-faq-block',
			'publishrankFaqBlock',
			array(
				'schemaOn' => OutputSettings::faq(),
				'cpt'      => self::CPT,
			)
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'publishrank-faq-block', 'publishrank', PUBLISHRANK_SEO_PATH . 'languages' );
		}
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'FAQ', 'publishrank' ),
				'category'        => 'widgets',
				'description'     => __( 'Questions and answers as an accessible accordion or list, with optional FAQPage structured data.', 'publishrank' ),
				'icon'            => 'editor-help',
				'keywords'        => array( 'faq', 'questions', 'accordion', 'schema' ),
				'editor_script'   => 'publishrank-faq-block',
				'view_script'     => 'publishrank-faq-view',
				'style'           => 'publishrank-faq',
				'render_callback' => array( __CLASS__, 'render_block' ),
				'supports'        => array(
					'align'      => array( 'wide', 'full' ),
					'anchor'     => true,
					'html'       => false,
					'color'      => array( 'background' => true, 'text' => true, 'link' => true ),
					'spacing'    => array( 'margin' => true, 'padding' => true ),
					'typography' => array( 'fontSize' => true, 'lineHeight' => true ),
				),
				'attributes'      => array(
					'items'         => array( 'type' => 'array', 'default' => array() ),
					'collection'    => array( 'type' => 'integer', 'default' => 0 ),
					'title'         => array( 'type' => 'string', 'default' => '' ),
					'titleLevel'    => array( 'type' => 'integer', 'default' => 2 ),
					'questionLevel' => array( 'type' => 'string', 'default' => 'none' ),
					'layout'        => array( 'type' => 'string', 'default' => 'accordion' ),
					'open'          => array( 'type' => 'string', 'default' => 'first' ),
					'singleOpen'    => array( 'type' => 'boolean', 'default' => false ),
					'icon'          => array( 'type' => 'string', 'default' => 'chevron' ),
					'iconPosition'  => array( 'type' => 'string', 'default' => 'end' ),
					'separator'     => array( 'type' => 'string', 'default' => 'card' ),
					'questionColor' => array( 'type' => 'string', 'default' => '' ),
					'answerColor'   => array( 'type' => 'string', 'default' => '' ),
					'accentColor'   => array( 'type' => 'string', 'default' => '' ),
					'itemBackground'=> array( 'type' => 'string', 'default' => '' ),
					'borderColor'   => array( 'type' => 'string', 'default' => '' ),
					'radius'        => array( 'type' => 'integer', 'default' => 10 ),
					'gap'           => array( 'type' => 'integer', 'default' => 12 ),
					'itemPadding'   => array( 'type' => 'integer', 'default' => 16 ),
					'questionSize'  => array( 'type' => 'string', 'default' => '' ),
					'questionWeight'=> array( 'type' => 'string', 'default' => '' ),
					'showSearch'    => array( 'type' => 'boolean', 'default' => false ),
					'showControls'  => array( 'type' => 'boolean', 'default' => false ),
					'schema'        => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		);
		foreach ( array( 'boxed' => __( 'Boxed', 'publishrank' ), 'minimal' => __( 'Minimal', 'publishrank' ), 'bold' => __( 'Bold', 'publishrank' ) ) as $name => $label ) {
			register_block_style( self::BLOCK, array( 'name' => $name, 'label' => $label ) );
		}
	}

	/* ------------------------------------------------------------------ Item handling */

	/** Tags allowed in answers; matches what Google accepts inside an FAQ Answer. */
	public static function allowed_answer_html() {
		return array(
			'a'      => array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true ),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
	}

	/**
	 * Normalise raw items into array( array( q, a, id ) ). Incomplete items are dropped.
	 *
	 * @param mixed $raw
	 * @return array<int,array{q:string,a:string,id:string}>
	 */
	public static function clean_items( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? array_slice( $raw, 0, self::MAX_ITEMS ) : array() as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) ( $item['q'] ?? $item['question'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) ) );
			$a = (string) ( $item['a'] ?? $item['answer'] ?? '' );
			$a = (string) preg_replace( '#<(script|style|iframe|object|template)\b[^>]*>.*?</\1\s*>#is', '', $a );
			$a = trim( wp_kses( $a, self::allowed_answer_html() ) );
			if ( '' === $q || '' === trim( wp_strip_all_tags( $a ) ) ) {
				continue;
			}
			$id    = isset( $item['id'] ) && preg_match( '/^[A-Za-z0-9_-]{1,40}$/', (string) $item['id'] ) ? (string) $item['id'] : 'q' . substr( md5( $q ), 0, 8 );
			$out[] = array( 'q' => substr( $q, 0, 500 ), 'a' => $a, 'id' => $id );
		}
		return $out;
	}

	/** Items of a published FAQ collection. */
	public static function collection_items( $collection_id ) {
		$post = get_post( (int) $collection_id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			return array();
		}
		$items = array();
		foreach ( self::find_blocks( parse_blocks( (string) $post->post_content ), 0 ) as $block ) {
			$items = array_merge( $items, self::clean_items( $block['attrs']['items'] ?? array() ) );
		}
		return $items;
	}

	/** Recursively find FAQ blocks, following synced patterns one level deep. */
	private static function find_blocks( array $blocks, $depth ) {
		$found = array();
		foreach ( $blocks as $b ) {
			if ( self::BLOCK === ( $b['blockName'] ?? '' ) ) {
				$found[] = $b;
			} elseif ( 'core/block' === ( $b['blockName'] ?? '' ) && ! empty( $b['attrs']['ref'] ) && $depth < 1 ) {
				$ref = get_post( (int) $b['attrs']['ref'] );
				if ( $ref instanceof \WP_Post && 'wp_block' === $ref->post_type && 'publish' === $ref->post_status ) {
					$found = array_merge( $found, self::find_blocks( parse_blocks( (string) $ref->post_content ), $depth + 1 ) );
				}
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$found = array_merge( $found, self::find_blocks( $b['innerBlocks'], $depth ) );
			}
		}
		return $found;
	}

	/** Items for one block's attributes (own items, or its collection). */
	private static function block_items( array $attrs ) {
		if ( ! empty( $attrs['collection'] ) ) {
			return self::collection_items( (int) $attrs['collection'] );
		}
		return self::clean_items( $attrs['items'] ?? array() );
	}

	/**
	 * Every visible FAQ item on a post that is eligible for structured data, de-duplicated by question.
	 *
	 * @return array<int,array{q:string,a:string,id:string}>
	 */
	public static function schema_items( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		if ( isset( self::$memo[ $post->ID ] ) ) {
			return self::$memo[ $post->ID ];
		}
		$items   = array();
		$content = (string) $post->post_content;
		if ( function_exists( 'parse_blocks' ) && false !== strpos( $content, '<!-- wp:' ) ) {
			foreach ( self::find_blocks( parse_blocks( $content ), 0 ) as $block ) {
				if ( array_key_exists( 'schema', $block['attrs'] ) && false === $block['attrs']['schema'] ) {
					continue;
				}
				$items = array_merge( $items, self::block_items( $block['attrs'] ) );
			}
		}
		if ( has_shortcode( $content, 'publishrank_faq' ) && preg_match_all( '/' . get_shortcode_regex( array( 'publishrank_faq' ) ) . '/', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $sc ) {
				$atts = shortcode_parse_atts( $sc[3] );
				$atts = is_array( $atts ) ? $atts : array();
				if ( isset( $atts['schema'] ) && in_array( strtolower( (string) $atts['schema'] ), array( '0', 'no', 'false', 'off' ), true ) ) {
					continue;
				}
				$items = array_merge( $items, ! empty( $atts['collection'] ) ? self::collection_items( (int) $atts['collection'] ) : self::clean_items( get_post_meta( $post->ID, PostMeta::FAQ_ITEMS, true ) ) );
			}
		}
		$seen = array();
		$out  = array();
		foreach ( $items as $item ) {
			$key = strtolower( $item['q'] );
			if ( isset( $seen[ $key ] ) ) {
				continue; // Duplicate question: keep the first visible copy only.
			}
			$seen[ $key ] = true;
			$out[]        = $item;
		}
		self::$memo[ $post->ID ] = $out;
		return $out;
	}

	/** Forget memoised items (tests, previews). */
	public static function reset() {
		self::$memo = array();
	}

	/** FAQ schema is eligible only when the page visibly contains FAQ items. */
	public static function has_visible_faq( $post ) {
		return (bool) self::schema_items( $post );
	}

	/** Question/Answer entities, sharing the visible item list. */
	public static function entities( $post ) {
		if ( ! OutputSettings::faq() ) {
			return array();
		}
		$url = $post instanceof \WP_Post ? (string) get_permalink( $post ) : '';
		$out = array();
		foreach ( self::schema_items( $post ) as $item ) {
			$out[] = array(
				'@type'          => 'Question',
				'@id'            => $url . '#' . $item['id'],
				'name'           => $item['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => trim( preg_replace( '/\s+/u', ' ', $item['a'] ) ),
				),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Rendering */

	public static function render_block( $attrs, $content = '', $block = null ) {
		$attrs = is_array( $attrs ) ? $attrs : array();
		$items = self::block_items( $attrs );
		if ( ! $items ) {
			return '';
		}
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => self::classes( $attrs ), 'style' => self::style_vars( $attrs ) ) ) : 'class="' . esc_attr( self::classes( $attrs ) ) . '" style="' . esc_attr( self::style_vars( $attrs ) ) . '"';
		return self::markup( $items, $attrs, $wrapper );
	}

	/** Shortcode: [publishrank_faq title="" open="first|all|none" limit="20" collection="123" layout="accordion|list" search="1" controls="1" schema="1"]. */
	public function shortcode( $atts = array() ) {
		if ( ! OutputSettings::faq() ) {
			return '';
		}
		$atts = shortcode_atts(
			array(
				'title'      => __( 'Frequently asked questions', 'publishrank' ),
				'open'       => 'first',
				'limit'      => 20,
				'collection' => 0,
				'layout'     => 'accordion',
				'search'     => '0',
				'controls'   => '0',
				'schema'     => '1',
			),
			$atts,
			'publishrank_faq'
		);
		if ( ! empty( $atts['collection'] ) ) {
			$items = self::collection_items( (int) $atts['collection'] );
		} else {
			$post  = get_queried_object();
			$post  = $post instanceof \WP_Post ? $post : get_post();
			$items = $post instanceof \WP_Post ? self::clean_items( get_post_meta( $post->ID, PostMeta::FAQ_ITEMS, true ) ) : array();
		}
		$items = array_slice( $items, 0, min( self::MAX_ITEMS, max( 1, absint( $atts['limit'] ) ) ) );
		if ( ! $items ) {
			return '';
		}
		return self::render_items(
			$items,
			array(
				'title'        => (string) $atts['title'],
				'open'         => (string) $atts['open'],
				'layout'       => (string) $atts['layout'],
				'showSearch'   => in_array( (string) $atts['search'], array( '1', 'yes', 'true' ), true ),
				'showControls' => in_array( (string) $atts['controls'], array( '1', 'yes', 'true' ), true ),
			)
		);
	}

	/** Public PHP API used by the shortcode and themes. */
	public static function render_items( array $items, array $args = array() ) {
		$items = self::clean_items( $items );
		if ( ! $items ) {
			return '';
		}
		wp_enqueue_style( 'publishrank-faq' );
		wp_enqueue_script( 'publishrank-faq-view' );
		return self::markup( $items, $args, 'class="' . esc_attr( self::classes( $args ) ) . '" style="' . esc_attr( self::style_vars( $args ) ) . '"' );
	}

	private static function classes( array $a ) {
		$layout = isset( $a['layout'] ) && 'list' === $a['layout'] ? 'list' : 'accordion';
		$icon   = isset( $a['icon'] ) && in_array( $a['icon'], array( 'chevron', 'plus', 'caret', 'none' ), true ) ? $a['icon'] : 'chevron';
		$sep    = isset( $a['separator'] ) && in_array( $a['separator'], array( 'card', 'line', 'dashed', 'none' ), true ) ? $a['separator'] : 'card';
		$pos    = isset( $a['iconPosition'] ) && 'start' === $a['iconPosition'] ? 'start' : 'end';
		return 'publishrank-faq publishrank-faq--' . $layout . ' publishrank-faq--icon-' . $icon . ' publishrank-faq--icon-' . $pos . ' publishrank-faq--sep-' . $sep;
	}

	private static function style_vars( array $a ) {
		$vars  = array();
		$color = static function ( $v ) {
			$v = (string) $v;
			return preg_match( '/^#[0-9a-fA-F]{3,8}$|^rgba?\([\d\s.,%]+\)$|^var\(--[a-z0-9-]+\)$/', $v ) ? $v : '';
		};
		foreach ( array( 'questionColor' => '--prfaq-q', 'answerColor' => '--prfaq-a', 'accentColor' => '--prfaq-accent', 'itemBackground' => '--prfaq-bg', 'borderColor' => '--prfaq-border' ) as $k => $var ) {
			if ( ! empty( $a[ $k ] ) && '' !== $color( $a[ $k ] ) ) {
				$vars[] = $var . ':' . $color( $a[ $k ] );
			}
		}
		foreach ( array( 'radius' => array( '--prfaq-radius', 0, 40 ), 'gap' => array( '--prfaq-gap', 0, 64 ), 'itemPadding' => array( '--prfaq-pad', 0, 64 ) ) as $k => $cfg ) {
			if ( isset( $a[ $k ] ) && '' !== (string) $a[ $k ] ) {
				$vars[] = $cfg[0] . ':' . max( $cfg[1], min( $cfg[2], (int) $a[ $k ] ) ) . 'px';
			}
		}
		if ( ! empty( $a['questionSize'] ) && preg_match( '/^\d{1,2}(?:\.\d+)?(?:px|rem|em)$/', (string) $a['questionSize'] ) ) {
			$vars[] = '--prfaq-qsize:' . $a['questionSize'];
		}
		if ( ! empty( $a['questionWeight'] ) && preg_match( '/^[1-9]00$/', (string) $a['questionWeight'] ) ) {
			$vars[] = '--prfaq-qweight:' . $a['questionWeight'];
		}
		return implode( ';', $vars );
	}

	private static function markup( array $items, array $a, $wrapper_attrs ) {
		$n       = ++self::$instance;
		$layout  = isset( $a['layout'] ) && 'list' === $a['layout'] ? 'list' : 'accordion';
		$open    = isset( $a['open'] ) && in_array( $a['open'], array( 'first', 'all', 'none' ), true ) ? $a['open'] : 'first';
		$title   = isset( $a['title'] ) ? trim( wp_strip_all_tags( (string) $a['title'] ) ) : '';
		$tlevel  = isset( $a['titleLevel'] ) ? max( 2, min( 6, (int) $a['titleLevel'] ) ) : 2;
		$qlevel  = isset( $a['questionLevel'] ) && in_array( $a['questionLevel'], array( 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $a['questionLevel'] : '';
		$title_id = 'publishrank-faq-title-' . $n;
		$data    = ' data-single="' . ( ! empty( $a['singleOpen'] ) ? '1' : '0' ) . '"';
		$html    = '<section ' . $wrapper_attrs . $data . ( '' !== $title ? ' aria-labelledby="' . esc_attr( $title_id ) . '"' : ' aria-label="' . esc_attr__( 'Frequently asked questions', 'publishrank' ) . '"' ) . '>';
		if ( '' !== $title ) {
			$html .= '<h' . $tlevel . ' class="publishrank-faq__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $title ) . '</h' . $tlevel . '>';
		}
		if ( 'accordion' === $layout && ( ! empty( $a['showSearch'] ) || ! empty( $a['showControls'] ) ) ) {
			$html .= '<div class="publishrank-faq__tools">';
			if ( ! empty( $a['showSearch'] ) ) {
				$html .= '<label class="publishrank-faq__search"><span class="screen-reader-text">' . esc_html__( 'Search questions', 'publishrank' ) . '</span><input type="search" placeholder="' . esc_attr__( 'Search questions…', 'publishrank' ) . '" data-faq-search /></label>';
			}
			if ( ! empty( $a['showControls'] ) ) {
				$html .= '<button type="button" class="publishrank-faq__toggle" data-faq-expand>' . esc_html__( 'Expand all', 'publishrank' ) . '</button><button type="button" class="publishrank-faq__toggle" data-faq-collapse>' . esc_html__( 'Collapse all', 'publishrank' ) . '</button>';
			}
			$html .= '<p class="publishrank-faq__status screen-reader-text" aria-live="polite" data-faq-status></p></div>';
		}
		$html .= '<div class="publishrank-faq__items">';
		foreach ( $items as $i => $item ) {
			$q      = esc_html( $item['q'] );
			$answer = wpautop( $item['a'] );
			$answer = wp_kses( $answer, self::allowed_answer_html() );
			$qhtml  = '' !== $qlevel ? '<' . $qlevel . ' class="publishrank-faq__q">' . $q . '</' . $qlevel . '>' : '<span class="publishrank-faq__q">' . $q . '</span>';
			$anchor = esc_attr( $item['id'] );
			if ( 'list' === $layout ) {
				$html .= '<div class="publishrank-faq__item" id="' . $anchor . '">' . ( '' !== $qlevel ? $qhtml : '<h3 class="publishrank-faq__q">' . $q . '</h3>' ) . '<div class="publishrank-faq__answer">' . $answer . '</div></div>';
				continue;
			}
			$is_open = 'all' === $open || ( 'first' === $open && 0 === $i );
			$html   .= '<details class="publishrank-faq__item" id="' . $anchor . '"' . ( $is_open ? ' open' : '' ) . '><summary>' . $qhtml . '<span class="publishrank-faq__icon" aria-hidden="true"></span></summary><div class="publishrank-faq__answer">' . $answer . '</div></details>';
		}
		return $html . '</div></section>';
	}

	/* ------------------------------------------------------------------ Collections admin */

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['prfaq_count']     = __( 'Questions', 'publishrank' );
				$new['prfaq_shortcode'] = __( 'Shortcode', 'publishrank' );
			}
		}
		return $new;
	}

	public static function column( $col, $post_id ) {
		if ( 'prfaq_count' === $col ) {
			$post  = get_post( $post_id );
			$count = 0;
			foreach ( self::find_blocks( parse_blocks( (string) $post->post_content ), 0 ) as $b ) {
				$count += count( self::clean_items( $b['attrs']['items'] ?? array() ) );
			}
			echo (int) $count;
		} elseif ( 'prfaq_shortcode' === $col ) {
			echo '<code>[publishrank_faq collection="' . (int) $post_id . '"]</code>';
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( $post instanceof \WP_Post && self::CPT === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['prfaq_duplicate'] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_faq_duplicate&post=' . (int) $post->ID ), 'publishrank_faq_duplicate_' . $post->ID ) ) . '">' . esc_html__( 'Duplicate', 'publishrank' ) . '</a>';
			$actions['prfaq_export']    = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_faq_export&ids=' . (int) $post->ID ), 'publishrank_faq_export' ) ) . '">' . esc_html__( 'Export', 'publishrank' ) . '</a>';
		}
		return $actions;
	}

	public static function duplicate( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type ) {
			return 0;
		}
		$new = wp_insert_post(
			array(
				'post_type'    => self::CPT,
				'post_status'  => 'draft',
				/* translators: %s: collection title */
				'post_title'   => sprintf( __( '%s (copy)', 'publishrank' ), $post->post_title ),
				'post_content' => wp_slash( $post->post_content ),
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			return 0;
		}
		$terms = wp_get_object_terms( $post->ID, self::TAX, array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && $terms ) {
			wp_set_object_terms( $new, $terms, self::TAX );
		}
		return (int) $new;
	}

	public static function handle_duplicate() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified next.
		check_admin_referer( 'publishrank_faq_duplicate_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$new = self::duplicate( $id );
		wp_safe_redirect( $new ? get_edit_post_link( $new, 'url' ) : admin_url( 'edit.php?post_type=' . self::CPT ) );
		exit;
	}

	/** JSON export document for collections. */
	public static function export_data( array $ids ) {
		$out = array( 'format' => 'publishrank-faq', 'version' => 1, 'exported' => gmdate( 'c' ), 'collections' => array() );
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post || self::CPT !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items = array();
			foreach ( self::find_blocks( parse_blocks( (string) $post->post_content ), 0 ) as $b ) {
				$items = array_merge( $items, self::clean_items( $b['attrs']['items'] ?? array() ) );
			}
			$out['collections'][] = array(
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'categories' => wp_get_object_terms( $post->ID, self::TAX, array( 'fields' => 'names' ) ),
				'items'      => array_map( static function ( $i ) { return array( 'question' => $i['q'], 'answer' => $i['a'] ); }, $items ),
			);
		}
		return $out;
	}

	public static function handle_export() {
		check_admin_referer( 'publishrank_faq_export' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$ids = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array();
		if ( ! $ids ) {
			$ids = get_posts( array( 'post_type' => self::CPT, 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 500, 'fields' => 'ids' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="publishrank-faq-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( self::export_data( $ids ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Import a decoded export document. Every collection becomes a new draft; nothing is overwritten.
	 *
	 * @return int Number of collections created.
	 */
	public static function import_data( $data ) {
		if ( ! is_array( $data ) || 'publishrank-faq' !== ( $data['format'] ?? '' ) || empty( $data['collections'] ) || ! is_array( $data['collections'] ) ) {
			return 0;
		}
		$created = 0;
		foreach ( array_slice( $data['collections'], 0, 200 ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$items = self::clean_items( $c['items'] ?? array() );
			if ( ! $items ) {
				continue;
			}
			$attrs = array( 'items' => array_map( static function ( $i ) { return array( 'id' => $i['id'], 'q' => $i['q'], 'a' => $i['a'] ); }, $items ) );
			$id    = wp_insert_post(
				array(
					'post_type'    => self::CPT,
					'post_status'  => 'draft',
					'post_title'   => sanitize_text_field( (string) ( $c['title'] ?? __( 'Imported FAQ', 'publishrank' ) ) ),
					'post_content' => wp_slash( serialize_block( array( 'blockName' => self::BLOCK, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) ) ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			if ( ! empty( $c['categories'] ) && is_array( $c['categories'] ) ) {
				wp_set_object_terms( $id, array_map( 'sanitize_text_field', array_slice( $c['categories'], 0, 10 ) ), self::TAX );
			}
			++$created;
		}
		return $created;
	}

	public static function handle_import() {
		check_admin_referer( 'publishrank_faq_import' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$created = 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the uploaded file is size-checked and JSON-decoded; every value is sanitised in import_data().
		$file = isset( $_FILES['faq_file']['tmp_name'] ) && is_uploaded_file( $_FILES['faq_file']['tmp_name'] ) ? $_FILES['faq_file'] : null;
		if ( $file && (int) $file['size'] > 0 && (int) $file['size'] <= 2 * MB_IN_BYTES ) {
			$created = self::import_data( json_decode( (string) file_get_contents( $file['tmp_name'] ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local uploaded temp file.
		}
		\PublishRankSEO\Admin\AdminAssets::flash( $created ? 'success' : 'error', $created ? sprintf( /* translators: %d: number */ _n( '%d FAQ collection imported as a draft.', '%d FAQ collections imported as drafts.', $created, 'publishrank' ), $created ) : __( 'Nothing was imported. Choose a PublishRank FAQ export file (JSON, up to 2 MB).', 'publishrank' ) );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::CPT ) );
		exit;
	}

	public static function bulk_actions( $actions ) {
		$actions['prfaq_export']    = __( 'Export as JSON', 'publishrank' );
		$actions['prfaq_duplicate'] = __( 'Duplicate', 'publishrank' );
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'prfaq_export' === $action ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_faq_export&ids=' . implode( ',', array_map( 'absint', (array) $ids ) ) ), 'publishrank_faq_export' );
		}
		if ( 'prfaq_duplicate' === $action ) {
			$n = 0;
			foreach ( (array) $ids as $id ) {
				if ( current_user_can( 'edit_post', (int) $id ) && self::duplicate( (int) $id ) ) {
					++$n;
				}
			}
			\PublishRankSEO\Admin\AdminAssets::flash( 'success', sprintf( /* translators: %d: number */ _n( '%d collection duplicated.', '%d collections duplicated.', $n, 'publishrank' ), $n ) );
		}
		return $redirect;
	}

	/** Import / export panel and flash messages on the collections list. */
	public static function tools_panel() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . self::CPT !== $screen->id || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		\PublishRankSEO\Admin\AdminAssets::render_flash();
		echo '<div class="card publishrank-faq-tools" data-prn-passthrough style="max-width:none;margin:12px 0 4px"><p><strong>' . esc_html__( 'Reusable FAQ collections', 'publishrank' ) . '</strong> — ' . esc_html__( 'Edit questions once and show them anywhere with the FAQ block (choose the collection in the block settings) or the shortcode in the list below. Categories help you organise and filter them.', 'publishrank' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px"><input type="hidden" name="action" value="publishrank_faq_import" />';
		wp_nonce_field( 'publishrank_faq_import' );
		echo '<label for="prfaq-file">' . esc_html__( 'Import JSON:', 'publishrank' ) . '</label> <input type="file" id="prfaq-file" name="faq_file" accept="application/json,.json" required /> <button class="button" type="submit">' . esc_html__( 'Import as drafts', 'publishrank' ) . '</button> <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_faq_export' ), 'publishrank_faq_export' ) ) . '">' . esc_html__( 'Export all', 'publishrank' ) . '</a></form><script>(function(){var c=document.currentScript&&document.currentScript.parentNode;document.addEventListener(\'DOMContentLoaded\',function(){var h=document.querySelector(\'.wrap .wp-header-end\');if(c&&h&&h.parentNode){h.parentNode.insertBefore(c,h.nextSibling);}});}());</script></div>';
	}
}
