<?php
namespace PublishRankSEO\Sitemap\News;
use PublishRankSEO\Sitemap\Providers\SitemapProviderInterface;
use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;
use PublishRankSEO\Core\PublicationProfile;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;
final class NewsSitemapProvider implements SitemapProviderInterface {
	const SHARD_SIZE = 1000;
	public function get_name() { return 'News'; }
	public function get_slug() { return 'news'; }
	public function is_enabled() { return (bool) get_option( 'publishrank_seo_news_enabled', true ); }
	public function get_shard_count() { $query = new \WP_Query( $this->query_args( 1, true ) ); return max( 1, (int) ceil( (int) $query->found_posts / $this->limit() ) ); }
	public function get_lastmod() { $args = $this->query_args( 1, true ); $args['orderby'] = 'modified'; $args['order'] = 'DESC'; $args['no_found_rows'] = true; $query = new \WP_Query( $args ); return ! empty( $query->posts ) ? get_post_modified_time( DATE_W3C, true, (int) $query->posts[0] ) : ''; }
	public function prepare_cursor( $steps = 2 ) { SitemapCursorStore::prepare_posts( $this->get_slug(), $this->query_args( $this->limit(), false ), $this->limit(), $steps ); }
	private function post_types() {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', (string) get_option( 'publishrank_seo_news_post_types', 'post' ), -1, PREG_SPLIT_NO_EMPTY ) as $type ) {
			$type = sanitize_key( $type ); $obj = get_post_type_object( $type );
			if ( $obj && $obj->public && $obj->publicly_queryable && 'page' !== $type && 'attachment' !== $type ) { $out[] = $type; }
		}
				return array_values( array_unique( $out ? $out : array( 'post' ) ) );
	}
	private function ids( $option ) { $ids = array(); foreach ( preg_split( '/[\s,]+/', (string) get_option( $option, '' ), -1, PREG_SPLIT_NO_EMPTY ) as $value ) { if ( absint( $value ) ) { $ids[] = absint( $value ); } } return array_values( array_unique( $ids ) ); }
	private function keywords( $post_id ) { if ( ! get_option( 'publishrank_seo_news_keywords', true ) ) { return array(); } $values = array(); $focus = get_post_meta( $post_id, PostMeta::FOCUS_KEYWORD, true ); if ( $focus ) { $values[] = $focus; } $values = array_merge( $values, PostMeta::get_list( $post_id, PostMeta::SECONDARY_KEYPHRASES ), PostMeta::get_list( $post_id, PostMeta::RELATED_TERMS ) ); $entity_topic = PostMeta::get( $post_id, PostMeta::ENTITY_TOPIC, '' ); if ( $entity_topic ) { $values[] = $entity_topic; } $tags = get_the_tags( $post_id ); if ( is_array( $tags ) ) { $values = array_merge( $values, wp_list_pluck( $tags, 'name' ) ); } $categories = get_the_category( $post_id ); if ( is_array( $categories ) ) { $values = array_merge( $values, wp_list_pluck( $categories, 'name' ) ); } return array_slice( array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', $values ) ) ) ), 0, 10 ); }
	public function render_shard( $shard ) {
		$limit = $this->limit(); $post_ids = SitemapCursorStore::post_page( $this->get_slug(), $this->query_args( $limit, false ), $shard, $limit ); $entries = array();
		if ( null === $post_ids ) { return SitemapRenderer::urlset( $entries, array( 'news' => 'http://www.google.com/schemas/sitemap-news/0.9' ) ); }
		$language = substr( preg_replace( '/[^a-zA-Z-]/', '', sanitize_text_field( (string) get_option( 'publishrank_default_language', substr( (string) get_locale(), 0, 2 ) ) ) ), 0, 8 ) ?: 'en'; $name = PublicationProfile::publication_name(); $genre = sanitize_text_field( (string) get_option( 'publishrank_seo_news_genre', '' ) );
			foreach ( $post_ids as $post_id ) {
					if ( '-1' === (string) get_post_meta( $post_id, PostMeta::NEWS_ELIGIBLE, true ) ) { continue; }
				if ( ! $this->is_fresh_timestamp( $post_id ) ) { continue; }
				$robots = strtolower( (string) get_post_meta( $post_id, PostMeta::ROBOTS, true ) ); $url = get_permalink( $post_id ); $title = wp_strip_all_tags( get_the_title( $post_id ) );
			if ( false !== strpos( $robots, 'noindex' ) || ! $url || ! $title ) { continue; }
			$news = '<news:news><news:publication><news:name>' . esc_xml( $name ) . '</news:name><news:language>' . esc_xml( $language ) . '</news:language></news:publication><news:publication_date>' . esc_xml( get_post_time( DATE_W3C, true, $post_id ) ) . '</news:publication_date><news:title>' . esc_xml( $title ) . '</news:title>';
			if ( $genre ) { $news .= '<news:genres>' . esc_xml( $genre ) . '</news:genres>'; } $keywords = $this->keywords( $post_id ); if ( $keywords ) { $news .= '<news:keywords>' . esc_xml( implode( ', ', $keywords ) ) . '</news:keywords>'; } $news .= '</news:news>';
			$entries[] = SitemapRenderer::url( $url, get_post_modified_time( DATE_W3C, true, $post_id ) ?: get_post_time( DATE_W3C, true, $post_id ), $news );
		}
			return SitemapRenderer::urlset( $entries, array( 'news' => 'http://www.google.com/schemas/sitemap-news/0.9' ) );
		}
		private function is_fresh_timestamp( $post_id ) {
			$hours = max( 1, min( 168, (int) get_option( 'publishrank_seo_news_window', 48 ) ) );
			$published = (int) get_post_time( 'U', true, $post_id );
			$now = (int) current_time( 'timestamp', true );
			return $published > 0 && $published <= $now && $published >= ( $now - ( $hours * HOUR_IN_SECONDS ) );
		}
		private function query_args( $limit, $count_only ) { $hours = max( 1, min( 168, (int) get_option( 'publishrank_seo_news_window', 48 ) ) ); $excluded_ids = $this->ids( 'publishrank_seo_news_exclude_ids' ); $excluded_categories = $this->ids( 'publishrank_seo_news_exclude_categories' ); $args = array( 'post_type' => $this->post_types(), 'post_status' => 'publish', 'posts_per_page' => $count_only ? 1 : $limit, 'no_found_rows' => ! $count_only, 'ignore_sticky_posts' => true, 'orderby' => 'date', 'order' => 'DESC', 'date_query' => array( array( 'after' => $hours . ' hours ago', 'inclusive' => true ) ), 'fields' => 'ids', 'meta_query' => array( 'relation' => 'OR', array( 'key' => PostMeta::NEWS_ELIGIBLE, 'compare' => 'NOT EXISTS' ), array( 'key' => PostMeta::NEWS_ELIGIBLE, 'value' => array( '0', '1' ), 'compare' => 'IN' ) ) ); if ( $excluded_ids ) { $args['post__not_in'] = $excluded_ids; } if ( $excluded_categories ) { $args['category__not_in'] = $excluded_categories; } return $args; }
	private function limit() { return max( 1, min( self::SHARD_SIZE, (int) get_option( 'publishrank_seo_news_max_urls', 1000 ) ) ); }
}
