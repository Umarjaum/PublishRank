<?php
/** Central registry of every structured-data type PublishRank offers. */
namespace PublishRankSEO\Schema;

defined( 'ABSPATH' ) || exit;

final class SchemaTypes {
	/** Translate only literal strings, and only after WordPress has initialized. */
	private static function label( $key ) {
		$labels = array(
			'News Article' => 'News Article',
			'Article' => 'Article',
			'Blog Post' => 'Blog Post',
			'Live Blog Post' => 'Live Blog Post',
			'Report' => 'Report',
			'Tech Article' => 'Tech Article',
			'Scholarly Article' => 'Scholarly Article',
			'Sponsored / Advertiser Content' => 'Sponsored / Advertiser Content',
			'Review (with rating)' => 'Review (with rating)',
			'Recipe' => 'Recipe',
			'Standard Web Page' => 'Standard Web Page',
			'About Page' => 'About Page',
			'Contact Page' => 'Contact Page',
			'FAQ Page' => 'FAQ Page',
			'Q&A Page' => 'Q&A Page',
			'Collection / Hub Page' => 'Collection / Hub Page',
			'Item Page' => 'Item Page',
			'Profile Page' => 'Profile Page',
			'Medical Web Page' => 'Medical Web Page',
			'Search Results Page' => 'Search Results Page',
			'Legal / Policy Page (Privacy, Terms…)' => 'Legal / Policy Page (Privacy, Terms…)',
			'Video' => 'Video',
			'Audio / Podcast Episode' => 'Audio / Podcast Episode',
			'Image Gallery' => 'Image Gallery',
			'Single Image' => 'Single Image',
			'Event' => 'Event',
			'Articles' => 'Articles',
			'Pages' => 'Pages',
			'Legal' => 'Legal',
			'Media & Events' => 'Media & Events',
		);
		if ( ! isset( $labels[ $key ] ) ) { return $key; }
		if ( ! function_exists( 'did_action' ) || ! did_action( 'init' ) ) { return $labels[ $key ]; }
		switch ( $key ) {
			case 'News Article': return __( 'News Article', 'publishrank' );
			case 'Article': return __( 'Article', 'publishrank' );
			case 'Blog Post': return __( 'Blog Post', 'publishrank' );
			case 'Live Blog Post': return __( 'Live Blog Post', 'publishrank' );
			case 'Report': return __( 'Report', 'publishrank' );
			case 'Tech Article': return __( 'Tech Article', 'publishrank' );
			case 'Scholarly Article': return __( 'Scholarly Article', 'publishrank' );
			case 'Sponsored / Advertiser Content': return __( 'Sponsored / Advertiser Content', 'publishrank' );
			case 'Review (with rating)': return __( 'Review (with rating)', 'publishrank' );
			case 'Recipe': return __( 'Recipe', 'publishrank' );
			case 'Standard Web Page': return __( 'Standard Web Page', 'publishrank' );
			case 'About Page': return __( 'About Page', 'publishrank' );
			case 'Contact Page': return __( 'Contact Page', 'publishrank' );
			case 'FAQ Page': return __( 'FAQ Page', 'publishrank' );
			case 'Q&A Page': return __( 'Q&A Page', 'publishrank' );
			case 'Collection / Hub Page': return __( 'Collection / Hub Page', 'publishrank' );
			case 'Item Page': return __( 'Item Page', 'publishrank' );
			case 'Profile Page': return __( 'Profile Page', 'publishrank' );
			case 'Medical Web Page': return __( 'Medical Web Page', 'publishrank' );
			case 'Search Results Page': return __( 'Search Results Page', 'publishrank' );
			case 'Legal / Policy Page (Privacy, Terms…)': return __( 'Legal / Policy Page (Privacy, Terms…)', 'publishrank' );
			case 'Video': return __( 'Video', 'publishrank' );
			case 'Audio / Podcast Episode': return __( 'Audio / Podcast Episode', 'publishrank' );
			case 'Image Gallery': return __( 'Image Gallery', 'publishrank' );
			case 'Single Image': return __( 'Single Image', 'publishrank' );
			case 'Event': return __( 'Event', 'publishrank' );
			case 'Articles': return __( 'Articles', 'publishrank' );
			case 'Pages': return __( 'Pages', 'publishrank' );
			case 'Legal': return __( 'Legal', 'publishrank' );
			case 'Media & Events': return __( 'Media & Events', 'publishrank' );
			default: return $labels[ $key ];
		}
	}

