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
		foreach ( $this->media_nodes() as $node ) {
			if ( is_array( $node ) && ! empty( $node ) ) { $nodes[] = $node; }
		}
		return $nodes;
	}

	/**
	 * The @type for GraphOutput's WebPage node, honoring per-document overrides and the global defaults.
	 * Every front-end document gets exactly one WebPage-family node: singular documents follow the editor choice /
	 * page map / "Default schema for pages", the front page follows "Homepage webpage type", and archives, author,
	 * search and 404 documents get their natural type instead of silently losing the default WebPage.
	 */
	public function current_webpage_type() {
		if ( is_front_page() ) {
			$home = sanitize_text_field( (string) get_option( 'publishrank_schema_home_type', 'WebPage' ) );
			$home = SchemaTypes::resolve( $home );
			if ( is_singular() ) {
				// A static front page may still carry an explicit editor override below.
				$override = $this->singular_override_type();
				if ( $override ) { return $override; }
			}
			return $home ? $home : 'WebPage';
		}
		if ( is_author() )  { return get_option( 'publishrank_schema_author_enabled', true ) ? 'ProfilePage' : 'CollectionPage'; }
		if ( is_search() )  { return 'SearchResultsPage'; }
		if ( is_404() )     { return 'WebPage'; }
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$override = $this->singular_override_type();
				if ( $override ) { return $override; }
				if ( 'page' === $post->post_type ) {
					$default = SchemaTypes::resolve( sanitize_text_field( (string) get_option( 'publishrank_schema_default_page_type', 'WebPage' ) ) );
					return $default ? $default : 'WebPage';
				}
			}
			return 'WebPage';
		}
		if ( is_home() || is_archive() || is_post_type_archive() ) { return 'CollectionPage'; }
		return 'WebPage';
	}

	/** Editor override or global About/Contact assignment for the current singular document ('' = none). */
	private function singular_override_type() {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) { return ''; }
		$resolved = SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' ) );
		if ( $resolved && in_array( $resolved, array_merge( array( 'WebPage' ), array_keys( SchemaTypes::page_types() ) ), true ) ) {
			return $resolved;
		}
		return $this->page_map_type( $post->ID );
	}

	/** Global About/Contact/Privacy/Terms page assignment → WebPage subtype. */
	private function page_map_type( $post_id ) {
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

	/** VideoObject and ImageGallery nodes selected for the current editorial document. */
	private function media_nodes() {
$post = get_queried_object();
if ( ! $post instanceof \WP_Post ) { return array(); }
$type = SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' ) );
$permalink = trailingslashit( esc_url_raw( get_permalink( $post ) ) );
$title = wp_strip_all_tags( (string) get_the_title( $post ) );
if ( 'VideoObject' === $type ) {
$content = PostMeta::get( $post->ID, PostMeta::VIDEO_URL, '' );
$embed = PostMeta::get( $post->ID, PostMeta::VIDEO_EMBED_URL, '' );
if ( $content || $embed ) {
$video = array( '@type' => 'VideoObject', '@id' => $permalink . '#video', 'name' => $title, 'url' => $permalink, 'description' => wp_strip_all_tags( get_the_excerpt( $post ) ), 'uploadDate' => get_post_time( DATE_W3C, true, $post ), 'thumbnailUrl' => PostMeta::get( $post->ID, PostMeta::VIDEO_THUMBNAIL, '' ), 'mainEntityOfPage' => array( '@id' => $permalink . '#webpage' ) );
if ( $content ) { $video['contentUrl'] = esc_url_raw( $content ); }
if ( $embed ) { $video['embedUrl'] = esc_url_raw( $embed ); }
$duration = PostMeta::get( $post->ID, PostMeta::VIDEO_DURATION, '' );
if ( $duration && preg_match( '/^[A-Z0-9:]+$/i', $duration ) ) { $video['duration'] = $duration; }
return array( array_filter( $video ) );
}
}
if ( 'ImageGallery' === $type ) {
$images = $this->content_image_urls( $post );
if ( count( $images ) > 1 ) {
$gallery = array( '@type' => 'ImageGallery', '@id' => $permalink . '#gallery', 'name' => $title, 'url' => $permalink, 'image' => array(), 'mainEntityOfPage' => array( '@id' => $permalink . '#webpage' ) );
foreach ( array_slice( $images, 0, 20 ) as $index => $image ) { $gallery['image'][] = array( '@type' => 'ImageObject', '@id' => $permalink . '#gallery-image-' . ( $index + 1 ), 'url' => $image['url'], 'width' => (int) ( $image['width'] ?? 0 ), 'height' => (int) ( $image['height'] ?? 0 ) ); }
return array( $gallery );
}
}
return array();
}
/** Extract clean http(s) URLs from raw multiline text. */
private function content_image_urls( $post ) {
			$images = array();
			$content = (string) $post->post_content;
			if ( preg_match_all( '/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
				foreach ( array_slice( $matches[1], 0, 20 ) as $url ) {
					$url = esc_url_raw( $url );
					$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
					if ( $url && in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) { $images[] = array( 'url' => $url ); }
				}
			}
			if ( empty( $images ) && function_exists( 'get_the_post_thumbnail_url' ) ) {
				$url = esc_url_raw( (string) get_the_post_thumbnail_url( $post, 'full' ) );
				if ( $url ) { $images[] = array( 'url' => $url ); }
			}
			return $images;
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
