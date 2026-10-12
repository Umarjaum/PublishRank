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
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) { return; }
		wp_enqueue_script( 'publishrank-schema-suite', plugins_url( 'assets/js/schema-suite.js', PUBLISHRANK_SEO_FILE ), array(), PUBLISHRANK_SEO_VERSION, true );
	}
	/** Google Rich Results Test URL for any permalink. */
	public static function google_test_url( $url ) {
		return 'https://search.google.com/test/rich-results?url=' . rawurlencode( (string) $url );
	}

	/** Dedicated schema meta box on every public post type. */
	public function add_meta_box( $post_type = '', $post = null ) {
		if ( $post_type && in_array( $post_type, PostMeta::post_types(), true ) ) {
			add_meta_box( 'publishrank-schema', __( 'Schema (Structured Data)', 'publishrank' ), array( $this, 'render_meta_box' ), $post_type, 'normal', 'high' );
		}
	}

	/** @param \WP_Post $post */
	public function render_meta_box( $post ) {
		$selected  = PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' );
		$permalink = get_permalink( $post );
		echo '<div class="publishrank-schema-suite">';
		echo '<p><label for="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '"><strong>' . esc_html__( 'Schema type for this ' . ( 'page' === $post->post_type ? 'page' : 'post' ), 'publishrank' ) . '</strong></label><br />';
		echo '<select class="widefat" id="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '" name="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '">';
		echo '<option value=""' . selected( $selected, '', false ) . '>' . esc_html__( '— Automatic (recommended default) —', 'publishrank' ) . '</option>';
		foreach ( SchemaTypes::grouped() as $group => $types ) {
			echo '<optgroup label="' . esc_attr( $group ) . '">';
			foreach ( $types as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Automatic follows the smart defaults on the Schema settings screen (posts → NewsArticle, your About page → AboutPage, author pages → ProfilePage, and so on). Pick a type here only to override this specific page.', 'publishrank' ) . '</p>';

		// Conditional detail panels (toggled by the inline script below).
		echo '<div class="pr-schema-panel" data-types="FAQPage" style="display:none"><hr /><h4>' . esc_html__( 'FAQ questions & answers', 'publishrank' ) . '</h4><p class="description">' . esc_html__( 'Add up to 20 questions. If left empty, PublishRank auto-detects <details>/<summary> blocks in the content.', 'publishrank' ) . '</p>';
		$faq_items = get_post_meta( $post->ID, PostMeta::FAQ_ITEMS, true );
		if ( empty( $faq_items ) ) { $faq_items = array( array( 'q' => '', 'a' => '' ) ); }
		foreach ( $faq_items as $i => $item ) {
			echo '<p><input class="widefat" type="text" name="publishrank_faq_items[' . (int) $i . '][q]" value="' . esc_attr( isset( $item['q'] ) ? $item['q'] : '' ) . '" placeholder="' . esc_attr__( 'Question', 'publishrank' ) . '" />';
			echo '<textarea class="widefat" rows="2" name="publishrank_faq_items[' . (int) $i . '][a]" placeholder="' . esc_attr__( 'Answer', 'publishrank' ) . '">' . esc_textarea( isset( $item['a'] ) ? $item['a'] : '' ) . '</textarea></p>';
		}
		echo '</div>';

		echo '<div class="pr-schema-panel" data-types="VideoObject" style="display:none"><hr /><h4>' . esc_html__( 'Video details', 'publishrank' ) . '</h4>';
		foreach ( array( PostMeta::VIDEO_URL => __( 'Video file URL (contentUrl)', 'publishrank' ), PostMeta::VIDEO_EMBED_URL => __( 'Embed URL (embedUrl)', 'publishrank' ), PostMeta::VIDEO_THUMBNAIL => __( 'Thumbnail URL', 'publishrank' ) ) as $key => $label ) {
			echo '<p><label><strong>' . esc_html( $label ) . '</strong><br /><input class="widefat" type="url" name="' . esc_attr( $key ) . '" value="' . esc_attr( PostMeta::get( $post->ID, $key ) ) . '" /></label></p>';
		}
		echo '<p><label><strong>' . esc_html__( 'Duration (ISO 8601, e.g. PT4M30S)', 'publishrank' ) . '</strong><br /><input class="widefat" type="text" name="' . esc_attr( PostMeta::VIDEO_DURATION ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::VIDEO_DURATION ) ) . '" /></label></p></div>';

		echo '<div class="pr-schema-panel" data-types="AudioObject" style="display:none"><hr /><h4>' . esc_html__( 'Audio details', 'publishrank' ) . '</h4>';
		echo '<p><label><strong>' . esc_html__( 'Audio file URL', 'publishrank' ) . '</strong><br /><input class="widefat" type="url" name="' . esc_attr( PostMeta::AUDIO_URL ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::AUDIO_URL ) ) . '" /></label></p>';
		echo '<p><label><strong>' . esc_html__( 'Duration (ISO 8601)', 'publishrank' ) . '</strong><br /><input class="widefat" type="text" name="' . esc_attr( PostMeta::AUDIO_DURATION ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::AUDIO_DURATION ) ) . '" /></label></p></div>';

		echo '<div class="pr-schema-panel" data-types="Event" style="display:none"><hr /><h4>' . esc_html__( 'Event details', 'publishrank' ) . '</h4>';
		echo '<p><label><strong>' . esc_html__( 'Start date/time', 'publishrank' ) . '</strong><br /><input class="widefat" type="text" name="' . esc_attr( PostMeta::EVENT_START ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::EVENT_START ) ) . '" placeholder="2026-11-01 18:00" /></label></p>';
		echo '<p><label><strong>' . esc_html__( 'Venue / location', 'publishrank' ) . '</strong><br /><input class="widefat" type="text" name="' . esc_attr( PostMeta::EVENT_LOCATION ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::EVENT_LOCATION ) ) . '" /></label></p></div>';

		// Verify with Google — the button you asked for, on every post/page.
		echo '<hr /><p><a class="button button-primary" href="' . esc_url( self::google_test_url( $permalink ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Verify Schema with Google', 'publishrank' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( 'https://validator.schema.org/validate?url=' . rawurlencode( $permalink ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Schema.org Validator', 'publishrank' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Opens Google’s Rich Results Test preloaded with this page’s live URL. Save/publish first so Google sees the latest markup.', 'publishrank' ) . '</p>';
		echo '</div>';


	}

	/** Frontend admin-bar "Validate Schema" for the currently viewed post/page/author. */
	public function admin_bar_validate( $bar ) {
		if ( is_admin() || ! is_object( $bar ) || ! ( is_singular() || is_author() ) ) { return; }
		if ( is_author() ) {
			if ( ! current_user_can( 'edit_user', get_queried_object_id() ) ) { return; }
			$url = get_author_posts_url( get_queried_object_id() );
		} else {
			if ( ! current_user_can( 'edit_post', get_queried_object_id() ) ) { return; }
			$url = get_permalink();
		}
		$bar->add_node( array(
			'id'     => 'publishrank-validate-schema',
			'parent' => 'publishrank-seo-toolbar',
			'title'  => esc_html__( 'Validate Schema (Google)', 'publishrank' ),
			'href'   => esc_url( self::google_test_url( $url ) ),
			'meta'   => array( 'target' => '_blank', 'rel' => 'noopener noreferrer' ),
		) );
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
		if ( ! current_user_can( 'edit_user', $user->ID ) ) { return; }
		wp_nonce_field( 'publishrank_author_schema', 'publishrank_author_schema_nonce' );
		echo '<h2>' . esc_html__( 'PublishRank Author Schema', 'publishrank' ) . '</h2><table class="form-table">';
		echo '<tr><th><label for="pr_author_job">' . esc_html__( 'Job title', 'publishrank' ) . '</label></th><td><input class="regular-text" type="text" id="pr_author_job" name="pr_author_job_title" value="' . esc_attr( (string) get_user_meta( $user->ID, '_publishrank_author_job_title', true ) ) . '" /><p class="description">' . esc_html__( 'Shown as jobTitle in the author’s structured data.', 'publishrank' ) . '</p></td></tr>';
		echo '<tr><th><label for="pr_author_social">' . esc_html__( 'Profile URLs (sameAs)', 'publishrank' ) . '</label></th><td><textarea class="large-text" rows="4" id="pr_author_social" name="pr_author_social">' . esc_textarea( (string) get_user_meta( $user->ID, '_publishrank_author_social', true ) ) . '</textarea><p class="description">' . esc_html__( 'One URL per line: X/Twitter, LinkedIn, Mastodon, portfolio, etc. Google uses these to confirm author identity (E-E-A-T).', 'publishrank' ) . '</p></td></tr>';
		echo '</table>';
	}

	public function save_user_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) { return; }
		if ( ! isset( $_POST['publishrank_author_schema_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_author_schema_nonce'] ) ), 'publishrank_author_schema' ) ) { return; }
		update_user_meta( $user_id, '_publishrank_author_job_title', PostMeta::sanitize_text( isset( $_POST['pr_author_job_title'] ) ? $_POST['pr_author_job_title'] : '', 120 ) );
		$social = isset( $_POST['pr_author_social'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pr_author_social'] ) ) : '';
		$social = implode( "\n", SchemaBuilder::author_social_urls_from_text( $social ) );
		update_user_meta( $user_id, '_publishrank_author_social', $social );
	}
}