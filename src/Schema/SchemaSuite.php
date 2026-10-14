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
			// Share the nonce used by PostMeta::save so the schema selector works in
			// Gutenberg, Classic Editor, and page editors without a second save path.
			wp_nonce_field( 'publishrank_seo_meta', 'publishrank_seo_meta_nonce' );
			$selected  = PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' );
		$permalink = get_permalink( $post );
		echo '<div class="publishrank-schema-suite">';
		$schema_label = ( 'page' === $post->post_type ) ? esc_html__( 'Schema type for this page', 'publishrank' ) : esc_html__( 'Schema type for this post', 'publishrank' );
		echo '<p><label for="' . esc_attr( PostMeta::SCHEMA_TYPE ) . '"><strong>' . $schema_label . '</strong></label><br />';
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
		echo '<p class="description">' . esc_html__( 'Automatic uses NewsArticle for news posts. Choose only Article, News Article, Live Blog Post, Video, or Image Gallery when this document needs an explicit type; publisher, author, WebPage, and breadcrumb nodes remain foundational and connected.', 'publishrank' ) . '</p>';

		// News-publisher detail panels.
		\PublishRankSEO\Schema\LiveBlogSchema::render_panel( $post );

		echo '<div class="pr-schema-panel" data-types="VideoObject" style="display:none"><hr /><h4>' . esc_html__( 'Video details', 'publishrank' ) . '</h4>';
		foreach ( array( PostMeta::VIDEO_URL => __( 'Video file URL (contentUrl)', 'publishrank' ), PostMeta::VIDEO_EMBED_URL => __( 'Embed URL (embedUrl)', 'publishrank' ), PostMeta::VIDEO_THUMBNAIL => __( 'Thumbnail URL', 'publishrank' ) ) as $key => $label ) {
			echo '<p><label><strong>' . esc_html( $label ) . '</strong><br /><input class="widefat" type="url" name="' . esc_attr( $key ) . '" value="' . esc_attr( PostMeta::get( $post->ID, $key ) ) . '" /></label></p>';
		}
		echo '<p><label><strong>' . esc_html__( 'Duration (ISO 8601, e.g. PT4M30S)', 'publishrank' ) . '</strong><br /><input class="widefat" type="text" name="' . esc_attr( PostMeta::VIDEO_DURATION ) . '" value="' . esc_attr( PostMeta::get( $post->ID, PostMeta::VIDEO_DURATION ) ) . '" /></label></p></div>';


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
