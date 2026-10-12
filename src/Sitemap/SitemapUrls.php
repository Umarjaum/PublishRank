<?php
namespace PublishRankSEO\Sitemap;

defined( 'ABSPATH' ) || exit;

final class SitemapUrls {
	public static function base() { $value = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) get_option( 'publishrank_sitemap_endpoint', 'publishrank-sitemap' ) ) ); return $value ?: 'publishrank-sitemap'; }
	public static function aliases() { $raw = (string) get_option( 'publishrank_sitemap_aliases', '' ); $out = array(); foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $alias ) { $alias = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $alias ) ); if ( $alias && $alias !== self::base() ) { $out[] = $alias; } } return array_values( array_unique( $out ) ); }
	public static function index_url() { return home_url( '/' . self::base() . '.xml' ); }
	public static function shard_prefix() {
		$base = self::base();
		return preg_match( '/-sitemap$/', $base ) ? substr( $base, 0, -8 ) : $base;
	}
	public static function shard_url( $slug, $shard = 1 ) {
		return home_url( '/' . self::shard_prefix() . '-' . sanitize_key( $slug ) . '-sitemap-' . max( 1, absint( $shard ) ) . '.xml' );
	}
}
