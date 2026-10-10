<?php
/**
 * Derives technical metadata without touching presentation layers.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\PublicationProfile;

final class MetaGenerator {
	/** @return string */
	public function title() {
		$post_id = is_singular() ? get_queried_object_id() : 0;
		if ( is_front_page() || is_home() ) {
			$home_title = sanitize_text_field( (string) get_option( 'publishrank_home_title', '' ) );
			if ( $home_title ) { return function_exists( 'mb_substr' ) ? mb_substr( $home_title, 0, 180 ) : substr( $home_title, 0, 180 ); }
		}
		if ( $post_id ) {
			$override = PostMeta::get( $post_id, PostMeta::TITLE, '' );
			if ( '' !== (string) $override ) {
				return wp_strip_all_tags( (string) $override );
			}
		}
		$context = is_front_page() || is_home() ? 'home' : ( is_singular( 'post' ) ? 'post' : ( is_singular() ? 'page' : 'archive' ) );
		$template = (string) get_option( 'publishrank_seo_title_template_' . $context, '' );
		if ( '' === $template ) {
			$template = 'home' === $context ? '%%sitename%% %%page%%' : '%%title%% %%sep%% %%sitename%% %%page%%';
		}
		$values = array(
				// This method feeds pre_get_document_title; calling wp_get_document_title()
				// here would recurse on archives and search results.
				'%%title%%' => $post_id ? get_the_title( $post_id ) : $this->context_title( $context ),
			'%%sitename%%' => get_bloginfo( 'name' ),
			'%%category%%' => $post_id ? $this->category( $post_id ) : '',
			'%%author%%' => $post_id ? get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) ) : '',
			'%%date%%' => $post_id ? get_the_date( '', $post_id ) : '',
			'%%excerpt%%' => $post_id ? ( has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : '' ) : get_bloginfo( 'description' ),
			'%%page%%' => $this->page_suffix(),
			'%%sep%%' => (string) get_option( 'publishrank_seo_title_separator', '|' ),
		);
		$title = strtr( (string) $template, $values );
		$title = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $title ) ), " \t\n\r\0\x0B|–—-" );
		return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 180 ) : substr( $title, 0, 180 );
	}

	/** @return string */
	public function description() {
		$post_id = is_singular() ? get_queried_object_id() : 0;
		if ( is_front_page() || is_home() ) {
			$home_description = trim( wp_strip_all_tags( (string) get_option( 'publishrank_home_description', '' ) ) );
			if ( $home_description ) { return function_exists( 'mb_substr' ) ? mb_substr( $home_description, 0, 160 ) : substr( $home_description, 0, 160 ); }
		}
		$override = $post_id ? PostMeta::get( $post_id, PostMeta::DESCRIPTION, '' ) : '';
		// A manually written SEO description must win over the generated excerpt.
		// The previous ordering overwrote a valid per-post override on every request.
		if ( '' !== (string) $override ) {
			$text = $override;
		} else {
			$text = get_bloginfo( 'description' );
			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( $post instanceof \WP_Post ) {
					$text = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( $post->post_content, 30, '' );
				}
			}
		}
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 160 ) : substr( $text, 0, 160 );
	}

	/** @return string */
	public function canonical() {
		$primary = PublicationProfile::primary_url();
		$primary_host = strtolower( (string) wp_parse_url( $primary, PHP_URL_HOST ) );
		if ( is_singular() ) {
			$override = PostMeta::get( get_queried_object_id(), PostMeta::CANONICAL, '' );
			$candidate = $override ? PublicationProfile::normalize_url( $override ) : '';
			$candidate_host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
			if ( $candidate && $primary_host && $candidate_host === $primary_host && ! $this->is_media_host( $candidate_host ) ) {
				return $candidate;
			}
			$permalink = PublicationProfile::normalize_url( (string) get_permalink() );
			$permalink_host = strtolower( (string) wp_parse_url( $permalink, PHP_URL_HOST ) );
			return $permalink && ( ! $primary_host || $permalink_host === $primary_host ) ? $permalink : $primary;
		}
		if ( is_home() || is_front_page() ) { return $primary; }
		if ( function_exists( 'get_pagenum_link' ) ) {
			$paged_url = PublicationProfile::normalize_url( (string) get_pagenum_link( max( 1, (int) get_query_var( 'paged' ) ) ) );
			if ( $paged_url ) {
				return $paged_url;
			}
		}
		$request = isset( $GLOBALS['wp']->request ) ? $GLOBALS['wp']->request : '';
		return PublicationProfile::normalize_url( home_url( '/' . ltrim( (string) $request, '/' ) ) ) ?: $primary;
	}

	/** @return string */
	public function section() {
		if ( ! is_singular( 'post' ) ) { return ''; }
		$categories = get_the_category( get_queried_object_id() );
		return ! empty( $categories ) ? wp_strip_all_tags( (string) $categories[0]->name ) : __( 'News', 'publishrank' );
	}

	/** @return array<int, string> */
	public function keywords() {
		if ( ! is_singular( 'post' ) ) { return array(); }
		$tags = get_the_tags( get_queried_object_id() );
		return ! empty( $tags ) && is_array( $tags ) ? array_map( 'wp_strip_all_tags', wp_list_pluck( $tags, 'name' ) ) : array();
	}

	/** @return string */
	public function robots() {
		$directive = is_singular() ? PostMeta::get( get_queried_object_id(), PostMeta::ROBOTS, '' ) : '';
		if ( $directive ) { return $this->normalize_robots( $directive ); }
		if ( is_search() || is_404() || is_feed() ) { return 'noindex,follow'; }
		if ( is_author() && get_option( 'publishrank_noindex_author_archives', false ) ) { return 'noindex,follow'; }
		if ( is_date() && get_option( 'publishrank_noindex_date_archives', false ) ) { return 'noindex,follow'; }
		$global = (string) get_option( 'publishrank_seo_default_robots', 'index,follow' );
		return $this->normalize_robots( $global );
	}

	/** @param string $value @return string */
	public function normalize_robots( $value ) {
		$allowed = array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'notranslate', 'indexifembedded', 'max-snippet:0', 'max-snippet:-1', 'max-image-preview:none', 'max-image-preview:standard', 'max-image-preview:large', 'max-video-preview:0', 'max-video-preview:-1' );
		$tokens = array();
		foreach ( preg_split( '/\s*,\s*/', strtolower( (string) $value ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( in_array( $token, $allowed, true ) ) { $tokens[] = $token; }
		}
		$tokens = array_values( array_unique( $tokens ) );
		if ( in_array( 'noindex', $tokens, true ) ) { $tokens = array_values( array_diff( $tokens, array( 'index' ) ) ); }
		if ( in_array( 'nofollow', $tokens, true ) ) { $tokens = array_values( array_diff( $tokens, array( 'follow' ) ) ); }
		return implode( ',', $tokens ? $tokens : array( 'index', 'follow' ) );
	}

	private function category( $post_id ) {
		$categories = get_the_category( $post_id );
		return ! empty( $categories ) ? (string) $categories[0]->name : '';
	}

	/** @param string $context @return string */
	private function context_title( $context ) {
		if ( 'home' === $context ) {
			return get_bloginfo( 'name' );
		}
		if ( function_exists( 'get_the_archive_title' ) && ( is_archive() || is_search() ) ) {
			return wp_strip_all_tags( (string) get_the_archive_title() );
		}
		if ( is_404() ) {
			return __( 'Page not found', 'publishrank' );
		}
		return get_bloginfo( 'name' );
	}

	private function page_suffix() {
		$page = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		return $page > 1 ? sprintf( __( 'Page %d', 'publishrank' ), $page ) : '';
	}

	private function is_media_host( $host ) {
		$hosts = array( get_option( 'publishrank_media_host', '' ), get_option( 'publishrank_cdn_host', '' ), get_option( 'publishrank_video_host', '' ) );
		foreach ( $hosts as $configured ) {
			$configured_host = strtolower( (string) preg_replace( '#^https?://#', '', (string) $configured ) );
			$configured_host = preg_replace( '#/.*$#', '', $configured_host );
			if ( $configured_host && $configured_host === strtolower( (string) $host ) ) { return true; }
		}
		return false;
	}
}
