<?php
/**
 * The document a schema template is resolved against: a post, a term archive or the front page.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Studio;

use PublishRankSEO\Core\PublicationProfile;

defined( 'ABSPATH' ) || exit;

final class Context {

	/** @var \WP_Post|null */
	public $post = null;
	/** @var \WP_Term|null */
	public $term = null;
	/** @var bool */
	public $is_front = false;
	/** @var string Canonical URL of the document. */
	public $url = '';
	/** @var string Primary site URL with trailing slash. */
	public $site = '';

	/** Context for the current front-end request (null when no template can apply, e.g. 404 or search). */
	public static function current() {
		if ( is_404() || is_search() ) {
			return null;
		}
		if ( is_front_page() && ! is_singular() ) {
			return self::front();
		}
		if ( is_singular() ) {
			return self::for_post( get_queried_object_id(), true );
		}
		$object = get_queried_object();
		if ( $object instanceof \WP_Term ) {
			return self::for_term( $object->term_id );
		}
		return null;
	}

	public static function for_post( $post_id, $current = false ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$c           = new self();
		$c->post     = $post;
		$c->is_front = (int) get_option( 'page_on_front' ) === (int) $post->ID && 'page' === get_option( 'show_on_front' );
		$c->site     = trailingslashit( PublicationProfile::primary_url() );
		$url         = '';
		if ( class_exists( '\\PublishRankSEO\\Meta\\MetaGenerator' ) ) {
			$meta = new \PublishRankSEO\Meta\MetaGenerator();
			$url  = $current ? $meta->canonical() : $meta->canonical_for_post( $post->ID );
		}
		$c->url = $url ? $url : (string) get_permalink( $post );
		return $c;
	}

	public static function for_term( $term_id ) {
		$term = get_term( (int) $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$c       = new self();
		$c->term = $term;
		$c->site = trailingslashit( PublicationProfile::primary_url() );
		$link    = get_term_link( $term );
		$c->url  = is_wp_error( $link ) ? '' : (string) $link;
		return $c;
	}

	public static function front() {
		$c           = new self();
		$c->is_front = true;
		$c->site     = trailingslashit( PublicationProfile::primary_url() );
		$c->url      = trailingslashit( home_url( '/' ) );
		return $c;
	}

	/** Stable @id values of the entities PublishRank already prints. */
	public function id( $entity ) {
		$page = trailingslashit( $this->url );
		switch ( $entity ) {
			case 'webpage':
				return $page . '#webpage';
			case 'website':
				return $this->site . '#website';
			case 'organization':
				return $this->site . ( PublicationProfile::identity_is_person() ? '#person' : '#organization' );
			case 'article':
				return $page . '#article';
			case 'breadcrumb':
				return $page . '#breadcrumb';
			case 'local_business':
				return $this->site . '#local-business';
			case 'author':
				if ( $this->post ) {
					return trailingslashit( get_author_posts_url( (int) $this->post->post_author ) ) . '#author';
				}
				return '';
		}
		return '';
	}

	/** A short human description used in diagnostics and previews. */
	public function label() {
		if ( $this->post ) {
			return get_the_title( $this->post ) . ' (' . $this->post->post_type . ' #' . $this->post->ID . ')';
		}
		if ( $this->term ) {
			return $this->term->name . ' (' . $this->term->taxonomy . ')';
		}
		return __( 'Front page', 'publishrank' );
	}
}
