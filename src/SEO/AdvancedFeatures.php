<?php
/** Premium-style technical SEO features implemented without third-party dependencies. */
namespace PublishRankSEO\SEO;

use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Meta\SeoData;

defined( 'ABSPATH' ) || exit;

final class AdvancedFeatures {
	/** Register supported frontend and editor hooks. */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'init', array( PostMeta::class, 'register_meta' ), 20 );
		add_action( 'save_post', array( PostMeta::class, 'save' ), 10 );
		add_action( 'wp_ajax_publishrank_resize_discover_image', array( $this, 'ajax_resize_discover_image' ) );
		add_action( 'wp_head', array( $this, 'render_pagination_links' ), 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_noindex_posts' ), 10, 2 );
	}

		/** Add editorial controls to posts without changing theme presentation. */
	public function add_meta_box( $post_type = '', $post = null ) {
		if ( $post_type && in_array( $post_type, PostMeta::post_types(), true ) ) {
				\add_meta_box( 'publishrank-seo-overrides', __( 'SEO Overrides', 'publishrank' ), array( $this, 'render_meta_box' ), $post_type, 'normal', 'high' );
		}
	}

	/** Article/news-only controls apply to editorial post types, not pages. */
	private function is_article_type( $post_type ) {
		$types = array();
		foreach ( preg_split( '/[\s,]+/', (string) get_option( 'publishrank_seo_news_post_types', 'post' ), -1, PREG_SPLIT_NO_EMPTY ) as $type ) {
			$types[] = sanitize_key( $type ); }
		$object = get_post_type_object( (string) $post_type );
		return $object && $object->public && $object->publicly_queryable && ! in_array( $post_type, array( 'page', 'attachment' ), true ) && in_array( $post_type, $types, true );
	}

	/** Read-only summary of what visitors and search engines will get, and where each value came from. */
	private function render_resolved_summary( $post ) {
		$seo = SeoData::for_post( $post );
		if ( empty( $seo ) ) {
			return; }
		$labels = array(
			'title'       => __( 'Title', 'publishrank' ),
			'description' => __( 'Description', 'publishrank' ),
			'canonical'   => __( 'Canonical URL', 'publishrank' ),
			'robots'      => __( 'Robots', 'publishrank' ),
			'image'       => __( 'Social image', 'publishrank' ),
			'image_alt'   => __( 'Image alt text', 'publishrank' ),
		);
		$image  = isset( $seo['image'] ) && is_array( $seo['image'] ) ? $seo['image'] : array();
		$values = array(
			'title'       => $seo['title'],
			'description' => $seo['description'],
			'canonical'   => $seo['canonical'],
			'robots'      => $seo['robots'],
			'image'       => isset( $image['url'] ) ? $image['url'] : '',
			'image_alt'   => isset( $image['alt'] ) ? $image['alt'] : '',
		);
		$names  = array(
			'override'   => __( 'Your override', 'publishrank' ),
			'auto'       => __( 'Automatic', 'publishrank' ),
			'featured'   => __( 'Featured image', 'publishrank' ),
			'content'    => __( 'First image in content', 'publishrank' ),
			'attachment' => __( 'Attachment', 'publishrank' ),
			'none'       => __( 'Not available', 'publishrank' ),
		);
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'What will be published', 'publishrank' ) . '</strong> — ' . esc_html__( 'fetched automatically from this post. Fill in a field below only to override it; leave it empty to stay automatic.', 'publishrank' ) . '</p>';
		echo '<table class="widefat striped" style="margin-bottom:10px"><caption class="screen-reader-text">' . esc_html__( 'SEO values that will be published and their source', 'publishrank' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Field', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Published value', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Value source', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $labels as $key => $label ) {
			$source = isset( $seo['sources'][ $key ] ) ? $seo['sources'][ $key ] : 'auto';
			echo '<tr><th scope="row" style="width:140px">' . esc_html( $label ) . '</th><td>' . ( '' !== (string) $values[ $key ] ? esc_html( (string) $values[ $key ] ) : '<em>' . esc_html__( 'empty', 'publishrank' ) . '</em>' ) . '</td><td style="width:170px"><span class="description">' . esc_html( isset( $names[ $source ] ) ? $names[ $source ] : $source ) . '</span></td></tr>';
		}
		echo '</tbody></table>';
		if ( isset( $image['alt_source'] ) && 'none' === $image['alt_source'] && ! empty( $image['url'] ) ) {
			echo '<p class="description">' . esc_html__( 'This image has no alt text. Add it in the Media Library or in the social image alt override below.', 'publishrank' ) . '</p>';
		}
		echo '</div>';
	}

	/** @param \WP_Post $post Render fields. */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'publishrank_seo_meta', 'publishrank_seo_meta_nonce' );
		$primary_category = absint( PostMeta::get( $post->ID, PostMeta::PRIMARY_CATEGORY, 0 ) );
		$seo              = SeoData::for_post( $post );
		$seo_image        = isset( $seo['image'] ) && is_array( $seo['image'] ) ? $seo['image'] : array();
		$placeholders     = array(
		PostMeta::TITLE          => '%%title%% %%sep%% %%sitename%%',
		PostMeta::DESCRIPTION    => '%%excerpt%%',
		PostMeta::CANONICAL      => isset( $seo['canonical'] ) ? $seo['canonical'] : '',
		PostMeta::OG_TITLE       => isset( $seo['title'] ) ? $seo['title'] : '',
		PostMeta::OG_DESCRIPTION => isset( $seo['description'] ) ? $seo['description'] : '',
		PostMeta::OG_IMAGE       => isset( $seo_image['url'] ) ? $seo_image['url'] : '',
		PostMeta::OG_IMAGE_ALT   => isset( $seo_image['alt'] ) ? $seo_image['alt'] : '',
		);
		$is_article = $this->is_article_type( $post->post_type );
		$this->render_resolved_summary( $post );
		echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Automatic SEO is active.', 'publishrank' ) . '</strong> ' . esc_html__( 'PublishRank fills in search and social details from the article by default. Use the sections below only when you need an override; blank fields stay automatic.', 'publishrank' ) . '</p></div>';
		echo '<p class="pr-seo-editor-intro">' . esc_html__( 'Work from top to bottom: search appearance, social sharing, article details, then indexing. These settings affect search and sharing output, not the way your theme displays the article.', 'publishrank' ) . '</p>';

		echo '<section class="pr-seo-group" aria-labelledby="pr-seo-search-title"><h3 id="pr-seo-search-title">' . esc_html__( '1. Search appearance', 'publishrank' ) . '</h3><p class="description">' . esc_html__( 'Use dynamic variables instead of hard-coded text when you want reusable templates. Pick a variable from the “Insert variable” list under each field, or type it. Leave a field blank to use its automatic value.', 'publishrank' ) . '</p>';
		$this->render_seo_field( $post->ID, PostMeta::TITLE, __( 'SEO title', 'publishrank' ), __( 'The title shown in search results. Use variables such as %%title%% %%sep%% %%sitename%% rather than hard-coding article-specific text. Leave blank for the global template.', 'publishrank' ), 'text', 255, $placeholders );
		$this->render_seo_field( $post->ID, PostMeta::DESCRIPTION, __( 'Meta description', 'publishrank' ), __( 'The short summary shown beneath the search title. Use the “Insert variable” list to add dynamic values. Leave blank for an automatic summary.', 'publishrank' ), 'textarea', 320, $placeholders );
		$this->render_seo_field( $post->ID, PostMeta::CANONICAL, __( 'Canonical URL', 'publishrank' ), __( 'Usually leave blank. Add a URL only when this article is a copy or syndicated version and another page should be treated as the original.', 'publishrank' ), 'url', 0, $placeholders );
		echo '</section>';
			echo '<section class="pr-seo-group" aria-labelledby="pr-seo-context-title"><h3 id="pr-seo-context-title">' . esc_html__( '2. Article context', 'publishrank' ) . '</h3><p class="description">' . esc_html__( 'Add article context used to enrich structured data and editorial organization.', 'publishrank' ) . '</p>';
		$this->render_seo_field( $post->ID, PostMeta::ENTITY_TOPIC, __( 'Main entity or topic', 'publishrank' ), __( 'The person, organization, place, or subject this article is mainly about. Used to enrich structured data.', 'publishrank' ), 'text', 120 );
		echo '<div class="pr-seo-field"><label for="' . esc_attr( PostMeta::PRIMARY_CATEGORY ) . '"><strong>' . esc_html__( 'Primary category', 'publishrank' ) . '</strong></label><select class="widefat" id="' . esc_attr( PostMeta::PRIMARY_CATEGORY ) . '" name="' . esc_attr( PostMeta::PRIMARY_CATEGORY ) . '" aria-describedby="pr-seo-primary-category-help"><option value="0">' . esc_html__( 'Use the post category', 'publishrank' ) . '</option>';
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $category ) {
			echo '<option value="' . esc_attr( $category->term_id ) . '" ' . selected( $primary_category, $category->term_id, false ) . '>' . esc_html( $category->name ) . '</option>';
		}
		echo '</select><p class="description" id="pr-seo-primary-category-help">' . esc_html__( 'Choose a main category for this article’s structured data, or keep the automatic post category.', 'publishrank' ) . '</p></div>';
		$cornerstone_help = 'pr-seo-cornerstone-help';
		echo '<div class="pr-seo-field"><label><input type="checkbox" name="' . esc_attr( PostMeta::CORNERSTONE ) . '" value="1" aria-describedby="' . esc_attr( $cornerstone_help ) . '" ' . checked( PostMeta::get( $post->ID, PostMeta::CORNERSTONE, '0' ), '1', false ) . ' /> <strong>' . esc_html__( 'Mark as a cornerstone article', 'publishrank' ) . '</strong></label><p class="description" id="' . esc_attr( $cornerstone_help ) . '">' . esc_html__( 'Flag this as an important, long-term reference article on your site.', 'publishrank' ) . '</p></div></section>';

		echo '<section class="pr-seo-group" aria-labelledby="pr-seo-social-title"><h3 id="pr-seo-social-title">' . esc_html__( '3. Social sharing', 'publishrank' ) . '</h3><p class="description">' . esc_html__( 'Optional overrides for the preview card when this article is shared on social networks.', 'publishrank' ) . '</p>';
		$this->render_seo_field( $post->ID, PostMeta::OG_TITLE, __( 'Social title', 'publishrank' ), __( 'The headline used on Facebook, LinkedIn, X and other previews. Dynamic variables are supported. Leave blank to use the SEO title.', 'publishrank' ), 'text', 255, $placeholders );
		$this->render_seo_field( $post->ID, PostMeta::OG_DESCRIPTION, __( 'Social description', 'publishrank' ), __( 'The summary used on social preview cards. Dynamic variables are supported. Leave blank to use the meta description.', 'publishrank' ), 'textarea', 320, $placeholders );
		$this->render_seo_field( $post->ID, PostMeta::OG_IMAGE, __( 'Social image URL', 'publishrank' ), __( 'An image URL for social cards. Leave blank to use the featured image or site default.', 'publishrank' ), 'url', 0, $placeholders );
		$this->render_discover_image_tools( $post, isset( $seo_image['url'] ) ? $seo_image['url'] : '' );
		$this->render_seo_field( $post->ID, PostMeta::OG_IMAGE_ALT, __( 'Social image alt text', 'publishrank' ), __( 'A concise description of the social image for people using assistive technology.', 'publishrank' ), 'text', 255, $placeholders );
		$this->render_seo_field( $post->ID, PostMeta::TWITTER_CREATOR, __( 'X creator handle', 'publishrank' ), __( 'The author’s X account handle, if applicable. Enter the handle only.', 'publishrank' ), 'text', 15 );
		$card = PostMeta::get( $post->ID, PostMeta::TWITTER_CARD, 'summary_large_image' );
		echo '<div class="pr-seo-field"><label for="' . esc_attr( PostMeta::TWITTER_CARD ) . '"><strong>' . esc_html__( 'X preview layout', 'publishrank' ) . '</strong></label><select class="widefat" id="' . esc_attr( PostMeta::TWITTER_CARD ) . '" name="' . esc_attr( PostMeta::TWITTER_CARD ) . '">';
		foreach ( array( 'summary_large_image' => __( 'Large image and summary', 'publishrank' ), 'summary' => __( 'Summary only', 'publishrank' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $card, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Choose how the X preview card should display this article.', 'publishrank' ) . '</p></div></section>';

		echo '<section class="pr-seo-group" aria-labelledby="pr-seo-article-title"><h3 id="pr-seo-article-title">' . esc_html__( '4. Article and author details', 'publishrank' ) . '</h3><p class="description">' . esc_html__( 'Optional details used by supported article and media structured data. Leave fields blank when they do not apply.', 'publishrank' ) . '</p>';
		$this->render_seo_field( $post->ID, PostMeta::CONTENT_TIER, __( 'Content access tier', 'publishrank' ), __( 'A short description of the access level, such as free or members-only.', 'publishrank' ), 'text', 80 );
		$this->render_seo_field( $post->ID, PostMeta::AUTHOR_NAME, __( 'Schema author name', 'publishrank' ), __( 'Use this only when the article’s schema author differs from its WordPress author.', 'publishrank' ), 'text', 255 );
		$this->render_seo_field( $post->ID, PostMeta::AUTHOR_JOB_TITLE, __( 'Author role or job title', 'publishrank' ), __( 'Optional role to include with the schema author details.', 'publishrank' ), 'text', 120 );
		$this->render_seo_field( $post->ID, PostMeta::AUTHOR_IMAGE, __( 'Schema author image URL', 'publishrank' ), __( 'Optional public image URL for the schema author.', 'publishrank' ), 'url' );
		$this->render_seo_field( $post->ID, PostMeta::VIDEO_THUMBNAIL, __( 'Video thumbnail URL', 'publishrank' ), __( 'Thumbnail image URL for this article’s video, when applicable.', 'publishrank' ), 'url' );
		$this->render_seo_field( $post->ID, PostMeta::VIDEO_DURATION, __( 'Video duration', 'publishrank' ), __( 'Enter an ISO 8601 duration, for example PT4M30S for four minutes and thirty seconds.', 'publishrank' ), 'text', 32 );
		$this->render_seo_field( $post->ID, PostMeta::CITATIONS, __( 'Citations or source references', 'publishrank' ), __( 'Optional references that support the article. Add one citation per line.', 'publishrank' ), 'textarea', 2000 );
		echo '</section>';

		echo '<section class="pr-seo-group" aria-labelledby="pr-seo-indexing-title"><h3 id="pr-seo-indexing-title">' . esc_html__( '5. Indexing and distribution', 'publishrank' ) . '</h3><p class="description">' . esc_html__( 'Change these only when you want to alter whether or how search engines discover this article.', 'publishrank' ) . '</p>';
		$this->render_seo_field( $post->ID, PostMeta::ROBOTS, __( 'Search visibility directives', 'publishrank' ), __( 'Leave blank for the site default. Use supported directives such as noindex,follow. Noindex keeps a URL out of search results; it is not a robots.txt crawl block.', 'publishrank' ), 'text', 0, array(), get_option( 'publishrank_seo_default_robots', 'index,follow' ) );
		if ( $is_article ) {
			$news_eligible = '-1' !== (string) get_post_meta( $post->ID, PostMeta::NEWS_ELIGIBLE, true );
			$news_help = 'pr-seo-news-eligible-help';
			echo '<div class="pr-seo-field"><label><input type="checkbox" name="' . esc_attr( PostMeta::NEWS_ELIGIBLE ) . '" value="1" aria-describedby="' . esc_attr( $news_help ) . '" ' . checked( $news_eligible, true, false ) . ' /> <strong>' . esc_html__( 'Include in Google News sitemap', 'publishrank' ) . '</strong></label><p class="description" id="' . esc_attr( $news_help ) . '">' . esc_html__( 'Eligible public, indexable articles are included automatically within the configured freshness window. Clear this only to exclude this article.', 'publishrank' ) . '</p></div>';
			$free_help = 'pr-seo-accessible-free-help';
			echo '<input type="hidden" name="publishrank_seo_article_fields" value="1" /><div class="pr-seo-field"><label><input type="checkbox" name="' . esc_attr( PostMeta::ACCESSIBLE_FOR_FREE ) . '" value="1" aria-describedby="' . esc_attr( $free_help ) . '" ' . checked( PostMeta::get( $post->ID, PostMeta::ACCESSIBLE_FOR_FREE, '1' ), '1', false ) . ' /> <strong>' . esc_html__( 'Article is accessible for free', 'publishrank' ) . '</strong></label><p class="description" id="' . esc_attr( $free_help ) . '">' . esc_html__( 'Uncheck this for content that requires a subscription or payment.', 'publishrank' ) . '</p></div>';
		}
		echo '</section></div>';
	}

	/** Show the effective image dimensions and allow editors to create a separate resized media copy. */
	private function render_discover_image_tools( $post, $effective_url = '' ) {
		$value  = (string) PostMeta::get( $post->ID, PostMeta::OG_IMAGE, '' );
		$url    = '' !== $value ? $value : (string) $effective_url;
		$image_id = $url && function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
		$meta   = $image_id ? wp_get_attachment_metadata( $image_id ) : array();
		$width  = is_array( $meta ) && isset( $meta['width'] ) ? absint( $meta['width'] ) : 0;
		$height = is_array( $meta ) && isset( $meta['height'] ) ? absint( $meta['height'] ) : 0;
		$initial = $width && $height ? $this->discover_dimensions_message( $width, $height ) : ( $url ? __( 'This image is not linked to a WordPress Media Library item. You can check its dimensions, but create a resized copy by choosing the image from the Media Library.', 'publishrank' ) : __( 'Choose an image to inspect its pixel dimensions.', 'publishrank' ) );
		$guidance_id = 'pr-discover-image-guidance';
		echo '<div class="pr-discover-image-tool" data-attachment-id="' . esc_attr( (string) $image_id ) . '" data-width="' . esc_attr( (string) $width ) . '" data-height="' . esc_attr( (string) $height ) . '" data-image-url="' . esc_url( $url ) . '">';
		echo '<h4>' . esc_html__( 'Google Discover image', 'publishrank' ) . '</h4>';
		echo '<p class="description" id="' . esc_attr( $guidance_id ) . '">' . esc_html__( 'Google recommends at least 1200 px wide and more than 300,000 total pixels. A 16:9 landscape image is recommended, not required. Large image previews also require max-image-preview:large; meeting the size guidance does not guarantee Discover placement. Google may crop automatically, so keep important details visible.', 'publishrank' ) . ' <a href="https://developers.google.com/search/docs/appearance/google-discover" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read Google’s image guidance', 'publishrank' ) . '</a></p>';
		echo '<p class="pr-discover-image-status" role="status" aria-live="polite" aria-atomic="true">' . esc_html( $initial ) . '</p>';
		if ( $url ) {
			echo '<img class="pr-discover-image-preview" src="' . esc_url( $url ) . '" alt="" loading="lazy" />';
		}
		echo '<div class="pr-discover-image-actions"><button type="button" class="button rhs-media-button" data-target="' . esc_attr( PostMeta::OG_IMAGE ) . '">' . esc_html__( 'Choose from Media Library', 'publishrank' ) . '</button> <button type="button" class="button pr-discover-check">' . esc_html__( 'Check image dimensions', 'publishrank' ) . '</button></div>';
		echo '<p class="description">' . esc_html__( 'Create a new copy; the original stays unchanged. WordPress can reduce an image, but it will not upscale a smaller source. To meet Google’s width guidance, choose an original at least 1200 px wide.', 'publishrank' ) . '</p>';
		$dimensions_help_id = 'pr-discover-image-size-help';
		$crop_help_id       = 'pr-discover-image-crop-help';
		$described_by       = esc_attr( $guidance_id . ' ' . $dimensions_help_id );
		echo '<div class="pr-discover-image-size"><div><label for="pr-discover-image-width"><strong>' . esc_html__( 'Target width (px)', 'publishrank' ) . '</strong></label><input type="number" id="pr-discover-image-width" class="small-text" min="1" max="6000" step="1" value="1200" aria-describedby="' . $described_by . '" /></div><div><label for="pr-discover-image-height"><strong>' . esc_html__( 'Target height (px, optional)', 'publishrank' ) . '</strong></label><input type="number" id="pr-discover-image-height" class="small-text" min="1" max="6000" step="1" placeholder="' . esc_attr__( 'Preserve aspect ratio', 'publishrank' ) . '" aria-describedby="' . $described_by . '" /></div></div>';
		echo '<p class="description" id="' . esc_attr( $dimensions_help_id ) . '">' . esc_html__( 'Leave height blank to size by width. With both dimensions set, the image fits within that box unless you select an exact crop.', 'publishrank' ) . '</p>';
		echo '<p><label><input type="checkbox" class="pr-discover-image-crop" aria-describedby="' . esc_attr( $crop_help_id ) . '" /> ' . esc_html__( 'Crop to the exact width and height', 'publishrank' ) . '</label> <span class="description" id="' . esc_attr( $crop_help_id ) . '">' . esc_html__( 'WordPress crops from the center. Inspect the new copy before use.', 'publishrank' ) . '</span></p>';
		echo '<button type="button" class="button button-secondary pr-discover-resize"' . ( $image_id ? '' : ' disabled="disabled"' ) . '>' . esc_html__( 'Create resized copy', 'publishrank' ) . '</button></div>';
	}

	/** Build a localized, factual dimension message without implying Discover placement is guaranteed. */
	private function discover_dimensions_message( $width, $height ) {
		$width  = absint( $width );
		$height = absint( $height );
		if ( ! $width || ! $height ) {
			return __( 'Image dimensions are unavailable.', 'publishrank' );
		}
		$size = sprintf( __( 'Current dimensions: %1$s × %2$s px (%3$s pixels).', 'publishrank' ), number_format_i18n( $width ), number_format_i18n( $height ), number_format_i18n( $width * $height ) );
		$meets = $width >= 1200 && ( $width * $height ) > 300000;
		$ratio = abs( ( $width / $height ) - ( 16 / 9 ) ) < 0.025;
		if ( $meets ) {
			return $size . ' ' . ( $ratio ? __( 'Meets Google’s image-size guidance and is close to 16:9.', 'publishrank' ) : __( 'Meets Google’s image-size guidance. Google recommends 16:9, but does not require it.', 'publishrank' ) );
		}
		return $size . ' ' . __( 'Below Google’s suggested size for large Discover images: at least 1200 px wide and more than 300,000 total pixels.', 'publishrank' );
	}

	/** Create a non-destructive derivative for a selected local media item. */
	public function ajax_resize_discover_image() {
		check_ajax_referer( 'publishrank_resize_discover_image', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$source_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $post_id || ! $source_id || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'upload_files' ) || ! wp_attachment_is_image( $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create a resized image copy.', 'publishrank' ) ), 403 );
		}
		$width_value = isset( $_POST['width'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['width'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$width = ctype_digit( $width_value ) ? (int) $width_value : 0;
		$height_value = isset( $_POST['height'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['height'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$height = '' === $height_value ? null : ( ctype_digit( $height_value ) ? (int) $height_value : 0 );
		$crop = isset( $_POST['crop'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['crop'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $width < 1 || $width > 6000 || ( null !== $height && ( $height < 1 || $height > 6000 ) ) || ( null !== $height && ( $width * $height ) > 40000000 ) || ( $crop && null === $height ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a width from 1 to 6000 px. For an exact crop, also enter a height; output is limited to 40 megapixels.', 'publishrank' ) ), 400 );
		}
		$source = get_attached_file( $source_id );
		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) ? realpath( $upload['basedir'] ) : false;
		$file   = $source ? realpath( $source ) : false;
		if ( ! $base || ! $file || 0 !== strpos( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $base ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected image file is unavailable in the WordPress uploads folder.', 'publishrank' ) ), 400 );
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			wp_send_json_error( array( 'message' => __( 'WordPress could not open this image for editing.', 'publishrank' ) ), 400 );
		}
		$source_size = $editor->get_size();
		if ( ! is_array( $source_size ) || empty( $source_size['width'] ) || empty( $source_size['height'] ) ) {
			wp_send_json_error( array( 'message' => __( 'WordPress could not read the selected image dimensions.', 'publishrank' ) ), 400 );
		}
		$source_width  = (int) $source_size['width'];
		$source_height = (int) $source_size['height'];
		$scale         = min( $width / $source_width, null === $height ? PHP_FLOAT_MAX : $height / $source_height );
		if ( $crop && ( $width > $source_width || $height > $source_height ) ) {
			wp_send_json_error( array( 'message' => __( 'WordPress will not upscale this image to create an exact crop. Choose an original at least as large as the requested dimensions.', 'publishrank' ) ), 400 );
		}
		if ( ! $crop && $scale > 1 ) {
			wp_send_json_error( array( 'message' => __( 'WordPress will not upscale this image. Choose an original large enough for the requested output; enlarging cannot restore image detail.', 'publishrank' ) ), 400 );
		}
		if ( ( ! $crop && 1.0 === $scale ) || ( $crop && $width === $source_width && $height === $source_height ) ) {
			wp_send_json_error( array( 'message' => __( 'This image is already at the requested size. Keep it selected; no resized copy is needed.', 'publishrank' ) ), 400 );
		}
		if ( $crop ) {
			$output_pixels = $width * $height;
		} else {
			$output_width  = max( 1, (int) round( $source_width * $scale ) );
			$output_height = max( 1, (int) round( $source_height * $scale ) );
			$output_pixels = $output_width * $output_height;
		}
		if ( $output_pixels > 40000000 ) {
			wp_send_json_error( array( 'message' => __( 'This size would create an image larger than 40 megapixels. Choose smaller dimensions.', 'publishrank' ) ), 400 );
		}
		$resized = $editor->resize( $width, $height, $crop );
		if ( is_wp_error( $resized ) ) {
			wp_send_json_error( array( 'message' => __( 'WordPress could not resize this image. Try a different size or crop option.', 'publishrank' ) ), 400 );
		}
		$directory = dirname( $file );
		$extension = pathinfo( $file, PATHINFO_EXTENSION );
		$basename  = sanitize_file_name( pathinfo( $file, PATHINFO_FILENAME ) . '-discover.' . $extension );
		$target    = trailingslashit( $directory ) . wp_unique_filename( $directory, $basename );
		$saved     = $editor->save( $target );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => __( 'WordPress could not save the resized image copy.', 'publishrank' ) ), 500 );
		}
		$saved_path = isset( $saved['path'] ) ? realpath( $saved['path'] ) : realpath( $target );
		if ( ! $saved_path || 0 !== strpos( wp_normalize_path( $saved_path ), trailingslashit( wp_normalize_path( $base ) ) ) || ! is_readable( $saved_path ) ) {
			wp_send_json_error( array( 'message' => __( 'The resized image could not be verified inside the uploads folder.', 'publishrank' ) ), 500 );
		}
		$info = wp_getimagesize( $saved_path );
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			wp_delete_file( $saved_path );
			wp_send_json_error( array( 'message' => __( 'The resized copy has invalid image dimensions.', 'publishrank' ) ), 500 );
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$mime = isset( $saved['mime-type'] ) ? $saved['mime-type'] : ( wp_check_filetype( $saved_path )['type'] ?? get_post_mime_type( $source_id ) );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => sprintf( __( '%s resized copy', 'publishrank' ), get_the_title( $source_id ) ),
				'post_excerpt'   => get_post_field( 'post_excerpt', $source_id ),
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			),
			$saved_path,
			$post_id,
			true
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $saved_path );
			wp_send_json_error( array( 'message' => __( 'The resized copy could not be added to the Media Library.', 'publishrank' ) ), 500 );
		}
		$alt = get_post_meta( $source_id, '_wp_attachment_image_alt', true );
		if ( '' !== (string) $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $saved_path );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
		$url = wp_get_attachment_url( $attachment_id );
		wp_send_json_success(
			array(
				'attachmentId' => (int) $attachment_id,
				'url'          => esc_url_raw( $url ),
				'width'        => (int) $info[0],
				'height'       => (int) $info[1],
			)
		);
	}

	/** Render an optional post-level SEO override with a human-readable label and help. */
	private function render_seo_field( $post_id, $key, $label, $help = '', $type = 'text', $max = 0, $placeholders = array(), $placeholder = '' ) {
		$id       = (string) $key;
		$help_id  = $id . '-help';
		$value    = PostMeta::get( $post_id, $key );
		if ( '' === $placeholder && isset( $placeholders[ $key ] ) ) {
			$placeholder = $placeholders[ $key ];
		}
		$described_by = '' !== $help ? ' aria-describedby="' . esc_attr( $help_id ) . '"' : '';
		echo '<div class="pr-seo-field"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label>';
		if ( 'textarea' === $type ) {
			echo '<textarea class="widefat" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" maxlength="' . esc_attr( (string) $max ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . $described_by . '>' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input class="widefat" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '"' . ( $max ? ' maxlength="' . esc_attr( (string) $max ) . '"' : '' ) . ' placeholder="' . esc_attr( $placeholder ) . '"' . $described_by . ' />';
		}
		if ( '' !== $help ) {
			echo '<p class="description" id="' . esc_attr( $help_id ) . '">' . esc_html( $help ) . '</p>';
		}
		$variable_fields = array(
			PostMeta::TITLE          => 'title',
			PostMeta::OG_TITLE       => 'title',
			PostMeta::DESCRIPTION    => 'description',
			PostMeta::OG_DESCRIPTION => 'description',
		);
		if ( isset( $variable_fields[ $key ] ) ) {
			echo \PublishRankSEO\Admin\VariablePicker::slot( $id, $variable_fields[ $key ], 'post' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in slot().
		}
		echo '</div>';
	}
	private function render_repeater( $post_id, $key, $label, $description, $max ) {
		$values  = PostMeta::get_list( $post_id, $key );
		$help_id = sanitize_html_class( $key ) . '-list-help';
		echo '<fieldset class="rhs-repeater" data-key="' . esc_attr( $key ) . '" data-label="' . esc_attr( $label ) . '" data-max="' . esc_attr( (string) $max ) . '" aria-describedby="' . esc_attr( $help_id ) . '"><legend>' . esc_html( $label ) . '</legend>';
		echo '<div class="rhs-repeater__rows">';
		foreach ( $values as $index => $value ) {
			$this->render_repeater_row( $key, $value, $label, $index + 1, $help_id );
		}
		echo '</div>';
		echo '<p><button type="button" class="button rhs-repeater__add" aria-label="' . esc_attr( sprintf( /* translators: %s: list name. */ __( 'Add another item to %s', 'publishrank' ), $label ) ) . '">' . esc_html__( 'Add another', 'publishrank' ) . '</button></p>';
		echo '<p class="description" id="' . esc_attr( $help_id ) . '">' . esc_html( $description ) . '</p><span class="screen-reader-text rhs-repeater__status" role="status" aria-live="polite" aria-atomic="true"></span></fieldset>';
	}

	private function render_repeater_row( $key, $value = '', $label = '', $count = 1, $help_id = '' ) {
		$input_label  = sprintf( /* translators: 1: list name, 2: item number. */ __( '%1$s, item %2$d', 'publishrank' ), $label, (int) $count );
		$remove_label = sprintf( /* translators: 1: list name, 2: item number. */ __( 'Remove item %2$d from %1$s', 'publishrank' ), $label, (int) $count );
		echo '<div class="rhs-repeater__row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;">';
		echo '<input class="widefat" style="flex:1;" type="text" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $value ) . '" maxlength="100" aria-label="' . esc_attr( $input_label ) . '" aria-describedby="' . esc_attr( $help_id ) . '" />';
		echo '<button type="button" class="button rhs-repeater__remove" aria-label="' . esc_attr( $remove_label ) . '">&times;</button></div>';
	}
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
		$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$args['meta_query'][] = array(
			'relation' => 'OR',
			array(
				'key'     => PostMeta::ROBOTS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => PostMeta::ROBOTS,
				'value'   => 'noindex',
				'compare' => 'NOT LIKE',
			),
		);
		return $args;
	}
}

/* EOF */
