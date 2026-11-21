<?php
/**
 * Dynamic field sources for Schema Studio templates.
 *
 * Administrators map a schema property to one of these sources instead of typing a value. Sources cover
 * WordPress core fields, custom post types and taxonomies, custom fields (post meta and ACF), WooCommerce
 * products, PublishRank SEO data and the Local SEO profile. Every source returns a ready-to-print value or
 * '' when nothing is available, so an empty source never produces an empty property.
 *
 * Text values may also embed sources: "{{post.title}} — {{site.name}}".
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Studio;

use PublishRankSEO\Core\PublicationProfile;

defined( 'ABSPATH' ) || exit;

final class Fields {

	/**
	 * Field registry: key => [label, group, kind]. kind: text|url|date|number|list|object|bool|ref.
	 * Pattern sources (meta:KEY, tax:TAXONOMY, acf:FIELD, pr:KEY, termmeta:KEY) are listed in patterns().
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function all() {
		$f = array(
			'post.title'            => array( __( 'Post title', 'publishrank' ), 'post', 'text' ),
			'post.seo_title'        => array( __( 'SEO title (as printed in <title>)', 'publishrank' ), 'post', 'text' ),
			'post.description'      => array( __( 'Meta description (as printed)', 'publishrank' ), 'post', 'text' ),
			'post.excerpt'          => array( __( 'Excerpt', 'publishrank' ), 'post', 'text' ),
			'post.content_text'     => array( __( 'Content as plain text (first 500 characters)', 'publishrank' ), 'post', 'text' ),
			'post.permalink'        => array( __( 'Permalink', 'publishrank' ), 'post', 'url' ),
			'post.canonical'        => array( __( 'Canonical URL', 'publishrank' ), 'post', 'url' ),
			'post.featured_image'   => array( __( 'Featured image URL', 'publishrank' ), 'post', 'url' ),
			'post.featured_image_object' => array( __( 'Featured image (ImageObject with size)', 'publishrank' ), 'post', 'object' ),
			'post.images'           => array( __( 'All images in the content', 'publishrank' ), 'post', 'list' ),
			'post.date_published'   => array( __( 'Publication date', 'publishrank' ), 'post', 'date' ),
			'post.date_modified'    => array( __( 'Last modified date', 'publishrank' ), 'post', 'date' ),
			'post.author_name'      => array( __( 'Author name', 'publishrank' ), 'post', 'text' ),
			'post.author_url'       => array( __( 'Author archive URL', 'publishrank' ), 'post', 'url' ),
			'post.author_person'    => array( __( 'Author (Person object)', 'publishrank' ), 'post', 'object' ),
			'post.primary_category' => array( __( 'Primary category name', 'publishrank' ), 'post', 'text' ),
			'post.categories'       => array( __( 'Category names', 'publishrank' ), 'post', 'list' ),
			'post.tags'             => array( __( 'Tag names', 'publishrank' ), 'post', 'list' ),
			'post.word_count'       => array( __( 'Word count', 'publishrank' ), 'post', 'number' ),
			'post.comment_count'    => array( __( 'Approved comment count', 'publishrank' ), 'post', 'number' ),
			'post.language'         => array( __( 'Content language (BCP 47)', 'publishrank' ), 'post', 'text' ),
			'post.type_label'       => array( __( 'Post type name', 'publishrank' ), 'post', 'text' ),
			'post.id'               => array( __( 'Post ID', 'publishrank' ), 'post', 'number' ),
			'term.name'             => array( __( 'Archive term name', 'publishrank' ), 'taxonomy', 'text' ),
			'term.description'      => array( __( 'Archive term description', 'publishrank' ), 'taxonomy', 'text' ),
			'term.url'              => array( __( 'Archive term URL', 'publishrank' ), 'taxonomy', 'url' ),
			'term.count'            => array( __( 'Items in the archive', 'publishrank' ), 'taxonomy', 'number' ),
			'site.name'             => array( __( 'Site name', 'publishrank' ), 'site', 'text' ),
			'site.tagline'          => array( __( 'Site tagline', 'publishrank' ), 'site', 'text' ),
			'site.url'              => array( __( 'Site URL', 'publishrank' ), 'site', 'url' ),
			'site.language'         => array( __( 'Site language (BCP 47)', 'publishrank' ), 'site', 'text' ),
			'org.name'              => array( __( 'Publisher / organization name', 'publishrank' ), 'site', 'text' ),
			'org.logo'              => array( __( 'Publisher logo URL', 'publishrank' ), 'site', 'url' ),
			'org.same_as'           => array( __( 'Publisher social profiles', 'publishrank' ), 'site', 'list' ),
			'ref.organization'      => array( __( 'Link to the site Organization / Person', 'publishrank' ), 'relationships', 'ref' ),
			'ref.website'           => array( __( 'Link to the WebSite', 'publishrank' ), 'relationships', 'ref' ),
			'ref.webpage'           => array( __( 'Link to this WebPage', 'publishrank' ), 'relationships', 'ref' ),
			'ref.article'           => array( __( 'Link to this Article', 'publishrank' ), 'relationships', 'ref' ),
			'ref.author'            => array( __( 'Link to the author Person', 'publishrank' ), 'relationships', 'ref' ),
			'ref.local_business'    => array( __( 'Link to the Local SEO business', 'publishrank' ), 'relationships', 'ref' ),
			'ref.breadcrumb'        => array( __( 'Link to the BreadcrumbList', 'publishrank' ), 'relationships', 'ref' ),
			'local.name'            => array( __( 'Business name', 'publishrank' ), 'local', 'text' ),
			'local.description'     => array( __( 'Business description', 'publishrank' ), 'local', 'text' ),
			'local.telephone'       => array( __( 'Business telephone', 'publishrank' ), 'local', 'text' ),
			'local.email'           => array( __( 'Business email', 'publishrank' ), 'local', 'text' ),
			'local.url'             => array( __( 'Business website', 'publishrank' ), 'local', 'url' ),
			'local.image'           => array( __( 'Business photo', 'publishrank' ), 'local', 'url' ),
			'local.logo'            => array( __( 'Business logo', 'publishrank' ), 'local', 'url' ),
			'local.address'         => array( __( 'Business address (PostalAddress)', 'publishrank' ), 'local', 'object' ),
			'local.geo'             => array( __( 'Coordinates (GeoCoordinates)', 'publishrank' ), 'local', 'object' ),
			'local.opening_hours'   => array( __( 'Opening hours (OpeningHoursSpecification list)', 'publishrank' ), 'local', 'list' ),
			'local.price_range'     => array( __( 'Price range', 'publishrank' ), 'local', 'text' ),
			'local.area_served'     => array( __( 'Service areas (Place list)', 'publishrank' ), 'local', 'list' ),
			'local.services'        => array( __( 'Services (OfferCatalog)', 'publishrank' ), 'local', 'object' ),
			'local.same_as'         => array( __( 'Business profiles', 'publishrank' ), 'local', 'list' ),
			'local.type'            => array( __( 'Business type', 'publishrank' ), 'local', 'text' ),
		);
		{
			$f += array(
				'woo.name'             => array( __( 'Product name', 'publishrank' ), 'woocommerce', 'text' ),
				'woo.short_description'=> array( __( 'Product short description', 'publishrank' ), 'woocommerce', 'text' ),
				'woo.sku'              => array( __( 'SKU', 'publishrank' ), 'woocommerce', 'text' ),
				'woo.gtin'             => array( __( 'GTIN / UPC / EAN / ISBN', 'publishrank' ), 'woocommerce', 'text' ),
				'woo.price'            => array( __( 'Current price', 'publishrank' ), 'woocommerce', 'number' ),
				'woo.regular_price'    => array( __( 'Regular price', 'publishrank' ), 'woocommerce', 'number' ),
				'woo.sale_price'       => array( __( 'Sale price', 'publishrank' ), 'woocommerce', 'number' ),
				'woo.currency'         => array( __( 'Store currency', 'publishrank' ), 'woocommerce', 'text' ),
				'woo.availability'     => array( __( 'Availability (schema.org URL)', 'publishrank' ), 'woocommerce', 'url' ),
				'woo.stock_quantity'   => array( __( 'Stock quantity', 'publishrank' ), 'woocommerce', 'number' ),
				'woo.image'            => array( __( 'Product image', 'publishrank' ), 'woocommerce', 'url' ),
				'woo.gallery'          => array( __( 'Product gallery images', 'publishrank' ), 'woocommerce', 'list' ),
				'woo.category'         => array( __( 'Primary product category', 'publishrank' ), 'woocommerce', 'text' ),
				'woo.brand'            => array( __( 'Brand (WooCommerce Brands)', 'publishrank' ), 'woocommerce', 'text' ),
				'woo.weight'           => array( __( 'Weight (QuantitativeValue)', 'publishrank' ), 'woocommerce', 'object' ),
				'woo.offer'            => array( __( 'Offer (price, currency, availability, URL)', 'publishrank' ), 'woocommerce', 'object' ),
				'woo.aggregate_rating' => array( __( 'Aggregate rating (only with real reviews)', 'publishrank' ), 'woocommerce', 'object' ),
				'woo.rating'           => array( __( 'Average rating', 'publishrank' ), 'woocommerce', 'number' ),
				'woo.review_count'     => array( __( 'Review count', 'publishrank' ), 'woocommerce', 'number' ),
				'woo.variation_count'  => array( __( 'Number of variations', 'publishrank' ), 'woocommerce', 'number' ),
			);
		}
		return (array) apply_filters( 'publishrank_schema_studio_fields', $f );
	}

	/** Pattern sources whose second half is chosen by the administrator. */
	public static function patterns() {
		return array(
			'meta:'     => __( 'Custom field (post meta key)', 'publishrank' ),
			'acf:'      => __( 'Advanced Custom Fields field name', 'publishrank' ),
			'tax:'      => __( 'Custom taxonomy (term names)', 'publishrank' ),
			'pr:'       => __( 'PublishRank per-post schema field', 'publishrank' ),
			'termmeta:' => __( 'Term meta key (archives)', 'publishrank' ),
		);
	}

	/** PublishRank per-post fields exposed as pr:KEY (they map to _publishrank_seo_KEY). */
	public static function publishrank_keys() {
		return array( 'event_start', 'event_location', 'event_street', 'event_locality', 'event_region', 'event_postal_code', 'event_country', 'video_url', 'video_embed_url', 'video_thumbnail', 'video_duration', 'audio_url', 'audio_duration', 'product_price', 'product_currency', 'product_availability', 'product_brand', 'product_sku', 'recipe_time', 'recipe_yield', 'review_rating', 'review_item', 'job_valid_through', 'job_employment_type', 'job_hiring_org', 'focus_keyword', 'dateline', 'content_location' );
	}

	/** Custom meta keys used on the site (for the field picker), excluding protected keys. @return array<int,string> */
	public static function known_meta_keys( $limit = 80 ) {
		global $wpdb;
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key NOT LIKE %s ORDER BY meta_key LIMIT %d", $wpdb->esc_like( '_' ) . '%', (int) $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
	}

	/** Resolve a {{token}} string. Non-string inputs pass through. */
	public static function interpolate( $text, Context $ctx ) {
		if ( ! is_string( $text ) || false === strpos( $text, '{{' ) ) {
			return $text;
		}
		return trim(
			(string) preg_replace_callback(
				'/\{\{\s*([a-z]+[:.][A-Za-z0-9_.:\-]+)\s*\}\}/',
				static function ( $m ) use ( $ctx ) {
					$v = Fields::resolve( $m[1], $ctx );
					if ( is_array( $v ) ) {
						$v = isset( $v['@id'] ) ? $v['@id'] : implode( ', ', array_filter( $v, 'is_scalar' ) );
					}
					return is_scalar( $v ) ? (string) $v : '';
				},
				$text
			)
		);
	}

	/**
	 * Resolve one source key against a context.
	 *
	 * @return mixed '' when unavailable.
	 */
	public static function resolve( $key, Context $ctx ) {
		$key = (string) $key;
		foreach ( array( 'meta:', 'acf:', 'tax:', 'pr:', 'termmeta:' ) as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return self::pattern( $prefix, substr( $key, strlen( $prefix ) ), $ctx );
			}
		}
		$dot   = strpos( $key, '.' );
		$group = false === $dot ? $key : substr( $key, 0, $dot );
		$name  = false === $dot ? '' : substr( $key, $dot + 1 );
		switch ( $group ) {
			case 'post':
				return $ctx->post ? self::post_field( $name, $ctx ) : '';
			case 'term':
				return self::term_field( $name, $ctx );
			case 'site':
			case 'org':
				return self::site_field( $group, $name, $ctx );
			case 'ref':
				$id = $ctx->id( $name );
				if ( 'local_business' === $name && ! self::local_node() ) {
					return '';
				}
				return '' !== $id ? array( '@id' => $id ) : '';
			case 'local':
				return self::local_field( $name );
			case 'woo':
				return self::woo_field( $name, $ctx );
		}
		$custom = apply_filters( 'publishrank_schema_studio_resolve_field', null, $key, $ctx );
		return null === $custom ? '' : $custom;
	}

	private static function pattern( $prefix, $name, Context $ctx ) {
		$name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name );
		if ( '' === $name ) {
			return '';
		}
		switch ( $prefix ) {
			case 'meta:':
				if ( ! $ctx->post || is_protected_meta( $name, 'post' ) ) {
					return '';
				}
				$v = get_post_meta( $ctx->post->ID, $name, true );
				return self::clean_scalar_or_list( $v );
			case 'acf:':
				if ( ! $ctx->post || ! function_exists( 'get_field' ) ) {
					return '';
				}
				$v = get_field( $name, $ctx->post->ID ); // phpcs:ignore -- ACF API.
				if ( is_array( $v ) && isset( $v['url'] ) ) {
					return (string) $v['url'];
				}
				return self::clean_scalar_or_list( $v );
			case 'tax:':
				if ( ! $ctx->post || ! taxonomy_exists( $name ) ) {
					return '';
				}
				$terms = get_the_terms( $ctx->post, $name );
				return is_array( $terms ) ? array_values( wp_list_pluck( $terms, 'name' ) ) : '';
			case 'pr:':
				if ( ! $ctx->post || ! in_array( $name, self::publishrank_keys(), true ) ) {
					return '';
				}
				return self::clean_scalar_or_list( get_post_meta( $ctx->post->ID, '_publishrank_seo_' . $name, true ) );
			case 'termmeta:':
				if ( ! $ctx->term || is_protected_meta( $name, 'term' ) ) {
					return '';
				}
				return self::clean_scalar_or_list( get_term_meta( $ctx->term->term_id, $name, true ) );
		}
		return '';
	}

	/** @param mixed $v */
	private static function clean_scalar_or_list( $v ) {
		if ( is_array( $v ) ) {
			$list = array_values( array_filter( array_map( static function ( $x ) { return is_scalar( $x ) ? trim( wp_strip_all_tags( (string) $x ) ) : ''; }, $v ), 'strlen' ) );
			return $list ? $list : '';
		}
		if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
			return $v;
		}
		return is_scalar( $v ) ? trim( wp_strip_all_tags( (string) $v ) ) : '';
	}

	private static function iso( $mysql_gmt, $mysql_local ) {
		$ts = ( $mysql_gmt && '0000-00-00 00:00:00' !== $mysql_gmt ) ? strtotime( $mysql_gmt . ' UTC' ) : strtotime( (string) $mysql_local );
		return $ts ? wp_date( 'c', $ts ) : '';
	}

	private static function post_field( $name, Context $ctx ) {
		$post = $ctx->post;
		switch ( $name ) {
			case 'title':
				return wp_strip_all_tags( get_the_title( $post ) );
			case 'seo_title':
			case 'description':
				if ( class_exists( '\\PublishRankSEO\\Meta\\SeoData' ) ) {
					$d = \PublishRankSEO\Meta\SeoData::for_post( $post );
					return isset( $d[ 'seo_title' === $name ? 'title' : 'description' ] ) ? (string) $d[ 'seo_title' === $name ? 'title' : 'description' ] : '';
				}
				return '';
			case 'excerpt':
				return has_excerpt( $post ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : '';
			case 'content_text':
				$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ) );
				return '' === $text ? '' : ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 ) );
			case 'permalink':
				return (string) get_permalink( $post );
			case 'canonical':
				return $ctx->url;
			case 'featured_image':
				$src = get_the_post_thumbnail_url( $post, 'full' );
				return $src ? (string) $src : '';
			case 'featured_image_object':
				$id = (int) get_post_thumbnail_id( $post );
				if ( ! $id ) {
					return '';
				}
				$img = wp_get_attachment_image_src( $id, 'full' );
				if ( ! $img ) {
					return '';
				}
				$o = array( '@type' => 'ImageObject', 'url' => $img[0], 'contentUrl' => $img[0] );
				if ( ! empty( $img[1] ) ) {
					$o['width']  = (int) $img[1];
					$o['height'] = (int) $img[2];
				}
				$caption = wp_get_attachment_caption( $id );
				if ( $caption ) {
					$o['caption'] = wp_strip_all_tags( $caption );
				}
				return $o;
			case 'images':
				preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->post_content, $m );
				$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $m[1] ) ) ) );
				$feat = get_the_post_thumbnail_url( $post, 'full' );
				if ( $feat ) {
					array_unshift( $urls, $feat );
				}
				$urls = array_values( array_unique( $urls ) );
				return $urls ? array_slice( $urls, 0, 10 ) : '';
			case 'date_published':
				return self::iso( $post->post_date_gmt, $post->post_date );
			case 'date_modified':
				return self::iso( $post->post_modified_gmt, $post->post_modified );
			case 'author_name':
				return (string) get_the_author_meta( 'display_name', (int) $post->post_author );
			case 'author_url':
				return (string) get_author_posts_url( (int) $post->post_author );
			case 'author_person':
				$name = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
				return '' === $name ? '' : array( '@type' => 'Person', '@id' => $ctx->id( 'author' ), 'name' => $name, 'url' => (string) get_author_posts_url( (int) $post->post_author ) );
			case 'primary_category':
				$primary = (int) get_post_meta( $post->ID, '_publishrank_seo_primary_category', true );
				$term    = $primary ? get_term( $primary ) : null;
				if ( ! $term instanceof \WP_Term ) {
					$cats = get_the_category( $post->ID );
					$term = $cats ? $cats[0] : null;
				}
				return $term instanceof \WP_Term ? $term->name : '';
			case 'categories':
				$terms = get_the_terms( $post, 'category' );
				return is_array( $terms ) ? array_values( wp_list_pluck( $terms, 'name' ) ) : '';
			case 'tags':
				$terms = get_the_terms( $post, 'post_tag' );
				return is_array( $terms ) ? array_values( wp_list_pluck( $terms, 'name' ) ) : '';
			case 'word_count':
				$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
				$n    = preg_match_all( '/[\p{L}\p{N}]+/u', $text );
				return $n ? (int) $n : '';
			case 'comment_count':
				return (int) $post->comment_count;
			case 'language':
				return self::bcp47( \PublishRankSEO\Meta\LanguageAlternates::content_language( $post ) );
			case 'type_label':
				$obj = get_post_type_object( $post->post_type );
				return $obj ? (string) $obj->labels->singular_name : '';
			case 'id':
				return (int) $post->ID;
		}
		return '';
	}

	private static function term_field( $name, Context $ctx ) {
		if ( ! $ctx->term ) {
			return '';
		}
		switch ( $name ) {
			case 'name':
				return $ctx->term->name;
			case 'description':
				return trim( wp_strip_all_tags( (string) $ctx->term->description ) );
			case 'url':
				return $ctx->url;
			case 'count':
				return (int) $ctx->term->count;
		}
		return '';
	}

	public static function bcp47( $locale ) {
		$locale = str_replace( '_', '-', (string) $locale );
		return '' === $locale ? '' : $locale;
	}

	private static function site_field( $group, $name, Context $ctx ) {
		if ( 'site' === $group ) {
			switch ( $name ) {
				case 'name':
					return PublicationProfile::site_name();
				case 'tagline':
					return (string) get_bloginfo( 'description' );
				case 'url':
					return $ctx->site;
				case 'language':
					return self::bcp47( get_locale() );
			}
			return '';
		}
		$org = PublicationProfile::organization_identity();
		switch ( $name ) {
			case 'name':
				$n = isset( $org['name'] ) ? trim( (string) $org['name'] ) : '';
				return '' !== $n ? $n : PublicationProfile::publisher_name();
			case 'logo':
				$logo = isset( $org['logo'] ) ? (string) $org['logo'] : '';
				return '' !== $logo ? $logo : (string) get_option( 'publishrank_publisher_logo', '' );
			case 'same_as':
				$p = PublicationProfile::social_profiles();
				return $p ? array_values( array_filter( (array) $p, 'is_string' ) ) : '';
		}
		return '';
	}

	/** @return array<string,mixed> The LocalBusiness node exactly as Local SEO prints it (empty when off). */
	public static function local_node() {
		static $node = null;
		if ( null === $node ) {
			$node = class_exists( '\\PublishRankSEO\\SEO\\LocalSEO' ) ? (array) \PublishRankSEO\SEO\LocalSEO::connected_node() : array();
		}
		return $node;
	}

	private static function local_field( $name ) {
		$n = self::local_node();
		if ( ! $n ) {
			return '';
		}
		$map = array(
			'name'          => 'name',
			'description'   => 'description',
			'telephone'     => 'telephone',
			'email'         => 'email',
			'url'           => 'url',
			'logo'          => 'logo',
			'address'       => 'address',
			'geo'           => 'geo',
			'opening_hours' => 'openingHoursSpecification',
			'price_range'   => 'priceRange',
			'area_served'   => 'areaServed',
			'services'      => 'hasOfferCatalog',
			'same_as'       => 'sameAs',
		);
		if ( 'image' === $name ) {
			$img = isset( $n['image'] ) ? $n['image'] : '';
			return is_array( $img ) ? (string) reset( $img ) : (string) $img;
		}
		if ( 'type' === $name ) {
			$t = $n['@type'];
			return is_array( $t ) ? (string) reset( $t ) : (string) $t;
		}
		return isset( $map[ $name ], $n[ $map[ $name ] ] ) ? $n[ $map[ $name ] ] : '';
	}

	private static function woo_field( $name, Context $ctx ) {
		if ( ! $ctx->post || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$p = wc_get_product( $ctx->post->ID );
		if ( ! $p ) {
			return '';
		}
		switch ( $name ) {
			case 'name':
				return wp_strip_all_tags( $p->get_name() );
			case 'short_description':
				return trim( wp_strip_all_tags( (string) $p->get_short_description() ) );
			case 'sku':
				return (string) $p->get_sku();
			case 'gtin':
				return method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id() : '';
			case 'price':
				$v = $p->get_price();
				return '' === (string) $v ? '' : (float) wc_format_decimal( $v, wc_get_price_decimals() );
			case 'regular_price':
				$v = $p->get_regular_price();
				return '' === (string) $v ? '' : (float) wc_format_decimal( $v, wc_get_price_decimals() );
			case 'sale_price':
				$v = $p->get_sale_price();
				return '' === (string) $v ? '' : (float) wc_format_decimal( $v, wc_get_price_decimals() );
			case 'currency':
				return (string) get_woocommerce_currency();
			case 'availability':
				$map = array( 'instock' => 'InStock', 'outofstock' => 'OutOfStock', 'onbackorder' => 'BackOrder' );
				$s   = (string) $p->get_stock_status();
				return isset( $map[ $s ] ) ? 'https://schema.org/' . $map[ $s ] : '';
			case 'stock_quantity':
				$q = $p->get_stock_quantity();
				return null === $q ? '' : (int) $q;
			case 'image':
				$id = (int) $p->get_image_id();
				return $id ? (string) wp_get_attachment_image_url( $id, 'full' ) : '';
			case 'gallery':
				$urls = array_filter( array_map( static function ( $id ) { return (string) wp_get_attachment_image_url( (int) $id, 'full' ); }, (array) $p->get_gallery_image_ids() ) );
				return $urls ? array_values( $urls ) : '';
			case 'category':
				$terms   = get_the_terms( $p->get_id(), 'product_cat' );
				$default = (int) get_option( 'default_product_cat', 0 );
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					// WooCommerce's fallback "Uncategorized" says nothing about the product.
					if ( (int) $term->term_id !== $default && 'uncategorized' !== $term->slug ) {
						return $term->name;
					}
				}
				return '';
			case 'brand':
				foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $tax ) {
					if ( taxonomy_exists( $tax ) ) {
						$terms = get_the_terms( $p->get_id(), $tax );
						if ( is_array( $terms ) && $terms ) {
							return $terms[0]->name;
						}
					}
				}
				return '';
			case 'weight':
				$w = $p->get_weight();
				if ( '' === (string) $w ) {
					return '';
				}
				$units = array( 'kg' => 'KGM', 'g' => 'GRM', 'lbs' => 'LBR', 'oz' => 'ONZ' );
				$unit  = (string) get_option( 'woocommerce_weight_unit', 'kg' );
				return array( '@type' => 'QuantitativeValue', 'value' => (float) $w, 'unitCode' => isset( $units[ $unit ] ) ? $units[ $unit ] : 'KGM' );
			case 'offer':
				$price = $p->get_price();
				if ( '' === (string) $price ) {
					return '';
				}
				$offer = array(
					'@type'         => 'Offer',
					'price'         => wc_format_decimal( $price, wc_get_price_decimals() ),
					'priceCurrency' => (string) get_woocommerce_currency(),
					'url'           => (string) get_permalink( $p->get_id() ),
				);
				$avail = self::woo_field( 'availability', $ctx );
				if ( $avail ) {
					$offer['availability'] = $avail;
				}
				if ( $p->is_on_sale() && $p->get_date_on_sale_to() ) {
					$offer['priceValidUntil'] = $p->get_date_on_sale_to()->date( 'Y-m-d' );
				}
				return $offer;
			case 'aggregate_rating':
				$count = (int) $p->get_review_count();
				if ( $count < 1 || ! wc_review_ratings_enabled() ) {
					return ''; // Ratings are only printed when real customer reviews exist.
				}
				return array( '@type' => 'AggregateRating', 'ratingValue' => (float) $p->get_average_rating(), 'reviewCount' => $count, 'bestRating' => 5, 'worstRating' => 1 );
			case 'rating':
				return (int) $p->get_review_count() > 0 ? (float) $p->get_average_rating() : '';
			case 'review_count':
				$c = (int) $p->get_review_count();
				return $c > 0 ? $c : '';
			case 'variation_count':
				return $p->is_type( 'variable' ) ? count( $p->get_children() ) : '';
		}
		return '';
	}
}
