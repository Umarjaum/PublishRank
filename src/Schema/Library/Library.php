<?php
/**
 * Schema Library: editor panel, validation, JSON-LD nodes and REST API for the extra schema types.
 *
 * Storage: post meta `_publishrank_schema_library` = list of { type, data } (max 5 per post).
 * Output: nodes are appended to the existing PublishRank graph through `publishrank_schema_graph` and only when
 * every required field validates. Invalid entries are reported in the editor and REST validation, never printed.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Library;

use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Library {

	const META      = '_publishrank_schema_library';
	const MAX_NODES = 5;
	const NONCE     = 'publishrank_schema_library';

	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 20, 2 );
		add_filter( 'publishrank_schema_graph', array( $this, 'graph' ), 30 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		register_post_meta( '', self::META, array( 'show_in_rest' => false, 'single' => true, 'type' => 'array', 'auth_callback' => static function ( $allowed, $key, $post_id ) { return current_user_can( 'edit_post', $post_id ); } ) );
	}

	/* ------------------------------------------------------------ Validation */

	/** @return array{value:mixed,error:string} */
	public static function check( array $field, $raw ) {
		$kind = $field['kind'];
		// Stored values round-trip through here: scalars become strings, list values become one item per line.
		if ( is_array( $raw ) ) {
			$raw = 'lines' === $kind ? implode( "\n", array_filter( $raw, 'is_scalar' ) ) : '';
		}
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $raw ) {
			return array( 'value' => '', 'error' => ! empty( $field['required'] ) ? __( 'Required.', 'publishrank' ) : '' );
		}
		switch ( $kind ) {
			case 'text':
				$v = sanitize_text_field( $raw );
				return array( 'value' => substr( $v, 0, 300 ), 'error' => '' );
			case 'textarea':
				$v = sanitize_textarea_field( $raw );
				if ( ! empty( $field['min'] ) && strlen( $v ) < (int) $field['min'] ) {
					return array( 'value' => $v, 'error' => sprintf( /* translators: %d: characters */ __( 'At least %d characters.', 'publishrank' ), (int) $field['min'] ) ); }
				return array( 'value' => substr( $v, 0, ! empty( $field['max'] ) ? (int) $field['max'] : 5000 ), 'error' => '' );
			case 'url':
				$v = esc_url_raw( $raw, array( 'http', 'https' ) );
				return $v && wp_http_validate_url( $v ) ? array( 'value' => $v, 'error' => '' ) : array( 'value' => '', 'error' => __( 'Enter a full web address starting with https://', 'publishrank' ) );
			case 'date':
				$ok = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) && checkdate( (int) substr( $raw, 5, 2 ), (int) substr( $raw, 8, 2 ), (int) substr( $raw, 0, 4 ) );
				return $ok ? array( 'value' => $raw, 'error' => '' ) : array( 'value' => '', 'error' => __( 'Use the format YYYY-MM-DD.', 'publishrank' ) );
			case 'datetime':
				$ok = preg_match( '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?$/', $raw ) && false !== strtotime( $raw );
				return $ok ? array( 'value' => str_replace( ' ', 'T', $raw ), 'error' => '' ) : array( 'value' => '', 'error' => __( 'Use YYYY-MM-DD or YYYY-MM-DDThh:mm.', 'publishrank' ) );
			case 'duration':
				$v  = strtoupper( $raw );
				$ok = preg_match( '/^P(?!$)(?:\d+Y)?(?:\d+M)?(?:\d+W)?(?:\d+D)?(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+(?:\.\d+)?S)?)?$/', $v );
				return $ok ? array( 'value' => $v, 'error' => '' ) : array( 'value' => '', 'error' => __( 'Use an ISO 8601 duration such as PT45M or P6M.', 'publishrank' ) );
			case 'number':
				return is_numeric( $raw ) ? array( 'value' => 0 + $raw, 'error' => '' ) : array( 'value' => '', 'error' => __( 'Enter a number.', 'publishrank' ) );
			case 'money':
				return preg_match( '/^\d+(?:\.\d{1,2})?$/', $raw ) ? array( 'value' => number_format( (float) $raw, 2, '.', '' ), 'error' => '' ) : array( 'value' => '', 'error' => __( 'Enter an amount such as 9.99.', 'publishrank' ) );
			case 'currency':
				$v = strtoupper( $raw );
				return preg_match( '/^[A-Z]{3}$/', $v ) ? array( 'value' => $v, 'error' => '' ) : array( 'value' => '', 'error' => __( 'Use a 3-letter code such as USD.', 'publishrank' ) );
			case 'country':
				$v = strtoupper( $raw );
				return preg_match( '/^[A-Z]{2}$/', $v ) ? array( 'value' => $v, 'error' => '' ) : array( 'value' => '', 'error' => __( 'Use a 2-letter country code such as GB.', 'publishrank' ) );
			case 'rating':
				return is_numeric( $raw ) && (float) $raw >= 1 && (float) $raw <= 5 ? array( 'value' => 0 + $raw, 'error' => '' ) : array( 'value' => '', 'error' => __( 'Enter a rating from 1 to 5.', 'publishrank' ) );
			case 'select':
				return in_array( $raw, (array) $field['options'], true ) ? array( 'value' => $raw, 'error' => '' ) : array( 'value' => '', 'error' => __( 'Choose one of the listed options.', 'publishrank' ) );
			case 'lines':
				$lines = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', (string) $raw ) ) ) );
				return $lines ? array( 'value' => array_slice( $lines, 0, 50 ), 'error' => '' ) : array( 'value' => array(), 'error' => ! empty( $field['required'] ) ? __( 'Required.', 'publishrank' ) : '' );
		}
		return array( 'value' => '', 'error' => __( 'Unsupported field.', 'publishrank' ) );
	}

	/** Validate and normalise one entry. @return array{type:string,data:array,errors:array<string,string>,valid:bool} */
	public static function normalise( $type, array $input ) {
		$defs   = Definitions::all();
		$out    = array( 'type' => (string) $type, 'data' => array(), 'errors' => array(), 'valid' => false );
		if ( ! isset( $defs[ $type ] ) ) {
			$out['errors']['_type'] = __( 'Unknown schema type.', 'publishrank' );
			return $out;
		}
		foreach ( $defs[ $type ]['fields'] as $key => $field ) {
			$res = self::check( $field, isset( $input[ $key ] ) ? $input[ $key ] : '' );
			if ( '' !== $res['error'] ) {
				$out['errors'][ $key ] = $res['error']; }
			if ( '' !== $res['value'] && array() !== $res['value'] ) {
				$out['data'][ $key ] = $res['value']; }
		}
		if ( 'Quiz' === $type && ! isset( $out['errors']['questions'] ) ) {
			foreach ( (array) ( $out['data']['questions'] ?? array() ) as $line ) {
				if ( false === strpos( $line, '|' ) ) {
					$out['errors']['questions'] = __( 'Each line needs "Question | Answer".', 'publishrank' );
					break; }
			}
		}
		$out['valid'] = ! $out['errors'];
		return $out;
	}

	/* ------------------------------------------------------------ JSON-LD */

	/** @return array<string,mixed>|null */
	public static function node( $type, array $d, $url, $post_id = 0 ) {
		$id   = $url . '#' . strtolower( $type );
		$base = array( '@type' => $type, '@id' => $id );
		$org  = static function ( $name, $u = '' ) {
			return array_filter( array( '@type' => 'Organization', 'name' => $name, 'url' => $u ) );
		};
		switch ( $type ) {
			case 'HowTo':
				$n = $base + array( 'name' => $d['name'], 'step' => array() );
				foreach ( $d['steps'] as $i => $text ) {
					$n['step'][] = array( '@type' => 'HowToStep', 'position' => $i + 1, 'text' => $text ); }
				foreach ( array( 'supply' => 'HowToSupply', 'tool' => 'HowToTool' ) as $k => $t ) {
					foreach ( (array) ( $d[ $k ] ?? array() ) as $name ) {
						$n[ $k ][] = array( '@type' => $t, 'name' => $name ); }
				}
				if ( ! empty( $d['totalTime'] ) ) {
					$n['totalTime'] = $d['totalTime']; }
				return $n;
			case 'Course':
				return $base + array( 'name' => $d['name'], 'description' => $d['description'], 'provider' => $org( $d['provider'], $d['providerUrl'] ?? '' ) ) + ( isset( $d['courseCode'] ) ? array( 'courseCode' => $d['courseCode'] ) : array() );
			case 'EducationalOccupationalProgram':
				$n = $base + array( 'name' => $d['name'], 'provider' => $org( $d['provider'] ) );
				foreach ( array( 'timeToComplete', 'educationalCredentialAwarded' ) as $k ) {
					if ( isset( $d[ $k ] ) ) {
						$n[ $k ] = $d[ $k ]; }
				}
				if ( isset( $d['offerPrice'], $d['priceCurrency'] ) ) {
					$n['offers'] = array( '@type' => 'Offer', 'price' => $d['offerPrice'], 'priceCurrency' => $d['priceCurrency'] ); }
				return $n;
			case 'SoftwareApplication':
				$n = $base + array( 'name' => $d['name'], 'applicationCategory' => $d['applicationCategory'], 'operatingSystem' => $d['operatingSystem'], 'offers' => array( '@type' => 'Offer', 'price' => $d['price'], 'priceCurrency' => $d['priceCurrency'] ) );
				if ( isset( $d['ratingValue'], $d['ratingCount'] ) ) {
					$n['aggregateRating'] = array( '@type' => 'AggregateRating', 'ratingValue' => $d['ratingValue'], 'ratingCount' => (int) $d['ratingCount'] ); }
				return $n;
			case 'Book':
				$n = $base + array( 'name' => $d['name'], 'author' => array( '@type' => 'Person', 'name' => $d['author'] ) );
				foreach ( array( 'isbn', 'datePublished', 'numberOfPages' ) as $k ) {
					if ( isset( $d[ $k ] ) ) {
						$n[ $k ] = $d[ $k ]; }
				}
				if ( isset( $d['publisher'] ) ) {
					$n['publisher'] = $org( $d['publisher'] ); }
				return $n;
			case 'Movie':
				$n = $base + array( 'name' => $d['name'], 'image' => $d['image'] );
				if ( isset( $d['dateCreated'] ) ) {
					$n['dateCreated'] = $d['dateCreated']; }
				if ( isset( $d['director'] ) ) {
					$n['director'] = array( '@type' => 'Person', 'name' => $d['director'] ); }
				return $n;
			case 'MusicAlbum':
				$n = $base + array( 'name' => $d['name'], 'byArtist' => array( '@type' => 'MusicGroup', 'name' => $d['byArtist'] ) );
				foreach ( array( 'datePublished', 'numTracks' ) as $k ) {
					if ( isset( $d[ $k ] ) ) {
						$n[ $k ] = $d[ $k ]; }
				}
				return $n;
			case 'LocalBusiness':
				$n = array( '@type' => $d['businessType'], '@id' => $id, 'name' => $d['name'], 'address' => array_filter( array( '@type' => 'PostalAddress', 'streetAddress' => $d['streetAddress'], 'addressLocality' => $d['addressLocality'], 'postalCode' => $d['postalCode'] ?? '', 'addressCountry' => $d['addressCountry'] ) ) );
				foreach ( array( 'telephone', 'priceRange', 'url' ) as $k ) {
					if ( isset( $d[ $k ] ) ) {
						$n[ $k ] = $d[ $k ]; }
				}
				if ( ! empty( $d['openingHours'] ) ) {
					$n['openingHours'] = $d['openingHours']; }
				return $n;
			case 'TouristAttraction':
				$n = $base + array( 'name' => $d['name'], 'address' => array( '@type' => 'PostalAddress', 'addressLocality' => $d['addressLocality'], 'addressCountry' => $d['addressCountry'] ) );
				if ( isset( $d['latitude'], $d['longitude'] ) ) {
					$n['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => $d['latitude'], 'longitude' => $d['longitude'] ); }
				return $n;
			case 'Service':
				return $base + array( 'name' => $d['name'], 'serviceType' => $d['serviceType'], 'provider' => $org( $d['provider'] ) ) + ( isset( $d['areaServed'] ) ? array( 'areaServed' => $d['areaServed'] ) : array() );
			case 'Dataset':
				$n = $base + array( 'name' => $d['name'], 'description' => $d['description'] );
				foreach ( array( 'license', 'temporalCoverage' ) as $k ) {
					if ( isset( $d[ $k ] ) ) {
						$n[ $k ] = $d[ $k ]; }
				}
				if ( isset( $d['creator'] ) ) {
					$n['creator'] = $org( $d['creator'] ); }
				if ( isset( $d['contentUrl'] ) ) {
					$n['distribution'] = array_filter( array( '@type' => 'DataDownload', 'contentUrl' => $d['contentUrl'], 'encodingFormat' => $d['encodingFormat'] ?? '' ) ); }
				return $n;
			case 'ClaimReview':
				$n = $base + array(
					'url'           => $url,
					'claimReviewed' => $d['claimReviewed'],
					'itemReviewed'  => array_filter( array( '@type' => 'Claim', 'author' => array( '@type' => 'Person', 'name' => $d['claimAuthor'] ), 'datePublished' => $d['claimDate'] ?? '' ) ),
					'reviewRating'  => array( '@type' => 'Rating', 'ratingValue' => $d['ratingValue'], 'bestRating' => 5, 'worstRating' => 1, 'alternateName' => $d['alternateName'] ),
					'author'        => $org( \PublishRankSEO\Feeds\FeedManager::publisher_name(), home_url( '/' ) ),
				);
				return $n;
			case 'QAPage':
				$question = array( '@type' => 'Question', 'name' => $d['question'], 'answerCount' => (int) ( $d['answerCount'] ?? 1 ), 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $d['answer'] ) );
				if ( isset( $d['questionText'] ) ) {
					$question['text'] = $d['questionText']; }
				return $base + array( 'mainEntity' => $question );
			case 'DiscussionForumPosting':
				$author = $post_id ? get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) ) : '';
				return $base + array( 'headline' => $d['headline'], 'text' => $d['text'], 'url' => $url ) + ( $post_id ? array( 'datePublished' => get_post_time( DATE_W3C, true, $post_id ), 'author' => array( '@type' => 'Person', 'name' => $author ) ) : array() );
			case 'SpecialAnnouncement':
				$n = $base + array( 'name' => $d['name'], 'text' => $d['text'], 'datePosted' => $d['datePosted'] );
				foreach ( array( 'expires', 'category', 'spatialCoverage' ) as $k ) {
					if ( isset( $d[ $k ] ) ) {
						$n[ $k ] = $d[ $k ]; }
				}
				return $n;
			case 'Quiz':
				$parts = array();
				foreach ( $d['questions'] as $line ) {
					list( $q, $a ) = array_map( 'trim', explode( '|', $line, 2 ) );
					$parts[]       = array( '@type' => 'Question', 'name' => $q, 'eduQuestionType' => 'Flashcard', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $a ) );
				}
				return $base + array( 'name' => $d['name'], 'hasPart' => $parts ) + ( isset( $d['about'] ) ? array( 'about' => array( '@type' => 'Thing', 'name' => $d['about'] ) ) : array() );
			case 'VideoGame':
				return $base + array( 'name' => $d['name'], 'gamePlatform' => $d['gamePlatform'] ) + ( isset( $d['publisher'] ) ? array( 'publisher' => $org( $d['publisher'] ) ) : array() ) + ( isset( $d['genre'] ) ? array( 'genre' => $d['genre'] ) : array() );
			case 'Person':
				$n = $base + array( 'name' => $d['name'] );
				foreach ( array( 'jobTitle', 'birthDate', 'deathDate' ) as $k ) {
					if ( isset( $d[ $k ] ) ) {
						$n[ $k ] = $d[ $k ]; }
				}
				if ( ! empty( $d['sameAs'] ) ) {
					$n['sameAs'] = array_values( array_filter( array_map( 'esc_url_raw', $d['sameAs'] ) ) ); }
				return $n;
		}
		return null;
	}

	/** Stored entries for a post. @return array<int,array{type:string,data:array}> */
	public static function entries( $post_id ) {
		$raw = get_post_meta( $post_id, self::META, true );
		return is_array( $raw ) ? array_slice( array_values( array_filter( $raw, 'is_array' ) ), 0, self::MAX_NODES ) : array();
	}

	/** Valid nodes of a post (what is printed). @return array<int,array<string,mixed>> */
	public static function nodes_for( $post_id ) {
		$url   = get_permalink( $post_id );
		$nodes = array();
		foreach ( self::entries( $post_id ) as $e ) {
			$n = self::normalise( (string) ( $e['type'] ?? '' ), (array) ( $e['data'] ?? array() ) );
			if ( ! $n['valid'] ) {
				continue; }
			$node = self::node( $n['type'], $n['data'], $url, (int) $post_id );
			if ( $node ) {
				$nodes[] = $node; }
		}
		return $nodes;
	}

	public function graph( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) {
			return $graph; }
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return $graph; }
		foreach ( self::nodes_for( $post->ID ) as $node ) {
			$graph[] = $node; }
		return $graph;
	}

	/* ------------------------------------------------------------ Editor panel */

	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return; }
		wp_enqueue_style( 'publishrank-schema-library', plugins_url( 'assets/css/schema-library.css', PUBLISHRANK_SEO_FILE ), array(), PUBLISHRANK_SEO_VERSION );
		wp_enqueue_script( 'publishrank-schema-library', plugins_url( 'assets/js/schema-library.js', PUBLISHRANK_SEO_FILE ), array(), PUBLISHRANK_SEO_VERSION . '.' . (string) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/js/schema-library.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	public function meta_box( $post_type ) {
		if ( ! is_post_type_viewable( $post_type ) || 'attachment' === $post_type || ! Capabilities::can( 'manage_schema' ) && ! Capabilities::can( 'edit_seo' ) ) {
			return; }
		add_meta_box( 'publishrank-schema-library', __( 'PublishRank — more structured data', 'publishrank' ), array( $this, 'render' ), $post_type, 'normal', 'default' );
	}

	public function render( $post ) {
		$defs    = Definitions::all();
		$groups  = Definitions::groups();
		$entries = self::entries( $post->ID );
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		echo '<p class="description">' . esc_html( sprintf( /* translators: %d: max */ __( 'Add up to %d extra schema blocks to this page. A block is published only when every required field is valid, so search engines never see an incomplete block.', 'publishrank' ), self::MAX_NODES ) ) . '</p>';
		echo '<div class="prx-sl" data-prx-sl>';
		foreach ( $entries as $i => $e ) {
			$this->render_entry( $i, (string) ( $e['type'] ?? '' ), (array) ( $e['data'] ?? array() ), $defs );
		}
		echo '</div>';
		echo '<p class="prx-sl__add"><label class="screen-reader-text" for="prx-sl-type">' . esc_html__( 'Schema type to add', 'publishrank' ) . '</label><select id="prx-sl-type" data-prx-sl-type><option value="">' . esc_html__( 'Add a schema block…', 'publishrank' ) . '</option>';
		foreach ( $groups as $gk => $gl ) {
			echo '<optgroup label="' . esc_attr( $gl ) . '">';
			foreach ( $defs as $type => $def ) {
				if ( $def['group'] === $gk ) {
					echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $def['label'] ) . '</option>'; }
			}
			echo '</optgroup>';
		}
		echo '</select> <button type="button" class="button" data-prx-sl-add>' . esc_html__( 'Add', 'publishrank' ) . '</button></p>';
		echo '<script type="application/json" id="prx-sl-templates">' . wp_json_encode( array_map( function ( $type ) use ( $defs ) { return $this->entry_html( '__i__', $type, array(), $defs, array() ); }, array_combine( array_keys( $defs ), array_keys( $defs ) ) ), JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	}

	private function render_entry( $i, $type, array $data, array $defs ) {
		$n = self::normalise( $type, $data );
		echo $this->entry_html( $i, $type, $data, $defs, $n['errors'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping helpers.
	}

	private function entry_html( $i, $type, array $data, array $defs, array $errors ) {
		if ( ! isset( $defs[ $type ] ) ) {
			return ''; }
		$def  = $defs[ $type ];
		$name = self::META . '[' . $i . ']';
		$html = '<fieldset class="prx-sl__entry" data-prx-sl-entry><legend>' . esc_html( $def['label'] ) . ( $errors ? ' <span class="prx-sl__bad">' . esc_html__( 'incomplete — not published', 'publishrank' ) . '</span>' : ' <span class="prx-sl__ok">' . esc_html__( 'valid', 'publishrank' ) . '</span>' ) . '</legend>';
		$html .= '<input type="hidden" name="' . esc_attr( $name ) . '[type]" value="' . esc_attr( $type ) . '" />';
		foreach ( $def['fields'] as $key => $field ) {
			$id    = 'prx-sl-' . $i . '-' . $key;
			$value = $data[ $key ] ?? '';
			$value = is_array( $value ) ? implode( "\n", $value ) : (string) $value;
			$html .= '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $field['label'] ) . ( ! empty( $field['required'] ) ? ' *' : '' ) . '</strong></label><br />';
			$fname = $name . '[data][' . $key . ']';
			if ( in_array( $field['kind'], array( 'textarea', 'lines' ), true ) ) {
				$html .= '<textarea class="large-text" rows="' . ( 'lines' === $field['kind'] ? 4 : 3 ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $fname ) . '">' . esc_textarea( $value ) . '</textarea>';
			} elseif ( 'select' === $field['kind'] ) {
				$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $fname ) . '"><option value=""></option>';
				foreach ( $field['options'] as $o ) {
					$html .= '<option value="' . esc_attr( $o ) . '"' . selected( $value, $o, false ) . '>' . esc_html( $o ) . '</option>'; }
				$html .= '</select>';
			} else {
				$html .= '<input class="regular-text" type="' . ( 'url' === $field['kind'] ? 'url' : 'text' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $fname ) . '" value="' . esc_attr( $value ) . '" />';
			}
			if ( isset( $errors[ $key ] ) ) {
				$html .= '<br /><span class="prx-sl__bad" role="alert">' . esc_html( $errors[ $key ] ) . '</span>'; }
			$html .= '</p>';
		}
		$html .= '<p><button type="button" class="button-link-delete" data-prx-sl-remove>' . esc_html__( 'Remove this block', 'publishrank' ) . '</button></p></fieldset>';
		return $html;
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return; }
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ( ! Capabilities::can( 'manage_schema' ) && ! Capabilities::can( 'edit_seo' ) ) ) {
			return; }
		$posted = isset( $_POST[ self::META ] ) && is_array( $_POST[ self::META ] ) ? wp_unslash( $_POST[ self::META ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated per field kind below.
		$keep   = array();
		foreach ( array_slice( $posted, 0, self::MAX_NODES ) as $e ) {
			if ( ! is_array( $e ) || empty( $e['type'] ) || ! is_string( $e['type'] ) ) {
				continue; }
			$n = self::normalise( $e['type'], isset( $e['data'] ) && is_array( $e['data'] ) ? $e['data'] : array() );
			if ( isset( $n['errors']['_type'] ) ) {
				continue; }
			// Keep incomplete drafts so the editor can finish them; they are never printed (see nodes_for()).
			$keep[] = array( 'type' => $n['type'], 'data' => $n['data'] );
		}
		if ( $keep ) {
			update_post_meta( $post_id, self::META, $keep );
		} else {
			delete_post_meta( $post_id, self::META ); }
		do_action( 'publishrank_schema_library_saved', $post_id );
	}

	/* ------------------------------------------------------------ REST */

	public function routes() {
		$ns = 'publishrank/v1';
		register_rest_route( $ns, '/schema/types', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_types' ), 'permission_callback' => Capabilities::rest( 'manage_schema' ) ) );
		register_rest_route( $ns, '/schema/validate', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_validate' ), 'permission_callback' => Capabilities::rest( 'manage_schema' ) ) );
		register_rest_route( $ns, '/schema/post/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'rest_get' ), 'permission_callback' => array( $this, 'rest_can_edit' ) ),
			array( 'methods' => 'PUT', 'callback' => array( $this, 'rest_put' ), 'permission_callback' => array( $this, 'rest_can_edit' ) ),
		) );
	}

	public function rest_can_edit( \WP_REST_Request $r ) {
		$id = absint( $r['id'] );
		return $id && get_post( $id ) && current_user_can( 'edit_post', $id ) && ( Capabilities::can( 'manage_schema' ) || Capabilities::can( 'edit_seo' ) ) ? true : new \WP_Error( 'rest_forbidden', __( 'You cannot edit schema for this post.', 'publishrank' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function rest_types() {
		$out = array();
		foreach ( Definitions::all() as $type => $d ) {
			$out[] = array( 'type' => $type, 'label' => $d['label'], 'group' => $d['group'], 'documentation' => $d['doc'], 'fields' => $d['fields'] );
		}
		return rest_ensure_response( $out );
	}

	public function rest_validate( \WP_REST_Request $r ) {
		$type = sanitize_text_field( (string) $r->get_param( 'type' ) );
		$data = $r->get_param( 'data' );
		$n    = self::normalise( $type, is_array( $data ) ? $data : array() );
		$resp = array( 'valid' => $n['valid'], 'errors' => (object) $n['errors'] );
		if ( $n['valid'] ) {
			$resp['jsonld'] = array( '@context' => 'https://schema.org' ) + (array) self::node( $n['type'], $n['data'], home_url( '/example/' ), 0 ); }
		return rest_ensure_response( $resp );
	}

	public function rest_get( \WP_REST_Request $r ) {
		$id  = absint( $r['id'] );
		$out = array();
		foreach ( self::entries( $id ) as $e ) {
			$n     = self::normalise( (string) $e['type'], (array) $e['data'] );
			$out[] = array( 'type' => $n['type'], 'data' => $n['data'], 'valid' => $n['valid'], 'errors' => (object) $n['errors'] );
		}
		return rest_ensure_response( array( 'entries' => $out, 'published' => count( self::nodes_for( $id ) ) ) );
	}

	public function rest_put( \WP_REST_Request $r ) {
		$id      = absint( $r['id'] );
		$entries = $r->get_param( 'entries' );
		if ( ! is_array( $entries ) || count( $entries ) > self::MAX_NODES ) {
			return new \WP_Error( 'publishrank_schema_entries', sprintf( /* translators: %d: max */ __( 'Send an "entries" array with at most %d items.', 'publishrank' ), self::MAX_NODES ), array( 'status' => 400 ) ); }
		$keep   = array();
		$report = array();
		foreach ( $entries as $e ) {
			$n = self::normalise( is_array( $e ) ? (string) ( $e['type'] ?? '' ) : '', is_array( $e ) && is_array( $e['data'] ?? null ) ? $e['data'] : array() );
			if ( isset( $n['errors']['_type'] ) ) {
				return new \WP_Error( 'publishrank_schema_type', __( 'Unknown schema type.', 'publishrank' ), array( 'status' => 400 ) ); }
			$keep[]   = array( 'type' => $n['type'], 'data' => $n['data'] );
			$report[] = array( 'type' => $n['type'], 'valid' => $n['valid'], 'errors' => (object) $n['errors'] );
		}
		if ( $keep ) {
			update_post_meta( $id, self::META, $keep );
		} else {
			delete_post_meta( $id, self::META ); }
		do_action( 'publishrank_schema_library_saved', $id );
		return rest_ensure_response( array( 'saved' => count( $keep ), 'published' => count( self::nodes_for( $id ) ), 'entries' => $report ) );
	}
}
