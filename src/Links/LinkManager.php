<?php
/** External link policy enforcement and per-post link accounting. */
namespace PublishRankSEO\Links;

defined( 'ABSPATH' ) || exit;

final class LinkManager {
	const COUNTS_META = '_publishrank_link_counts';

	public function register() {
		add_filter( 'the_content', array( $this, 'apply_policy' ), 20 );
		add_action( 'save_post', array( $this, 'count_links' ), 30, 1 );
	}

	public static function enabled() {
		return (bool) get_option( 'publishrank_links_enabled', 1 );
	}

	/** Hosts (apex and www variants) that count as this site. @return array<string,bool> */
	private static function site_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host ) { continue; }
			$hosts[ $host ] = true;
			$hosts[ preg_replace( '/^www\./', '', $host ) ]  = true;
			$hosts[ 'www.' . preg_replace( '/^www\./', '', $host ) ] = true;
		}
		return $hosts;
	}

	/** @return array<string,bool> Domains configured for nofollow, normalized. */
	private static function nofollow_domains() {
		$domains = array();
		$raw = preg_split( '/[\s,]+/', (string) get_option( 'publishrank_links_nofollow_domains', '' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $raw as $domain ) {
			$domain = strtolower( trim( $domain ) );
			$domain = preg_replace( '#^https?://#', '', $domain );
			$domain = preg_replace( '#/.*$#', '', (string) $domain );
			if ( '' !== $domain ) { $domains[ $domain ] = true; }
		}
		return $domains;
	}

	/** Rewrite anchors in singular content per policy; feeds and non-singular views untouched. */
	public function apply_policy( $content ) {
		if ( ! self::enabled() || is_admin() || is_feed() || ! is_singular() || false === strpos( (string) $content, '<a ' ) ) {
			return $content;
		}
		if ( apply_filters( 'publishrank_skip_link_policy', false ) ) { return $content; }
		return (string) preg_replace_callback( '/<a\b[^>]*>/i', array( $this, 'process_anchor' ), (string) $content );
	}

	/** @param array<int,string> $matches */
	private function process_anchor( $matches ) {
		$tag = $matches[0];
		if ( false !== stripos( $tag, 'data-publishrank-skip' ) ) { return $tag; }
		if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $href ) && ! preg_match( '/\bhref\s*=\s*([^\s>]+)/i', $tag, $href ) ) {
			return $tag;
		}
		$url = html_entity_decode( trim( $href[2] ?? $href[1] ?? '' ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $url ) ) { return $tag; }
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || isset( self::site_hosts()[ $host ] ) ) { return $tag; } // Internal.

		$rel_add   = array();
		$nofollows = self::nofollow_domains();
		if ( get_option( 'publishrank_links_sponsored', 0 ) ) { $rel_add[] = 'sponsored'; $rel_add[] = 'nofollow'; }
		elseif ( isset( $nofollows[ $host ] ) || isset( $nofollows[ preg_replace( '/^www\./', '', $host ) ] ) ) { $rel_add[] = 'nofollow'; }
		if ( preg_match( '/\btarget\s*=\s*(["\']?)_blank\1/i', $tag ) ) { $rel_add[] = 'noopener'; $rel_add[] = 'noreferrer'; }
		if ( get_option( 'publishrank_links_new_tab', 0 ) && ! preg_match( '/\btarget\s*=/i', $tag ) ) {
			$rel_add[] = 'noopener'; $rel_add[] = 'noreferrer';
			$tag = rtrim( $tag, '>' ) . ' target="_blank" rel="">';
		}
		if ( ! $rel_add ) { return $tag; }
		if ( preg_match( '/\brel\s*=\s*(["\'])(.*?)\1/i', $tag, $rel ) ) {
			$existing = preg_split( '/\s+/', strtolower( trim( $rel[2] ) ), -1, PREG_SPLIT_NO_EMPTY );
			$merged   = array_unique( array_merge( $existing, $rel_add ) );
			return str_replace( $rel[0], 'rel="' . esc_attr( implode( ' ', $merged ) ) . '"', $tag );
		}
		return rtrim( $tag, '>' ) . ' rel="' . esc_attr( implode( ' ', array_unique( $rel_add ) ) ) . '">';
	}

	/** Store internal/external link counts as post meta for Insights and audits. */
	public function count_links( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( false === strpos( $content, 'href=' ) ) {
			update_post_meta( $post_id, self::COUNTS_META, array( 'internal' => 0, 'external' => 0 ) );
			return;
		}
		$hosts   = self::site_hosts();
		$internal = 0; $external = 0;
		if ( preg_match_all( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $content, $m ) ) {
			foreach ( $m[2] as $url ) {
				$url = html_entity_decode( trim( $url ), ENT_QUOTES, 'UTF-8' );
				if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $url ) ) { continue; }
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( '' === $host || isset( $hosts[ $host ] ) || 0 !== strpos( $url, 'http' ) ) { $internal++; } else { $external++; }
			}
		}
		update_post_meta( $post_id, self::COUNTS_META, array( 'internal' => $internal, 'external' => $external ) );
	}

	/** @return array{internal:int,external:int} */
	public static function counts( $post_id ) {
		$counts = get_post_meta( $post_id, self::COUNTS_META, true );
		return is_array( $counts ) ? array( 'internal' => (int) ( $counts['internal'] ?? 0 ), 'external' => (int) ( $counts['external'] ?? 0 ) ) : array( 'internal' => 0, 'external' => 0 );
	}
}