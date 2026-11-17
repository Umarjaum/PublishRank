<?php
/**
 * Title and description templates for products, product categories/tags and the shop page.
 *
 * Product variables: %%price%%, %%regular_price%%, %%sale_price%%, %%currency%%, %%brand%%, %%sku%%,
 * %%category%%, %%stock%%, %%rating%%, %%short_description%%. Term variables: %%term_title%%,
 * %%term_description%%, %%product_count%%. Every core variable (%%title%%, %%sep%%, %%sitename%%,
 * %%page%%…) still works. A title or description typed on the product itself always wins.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\WooCommerce;

use PublishRankSEO\Meta\MetaGenerator;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class Templates {
	public function register() {
		add_filter( 'publishrank_seo_title_pre', array( $this, 'title' ), 20 );
		add_filter( 'publishrank_seo_description_pre', array( $this, 'description' ), 20 );
	}

	/** @return array<string,string> */
	public static function variables() {
		return array(
			'%%title%%'             => __( 'Product or page name', 'publishrank' ),
			'%%sep%%'               => __( 'Title separator', 'publishrank' ),
			'%%sitename%%'          => __( 'Site name', 'publishrank' ),
			'%%price%%'             => __( 'Current price with currency', 'publishrank' ),
			'%%regular_price%%'     => __( 'Regular price', 'publishrank' ),
			'%%sale_price%%'        => __( 'Sale price (empty when not on sale)', 'publishrank' ),
			'%%brand%%'             => __( 'Brand', 'publishrank' ),
			'%%sku%%'               => __( 'SKU', 'publishrank' ),
			'%%category%%'          => __( 'Main category', 'publishrank' ),
			'%%stock%%'             => __( 'Stock status', 'publishrank' ),
			'%%rating%%'            => __( 'Average rating', 'publishrank' ),
			'%%short_description%%' => __( 'Short description', 'publishrank' ),
			'%%term_title%%'        => __( 'Category or tag name', 'publishrank' ),
			'%%term_description%%'  => __( 'Category or tag description', 'publishrank' ),
			'%%product_count%%'     => __( 'Number of products in the category', 'publishrank' ),
		);
	}

	/** Which template applies to this request: [kind, setting key prefix] or null. */
	private static function context() {
		if ( ! Settings::active() ) {
			return null;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return 'cat';
		}
		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			return 'tag';
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'shop';
		}
		return null;
	}

	public function title( $pre ) {
		if ( is_string( $pre ) && '' !== trim( $pre ) ) {
			return $pre;
		}
		$ctx = self::context();
		if ( ! $ctx ) {
			return $pre;
		}
		$tpl = (string) Settings::get( 'tpl_' . $ctx . '_title' );
		if ( '' === $tpl || self::has_own( $ctx, PostMeta::TITLE ) ) {
			return $pre;
		}
		return self::render( $tpl, $ctx, 'title' );
	}

	public function description( $pre ) {
		if ( is_string( $pre ) && '' !== trim( $pre ) ) {
			return $pre;
		}
		$ctx = self::context();
		if ( ! $ctx ) {
			return $pre;
		}
		$tpl = (string) Settings::get( 'tpl_' . $ctx . '_desc' );
		if ( '' === $tpl || self::has_own( $ctx, PostMeta::DESCRIPTION ) ) {
			return $pre;
		}
		return self::render( $tpl, $ctx, 'description' );
	}

	/** A title/description saved on the product or shop page itself wins over the template. */
	private static function has_own( $ctx, $key ) {
		if ( 'product' === $ctx ) {
			return '' !== trim( (string) get_post_meta( get_queried_object_id(), $key, true ) );
		}
		if ( 'shop' === $ctx && function_exists( 'wc_get_page_id' ) ) {
			return '' !== trim( (string) get_post_meta( wc_get_page_id( 'shop' ), $key, true ) );
		}
		return false;
	}

	/** Resolve product/term variables, then hand the rest to PublishRank's own resolver. */
	public static function render( $tpl, $ctx, $field, $object_id = 0 ) {
		$vars    = array();
		$post_id = 0;
		if ( 'product' === $ctx ) {
			$post_id = $object_id ? (int) $object_id : (int) get_queried_object_id();
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
			if ( $product ) {
				$vars = self::product_vars( $product );
			}
		} elseif ( 'cat' === $ctx || 'tag' === $ctx ) {
			$term = $object_id ? get_term( (int) $object_id ) : get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$vars = array(
					'%%term_title%%'       => $term->name,
					'%%title%%'            => $term->name,
					'%%term_description%%' => wp_strip_all_tags( term_description( $term ) ),
					'%%product_count%%'    => (string) (int) $term->count,
				);
			}
		} elseif ( 'shop' === $ctx && function_exists( 'wc_get_page_id' ) ) {
			$post_id = (int) wc_get_page_id( 'shop' );
			$vars    = array( '%%product_count%%' => (string) (int) ( wp_count_posts( 'product' )->publish ?? 0 ) );
		}
		$text = strtr( $tpl, $vars );
		$text = ( new MetaGenerator() )->resolve_template( $text, $post_id, $field );
		// Collapse separators left dangling by empty variables ("Shirt | | Shop" -> "Shirt | Shop").
		$sep  = preg_quote( \PublishRankSEO\Meta\TemplateResolver::separator(), '/' );
		$text = preg_replace( '/\s*(' . $sep . ')\s*(?:' . $sep . '\s*)+/u', ' $1 ', $text );
		$text = preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', $text );
		return trim( preg_replace( '/\s{2,}/', ' ', (string) $text ) );
	}

	/** @return array<string,string> */
	public static function product_vars( $product ) {
		$plain = static function ( $html ) {
			return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
		};
		$price   = ProductData::price( $product );
		$regular = $product->is_type( 'variable' ) ? $product->get_variation_regular_price( 'min', true ) : $product->get_regular_price();
		$sale    = $product->is_on_sale() ? ( $product->is_type( 'variable' ) ? $product->get_variation_sale_price( 'min', true ) : $product->get_sale_price() ) : '';
		return array(
			'%%price%%'             => '' !== (string) $price ? $plain( wc_price( $price ) ) : '',
			'%%regular_price%%'     => '' !== (string) $regular ? $plain( wc_price( $regular ) ) : '',
			'%%sale_price%%'        => '' !== (string) $sale ? $plain( wc_price( $sale ) ) : '',
			'%%currency%%'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'%%brand%%'             => ProductData::brand( $product ),
			'%%sku%%'               => (string) $product->get_sku(),
			'%%category%%'          => ProductData::category_name( $product ),
			'%%stock%%'             => ProductData::stock_label( $product ),
			'%%rating%%'            => $product->get_rating_count() ? wc_format_decimal( $product->get_average_rating(), 1 ) : '',
			'%%short_description%%' => wp_trim_words( $plain( $product->get_short_description() ), 30, '…' ),
		);
	}
}
