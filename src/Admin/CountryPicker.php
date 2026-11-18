<?php
/**
 * Searchable multi-country selector shared by Traffic Guard and Access Shield.
 *
 * Progressive enhancement: the server renders a plain text field holding comma-separated ISO codes
 * (alpha-2 or alpha-3 accepted). assets/js/country-picker.js turns it into an accessible combobox
 * with chips, type-ahead search by name, alpha-2 or alpha-3 code, keyboard navigation and paste
 * support. Without JavaScript the text field keeps working.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Countries;

defined( 'ABSPATH' ) || exit;

final class CountryPicker {
	/** @var bool */
	private static $data_printed = false;

	public static function enqueue() {
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/country-picker.js';
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/country-picker.css';
		wp_enqueue_style( 'publishrank-country-picker', Branding::asset_url( 'css/country-picker.css' ), array(), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_enqueue_script( 'publishrank-country-picker', Branding::asset_url( 'js/country-picker.js' ), array(), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_localize_script(
			'publishrank-country-picker',
			'publishrankCountryPicker',
			array(
				'i18n' => array(
					'placeholder' => __( 'Search by country name or code…', 'publishrank' ),
					'remove'      => __( 'Remove %s', 'publishrank' ),
					'none'        => __( 'No countries selected.', 'publishrank' ),
					'noMatch'     => __( 'No matching country.', 'publishrank' ),
					'selected'    => __( '%d selected', 'publishrank' ),
					'clear'       => __( 'Clear all', 'publishrank' ),
					'added'       => __( '%s added', 'publishrank' ),
					'removed'     => __( '%s removed', 'publishrank' ),
				),
			)
		);
	}

	/**
	 * Print a picker.
	 *
	 * @param string   $name     Form field name.
	 * @param string[] $selected Alpha-2 codes.
	 * @param string   $label    Visible label.
	 * @param string   $help     Help text.
	 * @param bool     $special  Offer T1 (Tor network).
	 */
	public static function render( $name, array $selected, $label, $help = '', $special = false ) {
		self::print_data();
		$id = 'prcp-' . substr( md5( $name ), 0, 8 );
		echo '<div class="prx-field pr-country-picker" data-special="' . ( $special ? '1' : '0' ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="text" class="prx-input pr-country-picker__raw" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( implode( ', ', $selected ) ) . '" autocomplete="off" spellcheck="false"' . ( '' !== $help ? ' aria-describedby="' . esc_attr( $id ) . '-help"' : '' ) . ' />';
		if ( '' !== $help ) {
			echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/** Country data once per page, as JSON (no inline script execution). */
	private static function print_data() {
		if ( self::$data_printed ) {
			return;
		}
		self::$data_printed = true;
		$rows = array();
		foreach ( Countries::records() as $r ) {
			$rows[] = array( $r['code'], $r['alpha3'], $r['name'] );
		}
		$rows[] = array( 'T1', '', Countries::name( 'T1' ) );
		echo '<script type="application/json" id="pr-country-data">' . wp_json_encode( $rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . '</script>';
	}
}
