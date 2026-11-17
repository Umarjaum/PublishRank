<?php
/**
 * Private Google Merchant Center product feed (RSS 2.0 + g: namespace).
 *
 * The same file is accepted by Facebook and Instagram catalogues (Meta Commerce Manager).
 * Address: https://example.com/?publishrank_feed=google&key=SECRET — the secret key is required,
 * the feed is never indexed and is streamed in batches so large catalogues stay within memory.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Feed {
	const QUERY = 'publishrank_feed';
	const BATCH = 100;

	public function register() {
		add_action( 'init', array( $this, 'maybe_serve' ), 50 );
	}

	public static function url( $format = 'google' ) {
		return add_query_arg( array( self::QUERY => $format, 'key' => Settings::feed_key() ), home_url( '/' ) );
	}

	public function maybe_serve() {
		if ( ! isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorised by the secret key below.
			return;
		}
		$format = sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$given  = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		if ( ! Settings::active() || ! Settings::get( 'feed_enabled' ) ) {
			status_header( 404 );
			exit( 'Feed not enabled.' );
		}
		$key = (string) Settings::get( 'feed_key' );
		if ( '' === $key || '' === $given || ! hash_equals( $key, $given ) ) {
			status_header( 403 );
			exit( 'Invalid feed key.' );
		}
		if ( ! in_array( $format, array( 'google', 'meta', 'facebook' ), true ) ) {
			status_header( 400 );
			exit( 'Unknown feed format.' );
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		self::stream( 'php://output' );
		exit;
	}

	/** Product IDs in the feed (parents; variations are expanded while writing). @return int[] */
	public static function product_ids( $page, $per ) {
		return wc_get_products(
			array(
				'status'     => 'publish',
				'visibility' => 'catalog',
				'limit'      => $per,
				'page'       => $page,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'return'     => 'ids',
			)
		);
	}

	/** Write the feed to a stream. Returns the number of items written. */
	public static function stream( $target ) {
		$out = fopen( $target, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$x   = static function ( $s ) {
			return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		};
		fwrite( $out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $out, '<title>' . $x( get_bloginfo( 'name' ) ) . "</title>\n<link>" . $x( home_url( '/' ) ) . "</link>\n<description>" . $x( get_bloginfo( 'description' ) ) . "</description>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$count = 0;
		$page  = 1;
		do {
			$ids = self::product_ids( $page, self::BATCH );
			foreach ( (array) $ids as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product || ProductData::excluded_from_feed( $product ) ) {
					continue;
				}
				if ( $product->is_type( 'variable' ) && Settings::get( 'feed_variations' ) ) {
					foreach ( (array) $product->get_children() as $child ) {
						$v = wc_get_product( $child );
						if ( $v && 'publish' === get_post_status( $child ) && ! ProductData::excluded_from_feed( $v ) ) {
							$count += self::write_item( $out, $v, $product, $x );
						}
					}
				} elseif ( ! $product->is_type( 'grouped' ) ) {
					$count += self::write_item( $out, $product, null, $x );
				}
			}
			++$page;
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( is_array( $ids ) && count( $ids ) === self::BATCH );
		fwrite( $out, "</channel>\n</rss>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		update_option( 'publishrank_woo_feed_last', array( 'time' => gmdate( 'c' ), 'items' => $count ), false );
		return $count;
	}

	/** One <item>. Returns 1 when written, 0 when skipped. */
	private static function write_item( $out, $product, $parent, $x ) {
		$stock = $product->get_stock_status();
		if ( 'outofstock' === $stock && ! Settings::get( 'feed_out_of_stock' ) ) {
			return 0;
		}
		$regular = $product->get_regular_price();
		$price   = '' !== (string) $regular ? $regular : $product->get_price();
		if ( '' === (string) $price ) {
			return 0;
		}
		$cur  = get_woocommerce_currency();
		$main = $parent ? $parent : $product;
		$desc = $product->get_description();
		if ( '' === trim( wp_strip_all_tags( $desc ) ) ) {
			$desc = $main->get_short_description() ? $main->get_short_description() : $main->get_description();
		}
		$f = array(
			'g:id'           => $product->get_sku() ? $product->get_sku() : 'wc-' . $product->get_id(),
			'title'          => wp_strip_all_tags( $product->get_name() ),
			'description'    => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $desc ) ), 800, '' ),
			'link'           => $product->get_permalink(),
			'g:availability' => 'outofstock' === $stock ? 'out_of_stock' : ( 'onbackorder' === $stock ? 'backorder' : 'in_stock' ),
			'g:price'        => wc_format_decimal( wc_get_price_to_display( $product, array( 'price' => $price ) ), 2 ) . ' ' . $cur,
			'g:condition'    => strtolower( str_replace( 'Condition', '', ProductData::condition( $product ) ) ),
		);
		if ( $parent ) {
			$f['g:item_group_id'] = $parent->get_sku() ? $parent->get_sku() : 'wc-' . $parent->get_id();
		}
		$img = wp_get_attachment_image_url( (int) ( $product->get_image_id() ? $product->get_image_id() : $main->get_image_id() ), 'full' );
		if ( $img ) {
			$f['g:image_link'] = $img;
		}
		if ( $product->is_on_sale() && '' !== (string) $product->get_sale_price() ) {
			$f['g:sale_price'] = wc_format_decimal( wc_get_price_to_display( $product, array( 'price' => $product->get_sale_price() ) ), 2 ) . ' ' . $cur;
			if ( $product->get_date_on_sale_from() && $product->get_date_on_sale_to() ) {
				$f['g:sale_price_effective_date'] = $product->get_date_on_sale_from()->date( 'c' ) . '/' . $product->get_date_on_sale_to()->date( 'c' );
			}
		}
		$brand = ProductData::brand( $product );
		$gtin  = ProductData::gtin( $product );
		$mpn   = ProductData::mpn( $product );
		if ( '' !== $brand ) {
			$f['g:brand'] = $brand;
		}
		if ( '' !== $gtin ) {
			$f['g:gtin'] = $gtin;
		}
		if ( '' !== $mpn ) {
			$f['g:mpn'] = $mpn;
		}
		if ( '' === $gtin && ( '' === $mpn || '' === $brand ) ) {
			$f['g:identifier_exists'] = 'no';
		}
		$gc = ProductData::google_category( $product );
		if ( '' !== $gc ) {
			$f['g:google_product_category'] = $gc;
		}
		$cats = get_the_terms( $main->get_id(), 'product_cat' );
		if ( $cats && ! is_wp_error( $cats ) ) {
			$path = array();
			$t    = $cats[0];
			while ( $t && ! is_wp_error( $t ) ) {
				array_unshift( $path, $t->name );
				$t = $t->parent ? get_term( $t->parent, 'product_cat' ) : null;
			}
			$f['g:product_type'] = implode( ' > ', $path );
		}
		foreach ( array( 'attr_color' => 'g:color', 'attr_size' => 'g:size', 'attr_material' => 'g:material', 'attr_pattern' => 'g:pattern', 'attr_gender' => 'g:gender', 'attr_age_group' => 'g:age_group' ) as $setting => $tag ) {
			$v = ProductData::attribute( $product, $setting );
			if ( '' !== $v ) {
				$f[ $tag ] = $v;
			}
		}
		if ( '' !== (string) $product->get_weight() ) {
			$f['g:shipping_weight'] = $product->get_weight() . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
		}
		foreach ( ProductData::labels( $product ) as $i => $label ) {
			$f[ 'g:custom_label_' . $i ] = $label;
		}
		$xml = "<item>\n";
		foreach ( $f as $tag => $value ) {
			$xml .= '<' . $tag . '>' . $x( $value ) . '</' . $tag . ">\n";
		}
		$gallery = array_slice( (array) ( $parent ? $parent->get_gallery_image_ids() : $product->get_gallery_image_ids() ), 0, 10 );
		foreach ( $gallery as $gid ) {
			$src = wp_get_attachment_image_url( (int) $gid, 'full' );
			if ( $src ) {
				$xml .= '<g:additional_image_link>' . $x( $src ) . "</g:additional_image_link>\n";
			}
		}
		$ship = Settings::get( 'shipping_schema' ) ? ProductSchema::shipping_details( $product ) : null;
		if ( $ship ) {
			$xml .= '<g:shipping><g:country>' . $x( $ship['shippingDestination']['addressCountry'] ) . '</g:country><g:price>' . $x( wc_format_decimal( $ship['shippingRate']['value'], 2 ) . ' ' . $cur ) . "</g:price></g:shipping>\n";
		}
		$xml .= "</item>\n";
		fwrite( $out, $xml ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		return 1;
	}

	/** Feed status for the admin screen, REST and CLI. @return array<string,mixed> */
	public static function status() {
		$last = get_option( 'publishrank_woo_feed_last', array() );
		return array(
			'enabled'    => (bool) Settings::get( 'feed_enabled' ),
			'url'        => Settings::get( 'feed_enabled' ) ? self::url() : '',
			'last_built' => is_array( $last ) && ! empty( $last['time'] ) ? (string) $last['time'] : '',
			'last_items' => is_array( $last ) && isset( $last['items'] ) ? (int) $last['items'] : 0,
		);
	}
}
