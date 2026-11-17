<?php
/**
 * Advanced feed features (settings live in the FeedManager option):
 *  - keep noindex, excluded posts and excluded categories/tags out of every feed;
 *  - publish delay: new items appear in feeds N minutes after publication (scraper protection);
 *  - WebSub (PubSubHubbub): hub discovery link + instant hub ping when a post is published or updated;
 *  - JSON Feed 1.1 at /feed/json/ with discovery link;
 *  - optional campaign tagging (utm_*) of item links, GUIDs untouched.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Feeds;

defined( 'ABSPATH' ) || exit;

final class FeedEnhancer {

	const JSON_FEED   = 'json';
	const DEFAULT_HUB = 'https://pubsubhubbub.appspot.com/';

	public function register() {
		$s = FeedManager::settings();
		add_action( 'pre_get_posts', array( $this, 'filter_feed_query' ), 20 );
		if ( ! empty( $s['websub'] ) ) {
			add_action( 'rss2_head', array( $this, 'hub_link' ), 5 );
			add_action( 'atom_head', array( $this, 'hub_link' ), 5 );
			add_action( 'transition_post_status', array( $this, 'maybe_ping' ), 30, 3 );
			add_action( 'publishrank_websub_ping', array( __CLASS__, 'ping' ) );
		}
		if ( ! empty( $s['json_feed'] ) ) {
			add_action( 'init', array( $this, 'add_json_feed' ) );
			add_action( 'wp_head', array( $this, 'json_discovery' ), 5 );
		}
		if ( ! empty( $s['utm'] ) ) {
			add_filter( 'the_permalink_rss', array( $this, 'tag_link' ), 20 );
		}
	}

	/* ---------------------------------------------------------------- Query rules */

	/** @return int[] */
	private static function id_list( $raw ) {
		return array_values( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) ) ) );
	}

	/** Apply exclusions and the publish delay to every feed query (main, category, tag, author, news, JSON). */
	public function filter_feed_query( $query ) {
		if ( ! $query instanceof \WP_Query || ( ! $query->is_feed() && ! $query->get( 'publishrank_feed' ) ) || is_admin() ) {
			return;
		}
		self::apply_rules( $query );
	}

	public static function apply_rules( \WP_Query $query ) {
		$s = FeedManager::settings();
		if ( ! empty( $s['exclude_noindex'] ) ) {
			$meta   = (array) $query->get( 'meta_query' );
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => '_publishrank_seo_robots', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_publishrank_seo_robots', 'value' => 'noindex', 'compare' => 'NOT LIKE' ),
			);
			$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$posts = self::id_list( $s['exclude_posts'] );
		if ( $posts ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $posts ) );
		}
		$terms = self::id_list( $s['exclude_terms'] );
		if ( $terms ) {
			$tax = (array) $query->get( 'tax_query' );
			foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
				$ids = array();
				foreach ( $terms as $id ) {
					$t = get_term( $id );
					if ( $t && ! is_wp_error( $t ) && $taxonomy === $t->taxonomy ) {
						$ids[] = $id; }
				}
				if ( $ids ) {
					$tax[] = array( 'taxonomy' => $taxonomy, 'terms' => $ids, 'operator' => 'NOT IN' ); }
			}
			$query->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		$delay = (int) $s['delay_minutes'];
		if ( $delay > 0 ) {
			$dq   = (array) $query->get( 'date_query' );
			$dq[] = array( 'column' => 'post_date_gmt', 'before' => gmdate( 'Y-m-d H:i:s', time() - $delay * MINUTE_IN_SECONDS ) );
			$query->set( 'date_query', $dq );
		}
	}

	/* ---------------------------------------------------------------- WebSub */

	public static function hub() {
		$s   = FeedManager::settings();
		$hub = trim( (string) $s['hub_url'] );
		return '' !== $hub ? $hub : self::DEFAULT_HUB;
	}

	public function hub_link() {
		echo "\t" . '<atom:link rel="hub" href="' . esc_url( self::hub() ) . '" />' . "\n";
	}

	/** Queue one ping a few seconds after publish/update of a public post (delay setting is respected). */
	public function maybe_ping( $new, $old, $post ) {
		if ( 'publish' !== $new || ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) || '' !== (string) $post->post_password || wp_is_post_revision( $post ) ) {
			return;
		}
		$s     = FeedManager::settings();
		$delay = max( 5, (int) $s['delay_minutes'] * MINUTE_IN_SECONDS + 5 );
		if ( ! wp_next_scheduled( 'publishrank_websub_ping' ) ) {
			wp_schedule_single_event( time() + $delay, 'publishrank_websub_ping' );
		}
	}

	/** @return array<string,int|string> topic => HTTP status or error */
	public static function ping() {
		$s      = FeedManager::settings();
		$topics = array( get_feed_link( 'rss2' ), get_feed_link( 'atom' ) );
		if ( ! empty( $s['news_feed'] ) ) {
			$topics[] = FeedManager::news_feed_url(); }
		if ( ! empty( $s['json_feed'] ) ) {
			$topics[] = get_feed_link( self::JSON_FEED ); }
		$out = array();
		foreach ( array_unique( $topics ) as $topic ) {
			$r             = wp_safe_remote_post(
				self::hub(),
				array(
					'timeout' => 8,
					'body'    => array( 'hub.mode' => 'publish', 'hub.url' => $topic ),
				)
			);
			$out[ $topic ] = is_wp_error( $r ) ? $r->get_error_message() : (int) wp_remote_retrieve_response_code( $r );
		}
		update_option( 'publishrank_websub_last', array( 'time' => time(), 'results' => $out ), false );
		return $out;
	}

	/* ---------------------------------------------------------------- JSON Feed */

	public function add_json_feed() {
		add_feed( self::JSON_FEED, array( $this, 'render_json_feed' ) );
	}

	public function json_discovery() {
		/* translators: %s: site name */
		echo '<link rel="alternate" type="application/feed+json" title="' . esc_attr( sprintf( __( '%s » JSON Feed', 'publishrank' ), FeedManager::publisher_name() ) ) . '" href="' . esc_url( get_feed_link( self::JSON_FEED ) ) . '" />' . "\n";
	}

	/** JSON Feed 1.1 (https://jsonfeed.org/version/1.1) built from the current feed query. */
	public function render_json_feed() {
		global $wp_query;
		$s     = FeedManager::settings();
		$limit = $s['item_limit'] > 0 ? (int) $s['item_limit'] : (int) get_option( 'posts_per_rss', 10 );
		$q             = new \WP_Query();
		$q->query_vars = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => max( 1, min( 100, $limit ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		self::apply_rules( $q );
		$q->get_posts();
		$excerpt_only = 'excerpt' === $s['content_mode'] || ( '' === $s['content_mode'] && get_option( 'rss_use_excerpt' ) );
		$items        = array();
		foreach ( $q->posts as $post ) {
			$link = get_permalink( $post );
			if ( ! empty( $s['utm'] ) ) {
				$link = $this->tag_link( $link ); }
			$item  = array(
				'id'             => get_the_guid( $post ),
				'url'            => $link,
				'title'          => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'summary'        => html_entity_decode( wp_strip_all_tags( get_the_excerpt( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'date_published' => get_post_time( DATE_RFC3339, true, $post ),
				'date_modified'  => get_post_modified_time( DATE_RFC3339, true, $post ),
				'authors'        => array( array( 'name' => get_the_author_meta( 'display_name', (int) $post->post_author ), 'url' => get_author_posts_url( (int) $post->post_author ) ) ),
				'tags'           => array_values( array_map( 'html_entity_decode', wp_list_pluck( (array) get_the_terms( $post, 'post_tag' ) ?: array(), 'name' ) ) ),
			);
			if ( $excerpt_only ) {
				$item['content_text'] = $item['summary'];
			} else {
				$item['content_html'] = wp_kses_post( apply_filters( 'the_content_feed', apply_filters( 'the_content', $post->post_content ), 'json' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			}
			$img = get_the_post_thumbnail_url( $post, 'full' );
			if ( $img ) {
				$item['image'] = $img; }
			$items[] = $item;
		}
		$feed = array(
			'version'       => 'https://jsonfeed.org/version/1.1',
			'title'         => html_entity_decode( get_wp_title_rss(), ENT_QUOTES, 'UTF-8' ),
			'home_page_url' => home_url( '/' ),
			'feed_url'      => get_feed_link( self::JSON_FEED ),
			'description'   => html_entity_decode( get_bloginfo_rss( 'description' ), ENT_QUOTES, 'UTF-8' ),
			'language'      => str_replace( '_', '-', get_locale() ),
			'authors'       => array( array( 'name' => FeedManager::publisher_name(), 'url' => home_url( '/' ) ) ),
			'items'         => $items,
		);
		$icon = get_site_icon_url( 512 );
		if ( $icon ) {
			$feed['icon'] = $icon; }
		if ( ! empty( $s['websub'] ) ) {
			$feed['hubs'] = array( array( 'type' => 'WebSub', 'url' => self::hub() ) ); }
		// Conditional GET (ETag / Last-Modified / 304) is handled by WordPress core for every feed, this one included.
		header( 'Content-Type: application/feed+json; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo (string) wp_json_encode( $feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded.
	}

	/* ---------------------------------------------------------------- Link tagging */

	public function tag_link( $link ) {
		$s        = FeedManager::settings();
		$campaign = '' !== trim( (string) $s['utm_campaign'] ) ? sanitize_title( $s['utm_campaign'] ) : 'feed';
		return add_query_arg( array( 'utm_source' => 'rss', 'utm_medium' => 'feed', 'utm_campaign' => $campaign ), $link );
	}
}
