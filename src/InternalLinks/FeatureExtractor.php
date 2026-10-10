<?php
/** Topic, keyword, taxonomy and content-similarity features. */
namespace PublishRankSEO\InternalLinks;

final class FeatureExtractor {
	const STOP_WORDS = array( 'the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'you', 'are', 'was', 'were', 'has', 'have', 'had', 'will', 'would', 'can', 'could', 'should', 'about', 'after', 'before', 'into', 'over', 'under', 'than', 'then', 'when', 'where', 'which', 'while', 'their', 'there', 'them', 'they', 'our', 'out', 'but', 'not', 'all', 'any', 'its', 'his', 'her', 'she', 'him', 'who', 'what', 'why', 'how', 'new', 'news', 'said', 'say', 'says' );

	public static function text( $value ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
		$value = wp_strip_all_tags( $value );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	private static function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}

	public static function tokens( $text, $max = 100 ) {
		$text = self::lower( self::text( $text ) );
		if ( ! preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'\-]*/u', $text, $matches ) ) {
			return array();
		}
		$counts = array();
		foreach ( (array) $matches[0] as $token ) {
			$token = trim( preg_replace( '/^[\-\'\p{P}]+|[\-\'\p{P}]+$/u', '', $token ) );
			$token = self::lower( $token );
			if ( '' === $token || is_numeric( $token ) || in_array( $token, self::STOP_WORDS, true ) ) {
				continue;
			}
			$counts[ $token ] = isset( $counts[ $token ] ) ? $counts[ $token ] + 1 : 1;
		}
		arsort( $counts );
		return array_slice( $counts, 0, absint( $max ), true );
	}

	private static function phrases( $value ) {
		$parts = preg_split( '/[,;\n\r]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $parts as $part ) {
			$part = trim( preg_replace( '/\s+/u', ' ', $part ) );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return array_slice( array_unique( $out ), 0, 20 );
	}

	private static function add_term( &$terms, $term, $weight ) {
		$term = trim( self::lower( $term ) );
		if ( '' === $term ) {
			return;
		}
		$terms[ $term ] = isset( $terms[ $term ] ) ? max( $terms[ $term ], $weight ) : $weight;
	}

	public static function from_post( $post ) {
		$title          = self::text( get_the_title( $post ) );
		$content        = self::text( $post->post_content );
		$focus_raw      = (string) get_post_meta( $post->ID, '_publishrank_seo_focus_keyword', true );
		$focus_phrases  = self::phrases( $focus_raw );
		$title_tokens   = self::tokens( $title, 40 );
		$content_tokens = self::tokens( $title . ' ' . $title . ' ' . $content, 200 );
		$taxonomy_ids   = array();
		$taxonomy_names = array();

		foreach ( (array) get_object_taxonomies( $post, 'objects' ) as $taxonomy_name => $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$terms = wp_get_post_terms( $post->ID, $taxonomy_name, array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( (array) $terms as $term ) {
				$taxonomy_ids[ $taxonomy_name ][]  = (int) $term->term_id;
				$taxonomy_names[ $taxonomy_name ][] = $term->name;
			}
		}

		$terms = array();
		foreach ( $title_tokens as $token => $count ) {
			self::add_term( $terms, $token, min( 10, 6 + $count ) );
		}
		foreach ( $focus_phrases as $phrase ) {
			self::add_term( $terms, $phrase, 14 );
			foreach ( array_keys( self::tokens( $phrase, 10 ) ) as $token ) {
				self::add_term( $terms, $token, 11 );
			}
		}
		foreach ( $taxonomy_names as $names ) {
			foreach ( (array) $names as $name ) {
				self::add_term( $terms, $name, 10 );
				foreach ( array_keys( self::tokens( $name, 8 ) ) as $token ) {
					self::add_term( $terms, $token, 7 );
				}
			}
		}
		arsort( $content_tokens );
		foreach ( array_slice( $content_tokens, 0, 80, true ) as $token => $count ) {
			self::add_term( $terms, $token, min( 6, 2 + (int) log( 1 + $count ) ) );
		}
		arsort( $terms );

		$modified = ! empty( $post->post_modified_gmt ) ? strtotime( $post->post_modified_gmt ) : strtotime( (string) $post->post_modified );
		return array(
			'title'          => $title,
			'title_tokens'   => array_keys( $title_tokens ),
			'vector'         => $content_tokens,
			'terms'          => array_slice( $terms, 0, 80, true ),
			'focus'          => $focus_phrases,
			'taxonomy_ids'   => $taxonomy_ids,
			'taxonomy_names' => $taxonomy_names,
			'modified'       => $modified ? (int) $modified : time(),
		);
	}
}
