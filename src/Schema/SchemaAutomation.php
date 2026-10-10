<?php
namespace PublishRankSEO\Schema;

use PublishRankSEO\Core\PublicationProfile;

final class SchemaAutomation {
	/** @return array<string, mixed>|null */
	public function for_current_document() {
		if ( ! is_singular() ) { return null; }
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) { return null; }
		$schema = array( '@type' => 'WebPage', '@id' => esc_url_raw( get_permalink( $post ) ) . '#webpage', 'url' => esc_url_raw( get_permalink( $post ) ), 'name' => wp_strip_all_tags( get_the_title( $post ) ), 'datePublished' => get_post_time( DATE_W3C, true, $post ), 'dateModified' => get_post_modified_time( DATE_W3C, true, $post ), 'isPartOf' => array( '@id' => trailingslashit( PublicationProfile::primary_url() ) . '#website' ) );
		if ( 'post' === $post->post_type || post_type_supports( $post->post_type, 'editor' ) ) { $schema = $this->article( $post, $schema ); }
		$faq = $this->faq( $post );
		return array( 'primary' => $schema, 'faq' => $faq );
	}
	private function article( $post, $base ) {
		$author = get_userdata( $post->post_author ); $organization_id = trailingslashit( PublicationProfile::primary_url() ) . '#organization';
		$article = array( '@type' => 'Article', '@id' => esc_url_raw( get_permalink( $post ) ) . '#article', 'headline' => wp_strip_all_tags( get_the_title( $post ) ), 'datePublished' => get_post_time( DATE_W3C, true, $post ), 'dateModified' => get_post_modified_time( DATE_W3C, true, $post ), 'author' => array( '@type' => 'Person', 'name' => $author ? wp_strip_all_tags( $author->display_name ) : __( 'Editorial staff', 'publishrank' ) ), 'publisher' => array( '@id' => $organization_id ), 'mainEntityOfPage' => array( '@id' => esc_url_raw( get_permalink( $post ) ) ) );
		$image = get_the_post_thumbnail_url( $post, 'full' ); if ( $image ) { $article['image'] = array( esc_url_raw( $image ) ); }
		$base['mainEntity'] = $article; return $base;
	}
	/** @return array<string, mixed>|null */
	private function faq( $post ) {
		$content = (string) $post->post_content; preg_match_all( '/<details[^>]*>\s*<summary[^>]*>(.*?)<\/summary>(.*?)<\/details>/is', $content, $matches, PREG_SET_ORDER );
		if ( empty( $matches ) ) { return null; }
		$questions = array(); foreach ( $matches as $match ) { $question = trim( wp_strip_all_tags( $match[1] ) ); $answer = trim( wp_strip_all_tags( $match[2] ) ); if ( $question && $answer ) { $questions[] = array( '@type' => 'Question', 'name' => $question, 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $answer ) ); } }
		return empty( $questions ) ? null : array( '@type' => 'FAQPage', 'mainEntity' => $questions );
	}
}
