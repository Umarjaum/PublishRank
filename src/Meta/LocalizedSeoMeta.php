<?php
/** Locale-aware per-post SEO values for multilingual WordPress sites. */
namespace PublishRankSEO\Meta;

defined( 'ABSPATH' ) || exit;

final class LocalizedSeoMeta {
	const META_KEY = '_publishrank_seo_localized';

	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20, 2 );
		add_action( 'save_post', array( $this, 'save' ), 30, 2 );
	}

	/** Supported locales include configured languages, multilingual-plugin languages, and Urdu by default. */
	public static function locales() {
		$locales = array_keys( self::catalog() );
		$default = LanguageAlternates::default_locale();
		if ( $default ) { $locales[] = $default; }
		$map = LanguageAlternates::parse_map( (string) get_option( 'publishrank_seo_hreflang_map', '' ) );
		$locales = array_merge( $locales, array_keys( $map ) );
		if ( function_exists( 'pll_languages_list' ) ) {
			foreach ( (array) pll_languages_list( array( 'fields' => 'locale' ) ) as $locale ) { $locales[] = $locale; }
		}
		if ( function_exists( 'icl_get_languages' ) ) {
			foreach ( (array) icl_get_languages( 'skip_missing=0' ) as $language ) { $locales[] = $language['default_locale'] ?? ( $language['code'] ?? '' ); }
		}
		$out = array();
		foreach ( $locales as $locale ) { $locale = LanguageAlternates::locale( $locale ); if ( $locale && 'x-default' !== $locale ) { $out[ $locale ] = $locale; } }
		return array_values( $out );
	}

	/** Languages an editor can opt in to per article. The site default language is never listed: it always uses the normal PublishRank fields. */
	public static function optional_locales() {
		$default = strtolower( (string) LanguageAlternates::default_locale() );
		return array_values(
			array_filter(
				self::locales(),
				static function ( $locale ) use ( $default ) {
					return strtolower( (string) $locale ) !== $default;
				}
			)
		);
	}

	/** Common language catalog; configured WPML/Polylang/hreflang locales are merged at runtime. */
	public static function catalog() {
		return array(
			'en' => 'English', 'en-US' => 'English (United States)', 'en-GB' => 'English (United Kingdom)',
			'ur' => 'اردو (Urdu)', 'ur-PK' => 'اردو (پاکستان)', 'ar' => 'العربية (Arabic)', 'ar-SA' => 'العربية (Saudi Arabia)',
			'fa' => 'فارسی (Persian)', 'he' => 'עברית (Hebrew)', 'bn' => 'বাংলা (Bengali)', 'hi' => 'हिन्दी (Hindi)',
			'gu' => 'ગુજરાતી (Gujarati)', 'mr' => 'मराठी (Marathi)', 'ne' => 'नेपाली (Nepali)', 'pa' => 'ਪੰਜਾਬੀ (Punjabi)',
			'ta' => 'தமிழ் (Tamil)', 'te' => 'తెలుగు (Telugu)', 'th' => 'ไทย (Thai)', 'zh-CN' => '简体中文 (Simplified Chinese)',
			'zh-TW' => '繁體中文 (Traditional Chinese)', 'ja' => '日本語 (Japanese)', 'ko' => '한국어 (Korean)',
			'de' => 'Deutsch (German)', 'es' => 'Español (Spanish)', 'fr' => 'Français (French)', 'it' => 'Italiano (Italian)',
			'pt' => 'Português (Portuguese)', 'pt-BR' => 'Português do Brasil (Brazilian Portuguese)', 'nl' => 'Nederlands (Dutch)',
			'pl' => 'Polski (Polish)', 'ru' => 'Русский (Russian)', 'uk' => 'Українська (Ukrainian)', 'tr' => 'Türkçe (Turkish)',
			'sv' => 'Svenska (Swedish)', 'da' => 'Dansk (Danish)', 'no' => 'Norsk (Norwegian)', 'fi' => 'Suomi (Finnish)',
			'cs' => 'Čeština (Czech)', 'el' => 'Ελληνικά (Greek)', 'ro' => 'Română (Romanian)', 'hu' => 'Magyar (Hungarian)',
			'vi' => 'Tiếng Việt (Vietnamese)', 'id' => 'Bahasa Indonesia', 'ms' => 'Bahasa Melayu', 'sw' => 'Kiswahili',
		);
	}

	public static function is_rtl( $locale ) {
		return in_array( strtolower( preg_replace( '/-.*/', '', (string) $locale ) ), array( 'ar', 'fa', 'he', 'ur' ), true );
	}

	/** Resolve an exact locale first, then its base language, without changing the fallback value. */
	public static function get_override( $post_id, $key ) {
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ! get_option( 'publishrank_seo_multilingual_enabled', true ) ) { return ''; }
		$field = self::field( $key );
		if ( ! $field ) { return ''; }
		$locale = LanguageAlternates::current_locale();
		$data = get_post_meta( (int) $post_id, self::META_KEY, true );
		if ( ! is_array( $data ) || ! $locale ) { return ''; }
		$default = strtolower( (string) LanguageAlternates::default_locale() );
		foreach ( array( $locale, preg_replace( '/-.*/', '', $locale ) ) as $candidate ) {
			// The default language (English on most sites) always uses the normal PublishRank fields, so it can never clash with an override.
			if ( strtolower( (string) $candidate ) === $default ) {
				continue; }
			if ( ! empty( $data[ $candidate ][ $field ] ) && is_scalar( $data[ $candidate ][ $field ] ) ) { return (string) $data[ $candidate ][ $field ]; }
		}
		return '';
	}

	public static function field( $key ) {
		$map = array( PostMeta::TITLE => 'title', PostMeta::DESCRIPTION => 'description', PostMeta::FOCUS_KEYWORD => 'focus_keyword', PostMeta::OG_TITLE => 'og_title', PostMeta::OG_DESCRIPTION => 'og_description', PostMeta::OG_IMAGE => 'og_image', PostMeta::ROBOTS => 'robots' );
		return $map[ $key ] ?? '';
	}

	public function add_meta_box( $post_type, $post ) {
		if ( ! $post instanceof \WP_Post || ! post_type_supports( $post_type, 'editor' ) || ! current_user_can( 'edit_post', $post->ID ) ) { return; }
		add_meta_box( 'publishrank-localized-seo', __( 'PublishRank Multilingual SEO', 'publishrank' ), array( $this, 'render' ), $post_type, 'normal', 'default' );
	}

	public function render( $post ) {
		$data = get_post_meta( $post->ID, self::META_KEY, true );
		$data = is_array( $data ) ? $data : array();
		wp_nonce_field( 'publishrank_localized_seo', 'publishrank_localized_seo_nonce' );
		$catalog = self::catalog();
		$default = (string) LanguageAlternates::default_locale();
		$default_label = isset( $catalog[ $default ] ) ? $catalog[ $default ] : $default;
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: default language name. */
				__( 'The default language (%s) always uses the normal PublishRank fields. Tick a language below only if this article has its own version in it; each ticked language gets its own SEO fields and overrides the normal ones on that language only.', 'publishrank' ),
				$default_label
			)
		) . '</p>';
		$optional = self::optional_locales();
		echo '<div class="publishrank-lang-picker" style="display:flex;flex-wrap:wrap;gap:6px 16px;margin:8px 0 4px">';
		foreach ( $optional as $locale ) {
			$label = isset( $catalog[ $locale ] ) ? $catalog[ $locale ] : $locale;
			$on    = ! empty( $data[ $locale ] ) && is_array( $data[ $locale ] );
			echo '<label><input type="checkbox" class="publishrank-lang-toggle" data-lang="' . esc_attr( $locale ) . '" name="publishrank_seo_localized_on[' . esc_attr( $locale ) . ']" value="1"' . checked( $on, true, false ) . ' /> ' . esc_html( $locale . ' — ' . $label ) . '</label>';
		}
		echo '</div><p class="description">' . esc_html__( 'Unticking a language and updating the article removes that language\'s overrides.', 'publishrank' ) . '</p>';
		foreach ( $optional as $locale ) {
			$label = isset( $catalog[ $locale ] ) ? $catalog[ $locale ] : $locale;
			$row   = isset( $data[ $locale ] ) && is_array( $data[ $locale ] ) ? $data[ $locale ] : array();
			$on    = ! empty( $row );
			echo '<fieldset class="publishrank-lang-box" data-lang="' . esc_attr( $locale ) . '"' . ( $on ? '' : ' hidden' ) . ' dir="' . esc_attr( self::is_rtl( $locale ) ? 'rtl' : 'ltr' ) . '" style="border:1px solid #dcdcde;padding:12px;margin:12px 0"><legend><strong>' . esc_html( $locale . ' — ' . $label ) . '</strong></legend>';
			foreach ( array( 'title' => __( 'SEO title', 'publishrank' ), 'description' => __( 'Meta description', 'publishrank' ), 'focus_keyword' => __( 'Focus keyword', 'publishrank' ), 'og_title' => __( 'Open Graph title', 'publishrank' ), 'og_description' => __( 'Open Graph description', 'publishrank' ), 'og_image' => __( 'Open Graph image URL', 'publishrank' ), 'robots' => __( 'Robots directives', 'publishrank' ) ) as $field => $field_label ) {
				$input = 'description' === $field || 'og_description' === $field ? 'textarea' : 'input';
				$value = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
				echo '<p><label><strong>' . esc_html( $field_label ) . '</strong><br />';
				if ( 'textarea' === $input ) { echo '<textarea class="widefat" rows="2" name="publishrank_seo_localized[' . esc_attr( $locale ) . '][' . esc_attr( $field ) . ']">' . esc_textarea( $value ) . '</textarea>'; } else { echo '<input class="widefat" type="text" name="publishrank_seo_localized[' . esc_attr( $locale ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( $value ) . '" />'; }
				echo '</label></p>';
			}
			echo '</fieldset>';
		}
		// Show or hide a language's fields as it is ticked or unticked.
		echo '<script>document.querySelectorAll(".publishrank-lang-toggle").forEach(function(t){t.addEventListener("change",function(){var b=document.querySelector(".publishrank-lang-box[data-lang=\""+t.getAttribute("data-lang")+"\"]");if(b){b.hidden=!t.checked;}});});</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static script.
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['publishrank_localized_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_localized_seo_nonce'] ) ), 'publishrank_localized_seo' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$posted = isset( $_POST['publishrank_seo_localized'] ) && is_array( $_POST['publishrank_seo_localized'] ) ? wp_unslash( $_POST['publishrank_seo_localized'] ) : array();
		$optional = self::optional_locales();
		$opted_in = isset( $_POST['publishrank_seo_localized_on'] ) && is_array( $_POST['publishrank_seo_localized_on'] ) ? array_keys( wp_unslash( $_POST['publishrank_seo_localized_on'] ) ) : array();
		$existing = get_post_meta( $post_id, self::META_KEY, true );
		$clean    = array();
		// Rows for locales the panel no longer shows (for example the default language) are carried over untouched.
		if ( is_array( $existing ) ) {
			foreach ( $existing as $locale => $row ) {
				if ( ! in_array( $locale, $optional, true ) && is_array( $row ) ) { $clean[ $locale ] = $row; }
			}
		}
		foreach ( $optional as $locale ) {
			if ( ! in_array( $locale, $opted_in, true ) ) { continue; }
			$row = isset( $posted[ $locale ] ) && is_array( $posted[ $locale ] ) ? $posted[ $locale ] : array();
			foreach ( array( 'title' => 255, 'description' => 320, 'focus_keyword' => 120, 'og_title' => 255, 'og_description' => 320, 'og_image' => 2048, 'robots' => 120 ) as $field => $length ) {
				$value = isset( $row[ $field ] ) ? ( 'og_image' === $field ? PostMeta::sanitize_public_url( (string) $row[ $field ] ) : ( 'robots' === $field ? sanitize_text_field( (string) $row[ $field ] ) : TemplateResolver::sanitize_template( (string) $row[ $field ], $length ) ) ) : '';
				if ( '' !== $value ) { $clean[ $locale ][ $field ] = $value; }
			}
		}
		if ( empty( $clean ) ) { delete_post_meta( $post_id, self::META_KEY ); } else { update_post_meta( $post_id, self::META_KEY, $clean ); }
		SeoData::flush( (int) $post_id );
	}
}
/* EOF */
