<?php
/** Emits safe international language and regional alternates. */
namespace PublishRankSEO\Meta;

defined( 'ABSPATH' ) || exit;

final class LanguageAlternates {
	public function register() {
		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	/** @return array<string, string> */
	public static function alternates() {
		if ( ! get_option( 'publishrank_seo_multilingual_enabled', true ) ) {
			return array();
		}
		$alternates = array();
		$manual = (string) get_option( 'publishrank_seo_hreflang_map', '' );
		foreach ( preg_split( '/\r\n|\r|\n/', $manual ) as $line ) {
			$parts = preg_split( '/\s*[=|]\s*/', trim( $line ), 2 );
			if ( 2 === count( $parts ) && self::locale( $parts[0] ) && esc_url_raw( $parts[1] ) ) {
				$alternates[ self::locale( $parts[0] ) ] = esc_url_raw( $parts[1] );
			}
		}
		if ( function_exists( 'wpml_get_language_information' ) && function_exists( 'icl_get_languages' ) ) {
			$languages = icl_get_languages( 'skip_missing=0' );
			foreach ( (array) $languages as $language ) {
				$locale = isset( $language['default_locale'] ) ? $language['default_locale'] : ( $language['code'] ?? '' );
				if ( ! empty( $language['url'] ) && self::locale( $locale ) ) {
					$alternates[ self::locale( $locale ) ] = esc_url_raw( $language['url'] );
				}
			}
		}
		if ( function_exists( 'pll_the_languages' ) ) {
			$languages = pll_the_languages( array( 'raw' => 1 ) );
			foreach ( (array) $languages as $language ) {
				$locale = $language['locale'] ?? ( $language['slug'] ?? '' );
				if ( ! empty( $language['url'] ) && self::locale( $locale ) ) {
					$alternates[ self::locale( $locale ) ] = esc_url_raw( $language['url'] );
				}
			}
		}
		$current = self::locale( get_option( 'publishrank_default_locale', get_bloginfo( 'language' ) ) );
		if ( $current ) {
			$alternates[ $current ] = ( new MetaGenerator() )->canonical();
		}
		return array_filter( $alternates );
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
			$x_default = esc_url_raw( (string) get_option( 'publishrank_seo_x_default_url', '' ) );
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $x_default ? $x_default : ( new MetaGenerator() )->canonical() ) . '" />' . "\n";
			echo "<!-- Language alternate links end -->\n";
	}
}

/* EOF */
