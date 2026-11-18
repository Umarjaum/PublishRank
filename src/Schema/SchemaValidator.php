<?php
/**
 * Offline structured-data checker used by the editor preview and the SEO Inspector.
 *
 * Rules follow Google Search Central's documented required/recommended properties for rich-result
 * types, and schema.org expectations for other types. It checks presence and format (absolute URLs,
 * ISO 8601 dates/durations, prices, currencies, ratings). It does not contact Google: the result says
 * whether the markup is well formed, not whether Google will show a rich result.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Schema;

defined( 'ABSPATH' ) || exit;

final class SchemaValidator {

	/**
	 * Per type: required (error when missing), recommended (warning), and format rules.
	 * Alternatives are written "a|b" (any one is enough).
	 *
	 * @return array<string,array{required?:string[],recommended?:string[],doc?:string}>
	 */
	public static function rules() {
		$article = array( 'required' => array( 'headline', 'datePublished' ), 'recommended' => array( 'image', 'author', 'dateModified', 'publisher' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/article' );
		$rules   = array(
			'Article'                => $article,
			'NewsArticle'            => $article,
			'BlogPosting'            => $article,
			'LiveBlogPosting'        => array( 'required' => array( 'headline', 'datePublished', 'coverageStartTime' ), 'recommended' => array( 'liveBlogUpdate', 'image', 'author' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/article' ),
			'Product'                => array( 'required' => array( 'name', 'offers|review|aggregateRating' ), 'recommended' => array( 'image', 'description', 'sku|gtin|gtin8|gtin12|gtin13|gtin14|mpn', 'brand' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/product' ),
			'ProductGroup'           => array( 'required' => array( 'name', 'hasVariant|productGroupID' ), 'recommended' => array( 'variesBy', 'brand' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/product-variants' ),
			'Offer'                  => array( 'required' => array( 'price|priceSpecification', 'priceCurrency|priceSpecification' ), 'recommended' => array( 'availability', 'url' ) ),
			'AggregateOffer'         => array( 'required' => array( 'lowPrice', 'priceCurrency' ), 'recommended' => array( 'offerCount', 'highPrice' ) ),
			'AggregateRating'        => array( 'required' => array( 'ratingValue', 'ratingCount|reviewCount' ), 'recommended' => array( 'bestRating' ) ),
			'Review'                 => array( 'required' => array( 'author', 'reviewRating|reviewBody' ), 'recommended' => array( 'itemReviewed', 'datePublished' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/review-snippet' ),
			'Rating'                 => array( 'required' => array( 'ratingValue' ), 'recommended' => array( 'bestRating' ) ),
			'Event'                  => array( 'required' => array( 'name', 'startDate', 'location' ), 'recommended' => array( 'endDate', 'eventStatus', 'image', 'description', 'offers', 'organizer', 'eventAttendanceMode' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/event' ),
			'Recipe'                 => array( 'required' => array( 'name', 'image' ), 'recommended' => array( 'recipeIngredient', 'recipeInstructions', 'author', 'totalTime|cookTime', 'recipeYield', 'nutrition' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/recipe' ),
			'JobPosting'             => array( 'required' => array( 'title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation|jobLocationType' ), 'recommended' => array( 'validThrough', 'employmentType', 'baseSalary', 'identifier' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/job-posting' ),
			'Course'                 => array( 'required' => array( 'name', 'description', 'provider' ), 'recommended' => array( 'offers', 'hasCourseInstance' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/course' ),
			'HowTo'                  => array( 'required' => array( 'name', 'step' ), 'recommended' => array( 'totalTime', 'supply', 'tool', 'image' ) ),
			'VideoObject'            => array( 'required' => array( 'name', 'thumbnailUrl', 'uploadDate' ), 'recommended' => array( 'description', 'duration', 'contentUrl|embedUrl' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/video' ),
			'ImageObject'            => array( 'required' => array( 'contentUrl|url' ), 'recommended' => array( 'creator', 'creditText', 'copyrightNotice', 'license' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/image-license-metadata' ),
			'LocalBusiness'          => array( 'required' => array( 'name', 'address' ), 'recommended' => array( 'telephone', 'url', 'geo', 'openingHoursSpecification|openingHours', 'priceRange', 'image' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/local-business' ),
			'Organization'           => array( 'required' => array( 'name' ), 'recommended' => array( 'url', 'logo', 'sameAs' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/organization' ),
			'NewsMediaOrganization'  => array( 'required' => array( 'name' ), 'recommended' => array( 'url', 'logo', 'sameAs', 'publishingPrinciples' ) ),
			'Person'                 => array( 'required' => array( 'name' ), 'recommended' => array( 'url|sameAs' ) ),
			'BreadcrumbList'         => array( 'required' => array( 'itemListElement' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/breadcrumb' ),
			'ListItem'               => array( 'required' => array( 'position' ), 'recommended' => array( 'name|item' ) ),
			'FAQPage'                => array( 'required' => array( 'mainEntity' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/faqpage' ),
			'Question'               => array( 'required' => array( 'name', 'acceptedAnswer|suggestedAnswer' ) ),
			'Answer'                 => array( 'required' => array( 'text' ) ),
			'QAPage'                 => array( 'required' => array( 'mainEntity' ) ),
			'WebSite'                => array( 'required' => array( 'name', 'url' ) ),
			'WebPage'                => array( 'required' => array( 'url|@id' ), 'recommended' => array( 'name' ) ),
			'SoftwareApplication'    => array( 'required' => array( 'name', 'offers', 'aggregateRating|review' ), 'recommended' => array( 'operatingSystem', 'applicationCategory' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/software-app' ),
			'Book'                   => array( 'required' => array( 'name', 'author' ), 'recommended' => array( 'isbn', 'url' ) ),
			'Movie'                  => array( 'required' => array( 'name', 'image' ), 'recommended' => array( 'dateCreated', 'director' ) ),
			'Dataset'                => array( 'required' => array( 'name', 'description' ), 'recommended' => array( 'license', 'creator', 'distribution' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/dataset' ),
			'ClaimReview'            => array( 'required' => array( 'claimReviewed', 'reviewRating', 'url', 'author' ), 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/factcheck' ),
			'DiscussionForumPosting' => array( 'required' => array( 'author', 'datePublished', 'text|image|video' ) ),
			'SpecialAnnouncement'    => array( 'required' => array( 'name', 'datePosted' ) ),
			'Service'                => array( 'required' => array( 'name' ), 'recommended' => array( 'provider', 'areaServed' ) ),
			'TouristAttraction'      => array( 'required' => array( 'name' ), 'recommended' => array( 'address|geo' ) ),
			'MusicAlbum'             => array( 'required' => array( 'name', 'byArtist' ) ),
			'VideoGame'              => array( 'required' => array( 'name' ), 'recommended' => array( 'gamePlatform' ) ),
			'Quiz'                   => array( 'required' => array( 'hasPart' ) ),
			'PostalAddress'          => array( 'recommended' => array( 'streetAddress', 'addressLocality', 'addressCountry' ) ),
		);
		return (array) apply_filters( 'publishrank_schema_validator_rules', $rules );
	}

	/** Properties that must be absolute http(s) URLs when present as strings. */
	const URL_PROPS = array( 'url', 'logo', 'image', 'thumbnailUrl', 'contentUrl', 'embedUrl', 'sameAs', 'item', 'license' );
	const DATE_PROPS = array( 'datePublished', 'dateModified', 'dateCreated', 'uploadDate', 'startDate', 'endDate', 'datePosted', 'validThrough', 'validFrom', 'birthDate', 'deathDate', 'coverageStartTime', 'coverageEndTime', 'priceValidUntil' );
	const DURATION_PROPS = array( 'duration', 'totalTime', 'cookTime', 'prepTime', 'timeRequired' );

	/** Known LocalBusiness subtypes are checked as LocalBusiness. */
	private static function rule_type( $type ) {
		$rules = self::rules();
		if ( isset( $rules[ $type ] ) ) {
			return $type;
		}
		$local = array( 'Restaurant', 'CafeOrCoffeeShop', 'BarOrPub', 'Hotel', 'Store', 'MedicalClinic', 'Dentist', 'LegalService', 'RealEstateAgent', 'AutoRepair', 'HealthClub', 'Library', 'Museum', 'MovieTheater', 'PerformingArtsTheater', 'Bakery', 'FoodEstablishment', 'LodgingBusiness', 'ProfessionalService' );
		if ( in_array( $type, $local, true ) ) {
			return 'LocalBusiness';
		}
		if ( in_array( $type, array( 'ReportageNewsArticle', 'AnalysisNewsArticle', 'OpinionNewsArticle', 'BackgroundNewsArticle', 'ReviewNewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' ), true ) ) {
			return 'Article';
		}
		if ( in_array( $type, array( 'ItemPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'ProfilePage', 'SearchResultsPage', 'CheckoutPage', 'MedicalWebPage' ), true ) ) {
			return 'WebPage';
		}
		if ( in_array( $type, array( 'MobileApplication', 'WebApplication' ), true ) ) {
			return 'SoftwareApplication';
		}
		return '';
	}

	/**
	 * Check one entity and every entity nested in it.
	 *
	 * @param array  $node
	 * @param string $path Human path for messages.
	 * @return array<int,array{level:string,area:string,msg:string}>
	 */
	public static function check( array $node, $path = '' ) {
		$out   = array();
		$types = isset( $node['@type'] ) ? array_map( 'strval', (array) $node['@type'] ) : array();
		$label = '' !== $path ? $path : ( $types ? implode( '/', $types ) : 'Node' );
		$ref   = isset( $node['@id'] ) && 1 === count( $node );
		$add   = static function ( $level, $msg ) use ( &$out, $label ) {
			$out[] = array( 'level' => $level, 'area' => $label, 'msg' => $msg );
		};
		if ( ! $types && ! $ref && '' === $path ) {
			$add( 'error', __( 'Entity has no @type.', 'publishrank' ) );
		}
		$has = static function ( $alts ) use ( $node ) {
			foreach ( explode( '|', $alts ) as $p ) {
				if ( isset( $node[ $p ] ) && '' !== $node[ $p ] && array() !== $node[ $p ] ) {
					return true;
				}
			}
			return false;
		};
		$rules = self::rules();
		foreach ( $types as $type ) {
			$rt = self::rule_type( $type );
			if ( '' === $rt || $ref ) {
				continue;
			}
			foreach ( $rules[ $rt ]['required'] ?? array() as $p ) {
				if ( ! $has( $p ) ) {
					/* translators: 1: type, 2: property names */
					$add( 'error', sprintf( __( '%1$s is missing required property %2$s.', 'publishrank' ), $type, str_replace( '|', ' / ', $p ) ) );
				}
			}
			foreach ( $rules[ $rt ]['recommended'] ?? array() as $p ) {
				if ( ! $has( $p ) ) {
					/* translators: 1: type, 2: property names */
					$add( 'info', sprintf( __( '%1$s: %2$s is recommended.', 'publishrank' ), $type, str_replace( '|', ' / ', $p ) ) );
				}
			}
		}
		// Formats.
		foreach ( $node as $prop => $value ) {
			if ( ! is_string( $prop ) || '@' === $prop[0] ) {
				continue;
			}
			$values = is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) ? $value : array( $value );
			foreach ( $values as $v ) {
				if ( is_string( $v ) ) {
					if ( in_array( $prop, self::URL_PROPS, true ) && ! preg_match( '#^https?://[^\s]+$#i', $v ) ) {
						/* translators: %s: property */
						$add( 'error', sprintf( __( '%s must be an absolute http(s) URL.', 'publishrank' ), $prop ) );
					} elseif ( in_array( $prop, self::DATE_PROPS, true ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $v ) ) {
						/* translators: %s: property */
						$add( 'error', sprintf( __( '%s must be an ISO 8601 date such as 2026-05-01 or 2026-05-01T09:30:00+00:00.', 'publishrank' ), $prop ) );
					} elseif ( in_array( $prop, self::DURATION_PROPS, true ) && ! preg_match( '/^P(?!$)(?:\d+Y)?(?:\d+M)?(?:\d+W)?(?:\d+D)?(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+(?:\.\d+)?S)?)?$/', $v ) ) {
						/* translators: %s: property */
						$add( 'error', sprintf( __( '%s must be an ISO 8601 duration such as PT1H30M.', 'publishrank' ), $prop ) );
					} elseif ( 'priceCurrency' === $prop && ! preg_match( '/^[A-Z]{3}$/', $v ) ) {
						$add( 'error', __( 'priceCurrency must be a 3-letter ISO 4217 code such as USD.', 'publishrank' ) );
					} elseif ( in_array( $prop, array( 'price', 'lowPrice', 'highPrice' ), true ) && ! is_numeric( $v ) ) {
						/* translators: %s: property */
						$add( 'error', sprintf( __( '%s must be a number without currency symbols.', 'publishrank' ), $prop ) );
					} elseif ( in_array( $prop, array( 'headline' ), true ) && function_exists( 'mb_strlen' ) && mb_strlen( $v ) > 110 ) {
						$add( 'warn', __( 'headline is over 110 characters; Google may truncate it.', 'publishrank' ) );
					}
				}
				if ( in_array( $prop, array( 'ratingValue' ), true ) && is_scalar( $v ) ) {
					$best  = isset( $node['bestRating'] ) && is_numeric( $node['bestRating'] ) ? (float) $node['bestRating'] : 5.0;
					$worst = isset( $node['worstRating'] ) && is_numeric( $node['worstRating'] ) ? (float) $node['worstRating'] : 1.0;
					if ( ! is_numeric( $v ) || (float) $v < $worst || (float) $v > $best ) {
						/* translators: 1: worst, 2: best */
						$add( 'error', sprintf( __( 'ratingValue must be a number between %1$s and %2$s.', 'publishrank' ), $worst, $best ) );
					}
				}
				if ( is_array( $v ) && ! ( isset( $v['@id'] ) && 1 === count( $v ) ) ) {
					$child = isset( $v['@type'] ) ? implode( '/', (array) $v['@type'] ) : $prop;
					$out   = array_merge( $out, self::check( $v, $label . ' › ' . $child ) );
				}
			}
		}
		if ( in_array( 'FAQPage', $types, true ) && isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ) {
			$names = array();
			foreach ( (array) $node['mainEntity'] as $q ) {
				if ( is_array( $q ) && isset( $q['name'] ) && is_string( $q['name'] ) ) {
					$k = strtolower( $q['name'] );
					if ( isset( $names[ $k ] ) ) {
						$add( 'warn', __( 'The same question appears twice in FAQPage.', 'publishrank' ) );
						break;
					}
					$names[ $k ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Check a whole page: every JSON-LD block, duplicate singletons, dangling references.
	 *
	 * @param string[] $raw_blocks Raw JSON-LD strings.
	 * @return array{blocks:array,nodes:array,issues:array,summary:array}
	 */
	public static function check_blocks( array $raw_blocks ) {
		$blocks = array();
		$nodes  = array();
		$issues = array();
		$defined = array();
		$refs    = array();
		$type_count = array();
		$walk = static function ( $v ) use ( &$walk, &$refs, &$defined ) {
			if ( ! is_array( $v ) ) {
				return;
			}
			if ( isset( $v['@id'] ) && is_string( $v['@id'] ) ) {
				if ( 1 === count( $v ) ) {
					$refs[ $v['@id'] ] = true;
				} else {
					$defined[ $v['@id'] ] = true;
				}
			}
			foreach ( $v as $c ) {
				$walk( $c );
			}
		};
		foreach ( $raw_blocks as $i => $raw ) {
			$data = json_decode( (string) $raw, true );
			if ( ! is_array( $data ) ) {
				$blocks[] = array( 'pretty' => (string) $raw, 'valid' => false, 'error' => json_last_error_msg(), 'types' => array() );
				/* translators: 1: block number, 2: parser error */
				$issues[] = array( 'level' => 'error', 'area' => 'JSON-LD', 'msg' => sprintf( __( 'JSON-LD block %1$d is not valid JSON (%2$s). Search engines ignore it.', 'publishrank' ), $i + 1, json_last_error_msg() ) );
				continue;
			}
			$list  = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ? $data : array( $data ) );
			$types = array();
			foreach ( $list as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$t = isset( $node['@type'] ) ? array_map( 'strval', (array) $node['@type'] ) : array();
				foreach ( $t as $one ) {
					$type_count[ $one ] = ( $type_count[ $one ] ?? 0 ) + 1;
				}
				$types   = array_merge( $types, $t );
				$node_issues = self::check( $node );
				$nodes[] = array(
					'type'   => implode( ', ', $t ),
					'id'     => (string) ( $node['@id'] ?? '' ),
					'name'   => (string) ( is_scalar( $node['headline'] ?? null ) ? $node['headline'] : ( is_scalar( $node['name'] ?? null ) ? $node['name'] : '' ) ),
					'errors' => count( array_filter( $node_issues, static function ( $x ) { return 'error' === $x['level']; } ) ),
				);
				$issues = array_merge( $issues, $node_issues );
				$walk( $node );
			}
			if ( ! isset( $data['@context'] ) || false === stripos( wp_json_encode( $data['@context'] ), 'schema.org' ) ) {
				/* translators: %d: block number */
				$issues[] = array( 'level' => 'error', 'area' => 'JSON-LD', 'msg' => sprintf( __( 'JSON-LD block %d has no schema.org @context.', 'publishrank' ), $i + 1 ) );
			}
			$blocks[] = array( 'pretty' => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'valid' => true, 'error' => '', 'types' => array_values( array_unique( $types ) ) );
		}
		foreach ( array( 'FAQPage', 'BreadcrumbList', 'WebSite' ) as $single ) {
			if ( ( $type_count[ $single ] ?? 0 ) > 1 ) {
				/* translators: %s: type */
				$issues[] = array( 'level' => 'warn', 'area' => 'JSON-LD', 'msg' => sprintf( __( '%s appears more than once on this page. Another plugin or the theme may be adding a second copy.', 'publishrank' ), $single ) );
			}
		}
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array_keys( $refs ) as $ref ) {
			if ( ! isset( $defined[ $ref ] ) && (string) wp_parse_url( $ref, PHP_URL_HOST ) === $home ) {
				/* translators: %s: @id */
				$issues[] = array( 'level' => 'warn', 'area' => 'JSON-LD', 'msg' => sprintf( __( 'A node refers to %s but that entity is not in the graph.', 'publishrank' ), $ref ) );
			}
		}
		$summary = array( 'error' => 0, 'warn' => 0, 'info' => 0 );
		foreach ( $issues as $x ) {
			$summary[ $x['level'] ] = ( $summary[ $x['level'] ] ?? 0 ) + 1;
		}
		return array( 'blocks' => $blocks, 'nodes' => $nodes, 'issues' => $issues, 'summary' => $summary );
	}

	/**
	 * Parse the SEO-relevant parts of an HTML document.
	 *
	 * @return array{title:string,meta:array,canonical:array,jsonld:string[],h1:string[],lang:string}
	 */
	public static function parse_html( $html ) {
		$out = array( 'title' => '', 'meta' => array(), 'canonical' => array(), 'jsonld' => array(), 'h1' => array(), 'lang' => '', 'hreflang' => array() );
		if ( '' === trim( (string) $html ) || ! class_exists( '\DOMDocument' ) ) {
			return $out;
		}
		$dom  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$t = $dom->getElementsByTagName( 'title' );
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM properties.
		$out['title'] = $t->length ? trim( $t->item( 0 )->textContent ) : '';
		$html_el      = $dom->getElementsByTagName( 'html' );
		$out['lang']  = $html_el->length ? (string) $html_el->item( 0 )->getAttribute( 'lang' ) : '';
		foreach ( $dom->getElementsByTagName( 'meta' ) as $m ) {
			$key = $m->getAttribute( 'property' ) ? $m->getAttribute( 'property' ) : $m->getAttribute( 'name' );
			if ( '' !== $key ) {
				$out['meta'][ strtolower( $key ) ][] = $m->getAttribute( 'content' );
			}
		}
		foreach ( $dom->getElementsByTagName( 'link' ) as $l ) {
			$rel = strtolower( $l->getAttribute( 'rel' ) );
			if ( 'canonical' === $rel ) {
				$out['canonical'][] = $l->getAttribute( 'href' );
			} elseif ( 'alternate' === $rel && $l->getAttribute( 'hreflang' ) ) {
				$out['hreflang'][ $l->getAttribute( 'hreflang' ) ] = $l->getAttribute( 'href' );
			}
		}
		foreach ( $dom->getElementsByTagName( 'script' ) as $s ) {
			if ( 'application/ld+json' === strtolower( trim( $s->getAttribute( 'type' ) ) ) ) {
				$out['jsonld'][] = trim( $s->textContent );
			}
		}
		foreach ( $dom->getElementsByTagName( 'h1' ) as $h ) {
			$out['h1'][] = trim( preg_replace( '/\s+/u', ' ', $h->textContent ) );
		}
		// phpcs:enable
		return $out;
	}
}
