<?php
/** Fetches the site's own pages and counts SEO tags, to expose duplicate output from themes or other plugins. */
namespace PublishRankSEO\Admin;

final class FrontendScanner {
	/**
	 * Count SEO tags in an HTML document. Pure string analysis.
	 *
	 * @param string $html Page HTML.
	 * @return array<string,int>
	 */
	public static function analyze( $html ) {
		$html     = (string) $html;
		$patterns = array(
			'title'         => '#<title[\s>]#i',
			'description'   => '#<meta[^>]+name=["\']description["\']#i',
			'canonical'     => '#<link[^>]+rel=["\']canonical["\']#i',
			'robots'        => '#<meta[^>]+name=["\']robots["\']#i',
			'og_title'      => '#<meta[^>]+property=["\']og:title["\']#i',
			'og_image'      => '#<meta[^>]+property=["\']og:image["\']#i',
			'twitter_card'  => '#<meta[^>]+name=["\']twitter:card["\']#i',
			'jsonld'        => '#<script[^>]+type=["\']application/ld\+json["\']#i',
		);
		$counts = array();
		foreach ( $patterns as $key => $pattern ) {
			$counts[ $key ] = (int) preg_match_all( $pattern, $html );
		}
		$counts['publishrank'] = ( false !== strpos( $html, '<!-- SEO metadata output begin -->' ) || false !== strpos( $html, 'PublishRank: begin' ) ) ? 1 : 0;
		return $counts;
	}

	/** @return array<string,string> label => URL of this site's own pages worth scanning. */
	public function targets() {
		$targets = array( __( 'Homepage', 'publishrank' ) => home_url( '/' ) );
		$posts   = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true ) );
		if ( ! empty( $posts ) ) {
			$targets[ __( 'Latest article', 'publishrank' ) ] = get_permalink( $posts[0] );
		}
		return $targets;
	}

	/** @return array<int,array<string,mixed>> */
	public function run() {
		$results = array();
		foreach ( $this->targets() as $label => $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 3,
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				$results[] = array( 'label' => $label, 'url' => $url, 'error' => $response->get_error_message() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				/* translators: %d: HTTP status code. */
				$results[] = array( 'label' => $label, 'url' => $url, 'error' => sprintf( __( 'The page returned HTTP %d.', 'publishrank' ), $code ) );
				continue;
			}
			$results[] = array( 'label' => $label, 'url' => $url, 'counts' => self::analyze( wp_remote_retrieve_body( $response ) ) );
		}
		return $results;
	}

	/**
	 * Turn counts into findings.
	 *
	 * @param array<string,int> $counts Tag counts.
	 * @return array<int,array<string,string>> Each item has status and message.
	 */
	public static function findings( array $counts ) {
		$rules = array(
			'title'        => array( __( '<title> tag', 'publishrank' ), 1 ),
			'description'  => array( __( 'Meta description', 'publishrank' ), 1 ),
			'canonical'    => array( __( 'Canonical link', 'publishrank' ), 1 ),
			'robots'       => array( __( 'Robots meta tag', 'publishrank' ), 1 ),
			'og_title'     => array( __( 'Open Graph title', 'publishrank' ), 1 ),
			'twitter_card' => array( __( 'Twitter card', 'publishrank' ), 1 ),
		);
		$out = array();
		foreach ( $rules as $key => $rule ) {
			$n = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
			if ( 1 === $n ) {
				$out[] = array( 'status' => 'PASS', 'message' => $rule[0] . ': 1' );
			} elseif ( 0 === $n ) {
				$out[] = array( 'status' => 'WARNING', 'message' => $rule[0] . ': ' . __( 'missing', 'publishrank' ) );
			} else {
				/* translators: 1: tag name, 2: number found. */
				$out[] = array( 'status' => 'ERROR', 'message' => sprintf( __( '%1$s: %2$d found. Duplicates confuse crawlers; another plugin or the theme is printing the same tag.', 'publishrank' ), $rule[0], $n ) );
			}
		}
		$out[] = array( 'status' => ! empty( $counts['jsonld'] ) ? 'PASS' : 'WARNING', 'message' => sprintf( /* translators: %d: number of JSON-LD blocks. */ __( 'JSON-LD blocks: %d', 'publishrank' ), isset( $counts['jsonld'] ) ? (int) $counts['jsonld'] : 0 ) );
		$out[] = array( 'status' => ! empty( $counts['publishrank'] ) ? 'PASS' : 'ERROR', 'message' => ! empty( $counts['publishrank'] ) ? __( 'PublishRank output is present on this page.', 'publishrank' ) : __( 'PublishRank output was not found. A caching layer may be serving an old copy, or the theme omits wp_head().', 'publishrank' ) );
		return $out;
	}
}
