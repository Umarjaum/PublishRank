<?php
/** Technical head metadata output with Yoast-compatible standards, without imitating Yoast branding. */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Core\PublicationProfile;

final class HeadOutput {
	/** @var OutputRegistry */
	private $registry;
	/** @var MetaGenerator */
	private $meta;

	public function __construct( OutputRegistry $registry, MetaGenerator $meta = null ) {
		$this->registry = $registry;
		$this->meta     = $meta ? $meta : new MetaGenerator();
	}

	public function register() {
		if ( ! get_option( 'publishrank_meta_enabled', true ) ) {
			return;
		}
		add_filter( 'pre_get_document_title', array( $this, 'title' ), 99 );
		add_action( 'wp_head', array( $this, 'render' ), 1 );
		// PublishRank prints the canonical and robots tags itself; remove core's copies to avoid duplicates.
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_robots', 1 );
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

	public function render() {
		if ( is_admin() || wp_doing_ajax() || ! get_option( 'publishrank_meta_enabled', true ) ) {
			return;
		}
		$source_attribution = (bool) get_option( 'publishrank_source_attribution', false );
		// Keep a neutral delimiter for output-recovery logic; product credits are opt-in.
		echo "<!-- SEO metadata output begin -->\n";
		if ( $source_attribution ) {
			echo '<!-- PublishRank: begin; version=' . esc_html( PUBLISHRANK_SEO_VERSION ) . '; output=technical,social,news -->' . "\n";
			echo '<meta name="generator" content="PublishRank SEO ' . esc_attr( PUBLISHRANK_SEO_VERSION ) . '" />' . "\n";
			echo '<meta name="publishrank" content="active" />' . "\n";
		}
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
			if ( is_singular( 'post' ) && $this->registry->claim( 'news-meta' ) ) {
				echo "<!-- News SEO metadata begin; type=NewsArticle -->\n";
			$post_id = get_queried_object_id();
			echo '<meta property="article:published_time" content="' . esc_attr( get_post_time( DATE_W3C, true, $post_id ) ) . '" />' . "\n";
			echo '<meta property="article:modified_time" content="' . esc_attr( get_post_modified_time( DATE_W3C, true, $post_id ) ) . '" />' . "\n";
			echo '<meta property="article:section" content="' . esc_attr( $this->meta->section() ) . '" />' . "\n";
				foreach ( $this->meta->keywords() as $keyword ) {
					echo '<meta property="article:tag" content="' . esc_attr( $keyword ) . '" />' . "\n";
				}
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
					echo '<meta property="og:type" content="' . esc_attr( is_singular( 'post' ) ? 'article' : 'website' ) . '" />' . "\n";
					if ( is_singular( 'post' ) ) { echo '<meta property="og:updated_time" content="' . esc_attr( get_post_modified_time( DATE_W3C, true, get_queried_object_id() ) ) . '" />' . "\n"; }
				$locale = str_replace( '-', '_', sanitize_text_field( (string) get_option( 'publishrank_default_locale', get_locale() ) ) );
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
					if ( is_singular( 'post' ) ) {
						$publisher = $this->publisher_profile();
						if ( $publisher ) { echo '<meta property="article:publisher" content="' . esc_url( $publisher ) . '" />' . "\n"; }
						$author_url = get_author_posts_url( (int) get_post_field( 'post_author', get_queried_object_id() ) );
						if ( $author_url ) { echo '<meta property="article:author" content="' . esc_url( $author_url ) . '" />' . "\n"; }
					}
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

	/** @return string Social image: per-post override, featured image, then the site default. */
		private function image() {
		if ( is_singular() ) {
			$post_id  = get_queried_object_id();
			$override = PostMeta::get( $post_id, PostMeta::OG_IMAGE, '' );
			if ( '' !== (string) $override ) {
				$url = PublicationProfile::normalize_url( (string) $override );
				if ( $url ) {
					return PublicationProfile::asset_url( $url );
				}
			}
			if ( is_singular( 'post' ) ) {
				$image = get_the_post_thumbnail_url( $post_id, 'full' );
				if ( $image ) {
					return PublicationProfile::asset_url( (string) $image );
				}
			}
		}
		$default = (string) get_option( 'publishrank_seo_default_social_image', '' );
			return $default ? PublicationProfile::asset_url( $default ) : '';
		}

		/** @param string $url @return string */
		private function image_alt( $url ) {
			if ( is_singular() ) {
				$alt = PostMeta::get( get_queried_object_id(), PostMeta::OG_IMAGE_ALT, '' );
				if ( $alt ) { return wp_strip_all_tags( (string) $alt ); }
			}
			$attachment_id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
			return $attachment_id ? sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) : '';
		}

		/** @param string $url @return array<string, mixed> */
		private function image_details( $url ) {
			$details = array( 'width' => 0, 'height' => 0, 'type' => '' );
			if ( ! $url || ! function_exists( 'attachment_url_to_postid' ) ) { return $details; }
			$attachment_id = absint( attachment_url_to_postid( $url ) );
			if ( ! $attachment_id ) { return $details; }
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $metadata ) ) {
				$details['width'] = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0;
				$details['height'] = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0;
			}
			$mime = get_post_mime_type( $attachment_id );
			$details['type'] = $mime ? sanitize_text_field( $mime ) : '';
			return $details;
		}

		/** @return string */
		private function publisher_profile() {
			$profiles = PublicationProfile::social_profiles();
			foreach ( $profiles as $profile ) {
				if ( false !== strpos( strtolower( $profile ), 'facebook.com' ) ) { return $profile; }
			}
			return ! empty( $profiles ) ? (string) reset( $profiles ) : '';
		}
	}

/* EOF */
