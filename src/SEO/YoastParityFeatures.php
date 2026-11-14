<?php
/** Technical SEO features commonly expected from a mature SEO plugin. */
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class YoastParityFeatures {
	/** Register update-safe WordPress integrations. */
	public function register() {
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		add_filter( 'wp_resource_hints', array( $this, 'remove_external_hints' ), 10, 2 );
		// Feed syndication: a source link back to the original article and the featured image in every item.
		add_filter( 'the_content_feed', array( $this, 'feed_content' ), 20 );
		add_filter( 'the_excerpt_rss', array( $this, 'feed_content' ), 20 );
		add_action( 'rss2_ns', array( $this, 'feed_media_namespace' ) );
		add_action( 'rss2_item', array( $this, 'feed_media_item' ) );
		add_action( 'template_redirect', array( $this, 'redirect_attachment_pages' ) );
		add_filter( 'get_canonical_url', array( $this, 'canonical_url' ), 10, 2 );
		add_filter( 'pre_get_posts', array( $this, 'protect_search_queries' ) );
	}

	/** Keep WordPress core robots output consistent with PublishRank directives. */
	public function filter_robots( $robots ) {
		if ( is_singular() ) {
			$directive = PostMeta::get( get_queried_object_id(), PostMeta::ROBOTS, '' );
			if ( '' !== $directive ) {
				$parts              = array_map( 'trim', explode( ',', $directive ) );
				$robots['noindex']  = in_array( 'noindex', $parts, true );
				$robots['nofollow'] = in_array( 'nofollow', $parts, true );
			}
		}
		if ( is_search() || is_404() ) {
			$robots['noindex'] = true;
		}
		if ( is_author() && get_option( 'publishrank_noindex_author_archives', false ) ) {
			$robots['noindex'] = true; }
		if ( is_date() && get_option( 'publishrank_noindex_date_archives', false ) ) {
			$robots['noindex'] = true; }
		return $robots;
	}

	/** Do not emit speculative third-party DNS hints on the frontend (hosts configured in PublishRank are kept). */
	public function remove_external_hints( $urls, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
			return $urls;
		}
		$allowed = class_exists( '\\PublishRankSEO\\Performance\\ResourceHints' ) ? \PublishRankSEO\Performance\ResourceHints::configured_hosts() : array( 'dns' => array() );
		$allowed = array_map(
			static function ( $href ) {
				return untrailingslashit( strtolower( (string) preg_replace( '#^https?:#i', '', (string) $href ) ) );
			},
			(array) $allowed['dns']
		);
		$home    = untrailingslashit( strtolower( (string) preg_replace( '#^https?:#i', '', home_url() ) ) );
		return array_values(
			array_filter(
				$urls,
				static function ( $entry ) use ( $allowed, $home ) {
					$href = is_array( $entry ) ? (string) ( $entry['href'] ?? '' ) : (string) $entry;
					$key  = untrailingslashit( strtolower( (string) preg_replace( '#^https?:#i', '', $href ) ) );
					return 0 === strpos( $key, $home ) || in_array( $key, $allowed, true );
				}
			)
		);
	}

	/** Back-compat alias for the old filter callback name. */
	public function append_feed_link( $content ) {
		return $this->feed_content( $content );
	}

	/** Featured image of a feed item, or null. @return array{url:string,width:int,height:int,type:string,alt:string}|null */
	private static function feed_image( $post ) {
		$post = get_post( $post );
		if ( ! $post || post_password_required( $post ) ) {
			return null; }
		$id = (int) get_post_thumbnail_id( $post );
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return null; }
		$size = apply_filters( 'publishrank_feed_image_size', 'large', $post );
		$src  = wp_get_attachment_image_src( $id, $size );
		if ( ! $src || empty( $src[0] ) ) {
			return null; }
		$alt = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) );
		return array(
			'url'    => (string) $src[0],
			'width'  => isset( $src[1] ) ? (int) $src[1] : 0,
			'height' => isset( $src[2] ) ? (int) $src[2] : 0,
			'type'   => (string) get_post_mime_type( $id ),
			'alt'    => '' !== $alt ? $alt : wp_strip_all_tags( get_the_title( $post ) ),
		);
	}

	/**
	 * RSS item body for syndication partners and news apps: the featured image on top and a source line at the end.
	 * The source points at the canonical URL, so a syndicated copy credits the original outlet instead of this site.
	 */
	public function feed_content( $content ) {
		if ( ! is_feed() ) {
			return $content; }
		$post = get_post();
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return $content; }
		$content = (string) $content;
		if ( get_option( 'publishrank_feed_images', 1 ) ) {
			$image = self::feed_image( $post );
			if ( $image && false === strpos( $content, $image['url'] ) ) {
				$img     = '<p><img src="' . esc_url( $image['url'] ) . '"' . ( $image['width'] ? ' width="' . (int) $image['width'] . '"' : '' ) . ( $image['height'] ? ' height="' . (int) $image['height'] . '"' : '' ) . ' alt="' . esc_attr( $image['alt'] ) . '" /></p>';
				$content = $img . "\n" . $content;
			}
		}
		if ( get_option( 'publishrank_feed_attribution', 1 ) && false === strpos( $content, 'data-publishrank-source' ) ) {
			$permalink = (string) get_permalink( $post );
			$canonical = class_exists( '\\PublishRankSEO\\Meta\\MetaGenerator' ) ? (string) ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical_for_post( $post->ID ) : $permalink;
			$canonical = '' !== $canonical ? $canonical : $permalink;
			$title     = '<a href="' . esc_url( $canonical ) . '">' . esc_html( wp_strip_all_tags( get_the_title( $post ) ) ) . '</a>';
			$site_host = (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
			$orig_host = strtolower( (string) wp_parse_url( $canonical, PHP_URL_HOST ) );
			if ( '' !== $orig_host && preg_replace( '/^www\./', '', $orig_host ) !== $site_host ) {
				/* translators: 1: linked article title, 2: original publication host. */
				$line = sprintf( __( 'The post %1$s was originally published on %2$s.', 'publishrank' ), $title, esc_html( $orig_host ) );
			} else {
				$name = \PublishRankSEO\Core\PublicationProfile::publication_name();
				$name = '' !== $name ? $name : (string) get_bloginfo( 'name' );
				/* translators: 1: linked article title, 2: linked publication name. */
				$line = sprintf( __( 'The post %1$s appeared first on %2$s.', 'publishrank' ), $title, '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $name ) . '</a>' );
			}
			$line     = (string) apply_filters( 'publishrank_feed_attribution', $line, $post, $canonical );
			$content .= "\n" . '<p data-publishrank-source="1">' . wp_kses_post( $line ) . '</p>';
		}
		return $content;
	}

	/** Declare the Media RSS namespace once (skipped when another plugin already declares it). */
	public function feed_media_namespace() {
		if ( ! get_option( 'publishrank_feed_images', 1 ) || has_action( 'rss2_ns', 'jetpack_rss2_ns' ) || ! apply_filters( 'publishrank_feed_media_namespace', true ) ) {
			return; }
		echo 'xmlns:media="http://search.yahoo.com/mrss/"' . "\n";
	}

	/** <media:content> with the featured image, read by Google News, Flipboard, MSN Start and most feed readers. */
	public function feed_media_item() {
		if ( ! get_option( 'publishrank_feed_images', 1 ) || ! apply_filters( 'publishrank_feed_media_namespace', true ) ) {
			return; }
		$image = self::feed_image( get_post() );
		if ( ! $image ) {
			return; }
		echo "\t\t" . '<media:content url="' . esc_url( $image['url'] ) . '" medium="image"' . ( $image['width'] ? ' width="' . (int) $image['width'] . '"' : '' ) . ( $image['height'] ? ' height="' . (int) $image['height'] . '"' : '' ) . ( $image['type'] ? ' type="' . esc_attr( $image['type'] ) . '"' : '' ) . '>' . "\n";
		echo "\t\t\t" . '<media:description type="plain"><![CDATA[' . str_replace( ']]>', ']]&gt;', $image['alt'] ) . ']]></media:description>' . "\n";
		echo "\t\t</media:content>\n";
	}

	/** Redirect attachment pages to their media URL without modifying templates. */
	public function redirect_attachment_pages() {
		if ( ! is_attachment() || headers_sent() ) {
			return;
		}
		$attachment_id = get_queried_object_id();
		$url           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		if ( $url ) {
			wp_safe_redirect( $url, 301 );
			exit;
		}
	}

	/** Respect a per-post canonical override in core canonical output. */
	public function canonical_url( $canonical, $post ) {
		$post_id  = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$override = $post_id ? PostMeta::get( $post_id, PostMeta::CANONICAL, '' ) : '';
		return '' !== $override ? esc_url_raw( $override ) : $canonical;
	}

	/** Prevent search and empty queries from becoming accidental indexable results. */
	public function protect_search_queries( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return $query;
		}
		if ( $query->is_search() ) {
			$query->set( 'posts_per_page', min( 20, ! empty( (int) $query->get( 'posts_per_page' ) ) ? (int) $query->get( 'posts_per_page' ) : 20 ) );
		}
		return $query;
	}
}

/* EOF */
