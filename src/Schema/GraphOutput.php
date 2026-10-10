<?php
/** Emits one connected JSON-LD graph for the current document. */
namespace PublishRankSEO\Schema;

use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Core\PublicationProfile;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\SEO\BreadcrumbEngine;

final class GraphOutput {
	/** @var OutputRegistry */
	private $registry;

	public function __construct( OutputRegistry $registry ) { $this->registry = $registry; }
	public function register() { add_action( 'wp_head', array( $this, 'render' ), 20 ); }

	/** Render one connected graph when PublishRank owns the JSON-LD slot. */
	public function render() {
		if ( is_admin() || wp_doing_ajax() || ! get_option( 'publishrank_schema_enabled', true ) || ! $this->registry->claim( 'jsonld', 'graph-output' ) ) { return; }
		$identity = $this->identity_entity();
		$graph = array( $identity, $this->website(), $this->webpage() );
		$list = null;
		if ( is_singular( 'post' ) ) {
			$graph[] = $this->article();
			$graph[] = $this->article_author();
		}
		if ( ! is_singular() ) {
			$list = $this->collection_list();
			if ( $list ) {
				$graph[] = $list;
				$graph[2]['mainEntity'] = array( '@id' => trailingslashit( ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical() ) . '#itemlist' );
			}
		}
		$automated = ( new SchemaAutomation() )->for_current_document();
		if ( is_array( $automated ) && ! empty( $automated['faq'] ) ) {
			$faq = $automated['faq'];
			$faq['@id'] = trailingslashit( ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical() ) . '#faq';
			$faq['mainEntityOfPage'] = array( '@id' => trailingslashit( ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical() ) . '#webpage' );
			$graph[] = $faq;
		}
		$graph = apply_filters( 'publishrank_schema_graph', $graph, $this );
		if ( ! is_array( $graph ) ) { $graph = array(); }
		$graph = array_values( array_filter( $graph, 'is_array' ) );
		if ( is_singular() && false === function_exists( 'yoast_breadcrumb' ) ) { $graph[] = ( new BreadcrumbEngine() )->schema(); }
		$entities = PublicationProfile::identity_is_person() ? 'person,website,webpage' : 'organization,website,webpage';
		if ( is_singular( 'post' ) ) { $entities .= ',article'; }
		if ( $list ) { $entities .= ',itemlist'; }
		echo '<!-- Structured data graph begin; entities=' . esc_attr( $entities ) . " -->\n";
		$custom = $this->custom_schema(); if ( $custom ) { $graph[] = $custom; }
		echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
		echo "<!-- Structured data graph end -->\n";
	}

	/** @return string Stable @id anchor for the site identity entity. */
	private function identity_id() {
		return trailingslashit( PublicationProfile::primary_url() ) . ( PublicationProfile::identity_is_person() ? '#person' : '#organization' );
	}

