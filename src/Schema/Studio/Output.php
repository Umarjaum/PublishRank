<?php
/**
 * Prints Schema Studio templates inside PublishRank's single connected @graph.
 *
 * Merge strategy: a template whose type matches a node PublishRank already prints for the page (Article,
 * WebPage, Organization, LocalBusiness ...) enriches that node instead of adding a duplicate. "replace"
 * lets template values win; "add" always prints a separate entity. WooCommerce products are enriched in
 * WooCommerce's own Product markup, so a product page never carries two Product entities.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Studio;

defined( 'ABSPATH' ) || exit;

final class Output {

	/** Types that are entities a page is "about"; orphans of these are connected to the WebPage. */
	const MAIN_FAMILIES = array( 'CreativeWork', 'Event', 'Product', 'Service', 'JobPosting', 'Course', 'Trip' );
	/** Identity and structural types never get mainEntityOfPage. */
	const STRUCTURAL = array( 'WebSite', 'WebPage', 'BreadcrumbList', 'ItemList', 'ImageObject', 'Person', 'Organization', 'Place', 'SiteNavigationElement', 'WPHeader', 'WPFooter', 'Brand', 'Offer', 'AggregateRating', 'Rating', 'ListItem' );

	/** @var array<int,array> Report of the last run (for diagnostics). */
	public static $report = array();

	public function register() {
		add_filter( 'publishrank_schema_graph_final', array( $this, 'apply' ), 10, 1 );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'woocommerce' ), 40, 2 );
	}

	/** Apply matching templates to the current page graph. */
	public function apply( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}
		$settings = Templates::settings();
		$ctx      = Context::current();
		if ( ! $ctx ) {
			return $graph;
		}
		$preview = apply_filters( 'publishrank_schema_studio_preview_template', null );
		if ( ! empty( $settings['enabled'] ) || is_array( $preview ) ) {
			$graph = self::apply_templates( $graph, $ctx, null, is_array( $preview ) ? $preview : null );
		}
		if ( ! empty( $settings['connect_orphans'] ) ) {
			$graph = self::connect_orphans( $graph, $ctx );
		}
		return $graph;
	}

	/** Whether WooCommerce prints the Product markup for this context (then we enrich that instead). */
	public static function woocommerce_owns_product( Context $ctx ) {
		return $ctx->post && 'product' === $ctx->post->post_type && class_exists( 'WC_Structured_Data' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * @param array<int,array> $graph
	 * @return array<int,array>
	 */
	public static function apply_templates( array $graph, Context $ctx, $only = null, $preview = null ) {
		self::$report = array();
		$templates    = null === $only ? Templates::for_context( $ctx ) : array( $only );
		if ( is_array( $preview ) ) {
			// Editor preview: the unsaved template replaces its saved version (and any template it would outrank).
			$templates = array_values(
				array_filter(
					$templates,
					static function ( $t ) use ( $preview ) {
						return $t['id'] !== $preview['id'] && ! ( $t['type'] === $preview['type'] && 'add' !== $t['mode'] && 'add' !== $preview['mode'] );
					}
				)
			);
			array_unshift( $templates, $preview );
		}
		$assign       = $ctx->post ? Templates::post_assignment( $ctx->post->ID ) : array( 'overrides' => array() );
		foreach ( $templates as $tpl ) {
			$entry = array( 'id' => $tpl['id'], 'name' => $tpl['name'], 'type' => $tpl['type'], 'mode' => $tpl['mode'], 'result' => '', 'target' => '' );
			if ( Vocabulary::is_a( $tpl['type'], 'Product' ) && self::woocommerce_owns_product( $ctx ) ) {
				$entry['result'] = 'woocommerce';
				self::$report[]  = $entry;
				continue;
			}
			$built = Engine::build( $tpl, $ctx, isset( $assign['overrides'][ $tpl['id'] ] ) ? $assign['overrides'][ $tpl['id'] ] : array() );
			if ( ! $built['node'] ) {
				$entry['result'] = 'skipped';
				$entry['reason'] = $built['skipped'];
				self::$report[]  = $entry;
				continue;
			}
			$graph           = self::place( $graph, $built['node'], $tpl, $ctx, $entry );
			self::$report[]  = $entry;
			if ( 'added' === $entry['result'] && ! empty( $tpl['replace_article'] ) && ! empty( $tpl['main_entity'] ) ) {
				$graph = self::drop_builtin_article( $graph, $ctx, $entry['target'] );
			}
		}
		return $graph;
	}

	/**
	 * A course, event, job or product page is not a news article. When a main-entity template opts in, the
	 * built-in Article node for the URL is removed, references to it are pointed at the template's entity,
	 * and the article-only author node is removed if nothing else references it.
	 */
	public static function drop_builtin_article( array $graph, Context $ctx, $replacement_id ) {
		$article_id = trailingslashit( $ctx->url ) . '#article';
		$author_ids = array();
		$found      = false;
		foreach ( $graph as $i => $node ) {
			if ( ( $node['@id'] ?? '' ) === $article_id ) {
				foreach ( (array) ( $node['author'] ?? array() ) as $k => $a ) {
					if ( is_array( $a ) && isset( $a['@id'] ) ) {
						$author_ids[] = $a['@id'];
					} elseif ( 'string' === gettype( $k ) && '@id' === $k ) {
						$author_ids[] = $a;
					}
				}
				unset( $graph[ $i ] );
				$found = true;
			}
		}
		if ( ! $found ) {
			return $graph;
		}
		$graph = array_values( $graph );
		$swap  = static function ( $value ) use ( &$swap, $article_id, $replacement_id ) {
			if ( is_array( $value ) ) {
				if ( isset( $value['@id'] ) && $value['@id'] === $article_id && 1 === count( $value ) ) {
					return array( '@id' => $replacement_id );
				}
				foreach ( $value as $k => $v ) {
					$value[ $k ] = $swap( $v );
				}
			}
			return $value;
		};
		$graph = $swap( $graph );
		$json  = (string) wp_json_encode( $graph );
		foreach ( array_unique( $author_ids ) as $aid ) {
			if ( substr_count( $json, '"' . str_replace( '/', '\\/', $aid ) . '"' ) <= 1 ) {
				foreach ( $graph as $i => $node ) {
					if ( ( $node['@id'] ?? '' ) === $aid && ! Vocabulary::any_is_a( (array) ( $node['@type'] ?? array() ), 'Organization' ) ) {
						unset( $graph[ $i ] );
					}
				}
			}
		}
		return array_values( $graph );
	}

	/** Find the node a template should merge into. */
	public static function target_index( array $graph, $type, Context $ctx ) {
		$page = trailingslashit( $ctx->url );
		foreach ( $graph as $i => $node ) {
			$types = (array) ( $node['@type'] ?? array() );
			if ( ! Vocabulary::any_is_a( $types, $type ) ) {
				continue;
			}
			$id = (string) ( $node['@id'] ?? '' );
			// Page-level nodes belong to this URL; identity nodes (Organization, WebSite, LocalBusiness) are site-level.
			if ( '' === $id || 0 === strpos( $id, $page ) || 0 === strpos( $id, $ctx->site . '#' ) ) {
				return $i;
			}
		}
		return -1;
	}

	private static function place( array $graph, array $node, array $tpl, Context $ctx, array &$entry ) {
		$index = 'add' === $tpl['mode'] ? -1 : self::target_index( $graph, $tpl['type'], $ctx );
		if ( $index >= 0 ) {
			$existing = $graph[ $index ];
			unset( $node['@type'] );
			if ( 'replace' === $tpl['mode'] ) {
				$graph[ $index ] = array_merge( $existing, $node );
			} else {
				$graph[ $index ] = $existing + $node;
			}
			$entry['result'] = 'replace' === $tpl['mode'] ? 'replaced' : 'merged';
			$entry['target'] = (string) ( $existing['@id'] ?? '' );
			return $graph;
		}
		$node['@id'] = trailingslashit( $ctx->url ) . '#' . $tpl['id'];
		$webpage     = $ctx->id( 'webpage' );
		$has_page    = false;
		foreach ( $graph as $i => $g ) {
			if ( ( $g['@id'] ?? '' ) === $webpage ) {
				$has_page = $i;
				break;
			}
		}
		if ( false !== $has_page && ! in_array( $tpl['type'], self::STRUCTURAL, true ) && self::is_main_family( $tpl['type'] ) && ! isset( $node['mainEntityOfPage'] ) && ! isset( $node['isPartOf'] ) ) {
			if ( $tpl['main_entity'] || ! Vocabulary::is_a( $tpl['type'], 'CreativeWork' ) ) {
				$node['mainEntityOfPage'] = array( '@id' => $webpage );
				if ( $tpl['main_entity'] && ! isset( $graph[ $has_page ]['mainEntity'] ) ) {
					$graph[ $has_page ]['mainEntity'] = array( '@id' => $node['@id'] );
				}
			} else {
				$node['isPartOf'] = array( '@id' => $webpage );
			}
		}
		if ( Vocabulary::is_a( $tpl['type'], 'CreativeWork' ) && ! isset( $node['publisher'] ) && Vocabulary::applies( 'publisher', $tpl['type'] ) ) {
			$node['publisher'] = array( '@id' => $ctx->id( 'organization' ) );
		}
		if ( ( Vocabulary::is_a( $tpl['type'], 'Course' ) || Vocabulary::is_a( $tpl['type'], 'Service' ) ) && ! isset( $node['provider'] ) ) {
			$node['provider'] = array( '@id' => $ctx->id( 'organization' ) );
		}
		$graph[]         = $node;
		$entry['result'] = 'added';
		$entry['target'] = $node['@id'];
		return $graph;
	}

	public static function is_main_family( $type ) {
		foreach ( self::MAIN_FAMILIES as $f ) {
			if ( Vocabulary::is_a( $type, $f ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Give every top-level main entity a stable @id and connect it to the WebPage, so the graph has no
	 * disconnected islands (for example Schema Library nodes added by older versions).
	 */
	public static function connect_orphans( array $graph, Context $ctx ) {
		$webpage  = $ctx->id( 'webpage' );
		$has_page = false;
		foreach ( $graph as $g ) {
			if ( ( $g['@id'] ?? '' ) === $webpage ) {
				$has_page = true;
				break;
			}
		}
		$counter = array();
		foreach ( $graph as $i => $node ) {
			$types = (array) ( $node['@type'] ?? array() );
			$type  = (string) reset( $types );
			if ( '' === $type || in_array( $type, self::STRUCTURAL, true ) || Vocabulary::any_is_a( $types, 'WebPage' ) || Vocabulary::any_is_a( $types, 'Organization' ) || ! self::is_main_family( $type ) ) {
				continue;
			}
			if ( empty( $node['@id'] ) ) {
				$slug                 = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $type ) );
				$counter[ $slug ]     = isset( $counter[ $slug ] ) ? $counter[ $slug ] + 1 : 1;
				$graph[ $i ]['@id']   = trailingslashit( $ctx->url ) . '#' . $slug . ( $counter[ $slug ] > 1 ? '-' . $counter[ $slug ] : '' );
			}
			if ( $has_page && ! isset( $node['mainEntityOfPage'] ) && ! isset( $node['isPartOf'] ) ) {
				// Embedded creative works (a video in an article) are part of the page; other entities are its main entity.
				$graph[ $i ][ Vocabulary::any_is_a( $types, 'CreativeWork' ) ? 'isPartOf' : 'mainEntityOfPage' ] = array( '@id' => $webpage );
			}
		}
		return $graph;
	}

	/** Enrich WooCommerce's own Product markup with matching Product templates. */
	public function woocommerce( $markup, $product = null ) {
		if ( ! is_array( $markup ) || empty( Templates::settings()['enabled'] ) || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $markup;
		}
		$ctx = Context::for_post( $product->get_id() );
		if ( ! $ctx ) {
			return $markup;
		}
		$assign = Templates::post_assignment( $product->get_id() );
		foreach ( Templates::for_context( $ctx ) as $tpl ) {
			if ( ! Vocabulary::is_a( $tpl['type'], 'Product' ) ) {
				continue;
			}
			$built = Engine::build( $tpl, $ctx, isset( $assign['overrides'][ $tpl['id'] ] ) ? $assign['overrides'][ $tpl['id'] ] : array() );
			if ( ! $built['node'] ) {
				continue;
			}
			$node = $built['node'];
			unset( $node['@type'] );
			$markup = 'replace' === $tpl['mode'] ? array_merge( $markup, $node ) : $markup + $node;
		}
		return $markup;
	}
}
