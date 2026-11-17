<?php
/**
 * "PublishRank SEO" tab in the WooCommerce product editor, plus a part-number field per variation.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class ProductTab {
	const NONCE = 'publishrank_woo_product';

	public function register() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ), 90 );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ), 20 );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 20, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_head-post.php', array( $this, 'styles' ) );
		add_action( 'admin_head-post-new.php', array( $this, 'styles' ) );
	}

	/** Small brand-coloured styles for the product tab (only on product edit screens). */
	public function styles() {
		if ( 'product' !== get_post_type() && ( ! isset( $_GET['post_type'] ) || 'product' !== $_GET['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		echo '<style id="publishrank-woo-tab">'
			. '#woocommerce-product-data ul.wc-tabs li.publishrank_seo_options a::before{content:"\\f185";font-family:dashicons;color:#9B1245}'
			. '.pr-woo-panel__score{padding:12px 12px 14px!important;background:#FBF7F2;border-top:1px solid #E4DDD2}'
			. '.pr-woo-panel__score p{margin:0 0 8px!important;padding:0!important}'
			. '.pr-woo-score{display:inline-block;padding:2px 10px;border-radius:999px;font-weight:700;color:#fff;background:#9B1245}'
			. '.pr-woo-score--ok{background:#B08A45}.pr-woo-score--good{background:#3D7264}'
			. '.pr-woo-missing{margin:0 0 0 12px!important;padding:0 0 0 14px!important;list-style:disc;columns:2;column-gap:28px;color:#4B5666}'
			. '.pr-woo-missing li{margin:0 0 4px}'
			. '.pr-woo-gtin{display:inline-block;padding-top:6px}.pr-woo-ok{color:#3D7264;font-weight:600}.pr-woo-bad{color:#B23A3A;font-weight:600}'
			. '</style>';
	}

	public function tab( $tabs ) {
		$tabs['publishrank_seo'] = array(
			'label'    => __( 'PublishRank SEO', 'publishrank' ),
			'target'   => 'publishrank_seo_product_data',
			'class'    => array(),
			'priority' => 85,
		);
		return $tabs;
	}

	/** @return array<string,string> */
	public static function condition_options() {
		return array(
			''                     => __( 'Store default', 'publishrank' ),
			'NewCondition'         => __( 'New', 'publishrank' ),
			'UsedCondition'        => __( 'Used', 'publishrank' ),
			'RefurbishedCondition' => __( 'Refurbished', 'publishrank' ),
			'DamagedCondition'     => __( 'Damaged', 'publishrank' ),
		);
	}

	public function panel() {
		global $post;
		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( ! $product ) {
			return;
		}
		$pid    = $product->get_id();
		$native = method_exists( $product, 'get_global_unique_id' );
		$raw    = ProductData::raw_gtin( $product );
		$check  = '' === $raw ? null : ProductData::check_gtin( $raw );
		echo '<div id="publishrank_seo_product_data" class="panel woocommerce_options_panel hidden pr-woo-panel">';
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		echo '<div class="options_group"><p class="pr-woo-panel__intro"><strong>' . esc_html__( 'Google product details', 'publishrank' ) . '</strong><br />' . esc_html__( 'These values go into product structured data and the Merchant Center feed. Leave a field empty when the product has no such value: nothing is ever invented.', 'publishrank' ) . '</p>';
		if ( $native ) {
			echo '<p class="form-field"><label>' . esc_html__( 'Barcode (GTIN)', 'publishrank' ) . '</label><span class="pr-woo-gtin" data-pr-gtin-status>';
			if ( null === $check ) {
				echo esc_html__( 'No barcode yet. Add it under Inventory → GTIN, UPC, EAN or ISBN.', 'publishrank' );
			} elseif ( true === $check ) {
				echo '<span class="pr-woo-ok">✓ ' . esc_html( ProductData::digits( $raw ) ) . ' — ' . esc_html__( 'valid, used for Google (change it under Inventory)', 'publishrank' ) . '</span>';
			} else {
				echo '<span class="pr-woo-bad">✕ ' . esc_html( $raw ) . ' — ' . esc_html( $check ) . '. ' . esc_html__( 'It is left out of Google data until corrected under Inventory.', 'publishrank' ) . '</span>';
			}
			echo '</span></p>';
		} else {
			woocommerce_wp_text_input(
				array(
					'id'          => 'publishrank_woo_gtin',
					'label'       => __( 'Barcode (GTIN)', 'publishrank' ),
					'value'       => $raw,
					'placeholder' => __( 'EAN, UPC or ISBN — 8, 12, 13 or 14 digits', 'publishrank' ),
					'desc_tip'    => true,
					'description' => __( 'The check digit is verified before the code is used.', 'publishrank' ),
					'custom_attributes' => array( 'data-pr-gtin' => '1', 'inputmode' => 'numeric' ),
				)
			);
		}
		woocommerce_wp_text_input( array( 'id' => 'publishrank_woo_mpn', 'label' => __( 'Part number (MPN)', 'publishrank' ), 'value' => (string) get_post_meta( $pid, ProductData::MPN, true ), 'desc_tip' => true, 'description' => __( 'The manufacturer part number. Needed by Google when there is no barcode.', 'publishrank' ) ) );
		woocommerce_wp_text_input( array( 'id' => 'publishrank_woo_brand', 'label' => __( 'Brand', 'publishrank' ), 'value' => (string) get_post_meta( $pid, ProductData::BRAND, true ), 'placeholder' => ProductData::brand( $product ), 'desc_tip' => true, 'description' => __( 'Overrides the WooCommerce Brands term, brand attribute and store default.', 'publishrank' ) ) );
		woocommerce_wp_select( array( 'id' => 'publishrank_woo_condition', 'label' => __( 'Condition', 'publishrank' ), 'value' => (string) get_post_meta( $pid, ProductData::CONDITION, true ), 'options' => self::condition_options() ) );
		woocommerce_wp_text_input( array( 'id' => 'publishrank_woo_google_category', 'label' => __( 'Google category', 'publishrank' ), 'value' => (string) get_post_meta( $pid, ProductData::GOOGLE_CAT, true ), 'placeholder' => __( 'e.g. Apparel & Accessories > Clothing > Shirts & Tops, or 212', 'publishrank' ), 'desc_tip' => true, 'description' => __( 'Google product taxonomy path or ID.', 'publishrank' ) ) );
		echo '</div><div class="options_group"><p class="pr-woo-panel__intro"><strong>' . esc_html__( 'Merchant feed', 'publishrank' ) . '</strong></p>';
		for ( $i = 0; $i < 5; $i++ ) {
			/* translators: %d: label number 0-4 */
			woocommerce_wp_text_input( array( 'id' => 'publishrank_woo_label_' . $i, 'label' => sprintf( __( 'Campaign label %d', 'publishrank' ), $i ), 'value' => (string) get_post_meta( $pid, ProductData::LABEL_PREFIX . $i, true ), 'desc_tip' => true, 'description' => __( 'Group products in Google Ads campaigns (custom_label).', 'publishrank' ) ) );
		}
		woocommerce_wp_checkbox( array( 'id' => 'publishrank_woo_exclude_feed', 'label' => __( 'Leave out of feed', 'publishrank' ), 'value' => get_post_meta( $pid, ProductData::EXCLUDE_FEED, true ) ? 'yes' : 'no', 'description' => __( 'Do not send this product to Google Merchant Center, Facebook or Instagram.', 'publishrank' ) ) );
		$audit = Audit::score( $product );
		echo '</div><div class="options_group pr-woo-panel__score"><p><strong>' . esc_html__( 'Product SEO score', 'publishrank' ) . ':</strong> <span class="pr-woo-score pr-woo-score--' . esc_attr( Audit::band( $audit['score'] ) ) . '">' . (int) $audit['score'] . '/100</span></p>';
		if ( $audit['missing'] ) {
			echo '<ul class="pr-woo-missing">';
			foreach ( $audit['missing'] as $m ) {
				echo '<li>' . esc_html( $m ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div></div>';
		?>
		<script>
		(function(){var f=document.querySelector('[data-pr-gtin]');if(!f){return;}var o=document.createElement('span');o.className='description pr-woo-gtin-live';f.parentNode.appendChild(o);function c(v){v=(v||'').replace(/\D/g,'');if(!v){return '';}if([8,12,13,14].indexOf(v.length)<0){return '✕ <?php echo esc_js( __( 'must have 8, 12, 13 or 14 digits', 'publishrank' ) ); ?>';}var b=v.slice(0,-1),s=0;for(var i=0;i<b.length;i++){var d=+b[b.length-1-i];s+=i%2===0?d*3:d;}var e=(10-s%10)%10;return e===+v.slice(-1)?'✓ <?php echo esc_js( __( 'valid barcode', 'publishrank' ) ); ?>':'✕ <?php echo esc_js( __( 'check digit should be', 'publishrank' ) ); ?> '+e;}function u(){o.textContent=c(f.value);o.style.color=/✓/.test(o.textContent)?'#275748':'#8E3941';}f.addEventListener('input',u);u();})();
		</script>
		<?php
	}

	public function save( $product ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		$pid  = $product->get_id();
		$text = static function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		};
		if ( isset( $_POST['publishrank_woo_gtin'] ) && ! method_exists( $product, 'get_global_unique_id' ) ) {
			$raw = $text( 'publishrank_woo_gtin' );
			ProductData::save_gtin( $product, $raw );
			if ( '' !== $raw && true !== ProductData::check_gtin( $raw ) ) {
				set_transient( 'publishrank_woo_gtin_notice_' . get_current_user_id(), $raw . ' — ' . ProductData::check_gtin( $raw ), 60 );
			}
		}
		$map = array(
			'publishrank_woo_mpn'             => ProductData::MPN,
			'publishrank_woo_brand'           => ProductData::BRAND,
			'publishrank_woo_google_category' => ProductData::GOOGLE_CAT,
		);
		for ( $i = 0; $i < 5; $i++ ) {
			$map[ 'publishrank_woo_label_' . $i ] = ProductData::LABEL_PREFIX . $i;
		}
		foreach ( $map as $field => $meta ) {
			$v = $text( $field );
			if ( '' === $v ) {
				$product->delete_meta_data( $meta );
			} else {
				$product->update_meta_data( $meta, $v );
			}
		}
		$cond = $text( 'publishrank_woo_condition' );
		if ( in_array( $cond, Settings::CONDITIONS, true ) ) {
			$product->update_meta_data( ProductData::CONDITION, $cond );
		} else {
			$product->delete_meta_data( ProductData::CONDITION );
		}
		if ( ! empty( $_POST['publishrank_woo_exclude_feed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product->update_meta_data( ProductData::EXCLUDE_FEED, 1 );
		} else {
			$product->delete_meta_data( ProductData::EXCLUDE_FEED );
		}
	}

	public function variation_fields( $loop, $variation_data, $variation ) {
		$value = (string) get_post_meta( $variation->ID, ProductData::MPN, true );
		echo '<div class="form-row form-row-full pr-woo-variation">';
		woocommerce_wp_text_input(
			array(
				'id'            => 'publishrank_woo_variation_mpn_' . (int) $loop,
				'name'          => 'publishrank_woo_variation_mpn[' . (int) $loop . ']',
				'label'         => __( 'Part number (MPN) — PublishRank', 'publishrank' ),
				'value'         => $value,
				'wrapper_class' => 'form-row form-row-full',
				'desc_tip'      => true,
				'description'   => __( 'Used for this variation in Google data and the feed. Empty = the main product’s part number.', 'publishrank' ),
			)
		);
		echo '</div>';
	}

	public function save_variation( $variation_id, $i ) {
		check_ajax_referer( 'save-variations', 'security' );
		$all = isset( $_POST['publishrank_woo_variation_mpn'] ) && is_array( $_POST['publishrank_woo_variation_mpn'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['publishrank_woo_variation_mpn'] ) ) : array();
		$v   = isset( $all[ $i ] ) ? trim( (string) $all[ $i ] ) : '';
		if ( '' === $v ) {
			delete_post_meta( $variation_id, ProductData::MPN );
		} else {
			update_post_meta( $variation_id, ProductData::MPN, $v );
		}
	}

	public function notice() {
		$key = 'publishrank_woo_gtin_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! $msg ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible"><p><strong>PublishRank:</strong> ' . esc_html__( 'The barcode was saved but is not valid, so it is left out of Google data:', 'publishrank' ) . ' ' . esc_html( (string) $msg ) . '</p></div>';
	}
}