	/** @return array<string, mixed> */
	private function identity_entity() {
		return PublicationProfile::identity_is_person() ? $this->person() : $this->organization();
	}
	/** @return array<string, mixed> */
	/** @return array<string, mixed> */
	private function organization() {
		$url = trailingslashit( PublicationProfile::primary_url() );
		$org = PublicationProfile::organization_identity();
		$type = sanitize_text_field( (string) get_option( 'publishrank_seo_publisher_type', 'NewsMediaOrganization' ) );
		$type = in_array( $type, array( 'NewsMediaOrganization', 'Organization' ), true ) ? $type : 'NewsMediaOrganization';

		$logo_url = $org['logo'] ? esc_url_raw( $org['logo'] ) : '';
		if ( ! $logo_url ) {
			$theme_logo = get_theme_mod( 'custom_logo' );
			$logo_url = $theme_logo ? wp_get_attachment_image_url( (int) $theme_logo, 'full' ) : '';
		}
		if ( ! $logo_url && function_exists( 'get_site_icon_url' ) ) { $logo_url = get_site_icon_url( 512 ); }

		$country = strtoupper( sanitize_text_field( $org['country'] ) );
		$organization = array(
			'@type'      => $type,
			'@id'        => $url . '#organization',
			'name'       => $org['name'] ? sanitize_text_field( $org['name'] ) : PublicationProfile::publisher_name(),
			'url'        => $url,
			'inLanguage' => sanitize_text_field( (string) get_option( 'publishrank_default_language', substr( (string) get_locale(), 0, 2 ) ) ),
		);
		if ( $org['legal_name'] )     { $organization['legalName'] = sanitize_text_field( $org['legal_name'] ); }
		if ( $org['alternate_name'] ) { $organization['alternateName'] = sanitize_text_field( $org['alternate_name'] ); }
		if ( $org['description'] )    { $organization['description'] = wp_strip_all_tags( $org['description'] ); }
		if ( $org['founding_date'] && preg_match( '/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/', $org['founding_date'] ) ) {
			$organization['foundingDate'] = $org['founding_date'];
		}
		if ( $org['contact_email'] || $org['contact_phone'] ) {
			$organization['contactPoint'] = array( '@type' => 'ContactPoint', 'contactType' => 'customer support' );
			if ( $org['contact_email'] ) { $organization['contactPoint']['email'] = sanitize_email( $org['contact_email'] ); }
			if ( $org['contact_phone'] ) { $organization['contactPoint']['telephone'] = sanitize_text_field( $org['contact_phone'] ); }
		}
		if ( $org['street'] || $org['locality'] || $org['region'] || $org['postal_code'] || $country ) {
			$organization['address'] = array( '@type' => 'PostalAddress' );
			if ( $org['street'] )      { $organization['address']['streetAddress']  = sanitize_text_field( $org['street'] ); }
			if ( $org['locality'] )    { $organization['address']['addressLocality'] = sanitize_text_field( $org['locality'] ); }
			if ( $org['region'] )      { $organization['address']['addressRegion']   = sanitize_text_field( $org['region'] ); }
			if ( $org['postal_code'] ) { $organization['address']['postalCode']      = sanitize_text_field( $org['postal_code'] ); }
			if ( $country )            { $organization['address']['addressCountry']  = $country; }
		}
		if ( $country ) { $organization['areaServed'] = array( '@type' => 'Country', 'name' => $country ); }
		if ( $logo_url ) { $organization['logo'] = $this->image_object( $logo_url, $url . '#publisher-logo' ); }
		if ( ! empty( $org['parent_organization'] ) ) { $organization['parentOrganization'] = array( '@type' => 'Organization', 'name' => sanitize_text_field( $org['parent_organization'] ) ); }
		if ( ! empty( $org['owner_name'] ) ) { $owner = array( '@type' => 'Organization', 'name' => sanitize_text_field( $org['owner_name'] ) ); if ( ! empty( $org['owner_url'] ) ) { $owner['url'] = esc_url_raw( $org['owner_url'] ); } if ( ! empty( $org['owner_logo'] ) ) { $owner['logo'] = $this->image_object( $org['owner_logo'], $url . '#owner-logo' ); } $organization['owner'] = $owner; }
		if ( ! empty( $org['copyright_holder'] ) ) { $organization['copyrightHolder'] = array( '@type' => 'Organization', 'name' => sanitize_text_field( $org['copyright_holder'] ) ); }
		$social = PublicationProfile::social_profiles();
		if ( ! empty( $social ) ) { $organization['sameAs'] = $social; }
		return $organization;
	}

