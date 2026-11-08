<?php
/** Schema Suite: per-document schema picker, Google validation shortcuts, author profile fields. */
namespace PublishRankSEO\Schema;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class SchemaSuite {

	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 12, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_validate' ), 110 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'show_user_profile', array( $this, 'user_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'user_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_fields' ) );
	}


	/** Enqueue the schema picker behavior only on post editor screens. */
	public function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return; }
		wp_enqueue_script( 'publishrank-schema-suite', plugins_url( 'assets/js/schema-suite.js', PUBLISHRANK_SEO_FILE ), array(), PUBLISHRANK_SEO_VERSION, true );
	}
	/** Google Rich Results Test URL for any permalink. */
	public static function google_test_url( $url ) {
		return 'https://search.google.com/test/rich-results?url=' . rawurlencode( (string) $url );
	}

	/** Dedicated schema meta box on every public post type. */
	public function add_meta_box( $post_type = '', $post = null ) {
		if ( $post_type && in_array( $post_type, PostMeta::post_types(), true ) ) {
				add_meta_box( 'publishrank-schema', __( 'Schema Selection', 'publishrank' ), array( $this, 'render_meta_box' ), $post_type, 'normal', 'high' );
		}
	}

		/** @param \WP_Post $post */
	public function render_meta_box( $post ) {
		// Share the nonce used by PostMeta::save so the schema selector works in
		// Gutenberg, Classic Editor, and page editors without a second save path.
		wp_nonce_field( 'publishrank_seo_meta', 'publishrank_seo_meta_nonce' );
		$selected  = PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' );
		$permalink = get_permalink( $post );
		echo '<div class="publishrank-schema-suite">';
		$schema_label = ( 'page' === $post->post_type ) ? __( 'Schema type for this page', 'publishrank' ) : __( 'Schema type for this post', 'publishrank' );
		echo '<p><label for="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '"><strong>' . esc_html( $schema_label ) . '</strong></label><br />';
		echo '<select class="widefat" id="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '" name="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '">';
		echo '<option value=""' . selected( $selected, '', false ) . '>' . esc_html__( '— Automatic (recommended default) —', 'publishrank' ) . '</option>';
		if ( 'page' === $post->post_type ) {
			echo '<optgroup label="' . esc_attr__( 'Page schema', 'publishrank' ) . '">';
			foreach ( SchemaTypes::page_types() as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
			} echo '</optgroup>'; } else {
			echo '<optgroup label="' . esc_attr__( 'FAQs', 'publishrank' ) . '">';
			foreach ( SchemaTypes::faq_types() as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
			} echo '</optgroup>'; }
			foreach ( SchemaTypes::grouped() as $group => $types ) {
				echo '<optgroup label="' . esc_attr( $group ) . '">';
				foreach ( $types as $key => $label ) {
					echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</optgroup>';
			}
			echo '</select></p>';
			echo '<p class="description">' . esc_html__( 'Automatic uses the configured page default (Standard Web Page) for pages and NewsArticle for news posts. Select a matching type only when the content and required details support it. Event, Product, Recipe and Review schemas appear only when their required fields are complete. Publisher, author, WebPage and breadcrumb nodes remain connected.', 'publishrank' ) . '</p>';

			// News-publisher detail panels.
			\PublishRankSEO\Schema\LiveBlogSchema::render_panel( $post );
			echo '<div class="pr-schema-panel" data-types="WebStory" style="display:none"><hr /><h4>' . esc_html__( 'Web Story details', 'publishrank' ) . '</h4><p class="description">' . esc_html__( 'PublishRank emits standards-compliant Article markup for a Web Story and connects it to the same single publisher, WebSite and WebPage nodes used by the rest of the site. Use the actual portrait poster and square publisher logo used by the story.', 'publishrank' ) . '</p>';
			$this->schema_text_field( $post->ID, PostMeta::WEB_STORY_POSTER, __( 'Portrait poster image URL', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::WEB_STORY_PUBLISHER_LOGO, __( 'Story publisher logo URL (optional)', 'publishrank' ) );
			echo '</div>';

			echo '<div class="pr-schema-panel" data-types="VideoObject" style="display:none"><hr /><h4>' . esc_html__( 'Video details', 'publishrank' ) . '</h4>';
			foreach ( array(
				PostMeta::VIDEO_URL       => __( 'Video file URL (contentUrl)', 'publishrank' ),
				PostMeta::VIDEO_EMBED_URL => __( 'Embed URL (embedUrl)', 'publishrank' ),
				PostMeta::VIDEO_THUMBNAIL => __( 'Thumbnail URL', 'publishrank' ),
			) as $key => $label ) {
				echo '<p><label><strong>' . esc_html( $label ) . '</strong><br /><input class="widefat" type="url" name="' . esc_attr( $key ) . '" value="' . esc_attr( PostMeta::get( $post->ID, $key ) ) . '" /></label></p>';
			}
			echo '<p><label><strong>' . esc_html__( 'Duration (ISO 8601, e.g. PT4M30S)', 'publishrank' ) . '</strong><br /><input class="widefat" type="text" name="' . esc_attr( PostMeta::VIDEO_DURATION ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::VIDEO_DURATION ) ) . '" /></label></p></div>';

			echo '<div class="pr-schema-panel" data-types="AudioObject,PodcastEpisode" style="display:none"><hr /><h4>' . esc_html__( 'Audio details', 'publishrank' ) . '</h4>';
			echo '<p><label><strong>' . esc_html__( 'Audio file URL (contentUrl)', 'publishrank' ) . '</strong><br /><input class="widefat" type="url" name="' . esc_attr( PostMeta::AUDIO_URL ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::AUDIO_URL ) ) . '" /></label></p>';
			echo '<p><label><strong>' . esc_html__( 'Duration (ISO 8601, e.g. PT32M10S)', 'publishrank' ) . '</strong><br /><input class="widefat" type="text" name="' . esc_attr( PostMeta::AUDIO_DURATION ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::AUDIO_DURATION ) ) . '" /></label></p></div>';

			echo '<div class="pr-schema-panel" data-types="FAQPage" style="display:none"><hr /><h4>' . esc_html__( 'FAQ questions and answers', 'publishrank' ) . '</h4>';
			echo '<p class="description">' . esc_html__( 'Only filled-in pairs are published. If no complete question and answer exists, PublishRank outputs a standard web page instead of an empty FAQ Page.', 'publishrank' ) . '</p>';
			$faq_items = get_post_meta( $post->ID, PostMeta::FAQ_ITEMS, true );
			$faq_items = is_array( $faq_items ) ? array_values( $faq_items ) : array();
			$rows      = max( 5, min( 20, count( $faq_items ) + 2 ) );
			for ( $i = 0; $i < $rows; $i++ ) {
				$q = isset( $faq_items[ $i ]['q'] ) ? (string) $faq_items[ $i ]['q'] : '';
				$a = isset( $faq_items[ $i ]['a'] ) ? (string) $faq_items[ $i ]['a'] : '';
				echo '<p><label><strong>' . esc_html( sprintf( /* translators: %d: question number */ __( 'Question %d', 'publishrank' ), $i + 1 ) ) . '</strong><br /><input class="widefat" type="text" name="publishrank_faq_items[' . (int) $i . '][q]" value="' . esc_attr( $q ) . '" /></label><br /><textarea class="widefat" rows="2" name="publishrank_faq_items[' . (int) $i . '][a]" placeholder="' . esc_attr__( 'Answer', 'publishrank' ) . '">' . esc_textarea( $a ) . '</textarea></p>';
			}
			echo '</div>';
			echo '<div class="pr-schema-panel" data-types="Event" style="display:none"><hr /><h4>' . esc_html__( 'Event details', 'publishrank' ) . '</h4><p class="description">' . esc_html__( 'Event markup is emitted only when the start date, venue, city and two-letter country code are complete.', 'publishrank' ) . '</p>';
			$this->schema_text_field( $post->ID, PostMeta::EVENT_START, __( 'Start date and time (ISO 8601)', 'publishrank' ), '2026-10-25T18:00:00-04:00' );
			$this->schema_text_field( $post->ID, PostMeta::EVENT_LOCATION, __( 'Venue name', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::EVENT_STREET, __( 'Street address', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::EVENT_LOCALITY, __( 'City / locality', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::EVENT_REGION, __( 'State / region (optional)', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::EVENT_POSTAL_CODE, __( 'Postal code (optional)', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::EVENT_COUNTRY, __( 'Country code (ISO 3166-1 alpha-2)', 'publishrank' ), 'US' );
			echo '</div><div class="pr-schema-panel" data-types="Recipe" style="display:none"><hr /><h4>' . esc_html__( 'Recipe details', 'publishrank' ) . '</h4><p class="description">' . esc_html__( 'Enter the actual ingredients and ordered preparation steps, one per line.', 'publishrank' ) . '</p>';
			$this->schema_textarea_field( $post->ID, PostMeta::RECIPE_INGREDIENTS, __( 'Ingredients', 'publishrank' ) );
			$this->schema_textarea_field( $post->ID, PostMeta::RECIPE_STEPS, __( 'Preparation steps', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::RECIPE_TIME, __( 'Preparation time (ISO 8601 duration)', 'publishrank' ), 'PT30M' );
			$this->schema_text_field( $post->ID, PostMeta::RECIPE_YIELD, __( 'Yield', 'publishrank' ), '4 servings' );
			echo '</div><div class="pr-schema-panel" data-types="JobPosting" style="display:none"><hr /><h4>' . esc_html__( 'Job posting details', 'publishrank' ) . '</h4><p class="description">' . esc_html__( 'Job markup is output only when the hiring organization, a closing date and either a location or remote country are filled in. The post title is the job title and the post content is the job description. Remove or expire the posting once the job is filled.', 'publishrank' ) . '</p><input type="hidden" name="publishrank_job_fields" value="1" />';
			$this->schema_text_field( $post->ID, PostMeta::JOB_HIRING_ORG, __( 'Hiring organization', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::JOB_HIRING_ORG_URL, __( 'Organization website (optional)', 'publishrank' ), 'https://', 'url' );
			$this->schema_text_field( $post->ID, PostMeta::JOB_HIRING_ORG_LOGO, __( 'Organization logo URL (optional)', 'publishrank' ), 'https://', 'url' );
			$this->schema_text_field( $post->ID, PostMeta::JOB_VALID_THROUGH, __( 'Applications close (YYYY-MM-DD)', 'publishrank' ), '', 'date' );
			$selected_types = array_filter( explode( ',', (string) PostMeta::get( $post->ID, PostMeta::JOB_EMPLOYMENT_TYPE, '' ) ) );
			echo '<p><strong>' . esc_html__( 'Employment type', 'publishrank' ) . '</strong><br />';
			foreach ( PostMeta::job_employment_types() as $job_key => $job_label ) {
				echo '<label style="margin-right:10px"><input type="checkbox" name="' . esc_attr( PostMeta::JOB_EMPLOYMENT_TYPE ) . '[]" value="' . esc_attr( strtolower( $job_key ) ) . '"' . checked( in_array( $job_key, $selected_types, true ), true, false ) . ' /> ' . esc_html( $job_label ) . '</label>'; }
			echo '</p>';
			$this->schema_text_field( $post->ID, PostMeta::JOB_STREET, __( 'Street address (optional)', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::JOB_LOCALITY, __( 'City', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::JOB_REGION, __( 'State / region (optional)', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::JOB_POSTAL_CODE, __( 'Postal code (optional)', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::JOB_COUNTRY, __( 'Country code (e.g. PK, US)', 'publishrank' ) );
			echo '<p><label><input type="checkbox" name="' . esc_attr( PostMeta::JOB_REMOTE ) . '" value="1"' . checked( PostMeta::get( $post->ID, PostMeta::JOB_REMOTE, '0' ), '1', false ) . ' /> ' . esc_html__( 'Fully remote job', 'publishrank' ) . '</label></p>';
			$this->schema_text_field( $post->ID, PostMeta::JOB_REMOTE_COUNTRY, __( 'Remote: country applicants must live in (code)', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::JOB_SALARY_MIN, __( 'Salary from (optional)', 'publishrank' ), '', 'number', '0.01' );
			$this->schema_text_field( $post->ID, PostMeta::JOB_SALARY_MAX, __( 'Salary to (optional)', 'publishrank' ), '', 'number', '0.01' );
			$this->schema_text_field( $post->ID, PostMeta::JOB_SALARY_CURRENCY, __( 'Salary currency (e.g. PKR, USD)', 'publishrank' ) );
			$unit = (string) PostMeta::get( $post->ID, PostMeta::JOB_SALARY_UNIT, '' );
			echo '<p><label><strong>' . esc_html__( 'Salary is per', 'publishrank' ) . '</strong><br /><select name="' . esc_attr( PostMeta::JOB_SALARY_UNIT ) . '"><option value="">—</option>';
			foreach ( array( 'HOUR' => __( 'Hour', 'publishrank' ), 'DAY' => __( 'Day', 'publishrank' ), 'WEEK' => __( 'Week', 'publishrank' ), 'MONTH' => __( 'Month', 'publishrank' ), 'YEAR' => __( 'Year', 'publishrank' ) ) as $u => $l ) {
				echo '<option value="' . esc_attr( $u ) . '"' . selected( $unit, $u, false ) . '>' . esc_html( $l ) . '</option>'; }
			echo '</select></label></p>';
			$this->schema_text_field( $post->ID, PostMeta::JOB_IDENTIFIER, __( 'Internal job reference (optional)', 'publishrank' ) );
			echo '<p><label><input type="checkbox" name="' . esc_attr( PostMeta::JOB_DIRECT_APPLY ) . '" value="1"' . checked( PostMeta::get( $post->ID, PostMeta::JOB_DIRECT_APPLY, '0' ), '1', false ) . ' /> ' . esc_html__( 'Applicants can apply directly on this page', 'publishrank' ) . '</label></p>';
			echo '</div><div class="pr-schema-panel" data-types="Review,ReviewNewsArticle" style="display:none"><hr /><h4>' . esc_html__( 'Review details', 'publishrank' ) . '</h4><p class="description">' . esc_html__( 'Use a genuine rating visible on the page. Google only shows review stars when the reviewed item has a specific type (Product, Book, Movie …), not a generic “Thing”.', 'publishrank' ) . '</p>';
			$current_item_type = (string) PostMeta::get( $post->ID, PostMeta::REVIEW_ITEM_TYPE, '' );
			echo '<p><label><strong>' . esc_html__( 'Type of item reviewed', 'publishrank' ) . '</strong><br /><select name="' . esc_attr( PostMeta::REVIEW_ITEM_TYPE ) . '"><option value="">' . esc_html__( '— Choose a type —', 'publishrank' ) . '</option>';
			foreach ( PostMeta::review_item_types() as $item_key => $item_label ) {
				echo '<option value="' . esc_attr( $item_key ) . '"' . selected( $current_item_type, $item_key, false ) . '>' . esc_html( $item_label ) . '</option>';
			}
			echo '</select></label></p>';
			$this->schema_text_field( $post->ID, PostMeta::REVIEW_ITEM, __( 'Name of the item reviewed', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::REVIEW_RATING, __( 'Rating (0–5)', 'publishrank' ), '', 'number', '0.1' );
			$this->schema_text_field( $post->ID, PostMeta::REVIEW_ITEM_URL, __( 'Official page of the item (optional)', 'publishrank' ), 'https://', 'url' );
			$this->schema_text_field( $post->ID, PostMeta::REVIEW_ITEM_IMAGE, __( 'Image of the item (optional)', 'publishrank' ), 'https://', 'url' );
			$this->schema_text_field( $post->ID, PostMeta::REVIEW_ITEM_BRAND, __( 'Brand or maker (products, apps; optional)', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::REVIEW_ITEM_CREATOR, __( 'Author / director / artist (books, films, music; optional)', 'publishrank' ) );
			$this->schema_textarea_field( $post->ID, PostMeta::REVIEW_PROS, __( 'Pros, one per line (optional)', 'publishrank' ) );
			$this->schema_textarea_field( $post->ID, PostMeta::REVIEW_CONS, __( 'Cons, one per line (optional)', 'publishrank' ) );
			echo '</div><div class="pr-schema-panel" data-types="Product" style="display:none"><hr /><h4>' . esc_html__( 'Product and offer details', 'publishrank' ) . '</h4><p class="description">' . esc_html__( 'Offer markup requires an accurate price and ISO 4217 currency. Keep all details accurate and visible to visitors.', 'publishrank' ) . '</p>';
			$this->schema_text_field( $post->ID, PostMeta::PRODUCT_SKU, __( 'SKU (optional)', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::PRODUCT_BRAND, __( 'Brand (optional)', 'publishrank' ) );
			$this->schema_text_field( $post->ID, PostMeta::PRODUCT_PRICE, __( 'Price', 'publishrank' ), '', 'number', '0.01' );
			$this->schema_text_field( $post->ID, PostMeta::PRODUCT_CURRENCY, __( 'Currency code', 'publishrank' ), 'USD' );
			echo '<p><label><strong>' . esc_html__( 'Availability', 'publishrank' ) . '</strong><br /><select name="' . esc_attr( PostMeta::PRODUCT_AVAILABILITY ) . '"><option value="">' . esc_html__( 'Not specified', 'publishrank' ) . '</option>';
			foreach ( array( 'InStock', 'OutOfStock', 'PreOrder', 'BackOrder', 'Discontinued' ) as $availability ) {
				$url = 'https://schema.org/' . $availability;
				echo '<option value="' . esc_attr( $url ) . '"' . selected( PostMeta::get( $post->ID, PostMeta::PRODUCT_AVAILABILITY ), $url, false ) . '>' . esc_html( $availability ) . '</option>';
			}
			echo '</select></label></p></div>';
	}

		/** Render one escaped schema text/number field. */
	private function schema_text_field( $post_id, $key, $label, $placeholder = '', $type = 'text', $step = '' ) {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br /><input class="widefat" type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( PostMeta::get( $post_id, $key ) ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . ( '' !== $step ? ' step="' . esc_attr( $step ) . '" min="0"' : '' ) . ' /></label></p>';
	}

		/** Render one escaped schema textarea. */
	private function schema_textarea_field( $post_id, $key, $label ) {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br /><textarea class="widefat" rows="5" name="' . esc_attr( $key ) . '">' . esc_textarea( (string) PostMeta::get( $post_id, $key ) ) . '</textarea></label></p>';
	}

	/** Frontend admin-bar "Validate Schema" for the currently viewed post/page/author. */
	public function admin_bar_validate( $bar ) {
		if ( is_admin() || ! is_object( $bar ) || ! ( is_singular() || is_author() ) ) {
			return; }
		if ( is_author() ) {
			if ( ! current_user_can( 'edit_user', get_queried_object_id() ) ) {
				return; }
			$url = get_author_posts_url( get_queried_object_id() );
		} else {
			if ( ! current_user_can( 'edit_post', get_queried_object_id() ) ) {
				return; }
			$url = get_permalink();
		}
		$bar->add_node(
			array(
				'id'     => 'publishrank-validate-schema',
				'parent' => 'publishrank-seo-toolbar',
				'title'  => esc_html__( 'Validate Schema (Google)', 'publishrank' ),
				'href'   => esc_url( self::google_test_url( $url ) ),
				'meta'   => array(
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				),
			)
		);
	}

	/** Quick "Schema Test" link on every row of the post/page list screens. */
	public function row_actions( $actions, $post ) {
		if ( 'publish' === get_post_status( $post ) ) {
			$actions['publishrank_schema_test'] = '<a href="' . esc_url( self::google_test_url( get_permalink( $post->ID ) ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Schema Test', 'publishrank' ) . '</a>';
		}
		return $actions;
	}

	/** Author enrichment fields (feed the mandatory author-page Person schema). */
	public function user_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return; }
		wp_nonce_field( 'publishrank_author_schema', 'publishrank_author_schema_nonce' );
		echo '<h2>' . esc_html__( 'PublishRank Author Schema', 'publishrank' ) . '</h2><table class="form-table">';
		echo '<tr><th><label for="pr_author_job">' . esc_html__( 'Job title', 'publishrank' ) . '</label></th><td><input class="regular-text" type="text" id="pr_author_job" name="pr_author_job_title" value="' . esc_attr( (string) get_user_meta( $user->ID, '_publishrank_author_job_title', true ) ) . '" /><p class="description">' . esc_html__( 'Shown as jobTitle in the author’s structured data.', 'publishrank' ) . '</p></td></tr>';
			echo '<tr><th><label for="pr_author_social">' . esc_html__( 'Profile URLs (sameAs)', 'publishrank' ) . '</label></th><td><textarea class="large-text" rows="4" id="pr_author_social" name="pr_author_social">' . esc_textarea( (string) get_user_meta( $user->ID, '_publishrank_author_social', true ) ) . '</textarea><p class="description">' . esc_html__( 'One URL per line: X/Twitter, LinkedIn, Mastodon, portfolio, etc. Google uses these to confirm author identity (E-E-A-T).', 'publishrank' ) . '</p></td></tr>';
			echo '<tr><th><label for="pr_author_facebook">' . esc_html__( 'Facebook profile URL', 'publishrank' ) . '</label></th><td><input class="regular-text" type="url" id="pr_author_facebook" name="pr_author_facebook" value="' . esc_attr( (string) get_user_meta( $user->ID, '_publishrank_author_facebook', true ) ) . '" /><p class="description">' . esc_html__( 'Optional author profile URL used for the existing article:author attribution. Facebook may ignore authorship metadata.', 'publishrank' ) . '</p></td></tr>';
		echo '<tr><th><label for="pr_author_expertise">' . esc_html__( 'Expertise topics', 'publishrank' ) . '</label></th><td><textarea class="large-text" rows="3" id="pr_author_expertise" name="pr_author_expertise">' . esc_textarea( (string) get_user_meta( $user->ID, '_publishrank_author_expertise', true ) ) . '</textarea><p class="description">' . esc_html__( 'Add accurate coverage areas, one per line or separated by commas (for example: climate policy, public health, local government). These are emitted as knowsAbout in Person structured data.', 'publishrank' ) . '</p></td></tr>';
		echo '</table>';
	}

	public function save_user_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return; }
		if ( ! isset( $_POST['publishrank_author_schema_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_author_schema_nonce'] ) ), 'publishrank_author_schema' ) ) {
			return; }
		update_user_meta( $user_id, '_publishrank_author_job_title', PostMeta::truncate( isset( $_POST['pr_author_job_title'] ) ? sanitize_text_field( wp_unslash( $_POST['pr_author_job_title'] ) ) : '', 120 ) );
		$social = isset( $_POST['pr_author_social'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pr_author_social'] ) ) : '';
		$social = implode( "\n", SchemaBuilder::author_social_urls_from_text( $social ) );
			update_user_meta( $user_id, '_publishrank_author_social', $social );
			$facebook = esc_url_raw( wp_unslash( $_POST['pr_author_facebook'] ?? '' ) );
			$parts    = wp_parse_url( $facebook );
		if ( empty( $parts['host'] ) || false === strpos( strtolower( (string) $parts['host'] ), 'facebook.com' ) ) {
			$facebook = ''; }
			update_user_meta( $user_id, '_publishrank_author_facebook', $facebook );
		$expertise = isset( $_POST['pr_author_expertise'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pr_author_expertise'] ) ) : '';
		$expertise = implode( "\n", SchemaBuilder::author_expertise_from_text( $expertise ) );
		update_user_meta( $user_id, '_publishrank_author_expertise', $expertise );
	}
}
