<?php
/** Technical head metadata output with Yoast-compatible standards, without imitating Yoast branding. */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Core\PublicationProfile;
use PublishRankSEO\SEO\SocialWatermark;

defined( 'ABSPATH' ) || exit;

final class HeadOutput {
	/** @var OutputRegistry */
	private $registry;
	/** @var MetaGenerator */
	private $meta;

	public function __construct( OutputRegistry $registry, ?MetaGenerator $meta = null ) {
		$this->registry = $registry;
		$this->meta     = $meta ? $meta : new MetaGenerator();
	}

	public function register() {
		if ( ! get_option( 'publishrank_meta_enabled', true ) ) {
			return;
		}
		add_action( 'after_setup_theme', array( $this, 'ensure_title_support' ), 1 );
		add_filter( 'pre_get_document_title', array( $this, 'title' ), 99 );
		add_action( 'wp_head', array( $this, 'render' ), 1 );
		// PublishRank prints the canonical and robots tags itself; remove core's copies to avoid duplicates.
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_robots', 1 );
	}

	/** Add core title-tag support only when the active theme has not declared it. */
	public function ensure_title_support() {
		if ( function_exists( 'current_theme_supports' ) && ! current_theme_supports( 'title-tag' ) ) {
			add_theme_support( 'title-tag' );
		}
	}

	public function title( $title ) {
		if ( is_admin() || wp_doing_ajax() ) {
			return $title;
		}

		$generated = $this->meta->title();
		if ( '' !== $generated ) {
			$this->registry->claim( 'title', 'head-output' );
		}
		return '' !== $generated ? $generated : $title;
	}

	/**
	 * Fail-safe entry point: a problem while building this output can never take the public page down.
	 * Partial markup is discarded and the failure is recorded for the integrity/diagnostics screens.
	 */
	public function render() {
		ob_start();
		try {
			$this->render_output();
			echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup was escaped where it was built.
		} catch ( \Throwable $e ) {
			ob_end_clean();
			do_action( 'publishrank_module_failed', __CLASS__, 'runtime_exception', false, $e->getMessage() );
		}
	}