	/** @return array<string, mixed> */
	private function person() {
		$url    = trailingslashit( PublicationProfile::primary_url() );
		$person = PublicationProfile::person_identity();
		$node   = array(
			'@type'      => 'Person',
			'@id'        => $url . '#person',
			'name'       => $person['name'] ? sanitize_text_field( $person['name'] ) : PublicationProfile::publisher_name(),
			'url'        => $person['url'] ? esc_url_raw( $person['url'] ) : $url,
			'inLanguage' => sanitize_text_field( (string) get_option( 'publishrank_default_language', substr( (string) get_locale(), 0, 2 ) ) ),
		);
		if ( $person['alternate_name'] ) { $node['alternateName'] = sanitize_text_field( $person['alternate_name'] ); }
		if ( $person['description'] )    { $node['description'] = wp_strip_all_tags( $person['description'] ); }
		if ( $person['job_title'] )      { $node['jobTitle'] = sanitize_text_field( $person['job_title'] ); }
		if ( $person['email'] )          { $node['email'] = sanitize_email( $person['email'] ); }
		if ( $person['image'] )          { $node['image'] = $this->image_object( $person['image'], $url . '#person-image' ); }
		$social = PublicationProfile::social_profiles();
		if ( ! empty( $social ) ) { $node['sameAs'] = $social; }
		return $node;
	}
	/** @return array<string, mixed> */
	private function website() {
		$url = trailingslashit( PublicationProfile::primary_url() );
		return array(
			'@type' => 'WebSite',
			'@id' => $url . '#website',
			'url' => $url,
			'name' => PublicationProfile::publication_name(),
			'description' => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
			'publisher' => array( '@id' => $this->identity_id() ),
			'inLanguage' => sanitize_text_field( (string) get_option( 'publishrank_default_locale', get_bloginfo( 'language' ) ) ),
			'potentialAction' => array(
				'@type' => 'SearchAction',
				'target' => array( '@type' => 'EntryPoint', 'urlTemplate' => $url . '?s={search_term_string}' ),
				'query-input' => 'required name=search_term_string',
			),
		);
	}

