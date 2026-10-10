<?php
/** Serve the site's configurable llms.txt and optional llms-full.txt resources. */
namespace PublishRankSEO\AI;

final class LLMSTxt {
	const OPTION_ENABLED       = 'publishrank_llms_txt_enabled';
	const OPTION_TITLE         = 'publishrank_llms_txt_title';
	const OPTION_SUMMARY       = 'publishrank_llms_txt_summary';
	const OPTION_INTRO         = 'publishrank_llms_txt_intro';
	const OPTION_HOME          = 'publishrank_llms_txt_home';
	const OPTION_POSTS         = 'publishrank_llms_txt_posts';
	const OPTION_PAGES         = 'publishrank_llms_txt_pages';
	const OPTION_CATEGORIES    = 'publishrank_llms_txt_categories';
	const OPTION_POST_COUNT    = 'publishrank_llms_txt_post_count';
	const OPTION_CUSTOM        = 'publishrank_llms_txt_custom';
	const OPTION_FULL_ENABLED  = 'publishrank_llms_full_enabled';
	const OPTION_FULL_WORDS    = 'publishrank_llms_full_words';

	public function register() {
		add_action( 'init', array( $this, 'rewrite' ), 20 );
		// Flush once after the rules have been registered so /llms.txt works immediately
		// after activation or upgrade, without flushing on every request.
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), 1 );
		add_action( 'wp_head', array( $this, 'head_link' ), 1 );
	}

	public function rewrite() {
		add_rewrite_rule( '^llms\.txt/?$', 'index.php?publishrank_llms=1', 'top' );
		add_rewrite_rule( '^llms-full\.txt/?$', 'index.php?publishrank_llms_full=1', 'top' );
	}

	public function maybe_flush_rewrite_rules() {
		if ( '1' !== (string) get_option( 'publishrank_llms_rewrite_ready', '' ) ) {
			flush_rewrite_rules( false );
			update_option( 'publishrank_llms_rewrite_ready', '1', false );
		}
	}

	public function query_vars( $vars ) {
		$vars[] = 'publishrank_llms';
		$vars[] = 'publishrank_llms_full';
		return $vars;
	}

	public function head_link() {
		if ( is_admin() || wp_doing_ajax() || ! get_option( self::OPTION_ENABLED, 1 ) ) {
			return;
		}
		$url = home_url( '/llms.txt' );
		// rel=alternate provides machine-readable discovery; the meta names below are
		// PublishRank-specific metadata, not a claim of a universal llms.txt standard.
		echo '<link rel="alternate" type="text/plain" title="llms.txt" href="' . esc_url( $url ) . '" />' . "\n";
		echo '<link rel="describedby" href="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta name="publishrank:llms-txt" content="' . esc_url( $url ) . '" />' . "\n";
		if ( get_option( self::OPTION_FULL_ENABLED, 0 ) ) {
			$full_url = home_url( '/llms-full.txt' );
			echo '<link rel="alternate" type="text/plain" title="llms-full.txt" href="' . esc_url( $full_url ) . '" />' . "\n";
			echo '<meta name="publishrank:llms-full-txt" content="' . esc_url( $full_url ) . '" />' . "\n";
		}
	}

	public function serve() {
		$full = (bool) get_query_var( 'publishrank_llms_full' );
		$root = (bool) get_query_var( 'publishrank_llms' );
		if ( ! $root && ! $full ) {
			return;
		}
		if ( ! get_option( self::OPTION_ENABLED, 1 ) || ( $full && ! get_option( self::OPTION_FULL_ENABLED, 0 ) ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo "PublishRank llms.txt is disabled.\n";
			exit;
		}
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );
		echo $full ? $this->build_full() : $this->build(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text Markdown resource.
		exit;
	}

	public function build() {
		$title   = trim( (string) get_option( self::OPTION_TITLE, get_bloginfo( 'name' ) ) );
		$summary = trim( (string) get_option( self::OPTION_SUMMARY, get_bloginfo( 'description' ) ) );
		$intro   = trim( (string) get_option( self::OPTION_INTRO, '' ) );
		$lines   = array( '# ' . $this->line( $title ) );
		if ( '' !== $summary ) { $lines[] = '> ' . $this->line( $summary ); }
		if ( '' !== $intro ) { $lines[] = ''; $lines[] = $this->line( $intro ); }
		$sections = array();
		if ( get_option( self::OPTION_HOME, 1 ) ) { $sections[] = array( 'name' => __( 'Website', 'publishrank' ), 'links' => array( array( get_bloginfo( 'name' ), home_url( '/' ), __( 'Official website homepage.', 'publishrank' ) ) ) ); }
		if ( get_option( self::OPTION_POSTS, 1 ) ) { $sections[] = array( 'name' => __( 'Recent articles', 'publishrank' ), 'links' => $this->posts( absint( get_option( self::OPTION_POST_COUNT, 20 ) ) ) ); }
		if ( get_option( self::OPTION_PAGES, 1 ) ) { $sections[] = array( 'name' => __( 'Pages', 'publishrank' ), 'links' => $this->pages() ); }
		if ( get_option( self::OPTION_CATEGORIES, 1 ) ) { $sections[] = array( 'name' => __( 'Categories', 'publishrank' ), 'links' => $this->categories() ); }
		foreach ( $this->custom_sections() as $section ) { $sections[] = $section; }
		foreach ( $sections as $section ) {
			if ( empty( $section['links'] ) ) { continue; }
			$lines[] = ''; $lines[] = '## ' . $this->line( $section['name'] );
			foreach ( $section['links'] as $link ) {
				$lines[] = '- [' . $this->line( $link[0] ) . '](' . esc_url_raw( $link[1] ) . ')' . ( '' !== trim( (string) $link[2] ) ? ': ' . $this->line( $link[2] ) : '' );
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	public function build_full() {
		$title   = trim( (string) get_option( self::OPTION_TITLE, get_bloginfo( 'name' ) ) );
		$summary = trim( (string) get_option( self::OPTION_SUMMARY, get_bloginfo( 'description' ) ) );
		$limit   = max( 1000, min( 10000, absint( get_option( self::OPTION_FULL_WORDS, 8000 ) ) ) );
		$lines   = array( '# ' . $this->line( $title ), '', '> ' . $this->line( $summary ), '' );
		$posts   = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => min( 100, absint( get_option( self::OPTION_POST_COUNT, 20 ) ) ), 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ) );
		$words   = 0;
		foreach ( $posts as $post ) {
			$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) ) ) );
			if ( '' === $text ) { continue; }
			$parts = preg_split( '/\s+/u', $text );
			$remaining = $limit - $words;
			if ( $remaining <= 0 ) { break; }
			if ( count( $parts ) > $remaining ) { $parts = array_slice( $parts, 0, $remaining ); }
			$lines[] = '## ' . $this->line( get_the_title( $post ) );
			$lines[] = '[' . $this->line( get_the_title( $post ) ) . '](' . esc_url_raw( get_permalink( $post ) ) . ')';
			$lines[] = implode( ' ', $parts );
			$lines[] = '';
			$words += count( $parts );
		}
		return implode( "\n", $lines ) . "\n";
	}

	private function posts( $count ) {
		$out = array();
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => max( 1, min( 100, $count ) ), 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ) );
		foreach ( $posts as $post ) { $out[] = array( get_the_title( $post ), get_permalink( $post ), wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post ) ), 28, '…' ) ); }
		return $out;
	}

	private function pages() {
		$out = array();
		foreach ( get_pages( array( 'post_status' => 'publish', 'number' => 50, 'sort_column' => 'menu_order,post_title' ) ) as $page ) { $out[] = array( get_the_title( $page ), get_permalink( $page ), __( 'Published site page.', 'publishrank' ) ); }
		return $out;
	}

	private function categories() {
		$out = array(); $terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'number' => 50 ) );
		if ( is_wp_error( $terms ) ) { return $out; }
		foreach ( $terms as $term ) { $url = get_term_link( $term ); if ( is_wp_error( $url ) ) { continue; } $out[] = array( $term->name, $url, $term->description ? wp_trim_words( wp_strip_all_tags( $term->description ), 24, '…' ) : __( 'Published content category.', 'publishrank' ) ); }
		return $out;
	}

	private function custom_sections() {
		$raw = (string) get_option( self::OPTION_CUSTOM, '' ); $out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 3 || '' === $parts[0] || '' === $parts[1] || '' === $parts[2] || ! wp_http_validate_url( $parts[2] ) ) { continue; }
			if ( ! isset( $out[ $parts[0] ] ) ) { $out[ $parts[0] ] = array( 'name' => $parts[0], 'links' => array() ); }
			$out[ $parts[0] ]['links'][] = array( $parts[1], $parts[2], isset( $parts[3] ) ? $parts[3] : '' );
		}
		return array_values( $out );
	}

	private function line( $value ) { return trim( str_replace( array( "\r", "\n" ), ' ', wp_strip_all_tags( (string) $value ) ) ); }
}
