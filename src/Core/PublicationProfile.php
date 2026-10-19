<?php
/**
 * Centralized, site-local publication and infrastructure settings.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class PublicationProfile {
	/** @return string */
	public static function publication_name() {
		$name = get_option( 'publishrank_seo_publication_name', '' );
		return $name ? sanitize_text_field( (string) $name ) : sanitize_text_field( (string) get_bloginfo( 'name' ) );
	}

	/** @return string */
	public static function publisher_name() {
		$name = get_option( 'publishrank_seo_publisher_name', '' );
		return $name ? sanitize_text_field( (string) $name ) : self::publication_name();
	}

	/** @return string */
	public static function primary_url() {
		$url = get_option( 'publishrank_primary_url', '' );
		$url = self::normalize_url( $url ? (string) $url : home_url( '/' ), true );
		return $url ? trailingslashit( $url ) : trailingslashit( self::normalize_url( home_url( '/' ), true ) );
	}

	/** Normalize a configured URL and reject local/admin destinations. */
	public static function normalize_url( $url, $base_only = false ) {
		$url = esc_url_raw( trim( (string) $url ) );
		$parts = $url ? wp_parse_url( $url ) : array();
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) { return ''; }
		$host = strtolower( (string) $parts['host'] );
		if ( self::is_local_host( $host ) && ! self::local_hosts_allowed() ) { return ''; }
		if ( false !== strpos( (string) ( $parts['path'] ?? '' ), '/wp-admin' ) ) { return ''; }
		$path = $base_only ? '/' : ( isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '/' );
		$port = ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$query = $base_only ? '' : self::identifying_query( isset( $parts['query'] ) ? (string) $parts['query'] : '' );
		// Keep the configured scheme. set_url_scheme() with no scheme guesses from the current request, which downgrades https to http
		// behind proxies that do not forward the protocol and then mixes http and https ids inside one JSON-LD graph.
		return strtolower( $parts['scheme'] ) . '://' . $host . $port . $path . $query;
	}

	/**
	 * Keep only query arguments that identify content (plain permalinks use ?p=123, ?page_id=4, ?cat=2, ...);
	 * tracking and session parameters are dropped. Without this every post canonicalises to the home page
	 * on sites that use WordPress's default "Plain" permalink structure.
	 */
	private static function identifying_query( $query ) {
		if ( '' === $query ) { return ''; }
		parse_str( $query, $args );
		$allowed = array( 'p', 'page_id', 'attachment_id', 'cat', 'tag', 'author', 'm', 'post_type', 'taxonomy', 'term', 'paged', 'page' );
		if ( function_exists( 'apply_filters' ) ) { $allowed = (array) apply_filters( 'publishrank_seo_canonical_query_args', $allowed ); }
		$keep = array_intersect_key( (array) $args, array_flip( $allowed ) );
		ksort( $keep );
		return $keep ? '?' . http_build_query( $keep, '', '&' ) : '';
	}

	/** @param string $host Lower-case host name. */
	private static function is_local_host( $host ) {
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) || false !== strpos( $host, '.local' );
	}

	/** Local hosts are valid on local/development/staging sites; they are refused on production. */
	private static function local_hosts_allowed() {
		$type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$allowed = in_array( $type, array( 'local', 'development', 'staging' ), true );
		return (bool) ( function_exists( 'apply_filters' ) ? apply_filters( 'publishrank_seo_allow_local_hosts', $allowed ) : $allowed );
	}

	/** @return array<string, string> */
	/** @param string $url @return string */
	public static function media_url( $url ) {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return '';
		}
		$host = trim( (string) get_option( 'publishrank_media_host', '' ) );
		if ( ! $host ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}
		$media_host = preg_replace( '#^https?://#i', '', $host );
		$media_host = trim( (string) $media_host, '/' );
		if ( ! $media_host || in_array( strtolower( $media_host ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) { return $url; }
		return $media_host ? 'https://' . $media_host . ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) : $url;
	}

	/** @param string $url @return string */
	public static function cdn_url( $url ) {
		$url = esc_url_raw( $url );
		if ( ! $url || ! get_option( 'publishrank_cdn_enabled', false ) ) {
			return $url;
		}
		$host = trim( (string) get_option( 'publishrank_cdn_host', '' ) );
		if ( ! $host ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}
		$cdn_host = trim( (string) preg_replace( '#^https?://#i', '', $host ), '/' );
		if ( ! $cdn_host || in_array( strtolower( $cdn_host ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) { return $url; }
		return $cdn_host ? 'https://' . $cdn_host . ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) : $url;
	}

	/** @param string $url @return string */
	public static function asset_url( $url ) {
		return self::cdn_url( self::media_url( $url ) );
	}
	/* ---------------------------------------------------------------------
	 * Publication Identity — structured Organization / Person identity.
	 * ------------------------------------------------------------------- */

	/** @return string 'organization'|'person' */
	public static function identity_type() {
		$type = sanitize_key( (string) get_option( 'publishrank_identity_type', '' ) );
		return in_array( $type, array( 'organization', 'person' ), true ) ? $type : 'organization';
	}

	/** @return bool */
	public static function identity_is_person() { return 'person' === self::identity_type(); }

	/**
	 * Organization identity fields.
	 * Falls back to the legacy flat options so existing sites keep their identity.
	 * @return array<string, string>
	 */
	public static function organization_identity() {
		$get = static function ( $key, $legacy = '' ) {
			$value = get_option( $key, '' );
			if ( '' === (string) $value && $legacy ) { $value = get_option( $legacy, '' ); }
			return is_string( $value ) ? trim( $value ) : '';
		};
		return array(
			'name'           => $get( 'publishrank_identity_name', 'publishrank_seo_publisher_name' ),
			'legal_name'     => $get( 'publishrank_identity_legal_name', 'publishrank_schema_legal_name' ),
			'alternate_name' => $get( 'publishrank_identity_alternate_name', 'publishrank_publication_short_name' ),
				'alternate_names' => self::alternate_names( $get( 'publishrank_identity_alternate_names' ) ),
			'description'    => $get( 'publishrank_identity_description', 'publishrank_schema_org_description' ),
			'logo'           => $get( 'publishrank_identity_logo', 'publishrank_seo_publisher_logo' ),
			'founding_date'  => $get( 'publishrank_identity_founding_date', 'publishrank_schema_founding_date' ),
			'contact_email'  => $get( 'publishrank_identity_contact_email', 'publishrank_contact' ),
			'contact_phone'  => $get( 'publishrank_identity_contact_phone', 'publishrank_schema_phone' ),
			'street'         => $get( 'publishrank_identity_street', 'publishrank_schema_street_address' ),
			'locality'       => $get( 'publishrank_identity_locality', 'publishrank_schema_address_locality' ),
			'region'         => $get( 'publishrank_identity_region', 'publishrank_schema_address_region' ),
			'postal_code'    => $get( 'publishrank_identity_postal_code', 'publishrank_schema_postal_code' ),
			'country'        => $get( 'publishrank_identity_country', 'publishrank_country' ),
			'parent_organization' => $get( 'publishrank_identity_parent_organization' ),
			'owner_name'    => $get( 'publishrank_identity_owner_name' ),
			'owner_url'     => $get( 'publishrank_identity_owner_url' ),
			'owner_logo'    => $get( 'publishrank_identity_owner_logo' ),
			'copyright_holder' => $get( 'publishrank_identity_copyright_holder' ),
			'founder'        => $get( 'publishrank_schema_founder' ),
			'founding_location' => $get( 'publishrank_schema_founding_location' ),
			'knows_about'    => $get( 'publishrank_schema_knows_about' ),
		);
	}

	/** @return array<string, string> */
	

		/** @return string[] Organization aliases, one per line or comma-separated. */
		public static function alternate_names( $raw ) {
			$names = array();
			foreach ( preg_split( '/[\r\n,]+/', (string) $raw ) as $name ) {
				$name = sanitize_text_field( trim( $name ) );
				if ( '' !== $name ) { $names[] = $name; }
			}
			return array_values( array_unique( array_slice( $names, 0, 50 ) ) );
		}

		public static function person_identity() {
		$get = static function ( $key ) {
			$value = get_option( $key, '' );
			return is_string( $value ) ? trim( $value ) : '';
		};
		return array(
			'name'           => $get( 'publishrank_identity_person_name' ),
			'alternate_name' => $get( 'publishrank_identity_person_alternate_name' ),
			'description'    => $get( 'publishrank_identity_person_description' ),
			'image'          => $get( 'publishrank_identity_person_image' ),
			'job_title'      => $get( 'publishrank_identity_person_job_title' ),
			'email'          => $get( 'publishrank_identity_person_email' ),
			'url'            => $get( 'publishrank_identity_person_url' ),
		);
	}

	/** Structured social networks. @return array<string, array{label:string, base:string}> */
	public static function social_networks() {
		return array(
			'x'         => array( 'label' => 'X (Twitter)', 'base' => 'https://x.com/%s' ),
			'facebook'  => array( 'label' => 'Facebook',    'base' => 'https://www.facebook.com/%s' ),
			'instagram' => array( 'label' => 'Instagram',   'base' => 'https://www.instagram.com/%s' ),
			'linkedin'  => array( 'label' => 'LinkedIn',    'base' => 'https://www.linkedin.com/company/%s' ),
			'youtube'   => array( 'label' => 'YouTube',     'base' => 'https://www.youtube.com/@%s' ),
			'tiktok'    => array( 'label' => 'TikTok',      'base' => 'https://www.tiktok.com/@%s' ),
			'wikipedia' => array( 'label' => 'Wikipedia',   'base' => 'https://en.wikipedia.org/wiki/%s' ),
			'wikidata'  => array( 'label' => 'Wikidata',    'base' => 'https://www.wikidata.org/wiki/%s' ),
		);
	}

	/**
	 * SameAs / social profile URLs: structured networks + custom list,
	 * merged with the legacy free-text list. Handles or full URLs accepted.
	 * @return string[]
	 */
	public static function social_profiles() {
		$profiles = array();
		$push = static function ( $url ) use ( &$profiles ) {
			$url = esc_url_raw( trim( (string) $url ) );
			if ( $url ) { $profiles[] = $url; }
		};

		// Legacy free-text list (backward compatibility).
		foreach ( preg_split( '/\r\n|\r|\n/', (string) get_option( 'publishrank_social_profiles', '' ) ) as $line ) {
			$push( $line );
		}

		// Structured network profiles.
		foreach ( self::social_networks() as $key => $network ) {
			$value = trim( (string) get_option( 'publishrank_social_' . $key, '' ) );
			if ( '' === $value ) { continue; }
			if ( preg_match( '#^https?://#i', $value ) ) {
				$push( $value );
				continue;
			}
			$push( sprintf( $network['base'], rawurlencode( ltrim( $value, '@' ) ) ) );
		}

		// Custom profiles.
		foreach ( preg_split( '/\r\n|\r|\n/', (string) get_option( 'publishrank_social_custom', '' ) ) as $line ) {
			$push( $line );
		}

		return array_values( array_unique( $profiles ) );
	}

}
