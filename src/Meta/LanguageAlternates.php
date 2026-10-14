<?php
/** Emits safe international language and regional alternates. */
namespace PublishRankSEO\Meta;

defined( 'ABSPATH' ) || exit;

final class LanguageAlternates {
	public function register() {
		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	/** Locale of the document being served (Polylang / WPML aware), falling back to the site locale. */
	public static function current_locale() {
		if ( function_exists( 'pll_current_language' ) ) {
			$locale = self::locale( (string) pll_current_language( 'locale' ) );
			if ( $locale ) { return $locale; }
		}
		if ( function_exists( 'icl_get_languages' ) && has_filter( 'wpml_current_language' ) ) {
			$code = (string) apply_filters( 'wpml_current_language', null );
			$languages = (array) icl_get_languages( 'skip_missing=0' );
			if ( $code && ! empty( $languages[ $code ]['default_locale'] ) && self::locale( $languages[ $code ]['default_locale'] ) ) { return self::locale( $languages[ $code ]['default_locale'] ); }
			if ( $code && self::locale( $code ) ) { return self::locale( $code ); }
		}
		return self::default_locale();
	}

	/** Site default locale; never empty even when the option was saved blank. */
	public static function default_locale() {
		$value = trim( (string) get_option( 'publishrank_default_locale', '' ) );
		return self::locale( '' !== $value ? $value : get_bloginfo( 'language' ) );
	}

	/** Parse "locale=URL" lines. Keeps x-default as-is. @return array<string,string> */
	public static function parse_map( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$parts = preg_split( '/\s*[=|]\s*/', trim( $line ), 2 );
			if ( 2 !== count( $parts ) ) { continue; }
			$key = 'x-default' === strtolower( trim( $parts[0] ) ) ? 'x-default' : self::locale( $parts[0] );
			$url = esc_url_raw( trim( $parts[1] ) );
			if ( $key && $url ) { $out[ $key ] = $url; }
		}
		return $out;
	}

	/**
	 * Alternates for the CURRENT document, so every page lists its own translations.
	 *
	 * @return array<string, string>
	 */
	public static function alternates() {
		if ( ! get_option( 'publishrank_seo_multilingual_enabled', true ) ) {
			return array();
		}
		$alternates = array();
		$current    = self::current_locale();
		$canonical  = ( new MetaGenerator() )->canonical();
		$post       = is_singular() ? get_queried_object() : null;
		$per_post   = $post instanceof \WP_Post ? self::parse_map( (string) get_post_meta( $post->ID, PostMeta::HREFLANG, true ) ) : array();
		$site_map   = self::parse_map( (string) get_option( 'publishrank_seo_hreflang_map', '' ) );
		if ( $per_post ) {
			// An editor-set list for this exact page always wins.
			$alternates = $per_post;
		} elseif ( $site_map ) {
			if ( is_front_page() || is_home() ) {
				$alternates = $site_map;
			} elseif ( get_option( 'publishrank_seo_hreflang_mirror', false ) && ! empty( $site_map[ $current ] ) && 0 === strpos( trailingslashit( $canonical ), trailingslashit( $site_map[ $current ] ) ) ) {
				// Opt-in: language folders share one URL structure, so mirror this page's path under each language root.
				$rest = substr( trailingslashit( $canonical ), strlen( trailingslashit( $site_map[ $current ] ) ) );
				foreach ( $site_map as $locale => $root ) { if ( 'x-default' !== $locale ) { $alternates[ $locale ] = trailingslashit( $root ) . $rest; } }
			}
		}
		if ( function_exists( 'icl_get_languages' ) ) {
			foreach ( (array) icl_get_languages( 'skip_missing=1' ) as $language ) {
				$locale = isset( $language['default_locale'] ) ? $language['default_locale'] : ( $language['code'] ?? '' );
				if ( ! empty( $language['url'] ) && self::locale( $locale ) ) { $alternates[ self::locale( $locale ) ] = esc_url_raw( $language['url'] ); }
			}
		}
		if ( function_exists( 'pll_the_languages' ) ) {
			foreach ( (array) pll_the_languages( array( 'raw' => 1, 'hide_if_no_translation' => 1 ) ) as $language ) {
				$locale = $language['locale'] ?? ( $language['slug'] ?? '' );
				if ( ! empty( $language['url'] ) && self::locale( $locale ) ) { $alternates[ self::locale( $locale ) ] = esc_url_raw( $language['url'] ); }
			}
		}
		// Every page must reference itself, under ITS OWN language (not blindly the site default).
		if ( $current && $canonical ) {
			$alternates[ $current ] = $canonical;
		}
		return array_filter( $alternates );
	}

	/** x-default for the current document, or '' when none can be determined reliably. */
	public static function x_default( array $alternates ) {
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post ) {
			$per_post = self::parse_map( (string) get_post_meta( $post->ID, PostMeta::HREFLANG, true ) );
			if ( ! empty( $per_post['x-default'] ) ) { return $per_post['x-default']; }
		}
		if ( ( is_front_page() || is_home() ) ) {
			$configured = esc_url_raw( (string) get_option( 'publishrank_seo_x_default_url', '' ) );
			if ( $configured ) { return $configured; }
			$site_map = self::parse_map( (string) get_option( 'publishrank_seo_hreflang_map', '' ) );
			if ( ! empty( $site_map['x-default'] ) ) { return $site_map['x-default']; }
		}
		$default = self::default_locale();
		return ( $default && ! empty( $alternates[ $default ] ) ) ? $alternates[ $default ] : '';
	}

	/** @param string $value @return string */
	public static function locale( $value ) {
		$value = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $value ) );
		$value = str_replace( '_', '-', $value );
		$parts = array_values( array_filter( explode( '-', $value ) ) );
		if ( empty( $parts ) || strlen( $parts[0] ) < 2 ) {
			return '';
		}
		$result = substr( $parts[0], 0, 8 );
		if ( isset( $parts[1] ) && $parts[1] ) {
			$result .= '-' . strtoupper( substr( $parts[1], 0, 8 ) );
		}
		return $result;
	}

	public function render() {
		if ( is_admin() || wp_doing_ajax() || ! get_option( 'publishrank_seo_multilingual_enabled', true ) ) {
			return;
		}
			$alternates = self::alternates();
			echo '<!-- Language alternate links begin; count=' . esc_attr( (string) count( $alternates ) ) . " -->\n";
			foreach ( $alternates as $locale => $url ) {
				echo '<link rel="alternate" hreflang="' . esc_attr( $locale ) . '" href="' . esc_url( $url ) . '" />' . "\n";
			}
			$x_default = self::x_default( $alternates );
			if ( $x_default ) { echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $x_default ) . '" />' . "\n"; }
			echo "<!-- Language alternate links end -->\n";
	}
}

/* EOF */
