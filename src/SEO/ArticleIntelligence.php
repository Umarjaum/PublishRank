<?php
/**
 * Article SEO Intelligence.
 *
 * A fully local analysis engine: it scores an article across keyphrase, headline, snippet,
 * structure, readability, media, links and newsroom signals, and derives keyphrase, meta
 * description, headline, image-alt and internal-link suggestions from the article text and
 * the local database. It never calls an external API, service, model or CDN.
 *
 * v2.9-upgrade: adds severities, "why it matters", one-click fix metadata, an interactive
 * fix studio (keyphrase/description/headline/alt/internal links) with Apply/Regenerate/
 * Cancel, and an advanced score dashboard (SVG ring, category bars, priority issues, tally,
 * honest previous-score comparison). Scoring weights
 * and check IDs are unchanged; all stored meta keys and hooks are preserved.
 */
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class ArticleIntelligence {
	const SCORE_META     = '_publishrank_seo_intel_score';
	const SUMMARY_META   = '_publishrank_seo_intel_summary';
	const AJAX_ACTION    = 'publishrank_article_intel';
	const BATCH_ACTION   = 'publishrank_intel_batch';
	const SUGGEST_ACTION = 'publishrank_intel_suggest';
	const APPLY_ACTION   = 'publishrank_intel_apply';
	const MAX_WORDS      = 6000;

	/* ------------------------------------------------------------------ *
	 * WordPress integration
	 * ------------------------------------------------------------------ */

	public static function enabled() {
		return (bool) apply_filters( 'publishrank_article_intelligence_enabled', (bool) get_option( 'publishrank_article_intel_enabled', 1 ) );
	}

	public function register() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ), 10, 2 );
			add_action( 'save_post', array( $this, 'store_on_save' ), 40, 3 );
			add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
		add_action( 'wp_ajax_' . self::SUGGEST_ACTION, array( $this, 'suggest' ) );
		add_action( 'wp_ajax_' . self::APPLY_ACTION, array( $this, 'apply' ) );
		add_action( 'admin_post_' . self::BATCH_ACTION, array( $this, 'batch' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public static function supported_post_types() {
		$types = get_post_types(
			array(
				'public'              => true,
				'show_ui'             => true,
				'exclude_from_search' => false,
			),
			'names'
		);
		$out   = array();
		foreach ( $types as $post_type ) {
			if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
				$out[] = $post_type;
				continue; }
			if ( 'attachment' === $post_type || is_post_type_hierarchical( $post_type ) ) {
				continue; }
			if ( post_type_supports( $post_type, 'editor' ) && post_type_supports( $post_type, 'title' ) ) {
				$out[] = $post_type; }
		}
		return array_values( array_unique( (array) apply_filters( 'publishrank_article_intelligence_post_types', $out ) ) );
	}

	public function meta_box( $post_type, $post = null ) {
		if ( $post instanceof \WP_Post ) {
			$post_type = $post->post_type; }
		$post_type = is_string( $post_type ) && '' !== $post_type ? $post_type : 'post';
		if ( ! in_array( $post_type, self::supported_post_types(), true ) ) {
			return; }
		add_meta_box(
			'publishrank_article_intelligence',
			__( 'PublishRank SEO Score & Recommendations', 'publishrank' ),
			array( __CLASS__, 'render_panel' ),
			$post_type,
			'normal',
			'high'
		);
	}

	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::supported_post_types(), true ) ) {
			return; }
		global $post;
		$current_post_id = $post instanceof \WP_Post ? absint( $post->ID ) : ( isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$css_path     = PUBLISHRANK_SEO_PATH . 'assets/css/article-intel.css';
		$script_path  = PUBLISHRANK_SEO_PATH . 'assets/js/article-intel.js';
		$css_version  = \PublishRankSEO\Core\Branding::version() . '.' . ( file_exists( $css_path ) ? (string) filemtime( $css_path ) : '0' );
		$js_version   = \PublishRankSEO\Core\Branding::version() . '.' . ( file_exists( $script_path ) ? (string) filemtime( $script_path ) : '0' );
		wp_enqueue_style( 'publishrank-article-intel', \PublishRankSEO\Core\Branding::asset_url( 'css/article-intel.css' ), array(), $css_version );
		wp_enqueue_script( 'publishrank-article-intel', \PublishRankSEO\Core\Branding::asset_url( 'js/article-intel.js' ), array( 'jquery' ), $js_version, true );
		wp_localize_script(
			'publishrank-article-intel',
			'publishrankIntel',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::AJAX_ACTION ),
				'action'        => self::AJAX_ACTION,
				'suggestAction' => self::SUGGEST_ACTION,
				'suggestNonce'  => wp_create_nonce( self::SUGGEST_ACTION ),
				'applyAction'   => self::APPLY_ACTION,
				'applyNonce'    => wp_create_nonce( self::APPLY_ACTION ),
				'postId'        => $current_post_id,
				'analyzing'     => __( 'Analyzing…', 'publishrank' ),
				'failed'        => __( 'Analysis could not be completed. Save the draft and try again.', 'publishrank' ),
				'i18n'          => array(
					'applied'   => __( 'Saved.', 'publishrank' ),
					'applyFail' => __( 'Could not save the value. Please try again.', 'publishrank' ),
					'copied'    => __( 'Link copied.', 'publishrank' ),
					'copyFail'  => __( 'Copy failed — select the URL manually.', 'publishrank' ),
					'empty'     => __( 'Enter a value first.', 'publishrank' ),
					'altLibraryOnly' => __( 'Saved to the media library. Open the image block and paste the alt text there to finish.', 'publishrank' ),
					'introInserted'  => __( 'First paragraph updated. Review it, then update the post.', 'publishrank' ),
					'introFail'      => __( 'Could not find a first paragraph. Use Copy text and paste it into your content.', 'publishrank' ),
					'justNow'   => __( 'just now', 'publishrank' ),
					'noSuggest' => __( 'No reliable suggestion could be derived from the current content.', 'publishrank' ),
					'reanalyze' => __( 'Re-analyze', 'publishrank' ),
					'wellOptimized' => __( 'Well optimized', 'publishrank' ),
					'needsImprovement' => __( 'Needs improvement', 'publishrank' ),
					'needsWork' => __( 'Needs work', 'publishrank' ),
					'analysisComplete' => __( 'Analysis complete. SEO score: %1$d out of 100. %2$s.', 'publishrank' ),
					'characterCountSingle' => __( '%d character (target 70–160)', 'publishrank' ),
					'characterCountPlural' => __( '%d characters (target 70–160)', 'publishrank' ),
				),
			)
		);
	}

	public function store_on_save( $post_id, $post, $update = true ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! $post instanceof \WP_Post ) {
			return; }
		if ( 'auto-draft' === $post->post_status || ! in_array( $post->post_type, self::supported_post_types(), true ) ) {
			return; }
		self::store( $post );
	}

	/* ------------------------------------------------------------------ *
	 * Request data collection (shared by analyze/suggest endpoints)
	 * ------------------------------------------------------------------ */

	private static function collect_request_data( $action ) {
		check_ajax_referer( $action, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$allowed = $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to analyze this article.', 'publishrank' ) ), 403 );
		}
		$text    = static function ( $key, $limit ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return self::substr( $value, $limit );
		};
		$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post    = $post_id ? get_post( $post_id ) : null;
			$data    = $post instanceof \WP_Post ? self::data_from_post( $post ) : self::blank_data();
			$stored  = $data;
		return array(
			'post_id' => $post_id,
			'data'    => array_merge(
				$data,
				array(
					'post_id'       => $post_id,
					'title'         => $text( 'title', 300 ),
					'content'       => self::substr( $content, 400000 ),
					'excerpt'       => $text( 'excerpt', 500 ),
					'slug'          => sanitize_title( $text( 'slug', 200 ) ),
					'seo_title'     => $text( 'seo_title', 255 ),
						// Keep a saved override when a block-editor meta panel has not mounted yet.
						// A submitted non-empty value still wins, so unsaved edits are analyzed live.
						'description'   => '' !== $text( 'description', 320 ) ? $text( 'description', 320 ) : (string) ( $stored['description'] ?? '' ),
					'keyphrase'     => $text( 'keyphrase', 100 ),
					'has_thumbnail' => ! empty( $_POST['has_thumbnail'] ) ? true : ( $post instanceof \WP_Post && has_post_thumbnail( $post ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				)
			),
		);
	}

	/** Live analysis of unsaved editor content. */
	public function ajax() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		$req      = self::collect_request_data( self::AJAX_ACTION );
		$report   = self::analyze( $req['data'], true );
		$previous = $req['post_id'] ? self::stored( $req['post_id'] ) : null;
		wp_send_json_success(
			array(
				'score'    => $report['score'],
				'html'     => self::render_report( $report, $previous ),
				'stats'    => $report['stats'],
				'previous' => $previous,
			)
		);
	}

	/** Generate (or regenerate) one kind of suggestion from the current editor payload. */
	public function suggest() {
		check_ajax_referer( self::SUGGEST_ACTION, 'nonce' );
		$req     = self::collect_request_data( self::SUGGEST_ACTION );
		$d       = $req['data'];
		$kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$variant = isset( $_POST['variant'] ) ? absint( wp_unslash( $_POST['variant'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$html    = self::clean_html( (string) $d['content'] );
		$paras   = self::paragraphs( $html );
		$words   = array_slice( self::tokens( implode( ' ', $paras ) ), 0, self::MAX_WORDS );
		$title   = trim( wp_strip_all_tags( (string) $d['title'] ) );
		$out     = array();
		switch ( $kind ) {
			case 'keyphrase':
				$out = self::suggest_keyphrases( $title, $words, self::headings( $html ), (string) $d['terms'] );
				break;
			case 'description':
				$one = self::suggest_description( $paras, (string) $d['excerpt'], $variant );
				$out = '' !== $one ? array(
					'text'   => $one,
					'length' => self::len( $one ),
				) : array();
				break;
			case 'headline':
				$one = self::suggest_headline( $title, trim( (string) $d['keyphrase'] ) );
				$out = '' !== $one ? array(
					'text'   => $one,
					'length' => self::len( $one ),
				) : array();
				break;
			case 'alt':
				$out = self::suggest_alt_texts( $html, (int) $d['post_id'] );
				break;
			case 'links':
				$out = self::suggest_internal_links( (int) $d['post_id'], $title, $words );
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown suggestion kind.', 'publishrank' ) ), 400 );
		}
		wp_send_json_success(
			array(
				'kind'  => $kind,
				'items' => $out,
			)
		);
	}

	/** Persist one editor-approved SEO value. Never writes without an explicit request. */
	public function apply() {
		check_ajax_referer( self::APPLY_ACTION, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update this article.', 'publishrank' ) ), 403 );
		}
		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ok    = false;
		switch ( $field ) {
			case 'keyphrase':
				update_post_meta( $post_id, PostMeta::FOCUS_KEYWORD, sanitize_text_field( self::substr( $value, 100 ) ) );
				$ok = true;
				break;
			case 'description':
				update_post_meta( $post_id, PostMeta::DESCRIPTION, sanitize_text_field( self::substr( $value, 300 ) ) );
				$ok = true;
				break;
			case 'seo_title':
				update_post_meta( $post_id, PostMeta::TITLE, sanitize_text_field( self::substr( $value, 255 ) ) );
				$ok = true;
				break;
			case 'alt':
				$att = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( $att && wp_attachment_is_image( $att ) && current_user_can( 'edit_post', $att ) ) {
					// Keep the media-library alt in step so the same image gets this alt when it is reused.
					update_post_meta( $att, '_wp_attachment_image_alt', sanitize_text_field( self::substr( $value, 255 ) ) );
				}
				// The alt attribute itself lives in the post content; the editor script writes it there.
				$ok = true;
				break;
		}
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Nothing was saved.', 'publishrank' ) ), 400 );
		}
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post ) {
			self::store( $post ); }
		wp_send_json_success( array( 'stored' => self::stored( $post_id ) ) );
	}

	/** Analyze the next batch of articles that have no stored score. */
	public function batch() {
		\PublishRankSEO\Admin\AdminAssets::guard( self::BATCH_ACTION );
		$ids  = get_posts(
			array(
				'post_type'        => self::supported_post_types(),
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array(
					array(
						'key'     => self::SCORE_META,
						'compare' => 'NOT EXISTS',
					),
				), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$done = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				self::store( $post );
				++$done; }
		}
		/* translators: %d: number of article. */
		\PublishRankSEO\Admin\AdminAssets::done( 'publishrank-insights', 'success', sprintf( _n( 'Analyzed %d article.', 'Analyzed %d articles.', $done, 'publishrank' ), $done ) );
	}

	/* ------------------------------------------------------------------ *
	 * Data helpers
	 * ------------------------------------------------------------------ */

	public static function blank_data() {
		return array(
			'post_id'       => 0,
			'title'         => '',
			'content'       => '',
			'excerpt'       => '',
			'slug'          => '',
			'seo_title'     => '',
			'description'   => '',
			'keyphrase'     => '',
			'terms'         => '',
			'has_thumbnail' => false,
			'has_author'    => false,
			'modified'      => 0,
			'citations'     => '',
			'home_host'     => '',
		);
	}

	public static function data_from_post( $post ) {
		$id    = (int) $post->ID;
		$terms = array();
		if ( function_exists( 'wp_get_post_terms' ) ) {
			foreach ( array( 'category', 'post_tag' ) as $tax ) {
				$ts = wp_get_post_terms( $id, $tax, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $ts ) ) {
					$terms = array_merge( $terms, $ts ); }
			}
		}
		return array_merge(
			self::blank_data(),
			array(
				'post_id'       => $id,
				'title'         => (string) $post->post_title,
				'content'       => (string) $post->post_content,
				'excerpt'       => (string) $post->post_excerpt,
				'slug'          => (string) $post->post_name,
				'seo_title'     => (string) PostMeta::get( $id, PostMeta::TITLE, '' ),
				'description'   => (string) PostMeta::get( $id, PostMeta::DESCRIPTION, '' ),
				'keyphrase'     => (string) PostMeta::get( $id, PostMeta::FOCUS_KEYWORD, '' ),
				'terms'         => implode( ', ', array_unique( array_filter( $terms ) ) ),
				'has_thumbnail' => (bool) has_post_thumbnail( $post ),
				'has_author'    => (bool) ( PostMeta::get( $id, PostMeta::AUTHOR_NAME, '' ) || get_userdata( (int) $post->post_author ) ),
				'modified'      => (int) get_post_modified_time( 'U', true, $post ),
				'citations'     => (string) PostMeta::get( $id, PostMeta::CITATIONS, '' ),
			)
		);
	}

	public static function store( $post ) {
		$report = self::analyze( self::data_from_post( $post ), true );
		$fail   = array();
		foreach ( $report['checks'] as $check ) {
			if ( 'bad' === $check['status'] ) {
				$fail[] = $check['id']; }
		}
		$prev       = get_post_meta( $post->ID, self::SUMMARY_META, true );
		$prev_score = is_array( $prev ) && isset( $prev['score'] ) ? (int) $prev['score'] : null;
		update_post_meta( $post->ID, self::SCORE_META, (int) $report['score'] );
		update_post_meta(
			$post->ID,
			self::SUMMARY_META,
			array(
				'score'      => (int) $report['score'],
				'fail'       => array_slice( $fail, 0, 8 ),
				'at'         => gmdate( 'c' ),
				'prev_score' => $prev_score,
			)
		);
		delete_transient( 'pr_intel_links_' . (int) $post->ID );
		return $report;
	}

	/** @return array{score:int,fail:string[],at:string,prev_score:?int}|null */
	public static function stored( $post_id ) {
		$summary = get_post_meta( $post_id, self::SUMMARY_META, true );
		return is_array( $summary ) && isset( $summary['score'] )
			? array(
				'score'      => (int) $summary['score'],
				'fail'       => (array) ( $summary['fail'] ?? array() ),
				'at'         => (string) ( $summary['at'] ?? '' ),
				'prev_score' => isset( $summary['prev_score'] ) ? (int) $summary['prev_score'] : null,
			)
			: null;
	}

	/* ------------------------------------------------------------------ *
	 * Analysis
	 * ------------------------------------------------------------------ */

	public static function analyze( array $d, $site = true ) {
		$d          = array_merge( self::blank_data(), $d );
		$html       = self::clean_html( (string) $d['content'] );
		$paragraphs = self::paragraphs( $html );
		$text       = implode( "\n", $paragraphs );
		$words      = self::tokens( $text );
		if ( count( $words ) > self::MAX_WORDS ) {
			$words = array_slice( $words, 0, self::MAX_WORDS ); }
		$word_count            = count( $words );
		$headings              = self::headings( $html );
		$images                = self::images( $html );
		$links                 = self::links( $html, (string) $d['home_host'] );
		$sentences             = self::sentences( $paragraphs );
		$latin                 = self::is_latin( $text );
		$keyphrase             = trim( (string) $d['keyphrase'] );
		$keyphrase_occurrences = 0;
		$keyphrase_density     = 0.0;
		$keyphrase_coverage    = array(
			'title'       => false,
			'description' => false,
			'slug'        => false,
			'intro'       => false,
			'heading'     => false,
			'alt'         => false,
			'body'        => false,
		);
		$title                 = trim( wp_strip_all_tags( (string) $d['title'] ) );
		$seo_title             = trim( (string) $d['seo_title'] );
		$serp_title            = '' !== $seo_title ? $seo_title : $title;
		$description           = trim( (string) $d['description'] );

		$checks = array();
		$add    = static function ( $id, $group, $status, $weight, $message ) use ( &$checks ) {
			$checks[] = array(
				'id'      => $id,
				'group'   => $group,
				'status'  => $status,
				'weight'  => (int) $weight,
				'message' => $message,
			);
		};

		/* Focus-keyword scoring is intentionally disabled. */

		/* ---- Headline ---- */
		$tlen = self::len( $title );
		if ( 0 === $tlen ) {
			$add( 'headline_missing', 'headline', 'bad', 3, __( 'Add a headline.', 'publishrank' ) );
		} else {
			$status = ( $tlen >= 30 && $tlen <= 65 ) ? 'good' : ( ( $tlen >= 20 && $tlen <= 80 ) ? 'ok' : 'bad' );
			/* translators: %d: numeric count or measurement described by the message; %d: number of characters. */
			$add( 'headline_length', 'headline', $status, 2, 'good' === $status ? sprintf( __( 'The headline length (%d characters) works well.', 'publishrank' ), $tlen ) : sprintf( __( 'The headline is %d characters; aim for 30 to 65.', 'publishrank' ), $tlen ) );
			$hwords = count( self::tokens( $title ) );
			$add( 'headline_words', 'headline', ( $hwords >= 5 && $hwords <= 14 ) ? 'good' : 'ok', 1, ( $hwords >= 5 && $hwords <= 14 ) ? __( 'The headline has a readable number of words.', 'publishrank' ) : __( 'Aim for roughly 5 to 14 words in the headline.', 'publishrank' ) );
			$letters = preg_match_all( '/\p{L}/u', $title );
			$upper   = preg_match_all( '/\p{Lu}/u', $title );
			$shouty  = $letters > 12 && ( $upper / max( 1, $letters ) ) > 0.6;
			$hype    = (bool) preg_match( '/(!{2,}|\?{2,}|\?!|!\?)|\b(you won\'t believe|shocking|this one trick|what happened next|will blow your mind|gone wrong)\b/iu', $title );
			$add( 'headline_tone', 'headline', ( $shouty || $hype ) ? 'bad' : 'good', 1, ( $shouty || $hype ) ? __( 'The headline reads as sensational (capitals or clickbait phrasing), which can hurt trust and News eligibility.', 'publishrank' ) : __( 'The headline tone is straightforward.', 'publishrank' ) );
		}

		/* ---- Snippet ---- */
		$pixels = self::pixel_width( $serp_title );
		if ( '' !== $serp_title ) {
			/* translators: %1$d: pixel width or size; %2$d: pixel width or size. */
			$add( 'snippet_title_width', 'snippet', $pixels <= 580 ? 'good' : ( $pixels <= 640 ? 'ok' : 'bad' ), 2, $pixels <= 580 ? sprintf( __( 'The search title should fit without truncation (about %1$d of %2$d px).', 'publishrank' ), $pixels, 580 ) : sprintf( __( 'The search title may be truncated in results (about %1$d of %2$d px).', 'publishrank' ), $pixels, 580 ) );
		}
		$dlen = self::len( $description );
		if ( 0 === $dlen ) {
			$add( 'snippet_description', 'snippet', 'bad', 2, __( 'Write a 70–160 character meta description; without one, search engines choose a snippet for you.', 'publishrank' ) );
		} elseif ( $dlen >= 70 && $dlen <= 160 ) {
			$add( 'snippet_description', 'snippet', 'good', 2, __( 'The meta description length is good.', 'publishrank' ) );
		} else {
			/* translators: %d: number of characters. */
			$add( 'snippet_description', 'snippet', 'ok', 2, sprintf( __( 'The meta description is %d characters; aim for 70 to 160.', 'publishrank' ), $dlen ) );
		}

		/* ---- Structure ---- */
		if ( $word_count < 150 ) {
			/* translators: %d: number of words. */
			$add( 'structure_length', 'structure', 'bad', 2, sprintf( __( 'Only %d words: too thin for most news coverage. Add context, sourcing and detail.', 'publishrank' ), $word_count ) );
		} elseif ( $word_count < 300 ) {
			/* translators: %d: number of words. */
			$add( 'structure_length', 'structure', 'ok', 2, sprintf( __( '%d words: acceptable for a brief, but 300+ gives readers and search engines more to work with.', 'publishrank' ), $word_count ) );
		} else {
			/* translators: %d: number of words. */
			$add( 'structure_length', 'structure', 'good', 2, sprintf( __( '%d words: a substantial article.', 'publishrank' ), $word_count ) );
		}
		$h2plus = array_filter(
			$headings,
			static function ( $h ) {
				return $h['level'] >= 2;
			}
		);
		if ( $word_count > 300 ) {
			$add( 'structure_subheadings', 'structure', $h2plus ? 'good' : 'bad', 2, $h2plus ? __( 'Subheadings break the article into scannable sections.', 'publishrank' ) : __( 'Add descriptive subheadings (H2/H3) to break up the article.', 'publishrank' ) );
			if ( $h2plus ) {
				$gap = self::longest_gap_without_heading( $html );
				$add( 'structure_heading_spacing', 'structure', $gap > 350 ? 'ok' : 'good', 1, $gap > 350 ? __( 'Some sections run over 350 words without a subheading.', 'publishrank' ) : __( 'Subheadings are spaced evenly.', 'publishrank' ) );
			}
		}
		$prev    = 1;
		$skipped = false;
		$h1      = false;
		foreach ( $headings as $h ) {
			if ( 1 === $h['level'] ) {
				$h1 = true; }
			if ( $h['level'] > $prev + 1 && $prev >= 2 ) {
				$skipped = true; }
			$prev = $h['level'];
		}
		if ( $headings ) {
			$add( 'structure_hierarchy', 'structure', ( $skipped || $h1 ) ? 'ok' : 'good', 1, $h1 ? __( 'The content contains an H1; the theme already outputs the headline as H1, so use H2 and below.', 'publishrank' ) : ( $skipped ? __( 'Heading levels skip a step (for example H2 to H4). Keep the hierarchy sequential.', 'publishrank' ) : __( 'The heading hierarchy is clean.', 'publishrank' ) ) );
		}
		if ( $paragraphs ) {
			$long = 0;
			foreach ( $paragraphs as $p ) {
				if ( count( self::tokens( $p ) ) > 150 ) {
					++$long; }
			}
			$add( 'structure_paragraphs', 'structure', 0 === $long ? 'good' : 'ok', 1, 0 === $long ? __( 'Paragraph lengths are comfortable.', 'publishrank' ) : __( 'Some paragraphs exceed 150 words; split them for easier reading.', 'publishrank' ) );
			$lede = count( self::tokens( $paragraphs[0] ) );
			if ( $word_count >= 150 ) {
				$add( 'structure_lede', 'structure', $lede <= 90 ? 'good' : 'ok', 1, $lede <= 90 ? __( 'The opening paragraph is tight.', 'publishrank' ) : __( 'The opening paragraph is long; lead with the key facts in a few sentences.', 'publishrank' ) );
			}
		}

		/* ---- Readability ---- */
		$sentence_count = count( $sentences );
		if ( $sentence_count >= 3 ) {
			$long_sentences = 0;
			foreach ( $sentences as $s ) {
				if ( count( self::tokens( $s ) ) > 25 ) {
					++$long_sentences; }
			}
			$share = $long_sentences / $sentence_count;
			/* translators: %d: percentage value. */
			$add( 'read_sentence_length', 'readability', $share <= 0.25 ? 'good' : ( $share <= 0.4 ? 'ok' : 'bad' ), 2, $share <= 0.25 ? sprintf( __( 'Only %d%% of sentences are long (25+ words).', 'publishrank' ), round( $share * 100 ) ) : sprintf( __( '%d%% of sentences exceed 25 words; shorten some.', 'publishrank' ), round( $share * 100 ) ) );
			if ( $latin && $word_count >= 50 ) {
				$ease = self::flesch( $words, $sentence_count );
				/* translators: %s: Flesch reading-ease score. */
				$add( 'read_flesch', 'readability', $ease >= 60 ? 'good' : ( $ease >= 45 ? 'ok' : 'bad' ), 2, $ease >= 60 ? sprintf( __( 'Flesch reading ease is %s: easy to read.', 'publishrank' ), self::num( $ease ) ) : sprintf( __( 'Flesch reading ease is %s: prefer shorter sentences and simpler words.', 'publishrank' ), self::num( $ease ) ) );
				$passive    = 0;
				$transition = 0;
				foreach ( $sentences as $s ) {
					if ( preg_match( '/\b(?:is|are|was|were|be|been|being)\s+(?:\w+ly\s+)?\w+(?:ed|en)\b/i', $s ) ) {
						++$passive; }
					if ( preg_match( self::transition_pattern(), $s ) ) {
						++$transition; }
				}
				$p_share = $passive / $sentence_count;
				/* translators: %d: percentage value. */
				$add( 'read_passive', 'readability', $p_share <= 0.1 ? 'good' : ( $p_share <= 0.2 ? 'ok' : 'bad' ), 1, $p_share <= 0.1 ? sprintf( __( 'Passive voice is kept low (%d%% of sentences).', 'publishrank' ), round( $p_share * 100 ) ) : sprintf( __( '%d%% of sentences look passive; prefer active constructions.', 'publishrank' ), round( $p_share * 100 ) ) );
				$t_share = $transition / $sentence_count;
				/* translators: %d: percentage value. */
				$add( 'read_transitions', 'readability', $t_share >= 0.2 ? 'good' : ( $t_share >= 0.1 ? 'ok' : 'bad' ), 1, $t_share >= 0.2 ? sprintf( __( '%d%% of sentences use transition words, which helps flow.', 'publishrank' ), round( $t_share * 100 ) ) : sprintf( __( 'Only %d%% of sentences use transition words; connect ideas more clearly.', 'publishrank' ), round( $t_share * 100 ) ) );
			} elseif ( ! $latin ) {
				$add( 'read_language', 'readability', 'info', 0, __( 'Readability formulas are tuned for Latin-script text and were not applied to this article.', 'publishrank' ) );
			}
			if ( $sentence_count >= 6 ) {
				$run     = 1;
				$max_run = 1;
				$last    = '';
				foreach ( $sentences as $s ) {
					$first   = self::tokens( $s );
					$first   = $first ? $first[0] : '';
					$run     = ( '' !== $first && $first === $last ) ? $run + 1 : 1;
					$max_run = max( $max_run, $run );
					$last    = $first;
				}
				$add( 'read_repetition', 'readability', $max_run >= 3 ? 'ok' : 'good', 1, $max_run >= 3 ? __( 'Three or more consecutive sentences start with the same word; vary the openings.', 'publishrank' ) : __( 'Sentence openings are varied.', 'publishrank' ) );
			}
		}

		/* ---- Media ---- */
		$add( 'media_featured', 'media', $d['has_thumbnail'] ? 'good' : 'bad', 2, $d['has_thumbnail'] ? __( 'A featured image is set for previews and Google News.', 'publishrank' ) : __( 'Set a featured image so the article previews well in search and news surfaces.', 'publishrank' ) );
		if ( $images ) {
			$missing_alt = 0;
			foreach ( $images as $img ) {
				if ( '' === $img['alt'] ) {
					++$missing_alt; }
			}
			$total = count( $images );
			if ( 0 === $missing_alt ) {
				$add( 'media_alt', 'media', 'good', 1, __( 'All images have alternative text.', 'publishrank' ) );
			} else {
				/* translators: %d: number of image. */
				$add( 'media_alt', 'media', ( $missing_alt / $total ) > 0.5 ? 'bad' : 'ok', 1, sprintf( _n( '%d image is missing alt text.', '%d images are missing alt text.', $missing_alt, 'publishrank' ), $missing_alt ) );
			}
		}
		$quality = self::image_quality( $html );
		if ( 0 === ( $quality['large'] + $quality['oversize'] + $quality['legacy'] ) ) {
			$add( 'media_weight', 'media', 'good', 1, __( 'Article images are within the recommended file-size budget.', 'publishrank' ) );
			$add( 'media_dimensions', 'media', 'good', 1, __( 'Images are sized for the configured maximum width.', 'publishrank' ) );
			$add( 'media_format', 'media', 'good', 1, __( 'Images use modern WebP/AVIF formats where available.', 'publishrank' ) );
		} else {
			if ( $quality['large'] ) {
				/* translators: %d: number of image. */
				$add( 'media_weight', 'media', 'ok', 1, sprintf( _n( '%d image file exceeds 350 KB; compress it to speed up the page.', '%d image files exceed 350 KB; compress them to speed up the page.', $quality['large'], 'publishrank' ), $quality['large'] ) );
			} else {
				$add( 'media_weight', 'media', 'good', 1, __( 'Article images are within the recommended file-size budget.', 'publishrank' ) );
			}
			if ( $quality['oversize'] ) {
				/* translators: %d: number of image. */
				$add( 'media_dimensions', 'media', 'ok', 1, sprintf( _n( '%d image is wider than the configured maximum; serve a smaller size.', '%d images are wider than the configured maximum; serve smaller sizes.', $quality['oversize'], 'publishrank' ), $quality['oversize'] ) );
			} else {
				$add( 'media_dimensions', 'media', 'good', 1, __( 'Images are sized for the configured maximum width.', 'publishrank' ) );
			}
			if ( $quality['legacy'] ) {
				/* translators: %d: number of image. */
				$add( 'media_format', 'media', 'ok', 1, sprintf( _n( '%d image uses a legacy format; convert it to WebP or AVIF when practical.', '%d images use legacy formats; convert them to WebP or AVIF when practical.', $quality['legacy'], 'publishrank' ), $quality['legacy'] ) );
			} else {
				$add( 'media_format', 'media', 'good', 1, __( 'Images use modern WebP/AVIF formats where available.', 'publishrank' ) );
			}
		}

		/* ---- Links ---- */
		$internal = 0;
		$external = 0;
		$generic  = 0;
		foreach ( $links as $link ) {
			if ( $link['internal'] ) {
				++$internal;
			} else {
				++$external; }
			if ( preg_match( '/^(click here|here|read more|more|link|this|this link|learn more|source)$/iu', $link['text'] ) ) {
				++$generic; }
		}
		$need = $word_count >= 600 ? 2 : 1;
		if ( $word_count >= 150 ) {
			/* translators: %d: numeric count or measurement described by the message. */
			$add( 'links_internal', 'links', $internal >= $need ? 'good' : ( $internal > 0 ? 'ok' : 'bad' ), 2, $internal >= $need ? sprintf( _n( '%d internal link found.', '%d internal links found.', $internal, 'publishrank' ), $internal ) : __( 'Link to related coverage on your own site to help readers and crawlers.', 'publishrank' ) );
			$cited = $external > 0 || '' !== trim( (string) $d['citations'] );
			$add( 'links_external', 'links', $cited ? 'good' : 'ok', 1, $cited ? __( 'The article attributes or cites outside sources.', 'publishrank' ) : __( 'Link to or cite primary sources where you report from them.', 'publishrank' ) );
		}
		if ( $links ) {
			$add( 'links_anchor_text', 'links', 0 === $generic ? 'good' : 'ok', 1, 0 === $generic ? __( 'Link anchor text is descriptive.', 'publishrank' ) : __( 'Replace generic anchors such as “click here” with descriptive text.', 'publishrank' ) );
			$density = $word_count > 0 ? ( count( $links ) / $word_count ) * 100 : 0;
			if ( $density > 5 ) {
				$add( 'links_density', 'links', 'ok', 1, __( 'There are many links for the length of the article; keep only the most useful.', 'publishrank' ) );
			}
		}

		/* ---- Newsroom signals ---- */
		$add( 'news_byline', 'news', $d['has_author'] ? 'good' : 'bad', 1, $d['has_author'] ? __( 'The article has a named author or byline.', 'publishrank' ) : __( 'Add a named author or editorial byline.', 'publishrank' ) );
		if ( $d['modified'] > 0 ) {
			$age = (int) floor( ( time() - (int) $d['modified'] ) / 86400 );
			if ( $age > 365 ) {
				/* translators: %d: number of days. */
				$add( 'news_fresh', 'news', 'ok', 1, sprintf( __( 'Last updated %d days ago; review it for accuracy and refresh if the story has moved on.', 'publishrank' ), $age ) );
			}
		}

		/* ---- Site-level checks ---- */
		if ( $site ) {
			foreach ( self::site_checks( $d, $keyphrase, $title ) as $check ) {
				$checks[] = $check; }
		}

		/* ---- Enrich: severity, why-it-matters, fix action ---- */
		$bad_count  = 0;
		$ok_count   = 0;
		$info_count = 0;
		$high_count = 0;
		foreach ( $checks as $i => $check ) {
			$checks[ $i ]['severity'] = self::severity_for( $check['id'], $check['status'] );
			$checks[ $i ]['why']      = self::why_for( $check['id'] );
			$fix                      = self::fix_for( $check['id'], $check['status'] );
			if ( $fix ) {
				$checks[ $i ]['fix'] = $fix; }
			if ( 'bad' === $check['status'] ) {
				++$bad_count;
				if ( in_array( $checks[ $i ]['severity'], array( 'critical', 'high' ), true ) ) {
					++$high_count; }
			} elseif ( 'ok' === $check['status'] ) {
				++$ok_count; } elseif ( 'info' === $check['status'] ) {
				++$info_count; }
		}

		/* ---- Score (weights unchanged from v2.9) ---- */
		$earned = 0.0;
		$max    = 0.0;
		$groups = array();
		foreach ( $checks as $check ) {
			$g = $check['group'];
			if ( ! isset( $groups[ $g ] ) ) {
				$groups[ $g ] = array(
					'earned' => 0.0,
					'max'    => 0.0,
				); }
			if ( 'info' === $check['status'] ) {
				continue; }
			$w                       = (float) $check['weight'];
			$v                       = 'good' === $check['status'] ? $w : ( 'ok' === $check['status'] ? $w * 0.5 : 0.0 );
			$max                    += $w;
			$earned                 += $v;
			$groups[ $g ]['max']    += $w;
			$groups[ $g ]['earned'] += $v;
		}
		$score = $max > 0 ? (int) round( ( $earned / $max ) * 100 ) : 0;
		foreach ( $groups as $g => $v ) {
			$groups[ $g ] = $v['max'] > 0 ? (int) round( ( $v['earned'] / $v['max'] ) * 100 ) : 0;
		}

		return array(
			'score'       => $score,
			'rating'      => $score >= 80 ? 'good' : ( $score >= 55 ? 'ok' : 'bad' ),
			'groups'      => $groups,
			'checks'      => $checks,
			'stats'       => array(
				'words'                        => $word_count,
				'images'                       => count( $images ),
				'sentences'                    => $sentence_count,
				'paragraphs'                   => count( $paragraphs ),
				'reading_min'                  => max( 1, (int) ceil( $word_count / 238 ) ),
				'internal'                     => $internal,
				'external'                     => $external,
				'headings'                     => count( $headings ),
				'serp_pixels'                  => $pixels,
				'issues_bad'                   => $bad_count,
				'issues_ok'                    => $ok_count,
				'issues_info'                  => $info_count,
				'issues_high'                  => $high_count,
				'generated_at'                 => gmdate( 'c' ),
			),
			'suggestions' => array(
				'keyphrases'  => $word_count >= 80 ? self::suggest_keyphrases( $title, $words, $headings, (string) $d['terms'] ) : array(),
				'description' => '' === $description ? self::suggest_description( $paragraphs, (string) $d['excerpt'] ) : '',
				'headline'    => self::suggest_headline( $title, $keyphrase ),
				'alt'         => $site ? self::suggest_alt_texts( $html, (int) $d['post_id'] ) : array(),
				'links'       => ( $site && $word_count >= 80 ) ? self::suggest_internal_links( (int) $d['post_id'], $title, $words ) : array(),
				'intro'       => '' !== $keyphrase && $paragraphs ? self::suggest_intro( $paragraphs[0], $keyphrase ) : '',
			),
			'current'     => array(
				'keyphrase'   => $keyphrase,
				'description' => $description,
				'seo_title'   => $seo_title,
				'title'       => $title,
			),
		);
	}

	/** Database-backed checks. @return array<int,array<string,mixed>> */
	private static function site_checks( array $d, $keyphrase, $title ) {
		$checks = array();
		$pid    = (int) $d['post_id'];
		if ( ! function_exists( 'get_posts' ) ) {
			return $checks; }

		global $wpdb;
		if ( '' !== $title && isset( $wpdb ) && is_object( $wpdb ) ) {
			$types = self::supported_post_types();
			$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$args  = array_merge( $types, array( $title, $pid ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in contains only generated %s placeholders from the validated post-type list.
			$dupe     = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status IN ('publish','future','pending','draft') AND post_title = %s AND ID <> %d LIMIT 1", $args ) );
			$checks[] = array(
				'id'      => 'site_duplicate_title',
				'group'   => 'site',
				'status'  => $dupe ? 'bad' : 'good',
				'weight'  => 1,
				'message' => $dupe ? __( 'Another article already uses this exact headline.', 'publishrank' ) : __( 'The headline is unique on this site.', 'publishrank' ),
			);
		}
		if ( $pid && class_exists( '\\PublishRankSEO\\InternalLinks\\Store' ) && \PublishRankSEO\InternalLinks\Store::enabled() && 'publish' === get_post_status( $pid ) ) {
			$node = \PublishRankSEO\InternalLinks\Store::node_for_object( $pid );
			if ( $node ) {
				$in = (int) $node->inbound_count;
				/* translators: %d: numeric count or measurement described by the message. */
				$checks[] = array(
					'id'      => 'site_inbound',
					'group'   => 'site',
					'status'  => $in >= 2 ? 'good' : ( 1 === $in ? 'ok' : 'bad' ),
					'weight'  => 1,
					/* translators: %d: count of other articles linking to the current article. */
					'message' => $in >= 1 ? sprintf( _n( '%d other article links here.', '%d other articles link here.', $in, 'publishrank' ), $in ) : __( 'No other article links to this one yet (orphan). See Internal Links for suggestions.', 'publishrank' ),
				);
			}
		}
		return $checks;
	}

	/* ------------------------------------------------------------------ *
	 * Severity / why / fix mapping
	 * ------------------------------------------------------------------ */

	private static function severity_for( $id, $status ) {
		if ( 'info' === $status ) {
			return 'info'; }
		if ( 'good' === $status ) {
			return 'none'; }
		if ( 'ok' === $status ) {
			return 'low'; }
		$critical = array( 'keyphrase_missing', 'headline_missing', 'snippet_description', 'structure_length' );
		$high     = array( 'keyphrase_title', 'keyphrase_intro', 'keyphrase_density', 'headline_tone', 'media_alt', 'media_featured', 'links_internal', 'news_byline' );
		if ( in_array( $id, $critical, true ) ) {
			return 'critical'; }
		if ( in_array( $id, $high, true ) ) {
			return 'high'; }
		return 'medium';
	}

	private static function why_for( $id ) {
		$map = array(
			'keyphrase_title'       => __( 'Search engines weigh the title heavily; a missing subject weakens relevance signals.', 'publishrank' ),
			'keyphrase_intro'       => __( 'Stating the subject early confirms to readers and crawlers what the article covers.', 'publishrank' ),
			'keyphrase_density'     => __( 'An absent or overstuffed keyphrase reads as off-topic or spammy.', 'publishrank' ),
			'headline_missing'      => __( 'Articles without a headline produce poor search and news listings.', 'publishrank' ),
			'headline_length'       => __( 'Titles that are too short or too long are truncated or ignored in results.', 'publishrank' ),
			'headline_tone'         => __( 'Sensational headlines reduce trust and can affect news-surface eligibility.', 'publishrank' ),
			'snippet_description'   => __( 'Without a meta description, search engines choose an arbitrary snippet for you.', 'publishrank' ),
			'snippet_title_width'   => __( 'Over-wide search titles are cut off in results, hiding the subject.', 'publishrank' ),
			'structure_length'      => __( 'Thin content rarely satisfies readers or ranks for competitive queries.', 'publishrank' ),
			'structure_subheadings' => __( 'Subheadings make long articles scannable for readers and search engines.', 'publishrank' ),
			'read_sentence_length'  => __( 'Long sentences are harder to read, especially on mobile.', 'publishrank' ),
			'read_flesch'           => __( 'Harder text keeps fewer readers and can dampen engagement.', 'publishrank' ),
			'read_passive'          => __( 'Active voice is clearer and more direct.', 'publishrank' ),
			'read_transitions'      => __( 'Transition words guide readers through the argument.', 'publishrank' ),
			'media_featured'        => __( 'Featured images control how the article appears in shares and news surfaces.', 'publishrank' ),
			'media_alt'             => __( 'Alt text makes images accessible and helps image search understand them.', 'publishrank' ),
			'links_internal'        => __( 'Internal links help readers discover related coverage and distribute crawl attention.', 'publishrank' ),
			'links_external'        => __( 'Citing primary sources supports credibility and E-E-A-T signals.', 'publishrank' ),
			'links_anchor_text'     => __( 'Descriptive anchors tell readers and crawlers what the destination covers.', 'publishrank' ),
			'news_byline'           => __( 'A named byline is a strong trust and news-eligibility signal.', 'publishrank' ),
			'site_duplicate_title'  => __( 'Duplicate headlines confuse search engines about which article to rank.', 'publishrank' ),
			'site_inbound'          => __( 'Orphan pages are crawled rarely and receive little internal authority.', 'publishrank' ),
		);
		return isset( $map[ $id ] ) ? $map[ $id ] : '';
	}

	private static function fix_for( $id, $status ) {
		if ( 'good' === $status || 'info' === $status ) {
			return null; }
		$map = array(
			'keyphrase_length'      => 'keyphrase',
			'keyphrase_title'       => 'headline',
			'keyphrase_intro'       => 'intro',
			'keyphrase_description' => 'description',
			'snippet_description'   => 'description',
			'headline_length'       => 'headline',
			'headline_tone'         => 'headline',
			'snippet_title_width'   => 'headline',
			'media_alt'             => 'alt',
			'links_internal'        => 'links',
			'site_inbound'          => 'links',
		);
		return isset( $map[ $id ] ) ? $map[ $id ] : null;
	}

	/* ------------------------------------------------------------------ *
	 * Suggestions
	 * ------------------------------------------------------------------ */

	public static function suggest_keyphrases( $title, array $words, array $headings, $terms = '' ) {
		$stop   = self::stopwords();
		$scores = array();
		$counts = array();
		$feed   = static function ( array $tokens, $boost, $count_body ) use ( &$scores, &$counts, $stop ) {
			$n = count( $tokens );
			for ( $size = 1; $size <= 3; $size++ ) {
				for ( $i = 0; $i + $size <= $n; $i++ ) {
					$first = $tokens[ $i ];
					$last  = $tokens[ $i + $size - 1 ];
					if ( isset( $stop[ $first ] ) || isset( $stop[ $last ] ) ) {
						continue; }
					if ( 1 === $size && ( self::len( $first ) < 4 || ctype_digit( $first ) ) ) {
						continue; }
					if ( ctype_digit( $first ) || ctype_digit( $last ) ) {
						continue; }
					$key            = implode( ' ', array_slice( $tokens, $i, $size ) );
					$scores[ $key ] = ( $scores[ $key ] ?? 0 ) + $boost * ( 1 + 0.6 * ( $size - 1 ) );
					if ( $count_body ) {
						$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1; }
				}
			}
		};
		$feed( $words, 1, true );
		$feed( self::tokens( $title ), 3, false );
		foreach ( $headings as $h ) {
			$feed( self::tokens( $h['text'] ), 2, false ); }
		if ( '' !== trim( (string) $terms ) ) {
			$feed( self::tokens( (string) $terms ), 2.5, false ); }
		arsort( $scores );
		$picked = array();
		foreach ( $scores as $phrase => $score ) {
			$count = (int) ( $counts[ $phrase ] ?? 0 );
			$size  = substr_count( $phrase, ' ' ) + 1;
			if ( $count < ( 1 === $size ? 3 : 2 ) ) {
				continue; }
			$covered = false;
			foreach ( $picked as $p ) {
				if ( false !== strpos( ' ' . $p['phrase'] . ' ', ' ' . $phrase . ' ' ) ) {
					$covered = true;
					break; }
			}
			if ( $covered ) {
				continue; }
			$picked[] = array(
				'phrase' => (string) $phrase,
				'count'  => $count,
			);
			if ( count( $picked ) >= 6 ) {
				break; }
		}
		return $picked;
	}

	/** Build a meta description from the excerpt or the opening sentences. $variant rotates the sentence window deterministically. */
	public static function suggest_description( array $paragraphs, $excerpt = '', $variant = 0 ) {
		$source = trim( wp_strip_all_tags( (string) $excerpt ) );
		if ( '' === $source && $paragraphs ) {
			$source = implode( ' ', array_slice( $paragraphs, 0, 5 ) ); }
		$source = trim( preg_replace( '/\s+/u', ' ', $source ) );
		if ( self::len( $source ) < 40 ) {
			return ''; }
		$sentences = (array) preg_split( '/(?<=[.!?۔؟])\s+/u', $source, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $sentences ) {
			return ''; }
		$sentence_count = count( $sentences );
		$start           = min( absint( $variant ), max( 0, $sentence_count - 1 ) );
		$out   = '';
		for ( $i = $start; $i < $sentence_count; $i++ ) {
			$next = '' === $out ? $sentences[ $i ] : $out . ' ' . $sentences[ $i ];
			if ( self::len( $next ) > 158 ) {
				break; }
			$out = $next;
		}
		if ( '' === $out ) {
			$out = $sentences[ $start ]; }
		if ( self::len( $out ) < 70 ) {
			$out = self::substr( $source, 155 );
			$cut = strrpos( $out, ' ' );
			if ( false !== $cut && $cut > 90 ) {
				$out = substr( $out, 0, $cut ); }
			$out = rtrim( $out, ' ,;:-' ) . '…';
		}
		return self::substr( $out, 158 );
	}

	/**
	 * Suggest an opening paragraph that states the focus keyword early. The original first paragraph is kept
	 * in full; only a short lead-in sentence is added, and the editor reviews it before anything changes.
	 */
	public static function suggest_intro( $first_paragraph, $keyphrase ) {
		$first   = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $first_paragraph ) ) );
		$keyword = trim( (string) $keyphrase );
		if ( '' === $first || '' === $keyword ) {
			return ''; }
		$phrase = self::stems( self::tokens( $keyword ) );
		if ( $phrase && self::phrase_count( array_slice( self::stems( self::tokens( $first ) ), 0, 80 ), $phrase ) > 0 ) {
			return ''; }
		/* translators: %s: focus keyword. */
		$lead = sprintf( __( 'This article is about %s.', 'publishrank' ), $keyword );
		return $lead . ' ' . $first;
	}

	/**
	 * Suggest an improved search headline derived from the actual title.
	 * Returns '' when the title already passes or no reliable improvement exists.
	 */
	public static function suggest_headline( $title, $keyphrase = '' ) {
		$out = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $title ) ) );
		if ( '' === $out ) {
			return ''; }
		$changed = false;
		$clean   = trim( (string) preg_replace( '/\s*[!?]{2,}/u', '', $out ) );
		$cool = $clean;
		if ( $clean !== $out && '' !== $cool ) {
			$out     = $cool;
			$changed = true; }
		$words = preg_split( '/\s+/u', $out );
		foreach ( $words as $i => $w ) {
			if ( preg_match( '/^\p{Lu}{4,}$/u', $w ) && ! in_array( $w, array( 'NASA', 'UNICEF', 'NATO', 'COVID', 'FIFA', 'UEFA' ), true ) ) {
				$words[ $i ] = self::substr( $w, 1 ) ? ( function_exists( 'mb_convert_case' ) ? mb_convert_case( $w, MB_CASE_TITLE, 'UTF-8' ) : ucfirst( strtolower( $w ) ) ) : $w;
				$changed     = true;
			}
		}
		$out = implode( ' ', $words );
		if ( self::len( $out ) > 65 ) {
			$cut = self::substr( $out, 62 );
			$pos = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
			if ( false !== $pos && $pos > 30 ) {
				$out     = rtrim( mb_substr( $cut, 0, $pos, 'UTF-8' ), ' ,;:-' );
				$changed = true; }
		}
		if ( '' !== trim( (string) $keyphrase ) ) {
			$kp   = trim( (string) $keyphrase );
			$have = self::phrase_count( self::stems( self::tokens( $out ) ), self::stems( self::tokens( $kp ) ) ) > 0;
			if ( ! $have ) {
				$room = 65 - ( self::len( $kp ) + 2 );
				if ( $room >= 20 ) {
					if ( self::len( $out ) > $room ) {
						$cut = self::substr( $out, $room );
						$pos = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );
						$out = rtrim( false !== $pos && $pos > 10 ? ( function_exists( 'mb_substr' ) ? mb_substr( $cut, 0, $pos, 'UTF-8' ) : substr( $cut, 0, $pos ) ) : $cut, ' ,;:-' );
					}
					$out     = $kp . ': ' . $out;
					$changed = true;
				}
			}
		}
		return $changed && 0 !== strcmp( $out, trim( (string) $title ) ) ? $out : '';
	}

	/**
	 * Alt-text suggestions for images missing alt. Reuse the latest Media Library
	 * alt text first, then caption or cleaned filename — never invent visual detail.
	 * @return array<int,array{identifier:string,suggestion:string,attachment_id:int}>
	 */
	private static function suggest_alt_texts( $html, $post_id ) {
		$out = array();
		if ( ! preg_match_all( '/<img\b[^>]*>/i', (string) $html, $m ) ) {
			return $out; }
		static $att_cache = array();
		foreach ( $m[0] as $tag ) {
			// Missing alt means no alt attribute at all, or an empty one — the same rule the analyzer uses.
			if ( preg_match( '/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $a ) && '' !== trim( html_entity_decode( isset( $a[2] ) && '' !== $a[2] ? $a[2] : $a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
				continue; }
			if ( ! preg_match( '/\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\')/i', $tag, $s ) ) {
				continue; }
			$url  = html_entity_decode( isset( $s[2] ) && '' !== $s[2] ? $s[2] : $s[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$base = sanitize_file_name( rawurldecode( (string) wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
			if ( '' === $base ) {
				$base = $url; }
			$att = 0;
			if ( preg_match( '/wp-image-(\d+)/', $tag, $idm ) ) {
				$att = (int) $idm[1]; }
			if ( ! $att ) {
				if ( ! isset( $att_cache[ $url ] ) ) {
					$att_cache[ $url ] = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $url ) : 0;
				}
				$att = $att_cache[ $url ];
			}
			$from_meta = '';
			if ( $att ) {
				$from_meta = trim( sanitize_text_field( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) ) );
				if ( '' === $from_meta ) {
				$cap = get_post_field( 'post_excerpt', $att );
				if ( '' !== trim( (string) $cap ) ) {
					$from_meta = trim( wp_strip_all_tags( (string) $cap ) ); }
				}
			}
			$name       = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $base );
			$name       = (string) preg_replace( '/[-_](\d{2,5}x\d{2,5}|scaled|cropped)$/i', '', $name );
			$name       = str_replace( array( '-', '_' ), ' ', $name );
			$name       = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
			$tokens     = self::tokens( $name );
			$suggestion = '';
			if ( '' !== $from_meta && self::len( $from_meta ) >= 3 ) {
				$suggestion = self::substr( $from_meta, 120 );
			} elseif ( count( $tokens ) >= 2 && count( array_filter( $tokens, 'ctype_alpha' ) ) >= 2 && ! preg_match( '/\d{4,}/', $name ) ) {
				$suggestion = function_exists( 'mb_convert_case' ) ? mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' ) : ucwords( strtolower( $name ) );
				$suggestion = self::substr( $suggestion, 120 );
			}
			// No reliable suggestion is fine: the editor still gets an empty field with a placeholder to describe the image.
			$out[] = array(
				'identifier'    => $base,
				'suggestion'    => $suggestion,
				'attachment_id' => $att,
				'src'           => $url,
			);
			if ( count( $out ) >= 10 ) {
				break; }
		}
		return $out;
	}

	/**
	 * Internal-link suggestions from published content, scored by shared significant terms.
	 * Results are transient-cached per post and invalidated on save.
	 * @return array<int,array{title:string,url:string,reason:string,post_id:int}>
	 */
	private static function suggest_internal_links( $post_id, $title, array $words, $limit = 5 ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || ! function_exists( 'get_posts' ) ) {
			return array(); }
		$hash   = md5( strtolower( trim( (string) $title ) ) . '|' . implode( ' ', array_slice( $words, 0, 400 ) ) );
		$key    = 'pr_intel_links_' . $post_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['h'], $cached['items'] ) && $cached['h'] === $hash ) {
			return $cached['items']; }

		$stop = self::stopwords();
		$freq = array();
		foreach ( $words as $w ) {
			if ( isset( $stop[ $w ] ) || self::len( $w ) < 4 || ctype_digit( $w ) ) {
				continue; }
			$freq[ $w ] = ( $freq[ $w ] ?? 0 ) + 1;
		}
		arsort( $freq );
		$terms = array_slice( array_keys( $freq ), 0, 12 );
		if ( ! $terms ) {
			return array(); }
		$term_stems = array_flip( self::stems( $terms ) );

		$candidates = get_posts(
			array(
				'post_type'        => self::supported_post_types(),
				'post_status'      => 'publish',
				'posts_per_page'   => 60,
				'post__not_in'     => array( $post_id ),
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$scored     = array();
		foreach ( $candidates as $cid ) {
			$ctitle = wp_strip_all_tags( get_the_title( (int) $cid ) );
			if ( '' === $ctitle ) {
				continue; }
			$ctokens = self::stems( self::tokens( $ctitle ) );
			$shared  = array();
			$score   = 0;
			foreach ( $ctokens as $t ) {
				if ( isset( $term_stems[ $t ] ) ) {
					$score       += 2;
					$shared[ $t ] = true; }
			}
			if ( $score < 2 ) {
				continue; }
			$scored[ $cid ] = array(
				'score'  => $score,
				'shared' => array_keys( $shared ),
				'title'  => $ctitle,
			);
		}
		uasort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		$out = array();
		foreach ( array_slice( $scored, 0, $limit, true ) as $cid => $row ) {
			$out[] = array(
				'title'   => $row['title'],
				'url'     => (string) get_permalink( (int) $cid ),
				/* translators: %s: list of shared topics. */
				'reason'  => sprintf( __( 'Shares topics: %s', 'publishrank' ), implode( ', ', array_slice( $row['shared'], 0, 3 ) ) ),
				'post_id' => (int) $cid,
			);
		}
		set_transient(
			$key,
			array(
				'h'     => $hash,
				'items' => $out,
			),
			6 * HOUR_IN_SECONDS
		);
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	public static function group_labels() {
		return array(
			'headline'    => __( 'SEO headline', 'publishrank' ),
			'snippet'     => __( 'Search snippet & meta description', 'publishrank' ),
			'structure'   => __( 'Content structure', 'publishrank' ),
			'readability' => __( 'Readability', 'publishrank' ),
			'media'       => __( 'Media & image accessibility', 'publishrank' ),
			'links'       => __( 'Internal & external links', 'publishrank' ),
			'news'        => __( 'Newsroom signals', 'publishrank' ),
			'site'        => __( 'Across your site', 'publishrank' ),
		);
	}

	public static function check_label( $id ) {
		$labels = array(
			'keyphrase_title'       => __( 'Keyphrase not in title', 'publishrank' ),
			'keyphrase_description' => __( 'Keyphrase not in description', 'publishrank' ),
			'keyphrase_intro'       => __( 'Keyphrase not in opening paragraph', 'publishrank' ),
			'keyphrase_density'     => __( 'Keyphrase density', 'publishrank' ),
			'headline_length'       => __( 'Headline length', 'publishrank' ),
			'headline_tone'         => __( 'Sensational headline', 'publishrank' ),
			'snippet_title_width'   => __( 'Search title too wide', 'publishrank' ),
			'snippet_description'   => __( 'Missing or poor meta description', 'publishrank' ),
			'structure_length'      => __( 'Thin article', 'publishrank' ),
			'structure_subheadings' => __( 'No subheadings', 'publishrank' ),
			'read_sentence_length'  => __( 'Long sentences', 'publishrank' ),
			'read_flesch'           => __( 'Hard to read', 'publishrank' ),
			'read_passive'          => __( 'Passive voice', 'publishrank' ),
			'read_transitions'      => __( 'Few transition words', 'publishrank' ),
			'media_featured'        => __( 'No featured image', 'publishrank' ),
			'media_alt'             => __( 'Images without alt text', 'publishrank' ),
			'links_internal'        => __( 'No internal links', 'publishrank' ),
			'news_byline'           => __( 'No byline', 'publishrank' ),
			'media_weight'          => __( 'Image file size', 'publishrank' ),
			'media_dimensions'      => __( 'Image dimensions', 'publishrank' ),
			'media_format'          => __( 'Image format', 'publishrank' ),
			'site_duplicate_title'  => __( 'Duplicate headline', 'publishrank' ),
			'site_inbound'          => __( 'Orphan article', 'publishrank' ),
		);
		return isset( $labels[ $id ] ) ? $labels[ $id ] : (string) $id;
	}

	private static function severity_label( $severity ) {
		$map = array(
			'critical' => __( 'Critical', 'publishrank' ),
			'high'     => __( 'High', 'publishrank' ),
			'medium'   => __( 'Medium', 'publishrank' ),
			'low'      => __( 'Low', 'publishrank' ),
		);
		return isset( $map[ $severity ] ) ? $map[ $severity ] : '';
	}

	/** SVG progress ring. */
	private static function ring_svg( $score, $rating, $size = 112, $stroke = 10 ) {
		$score  = max( 0, min( 100, (int) $score ) );
		$size   = (int) $size;
		$stroke = (int) $stroke;
		$r      = ( $size - $stroke ) / 2 - 2;
		$c      = 2 * M_PI * $r;
		$offset = $c * ( 1 - $score / 100 );
		$cy     = $size / 2;
		$fs     = max( 16, (int) ( $size * 0.24 ) );
		return sprintf(
			'<svg class="pr-ring pr-ring--%1$s" width="%2$d" height="%2$d" viewBox="0 0 %2$d %2$d" role="img" aria-label="%3$s"><circle class="pr-ring__bg" cx="%4$s" cy="%4$s" r="%5$s" stroke-width="%6$d"/><circle class="pr-ring__val" cx="%4$s" cy="%4$s" r="%5$s" stroke-width="%6$d" stroke-dasharray="%7$s" stroke-dashoffset="%8$s" transform="rotate(-90 %4$s %4$s)"/><text x="%4$s" y="%4$s" class="pr-ring__text" style="font-size:%9$dpx">%10$d</text></svg>', // phpcs:ignore WordPress.Security.EscapeOutput -- all interpolated values are ints or a fixed translated label.
			esc_attr( $rating ),
			$size,
			/* translators: %d: editorial SEO score. */
			esc_attr( sprintf( __( 'SEO score %d out of 100', 'publishrank' ), $score ) ),
			$cy,
			round( $r, 2 ),
			$stroke,
			round( $c, 2 ),
			round( $offset, 2 ),
			$fs,
			$score
		);
	}

	/** Escaped HTML for a report; used by the meta box and the AJAX response. */
	public static function render_report( array $report, $previous = null ) {
		$labels       = self::group_labels();
		$s            = $report['stats'];
		$score        = (int) $report['score'];
		$rating       = $report['rating'];
		$status_label = 'good' === $rating ? __( 'Well optimized', 'publishrank' ) : ( 'ok' === $rating ? __( 'Needs improvement', 'publishrank' ) : __( 'Needs work', 'publishrank' ) );
		$out          = '<div class="pr-dash">';

		/* --- Header: ring + status + comparison --- */
		$out .= '<div class="pr-dash__head pr-dash__head--' . esc_attr( $rating ) . '">';
		$out .= '<div class="pr-dash__ring">' . self::ring_svg( $score, $rating, 112, 10 ) . '</div>';
		$out .= '<div class="pr-dash__status"><span class="pr-dash__label">' . esc_html( $status_label ) . '</span>';
		/* translators: %1$d: number of words; %2$d: numeric count or measurement described by the message; %3$d: number of images; %4$d: numeric count or measurement described by the message; %5$d: numeric count or measurement described by the message. */
		$out          .= '<span class="pr-dash__stats">' . esc_html( sprintf( __( '%1$d words · %2$d min read · %3$d images · %4$d internal / %5$d external links', 'publishrank' ), $s['words'], $s['reading_min'], $s['images'], $s['internal'], $s['external'] ) ) . '</span>';
		$out          .= '<span class="pr-dash__explainer">' . esc_html__( 'A local summary of on-page SEO checks. It is not a Google ranking metric.', 'publishrank' ) . '</span>';
		$current_fails = array();
		foreach ( $report['checks'] as $check ) {
			if ( 'bad' === $check['status'] ) {
				$current_fails[] = $check['id']; }
		}
		if ( is_array( $previous ) && isset( $previous['score'] ) ) {
			$delta    = $score - (int) $previous['score'];
			$resolved = array_diff( (array) $previous['fail'], $current_fails );
			$new      = array_diff( $current_fails, (array) $previous['fail'] );
			$cls      = $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : 'same' );
			$out     .= '<span class="pr-dash__delta pr-dash__delta--' . esc_attr( $cls ) . '">';
			$out     .= esc_html(
				sprintf(
				/* translators: 1: signed score change, 2: resolved issue count, 3: new issue count. */
					__( '%1$s vs last saved analysis · %2$d resolved · %3$d new', 'publishrank' ),
					( $delta > 0 ? '+' : '' ) . $delta,
					count( $resolved ),
					count( $new )
				)
			) . '</span>';
		} else {
			$out .= '<span class="pr-dash__delta pr-dash__delta--first">' . esc_html__( 'First analysis for this article — scores are saved when the post is saved.', 'publishrank' ) . '</span>';
		}

		$out .= '</div></div>';

		/* --- Category bars --- */
		$out .= '<div class="pr-dash__cats" role="list">';
		foreach ( $labels as $key => $label ) {
			$items = self::checks_in( $report, $key, false );
			if ( ! $items ) {
				continue; }
			$val    = isset( $report['groups'][ $key ] ) ? (int) $report['groups'][ $key ] : 0;
			$issues = count(
				array_filter(
					$items,
					static function ( $c ) {
						return 'bad' === $c['status'];
					}
				)
			);
			$warn   = count(
				array_filter(
					$items,
					static function ( $c ) {
						return 'ok' === $c['status'];
					}
				)
			);
			$cls    = $val >= 80 ? 'good' : ( $val >= 55 ? 'ok' : 'bad' );
			$out   .= '<div class="pr-cat pr-cat--' . esc_attr( $cls ) . '" role="listitem">';
			$out   .= '<div class="pr-cat__top"><span class="pr-cat__name">' . esc_html( $label ) . '</span><span class="pr-cat__val">' . esc_html( (string) $val ) . '<i>/100</i></span></div>';
			$out   .= '<div class="pr-cat__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $val ) . '" aria-label="' . esc_attr( $label ) . '"><span style="width:' . esc_attr( (string) $val ) . '%"></span></div>';
			/* translators: %d: number of issue; %d: number of improvement. */
			$out .= '<div class="pr-cat__meta">' . esc_html( sprintf( _n( '%d issue', '%d issues', $issues, 'publishrank' ), $issues ) . ( $warn ? ' · ' . sprintf( _n( '%d improvement', '%d improvements', $warn, 'publishrank' ), $warn ) : '' ) ) . '</div>';
			$out .= '</div>';
		}
		$out .= '<div class="pr-cat pr-cat--na" role="listitem"><div class="pr-cat__top"><span class="pr-cat__name">' . esc_html__( 'Structured data', 'publishrank' ) . '</span><span class="pr-cat__val pr-cat__val--na">' . esc_html__( 'n/a', 'publishrank' ) . '</span></div><div class="pr-cat__meta">' . esc_html__( 'Not evaluated by the article analyzer — see the PublishRank Schema suite.', 'publishrank' ) . '</div></div>';
		$out .= '</div>';

		/* --- Tally --- */
		$passed = count(
			array_filter(
				$report['checks'],
				static function ( $c ) {
					return 'good' === $c['status'];
				}
			)
		);
		$out   .= '<div class="pr-dash__tally"><span><b>' . esc_html( (string) $s['issues_bad'] ) . '</b> ' . esc_html__( 'issues detected', 'publishrank' ) . '</span><span><b>' . esc_html( (string) $s['issues_ok'] ) . '</b> ' . esc_html__( 'suggested improvements', 'publishrank' ) . '</span><span><b>' . esc_html( (string) $passed ) . '</b> ' . esc_html__( 'checks passed', 'publishrank' ) . '</span><span><b>' . esc_html( (string) $s['issues_info'] ) . '</b> ' . esc_html__( 'not evaluated', 'publishrank' ) . '</span></div>';

		/* --- Priority issues --- */
		$priority = array_values(
			array_filter(
				$report['checks'],
				static function ( $c ) {
					return 'bad' === $c['status'] || ( 'ok' === $c['status'] && ! empty( $c['fix'] ) ); }
			)
		);
		$rank     = array(
			'critical' => 0,
			'high'     => 1,
			'medium'   => 2,
			'low'      => 3,
		);
		usort(
			$priority,
			static function ( $a, $b ) use ( $rank ) {
				$ra = 'bad' === $a['status'] ? $rank[ $a['severity'] ] : 4;
				$rb = 'bad' === $b['status'] ? $rank[ $b['severity'] ] : 4;
				return $ra === $rb ? 0 : ( $ra < $rb ? -1 : 1 );
			}
		);
		$priority = array_slice( $priority, 0, 6 );
		if ( $priority ) {
			$out .= '<div class="pr-dash__issues"><h3 class="pr-dash__subtitle">' . esc_html__( 'Priority issues', 'publishrank' ) . '</h3><ul class="pr-issues">';
			foreach ( $priority as $item ) {
				$sev  = 'bad' === $item['status'] ? $item['severity'] : 'low';
				$out .= '<li class="pr-issue pr-issue--' . esc_attr( $sev ) . '"><span class="pr-issue__badge">' . esc_html( self::severity_label( $sev ) ) . '</span><div class="pr-issue__body"><p class="pr-issue__msg">' . esc_html( $item['message'] ) . '</p>';
				if ( ! empty( $item['why'] ) ) {
					$out .= '<p class="pr-issue__why">' . esc_html( $item['why'] ) . '</p>'; }
				$out .= '</div>';
				if ( ! empty( $item['fix'] ) ) {
					$out .= '<button type="button" class="button button-small pr-issue__fix" data-kind="' . esc_attr( $item['fix'] ) . '">' . esc_html__( 'Fix', 'publishrank' ) . '</button>'; }
				$out .= '</li>';
			}
			$out .= '</ul></div>';
		}

		/* --- Full check list (existing details groups, preserved) --- */
		$icons = array(
			'good' => '●',
			'ok'   => '●',
			'bad'  => '●',
			'info' => '○',
		);
		$out  .= '<div class="pr-intel__groups">';
		foreach ( $labels as $key => $label ) {
			$items = self::checks_in( $report, $key, false );
			if ( ! $items ) {
				continue; }
			usort(
				$items,
				static function ( $a, $b ) {
					$rank = array(
						'bad'  => 0,
						'ok'   => 1,
						'info' => 2,
						'good' => 3,
					);
					return $rank[ $a['status'] ] <=> $rank[ $b['status'] ];
				}
			);
			$open = false;
			foreach ( $items as $item ) {
				if ( 'bad' === $item['status'] || 'ok' === $item['status'] ) {
					$open = true;
					break; }
			}
			$out .= '<details class="pr-intel__group"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $label ) . '</summary><ul>';
			foreach ( $items as $item ) {
				$out .= '<li class="pr-intel__item pr-intel__item--' . esc_attr( $item['status'] ) . '"><span class="pr-intel__dot" aria-hidden="true">' . esc_html( $icons[ $item['status'] ] ) . '</span> ' . esc_html( $item['message'] ) . '</li>';
			}
			$out .= '</ul></details>';
		}
		$out .= '</div>';

		/* --- Fix studio --- */
		$out .= self::render_studio( $report );
		return $out . '</div>';
	}

	/** Interactive fix studio: keyphrase, description, headline, alt text, internal links. */
	private static function render_studio( array $report ) {
		$sug     = $report['suggestions'];
		$current = isset( $report['current'] ) && is_array( $report['current'] ) ? $report['current'] : array();
		$cur     = static function ( $key ) use ( $current ) {
			return isset( $current[ $key ] ) ? (string) $current[ $key ] : '';
		};
		$flagged = static function ( array $ids ) use ( $report ) {
			foreach ( $report['checks'] as $check ) {
				if ( in_array( $check['id'], $ids, true ) && in_array( $check['status'], array( 'bad', 'ok' ), true ) ) {
					return true; }
			}
			return false;
		};
		$feedback = '<span class="pr-studio__feedback" role="status" aria-live="polite" aria-atomic="true"></span>';
		$shown    = 0;
		$out      = '<section class="pr-studio" aria-label="' . esc_attr__( 'SEO fix assistant', 'publishrank' ) . '"><h3 class="pr-dash__subtitle">' . esc_html__( 'Fix assistant', 'publishrank' ) . '</h3>';
		$out     .= '<p class="pr-studio__note">' . esc_html__( 'Every suggestion below is derived from this article. Review, edit, then apply — nothing changes until you apply it.', 'publishrank' ) . '</p>';
		/* ---- SEO headline ---- */
		$headline_value = '' !== $sug['headline'] ? $sug['headline'] : ( '' !== $cur( 'seo_title' ) ? $cur( 'seo_title' ) : $cur( 'title' ) );
		if ( '' !== $sug['headline'] || $flagged( array( 'headline_length', 'headline_tone', 'snippet_title_width' ) ) ) {
			++$shown;
			$out .= '<div class="pr-studio__block" data-studio="headline"><h4>' . esc_html__( 'SEO headline', 'publishrank' ) . '</h4><p class="pr-studio__why">' . esc_html__( 'The title shown in search results. Your WordPress post title stays unchanged; this saves the SEO title only.', 'publishrank' ) . '</p><div class="pr-studio__row"><label class="screen-reader-text" for="pr-studio-headline">' . esc_html__( 'SEO headline input', 'publishrank' ) . '</label><input type="text" class="pr-studio__input" id="pr-studio-headline" maxlength="255" value="' . esc_attr( $headline_value ) . '" placeholder="' . esc_attr__( 'Write a 30–65 character search title that summarizes the article', 'publishrank' ) . '" /><button type="button" class="button button-primary pr-studio__apply" data-field="seo_title" data-input="pr-studio-headline">' . esc_html__( 'Apply SEO title', 'publishrank' ) . '</button><button type="button" class="button pr-studio__regen" data-kind="headline">' . esc_html__( 'Regenerate', 'publishrank' ) . '</button>' . $feedback . '</div></div>';
		}

		/* ---- Meta description ---- */
		$desc_value = '' !== $cur( 'description' ) ? $cur( 'description' ) : (string) $sug['description'];
		if ( '' !== $desc_value || $flagged( array( 'snippet_description' ) ) ) {
			++$shown;
			$count = self::len( $desc_value );
			$out  .= '<div class="pr-studio__block" data-studio="description"><h4>' . esc_html__( 'Meta description', 'publishrank' ) . '</h4><div class="pr-studio__row pr-studio__row--stack"><label class="screen-reader-text" for="pr-studio-description">' . esc_html__( 'Meta description input', 'publishrank' ) . '</label><textarea class="pr-studio__textarea" id="pr-studio-description" aria-describedby="pr-studio-description-count" maxlength="300" rows="3" placeholder="' . esc_attr__( 'Write 70–160 characters that summarize the article ', 'publishrank' ) . '">' . esc_textarea( $desc_value ) . '</textarea><span class="pr-studio__count" id="pr-studio-description-count">' . esc_html( sprintf( _n( '%d character (target 70–160)', '%d characters (target 70–160)', $count, 'publishrank' ), $count ) ) . '</span></div><div class="pr-studio__row"><button type="button" class="button button-primary pr-studio__apply" data-field="description" data-input="pr-studio-description">' . esc_html__( 'Apply description', 'publishrank' ) . '</button><button type="button" class="button pr-studio__regen" data-kind="description">' . esc_html__( 'Regenerate', 'publishrank' ) . '</button><button type="button" class="button pr-studio__cancel" data-target="pr-studio-description">' . esc_html__( 'Cancel', 'publishrank' ) . '</button>' . $feedback . '</div></div>';
		}


		/* ---- Internal links ---- */
		if ( ! empty( $sug['links'] ) ) {
			++$shown;
			$out .= '<div class="pr-studio__block" data-studio="links"><h4>' . esc_html__( 'Internal link suggestions', 'publishrank' ) . '</h4><p class="pr-studio__why">' . esc_html__( 'Relevant published articles on this site. Copy a link and add it where it helps the reader — nothing is inserted automatically.', 'publishrank' ) . '</p>';
			foreach ( $sug['links'] as $item ) {
				$out .= '<div class="pr-studio__row pr-studio__link"><span class="pr-studio__link-title">' . esc_html( $item['title'] ) . '</span><span class="pr-studio__link-reason">' . esc_html( $item['reason'] ) . '</span><code class="pr-studio__link-url">' . esc_html( $item['url'] ) . '</code><button type="button" class="button button-small pr-studio__copy" aria-label="' . esc_attr( sprintf( /* translators: %s: linked article title. */ __( 'Copy link to %s', 'publishrank' ), $item['title'] ) ) . '" data-url="' . esc_attr( $item['url'] ) . '">' . esc_html__( 'Copy link', 'publishrank' ) . '</button><span class="pr-studio__copy-feedback" role="status" aria-live="polite" aria-atomic="true"></span></div>';
			}
			$out .= '</div>';
		}

		if ( 0 === $shown ) {
			$out .= '<p class="pr-studio__why">' . esc_html__( 'No automatic fixes are available yet — add more content and the assistant will derive suggestions from it.', 'publishrank' ) . '</p>';
		}
		return $out . '</section>';
	}

	/** Panel markup for the post editor meta box. */
	public static function render_panel( $post ) {
		$report   = self::analyze( self::data_from_post( $post ), true );
		$previous = self::stored( (int) $post->ID );
		echo '<section class="pr-intel" id="pr-intel" data-score="' . esc_attr( (string) $report['score'] ) . '" role="region" aria-labelledby="pr-intel-heading">';
		echo '<div class="pr-intel__head"><h2 id="pr-intel-heading">' . esc_html__( 'SEO score and improvement plan', 'publishrank' ) . '</h2> <button type="button" class="button button-small" id="pr-intel-refresh" aria-controls="pr-intel-body">' . esc_html__( 'Re-analyze', 'publishrank' ) . '</button> <span class="description">' . esc_html__( 'Computed locally on your server. No external service is contacted.', 'publishrank' ) . '</span></div>';
		echo '<span class="screen-reader-text" id="pr-intel-status" role="status" aria-live="polite" aria-atomic="true"></span>';
		echo '<div class="pr-intel__body" id="pr-intel-body" aria-busy="false">' . self::render_report( $report, $previous ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- render_report escapes every dynamic value.
	}

	/* ------------------------------------------------------------------ *
	 * Site-wide rollup (Insights) — unchanged behavior
	 * ------------------------------------------------------------------ */

	public static function overview( array $ids ) {
		$scored = 0;
		$sum    = 0;
		$dist   = array(
			'low'  => 0,
			'mid'  => 0,
			'high' => 0,
		);
		$fails  = array();
		foreach ( $ids as $id ) {
			$summary = self::stored( (int) $id );
			if ( ! $summary ) {
				continue; }
			++$scored;
			$sum += $summary['score'];
			if ( $summary['score'] >= 80 ) {
				++$dist['high'];
			} elseif ( $summary['score'] >= 55 ) {
				++$dist['mid'];
			} else {
				++$dist['low']; }
			foreach ( $summary['fail'] as $f ) {
				$fails[ $f ] = ( $fails[ $f ] ?? 0 ) + 1; }
		}
		arsort( $fails );
		return array(
			'total'        => count( $ids ),
			'scored'       => $scored,
			'average'      => $scored ? (int) round( $sum / $scored ) : 0,
			'distribution' => $dist,
			'top_issues'   => array_slice( $fails, 0, 6, true ),
		);
	}

	public static function cannibalized_keyphrases( $limit = 10 ) {
		global $wpdb;
		$types = self::supported_post_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args  = array_merge( array( PostMeta::FOCUS_KEYWORD ), $types, array( (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in contains only generated %s placeholders from the validated post-type list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS keyphrase, COUNT(*) AS total FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_type IN ($in) AND p.post_status = 'publish' GROUP BY m.meta_value HAVING COUNT(*) > 1 ORDER BY total DESC LIMIT %d", $args ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'keyphrase' => (string) $row->keyphrase,
				'count'     => (int) $row->total,
			); }
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Text utilities (pure, no WordPress state)
	 * ------------------------------------------------------------------ */

	private static function checks_in( array $report, $group, $scored_only ) {
		$out = array();
		foreach ( $report['checks'] as $check ) {
			if ( $check['group'] !== $group ) {
				continue; }
			if ( $scored_only && 'info' === $check['status'] ) {
				continue; }
			$out[] = $check;
		}
		return $out;
	}

	private static function clean_html( $html ) {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', (string) $html );
		return (string) preg_replace( '#<(script|style|noscript|iframe)\b.*?</\1>#is', '', $html );
	}

	private static function paragraphs( $html ) {
		$html = (string) preg_replace( '/\[\/?[a-z][a-z0-9_\-]*(?:\s[^\]]*)?\]/i', ' ', $html );
		$html = (string) preg_replace( '#</(?:p|div|li|h[1-6]|blockquote|tr|figcaption)>|<br\s*/?>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out  = array();
		foreach ( (array) preg_split( '/\n+/', $text ) as $line ) {
			$line = trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$out[] = $line; }
		}
		return $out;
	}

	private static function sentences( array $paragraphs ) {
		$out = array();
		foreach ( $paragraphs as $p ) {
			foreach ( (array) preg_split( '/(?<=[.!?۔؟])\s+/u', $p, -1, PREG_SPLIT_NO_EMPTY ) as $s ) {
				if ( count( self::tokens( $s ) ) >= 2 ) {
					$out[] = $s; }
			}
		}
		return $out;
	}

	private static function headings( $html ) {
		$out = array();
		if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$out[] = array(
					'level' => (int) $row[1],
					'text'  => trim( html_entity_decode( wp_strip_all_tags( $row[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				);
			}
		}
		return $out;
	}

	private static function images( $html ) {
		$out = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$alt = '';
				if ( preg_match( '/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $a ) ) {
					$alt = trim( html_entity_decode( isset( $a[2] ) && '' !== $a[2] ? $a[2] : $a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				}
				$out[] = array( 'alt' => $alt );
			}
		}
		return $out;
	}

	private static function image_quality( $html ) {
		$out = array(
			'large'    => 0,
			'oversize' => 0,
			'legacy'   => 0,
		);
		$max = (int) get_option( 'publishrank_image_max_width', 2000 );
		if ( ! preg_match_all( '/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\')/i', $html, $matches, PREG_SET_ORDER ) ) {
			return $out; }
		foreach ( $matches as $match ) {
			$url = html_entity_decode( ( isset( $match[2] ) && '' !== $match[2] ? $match[2] : $match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$id  = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $url ) : 0;
			if ( ! $id ) {
				continue; }
			$file = get_attached_file( $id );
			if ( $file && is_readable( $file ) && filesize( $file ) > 350 * 1024 ) {
				++$out['large']; }
			$meta = wp_get_attachment_metadata( $id );
			if ( ! empty( $meta['width'] ) && (int) $meta['width'] > $max ) {
				++$out['oversize']; }
			$ext = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
			if ( $ext && ! in_array( $ext, array( 'webp', 'avif' ), true ) ) {
				++$out['legacy']; }
		}
		return $out;
	}

	private static function links( $html, $home_host ) {
		$home = '' !== $home_host ? strtolower( $home_host ) : ( function_exists( 'home_url' ) ? strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) : '' );
		$home = (string) preg_replace( '/^www\./', '', $home );
		$out  = array();
		if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				if ( ! preg_match( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $row[1], $h ) ) {
					continue; }
				$url = trim( html_entity_decode( isset( $h[2] ) && '' !== $h[2] ? $h[2] : $h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $url ) ) {
					continue; }
				$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				$host  = (string) preg_replace( '/^www\./', '', $host );
				$out[] = array(
					'internal' => '' === $host || ( '' !== $home && $host === $home ),
					'text'     => trim( html_entity_decode( wp_strip_all_tags( $row[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				);
			}
		}
		return $out;
	}

	private static function longest_gap_without_heading( $html ) {
		$parts = preg_split( '#<h[1-6]\b[^>]*>.*?</h[1-6]>#is', $html );
		$max   = 0;
		foreach ( (array) $parts as $part ) {
			$max = max( $max, count( self::tokens( implode( ' ', self::paragraphs( (string) $part ) ) ) ) );
		}
		return $max;
	}

	public static function tokens( $text ) {
		$text = self::lower( (string) $text );
		preg_match_all( '/[\p{L}\p{M}\p{N}]+(?:[\'’][\p{L}]+)*/u', $text, $m );
		return isset( $m[0] ) ? $m[0] : array();
	}

	private static function stems( array $tokens ) {
		$out = array();
		foreach ( $tokens as $t ) {
			$t = str_replace( array( "'", '’' ), '', (string) $t );
			if ( strlen( $t ) > 3 && preg_match( '/^[a-z]+$/', $t ) ) {
				if ( 'ies' === substr( $t, -3 ) ) {
					$t = substr( $t, 0, -3 ) . 'y'; } elseif ( 's' === substr( $t, -1 ) && 'ss' !== substr( $t, -2 ) ) {
					$t = substr( $t, 0, -1 ); }
			}
			$out[] = $t;
		}
		return $out;
	}

	private static function phrase_count( array $haystack, array $needle ) {
		$n     = count( $needle );
		$h     = count( $haystack );
		$count = 0;
		if ( 0 === $n || $h < $n ) {
			return 0; }
		for ( $i = 0; $i <= $h - $n; $i++ ) {
			if ( $haystack[ $i ] === $needle[0] && array_slice( $haystack, $i, $n ) === $needle ) {
				++$count;
				$i += $n - 1; }
		}
		return $count;
	}

	private static function phrase_position( array $haystack, array $needle ) {
		$n = count( $needle );
		$h = count( $haystack );
		for ( $i = 0; $n > 0 && $i <= $h - $n; $i++ ) {
			if ( array_slice( $haystack, $i, $n ) === $needle ) {
				return $i; }
		}
		return PHP_INT_MAX;
	}

	private static function is_latin( $text ) {
		$letters = preg_match_all( '/\p{L}/u', (string) $text );
		return $letters > 0 && ( preg_match_all( '/[A-Za-z]/', (string) $text ) / $letters ) >= 0.7;
	}

	private static function flesch( array $words, $sentences ) {
		$count     = max( 1, count( $words ) );
		$syllables = 0;
		foreach ( $words as $w ) {
			$w = preg_replace( '/[^a-z]/', '', $w );
			if ( '' === $w ) {
				++$syllables;
				continue; }
			$w          = preg_replace( '/e$/', '', $w );
			$syllables += max( 1, preg_match_all( '/[aeiouy]+/', (string) $w ) );
		}
		return round( 206.835 - 1.015 * ( $count / max( 1, $sentences ) ) - 84.6 * ( $syllables / $count ), 1 );
	}

	public static function pixel_width( $text ) {
		$width = 0.0;
		foreach ( preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( false !== strpos( "il.,:;|!'’", $ch ) ) {
				$width += 5.5; } elseif ( false !== strpos( 'fjtr ()-', $ch ) ) {
				$width += 6.5; } elseif ( false !== strpos( 'mwMW', $ch ) ) {
					$width += 16.0; } elseif ( preg_match( '/\p{Lu}/u', $ch ) ) {
					$width += 13.0; } elseif ( strlen( $ch ) > 1 ) {
							$width += 14.0; } else {
							$width += 10.5; }
		}
		return (int) round( $width * 0.55 );
	}

	private static function transition_pattern() {
		return '/\b(?:however|therefore|moreover|furthermore|meanwhile|consequently|additionally|besides|because|although|though|while|whereas|instead|otherwise|nevertheless|nonetheless|similarly|likewise|finally|first|second|third|next|then|afterward|previously|subsequently|as a result|for example|for instance|in addition|in contrast|on the other hand|according to|in fact|so that|since|unless|despite)\b/i';
	}

	private static function stopwords() {
		static $set = null;
		if ( null === $set ) {
			$list = 'a about above after again against all also am an and any are aren\'t as at be because been before being below between both but by can can\'t cannot could couldn\'t did didn\'t do does doesn\'t doing don\'t down during each few for from further had hadn\'t has hasn\'t have haven\'t having he he\'d he\'ll he\'s her here here\'s hers herself him himself his how how\'s i i\'d i\'ll i\'m i\'ve if in into is isn\'t it it\'s its itself just let\'s me more most mustn\'t my myself no nor not of off on once only or other ought our ours ourselves out over own said says same shan\'t she she\'d she\'ll she\'s should shouldn\'t so some such than that that\'s the their theirs them themselves then there there\'s these they they\'d they\'ll they\'re they\'ve this those through to too under until up very was wasn\'t we we\'d we\'ll we\'re we\'ve were weren\'t what what\'s when when\'s where where\'s which while who who\'s whom why why\'s will with won\'t would wouldn\'t you you\'d you\'ll you\'re you\'ve your yours yourself yourselves will new one two many much may might also been being get got via per';
			$set  = array_fill_keys( explode( ' ', $list ), true );
		}
		return $set;
	}

	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	private static function len( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	private static function substr( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, (int) $length, 'UTF-8' ) : substr( (string) $text, 0, (int) $length );
	}

	private static function num( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 1, '.', '' ), '0' ), '.' );
	}
}
