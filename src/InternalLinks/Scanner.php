<?php
/** Keyset-batched construction of the internal-link content graph. */
namespace PublishRankSEO\InternalLinks;

defined( 'ABSPATH' ) || exit;

final class Scanner {
	const STATE_KEY = 'publishrank_internal_links_scan_state';

	const BACKUP_KEY   = 'publishrank_internal_links_scan_state_backup';
	const MAX_STALLS   = 3;
	const STATE_TTL    = HOUR_IN_SECONDS;

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
			'batches'                  => 0,
			'stalls'                   => 0,
		);
		self::save_state( $state );
		return $state;
	}

	/** Persist scan progress in a transient plus a non-autoloaded backup option, so an evicted transient cannot silently restart or lose a scan. */
	private static function save_state( array $state ) {
		set_transient( self::STATE_KEY, $state, self::STATE_TTL );
		update_option(
			self::BACKUP_KEY,
			array(
				'expires' => time() + self::STATE_TTL,
				'state'   => $state,
			),
			false
		);
	}

	private static function clear_state() {
		delete_transient( self::STATE_KEY );
		delete_option( self::BACKUP_KEY );
	}

	/** Stop a running or paused scan without touching the graph built so far. */
	public static function cancel() {
		self::clear_state();
	}

	public static function state() {
		$state = get_transient( self::STATE_KEY );
		if ( is_array( $state ) ) {
			return $state;
		}
		$backup = get_option( self::BACKUP_KEY );
		if ( is_array( $backup ) && ! empty( $backup['state'] ) && is_array( $backup['state'] ) && ! empty( $backup['expires'] ) && (int) $backup['expires'] > time() ) {
			return $backup['state'];
		}
		if ( $backup ) {
			delete_option( self::BACKUP_KEY ); // Expired leftovers.
		}
		return null;
	}

	/** Percentage complete (0-100) for the current scan state. */
	public static function percent( $state ) {
		if ( ! is_array( $state ) ) {
			return 0;
		}
		if ( ! empty( $state['done'] ) ) {
			return 100;
		}
		$total = max( 1, (int) ( $state['total'] ?? 0 ) );
		if ( 'nodes' === ( $state['phase'] ?? '' ) ) {
			// Node analysis is the first 80% of the work; recommendations are the last 20%.
			return (int) min( 80, floor( ( (int) ( $state['processed'] ?? 0 ) / $total ) * 80 ) );
		}
		global $wpdb;
		$max_node = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(node_id) FROM %i', Store::table( 'nodes' ) ) );
		if ( $max_node < 1 ) {
			return 99;
		}
		return (int) min( 99, 80 + floor( ( (int) ( $state['last_recommendation_node'] ?? 0 ) / $max_node ) * 19 ) );
	}

	/**
	 * Process one batch. Returns the updated state, or null when no scan is active.
	 * The state carries 'done' => true when finished and 'error' => message when the scan had to stop.
	 */
	public static function run_batch() {
		$state = self::state();
		if ( ! $state ) {
			return null;
		}
		unset( $state['error'] ); // Resuming retries from the saved cursor.
		$before = array( $state['phase'], (int) $state['last_id'], (int) $state['processed'], (int) $state['last_recommendation_node'] );

		try {
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
					self::clear_state();
					Store::invalidate_cache();
					return $state;
				}
			}
		} catch ( \Throwable $e ) {
			// Keep the saved cursor so the scan can be resumed, but report the failure instead of retrying forever.
			$state['error'] = $e->getMessage();
			self::save_state( $state );
			return $state;
		}

		$state['batches'] = (int) ( $state['batches'] ?? 0 ) + 1;
		$after            = array( $state['phase'], (int) $state['last_id'], (int) $state['processed'], (int) $state['last_recommendation_node'] );

		// A batch that moves no cursor and changes no phase would repeat forever. Stop after a few of them.
		if ( $before === $after ) {
			$state['stalls'] = (int) ( $state['stalls'] ?? 0 ) + 1;
			if ( $state['stalls'] >= self::MAX_STALLS ) {
				$state['error'] = __( 'The scan stopped because it was not making progress. Cancel it and run a new graph scan.', 'publishrank' );
			}
		} else {
			$state['stalls'] = 0;
		}

		self::save_state( $state );
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
