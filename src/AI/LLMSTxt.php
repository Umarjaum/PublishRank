<?php
/**
 * Serve the site's llms.txt (curated map) and llms-full.txt (article text) for AI agents.
 *
 * The concise file follows the llms.txt layout: an H1 title, a blockquote summary, optional context, H2 link lists and a
 * final "Optional" section for secondary material. Everything published is public content that WordPress would show any
 * visitor: password-protected, non-public and noindex posts are never listed.
 */
namespace PublishRankSEO\AI;

use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Robots\RobotsTxt;
use PublishRankSEO\Sitemap\SitemapUrls;

defined( 'ABSPATH' ) || exit;

final class LLMSTxt {
	const OPTION_ENABLED      = 'publishrank_llms_txt_enabled';
	const OPTION_TITLE        = 'publishrank_llms_txt_title';
	const OPTION_SUMMARY      = 'publishrank_llms_txt_summary';
	const OPTION_INTRO        = 'publishrank_llms_txt_intro';
	const OPTION_HOME         = 'publishrank_llms_txt_home';
	const OPTION_POSTS        = 'publishrank_llms_txt_posts';
	const OPTION_PAGES        = 'publishrank_llms_txt_pages';
	const OPTION_CATEGORIES   = 'publishrank_llms_txt_categories';
	const OPTION_POST_COUNT   = 'publishrank_llms_txt_post_count';
	const OPTION_CUSTOM       = 'publishrank_llms_txt_custom';
	const OPTION_FULL_ENABLED = 'publishrank_llms_full_enabled';
	const OPTION_FULL_WORDS   = 'publishrank_llms_full_words';
	/** Added in 2.9.51. */
	const OPTION_POLICY        = 'publishrank_llms_txt_policy';
	const OPTION_AUTHORS       = 'publishrank_llms_txt_authors';
	const OPTION_TAGS          = 'publishrank_llms_txt_tags';
	const OPTION_CPT           = 'publishrank_llms_txt_cpt';
	const OPTION_RESOURCES     = 'publishrank_llms_txt_resources';
	const OPTION_OPTIONAL      = 'publishrank_llms_txt_optional';
	const OPTION_FULL_PER_POST = 'publishrank_llms_full_per_post';
	const OPTION_VERSION       = 'publishrank_llms_cache_ver';
	const OPTION_OVERRIDE      = 'publishrank_llms_txt_override';
	const OPTION_OVERRIDE_TEXT = 'publishrank_llms_txt_override_text';
	/** Slug fragments that identify the pages that show who is behind a publication. */
	const POLICY_PATTERN = '/(^|-)(about|contact|editorial|ethics|corrections?|fact-?check\w*|standards|masthead|team|staff|ownership|funding|advertis\w*|tips|submit|diversity|trust|privacy|terms)(-|$)/';

