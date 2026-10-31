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
			$home_title = $this->resolve_template( TemplateResolver::sanitize_template( (string) get_option( 'publishrank_home_title', '' ) ), $post_id, 'title' );
			if ( '' !== $home_title ) {
				return function_exists( 'mb_substr' ) ? mb_substr( $home_title, 0, 180 ) : substr( $home_title, 0, 180 ); }
		}
		if ( $post_id ) {
			$override = PostMeta::get( $post_id, PostMeta::TITLE, '' );
			if ( '' !== (string) $override ) {
				$resolved = $this->resolve_template( (string) $override, $post_id, 'title' );
				if ( '' !== $resolved ) {
					return $resolved;
				}
				// An override that resolves to nothing (for example only unknown variables) falls back to the global template.
			}
		}
		$context = is_front_page() || is_home() ? 'home' : ( $this->is_editorial_article() ? 'post' : ( is_singular() ? 'page' : 'archive' ) );
		return $this->render_title( $context, $post_id );
	}

	/** Title for a specific post, independent of the main query (REST, editor, CLI). Override wins. */
	public function title_for_post( $post_id ) {
		$post_id  = (int) $post_id;
		$override = PostMeta::get( $post_id, PostMeta::TITLE, '' );
		if ( '' !== (string) $override ) {
			$resolved = $this->resolve_template( (string) $override, $post_id, 'title' );
			if ( '' !== $resolved ) {
				return $resolved; }
		}
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
		// This method feeds pre_get_document_title; calling wp_get_document_title() here would recurse on
		// archives and search results, so the archive title comes from get_the_archive_title() instead.
		$title = $this->resolve_template( $template, $post_id, 'title', array( 'title' => $this->context_title( $context ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 180 ) : substr( $title, 0, 180 );
	}

	/** @return string */
	public function description() {
		$post_id = is_singular() ? get_queried_object_id() : 0;
		if ( is_front_page() || is_home() ) {
			$home_description = SeoData::clean( $this->resolve_template( TemplateResolver::sanitize_template( (string) get_option( 'publishrank_home_description', '' ) ), $post_id, 'description' ) );
			if ( '' !== $home_description ) {
				return function_exists( 'mb_substr' ) ? mb_substr( $home_description, 0, 160 ) : substr( $home_description, 0, 160 ); }
		}
		$override = $post_id ? PostMeta::get( $post_id, PostMeta::DESCRIPTION, '' ) : '';
		// A manually written SEO description always wins over anything fetched automatically. A manually written SEO description must win over the generated excerpt.
		if ( '' !== (string) $override ) {
			$resolved = SeoData::clean( $this->resolve_template( (string) $override, $post_id, 'description' ) );
			if ( '' !== $resolved ) {
				return SeoData::truncate( $resolved, 160 );
			}
		}
		if ( $post_id ) {
			$auto = SeoData::auto_description( $post_id );
			if ( '' !== $auto ) {
				return $auto; }
		}
		$text = $this->archive_description();
		if ( '' === $text ) {
			$text = SeoData::clean( \PublishRankSEO\Core\PublicationProfile::publication_description() ); }
		return SeoData::truncate( $text, 160 );
	}

	/**
	 * Resolve a title or description template through the shared TemplateResolver.
	 * Every template field (global and per-post) goes through here, so they always support the same variables.
	 *
	 * @param string $template Template text.
	 * @param int    $post_id  Post the template belongs to, or 0.
	 * @param string $field    'title' or 'description'.
	 * @param array  $extra    Extra resolver context (for example a non-post title).
	 * @return string Plain text.
	 */
	public function resolve_template( $template, $post_id, $field = 'title', $extra = array() ) {
		$post_id = absint( $post_id );
		$main_id = is_singular() ? (int) get_queried_object_id() : 0;
		$context = array_merge(
			array(
				'field'   => $field,
				'post_id' => $post_id,
				// Pagination belongs to the main request only; a post resolved for REST/editor use has no page suffix.
				'page'    => ( ! $post_id || $post_id === $main_id ) ? TemplateResolver::current_page() : 0,
			),
			is_array( $extra ) ? $extra : array()
		);
		if ( ! $post_id && ! isset( $context['permalink'] ) && false !== stripos( (string) $template, 'permalink' ) ) {
			$context['permalink'] = $this->canonical();
		}
		return TemplateResolver::resolve( $template, $context );
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
		$primary      = PublicationProfile::primary_url();
		$primary_host = strtolower( (string) wp_parse_url( $primary, PHP_URL_HOST ) );
		if ( is_singular() ) {
			return $this->canonical_for_post( get_queried_object_id() );
		}
		if ( is_home() || is_front_page() ) {
			return $primary; }
		if ( function_exists( 'get_pagenum_link' ) ) {
			$paged_url = PublicationProfile::normalize_url( (string) get_pagenum_link( max( 1, (int) get_query_var( 'paged' ) ) ) );
			if ( $paged_url ) {
				return $paged_url;
			}
		}
		$request = isset( $GLOBALS['wp']->request ) ? $GLOBALS['wp']->request : '';
		return ! empty( PublicationProfile::normalize_url( home_url( '/' . ltrim( (string) $request, '/' ) ) ) ) ? PublicationProfile::normalize_url( home_url( '/' . ltrim( (string) $request, '/' ) ) ) : $primary;
	}

	/** Canonical URL for a post: a valid same-site override, else its permalink. */
	public function canonical_for_post( $post_id ) {
		$primary        = PublicationProfile::primary_url();
		$primary_host   = strtolower( (string) wp_parse_url( $primary, PHP_URL_HOST ) );
		$override       = PostMeta::get( $post_id, PostMeta::CANONICAL, '' );
		$candidate      = $override ? PublicationProfile::normalize_url( $override ) : '';
		$candidate_host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
		if ( $candidate && $primary_host && $candidate_host === $primary_host && ! $this->is_media_host( $candidate_host ) ) {
			return $candidate;
		}
		$permalink_raw  = $this->permalink_for_post( $post_id );
		$permalink      = PublicationProfile::normalize_url( (string) $permalink_raw );
		$permalink_host = strtolower( (string) wp_parse_url( $permalink, PHP_URL_HOST ) );
		return $permalink && ( ! $primary_host || $permalink_host === $primary_host ) ? $permalink : $primary;
	}

	/** Use the selected primary category only when the site's permalink structure supports it. */
	private function permalink_for_post( $post_id ) {
		$mode      = get_option( 'publishrank_seo_canonical_category_mode', 'permalink' );
		$structure = (string) get_option( 'permalink_structure', '' );
		if ( 'primary_category' !== $mode || false === strpos( $structure, '%category%' ) ) {
			return get_permalink( $post_id ); }
		$category_id = absint( PostMeta::get( $post_id, PostMeta::PRIMARY_CATEGORY, 0 ) );
		$categories  = get_the_category( $post_id );
		$category    = $category_id ? get_term( $category_id, 'category' ) : ( ! empty( $categories ) ? $categories[0] : null );
		if ( ! $category || is_wp_error( $category ) ) {
			return get_permalink( $post_id ); }
		$filter = static function ( $selected, $post_categories ) use ( $category ) {
			return $category;
		};
		add_filter( 'post_link_category', $filter, 10, 2 );
		$url = get_permalink( $post_id );
		remove_filter( 'post_link_category', $filter, 10 );
		return $url;
	}

	/** @return string */
	public function section() {
		if ( ! $this->is_editorial_article() ) {
			return ''; }
		$categories = get_the_category( get_queried_object_id() );
		return ! empty( $categories ) ? wp_strip_all_tags( (string) $categories[0]->name ) : __( 'News', 'publishrank' );
	}

	/** @return array<int, string> */
	public function keywords() {
		if ( ! $this->is_editorial_article() ) {
			return array(); }
		$post_id  = get_queried_object_id();
		$keywords = array();
		$tags     = get_the_tags( $post_id );
		if ( ! empty( $tags ) && is_array( $tags ) ) {
			$keywords = array_map( 'wp_strip_all_tags', wp_list_pluck( $tags, 'name' ) ); }
		$focus = PostMeta::get( $post_id, PostMeta::FOCUS_KEYWORD, '' );
		if ( $focus ) {
			$keywords[] = $focus; }
		$primary = PostMeta::get( $post_id, PostMeta::PRIMARY_KEYPHRASE, '' );
		if ( $primary ) {
			$keywords[] = $primary; }
		$keywords = array_merge( $keywords, PostMeta::get_list( $post_id, PostMeta::SECONDARY_KEYPHRASES ), PostMeta::get_list( $post_id, PostMeta::RELATED_TERMS ) );
		return array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', $keywords ) ) ) );
	}

	/** @return string */
	public function robots() {
		$directive = is_singular() ? PostMeta::get( get_queried_object_id(), PostMeta::ROBOTS, '' ) : '';
		if ( $directive ) {
			return $this->normalize_robots( $directive ); }
		if ( is_search() || is_404() || is_feed() ) {
			return 'noindex,follow'; }
		if ( is_author() && get_option( 'publishrank_noindex_author_archives', false ) ) {
			return 'noindex,follow'; }
		if ( is_date() && get_option( 'publishrank_noindex_date_archives', false ) ) {
			return 'noindex,follow'; }
		$global = (string) get_option( 'publishrank_seo_default_robots', 'index,follow' );
		return $this->normalize_robots( $global );
	}

	/** @param string $value @return string */
	public function normalize_robots( $value ) {
		$allowed = array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'notranslate', 'indexifembedded', 'max-snippet:0', 'max-snippet:-1', 'max-image-preview:none', 'max-image-preview:standard', 'max-image-preview:large', 'max-video-preview:0', 'max-video-preview:-1' );
		$tokens  = array();
		foreach ( preg_split( '/\s*,\s*/', strtolower( (string) $value ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( in_array( $token, $allowed, true ) ) {
				$tokens[] = $token; }
		}
		$tokens = array_values( array_unique( $tokens ) );
		if ( in_array( 'noindex', $tokens, true ) ) {
			$tokens = array_values( array_diff( $tokens, array( 'index' ) ) ); }
		if ( in_array( 'nofollow', $tokens, true ) ) {
			$tokens = array_values( array_diff( $tokens, array( 'follow' ) ) ); }
		return implode( ',', $tokens ? $tokens : array( 'index', 'follow' ) );
	}

	/** @param string $context @return string */
	private function context_title( $context ) {
		if ( 'home' === $context ) {
			return \PublishRankSEO\Core\PublicationProfile::publication_name();
		}
		if ( function_exists( 'get_the_archive_title' ) && ( is_archive() || is_search() ) ) {
			return wp_strip_all_tags( (string) get_the_archive_title() );
		}
		if ( is_404() ) {
			return __( 'Page not found', 'publishrank' );
		}
		return \PublishRankSEO\Core\PublicationProfile::publication_name();
	}

	private function is_media_host( $host ) {
		$hosts = array( get_option( 'publishrank_media_host', '' ), get_option( 'publishrank_cdn_host', '' ), get_option( 'publishrank_video_host', '' ) );
		foreach ( $hosts as $configured ) {
			$configured_host = strtolower( (string) preg_replace( '#^https?://#', '', (string) $configured ) );
			$configured_host = preg_replace( '#/.*$#', '', $configured_host );
			if ( $configured_host && 0 === strcasecmp( $configured_host, (string) $host ) ) {
				return true; }
		}
		return false;
	}

	private function is_editorial_article() {
		$post = get_queried_object();
		$type = $post instanceof \WP_Post ? get_post_type_object( $post->post_type ) : false;
		return is_singular() && $type && $type->public && $type->publicly_queryable && ! in_array( $post->post_type, array( 'page', 'attachment' ), true );
	}
}
