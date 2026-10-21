<?php
/**
 * Article SEO Intelligence.
 *
 * A fully local analysis engine: it scores an article across keyphrase, headline, snippet,
 * structure, readability, media, links and newsroom signals, and derives keyphrase and
 * meta-description suggestions from the article text itself. It never calls an external
 * API, service, model or CDN; every result is computed from the post and the site database.
 */
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class ArticleIntelligence {
	const SCORE_META   = '_publishrank_seo_intel_score';
	const SUMMARY_META = '_publishrank_seo_intel_summary';
	const AJAX_ACTION  = 'publishrank_article_intel';
	const BATCH_ACTION = 'publishrank_intel_batch';
	const MAX_WORDS    = 6000;

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
		// Generic hooks are intentional: public custom post types are normally registered
		// on init, after plugins_loaded. We resolve the post type at runtime instead.
		add_action( 'save_post', array( $this, 'store_on_save' ), 40, 3 );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ), 10, 2 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
		add_action( 'admin_post_' . self::BATCH_ACTION, array( $this, 'batch' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Editorial post types eligible for local article intelligence.
	 * Built-in posts are always included; public non-hierarchical CPTs that expose
	 * the editor in REST are included by default and can be filtered by deployments.
	 *
	 * @return string[]
	 */
	public static function supported_post_types() {
		$types = get_post_types( array( 'public' => true, 'show_ui' => true, 'exclude_from_search' => false ), 'names' );
		$out   = array();
		foreach ( $types as $post_type ) {
			if ( 'post' === $post_type ) {
				$out[] = $post_type;
				continue;
			}
			if ( in_array( $post_type, array( 'page', 'attachment' ), true ) || is_post_type_hierarchical( $post_type ) ) {
				continue;
			}
			if ( post_type_supports( $post_type, 'editor' ) && post_type_supports( $post_type, 'title' ) ) {
				$out[] = $post_type;
			}
		}
		return array_values( array_unique( (array) apply_filters( 'publishrank_article_intelligence_post_types', $out ) ) );
	}

	/** Add the intelligence panel to each supported editorial editor. */
	public function meta_box( $post_type, $post = null ) {
		if ( $post instanceof \WP_Post ) {
			$post_type = $post->post_type;
		}
		$post_type = is_string( $post_type ) && '' !== $post_type ? $post_type : 'post';
		if ( ! in_array( $post_type, self::supported_post_types(), true ) ) {
			return;
		}
		add_meta_box(
			'publishrank_article_intelligence',
			__( 'PublishRank Article SEO Intelligence', 'publishrank' ),
			array( __CLASS__, 'render_panel' ),
			$post_type,
			'normal',
			'high'
		);
	}

	/** Load the live-analysis script only on the post editor. */
	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::supported_post_types(), true ) ) {
			return;
		}
		$version = \PublishRankSEO\Core\Branding::version();
		$css_path = PUBLISHRANK_SEO_PATH . 'assets/css/article-intel.css';
		$js_path  = PUBLISHRANK_SEO_PATH . 'assets/js/article-intel.js';
		$css_ver  = $version . '.' . (int) ( is_file( $css_path ) ? filemtime( $css_path ) : 0 );
		$js_ver   = $version . '.' . (int) ( is_file( $js_path ) ? filemtime( $js_path ) : 0 );
		wp_enqueue_style( 'publishrank-article-intel', \PublishRankSEO\Core\Branding::asset_url( 'css/article-intel.css' ), array(), $css_ver );
		wp_enqueue_script( 'publishrank-article-intel', \PublishRankSEO\Core\Branding::asset_url( 'js/article-intel.js' ), array( 'jquery', 'wp-data' ), $js_ver, true );
		wp_localize_script(
			'publishrank-article-intel',
			'publishrankIntel',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( self::AJAX_ACTION ),
					'action'    => self::AJAX_ACTION,
					'postId'    => isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'analyzing' => __( 'Analyzing…', 'publishrank' ),
					'failed'    => __( 'Analysis could not be completed. Save the draft and try again.', 'publishrank' ),
					'refresh'   => __( 'Re-analyze', 'publishrank' ),
					'analyze'   => __( 'Analyze article', 'publishrank' ),
					'synced'    => __( 'SEO values synced. Save or update the article to keep them.', 'publishrank' ),
					'scoreLabel'=> __( 'Editorial SEO score', 'publishrank' ),
				)
		);
	}

	/** Persist the latest score so lists, Insights and the REST API stay cheap. */
	public function store_on_save( $post_id, $post, $update = true ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status || ! in_array( $post->post_type, self::supported_post_types(), true ) ) {
			return;
		}
		self::store( $post );
	}

	/** Live analysis of unsaved editor content. */
	public function ajax() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$allowed = $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to analyze this article.', 'publishrank' ) ), 403 );
		}
		$text  = static function ( $key, $limit ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return self::substr( $value, $limit );
		};
		$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post    = $post_id ? get_post( $post_id ) : null;
		$data    = $post instanceof \WP_Post ? self::data_from_post( $post ) : self::blank_data();
		$data    = array_merge(
			$data,
				array(
					'post_id'       => $post_id,
					'title'         => $text( 'title', 300 ),
					'content'       => self::substr( $content, 400000 ),
					'excerpt'       => $text( 'excerpt', 3200 ),
				'slug'          => sanitize_title( $text( 'slug', 200 ) ),
				'seo_title'     => $text( 'seo_title', 255 ),
				'description'   => $text( 'description', 320 ),
				'keyphrase'     => $text( 'keyphrase', 100 ),
				'has_thumbnail' => ! empty( $_POST['has_thumbnail'] ) ? true : ( $post instanceof \WP_Post && has_post_thumbnail( $post ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		$report = self::analyze( $data, true );
			wp_send_json_success( array( 'score' => $report['score'], 'groups' => $report['groups'], 'html' => self::render_report( $report ), 'stats' => $report['stats'] ) );
	}

	/** Analyze the next batch of articles that have no stored score. */
	public function batch() {
		\PublishRankSEO\Admin\AdminAssets::guard( self::BATCH_ACTION );
		$ids = get_posts(
			array(
				'post_type'        => self::supported_post_types(),
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( array( 'key' => self::SCORE_META, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$done = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				self::store( $post );
				$done++;
			}
		}
		/* translators: %d: number of analyzed articles. */
		\PublishRankSEO\Admin\AdminAssets::done( 'publishrank-insights', 'success', sprintf( /* translators: %d: number of articles analyzed. */ _n( 'Analyzed %d article.', 'Analyzed %d articles.', $done, 'publishrank' ), $done ) );
	}

	/* ------------------------------------------------------------------ *
	 * Data helpers
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed> */
	public static function blank_data() {
		return array(
			'post_id' => 0, 'title' => '', 'content' => '', 'excerpt' => '', 'slug' => '', 'seo_title' => '',
			'description' => '', 'keyphrase' => '', 'has_thumbnail' => false, 'has_author' => false,
			'modified' => 0, 'citations' => '', 'home_host' => '',
		);
	}

	/** @param \WP_Post $post @return array<string,mixed> */
	public static function data_from_post( $post ) {
		$id = (int) $post->ID;
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
				'has_thumbnail' => (bool) has_post_thumbnail( $post ),
				'has_author'    => (bool) ( PostMeta::get( $id, PostMeta::AUTHOR_NAME, '' ) || get_userdata( (int) $post->post_author ) ),
				'modified'      => (int) get_post_modified_time( 'U', true, $post ),
				'citations'     => (string) PostMeta::get( $id, PostMeta::CITATIONS, '' ),
			)
		);
	}

	/** Analyze and persist a post. @param \WP_Post $post @return array<string,mixed> */
	public static function store( $post ) {
		$report = self::analyze( self::data_from_post( $post ), true );
		$fail   = array();
		foreach ( $report['checks'] as $check ) {
			if ( 'bad' === $check['status'] ) {
				$fail[] = $check['id'];
			}
		}
		update_post_meta( $post->ID, self::SCORE_META, (int) $report['score'] );
		update_post_meta( $post->ID, self::SUMMARY_META, array( 'score' => (int) $report['score'], 'fail' => array_slice( $fail, 0, 8 ), 'at' => gmdate( 'c' ) ) );
		return $report;
	}

	/** @return array{score:int,fail:string[],at:string}|null */
	public static function stored( $post_id ) {
		$summary = get_post_meta( $post_id, self::SUMMARY_META, true );
		return is_array( $summary ) && isset( $summary['score'] ) ? array( 'score' => (int) $summary['score'], 'fail' => (array) ( $summary['fail'] ?? array() ), 'at' => (string) ( $summary['at'] ?? '' ) ) : null;
	}

	/* ------------------------------------------------------------------ *
	 * Analysis
	 * ------------------------------------------------------------------ */

	/**
	 * Run every check. Pass $site = true to include database-backed checks
	 * (keyphrase cannibalization, duplicate headlines, inbound internal links).
	 *
	 * @param array<string,mixed> $d Article data, see blank_data().
	 * @return array<string,mixed>
	 */
	public static function analyze( array $d, $site = true ) {
		$d    = array_merge( self::blank_data(), $d );
		$html = self::clean_html( (string) $d['content'] );
		$paragraphs = self::paragraphs( $html );
		$text       = implode( "\n", $paragraphs );
		$words      = self::tokens( $text );
		if ( count( $words ) > self::MAX_WORDS ) {
			$words = array_slice( $words, 0, self::MAX_WORDS );
		}
		$word_count = count( $words );
		$headings   = self::headings( $html );
		$images     = self::images( $html );
		$links      = self::links( $html, (string) $d['home_host'] );
		$sentences  = self::sentences( $paragraphs );
		$latin      = self::is_latin( $text );
		$keyphrase  = trim( (string) $d['keyphrase'] );
		$keyphrase_occurrences = 0;
		$keyphrase_density = 0.0;
		$keyphrase_coverage = array( 'title' => false, 'description' => false, 'slug' => false, 'intro' => false, 'heading' => false, 'alt' => false, 'body' => false );
		$title      = trim( wp_strip_all_tags( (string) $d['title'] ) );
		$seo_title  = trim( (string) $d['seo_title'] );
		$serp_title = '' !== $seo_title ? $seo_title : $title;
		$description = trim( (string) $d['description'] );

		$checks = array();
		$add    = static function ( $id, $group, $status, $weight, $message ) use ( &$checks ) {
			$checks[] = array( 'id' => $id, 'group' => $group, 'status' => $status, 'weight' => (int) $weight, 'message' => $message );
		};

		/* ---- Keyphrase ---- */
		if ( '' === $keyphrase ) {
			$add( 'keyphrase_missing', 'keyphrase', 'bad', 3, __( 'Set a focus keyphrase so PublishRank can check how well the article targets it.', 'publishrank' ) );
		} else {
			$phrase = self::stems( self::tokens( $keyphrase ) );
			$pcount = count( $phrase );
			if ( $pcount >= 1 && $pcount <= 6 ) {
				$add( 'keyphrase_length', 'keyphrase', 'good', 1, __( 'The focus keyphrase has a sensible length.', 'publishrank' ) );
			} else {
				$add( 'keyphrase_length', 'keyphrase', 'ok', 1, __( 'Keep the focus keyphrase between one and six words.', 'publishrank' ) );
			}
				$in_title = self::phrase_count( self::stems( self::tokens( $serp_title ) ), $phrase ) > 0;
				$keyphrase_coverage['title'] = $in_title;
			if ( $in_title ) {
				$early = self::phrase_position( self::stems( self::tokens( $serp_title ) ), $phrase ) <= 3;
				$add( 'keyphrase_title', 'keyphrase', $early ? 'good' : 'ok', 3, $early ? __( 'The focus keyphrase appears at the start of the search title.', 'publishrank' ) : __( 'The focus keyphrase is in the search title; moving it closer to the start usually helps.', 'publishrank' ) );
			} else {
				$add( 'keyphrase_title', 'keyphrase', 'bad', 3, __( 'Add the focus keyphrase to the headline or SEO title.', 'publishrank' ) );
			}
			if ( '' !== $description ) {
					$in_desc = self::phrase_count( self::stems( self::tokens( $description ) ), $phrase ) > 0;
					$keyphrase_coverage['description'] = $in_desc;
				$add( 'keyphrase_description', 'keyphrase', $in_desc ? 'good' : 'bad', 2, $in_desc ? __( 'The meta description contains the focus keyphrase.', 'publishrank' ) : __( 'Mention the focus keyphrase in the meta description.', 'publishrank' ) );
			}
			$slug_words = array_filter( explode( '-', strtolower( (string) $d['slug'] ) ) );
			$ascii      = (bool) preg_match( '/^[a-z0-9 \'\-]+$/i', $keyphrase );
			if ( $ascii && $slug_words ) {
				$slug_stems = self::stems( array_values( $slug_words ) );
					$missing    = array_diff( $phrase, $slug_stems );
					$keyphrase_coverage['slug'] = empty( $missing );
				$add( 'keyphrase_slug', 'keyphrase', $missing ? 'ok' : 'good', 1, $missing ? __( 'Consider including the focus keyphrase words in the URL slug.', 'publishrank' ) : __( 'The URL slug contains the focus keyphrase.', 'publishrank' ) );
			}
			if ( $paragraphs ) {
				$first = self::stems( self::tokens( $paragraphs[0] ) );
					$in_first = self::phrase_count( array_slice( $first, 0, 80 ), $phrase ) > 0;
					$keyphrase_coverage['intro'] = $in_first;
				$add( 'keyphrase_intro', 'keyphrase', $in_first ? 'good' : 'bad', 2, $in_first ? __( 'The focus keyphrase appears in the opening paragraph.', 'publishrank' ) : __( 'Use the focus keyphrase within the opening paragraph.', 'publishrank' ) );
			}
			$sub = array_filter( $headings, static function ( $h ) { return $h['level'] >= 2 && $h['level'] <= 4; } );
			if ( $sub ) {
				$hit = false;
					foreach ( $sub as $h ) {
						if ( self::phrase_count( self::stems( self::tokens( $h['text'] ) ), $phrase ) > 0 ) { $hit = true; break; }
					}
					$keyphrase_coverage['heading'] = $hit;
				$add( 'keyphrase_heading', 'keyphrase', $hit ? 'good' : 'ok', 1, $hit ? __( 'A subheading contains the focus keyphrase.', 'publishrank' ) : __( 'Use the focus keyphrase (or a close variation) in at least one subheading.', 'publishrank' ) );
			}
			if ( $images ) {
				$hit = false;
					foreach ( $images as $img ) {
						if ( '' !== $img['alt'] && self::phrase_count( self::stems( self::tokens( $img['alt'] ) ), $phrase ) > 0 ) { $hit = true; break; }
					}
					$keyphrase_coverage['alt'] = $hit;
				$add( 'keyphrase_alt', 'keyphrase', $hit ? 'good' : 'ok', 1, $hit ? __( 'An image alt text contains the focus keyphrase.', 'publishrank' ) : __( 'Describe at least one image using the focus keyphrase where it is accurate.', 'publishrank' ) );
			}
				$stemmed = self::stems( $words );
				$found   = self::phrase_count( $stemmed, $phrase );
				$keyphrase_occurrences = $found;
				$keyphrase_coverage['body'] = $found > 0;
				$keyphrase_density = ( $found * $pcount / max( 1, $word_count ) ) * 100;
				if ( $word_count >= 100 ) {
					$density = ( $found * $pcount / max( 1, $word_count ) ) * 100;
					$keyphrase_density = $density;
				if ( 0 === $found ) {
					$add( 'keyphrase_density', 'keyphrase', 'bad', 2, __( 'The focus keyphrase does not appear in the article body.', 'publishrank' ) );
				} elseif ( $density > 3.0 ) {
					/* translators: %s: keyphrase density percentage. */
					$add( 'keyphrase_density', 'keyphrase', 'bad', 2, sprintf( __( 'Keyphrase density is %s%%, which reads as stuffing. Vary the wording.', 'publishrank' ), self::num( $density ) ) );
				} elseif ( $density < 0.3 ) {
					/* translators: %s: keyphrase density percentage. */
					$add( 'keyphrase_density', 'keyphrase', 'ok', 2, sprintf( __( 'Keyphrase density is %s%%; mention the topic a little more naturally.', 'publishrank' ), self::num( $density ) ) );
				} else {
					/* translators: %s: keyphrase density percentage. */
					$add( 'keyphrase_density', 'keyphrase', 'good', 2, sprintf( __( 'Keyphrase density is a healthy %s%%.', 'publishrank' ), self::num( $density ) ) );
				}
			}
		}

		/* ---- Headline ---- */
		$tlen = self::len( $title );
		if ( 0 === $tlen ) {
			$add( 'headline_missing', 'headline', 'bad', 3, __( 'Add a headline.', 'publishrank' ) );
		} else {
			$status = ( $tlen >= 30 && $tlen <= 65 ) ? 'good' : ( ( $tlen >= 20 && $tlen <= 80 ) ? 'ok' : 'bad' );
			/* translators: %d: headline length in characters. */
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
			/* translators: 1: estimated pixel width, 2: pixel limit. */
			$add( 'snippet_title_width', 'snippet', $pixels <= 580 ? 'good' : ( $pixels <= 640 ? 'ok' : 'bad' ), 2, $pixels <= 580 ? sprintf( __( 'The search title should fit without truncation (about %1$d of %2$d px).', 'publishrank' ), $pixels, 580 ) : sprintf( __( 'The search title may be truncated in results (about %1$d of %2$d px).', 'publishrank' ), $pixels, 580 ) );
		}
		$dlen = self::len( $description );
		if ( 0 === $dlen ) {
			$add( 'snippet_description', 'snippet', 'bad', 2, __( 'Write a 70–160 character meta description; without one, search engines choose a snippet for you.', 'publishrank' ) );
		} elseif ( $dlen >= 70 && $dlen <= 160 ) {
			$add( 'snippet_description', 'snippet', 'good', 2, __( 'The meta description length is good.', 'publishrank' ) );
		} else {
			/* translators: %d: meta description length. */
			$add( 'snippet_description', 'snippet', 'ok', 2, sprintf( __( 'The meta description is %d characters; aim for 70 to 160.', 'publishrank' ), $dlen ) );
		}

		/* ---- Structure ---- */
		if ( $word_count < 150 ) {
			/* translators: %d: word count. */
			$add( 'structure_length', 'structure', 'bad', 2, sprintf( __( 'Only %d words: too thin for most news coverage. Add context, sourcing and detail.', 'publishrank' ), $word_count ) );
		} elseif ( $word_count < 300 ) {
			/* translators: %d: word count. */
			$add( 'structure_length', 'structure', 'ok', 2, sprintf( __( '%d words: acceptable for a brief, but 300+ gives readers and search engines more to work with.', 'publishrank' ), $word_count ) );
		} else {
			/* translators: %d: word count. */
			$add( 'structure_length', 'structure', 'good', 2, sprintf( __( '%d words: a substantial article.', 'publishrank' ), $word_count ) );
		}
		$h2plus = array_filter( $headings, static function ( $h ) { return $h['level'] >= 2; } );
		if ( $word_count > 300 ) {
			$add( 'structure_subheadings', 'structure', $h2plus ? 'good' : 'bad', 2, $h2plus ? __( 'Subheadings break the article into scannable sections.', 'publishrank' ) : __( 'Add descriptive subheadings (H2/H3) to break up the article.', 'publishrank' ) );
			if ( $h2plus ) {
				$gap = self::longest_gap_without_heading( $html );
				$add( 'structure_heading_spacing', 'structure', $gap > 350 ? 'ok' : 'good', 1, $gap > 350 ? __( 'Some sections run over 350 words without a subheading.', 'publishrank' ) : __( 'Subheadings are spaced evenly.', 'publishrank' ) );
			}
		}
		$prev = 1; $skipped = false; $h1 = false;
		foreach ( $headings as $h ) {
			if ( 1 === $h['level'] ) { $h1 = true; }
			if ( $h['level'] > $prev + 1 && $prev >= 2 ) { $skipped = true; }
			$prev = max( $prev, $h['level'] ) === $h['level'] ? $h['level'] : $h['level'];
		}
		if ( $headings ) {
			$add( 'structure_hierarchy', 'structure', ( $skipped || $h1 ) ? 'ok' : 'good', 1, $h1 ? __( 'The content contains an H1; the theme already outputs the headline as H1, so use H2 and below.', 'publishrank' ) : ( $skipped ? __( 'Heading levels skip a step (for example H2 to H4). Keep the hierarchy sequential.', 'publishrank' ) : __( 'The heading hierarchy is clean.', 'publishrank' ) ) );
		}
		if ( $paragraphs ) {
			$long = 0;
			foreach ( $paragraphs as $p ) {
				if ( count( self::tokens( $p ) ) > 150 ) { $long++; }
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
				if ( count( self::tokens( $s ) ) > 25 ) { $long_sentences++; }
			}
			$share = $long_sentences / $sentence_count;
			/* translators: %d: percentage of long sentences. */
			$add( 'read_sentence_length', 'readability', $share <= 0.25 ? 'good' : ( $share <= 0.4 ? 'ok' : 'bad' ), 2, $share <= 0.25 ? sprintf( __( 'Only %d%% of sentences are long (25+ words).', 'publishrank' ), round( $share * 100 ) ) : sprintf( __( '%d%% of sentences exceed 25 words; shorten some.', 'publishrank' ), round( $share * 100 ) ) );
			if ( $latin && $word_count >= 50 ) {
				$ease = self::flesch( $words, $sentence_count );
				/* translators: %s: Flesch reading ease score. */
				$add( 'read_flesch', 'readability', $ease >= 60 ? 'good' : ( $ease >= 45 ? 'ok' : 'bad' ), 2, $ease >= 60 ? sprintf( __( 'Flesch reading ease is %s: easy to read.', 'publishrank' ), self::num( $ease ) ) : sprintf( __( 'Flesch reading ease is %s: prefer shorter sentences and simpler words.', 'publishrank' ), self::num( $ease ) ) );
				$passive = 0; $transition = 0;
				foreach ( $sentences as $s ) {
					if ( preg_match( '/\b(?:is|are|was|were|be|been|being)\s+(?:\w+ly\s+)?\w+(?:ed|en)\b/i', $s ) ) { $passive++; }
					if ( preg_match( self::transition_pattern(), $s ) ) { $transition++; }
				}
				$p_share = $passive / $sentence_count;
				/* translators: %d: percentage of sentences in passive voice. */
				$add( 'read_passive', 'readability', $p_share <= 0.1 ? 'good' : ( $p_share <= 0.2 ? 'ok' : 'bad' ), 1, $p_share <= 0.1 ? sprintf( __( 'Passive voice is kept low (%d%% of sentences).', 'publishrank' ), round( $p_share * 100 ) ) : sprintf( __( '%d%% of sentences look passive; prefer active constructions.', 'publishrank' ), round( $p_share * 100 ) ) );
				$t_share = $transition / $sentence_count;
				/* translators: %d: percentage of sentences containing transition words. */
				$add( 'read_transitions', 'readability', $t_share >= 0.2 ? 'good' : ( $t_share >= 0.1 ? 'ok' : 'bad' ), 1, $t_share >= 0.2 ? sprintf( __( '%d%% of sentences use transition words, which helps flow.', 'publishrank' ), round( $t_share * 100 ) ) : sprintf( __( 'Only %d%% of sentences use transition words; connect ideas more clearly.', 'publishrank' ), round( $t_share * 100 ) ) );
			}
			if ( $sentence_count >= 6 ) {
				$run = 1; $max_run = 1; $last = '';
				foreach ( $sentences as $s ) {
					$first = self::tokens( $s );
					$first = $first ? $first[0] : '';
					$run   = ( '' !== $first && $first === $last ) ? $run + 1 : 1;
					$max_run = max( $max_run, $run );
					$last  = $first;
				}
				$add( 'read_repetition', 'readability', $max_run >= 3 ? 'ok' : 'good', 1, $max_run >= 3 ? __( 'Three or more consecutive sentences start with the same word; vary the openings.', 'publishrank' ) : __( 'Sentence openings are varied.', 'publishrank' ) );
			}
		}

		/* ---- Media ---- */
		$add( 'media_featured', 'media', $d['has_thumbnail'] ? 'good' : 'bad', 2, $d['has_thumbnail'] ? __( 'A featured image is set for previews and Google News.', 'publishrank' ) : __( 'Set a featured image for article cards, social previews and Google News.', 'publishrank' ) );
		if ( $images ) {
			$no_alt = 0;
			foreach ( $images as $img ) {
				if ( '' === $img['alt'] ) { $no_alt++; }
			}
			/* translators: %d: number of images without alt text. */
				$add( 'media_alt', 'media', 0 === $no_alt ? 'good' : ( $no_alt < count( $images ) ? 'ok' : 'bad' ), 1, 0 === $no_alt ? __( 'Every image in the article has alt text.', 'publishrank' ) : sprintf( /* translators: %d: number of images missing alt text. */ _n( '%d image is missing alt text.', '%d images are missing alt text.', $no_alt, 'publishrank' ), $no_alt ) );
				$quality = self::image_quality( $html );
				$add( 'media_weight', 'media', 0 === $quality['large'] ? 'good' : ( $quality['large'] < 2 ? 'ok' : 'bad' ), 2, 0 === $quality['large'] ? __( 'Article images are within the recommended file-size budget.', 'publishrank' ) : sprintf( _n( '%d image is larger than 350 KB; compress it before publishing.', '%d images are larger than 350 KB; compress them before publishing.', $quality['large'], 'publishrank' ), $quality['large'] ) );
				$add( 'media_dimensions', 'media', 0 === $quality['oversize'] ? 'good' : 'ok', 1, 0 === $quality['oversize'] ? __( 'Images are sized for the configured maximum width.', 'publishrank' ) : sprintf( _n( '%d image exceeds the configured maximum width; resize it.', '%d images exceed the configured maximum width; resize them.', $quality['oversize'], 'publishrank' ), $quality['oversize'] ) );
				$add( 'media_format', 'media', 0 === $quality['legacy'] ? 'good' : 'ok', 1, 0 === $quality['legacy'] ? __( 'Images use modern WebP/AVIF formats where available.', 'publishrank' ) : sprintf( _n( '%d image uses a legacy format; convert it to WebP or AVIF when practical.', '%d images use legacy formats; convert them to WebP or AVIF when practical.', $quality['legacy'], 'publishrank' ), $quality['legacy'] ) );
		}

		/* ---- Links ---- */
		$internal = 0; $external = 0; $generic = 0;
		foreach ( $links as $link ) {
			if ( $link['internal'] ) { $internal++; } else { $external++; }
			if ( preg_match( '/^(click here|here|read more|more|link|this|this link|learn more|source)$/iu', $link['text'] ) ) { $generic++; }
		}
		$need = $word_count >= 600 ? 2 : 1;
		if ( $word_count >= 150 ) {
			/* translators: %d: internal links found. */
			$add( 'links_internal', 'links', $internal >= $need ? 'good' : ( $internal > 0 ? 'ok' : 'bad' ), 2, $internal >= $need ? sprintf( /* translators: %d: number of internal links found. */ _n( '%d internal link found.', '%d internal links found.', $internal, 'publishrank' ), $internal ) : __( 'Link to related coverage on your own site to help readers and crawlers.', 'publishrank' ) );
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
				/* translators: %d: days since the last update. */
				$add( 'news_fresh', 'news', 'ok', 1, sprintf( __( 'Last updated %d days ago; review it for accuracy and refresh if the story has moved on.', 'publishrank' ), $age ) );
			}
		}

		/* ---- Site-level checks ---- */
		if ( $site ) {
			foreach ( self::site_checks( $d, $keyphrase, $title ) as $check ) {
				$checks[] = $check;
			}
		}

		$earned = 0.0; $max = 0.0; $groups = array();
		foreach ( $checks as $check ) {
			$g = $check['group'];
			if ( ! isset( $groups[ $g ] ) ) { $groups[ $g ] = array( 'earned' => 0.0, 'max' => 0.0 ); }
			if ( 'info' === $check['status'] ) { continue; }
			$w = (float) $check['weight'];
			$v = 'good' === $check['status'] ? $w : ( 'ok' === $check['status'] ? $w * 0.5 : 0.0 );
			$max += $w; $earned += $v;
			$groups[ $g ]['max'] += $w; $groups[ $g ]['earned'] += $v;
		}
		$score = $max > 0 ? (int) round( ( $earned / $max ) * 100 ) : 0;
		foreach ( $groups as $g => $v ) {
			$groups[ $g ] = $v['max'] > 0 ? (int) round( ( $v['earned'] / $v['max'] ) * 100 ) : 0;
		}

		$keyphrase_suggestions = $word_count >= 80 ? self::suggest_keyphrases( $title, $words, $headings ) : array();
		if ( ! $keyphrase_suggestions ) {
			$title_phrase = self::suggest_title_keyphrase( $title );
			if ( '' !== $title_phrase ) { $keyphrase_suggestions[] = array( 'phrase' => $title_phrase, 'count' => 0 ); }
		}

		return array(
			'score'       => $score,
			'rating'      => $score >= 80 ? 'good' : ( $score >= 55 ? 'ok' : 'bad' ),
			'groups'      => $groups,
			'checks'      => $checks,
			'fields'      => array( 'title' => $title, 'seo_title' => $seo_title, 'description' => $description, 'keyphrase' => $keyphrase ),
			'stats'       => array(
				'words'        => $word_count,
				'images'       => count( $images ),
				'focus_keyword' => $keyphrase,
				'focus_keyword_occurrences' => $keyphrase_occurrences,
				'focus_keyword_density' => round( $keyphrase_density, 2 ),
				'focus_keyword_coverage' => count( array_filter( $keyphrase_coverage ) ),
				'focus_keyword_coverage_total' => '' !== $keyphrase ? count( $keyphrase_coverage ) : 0,
				'sentences'    => $sentence_count,
				'paragraphs'   => count( $paragraphs ),
				'reading_min'  => max( 1, (int) ceil( $word_count / 238 ) ),
				'internal'     => $internal,
				'external'     => $external,
				'headings'     => count( $headings ),
				'serp_pixels'  => $pixels,
			),
			'suggestions' => array(
				'keyphrases'  => $keyphrase_suggestions,
				'title'       => '' === $seo_title ? $title : '',
				'description' => '' === $description ? self::suggest_description( $paragraphs, (string) $d['excerpt'] ) : '',
			),
		);
	}

	/** Database-backed checks. @return array<int,array<string,mixed>> */
	private static function site_checks( array $d, $keyphrase, $title ) {
		$checks = array();
		$pid    = (int) $d['post_id'];
		if ( ! function_exists( 'get_posts' ) ) {
			return $checks;
		}
		if ( '' !== $keyphrase ) {
			$others = get_posts(
				array(
					'post_type'        => self::supported_post_types(),
					'post_status'      => array( 'publish', 'future', 'pending', 'draft' ),
					'posts_per_page'   => 5,
					'post__not_in'     => $pid ? array( $pid ) : array(),
					'meta_key'         => PostMeta::FOCUS_KEYWORD, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => $keyphrase, // phpcs:ignore WordPress.DB.SlowDBQuery
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			if ( $others ) {
				$names = array();
				foreach ( $others as $other ) { $names[] = '“' . wp_strip_all_tags( get_the_title( $other ) ) . '”'; }
				/* translators: %s: list of article titles. */
				$checks[] = array( 'id' => 'site_cannibalization', 'group' => 'site', 'status' => 'bad', 'weight' => 2, 'message' => sprintf( /* translators: %s: comma-separated titles of articles targeting the same keyphrase. */ __( 'Other articles target the same focus keyphrase and may compete with this one: %s', 'publishrank' ), implode( ', ', $names ) ) );
			} else {
				$checks[] = array( 'id' => 'site_cannibalization', 'group' => 'site', 'status' => 'good', 'weight' => 2, 'message' => __( 'No other article uses this focus keyphrase.', 'publishrank' ) );
			}
		}
		global $wpdb;
		if ( '' !== $title && isset( $wpdb ) && is_object( $wpdb ) ) {
			$types = self::supported_post_types();
				$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
				$args  = array_merge( $types, array( $title, $pid ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in contains only generated %s placeholders from the validated post-type list; all values are bound below.
				$dupe = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status IN ('publish','future','pending','draft') AND post_title = %s AND ID <> %d LIMIT 1", $args ) );
			$checks[] = array( 'id' => 'site_duplicate_title', 'group' => 'site', 'status' => $dupe ? 'bad' : 'good', 'weight' => 1, 'message' => $dupe ? __( 'Another article already uses this exact headline.', 'publishrank' ) : __( 'The headline is unique on this site.', 'publishrank' ) );
		}
		if ( $pid && class_exists( '\\PublishRankSEO\\InternalLinks\\Store' ) && \PublishRankSEO\InternalLinks\Store::enabled() && 'publish' === get_post_status( $pid ) ) {
			$node = \PublishRankSEO\InternalLinks\Store::node_for_object( $pid );
			if ( $node ) {
				$in = (int) $node->inbound_count;
				/* translators: %d: number of inbound internal links. */
				$checks[] = array( 'id' => 'site_inbound', 'group' => 'site', 'status' => $in >= 2 ? 'good' : ( $in === 1 ? 'ok' : 'bad' ), 'weight' => 1, 'message' => $in >= 1 ? sprintf( _n( '%d other article links here.', '%d other articles link here.', $in, 'publishrank' ), $in ) : __( 'No other article links to this one yet (orphan). See Internal Links for suggestions.', 'publishrank' ) );
			}
		}
		return $checks;
	}

	/* ------------------------------------------------------------------ *
	 * Suggestions
	 * ------------------------------------------------------------------ */

	/** Offer a conservative keyphrase seed from the headline when the draft is too short to score phrase frequency. */
	public static function suggest_title_keyphrase( $title ) {
		$stop   = self::stopwords();
		$tokens = array_values( array_filter( self::tokens( wp_strip_all_tags( (string) $title ) ), static function ( $word ) use ( $stop ) {
			return ! isset( $stop[ $word ] ) && self::len( $word ) > 2 && ! ctype_digit( $word );
		} ) );
		return implode( ' ', array_slice( $tokens, 0, 3 ) );
	}

	/**
	 * Rank 1–3 word phrases by frequency, weighting headline and subheading occurrences.
	 *
	 * @param string[] $words Lowercase body tokens.
	 * @param array<int,array{level:int,text:string}> $headings
	 * @return array<int,array{phrase:string,count:int}>
	 */
	public static function suggest_keyphrases( $title, array $words, array $headings ) {
		$stop   = self::stopwords();
		$scores = array(); $counts = array();
		$feed   = static function ( array $tokens, $boost, $count_body ) use ( &$scores, &$counts, $stop ) {
			$n = count( $tokens );
			for ( $size = 1; $size <= 3; $size++ ) {
				for ( $i = 0; $i + $size <= $n; $i++ ) {
					$first = $tokens[ $i ]; $last = $tokens[ $i + $size - 1 ];
					if ( isset( $stop[ $first ] ) || isset( $stop[ $last ] ) ) { continue; }
					if ( 1 === $size && ( self::len( $first ) < 4 || ctype_digit( $first ) ) ) { continue; }
					if ( ctype_digit( $first ) || ctype_digit( $last ) ) { continue; }
					$key = implode( ' ', array_slice( $tokens, $i, $size ) );
					$scores[ $key ] = ( $scores[ $key ] ?? 0 ) + $boost * ( 1 + 0.6 * ( $size - 1 ) );
					if ( $count_body ) { $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1; }
				}
			}
		};
		$feed( $words, 1, true );
		$feed( self::tokens( $title ), 3, false );
		foreach ( $headings as $h ) { $feed( self::tokens( $h['text'] ), 2, false ); }
		arsort( $scores );
		$picked = array();
		foreach ( $scores as $phrase => $score ) {
			$count = (int) ( $counts[ $phrase ] ?? 0 );
			$size  = substr_count( $phrase, ' ' ) + 1;
			if ( $count < ( 1 === $size ? 3 : 2 ) ) { continue; }
			$covered = false;
			foreach ( $picked as $p ) {
				if ( false !== strpos( ' ' . $p['phrase'] . ' ', ' ' . $phrase . ' ' ) ) { $covered = true; break; }
			}
			if ( $covered ) { continue; }
			$picked[] = array( 'phrase' => (string) $phrase, 'count' => $count );
			if ( count( $picked ) >= 6 ) { break; }
		}
		return $picked;
	}

	/** Build a meta description from the excerpt or the opening sentences. @param string[] $paragraphs */
	public static function suggest_description( array $paragraphs, $excerpt = '' ) {
		$source = trim( wp_strip_all_tags( (string) $excerpt ) );
		if ( '' === $source && $paragraphs ) {
			$source = implode( ' ', array_slice( $paragraphs, 0, 3 ) );
		}
		$source = trim( preg_replace( '/\s+/u', ' ', $source ) );
		if ( self::len( $source ) < 40 ) { return ''; }
		$out = '';
		foreach ( (array) preg_split( '/(?<=[.!?۔؟])\s+/u', $source, -1, PREG_SPLIT_NO_EMPTY ) as $sentence ) {
			$next = '' === $out ? $sentence : $out . ' ' . $sentence;
			if ( self::len( $next ) > 158 ) { break; }
			$out = $next;
		}
		if ( self::len( $out ) < 70 ) {
			$out = self::substr( $source, 155 );
			$cut = strrpos( $out, ' ' );
			if ( false !== $cut && $cut > 90 ) { $out = substr( $out, 0, $cut ); }
			$out = rtrim( $out, " ,;:-" ) . '…';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	/** Group labels in display order. @return array<string,string> */
	public static function group_labels() {
		return array(
			'keyphrase'   => __( 'Keyphrase', 'publishrank' ),
			'headline'    => __( 'Headline', 'publishrank' ),
			'snippet'     => __( 'Search snippet', 'publishrank' ),
			'structure'   => __( 'Structure', 'publishrank' ),
			'readability' => __( 'Readability', 'publishrank' ),
			'media'       => __( 'Media', 'publishrank' ),
			'links'       => __( 'Links', 'publishrank' ),
			'news'        => __( 'Newsroom signals', 'publishrank' ),
			'site'        => __( 'Across your site', 'publishrank' ),
		);
	}

	/** Human label for a stored failing-check id. */
	public static function check_label( $id ) {
		$labels = array(
			'keyphrase_missing' => __( 'No focus keyphrase', 'publishrank' ), 'keyphrase_title' => __( 'Keyphrase not in title', 'publishrank' ),
			'keyphrase_description' => __( 'Keyphrase not in description', 'publishrank' ), 'keyphrase_intro' => __( 'Keyphrase not in opening paragraph', 'publishrank' ),
			'keyphrase_density' => __( 'Keyphrase density', 'publishrank' ), 'headline_length' => __( 'Headline length', 'publishrank' ),
			'headline_tone' => __( 'Sensational headline', 'publishrank' ), 'snippet_title_width' => __( 'Search title too wide', 'publishrank' ),
			'snippet_description' => __( 'Missing or poor meta description', 'publishrank' ), 'structure_length' => __( 'Thin article', 'publishrank' ),
			'structure_subheadings' => __( 'No subheadings', 'publishrank' ), 'read_sentence_length' => __( 'Long sentences', 'publishrank' ),
			'read_flesch' => __( 'Hard to read', 'publishrank' ), 'read_passive' => __( 'Passive voice', 'publishrank' ),
			'read_transitions' => __( 'Few transition words', 'publishrank' ), 'media_featured' => __( 'No featured image', 'publishrank' ),
			'media_alt' => __( 'Images without alt text', 'publishrank' ), 'links_internal' => __( 'No internal links', 'publishrank' ),
				'news_byline' => __( 'No byline', 'publishrank' ), 'media_weight' => __( 'Image file size', 'publishrank' ), 'media_dimensions' => __( 'Image dimensions', 'publishrank' ), 'media_format' => __( 'Image format', 'publishrank' ), 'site_cannibalization' => __( 'Keyphrase cannibalization', 'publishrank' ),
			'site_duplicate_title' => __( 'Duplicate headline', 'publishrank' ), 'site_inbound' => __( 'Orphan article', 'publishrank' ),
		);
		return isset( $labels[ $id ] ) ? $labels[ $id ] : (string) $id;
	}

	/** Escaped HTML for a report; used by the meta box and the AJAX response. @param array<string,mixed> $report */
	public static function render_report( array $report ) {
		$labels = self::group_labels();
		$icons  = array( 'good' => '●', 'ok' => '●', 'bad' => '●', 'info' => '○' );
		$s      = $report['stats'];
		$f      = isset( $report['fields'] ) && is_array( $report['fields'] ) ? $report['fields'] : array( 'title' => '', 'seo_title' => '', 'description' => '', 'keyphrase' => '' );
		$sug    = $report['suggestions'];
		$out    = '<div class="pr-intel__summary pr-intel__summary--' . esc_attr( $report['rating'] ) . '"><span class="pr-intel__score">' . esc_html( (string) $report['score'] ) . '</span><span class="pr-intel__of">/100</span>';
		/* translators: 1: word count, 2: reading minutes, 3: image count, 4: internal links, 5: external links. */
		$out   .= '<span class="pr-intel__stats">' . esc_html( sprintf( __( '%1$d words · %2$d min read · %3$d images · %4$d internal / %5$d external links', 'publishrank' ), $s['words'], $s['reading_min'], $s['images'], $s['internal'], $s['external'] ) ) . '</span></div>';
		$quick_open = '' === (string) $f['keyphrase'] || '' === (string) $f['seo_title'] || '' === (string) $f['description'];
		$keyphrase_hint = ! empty( $sug['keyphrases'][0]['phrase'] ) ? (string) $sug['keyphrases'][0]['phrase'] : '';
		$out .= '<details class="pr-intel__quick"' . ( $quick_open ? ' open' : '' ) . '><summary>' . esc_html__( 'SEO essentials — add or edit values', 'publishrank' ) . '</summary><div class="pr-intel__quick-grid">';
		$out .= '<label>' . esc_html__( 'Focus keyphrase', 'publishrank' ) . '<span class="pr-intel__field-row"><input class="pr-intel__field" type="text" maxlength="100" data-meta-key="_publishrank_seo_focus_keyword" value="' . esc_attr( (string) $f['keyphrase'] ) . '" placeholder="' . esc_attr( $keyphrase_hint ? sprintf( /* translators: %s: a suggested focus keyphrase inferred from the article. */ __( 'Suggested: %s', 'publishrank' ), $keyphrase_hint ) : __( 'Enter the main topic of this article', 'publishrank' ) ) . '" />';
		if ( '' !== $keyphrase_hint ) { $out .= '<button type="button" class="button button-small pr-intel__apply-suggestion" data-meta-key="_publishrank_seo_focus_keyword" data-value="' . esc_attr( $keyphrase_hint ) . '">' . esc_html__( 'Use suggestion', 'publishrank' ) . '</button>'; }
		$out .= '</span></label>';
		$out .= '<label>' . esc_html__( 'SEO title', 'publishrank' ) . '<span class="pr-intel__field-row"><input class="pr-intel__field" type="text" maxlength="255" data-meta-key="_publishrank_seo_title" value="' . esc_attr( (string) $f['seo_title'] ) . '" placeholder="' . esc_attr( '' !== (string) $f['title'] ? sprintf( /* translators: %s: current article headline used as the default search title. */ __( 'Optional override — default: %s', 'publishrank' ), $f['title'] ) : __( 'Add a concise search title', 'publishrank' ) ) . '" />';
		if ( ! empty( $sug['title'] ) ) { $out .= '<button type="button" class="button button-small pr-intel__apply-suggestion" data-meta-key="_publishrank_seo_title" data-value="' . esc_attr( $sug['title'] ) . '">' . esc_html__( 'Use headline', 'publishrank' ) . '</button>'; }
		$out .= '</span></label>';
		$out .= '<label>' . esc_html__( 'Meta description', 'publishrank' ) . '<span class="pr-intel__field-row pr-intel__field-row--textarea"><textarea class="pr-intel__field" maxlength="320" rows="3" data-meta-key="_publishrank_seo_description" placeholder="' . esc_attr( ! empty( $sug['description'] ) ? $sug['description'] : __( 'Summarize the article in one clear, reader-focused sentence.', 'publishrank' ) ) . '">' . esc_textarea( (string) $f['description'] ) . '</textarea>';
		if ( ! empty( $sug['description'] ) ) { $out .= '<button type="button" class="button button-small pr-intel__apply-suggestion" data-meta-key="_publishrank_seo_description" data-value="' . esc_attr( $sug['description'] ) . '">' . esc_html__( 'Use draft', 'publishrank' ) . '</button>'; }
		$out .= '</span></label></div><p class="description pr-intel__save-status" role="status">' . esc_html__( 'These values sync with PublishRank SEO settings. Save or update the article to keep them.', 'publishrank' ) . '</p></details>';
		if ( ! empty( $s['focus_keyword'] ) ) {
			$out .= '<div class="pr-intel__keyword-metrics"><strong>' . esc_html__( 'Focus keyphrase', 'publishrank' ) . ':</strong> ' . esc_html( $s['focus_keyword'] ) . ' · ' . esc_html( sprintf( __( '%1$d occurrence(s), %2$s%% density, %3$d/%4$d placement signals', 'publishrank' ), $s['focus_keyword_occurrences'], $s['focus_keyword_density'], $s['focus_keyword_coverage'], $s['focus_keyword_coverage_total'] ) ) . '</div>';
		}
		$out   .= '<div class="pr-intel__groups">';
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $report['groups'][ $key ] ) || 0 === count( self::checks_in( $report, $key, true ) ) ) { continue; }
			$val  = (int) $report['groups'][ $key ];
			$out .= '<span class="pr-intel__chip pr-intel__chip--' . esc_attr( $val >= 80 ? 'good' : ( $val >= 55 ? 'ok' : 'bad' ) ) . '">' . esc_html( $label ) . ' ' . esc_html( (string) $val ) . '</span>';
		}
		$out .= '</div>';
		foreach ( $labels as $key => $label ) {
			$items = self::checks_in( $report, $key, false );
			if ( ! $items ) { continue; }
			usort( $items, static function ( $a, $b ) { $rank = array( 'bad' => 0, 'ok' => 1, 'info' => 2, 'good' => 3 ); return $rank[ $a['status'] ] <=> $rank[ $b['status'] ]; } );
			$open = false;
			foreach ( $items as $item ) { if ( 'bad' === $item['status'] || 'ok' === $item['status'] ) { $open = true; break; } }
			$out .= '<details class="pr-intel__group"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $label ) . '</summary><ul>';
			foreach ( $items as $item ) {
				$out .= '<li class="pr-intel__item pr-intel__item--' . esc_attr( $item['status'] ) . '"><span class="pr-intel__dot" aria-hidden="true">' . esc_html( $icons[ $item['status'] ] ) . '</span> ' . esc_html( $item['message'] ) . '</li>';
			}
			$out .= '</ul></details>';
		}
		if ( ! empty( $sug['keyphrases'] ) || ! empty( $sug['description'] ) ) {
			$out .= '<div class="pr-intel__suggest"><strong>' . esc_html__( 'Suggestions from your article text', 'publishrank' ) . '</strong>';
			if ( ! empty( $sug['keyphrases'] ) ) {
				$out .= '<p>' . esc_html__( 'Likely keyphrases:', 'publishrank' ) . ' ';
				foreach ( $sug['keyphrases'] as $k ) {
					$source = ! empty( $k['count'] ) ? ' <span class="pr-intel__count">×' . esc_html( (string) $k['count'] ) . '</span>' : ' <span class="pr-intel__count">' . esc_html__( 'headline', 'publishrank' ) . '</span>';
					$out .= '<button type="button" class="button button-small pr-intel__use-keyphrase" data-phrase="' . esc_attr( $k['phrase'] ) . '">' . esc_html( $k['phrase'] ) . $source . '</button> ';
				}
				$out .= '</p>';
			}
			if ( ! empty( $sug['description'] ) ) {
				$out .= '<p>' . esc_html__( 'Draft meta description:', 'publishrank' ) . ' <em>' . esc_html( $sug['description'] ) . '</em> <button type="button" class="button button-small pr-intel__use-description" data-text="' . esc_attr( $sug['description'] ) . '">' . esc_html__( 'Use this', 'publishrank' ) . '</button></p>';
			}
			$out .= '</div>';
		}
		return $out;
	}

	/** Panel markup for the post editor meta box. @param \WP_Post $post */
	public static function render_panel( $post ) {
		$report = self::analyze( self::data_from_post( $post ), true );
		echo '<div class="pr-intel" id="pr-intel" data-score="' . esc_attr( (string) $report['score'] ) . '">';
		echo '<div class="pr-intel__head"><strong>' . esc_html__( 'Article SEO intelligence', 'publishrank' ) . '</strong> <button type="button" class="button button-small" id="pr-intel-refresh">' . esc_html__( 'Re-analyze', 'publishrank' ) . '</button> <span class="description">' . esc_html__( 'Computed locally on your server. No external service is contacted.', 'publishrank' ) . '</span></div>';
		echo '<div class="pr-intel__body" id="pr-intel-body">' . self::render_report( $report ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- render_report escapes every dynamic value.
	}

	/**
	 * Site-wide rollup from stored scores for Insights.
	 *
	 * @param int[] $ids Post IDs to summarize.
	 * @return array<string,mixed>
	 */
	public static function overview( array $ids ) {
		$scored = 0; $sum = 0; $dist = array( 'low' => 0, 'mid' => 0, 'high' => 0 ); $fails = array();
		foreach ( $ids as $id ) {
			$summary = self::stored( (int) $id );
			if ( ! $summary ) { continue; }
			$scored++; $sum += $summary['score'];
			if ( $summary['score'] >= 80 ) { $dist['high']++; } elseif ( $summary['score'] >= 55 ) { $dist['mid']++; } else { $dist['low']++; }
			foreach ( $summary['fail'] as $f ) { $fails[ $f ] = ( $fails[ $f ] ?? 0 ) + 1; }
		}
		arsort( $fails );
		return array( 'total' => count( $ids ), 'scored' => $scored, 'average' => $scored ? (int) round( $sum / $scored ) : 0, 'distribution' => $dist, 'top_issues' => array_slice( $fails, 0, 6, true ) );
	}

	/** Focus keyphrases used by more than one article. @return array<int,array{keyphrase:string,count:int}> */
	public static function cannibalized_keyphrases( $limit = 10 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$types = self::supported_post_types();
			$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$args  = array_merge( array( PostMeta::FOCUS_KEYWORD ), $types, array( (int) $limit ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in contains only generated %s placeholders from the validated post-type list; all values are bound below.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value AS keyphrase, COUNT(*) AS total FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_type IN ($in) AND p.post_status = 'publish' GROUP BY m.meta_value HAVING COUNT(*) > 1 ORDER BY total DESC LIMIT %d", $args ) );
		$out  = array();
		foreach ( (array) $rows as $row ) { $out[] = array( 'keyphrase' => (string) $row->keyphrase, 'count' => (int) $row->total ); }
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Text utilities (pure, no WordPress state)
	 * ------------------------------------------------------------------ */

	/** @return array<int,array<string,mixed>> */
	private static function checks_in( array $report, $group, $scored_only ) {
		$out = array();
		foreach ( $report['checks'] as $check ) {
			if ( $check['group'] !== $group ) { continue; }
			if ( $scored_only && 'info' === $check['status'] ) { continue; }
			$out[] = $check;
		}
		return $out;
	}

	private static function clean_html( $html ) {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', (string) $html );
		return (string) preg_replace( '#<(script|style|noscript|iframe)\b.*?</\1>#is', '', $html );
	}

	/** @return string[] */
	private static function paragraphs( $html ) {
		$html = (string) preg_replace( '/\[\/?[a-z][a-z0-9_\-]*(?:\s[^\]]*)?\]/i', ' ', $html );
		$html = (string) preg_replace( '#</(?:p|div|li|h[1-6]|blockquote|tr|figcaption)>|<br\s*/?>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out  = array();
		foreach ( (array) preg_split( '/\n+/', $text ) as $line ) {
			$line = trim( (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $line ) );
			if ( '' !== $line ) { $out[] = $line; }
		}
		return $out;
	}

	/** @param string[] $paragraphs @return string[] */
	private static function sentences( array $paragraphs ) {
		$out = array();
		foreach ( $paragraphs as $p ) {
			foreach ( (array) preg_split( '/(?<=[.!?۔؟])\s+/u', $p, -1, PREG_SPLIT_NO_EMPTY ) as $s ) {
				if ( count( self::tokens( $s ) ) >= 2 ) { $out[] = $s; }
			}
		}
		return $out;
	}

	/** @return array<int,array{level:int,text:string}> */
	private static function headings( $html ) {
		$out = array();
		if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$out[] = array( 'level' => (int) $row[1], 'text' => trim( html_entity_decode( wp_strip_all_tags( $row[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			}
		}
		return $out;
	}

	/** @return array<int,array{alt:string}> */
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

	/** @return array{large:int,oversize:int,legacy:int} */
	private static function image_quality( $html ) {
		$out = array( 'large' => 0, 'oversize' => 0, 'legacy' => 0 ); $max = (int) get_option( 'publishrank_image_max_width', 2000 );
		if ( ! preg_match_all( '/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\')/i', $html, $matches, PREG_SET_ORDER ) ) { return $out; }
		foreach ( $matches as $match ) { $url = html_entity_decode( ( isset( $match[2] ) && '' !== $match[2] ? $match[2] : $match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); $id = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $url ) : 0; if ( ! $id ) { continue; } $file = get_attached_file( $id ); if ( $file && is_readable( $file ) && filesize( $file ) > 350 * 1024 ) { $out['large']++; } $meta = wp_get_attachment_metadata( $id ); if ( ! empty( $meta['width'] ) && (int) $meta['width'] > $max ) { $out['oversize']++; } $ext = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) ); if ( $ext && ! in_array( $ext, array( 'webp', 'avif' ), true ) ) { $out['legacy']++; } }
		return $out;
	}

	/** @return array<int,array{internal:bool,text:string}> */
	private static function links( $html, $home_host ) {
		$home = '' !== $home_host ? strtolower( $home_host ) : ( function_exists( 'home_url' ) ? strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) : '' );
		$home = (string) preg_replace( '/^www\./', '', $home );
		$out  = array();
		if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				if ( ! preg_match( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $row[1], $h ) ) { continue; }
				$url = trim( html_entity_decode( isset( $h[2] ) && '' !== $h[2] ? $h[2] : $h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $url ) ) { continue; }
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				$host = (string) preg_replace( '/^www\./', '', $host );
				$out[] = array( 'internal' => '' === $host || ( '' !== $home && $host === $home ), 'text' => trim( html_entity_decode( wp_strip_all_tags( $row[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			}
		}
		return $out;
	}

	/** Longest run of words between headings (or from start/end). */
	private static function longest_gap_without_heading( $html ) {
		$parts = preg_split( '#<h[1-6]\b[^>]*>.*?</h[1-6]>#is', $html );
		$max   = 0;
		foreach ( (array) $parts as $part ) {
			$max = max( $max, count( self::tokens( implode( ' ', self::paragraphs( (string) $part ) ) ) ) );
		}
		return $max;
	}

	/** @return string[] */
	public static function tokens( $text ) {
		$text = self::lower( (string) $text );
		preg_match_all( '/[\p{L}\p{M}\p{N}]+(?:[\'’][\p{L}]+)*/u', $text, $m );
		return isset( $m[0] ) ? $m[0] : array();
	}

	/** Light English stemming for matching only; non-ASCII tokens are returned unchanged. @param string[] $tokens @return string[] */
	private static function stems( array $tokens ) {
		$out = array();
		foreach ( $tokens as $t ) {
			$t = str_replace( array( "'", '’' ), '', (string) $t );
			if ( strlen( $t ) > 3 && preg_match( '/^[a-z]+$/', $t ) ) {
				if ( 'ies' === substr( $t, -3 ) ) { $t = substr( $t, 0, -3 ) . 'y'; }
				elseif ( 's' === substr( $t, -1 ) && 'ss' !== substr( $t, -2 ) ) { $t = substr( $t, 0, -1 ); }
			}
			$out[] = $t;
		}
		return $out;
	}

	/** @param string[] $haystack @param string[] $needle */
	private static function phrase_count( array $haystack, array $needle ) {
		$n = count( $needle ); $h = count( $haystack ); $count = 0;
		if ( 0 === $n || $h < $n ) { return 0; }
		for ( $i = 0; $i <= $h - $n; $i++ ) {
			if ( $haystack[ $i ] === $needle[0] && array_slice( $haystack, $i, $n ) === $needle ) { $count++; $i += $n - 1; }
		}
		return $count;
	}

	/** Zero-based index of the first match, or PHP_INT_MAX. @param string[] $haystack @param string[] $needle */
	private static function phrase_position( array $haystack, array $needle ) {
		$n = count( $needle ); $h = count( $haystack );
		for ( $i = 0; $n > 0 && $i <= $h - $n; $i++ ) {
			if ( array_slice( $haystack, $i, $n ) === $needle ) { return $i; }
		}
		return PHP_INT_MAX;
	}

	private static function is_latin( $text ) {
		$letters = preg_match_all( '/\p{L}/u', (string) $text );
		return $letters > 0 && ( preg_match_all( '/[A-Za-z]/', (string) $text ) / $letters ) >= 0.7;
	}

	/** @param string[] $words */
	private static function flesch( array $words, $sentences ) {
		$count = max( 1, count( $words ) ); $syllables = 0;
		foreach ( $words as $w ) {
			$w = preg_replace( '/[^a-z]/', '', $w );
			if ( '' === $w ) { $syllables++; continue; }
			$w = preg_replace( '/e$/', '', $w );
			$syllables += max( 1, preg_match_all( '/[aeiouy]+/', (string) $w ) );
		}
		return round( 206.835 - 1.015 * ( $count / max( 1, $sentences ) ) - 84.6 * ( $syllables / $count ), 1 );
	}

	/** Approximate Arial 20px width of a title as rendered in search results. */
	public static function pixel_width( $text ) {
		$width = 0.0;
		foreach ( preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( false !== strpos( "il.,:;|!'’", $ch ) ) { $width += 5.5; }
			elseif ( false !== strpos( 'fjtr ()-', $ch ) ) { $width += 6.5; }
			elseif ( false !== strpos( 'mwMW', $ch ) ) { $width += 16.0; }
			elseif ( preg_match( '/\p{Lu}/u', $ch ) ) { $width += 13.0; }
			elseif ( strlen( $ch ) > 1 ) { $width += 14.0; }
			else { $width += 10.5; }
		}
		return (int) round( $width * 0.55 );
	}

	private static function transition_pattern() {
		return '/\b(?:however|therefore|moreover|furthermore|meanwhile|consequently|additionally|besides|because|although|though|while|whereas|instead|otherwise|nevertheless|nonetheless|similarly|likewise|finally|first|second|third|next|then|afterward|previously|subsequently|as a result|for example|for instance|in addition|in contrast|on the other hand|according to|in fact|so that|since|unless|despite)\b/i';
	}

	/** @return array<string,bool> */
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
