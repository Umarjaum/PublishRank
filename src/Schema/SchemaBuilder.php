<?php
/** Builds context-aware schema nodes: author profiles, media objects, FAQs and advanced content entities. */
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
				$home = SchemaTypes::resolve_in( sanitize_text_field( (string) get_option( 'publishrank_schema_home_type', 'WebPage' ) ), SchemaTypes::page_default_types(), 'WebPage' );
			if ( is_singular() ) {
				// A static front page may still carry an explicit editor override below.
				$override = $this->singular_override_type();
				if ( $override ) { return $override; }
			}
			return $home ? $home : 'WebPage';
		}
		if ( is_author() )  {
			if ( ! get_option( 'publishrank_schema_author_enabled', true ) ) { return 'CollectionPage'; }
			return SchemaTypes::resolve_in( get_option( 'publishrank_schema_author_type', 'ProfilePage' ), SchemaTypes::author_types(), 'ProfilePage' );
		}
		if ( is_search() )  { return SchemaTypes::resolve_in( get_option( 'publishrank_schema_search_type', 'SearchResultsPage' ), SchemaTypes::search_types(), 'SearchResultsPage' ); }
		if ( is_404() )     { return 'WebPage'; }
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$override = $this->singular_override_type();
				if ( $override ) { return $override; }
				if ( 'page' === $post->post_type ) {
					return SchemaTypes::resolve_in( sanitize_text_field( (string) get_option( 'publishrank_schema_default_page_type', 'WebPage' ) ), SchemaTypes::page_default_types(), 'WebPage' );
				}
			}
			return 'WebPage';
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term_choice = $this->term_override_type();
			if ( $term_choice ) { return $term_choice; }
			$option = is_category() ? 'publishrank_schema_category_type' : ( is_tag() ? 'publishrank_schema_tag_type' : 'publishrank_schema_archive_type' );
			return SchemaTypes::resolve_in( get_option( $option, 'CollectionPage' ), SchemaTypes::archive_types(), 'CollectionPage' );
		}
		if ( is_home() || is_archive() || is_post_type_archive() ) {
			return SchemaTypes::resolve_in( get_option( 'publishrank_schema_archive_type', 'CollectionPage' ), SchemaTypes::archive_types(), 'CollectionPage' );
		}
		return 'WebPage';
	}

	/** Per-term override (category, tag or custom taxonomy edit screen); '' = follow the archive defaults. */
	private function term_override_type() {
		$term = get_queried_object();
		if ( ! is_object( $term ) || empty( $term->term_id ) ) { return ''; }
		return SchemaTypes::resolve_in( get_term_meta( (int) $term->term_id, TermSchema::META_KEY, true ), SchemaTypes::archive_types(), '' );
	}

	/**
	 * Question/Answer entities for an FAQPage document, built only from items the editor entered.
	 * Returns an empty array when there is nothing valid to publish, so an empty FAQPage is never emitted.
	 *
	 * @param \WP_Post $post
	 * @return array<int,array<string,mixed>>
	 */
	public function faq_entities( $post ) {
		if ( ! $post instanceof \WP_Post ) { return array(); }
		$items = get_post_meta( $post->ID, PostMeta::FAQ_ITEMS, true );
		if ( ! is_array( $items ) ) { return array(); }
		$entities = array();
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$question = isset( $item['q'] ) ? trim( wp_strip_all_tags( (string) $item['q'] ) ) : '';
			$answer   = isset( $item['a'] ) ? trim( wp_strip_all_tags( (string) $item['a'] ) ) : '';
			if ( '' === $question || '' === $answer ) { continue; }
			$entities[] = array( '@type' => 'Question', 'name' => $question, 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $answer ) );
		}
		return $entities;
	}

	/** Editor override or global About/Contact assignment for the current singular document ('' = none). */
	private function singular_override_type() {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) { return ''; }
		$resolved = SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' ) );
		if ( $resolved && array_key_exists( $resolved, SchemaTypes::page_types() ) ) {
			// An FAQPage with no valid questions would be invalid markup: fall back to the default WebPage.
			if ( 'FAQPage' === $resolved && ! $this->faq_entities( $post ) ) { return ''; }
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
		$expertise = self::author_expertise( $author_id );
		if ( $expertise ) { $person['knowsAbout'] = $expertise; }
		$social = self::author_social( $author_id );
		if ( $social ) { $person['sameAs'] = $social; }
		$identity_url = trailingslashit( PublicationProfile::primary_url() );
		$person['worksFor'] = array( '@id' => $identity_url . ( PublicationProfile::identity_is_person() ? '#person' : '#organization' ) );
		$person['mainEntityOfPage'] = array( '@id' => $url . '#webpage' );
		return $person;
	}

	/** Normalize author expertise entered as comma/newline/semicolon-separated topics. @return string[] */
	public static function author_expertise_from_text( $raw ) {
		$topics = array();
		foreach ( preg_split( '/[\r\n,;]+/', (string) $raw ) as $topic ) {
			$topic = sanitize_text_field( trim( $topic ) );
			if ( '' !== $topic ) { $topics[] = function_exists( 'mb_substr' ) ? mb_substr( $topic, 0, 100 ) : substr( $topic, 0, 100 ); }
		}
		return array_values( array_unique( array_slice( $topics, 0, 20 ) ) );
	}
	/** Sanitized expertise topic list for one author profile. @return string[] */
	public static function author_expertise( $user_id ) {
		return self::author_expertise_from_text( (string) get_user_meta( (int) $user_id, '_publishrank_author_expertise', true ) );
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

	/** Explicit media and rich-entity nodes selected for the current editorial document. */
	private function media_nodes() {
$post = get_queried_object();
if ( ! $post instanceof \WP_Post ) { return array(); }
$type = SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' ) );
$permalink = trailingslashit( esc_url_raw( get_permalink( $post ) ) );
$nodes = array();
		// Every public singular page/post gets a connected ImageObject when an editorial image, fallback social image, logo, or site icon exists.
		$primary = \PublishRankSEO\Meta\SeoData::image_for_post( $post );
		$image_url = $primary ? (string) $primary['url'] : (string) get_option( 'publishrank_seo_default_social_image', '' );
		if ( ! $image_url ) { $identity = PublicationProfile::organization_identity(); $image_url = (string) ( $identity['logo'] ?? '' ); }
		if ( ! $image_url && function_exists( 'get_site_icon_url' ) ) { $image_url = (string) get_site_icon_url( 512 ); }
		$primary_image = array();
		if ( $image_url && in_array( get_post_type( $post ), array( 'post', 'page' ), true ) ) {
			$image_id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $image_url ) ) : 0;
			$image_meta = $image_id ? wp_get_attachment_metadata( $image_id ) : array();
			$primary_image = array( '@type' => 'ImageObject', '@id' => $permalink . '#primaryimage', 'url' => esc_url_raw( $image_url ), 'contentUrl' => esc_url_raw( $image_url ) );
			if ( is_array( $image_meta ) ) { if ( ! empty( $image_meta['width'] ) ) { $primary_image['width'] = (int) $image_meta['width']; } if ( ! empty( $image_meta['height'] ) ) { $primary_image['height'] = (int) $image_meta['height']; } }
			if ( $image_id ) { $alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true ); if ( $alt ) { $primary_image['caption'] = sanitize_text_field( $alt ); } }
			$nodes[] = $primary_image;
		}
	$title = wp_strip_all_tags( (string) get_the_title( $post ) );
	if ( array_key_exists( $type, SchemaTypes::rich_entity_types() ) ) {
		$entity = $this->rich_entity( $type, $post, $permalink, $title );
		if ( $entity ) { $nodes[] = $entity; }
		return $nodes;
	}
	if ( 'VideoObject' === $type ) {
	$content = PostMeta::get( $post->ID, PostMeta::VIDEO_URL, '' );
	$embed = PostMeta::get( $post->ID, PostMeta::VIDEO_EMBED_URL, '' );
	if ( $content || $embed ) {
	$thumbnail = PostMeta::get( $post->ID, PostMeta::VIDEO_THUMBNAIL, '' );
	if ( ! $thumbnail ) { $thumbnail = $image_url; }
	if ( ! $thumbnail ) { return $nodes; }
	$video = array( '@type' => 'VideoObject', '@id' => $permalink . '#video', 'name' => $title, 'url' => $permalink, 'description' => wp_strip_all_tags( get_the_excerpt( $post ) ), 'uploadDate' => get_post_time( DATE_W3C, true, $post ), 'thumbnailUrl' => esc_url_raw( $thumbnail ), 'mainEntityOfPage' => array( '@id' => $permalink . '#webpage' ) );
if ( $content ) { $video['contentUrl'] = esc_url_raw( $content ); }
if ( $embed ) { $video['embedUrl'] = esc_url_raw( $embed ); }
$duration = PostMeta::get( $post->ID, PostMeta::VIDEO_DURATION, '' );
if ( $duration && preg_match( '/^[A-Z0-9:]+$/i', $duration ) ) { $video['duration'] = $duration; }
return array_merge( $nodes, array( array_filter( $video ) ) );
}
}
if ( 'AudioObject' === $type || 'PodcastEpisode' === $type ) {
$audio_url = esc_url_raw( (string) PostMeta::get( $post->ID, PostMeta::AUDIO_URL, '' ) );
if ( $audio_url ) {
$audio = array( '@type' => 'AudioObject', '@id' => $permalink . '#audio', 'name' => $title, 'contentUrl' => $audio_url, 'uploadDate' => get_post_time( DATE_W3C, true, $post ), 'description' => wp_strip_all_tags( get_the_excerpt( $post ) ) );
$audio_duration = (string) PostMeta::get( $post->ID, PostMeta::AUDIO_DURATION, '' );
if ( $audio_duration && preg_match( '/^P[A-Z0-9.:]+$/i', $audio_duration ) ) { $audio['duration'] = $audio_duration; }
if ( 'PodcastEpisode' === $type ) {
$audio['mainEntityOfPage'] = array( '@id' => $permalink . '#webpage' );
$episode = array( '@type' => 'PodcastEpisode', '@id' => $permalink . '#podcast-episode', 'name' => $title, 'url' => $permalink, 'datePublished' => get_post_time( DATE_W3C, true, $post ), 'description' => wp_strip_all_tags( get_the_excerpt( $post ) ), 'associatedMedia' => array( '@id' => $permalink . '#audio' ), 'mainEntityOfPage' => array( '@id' => $permalink . '#webpage' ), 'publisher' => array( '@id' => trailingslashit( PublicationProfile::primary_url() ) . ( PublicationProfile::identity_is_person() ? '#person' : '#organization' ) ) );
return array_merge( $nodes, array( array_filter( $audio ), array_filter( $episode ) ) );
}
$audio['mainEntityOfPage'] = array( '@id' => $permalink . '#webpage' );
return array_merge( $nodes, array( array_filter( $audio ) ) );
}
}
if ( 'ImageGallery' === $type ) {
$images = $this->content_image_urls( $post );
	if ( count( $images ) > 0 ) {
$gallery = array( '@type' => 'ImageGallery', '@id' => $permalink . '#gallery', 'name' => $title, 'url' => $permalink, 'image' => array(), 'mainEntityOfPage' => array( '@id' => $permalink . '#webpage' ) );
foreach ( array_slice( $images, 0, 20 ) as $index => $image ) { $gallery['image'][] = array( '@type' => 'ImageObject', '@id' => $permalink . '#gallery-image-' . ( $index + 1 ), 'url' => $image['url'], 'width' => (int) ( $image['width'] ?? 0 ), 'height' => (int) ( $image['height'] ?? 0 ) ); }
return array_merge( $nodes, array( $gallery ) );
}
}
	return $nodes;
	}

	/** Build one explicit non-article entity; incomplete data emits no misleading partial entity. */
	private function rich_entity( $type, $post, $permalink, $title ) {
		$description = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		$base = array( 'name' => $title, 'url' => $permalink, 'mainEntityOfPage' => array( '@id' => $permalink . '#webpage' ) );
		if ( $description ) { $base['description'] = $description; }
		if ( 'Event' === $type ) {
			$start = trim( (string) PostMeta::get( $post->ID, PostMeta::EVENT_START, '' ) );
			$location = trim( wp_strip_all_tags( (string) PostMeta::get( $post->ID, PostMeta::EVENT_LOCATION, '' ) ) );
			$locality = trim( wp_strip_all_tags( (string) PostMeta::get( $post->ID, PostMeta::EVENT_LOCALITY, '' ) ) );
			$country = strtoupper( trim( (string) PostMeta::get( $post->ID, PostMeta::EVENT_COUNTRY, '' ) ) );
			$date_parts = date_parse( $start );
			if ( ! $location || ! $locality || ! preg_match( '/^[A-Z]{2}$/', $country ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?$/', $start ) || false === strtotime( $start ) || ! is_array( $date_parts ) || ! empty( $date_parts['error_count'] ) || ! empty( $date_parts['warning_count'] ) ) { return null; }
			$address = array( '@type' => 'PostalAddress', 'addressLocality' => $locality, 'addressCountry' => $country );
			foreach ( array( 'streetAddress' => PostMeta::EVENT_STREET, 'addressRegion' => PostMeta::EVENT_REGION, 'postalCode' => PostMeta::EVENT_POSTAL_CODE ) as $property => $key ) {
				$value = trim( wp_strip_all_tags( (string) PostMeta::get( $post->ID, $key, '' ) ) );
				if ( $value ) { $address[ $property ] = $value; }
			}
			return array( '@type' => 'Event', '@id' => $permalink . '#event', 'name' => $title, 'url' => $permalink, 'startDate' => str_replace( ' ', 'T', $start ), 'location' => array( '@type' => 'Place', 'name' => $location, 'address' => $address ), 'mainEntityOfPage' => array( '@id' => $permalink . '#webpage' ) ) + ( $description ? array( 'description' => $description ) : array() );
		}
		if ( 'Recipe' === $type ) {
			$ingredients = $this->schema_lines( PostMeta::get( $post->ID, PostMeta::RECIPE_INGREDIENTS, '' ), 50, 200 );
			$steps = $this->schema_lines( PostMeta::get( $post->ID, PostMeta::RECIPE_STEPS, '' ), 50, 500 );
			if ( empty( $ingredients ) || empty( $steps ) ) { return null; }
			$recipe = array( '@type' => 'Recipe', '@id' => $permalink . '#recipe' ) + $base;
			$author = get_userdata( $post->post_author );
			$author_name = PostMeta::get( $post->ID, PostMeta::AUTHOR_NAME, $author ? $author->display_name : '' );
			if ( $author_name ) { $recipe['author'] = array( '@type' => 'Person', 'name' => wp_strip_all_tags( (string) $author_name ) ); }
			$recipe['datePublished'] = get_post_time( DATE_W3C, true, $post );
			$image = \PublishRankSEO\Meta\SeoData::image_for_post( $post );
			if ( $image && ! empty( $image['url'] ) ) { $recipe['image'] = esc_url_raw( $image['url'] ); }
			$recipe['recipeIngredient'] = $ingredients;
			$recipe['recipeInstructions'] = array();
			foreach ( $steps as $step ) { $recipe['recipeInstructions'][] = array( '@type' => 'HowToStep', 'text' => $step ); }
			$time = strtoupper( trim( (string) PostMeta::get( $post->ID, PostMeta::RECIPE_TIME, '' ) ) );
			if ( $time && preg_match( '/^P(?:\d+D)?(?:T(?:\d+H)?(?:\d+M)?(?:\d+(?:\.\d+)?S)?)?$/', $time ) && preg_match( '/\d/', $time ) ) { $recipe['prepTime'] = $time; }
			$yield = trim( wp_strip_all_tags( (string) PostMeta::get( $post->ID, PostMeta::RECIPE_YIELD, '' ) ) );
			if ( $yield ) { $recipe['recipeYield'] = $yield; }
			return $recipe;
		}
		if ( 'Review' === $type ) {
			$item = trim( wp_strip_all_tags( (string) PostMeta::get( $post->ID, PostMeta::REVIEW_ITEM, '' ) ) );
			$rating_raw = trim( (string) PostMeta::get( $post->ID, PostMeta::REVIEW_RATING, '' ) );
			if ( '' === $item || ! is_numeric( $rating_raw ) || (float) $rating_raw < 0 || (float) $rating_raw > 5 ) { return null; }
			$user = get_userdata( $post->post_author );
			$author_name = PostMeta::get( $post->ID, PostMeta::AUTHOR_NAME, $user ? $user->display_name : __( 'Editorial staff', 'publishrank' ) );
			$review = array( '@type' => 'Review', '@id' => $permalink . '#review' ) + $base;
			$review['itemReviewed'] = array( '@type' => 'Thing', 'name' => $item );
			$review['reviewRating'] = array( '@type' => 'Rating', 'ratingValue' => (float) $rating_raw, 'bestRating' => 5, 'worstRating' => 0 );
			$review['author'] = array( '@type' => 'Person', 'name' => wp_strip_all_tags( (string) $author_name ) );
			$body = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 500, '' );
			if ( $body ) { $review['reviewBody'] = $body; }
			return $review;
		}
		if ( 'Product' === $type ) {
			$price = trim( (string) PostMeta::get( $post->ID, PostMeta::PRODUCT_PRICE, '' ) );
			$currency = strtoupper( trim( (string) PostMeta::get( $post->ID, PostMeta::PRODUCT_CURRENCY, '' ) ) );
			if ( ! preg_match( '/^\d+(?:\.\d{1,2})?$/', $price ) || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) { return null; }
			$product = array( '@type' => 'Product', '@id' => $permalink . '#product' ) + $base;
			$sku = trim( (string) PostMeta::get( $post->ID, PostMeta::PRODUCT_SKU, '' ) );
			$brand = trim( wp_strip_all_tags( (string) PostMeta::get( $post->ID, PostMeta::PRODUCT_BRAND, '' ) ) );
			if ( $sku ) { $product['sku'] = $sku; }
			if ( $brand ) { $product['brand'] = array( '@type' => 'Brand', 'name' => $brand ); }
			$offer = array( '@type' => 'Offer', 'url' => $permalink, 'price' => $price, 'priceCurrency' => $currency );
			$availability = (string) PostMeta::get( $post->ID, PostMeta::PRODUCT_AVAILABILITY, '' );
			$allowed = array( 'https://schema.org/InStock', 'https://schema.org/OutOfStock', 'https://schema.org/PreOrder', 'https://schema.org/BackOrder', 'https://schema.org/Discontinued' );
			if ( in_array( $availability, $allowed, true ) ) { $offer['availability'] = $availability; }
			$product['offers'] = $offer;
			$image = \PublishRankSEO\Meta\SeoData::image_for_post( $post );
			if ( $image && ! empty( $image['url'] ) ) { $product['image'] = esc_url_raw( $image['url'] ); }
			return $product;
		}
		return null;
	}

	/** Split bounded, sanitized newline-delimited schema lists. */
	private function schema_lines( $raw, $max_items, $max_length ) {
		$items = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( '' === $line ) { continue; }
			$line = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, $max_length ) : substr( $line, 0, $max_length );
			$items[] = $line;
			if ( count( $items ) >= $max_items ) { break; }
		}
		return $items;
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
