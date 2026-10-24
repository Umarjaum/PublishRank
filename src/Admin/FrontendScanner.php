<?php
/** Fetches the site's own pages and sitemaps and validates their emitted SEO output. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Sitemap\SitemapUrls;

defined( 'ABSPATH' ) || exit;

final class FrontendScanner {
	/**
	 * Count visible metadata and inspect JSON-LD blocks in an HTML document.
	 *
	 * @param string $html Page HTML.
	 * @return array<string,mixed>
	 */
	public static function analyze( $html ) {
		$html     = (string) $html;
		$patterns = array(
			'title'        => '#<title[\s>]#i',
			'description'  => '#<meta[^>]+name=["\']description["\']#i',
			'canonical'    => '#<link[^>]+rel=["\']canonical["\']#i',
			'robots'       => '#<meta[^>]+name=["\']robots["\']#i',
			'og_title'     => '#<meta[^>]+property=["\']og:title["\']#i',
			'og_image'     => '#<meta[^>]+property=["\']og:image["\']#i',
			'twitter_card' => '#<meta[^>]+name=["\']twitter:card["\']#i',
			'jsonld'       => '#<script\b[^>]+type=["\']application/ld\+json["\'][^>]*>#i',
		);
		$counts   = array();
		foreach ( $patterns as $key => $pattern ) {
			$counts[ $key ] = (int) preg_match_all( $pattern, $html ); }
		$counts['publishrank']                      = ( false !== strpos( $html, '<!-- SEO metadata output begin -->' ) || false !== strpos( $html, 'PublishRank: begin' ) ) ? 1 : 0;
		$counts['jsonld_valid']                     = 0;
		$counts['jsonld_invalid']                   = 0;
		$counts['jsonld_article']                   = 0;
		$counts['jsonld_duplicate_ids']             = 0;
		$counts['jsonld_article_missing_headline']  = 0;
		$counts['jsonld_article_missing_author']    = 0;
		$counts['jsonld_article_missing_published'] = 0;
		$counts['jsonld_article_invalid_published'] = 0;
		$counts['jsonld_article_missing_image']     = 0;
		$counts['jsonld_article_missing_publisher'] = 0;
		$counts['jsonld_article_missing_modified']  = 0;
		$counts['jsonld_article_invalid_modified']  = 0;
		$counts['jsonld_canonical_mismatch']        = 0;
		$canonical                                  = self::canonical_url( $html );
		$ids                                        = array();
		$articles                                   = array();
		$blocks                                     = array();
		preg_match_all( '#<script\b[^>]*type\s*=\s*(["\'])application/ld\+json\1[^>]*>(.*?)</script\s*>#is', $html, $blocks, PREG_SET_ORDER );
		foreach ( $blocks as $block ) {
			$decoded = json_decode( trim( (string) $block[2] ), true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				++$counts['jsonld_invalid'];
				continue; }
			++$counts['jsonld_valid'];
			self::collect_schema_nodes( $decoded, $articles, $ids );
		}
		$counts['jsonld_article']       = count( $articles );
		$counts['jsonld_duplicate_ids'] = count(
			array_filter(
				$ids,
				static function ( $count ) {
					return $count > 1;
				}
			)
		);
		foreach ( $articles as $article ) {
			foreach ( array( 'headline', 'author', 'datePublished', 'image', 'publisher', 'dateModified' ) as $property ) {
				$key = 'jsonld_article_missing_' . ( 'datePublished' === $property ? 'published' : ( 'dateModified' === $property ? 'modified' : $property ) );
				if ( ! self::has_value( $article, $property ) ) {
					++$counts[ $key ]; }
			}
			foreach ( array(
				'datePublished' => 'published',
				'dateModified'  => 'modified',
			) as $property => $suffix ) {
				if ( self::has_value( $article, $property ) && ! self::valid_schema_date( $article[ $property ] ) ) {
					++$counts[ 'jsonld_article_invalid_' . $suffix ]; }
			}
			$article_url = isset( $article['url'] ) && is_string( $article['url'] ) ? trim( $article['url'] ) : '';
			if ( $canonical && $article_url && self::normalize_url( $canonical ) !== self::normalize_url( $article_url ) ) {
				++$counts['jsonld_canonical_mismatch']; }
		}
		return $counts;
	}

	/** @return array<int,array<string,mixed>> Page and sitemap scan results. */
	public function run() {
		$results = array();
		foreach ( $this->targets() as $label => $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', true ),
					'headers'             => array( 'Cache-Control' => 'no-cache' ),
					'limit_response_size' => 2097152,
				)
			);
			if ( is_wp_error( $response ) ) {
				$results[] = array(
					'label'  => $label,
					'url'    => $url,
					'status' => 'NOT TESTED',
					'error'  => $response->get_error_message(),
				);
				continue; }
			$code = (int) wp_remote_retrieve_response_code( $response );
			/* translators: %d: HTTP response status code. */
			if ( $code < 200 || $code >= 300 ) {
				$results[] = array(
					'label'  => $label,
					'url'    => $url,
					'status' => 'ERROR',
					/* translators: %d: HTTP response status code. */
					'error'  => sprintf( __( 'The page returned HTTP %d.', 'publishrank' ), $code ),
				);
				continue; }
			$body = (string) wp_remote_retrieve_body( $response );
			if ( strlen( $body ) >= 2097152 ) {
				$results[] = array(
					'label'  => $label,
					'url'    => $url,
					'status' => 'NOT TESTED',
					'error'  => __( 'The HTML response reached the 2 MiB safety limit; truncated content was not analyzed.', 'publishrank' ),
				);
				continue; }
			$results[] = array(
				'label'  => $label,
				'url'    => $url,
				'counts' => self::analyze( $body ),
			);
		}
		return array_merge( $results, $this->scan_sitemaps() );
	}

	/** @return array<int,array<string,mixed>> */
	public function targets() {
		$targets = array( __( 'Homepage', 'publishrank' ) => home_url( '/' ) );
		$posts   = get_posts(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'numberposts'   => 1,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		if ( ! empty( $posts ) ) {
			$targets[ __( 'Latest article', 'publishrank' ) ] = get_permalink( $posts[0] ); }
		return $targets;
	}

	/** Fetch and parse the actual index plus at most ten same-host child sitemap endpoints. */
	private function scan_sitemaps() {
		$index_url = SitemapUrls::index_url();
		$index     = $this->fetch_xml( $index_url, __( 'Sitemap index', 'publishrank' ), 'sitemapindex' );
		$results   = array( $index );
		if ( empty( $index['children'] ) || ! is_array( $index['children'] ) ) {
			return $results; }
		foreach ( array_slice( (array) ( $index['children'] ?? array() ), 0, 10 ) as $url ) {
			$url = trim( (string) $url );
			if ( ! self::same_site_url( $url ) ) {
				$results[] = array(
					'label'  => __( 'Sitemap child endpoint', 'publishrank' ),
					'url'    => $url,
					'status' => 'ERROR',
					'error'  => __( 'The sitemap index contains a non-site or invalid child URL; it was not requested.', 'publishrank' ),
				);
				continue;
			}
			/* translators: %d: numeric count or measurement described by the message. */
			$results[] = $this->fetch_xml( $url, sprintf( __( 'Child sitemap %d', 'publishrank' ), count( $results ) ), 'urlset' );
		}
		return $results;
	}

	/** @return array<string,mixed> */
	private function fetch_xml( $url, $label, $expected_root ) {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return array(
				'label'  => $label,
				'url'    => $url,
				'status' => 'NOT TESTED',
				'error'  => __( 'PHP SimpleXML is unavailable, so XML well-formedness cannot be verified.', 'publishrank' ),
			); }
		if ( ! self::same_site_url( $url ) ) {
			return array(
				'label'  => $label,
				'url'    => $url,
				'status' => 'ERROR',
				'error'  => __( 'The sitemap endpoint does not match this site host.', 'publishrank' ),
			); }
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 12,
				'redirection'         => 0,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', true ),
				'headers'             => array(
					'Cache-Control' => 'no-cache',
					'Accept'        => 'application/xml,text/xml;q=0.9,*/*;q=0.1',
				),
				'limit_response_size' => 2097152,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'label'  => $label,
				'url'    => $url,
				'status' => 'NOT TESTED',
				'error'  => $response->get_error_message(),
			); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		/* translators: %d: HTTP response status code. */
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'label'  => $label,
				'url'    => $url,
				'status' => 'ERROR',
				/* translators: %d: HTTP response status code. */
				'error'  => sprintf( __( 'The sitemap endpoint returned HTTP %d.', 'publishrank' ), $code ),
			); }
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) >= 2097152 ) {
			return array(
				'label'  => $label,
				'url'    => $url,
				'status' => 'NOT TESTED',
				'error'  => __( 'The XML response reached the 2 MiB safety limit; truncated content was not validated.', 'publishrank' ),
			); }
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		/* translators: %1$d: HTTP response status code; %2$s: expected XML root element name. */
		if ( false === $xml || $expected_root !== $xml->getName() ) {
			return array(
				'label'  => $label,
				'url'    => $url,
				'status' => 'ERROR',
				/* translators: 1: HTTP response status code, 2: expected XML root element name. */
				'error'  => sprintf( __( 'HTTP %1$d succeeded, but XML was malformed or the root element was not %2$s.', 'publishrank' ), $code, $expected_root ),
			); }
		$locs  = $xml->xpath( '//*[local-name()="loc"]' );
		$count = is_array( $locs ) ? count( $locs ) : 0;
		/* translators: %1$d: HTTP response status code; %2$s: XML document type; %3$d: HTTP response status code. */
		$message = sprintf( __( 'HTTP %1$d; valid %2$s XML; %3$d location(s).', 'publishrank' ), $code, $expected_root, $count );
		if ( 'urlset' === $expected_root && 0 === $count ) {
			$message .= ' ' . __( 'This sitemap is valid and empty.', 'publishrank' ); }
		$news = 'urlset' === $expected_root ? $xml->xpath( '//*[local-name()="news"]' ) : array();
		if ( 'urlset' === $expected_root && is_array( $news ) && $news ) {
			$stale         = 0;
			$invalid_dates = 0;
			$now           = time();
			foreach ( (array) $news as $entry ) {
				$dates = $entry->xpath( './/*[local-name()="publication_date"]' );
				$date  = isset( $dates[0] ) ? strtotime( (string) $dates[0] ) : false;
				if ( false === $date ) {
					++$invalid_dates; } elseif ( $date > $now + HOUR_IN_SECONDS || $date < $now - ( 48 * HOUR_IN_SECONDS ) ) {
					++$stale; }
			}
			/* translators: %1$d: numeric count or measurement described by the message; %2$d: number of date. */
			if ( $invalid_dates || $stale ) {
				return array(
					'label'  => $label,
					'url'    => $url,
					'status' => 'ERROR',
					/* translators: 1: count of missing or invalid publication dates, 2: count of dates older than the allowed 48-hour window. */
					'error'  => sprintf( __( 'News Sitemap has %1$d missing/invalid publication date(s) and %2$d date(s) outside the previous 48 hours.', 'publishrank' ), $invalid_dates, $stale ),
				); }
			/* translators: %d: numeric count or measurement described by the message. */
			$message .= ' ' . sprintf( __( 'News entries checked: %d; all publication dates are within the 48-hour window.', 'publishrank' ), count( $news ) );
		}
		$children = array();
		if ( 'sitemapindex' === $expected_root && is_array( $locs ) ) {
			foreach ( $locs as $loc ) {
				$children[] = trim( (string) $loc ); }
		}
		return array(
			'label'    => $label,
			'url'      => $url,
			'status'   => 'PASS',
			'message'  => $message,
			'children' => $children,
		);
	}

	/** @return array<int,array<string,string>> */
	public static function findings( array $counts ) {
		$rules = array(
			'title'        => array( __( '<title> tag', 'publishrank' ), 1 ),
			'description'  => array( __( 'Meta description', 'publishrank' ), 1 ),
			'canonical'    => array( __( 'Canonical link', 'publishrank' ), 1 ),
			'robots'       => array( __( 'Robots meta tag', 'publishrank' ), 1 ),
			'og_title'     => array( __( 'Open Graph title', 'publishrank' ), 1 ),
			'twitter_card' => array( __( 'Twitter card', 'publishrank' ), 1 ),
		);
		$out   = array();
		foreach ( $rules as $key => $rule ) {
			$n = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
			if ( 1 === $n ) {
				$out[] = array(
					'status'  => 'PASS',
					'message' => $rule[0] . ': 1',
				);
			} elseif ( 0 === $n ) {
				$out[] = array(
					'status'  => 'WARNING',
					'message' => $rule[0] . ': ' . __( 'missing', 'publishrank' ),
				);
			} else {
				$out[] = array(
					'status'  => 'ERROR',
					/* translators: 1: JSON-LD or markup rule name, 2: count of matching findings. */
					'message' => sprintf( __( '%1$s: %2$d found. Review duplicate theme or plugin output.', 'publishrank' ), $rule[0], $n ),
				);
			}
		}
		$blocks = (int) ( $counts['jsonld'] ?? 0 );
		/* translators: %d: numeric count or measurement described by the message. */
		$out[]   = array(
			'status'  => $blocks ? 'PASS' : 'WARNING',
			/* translators: %d: number of JSON-LD script blocks found. */
			'message' => sprintf( __( 'JSON-LD blocks found: %d.', 'publishrank' ), $blocks ),
		);
		$invalid = (int) ( $counts['jsonld_invalid'] ?? 0 );
		/* translators: %d: numeric count or measurement described by the message. */
		$out[]    = array(
			'status'  => $invalid ? 'ERROR' : ( $blocks ? 'PASS' : 'NOT TESTED' ),
			/* translators: %d: number of JSON-LD blocks that failed to parse. */
			'message' => $invalid ? sprintf( __( 'Malformed JSON-LD blocks: %d.', 'publishrank' ), $invalid ) : ( $blocks ? __( 'All present JSON-LD blocks parse as JSON.', 'publishrank' ) : __( 'No JSON-LD blocks were available to parse.', 'publishrank' ) ),
		);
		$articles = (int) ( $counts['jsonld_article'] ?? 0 );
		/* translators: %d: numeric count or measurement described by the message. */
		$out[] = array(
			'status'  => $articles > 1 ? 'ERROR' : ( 1 === $articles ? 'PASS' : 'WARNING' ),
			/* translators: %d: number of Article-family JSON-LD entities found. */
			'message' => $articles > 1 ? sprintf( __( '%d Article-family entities found; review duplicate primary articles.', 'publishrank' ), $articles ) : ( 1 === $articles ? __( 'One Article, NewsArticle, or BlogPosting entity is present.', 'publishrank' ) : __( 'No Article-family entity was found; review the selected schema type if this is editorial content.', 'publishrank' ) ),
		);
		if ( $articles ) {
			foreach ( array(
				'headline'  => __( 'headline', 'publishrank' ),
				'author'    => __( 'author', 'publishrank' ),
				'published' => __( 'publication date', 'publishrank' ),
				'image'     => __( 'image', 'publishrank' ),
				'publisher' => __( 'publisher', 'publishrank' ),
			) as $key => $label ) {
				$missing = (int) ( $counts[ 'jsonld_article_missing_' . $key ] ?? 0 );
				/* translators: %1$s: dynamic value identified by the surrounding label or sentence; %2$d: numeric count or measurement described by the message; %s: schema property name. */
				$out[] = array(
					'status'  => $missing ? 'WARNING' : 'PASS',
					/* translators: 1: Article metadata field label, 2: count of missing fields; the success message uses %s for the field label. */
					'message' => $missing ? sprintf( __( 'Article-family entity missing %1$s: %2$d.', 'publishrank' ), $label, $missing ) : sprintf( __( 'Article-family entity has %s.', 'publishrank' ), $label ),
				);
			}
			$missing_modified = (int) ( $counts['jsonld_article_missing_modified'] ?? 0 );
			/* translators: %d: numeric count or measurement described by the message. */
			$out[]         = array(
				'status'  => $missing_modified ? 'WARNING' : 'PASS',
				/* translators: %d: Article-family entities without dateModified. */
				'message' => $missing_modified ? sprintf( __( 'dateModified is absent on %d Article-family entity/entities; add it only when accurate.', 'publishrank' ), $missing_modified ) : __( 'Article-family modification dates are present.', 'publishrank' ),
			);
			$invalid_dates = (int) ( $counts['jsonld_article_invalid_published'] ?? 0 ) + (int) ( $counts['jsonld_article_invalid_modified'] ?? 0 );
			/* translators: %d: numeric count or measurement described by the message. */
			$out[]    = array(
				'status'  => $invalid_dates ? 'ERROR' : 'PASS',
				/* translators: %d: invalid publication or modification date values. */
				'message' => $invalid_dates ? sprintf( __( 'Invalid ISO-style publication or modification dates: %d.', 'publishrank' ), $invalid_dates ) : __( 'Present publication and modification dates use valid ISO-style values.', 'publishrank' ),
			);
			$mismatch = (int) ( $counts['jsonld_canonical_mismatch'] ?? 0 );
			/* translators: %d: numeric count or measurement described by the message. */
			$out[] = array(
				'status'  => $mismatch ? 'ERROR' : 'PASS',
				/* translators: %d: entities whose Article URL differs from the canonical URL. */
				'message' => $mismatch ? sprintf( __( 'Canonical URL differs from Article URL on %d entity/entities.', 'publishrank' ), $mismatch ) : __( 'Article URLs match the page canonical URL where both were present.', 'publishrank' ),
			);
		}
		$duplicate_ids = (int) ( $counts['jsonld_duplicate_ids'] ?? 0 );
		/* translators: %d: numeric count or measurement described by the message. */
		$out[] = array(
			'status'  => $duplicate_ids ? 'ERROR' : ( $blocks ? 'PASS' : 'NOT TESTED' ),
			/* translators: %d: duplicate JSON-LD @id values. */
			'message' => $duplicate_ids ? sprintf( __( 'Duplicate JSON-LD @id values: %d.', 'publishrank' ), $duplicate_ids ) : __( 'No duplicate JSON-LD @id values were found.', 'publishrank' ),
		);
		$out[] = array(
			'status'  => ! empty( $counts['publishrank'] ) ? 'PASS' : 'WARNING',
			'message' => ! empty( $counts['publishrank'] ) ? __( 'PublishRank output is present on this page.', 'publishrank' ) : __( 'PublishRank output was not found. A caching layer may be serving an old copy, or the theme omits wp_head().', 'publishrank' ),
		);
		return $out;
	}

	/** Recursively collect schema entities, IDs and editorial Article-family nodes. */
	private static function collect_schema_nodes( $value, &$articles, &$ids ) {
		if ( ! is_array( $value ) ) {
			return; }
		if ( count( $value ) > 1 && isset( $value['@id'] ) && is_string( $value['@id'] ) && '' !== trim( $value['@id'] ) ) {
			$id         = trim( $value['@id'] );
			$ids[ $id ] = isset( $ids[ $id ] ) ? $ids[ $id ] + 1 : 1; }
		if ( isset( $value['@type'] ) ) {
			$types         = is_array( $value['@type'] ) ? $value['@type'] : array( $value['@type'] );
			$article_types = array( 'Article', 'NewsArticle', 'BlogPosting', 'LiveBlogPosting', 'ReportageNewsArticle', 'AnalysisNewsArticle', 'OpinionNewsArticle', 'BackgroundNewsArticle', 'ReviewNewsArticle', 'SatireNewsArticle', 'AskPublicNewsArticle', 'ScholarlyArticle', 'TechArticle', 'AdvertiserContentArticle' );
			foreach ( $types as $type ) {
				if ( is_string( $type ) && in_array( $type, $article_types, true ) ) {
					$articles[] = $value;
					break; }
			}
		}
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				self::collect_schema_nodes( $child, $articles, $ids ); }
		}
	}

	private static function has_value( $node, $key ) {
		if ( ! array_key_exists( $key, $node ) ) {
			return false; }
		$value = $node[ $key ];
		if ( is_string( $value ) ) {
			return '' !== trim( $value ); }
		return ! empty( $value );
	}

	private static function valid_schema_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:[Tt ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?)?$/', trim( $value ) ) ) {
			return false; }
		$date = date_parse( trim( $value ) );
		return is_array( $date ) && empty( $date['error_count'] ) && empty( $date['warning_count'] ) && false !== strtotime( trim( $value ) );
	}

	private static function canonical_url( $html ) {
		if ( ! preg_match_all( '#<link\b[^>]*>#i', (string) $html, $tags ) ) {
			return ''; }
		foreach ( $tags[0] as $tag ) {
			$rel = self::attribute( $tag, 'rel' );
			if ( 'canonical' === strtolower( trim( $rel ) ) ) {
				return self::attribute( $tag, 'href' ); }
		}
		return '';
	}

	private static function attribute( $tag, $name ) {
		$pattern = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is';
		return preg_match( $pattern, (string) $tag, $match ) ? html_entity_decode( trim( $match[2] ), ENT_QUOTES, 'UTF-8' ) : '';
	}

	private static function normalize_url( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return rtrim( trim( (string) $url ), '/' ); }
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' ); }
		return strtolower( (string) ( $parts['scheme'] ?? '' ) ) . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	private static function same_site_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! is_array( $home ) || ! isset( $home['scheme'], $home['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false; }
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || 0 !== strcasecmp( $scheme, (string) $home['scheme'] ) || 0 !== strcasecmp( (string) $parts['host'], (string) $home['host'] ) ) {
			return false; }
		$default_port = 'https' === $scheme ? 443 : 80;
		return (int) ( $parts['port'] ?? $default_port ) === (int) ( $home['port'] ?? $default_port );
	}
}