	private function render_output() {
		if ( is_admin() || wp_doing_ajax() || ! get_option( 'publishrank_meta_enabled', true ) ) {
			return;
		}
		// Always disclose technical provenance in the public source. This is separate from the optional visible footer credit.
		echo "<!-- SEO metadata output begin -->\n";
		echo '<!-- PublishRank: begin; version=' . esc_html( PUBLISHRANK_SEO_VERSION ) . '; output=technical,social,news -->' . "\n";
		echo '<meta name="generator" content="PublishRank ' . esc_attr( PUBLISHRANK_SEO_VERSION ) . '" />' . "\n";
		echo '<meta name="publishrank" content="active" />' . "\n";
		echo "<!-- Technical metadata begin -->\n";
		$description = $this->meta->description();
		if ( $this->registry->claim( 'description' ) && '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		if ( $this->registry->claim( 'canonical' ) ) {
			echo '<link rel="canonical" href="' . esc_url( $this->meta->canonical() ) . '" />' . "\n";
		}
			if ( $this->registry->claim( 'robots' ) ) {
				echo '<meta name="robots" content="' . esc_attr( $this->robots_value() ) . '" />' . "\n";
			}
			echo "<!-- Technical metadata end -->\n";
			if ( $this->is_editorial_article() && $this->registry->claim( 'news-meta' ) ) {
				echo "<!-- News SEO metadata begin; type=NewsArticle -->\n";
			$post_id = get_queried_object_id();
			echo '<meta property="article:published_time" content="' . esc_attr( get_post_time( DATE_W3C, true, $post_id ) ) . '" />' . "\n";
			echo '<meta property="article:modified_time" content="' . esc_attr( get_post_modified_time( DATE_W3C, true, $post_id ) ) . '" />' . "\n";
			echo '<meta property="article:section" content="' . esc_attr( $this->meta->section() ) . '" />' . "\n";
					foreach ( $this->meta->keywords() as $keyword ) {
						echo '<meta property="article:tag" content="' . esc_attr( $keyword ) . '" />' . "\n";
					}
					$news_keywords = implode( ', ', $this->meta->keywords() );
					if ( $news_keywords ) { echo '<meta name="news_keywords" content="' . esc_attr( $news_keywords ) . '" />' . "\n"; }
				echo "<!-- News SEO metadata end -->\n";
			}
				$image = $this->image();
				$image_details = $this->image_details( $image );
				if ( $this->registry->claim( 'og' ) ) {
					echo "<!-- Open Graph metadata begin -->\n";
			echo '<meta property="og:title" content="' . esc_attr( $this->social( PostMeta::OG_TITLE, $this->meta->title() ) ) . '" />' . "\n";
			echo '<meta property="og:site_name" content="' . esc_attr( PublicationProfile::publication_name() ) . '" />' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $this->social( PostMeta::OG_DESCRIPTION, $description ) ) . '" />' . "\n";
					echo '<meta property="og:url" content="' . esc_url( $this->meta->canonical() ) . '" />' . "\n";
					echo '<meta property="og:type" content="' . esc_attr( $this->is_editorial_article() ? 'article' : 'website' ) . '" />' . "\n";
					if ( $this->is_editorial_article() ) { echo '<meta property="og:updated_time" content="' . esc_attr( get_post_modified_time( DATE_W3C, true, get_queried_object_id() ) ) . '" />' . "\n"; }
				$locale = str_replace( '-', '_', LanguageAlternates::current_locale() ?: sanitize_text_field( (string) get_option( 'publishrank_default_locale', get_locale() ) ) );
				echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '" />' . "\n";
				foreach ( LanguageAlternates::alternates() as $alternate_locale => $alternate_url ) {
					$alternate_locale = str_replace( '-', '_', $alternate_locale );
					if ( $alternate_locale !== $locale ) {
						echo '<meta property="og:locale:alternate" content="' . esc_attr( $alternate_locale ) . '" />' . "\n";
					}
				}
					if ( $image ) {
						echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
						$image_alt = $this->image_alt( $image );
						if ( $image_alt ) { echo '<meta property="og:image:alt" content="' . esc_attr( $image_alt ) . '" />' . "\n"; }
						if ( 0 < $image_details['width'] ) { echo '<meta property="og:image:width" content="' . esc_attr( $image_details['width'] ) . '" />' . "\n"; }
						if ( 0 < $image_details['height'] ) { echo '<meta property="og:image:height" content="' . esc_attr( $image_details['height'] ) . '" />' . "\n"; }
						if ( $image_details['type'] ) { echo '<meta property="og:image:type" content="' . esc_attr( $image_details['type'] ) . '" />' . "\n"; }
							if ( 0 === strpos( strtolower( $image ), 'https://' ) ) { echo '<meta property="og:image:secure_url" content="' . esc_url( $image ) . '" />' . "\n"; }
						}
							if ( $this->is_editorial_article() ) {
							$publisher = $this->publisher_profile();
							if ( $publisher ) { echo '<meta property="article:publisher" content="' . esc_url( $publisher ) . '" />' . "\n"; }
							$author_id = (int) get_post_field( 'post_author', get_queried_object_id() );
							$author_url = (string) get_user_meta( $author_id, '_publishrank_author_facebook', true );
							if ( ! $author_url ) { $author_url = get_author_posts_url( $author_id ); }
							if ( $author_url ) { echo '<meta property="article:author" content="' . esc_url( $author_url ) . '" />' . "\n"; }
						}
						$facebook_page = esc_url_raw( (string) get_option( 'publishrank_facebook_page_url', '' ) );
						if ( $facebook_page ) { echo '<meta property="fb:pages" content="' . esc_attr( $facebook_page ) . '" />' . "\n"; }
						$app_id = sanitize_text_field( (string) get_option( 'publishrank_facebook_app_id', '' ) );
					if ( $app_id ) { echo '<meta property="fb:app_id" content="' . esc_attr( $app_id ) . '" />' . "\n"; }
					echo "<!-- Open Graph metadata end -->\n";
			}
				if ( $this->registry->claim( 'twitter' ) ) {
				echo "<!-- X/Twitter metadata begin -->\n";
			$twitter_card = is_singular() ? PostMeta::get( get_queried_object_id(), PostMeta::TWITTER_CARD, get_option( 'publishrank_twitter_card', 'summary_large_image' ) ) : get_option( 'publishrank_twitter_card', 'summary_large_image' );
			$twitter_card = in_array( $twitter_card, array( 'summary', 'summary_large_image' ), true ) ? $twitter_card : 'summary_large_image';
			echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . '" />' . "\n";
			$handle = (string) get_option( 'publishrank_seo_twitter_site', '' );
			if ( '' !== $handle ) {
				echo '<meta name="twitter:site" content="@' . esc_attr( ltrim( $handle, '@' ) ) . '" />' . "\n";
			}
			$creator = is_singular() ? PostMeta::get( get_queried_object_id(), PostMeta::TWITTER_CREATOR, '' ) : '';
			if ( $creator ) { echo '<meta name="twitter:creator" content="@' . esc_attr( ltrim( $creator, '@' ) ) . '" />' . "\n"; }
			echo '<meta name="twitter:title" content="' . esc_attr( $this->social( PostMeta::OG_TITLE, $this->meta->title() ) ) . '" />' . "\n";
			echo '<meta name="twitter:description" content="' . esc_attr( $this->social( PostMeta::OG_DESCRIPTION, $description ) ) . '" />' . "\n";
						if ( $image ) {
							echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
							$image_alt = $this->image_alt( $image );
							if ( $image_alt ) { echo '<meta name="twitter:image:alt" content="' . esc_attr( $image_alt ) . '" />' . "\n"; }
						}
				echo "<!-- X/Twitter metadata end -->\n";
			}
			echo "<!-- SEO metadata output end -->\n";
	}

	/** Per-post social text override, falling back to the generated value. */
	private function social( $meta_key, $fallback ) {
		if ( is_singular() ) {
			$override = PostMeta::get( get_queried_object_id(), $meta_key, '' );
			if ( '' !== (string) $override ) {
				return wp_strip_all_tags( (string) $override );
			}
		}
		return $fallback;
	}

	/** Robots directive: respects "discourage search engines" and adds large image previews for indexable pages. */
	private function robots_value() {
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			return 'noindex,nofollow';
		}
		$robots = $this->meta->robots();
		if ( false === strpos( $robots, 'noindex' ) && false === strpos( $robots, 'max-image-preview' ) ) {
			$robots .= ',max-image-preview:large';
		}
		return (string) apply_filters( 'publishrank_seo_robots', $robots );
	}

	/** @var array<string,mixed> Details of the social image chosen for this request. */
	private $image_info = array();

	/** @return string Social image: per-post override, featured image (any post type), first content image, then the site default. */
	private function image() {
		$this->image_info = array();
		if ( is_singular() ) {
			$found = SeoData::image_for_post( get_queried_object_id() );
			if ( $found && ! empty( $found['url'] ) ) {
				$url = 'override' === ( $found['source'] ?? '' ) ? PublicationProfile::normalize_url( (string) $found['url'] ) : (string) $found['url'];
				if ( $url ) {
					$url = PublicationProfile::asset_url( $url );
						$this->image_info = array_merge( $found, array( 'url' => $url ) );
						$processed = SocialWatermark::url( $url, $this->image_info );
						$this->image_info['original_url'] = $url;
						$this->image_info['url'] = $processed;
						return $processed;
				}
			}
		}
			$default = (string) get_option( 'publishrank_seo_default_social_image', '' );
			$default = $default ? PublicationProfile::asset_url( $default ) : '';
			return $default ? SocialWatermark::url( $default ) : '';
	}

	/** @param string $url @return string Override, then attachment alt text, caption or post title. */
	private function image_alt( $url ) {
		if ( ! empty( $this->image_info['url'] ) && $this->image_info['url'] === $url && ! empty( $this->image_info['alt'] ) ) {
			return wp_strip_all_tags( (string) $this->image_info['alt'] );
		}
		$attachment_id = function_exists( 'attachment_url_to_postid' ) && $url ? absint( attachment_url_to_postid( $url ) ) : 0;
		return $attachment_id ? sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) : '';
	}

	/** @param string $url @return array<string, mixed> */
	private function image_details( $url ) {
		$details = array( 'width' => 0, 'height' => 0, 'type' => '' );
		if ( ! $url ) { return $details; }
		if ( ! empty( $this->image_info['url'] ) && $this->image_info['url'] === $url && ( ! empty( $this->image_info['width'] ) || ! empty( $this->image_info['type'] ) ) ) {
			return array(
				'width'  => absint( $this->image_info['width'] ?? 0 ),
				'height' => absint( $this->image_info['height'] ?? 0 ),
				'type'   => sanitize_text_field( (string) ( $this->image_info['type'] ?? '' ) ),
			);
		}
		if ( ! function_exists( 'attachment_url_to_postid' ) ) { return $details; }
		$attachment_id = absint( attachment_url_to_postid( $url ) );
		if ( ! $attachment_id ) { return $details; }
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) ) {
			$details['width']  = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0;
			$details['height'] = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0;
		}
		$mime     = get_post_mime_type( $attachment_id );
		$filetype = wp_check_filetype( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$details['type'] = ! empty( $filetype['type'] ) ? sanitize_text_field( $filetype['type'] ) : ( $mime ? sanitize_text_field( $mime ) : '' );
		return $details;
	}

		/** @return string */
		private function publisher_profile() {
			$configured = esc_url_raw( (string) get_option( 'publishrank_facebook_page_url', '' ) );
			if ( $configured ) { return $configured; }
				$profiles = PublicationProfile::social_profiles();
			foreach ( $profiles as $profile ) {
				if ( false !== strpos( strtolower( $profile ), 'facebook.com' ) ) { return $profile; }
			}
			return ! empty( $profiles ) ? (string) reset( $profiles ) : '';
		}
		private function is_editorial_article() {
			$post = get_queried_object();
			$type = $post instanceof \WP_Post ? get_post_type_object( $post->post_type ) : false;
			return is_singular() && $type && $type->public && $type->publicly_queryable && ! in_array( $post->post_type, array( 'page', 'attachment' ), true );
		}
	}

/* EOF */
