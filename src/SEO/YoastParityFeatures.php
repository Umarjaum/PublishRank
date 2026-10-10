<?php
/** Technical SEO features commonly expected from a mature SEO plugin. */
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;

final class YoastParityFeatures {
	/** Register update-safe WordPress integrations. */
	public function register() {
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		add_filter( 'wp_resource_hints', array( $this, 'remove_external_hints' ), 10, 2 );
		add_filter( 'the_content_feed', array( $this, 'append_feed_link' ) );
		add_filter( 'the_excerpt_rss', array( $this, 'append_feed_link' ) );
		add_action( 'template_redirect', array( $this, 'redirect_attachment_pages' ) );
		add_filter( 'get_canonical_url', array( $this, 'canonical_url' ), 10, 2 );
		add_filter( 'pre_get_posts', array( $this, 'protect_search_queries' ) );
	}

	/** Keep WordPress core robots output consistent with PublishRank directives. */
	public function filter_robots( $robots ) {
		if ( is_singular() ) {
			$directive = PostMeta::get( get_queried_object_id(), PostMeta::ROBOTS, '' );
			if ( '' !== $directive ) {
				$parts = array_map( 'trim', explode( ',', $directive ) );
				$robots['noindex'] = in_array( 'noindex', $parts, true );
				$robots['nofollow'] = in_array( 'nofollow', $parts, true );
			}
		}
			if ( is_search() || is_404() ) {
				$robots['noindex'] = true;
			}
			if ( is_author() && get_option( 'publishrank_noindex_author_archives', false ) ) { $robots['noindex'] = true; }
			if ( is_date() && get_option( 'publishrank_noindex_date_archives', false ) ) { $robots['noindex'] = true; }
		return $robots;
	}

	/** Do not emit speculative third-party DNS hints on the frontend. */
	public function remove_external_hints( $urls, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
			return $urls;
		}
		return array_values( array_filter( $urls, static function ( $url ) {
			return 0 === strpos( (string) $url, home_url() );
		} ) );
	}

	/** Add a stable source link to feeds for attribution and discovery. */
	public function append_feed_link( $content ) {
		$original = $content;
		if ( ! is_singular() ) {
				return $original;
		}
		$url = get_permalink();
		if ( ! $url ) {
				return $original;
		}
		$link = '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Read the full article', 'publishrank' ) . '</a></p>';
		return $content . $link;
	}

	/** Redirect attachment pages to their media URL without modifying templates. */
	public function redirect_attachment_pages() {
		if ( ! is_attachment() || headers_sent() ) {
			return;
		}
		$attachment_id = get_queried_object_id();
		$url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		if ( $url ) {
			wp_safe_redirect( $url, 301 );
			exit;
		}
	}

	/** Respect a per-post canonical override in core canonical output. */
	public function canonical_url( $canonical, $post ) {
		$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$override = $post_id ? PostMeta::get( $post_id, PostMeta::CANONICAL, '' ) : '';
		return '' !== $override ? esc_url_raw( $override ) : $canonical;
	}

	/** Prevent search and empty queries from becoming accidental indexable results. */
	public function protect_search_queries( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return $query;
		}
		if ( $query->is_search() ) {
			$query->set( 'posts_per_page', min( 20, (int) $query->get( 'posts_per_page' ) ?: 20 ) );
		}
		return $query;
	}
}

/* EOF */
