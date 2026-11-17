<?php
/**
 * Richer Google product data on top of WooCommerce's own Product markup.
 *
 * - identifiers: validated GTIN, MPN, brand, condition, Google category;
 * - attributes: colour, size, material, pattern, gender, age group, weight;
 * - merchant listing details: shipping cost/times and the return policy;
 * - variable products become a ProductGroup with one Product per variation (hasVariant);
 * - shop, category and tag pages list their products as an ItemList.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class ProductSchema {
	const MAX_VARIANTS = 60;

	/** Attribute settings mapped to schema.org properties. */
	const ATTRIBUTES = array(
		'attr_color'     => 'color',
		'attr_size'      => 'size',
		'attr_material'  => 'material',
		'attr_pattern'   => 'pattern',
		'attr_gender'    => 'suggestedGender',
		'attr_age_group' => 'suggestedAge',
	);

	public function register() {
		add_filter( 'woocommerce_structured_data_product', array( $this, 'enrich' ), 30, 2 );
		// WooCommerce prints only the types it lists for the page; variable products become ProductGroup.
		add_filter(
			'woocommerce_structured_data_type_for_page',
			static function ( $types ) {
				$types = is_array( $types ) ? $types : array();
				if ( in_array( 'product', $types, true ) && ! in_array( 'productgroup', $types, true ) ) {
					$types[] = 'productgroup';
				}
				return $types;
			}
		);
		add_filter( 'publishrank_schema_graph', array( $this, 'listing' ), 26 );
	}

	/** Offer-level additions shared by products and variations. */
	public static function offer_extras( $product, array $offer ) {
		$s = Settings::all();
		$offer['itemCondition'] = 'https://schema.org/' . ProductData::condition( $product );
		$offer['seller']        = array( '@id' => home_url( '/' ) . '#organization' );
		if ( $s['shipping_schema'] ) {
			$ship = self::shipping_details( $product );
			if ( $ship ) {
				$offer['shippingDetails'] = $ship;
			}
		}
		if ( $s['returns_schema'] ) {
			$ret = self::return_policy();
			if ( $ret ) {
				$offer['hasMerchantReturnPolicy'] = $ret;
			}
		}
		return $offer;
	}

	/** @return array<string,mixed>|null */
	public static function shipping_details( $product ) {
		$s       = Settings::all();
		$country = $s['shipping_country'] ? $s['shipping_country'] : Settings::store_country();
		if ( '' === $country || '' === (string) $s['shipping_cost'] || ! function_exists( 'get_woocommerce_currency' ) ) {
			return null;
		}
		$cost  = (float) $s['shipping_cost'];
		$price = (float) ProductData::price( $product );
		if ( '' !== (string) $s['free_shipping_over'] && $price >= (float) $s['free_shipping_over'] ) {
			$cost = 0.0;
		}
		return array(
			'@type'               => 'OfferShippingDetails',
			'shippingRate'        => array( '@type' => 'MonetaryAmount', 'value' => $cost, 'currency' => get_woocommerce_currency() ),
			'shippingDestination' => array( '@type' => 'DefinedRegion', 'addressCountry' => $country ),
			'deliveryTime'        => array(
				'@type'        => 'ShippingDeliveryTime',
				'handlingTime' => array( '@type' => 'QuantitativeValue', 'minValue' => (int) $s['handling_min'], 'maxValue' => (int) $s['handling_max'], 'unitCode' => 'DAY' ),
				'transitTime'  => array( '@type' => 'QuantitativeValue', 'minValue' => (int) $s['transit_min'], 'maxValue' => (int) $s['transit_max'], 'unitCode' => 'DAY' ),
			),
		);
	}

	/** @return array<string,mixed>|null */
	public static function return_policy() {
		$s       = Settings::all();
		$country = $s['return_country'] ? $s['return_country'] : Settings::store_country();
		if ( '' === $country ) {
			return null;
		}
		$policy = array(
			'@type'              => 'MerchantReturnPolicy',
			'applicableCountry'  => $country,
		);
		if ( (int) $s['return_days'] <= 0 ) {
			$policy['returnPolicyCategory'] = 'https://schema.org/MerchantReturnNotPermitted';
		} else {
			$policy['returnPolicyCategory'] = 'https://schema.org/MerchantReturnFiniteReturnWindow';
			$policy['merchantReturnDays']   = (int) $s['return_days'];
			$policy['returnMethod']         = 'https://schema.org/' . $s['return_method'];
			$policy['returnFees']           = 'https://schema.org/' . $s['return_fees'];
		}
		if ( $s['return_policy_url'] ) {
			$policy['merchantReturnLink'] = $s['return_policy_url'];
		}
		return $policy;
	}

	/** Identifier and attribute properties for one product or variation. */
	public static function identity( $product ) {
		$out  = array();
		$gtin = ProductData::gtin( $product );
		if ( '' !== $gtin ) {
			$out['gtin'] = $gtin;
		}
		$mpn = ProductData::mpn( $product );
		if ( '' !== $mpn ) {
			$out['mpn'] = $mpn;
		}
		if ( $product->get_sku() ) {
			$out['sku'] = (string) $product->get_sku();
		}
		$brand = ProductData::brand( $product );
		if ( '' !== $brand ) {
			$out['brand'] = array( '@type' => 'Brand', 'name' => $brand );
		}
		foreach ( self::ATTRIBUTES as $setting => $prop ) {
			$v = ProductData::attribute( $product, $setting );
			if ( '' !== $v ) {
				$out[ $prop ] = $v;
			}
		}
		$w = ProductData::weight( $product );
		if ( $w ) {
			$out['weight'] = $w;
		}
		return $out;
	}

	/** Enrich WooCommerce's Product node. */
	public function enrich( $markup, $product ) {
		if ( ! Settings::active() || ! is_array( $markup ) || ! $product ) {
			return $markup;
		}
		$s = Settings::all();
		if ( ! $s['schema'] ) {
			return $markup;
		}
		if ( $s['rich_schema'] ) {
			foreach ( self::identity( $product ) as $k => $v ) {
				if ( empty( $markup[ $k ] ) || in_array( $k, array( 'gtin', 'mpn', 'brand' ), true ) ) {
					$markup[ $k ] = $v;
				}
			}
			$gc = ProductData::google_category( $product );
			if ( '' !== $gc && empty( $markup['category'] ) ) {
				$markup['category'] = $gc;
			}
		}
		if ( ! empty( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
			foreach ( $markup['offers'] as &$offer ) {
				if ( is_array( $offer ) && 'AggregateOffer' !== ( isset( $offer['@type'] ) ? $offer['@type'] : '' ) ) {
					$offer = self::offer_extras( $product, $offer );
				}
			}
			unset( $offer );
		}
		if ( $s['variants'] && $product->is_type( 'variable' ) && empty( $markup['inProductGroupWithID'] ) ) {
			$markup = self::as_group( $product, $markup );
		}
		return $markup;
	}

	/** Turn a variable product into a ProductGroup with its variations. */
	public static function as_group( $product, array $markup ) {
		$variants = array();
		$varies   = array();
		$group_id = $product->get_sku() ? (string) $product->get_sku() : (string) $product->get_id();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$children = array_slice( (array) $product->get_children(), 0, self::MAX_VARIANTS );
		foreach ( $children as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( ! $v || ! $v->is_purchasable() || 'publish' !== get_post_status( $child_id ) ) {
				continue;
			}
			$url  = $v->get_permalink();
			$node = array(
				'@type' => 'Product',
				'@id'   => $url . '#product',
				'name'  => wp_strip_all_tags( $v->get_name() ),
				'url'   => $url,
			);
			$img = wp_get_attachment_image_url( (int) $v->get_image_id(), 'full' );
			if ( $img ) {
				$node['image'] = $img;
			}
			$node  = array_merge( $node, self::identity( $v ) );
			foreach ( array_keys( $node ) as $k ) {
				if ( in_array( $k, self::ATTRIBUTES, true ) ) {
					$varies[ $k ] = 'https://schema.org/' . $k;
				}
			}
			$price = wc_get_price_to_display( $v );
			if ( '' !== (string) $price ) {
				$stock = $v->get_stock_status();
				$offer = array(
					'@type'           => 'Offer',
					'price'           => wc_format_decimal( $price, wc_get_price_decimals() ),
					'priceCurrency'   => $currency,
					'availability'    => 'https://schema.org/' . ( 'outofstock' === $stock ? 'OutOfStock' : ( 'onbackorder' === $stock ? 'BackOrder' : 'InStock' ) ),
					'url'             => $url,
				);
				if ( $v->is_on_sale() && $v->get_date_on_sale_to() ) {
					$offer['priceValidUntil'] = $v->get_date_on_sale_to()->date( 'Y-m-d' );
				}
				$node['offers'] = self::offer_extras( $v, $offer );
			}
			$variants[] = $node;
		}
		if ( ! $variants ) {
			return $markup;
		}
		$markup['@type']          = 'ProductGroup';
		$markup['productGroupID'] = $group_id;
		if ( $varies ) {
			$markup['variesBy'] = array_values( $varies );
		}
		$markup['hasVariant'] = $variants;
		unset( $markup['offers'], $markup['gtin'], $markup['mpn'] );
		return $markup;
	}

	/** Shop, category and tag pages: list the products shown. */
	public function listing( $graph ) {
		if ( ! Settings::active() || ! is_array( $graph ) || ! Settings::get( 'listing_schema' ) ) {
			return $graph;
		}
		$is_listing = ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );
		if ( ! $is_listing ) {
			return $graph;
		}
		global $wp_query;
		$items = array();
		$pos   = 0;
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$per   = (int) $wp_query->get( 'posts_per_page' );
		$base  = $per > 0 ? ( $paged - 1 ) * $per : 0;
		foreach ( (array) $wp_query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
				continue;
			}
			++$pos;
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $base + $pos,
				'url'      => get_permalink( $post ),
				'name'     => wp_strip_all_tags( get_the_title( $post ) ),
			);
		}
		if ( ! $items ) {
			return $graph;
		}
		$url  = function_exists( 'is_shop' ) && is_shop() ? get_permalink( wc_get_page_id( 'shop' ) ) : get_term_link( get_queried_object() );
		$url  = is_wp_error( $url ) || ! $url ? home_url( '/' ) : $url;
		$id   = $url . '#itemlist';
		$graph[] = array(
			'@type'           => 'ItemList',
			'@id'             => $id,
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);
		foreach ( $graph as &$node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) && array_intersect( (array) $node['@type'], array( 'CollectionPage', 'WebPage' ) ) ) {
				$node['mainEntity'] = array( '@id' => $id );
				break;
			}
		}
		unset( $node );
		return $graph;
	}
}
