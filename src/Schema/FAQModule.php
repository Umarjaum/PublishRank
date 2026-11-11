<?php
/** Advanced FAQ presentation module: accessible accordion shortcode backed by FAQ schema entries. */
namespace PublishRankSEO\Schema;

use PublishRankSEO\Core\OutputSettings;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class FAQModule {
	public function register() {
		add_shortcode( 'publishrank_faq', array( $this, 'shortcode' ) );
	}

	/** FAQ schema is eligible only when the page visibly contains the FAQ module. */
	public static function has_visible_faq( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		$content = (string) $post->post_content;
		return has_shortcode( $content, 'publishrank_faq' ) || ( function_exists( 'has_block' ) && has_block( 'publishrank/faq', $content ) );
	}

	/** Render saved FAQ pairs as an accessible, responsive accordion. */
	public function shortcode( $atts = array() ) {
		if ( ! OutputSettings::faq() ) {
			return '';
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$atts = shortcode_atts(
			array(
				'title' => __( 'Frequently asked questions', 'publishrank' ),
				'open'  => 'first',
				'limit' => 20,
			),
			$atts,
			'publishrank_faq'
		);
		$items = get_post_meta( $post->ID, PostMeta::FAQ_ITEMS, true );
		if ( ! is_array( $items ) ) {
			return '';
		}
		$limit = min( 20, max( 1, absint( $atts['limit'] ) ) );
		$valid = array();
		foreach ( array_slice( $items, 0, $limit ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = trim( wp_strip_all_tags( (string) ( $item['q'] ?? '' ) ) );
			$answer   = trim( wp_strip_all_tags( (string) ( $item['a'] ?? '' ) ) );
			if ( '' !== $question && '' !== $answer ) {
				$valid[] = array( 'q' => $question, 'a' => $answer );
			}
		}
		if ( ! $valid ) {
			return '';
		}
		wp_enqueue_style( 'publishrank-faq', plugins_url( 'assets/css/faq.css', PUBLISHRANK_SEO_FILE ), array(), PUBLISHRANK_SEO_VERSION );
		$open = strtolower( sanitize_key( (string) $atts['open'] ) );
		$html = '<section class="publishrank-faq"' . ( '' !== trim( (string) $atts['title'] ) ? ' aria-labelledby="publishrank-faq-title-' . (int) $post->ID . '"' : '' ) . '>';
		if ( '' !== trim( (string) $atts['title'] ) ) {
			$html .= '<h2 class="publishrank-faq__title" id="publishrank-faq-title-' . (int) $post->ID . '">' . esc_html( (string) $atts['title'] ) . '</h2>';
		}
		$html .= '<div class="publishrank-faq__items">';
		foreach ( $valid as $index => $item ) {
			$is_open = ( 'all' === $open || ( 'first' === $open && 0 === $index ) );
			$html   .= '<details class="publishrank-faq__item"' . ( $is_open ? ' open' : '' ) . '><summary>' . esc_html( $item['q'] ) . '<span class="publishrank-faq__icon" aria-hidden="true"></span></summary><div class="publishrank-faq__answer"><p>' . nl2br( esc_html( $item['a'] ) ) . '</p></div></details>';
		}
		return $html . '</div></section>';
	}
}
