<?php
/**
 * PublishRank → WooCommerce SEO.
 *
 * Always registered (even when WooCommerce is off) so the screen can be found and settings prepared.
 * Tabs: Overview · Product data · Shipping & returns · Templates · Store rules · Product audit ·
 * Merchant feed · Preview.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Integrations\WooCommerceSEO;
use PublishRankSEO\WooCommerce\Audit;
use PublishRankSEO\WooCommerce\Feed;
use PublishRankSEO\WooCommerce\ProductData;
use PublishRankSEO\WooCommerce\RestApi;
use PublishRankSEO\WooCommerce\Settings;
use PublishRankSEO\WooCommerce\Templates;

defined( 'ABSPATH' ) || exit;

final class WooCommerceSeoPage {
	const SLUG   = 'publishrank-woocommerce';
	const ACTION = 'publishrank_woocommerce_save';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 32 );
		add_action( 'admin_menu', array( $this, 'woo_menu' ), 99 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'admin_post_publishrank_woo_export', array( $this, 'export' ) );
		add_action( 'admin_post_publishrank_woo_rotate', array( $this, 'rotate' ) );
		add_action( 'admin_post_publishrank_woo_switch', array( $this, 'switch_module' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'WooCommerce SEO', 'publishrank' ), __( 'WooCommerce SEO', 'publishrank' ), Capabilities::cap( 'manage_woocommerce_seo' ), self::SLUG, array( $this, 'render' ) );
	}

	/** A shortcut inside WordPress's own WooCommerce menu, so store owners find it where they look. */
	public function woo_menu() {
		if ( Settings::woo_active() ) {
			add_submenu_page( 'woocommerce', __( 'PublishRank SEO', 'publishrank' ), __( 'PublishRank SEO', 'publishrank' ), Capabilities::cap( 'manage_woocommerce_seo' ), 'admin.php?page=' . self::SLUG );
		}
	}

	public function assets() {
		if ( self::SLUG === Suite::current_page() ) {
			Suite::enqueue();
			Charts::enqueue();
		}
	}

	/** Section => fields saved by that section's form. */
	private static function sections() {
		return array(
			'product'   => array( 'schema', 'rich_schema', 'variants', 'listing_schema', 'og_product', 'condition', 'default_brand', 'attr_color', 'attr_size', 'attr_material', 'attr_pattern', 'attr_gender', 'attr_age_group' ),
			'shipping'  => array( 'shipping_schema', 'shipping_country', 'shipping_cost', 'free_shipping_over', 'handling_min', 'handling_max', 'transit_min', 'transit_max', 'returns_schema', 'return_days', 'return_fees', 'return_method', 'return_country', 'return_policy_url' ),
			'templates' => array( 'tpl_product_title', 'tpl_product_desc', 'tpl_cat_title', 'tpl_cat_desc', 'tpl_tag_title', 'tpl_tag_desc', 'tpl_shop_title', 'tpl_shop_desc' ),
			'rules'     => array( 'noindex_utility', 'noindex_filters', 'noindex_hidden', 'sitemap', 'hide_out_of_stock', 'trash_redirect' ),
			'feed'      => array( 'feed_enabled', 'feed_variations', 'feed_out_of_stock', 'feed_google_category' ),
		);
	}

	public function save() {
		Suite::guard( self::ACTION, 'manage_woocommerce_seo' );
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$all     = self::sections();
		if ( ! isset( $all[ $section ] ) ) {
			Suite::done( self::SLUG, 'error', __( 'Unknown section.', 'publishrank' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is sanitised by Settings::sanitize().
		$in  = isset( $_POST['woo'] ) && is_array( $_POST['woo'] ) ? wp_unslash( $_POST['woo'] ) : array();
		$out = array();
		foreach ( $all[ $section ] as $k ) {
			if ( in_array( $k, Settings::bool_keys(), true ) ) {
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			} elseif ( isset( $in[ $k ] ) ) {
				$out[ $k ] = $in[ $k ];
			}
		}
		Settings::update( $out );
		if ( 'feed' === $section && ! empty( $out['feed_enabled'] ) ) {
			Settings::feed_key();
		}
		Suite::done( self::SLUG, 'success', __( 'WooCommerce SEO settings saved.', 'publishrank' ), array(), $section );
	}

	public function switch_module() {
		Suite::guard( 'publishrank_woo_switch', 'manage_modules' );
		\PublishRankSEO\Core\ModuleSwitches::write( Settings::MASTER, ! empty( $_POST['on'] ) );
		Suite::done( self::SLUG, 'success', ! empty( $_POST['on'] ) ? __( 'WooCommerce SEO is on.', 'publishrank' ) : __( 'WooCommerce SEO is off.', 'publishrank' ) );
	}

	public function rotate() {
		Suite::guard( 'publishrank_woo_rotate', 'manage_woocommerce_seo' );
		Settings::rotate_feed_key();
		Suite::done( self::SLUG, 'success', __( 'A new secret feed address was created. Update it in Merchant Center and Commerce Manager.', 'publishrank' ), array(), 'feed' );
	}

	public function export() {
		Suite::guard( 'publishrank_woo_export', 'view_reports' );
		$f = self::filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard().
		Suite::csv( 'publishrank-product-audit-' . gmdate( 'Y-m-d' ) . '.csv', Audit::csv_header(), Audit::csv_rows( $f ) );
	}

	/** @param array<string,mixed> $src */
	private static function filters( $src ) {
		return array(
			'search'  => isset( $src['s'] ) ? sanitize_text_field( wp_unslash( $src['s'] ) ) : '',
			'band'    => isset( $src['band'] ) ? sanitize_key( wp_unslash( $src['band'] ) ) : '',
			'missing' => isset( $src['missing'] ) ? sanitize_key( wp_unslash( $src['missing'] ) ) : '',
			'paged'   => isset( $src['paged'] ) ? max( 1, absint( $src['paged'] ) ) : 1,
		);
	}

	private function form( $section ) {
		Suite::form_open( self::ACTION );
		echo '<input type="hidden" name="section" value="' . esc_attr( $section ) . '" />';
	}

	private function save_button() {
		echo '<div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit"><span class="fa-solid fa-floppy-disk" aria-hidden="true"></span>' . esc_html__( 'Save changes', 'publishrank' ) . '</button></div></form>';
	}

	public function render() {
		if ( ! Capabilities::can( 'manage_woocommerce_seo' ) ) {
			return;
		}
		$s      = Settings::all();
		$woo    = Settings::woo_active();
		$on     = Settings::switched_on();
		Suite::open( __( 'WooCommerce SEO', 'publishrank' ), __( 'Google product data, a product SEO tab, templates, store rules, a product audit and a Merchant Center feed — working with WooCommerce, never duplicating it.', 'publishrank' ) );
		$this->status_hero( $woo, $on );
		$audit = $woo ? Audit::run( array( 'per_page' => 25, 'paged' => 1 ) ) : null;
		echo '<div class="prx-grid prx-grid--4">';
		Suite::kpi( __( 'Products', 'publishrank' ), $audit ? number_format_i18n( $audit['summary']['count'] ) : '—', $woo ? __( 'Published, private and draft', 'publishrank' ) : __( 'WooCommerce is off', 'publishrank' ), true );
		Suite::kpi( __( 'Average SEO score', 'publishrank' ), $audit && $audit['summary']['count'] ? $audit['summary']['average'] . '/100' : '—', $audit ? sprintf( /* translators: %d: count */ __( '%d need attention', 'publishrank' ), $audit['summary']['poor'] ) : '' );
		$missing_gtin = $audit && isset( $audit['summary']['missing']['gtin'] ) ? (int) $audit['summary']['missing']['gtin'] : 0;
		Suite::kpi( __( 'Barcode or part number', 'publishrank' ), $audit && $audit['summary']['count'] ? ( $audit['summary']['count'] - $missing_gtin ) . ' / ' . $audit['summary']['count'] : '—', __( 'Products Google can match', 'publishrank' ) );
		$feed = Feed::status();
		Suite::kpi( __( 'Merchant feed', 'publishrank' ), $feed['enabled'] ? __( 'On', 'publishrank' ) : __( 'Off', 'publishrank' ), $feed['last_built'] ? sprintf( /* translators: 1: items 2: time */ __( '%1$d items · %2$s', 'publishrank' ), $feed['last_items'], Suite::ago( $feed['last_built'] ) ) : __( 'Not fetched yet', 'publishrank' ) );
		echo '</div>';
		$tabs = array(
			'overview'  => __( 'Overview', 'publishrank' ),
			'product'   => __( 'Product data', 'publishrank' ),
			'shipping'  => __( 'Shipping & returns', 'publishrank' ),
			'templates' => __( 'Templates', 'publishrank' ),
			'rules'     => __( 'Store rules', 'publishrank' ),
			'audit'     => __( 'Product audit', 'publishrank' ) . ( $audit ? '|' . $audit['summary']['count'] : '' ),
			'feed'      => __( 'Merchant feed', 'publishrank' ),
			'preview'   => __( 'Preview', 'publishrank' ),
		);
		Suite::tabs( $tabs );
		$this->tab_overview( $s, $audit, $woo );
		$this->tab_product( $s );
		$this->tab_shipping( $s );
		$this->tab_templates( $s );
		$this->tab_rules( $s );
		$this->tab_audit( $woo );
		$this->tab_feed( $s, $woo );
		$this->tab_preview( $woo );
		Suite::close();
	}

	private function status_hero( $woo, $on ) {
		$tone  = $woo && $on ? 'ok' : 'warn';
		$title = $woo ? ( $on ? __( 'WooCommerce SEO is working on your shop', 'publishrank' ) : __( 'WooCommerce SEO is switched off', 'publishrank' ) ) : __( 'WooCommerce is not active yet', 'publishrank' );
		$text  = $woo ? ( $on ? __( 'Products, categories and the shop page get PublishRank titles, Google product data and store rules.', 'publishrank' ) : __( 'Your settings are kept. Switch it on to send product data to Google again.', 'publishrank' ) ) : __( 'You can set everything up now. It starts working the moment WooCommerce is activated.', 'publishrank' );
		echo '<div class="prx-hero pr-woo-hero"><div class="prx-hero__state"><span class="prx-hero__icon is-' . esc_attr( $tone ) . '"><span class="fa-solid fa-store" aria-hidden="true"></span></span><div><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p></div></div><div class="prx-row">';
		if ( ! $woo ) {
			$installed = file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' );
			if ( $installed && current_user_can( 'activate_plugins' ) ) {
				$url = wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=woocommerce/woocommerce.php' ), 'activate-plugin_woocommerce/woocommerce.php' );
				echo '<a class="prx-btn prx-btn--primary prx-btn--sm" href="' . esc_url( $url ) . '">' . esc_html__( 'Activate WooCommerce', 'publishrank' ) . '</a>';
			} elseif ( current_user_can( 'install_plugins' ) ) {
				echo '<a class="prx-btn prx-btn--primary prx-btn--sm" href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">' . esc_html__( 'Install WooCommerce', 'publishrank' ) . '</a>';
			}
		}
		if ( Capabilities::can( 'manage_modules' ) ) {
			Suite::form_open( 'publishrank_woo_switch' );
			echo '<input type="hidden" name="on" value="' . ( $on ? '0' : '1' ) . '" /><button class="prx-btn prx-btn--sm ' . ( $on ? '' : 'prx-btn--primary' ) . '" type="submit">' . esc_html( $on ? __( 'Switch off', 'publishrank' ) : __( 'Switch on', 'publishrank' ) ) . '</button></form>';
		}
		echo '</div></div>';
	}

	private function tab_overview( array $s, $audit, $woo ) {
		Suite::panel_open( 'overview', false );
		echo '<div class="prx-grid prx-grid--side"><div class="prx-stack">';
		Suite::card_open( __( 'Product SEO scores', 'publishrank' ), 'fa-chart-pie', __( 'How many products are ready for Google.', 'publishrank' ) );
		if ( $audit && $audit['summary']['count'] ) {
			$sum = $audit['summary'];
			echo '<div class="pr-woo-overview">' . Charts::donut( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Charts.
				array(
					array( 'label' => __( 'Good (80+)', 'publishrank' ), 'value' => $sum['good'], 'color' => 'teal' ),
					array( 'label' => __( 'Fair (50–79)', 'publishrank' ), 'value' => $sum['ok'], 'color' => 'brass' ),
					array( 'label' => __( 'Poor (under 50)', 'publishrank' ), 'value' => $sum['poor'], 'color' => 'plum' ),
				),
				(string) $sum['average'],
				__( 'average', 'publishrank' )
			) . '</div>';
		} else {
			echo '<div class="prx-empty"><span class="fa-solid fa-box-open" aria-hidden="true"></span><strong>' . esc_html( $woo ? __( 'No products yet', 'publishrank' ) : __( 'Activate WooCommerce to see your products here', 'publishrank' ) ) . '</strong></div>';
		}
		Suite::card_close();
		if ( $audit && ! empty( $audit['summary']['missing'] ) ) {
			Suite::card_open( __( 'Most common gaps', 'publishrank' ), 'fa-list-check', __( 'Fixing these lifts the most products at once.', 'publishrank' ) );
			$checks = Audit::checks();
			$rows   = array();
			foreach ( array_slice( $audit['summary']['missing'], 0, 6, true ) as $k => $n ) {
				$rows[] = array( 'label' => $checks[ $k ][1], 'value' => (int) $n, 'url' => add_query_arg( array( 'missing' => $k ), Suite::url( self::SLUG ) ) . '#audit' );
			}
			echo Charts::bars( $rows, (int) $audit['summary']['count'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Charts.
			Suite::card_close();
		}
		echo '</div><div class="prx-stack">';
		Suite::card_open( __( 'Setup checklist', 'publishrank' ), 'fa-clipboard-check' );
		$items = array(
			array( $woo, __( 'WooCommerce is active', 'publishrank' ) ),
			array( Settings::switched_on(), __( 'WooCommerce SEO is switched on', 'publishrank' ) ),
			array( (bool) $s['rich_schema'], __( 'Rich product data (barcode, part number, brand, attributes)', 'publishrank' ) ),
			array( (bool) $s['variants'], __( 'Each variation listed separately for Google', 'publishrank' ) ),
			array( (bool) $s['shipping_schema'] && '' !== (string) $s['shipping_cost'], __( 'Shipping cost and delivery times', 'publishrank' ) ),
			array( (bool) $s['returns_schema'], __( 'Return policy', 'publishrank' ) ),
			array( '' !== $s['tpl_product_title'], __( 'Product title template', 'publishrank' ) ),
			array( (bool) $s['feed_enabled'], __( 'Merchant Center feed', 'publishrank' ) ),
		);
		echo '<ul class="prx-list">';
		foreach ( $items as $i ) {
			echo '<li><span class="fa-solid ' . ( $i[0] ? 'fa-circle-check pr-ok' : 'fa-circle pr-todo' ) . '" aria-hidden="true"></span><div>' . esc_html( $i[1] ) . '</div></li>';
		}
		echo '</ul>';
		Suite::card_close();
		Suite::card_open( __( 'Where to find things', 'publishrank' ), 'fa-compass' );
		echo '<ul class="prx-list pr-woo-help"><li><span class="fa-solid fa-pen" aria-hidden="true"></span><div>' . esc_html__( 'Products → Edit → Product data → “PublishRank SEO” tab: barcode check, part number, brand, condition, Google category, campaign labels and “leave out of feed”.', 'publishrank' ) . '</div></li><li><span class="fa-solid fa-layer-group" aria-hidden="true"></span><div>' . esc_html__( 'Each variation has its own part-number field under Variations.', 'publishrank' ) . '</div></li><li><span class="fa-solid fa-terminal" aria-hidden="true"></span><div><code>wp publishrank woo audit</code> · <code>wp publishrank woo export-feed</code></div></li></ul>';
		Suite::card_close();
		echo '</div></div>';
		Suite::panel_close();
	}

	private function tab_product( array $s ) {
		Suite::panel_open( 'product' );
		Suite::card_open( __( 'Google product data', 'publishrank' ), 'fa-barcode', __( 'Added to WooCommerce’s own Product markup — one product node, never two.', 'publishrank' ) );
		$this->form( 'product' );
		Suite::switch_field( 'woo[schema]', $s['schema'], __( 'Connect product data to the PublishRank graph', 'publishrank' ), __( 'One breadcrumb, seller = your organisation, gallery images, item condition.', 'publishrank' ) );
		Suite::switch_field( 'woo[rich_schema]', $s['rich_schema'], __( 'Rich identifiers and attributes', 'publishrank' ), __( 'Validated barcode (GTIN), part number (MPN), brand, colour, size, material, pattern, gender, age group, weight and Google category.', 'publishrank' ) );
		Suite::switch_field( 'woo[variants]', $s['variants'], __( 'List every variation', 'publishrank' ), __( 'Variable products become a product group with one entry per size or colour, each with its own price, stock and identifiers.', 'publishrank' ) );
		Suite::switch_field( 'woo[listing_schema]', $s['listing_schema'], __( 'Shop and category pages list their products', 'publishrank' ), __( 'Adds an item list so Google understands the page is a collection.', 'publishrank' ) );
		Suite::switch_field( 'woo[og_product]', $s['og_product'], __( 'Product sharing tags', 'publishrank' ), __( 'Facebook, Pinterest and WhatsApp previews show price, currency, stock, brand, barcode and condition.', 'publishrank' ) );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::field( 'select', 'woo[condition]', $s['condition'], __( 'Default condition', 'publishrank' ), __( 'Each product can override this in its PublishRank SEO tab.', 'publishrank' ), array(), array( 'NewCondition' => __( 'New', 'publishrank' ), 'UsedCondition' => __( 'Used', 'publishrank' ), 'RefurbishedCondition' => __( 'Refurbished', 'publishrank' ), 'DamagedCondition' => __( 'Damaged', 'publishrank' ) ) );
		Suite::field( 'text', 'woo[default_brand]', $s['default_brand'], __( 'Default brand', 'publishrank' ), __( 'Used only when a product has no brand of its own. Leave empty if you sell several brands.', 'publishrank' ) );
		echo '</div><h3 class="prx-subhead">' . esc_html__( 'Which product attributes hold…', 'publishrank' ) . '</h3><div class="prx-grid prx-grid--3">';
		$attrs = array( '' => __( '— not used —', 'publishrank' ) );
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $t ) {
				$attrs[ 'pa_' . $t->attribute_name ] = $t->attribute_label;
			}
		}
		foreach ( array( 'attr_color' => __( 'Colour', 'publishrank' ), 'attr_size' => __( 'Size', 'publishrank' ), 'attr_material' => __( 'Material', 'publishrank' ), 'attr_pattern' => __( 'Pattern', 'publishrank' ), 'attr_gender' => __( 'Gender', 'publishrank' ), 'attr_age_group' => __( 'Age group', 'publishrank' ) ) as $k => $label ) {
			$opts = $attrs;
			if ( '' !== $s[ $k ] && ! isset( $opts[ $s[ $k ] ] ) ) {
				$opts[ $s[ $k ] ] = $s[ $k ];
			}
			Suite::field( 'select', 'woo[' . $k . ']', $s[ $k ], $label, '', array(), $opts );
		}
		echo '</div>';
		$this->save_button();
		Suite::card_close();
		Suite::panel_close();
	}

	private function tab_shipping( array $s ) {
		Suite::panel_open( 'shipping' );
		$store = Settings::store_country();
		$cur   = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		Suite::card_open( __( 'Shipping and returns for Google', 'publishrank' ), 'fa-truck-fast', __( 'Google shows delivery cost, delivery time and return terms on product results. Only switch these on when they match what you really offer.', 'publishrank' ) );
		$this->form( 'shipping' );
		echo '<div class="prx-grid prx-grid--2"><div class="prx-stack">';
		Suite::switch_field( 'woo[shipping_schema]', $s['shipping_schema'], __( 'Show shipping details', 'publishrank' ), __( 'Shipping cost and handling/transit days for your main country.', 'publishrank' ) );
		Suite::field( 'text', 'woo[shipping_country]', $s['shipping_country'], __( 'Ships to (country code)', 'publishrank' ), sprintf( /* translators: %s: country code */ __( 'Two letters, e.g. US, GB, PK. Empty = your store country (%s).', 'publishrank' ), $store ? $store : '—' ), array( 'maxlength' => 2 ) );
		/* translators: %s: currency code */
		Suite::field( 'text', 'woo[shipping_cost]', $s['shipping_cost'], sprintf( __( 'Standard shipping cost (%s)', 'publishrank' ), $cur ), __( '0 for free shipping.', 'publishrank' ), array( 'inputmode' => 'decimal' ) );
		/* translators: %s: currency code */
		Suite::field( 'text', 'woo[free_shipping_over]', $s['free_shipping_over'], sprintf( __( 'Free shipping on orders over (%s)', 'publishrank' ), $cur ), __( 'Optional. Products at or above this price show free shipping.', 'publishrank' ), array( 'inputmode' => 'decimal' ) );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::field( 'number', 'woo[handling_min]', $s['handling_min'], __( 'Handling days (min)', 'publishrank' ), '', array( 'min' => 0, 'max' => 30 ) );
		Suite::field( 'number', 'woo[handling_max]', $s['handling_max'], __( 'Handling days (max)', 'publishrank' ), '', array( 'min' => 0, 'max' => 30 ) );
		Suite::field( 'number', 'woo[transit_min]', $s['transit_min'], __( 'Delivery days (min)', 'publishrank' ), '', array( 'min' => 0, 'max' => 60 ) );
		Suite::field( 'number', 'woo[transit_max]', $s['transit_max'], __( 'Delivery days (max)', 'publishrank' ), '', array( 'min' => 0, 'max' => 60 ) );
		echo '</div></div><div class="prx-stack">';
		Suite::switch_field( 'woo[returns_schema]', $s['returns_schema'], __( 'Show return policy', 'publishrank' ), __( 'Return window, who pays and how items come back.', 'publishrank' ) );
		Suite::field( 'number', 'woo[return_days]', $s['return_days'], __( 'Return window (days)', 'publishrank' ), __( '0 means returns are not accepted.', 'publishrank' ), array( 'min' => 0, 'max' => 365 ) );
		Suite::field( 'select', 'woo[return_fees]', $s['return_fees'], __( 'Return cost', 'publishrank' ), '', array(), array( 'FreeReturn' => __( 'Free returns', 'publishrank' ), 'ReturnShippingFees' => __( 'Customer pays return shipping', 'publishrank' ), 'ReturnFeesCustomerResponsibility' => __( 'Customer arranges and pays', 'publishrank' ) ) );
		Suite::field( 'select', 'woo[return_method]', $s['return_method'], __( 'How items come back', 'publishrank' ), '', array(), array( 'ReturnByMail' => __( 'By post', 'publishrank' ), 'ReturnInStore' => __( 'In store', 'publishrank' ), 'ReturnAtKiosk' => __( 'At a drop-off point', 'publishrank' ) ) );
		Suite::field( 'text', 'woo[return_country]', $s['return_country'], __( 'Returns accepted from (country code)', 'publishrank' ), __( 'Empty = your store country.', 'publishrank' ), array( 'maxlength' => 2 ) );
		Suite::field( 'url', 'woo[return_policy_url]', $s['return_policy_url'], __( 'Return policy page', 'publishrank' ), '' );
		echo '</div></div>';
		$this->save_button();
		Suite::card_close();
		Suite::panel_close();
	}

	private function tab_templates( array $s ) {
		Suite::panel_open( 'templates' );
		echo '<div class="prx-grid prx-grid--side"><div class="prx-stack">';
		Suite::card_open( __( 'Title and description templates', 'publishrank' ), 'fa-heading', __( 'Leave a field empty to keep PublishRank’s normal title. A title typed on a product always wins.', 'publishrank' ) );
		$this->form( 'templates' );
		$rows = array(
			'product' => array( __( 'Products', 'publishrank' ), '%%title%% %%sep%% %%brand%% %%sep%% %%sitename%%', 'Buy %%title%% for %%price%%. %%stock%%. %%short_description%%' ),
			'cat'     => array( __( 'Product categories', 'publishrank' ), '%%term_title%% %%sep%% Shop %%sitename%%', 'Browse %%product_count%% %%term_title%% products. %%term_description%%' ),
			'tag'     => array( __( 'Product tags', 'publishrank' ), '%%term_title%% %%sep%% %%sitename%%', '' ),
			'shop'    => array( __( 'Shop page', 'publishrank' ), 'Shop %%sep%% %%sitename%%', '' ),
		);
		foreach ( $rows as $k => $r ) {
			echo '<h3 class="prx-subhead">' . esc_html( $r[0] ) . '</h3><div class="prx-grid prx-grid--2">';
			Suite::field( 'text', 'woo[tpl_' . $k . '_title]', $s[ 'tpl_' . $k . '_title' ], __( 'Title', 'publishrank' ), '', array( 'placeholder' => $r[1], 'data-pr-tpl' => '1' ) );
			Suite::field( 'text', 'woo[tpl_' . $k . '_desc]', $s[ 'tpl_' . $k . '_desc' ], __( 'Description', 'publishrank' ), '', array( 'placeholder' => $r[2], 'data-pr-tpl' => '1' ) );
			echo '</div>';
		}
		$this->save_button();
		Suite::card_close();
		echo '</div><div class="prx-stack">';
		Suite::card_open( __( 'Variables', 'publishrank' ), 'fa-code', __( 'Click to copy.', 'publishrank' ) );
		echo '<ul class="pr-woo-vars">';
		foreach ( Templates::variables() as $v => $label ) {
			echo '<li><button type="button" class="prx-chip" data-prx-copy="' . esc_attr( $v ) . '">' . esc_html( $v ) . '</button><span>' . esc_html( $label ) . '</span></li>';
		}
		echo '</ul>';
		if ( Settings::woo_active() ) {
			$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 1, 'return' => 'ids' ) );
			if ( $ids && '' !== $s['tpl_product_title'] ) {
				echo '<p class="prx-help"><strong>' . esc_html__( 'Example', 'publishrank' ) . ':</strong> ' . esc_html( Templates::render( $s['tpl_product_title'], 'product', 'title', (int) $ids[0] ) ) . '</p>';
			}
		}
		Suite::card_close();
		echo '</div></div>';
		Suite::panel_close();
	}

	private function tab_rules( array $s ) {
		Suite::panel_open( 'rules' );
		Suite::card_open( __( 'Store rules and indexing', 'publishrank' ), 'fa-scale-balanced' );
		$this->form( 'rules' );
		Suite::switch_field( 'woo[noindex_utility]', $s['noindex_utility'], __( 'Keep cart, checkout and account pages out of Google', 'publishrank' ) );
		Suite::switch_field( 'woo[noindex_filters]', $s['noindex_filters'], __( 'Keep sorted and filtered shop pages out of Google', 'publishrank' ), __( 'They still pass links to your products.', 'publishrank' ) );
		Suite::switch_field( 'woo[noindex_hidden]', $s['noindex_hidden'], __( 'Keep products hidden from the catalogue out of Google', 'publishrank' ) );
		Suite::switch_field( 'woo[sitemap]', $s['sitemap'], __( 'Products sitemap', 'publishrank' ), __( 'Products get their own sitemap; hidden products are left out.', 'publishrank' ) );
		Suite::switch_field( 'woo[hide_out_of_stock]', $s['hide_out_of_stock'], __( 'Hide sold-out products from Google and the sitemap', 'publishrank' ), __( 'Best for one-off items that will not come back. Leave off for products you restock.', 'publishrank' ) );
		Suite::switch_field( 'woo[trash_redirect]', $s['trash_redirect'], __( 'Redirect deleted products to their category', 'publishrank' ), __( 'A product moved to the bin sends visitors to its main category (or the shop). Restoring the product removes the redirect.', 'publishrank' ) );
		$this->save_button();
		Suite::card_close();
		Suite::panel_close();
	}

	private function tab_audit( $woo ) {
		Suite::panel_open( 'audit' );
		Suite::card_open( __( 'Product audit', 'publishrank' ), 'fa-clipboard-check', __( 'Every product scored out of 100, worst first. Fix what is listed to raise the score.', 'publishrank' ) );
		if ( ! $woo ) {
			echo '<div class="prx-empty"><span class="fa-solid fa-plug" aria-hidden="true"></span><strong>' . esc_html__( 'Activate WooCommerce to audit your products', 'publishrank' ) . '</strong></div>';
			Suite::card_close();
			Suite::panel_close();
			return;
		}
		$f   = self::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res = Audit::run( array_merge( $f, array( 'per_page' => 25 ) ) );
		echo '<form method="get" class="prx-toolbar pr-woo-filter" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input class="prx-input" type="search" name="s" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'Search name or SKU', 'publishrank' ) . '" />';
		echo '<select name="band"><option value="">' . esc_html__( 'All scores', 'publishrank' ) . '</option>';
		foreach ( array( 'poor' => __( 'Poor (under 50)', 'publishrank' ), 'ok' => __( 'Fair (50–79)', 'publishrank' ), 'good' => __( 'Good (80+)', 'publishrank' ) ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['band'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><select name="missing"><option value="">' . esc_html__( 'Any gap', 'publishrank' ) . '</option>';
		foreach ( Audit::checks() as $k => $c ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['missing'], $k, false ) . '>' . esc_html( sprintf( /* translators: %s: check */ __( 'Missing: %s', 'publishrank' ), $c[1] ) ) . '</option>';
		}
		echo '</select><button class="prx-btn" type="submit"><span class="fa-solid fa-filter" aria-hidden="true"></span>' . esc_html__( 'Filter', 'publishrank' ) . '</button></form>';
		Suite::form_open( 'publishrank_woo_export', array( 'class' => 'prx-row pr-woo-export' ) );
		foreach ( array( 's' => $f['search'], 'band' => $f['band'], 'missing' => $f['missing'] ) as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
		}
		echo '<button class="prx-btn prx-btn--sm" type="submit"><span class="fa-solid fa-file-csv" aria-hidden="true"></span>' . esc_html__( 'Export to spreadsheet (CSV)', 'publishrank' ) . '</button><span class="prx-help">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d product matches', '%d products match', $res['total'], 'publishrank' ), $res['total'] ) ) . '</span></form>';
		if ( ! $res['rows'] ) {
			echo '<div class="prx-empty"><span class="fa-solid fa-magnifying-glass" aria-hidden="true"></span><strong>' . esc_html__( 'No products match these filters', 'publishrank' ) . '</strong></div>';
		} else {
			$checks = Audit::checks();
			echo '<div class="prx-table-wrap"><table class="prx-table pr-woo-audit"><thead><tr><th>' . esc_html__( 'Product', 'publishrank' ) . '</th><th>' . esc_html__( 'Score', 'publishrank' ) . '</th><th>' . esc_html__( 'What is missing', 'publishrank' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $res['rows'] as $r ) {
				echo '<tr><td><strong>' . esc_html( $r['name'] ) . '</strong>' . ( $r['sku'] ? '<br /><span class="prx-help prx-mono">' . esc_html( $r['sku'] ) . '</span>' : '' ) . ( 'publish' !== $r['status'] ? ' ' . Suite::badge( $r['status'], 'warn' ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td><span class="pr-woo-score pr-woo-score--' . esc_attr( $r['band'] ) . '">' . (int) $r['score'] . '</span><div class="prx-meter prx-meter--' . esc_attr( 'good' === $r['band'] ? 'ok' : ( 'ok' === $r['band'] ? 'warn' : 'bad' ) ) . '"><span style="width:' . (int) $r['score'] . '%"></span></div></td>';
				echo '<td>' . ( $r['missing'] ? '<ul class="pr-woo-missing">' . implode( '', array_map( static function ( $m ) { return '<li>' . esc_html( $m ) . '</li>'; }, $r['missing'] ) ) . '</ul>' : '<span class="pr-ok">✓ ' . esc_html__( 'Nothing missing', 'publishrank' ) . '</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td class="prx-actions"><a class="prx-btn prx-btn--sm" href="' . esc_url( (string) $r['edit'] ) . '">' . esc_html__( 'Edit', 'publishrank' ) . '</a> <a class="prx-btn prx-btn--sm" href="' . esc_url( add_query_arg( 'product', $r['id'], Suite::url( self::SLUG ) ) . '#preview' ) . '">' . esc_html__( 'Preview', 'publishrank' ) . '</a></td></tr>';
			}
			echo '</tbody></table></div>';
			Suite::pagination( $res['total'], 25, $f['paged'] );
		}
		Suite::card_close();
		Suite::panel_close();
	}

	private function tab_feed( array $s, $woo ) {
		Suite::panel_open( 'feed' );
		echo '<div class="prx-grid prx-grid--side"><div class="prx-stack">';
		Suite::card_open( __( 'Google Merchant Center feed', 'publishrank' ), 'fa-rss', __( 'A private product feed. The same address works for Facebook and Instagram catalogues.', 'publishrank' ) );
		$this->form( 'feed' );
		Suite::switch_field( 'woo[feed_enabled]', $s['feed_enabled'], __( 'Publish the private feed', 'publishrank' ), __( 'Protected by a secret key in the address. Never indexed by search engines.', 'publishrank' ) );
		Suite::switch_field( 'woo[feed_variations]', $s['feed_variations'], __( 'One item per variation', 'publishrank' ), __( 'Recommended: each size and colour gets its own item grouped under the main product.', 'publishrank' ) );
		Suite::switch_field( 'woo[feed_out_of_stock]', $s['feed_out_of_stock'], __( 'Include sold-out products', 'publishrank' ), __( 'Google shows them as out of stock instead of dropping them.', 'publishrank' ) );
		Suite::field( 'text', 'woo[feed_google_category]', $s['feed_google_category'], __( 'Default Google category', 'publishrank' ), __( 'Used for products without their own Google category, e.g. “Apparel & Accessories” or 166.', 'publishrank' ) );
		$this->save_button();
		Suite::card_close();
		echo '</div><div class="prx-stack">';
		$st = Feed::status();
		Suite::card_open( __( 'Feed address', 'publishrank' ), 'fa-link' );
		if ( $st['enabled'] ) {
			echo '<div class="pr-woo-feedurl"><code>' . esc_html( $st['url'] ) . '</code><button type="button" class="prx-btn prx-btn--sm" data-prx-copy="' . esc_attr( $st['url'] ) . '"><span class="fa-solid fa-copy" aria-hidden="true"></span>' . esc_html__( 'Copy', 'publishrank' ) . '</button> <a class="prx-btn prx-btn--sm" target="_blank" rel="noopener" href="' . esc_url( $st['url'] ) . '">' . esc_html__( 'Open', 'publishrank' ) . '</a></div>';
			echo '<p class="prx-help">' . esc_html( $st['last_built'] ? sprintf( /* translators: 1: items 2: time */ __( 'Last fetched %2$s with %1$d items.', 'publishrank' ), $st['last_items'], Suite::ago( $st['last_built'] ) ) : __( 'Not fetched yet.', 'publishrank' ) ) . '</p>';
			Suite::form_open( 'publishrank_woo_rotate', array( 'data-prx-confirm' => __( 'Create a new secret address? The current address stops working straight away.', 'publishrank' ) ) );
			echo '<button class="prx-btn prx-btn--sm" type="submit"><span class="fa-solid fa-rotate" aria-hidden="true"></span>' . esc_html__( 'Create new secret address', 'publishrank' ) . '</button></form>';
		} else {
			echo '<p class="prx-help">' . esc_html__( 'Switch the feed on and save to get your private address.', 'publishrank' ) . '</p>';
		}
		echo '<ol class="prx-help pr-woo-steps"><li>' . esc_html__( 'Google Merchant Center → Products → Add products → From a file → enter the address → daily schedule.', 'publishrank' ) . '</li><li>' . esc_html__( 'Meta Commerce Manager → Catalogue → Data sources → Data feed → Scheduled feed → paste the same address.', 'publishrank' ) . '</li></ol>';
		Suite::card_close();
		echo '</div></div>';
		Suite::panel_close();
	}

	private function tab_preview( $woo ) {
		Suite::panel_open( 'preview' );
		if ( ! $woo ) {
			Suite::card_open( __( 'Preview', 'publishrank' ), 'fa-eye' );
			echo '<div class="prx-empty"><span class="fa-solid fa-plug" aria-hidden="true"></span><strong>' . esc_html__( 'Activate WooCommerce to preview products', 'publishrank' ) . '</strong></div>';
			Suite::card_close();
			Suite::panel_close();
			return;
		}
		$pid = isset( $_GET['product'] ) ? absint( wp_unslash( $_GET['product'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $pid ) {
			$latest = wc_get_products( array( 'status' => 'publish', 'limit' => 1, 'return' => 'ids' ) );
			$pid    = $latest ? (int) $latest[0] : 0;
		}
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product ) {
			Suite::card_open( __( 'Preview', 'publishrank' ), 'fa-eye' );
			echo '<div class="prx-empty"><span class="fa-solid fa-box-open" aria-hidden="true"></span><strong>' . esc_html__( 'Publish a product to see its preview', 'publishrank' ) . '</strong></div>';
			Suite::card_close();
			Suite::panel_close();
			return;
		}
		$url   = get_permalink( $product->get_id() );
		$title = (string) get_post_meta( $product->get_id(), '_publishrank_seo_title', true );
		$tpl   = (string) Settings::get( 'tpl_product_title' );
		$title = '' !== $title ? $title : ( '' !== $tpl ? Templates::render( $tpl, 'product', 'title', $product->get_id() ) : $product->get_name() . ' | ' . \PublishRankSEO\Core\PublicationProfile::publication_name() );
		$desc  = (string) get_post_meta( $product->get_id(), '_publishrank_seo_description', true );
		$dtpl  = (string) Settings::get( 'tpl_product_desc' );
		$desc  = '' !== $desc ? $desc : ( '' !== $dtpl ? Templates::render( $dtpl, 'product', 'description', $product->get_id() ) : wp_trim_words( wp_strip_all_tags( $product->get_short_description() ? $product->get_short_description() : $product->get_description() ), 26 ) );
		$tags  = WooCommerceSEO::og_tags( $product );
		echo '<div class="prx-grid prx-grid--side"><div class="prx-stack">';
		Suite::card_open( __( 'Google result preview', 'publishrank' ), 'fa-magnifying-glass', $product->get_name() );
		echo '<div class="pr-serp"><div class="pr-serp__url">' . esc_html( wp_parse_url( $url, PHP_URL_HOST ) . ' › ' . trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) . '</div><div class="pr-serp__title">' . esc_html( $title ) . '</div><div class="pr-serp__desc">' . esc_html( $desc ) . '</div><div class="pr-serp__rich">';
		if ( isset( $tags['product:price:amount'] ) ) {
			echo '<span>' . wp_kses_post( wc_price( $tags['product:price:amount'] ) ) . '</span>';
		}
		echo '<span>' . esc_html( ucfirst( $tags['product:availability'] ) ) . '</span>';
		if ( $product->get_rating_count() > 0 ) {
			echo '<span>★ ' . esc_html( wc_format_decimal( $product->get_average_rating(), 1 ) ) . ' (' . (int) $product->get_rating_count() . ')</span>';
		}
		echo '</div></div>';
		Suite::card_close();
		Suite::card_open( __( 'Product structured data', 'publishrank' ), 'fa-code', __( 'Exactly what Google receives for this product.', 'publishrank' ), '<a class="prx-btn prx-btn--sm" target="_blank" rel="noopener" href="' . esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( $url ) ) . '">' . esc_html__( 'Test in Google', 'publishrank' ) . '</a>' );
		echo '<pre class="prx-code pr-woo-json">' . esc_html( (string) wp_json_encode( RestApi::schema_for( $product ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		Suite::card_close();
		echo '</div><div class="prx-stack">';
		$a = \PublishRankSEO\WooCommerce\Audit::score( $product );
		Suite::card_open( __( 'Score', 'publishrank' ), 'fa-gauge-high', '', '<a class="prx-btn prx-btn--sm" href="' . esc_url( (string) get_edit_post_link( $product->get_id(), 'raw' ) ) . '">' . esc_html__( 'Edit product', 'publishrank' ) . '</a>' );
		echo Charts::gauge( $a['score'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Charts.
		if ( $a['missing'] ) {
			echo '<ul class="pr-woo-missing">' . implode( '', array_map( static function ( $m ) { return '<li>' . esc_html( $m ) . '</li>'; }, $a['missing'] ) ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		Suite::card_close();
		Suite::card_open( __( 'Sharing tags', 'publishrank' ), 'fa-share-nodes' );
		echo '<pre class="prx-code">';
		foreach ( $tags as $k => $v ) {
			echo esc_html( $k . ' = ' . $v ) . "\n";
		}
		echo '</pre>';
		Suite::card_close();
		echo '</div></div>';
		Suite::panel_close();
	}
}
