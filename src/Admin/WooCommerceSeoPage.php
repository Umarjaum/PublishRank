<?php
/**
 * PublishRank → WooCommerce SEO (shown only while WooCommerce is active).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Integrations\WooCommerceSEO;

defined( 'ABSPATH' ) || exit;

final class WooCommerceSeoPage {
	const SLUG   = 'publishrank-woocommerce';
	const ACTION = 'publishrank_woocommerce_save';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 32 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		if ( WooCommerceSEO::active() ) {
			add_submenu_page( Branding::MENU_SLUG, __( 'WooCommerce SEO', 'publishrank' ), __( 'WooCommerce SEO', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		}
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === $page ) {
			ModuleUi::enqueue();
		}
	}

	public function save() {
		AdminAssets::guard( self::ACTION );
		check_admin_referer( self::ACTION );
		$in  = isset( $_POST['woo'] ) && is_array( $_POST['woo'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['woo'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$out = array();
		foreach ( array( 'schema', 'og_product', 'noindex_utility', 'noindex_filters', 'noindex_hidden', 'sitemap' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$out['condition'] = isset( $in['condition'] ) ? sanitize_text_field( $in['condition'] ) : 'NewCondition';
		update_option( WooCommerceSEO::OPTION, $out, false );
		AdminAssets::done( self::SLUG, 'success', __( 'WooCommerce SEO settings saved.', 'publishrank' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) || ! WooCommerceSEO::active() ) {
			return;
		}
		$s = WooCommerceSEO::settings();
		echo '<div class="wrap pr21">';
		AdminAssets::header( __( 'WooCommerce SEO', 'publishrank' ), __( 'Product structured data, social sharing tags, product sitemap and noindex rules for store pages, working together with WooCommerce instead of duplicating it.', 'publishrank' ) );
		AdminAssets::render_flash();
		$toggles = array(
			'schema'          => __( 'Connect WooCommerce product schema to the PublishRank graph (one breadcrumb, seller = your organization, brand, condition, gallery images)', 'publishrank' ),
			'og_product'      => __( 'Use og:type "product" with price, currency, availability, SKU and brand when product pages are shared', 'publishrank' ),
			'noindex_utility' => __( 'Keep cart, checkout and account pages out of search results', 'publishrank' ),
			'noindex_filters' => __( 'Keep sorted and filtered shop listings out of search results (they still pass links)', 'publishrank' ),
			'noindex_hidden'  => __( 'Keep products hidden from the catalog out of search results', 'publishrank' ),
			'sitemap'         => __( 'List products in their own sitemap and leave hidden products out', 'publishrank' ),
		);
		echo '<div class="pr21-cols"><div class="pr21-main"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pr21-card">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		echo '<h2><span class="fa-solid fa-store" aria-hidden="true"></span> ' . esc_html__( 'Store SEO', 'publishrank' ) . '</h2>';
		foreach ( $toggles as $key => $label ) {
			echo '<label class="pr21-check"><input type="checkbox" name="woo[' . esc_attr( $key ) . ']" value="1"' . checked( $s[ $key ], 1, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p><label for="pr21-cond"><strong>' . esc_html__( 'Default item condition', 'publishrank' ) . '</strong></label><br /><select id="pr21-cond" name="woo[condition]">';
		foreach ( array( 'NewCondition' => __( 'New', 'publishrank' ), 'UsedCondition' => __( 'Used', 'publishrank' ), 'RefurbishedCondition' => __( 'Refurbished', 'publishrank' ), 'DamagedCondition' => __( 'Damaged', 'publishrank' ) ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['condition'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p><p><button type="submit" class="button button-primary">' . esc_html__( 'Save WooCommerce SEO', 'publishrank' ) . '</button></p></form>';
		$this->render_audit();
		echo '</div><aside class="pr21-side">';
		$this->render_preview();
		echo '</aside></div></div>';
	}

	private function render_audit() {
		$labels   = array(
			'image'       => __( 'Image', 'publishrank' ),
			'description' => __( 'Description', 'publishrank' ),
			'price'       => __( 'Price', 'publishrank' ),
			'sku'         => __( 'SKU', 'publishrank' ),
			'gtin'        => __( 'GTIN', 'publishrank' ),
			'brand'       => __( 'Brand', 'publishrank' ),
			'indexable'   => __( 'Indexable', 'publishrank' ),
		);
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC' ) );
		echo '<section class="pr21-card"><h2><span class="fa-solid fa-clipboard-check" aria-hidden="true"></span> ' . esc_html__( 'Product audit', 'publishrank' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Google needs a name, image and price for product results; SKU, GTIN and brand help it match your product to searches. Nothing is invented: missing values stay missing.', 'publishrank' ) . '</p>';
		echo '<table class="widefat striped pr21-table"><thead><tr><th>' . esc_html__( 'Product', 'publishrank' ) . '</th>';
		foreach ( $labels as $l ) {
			echo '<th>' . esc_html( $l ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $products ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No published products yet.', 'publishrank' ) . '</td></tr>';
		}
		foreach ( $products as $product ) {
			$a = WooCommerceSEO::audit( $product );
			echo '<tr><td><a href="' . esc_url( add_query_arg( 'product', $product->get_id(), Branding::page_url( self::SLUG ) ) ) . '">' . esc_html( $product->get_name() ) . '</a></td>';
			foreach ( array_keys( $labels ) as $k ) {
				echo '<td>' . ( $a[ $k ] ? '<span class="fa-solid fa-circle-check" style="color:var(--pr-teal,#3D7264)" aria-label="' . esc_attr__( 'Yes', 'publishrank' ) . '"></span>' : '<span class="fa-solid fa-circle-xmark" style="color:var(--pr-coral,#8E3941)" aria-label="' . esc_attr__( 'Missing', 'publishrank' ) . '"></span>' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></section>';
	}

	private function render_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pid = isset( $_GET['product'] ) ? absint( wp_unslash( $_GET['product'] ) ) : 0;
		if ( ! $pid ) {
			$latest = wc_get_products( array( 'status' => 'publish', 'limit' => 1, 'return' => 'ids' ) );
			$pid    = $latest ? (int) $latest[0] : 0;
		}
		$product = $pid ? wc_get_product( $pid ) : null;
		echo '<section class="pr21-card"><h2><span class="fa-brands fa-google" aria-hidden="true"></span> ' . esc_html__( 'Search result preview', 'publishrank' ) . '</h2>';
		if ( ! $product ) {
			echo '<p class="description">' . esc_html__( 'Publish a product to see its preview.', 'publishrank' ) . '</p></section>';
			return;
		}
		$url   = get_permalink( $product->get_id() );
		$title = (string) get_post_meta( $product->get_id(), '_publishrank_seo_title', true );
		$title = '' !== $title ? $title : $product->get_name() . ' | ' . \PublishRankSEO\Core\PublicationProfile::publication_name();
		$desc  = (string) get_post_meta( $product->get_id(), '_publishrank_seo_description', true );
		$desc  = '' !== $desc ? $desc : wp_trim_words( wp_strip_all_tags( $product->get_short_description() ? $product->get_short_description() : $product->get_description() ), 26 );
		$tags  = WooCommerceSEO::og_tags( $product );
		echo '<div class="pr21-serp"><div class="pr21-serp-url">' . esc_html( wp_parse_url( $url, PHP_URL_HOST ) . ' › ' . trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) . '</div>';
		echo '<div class="pr21-serp-title">' . esc_html( $title ) . '</div><div class="pr21-serp-desc">' . esc_html( $desc ) . '</div><div class="pr21-serp-rich">';
		if ( isset( $tags['product:price:amount'] ) ) {
			echo '<span>' . wp_kses_post( wc_price( $tags['product:price:amount'] ) ) . '</span>';
		}
		echo '<span>' . esc_html( ucfirst( $tags['product:availability'] ) ) . '</span>';
		if ( $product->get_rating_count() > 0 ) {
			/* translators: 1: average rating, 2: number of reviews. */
			echo '<span>★ ' . esc_html( sprintf( __( '%1$s (%2$d reviews)', 'publishrank' ), wc_format_decimal( $product->get_average_rating(), 1 ), $product->get_rating_count() ) ) . '</span>';
		}
		echo '</div></div>';
		echo '<h3>' . esc_html__( 'Open Graph product tags', 'publishrank' ) . '</h3><pre class="pr21-code">';
		foreach ( $tags as $k => $v ) {
			echo esc_html( $k . ' = ' . $v ) . "\n";
		}
		echo '</pre>';
		$markup = array();
		if ( function_exists( 'WC' ) && WC()->structured_data ) {
			$sd = WC()->structured_data;
			$sd->generate_product_data( $product );
			$data   = $sd->get_data();
			$markup = $data ? end( $data ) : array();
		}
		echo '<h3>' . esc_html__( 'Product structured data', 'publishrank' ) . '</h3><pre class="pr21-code">' . esc_html( (string) wp_json_encode( $markup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		echo '<p><a class="button" href="' . esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( $url ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Test in Google Rich Results', 'publishrank' ) . '</a> <a class="button" href="' . esc_url( get_edit_post_link( $product->get_id() ) ) . '">' . esc_html__( 'Edit product SEO', 'publishrank' ) . '</a></p></section>';
	}
}
