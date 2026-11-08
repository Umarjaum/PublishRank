<?php
/**
 * bbPress support: forum/topic sitemap, DiscussionForumPosting schema, crawl-waste robots rules.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Integrations;

defined( 'ABSPATH' ) || exit;

final class BbPressIntegration extends IntegrationBase {
	protected $module = 'bbpress';

	public function detected() {
		return class_exists( 'bbPress' ) || function_exists( 'bbpress' );
	}

	public function register() {
		add_filter( 'publishrank_sitemap_providers', array( $this, 'sitemap' ) );
		add_filter( 'publishrank_sitemap_cpt_excluded', array( $this, 'exclude_cpt' ) );
		add_filter( 'publishrank_sitemap_type_labels', array( $this, 'labels' ) );
		add_filter( 'publishrank_seo_robots', array( $this, 'robots' ), 20 );
		add_filter( 'publishrank_schema_graph', array( $this, 'schema' ), 20 );
		add_filter( 'publishrank_seo_meta_box_post_types', array( $this, 'meta_box_types' ) );
	}

	private function type( $kind ) {
		$fn = 'bbp_get_' . $kind . '_post_type';
		return function_exists( $fn ) ? $fn() : $kind;
	}

	public function sitemap( $providers ) {
		$providers[] = new PostTypeSitemapProvider( 'forums', __( 'Forums', 'publishrank' ), array( $this->type( 'forum' ), $this->type( 'topic' ) ), array( $this, 'active' ), array( 'publish', 'closed' ), array( $this, 'is_public_item' ) );
		return $providers;
	}

	/**
	 * Whether a forum or topic sits in a public forum. Topics of private and hidden forums keep the
	 * "publish" status, so the forum has to be checked or they would leak into the sitemap.
	 */
	public function is_public_item( $post_id ) {
		$post_id = (int) $post_id;
		$forum_id = $post_id;
		if ( get_post_type( $post_id ) !== $this->type( 'forum' ) ) {
			$forum_id = function_exists( 'bbp_get_topic_forum_id' ) ? (int) bbp_get_topic_forum_id( $post_id ) : 0;
		}
		if ( ! $forum_id ) {
			return true;
		}
		if ( function_exists( 'bbp_is_forum_public' ) ) {
			return (bool) bbp_is_forum_public( $forum_id );
		}
		return 'publish' === get_post_status( $forum_id );
	}

	/** Forums and topics get their own sitemap; replies are never listed (they live on the topic URL). */
	public function exclude_cpt( $excluded ) {
		if ( $this->active() ) {
			$excluded = array_merge( (array) $excluded, array( $this->type( 'forum' ), $this->type( 'topic' ), $this->type( 'reply' ) ) );
		}
		return $excluded;
	}

	public function labels( $types ) {
		$types['forums'] = array(
			'label' => __( 'Forums', 'publishrank' ),
			'desc'  => __( 'bbPress forums and discussion topics', 'publishrank' ),
			'abbr'  => 'FO',
		);
		return $types;
	}

	public function meta_box_types( $types ) {
		if ( $this->active() ) {
			$types[] = $this->type( 'forum' );
			$types[] = $this->type( 'topic' );
		}
		return $types;
	}

	/**
	 * Thin or duplicate bbPress views: single replies, user profile sub-pages, forum search,
	 * topic tag archives with one topic, views and edit screens.
	 */
	public function robots( $robots ) {
		if ( ! $this->active() || ! function_exists( 'is_bbpress' ) || ! is_bbpress() ) {
			return $robots;
		}
		$thin = ( function_exists( 'bbp_is_single_reply' ) && bbp_is_single_reply() )
			|| ( function_exists( 'bbp_is_search' ) && bbp_is_search() )
			|| ( function_exists( 'bbp_is_single_view' ) && bbp_is_single_view() )
			|| ( function_exists( 'bbp_is_single_user' ) && bbp_is_single_user() && ! ( function_exists( 'bbp_is_single_user_profile' ) && bbp_is_single_user_profile() ) )
			|| ( function_exists( 'bbp_is_topic_edit' ) && bbp_is_topic_edit() )
			|| ( function_exists( 'bbp_is_reply_edit' ) && bbp_is_reply_edit() )
			|| ( function_exists( 'bbp_is_forum_edit' ) && bbp_is_forum_edit() );
		if ( $thin ) {
			return self::robots_add( $robots, array( 'noindex', 'follow' ) );
		}
		// Topic tags with a single topic are thin pages.
		if ( function_exists( 'bbp_is_topic_tag' ) && bbp_is_topic_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term && (int) $term->count < (int) apply_filters( 'publishrank_bbpress_min_tag_topics', 2 ) ) {
				return self::robots_add( $robots, array( 'noindex', 'follow' ) );
			}
		}
		// Forums and topics inside private or hidden forums are never indexable.
		if ( ( ( function_exists( 'bbp_is_single_forum' ) && bbp_is_single_forum() ) || ( function_exists( 'bbp_is_single_topic' ) && bbp_is_single_topic() ) ) && ! $this->is_public_item( get_queried_object_id() ) ) {
			return self::robots_add( $robots, array( 'noindex', 'nofollow' ) );
		}
		// Empty topics (no replies yet) stay indexable but private/closed/spam never.
		if ( function_exists( 'bbp_is_single_topic' ) && bbp_is_single_topic() && function_exists( 'bbp_get_topic_status' ) ) {
			$status = bbp_get_topic_status( get_queried_object_id() );
			if ( in_array( $status, array( 'spam', 'trash', 'pending' ), true ) ) {
				return self::robots_add( $robots, array( 'noindex', 'nofollow' ) );
			}
		}
		return $robots;
	}

	/** DiscussionForumPosting for topics (Google "Discussion forum" structured data). */
	public function schema( $graph ) {
		if ( ! $this->active() || ! function_exists( 'bbp_is_single_topic' ) || ! bbp_is_single_topic() ) {
			return $graph;
		}
		$topic_id = (int) get_queried_object_id();
		$topic    = get_post( $topic_id );
		if ( ! $topic ) {
			return $graph;
		}
		$url  = get_permalink( $topic_id );
		$node = array(
			'@type'         => 'DiscussionForumPosting',
			'@id'           => trailingslashit( $url ) . '#discussion',
			'url'           => $url,
			'mainEntityOfPage' => array( '@id' => trailingslashit( $this->canonical() ) . '#webpage' ),
			'headline'      => wp_strip_all_tags( get_the_title( $topic ) ),
			'text'          => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $topic->post_content ) ), 120, '…' ),
			'datePublished' => get_post_time( DATE_W3C, true, $topic ),
			'dateModified'  => get_post_modified_time( DATE_W3C, true, $topic ),
			'author'        => $this->person( $topic->post_author ),
		);
		$forum_id = function_exists( 'bbp_get_topic_forum_id' ) ? (int) bbp_get_topic_forum_id( $topic_id ) : 0;
		if ( $forum_id ) {
			$node['isPartOf'] = array( '@type' => 'WebPage', 'url' => get_permalink( $forum_id ), 'name' => wp_strip_all_tags( get_the_title( $forum_id ) ) );
		}
		$reply_count = function_exists( 'bbp_get_topic_reply_count' ) ? (int) bbp_get_topic_reply_count( $topic_id, true ) : 0;
		$node['interactionStatistic'] = array(
			'@type'                => 'InteractionCounter',
			'interactionType'      => 'https://schema.org/CommentAction',
			'userInteractionCount' => $reply_count,
		);
		$replies = get_posts(
			array(
				'post_type'      => $this->type( 'reply' ),
				'post_parent'    => $topic_id,
				'post_status'    => 'publish',
				'posts_per_page' => (int) apply_filters( 'publishrank_bbpress_schema_reply_limit', 20 ),
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		$comments = array();
		foreach ( $replies as $reply ) {
			$comments[] = array(
				'@type'         => 'Comment',
				'url'           => function_exists( 'bbp_get_reply_url' ) ? bbp_get_reply_url( $reply->ID ) : get_permalink( $reply ),
				'text'          => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $reply->post_content ) ), 80, '…' ),
				'datePublished' => get_post_time( DATE_W3C, true, $reply ),
				'author'        => $this->person( $reply->post_author ),
			);
		}
		if ( $comments ) {
			$node['comment'] = $comments;
		}
		$graph[] = $node;
		return $graph;
	}

	private function person( $user_id ) {
		$user_id = (int) $user_id;
		$name    = $user_id ? get_the_author_meta( 'display_name', $user_id ) : '';
		$person  = array( '@type' => 'Person', 'name' => $name ? $name : __( 'Anonymous', 'publishrank' ) );
		if ( $user_id && function_exists( 'bbp_get_user_profile_url' ) ) {
			$person['url'] = bbp_get_user_profile_url( $user_id );
		}
		return $person;
	}
}
