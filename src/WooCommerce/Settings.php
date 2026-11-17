<?php
/**
 * WooCommerce SEO settings: one option, defaults, sanitising and the master switch.
 *
 * Settings can be edited while WooCommerce is switched off, so a store can be prepared before launch.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION = 'publishrank_woocommerce_seo';
	/** Features-page switch (default on). */
	const MASTER = 'publishrank_woocommerce_enabled';

	/** Item conditions understood by Google and schema.org. */
	const CONDITIONS = array( 'NewCondition', 'UsedCondition', 'RefurbishedCondition', 'DamagedCondition' );
	const RETURN_FEES = array( 'FreeReturn', 'ReturnShippingFees', 'ReturnFeesCustomerResponsibility' );
	const RETURN_METHODS = array( 'ReturnByMail', 'ReturnInStore', 'ReturnAtKiosk' );

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			// Structured data.
			'schema'              => 1,
			'rich_schema'         => 1,
			'variants'            => 1,
			'listing_schema'      => 1,
			'condition'           => 'NewCondition',
			'default_brand'       => '',
			'attr_color'          => 'pa_color',
			'attr_size'           => 'pa_size',
			'attr_material'       => 'pa_material',
			'attr_pattern'        => 'pa_pattern',
			'attr_gender'         => '',
			'attr_age_group'      => '',
			// Shipping and returns (Google merchant listings).
			'shipping_schema'     => 0,
			'shipping_country'    => '',
			'shipping_cost'       => '',
			'free_shipping_over'  => '',
			'handling_min'        => 0,
			'handling_max'        => 1,
			'transit_min'         => 2,
			'transit_max'         => 5,
			'returns_schema'      => 0,
			'return_days'         => 30,
			'return_fees'         => 'FreeReturn',
			'return_method'       => 'ReturnByMail',
			'return_country'      => '',
			'return_policy_url'   => '',
			// Sharing.
			'og_product'          => 1,
			// Indexing.
			'noindex_utility'     => 1,
			'noindex_filters'     => 1,
			'noindex_hidden'      => 1,
			'sitemap'             => 1,
			// Store rules.
			'hide_out_of_stock'   => 0,
			'trash_redirect'      => 1,
			// Title and description templates (empty = PublishRank default).
			'tpl_product_title'   => '',
			'tpl_product_desc'    => '',
			'tpl_cat_title'       => '',
			'tpl_cat_desc'        => '',
			'tpl_tag_title'       => '',
			'tpl_tag_desc'        => '',
			'tpl_shop_title'      => '',
			'tpl_shop_desc'       => '',
			// Merchant feed.
			'feed_enabled'        => 0,
			'feed_key'            => '',
			'feed_variations'     => 1,
			'feed_out_of_stock'   => 1,
			'feed_google_category' => '',
		);
	}

	/** Keys stored as 0/1. @return string[] */
	public static function bool_keys() {
		return array( 'schema', 'rich_schema', 'variants', 'listing_schema', 'shipping_schema', 'returns_schema', 'og_product', 'noindex_utility', 'noindex_filters', 'noindex_hidden', 'sitemap', 'hide_out_of_stock', 'trash_redirect', 'feed_enabled', 'feed_variations', 'feed_out_of_stock' );
	}

	/** @var array<string,mixed>|null */
	private static $cache = null;

	public static function flush() {
		self::$cache = null;
	}

	/** @return array<string,mixed> */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved = get_option( self::OPTION, array() );
		self::$cache = self::sanitize( array_merge( self::defaults(), is_array( $saved ) ? $saved : array() ) );
		return self::$cache;
	}

	/** @return mixed */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Merge and save. Unknown keys are dropped; every value is sanitised.
	 *
	 * @param array<string,mixed> $input Partial settings.
	 */
	public static function update( array $input ) {
		$next = self::sanitize( array_merge( self::all(), $input ) );
		update_option( self::OPTION, $next, false );
		self::flush();
		return $next;
	}

	/**
	 * @param array<string,mixed> $in Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $k => $default ) {
			$v = array_key_exists( $k, $in ) ? $in[ $k ] : $default;
			if ( in_array( $k, self::bool_keys(), true ) ) {
				$out[ $k ] = empty( $v ) ? 0 : 1;
				continue;
			}
			if ( is_int( $default ) ) {
				$out[ $k ] = max( 0, min( 365, (int) $v ) );
				continue;
			}
			$v = is_scalar( $v ) ? (string) $v : '';
			switch ( $k ) {
				case 'condition':
					$out[ $k ] = in_array( $v, self::CONDITIONS, true ) ? $v : 'NewCondition';
					break;
				case 'return_fees':
					$out[ $k ] = in_array( $v, self::RETURN_FEES, true ) ? $v : 'FreeReturn';
					break;
				case 'return_method':
					$out[ $k ] = in_array( $v, self::RETURN_METHODS, true ) ? $v : 'ReturnByMail';
					break;
				case 'shipping_country':
				case 'return_country':
					$v         = strtoupper( preg_replace( '/[^A-Za-z]/', '', $v ) );
					$out[ $k ] = 2 === strlen( $v ) ? $v : '';
					break;
				case 'shipping_cost':
				case 'free_shipping_over':
					$v         = str_replace( ',', '.', trim( $v ) );
					$out[ $k ] = '' === $v || ! is_numeric( $v ) ? '' : (string) max( 0, round( (float) $v, 2 ) );
					break;
				case 'return_policy_url':
					$out[ $k ] = esc_url_raw( $v );
					break;
				case 'feed_key':
					$out[ $k ] = preg_replace( '/[^A-Za-z0-9]/', '', $v );
					break;
				case 'attr_color':
				case 'attr_size':
				case 'attr_material':
				case 'attr_pattern':
				case 'attr_gender':
				case 'attr_age_group':
					$out[ $k ] = sanitize_title( $v );
					break;
				default:
					$out[ $k ] = 0 === strpos( $k, 'tpl_' ) ? trim( sanitize_text_field( $v ) ) : sanitize_text_field( $v );
			}
		}
		if ( $out['handling_max'] < $out['handling_min'] ) {
			$out['handling_max'] = $out['handling_min'];
		}
		if ( $out['transit_max'] < $out['transit_min'] ) {
			$out['transit_max'] = $out['transit_min'];
		}
		return $out;
	}

	/** Whether WooCommerce itself is active. */
	public static function woo_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/** The Features-page switch. */
	public static function switched_on() {
		return (bool) get_option( self::MASTER, 1 );
	}

	/** Store SEO runs only when WooCommerce is active and the switch is on. */
	public static function active() {
		return self::woo_active() && self::switched_on();
	}

	/** The private feed key, created on first use. */
	public static function feed_key() {
		$key = (string) self::get( 'feed_key' );
		if ( '' === $key ) {
			$key = wp_generate_password( 32, false, false );
			self::update( array( 'feed_key' => $key ) );
		}
		return $key;
	}

	public static function rotate_feed_key() {
		$key = wp_generate_password( 32, false, false );
		self::update( array( 'feed_key' => $key ) );
		return $key;
	}

	/** Shop's base country for shipping/returns defaults. */
	public static function store_country() {
		$base = (string) get_option( 'woocommerce_default_country', '' );
		$cc   = strtoupper( substr( $base, 0, 2 ) );
		return 2 === strlen( $cc ) ? $cc : '';
	}
}
