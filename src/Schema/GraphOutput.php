<?php
/** Emits one connected JSON-LD graph for the current document. */
// Primary article output is restricted to the centrally registered article family; other selections use their own builders.
namespace PublishRankSEO\Schema;

use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Core\OutputSettings;
use PublishRankSEO\Core\PublicationProfile;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\SEO\BreadcrumbEngine;
use PublishRankSEO\SEO\NewsroomSignals;

defined( 'ABSPATH' ) || exit;

final class GraphOutput {
	/** @var OutputRegistry */
	private $registry;

	public function __construct( OutputRegistry $registry ) {
		$this->registry = $registry; }
	public function register() {
		add_action( 'wp_head', array( $this, 'render' ), 20 ); }

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
			// Never fail silently: leave a visible hint in the page source so a missing graph can be diagnosed.
			echo '<!-- PublishRank structured data skipped: ' . esc_html( str_replace( '--', '- -', substr( $e->getMessage(), 0, 160 ) ) ) . " -->\n";
		}
	}

	/** Render one connected graph when PublishRank owns the JSON-LD slot. */
	private function render_output() {
		if ( is_admin() || wp_doing_ajax() || ! OutputSettings::schema() || ! $this->registry->claim( 'jsonld', 'graph-output' ) ) {
			return; }
		$identity = $this->identity_entity();
		$graph    = array( $identity, $this->website(), $this->webpage() );
		$list     = null;
		if ( $this->is_editorial_article() ) {
			$graph[] = $this->article();
			$graph[] = $this->article_author();
		}
		if ( ! is_singular() && ! is_404() ) {
			$list = $this->collection_list();
			if ( $list ) {
				$graph[] = $list;
				// An author's ProfilePage is about the Person; every other listing is about its item list.
				if ( ! is_author() || 'ProfilePage' !== ( $graph[2]['@type'] ?? '' ) ) {
					$graph[2]['mainEntity'] = array( '@id' => trailingslashit( ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical() ) . '#itemlist' );
				}
			}
		}
		$graph = apply_filters( 'publishrank_schema_graph', $graph, $this );
		if ( ! is_array( $graph ) ) {
			$graph = array(); }
		$graph = array_values( array_filter( $graph, 'is_array' ) );
		$crumbs_emitted = false;
		if ( BreadcrumbEngine::schema_enabled() && BreadcrumbEngine::context_supported() && '' === BreadcrumbEngine::external_schema_owner() ) {
			// The BreadcrumbList is built from the same items as the visible trail. A list with fewer than
			// two real items is not useful (or valid) markup, so it is only emitted when there is a trail.
			$crumbs = ( new BreadcrumbEngine() )->schema();
			if ( ! empty( $crumbs['itemListElement'] ) && count( $crumbs['itemListElement'] ) >= 2 ) {
				$graph[]        = $crumbs;
				$crumbs_emitted = true;
				foreach ( $graph as $graph_index => $graph_node ) {
					if ( isset( $graph_node['@id'] ) && '#webpage' === substr( (string) $graph_node['@id'], -8 ) ) {
						$graph[ $graph_index ]['breadcrumb'] = array( '@id' => $crumbs['@id'] );
						break;
					}
				}
			}
		}
		if ( ! $crumbs_emitted ) {
			// Never reference a BreadcrumbList that is not output (switched off, no trail, or owned by another plugin).
			foreach ( $graph as $graph_index => $graph_node ) {
				if ( isset( $graph_node['@id'], $graph_node['breadcrumb'] ) && '#webpage' === substr( (string) $graph_node['@id'], -8 ) ) {
					unset( $graph[ $graph_index ]['breadcrumb'] );
				}
			}
		}
		foreach ( ( new SchemaBuilder() )->extra_nodes() as $node ) {
			$graph[] = $node; }
		$graph = self::dedupe_graph( $graph );
		// "Organization and website schema" is a separate switch: the identity and WebSite nodes are removed here, after
		// every filter and extra node has been merged, together with any reference that would otherwise point at them.
		$identity_on = OutputSettings::org_website_schema();
		if ( ! $identity_on ) {
			$graph = self::without_identity_nodes( $graph );
		}
		if ( empty( $graph ) ) {
			return; }
		$entities = $identity_on ? ( PublicationProfile::identity_is_person() ? 'person,website,webpage' : 'organization,website,webpage' ) : 'webpage';
		if ( $this->is_editorial_article() ) {
			$entities .= ',article'; }
		if ( $list ) {
			$entities .= ',itemlist'; }
		echo '<!-- SEO Powered by PublishRank: structured data begin; entities=' . esc_attr( $entities ) . " -->\n";
		echo '<script type="application/ld+json">';
		echo wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		echo '</script>' . "\n";
		echo "<!-- end of SEO data Powered by PublishRank -->\n";
	}

	/**
	 * Remove the Organization/Person and WebSite nodes of the primary identity and every { "@id": ... } reference to them.
	 * Other nodes (WebPage, Article, BreadcrumbList ...) stay valid because optional links are simply dropped.
	 *
	 * @param array<int,array<string,mixed>> $graph Graph nodes.
	 * @return array<int,array<string,mixed>>
	 */
	public static function without_identity_nodes( array $graph ) {
		$base    = trailingslashit( PublicationProfile::primary_url() );
		$removed = array( $base . '#organization', $base . '#person', $base . '#website' );
		$kept    = array();
		foreach ( $graph as $node ) {
			if ( is_array( $node ) && isset( $node['@id'] ) && in_array( (string) $node['@id'], $removed, true ) ) {
				continue; }
			$kept[] = self::strip_references( $node, $removed );
		}
		return $kept;
	}

	/** @param mixed $value Node or property value. @param string[] $ids Ids to drop. @return mixed */
	private static function strip_references( $value, array $ids ) {
		if ( ! is_array( $value ) ) {
			return $value; }
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		$out     = array();
		foreach ( $value as $key => $child ) {
			if ( is_array( $child ) && 1 === count( $child ) && isset( $child['@id'] ) && in_array( (string) $child['@id'], $ids, true ) ) {
				continue; // A pure reference to a removed node.
			}
			$child = self::strip_references( $child, $ids );
			if ( $is_list ) {
				$out[] = $child; } else {
				$out[ $key ] = $child; }
		}
		return $out;
	}

	/** @return array<string, mixed> */
	private function identity_entity() {
		return PublicationProfile::identity_is_person() ? $this->person() : $this->organization();
	}
	/** @return array<string, mixed> */
	/** @return array<string, mixed> */
	private function organization() {
		$url  = trailingslashit( PublicationProfile::primary_url() );
		$org  = PublicationProfile::organization_identity();
		$type = sanitize_text_field( (string) get_option( 'publishrank_seo_publisher_type', 'NewsMediaOrganization' ) );
		$type = in_array( $type, array( 'NewsMediaOrganization', 'Organization' ), true ) ? $type : 'NewsMediaOrganization';

		$logo_url = $org['logo'] ? esc_url_raw( $org['logo'] ) : '';
		if ( ! $logo_url ) {
			$theme_logo = get_theme_mod( 'custom_logo' );
			$logo_url   = $theme_logo ? wp_get_attachment_image_url( (int) $theme_logo, 'full' ) : '';
		}
		if ( ! $logo_url && function_exists( 'get_site_icon_url' ) ) {
			$logo_url = get_site_icon_url( 512 ); }
		if ( ! $logo_url && is_singular() ) {
			$current = get_queried_object();
			if ( $current instanceof \WP_Post ) {
				$logo_url = (string) PostMeta::get( $current->ID, PostMeta::WEB_STORY_PUBLISHER_LOGO, '' ); }
		}

		$country      = strtoupper( sanitize_text_field( $org['country'] ) );
		$organization = array(
			'@type' => $type,
			'@id'   => $url . '#organization',
			'name'  => $org['name'] ? sanitize_text_field( $org['name'] ) : PublicationProfile::publisher_name(),
			'url'   => $url,
		);
		if ( $org['legal_name'] ) {
			$organization['legalName'] = sanitize_text_field( $org['legal_name'] ); }
		if ( ! empty( $org['alternate_names'] ) ) {
			$organization['alternateName'] = $org['alternate_names'];
		} elseif ( $org['alternate_name'] ) {
			$organization['alternateName'] = sanitize_text_field( $org['alternate_name'] ); }
		if ( $org['description'] ) {
			$organization['description'] = wp_strip_all_tags( $org['description'] ); }
		if ( $org['founding_date'] && preg_match( '/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/', $org['founding_date'] ) ) {
			$organization['foundingDate'] = $org['founding_date'];
		}
		if ( $org['contact_email'] || $org['contact_phone'] ) {
			$organization['contactPoint'] = array(
				'@type'       => 'ContactPoint',
				'contactType' => 'customer support',
			);
			if ( $org['contact_email'] ) {
				$organization['contactPoint']['email'] = sanitize_email( $org['contact_email'] ); }
			if ( $org['contact_phone'] ) {
				$organization['contactPoint']['telephone'] = sanitize_text_field( $org['contact_phone'] ); }
		}
		if ( $org['street'] || $org['locality'] || $org['region'] || $org['postal_code'] || $country ) {
			$organization['address'] = array( '@type' => 'PostalAddress' );
			if ( $org['street'] ) {
				$organization['address']['streetAddress'] = sanitize_text_field( $org['street'] ); }
			if ( $org['locality'] ) {
				$organization['address']['addressLocality'] = sanitize_text_field( $org['locality'] ); }
			if ( $org['region'] ) {
				$organization['address']['addressRegion'] = sanitize_text_field( $org['region'] ); }
			if ( $org['postal_code'] ) {
				$organization['address']['postalCode'] = sanitize_text_field( $org['postal_code'] ); }
			if ( $country ) {
				$organization['address']['addressCountry'] = $country; }
		}
		if ( $country ) {
			$organization['areaServed'] = array(
				'@type' => 'Country',
				'name'  => $country,
			); }
		if ( $logo_url ) {
			$organization['logo'] = $this->image_object( $logo_url, $url . '#publisher-logo' ); }
		if ( ! empty( $org['parent_organization'] ) ) {
			$organization['parentOrganization'] = array(
				'@type' => 'Organization',
				'name'  => sanitize_text_field( $org['parent_organization'] ),
			); }
		if ( ! empty( $org['owner_name'] ) ) {
			$owner = array(
				'@type' => 'Organization',
				'name'  => sanitize_text_field( $org['owner_name'] ),
			);
			if ( ! empty( $org['owner_url'] ) ) {
				$owner['url'] = esc_url_raw( $org['owner_url'] );
			} if ( ! empty( $org['owner_logo'] ) ) {
				$owner['logo'] = $this->image_object( $org['owner_logo'], $url . '#owner-logo' );
			} $organization['owner'] = $owner; }
		if ( ! empty( $org['copyright_holder'] ) ) {
			$organization['copyrightHolder'] = array(
				'@type' => 'Organization',
				'name'  => sanitize_text_field( $org['copyright_holder'] ),
			); }
		if ( ! empty( $org['founder'] ) ) {
			$organization['founder'] = array(
				'@type' => 'Person',
				'name'  => sanitize_text_field( $org['founder'] ),
			); }
		if ( ! empty( $org['founding_location'] ) ) {
			$organization['foundingLocation'] = array(
				'@type' => 'Place',
				'name'  => sanitize_text_field( $org['founding_location'] ),
			); }
		if ( ! empty( $org['knows_about'] ) ) {
			$topics = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\s*,\s*/', (string) $org['knows_about'] ) ) ) );
			if ( $topics ) {
				$organization['knowsAbout'] = $topics; }
		}
		$social = PublicationProfile::social_profiles();
		if ( ! empty( $social ) ) {
			$organization['sameAs'] = $social; }
		return $organization;
	}

	/** Stable @id for the configured publisher identity. */
	private function identity_id() {
		return trailingslashit( PublicationProfile::primary_url() ) . ( PublicationProfile::identity_is_person() ? '#person' : '#organization' ); }

	/** @return array<string, mixed> */
	private function person() {
		$url    = trailingslashit( PublicationProfile::primary_url() );
		$person = PublicationProfile::person_identity();
		$node   = array(
			'@type' => 'Person',
			'@id'   => $url . '#person',
			'name'  => $person['name'] ? sanitize_text_field( $person['name'] ) : PublicationProfile::publisher_name(),
			'url'   => $person['url'] ? esc_url_raw( $person['url'] ) : $url,
		);
		if ( $person['alternate_name'] ) {
			$node['alternateName'] = sanitize_text_field( $person['alternate_name'] ); }
		if ( $person['description'] ) {
			$node['description'] = wp_strip_all_tags( $person['description'] ); }
		if ( $person['job_title'] ) {
			$node['jobTitle'] = sanitize_text_field( $person['job_title'] ); }
		if ( $person['email'] ) {
			$node['email'] = sanitize_email( $person['email'] ); }
		if ( $person['image'] ) {
			$node['image'] = $this->image_object( $person['image'], $url . '#person-image' ); }
		$social = PublicationProfile::social_profiles();
		if ( ! empty( $social ) ) {
			$node['sameAs'] = $social; }
		return $node;
	}
	/** @return array<string, mixed> */
	private function website() {
		$url = trailingslashit( PublicationProfile::primary_url() );
		return array(
			'@type'           => 'WebSite',
			'@id'             => $url . '#website',
			'url'             => $url,
			'name'            => PublicationProfile::publication_name(),
			'description'     => wp_strip_all_tags( (string) \PublishRankSEO\Core\PublicationProfile::publication_description() ),
			'publisher'       => array( '@id' => $this->identity_id() ),
			'inLanguage'      => sanitize_text_field( (string) get_option( 'publishrank_default_locale', get_bloginfo( 'language' ) ) ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => $url . '?s={search_term_string}',
				),
				'query-input' => 'required name=search_term_string',
			),
		);
	}

	/** @return array<string, mixed> */
	private function webpage() {
		$canonical = ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical();
		$url       = trailingslashit( $canonical );
		$page      = array(
			'@type'       => ( new SchemaBuilder() )->current_webpage_type(),
			'@id'         => $url . '#webpage',
			'url'         => $canonical,
			'name'        => wp_get_document_title(),
			'description' => ( new \PublishRankSEO\Meta\MetaGenerator() )->description(),
			'inLanguage'  => sanitize_text_field( (string) get_option( 'publishrank_default_locale', get_bloginfo( 'language' ) ) ),
			'isPartOf'    => array( '@id' => trailingslashit( PublicationProfile::primary_url() ) . '#website' ),
			'about'       => array( '@id' => $this->identity_id() ),
		);
		if ( is_author() && 'ProfilePage' === $page['@type'] ) {
			$page['mainEntity'] = array( '@id' => trailingslashit( get_author_posts_url( (int) get_queried_object_id() ) ) . '#author' );
		}
		if ( is_singular() ) {
			$post                  = get_queried_object();
			$page['datePublished'] = get_post_time( DATE_W3C, true, $post );
			$page['dateModified']  = get_post_modified_time( DATE_W3C, true, $post );
			$page['breadcrumb']    = array( '@id' => $url . '#breadcrumb' );
			if ( $this->is_editorial_article() ) {
				$page['mainEntity'] = array( '@id' => $url . '#article' ); }
			if ( 'FAQPage' === $page['@type'] && $post instanceof \WP_Post ) {
				$faq = ( new SchemaBuilder() )->faq_entities( $post );
				if ( $faq ) {
					$page['mainEntity'] = $faq;
				} else {
					$page['@type'] = 'WebPage'; }
			}
			$found = $post instanceof \WP_Post ? \PublishRankSEO\Meta\SeoData::image_for_post( $post ) : null;
			$image = $found ? (string) $found['url'] : (string) get_option( 'publishrank_seo_default_social_image', '' );
			if ( $image ) {
				$image_id                   = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $image ) ) : 0;
				$image_meta                 = $image_id ? wp_get_attachment_metadata( $image_id ) : array();
				$page['primaryImageOfPage'] = $this->image_object( $image, $url . '#primaryimage', is_array( $image_meta ) ? $image_meta : array() );
			}
		}
		return $page;
	}

	/** @return array<string, mixed> */
	private function article() {
		$post             = get_queried_object();
		$permalink        = esc_url_raw( (string) get_permalink( $post ) );
		$site_url         = trailingslashit( PublicationProfile::primary_url() );
		$author           = $post instanceof \WP_Post ? get_userdata( $post->post_author ) : false;
		$author_url       = $author ? (string) get_author_posts_url( $post->post_author ) : $site_url;
			$default_type = SchemaTypes::resolve_in( get_option( 'publishrank_schema_default_post_type', 'NewsArticle' ), SchemaTypes::default_article_types(), 'NewsArticle' );
			$schema_type  = SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, $default_type ) );
			// Older releases stored broad values such as Report or Review. Never
			// pass those values through to JSON-LD: only the news-publisher article
			// allow-list (SchemaTypes::article_types) may be emitted for the article node.
		if ( ! array_key_exists( $schema_type, SchemaTypes::article_types() ) && 'WebStory' !== $schema_type ) {
			$schema_type = $default_type; }
		if ( 'WebStory' === $schema_type || $this->is_web_story_post( $post ) ) {
			$schema_type = 'Article'; }
			$schema_type = LiveBlogSchema::is_live_blog( $post ) ? 'LiveBlogPosting' : $schema_type;
			$seo             = \PublishRankSEO\Meta\SeoData::for_post( $post );
			$description     = isset( $seo['description'] ) ? (string) $seo['description'] : '';
			$description     = wp_strip_all_tags( $description );
		$author_node_id  = trailingslashit( $author_url ) . '#author';
		$identity        = $this->identity_entity();
		$article         = array(
			'@type'               => $schema_type,
			'@id'                 => trailingslashit( $permalink ) . '#article',
			'url'                 => $permalink,
			'headline'            => wp_strip_all_tags( (string) get_the_title( $post ) ),
			'description'         => $description,
			'datePublished'       => get_post_time( DATE_W3C, true, $post ),
			'dateModified'        => get_post_modified_time( DATE_W3C, true, $post ),
			'dateCreated'         => get_post_time( DATE_W3C, true, $post ),
			'inLanguage'          => sanitize_text_field( (string) get_option( 'publishrank_default_language', substr( (string) get_locale(), 0, 2 ) ) ),
			'isAccessibleForFree' => '1' !== (string) PostMeta::get( $post->ID, PostMeta::ACCESSIBLE_FOR_FREE, '1' ) ? false : true,
			'isPartOf'            => array( '@id' => $site_url . '#website' ),
			'author'              => array( '@id' => $author_node_id ),
			// Keep publisher data in the single shared identity node. Repeating name/logo here
			// allowed publication and organization settings to drift into conflicting values.
			'publisher'           => array( '@id' => $this->identity_id() ),
			'mainEntityOfPage'    => array( '@id' => trailingslashit( $permalink ) . '#webpage' ),
			'articleSection'      => $this->article_section( $post ),
			'genre'               => NewsroomSignals::genre( $post ),
			'articleBody'         => wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 500, '' ),
			'wordCount'           => str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ),
			'copyrightYear'       => (int) get_post_time( 'Y', true, $post ),
			'copyrightHolder'     => array( '@id' => $this->identity_id() ),
		);
		$copyright_notice = trim( sanitize_textarea_field( (string) get_option( 'publishrank_copyright', '' ) ) );
		if ( '' !== $copyright_notice ) {
			$uses_current_year = false !== strpos( $copyright_notice, '%current_year%' );
			if ( $uses_current_year ) {
				$current_year = wp_date( 'Y' );
				$copyright_notice = str_replace( '%current_year%', $current_year, $copyright_notice );
				$article['copyrightYear'] = (int) $current_year;
			}
			$article['copyrightNotice'] = $copyright_notice;
		}
		$found        = \PublishRankSEO\Meta\SeoData::image_for_post( $post );
		$story_poster = $this->is_web_story_post( $post ) ? (string) PostMeta::get( $post->ID, PostMeta::WEB_STORY_POSTER, '' ) : '';
		$image        = $story_poster ? $story_poster : ( $found ? (string) $found['url'] : (string) get_option( 'publishrank_seo_default_social_image', '' ) );
		if ( $image ) {
			$image_id   = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $image ) ) : 0;
			$image_meta = $image_id ? wp_get_attachment_metadata( $image_id ) : array();
			$image_node = $this->image_object( $image, trailingslashit( $permalink ) . '#primaryimage', is_array( $image_meta ) ? $image_meta : array() );
			$image_alt  = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';
			if ( $image_alt ) {
				$image_node['caption'] = sanitize_text_field( $image_alt ); }
			$article['image'] = $image_node;
		}
			$keywords = $this->keywords( $post );
		if ( $keywords ) {
			$article['keywords'] = $keywords; }
			$citations = array_filter( array_map( 'esc_url_raw', preg_split( '/\r\n|\r|\n|,/', (string) PostMeta::get( $post->ID, PostMeta::CITATIONS, '' ) ) ) );
		if ( $citations ) {
			$article['citation'] = array_values( $citations ); }
		$video = $this->video_object( $post, $image );
		if ( $video ) {
			$article['video'] = $video; }
			$dateline = sanitize_text_field( (string) get_post_meta( $post->ID, '_publishrank_seo_dateline', true ) );
		if ( $dateline ) {
			$article['dateline'] = $dateline; }
			$location = sanitize_text_field( (string) get_post_meta( $post->ID, '_publishrank_seo_location_created', true ) );
		if ( $location ) {
			$article['locationCreated'] = array(
				'@type' => 'Place',
				'name'  => $location,
			); }
		if ( 'LiveBlogPosting' === $schema_type ) {
			$article = LiveBlogSchema::decorate( $article, $post ); }
			// Speakable is opt-in: selectors depend on the theme, so none are invented. Return CSS selectors from this filter to enable it.
			$speakable = array_values( array_filter( array_map( 'sanitize_text_field', (array) apply_filters( 'publishrank_schema_speakable_selectors', array(), $post ) ) ) );
		if ( $speakable ) {
			$article['speakable'] = array(
				'@type'       => 'SpeakableSpecification',
				'cssSelector' => $speakable,
			); }
			return $article;
	}

	/** @return array<string, mixed> A stable author node connected to the article by @id. */
	private function article_author() {
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return array(); }
		$user       = get_userdata( $post->post_author );
		$author_url = $user ? (string) get_author_posts_url( $post->post_author ) : trailingslashit( PublicationProfile::primary_url() );
		$name       = PostMeta::get( $post->ID, PostMeta::AUTHOR_NAME, '' );
		if ( ! $name ) {
			$name = $user ? $user->display_name : __( 'Editorial staff', 'publishrank' ); }
		$node      = array(
			'@type' => 'Person',
			'@id'   => trailingslashit( $author_url ) . '#author',
			'name'  => wp_strip_all_tags( (string) $name ),
			'url'   => esc_url_raw( $author_url ),
		);
		$job_title = PostMeta::get( $post->ID, PostMeta::AUTHOR_JOB_TITLE, '' );
		$image     = PostMeta::get( $post->ID, PostMeta::AUTHOR_IMAGE, '' );
		if ( $job_title ) {
			$node['jobTitle'] = wp_strip_all_tags( (string) $job_title ); }
		if ( $user ) {
			$expertise = SchemaBuilder::author_expertise( $user->ID );
			if ( $expertise ) {
				$node['knowsAbout'] = $expertise; }
		}
		if ( $image ) {
			$node['image'] = $this->image_object( $image, trailingslashit( $author_url ) . '#author-image' ); } elseif ( $user && function_exists( 'get_avatar_url' ) ) {
			$node['image'] = esc_url_raw( get_avatar_url( $user->ID ) ); }
			return $node;
	}

	/** @param string $url @param string $id @param array $meta @return array<string, mixed> */
	private function image_object( $url, $id, $meta = array() ) {
		$clean = esc_url_raw( $url );
		$image = array(
			'@type'      => 'ImageObject',
			'@id'        => $id,
			'url'        => PublicationProfile::asset_url( $clean ),
			'contentUrl' => PublicationProfile::asset_url( $clean ),
		);
		if ( ! empty( $meta['width'] ) ) {
			$image['width'] = absint( $meta['width'] ); }
		if ( ! empty( $meta['height'] ) ) {
			$image['height'] = absint( $meta['height'] ); }
		$path     = (string) wp_parse_url( $clean, PHP_URL_PATH );
		$filetype = wp_check_filetype( $path );
		$mime     = ! empty( $filetype['type'] ) ? sanitize_text_field( $filetype['type'] ) : '';
		if ( $mime ) {
			$image['encodingFormat'] = $mime; }
		return $image;
	}

	/** @param \WP_Post $post @return string */
	private function keywords( $post ) {
		$terms = array();
		$tags  = get_the_tags( $post->ID );
		if ( is_array( $tags ) ) {
			$terms = array_merge( $terms, wp_list_pluck( $tags, 'name' ) ); }
		$categories = get_the_category( $post->ID );
		if ( is_array( $categories ) ) {
			$terms = array_merge( $terms, wp_list_pluck( $categories, 'name' ) ); }
		$focus = PostMeta::get( $post->ID, PostMeta::FOCUS_KEYWORD, '' );
		if ( $focus ) {
			$terms[] = wp_strip_all_tags( (string) $focus ); }
		$terms        = array_merge( $terms, PostMeta::get_list( $post->ID, PostMeta::SECONDARY_KEYPHRASES ), PostMeta::get_list( $post->ID, PostMeta::RELATED_TERMS ) );
		$entity_topic = PostMeta::get( $post->ID, PostMeta::ENTITY_TOPIC, '' );
		if ( $entity_topic ) {
			$terms[] = $entity_topic; }
		$terms = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $terms ) ) ) );
		return implode( ', ', $terms );
	}

	/** @param \WP_Post $post @param string $fallback_image @return array<string, mixed>|null */
	private function video_object( $post, $fallback_image ) {
		$thumbnail = (string) get_post_meta( $post->ID, PostMeta::VIDEO_THUMBNAIL, true );
		$duration  = (string) get_post_meta( $post->ID, PostMeta::VIDEO_DURATION, true );
		preg_match( '/https?:\/\/[^\s"\'<>]+\.(?:mp4|webm|mov)(?:\?[^\s"\'<>]+)?/i', (string) $post->post_content, $matches );
		$content_url = ! empty( $matches[0] ) ? esc_url_raw( $matches[0] ) : '';
		if ( ! $content_url || ! $thumbnail ) {
			return null; }
		$video = array(
			'@type'        => 'VideoObject',
			'@id'          => trailingslashit( (string) get_permalink( $post ) ) . '#video',
			'name'         => wp_strip_all_tags( (string) get_the_title( $post ) ),
			'description'  => ( function ( $seo ) {
				return isset( $seo['description'] ) ? (string) $seo['description'] : ''; } )( \PublishRankSEO\Meta\SeoData::for_post( $post ) ),
			'thumbnailUrl' => PublicationProfile::asset_url( $thumbnail ? $thumbnail : $fallback_image ),
			'uploadDate'   => get_post_time( DATE_W3C, true, $post ),
			'contentUrl'   => $content_url,
		);
		if ( $duration ) {
			$video['duration'] = sanitize_text_field( $duration ); }
		return $video;
	}

	/** @param \WP_Post $post @return string */
	private function article_section( $post ) {
		$categories = get_the_category( $post->ID );
		return ! empty( $categories ) ? (string) $categories[0]->name : __( 'News', 'publishrank' );
	}

	private function is_editorial_article() {
		$post = get_queried_object();
		$type = $post instanceof \WP_Post ? get_post_type_object( $post->post_type ) : false;
		if ( ! is_singular() || ! $post instanceof \WP_Post || ! $type || ! $type->public ) {
			return false; }
		if ( 'attachment' === $post->post_type ) {
			return false; }
		$chosen = SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' ) );
		if ( '' !== $chosen && ! array_key_exists( $chosen, SchemaTypes::article_types() ) && 'WebStory' !== $chosen ) {
			return false; }
		if ( 'page' === $post->post_type ) {
			// Pages are WebPage nodes unless the editor explicitly chose an article schema type for this page.
			return '' !== $chosen && array_key_exists( $chosen, SchemaTypes::article_types() );
		}
		return (bool) $type->publicly_queryable;
	}
	/** Detect the Web Stories editor profile or the common Web Stories plugin post types. */
	private function is_web_story_post( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false; }
		$chosen = SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' ) );
		return 'WebStory' === $chosen || in_array( $post->post_type, array( 'web-story', 'web_stories', 'web-story-page' ), true );
	}

	/** @return array<string, mixed>|null */
	private function collection_list() {
		global $wp_query;
		$posts = ( is_object( $wp_query ) && ! empty( $wp_query->posts ) && is_array( $wp_query->posts ) ) ? $wp_query->posts : array();
		if ( empty( $posts ) ) {
			return null; }
		$url   = trailingslashit( ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical() );
		$items = array();
		foreach ( array_slice( $posts, 0, 20 ) as $index => $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue; }
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'url'      => esc_url_raw( (string) get_permalink( $post ) ),
				'name'     => wp_strip_all_tags( (string) get_the_title( $post ) ),
			);
		}
		return empty( $items ) ? null : array(
			'@type'           => 'ItemList',
			'@id'             => $url . '#itemlist',
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);
	}

	/**
	 * Collapse duplicate nodes before output: nodes sharing an @id are merged (first value wins), when several supported article-type nodes describe the same URL only one primary
	 * entity is kept (LiveBlogPosting is preferred over NewsArticle/Article).
	 *
	 * @param array<int,array<string,mixed>> $graph
	 * @return array<int,array<string,mixed>>
	 */
	public static function dedupe_graph( array $graph ) {
		$article_types = array_keys( SchemaTypes::article_types() );
		$type_of       = static function ( $node ) {
			$t = $node['@type'] ?? '';
			return is_array( $t ) ? (string) reset( $t ) : (string) $t;
		};
		$by_id         = array();
		$out           = array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue; }
			$id = isset( $node['@id'] ) ? (string) $node['@id'] : '';
			if ( '' !== $id && isset( $by_id[ $id ] ) ) {
				$out[ $by_id[ $id ] ] += $node;
				continue; }
			if ( '' !== $id ) {
				$by_id[ $id ] = count( $out ); }
			$out[] = $node;
		}
		$primary_for = array();
		$drop        = array();
		foreach ( $out as $i => $node ) {
			$type = $type_of( $node );
			if ( in_array( $type, $article_types, true ) ) {
				$key = (string) ( $node['mainEntityOfPage']['@id'] ?? $node['url'] ?? '' );
				if ( '' === $key ) {
					continue; }
				if ( isset( $primary_for[ $key ] ) ) {
					$keep = $primary_for[ $key ];
					if ( 'LiveBlogPosting' === $type && 'LiveBlogPosting' !== $type_of( $out[ $keep ] ) ) {
						$drop[ $keep ]       = true;
						$primary_for[ $key ] = $i;
					} else {
						$drop[ $i ] = true; }
				} else {
					$primary_for[ $key ] = $i; }
			}
		}
		$result = array();
		foreach ( $out as $i => $node ) {
			if ( empty( $drop[ $i ] ) ) {
				$result[] = $node; }
		}
		return $result;
	}
}
/* EOF */
