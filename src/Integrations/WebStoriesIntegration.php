<?php
/**
 * Google Web Stories support: stories sitemap, PublishRank meta/schema on story pages
 * (replacing the plugin's own duplicate tags), and large image previews for Discover.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Integrations;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class WebStoriesIntegration extends IntegrationBase {
	const POST_TYPE = 'web-story';
	protected $module = 'web_stories';

	public function detected() {
		return defined( 'WEBSTORIES_VERSION' ) || post_type_exists( self::POST_TYPE );
	}

	public function register() {
		add_filter( 'publishrank_sitemap_providers', array( $this, 'sitemap' ) );
		add_filter( 'publishrank_sitemap_cpt_excluded', array( $this, 'exclude_cpt' ) );
		add_filter( 'publishrank_sitemap_type_labels', array( $this, 'labels' ) );
		add_filter( 'publishrank_seo_robots', array( $this, 'robots' ), 20 );
		add_filter( 'publishrank_seo_meta_box_post_types', array( $this, 'meta_box_types' ) );
		add_filter( 'publishrank_schema_graph', array( $this, 'schema' ), 20 );
		add_action( 'wp', array( $this, 'bridge_story_head' ) );
	}

	private function is_story() {
		return is_singular( self::POST_TYPE );
	}

	public function sitemap( $providers ) {
		$providers[] = new PostTypeSitemapProvider( 'web-stories', __( 'Web Stories', 'publishrank' ), array( self::POST_TYPE ), array( $this, 'active' ) );
		return $providers;
	}

	public function exclude_cpt( $excluded ) {
		if ( $this->active() ) {
			$excluded[] = self::POST_TYPE;
		}
		return $excluded;
	}

	public function labels( $types ) {
		$types['web-stories'] = array(
			'label' => __( 'Web Stories', 'publishrank' ),
			'desc'  => __( 'Tappable visual stories for Google Search and Discover', 'publishrank' ),
			'abbr'  => 'WS',
		);
		return $types;
	}

	public function meta_box_types( $types ) {
		if ( $this->active() ) {
			$types[] = self::POST_TYPE;
		}
		return $types;
	}

	/** Discover shows stories with large images only when max-image-preview:large is allowed. */
	public function robots( $robots ) {
		if ( $this->active() && $this->is_story() && false === strpos( (string) $robots, 'noindex' ) ) {
			$robots = self::robots_add( $robots, array( 'max-image-preview:large' ) );
		}
		return $robots;
	}

	/**
	 * Story graph: the Article gets every poster image, the story's own categories and tags, and the
	 * publisher logo chosen for stories. An Article is added only when no other code printed one.
	 */
	public function schema( $graph ) {
		if ( ! $this->active() || ! $this->is_story() ) {
			return $graph;
		}
		$post_id = (int) get_queried_object_id();
		$images  = $this->story_images( $post_id );
		$section = $this->story_terms( $post_id, 'web_story_category' );
		$tags    = $this->story_terms( $post_id, 'web_story_tag' );
		$logo    = esc_url_raw( (string) PostMeta::get( $post_id, PostMeta::WEB_STORY_PUBLISHER_LOGO, '' ) );

		$has_article = false;
		$publisher   = '';
		$identity    = array();
		$page_id     = '';
		foreach ( $graph as $i => $node ) {
			if ( ! is_array( $node ) || empty( $node['@id'] ) ) {
				continue;
			}
			$id    = (string) $node['@id'];
			$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( self::is_article_type( $types ) ) {
				$has_article = true;
				if ( $images ) {
					$graph[ $i ]['image']        = $this->merge_images( isset( $node['image'] ) ? $node['image'] : '', $images );
					$graph[ $i ]['thumbnailUrl'] = $images[0];
				}
				if ( $section ) {
					$graph[ $i ]['articleSection'] = $section[0];
				}
				if ( $tags && empty( $node['keywords'] ) ) {
					$graph[ $i ]['keywords'] = implode( ', ', $tags );
				}
			}
			if ( '' === $publisher && array_intersect( $types, array( 'Organization', 'NewsMediaOrganization', 'LocalBusiness', 'Corporation', 'OnlineBusiness' ) ) ) {
				$publisher = $id;
				$identity  = $node;
			}
			if ( '#webpage' === substr( $id, -8 ) ) {
				if ( '' === $page_id ) {
					$page_id = $id;
				}
				if ( $images && empty( $node['primaryImageOfPage'] ) ) {
					$graph[ $i ]['primaryImageOfPage'] = array( '@type' => 'ImageObject', 'url' => $images[0] );
				}
			}
		}
		// The story publisher logo gets its own Organization node, so the site-wide Organization stays identical on every page.
		if ( '' !== $logo && '' !== $publisher && ! empty( $identity['name'] ) ) {
			$story_publisher = trailingslashit( (string) get_permalink( $post_id ) ) . '#story-publisher';
			$graph[]         = array(
				'@type' => 'Organization',
				'@id'   => $story_publisher,
				'name'  => (string) $identity['name'],
				'url'   => ! empty( $identity['url'] ) ? (string) $identity['url'] : home_url( '/' ),
				'logo'  => array(
					'@type'      => 'ImageObject',
					'url'        => $logo,
					'contentUrl' => $logo,
				),
			);
			$publisher       = $story_publisher;
			foreach ( $graph as $i => $node ) {
				if ( is_array( $node ) && isset( $node['@type'] ) && self::is_article_type( (array) $node['@type'] ) ) {
					$graph[ $i ]['publisher'] = array( '@id' => $story_publisher );
				}
			}
		}
		if ( ! $has_article ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$url     = get_permalink( $post_id );
				$article = array(
					'@type'         => 'Article',
					'@id'           => trailingslashit( $url ) . '#article',
					'url'           => $url,
					'headline'      => wp_html_excerpt( wp_strip_all_tags( get_the_title( $post ) ), 110, '…' ),
					'datePublished' => get_post_time( DATE_W3C, true, $post ),
					'dateModified'  => get_post_modified_time( DATE_W3C, true, $post ),
					'inLanguage'    => str_replace( '_', '-', (string) get_locale() ),
					'author'        => array(
						'@type' => 'Person',
						'name'  => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
						'url'   => (string) get_author_posts_url( (int) $post->post_author ),
					),
					'isAccessibleForFree' => true,
				);
				$excerpt = trim( wp_strip_all_tags( (string) get_the_excerpt( $post ) ) );
				if ( '' !== $excerpt ) {
					$article['description'] = wp_html_excerpt( $excerpt, 300, '…' );
				}
				if ( $images ) {
					$article['image']        = $this->merge_images( '', $images );
					$article['thumbnailUrl'] = $images[0];
				}
				if ( $section ) {
					$article['articleSection'] = $section[0];
				}
				if ( $tags ) {
					$article['keywords'] = implode( ', ', $tags );
				}
				if ( '' !== $page_id ) {
					$article['mainEntityOfPage'] = array( '@id' => $page_id );
				}
				if ( '' === $publisher ) {
					$publisher = trailingslashit( \PublishRankSEO\Core\PublicationProfile::primary_url() ) . ( \PublishRankSEO\Core\PublicationProfile::identity_is_person() ? '#person' : '#organization' );
				}
				$article['publisher'] = array( '@id' => $publisher );
				$graph[] = $article;
			}
		}
		return $graph;
	}

	/** Any schema.org Article subtype: NewsArticle, ReportageNewsArticle, BlogPosting, LiveBlogPosting, … */
	private static function is_article_type( array $types ) {
		foreach ( $types as $type ) {
			$type = (string) $type;
			if ( 'Article' === substr( $type, -7 ) || 'BlogPosting' === substr( $type, -11 ) || 'Report' === $type ) {
				return true;
			}
		}
		return false;
	}

	/** Poster URLs, best first: the poster chosen for the story, the Web Stories plugin poster, the featured image. */
	private function story_images( $post_id ) {
		$urls   = array();
		$urls[] = esc_url_raw( (string) PostMeta::get( $post_id, PostMeta::WEB_STORY_POSTER, '' ) );
		$meta   = get_post_meta( $post_id, 'web_stories_poster', true );
		if ( is_array( $meta ) && ! empty( $meta['url'] ) ) {
			$urls[] = esc_url_raw( (string) $meta['url'] );
		}
		$thumb = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $thumb ) {
			$urls[] = esc_url_raw( (string) $thumb );
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private function story_terms( $post_id, $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		return ( is_array( $terms ) && $terms ) ? array_values( array_map( 'wp_strip_all_tags', wp_list_pluck( $terms, 'name' ) ) ) : array();
	}

	private function image_object( $url ) {
		$node = array(
			'@type'      => 'ImageObject',
			'url'        => $url,
			'contentUrl' => $url,
		);
		$id   = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
		$meta = $id ? wp_get_attachment_metadata( $id ) : array();
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			$node['width']  = (int) $meta['width'];
			$node['height'] = (int) $meta['height'];
		}
		return $node;
	}

	/** Keep whatever image the Article already has and add the missing poster images after it. */
	private function merge_images( $existing, array $urls ) {
		$list = array();
		if ( is_string( $existing ) && '' !== $existing ) {
			$list[] = $existing;
		} elseif ( is_array( $existing ) && $existing ) {
			$list = ( isset( $existing['@type'] ) || isset( $existing['@id'] ) || isset( $existing['url'] ) ) ? array( $existing ) : array_values( $existing );
		}
		$seen = array();
		foreach ( $list as $item ) {
			$known = is_array( $item ) ? (string) ( isset( $item['url'] ) ? $item['url'] : ( isset( $item['@id'] ) ? $item['@id'] : '' ) ) : (string) $item;
			if ( '' !== $known ) {
				$seen[ $known ] = true;
			}
		}
		foreach ( $urls as $url ) {
			if ( ! isset( $seen[ $url ] ) ) {
				$list[]       = $this->image_object( $url );
				$seen[ $url ] = true;
			}
		}
		return 1 === count( $list ) ? $list[0] : $list;
	}

	/**
	 * Story templates do not run wp_head; they fire web_stories_story_head. Print PublishRank's
	 * head tags and JSON-LD there and switch off the plugin's own metadata to avoid duplicates.
	 */
	public function bridge_story_head() {
		if ( ! $this->active() || ! $this->is_story() || ! (bool) apply_filters( 'publishrank_web_stories_head_bridge', true ) ) {
			return;
		}
		foreach ( array( 'web_stories_enable_metadata', 'web_stories_enable_schemaorg_metadata', 'web_stories_enable_open_graph_metadata', 'web_stories_enable_twitter_metadata' ) as $hook ) {
			add_filter( $hook, '__return_false', 99 );
		}
		remove_action( 'web_stories_story_head', 'rel_canonical' );
		remove_action( 'web_stories_story_head', 'wp_robots', 1 );
		add_action( 'web_stories_story_head', array( $this, 'print_head' ), 1 );
	}

	public function print_head() {
		global $wp_filter;
		if ( empty( $wp_filter['wp_head'] ) ) {
			return;
		}
		$wanted = array( 'PublishRankSEO\\Meta\\HeadOutput', 'PublishRankSEO\\Schema\\GraphOutput' );
		foreach ( $wp_filter['wp_head']->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn = $cb['function'];
				if ( is_array( $fn ) && is_object( $fn[0] ) && in_array( get_class( $fn[0] ), $wanted, true ) ) {
					call_user_func( $fn );
				}
			}
		}
	}
}
