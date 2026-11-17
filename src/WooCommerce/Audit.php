<?php
/**
 * Product audit: scores every product out of 100 and lists what is missing.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\WooCommerce;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class Audit {
	/** Check key => [points, label]. @return array<string,array{0:int,1:string}> */
	public static function checks() {
		return array(
			'title'       => array( 10, __( 'Product name 10–70 characters', 'publishrank' ) ),
			'image'       => array( 15, __( 'Main image', 'publishrank' ) ),
			'gallery'     => array( 5, __( 'Extra gallery images', 'publishrank' ) ),
			'description' => array( 10, __( 'Search description or short description (50+ characters)', 'publishrank' ) ),
			'content'     => array( 5, __( 'Full description (300+ characters)', 'publishrank' ) ),
			'price'       => array( 10, __( 'Price', 'publishrank' ) ),
			'sku'         => array( 5, __( 'SKU', 'publishrank' ) ),
			'gtin'        => array( 10, __( 'Valid barcode (GTIN) or part number (MPN)', 'publishrank' ) ),
			'brand'       => array( 10, __( 'Brand', 'publishrank' ) ),
			'category'    => array( 5, __( 'A real category (not Uncategorized)', 'publishrank' ) ),
			'reviews'     => array( 5, __( 'At least one review', 'publishrank' ) ),
			'indexable'   => array( 10, __( 'Can appear in Google (not hidden or noindex)', 'publishrank' ) ),
		);
	}

	/** @return array<string,bool> */
	public static function results( $product ) {
		$pid   = $product->get_id();
		$name  = (string) $product->get_name();
		$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		$desc  = (string) get_post_meta( $pid, PostMeta::DESCRIPTION, true );
		$short = wp_strip_all_tags( (string) $product->get_short_description() );
		$long  = wp_strip_all_tags( (string) $product->get_description() );
		$cats  = get_the_terms( $pid, 'product_cat' );
		$real  = false;
		if ( $cats && ! is_wp_error( $cats ) ) {
			foreach ( $cats as $c ) {
				if ( 'uncategorized' !== $c->slug && (int) get_option( 'default_product_cat' ) !== (int) $c->term_id ) {
					$real = true;
				}
			}
		}
		$robots = strtolower( (string) get_post_meta( $pid, PostMeta::ROBOTS, true ) );
		return array(
			'title'       => $len >= 10 && $len <= 70,
			'image'       => (bool) $product->get_image_id(),
			'gallery'     => count( (array) $product->get_gallery_image_ids() ) > 0,
			'description' => strlen( trim( '' !== trim( $desc ) ? $desc : $short ) ) >= 50,
			'content'     => strlen( trim( $long ) ) >= 300,
			'price'       => '' !== (string) $product->get_price() || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ),
			'sku'         => '' !== (string) $product->get_sku(),
			'gtin'        => '' !== ProductData::gtin( $product ) || '' !== ProductData::mpn( $product ),
			'brand'       => '' !== ProductData::brand( $product ),
			'category'    => $real,
			'reviews'     => $product->get_review_count() > 0,
			'indexable'   => false === strpos( $robots, 'noindex' ) && 'hidden' !== $product->get_catalog_visibility() && 'publish' === $product->get_status() && ! StoreRules::hide( $product ),
		);
	}

	/** @return array{score:int,results:array<string,bool>,missing:string[]} */
	public static function score( $product ) {
		$res     = self::results( $product );
		$score   = 0;
		$missing = array();
		foreach ( self::checks() as $k => $c ) {
			if ( ! empty( $res[ $k ] ) ) {
				$score += $c[0];
			} else {
				$missing[] = $c[1];
			}
		}
		return array( 'score' => $score, 'results' => $res, 'missing' => $missing );
	}

	public static function band( $score ) {
		return $score >= 80 ? 'good' : ( $score >= 50 ? 'ok' : 'poor' );
	}

	/**
	 * Audit rows with filters.
	 *
	 * @param array<string,mixed> $f search, band (good|ok|poor), missing (check key), paged, per_page.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,summary:array<string,mixed>}
	 */
	public static function run( array $f = array() ) {
		$out = array( 'rows' => array(), 'total' => 0, 'summary' => array( 'count' => 0, 'average' => 0, 'good' => 0, 'ok' => 0, 'poor' => 0, 'missing' => array() ) );
		if ( ! Settings::woo_active() ) {
			return $out;
		}
		$args = array( 'status' => array( 'publish', 'private', 'draft' ), 'limit' => 2000, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' );
		$search = isset( $f['search'] ) ? trim( (string) $f['search'] ) : '';
		$ids    = wc_get_products( $args );
		$all    = array();
		$sum    = 0;
		foreach ( (array) $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$a = self::score( $p );
			$b = self::band( $a['score'] );
			$out['summary'][ $b ]++;
			$sum += $a['score'];
			foreach ( $a['results'] as $k => $ok ) {
				if ( ! $ok ) {
					$out['summary']['missing'][ $k ] = isset( $out['summary']['missing'][ $k ] ) ? $out['summary']['missing'][ $k ] + 1 : 1;
				}
			}
			$row = array(
				'id'      => (int) $id,
				'name'    => (string) $p->get_name(),
				'sku'     => (string) $p->get_sku(),
				'type'    => (string) $p->get_type(),
				'status'  => (string) $p->get_status(),
				'score'   => (int) $a['score'],
				'band'    => $b,
				'results' => $a['results'],
				'missing' => $a['missing'],
				'edit'    => get_edit_post_link( $id, 'raw' ),
				'view'    => get_permalink( $id ),
			);
			if ( '' !== $search && false === stripos( $row['name'] . ' ' . $row['sku'], $search ) ) {
				continue;
			}
			if ( ! empty( $f['band'] ) && $f['band'] !== $b ) {
				continue;
			}
			if ( ! empty( $f['missing'] ) && ! empty( $a['results'][ (string) $f['missing'] ] ) ) {
				continue;
			}
			$all[] = $row;
		}
		$count = count( (array) $ids );
		$out['summary']['count']   = $count;
		$out['summary']['average'] = $count ? (int) round( $sum / $count ) : 0;
		arsort( $out['summary']['missing'] );
		usort(
			$all,
			static function ( $a, $b ) {
				return $a['score'] === $b['score'] ? strcmp( $a['name'], $b['name'] ) : $a['score'] - $b['score'];
			}
		);
		$out['total'] = count( $all );
		$per          = isset( $f['per_page'] ) ? max( 1, min( 2000, (int) $f['per_page'] ) ) : 25;
		$paged        = isset( $f['paged'] ) ? max( 1, (int) $f['paged'] ) : 1;
		$out['rows']  = array_slice( $all, ( $paged - 1 ) * $per, $per );
		return $out;
	}

	/** CSV rows for the spreadsheet export. */
	public static function csv_rows( array $f = array() ) {
		$f['per_page'] = 2000;
		$f['paged']    = 1;
		$rows          = array();
		foreach ( self::run( $f )['rows'] as $r ) {
			$line = array( $r['id'], $r['name'], $r['sku'], $r['type'], $r['status'], $r['score'] );
			foreach ( array_keys( self::checks() ) as $k ) {
				$line[] = ! empty( $r['results'][ $k ] ) ? 'yes' : 'no';
			}
			$line[] = implode( '; ', $r['missing'] );
			$line[] = $r['view'];
			$rows[] = $line;
		}
		return $rows;
	}

	public static function csv_header() {
		return array_merge( array( 'ID', 'Product', 'SKU', 'Type', 'Status', 'Score' ), array_keys( self::checks() ), array( 'Missing', 'URL' ) );
	}
}