	/** Editorial/article types for posts and editorial CPTs. */
	public static function article_types() {
		return array(
			'NewsArticle'              => self::label( 'News Article' ),
			'Article'                  => self::label( 'Article' ),
			'BlogPosting'              => self::label( 'Blog Post' ),
			'LiveBlogPosting'          => self::label( 'Live Blog Post' ),
			'Report'                   => self::label( 'Report' ),
			'TechArticle'              => self::label( 'Tech Article' ),
			'ScholarlyArticle'         => self::label( 'Scholarly Article' ),
			'AdvertiserContentArticle' => self::label( 'Sponsored / Advertiser Content' ),
			'Review'                   => self::label( 'Review (with rating)' ),
			'Recipe'                   => self::label( 'Recipe' ),
		);
	}

	/** WebPage subtypes for pages and any singular document. */
	public static function page_types() {
		return array(
			'WebPage'           => self::label( 'Standard Web Page' ),
			'AboutPage'         => self::label( 'About Page' ),
			'ContactPage'       => self::label( 'Contact Page' ),
			'FAQPage'           => self::label( 'FAQ Page' ),
			'QAPage'            => self::label( 'Q&A Page' ),
			'CollectionPage'    => self::label( 'Collection / Hub Page' ),
			'ItemPage'          => self::label( 'Item Page' ),
			'ProfilePage'       => self::label( 'Profile Page' ),
			'MedicalWebPage'    => self::label( 'Medical Web Page' ),
			'SearchResultsPage' => self::label( 'Search Results Page' ),
		);
	}

	/**
	 * Legal/policy pages. Note: schema.org defines no PrivacyPolicy type; the
	 * honest mapping is WebPage, so this is an editor-friendly alias.
	 */
	public static function aliases() {
		return array( 'LegalWebPage' => 'WebPage' );
	}

	public static function legal_types() {
		return array( 'LegalWebPage' => self::label( 'Legal / Policy Page (Privacy, Terms…)' ) );
	}

	/** Media and event types. */
	public static function media_types() {
		return array(
			'VideoObject'  => self::label( 'Video' ),
			'AudioObject'  => self::label( 'Audio / Podcast Episode' ),
			'ImageGallery' => self::label( 'Image Gallery' ),
			'ImageObject'  => self::label( 'Single Image' ),
			'Event'        => self::label( 'Event' ),
		);
	}

	/** All selectable keys mapped to their real schema.org @type. */
	public static function all() {
		return array_merge( self::article_types(), self::page_types(), self::legal_types(), self::media_types() );
	}

	/** Resolve any selectable key to a real schema.org @type (empty = invalid). */
	public static function resolve( $key ) {
		$raw = trim( (string) $key );
		if ( '' === $raw ) { return ''; }
		foreach ( array_keys( self::aliases() ) as $alias ) {
			if ( 0 === strcasecmp( $alias, $raw ) ) { return self::aliases()[ $alias ]; }
		}
		foreach ( array_keys( self::all() ) as $type ) {
			if ( 0 === strcasecmp( $type, $raw ) ) { return $type; }
		}
		return '';
	}

	/** Grouped map for <optgroup> rendering. */
	public static function grouped() {
		return array(
			self::label( 'Articles' )       => self::article_types(),
			self::label( 'Pages' )          => self::page_types(),
			self::label( 'Legal' )          => self::legal_types(),
			self::label( 'Media & Events' ) => self::media_types(),
		);
	}
}