<?php
/** Central registry of every structured-data type PublishRank offers. */
namespace PublishRankSEO\Schema;

defined( 'ABSPATH' ) || exit;

final class SchemaTypes {
	/** Translate labels after init; avoid WordPress 6.7 early-translation notices. */
	private static function label( $text ) {
		return function_exists( 'did_action' ) && did_action( 'init' ) ? __( $text, 'publishrank' ) : $text;
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