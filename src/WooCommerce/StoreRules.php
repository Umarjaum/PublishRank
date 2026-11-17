<?php
/**
 * Store rules: sold-out products and products moved to the bin.
 *
 * - Optionally keep sold-out products out of Google (noindex,follow) and out of the sitemap.
 * - A published product moved to the bin gets a 301 redirect to its main category (or the shop page),
 *   so shoppers and Google never hit a dead link. Restoring the product removes that redirect again.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\WooCommerce;

use PublishRankSEO\Redirect\RedirectStore;
use PublishRankSEO\Redirect\RedirectRepository;

defined( 'ABSPATH' ) || exit;

final class StoreRules {
	public function register() {
		add_filter( 'publishrank_seo_robots', array( $this, 'robots' ), 25 );
		add_action( 'wp_trash_post', array( $this, 'on_trash' ), 5 );
		add_action( 'untrashed_post', array( $this, 'on_untrash' ), 20 );
	}

	public static function sold_out( $product ) {
		return $product && 'outofstock' === $product->get_stock_status();
	}

	/** Whether a product should be hidden from Google and the sitemap because it is sold out. */
	public static function hide( $product ) {
		return Settings::active() && Settings::get( 'hide_out_of_stock' ) && self::sold_out( $product );
	}

	public function robots( $robots ) {
		if ( ! Settings::active() || ! Settings::get( 'hide_out_of_stock' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $robots;
		}
		$product = wc_get_product( get_queried_object_id() );
		return self::hide( $product ) ? 'noindex,follow' : $robots;
	}

	/** Where a binned product should send visitors. */
	public static function fallback_url( $post_id ) {
		$terms = get_the_terms( $post_id, 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$primary = (int) get_post_meta( $post_id, '_publishrank_seo_primary_category', true );
			foreach ( $terms as $t ) {
				if ( $primary && (int) $t->term_id === $primary ) {
					$link = get_term_link( $t );
					if ( ! is_wp_error( $link ) ) {
						return $link;
					}
				}
			}
			usort(
				$terms,
				static function ( $a, $b ) {
					return (int) $b->count - (int) $a->count;
				}
			);
			$link = get_term_link( $terms[0] );
			if ( ! is_wp_error( $link ) && 'uncategorized' !== $terms[0]->slug ) {
				return $link;
			}
		}
		$shop = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		return $shop > 0 ? get_permalink( $shop ) : home_url( '/' );
	}

	public function on_trash( $post_id ) {
		if ( ! Settings::active() || ! Settings::get( 'trash_redirect' ) || 'product' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		$source = (string) wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );
		if ( '' === $source || '/' === $source ) {
			return;
		}
		$target = self::fallback_url( $post_id );
		$ok     = RedirectStore::add( $source, $target, 301, false, 0, 0, array( 'note' => 'WooCommerce: product moved to the bin (#' . (int) $post_id . ')', 'allow_live' => 1 ) );
		if ( true === $ok ) {
			update_post_meta( $post_id, ProductData::REDIRECT, $source );
		}
	}

	public function on_untrash( $post_id ) {
		$source = (string) get_post_meta( $post_id, ProductData::REDIRECT, true );
		if ( '' === $source ) {
			return;
		}
		self::remove_redirect( $source );
		delete_post_meta( $post_id, ProductData::REDIRECT );
	}

	public static function remove_redirect( $source ) {
		if ( RedirectStore::v2() && method_exists( RedirectRepository::class, 'find_exact_by_source' ) ) {
			$row = RedirectRepository::find_exact_by_source( $source );
			if ( $row && ! empty( $row['id'] ) ) {
				return RedirectStore::delete( (int) $row['id'] );
			}
		}
		foreach ( RedirectStore::all() as $rule ) {
			if ( isset( $rule['source'] ) && untrailingslashit( (string) $rule['source'] ) === untrailingslashit( $source ) ) {
				return RedirectStore::delete( isset( $rule['id'] ) && is_numeric( $rule['id'] ) ? (int) $rule['id'] : RedirectStore::id( $rule ) );
			}
		}
		return false;
	}
}
