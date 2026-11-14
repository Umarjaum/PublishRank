<?php
/**
 * Front-end speed hints for publisher sites.
 *
 * - preconnect: CDN and media hosts (the origins every article image is fetched from).
 * - dns-prefetch: video and API hosts (and the CDN / media hosts again for browsers without preconnect).
 * - preload: the featured image of the article or page being viewed, with its srcset, at high priority,
 *   so the largest picture on the screen starts downloading before the stylesheet has finished.
 * - prefetch: Speculation Rules that prefetch a same-site article when the reader hovers or taps its link.
 *   On WordPress 6.8+ core already prints speculation rules; PublishRank then only configures them.
 *
 * Every part can be switched off under PublishRank > Settings > Infrastructure, speed and syndication.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Performance;

defined( 'ABSPATH' ) || exit;

final class ResourceHints {
	public function register() {
		// Priority 20: after other filters that prune third-party hints, so configured hosts are never dropped.
		add_filter( 'wp_resource_hints', array( $this, 'hints' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'preload_featured_image' ), 3 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'prioritize_featured_image' ), 20, 3 );
		add_action( 'wp_footer', array( $this, 'speculation_rules' ), 20 );
		// WordPress 6.8+ ships speculative loading: make it prefetch (never prerender) on hover.
		add_filter( 'wp_speculation_rules_configuration', array( $this, 'core_speculation_configuration' ), 20 );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'core_exclude_paths' ), 20 );
	}

	/** Whether the host hints are enabled. */
	public static function hints_enabled() {
		return (bool) get_option( 'publishrank_resource_hints', 1 );
	}

	/** Whether the featured image preload is enabled. */
	public static function preload_enabled() {
		return (bool) get_option( 'publishrank_preload_lcp_image', 1 );
	}

	/** Whether link prefetching is enabled. */
	public static function prefetch_enabled() {
		return (bool) get_option( 'publishrank_speculative_prefetch', 1 );
	}

	/**
	 * Hosts configured under Infrastructure, as scheme-qualified origins.
	 *
	 * @return array{preconnect:string[],dns:string[]}
	 */
	public static function configured_hosts() {
		$preconnect = array();
		if ( get_option( 'publishrank_cdn_enabled', 0 ) || '' !== trim( (string) get_option( 'publishrank_cdn_host', '' ) ) ) {
			$preconnect[] = self::normalize( (string) get_option( 'publishrank_cdn_host', '' ) );
		}
		$preconnect[] = self::normalize( (string) get_option( 'publishrank_media_host', '' ) );
		$dns          = $preconnect;
		foreach ( array( 'publishrank_video_host', 'publishrank_api_host' ) as $option ) {
			$dns[] = self::normalize( (string) get_option( $option, '' ) );
		}
		return array(
			'preconnect' => array_values( array_unique( array_filter( $preconnect ) ) ),
			'dns'        => array_values( array_unique( array_filter( $dns ) ) ),
		);
	}

	/** @param array<int,mixed> $urls @param string $relation_type @return array<int,mixed> */
	public function hints( $urls, $relation_type ) {
		if ( is_admin() || ! self::hints_enabled() || ! is_array( $urls ) ) {
			return $urls; }
		$hosts = self::configured_hosts();
		if ( 'preconnect' === $relation_type ) {
			$add = $hosts['preconnect'];
		} elseif ( 'dns-prefetch' === $relation_type ) {
			$add = $hosts['dns'];
		} else {
			return $urls;
		}
		$known = array();
		foreach ( $urls as $entry ) {
			$href = is_array( $entry ) ? (string) ( $entry['href'] ?? '' ) : (string) $entry;
			$known[ untrailingslashit( strtolower( preg_replace( '#^https?:#i', '', $href ) ) ) ] = true;
		}
		foreach ( $add as $href ) {
			$key = untrailingslashit( strtolower( preg_replace( '#^https?:#i', '', $href ) ) );
			if ( isset( $known[ $key ] ) ) {
				continue; }
			$known[ $key ] = true;
			// Images are fetched without credentials, so the preconnect must be anonymous to be reused.
			$urls[] = 'preconnect' === $relation_type ? array( 'href' => $href, 'crossorigin' => 'anonymous' ) : $href;
		}
		return $urls;
	}

	/** Featured image of the current singular view, or 0. */
	private static function featured_image_id() {
		if ( ! is_singular() || is_attachment() || post_password_required() ) {
			return 0; }
		$id = (int) get_post_thumbnail_id( (int) get_queried_object_id() );
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/** <link rel="preload" as="image"> for the featured image (the usual Largest Contentful Paint element). */
	public function preload_featured_image() {
		if ( is_admin() || is_feed() || is_embed() || ! self::preload_enabled() ) {
			return; }
		$id = self::featured_image_id();
		if ( ! $id ) {
			return; }
		/** Filter the image size preloaded for the featured image. Match the size your theme displays. */
		$size = apply_filters( 'publishrank_preload_image_size', 'full', $id );
		$src  = wp_get_attachment_image_src( $id, $size );
		if ( ! $src || empty( $src[0] ) ) {
			return; }
		$attrs = array(
			'rel'           => 'preload',
			'as'            => 'image',
			'href'          => (string) $src[0],
			'fetchpriority' => 'high',
		);
		$srcset = function_exists( 'wp_get_attachment_image_srcset' ) ? (string) wp_get_attachment_image_srcset( $id, $size ) : '';
		if ( '' !== $srcset ) {
			$attrs['imagesrcset'] = $srcset;
			$sizes                = function_exists( 'wp_get_attachment_image_sizes' ) ? (string) wp_get_attachment_image_sizes( $id, $size ) : '';
			$attrs['imagesizes']  = '' !== $sizes ? $sizes : '100vw';
		}
		$attrs = (array) apply_filters( 'publishrank_preload_image_attributes', $attrs, $id );
		if ( empty( $attrs['href'] ) ) {
			return; }
		$html = '<link';
		foreach ( $attrs as $name => $value ) {
			$html .= ' ' . sanitize_key( (string) $name ) . '="' . ( 'href' === $name ? esc_url( (string) $value ) : esc_attr( (string) $value ) ) . '"';
		}
		echo $html . " />\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute escaped above.
	}

	/** The preloaded featured image must not be lazy-loaded, and should load at high priority. */
	public function prioritize_featured_image( $attr, $attachment, $size ) {
		if ( is_admin() || ! self::preload_enabled() || ! $attachment instanceof \WP_Post || ! is_array( $attr ) ) {
			return $attr; }
		if ( (int) $attachment->ID !== self::featured_image_id() ) {
			return $attr; }
		if ( isset( $attr['loading'] ) && 'lazy' === $attr['loading'] ) {
			unset( $attr['loading'] ); }
		if ( empty( $attr['fetchpriority'] ) ) {
			$attr['fetchpriority'] = 'high'; }
		return $attr;
	}

	/** Paths that must never be prefetched (relative to the site root). */
	public static function excluded_paths() {
		$paths = array( '/wp-admin/*', '/wp-login.php*', '/wp-json/*', '/xmlrpc.php*', '/*logout*', '/cart/*', '/checkout/*', '/my-account/*', '/basket/*', '/feed/*', '/*/feed/*', '/*.xml', '/*.php*' );
		$login = class_exists( '\\PublishRankSEO\\Security\\PrivateLogin' ) && method_exists( '\\PublishRankSEO\\Security\\PrivateLogin', 'current_login_slug' ) ? (string) \PublishRankSEO\Security\PrivateLogin::current_login_slug() : '';
		if ( '' !== $login ) {
			$paths[] = '/' . trim( $login, '/' ) . '*'; }
		return array_values( array_unique( (array) apply_filters( 'publishrank_prefetch_exclude_paths', $paths ) ) );
	}

	/** WordPress 6.8+ core speculative loading: prefetch on hover ("moderate"), never prerender. */
	public function core_speculation_configuration( $config ) {
		if ( ! self::prefetch_enabled() ) {
			return $config; }
		if ( is_user_logged_in() ) {
			return null; // Editors browse with admin bars and previews: no speculative requests for them.
		}
		return array( 'mode' => 'prefetch', 'eagerness' => 'moderate' );
	}

	/** @param string[] $paths */
	public function core_exclude_paths( $paths ) {
		if ( ! self::prefetch_enabled() ) {
			return $paths; }
		return array_values( array_unique( array_merge( (array) $paths, self::excluded_paths() ) ) );
	}

	/** Speculation Rules for WordPress versions before 6.8 (core prints its own from 6.8). */
	public function speculation_rules() {
		if ( is_admin() || is_feed() || is_embed() || is_preview() || is_customize_preview() || ! self::prefetch_enabled() || is_user_logged_in() ) {
			return; }
		if ( function_exists( 'wp_get_speculation_rules' ) || function_exists( 'wp_get_speculation_rules_configuration' ) ) {
			return; // Core handles it; see core_speculation_configuration().
		}
		if ( defined( 'SPECULATION_RULES_VERSION' ) ) {
			return; // The Speculative Loading plugin is active.
		}
		$base     = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base     = '/' . trim( $base, '/' );
		$base     = '/' === $base ? '' : $base;
		$prefixed = array();
		foreach ( self::excluded_paths() as $path ) {
			$prefixed[] = $base . $path;
		}
		$rules = array(
			'prefetch' => array(
				array(
					'source'    => 'document',
					'where'     => array(
						'and' => array(
							array( 'href_matches' => $base . '/*' ),
							array( 'not' => array( 'href_matches' => $prefixed ) ),
							array( 'not' => array( 'href_matches' => '/*\\?*' ) ),
							array( 'not' => array( 'selector_matches' => 'a[rel~="nofollow"], a[target="_blank"], a[download], .no-prefetch, .no-prefetch a' ) ),
						),
					),
					'eagerness' => 'moderate',
				),
			),
		);
		$rules = (array) apply_filters( 'publishrank_speculation_rules', $rules );
		echo '<script type="speculationrules">' . wp_json_encode( $rules, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded.
	}

	/** Accepts a URL or bare host; returns a scheme-qualified origin or '' (also '' for the site's own host). */
	public static function normalize( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return ''; }
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			$value = 'https://' . preg_replace( '#^//#', '', $value ); }
		$parts  = wp_parse_url( $value );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		if ( '' === $host ) {
			return ''; }
		$origin = ( 'http' === $scheme ? 'http' : 'https' ) . '://' . $host;
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port']; }
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return $host === $site_host ? '' : $origin;
	}
}
