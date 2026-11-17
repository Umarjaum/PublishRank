<?php
/**
 * WooCommerce SEO.
 *
 * Runs only while WooCommerce is active and works *with* WooCommerce's own structured data instead of
 * printing a second Product node:
 *  - product pages stop being treated as news articles (no NewsArticle, article:* tags or author node);
 *  - WooCommerce's Product node is linked into the PublishRank graph (WebPage mainEntity, seller =
 *    the publisher Organization, brand from the Brands taxonomy or a "brand" attribute, item condition);
 *  - WooCommerce's duplicate BreadcrumbList and WebSite nodes are removed (PublishRank prints one of each);
 *  - Open Graph uses og:type=product with price, currency, availability, SKU and brand;
 *  - cart, checkout and account pages, sorted/filtered shop URLs and hidden products are noindex,follow;
 *  - products live in their own sitemap that leaves out catalog-hidden and noindex products.
 *
 * Since 2.24.0 it also boots the WooCommerce SEO suite in src/WooCommerce (rich Google product data,
 * the product editor tab, templates, store rules, the product audit, the Merchant Center feed, REST
 * and WP-CLI). Everything is controlled by the "WooCommerce SEO" switch on the Features page.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Integrations;

defined( 'ABSPATH' ) || exit;

final class WooCommerceSEO {

	const OPTION = \PublishRankSEO\WooCommerce\Settings::OPTION;

	public static function defaults() {
		return \PublishRankSEO\WooCommerce\Settings::defaults();
	}

	public static function settings() {
		return \PublishRankSEO\WooCommerce\Settings::all();
	}

	/** WooCommerce active and the Features switch on. */
	public static function active() {
		return \PublishRankSEO\WooCommerce\Settings::active();
	}

	public function register() {
		// Admin screens and the product tab are registered even while WooCommerce is off, so the
		// screen can always be opened and settings prepared before the shop goes live.
		( new \PublishRankSEO\WooCommerce\RestApi() )->register();
		add_action( 'woocommerce_loaded', array( $this, 'boot_store' ) );
		if ( did_action( 'woocommerce_loaded' ) ) {
			$this->boot_store();
		}
	}

	/** Hooks that only make sense while WooCommerce runs. */
	public function boot_store() {
		static $booted = false;
		if ( $booted || ! \PublishRankSEO\WooCommerce\Settings::switched_on() ) {
			return;
		}
		$booted = true;
		add_filter( 'publishrank_is_editorial_article', array( $this, 'not_article' ), 10, 2 );
		add_filter( 'publishrank_schema_graph', array( $this, 'graph' ), 25 );
		add_filter( 'woocommerce_structured_data_type_for_page', array( $this, 'woo_types' ) );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'product_markup' ), 20, 2 );
		add_filter( 'publishrank_og_type', array( $this, 'og_type' ) );
		add_action( 'publishrank_og_extra', array( $this, 'og_extra' ) );
		add_filter( 'publishrank_seo_robots', array( $this, 'robots' ), 20 );
		add_filter( 'publishrank_sitemap_providers', array( $this, 'sitemap' ) );
		add_filter( 'publishrank_sitemap_cpt_excluded', array( $this, 'exclude_cpt' ) );
		add_filter( 'publishrank_sitemap_type_labels', array( $this, 'labels' ) );
		( new \PublishRankSEO\WooCommerce\ProductSchema() )->register();
		( new \PublishRankSEO\WooCommerce\StoreRules() )->register();
		( new \PublishRankSEO\WooCommerce\Templates() )->register();
		( new \PublishRankSEO\WooCommerce\Feed() )->register();
		if ( is_admin() ) {
			( new \PublishRankSEO\WooCommerce\ProductTab() )->register();
		}
	}

	/** A real product page, not WooCommerce's "coming soon" screen shown in its place. */
	private static function on_product() {
		return self::active() && function_exists( 'is_product' ) && is_product() && ! self::serving_coming_soon();
	}

	/**
	 * Whether this front-end request gets WooCommerce's "coming soon" screen instead of the real page.
	 * Mirrors WooCommerce's own visitor rules (shop managers, store-only 404s, the exclusion filter and the
	 * private share link all see the real page). WooCommerce prints no product data on that screen.
	 */
	public static function serving_coming_soon() {
		$helper_class = 'Automattic\\WooCommerce\\Internal\\ComingSoon\\ComingSoonHelper';
		if ( ! self::active() || is_admin() || ! function_exists( 'wc_get_container' ) || ! class_exists( $helper_class ) || current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		try {
			$helper = wc_get_container()->get( $helper_class );
			if ( ! $helper->is_current_page_coming_soon() || ( $helper->is_store_coming_soon() && is_404() ) ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		/** This filter is documented by WooCommerce (since 9.1.0). */
		if ( apply_filters( 'woocommerce_coming_soon_exclude', false ) ) {
			return false;
		}
		if ( 'yes' === get_option( 'woocommerce_private_link' ) ) {
			$key = (string) get_option( 'woocommerce_share_key' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only comparison with WooCommerce's share key.
			$given = isset( $_GET['woo-share'] ) ? wp_unslash( $_GET['woo-share'] ) : ( isset( $_COOKIE['woo-share'] ) ? wp_unslash( $_COOKIE['woo-share'] ) : '' );
			if ( '' !== $key && is_string( $given ) && hash_equals( $key, $given ) ) {
				return false;
			}
		}
		return true;
	}

	/** A product is not a news article, whatever its post type flags say. */
	public function not_article( $is, $post ) {
		if ( $is && $post instanceof \WP_Post && 'product' === $post->post_type && self::active() ) {
			return false;
		}
		return $is;
	}

	/** Drop WooCommerce's breadcrumb and website nodes: PublishRank already prints one of each. */
	public function woo_types( $types ) {
		if ( ! self::settings()['schema'] ) {
			return $types;
		}
		return array_values( array_diff( (array) $types, array( 'breadcrumblist', 'website' ) ) );
	}

	public static function product_id_url( $product_id ) {
		return get_permalink( $product_id ) . '#product';
	}

	/** Brand: product field, WooCommerce Brands, a brand attribute, then the store default. */
	public static function brand( $product ) {
		return \PublishRankSEO\WooCommerce\ProductData::brand( $product );
	}

	/** Enrich WooCommerce's Product node and connect it to the PublishRank graph. */
	public function product_markup( $markup, $product ) {
		if ( ! self::settings()['schema'] || ! is_array( $markup ) || ! $product ) {
			return $markup;
		}
		$org = home_url( '/' ) . '#organization';
		$url = get_permalink( $product->get_id() );
		$markup['mainEntityOfPage'] = array( '@id' => trailingslashit( $url ) . '#webpage' );
		$brand = self::brand( $product );
		if ( '' !== $brand && empty( $markup['brand'] ) ) {
			$markup['brand'] = array( '@type' => 'Brand', 'name' => $brand );
		}
		$cats = wc_get_product_category_list( $product->get_id(), ' > ' );
		if ( $cats && empty( $markup['category'] ) ) {
			$markup['category'] = wp_strip_all_tags( $cats );
		}
		$gallery = array();
		foreach ( array_slice( (array) $product->get_gallery_image_ids(), 0, 6 ) as $img ) {
			$src = wp_get_attachment_image_url( (int) $img, 'full' );
			if ( $src ) {
				$gallery[] = $src;
			}
		}
		if ( $gallery && ! empty( $markup['image'] ) && is_string( $markup['image'] ) ) {
			$markup['image'] = array_values( array_unique( array_merge( array( $markup['image'] ), $gallery ) ) );
		}
		$condition = 'https://schema.org/' . \PublishRankSEO\WooCommerce\ProductData::condition( $product );
		if ( ! empty( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
			foreach ( $markup['offers'] as &$offer ) {
				if ( is_array( $offer ) ) {
					$offer['seller'] = array( '@id' => $org );
					if ( empty( $offer['itemCondition'] ) && 'AggregateOffer' !== ( $offer['@type'] ?? '' ) ) {
						$offer['itemCondition'] = $condition;
					}
				}
			}
			unset( $offer );
		}
		return $markup;
	}

	/** The product page's WebPage node is about the product. */
	public function graph( $graph ) {
		if ( ! self::on_product() || ! self::settings()['schema'] || ! is_array( $graph ) ) {
			return $graph;
		}
		$id = self::product_id_url( get_queried_object_id() );
		foreach ( $graph as &$node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) && in_array( 'WebPage', (array) $node['@type'], true ) ) {
				$node['@type']      = 'ItemPage';
				$node['mainEntity'] = array( '@id' => $id );
				break;
			}
		}
		unset( $node );
		return $graph;
	}

	public function og_type( $type ) {
		return self::on_product() && self::settings()['og_product'] ? 'product' : $type;
	}

	/** Open Graph product tags (Facebook/Meta product catalogue vocabulary). */
	public function og_extra( $type ) {
		if ( 'product' !== $type || ! self::on_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) {
			return;
		}
		foreach ( self::og_tags( $product ) as $property => $content ) {
			echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
		}
	}

	/** @return array<string,string> */
	public static function og_tags( $product ) {
		$tags  = array();
		$price = $product->is_type( 'variable' ) ? $product->get_variation_price( 'min', true ) : wc_get_price_to_display( $product );
		if ( '' !== (string) $price ) {
			$tags['product:price:amount']   = wc_format_decimal( $price, wc_get_price_decimals() );
			$tags['product:price:currency'] = get_woocommerce_currency();
		}
		$status                      = $product->get_stock_status();
		$tags['product:availability'] = 'outofstock' === $status ? 'out of stock' : ( 'onbackorder' === $status ? 'available for order' : 'in stock' );
		$tags['product:condition']    = strtolower( str_replace( 'Condition', '', \PublishRankSEO\WooCommerce\ProductData::condition( $product ) ) );
		$gtin                        = \PublishRankSEO\WooCommerce\ProductData::gtin( $product );
		if ( '' !== $gtin ) {
			$tags['product:gtin'] = $gtin;
		}
		$mpn = \PublishRankSEO\WooCommerce\ProductData::mpn( $product );
		if ( '' !== $mpn ) {
			$tags['product:mfr_part_no'] = $mpn;
		}
		if ( $product->get_sku() ) {
			$tags['product:retailer_item_id'] = $product->get_sku();
		}
		$brand = self::brand( $product );
		if ( '' !== $brand ) {
			$tags['product:brand'] = $brand;
		}
		return $tags;
	}

	/** Shop URL parameters that only sort or filter an existing listing. */
	public static function is_filtered_listing() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inspection of the public URL.
		foreach ( array_keys( $_GET ) as $key ) {
			$key = (string) $key;
			if ( in_array( $key, array( 'orderby', 'min_price', 'max_price', 'rating_filter', 'add-to-cart' ), true ) || 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
				return true;
			}
		}
		return false;
	}

	public function robots( $robots ) {
		if ( ! self::active() ) {
			return $robots;
		}
		// A placeholder screen must not be indexed under the real page's address.
		if ( self::serving_coming_soon() ) {
			return 'noindex,follow';
		}
		$s = self::settings();
		if ( $s['noindex_utility'] && ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) ) {
			return 'noindex,follow';
		}
		if ( $s['noindex_filters'] && ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) && self::is_filtered_listing() ) {
			return 'noindex,follow';
		}
		if ( $s['noindex_hidden'] && self::on_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product && 'hidden' === $product->get_catalog_visibility() ) {
				return 'noindex,follow';
			}
		}
		return $robots;
	}

	public function sitemap( $providers ) {
		$providers[] = new PostTypeSitemapProvider( 'products', __( 'Products', 'publishrank' ), array( 'product' ), array( __CLASS__, 'sitemap_active' ), null, array( __CLASS__, 'in_sitemap' ) );
		return $providers;
	}

	public static function sitemap_active() {
		return self::active() && self::settings()['sitemap'];
	}

	/** Catalog-hidden products (and sold-out ones, when that store rule is on) are not listed. */
	public static function in_sitemap( $post_id ) {
		$product = self::active() ? wc_get_product( (int) $post_id ) : null;
		return $product && 'hidden' !== $product->get_catalog_visibility() && ! \PublishRankSEO\WooCommerce\StoreRules::hide( $product );
	}

	public function exclude_cpt( $excluded ) {
		if ( self::sitemap_active() ) {
			$excluded[] = 'product';
		}
		return $excluded;
	}

	public function labels( $types ) {
		$types['products'] = array(
			'label' => __( 'Products', 'publishrank' ),
			'desc'  => __( 'WooCommerce products (hidden products left out)', 'publishrank' ),
			'abbr'  => 'PR',
		);
		return $types;
	}

	/**
	 * Practical product checks (kept for older callers; the full audit lives in WooCommerce\Audit).
	 *
	 * @return array<string,bool>
	 */
	public static function audit( $product ) {
		return \PublishRankSEO\WooCommerce\Audit::results( $product );
	}
}
