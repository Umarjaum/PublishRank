<?php
/**
 * Makes PublishRank's site-wide text settings translatable with Polylang (Strings translations)
 * and WPML (String Translation): publication name, descriptions, title templates and separators.
 *
 * Each setting is registered as a string in the "PublishRank" group; on the public site the
 * translated value for the current language replaces the stored one.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Meta;

defined( 'ABSPATH' ) || exit;

final class MultilingualStrings {
	const GROUP = 'PublishRank';

	/** @return string[] Option names whose text is shown to readers or search engines. */
	public static function options() {
		$names = array(
			'publishrank_seo_publication_name',
			'publishrank_home_description',
			'publishrank_seo_description_fallback',
			'publishrank_identity_description',
			'publishrank_identity_person_description',
			'publishrank_schema_org_description',
			'publishrank_seo_title_separator',
			'publishrank_breadcrumbs_separator',
			'publishrank_copyright',
		);
		foreach ( array( 'post', 'page', 'archive', 'home', 'search', 'author', 'category', 'tag', '404' ) as $context ) {
			$names[] = 'publishrank_seo_title_template_' . $context; }
		return (array) apply_filters( 'publishrank_translatable_options', $names );
	}

	private static function active() {
		return function_exists( 'pll_register_string' ) || has_action( 'wpml_register_single_string' );
	}

	public function register() {
		add_action( 'init', array( $this, 'boot' ), 20 );
	}

	public function boot() {
		if ( ! self::active() ) {
			return; }
		if ( is_admin() ) {
			$this->register_strings();
			foreach ( self::options() as $name ) {
				add_action( 'update_option_' . $name, array( $this, 'register_strings' ) ); }
			return;
		}
		foreach ( self::options() as $name ) {
			add_filter( 'option_' . $name, array( $this, 'translate' ), 20, 2 ); }
	}

	/** Register every non-empty setting so translators see it in the multilingual plugin's string screen. */
	public function register_strings() {
		foreach ( self::options() as $name ) {
			$value = get_option( $name, '' );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue; }
			if ( function_exists( 'pll_register_string' ) ) {
				pll_register_string( $name, $value, self::GROUP, strlen( $value ) > 80 ); }
			do_action( 'wpml_register_single_string', self::GROUP, $name, $value );
		}
	}

	/** Translate a stored setting into the current language. */
	public function translate( $value, $name = '' ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value; }
		if ( function_exists( 'pll__' ) ) {
			return pll__( $value ); }
		return (string) apply_filters( 'wpml_translate_single_string', $value, self::GROUP, (string) $name );
	}
}
