<?php
/**
 * Product identifiers and attributes PublishRank adds to WooCommerce products.
 *
 * Nothing is invented: a value that is not saved on the product (or derivable from its attributes)
 * stays empty and is left out of structured data and the feed.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class ProductData {
	const GTIN         = '_publishrank_woo_gtin';
	const MPN          = '_publishrank_woo_mpn';
	const BRAND        = '_publishrank_woo_brand';
	const CONDITION    = '_publishrank_woo_condition';
	const GOOGLE_CAT   = '_publishrank_woo_google_category';
	const EXCLUDE_FEED = '_publishrank_woo_exclude_feed';
	const LABEL_PREFIX = '_publishrank_woo_label_';
	const REDIRECT     = '_publishrank_woo_trash_redirect';

	/** Digits only. */
	public static function digits( $raw ) {
		return preg_replace( '/[^0-9]/', '', (string) $raw );
	}

	/**
	 * GTIN-8, UPC-A (12), EAN-13 and GTIN-14 with a correct check digit.
	 * Returns true, or a short reason the code is not usable.
	 *
	 * @return true|string
	 */
	public static function check_gtin( $raw ) {
		$g = self::digits( $raw );
		if ( '' === $g ) {
			return __( 'empty', 'publishrank' );
		}
		if ( ! in_array( strlen( $g ), array( 8, 12, 13, 14 ), true ) ) {
			return __( 'must have 8, 12, 13 or 14 digits', 'publishrank' );
		}
		if ( preg_match( '/^0+$/', $g ) ) {
			return __( 'cannot be all zeros', 'publishrank' );
		}
		$body  = substr( $g, 0, -1 );
		$check = (int) substr( $g, -1 );
		$sum   = 0;
		$len   = strlen( $body );
		for ( $i = 0; $i < $len; $i++ ) {
			// Weights alternate 3,1,3,1… starting from the digit next to the check digit.
			$digit = (int) $body[ $len - 1 - $i ];
			$sum  += ( 0 === $i % 2 ) ? $digit * 3 : $digit;
		}
		$expected = ( 10 - ( $sum % 10 ) ) % 10;
		if ( $expected !== $check ) {
			/* translators: %d: expected check digit */
			return sprintf( __( 'check digit should be %d', 'publishrank' ), $expected );
		}
		if ( '2' === $g[0] && 13 === strlen( $g ) ) {
			return __( 'starts with 2 (a store-internal code, not a global barcode)', 'publishrank' );
		}
		return true;
	}

	/** A valid GTIN for a product (WooCommerce's own field first, then ours), or ''. */
	public static function gtin( $product ) {
		if ( ! $product ) {
			return '';
		}
		$candidates = array();
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$candidates[] = (string) $product->get_global_unique_id();
		}
		$candidates[] = (string) get_post_meta( $product->get_id(), self::GTIN, true );
		foreach ( $candidates as $c ) {
			if ( '' !== $c && true === self::check_gtin( $c ) ) {
				return self::digits( $c );
			}
		}
		return '';
	}

	/** The raw barcode as entered (may be invalid), for the editor and audit. */
	public static function raw_gtin( $product ) {
		if ( ! $product ) {
			return '';
		}
		if ( method_exists( $product, 'get_global_unique_id' ) && '' !== (string) $product->get_global_unique_id() ) {
			return (string) $product->get_global_unique_id();
		}
		return (string) get_post_meta( $product->get_id(), self::GTIN, true );
	}

	/** Save a barcode into WooCommerce's native field when available, ours otherwise. */
	public static function save_gtin( $product, $raw ) {
		$raw = trim( (string) $raw );
		if ( method_exists( $product, 'set_global_unique_id' ) ) {
			try {
				$product->set_global_unique_id( self::digits( $raw ) );
				$product->save();
				delete_post_meta( $product->get_id(), self::GTIN );
				return true;
			} catch ( \Throwable $e ) {
				// WooCommerce refuses duplicates across products; keep ours so nothing is lost.
				update_post_meta( $product->get_id(), self::GTIN, self::digits( $raw ) );
				return $e->getMessage();
			}
		}
		if ( '' === $raw ) {
			delete_post_meta( $product->get_id(), self::GTIN );
		} else {
			update_post_meta( $product->get_id(), self::GTIN, self::digits( $raw ) );
		}
		return true;
	}

	/** Manufacturer part number: the variation's own, then the parent's. */
	public static function mpn( $product ) {
		if ( ! $product ) {
			return '';
		}
		$own = trim( (string) get_post_meta( $product->get_id(), self::MPN, true ) );
		if ( '' !== $own ) {
			return $own;
		}
		return $product->get_parent_id() ? trim( (string) get_post_meta( $product->get_parent_id(), self::MPN, true ) ) : '';
	}

	/** Brand: product field, WooCommerce Brands, a brand attribute, then the store default. */
	public static function brand( $product ) {
		if ( ! $product ) {
			return '';
		}
		$pid = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$own = trim( (string) get_post_meta( $pid, self::BRAND, true ) );
		if ( '' !== $own ) {
			return $own;
		}
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_the_terms( $pid, $tax );
				if ( $terms && ! is_wp_error( $terms ) ) {
					return (string) $terms[0]->name;
				}
			}
		}
		foreach ( array( 'pa_brand', 'brand' ) as $attr ) {
			$value = (string) $product->get_attribute( $attr );
			if ( '' !== $value ) {
				return trim( explode( ',', $value )[0] );
			}
		}
		return (string) Settings::get( 'default_brand' );
	}

	/** schema.org condition name (without URL). */
	public static function condition( $product ) {
		$pid = $product && $product->get_parent_id() ? $product->get_parent_id() : ( $product ? $product->get_id() : 0 );
		$own = $pid ? (string) get_post_meta( $pid, self::CONDITION, true ) : '';
		return in_array( $own, Settings::CONDITIONS, true ) ? $own : (string) Settings::get( 'condition' );
	}

	/** Attribute value for a configured attribute (comma-joined for simple products). */
	public static function attribute( $product, $setting_key ) {
		$attr = (string) Settings::get( $setting_key );
		if ( ! $product || '' === $attr ) {
			return '';
		}
		$value = (string) $product->get_attribute( $attr );
		if ( '' === $value && 0 !== strpos( $attr, 'pa_' ) ) {
			$value = (string) $product->get_attribute( 'pa_' . $attr );
		}
		if ( '' === $value && 0 === strpos( $attr, 'pa_' ) ) {
			// Custom (non-global) product attributes use the bare name, e.g. "color".
			$value = (string) $product->get_attribute( substr( $attr, 3 ) );
		}
		return trim( $value );
	}

	public static function google_category( $product ) {
		$pid = $product && $product->get_parent_id() ? $product->get_parent_id() : ( $product ? $product->get_id() : 0 );
		$own = $pid ? trim( (string) get_post_meta( $pid, self::GOOGLE_CAT, true ) ) : '';
		return '' !== $own ? $own : (string) Settings::get( 'feed_google_category' );
	}

	public static function excluded_from_feed( $product ) {
		if ( ! $product ) {
			return true;
		}
		if ( get_post_meta( $product->get_id(), self::EXCLUDE_FEED, true ) ) {
			return true;
		}
		return $product->get_parent_id() ? (bool) get_post_meta( $product->get_parent_id(), self::EXCLUDE_FEED, true ) : false;
	}

	/** Campaign labels 0–4. @return array<int,string> */
	public static function labels( $product ) {
		$pid = $product && $product->get_parent_id() ? $product->get_parent_id() : ( $product ? $product->get_id() : 0 );
		$out = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$v = $pid ? trim( (string) get_post_meta( $pid, self::LABEL_PREFIX . $i, true ) ) : '';
			if ( '' !== $v ) {
				$out[ $i ] = $v;
			}
		}
		return $out;
	}

	/** Weight with unit as schema.org QuantitativeValue, or null. */
	public static function weight( $product ) {
		$w = $product ? (string) $product->get_weight() : '';
		if ( '' === $w || ! is_numeric( $w ) ) {
			return null;
		}
		$unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
		$map  = array( 'kg' => 'KGM', 'g' => 'GRM', 'lbs' => 'LBR', 'oz' => 'ONZ' );
		return array( '@type' => 'QuantitativeValue', 'value' => (float) $w, 'unitCode' => isset( $map[ $unit ] ) ? $map[ $unit ] : strtoupper( $unit ) );
	}

	/** Price shown to shoppers (incl. tax when the store displays it that way). */
	public static function price( $product ) {
		if ( ! $product ) {
			return '';
		}
		if ( $product->is_type( 'variable' ) ) {
			$p = $product->get_variation_price( 'min', true );
		} else {
			$p = wc_get_price_to_display( $product );
		}
		return '' === (string) $p ? '' : wc_format_decimal( $p, wc_get_price_decimals() );
	}

	/** First product category name. */
	public static function category_name( $product ) {
		$pid   = $product && $product->get_parent_id() ? $product->get_parent_id() : ( $product ? $product->get_id() : 0 );
		$terms = $pid ? get_the_terms( $pid, 'product_cat' ) : false;
		return $terms && ! is_wp_error( $terms ) ? (string) $terms[0]->name : '';
	}

	public static function stock_label( $product ) {
		$s = $product ? $product->get_stock_status() : 'instock';
		if ( 'outofstock' === $s ) {
			return __( 'Out of stock', 'publishrank' );
		}
		return 'onbackorder' === $s ? __( 'Available on backorder', 'publishrank' ) : __( 'In stock', 'publishrank' );
	}
}
