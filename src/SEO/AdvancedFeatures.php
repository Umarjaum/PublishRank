<?php
/** Premium-style technical SEO features implemented without third-party dependencies. */
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;

final class AdvancedFeatures {
	/** Register supported frontend and editor hooks. */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( PostMeta::class, 'save' ), 10 );
		add_action( 'wp_head', array( $this, 'render_pagination_links' ), 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_noindex_posts' ), 10, 2 );
	}

	/** Add editorial controls to posts without changing theme presentation. */
	public function add_meta_box() {
		add_meta_box( 'publishrank-seo-meta', __( 'PublishRank', 'publishrank' ), array( $this, 'render_meta_box' ), 'post', 'normal', 'high' );
	}

	/** @param \WP_Post $post Render fields. */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'publishrank_seo_meta', 'publishrank_seo_meta_nonce' );
			$analysis = ( new KeywordDensityChecker() )->analyze( $post );
			$audit    = ( new EditorialAudit() )->analyze( $post );
		$fields = array(
			PostMeta::TITLE => __( 'SEO title', 'publishrank' ),
			PostMeta::DESCRIPTION => __( 'Meta description', 'publishrank' ),
			PostMeta::CANONICAL => __( 'Canonical URL', 'publishrank' ),
			PostMeta::OG_TITLE => __( 'Social title', 'publishrank' ),
				PostMeta::OG_DESCRIPTION => __( 'Social description', 'publishrank' ),
				PostMeta::OG_IMAGE => __( 'Social image URL', 'publishrank' ),
				PostMeta::OG_IMAGE_ALT => __( 'Social image alt text', 'publishrank' ),
				PostMeta::TWITTER_CREATOR => __( 'X/Twitter creator handle', 'publishrank' ),
			PostMeta::FOCUS_KEYWORD => __( 'Focus keyphrase', 'publishrank' ),
			PostMeta::VIDEO_THUMBNAIL => __( 'Video thumbnail URL', 'publishrank' ),
				PostMeta::VIDEO_DURATION => __( 'Video duration (ISO 8601)', 'publishrank' ),
				PostMeta::CONTENT_TIER => __( 'Article content tier', 'publishrank' ),
				PostMeta::AUTHOR_NAME => __( 'Schema author name override', 'publishrank' ),
				PostMeta::AUTHOR_JOB_TITLE => __( 'Schema author job title', 'publishrank' ),
				PostMeta::AUTHOR_IMAGE => __( 'Schema author image URL', 'publishrank' ),
				PostMeta::CITATIONS => __( 'Article citations', 'publishrank' ),
		);
		$primary_category = absint( PostMeta::get( $post->ID, PostMeta::PRIMARY_CATEGORY, 0 ) );
		echo '<p>' . esc_html__( 'These fields control technical search output only; the JNews theme remains responsible for presentation.', 'publishrank' ) . '</p>';
			if ( $analysis['keyword'] ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Keyword density', 'publishrank' ) . '</strong>: ' . esc_html( $analysis['keyword'] ) . ' — ' . esc_html( (string) $analysis['count'] ) . ' occurrence(s), ' . esc_html( (string) $analysis['density'] ) . '%, ' . esc_html( $analysis['status'] ) . '.</p><p class="description">' . esc_html__( 'Healthy guidance is 0.5%–3%; this is an editorial signal, not a ranking guarantee.', 'publishrank' ) . '</p></div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Enter a focus keyphrase to calculate its density automatically from the title and content.', 'publishrank' ) . '</p>';
		}
		foreach ( $fields as $key => $label ) {
				$type = false !== strpos( $key, 'description' ) || PostMeta::CITATIONS === $key ? 'textarea' : 'text';
			$value = PostMeta::get( $post->ID, $key );
			echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br />';
			if ( 'textarea' === $type ) {
				echo '<textarea class="widefat" rows="3" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
			} else {
				echo '<input class="widefat" type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
			}
			echo '<div class="notice ' . esc_attr( $audit['score'] >= 75 ? 'notice-success' : ( $audit['score'] >= 50 ? 'notice-warning' : 'notice-error' ) ) . ' inline"><p><strong>' . esc_html__( 'Editorial SEO audit', 'publishrank' ) . '</strong>: ' . esc_html( (string) $audit['score'] ) . '/100 — ' . esc_html( sprintf( /* translators: 1: word count, 2: reading ease. */ __( '%1$d words; reading ease %2$s.', 'publishrank' ), (int) $audit['words'], (string) $audit['reading_ease'] ) ) . '</p>';
			if ( ! empty( $audit['issues'] ) ) {
				echo '<ul>'; foreach ( array_slice( $audit['issues'], 0, 5 ) as $issue ) { echo '<li>' . esc_html( $issue ) . '</li>'; } echo '</ul>';
			} else { echo '<p>' . esc_html__( 'Core editorial checks pass. Review the article as a journalist before publishing.', 'publishrank' ) . '</p>'; }
			echo '</div>';
			echo '</p>';
		}
		echo '<hr style="margin:14px 0;" /><h3 style="margin:0 0 4px;">' . esc_html__( 'Keyphrase strategy', 'publishrank' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Primary and secondary keyphrases feed density analysis, schema keywords and Google News keywords.', 'publishrank' ) . '</p>';
		$value = PostMeta::get( $post->ID, PostMeta::PRIMARY_KEYPHRASE );
		echo '<p><label for="' . esc_attr( PostMeta::PRIMARY_KEYPHRASE ) . '"><strong>' . esc_html__( 'Primary keyphrase', 'publishrank' ) . '</strong></label><br />';
		echo '<input class="widefat" type="text" id="' . esc_attr( PostMeta::PRIMARY_KEYPHRASE ) . '" name="' . esc_attr( PostMeta::PRIMARY_KEYPHRASE ) . '" value="' . esc_attr( $value ) . '" maxlength="100" /></p>';
		$this->render_repeater( $post->ID, PostMeta::SECONDARY_KEYPHRASES, __( 'Secondary keyphrases', 'publishrank' ), __( 'Supporting phrases this article should also rank for.', 'publishrank' ), 10 );
		$this->render_repeater( $post->ID, PostMeta::RELATED_TERMS, __( 'Related terms', 'publishrank' ), __( 'Synonyms and closely related vocabulary for entity coverage.', 'publishrank' ), 20 );
		$value = PostMeta::get( $post->ID, PostMeta::ENTITY_TOPIC );
		echo '<p><label for="' . esc_attr( PostMeta::ENTITY_TOPIC ) . '"><strong>' . esc_html__( 'Entity / Topic', 'publishrank' ) . '</strong></label><br />';
		echo '<input class="widefat" type="text" id="' . esc_attr( PostMeta::ENTITY_TOPIC ) . '" name="' . esc_attr( PostMeta::ENTITY_TOPIC ) . '" value="' . esc_attr( $value ) . '" maxlength="120" /></p>';
		echo '<p><label for="' . esc_attr( PostMeta::PRIMARY_CATEGORY ) . '"><strong>' . esc_html__( 'Primary category', 'publishrank' ) . '</strong></label><br />';
		echo '<select class="widefat" id="' . esc_attr( PostMeta::PRIMARY_CATEGORY ) . '" name="' . esc_attr( PostMeta::PRIMARY_CATEGORY ) . '"><option value="0">' . esc_html__( 'Use the post category', 'publishrank' ) . '</option>';
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $category ) {
			echo '<option value="' . esc_attr( $category->term_id ) . '" ' . selected( $primary_category, $category->term_id, false ) . '>' . esc_html( $category->name ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label><input type="checkbox" name="' . esc_attr( PostMeta::CORNERSTONE ) . '" value="1" ' . checked( PostMeta::get( $post->ID, PostMeta::CORNERSTONE, '0' ), '1', false ) . ' /> ' . esc_html__( 'Mark as cornerstone content', 'publishrank' ) . '</label></p>';
		echo '<p><label for="' . esc_attr( PostMeta::ROBOTS ) . '"><strong>' . esc_html__( 'Robots directives', 'publishrank' ) . '</strong></label><br />';
		echo '<input class="widefat" type="text" id="' . esc_attr( PostMeta::ROBOTS ) . '" name="' . esc_attr( PostMeta::ROBOTS ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::ROBOTS, 'index,follow' ) ) . '" placeholder="index,follow,max-image-preview:large" />';
		echo '<span class="description">' . esc_html__( 'Use comma-separated supported directives, e.g. noindex,follow. noindex prevents this URL from appearing in search results; it does not block crawling. A robots.txt Disallow rule is different.', 'publishrank' ) . '</span></p>';
			echo '<p><label for="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '"><strong>' . esc_html__( 'Article schema type', 'publishrank' ) . '</strong></label><br />';
			echo '<select class="widefat" id="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '" name="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '">';
			foreach ( array( 'NewsArticle' => __( 'NewsArticle', 'publishrank' ), 'Article' => __( 'Article', 'publishrank' ), 'BlogPosting' => __( 'BlogPosting', 'publishrank' ) ) as $schema_type => $schema_label ) {
				echo '<option value="' . esc_attr( $schema_type ) . '" ' . selected( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, 'NewsArticle' ), $schema_type, false ) . '>' . esc_html( $schema_label ) . '</option>';
			}
			echo '</select><span class="description">' . esc_html__( 'Choose how this article is described in structured data. Search engines decide whether and how to show rich results.', 'publishrank' ) . '</span></p>';
			echo '<p><label for="' . esc_attr( PostMeta::TWITTER_CARD ) . '"><strong>' . esc_html__( 'X/Twitter card', 'publishrank' ) . '</strong></label><br /><select class="widefat" id="' . esc_attr( PostMeta::TWITTER_CARD ) . '" name="' . esc_attr( PostMeta::TWITTER_CARD ) . '">';
		foreach ( array( 'summary_large_image' => __( 'Summary with large image', 'publishrank' ), 'summary' => __( 'Summary', 'publishrank' ) ) as $card => $label ) { echo '<option value="' . esc_attr( $card ) . '" ' . selected( PostMeta::get( $post->ID, PostMeta::TWITTER_CARD, 'summary_large_image' ), $card, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></p><p><label><input type="checkbox" name="' . esc_attr( PostMeta::ACCESSIBLE_FOR_FREE ) . '" value="1" ' . checked( PostMeta::get( $post->ID, PostMeta::ACCESSIBLE_FOR_FREE, '1' ), '1', false ) . ' /> ' . esc_html__( 'Article is accessible for free', 'publishrank' ) . '</label></p>';
	}

	private function render_repeater( $post_id, $key, $label, $description, $max ) {
		$values = PostMeta::get_list( $post_id, $key );
		echo '<div class="rhs-repeater" data-key="' . esc_attr( $key ) . '" data-max="' . esc_attr( (string) $max ) . '">';
		echo '<p style="margin-bottom:4px;"><strong>' . esc_html( $label ) . '</strong></p>';
		echo '<div class="rhs-repeater__rows">';
		foreach ( $values as $value ) { $this->render_repeater_row( $key, $value ); }
		echo '</div>';
		echo '<p><button type="button" class="button rhs-repeater__add">+ ' . esc_html__( 'Add', 'publishrank' ) . '</button></p>';
		echo '<p class="description">' . esc_html( $description ) . '</p></div>';
	}

	private function render_repeater_row( $key, $value = '' ) {
		echo '<div class="rhs-repeater__row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;">';
		echo '<input class="widefat" style="flex:1;" type="text" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $value ) . '" maxlength="100" />';
		echo '<button type="button" class="button rhs-repeater__remove" aria-label="' . esc_attr__( 'Remove item', 'publishrank' ) . '">&times;</button></div>';
	}

	/** Emit rel prev/next on paginated archives. */
	public function render_pagination_links() {
		if ( is_singular() || is_admin() ) {
			return;
		}
		$prev = get_previous_posts_page_link();
		$next = get_next_posts_page_link();
		if ( $prev ) {
			echo '<link rel="prev" href="' . esc_url( $prev ) . '" />' . "\n";
		}
		if ( $next ) {
			echo '<link rel="next" href="' . esc_url( $next ) . '" />' . "\n";
		}
	}

	/** Keep noindex posts out of the core posts sitemap. */
	public function exclude_noindex_posts( $args, $post_type ) {
		if ( 'post' !== $post_type ) {
			return $args;
		}
		$args['meta_query'] = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$args['meta_query'][] = array(
			'relation' => 'OR',
			array( 'key' => PostMeta::ROBOTS, 'compare' => 'NOT EXISTS' ),
			array( 'key' => PostMeta::ROBOTS, 'value' => 'noindex', 'compare' => 'NOT LIKE' ),
		);
		return $args;
	}

}

/* EOF */
