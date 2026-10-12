<?php
/** Reading-time and table-of-contents shortcodes plus heading anchors. */
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class ReadingExperience {
	public function register() {
		add_shortcode( 'publishrank_reading_time', array( $this, 'reading_time_shortcode' ) );
		add_shortcode( 'publishrank_toc', array( $this, 'toc_shortcode' ) );
		add_filter( 'the_content', array( $this, 'anchor_headings' ), 12 );
	}

	public static function word_count( $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		$words   = preg_split( '/\s+/u', trim( wp_strip_all_tags( strip_shortcodes( $content ) ) ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/** @return int Estimated minutes at 225 wpm, minimum 1. */
	public static function reading_time( $post_id, $wpm = 225 ) {
		$wpm = max( 50, absint( $wpm ) );
		return max( 1, (int) ceil( self::word_count( $post_id ) / $wpm ) );
	}

	public function reading_time_shortcode() {
		if ( ! is_singular() ) { return ''; }
		$minutes = self::reading_time( get_queried_object_id() );
		return '<span class="publishrank-reading-time">' . esc_html( sprintf( /* translators: %d: minutes. */ _n( '%d min read', '%d min read', $minutes, 'publishrank' ), $minutes ) ) . '</span>';
	}

	/** Extract h2/h3 headings with stable anchors. @return array<int,array{level:int,text:string,id:string}> */
	public static function headings( $content ) {
		$headings = array();
		if ( ! preg_match_all( '/<h([23])\b[^>]*>(.*?)<\/h\1>/is', (string) $content, $m, PREG_SET_ORDER ) ) { return $headings; }
		$used = array();
		foreach ( $m as $match ) {
			$text = trim( wp_strip_all_tags( $match[2] ) );
			if ( '' === $text ) { continue; }
			$base = sanitize_title( $text ); $base = '' === $base ? 'section' : $base;
			$id   = $base; $i = 2;
			while ( isset( $used[ $id ] ) ) { $id = $base . '-' . $i; $i++; }
			$used[ $id ] = true;
			$headings[]  = array( 'level' => (int) $match[1], 'text' => $text, 'id' => $id );
		}
		return $headings;
	}

	/** Add matching ids to h2/h3 headings that lack them (skips feeds). */
	public function anchor_headings( $content ) {
		if ( is_admin() || is_feed() || ! is_singular() || false === strpos( (string) $content, '<h2' ) && false === strpos( (string) $content, '<H2' ) ) { return $content; }
		$map = array();
		foreach ( self::headings( $content ) as $heading ) { $map[] = $heading['id']; }
		$i = 0;
		return (string) preg_replace_callback( '/<h([23])\b([^>]*)>/i', static function ( $m ) use ( &$i, $map ) {
			if ( false !== stripos( $m[2], 'id=' ) || ! isset( $map[ $i ] ) ) { return $m[0]; }
			return '<h' . $m[1] . $m[2] . ' id="' . esc_attr( $map[ $i++ ] ) . '">';
		}, (string) $content );
	}

	/** [publishrank_toc] renders a nav list; renders nothing below the minimum heading count. */
	public function toc_shortcode() {
		if ( ! is_singular() ) { return ''; }
		$min = max( 2, absint( apply_filters( 'publishrank_toc_min_headings', 3 ) ) );
		$headings = self::headings( get_post_field( 'post_content', get_queried_object_id() ) );
		if ( count( $headings ) < $min ) { return ''; }
		$html = '<nav class="publishrank-toc" aria-label="' . esc_attr__( 'Table of contents', 'publishrank' ) . '"><p><strong>' . esc_html__( 'In this article', 'publishrank' ) . '</strong></p><ul>';
		foreach ( $headings as $heading ) {
			$html .= '<li class="publishrank-toc-level-' . (int) $heading['level'] . '"><a href="#' . esc_attr( $heading['id'] ) . '">' . esc_html( $heading['text'] ) . '</a></li>';
		}
		return $html . '</ul></nav>';
	}
}