	/** @return array<string, mixed> */
	private function webpage() {
		$canonical = ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical();
		$url = trailingslashit( $canonical );
		$page = array(
			'@type' => is_singular() ? 'WebPage' : 'CollectionPage',
			'@id' => $url . '#webpage',
			'url' => $canonical,
			'name' => wp_get_document_title(),
			'description' => ( new \PublishRankSEO\Meta\MetaGenerator() )->description(),
			'inLanguage' => sanitize_text_field( (string) get_option( 'publishrank_default_locale', get_bloginfo( 'language' ) ) ),
			'isPartOf' => array( '@id' => trailingslashit( PublicationProfile::primary_url() ) . '#website' ),
			'about' => array( '@id' => $this->identity_id() ),
		);
		if ( is_singular() ) {
			$post = get_queried_object();
			$page['datePublished'] = get_post_time( DATE_W3C, true, $post );
			$page['dateModified'] = get_post_modified_time( DATE_W3C, true, $post );
			$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
			if ( is_singular( 'post' ) ) { $page['mainEntity'] = array( '@id' => $url . '#article' ); }
			$image = $post instanceof \WP_Post ? get_the_post_thumbnail_url( $post, 'full' ) : '';
			if ( ! $image && $post instanceof \WP_Post ) { $image = (string) PostMeta::get( $post->ID, PostMeta::OG_IMAGE, get_option( 'publishrank_seo_default_social_image', '' ) ); }
			if ( $image ) {
				$image_id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $image ) ) : 0;
				$image_meta = $image_id ? wp_get_attachment_metadata( $image_id ) : array();
				$page['primaryImageOfPage'] = $this->image_object( $image, $url . '#primaryimage', is_array( $image_meta ) ? $image_meta : array() );
			}
		}
		return $page;
	}

	/** @return array<string, mixed> */
	private function article() {
		$post = get_queried_object();
		$permalink = esc_url_raw( (string) get_permalink( $post ) );
		$site_url = trailingslashit( PublicationProfile::primary_url() );
		$author = $post instanceof \WP_Post ? get_userdata( $post->post_author ) : false;
		$author_url = $author ? (string) get_author_posts_url( $post->post_author ) : $site_url;
		$schema_type = (string) PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, 'NewsArticle' );
		$schema_type = in_array( $schema_type, array( 'NewsArticle', 'Article', 'BlogPosting' ), true ) ? $schema_type : 'NewsArticle';
		$description = (string) PostMeta::get( $post->ID, PostMeta::DESCRIPTION, '' );
		$description = $description ? wp_strip_all_tags( $description ) : wp_strip_all_tags( get_the_excerpt( $post ) );
		$author_node_id = trailingslashit( $author_url ) . '#author';
		$identity = $this->identity_entity();
		$article = array(
			'@type' => $schema_type,
			'@id' => trailingslashit( $permalink ) . '#article',
			'url' => $permalink,
			'headline' => wp_strip_all_tags( (string) get_the_title( $post ) ),
			'description' => $description,
			'datePublished' => get_post_time( DATE_W3C, true, $post ),
			'dateModified' => get_post_modified_time( DATE_W3C, true, $post ),
			'dateCreated' => get_post_time( DATE_W3C, true, $post ),
			'inLanguage' => sanitize_text_field( (string) get_option( 'publishrank_default_language', substr( (string) get_locale(), 0, 2 ) ) ),
				'isAccessibleForFree' => '1' !== (string) PostMeta::get( $post->ID, PostMeta::ACCESSIBLE_FOR_FREE, '1' ) ? false : true,
			'isPartOf' => array( '@id' => $site_url . '#website' ),
			'author' => array( '@id' => $author_node_id ),
			'publisher' => array( '@id' => $this->identity_id(), 'name' => $identity['name'] ),
			'mainEntityOfPage' => array( '@id' => trailingslashit( $permalink ) . '#webpage' ),
			'articleSection' => $this->article_section( $post ),
			'wordCount' => str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ),
			'copyrightYear' => (int) get_post_time( 'Y', true, $post ),
			'copyrightHolder' => array( '@id' => $this->identity_id() ),
		);
		if ( isset( $identity['logo'] ) ) { $article['publisher']['logo'] = $identity['logo']; }
		$image = get_the_post_thumbnail_url( $post, 'full' );
		if ( ! $image ) { $image = (string) PostMeta::get( $post->ID, PostMeta::OG_IMAGE, get_option( 'publishrank_seo_default_social_image', '' ) ); }
		if ( $image ) {
			$image_id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $image ) ) : 0;
			$image_meta = $image_id ? wp_get_attachment_metadata( $image_id ) : array();
			$image_node = $this->image_object( $image, trailingslashit( $permalink ) . '#primaryimage', is_array( $image_meta ) ? $image_meta : array() );
			$image_alt = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';
			if ( $image_alt ) { $image_node['caption'] = sanitize_text_field( $image_alt ); }
			$article['image'] = $image_node;
		}
			$keywords = $this->keywords( $post );
			if ( $keywords ) { $article['keywords'] = $keywords; }
			$content_tier = sanitize_text_field( (string) PostMeta::get( $post->ID, PostMeta::CONTENT_TIER, '' ) );
			if ( $content_tier ) { $article['contentTier'] = $content_tier; }
			$citations = array_filter( array_map( 'esc_url_raw', preg_split( '/\r\n|\r|\n|,/', (string) PostMeta::get( $post->ID, PostMeta::CITATIONS, '' ) ) ) );
			if ( $citations ) { $article['citation'] = array_values( $citations ); }
		$video = $this->video_object( $post, $image );
		if ( $video ) { $article['video'] = $video; }
		return $article;
	}

	/** @return array<string, mixed> A stable author node connected to the article by @id. */
	private function article_author() {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) { return array(); }
		$user = get_userdata( $post->post_author );
		$author_url = $user ? (string) get_author_posts_url( $post->post_author ) : trailingslashit( PublicationProfile::primary_url() );
		$name = PostMeta::get( $post->ID, PostMeta::AUTHOR_NAME, '' );
		if ( ! $name ) { $name = $user ? $user->display_name : __( 'Editorial staff', 'publishrank' ); }
		$node = array(
			'@type' => 'Person',
			'@id' => trailingslashit( $author_url ) . '#author',
			'name' => wp_strip_all_tags( (string) $name ),
			'url' => esc_url_raw( $author_url ),
		);
		$job_title = PostMeta::get( $post->ID, PostMeta::AUTHOR_JOB_TITLE, '' );
		$image = PostMeta::get( $post->ID, PostMeta::AUTHOR_IMAGE, '' );
		if ( $job_title ) { $node['jobTitle'] = wp_strip_all_tags( (string) $job_title ); }
		if ( $image ) { $node['image'] = $this->image_object( $image, trailingslashit( $author_url ) . '#author-image' ); }
		elseif ( $user && function_exists( 'get_avatar_url' ) ) { $node['image'] = esc_url_raw( get_avatar_url( $user->ID ) ); }
		return $node;
	}

	/** @param string $url @param string $id @param array $meta @return array<string, mixed> */
	private function image_object( $url, $id, $meta = array() ) {
		$clean = esc_url_raw( $url );
		$image = array( '@type' => 'ImageObject', '@id' => $id, 'url' => PublicationProfile::asset_url( $clean ), 'contentUrl' => PublicationProfile::asset_url( $clean ) );
		if ( ! empty( $meta['width'] ) ) { $image['width'] = absint( $meta['width'] ); }
		if ( ! empty( $meta['height'] ) ) { $image['height'] = absint( $meta['height'] ); }
		return $image;
	}

	/** @param \WP_Post $post @return string */
	private function keywords( $post ) {
		$terms = array();
		$tags = get_the_tags( $post->ID );
		if ( is_array( $tags ) ) { $terms = array_merge( $terms, wp_list_pluck( $tags, 'name' ) ); }
		$categories = get_the_category( $post->ID );
		if ( is_array( $categories ) ) { $terms = array_merge( $terms, wp_list_pluck( $categories, 'name' ) ); }
		$focus = PostMeta::get( $post->ID, PostMeta::FOCUS_KEYWORD, '' );
		if ( $focus ) { $terms[] = wp_strip_all_tags( (string) $focus ); }
		$terms = array_merge( $terms, PostMeta::get_list( $post->ID, PostMeta::SECONDARY_KEYPHRASES ), PostMeta::get_list( $post->ID, PostMeta::RELATED_TERMS ) );
		$entity_topic = PostMeta::get( $post->ID, PostMeta::ENTITY_TOPIC, '' );
		if ( $entity_topic ) { $terms[] = $entity_topic; }
		$terms = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $terms ) ) ) );
		return implode( ', ', $terms );
	}

	/** @param \WP_Post $post @param string $fallback_image @return array<string, mixed>|null */
	private function video_object( $post, $fallback_image ) {
		$thumbnail = (string) get_post_meta( $post->ID, PostMeta::VIDEO_THUMBNAIL, true );
		$duration = (string) get_post_meta( $post->ID, PostMeta::VIDEO_DURATION, true );
		preg_match( '/https?:\/\/[^\s"\'<>]+\.(?:mp4|webm|mov)(?:\?[^\s"\'<>]+)?/i', (string) $post->post_content, $matches );
		$content_url = ! empty( $matches[0] ) ? esc_url_raw( $matches[0] ) : '';
		if ( ! $content_url || ! $thumbnail ) { return null; }
		$video = array(
			'@type' => 'VideoObject',
			'@id' => trailingslashit( (string) get_permalink( $post ) ) . '#video',
			'name' => wp_strip_all_tags( (string) get_the_title( $post ) ),
			'description' => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'thumbnailUrl' => PublicationProfile::asset_url( $thumbnail ? $thumbnail : $fallback_image ),
			'uploadDate' => get_post_time( DATE_W3C, true, $post ),
			'contentUrl' => $content_url,
		);
		if ( $duration ) { $video['duration'] = sanitize_text_field( $duration ); }
		return $video;
	}

	/** @param \WP_Post $post @return string */
	private function article_section( $post ) {
		$categories = get_the_category( $post->ID );
		return ! empty( $categories ) ? (string) $categories[0]->name : __( 'News', 'publishrank' );
	}

	/** @return array<string, mixed>|null */
	private function collection_list() {
		global $wp_query;
		$posts = ( is_object( $wp_query ) && ! empty( $wp_query->posts ) && is_array( $wp_query->posts ) ) ? $wp_query->posts : array();
		if ( empty( $posts ) ) { return null; }
		$url = trailingslashit( ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical() );
		$items = array();
		foreach ( array_slice( $posts, 0, 20 ) as $index => $post ) {
			if ( ! $post instanceof \WP_Post ) { continue; }
			$items[] = array( '@type' => 'ListItem', 'position' => $index + 1, 'url' => esc_url_raw( (string) get_permalink( $post ) ), 'name' => wp_strip_all_tags( (string) get_the_title( $post ) ) );
		}
		return empty( $items ) ? null : array( '@type' => 'ItemList', '@id' => $url . '#itemlist', 'numberOfItems' => count( $items ), 'itemListElement' => $items );
	}
}
/* EOF */
