<?php
/**
 * wp publishrank woo …  — WooCommerce SEO from the command line.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\CLI;

use PublishRankSEO\WooCommerce\Audit;
use PublishRankSEO\WooCommerce\Feed;
use PublishRankSEO\WooCommerce\ProductData;
use PublishRankSEO\WooCommerce\RestApi;
use PublishRankSEO\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Manage PublishRank WooCommerce SEO.
	 */
	class WooCommerceCommand {
		/**
		 * Show WooCommerce SEO status.
		 *
		 * @subcommand status
		 */
		public function status( $args, $assoc ) {
			$feed = Feed::status();
			$rows = array(
				array( 'key' => 'woocommerce_active', 'value' => Settings::woo_active() ? 'yes' : 'no' ),
				array( 'key' => 'switched_on', 'value' => Settings::switched_on() ? 'yes' : 'no' ),
				array( 'key' => 'feed_enabled', 'value' => $feed['enabled'] ? 'yes' : 'no' ),
				array( 'key' => 'feed_last_built', 'value' => $feed['last_built'] ? $feed['last_built'] : '—' ),
				array( 'key' => 'feed_last_items', 'value' => (string) $feed['last_items'] ),
			);
			foreach ( Settings::all() as $k => $v ) {
				if ( 'feed_key' !== $k ) {
					$rows[] = array( 'key' => $k, 'value' => (string) $v );
				}
			}
			\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' ), $rows, array( 'key', 'value' ) );
		}

		/**
		 * Score products out of 100.
		 *
		 * [--band=<band>]
		 * : good, ok or poor.
		 *
		 * [--missing=<check>]
		 * : Only products missing this check (image, gtin, brand, …).
		 *
		 * [--search=<text>]
		 * : Name or SKU contains.
		 *
		 * [--format=<format>]
		 * : table, csv or json. Default table.
		 *
		 * @subcommand audit
		 */
		public function audit( $args, $assoc ) {
			if ( ! Settings::woo_active() ) {
				\WP_CLI::error( 'WooCommerce is not active.' );
			}
			$res  = Audit::run( array( 'band' => (string) \WP_CLI\Utils\get_flag_value( $assoc, 'band', '' ), 'missing' => (string) \WP_CLI\Utils\get_flag_value( $assoc, 'missing', '' ), 'search' => (string) \WP_CLI\Utils\get_flag_value( $assoc, 'search', '' ), 'per_page' => 2000 ) );
			$rows = array();
			foreach ( $res['rows'] as $r ) {
				$rows[] = array( 'id' => $r['id'], 'name' => $r['name'], 'sku' => $r['sku'], 'score' => $r['score'], 'missing' => implode( '; ', $r['missing'] ) );
			}
			\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' ), $rows, array( 'id', 'name', 'sku', 'score', 'missing' ) );
			\WP_CLI::log( sprintf( 'Products: %d · average score: %d · good %d · ok %d · poor %d', $res['summary']['count'], $res['summary']['average'], $res['summary']['good'], $res['summary']['ok'], $res['summary']['poor'] ) );
		}

		/**
		 * Check a barcode (GTIN / EAN / UPC / ISBN).
		 *
		 * <gtin>
		 * : The code.
		 *
		 * @subcommand validate-gtin
		 */
		public function validate_gtin( $args ) {
			$res = ProductData::check_gtin( (string) $args[0] );
			true === $res ? \WP_CLI::success( 'Valid barcode.' ) : \WP_CLI::error( 'Invalid barcode: ' . $res );
		}

		/**
		 * Set PublishRank product fields.
		 *
		 * <product-id>
		 * : Product ID.
		 *
		 * [--gtin=<gtin>]
		 * : Barcode (GTIN-8/12/13/14). Checked before saving.
		 *
		 * [--mpn=<mpn>]
		 * : Manufacturer part number.
		 *
		 * [--brand=<brand>]
		 * : Brand name.
		 *
		 * [--condition=<condition>]
		 * : NewCondition, UsedCondition, RefurbishedCondition or DamagedCondition.
		 *
		 * [--google_category=<category>]
		 * : Google product category.
		 *
		 * [--exclude_feed=<0|1>]
		 * : 1 to leave the product out of the Merchant Center feed.
		 *
		 * @subcommand set
		 */
		public function set( $args, $assoc ) {
			$p = wc_get_product( (int) $args[0] );
			if ( ! $p ) {
				\WP_CLI::error( 'Product not found.' );
			}
			RestApi::apply_fields( $p, $assoc );
			\WP_CLI::success( 'Saved. Score now ' . Audit::score( wc_get_product( $p->get_id() ) )['score'] . '/100.' );
		}

		/**
		 * Print the private feed address.
		 *
		 * @subcommand feed-url
		 */
		public function feed_url() {
			if ( ! Settings::get( 'feed_enabled' ) ) {
				\WP_CLI::warning( 'The feed is switched off. Turn it on with: wp publishrank woo enable-feed' );
			}
			\WP_CLI::line( Feed::url() );
		}

		/**
		 * Switch the Merchant Center feed on.
		 *
		 * @subcommand enable-feed
		 */
		public function enable_feed() {
			Settings::update( array( 'feed_enabled' => 1 ) );
			Settings::feed_key();
			\WP_CLI::success( 'Feed on: ' . Feed::url() );
		}

		/**
		 * Create a new secret feed key (the old address stops working).
		 *
		 * @subcommand rotate-key
		 */
		public function rotate_key() {
			Settings::rotate_feed_key();
			\WP_CLI::success( 'New feed address: ' . Feed::url() );
		}

		/**
		 * Write the feed to a file.
		 *
		 * [--file=<path>]
		 * : Default: publishrank-feed.xml in the current folder.
		 *
		 * @subcommand export-feed
		 */
		public function export_feed( $args, $assoc ) {
			if ( ! Settings::woo_active() ) {
				\WP_CLI::error( 'WooCommerce is not active.' );
			}
			$file = (string) \WP_CLI\Utils\get_flag_value( $assoc, 'file', getcwd() . '/publishrank-feed.xml' );
			$n    = Feed::stream( $file );
			\WP_CLI::success( sprintf( '%d items written to %s', $n, $file ) );
		}
	}
	\WP_CLI::add_command( 'publishrank woo', __NAMESPACE__ . '\\WooCommerceCommand' );
}
