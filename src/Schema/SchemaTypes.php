<?php
/** Central registry of the structured-data types offered by PublishRank for news publishers. */
namespace PublishRankSEO\Schema;

defined( 'ABSPATH' ) || exit;
final class SchemaTypes {
	private static function label( $key ) {
		$labels = array(
			'News Article'            => 'News Article',
			'Article'                 => 'Article',
			'Live Blog Post'          => 'Live Blog Post',
			'Reportage News Article'  => 'Reportage News Article (original reporting)',
			'Analysis News Article'   => 'Analysis News Article',
			'Opinion News Article'    => 'Opinion News Article (op-ed / editorial)',
			'Report'                  => 'Report',
			'Technical Article'       => 'Technical Article',
			'Scholarly Article'       => 'Scholarly Article',
			'Sponsored Article'       => 'Sponsored Article (disclose sponsorship on-page)',
			'Background News Article' => 'Background News Article (explainer)',
			'Review News Article'     => 'Review News Article',
			'Satire News Article'     => 'Satire News Article',
			'Ask Public News Article' => 'Ask-the-Public News Article',
			'Blog Posting'            => 'Blog Posting',
			'Video'                   => 'Video',
			'Image Gallery'           => 'Image Gallery',
			'Audio'                   => 'Audio',
			'Podcast Episode'         => 'Podcast Episode',
			'Standard Web Page'       => 'Standard Web Page',
			'About Page'              => 'About Page',
			'Contact Page'            => 'Contact Page',
			'Collection Page'         => 'Collection Page',
			'FAQ Page'                => 'FAQ Page',
			'Profile Page'            => 'Profile Page',
			'Search Results Page'     => 'Search Results Page',
			'Event'                   => 'Event',
			'Product'                 => 'Product',
			'Recipe'                  => 'Recipe',
			'Review'                  => 'Review',
			'Articles'                => 'Articles',
			'Web Stories'             => 'Web Stories',
			'Web Story'               => 'Web Story (Article)',
			'Media & Images'          => 'Media & Images',
			'Events & rich results'   => 'Events & rich results',
			'Pages'                   => 'Page schema',
			'FAQs'                    => 'FAQs',
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}
	/** Editorial article schemas supported by this news-publisher plugin (all are schema.org NewsArticle/Article subtypes). */
	public static function article_types() {
		return array(
			'NewsArticle'              => self::label( 'News Article' ),
			'ReportageNewsArticle'     => self::label( 'Reportage News Article' ),
			'AnalysisNewsArticle'      => self::label( 'Analysis News Article' ),
			'OpinionNewsArticle'       => self::label( 'Opinion News Article' ),
			'Report'                   => self::label( 'Report' ),
			'TechArticle'              => self::label( 'Technical Article' ),
			'ScholarlyArticle'         => self::label( 'Scholarly Article' ),
			'AdvertiserContentArticle' => self::label( 'Sponsored Article' ),
			'BackgroundNewsArticle'    => self::label( 'Background News Article' ),
			'ReviewNewsArticle'        => self::label( 'Review News Article' ),
			'SatireNewsArticle'        => self::label( 'Satire News Article' ),
			'AskPublicNewsArticle'     => self::label( 'Ask Public News Article' ),
			'Article'                  => self::label( 'Article' ),
			'BlogPosting'              => self::label( 'Blog Posting' ),
			'LiveBlogPosting'          => self::label( 'Live Blog Post' ),
		);
	}
	/** Web Stories use valid Article markup; WebStory is an editor-only profile, not a schema.org type. */
	public static function web_story_types() {
		return array( 'WebStory' => self::label( 'Web Story' ) ); }
	/** Article types that can be a site-wide default (LiveBlogPosting needs per-post live-blog data). */
	public static function default_article_types() {
		$types = self::article_types();
		unset( $types['LiveBlogPosting'] );
		return $types;
	}
	/** Page-level (WebPage family) schemas an editor may choose for a single page. */
	public static function page_types() {
		return array(
			'WebPage'        => self::label( 'Standard Web Page' ),
			'AboutPage'      => self::label( 'About Page' ),
			'ContactPage'    => self::label( 'Contact Page' ),
			'CollectionPage' => self::label( 'Collection Page' ),
			'FAQPage'        => self::label( 'FAQ Page' ),
		);
	}
	/** Page types usable as a site-wide default (FAQPage needs per-page questions). */
	public static function page_default_types() {
		$types = self::page_types();
		unset( $types['FAQPage'] );
		return $types;
	}
	/** FAQ schema offered on posts as well as pages. */
	public static function faq_types() {
		return array( 'FAQPage' => self::label( 'FAQ Page' ) ); }
	/** Types selectable for category, tag, other taxonomy and date/post-type archive documents. */
	public static function archive_types() {
		return array(
			'CollectionPage' => self::label( 'Collection Page' ),
			'WebPage'        => self::label( 'Standard Web Page' ),
		);
	}
	/** Types selectable for author pages. */
	public static function author_types() {
		return array(
			'ProfilePage'    => self::label( 'Profile Page' ),
			'CollectionPage' => self::label( 'Collection Page' ),
			'WebPage'        => self::label( 'Standard Web Page' ),
		);
	}
	/** Types selectable for the internal search results document. */
	public static function search_types() {
		return array(
			'SearchResultsPage' => self::label( 'Search Results Page' ),
			'CollectionPage'    => self::label( 'Collection Page' ),
			'WebPage'           => self::label( 'Standard Web Page' ),
		);
	}
	public static function aliases() {
		return array(); }
	public static function legal_types() {
		return array(); }
	/** Media schemas supported by this news-publisher plugin. */
	public static function media_types() {
		return array(
			'VideoObject'    => self::label( 'Video' ),
			'ImageGallery'   => self::label( 'Image Gallery' ),
			'AudioObject'    => self::label( 'Audio' ),
			'PodcastEpisode' => self::label( 'Podcast Episode' ),
		);
	}
	/** Rich entity types that require type-specific details before output. */
	public static function rich_entity_types() {
		return array(
			'Event'   => self::label( 'Event' ),
			'Product' => self::label( 'Product' ),
			'Recipe'  => self::label( 'Recipe' ),
			'Review'  => self::label( 'Review' ),
		);
	}
	public static function all() {
		return array_merge( self::article_types(), self::web_story_types(), self::page_types(), self::media_types(), self::rich_entity_types(), self::archive_types(), self::author_types(), self::search_types() );
	}
	public static function resolve( $key ) {
		$raw = trim( (string) $key );
		if ( '' === $raw ) {
			return ''; }
		foreach ( array_keys( self::all() ) as $type ) {
			if ( 0 === strcasecmp( $type, $raw ) ) {
				return $type; }
		}
		return '';
	}
	/** Types an editor may store on a single post or page. */
	public static function selectable() {
		return array_merge( self::article_types(), self::web_story_types(), self::page_types(), self::media_types(), self::rich_entity_types() );
	}
	/** Resolve $key only when it belongs to $allowed (an associative type => label map); otherwise return $fallback. */
	public static function resolve_in( $key, array $allowed, $fallback = '' ) {
		$resolved = self::resolve( $key );
		return ( '' !== $resolved && array_key_exists( $resolved, $allowed ) ) ? $resolved : $fallback;
	}
	/** Grouped map for the editor. Foundational WebPage is automatic unless a page picks one. */
	public static function grouped() {
		return array(
			self::label( 'Articles' )              => self::article_types(),
			self::label( 'Web Stories' )           => self::web_story_types(),
			self::label( 'Media & Images' )        => self::media_types(),
			self::label( 'Events & rich results' ) => self::rich_entity_types(),
		);
	}
}