	public function register() {
		add_action( 'init', array( $this, 'rewrite' ), 20 );
		// Flush once after the rules have been registered so /llms.txt works immediately
		// after activation or upgrade, without flushing on every request.
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'match_request' ), 0 );
		add_action( 'template_redirect', array( $this, 'serve' ), 1 );
		add_action( 'wp_head', array( $this, 'head_link' ), 1 );
		// Any change to content, terms, authors or the llms settings retires the cached files.
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'edited_term', 'created_term', 'delete_term', 'profile_update', 'user_register', 'delete_user', 'update_option_blogname', 'update_option_blogdescription' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache' ) );
		}
		add_action( 'updated_option', array( $this, 'maybe_flush_for_option' ), 10, 1 );
		add_action( 'added_option', array( $this, 'maybe_flush_for_option' ), 10, 1 );
	}

	public function maybe_flush_for_option( $name ) {
		if ( is_string( $name ) && 0 === strpos( $name, 'publishrank_llms_' ) && self::OPTION_VERSION !== $name ) {
			self::flush_cache(); }
	}

	public static function flush_cache() {
		update_option( self::OPTION_VERSION, time() . wp_rand( 100, 999 ), false ); }

	public function rewrite() {
		add_rewrite_rule( '^llms\.txt/?$', 'index.php?publishrank_llms=1', 'top' );
		add_rewrite_rule( '^llms-full\.txt/?$', 'index.php?publishrank_llms_full=1', 'top' );
	}

	/** Safety net: answer /llms.txt even when the stored rewrite rules are stale or the site uses plain permalinks. */
	public function match_request( $wp ) {
		if ( ! is_object( $wp ) || empty( $_SERVER['REQUEST_URI'] ) || ! empty( $wp->query_vars['publishrank_llms'] ) || ! empty( $wp->query_vars['publishrank_llms_full'] ) ) {
			return; }
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) ); }
		$path = trim( rawurldecode( $path ), '/' );
		if ( 'llms.txt' === $path ) {
			$wp->query_vars['publishrank_llms'] = '1'; } elseif ( 'llms-full.txt' === $path ) {
			$wp->query_vars['publishrank_llms_full'] = '1'; }
	}

	public function maybe_flush_rewrite_rules() {
		$rules     = get_option( 'rewrite_rules', array() );
		$has_rules = is_array( $rules ) && isset( $rules['^llms\\.txt/?$'], $rules['^llms-full\\.txt/?$'] );
		if ( '1' === (string) get_option( 'publishrank_llms_rewrite_ready', '' ) && $has_rules ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'publishrank_llms_rewrite_ready', '1', false );
	}

	public function query_vars( $vars ) {
		$vars[] = 'publishrank_llms';
		$vars[] = 'publishrank_llms_full';
		return $vars;
	}

	public function head_link() {
		if ( is_admin() || wp_doing_ajax() || ! get_option( self::OPTION_ENABLED, 1 ) ) {
			return;
		}
		$url = home_url( '/llms.txt' );
		// rel=alternate provides machine-readable discovery; the meta names below are
		// PublishRank-specific metadata, not a claim of a universal llms.txt standard.
		echo '<link rel="alternate" type="text/plain" title="llms.txt" href="' . esc_url( $url ) . '" />' . "\n";
		echo '<link rel="describedby" href="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta name="publishrank:llms-txt" content="' . esc_url( $url ) . '" />' . "\n";
		if ( get_option( self::OPTION_FULL_ENABLED, 0 ) ) {
			$full_url = home_url( '/llms-full.txt' );
			echo '<link rel="alternate" type="text/plain" title="llms-full.txt" href="' . esc_url( $full_url ) . '" />' . "\n";
			echo '<meta name="publishrank:llms-full-txt" content="' . esc_url( $full_url ) . '" />' . "\n";
		}
	}

	public function serve() {
		$full = (bool) get_query_var( 'publishrank_llms_full' );
		$root = (bool) get_query_var( 'publishrank_llms' );
		if ( ! $root && ! $full ) {
			return;
		}
		remove_action( 'template_redirect', 'redirect_canonical' );
		if ( ! get_option( self::OPTION_ENABLED, 1 ) || ( $full && ! get_option( self::OPTION_FULL_ENABLED, 0 ) ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo "PublishRank llms.txt is disabled.\n";
			exit;
		}
		if ( ! $full && get_option( self::OPTION_OVERRIDE, 0 ) && trim( (string) get_option( self::OPTION_OVERRIDE_TEXT, '' ) ) !== '' ) { $body = trim( (string) get_option( self::OPTION_OVERRIDE_TEXT, '' ) ) . "\n"; } else { $body = $this->cached( $full ? 'full' : 'root' ); }
		$etag = '"' . md5( $body ) . '"';
		$mod  = $this->last_modified();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'ETag: ' . $etag );
		if ( $mod ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mod ) . ' GMT' ); }
		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
		if ( '' !== $inm && $inm === $etag ) {
			status_header( 304 );
			exit; }
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text Markdown resource.
		exit;
	}

	/** Build once per content change instead of querying on every crawler visit. */
	private function cached( $kind ) {
		$key = 'publishrank_llms_' . $kind . '_' . md5( (string) get_option( self::OPTION_VERSION, '0' ) );
		$hit = get_transient( $key );
		if ( is_string( $hit ) && '' !== $hit ) {
			return $hit; }
		$text = 'full' === $kind ? $this->build_full() : $this->build();
		set_transient( $key, $text, HOUR_IN_SECONDS );
		return $text;
	}

	private function last_modified() {
		$q = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'has_password'   => false,
			)
		);
		return $q ? (int) get_post_modified_time( 'U', true, (int) $q[0] ) : 0;
	}

	/* ------------------------------------------------------------------ concise llms.txt */

	public function build() {
		$title   = trim( (string) get_option( self::OPTION_TITLE, \PublishRankSEO\Core\PublicationProfile::publication_name() ) );
		$summary = trim( (string) get_option( self::OPTION_SUMMARY, \PublishRankSEO\Core\PublicationProfile::publication_description() ) );
		$intro   = trim( (string) get_option( self::OPTION_INTRO, '' ) );
		$lines   = array( '# ' . $this->line( '' !== $title ? $title : \PublishRankSEO\Core\PublicationProfile::publication_name() ) );
		if ( '' !== $summary ) {
			$lines[] = '> ' . $this->line( $summary ); }
		if ( '' !== $intro ) {
			$lines[] = '';
			$lines[] = $this->line( $intro ); }
		$seen     = array();
		$sections = array();
		$optional = array();
		$key      = $this->key_pages();
		if ( get_option( self::OPTION_HOME, 1 ) ) {
			$sections[] = array(
				'name'  => __( 'Website', 'publishrank' ),
				'links' => array( array( \PublishRankSEO\Core\PublicationProfile::publication_name(), home_url( '/' ), ! empty( $this->line( \PublishRankSEO\Core\PublicationProfile::publication_description() ) ) ? $this->line( \PublishRankSEO\Core\PublicationProfile::publication_description() ) : __( 'Official website homepage.', 'publishrank' ) ) ),
			); }
		if ( get_option( self::OPTION_POLICY, 1 ) && $key ) {
			$sections[] = array(
				'name'  => __( 'About and editorial standards', 'publishrank' ),
				'links' => $key,
			); }
		if ( get_option( self::OPTION_POSTS, 1 ) ) {
			$sections[] = array(
				'name'  => __( 'Recent articles', 'publishrank' ),
				'links' => $this->posts( absint( get_option( self::OPTION_POST_COUNT, 20 ) ) ),
			); }
		if ( get_option( self::OPTION_CPT, 0 ) ) {
			foreach ( $this->cpt_sections() as $section ) {
				$sections[] = $section; }
		}
		if ( get_option( self::OPTION_PAGES, 1 ) ) {
			$sections[] = array(
				'name'  => __( 'Pages', 'publishrank' ),
				'links' => $this->pages( wp_list_pluck( $key, 1 ) ),
			); }
		foreach ( $this->custom_sections() as $section ) {
			$sections[] = $section; }
		if ( get_option( self::OPTION_RESOURCES, 1 ) ) {
			$sections[] = array(
				'name'  => __( 'Machine-readable resources', 'publishrank' ),
				'links' => $this->resources(),
			); }
		$archives = array();
		if ( get_option( self::OPTION_CATEGORIES, 1 ) ) {
			$archives[ __( 'Categories', 'publishrank' ) ] = $this->terms( 'category', 50 ); }
		if ( get_option( self::OPTION_TAGS, 0 ) ) {
			$archives[ __( 'Tags', 'publishrank' ) ] = $this->terms( 'post_tag', 30 ); }
		if ( get_option( self::OPTION_AUTHORS, 1 ) ) {
			$archives[ __( 'Authors', 'publishrank' ) ] = $this->authors(); }
		if ( get_option( self::OPTION_OPTIONAL, 1 ) ) {
			// llms.txt reserves one "Optional" section for material a short context window can skip.
			foreach ( $archives as $label => $links ) {
				foreach ( $links as $link ) {
					$optional[] = array( $label . ': ' . $link[0], $link[1], $link[2] ); }
			}
			if ( $optional ) {
				$sections[] = array(
					'name'  => __( 'Optional', 'publishrank' ),
					'links' => $optional,
				); }
		} else {
			foreach ( $archives as $label => $links ) {
				$sections[] = array(
					'name'  => $label,
					'links' => $links,
				); }
		}
		foreach ( $sections as $section ) {
			$rows = array();
			foreach ( (array) $section['links'] as $link ) {
				$url = esc_url_raw( $link[1] );
				if ( '' === $url || isset( $seen[ $section['name'] ][ $url ] ) ) {
					continue; }
				$seen[ $section['name'] ][ $url ] = true;
				$rows[]                           = '- [' . $this->line( $link[0] ) . '](' . $url . ')' . ( '' !== trim( (string) $link[2] ) ? ': ' . $this->line( $link[2] ) : '' );
			}
			if ( ! $rows ) {
				continue; }
			$lines[] = '';
			$lines[] = '## ' . $this->line( $section['name'] );
			$lines   = array_merge( $lines, $rows );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/* ------------------------------------------------------------------ llms-full.txt */

	public function build_full() {
		$title   = trim( (string) get_option( self::OPTION_TITLE, \PublishRankSEO\Core\PublicationProfile::publication_name() ) );
		$summary = trim( (string) get_option( self::OPTION_SUMMARY, \PublishRankSEO\Core\PublicationProfile::publication_description() ) );
		$limit   = max( 1000, min( 10000, absint( get_option( self::OPTION_FULL_WORDS, 8000 ) ) ) );
		$each    = max( 150, min( 3000, absint( get_option( self::OPTION_FULL_PER_POST, 1200 ) ) ) );
		$lines   = array( '# ' . $this->line( '' !== $title ? $title : \PublishRankSEO\Core\PublicationProfile::publication_name() ) );
		if ( '' !== $summary ) {
			$lines[] = '';
			$lines[] = '> ' . $this->line( $summary ); }
		$lines[] = '';
		$lines[] = $this->line( sprintf( /* translators: 1: site URL, 2: date */ __( 'Full-text edition of %1$s, generated %2$s. Each entry links to its canonical article; the concise map is at %3$s.', 'publishrank' ), home_url( '/' ), gmdate( 'Y-m-d' ), home_url( '/llms.txt' ) ) );
		$words   = 0;
		$done    = array();
		// Authority pages first (About, editorial policy), then the newest articles, so truncation never drops what explains the publisher.
		$items = array();
		if ( get_option( self::OPTION_POLICY, 1 ) ) {
			foreach ( $this->key_page_posts() as $p ) {
				$items[] = $p; }
		}
		foreach ( $this->eligible(
			array(
				'post_type'      => 'post',
				'posts_per_page' => min( 100, max( 1, absint( get_option( self::OPTION_POST_COUNT, 20 ) ) ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		) as $p ) {
			$items[] = $p; }
		foreach ( $items as $post ) {
			if ( isset( $done[ $post->ID ] ) ) {
				continue; }
			$remaining = $limit - $words;
			if ( $remaining <= 0 ) {
				break; }
			$text = $this->plain_content( $post );
			if ( '' === $text ) {
				continue; }
			$parts = preg_split( '/\s+/u', $text );
			$cap   = min( $each, $remaining );
			$cut   = count( $parts ) > $cap;
			if ( $cut ) {
				$parts = array_slice( $parts, 0, $cap ); }
			$meta = array();
			if ( 'post' === $post->post_type ) {
				$meta[] = sprintf( /* translators: %s: date */ __( 'Published: %s', 'publishrank' ), get_the_date( 'Y-m-d', $post ) );
				if ( get_post_modified_time( 'Y-m-d', true, $post ) !== get_post_time( 'Y-m-d', true, $post ) ) {
					$meta[] = sprintf( /* translators: %s: date */ __( 'Updated: %s', 'publishrank' ), get_the_modified_date( 'Y-m-d', $post ) ); }
				$author = get_the_author_meta( 'display_name', $post->post_author );
				if ( $author ) {
					$meta[] = sprintf( /* translators: %s: author */ __( 'Author: %s', 'publishrank' ), $author ); }
				$cats = wp_list_pluck( (array) get_the_category( $post->ID ), 'name' );
				if ( $cats ) {
					$meta[] = sprintf( /* translators: %s: sections */ __( 'Section: %s', 'publishrank' ), implode( ', ', array_slice( $cats, 0, 3 ) ) ); }
			}
			$lines[] = '';
			$lines[] = '## ' . $this->line( get_the_title( $post ) );
			$lines[] = 'URL: ' . esc_url_raw( get_permalink( $post ) );
			if ( $meta ) {
				$lines[] = $this->line( implode( ' | ', $meta ) ); }
			$lines[] = '';
			$lines[] = implode( ' ', $parts );
			if ( $cut ) {
				$lines[] = '';
				$lines[] = '[' . __( 'Excerpt ends here; the complete text is at the URL above.', 'publishrank' ) . ']'; }
			$words            += count( $parts );
			$done[ $post->ID ] = true;
		}
		return implode( "\n", $lines ) . "\n";
	}

	/** Visible text of a post, with shortcodes and blocks rendered the way readers see them. */
	private function plain_content( $post ) {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Some blocks and shortcodes read the global.
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content );
		wp_reset_postdata();
		$html = preg_replace( '#<(script|style|noscript)\b.*?</\1>#is', '', (string) $html );
		$html = preg_replace( '#</(p|div|li|h[1-6]|blockquote|tr)>#i', "\n", (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/* ------------------------------------------------------------------ content sources */

	/** Query arguments that keep password-protected and noindex content out of every list. */
	private function eligible( array $args ) {
		$defaults = array(
			'post_status'      => 'publish',
			'has_password'     => false,
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array(
				'relation' => 'OR',
				array(
					'key'     => PostMeta::ROBOTS,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => PostMeta::ROBOTS,
					'value'   => 'noindex',
					'compare' => 'NOT LIKE',
				),
			),
		);
		return get_posts( array_merge( $defaults, $args ) );
	}

	private function describe( $post ) {
		$custom = trim( (string) get_post_meta( $post->ID, PostMeta::DESCRIPTION, true ) );
		$text   = '' !== $custom ? $custom : wp_strip_all_tags( get_the_excerpt( $post ) );
		return wp_trim_words( $text, 28, '…' );
	}

	private function posts( $count ) {
		$out = array();
		foreach ( $this->eligible(
			array(
				'post_type'      => 'post',
				'posts_per_page' => max( 1, min( 100, $count ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		) as $post ) {
			$desc  = $this->describe( $post );
			$out[] = array( get_the_title( $post ), get_permalink( $post ), trim( get_the_date( 'Y-m-d', $post ) . ( '' !== $desc ? ' — ' . $desc : '' ) ) );
		}
		return $out;
	}

	/** Pages that establish authority, found by slug and by WordPress' privacy-policy setting. @return array<int,array{0:string,1:string,2:string}> */
	private function key_pages() {
		$out = array();
		foreach ( $this->key_page_posts() as $page ) {
			$out[] = array( get_the_title( $page ), get_permalink( $page ), ! empty( $this->describe( $page ) ) ? $this->describe( $page ) : __( 'Published site page.', 'publishrank' ) ); }
		return $out;
	}

	/** @return \WP_Post[] */
	private function key_page_posts() {
		$found = array();
		$pages = $this->eligible(
			array(
				'post_type'      => 'page',
				'posts_per_page' => 200,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);
		$priv  = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		foreach ( $pages as $page ) {
			if ( $page->ID === $priv || preg_match( self::POLICY_PATTERN, $page->post_name ) ) {
				$found[ $page->ID ] = $page; }
		}
		return array_slice( array_values( $found ), 0, 15 );
	}

	private function pages( array $skip_urls = array() ) {
		$out = array();
		foreach ( $this->eligible(
			array(
				'post_type'      => 'page',
				'posts_per_page' => 50,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		) as $page ) {
			$url = get_permalink( $page );
			if ( in_array( $url, $skip_urls, true ) || (int) get_option( 'page_on_front' ) === $page->ID ) {
				continue; }
			$out[] = array( get_the_title( $page ), $url, ! empty( $this->describe( $page ) ) ? $this->describe( $page ) : __( 'Published site page.', 'publishrank' ) );
		}
		return $out;
	}

	/** One section per public custom post type (reports, galleries, live blogs and so on). */
	private function cpt_sections() {
		$out = array();
		foreach ( get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'elementor_library' ), true ) ) {
				continue; }
			$links = array();
			foreach ( $this->eligible(
				array(
					'post_type'      => $type->name,
					'posts_per_page' => 15,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			) as $post ) {
				$links[] = array( get_the_title( $post ), get_permalink( $post ), $this->describe( $post ) ); }
			if ( $links ) {
				$out[] = array(
					'name'  => $type->labels->name,
					'links' => $links,
				); }
		}
		return $out;
	}

	/** The machine-readable entry points for a newsroom: sitemaps, robots.txt and the full-text companion. */
	private function resources() {
		$out = array( array( __( 'Sitemap index', 'publishrank' ), SitemapUrls::index_url(), __( 'Every sitemap on this site.', 'publishrank' ) ) );
		if ( \PublishRankSEO\Core\OutputSettings::schema() ) {
			$out[] = array( __( 'Schema Aggregator', 'publishrank' ), rest_url( 'publishrank/v1/schema-aggregator' ), __( 'Complete JSON-LD content index for AI crawlers and knowledge systems.', 'publishrank' ) );
			$out[] = array( __( 'Structured-data discovery index', 'publishrank' ), rest_url( 'publishrank/v1/schema-index' ), __( 'Paginated index of public URLs and their schema types.', 'publishrank' ) );
		}
		if ( get_option( 'publishrank_seo_news_enabled', true ) ) {
			$out[] = array( __( 'News sitemap', 'publishrank' ), SitemapUrls::shard_url( 'news', 1 ), __( 'Articles from the last two days, in Google News format.', 'publishrank' ) ); }
		$out[] = array( 'robots.txt', home_url( '/robots.txt' ), __( 'Crawler access rules.', 'publishrank' ) );
		$feed  = get_feed_link();
		if ( $feed ) {
			$out[] = array( 'RSS', $feed, __( 'Latest articles as an RSS feed.', 'publishrank' ) ); }
		if ( get_option( self::OPTION_FULL_ENABLED, 0 ) ) {
			$out[] = array( 'llms-full.txt', home_url( '/llms-full.txt' ), __( 'Full text of recent articles in one Markdown file.', 'publishrank' ) ); }
		return $out;
	}

	private function terms( $taxonomy, $number ) {
		$out   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => $number,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $out; }
		foreach ( $terms as $term ) {
			if ( 'category' === $taxonomy && (int) get_option( 'default_category' ) === (int) $term->term_id && 1 === count( $terms ) ) {
				continue; }
			$url = get_term_link( $term );
			if ( is_wp_error( $url ) ) {
				continue; }
			$out[] = array( $term->name, $url, $term->description ? wp_trim_words( wp_strip_all_tags( $term->description ), 24, '…' ) : sprintf( /* translators: %d: number of articles */ _n( '%d article.', '%d articles.', (int) $term->count, 'publishrank' ), (int) $term->count ) );
		}
		return $out;
	}

	/** Bylines: the people behind the reporting, which agents use to judge expertise and trust. */
	private function authors() {
		$out = array();
		foreach ( get_users(
			array(
				'has_published_posts' => array( 'post' ),
				'number'              => 30,
				'orderby'             => 'post_count',
				'order'               => 'DESC',
				'fields'              => array( 'ID', 'display_name' ),
			)
		) as $user ) {
			$bio   = trim( wp_strip_all_tags( (string) get_the_author_meta( 'description', $user->ID ) ) );
			$out[] = array( $user->display_name, get_author_posts_url( $user->ID ), '' !== $bio ? wp_trim_words( $bio, 24, '…' ) : sprintf( /* translators: %d: article count */ _n( '%d article.', '%d articles.', (int) count_user_posts( $user->ID, 'post', true ), 'publishrank' ), (int) count_user_posts( $user->ID, 'post', true ) ) );
		}
		return $out;
	}

	private function custom_sections() {
		$raw = (string) get_option( self::OPTION_CUSTOM, '' );
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 3 || '' === $parts[0] || '' === $parts[1] || '' === $parts[2] || ! wp_http_validate_url( $parts[2] ) ) {
				continue; }
			if ( ! isset( $out[ $parts[0] ] ) ) {
				$out[ $parts[0] ] = array(
					'name'  => $parts[0],
					'links' => array(),
				); }
			$out[ $parts[0] ]['links'][] = array( $parts[1], $parts[2], isset( $parts[3] ) ? $parts[3] : '' );
		}
		return array_values( $out );
	}

	/* ------------------------------------------------------------------ review */

	/**
	 * Review the current files for mistakes that make them useless or misleading.
	 *
	 * @return array<int,array{level:string,msg:string}> level is error, warn, info or ok.
	 */
	public function analyze() {
		$issues = array();
		$add    = static function ( $level, $msg ) use ( &$issues ) {
			$issues[] = array(
				'level' => $level,
				'msg'   => $msg,
			);
		};
		$text   = $this->build();
		if ( get_option( self::OPTION_OVERRIDE, 0 ) && trim( (string) get_option( self::OPTION_OVERRIDE_TEXT, '' ) ) === '' ) { $add( 'error', __( 'The llms.txt override is enabled but empty.', 'publishrank' ) ); }
		if ( ! get_option( self::OPTION_ENABLED, 1 ) ) {
			$add( 'warn', __( 'llms.txt is switched off, so /llms.txt returns 404.', 'publishrank' ) ); }
		if ( file_exists( ABSPATH . 'llms.txt' ) ) {
			$add( 'error', __( 'A physical llms.txt file exists in your site root. The web server delivers that file instead of this one.', 'publishrank' ) ); }
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$add( 'warn', __( 'WordPress is set to discourage search engines, which tells AI crawlers to stay away as well.', 'publishrank' ) ); }
		if ( '' === trim( (string) get_option( self::OPTION_SUMMARY, \PublishRankSEO\Core\PublicationProfile::publication_description() ) ) ) {
			$add( 'warn', __( 'There is no summary. The one-sentence description under the title is the first thing an AI agent reads.', 'publishrank' ) ); } elseif ( strlen( (string) get_option( self::OPTION_SUMMARY, '' ) ) > 400 ) {
			$add( 'info', __( 'The summary is long. Keep it to one or two sentences and move detail to Additional context.', 'publishrank' ) ); }
			if ( preg_match( '/\b(api[_ -]?key|password|secret|token)\b\s*[:=]/i', (string) get_option( self::OPTION_INTRO, '' ) . ' ' . (string) get_option( self::OPTION_SUMMARY, '' ) ) ) {
				$add( 'error', __( 'The text looks like it contains a credential. This file is public.', 'publishrank' ) ); }
			if ( get_option( self::OPTION_POSTS, 1 ) && ! $this->posts( 1 ) ) {
				$add( 'info', __( 'No eligible articles to list yet. Published, public, indexable posts appear here automatically.', 'publishrank' ) ); }
			if ( get_option( self::OPTION_POLICY, 1 ) && ! $this->key_pages() ) {
				$add( 'info', __( 'No About, Contact, Editorial policy or Corrections page was detected. These pages are strong trust signals; publish them or add them under Curated resources.', 'publishrank' ) ); }
			$tokens = (int) ceil( strlen( $text ) / 4 );
			if ( $tokens > 12000 ) {
				/* translators: %s: token estimate */ $add( 'warn', sprintf( __( 'The concise file is about %s tokens. Agents with small context windows may skip it; lower the article count.', 'publishrank' ), number_format_i18n( $tokens ) ) ); }
			if ( substr_count( $text, "\n- [" ) !== count( array_unique( $this->urls_in( $text ) ) ) ) {
				$add( 'info', __( 'The same URL appears in more than one section.', 'publishrank' ) ); }
			$p       = RobotsTxt::parse( RobotsTxt::preview() );
			$blocked = array();
			foreach ( array( 'GPTBot', 'ClaudeBot', 'PerplexityBot', 'OAI-SearchBot', 'Google-Extended' ) as $bot ) {
				if ( ! RobotsTxt::check( $p, $bot, '/llms.txt' )['allowed'] ) {
						$blocked[] = $bot; }
			}
			if ( $blocked ) {
				/* translators: %s: crawler names */ $add( 'info', sprintf( __( 'robots.txt blocks %s, so they will not read this file. That is your choice; just keep the two files consistent.', 'publishrank' ), implode( ', ', $blocked ) ) ); }
			if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
				$add( 'info', __( 'Plain permalinks are active. /llms.txt works only if your server routes unknown paths to WordPress.', 'publishrank' ) ); }
			if ( get_option( self::OPTION_FULL_ENABLED, 0 ) && ! RobotsTxt::check( $p, 'GPTBot', '/llms-full.txt' )['allowed'] && ! RobotsTxt::check( $p, 'ClaudeBot', '/llms-full.txt' )['allowed'] ) {
				$add( 'info', __( 'llms-full.txt is on, but the main AI crawlers are blocked in robots.txt.', 'publishrank' ) ); }
			if ( ! array_filter(
				$issues,
				static function ( $i ) {
					return in_array( $i['level'], array( 'error', 'warn' ), true );
				}
			) ) {
				array_unshift(
					$issues,
					array(
						'level' => 'ok',
						'msg'   => __( 'No problems found. The file is well formed and ready for AI agents.', 'publishrank' ),
					)
				); }
			return $issues;
	}

	/** @return string[] */
	private function urls_in( $text ) {
		preg_match_all( '/^- \[[^\]]*\]\(([^)]+)\)/m', (string) $text, $m );
		return $m[1]; }

	/** Counts for the admin summary. @return array{links:int,sections:int,bytes:int,tokens:int,full_bytes:int} */
	public function stats() {
		$text = $this->build();
		return array(
			'links'      => count( $this->urls_in( $text ) ),
			'sections'   => preg_match_all( '/^## /m', $text ),
			'bytes'      => strlen( $text ),
			'tokens'     => (int) ceil( strlen( $text ) / 4 ),
			'full_bytes' => get_option( self::OPTION_FULL_ENABLED, 0 ) ? strlen( $this->build_full() ) : 0,
		);
	}

	private function line( $value ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) ) ); }
}
