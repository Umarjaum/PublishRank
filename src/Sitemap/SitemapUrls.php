<?php
namespace PublishRankSEO\Sitemap;

defined( 'ABSPATH' ) || exit;

final class SitemapUrls {
	/** Fixed on purpose: a publisher-editable endpoint could collide with WordPress' built-in wp-sitemap.xml or another plugin's sitemap. */
	const ENDPOINT = 'publishrank-sitemap';
	public static function base() {
		return self::ENDPOINT; }
	/** Aliases are retired together with the editable endpoint; the canonical URL is the only sitemap index URL. */
	public static function aliases() {
		return array(); }
	public static function index_url() {
		return home_url( '/' . self::base() . '.xml' ); }
	public static function shard_prefix() {
		$base = self::base();
		return preg_match( '/-sitemap$/', $base ) ? substr( $base, 0, -8 ) : $base;
	}
	public static function shard_url( $slug, $shard = 1 ) {
		return home_url( '/' . self::shard_prefix() . '-' . sanitize_key( $slug ) . '-sitemap-' . max( 1, absint( $shard ) ) . '.xml' );
	}
}
