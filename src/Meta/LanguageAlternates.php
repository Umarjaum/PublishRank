<?php
/** Emits safe international language and regional alternates. */
namespace PublishRankSEO\Meta;

use PublishRankSEO\Core\OutputSettings;

defined( 'ABSPATH' ) || exit;

final class LanguageAlternates {
	public function register() {
		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	/** Locale of the document being served (Polylang / WPML aware), falling back to the site locale. */
	public static function current_locale() {
		if ( function_exists( 'pll_current_language' ) ) {
			$locale = self::locale( (string) pll_current_language( 'locale' ) );
			if ( $locale ) {
				return $locale; }
		}
		if ( function_exists( 'icl_get_languages' ) && has_filter( 'wpml_current_language' ) ) {
			$code      = (string) apply_filters( 'wpml_current_language', null );
			$languages = (array) icl_get_languages( 'skip_missing=0' );
			if ( $code && ! empty( $languages[ $code ]['default_locale'] ) && self::locale( $languages[ $code ]['default_locale'] ) ) {
				return self::locale( $languages[ $code ]['default_locale'] ); }
			if ( $code && self::locale( $code ) ) {
				return self::locale( $code ); }
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
			if ( 2 !== count( $parts ) ) {
				continue; }
			$key = 'x-default' === strtolower( trim( $parts[0] ) ) ? 'x-default' : self::locale( $parts[0] );
			$url = esc_url_raw( trim( $parts[1] ) );
			if ( $key && $url ) {
				$out[ $key ] = $url; }
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
				foreach ( $site_map as $locale => $root ) {
					if ( 'x-default' !== $locale ) {
						$alternates[ $locale ] = trailingslashit( $root ) . $rest; }
				}
			}
		}
		if ( function_exists( 'icl_get_languages' ) ) {
			foreach ( (array) icl_get_languages( 'skip_missing=1' ) as $language ) {
				$locale = isset( $language['default_locale'] ) ? $language['default_locale'] : ( $language['code'] ?? '' );
				if ( ! empty( $language['url'] ) && self::locale( $locale ) ) {
					$alternates[ self::locale( $locale ) ] = esc_url_raw( $language['url'] ); }
			}
		}
		if ( function_exists( 'pll_the_languages' ) ) {
			foreach ( (array) pll_the_languages(
				array(
					'raw'                    => 1,
					'hide_if_no_translation' => 1,
				)
			) as $language ) {
				$locale = $language['locale'] ?? ( $language['slug'] ?? '' );
				if ( ! empty( $language['url'] ) && self::locale( $locale ) ) {
					$alternates[ self::locale( $locale ) ] = esc_url_raw( $language['url'] ); }
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
			if ( ! empty( $per_post['x-default'] ) ) {
				return $per_post['x-default']; }
		}
		if ( ( is_front_page() || is_home() ) ) {
			$configured = esc_url_raw( (string) get_option( 'publishrank_seo_x_default_url', '' ) );
			if ( $configured ) {
				return $configured; }
			$site_map = self::parse_map( (string) get_option( 'publishrank_seo_hreflang_map', '' ) );
			if ( ! empty( $site_map['x-default'] ) ) {
				return $site_map['x-default']; }
		}
		$default = self::default_locale();
		return ( $default && ! empty( $alternates[ $default ] ) ) ? $alternates[ $default ] : '';
	}

	/** True when Polylang or WPML is active. */
	public static function has_multilingual_plugin() {
		return function_exists( 'pll_get_post_translations' ) || has_filter( 'wpml_element_trid' );
	}

	/** Locale of one post (Polylang / WPML), falling back to the site default. */
	public static function post_locale( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$locale = self::locale( (string) pll_get_post_language( (int) $post_id, 'locale' ) );
			if ( $locale ) {
				return $locale; }
		}
		if ( has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, (int) $post_id );
			if ( is_array( $details ) && ! empty( $details['locale'] ) && self::locale( $details['locale'] ) ) {
				return self::locale( $details['locale'] ); }
		}
		return self::default_locale();
	}

	/**
	 * Published translations of a post, including the post itself.
	 *
	 * @return array<string,int> locale => post ID
	 */
	public static function post_translations( $post_id ) {
		$post_id = (int) $post_id;
		$out     = array();
		if ( function_exists( 'pll_get_post_translations' ) ) {
			foreach ( (array) pll_get_post_translations( $post_id ) as $id ) {
				$locale = function_exists( 'pll_get_post_language' ) ? self::locale( (string) pll_get_post_language( (int) $id, 'locale' ) ) : '';
				if ( $locale && 'publish' === get_post_status( (int) $id ) ) {
					$out[ $locale ] = (int) $id; }
			}
		} elseif ( has_filter( 'wpml_element_trid' ) ) {
			$type = 'post_' . (string) get_post_type( $post_id );
			$trid = apply_filters( 'wpml_element_trid', null, $post_id, $type );
			$rows = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, $type ) : array();
			$langs = function_exists( 'icl_get_languages' ) ? (array) icl_get_languages( 'skip_missing=0' ) : array();
			foreach ( (array) $rows as $code => $row ) {
				$id = is_object( $row ) && isset( $row->element_id ) ? (int) $row->element_id : 0;
				$locale = ! empty( $langs[ $code ]['default_locale'] ) ? self::locale( $langs[ $code ]['default_locale'] ) : self::locale( (string) $code );
				if ( $id && $locale && 'publish' === get_post_status( $id ) ) {
					$out[ $locale ] = $id; }
			}
		}
		return (array) apply_filters( 'publishrank_post_translations', $out, $post_id );
	}

	/** xhtml:link hreflang entries for a sitemap <url>; empty unless the post has at least one translation. */
	public static function sitemap_links( $post_id ) {
		if ( ! get_option( 'publishrank_seo_multilingual_enabled', true ) || ! self::has_multilingual_plugin() ) {
			return ''; }
		$translations = self::post_translations( $post_id );
		if ( count( $translations ) < 2 ) {
			return ''; }
		$xml = '';
		foreach ( $translations as $locale => $id ) {
			$url = get_permalink( $id );
			if ( $url ) {
				$xml .= '<xhtml:link rel="alternate" hreflang="' . esc_attr( $locale ) . '" href="' . esc_url( $url ) . '"/>'; }
		}
		return $xml;
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
		if ( is_admin() || wp_doing_ajax() || ! get_option( 'publishrank_seo_multilingual_enabled', true ) || OutputSettings::external_seo_handoff() ) {
			return;
		}
			$alternates = self::alternates();
			echo '<!-- Language alternate links begin; count=' . esc_attr( (string) count( $alternates ) ) . " -->\n";
		foreach ( $alternates as $locale => $url ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $locale ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
			$x_default = self::x_default( $alternates );
		if ( $x_default ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $x_default ) . '" />' . "\n"; }
			echo "<!-- Language alternate links end -->\n";
	}
}

/* EOF */
