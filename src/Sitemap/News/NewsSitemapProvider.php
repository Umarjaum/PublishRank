<?php
/**
 * Google News sitemap provider.
 *
 * v2.9.43 rewrite. The previous implementation depended on the keyset cursor
 * store (built for 50,000-URL catalogues) and on WP-Cron for shard checkpoints,
 * and it filtered the publication window with a site-timezone relative date
 * query. The News sitemap is a bounded rolling window, so it is now built from
 * one bounded, UTC-based query with no cursor and no background dependency.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Sitemap\News;

use PublishRankSEO\Sitemap\Providers\SitemapProviderInterface;
use PublishRankSEO\Sitemap\Engine\SitemapRenderer;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;
use PublishRankSEO\Core\PublicationProfile;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class NewsSitemapProvider implements SitemapProviderInterface {
	/** Google: at most 1,000 <news:news> entries per sitemap file. */
	const SHARD_SIZE = 1000;
	/** Safety bound on candidate posts examined per request (window-limited). */
	const MAX_CANDIDATES = 50000;
	/** Query page size used while filtering candidates in PHP. */
	const CANDIDATE_BATCH = 500;
	/** Seconds the candidate list may be reused (also invalidated by version bumps). */
	const CANDIDATE_TTL      = 60;
	const DIAGNOSTICS_OPTION = 'publishrank_seo_news_diagnostics';
	const NS                 = 'http://www.google.com/schemas/sitemap-news/0.9';

	/** @var array<string,mixed>|null Per-request memo. */
	private $memo = null;

	/** @var int[] Post IDs excluded in settings (set while collecting). */
	private $excluded_ids = array();

	/** @var int[] Category IDs excluded in settings (set while collecting). */
	private $excluded_cats = array();

	public function get_name() {
		return 'News'; }
	public function get_slug() {
		return 'news'; }
	public function is_enabled() {
		return (bool) get_option( 'publishrank_seo_news_enabled', true ); }

	/** Number of sitemap files; always at least one so an empty window is still a valid sitemap. */
	public function get_shard_count() {
		return max( 1, (int) ceil( count( $this->eligible() ) / $this->limit() ) );
	}

	/** Most recent modification time among entries currently in the sitemap. */
	public function get_lastmod() {
		$latest = 0;
		foreach ( $this->eligible() as $row ) {
			$latest = max( $latest, (int) $row['modified'] ); }
		return $latest ? gmdate( DATE_W3C, $latest ) : '';
	}

	public function render_shard( $shard ) {
		$shard    = max( 1, absint( $shard ) );
		$limit    = $this->limit();
		$rows     = array_slice( $this->eligible(), ( $shard - 1 ) * $limit, $limit );
		$language = self::language();
		$name     = self::publication_name();
		$entries  = array();
		foreach ( $rows as $row ) {
			$news = '<news:news><news:publication><news:name>' . SitemapRenderer::text( $name ) . '</news:name><news:language>' . SitemapRenderer::text( $language ) . '</news:language></news:publication>'
				. '<news:publication_date>' . SitemapRenderer::text( gmdate( DATE_W3C, (int) $row['published'] ) ) . '</news:publication_date>'
				. '<news:title>' . SitemapRenderer::text( $row['title'] ) . '</news:title>';
			// genres/keywords are no longer part of Google's News sitemap specification; emitted only on explicit opt-in.
			if ( apply_filters( 'publishrank_news_emit_legacy_tags', false ) ) {
				$genre = sanitize_text_field( (string) get_option( 'publishrank_seo_news_genre', '' ) );
				if ( $genre ) {
					$news .= '<news:genres>' . SitemapRenderer::text( $genre ) . '</news:genres>'; }
				$keywords = $this->keywords( (int) $row['id'] );
				if ( $keywords ) {
					$news .= '<news:keywords>' . SitemapRenderer::text( implode( ', ', $keywords ) ) . '</news:keywords>'; }
			}
			// Optional, legacy: Google's current News sitemap documentation lists only publication, date and title.
			if ( ! empty( $row['tickers'] ) && get_option( 'publishrank_seo_news_stock_tickers', false ) ) {
				$news .= '<news:stock_tickers>' . SitemapRenderer::text( implode( ', ', array_slice( (array) $row['tickers'], 0, 5 ) ) ) . '</news:stock_tickers>'; }
			$news     .= '</news:news>';
			$entries[] = SitemapRenderer::url( $row['url'], gmdate( DATE_W3C, (int) $row['modified'] ), $news );
		}
		return SitemapRenderer::urlset( $entries, array( 'news' => self::NS ) );
	}

	/**
	 * Ordered list (newest first) of eligible entries, each:
	 * id, url, title, published (UTC ts), modified (UTC ts).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function eligible() {
		if ( null !== $this->memo ) {
			return $this->memo['rows']; }
		$now    = $this->now();
		$cutoff = $now - ( $this->window_hours() * HOUR_IN_SECONDS );
		$key    = 'publishrank_news_rows_' . SitemapCursorStore::version( 'news' );
		$rows   = get_transient( $key );
		if ( ! is_array( $rows ) ) {
			$rows = $this->collect( $cutoff, $now );
			set_transient( $key, $rows, self::CANDIDATE_TTL );
		}
		// Cached rows are re-checked against the clock so entries leave the window exactly on time.
		$mod_cutoff = $this->include_modified() ? $now - ( $this->modified_window_hours() * HOUR_IN_SECONDS ) : PHP_INT_MAX;
		$rows       = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $cutoff, $now, $mod_cutoff ) {
					if ( (int) $row['published'] > $now + 60 ) {
						return false; }
					return (int) $row['published'] >= $cutoff || (int) $row['modified'] >= $mod_cutoff;
				}
			)
		);
		// Newest activity first: a freshly updated article ranks by its update time.
		usort(
			$rows,
			static function ( $a, $b ) {
				$ta = max( (int) $a['published'], (int) $a['modified'] );
				$tb = max( (int) $b['published'], (int) $b['modified'] );
				return $tb <=> $ta ? $tb <=> $ta : (int) $b['id'] <=> (int) $a['id'];
			}
		);
		$this->memo = array( 'rows' => $rows );
		return $rows;
	}

	/** Bounded scan of the window, then PHP-side eligibility checks. */
	private function collect( $cutoff, $now ) {
		$skipped = array();
		$from    = gmdate( 'Y-m-d H:i:s', $cutoff );
		$to      = gmdate( 'Y-m-d H:i:s', $now + 60 );
		// Updated articles: published earlier than the window but modified inside the "updated articles" window.
		$mod_from = $this->include_modified() ? gmdate( 'Y-m-d H:i:s', $now - ( $this->modified_window_hours() * HOUR_IN_SECONDS ) ) : '';
		$where    = static function ( $sql ) use ( $from, $to, $mod_from ) {
			global $wpdb;
			$fresh = $wpdb->prepare( "( {$wpdb->posts}.post_date_gmt >= %s AND {$wpdb->posts}.post_date_gmt <= %s )", $from, $to );
			if ( '' !== $mod_from ) {
				$fresh = '( ' . $fresh . ' OR ' . $wpdb->prepare( "( {$wpdb->posts}.post_modified_gmt >= %s AND {$wpdb->posts}.post_date_gmt <= %s )", $mod_from, $to ) . ' )';
			}
			return $sql . ' AND ' . $fresh . " AND {$wpdb->posts}.post_date_gmt > '1000-01-01 00:00:00'";
		};
		add_filter( 'posts_where', $where, 10, 1 );
		$args          = array(
			'post_type'           => $this->post_types(),
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => self::MAX_CANDIDATES,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'orderby'             => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'fields'              => 'ids',
			'suppress_filters'    => false,
		);
		// Exclusions are applied in PHP (not SQL) so every excluded article is counted in the diagnostics.
		$this->excluded_ids  = $this->ids( 'publishrank_seo_news_exclude_ids' );
		$this->excluded_cats = $this->ids( 'publishrank_seo_news_exclude_categories' );
		$rows = array();
		$scanned = 0;
		$page    = 1;
		do {
			$args['posts_per_page'] = self::CANDIDATE_BATCH;
			$args['paged']          = $page;
			$ids                    = array_map( 'absint', (array) ( new \WP_Query( $args ) )->posts );
			$scanned               += count( $ids );
			if ( $ids && function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'post', $ids ); }
			foreach ( $ids as $id ) {
				$entry = $this->build_entry( $id, $skipped );
				if ( $entry ) {
					$rows[] = $entry; }
			}
			$page++;
		} while ( count( $ids ) === self::CANDIDATE_BATCH && $scanned < self::MAX_CANDIDATES );
		remove_filter( 'posts_where', $where, 10 );
		if ( $scanned > self::MAX_CANDIDATES ) {
			$scanned = self::MAX_CANDIDATES; }
		if ( $scanned >= self::MAX_CANDIDATES ) {
			$skipped['candidate_limit'] = ( $skipped['candidate_limit'] ?? 0 ) + 1;
		}
		$this->record( $scanned, count( $rows ), $skipped );
		return $rows;
	}

	/** @return array<string,mixed>|null */
	private function build_entry( $post_id, array &$skipped ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			$skipped['not_public'] = ( $skipped['not_public'] ?? 0 ) + 1;
			return null; }
		if ( '-1' === (string) get_post_meta( $post_id, PostMeta::NEWS_ELIGIBLE, true ) ) {
			$skipped['excluded_by_editor'] = ( $skipped['excluded_by_editor'] ?? 0 ) + 1;
			return null; }
		if ( $this->excluded_ids && in_array( (int) $post_id, $this->excluded_ids, true ) ) {
			$skipped['excluded_by_id'] = ( $skipped['excluded_by_id'] ?? 0 ) + 1;
			return null; }
		if ( $this->excluded_cats && has_term( $this->excluded_cats, 'category', (int) $post_id ) ) {
			$skipped['excluded_by_category'] = ( $skipped['excluded_by_category'] ?? 0 ) + 1;
			return null; }
		if ( self::is_noindex( $post_id ) ) {
			$skipped['noindex'] = ( $skipped['noindex'] ?? 0 ) + 1;
			return null; }
		$url = $this->canonical_url( $post_id );
		if ( '' === $url ) {
			$skipped['invalid_url'] = ( $skipped['invalid_url'] ?? 0 ) + 1;
			return null; }
		$title = self::clean_title( (string) get_the_title( $post_id ) );
		if ( '' === $title ) {
			$title = self::clean_title( (string) get_post_meta( $post_id, PostMeta::TITLE, true ) ); }
		if ( '' === $title ) {
			$skipped['missing_title'] = ( $skipped['missing_title'] ?? 0 ) + 1;
			return null; }
		$published = (int) get_post_time( 'U', true, $post_id );
		if ( $published <= 0 ) {
			$skipped['missing_date'] = ( $skipped['missing_date'] ?? 0 ) + 1;
			return null; }
		$modified = (int) get_post_modified_time( 'U', true, $post_id );
		return array(
			'id'        => (int) $post_id,
			'url'       => $url,
			'title'     => $title,
			'published' => $published,
			'modified'  => $modified > 0 ? $modified : $published,
			'tickers'   => get_option( 'publishrank_seo_news_stock_tickers', false ) ? PostMeta::parse_tickers( (string) get_post_meta( $post_id, PostMeta::NEWS_STOCK_TICKERS, true ) ) : array(),
		);
	}

	/** Same-host canonical (honours a valid editor override), else the permalink. Empty = unusable. */
	private function canonical_url( $post_id ) {
		$permalink = (string) get_permalink( $post_id );
		$url       = $permalink;
		if ( class_exists( '\PublishRankSEO\Meta\MetaGenerator' ) ) {
			$candidate = ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical_for_post( $post_id );
			if ( $candidate && trailingslashit( $candidate ) !== trailingslashit( PublicationProfile::primary_url() ) ) {
				$url = $candidate; }
		}
		$url   = esc_url_raw( $url );
		$parts = $url ? wp_parse_url( $url ) : array();
		$home  = wp_parse_url( home_url( '/' ) );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return ''; }
		if ( empty( $home['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
			return ''; }
		return $url;
	}

	/** Per-post noindex from PublishRank, plus Yoast and Rank Math when they hold the directive. */
	public static function is_noindex( $post_id ) {
		if ( false !== strpos( strtolower( (string) get_post_meta( $post_id, PostMeta::ROBOTS, true ) ), 'noindex' ) ) {
			return true; }
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true; }
		$rank_math = get_post_meta( $post_id, 'rank_math_robots', true );
		return is_array( $rank_math ) && in_array( 'noindex', $rank_math, true );
	}

	/** Plain-text title: strip tags, decode entities (WordPress texturizes to HTML entities), collapse whitespace. */
	public static function clean_title( $title ) {
		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $title ) );
	}

	/** Publication name exactly as configured (Google requires it to match the name shown on news.google.com). */
	public static function publication_name() {
		$name = trim( preg_replace( '/\s*\([^)]*\)/', '', PublicationProfile::publication_name() ) ); // Spec: omit anything in parentheses.
		return '' !== $name ? $name : trim( wp_strip_all_tags( (string) \PublishRankSEO\Core\PublicationProfile::publication_name() ) );
	}

	/** ISO 639 two/three-letter code; zh-cn / zh-tw for Chinese per Google's specification. */
	public static function language() {
		$raw = (string) get_option( 'publishrank_default_language', '' );
		if ( '' === trim( $raw ) ) {
			$raw = (string) get_locale(); }
		$raw = strtolower( str_replace( '_', '-', preg_replace( '/[^a-zA-Z_-]/', '', $raw ) ) );
		if ( 0 === strpos( $raw, 'zh' ) ) {
			return ( false !== strpos( $raw, 'tw' ) || false !== strpos( $raw, 'hk' ) || false !== strpos( $raw, 'hant' ) ) ? 'zh-tw' : 'zh-cn'; }
		$code = (string) strtok( $raw, '-' );
		return preg_match( '/^[a-z]{2,3}$/', $code ) ? $code : 'en';
	}

	public function window_hours() {
		return max( 1, min( 168, (int) get_option( 'publishrank_seo_news_window', 48 ) ) ); }
	/** Whether articles published earlier but modified recently are listed too (default on). */
	public function include_modified() {
		return (bool) get_option( 'publishrank_seo_news_include_modified', true ); }
	/** Hours an edited article stays listed after its last modification. */
	public function modified_window_hours() {
		return max( 1, min( 168, (int) get_option( 'publishrank_seo_news_modified_window', 48 ) ) ); }
	private function limit() {
		return max( 1, min( self::SHARD_SIZE, (int) get_option( 'publishrank_seo_news_max_urls', 1000 ) ) ); }
	private function now() {
		return time(); }

	private function post_types() {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', (string) get_option( 'publishrank_seo_news_post_types', 'post' ), -1, PREG_SPLIT_NO_EMPTY ) as $type ) {
			$type = sanitize_key( $type );
			$obj  = get_post_type_object( $type );
			if ( $obj && $obj->public && $obj->publicly_queryable && 'page' !== $type && 'attachment' !== $type ) {
				$out[] = $type; }
		}
		return array_values( array_unique( $out ? $out : array( 'post' ) ) );
	}

	private function ids( $option ) {
		$ids = array();
		foreach ( preg_split( '/[\s,]+/', (string) get_option( $option, '' ), -1, PREG_SPLIT_NO_EMPTY ) as $value ) {
			if ( absint( $value ) ) {
				$ids[] = absint( $value ); }
		}
		return array_values( array_unique( $ids ) );
	}

	private function keywords( $post_id ) {
		if ( ! get_option( 'publishrank_seo_news_keywords', true ) ) {
			return array(); }
		$values = array();
		$focus  = get_post_meta( $post_id, PostMeta::FOCUS_KEYWORD, true );
		if ( $focus ) {
			$values[] = $focus; }
		$values = array_merge( $values, PostMeta::get_list( $post_id, PostMeta::SECONDARY_KEYPHRASES ), PostMeta::get_list( $post_id, PostMeta::RELATED_TERMS ) );
		$tags   = get_the_tags( $post_id );
		if ( is_array( $tags ) ) {
			$values = array_merge( $values, wp_list_pluck( $tags, 'name' ) ); }
		return array_slice( array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', $values ) ) ) ), 0, 10 );
	}

	/** Persist the last generation outcome for the admin diagnostics screen. */
	private function record( $candidates, $eligible, array $skipped ) {
		update_option(
			self::DIAGNOSTICS_OPTION,
			array(
				'generated_at' => time(),
				'candidates'   => (int) $candidates,
				'eligible'     => (int) $eligible,
				'skipped'      => $skipped,
				'window_hours' => $this->window_hours(),
				'truncated'    => (int) $candidates >= self::MAX_CANDIDATES,
				'error'        => '',
			),
			false
		);
	}

	/**
	 * Read-only diagnostics for administrators. Never includes file paths, credentials or post content.
	 *
	 * @return array<string,mixed>
	 */
	public function diagnostics() {
		$stored = get_option( self::DIAGNOSTICS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$rows   = $this->eligible();
		$cutoff = $this->now() - ( $this->window_hours() * HOUR_IN_SECONDS );
		$new    = 0;
		foreach ( $rows as $row ) {
			if ( (int) $row['published'] >= $cutoff ) {
				++$new; }
		}
		return array(
			'enabled'      => $this->is_enabled(),
			'window_hours' => $this->window_hours(),
			'include_modified'      => $this->include_modified(),
			'modified_window_hours' => $this->modified_window_hours(),
			'new'          => $new,
			'updated'      => count( $rows ) - $new,
			'eligible'     => count( $rows ),
			'shards'       => $this->get_shard_count(),
			'generated_at' => (int) ( $stored['generated_at'] ?? 0 ),
			'skipped'      => (array) ( $stored['skipped'] ?? array() ),
			'truncated'    => ! empty( $stored['truncated'] ),
			'error'        => (string) ( $stored['error'] ?? '' ),
			'language'     => self::language(),
			'publication'  => self::publication_name(),
		);
	}
}
