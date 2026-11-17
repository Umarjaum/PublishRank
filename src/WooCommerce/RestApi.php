<?php
/**
 * REST endpoints for WooCommerce SEO (namespace publishrank/v1).
 *
 *   GET|POST /woocommerce/settings
 *   GET      /woocommerce/audit?search=&band=&missing=&page=&per_page=
 *   GET|POST /woocommerce/products/{id}
 *   GET      /woocommerce/products/{id}/schema
 *   POST     /woocommerce/gtin/validate      {"gtin":"..."}
 *   GET      /woocommerce/feed
 *   POST     /woocommerce/feed/rotate-key
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\WooCommerce;

use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class RestApi {
	const NS = 'publishrank/v1';

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		$manage = Capabilities::rest( 'manage_woocommerce_seo' );
		$view   = Capabilities::rest( 'view_reports' );
		register_rest_route( self::NS, '/woocommerce/settings', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_settings' ), 'permission_callback' => $manage ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'save_settings' ), 'permission_callback' => $manage ),
		) );
		register_rest_route( self::NS, '/woocommerce/audit', array( 'methods' => 'GET', 'callback' => array( $this, 'audit' ), 'permission_callback' => $view ) );
		register_rest_route( self::NS, '/woocommerce/products/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_product' ), 'permission_callback' => array( $this, 'can_edit_product' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'save_product' ), 'permission_callback' => array( $this, 'can_edit_product' ) ),
		) );
		register_rest_route( self::NS, '/woocommerce/products/(?P<id>\d+)/schema', array( 'methods' => 'GET', 'callback' => array( $this, 'schema' ), 'permission_callback' => array( $this, 'can_edit_product' ) ) );
		register_rest_route( self::NS, '/woocommerce/gtin/validate', array( 'methods' => 'POST', 'callback' => array( $this, 'validate_gtin' ), 'permission_callback' => $view ) );
		register_rest_route( self::NS, '/woocommerce/feed', array( 'methods' => 'GET', 'callback' => array( $this, 'feed' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/woocommerce/feed/rotate-key', array( 'methods' => 'POST', 'callback' => array( $this, 'rotate' ), 'permission_callback' => $manage ) );
	}

	public function can_edit_product( $request ) {
		$id = (int) $request['id'];
		return $id > 0 && 'product' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
	}

	private static function need_woo() {
		return Settings::woo_active() ? null : new \WP_Error( 'publishrank_no_woocommerce', __( 'WooCommerce is not active.', 'publishrank' ), array( 'status' => 409 ) );
	}

	public function get_settings() {
		return rest_ensure_response( array( 'settings' => array_diff_key( Settings::all(), array( 'feed_key' => 1 ) ), 'woocommerce_active' => Settings::woo_active(), 'switched_on' => Settings::switched_on() ) );
	}

	public function save_settings( $request ) {
		$in = (array) $request->get_params();
		unset( $in['feed_key'] );
		$known = array_intersect_key( $in, Settings::defaults() );
		Settings::update( $known );
		return $this->get_settings();
	}

	public function audit( $request ) {
		$err = self::need_woo();
		if ( $err ) {
			return $err;
		}
		return rest_ensure_response(
			Audit::run(
				array(
					'search'   => (string) $request->get_param( 'search' ),
					'band'     => sanitize_key( (string) $request->get_param( 'band' ) ),
					'missing'  => sanitize_key( (string) $request->get_param( 'missing' ) ),
					'paged'    => (int) ( $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1 ),
					'per_page' => (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 25 ),
				)
			)
		);
	}

	public static function product_payload( $product ) {
		$audit = Audit::score( $product );
		$raw   = ProductData::raw_gtin( $product );
		return array(
			'id'              => $product->get_id(),
			'name'            => $product->get_name(),
			'gtin'            => $raw,
			'gtin_valid'      => '' !== $raw && true === ProductData::check_gtin( $raw ),
			'gtin_problem'    => '' === $raw || true === ProductData::check_gtin( $raw ) ? '' : ProductData::check_gtin( $raw ),
			'mpn'             => (string) get_post_meta( $product->get_id(), ProductData::MPN, true ),
			'brand'           => (string) get_post_meta( $product->get_id(), ProductData::BRAND, true ),
			'brand_resolved'  => ProductData::brand( $product ),
			'condition'       => (string) get_post_meta( $product->get_id(), ProductData::CONDITION, true ),
			'google_category' => (string) get_post_meta( $product->get_id(), ProductData::GOOGLE_CAT, true ),
			'labels'          => ProductData::labels( $product ),
			'exclude_feed'    => (bool) get_post_meta( $product->get_id(), ProductData::EXCLUDE_FEED, true ),
			'score'           => $audit['score'],
			'missing'         => $audit['missing'],
		);
	}

	public function get_product( $request ) {
		$err = self::need_woo();
		if ( $err ) {
			return $err;
		}
		$p = wc_get_product( (int) $request['id'] );
		return $p ? rest_ensure_response( self::product_payload( $p ) ) : new \WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
	}

	public function save_product( $request ) {
		$err = self::need_woo();
		if ( $err ) {
			return $err;
		}
		$p = wc_get_product( (int) $request['id'] );
		if ( ! $p ) {
			return new \WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
		}
		$in = (array) $request->get_params(); // JSON, form and query parameters alike.
		unset( $in['id'] );
		self::apply_fields( $p, $in );
		return rest_ensure_response( self::product_payload( wc_get_product( $p->get_id() ) ) );
	}

	/** Shared by REST and WP-CLI. @param array<string,mixed> $in */
	public static function apply_fields( $p, array $in ) {
		$pid = $p->get_id();
		if ( array_key_exists( 'gtin', $in ) ) {
			ProductData::save_gtin( $p, (string) $in['gtin'] );
		}
		foreach ( array( 'mpn' => ProductData::MPN, 'brand' => ProductData::BRAND, 'google_category' => ProductData::GOOGLE_CAT ) as $k => $meta ) {
			if ( array_key_exists( $k, $in ) ) {
				$v = sanitize_text_field( (string) $in[ $k ] );
				'' === $v ? delete_post_meta( $pid, $meta ) : update_post_meta( $pid, $meta, $v );
			}
		}
		if ( array_key_exists( 'condition', $in ) ) {
			$c = (string) $in['condition'];
			in_array( $c, Settings::CONDITIONS, true ) ? update_post_meta( $pid, ProductData::CONDITION, $c ) : delete_post_meta( $pid, ProductData::CONDITION );
		}
		if ( array_key_exists( 'exclude_feed', $in ) ) {
			$in['exclude_feed'] ? update_post_meta( $pid, ProductData::EXCLUDE_FEED, 1 ) : delete_post_meta( $pid, ProductData::EXCLUDE_FEED );
		}
		if ( isset( $in['labels'] ) && is_array( $in['labels'] ) ) {
			for ( $i = 0; $i < 5; $i++ ) {
				$v = isset( $in['labels'][ $i ] ) ? sanitize_text_field( (string) $in['labels'][ $i ] ) : '';
				'' === $v ? delete_post_meta( $pid, ProductData::LABEL_PREFIX . $i ) : update_post_meta( $pid, ProductData::LABEL_PREFIX . $i, $v );
			}
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
	}

	/** The final Product / ProductGroup node exactly as Google will see it. */
	public static function schema_for( $product ) {
		if ( ! function_exists( 'WC' ) || ! WC()->structured_data ) {
			return array();
		}
		$sd = new \WC_Structured_Data();
		$sd->generate_product_data( $product );
		$data = $sd->get_data();
		return $data ? (array) end( $data ) : array();
	}

	public function schema( $request ) {
		$err = self::need_woo();
		if ( $err ) {
			return $err;
		}
		$p = wc_get_product( (int) $request['id'] );
		return $p ? rest_ensure_response( self::schema_for( $p ) ) : new \WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
	}

	public function validate_gtin( $request ) {
		$raw = (string) $request->get_param( 'gtin' );
		$res = ProductData::check_gtin( $raw );
		return rest_ensure_response( array( 'gtin' => ProductData::digits( $raw ), 'valid' => true === $res, 'problem' => true === $res ? '' : $res ) );
	}

	public function feed() {
		return rest_ensure_response( Feed::status() );
	}

	public function rotate() {
		Settings::rotate_feed_key();
		return rest_ensure_response( Feed::status() );
	}
}
