<?php
/**
 * Derives technical metadata without touching presentation layers.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\PublicationProfile;

defined( 'ABSPATH' ) || exit;

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
		$context = is_front_page() || is_home() ? 'home' : ( $this->is_editorial_article() ? 'post' : ( is_singular() ? 'page' : 'archive' ) );
		return $this->render_title( $context, $post_id );
	}

	/** Title for a specific post, independent of the main query (REST, editor, CLI). Override wins. */
	public function title_for_post( $post_id ) {
		$post_id  = (int) $post_id;
		$override = PostMeta::get( $post_id, PostMeta::TITLE, '' );
		if ( '' !== (string) $override ) { return wp_strip_all_tags( (string) $override ); }
		$type    = get_post_type_object( (string) get_post_type( $post_id ) );
		$context = $type && $type->public && $type->publicly_queryable && ! in_array( $type->name, array( 'page', 'attachment' ), true ) ? 'post' : 'page';
		return $this->render_title( $context, $post_id );
	}

	/** @param string $context home|post|page|archive @param int $post_id Post ID or 0. */
	private function render_title( $context, $post_id ) {
		$template = (string) get_option( 'publishrank_seo_title_template_' . $context, '' );
		if ( '' === $template ) {
			$template = 'home' === $context ? '%%sitename%% %%page%%' : '%%title%% %%sep%% %%sitename%% %%page%%';
		}
		$values = array(
			// This method feeds pre_get_document_title; calling wp_get_document_title()
			// here would recurse on archives and search results.
			'%%title%%'    => $post_id ? get_the_title( $post_id ) : $this->context_title( $context ),
			'%%sitename%%' => get_bloginfo( 'name' ),
			'%%category%%' => $post_id ? $this->category( $post_id ) : '',
			'%%author%%'   => $post_id ? get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) ) : '',
			'%%date%%'     => $post_id ? get_the_date( '', $post_id ) : '',
			'%%excerpt%%'  => $post_id ? ( has_excerpt( $post_id ) && ! post_password_required( $post_id ) ? SeoData::clean( get_post_field( 'post_excerpt', $post_id ) ) : '' ) : get_bloginfo( 'description' ),
			'%%page%%'     => $this->page_suffix(),
			'%%sep%%'      => (string) get_option( 'publishrank_seo_title_separator', '|' ),
		);
		$title = strtr( $template, $values );
		$title = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) ), " \t\n\r\0\x0B|–—-" );
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
		// A manually written SEO description always wins over anything fetched automatically. A manually written SEO description must win over the generated excerpt.
		if ( '' !== (string) $override ) {
			return SeoData::truncate( SeoData::clean( (string) $override ), 160 );
		}
		if ( $post_id ) {
			$auto = SeoData::auto_description( $post_id );
			if ( '' !== $auto ) { return $auto; }
		}
		$text = $this->archive_description();
		if ( '' === $text ) { $text = SeoData::clean( get_bloginfo( 'description' ) ); }
		return SeoData::truncate( $text, 160 );
	}

	/** Description for term, author and post-type archives, fetched from WordPress. */
	private function archive_description() {
		$text = '';
		if ( is_category() || is_tag() || is_tax() ) {
			$text = (string) term_description();
		} elseif ( is_author() ) {
			$text = (string) get_the_author_meta( 'description', (int) get_queried_object_id() );
		} elseif ( is_post_type_archive() ) {
			$type = get_queried_object();
			$text = $type instanceof \WP_Post_Type ? (string) $type->description : '';
		}
		return SeoData::clean( $text );
	}

	/** @return string */
	public function canonical() {
		$primary = PublicationProfile::primary_url();
		$primary_host = strtolower( (string) wp_parse_url( $primary, PHP_URL_HOST ) );
		if ( is_singular() ) {
			return $this->canonical_for_post( get_queried_object_id() );
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

	/** Canonical URL for a post: a valid same-site override, else its permalink. */
	public function canonical_for_post( $post_id ) {
		$primary      = PublicationProfile::primary_url();
		$primary_host = strtolower( (string) wp_parse_url( $primary, PHP_URL_HOST ) );
		$override     = PostMeta::get( $post_id, PostMeta::CANONICAL, '' );
		$candidate    = $override ? PublicationProfile::normalize_url( $override ) : '';
		$candidate_host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
		if ( $candidate && $primary_host && $candidate_host === $primary_host && ! $this->is_media_host( $candidate_host ) ) {
			return $candidate;
		}
		$permalink_raw = $this->permalink_for_post( $post_id );
		$permalink      = PublicationProfile::normalize_url( (string) $permalink_raw );
		$permalink_host = strtolower( (string) wp_parse_url( $permalink, PHP_URL_HOST ) );
		return $permalink && ( ! $primary_host || $permalink_host === $primary_host ) ? $permalink : $primary;
	}

/** Use the selected primary category only when the site's permalink structure supports it. */
private function permalink_for_post( $post_id ) {
$mode = get_option( 'publishrank_seo_canonical_category_mode', 'permalink' );
$structure = (string) get_option( 'permalink_structure', '' );
if ( 'primary_category' !== $mode || false === strpos( $structure, '%category%' ) ) { return get_permalink( $post_id ); }
$category_id = absint( PostMeta::get( $post_id, PostMeta::PRIMARY_CATEGORY, 0 ) );
$categories = get_the_category( $post_id );
$category = $category_id ? get_term( $category_id, 'category' ) : ( ! empty( $categories ) ? $categories[0] : null );
if ( ! $category || is_wp_error( $category ) ) { return get_permalink( $post_id ); }
$filter = static function ( $selected, $post_categories ) use ( $category ) { return $category; };
add_filter( 'post_link_category', $filter, 10, 2 );
$url = get_permalink( $post_id );
remove_filter( 'post_link_category', $filter, 10 );
return $url;
}

	/** @return string */
	public function section() {
		if ( ! $this->is_editorial_article() ) { return ''; }
		$categories = get_the_category( get_queried_object_id() );
		return ! empty( $categories ) ? wp_strip_all_tags( (string) $categories[0]->name ) : __( 'News', 'publishrank' );
	}

	/** @return array<int, string> */
	public function keywords() {
		if ( ! $this->is_editorial_article() ) { return array(); }
		$post_id = get_queried_object_id(); $keywords = array();
		$tags = get_the_tags( $post_id );
		if ( ! empty( $tags ) && is_array( $tags ) ) { $keywords = array_map( 'wp_strip_all_tags', wp_list_pluck( $tags, 'name' ) ); }
		$focus = PostMeta::get( $post_id, PostMeta::FOCUS_KEYWORD, '' ); if ( $focus ) { $keywords[] = $focus; }
		$primary = PostMeta::get( $post_id, PostMeta::PRIMARY_KEYPHRASE, '' ); if ( $primary ) { $keywords[] = $primary; }
		$keywords = array_merge( $keywords, PostMeta::get_list( $post_id, PostMeta::SECONDARY_KEYPHRASES ), PostMeta::get_list( $post_id, PostMeta::RELATED_TERMS ) );
		return array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', $keywords ) ) ) );
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
		return $page > 1 ? sprintf( /* translators: %d: current page number in a paginated page title. */ __( 'Page %d', 'publishrank' ), $page ) : '';
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

	private function is_editorial_article() {
		$post = get_queried_object();
		$type = $post instanceof \WP_Post ? get_post_type_object( $post->post_type ) : false;
		return is_singular() && $type && $type->public && $type->publicly_queryable && ! in_array( $post->post_type, array( 'page', 'attachment' ), true );
	}
}
