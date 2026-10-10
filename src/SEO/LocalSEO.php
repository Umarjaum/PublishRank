<?php
/**
 * Local business profile, settings UI, and connected LocalBusiness JSON-LD.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class LocalSEO {
	const OPTION = 'publishrank_local_seo_profile';
	const SLUG = 'publishrank-local-seo';

	/** Register front-end schema and the settings screen. */
	public function register() {
		add_filter( 'publishrank_schema_graph', array( $this, 'append_schema' ), 30, 2 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 18 );
			add_action( 'admin_post_publishrank_local_seo_save', array( $this, 'save' ) );
		}
	}

	/** @return array<string,string> Supported LocalBusiness subtypes. */
	public static function types() {
		return array(
			'LocalBusiness' => 'Local business',
			'ProfessionalService' => 'Professional service',
			'Store' => 'Store / shop',
			'Restaurant' => 'Restaurant',
			'LegalService' => 'Legal service',
			'HomeAndConstructionBusiness' => 'Home and construction',
			'AutomotiveBusiness' => 'Automotive business',
			'HealthAndBeautyBusiness' => 'Health and beauty',
			'EntertainmentBusiness' => 'Entertainment business',
		);
	}

	/** Sanitize the profile with conservative, field-specific validation. */
	public static function sanitize_profile( $input ) {
		$input = is_array( $input ) ? $input : array();
		$type = isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : 'LocalBusiness';
		if ( ! array_key_exists( $type, self::types() ) ) { $type = 'LocalBusiness'; }
		$clean = array(
			'enabled' => ! empty( $input['enabled'] ) ? 1 : 0,
			'name' => isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '',
			'type' => $type,
			'description' => isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '',
			'url' => isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '',
			'phone' => isset( $input['phone'] ) ? sanitize_text_field( $input['phone'] ) : '',
			'email' => isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '',
			'logo' => isset( $input['logo'] ) ? esc_url_raw( trim( (string) $input['logo'] ) ) : '',
			'street' => isset( $input['street'] ) ? sanitize_text_field( $input['street'] ) : '',
			'city' => isset( $input['city'] ) ? sanitize_text_field( $input['city'] ) : '',
			'region' => isset( $input['region'] ) ? sanitize_text_field( $input['region'] ) : '',
			'postal_code' => isset( $input['postal_code'] ) ? sanitize_text_field( $input['postal_code'] ) : '',
			'country' => isset( $input['country'] ) ? self::sanitize_country( $input['country'] ) : '',
			'latitude' => isset( $input['latitude'] ) ? self::coordinate( $input['latitude'], -90, 90 ) : '',
			'longitude' => isset( $input['longitude'] ) ? self::coordinate( $input['longitude'], -180, 180 ) : '',
			'price_range' => isset( $input['price_range'] ) ? sanitize_text_field( $input['price_range'] ) : '',
			'has_map' => isset( $input['has_map'] ) ? esc_url_raw( trim( (string) $input['has_map'] ) ) : '',
			'service_area' => isset( $input['service_area'] ) ? sanitize_text_field( $input['service_area'] ) : '',
			'opening_hours' => isset( $input['opening_hours'] ) ? self::sanitize_hours( $input['opening_hours'] ) : array(),
			'same_as' => isset( $input['same_as'] ) ? self::sanitize_profiles( $input['same_as'] ) : array(),
		);
		return $clean;
	}

	/** Keep country names readable while normalizing ISO-style two-letter codes. */
	private static function sanitize_country( $value ) {
		$value = sanitize_text_field( $value );
		return 2 === strlen( $value ) ? strtoupper( $value ) : $value;
	}

	/** @param mixed $value @return string */
	private static function coordinate( $value, $min, $max ) {
		if ( '' === trim( (string) $value ) || ! is_numeric( $value ) ) { return ''; }
		$value = (float) $value;
		return ( $value >= $min && $value <= $max ) ? (string) $value : '';
	}

	/** Accept one Schema.org openingHours string per line, e.g. Mo-Fr 09:00-17:00. */
	public static function sanitize_hours( $value ) {
		if ( ! is_string( $value ) ) { return array(); }
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		$valid = array();
		$days = '(?:Mo|Tu|We|Th|Fr|Sa|Su)(?:-(?:Mo|Tu|We|Th|Fr|Sa|Su))?(?:,(?:Mo|Tu|We|Th|Fr|Sa|Su)(?:-(?:Mo|Tu|We|Th|Fr|Sa|Su))?)*';
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( preg_match( '/^' . $days . ' (?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d$/', $line ) ) { $valid[] = $line; }
		}
		return array_values( array_unique( $valid ) );
	}

	/** @param mixed $value @return array<int,string> */
	private static function sanitize_profiles( $value ) {
		if ( ! is_string( $value ) ) { return array(); }
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
			$url = esc_url_raw( trim( $line ) );
			if ( $url && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'https', 'http' ), true ) ) { $out[] = $url; }
		}
		return array_values( array_unique( $out ) );
	}

	/** Build schema without relying on the admin request; useful for deterministic tests. */
	public static function build_schema( $profile, $site_url = '' ) {
		$profile = is_array( $profile ) ? $profile : array();
		if ( empty( $profile['enabled'] ) || empty( $profile['name'] ) ) { return array(); }
		$types = self::types();
		$type = isset( $profile['type'] ) && isset( $types[ $profile['type'] ] ) ? $profile['type'] : 'LocalBusiness';
		$site_url = $site_url ? trailingslashit( $site_url ) : trailingslashit( home_url( '/' ) );
		$node = array(
			'@type' => $type,
			'@id' => $site_url . '#local-business',
			'name' => sanitize_text_field( $profile['name'] ),
			'url' => ! empty( $profile['url'] ) ? esc_url_raw( $profile['url'] ) : $site_url,
		);
		foreach ( array( 'description' => 'description', 'phone' => 'telephone', 'price_range' => 'priceRange', 'has_map' => 'hasMap' ) as $from => $to ) {
			if ( ! empty( $profile[ $from ] ) ) { $node[ $to ] = 'description' === $from ? wp_strip_all_tags( $profile[ $from ] ) : ( 'has_map' === $from ? esc_url_raw( $profile[ $from ] ) : sanitize_text_field( $profile[ $from ] ) ); }
		}
		if ( ! empty( $profile['email'] ) && is_email( $profile['email'] ) ) { $node['email'] = sanitize_email( $profile['email'] ); }
		if ( ! empty( $profile['logo'] ) ) { $node['image'] = esc_url_raw( $profile['logo'] ); }
		$address = array( '@type' => 'PostalAddress' );
		foreach ( array( 'street' => 'streetAddress', 'city' => 'addressLocality', 'region' => 'addressRegion', 'postal_code' => 'postalCode', 'country' => 'addressCountry' ) as $from => $to ) {
			if ( ! empty( $profile[ $from ] ) ) { $address[ $to ] = sanitize_text_field( $profile[ $from ] ); }
		}
		if ( count( $address ) > 1 ) { $node['address'] = $address; }
		if ( isset( $profile['latitude'], $profile['longitude'] ) && '' !== (string) $profile['latitude'] && '' !== (string) $profile['longitude'] && is_numeric( $profile['latitude'] ) && is_numeric( $profile['longitude'] ) && (float) $profile['latitude'] >= -90 && (float) $profile['latitude'] <= 90 && (float) $profile['longitude'] >= -180 && (float) $profile['longitude'] <= 180 ) {
			$node['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => (float) $profile['latitude'], 'longitude' => (float) $profile['longitude'] );
		}
		if ( ! empty( $profile['service_area'] ) ) { $node['areaServed'] = sanitize_text_field( $profile['service_area'] ); }
		$hours = isset( $profile['opening_hours'] ) && is_array( $profile['opening_hours'] ) ? self::sanitize_hours( implode( "\n", $profile['opening_hours'] ) ) : array();
		if ( $hours ) { $node['openingHours'] = $hours; }
		$same_as = isset( $profile['same_as'] ) && is_array( $profile['same_as'] ) ? $profile['same_as'] : array();
		$same_as = array_values( array_filter( array_map( 'esc_url_raw', $same_as ) ) );
		if ( $same_as ) { $node['sameAs'] = $same_as; }
		return $node;
	}

	/** Add the configured local entity only when explicitly enabled and named. */
	public function append_schema( $graph, $graph_output = null ) {
		$profile = get_option( self::OPTION, array() );
		$node = self::build_schema( $profile, \PublishRankSEO\Core\PublicationProfile::primary_url() );
		if ( $node ) {
			if ( ! \PublishRankSEO\Core\PublicationProfile::identity_is_person() ) {
				$node['branchOf'] = array( '@id' => trailingslashit( \PublishRankSEO\Core\PublicationProfile::primary_url() ) . '#organization' );
			}
			$graph[] = $node;
		}
		return $graph;
	}

	public function menu() {
		add_submenu_page( \PublishRankSEO\Core\Branding::MENU_SLUG, __( 'Local SEO', 'publishrank' ), __( 'Local SEO', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to manage Local SEO.', 'publishrank' ) ); }
		check_admin_referer( 'publishrank_local_seo_save' );
		$input = isset( $_POST['local_seo'] ) ? wp_unslash( $_POST['local_seo'] ) : array();
		$profile = self::sanitize_profile( $input );
		update_option( self::OPTION, $profile, false );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to manage Local SEO.', 'publishrank' ) ); }
		$p = get_option( self::OPTION, array() );
		$p = wp_parse_args( is_array( $p ) ? $p : array(), array( 'enabled' => 0, 'name' => '', 'type' => 'LocalBusiness', 'description' => '', 'url' => '', 'phone' => '', 'email' => '', 'logo' => '', 'street' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'country' => '', 'latitude' => '', 'longitude' => '', 'price_range' => '', 'has_map' => '', 'service_area' => '', 'opening_hours' => array(), 'same_as' => array() ) );
		$hours = is_array( $p['opening_hours'] ) ? implode( "\n", $p['opening_hours'] ) : '';
		$same_as = is_array( $p['same_as'] ) ? implode( "\n", $p['same_as'] ) : '';
		echo '<div class="wrap">';
		\PublishRankSEO\Admin\AdminAssets::header( __( 'Local SEO', 'publishrank' ), __( 'Configure a real-world business location and emit connected LocalBusiness structured data. Keep this disabled if your site has no public-facing business location.', 'publishrank' ) );
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Local SEO settings saved.', 'publishrank' ) . '</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_local_seo_save" />';
		wp_nonce_field( 'publishrank_local_seo_save' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row( 'enabled', __( 'Enable LocalBusiness schema', 'publishrank' ), '<label><input type="checkbox" name="local_seo[enabled]" value="1" ' . checked( ! empty( $p['enabled'] ), true, false ) . ' /> ' . esc_html__( 'Output LocalBusiness JSON-LD on public pages.', 'publishrank' ) . '</label>' );
		$this->input_row( 'name', __( 'Business / location name', 'publishrank' ), $p['name'], 'text', true, __( 'Use the real-world name shown to customers.', 'publishrank' ) );
		$type_options = '';
		foreach ( self::types() as $value => $label ) { $type_options .= '<option value="' . esc_attr( $value ) . '" ' . selected( $p['type'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		$this->row( 'type', __( 'Business type', 'publishrank' ), '<select name="local_seo[type]">' . $type_options . '</select>' );
		$this->textarea_row( 'description', __( 'Description', 'publishrank' ), $p['description'] );
		$this->input_row( 'url', __( 'Business URL', 'publishrank' ), $p['url'], 'url' );
		$this->input_row( 'phone', __( 'Public telephone', 'publishrank' ), $p['phone'] );
		$this->input_row( 'email', __( 'Public email', 'publishrank' ), $p['email'], 'email' );
		$this->input_row( 'logo', __( 'Logo / image URL', 'publishrank' ), $p['logo'], 'url' );
		echo '<tr><th scope="row">' . esc_html__( 'Address', 'publishrank' ) . '</th><td>';
		foreach ( array( 'street' => __( 'Street address', 'publishrank' ), 'city' => __( 'City / locality', 'publishrank' ), 'region' => __( 'State / region', 'publishrank' ), 'postal_code' => __( 'Postal code', 'publishrank' ), 'country' => __( 'Country code or name', 'publishrank' ) ) as $key => $label ) { echo '<p><label>' . esc_html( $label ) . '<br /><input class="regular-text" name="local_seo[' . esc_attr( $key ) . ']" value="' . esc_attr( $p[ $key ] ) . '" /></label></p>'; }
		echo '</td></tr>';
		$this->input_row( 'latitude', __( 'Latitude', 'publishrank' ), $p['latitude'], 'number', false, __( 'Optional decimal coordinate from -90 to 90.', 'publishrank' ) );
		$this->input_row( 'longitude', __( 'Longitude', 'publishrank' ), $p['longitude'], 'number', false, __( 'Optional decimal coordinate from -180 to 180.', 'publishrank' ) );
		$this->input_row( 'price_range', __( 'Price range', 'publishrank' ), $p['price_range'], 'text', false, __( 'Optional, e.g. $$; use only if applicable.', 'publishrank' ) );
		$this->input_row( 'has_map', __( 'Map URL', 'publishrank' ), $p['has_map'], 'url' );
		$this->input_row( 'service_area', __( 'Service area', 'publishrank' ), $p['service_area'], 'text', false, __( 'Optional description, e.g. Karachi metropolitan area.', 'publishrank' ) );
		$this->textarea_row( 'opening_hours', __( 'Opening hours', 'publishrank' ), $hours, __( 'One line per schedule, e.g. Mo-Fr 09:00-17:00. Valid day abbreviations: Mo, Tu, We, Th, Fr, Sa, Su.', 'publishrank' ) );
		$this->textarea_row( 'same_as', __( 'Official social / profile URLs', 'publishrank' ), $same_as, __( 'One full http(s) URL per line.', 'publishrank' ) );
		echo '</tbody></table><p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save Local SEO profile', 'publishrank' ) . '</button></p></form>';
		echo '<p class="description">' . esc_html__( 'Only enable this for a real, public-facing business/location and use accurate details. Structured data does not guarantee rich results or local rankings.', 'publishrank' ) . '</p></div>';
	}

	private function row( $key, $label, $html ) { echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; }
	private function input_row( $key, $label, $value, $type = 'text', $required = false, $description = '' ) {
		$attrs = 'url' === $type ? ' type="url"' : ( 'email' === $type ? ' type="email"' : ( 'number' === $type ? ' type="number" step="any"' : ' type="text"' ) );
		$this->row( $key, $label, '<input class="regular-text"' . $attrs . ' name="local_seo[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required ' : '' ) . '/>' . ( $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '' ) );
	}
	private function textarea_row( $key, $label, $value, $description = '' ) { $this->row( $key, $label, '<textarea class="large-text" rows="4" name="local_seo[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>' . ( $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '' ) ); }
}
