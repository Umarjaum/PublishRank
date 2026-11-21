<?php
/**
 * Markdown delivery for AI agents.
 *
 * Every eligible published post or page is available as clean Markdown with YAML front matter:
 *   https://example.com/my-article.md          (suffix)
 *   https://example.com/my-article/?format=md  (query)
 *   Accept: text/markdown                       (content negotiation on the normal URL)
 *   https://example.com/index.md                (site overview)
 * Discovery: <link rel="alternate" type="text/markdown"> and an HTTP Link header on HTML pages.
 * Password-protected, non-public and noindex content is never served.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AI;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class MarkdownDelivery {
	const OPTION = 'publishrank_ai_markdown';
	const CACHE_VERSION = 3;

	public static function defaults() {
		return array(
			'enabled'      => 1,
			'post_types'   => array( 'post', 'page' ),
			'suffix'       => 1,
			'query'        => 1,
			'negotiate'    => 1,
			'front_matter' => 1,
			'discovery'    => 1,
			'noindex'      => 1,
			'humans'       => 1,
			'footer'       => 1,
		);
	}

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = array_merge( self::defaults(), is_array( $s ) ? $s : array() );
		$s['post_types'] = array_values( array_filter( array_map( 'sanitize_key', (array) $s['post_types'] ) ) );
		return $s;
	}

	public static function save( array $in ) {
		$s = self::settings();
		foreach ( array( 'enabled', 'suffix', 'query', 'negotiate', 'front_matter', 'discovery', 'noindex', 'humans', 'footer' ) as $k ) {
			$s[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$types = array();
		foreach ( (array) ( $in['post_types'] ?? array() ) as $t ) {
			$t = sanitize_key( $t );
			if ( post_type_exists( $t ) && is_post_type_viewable( $t ) ) {
				$types[] = $t;
			}
		}
		$s['post_types'] = $types ? $types : array( 'post' );
		update_option( self::OPTION, $s, false );
		update_option( 'publishrank_ai_markdown_epoch', time(), false );
	}

	public static function register() {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return;
		}
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_suffix' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_negotiate' ), 1 );
		if ( $s['discovery'] ) {
			add_action( 'wp_head', array( __CLASS__, 'head_link' ), 3 );
		}
		add_action( 'save_post', array( __CLASS__, 'bump' ) );
	}

	public static function bump() {
		update_option( 'publishrank_ai_markdown_epoch', time(), false );
	}

	/* ---------------------------------------------------------------- URLs */

	public static function url_for( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$link = (string) get_permalink( $post );
		if ( get_option( 'permalink_structure' ) && ! wp_parse_url( $link, PHP_URL_QUERY ) && self::settings()['suffix'] ) {
			$path = (string) wp_parse_url( $link, PHP_URL_PATH );
			if ( '' === trim( $path, '/' ) ) {
				return home_url( '/index.md' );
			}
			return untrailingslashit( $link ) . '.md';
		}
		return add_query_arg( 'format', 'md', $link );
	}

	public static function eligible( $post ) {
		$post = get_post( $post );
		$s    = self::settings();
		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! in_array( $post->post_type, $s['post_types'], true ) || ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}
		if ( false !== strpos( strtolower( (string) get_post_meta( $post->ID, PostMeta::ROBOTS, true ) ), 'noindex' ) ) {
			return false;
		}
		return (bool) apply_filters( 'publishrank_markdown_eligible', true, $post );
	}

	/** Whether the current client may receive Markdown (per-bot setting). */
	private static function client_allowed() {
		$token = BotRegistry::current();
		if ( '' === $token ) {
			return (bool) self::settings()['humans'];
		}
		return (bool) CrawlerControl::rule( $token )['markdown'];
	}

	public static function head_link() {
		if ( ! is_singular() ) {
			if ( is_front_page() ) {
				echo '<link rel="alternate" type="text/markdown" title="' . esc_attr__( 'Site overview (Markdown)', 'publishrank' ) . '" href="' . esc_url( home_url( '/index.md' ) ) . '" />' . "\n";
			}
			return;
		}
		$post = get_queried_object();
		if ( $post instanceof \WP_Post && self::eligible( $post ) ) {
			echo '<link rel="alternate" type="text/markdown" title="' . esc_attr__( 'Markdown version', 'publishrank' ) . '" href="' . esc_url( self::url_for( $post ) ) . '" />' . "\n";
		}
	}

	/* ---------------------------------------------------------------- Request handling */

	/** /something.md and /index.md */
	public static function maybe_suffix() {
		if ( is_admin() || ! self::settings()['suffix'] || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path compared and resolved only.
		if ( '.md' !== strtolower( substr( $path, -3 ) ) ) {
			return;
		}
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$rel  = '/' . ltrim( '' !== trim( $base, '/' ) && 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : $path, '/' );
		if ( '/index.md' === strtolower( $rel ) ) {
			self::serve_site();
		}
		$stem = substr( $rel, 0, -3 );
		$id   = url_to_postid( home_url( user_trailingslashit( $stem ) ) );
		if ( ! $id ) {
			$id = url_to_postid( home_url( $stem ) );
		}
		if ( $id && self::eligible( $id ) ) {
			self::serve_post( get_post( $id ) );
		}
		if ( $id ) {
			self::refuse( 404 );
		}
	}

	public static function maybe_negotiate() {
		$s = self::settings();
		if ( ! is_singular() && ! is_front_page() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only format switch.
		$by_query = $s['query'] && isset( $_GET['format'] ) && in_array( strtolower( sanitize_key( wp_unslash( $_GET['format'] ) ) ), array( 'md', 'markdown' ), true );
		$accept   = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		$by_head  = $s['negotiate'] && self::prefers_markdown( $accept );
		if ( ! $by_query && ! $by_head ) {
			if ( is_singular() && $s['discovery'] && ! headers_sent() ) {
				$post = get_queried_object();
				if ( $post instanceof \WP_Post && self::eligible( $post ) ) {
					header( 'Link: <' . esc_url_raw( self::url_for( $post ) ) . '>; rel="alternate"; type="text/markdown"', false );
					header( 'Vary: Accept', false );
				}
			}
			return;
		}
		if ( is_front_page() && ! is_singular() ) {
			self::serve_site();
		}
		$post = get_queried_object();
		if ( $post instanceof \WP_Post && self::eligible( $post ) ) {
			self::serve_post( $post );
		}
		if ( $by_query ) {
			self::refuse( 404 );
		}
	}

	/** True when text/markdown is acceptable and ranked at least as high as text/html. */
	public static function prefers_markdown( $accept ) {
		if ( '' === $accept || false === strpos( $accept, 'markdown' ) ) {
			return false;
		}
		$q = array( 'md' => 0.0, 'html' => 0.0 );
		foreach ( explode( ',', $accept ) as $part ) {
			$bits = array_map( 'trim', explode( ';', $part ) );
			$type = strtolower( $bits[0] );
			$qv   = 1.0;
			foreach ( array_slice( $bits, 1 ) as $p ) {
				if ( 0 === strpos( $p, 'q=' ) ) {
					$qv = (float) substr( $p, 2 );
				}
			}
			if ( in_array( $type, array( 'text/markdown', 'text/x-markdown' ), true ) ) {
				$q['md'] = max( $q['md'], $qv );
			} elseif ( in_array( $type, array( 'text/html', 'application/xhtml+xml' ), true ) ) {
				$q['html'] = max( $q['html'], $qv );
			}
		}
		return $q['md'] > 0 && $q['md'] >= $q['html'];
	}

	private static function refuse( $code ) {
		status_header( $code );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 404 === $code ? 'Not found.' : 'Markdown is not available for this client.';
		exit;
	}

	/* ---------------------------------------------------------------- Rendering */

	public static function render_post( \WP_Post $post ) {
		$s     = self::settings();
		$key   = 'pr_md_' . $post->ID . '_' . md5( $post->post_modified_gmt . '|' . get_option( 'publishrank_ai_markdown_epoch', 0 ) . '|' . self::CACHE_VERSION . '|' . wp_json_encode( $s ) );
		$cache = get_transient( $key );
		if ( is_string( $cache ) && '' !== $cache ) {
			return $cache;
		}
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- blocks and shortcodes read it.
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content );
		wp_reset_postdata();
		$body  = ( new HtmlToMarkdown( home_url() ) )->convert( (string) $html );
		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$out   = '';
		if ( $s['front_matter'] ) {
			$desc   = trim( (string) get_post_meta( $post->ID, PostMeta::DESCRIPTION, true ) );
			$desc   = '' !== $desc ? $desc : wp_strip_all_tags( get_the_excerpt( $post ) );
			$canon  = trim( (string) get_post_meta( $post->ID, PostMeta::CANONICAL, true ) );
			$fm     = array(
				'title'        => $title,
				'url'          => '' !== $canon ? $canon : get_permalink( $post ),
				'description'  => wp_trim_words( html_entity_decode( $desc, ENT_QUOTES, 'UTF-8' ), 60, '…' ),
				'author'       => get_the_author_meta( 'display_name', (int) $post->post_author ),
				'published'    => get_post_time( 'c', true, $post ),
				'modified'     => get_post_modified_time( 'c', true, $post ),
				'language'     => str_replace( '_', '-', get_locale() ),
				'type'         => $post->post_type,
				'site'         => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'word_count'   => \PublishRankSEO\Meta\LanguageAlternates::word_count( $body ),
			);
			$thumb = get_the_post_thumbnail_url( $post, 'full' );
			if ( $thumb ) {
				$fm['image'] = $thumb;
			}
			foreach ( array( 'category' => 'categories', 'post_tag' => 'tags' ) as $tax => $label ) {
				if ( is_object_in_taxonomy( $post->post_type, $tax ) ) {
					$terms = get_the_terms( $post, $tax );
					if ( is_array( $terms ) && $terms ) {
						$fm[ $label ] = wp_list_pluck( $terms, 'name' );
					}
				}
			}
			$fm   = (array) apply_filters( 'publishrank_markdown_front_matter', $fm, $post );
			$out .= "---\n" . self::yaml( $fm ) . "---\n\n";
		}
		$out .= '# ' . $title . "\n\n" . $body;
		if ( $s['footer'] ) {
			$out .= "\n---\n\n" . sprintf( /* translators: 1: site name, 2: URL */ __( 'Source: %1$s — %2$s', 'publishrank' ), html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ), get_permalink( $post ) ) . "\n";
		}
		$out = (string) apply_filters( 'publishrank_markdown_output', $out, $post );
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	/** Minimal YAML emitter for scalar and list values. */
	public static function yaml( array $data ) {
		$out = '';
		foreach ( $data as $k => $v ) {
			$k = preg_replace( '/[^a-z0-9_]/i', '_', (string) $k );
			if ( is_array( $v ) ) {
				$out .= $k . ":\n";
				foreach ( $v as $item ) {
					$out .= '  - ' . self::yaml_scalar( $item ) . "\n";
				}
			} else {
				$out .= $k . ': ' . self::yaml_scalar( $v ) . "\n";
			}
		}
		return $out;
	}

	private static function yaml_scalar( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			return (string) $v;
		}
		$v = str_replace( array( "\r", "\n" ), ' ', (string) $v );
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $v ) . '"';
	}

	public static function render_site() {
		$lines   = array( '# ' . html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ), '' );
		$tagline = html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' );
		if ( '' !== $tagline ) {
			$lines[] = '> ' . $tagline;
			$lines[] = '';
		}
		$lines[] = sprintf( /* translators: %s: URL */ __( 'Every article below is available as Markdown. Full index for AI systems: %s', 'publishrank' ), home_url( '/llms.txt' ) );
		$lines[] = '';
		$types   = self::settings()['post_types'];
		foreach ( $types as $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj ) {
				continue;
			}
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => 'page' === $type ? 30 : 50,
					'orderby'        => 'page' === $type ? 'menu_order' : 'date',
					'order'          => 'page' === $type ? 'ASC' : 'DESC',
					'no_found_rows'  => true,
				)
			);
			$items = array();
			foreach ( $posts as $p ) {
				if ( self::eligible( $p ) ) {
					$items[] = '- [' . str_replace( array( '[', ']' ), '', html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) ) . '](' . self::url_for( $p ) . ')' . ( 'page' !== $type ? ' — ' . get_the_date( 'Y-m-d', $p ) : '' );
				}
			}
			if ( $items ) {
				$lines[] = '## ' . $obj->labels->name;
				$lines[] = '';
				$lines   = array_merge( $lines, $items, array( '' ) );
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	private static function send( $body, $canonical, $modified_gmt ) {
		$s    = self::settings();
		$etag = '"' . md5( $body ) . '"';
		$last = $modified_gmt ? gmdate( 'D, d M Y H:i:s', strtotime( $modified_gmt . ' UTC' ) ) . ' GMT' : '';
		CrawlerControl::mark_markdown();
		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Vary: Accept' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'ETag: ' . $etag );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $last ) {
			header( 'Last-Modified: ' . $last );
		}
		if ( $canonical ) {
			header( 'Link: <' . esc_url_raw( $canonical ) . '>; rel="canonical"', false );
		}
		if ( $s['noindex'] ) {
			header( 'X-Robots-Tag: noindex, follow' );
		}
		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as a date.
		if ( ( '' !== $inm && $inm === $etag ) || ( '' === $inm && $ims && $last && $ims >= strtotime( $last ) ) ) {
			status_header( 304 );
			exit;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'HEAD' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			header( 'Content-Length: ' . strlen( $body ) );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/markdown body, built from escaped Markdown.
		}
		exit;
	}

	public static function serve_post( \WP_Post $post ) {
		if ( ! self::client_allowed() ) {
			self::refuse( 403 );
		}
		self::send( self::render_post( $post ), get_permalink( $post ), $post->post_modified_gmt );
	}

	public static function serve_site() {
		if ( ! self::client_allowed() ) {
			self::refuse( 403 );
		}
		$latest = get_lastpostmodified( 'gmt' );
		self::send( self::render_site(), home_url( '/' ), $latest ? $latest : '' );
	}
}
