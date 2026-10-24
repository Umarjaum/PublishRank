<?php
/** Keyset-batched construction of the internal-link content graph. */
namespace PublishRankSEO\InternalLinks;

defined( 'ABSPATH' ) || exit;

final class Scanner {
	const STATE_KEY = 'publishrank_internal_links_scan_state';

	public static function start_rebuild() {
		Store::delete_unlocked_recommendations();
		$scan_id = Store::new_scan_id();
		$state   = array(
			'phase'                    => 'nodes',
			'scan_id'                  => $scan_id,
			'last_id'                  => 0,
			'processed'                => 0,
			'total'                    => Store::total_posts(),
			'last_recommendation_node' => 0,
			'recommendations'          => 0,
		);
		set_transient( self::STATE_KEY, $state, HOUR_IN_SECONDS );
		return $state;
	}

	public static function state() {
		$state = get_transient( self::STATE_KEY );
		return is_array( $state ) ? $state : null;
	}

	public static function run_batch() {
		$state = self::state();
		if ( ! $state ) {
			return null;
		}

		if ( 'nodes' === $state['phase'] ) {
			$ids = Store::post_ids_after( $state['last_id'], 25 );
			foreach ( $ids as $post_id ) {
				self::scan_post( $post_id, (int) $state['scan_id'] );
				$state['last_id']   = max( (int) $state['last_id'], $post_id );
				$state['processed'] = (int) $state['processed'] + 1;
			}
			if ( empty( $ids ) ) {
				Store::cleanup_scan( (int) $state['scan_id'] );
				$state['phase'] = 'recommendations';
			}
		} else {
			$settings = Store::settings();
			$targets  = Store::orphan_nodes_after( $state['last_recommendation_node'], 10, $settings['threshold'] );
			foreach ( $targets as $target ) {
				$state['recommendations']          = (int) $state['recommendations'] + Recommender::for_target( $target, $settings['max'] );
				$state['last_recommendation_node'] = max( (int) $state['last_recommendation_node'], (int) $target->node_id );
			}
			if ( empty( $targets ) ) {
				$state['done'] = true;
				delete_transient( self::STATE_KEY );
				Store::invalidate_cache();
				return $state;
			}
		}

		set_transient( self::STATE_KEY, $state, HOUR_IN_SECONDS );
		return $state;
	}

	public static function scan_post( $post_id, $scan_id = 0 ) {
		$post = get_post( $post_id );
		if ( ! Store::supports_post( $post ) ) {
			return false;
		}
		$scan_id  = $scan_id ? absint( $scan_id ) : Store::current_scan_id();
		$features = FeatureExtractor::from_post( $post );
		$node_id  = Store::upsert_node( $post, $features, $scan_id );
		Store::replace_terms( $node_id, $features['terms'], $scan_id );
		self::replace_edges( $post, $node_id, $scan_id );
		Store::refresh_counts( $node_id );
		Store::invalidate_cache();
		return true;
	}

	private static function replace_edges( $post, $source_node_id, $scan_id ) {
		global $wpdb;
		Store::delete_edges_for_source( $source_node_id );
		$targets    = self::content_targets( $post );
		$source_url = (string) get_permalink( $post );

		foreach ( $targets as $target_id => $anchor ) {
			$target_post = get_post( $target_id );
			if ( ! Store::supports_post( $target_post ) || (int) $target_post->ID === (int) $post->ID ) {
				continue;
			}
			$target_node = Store::node_for_object( $target_post->ID );
			if ( ! $target_node ) {
				$target_features = FeatureExtractor::from_post( $target_post );
				$target_node_id  = Store::upsert_node( $target_post, $target_features, $scan_id );
				Store::replace_terms( $target_node_id, $target_features['terms'], $scan_id );
			} else {
				$target_node_id = (int) $target_node->node_id;
			}
			Store::insert_edge( $source_node_id, $target_node_id, $anchor, $source_url, (string) get_permalink( $target_post ), $scan_id );
			Store::refresh_counts( $target_node_id );
		}
	}

	private static function content_targets( $post ) {
		$content = function_exists( 'do_blocks' ) ? do_blocks( (string) $post->post_content ) : (string) $post->post_content;
		$targets = array();
		if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			return $targets;
		}
		foreach ( $matches as $match ) {
			$attrs = (string) $match[1];
			if ( false !== stripos( $attrs, 'data-publishrank-skip' ) || preg_match( '/wp-block-button|skip-link/i', $attrs ) ) {
				continue;
			}
			if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $attrs, $href ) && ! preg_match( '/\bhref\s*=\s*([^\s>]+)/is', $attrs, $href ) ) {
				continue;
			}
			$url       = html_entity_decode( trim( $href[2] ?? $href[1] ?? '' ), ENT_QUOTES, 'UTF-8' );
			$target_id = self::resolve_post_id( $url );
			if ( ! $target_id || isset( $targets[ $target_id ] ) || (int) $target_id === (int) $post->ID ) {
				continue;
			}
			$anchor = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $match[2] ) ) );
			if ( strlen( $anchor ) < 2 || $anchor === $url ) {
				continue;
			}
			$targets[ absint( $target_id ) ] = $anchor;
		}
		return $targets;
	}

	public static function resolve_post_id( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $url ) ) {
			return 0;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https' : 'http' ) . ':' . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		} elseif ( ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}
		if ( ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return 0;
		}
		return absint( url_to_postid( $url ) );
	}
}
