<?php
/**
 * PublishRank RSS feed manager.
 *
 * Builds on WordPress' native feeds (RSS 2.0, Atom, category, tag, author, post type and taxonomy
 * feeds keep their core URLs and templates) and adds:
 *  - item limit and full-text / excerpt mode for feeds only (the reading settings stay untouched);
 *  - a custom channel title and description for the main feed;
 *  - a channel copyright and a publisher image when the site has no site icon;
 *  - configurable lines appended to each item: author, publisher, copyright, categories, custom text
 *    (the existing source link and featured image options are kept in YoastParityFeatures);
 *  - an optional news feed (/feed/news/) with only recent articles;
 *  - discovery links when the theme does not print them, and an optional Cache-Control header.
 * Every value printed into XML is escaped for XML; item HTML goes through wp_kses_post().
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Feeds;

use PublishRankSEO\Core\PublicationProfile;

defined( 'ABSPATH' ) || exit;

final class FeedManager {

	const OPTION    = 'publishrank_feeds';
	const NEWS_FEED = 'news';

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'item_limit'        => 0,
			'content_mode'      => '',
			'title'             => '',
			'description'       => '',
			'copyright'         => '',
			'append_author'     => 0,
			'append_publisher'  => 0,
			'append_copyright'  => 0,
			'append_categories' => 0,
			'custom_text'       => '',
			'news_feed'         => 0,
			'news_hours'        => 48,
			'discovery'         => 1,
			'cache_max_age'     => 0,
			'exclude_noindex'   => 1,
			'exclude_terms'     => '',
			'exclude_posts'     => '',
			'delay_minutes'     => 0,
			'websub'            => 0,
			'hub_url'           => '',
			'json_feed'         => 0,
			'utm'               => 0,
			'utm_campaign'      => '',
		);
	}

	/** @return array<string,mixed> */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** @param array<string,mixed> $in @return array<string,mixed> */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $key => $default ) {
			$value = isset( $in[ $key ] ) ? $in[ $key ] : ( is_int( $default ) && 0 === $default ? 0 : $default );
			switch ( $key ) {
				case 'item_limit':
					$out[ $key ] = max( 0, min( 200, absint( $value ) ) );
					break;
				case 'news_hours':
					$out[ $key ] = max( 1, min( 168, absint( $value ) ) );
					break;
				case 'cache_max_age':
					$out[ $key ] = max( 0, min( 86400, absint( $value ) ) );
					break;
				case 'delay_minutes':
					$out[ $key ] = max( 0, min( 1440, absint( $value ) ) );
					break;
				case 'exclude_terms':
				case 'exclude_posts':
					$out[ $key ] = implode( ',', array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) ) ) ) ) );
					break;
				case 'hub_url':
					$url         = esc_url_raw( trim( (string) $value ), array( 'https' ) );
					$out[ $key ] = ( $url && wp_http_validate_url( $url ) ) ? $url : '';
					break;
				case 'utm_campaign':
					$out[ $key ] = sanitize_title( (string) $value );
					break;
				case 'content_mode':
					$out[ $key ] = in_array( $value, array( 'full', 'excerpt' ), true ) ? $value : '';
					break;
				case 'title':
				case 'copyright':
					$out[ $key ] = sanitize_text_field( (string) $value );
					break;
				case 'description':
				case 'custom_text':
					$out[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				default:
					$out[ $key ] = empty( $value ) ? 0 : 1;
			}
		}
		return $out;
	}

	public function register() {
		$s = self::settings();
		if ( $s['item_limit'] > 0 ) {
			add_filter( 'option_posts_per_rss', array( $this, 'item_limit' ) );
		}
		if ( '' !== $s['content_mode'] ) {
			add_filter( 'option_rss_use_excerpt', array( $this, 'content_mode' ) );
		}
		add_filter( 'get_wp_title_rss', array( $this, 'channel_title' ), 20 );
		add_filter( 'bloginfo_rss', array( $this, 'channel_description' ), 20, 2 );
		add_action( 'rss2_head', array( $this, 'rss2_head' ) );
		add_action( 'atom_head', array( $this, 'atom_head' ) );
		add_filter( 'the_content_feed', array( $this, 'item_lines' ), 30 );
		add_filter( 'the_excerpt_rss', array( $this, 'item_lines' ), 30 );
		add_filter( 'wp_headers', array( $this, 'headers' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'discovery_links' ), 4 );
		if ( $s['news_feed'] ) {
			add_action( 'init', array( $this, 'add_news_feed' ) );
		}
		add_action( 'wp_loaded', array( __CLASS__, 'heal_rewrite_rules' ), 30 );
	}

	/** Whether the stored rewrite rules know the news feed name. */
	public static function rules_have_news_feed() {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) ) {
			return false;
		}
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( false !== strpos( (string) $pattern, 'feed/(' ) && preg_match( '/[(|]' . preg_quote( self::NEWS_FEED, '/' ) . '[|)]/', (string) $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keep /feed/news/ in step with the setting. Rules are flushed on activation before this module's
	 * init hooks have run, so a fresh install or update would otherwise answer 404 until permalinks are saved.
	 * Flushes at most once a minute.
	 */
	public static function heal_rewrite_rules() {
		if ( ! get_option( 'permalink_structure' ) ) {
			return;
		}
		$want = (bool) self::settings()['news_feed'];
		if ( $want === self::rules_have_news_feed() ) {
			return;
		}
		// Guard against a flush loop if something else keeps rewriting the rules: retry at most once a minute.
		if ( get_transient( 'publishrank_feed_rules_healed' ) ) {
			return;
		}
		set_transient( 'publishrank_feed_rules_healed', 1, MINUTE_IN_SECONDS );
		if ( ! $want ) {
			global $wp_rewrite;
			$wp_rewrite->feeds = array_values( array_diff( (array) $wp_rewrite->feeds, array( self::NEWS_FEED ) ) );
		}
		flush_rewrite_rules( false );
	}

	public function item_limit( $value ) {
		$s = self::settings();
		return $s['item_limit'] > 0 ? (int) $s['item_limit'] : $value;
	}

	public function content_mode( $value ) {
		$s = self::settings();
		return 'excerpt' === $s['content_mode'] ? 1 : ( 'full' === $s['content_mode'] ? 0 : $value );
	}

	/** True for the site's main posts feed (not category, tag, author, search or comment feeds). */
	private static function is_main_feed() {
		return is_feed() && ! is_archive() && ! is_search() && ! is_singular() && ! is_comment_feed();
	}

	public function channel_title( $title ) {
		$s = self::settings();
		if ( self::is_main_feed() ) {
			return '' !== $s['title'] ? $s['title'] : self::publisher_name();
		}
		return self::dedupe_title( $title );
	}

	/** "Daily Beacon | Daily Beacon" → "Daily Beacon": never repeat the same segment around a separator. */
	public static function dedupe_title( $title ) {
		$title = (string) $title;
		if ( ! preg_match_all( '/\s+(\||–|—|-|·|•|»|&#8211;|&#8212;|&raquo;|&#124;)\s+/u', $title, $m ) ) {
			return $title;
		}
		$parts = preg_split( '/\s+(?:\||–|—|-|·|•|»|&#8211;|&#8212;|&raquo;|&#124;)\s+/u', $title );
		$sep   = $m[1][0];
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part && ( ! $out || strcasecmp( end( $out ), $part ) !== 0 ) ) {
				$out[] = $part;
			}
		}
		return implode( ' ' . $sep . ' ', $out );
	}

	public function channel_description( $value, $show ) {
		$s = self::settings();
		if ( 'description' === $show && '' !== $s['description'] && self::is_main_feed() ) {
			return convert_chars( esc_html( $s['description'] ) );
		}
		return $value;
	}

	public static function publisher_name() {
		$name = class_exists( PublicationProfile::class ) ? (string) PublicationProfile::publication_name() : '';
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	public static function copyright_text() {
		$s = self::settings();
		if ( '' !== $s['copyright'] ) {
			return str_replace( array( '%%year%%', '%%sitename%%' ), array( gmdate( 'Y' ), self::publisher_name() ), $s['copyright'] );
		}
		/* translators: 1: current year, 2: publication name. */
		return sprintf( __( '© %1$s %2$s. All rights reserved.', 'publishrank' ), gmdate( 'Y' ), self::publisher_name() );
	}

	private static function xml( $text ) {
		return esc_xml( wp_strip_all_tags( (string) $text ) );
	}

	public function rss2_head() {
		$s = self::settings();
		if ( '' !== $s['copyright'] || $s['append_copyright'] ) {
			echo "\t<copyright>" . self::xml( self::copyright_text() ) . "</copyright>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_xml() above.
		}
		if ( ! has_site_icon() ) {
			$logo = (string) get_option( 'publishrank_identity_logo', '' );
			if ( '' !== $logo && wp_http_validate_url( $logo ) ) {
				echo "\t<image>\n\t\t<url>" . esc_url( $logo ) . "</url>\n\t\t<title>" . self::xml( self::publisher_name() ) . "</title>\n\t\t<link>" . esc_url( home_url( '/' ) ) . "</link>\n\t</image>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
	}

	public function atom_head() {
		$s = self::settings();
		if ( '' !== $s['copyright'] || $s['append_copyright'] ) {
			echo "\t<rights>" . self::xml( self::copyright_text() ) . "</rights>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** Configured lines appended once to every feed item. */
	public function item_lines( $content ) {
		if ( ! is_feed() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) || false !== strpos( (string) $content, 'data-publishrank-feed-lines' ) ) {
			return $content;
		}
		$s     = self::settings();
		$lines = array();
		if ( $s['append_author'] ) {
			$author = get_the_author_meta( 'display_name', (int) $post->post_author );
			if ( '' !== (string) $author ) {
				/* translators: %s: author name. */
				$lines[] = sprintf( esc_html__( 'Written by %s.', 'publishrank' ), '<a href="' . esc_url( get_author_posts_url( (int) $post->post_author ) ) . '">' . esc_html( $author ) . '</a>' );
			}
		}
		if ( $s['append_publisher'] ) {
			/* translators: %s: linked publication name. */
			$lines[] = sprintf( esc_html__( 'Published by %s.', 'publishrank' ), '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( self::publisher_name() ) . '</a>' );
		}
		if ( $s['append_categories'] ) {
			$names = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $names ) && $names ) {
				/* translators: %s: comma-separated category names. */
				$lines[] = sprintf( esc_html__( 'Filed under: %s', 'publishrank' ), esc_html( implode( ', ', $names ) ) );
			}
		}
		if ( '' !== $s['custom_text'] ) {
			$lines[] = esc_html( self::replace_vars( $s['custom_text'], $post ) );
		}
		if ( $s['append_copyright'] ) {
			$lines[] = esc_html( self::copyright_text() );
		}
		$lines = (array) apply_filters( 'publishrank_feed_item_lines', $lines, $post );
		if ( ! $lines ) {
			return $content;
		}
		return (string) $content . "\n" . '<p data-publishrank-feed-lines="1">' . wp_kses_post( implode( '<br />', $lines ) ) . '</p>';
	}

	/** Plain-text variables for the custom line. Unknown variables are removed, never printed. */
	public static function replace_vars( $text, $post ) {
		$map = array(
			'%%title%%'     => wp_strip_all_tags( get_the_title( $post ) ),
			'%%permalink%%' => (string) get_permalink( $post ),
			'%%sitename%%'  => self::publisher_name(),
			'%%author%%'    => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'%%date%%'      => (string) get_the_date( '', $post ),
			'%%year%%'      => gmdate( 'Y' ),
		);
		$text = strtr( (string) $text, $map );
		return trim( (string) preg_replace( '/%%[a-z0-9_]+%%/i', '', $text ) );
	}

	/** Optional shared-cache header for feeds; WordPress already answers conditional GETs (ETag / Last-Modified). */
	public function headers( $headers, $wp ) {
		$s = self::settings();
		if ( $s['cache_max_age'] > 0 && is_object( $wp ) && ! empty( $wp->query_vars['feed'] ) && ! is_user_logged_in() ) {
			$headers['Cache-Control'] = 'public, max-age=' . (int) $s['cache_max_age'];
		}
		return $headers;
	}

	/** Discovery links: only when the theme does not print them and Crawl optimization has not removed them on purpose. */
	public function discovery_links() {
		$s = self::settings();
		if ( $s['news_feed'] ) {
			/* translators: %s: publication name. */
			echo '<link rel="alternate" type="application/rss+xml" title="' . esc_attr( sprintf( __( '%s » News feed', 'publishrank' ), self::publisher_name() ) ) . '" href="' . esc_url( self::news_feed_url() ) . '" />' . "\n";
		}
		if ( ! $s['discovery'] || current_theme_supports( 'automatic-feed-links' ) ) {
			return;
		}
		$crawl = get_option( 'publishrank_crawl_optimization', array() );
		if ( is_array( $crawl ) && ( ! empty( $crawl['remove_feed_links'] ) || ! empty( $crawl['remove_legacy_feed_links'] ) ) ) {
			return;
		}
		feed_links();
		feed_links_extra();
	}

	public function add_news_feed() {
		add_feed( self::NEWS_FEED, array( $this, 'render_news_feed' ) );
	}

	public static function news_feed_url() {
		return get_feed_link( self::NEWS_FEED );
	}

	/** RSS 2.0 feed of recent, public, indexable articles (the same window as the News sitemap by default). */
	public function render_news_feed() {
		$s     = self::settings();
		$types = get_option( 'publishrank_seo_news_post_types', array( 'post' ) );
		$types = array_values( array_filter( array_map( 'sanitize_key', is_array( $types ) && $types ? $types : array( 'post' ) ), 'post_type_exists' ) );
		$limit = $s['item_limit'] > 0 ? (int) $s['item_limit'] : (int) get_option( 'posts_per_rss', 10 );
		// phpcs:ignore WordPress.WP.DiscouragedFunctions.query_posts_query_posts -- A feed callback must replace the main query for the core RSS template.
		query_posts(
			array(
				'post_type'           => $types ? $types : array( 'post' ),
				'post_status'         => 'publish',
				'publishrank_feed'    => 1, // Feed exclusions and publish delay apply here too.
				'has_password'        => false,
				'posts_per_page'      => max( 1, min( 100, $limit ) ),
				'ignore_sticky_posts' => true,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'date_query'          => array(
					array(
						'column' => 'post_date_gmt',
						'after'  => gmdate( 'Y-m-d H:i:s', time() - (int) $s['news_hours'] * HOUR_IN_SECONDS ),
					),
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded by date and limit.
				'meta_query'          => array(
					'relation' => 'OR',
					array(
						'key'     => '_publishrank_seo_robots',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_publishrank_seo_robots',
						'value'   => 'noindex',
						'compare' => 'NOT LIKE',
					),
				),
			)
		);
		load_template( ABSPATH . WPINC . '/feed-rss2.php' );
	}

	/**
	 * Every feed endpoint this site exposes, for diagnostics and previews.
	 *
	 * @return array<string,array{label:string,url:string,group:string}>
	 */
	public static function endpoints() {
		$out = array(
			'main'     => array( 'label' => __( 'Main feed (RSS 2.0)', 'publishrank' ), 'url' => get_feed_link(), 'group' => __( 'Site', 'publishrank' ) ),
			'atom'     => array( 'label' => __( 'Main feed (Atom)', 'publishrank' ), 'url' => get_feed_link( 'atom' ), 'group' => __( 'Site', 'publishrank' ) ),
			'comments' => array( 'label' => __( 'Comments feed', 'publishrank' ), 'url' => get_feed_link( 'comments_rss2' ), 'group' => __( 'Site', 'publishrank' ) ),
		);
		$s = self::settings();
		if ( $s['news_feed'] ) {
			$out['news'] = array( 'label' => __( 'News feed (recent articles)', 'publishrank' ), 'url' => self::news_feed_url(), 'group' => __( 'Site', 'publishrank' ) );
		}
		foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'number' => 6, 'orderby' => 'count', 'order' => 'DESC' ) ) as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[ 'cat-' . $term->term_id ] = array( 'label' => $term->name, 'url' => get_category_feed_link( $term->term_id ), 'group' => __( 'Categories', 'publishrank' ) );
			}
		}
		foreach ( get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => true, 'number' => 6, 'orderby' => 'count', 'order' => 'DESC' ) ) as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[ 'tag-' . $term->term_id ] = array( 'label' => $term->name, 'url' => get_tag_feed_link( $term->term_id ), 'group' => __( 'Tags', 'publishrank' ) );
			}
		}
		foreach ( get_users( array( 'has_published_posts' => array( 'post' ), 'number' => 6, 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
			$out[ 'author-' . $user->ID ] = array( 'label' => $user->display_name, 'url' => get_author_feed_link( (int) $user->ID ), 'group' => __( 'Authors', 'publishrank' ) );
		}
		foreach ( get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' ) as $type ) {
			if ( $type->has_archive ) {
				$url = get_post_type_archive_feed_link( $type->name );
				if ( $url ) {
					$out[ 'type-' . $type->name ] = array( 'label' => $type->labels->name, 'url' => $url, 'group' => __( 'Post types', 'publishrank' ) );
				}
			}
		}
		foreach ( get_taxonomies( array( 'public' => true, '_builtin' => false ), 'objects' ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => true, 'number' => 1 ) );
			if ( is_array( $terms ) && $terms && $terms[0] instanceof \WP_Term ) {
				$out[ 'tax-' . $tax->name ] = array( 'label' => $tax->labels->singular_name . ': ' . $terms[0]->name, 'url' => get_term_feed_link( $terms[0]->term_id, $tax->name ), 'group' => __( 'Taxonomies', 'publishrank' ) );
			}
		}
		return $out;
	}

	/**
	 * Fetch a feed the way a feed reader would and validate it.
	 *
	 * @return array{ok:bool,status:int,type:string,format:string,errors:string[],warnings:string[],channel:array,items:array,raw:string,ms:int}
	 */
	public static function diagnose( $url ) {
		$started = microtime( true );
		$result  = array(
			'ok'       => false,
			'status'   => 0,
			'type'     => '',
			'format'   => '',
			'errors'   => array(),
			'warnings' => array(),
			'channel'  => array(),
			'items'    => array(),
			'raw'      => '',
			'ms'       => 0,
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.1' ),
				'user-agent'  => 'PublishRank Feed Diagnostics; ' . home_url( '/' ),
			)
		);
		$result['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $response ) ) {
			$result['errors'][] = sprintf( /* translators: %s: error message. */ __( 'The feed could not be fetched from this server: %s', 'publishrank' ), $response->get_error_message() );
			return $result;
		}
		$result['status'] = (int) wp_remote_retrieve_response_code( $response );
		$result['type']   = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body             = (string) wp_remote_retrieve_body( $response );
		$result['raw']    = substr( $body, 0, 20000 );
		return self::validate_xml( $body, $result );
	}

	/** Validate feed XML. Public so tests can run it without a network request. */
	public static function validate_xml( $body, array $result = array() ) {
		$result = array_merge(
			array( 'ok' => false, 'status' => 200, 'type' => 'application/rss+xml', 'format' => '', 'errors' => array(), 'warnings' => array(), 'channel' => array(), 'items' => array(), 'raw' => '', 'ms' => 0 ),
			$result
		);
		if ( 200 !== $result['status'] ) {
			/* translators: %d: HTTP status code. */
			$result['errors'][] = sprintf( __( 'The feed answered with HTTP status %d instead of 200.', 'publishrank' ), $result['status'] );
		}
		if ( '' !== $result['type'] && false === stripos( $result['type'], 'xml' ) ) {
			/* translators: %s: content type. */
			$result['errors'][] = sprintf( __( 'The content type is "%s"; feed readers expect an XML type such as application/rss+xml.', 'publishrank' ), $result['type'] );
		}
		if ( preg_match( '#<b>(?:Warning|Notice|Deprecated|Fatal error)</b>|(?:^|\n)(?:PHP )?(?:Warning|Notice|Deprecated): #', $body ) ) {
			$result['errors'][] = __( 'PHP warnings or notices are printed inside the feed. Turn off WP_DEBUG_DISPLAY on production sites and fix the reported plugin or theme.', 'publishrank' );
		}
		$trimmed = ltrim( $body, "\xEF\xBB\xBF" );
		if ( '' === trim( $trimmed ) ) {
			$result['errors'][] = __( 'The feed is empty.', 'publishrank' );
			return $result;
		}
		if ( 0 !== strpos( $trimmed, '<' ) ) {
			$result['errors'][] = __( 'There is text or whitespace before the XML declaration. This usually comes from a blank line in a theme or plugin file and makes the feed invalid.', 'publishrank' );
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$ok   = $dom->loadXML( trim( $trimmed ), LIBXML_NONET | LIBXML_NOCDATA );
		foreach ( libxml_get_errors() as $error ) {
			if ( count( $result['errors'] ) < 12 ) {
				/* translators: 1: line number, 2: XML parser message. */
				$result['errors'][] = sprintf( __( 'XML error on line %1$d: %2$s', 'publishrank' ), (int) $error->line, trim( (string) $error->message ) );
			}
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || ! $dom->documentElement ) {
			return $result;
		}
		$root = $dom->documentElement;
		if ( 'rss' === $root->nodeName ) {
			$result['format'] = 'RSS ' . $root->getAttribute( 'version' );
			if ( '2.0' !== $root->getAttribute( 'version' ) ) {
				$result['warnings'][] = __( 'The rss element should declare version="2.0".', 'publishrank' );
			}
			$channel = $root->getElementsByTagName( 'channel' )->item( 0 );
			if ( ! $channel ) {
				$result['errors'][] = __( 'The RSS feed has no channel element.', 'publishrank' );
				return $result;
			}
			$result['channel'] = array(
				'title'       => self::child( $channel, 'title' ),
				'link'        => self::child( $channel, 'link' ),
				'description' => self::child( $channel, 'description' ),
				'copyright'   => self::child( $channel, 'copyright' ),
				'language'    => self::child( $channel, 'language' ),
				'updated'     => self::child( $channel, 'lastBuildDate' ),
			);
			foreach ( array( 'title', 'link', 'description' ) as $req ) {
				if ( '' === $result['channel'][ $req ] && 'description' !== $req ) {
					/* translators: %s: element name. */
					$result['errors'][] = sprintf( __( 'The channel has an empty <%s> element.', 'publishrank' ), $req );
				}
			}
			$guids = array();
			foreach ( $channel->getElementsByTagName( 'item' ) as $i => $item ) {
				$row = array(
					'title'   => self::child( $item, 'title' ),
					'link'    => self::child( $item, 'link' ),
					'date'    => self::child( $item, 'pubDate' ),
					'author'  => self::child_ns( $item, 'http://purl.org/dc/elements/1.1/', 'creator' ),
					'summary' => wp_trim_words( wp_strip_all_tags( self::child( $item, 'description' ) ), 32 ),
					'image'   => '',
				);
				$media = $item->getElementsByTagNameNS( 'http://search.yahoo.com/mrss/', 'content' )->item( 0 );
				if ( $media instanceof \DOMElement ) {
					$row['image'] = $media->getAttribute( 'url' );
				} elseif ( ( $enc = $item->getElementsByTagName( 'enclosure' )->item( 0 ) ) instanceof \DOMElement && 0 === strpos( $enc->getAttribute( 'type' ), 'image/' ) ) {
					$row['image'] = $enc->getAttribute( 'url' );
				}
				$guid = self::child( $item, 'guid' );
				if ( '' === $row['title'] && '' === $row['summary'] ) {
					/* translators: %d: item number. */
					$result['errors'][] = sprintf( __( 'Item %d has neither a title nor a description.', 'publishrank' ), $i + 1 );
				}
				if ( '' !== $row['date'] && false === \DateTime::createFromFormat( \DateTime::RSS, $row['date'] ) ) {
					/* translators: 1: item number, 2: date value. */
					$result['errors'][] = sprintf( __( 'Item %1$d has an invalid pubDate (%2$s). RSS requires RFC 822 dates.', 'publishrank' ), $i + 1, $row['date'] );
				}
				if ( '' !== $guid ) {
					if ( isset( $guids[ $guid ] ) ) {
						/* translators: %s: guid value. */
						$result['warnings'][] = sprintf( __( 'Duplicate guid %s: readers may hide one of the items.', 'publishrank' ), $guid );
					}
					$guids[ $guid ] = true;
				}
				if ( count( $result['items'] ) < 50 ) {
					$result['items'][] = $row;
				}
			}
		} elseif ( 'feed' === $root->localName ) {
			$result['format']  = 'Atom 1.0';
			$result['channel'] = array(
				'title'       => self::child( $root, 'title' ),
				'link'        => '',
				'description' => self::child( $root, 'subtitle' ),
				'copyright'   => self::child( $root, 'rights' ),
				'language'    => $root->getAttribute( 'xml:lang' ),
				'updated'     => self::child( $root, 'updated' ),
			);
			foreach ( $root->getElementsByTagName( 'entry' ) as $entry ) {
				$link = $entry->getElementsByTagName( 'link' )->item( 0 );
				$author = $entry->getElementsByTagName( 'author' )->item( 0 );
				$result['items'][] = array(
					'title'   => self::child( $entry, 'title' ),
					'link'    => $link instanceof \DOMElement ? $link->getAttribute( 'href' ) : '',
					'date'    => self::child( $entry, 'published' ),
					'author'  => $author ? self::child( $author, 'name' ) : '',
					'summary' => wp_trim_words( wp_strip_all_tags( self::child( $entry, 'summary' ) ), 32 ),
					'image'   => '',
				);
			}
		} else {
			/* translators: %s: root element name. */
			$result['errors'][] = sprintf( __( 'The document root is <%s>, not an RSS or Atom feed.', 'publishrank' ), $root->nodeName );
			return $result;
		}
		if ( ! $result['items'] ) {
			$result['warnings'][] = __( 'The feed is valid but has no items yet.', 'publishrank' );
		}
		$result['ok'] = ! $result['errors'];
		return $result;
	}

	private static function child( \DOMElement $parent, $name ) {
		foreach ( $parent->childNodes as $node ) {
			if ( $node instanceof \DOMElement && $node->nodeName === $name ) {
				return trim( (string) $node->textContent );
			}
		}
		return '';
	}

	private static function child_ns( \DOMElement $parent, $ns, $name ) {
		$node = $parent->getElementsByTagNameNS( $ns, $name )->item( 0 );
		return $node ? trim( (string) $node->textContent ) : '';
	}
}
