<?php
/** Builds context-aware schema nodes: author profiles, media objects, manual FAQ. */
namespace PublishRankSEO\Schema;

use PublishRankSEO\Core\PublicationProfile;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class SchemaBuilder {

	/** Extra graph nodes for the current request (called from GraphOutput). */
	public function extra_nodes() {
		$nodes = array();
		if ( is_author() ) {
			if ( get_option( 'publishrank_schema_author_enabled', true ) ) {
				$person = $this->author_person();
				if ( $person ) { $nodes[] = $person; }
			}
			return $nodes;
		}
		if ( ! is_singular() ) { return $nodes; }
		foreach ( array_merge( $this->media_nodes(), array_filter( array( $this->manual_faq() ) ) ) as $node ) {
			if ( is_array( $node ) && ! empty( $node ) ) { $nodes[] = $node; }
		}
		return $nodes;
	}

	/** The @type for GraphOutput's WebPage node, honoring overrides + global maps. */
	public function current_webpage_type() {
		if ( is_front_page() ) {
			$home = sanitize_text_field( (string) get_option( 'publishrank_schema_home_type', 'WebPage' ) );
			return SchemaTypes::resolve( $home ) ? SchemaTypes::resolve( $home ) : 'WebPage';
		}
		if ( is_author() )  { return 'ProfilePage'; }
		if ( is_search() )  { return 'SearchResultsPage'; }
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$resolved = SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' ) );
				if ( $resolved && in_array( $resolved, array_merge( array( 'WebPage' ), array_keys( SchemaTypes::page_types() ) ), true ) ) {
					return $resolved;
				}
				$mapped = $this->page_map_type( $post->ID );
				if ( $mapped ) { return $mapped; }
				if ( 'page' === $post->post_type ) {
					$default = sanitize_text_field( (string) get_option( 'publishrank_schema_default_page_type', 'WebPage' ) );
					return SchemaTypes::resolve( $default ) ? SchemaTypes::resolve( $default ) : 'WebPage';
				}
			}
		}
		return 'WebPage';
	}

	/** Global About/Contact/Privacy/Terms page assignment → WebPage subtype. */
	private function page_map_type( $post_id ) {
		$map = (array) get_option( 'publishrank_schema_page_map', array() );
		$map = array_map( 'absint', $map );
		if ( ! empty( $map['about'] ) && $map['about'] === (int) $post_id )    { return 'AboutPage'; }
		if ( ! empty( $map['contact'] ) && $map['contact'] === (int) $post_id ) { return 'ContactPage'; }
		if ( ! empty( $map['privacy'] ) && $map['privacy'] === (int) $post_id ) { return 'WebPage'; }
		if ( ! empty( $map['terms'] ) && $map['terms'] === (int) $post_id )     { return 'WebPage'; }
		return '';
	}

	/** Rich Person node for author archives; @id matches the article author @id. */
	public function author_person() {
		$author_id = (int) get_queried_object_id();
		if ( ! $author_id ) { return array(); }
		$url   = trailingslashit( get_author_posts_url( $author_id ) );
		$name  = wp_strip_all_tags( (string) get_the_author_meta( 'display_name', $author_id ) );
		$person = array(
			'@type' => 'Person',
			'@id'   => $url . '#author',
			'name'  => $name,
			'url'   => esc_url_raw( $url ),
		);
		$desc = trim( (string) get_the_author_meta( 'description', $author_id ) );
		if ( $desc ) { $person['description'] = wp_strip_all_tags( $desc ); }
		$avatar = function_exists( 'get_avatar_url' ) ? get_avatar_url( $author_id, array( 'size' => 512 ) ) : '';
		if ( $avatar ) {
			$person['image'] = array( '@type' => 'ImageObject', '@id' => $url . '#author-image', 'url' => esc_url_raw( $avatar ) );
		}
		$job = (string) get_user_meta( $author_id, '_publishrank_author_job_title', true );
		if ( $job ) { $person['jobTitle'] = sanitize_text_field( $job ); }
		$social = self::author_social( $author_id );
		if ( $social ) { $person['sameAs'] = $social; }
		$identity_url = trailingslashit( PublicationProfile::primary_url() );
		$person['worksFor'] = array( '@id' => $identity_url . ( PublicationProfile::identity_is_person() ? '#person' : '#organization' ) );
		$person['mainEntityOfPage'] = array( '@id' => $url . '#webpage' );
		return $person;
	}

	/** Sanitized sameAs list for an author (user profile field, one URL per line). */
	public static function author_social( $user_id ) {
		$raw = (string) get_user_meta( (int) $user_id, '_publishrank_author_social', true );
		$urls = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( $line && preg_match( '/^https?:\/\//i', $line ) ) { $urls[] = esc_url_raw( $line ); }
		}
		return array_values( array_unique( array_slice( $urls, 0, 20 ) ) );
	}

	/** VideoObject / AudioObject / Event / ImageObject nodes from post meta. */
	private function media_nodes() {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) { return array(); }
		$type       = SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' ) );
		$permalink  = trailingslashit( esc_url_raw( get_permalink( $post ) ) );
		$title      = wp_strip_all_tags( (string) get_the_title( $post ) );
		$nodes      = array();

		if ( 'VideoObject' === $type ) {
			$content = PostMeta::get( $post->ID, PostMeta::VIDEO_URL, '' );
			$embed   = PostMeta::get( $post->ID, PostMeta::VIDEO_EMBED_URL, '' );
			if ( $content || $embed ) {
				$video = array(
					'@type' => 'VideoObject', '@id' => $permalink . '#video',
					'name' => $title, 'url' => $permalink,
					'description' => wp_strip_all_tags( get_the_excerpt( $post ) ),
					'uploadDate' => get_post_time( DATE_W3C, true, $post ),
					'thumbnailUrl' => PostMeta::get( $post->ID, PostMeta::VIDEO_THUMBNAIL, '' ) ?: \PublishRankSEO\Meta\SeoData::image_for_post( $post )['url'] ?? '',
					'mainEntityOfPage' => array( '@id' => $permalink . '#webpage' ),
				);
				if ( $content ) { $video['contentUrl'] = esc_url_raw( $content ); }
				if ( $embed )   { $video['embedUrl']   = esc_url_raw( $embed ); }
				$duration = PostMeta::get( $post->ID, PostMeta::VIDEO_DURATION, '' );
				if ( $duration && preg_match( '/^[A-Z0-9:]+$/i', $duration ) ) { $video['duration'] = $duration; }
				$nodes[] = array_filter( $video );
			}
		} elseif ( 'AudioObject' === $type ) {
			$content = PostMeta::get( $post->ID, PostMeta::AUDIO_URL, '' );
			if ( $content ) {
				$audio = array(
					'@type' => 'AudioObject', '@id' => $permalink . '#audio',
					'name' => $title, 'url' => esc_url_raw( $content ),
					'uploadDate' => get_post_time( DATE_W3C, true, $post ),
				);
				$duration = PostMeta::get( $post->ID, PostMeta::AUDIO_DURATION, '' );
				if ( $duration && preg_match( '/^[A-Z0-9:]+$/i', $duration ) ) { $audio['duration'] = $duration; }
				$nodes[] = array_filter( $audio );
			}
		} elseif ( 'Event' === $type ) {
			$start = PostMeta::get( $post->ID, PostMeta::EVENT_START, '' );
			if ( $start && strtotime( $start ) ) {
				$event = array(
					'@type' => 'Event', '@id' => $permalink . '#event', 'name' => $title,
					'startDate' => gmdate( DATE_W3C, strtotime( $start ) ),
					'url' => $permalink,
					'organizer' => array( '@id' => trailingslashit( PublicationProfile::primary_url() ) . '#organization' ),
				);
				$location = PostMeta::get( $post->ID, PostMeta::EVENT_LOCATION, '' );
				if ( $location ) { $event['location'] = array( '@type' => 'Place', 'name' => $location ); }
				$nodes[] = array_filter( $event );
			}
		} elseif ( 'ImageObject' === $type && 'attachment' === $post->post_type && wp_attachment_is_image( $post ) ) {
			$src = wp_get_attachment_image_src( $post->ID, 'full' );
			if ( $src ) {
				$nodes[] = array(
					'@type' => 'ImageObject', '@id' => $permalink . '#image',
					'url' => esc_url_raw( $src[0] ), 'width' => (int) $src[1], 'height' => (int) $src[2],
					'name' => $title,
				);
			}
		}
		return $nodes;
	}

	/** Manual FAQ items entered in the schema meta box (takes priority over auto-detect). */
	private function manual_faq() {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) { return array(); }
		$items = get_post_meta( $post->ID, PostMeta::FAQ_ITEMS, true );
		if ( empty( $items ) || ! is_array( $items ) ) { return array(); }
		$questions = array();
		$allowed_html = array( 'a' => array( 'href' => true ), 'p' => array(), 'br' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array() );
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$q = trim( wp_strip_all_tags( (string) ( $item['q'] ?? '' ) ) );
			$a = trim( wp_kses( (string) ( $item['a'] ?? '' ), $allowed_html ) );
			if ( '' === $q || '' === trim( wp_strip_all_tags( $a ) ) ) { continue; }
			$questions[] = array(
				'@type' => 'Question',
				'name'  => $q,
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $a ),
			);
		}
		if ( empty( $questions ) ) { return array(); }
		$permalink = trailingslashit( esc_url_raw( get_permalink( $post ) ) );
		return array(
			'@type' => 'FAQPage', '@id' => $permalink . '#faq',
			'mainEntityOfPage' => array( '@id' => $permalink . '#webpage' ),
			'mainEntity' => $questions,
		);
	}
	/** Extract clean http(s) URLs from raw multiline text. */
	public static function author_social_urls_from_text( $text ) {
		$urls = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( $line && preg_match( '/^https?:\/\//i', $line ) ) { $urls[] = esc_url_raw( $line ); }
		}
		return array_values( array_unique( array_slice( $urls, 0, 20 ) ) );
	}

}