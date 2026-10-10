<?php
/** Relevant internal-link recommendation scoring and anchor selection. */
namespace PublishRankSEO\InternalLinks;

final class Recommender {
	public static function for_target( $target_node, $max = 5 ) {
		$target_post = get_post( (int) $target_node->object_id );
		if ( ! Store::supports_post( $target_post ) ) {
			return 0;
		}
		$target_features = json_decode( (string) $target_node->features, true );
		if ( ! is_array( $target_features ) ) {
			$target_features = FeatureExtractor::from_post( $target_post );
		}

		$inserted = 0;
		foreach ( self::candidate_ids( (int) $target_node->node_id ) as $source_node_id ) {
			if ( $inserted >= absint( $max ) ) {
				break;
			}
			if ( Store::edge_exists( $source_node_id, (int) $target_node->node_id ) ) {
				continue;
			}
			$source_node = Store::node( $source_node_id );
			if ( ! $source_node ) {
				continue;
			}
			$source_post = get_post( (int) $source_node->object_id );
			if ( ! Store::supports_post( $source_post ) || (int) $source_post->ID === (int) $target_post->ID ) {
				continue;
			}
			$source_features = json_decode( (string) $source_node->features, true );
			if ( ! is_array( $source_features ) ) {
				$source_features = FeatureExtractor::from_post( $source_post );
			}
			$reciprocal = Store::edge_exists( (int) $target_node->node_id, $source_node_id );
			$score      = self::score_pair( $source_features, $target_features, $reciprocal );
			if ( $score['score'] < 25 ) {
				continue;
			}
			$anchor = self::anchor_for( $source_post, $target_post, $target_features );
			if ( '' === $anchor ) {
				continue;
			}
			if ( Store::insert_recommendation( $source_node_id, (int) $target_node->node_id, $anchor, $score['score'], $score['signals'] ) ) {
				$inserted++;
			}
		}
		return $inserted;
	}

	private static function candidate_ids( $target_node_id ) {
		global $wpdb;
		$terms = Store::table( 'terms' );
		$ids   = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT s.node_id AS node_id, COUNT(*) AS shared_count FROM {$terms} s INNER JOIN {$terms} t ON t.term = s.term WHERE t.node_id = %d AND s.node_id != %d GROUP BY s.node_id ORDER BY shared_count DESC, s.node_id DESC LIMIT 100", $target_node_id, $target_node_id ) ) );

		if ( count( $ids ) < 25 ) {
			$nodes  = Store::table( 'nodes' );
			$recent = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT node_id FROM {$nodes} WHERE node_id != %d ORDER BY node_id DESC LIMIT 50", $target_node_id ) ) );
			foreach ( $recent as $id ) {
				if ( ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}
		return array_slice( array_values( array_unique( $ids ) ), 0, 120 );
	}

	public static function score_pair( $source, $target, $reciprocal = false ) {
		$source_vector = isset( $source['vector'] ) && is_array( $source['vector'] ) ? $source['vector'] : array();
		$target_vector = isset( $target['vector'] ) && is_array( $target['vector'] ) ? $target['vector'] : array();
		$dot = 0.0; $source_len = 0.0; $target_len = 0.0;
		foreach ( $source_vector as $token => $weight ) {
			$source_len += (float) $weight * (float) $weight;
			if ( isset( $target_vector[ $token ] ) ) {
				$dot += (float) $weight * (float) $target_vector[ $token ];
			}
		}
		foreach ( $target_vector as $weight ) {
			$target_len += (float) $weight * (float) $weight;
		}
		$cosine = ( $source_len && $target_len ) ? $dot / sqrt( $source_len * $target_len ) : 0.0;

		$source_title = isset( $source['title_tokens'] ) ? (array) $source['title_tokens'] : array();
		$target_title = isset( $target['title_tokens'] ) ? (array) $target['title_tokens'] : array();
		$title_overlap = count( array_intersect( $source_title, $target_title ) );
		$title_score   = $title_overlap ? min( 20, 5 + ( $title_overlap / max( 1, min( count( $source_title ), count( $target_title ) ) ) ) * 15 ) : 0;

		$taxonomy_score = 0;
		$shared_terms   = 0;
		foreach ( (array) ( $target['taxonomy_ids'] ?? array() ) as $taxonomy => $target_terms ) {
			$shared = count( array_intersect( (array) $target_terms, (array) ( $source['taxonomy_ids'][ $taxonomy ] ?? array() ) ) );
			$shared_terms += $shared;
			$taxonomy_score += 'category' === $taxonomy ? min( 8, $shared * 4 ) : min( 7, $shared * 2 );
		}
		$taxonomy_score = min( 15, $taxonomy_score );

		$focus_hits = 0;
		foreach ( (array) ( $target['focus'] ?? array() ) as $phrase ) {
			foreach ( array_keys( FeatureExtractor::tokens( $phrase, 20 ) ) as $token ) {
				if ( isset( $source_vector[ $token ] ) || in_array( $token, $source_title, true ) ) {
					$focus_hits++;
				}
			}
		}
		$focus_score = min( 15, $focus_hits * 5 );

		$age = isset( $source['modified'] ) ? ( time() - absint( $source['modified'] ) ) / DAY_IN_SECONDS : 9999;
		$freshness = $age <= 180 ? 4 : ( $age <= 365 ? 2 : 0 );
		$reciprocal_score = $reciprocal ? 8 : 0;
		$score = round( min( 100, ( $cosine * 35 ) + $title_score + $taxonomy_score + $focus_score + $freshness + $reciprocal_score ) );

		return array(
			'score'   => $score,
			'signals' => array_filter(
				array(
					__( 'content similarity', 'publishrank' ) => round( $cosine, 3 ),
					__( 'title/topic overlap', 'publishrank' ) => $title_overlap,
					__( 'category/tag overlap', 'publishrank' ) => $shared_terms,
					__( 'keyword match', 'publishrank' ) => $focus_hits,
					__( 'existing related link', 'publishrank' ) => $reciprocal ? 1 : 0,
				)
			),
		);
	}

	public static function anchor_for( $source_post, $target_post, $target_features ) {
		$source_text = FeatureExtractor::text( $source_post->post_content );
		foreach ( (array) ( $target_features['focus'] ?? array() ) as $phrase ) {
			if ( strlen( $phrase ) >= 3 && false !== stripos( $source_text, $phrase ) ) {
				return self::limit( $phrase );
			}
		}
		foreach ( (array) ( $target_features['taxonomy_names'] ?? array() ) as $names ) {
			foreach ( (array) $names as $name ) {
				if ( strlen( $name ) >= 3 && false !== stripos( $source_text, $name ) ) {
					return self::limit( $name );
				}
			}
		}
		$title = FeatureExtractor::text( get_the_title( $target_post ) );
		$words = preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
		return self::limit( implode( ' ', array_slice( $words, 0, 7 ) ) );
	}

	private static function limit( $value ) {
		$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
		return function_exists( 'mb_substr' ) ? trim( mb_substr( $value, 0, 80, 'UTF-8' ) ) : trim( substr( $value, 0, 80 ) );
	}
}
