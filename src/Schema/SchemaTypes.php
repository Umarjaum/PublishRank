<?php
/** Central registry of the structured-data types offered by PublishRank for news publishers. */
namespace PublishRankSEO\Schema;
defined( 'ABSPATH' ) || exit;
final class SchemaTypes {
    private static function label( $key ) {
        $labels = array(
            'News Article' => 'News Article', 'Article' => 'Article', 'Live Blog Post' => 'Live Blog Post',
            'Video' => 'Video', 'Image Gallery' => 'Image Gallery', 'Standard Web Page' => 'Standard Web Page',
            'Articles' => 'Articles', 'Media & Images' => 'Media & Images',
        );
        return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
    }
    /** The only editorial article schemas supported by this news-publisher plugin. */
    public static function article_types() {
        return array(
            'NewsArticle' => self::label( 'News Article' ),
            'Article' => self::label( 'Article' ),
            'LiveBlogPosting' => self::label( 'Live Blog Post' ),
        );
    }
    /** WebPage is a foundational node, not an editorial schema choice. */
    public static function page_types() { return array( 'WebPage' => self::label( 'Standard Web Page' ) ); }
    public static function aliases() { return array(); }
    public static function legal_types() { return array(); }
    /** The only media schemas supported by this news-publisher plugin. */
    public static function media_types() {
        return array( 'VideoObject' => self::label( 'Video' ), 'ImageGallery' => self::label( 'Image Gallery' ) );
    }
    public static function all() { return array_merge( self::article_types(), self::page_types(), self::media_types() ); }
    public static function resolve( $key ) {
        $raw = trim( (string) $key );
        if ( '' === $raw ) { return ''; }
        foreach ( array_keys( self::all() ) as $type ) { if ( 0 === strcasecmp( $type, $raw ) ) { return $type; } }
        return '';
    }
    /** Grouped map for the editor. Foundational WebPage is automatic and intentionally not selectable. */
    public static function grouped() {
        return array( self::label( 'Articles' ) => self::article_types(), self::label( 'Media & Images' ) => self::media_types() );
    }
}
