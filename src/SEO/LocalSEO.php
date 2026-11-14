<?php
/**
 * Local business profile, settings UI, and connected LocalBusiness JSON-LD.
 *
 * Every business type is a schema.org LocalBusiness subtype (or is multi-typed with LocalBusiness), so
 * every property PublishRank emits is valid for the selected type. Type-specific fields (menu and cuisine
 * for food, check-in times for lodging, medical specialties for clinics, ...) are wired by "family" and only
 * emitted for the types schema.org defines them on.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class LocalSEO {
	const OPTION = 'publishrank_local_seo_profile';
	const SLUG   = 'publishrank-local-seo';

	/** Register front-end schema and the settings screen. */
	public function register() {
		add_filter( 'publishrank_schema_graph', array( $this, 'append_schema' ), 30, 2 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 18 );
			add_action( 'admin_post_publishrank_local_seo_save', array( $this, 'save' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 1002 );
		}
	}

	/* --------------------------------------------------------------------------------------------
	 * Business types
	 * ------------------------------------------------------------------------------------------ */

	/**
	 * Business types grouped by niche. Every key is a schema.org type. Keys that are not LocalBusiness
	 * subtypes themselves are mapped to a valid emitted @type by schema_type().
	 *
	 * @return array<string,array<string,string>> Niche label => type => option label.
	 */
	public static function niches() {
		return array(
			'General' => array(
				'LocalBusiness'               => 'Local business (generic)',
				'ProfessionalService'         => 'Professional service (generic)',
				'Store'                       => 'Store / shop (generic)',
			),
			'Food & drink' => array(
				'FoodEstablishment'           => 'Food establishment (generic)',
				'Restaurant'                  => 'Restaurant',
				'FastFoodRestaurant'          => 'Fast food restaurant',
				'CafeOrCoffeeShop'            => 'Cafe / coffee shop',
				'Bakery'                      => 'Bakery',
				'IceCreamShop'                => 'Ice cream shop',
				'BarOrPub'                    => 'Bar or pub',
				'Brewery'                     => 'Brewery',
				'Winery'                      => 'Winery',
				'Distillery'                  => 'Distillery',
			),
			'Health & medical' => array(
				'MedicalBusiness'             => 'Medical business (generic)',
				'MedicalClinic'               => 'Medical clinic',
				'Physician'                   => 'Doctor / physician practice',
				'Hospital'                    => 'Hospital',
				'Dentist'                     => 'Dentist',
				'Optician'                    => 'Optician',
				'Pharmacy'                    => 'Pharmacy / drugstore',
				'VeterinaryCare'              => 'Veterinary care',
			),
			'Beauty & wellness' => array(
				'HealthAndBeautyBusiness'     => 'Health & beauty (generic)',
				'BeautySalon'                 => 'Beauty salon',
				'HairSalon'                   => 'Hair salon',
				'NailSalon'                   => 'Nail salon',
				'DaySpa'                      => 'Day spa',
				'TattooParlor'                => 'Tattoo parlor',
				'HealthClub'                  => 'Health club / gym',
			),
			'Home & construction services' => array(
				'HomeAndConstructionBusiness' => 'Home & construction (generic)',
				'Electrician'                 => 'Electrician',
				'Plumber'                     => 'Plumber',
				'HVACBusiness'                => 'Heating / ventilation / AC (HVAC)',
				'RoofingContractor'           => 'Roofing contractor',
				'HousePainter'                => 'House painter',
				'GeneralContractor'           => 'General contractor',
				'Locksmith'                   => 'Locksmith',
				'MovingCompany'               => 'Moving company',
			),
			'Automotive' => array(
				'AutomotiveBusiness'          => 'Automotive (generic)',
				'AutoBodyShop'                => 'Auto body shop',
				'AutoDealer'                  => 'Car dealership',
				'AutoPartsStore'              => 'Auto parts store',
				'AutoRental'                  => 'Car rental',
				'AutoRepair'                  => 'Auto repair shop',
				'AutoWash'                    => 'Car wash',
				'GasStation'                  => 'Gas station',
				'MotorcycleDealer'            => 'Motorcycle dealer',
				'MotorcycleRepair'            => 'Motorcycle repair',
			),
			'Legal, finance & real estate' => array(
				'LegalService'                => 'Legal service (generic)',
				'Attorney'                    => 'Attorney / law firm',
				'Notary'                      => 'Notary',
				'AccountingService'           => 'Accountant / bookkeeping',
				'FinancialService'            => 'Financial service (generic)',
				'BankOrCreditUnion'           => 'Bank or credit union',
				'AutomatedTeller'             => 'ATM',
				'InsuranceAgency'             => 'Insurance agency',
				'RealEstateAgent'             => 'Real estate agency',
				'EmploymentAgency'            => 'Employment / staffing agency',
				'TravelAgency'                => 'Travel agency',
			),
			'Lodging & travel stays' => array(
				'LodgingBusiness'             => 'Lodging (generic)',
				'Hotel'                       => 'Hotel',
				'Motel'                       => 'Motel',
				'Hostel'                      => 'Hostel',
				'BedAndBreakfast'             => 'Bed & breakfast',
				'VacationRental'              => 'Vacation rental',
				'Campground'                  => 'Campground',
				'Resort'                      => 'Resort',
			),
			'Retail & shopping' => array(
				'ShoppingCenter'              => 'Shopping center / mall',
				'BikeStore'                   => 'Bike store',
				'BookStore'                   => 'Book store',
				'ClothingStore'               => 'Clothing store',
				'ComputerStore'               => 'Computer store',
				'ConvenienceStore'            => 'Convenience store',
				'DepartmentStore'             => 'Department store',
				'ElectronicsStore'            => 'Electronics store',
				'Florist'                     => 'Florist',
				'FurnitureStore'              => 'Furniture store',
				'GardenStore'                 => 'Garden store',
				'GroceryStore'                => 'Grocery store',
				'HardwareStore'               => 'Hardware store',
				'HobbyShop'                   => 'Hobby shop',
				'HomeGoodsStore'              => 'Home goods store',
				'JewelryStore'                => 'Jewelry store',
				'LiquorStore'                 => 'Liquor store',
				'MensClothingStore'           => "Men's clothing store",
				'MobilePhoneStore'            => 'Mobile phone store',
				'MovieRentalStore'            => 'Movie rental store',
				'MusicStore'                  => 'Music store',
				'OfficeEquipmentStore'        => 'Office equipment store',
				'OutletStore'                 => 'Outlet store',
				'PawnShop'                    => 'Pawn shop',
				'PetStore'                    => 'Pet store',
				'ShoeStore'                   => 'Shoe store',
				'SportingGoodsStore'          => 'Sporting goods store',
				'TireShop'                    => 'Tire shop',
				'ToyStore'                    => 'Toy store',
				'WholesaleStore'              => 'Wholesale store',
			),
			'Entertainment & nightlife' => array(
				'EntertainmentBusiness'       => 'Entertainment (generic)',
				'AmusementPark'               => 'Amusement park',
				'ArtGallery'                  => 'Art gallery',
				'Casino'                      => 'Casino',
				'ComedyClub'                  => 'Comedy club',
				'MovieTheater'                => 'Movie theater / cinema',
				'NightClub'                   => 'Nightclub',
				'InternetCafe'                => 'Internet cafe',
			),
			'Sports & recreation' => array(
				'SportsActivityLocation'      => 'Sports venue (generic)',
				'BowlingAlley'                => 'Bowling alley',
				'ExerciseGym'                 => 'Exercise gym',
				'GolfCourse'                  => 'Golf course',
				'PublicSwimmingPool'          => 'Public swimming pool',
				'SkiResort'                   => 'Ski resort',
				'SportsClub'                  => 'Sports club',
				'StadiumOrArena'              => 'Stadium / arena',
				'TennisComplex'               => 'Tennis complex',
			),
			'Education & child care' => array(
				'EducationalOrganization'     => 'School / academy / tuition centre (generic)',
				'ChildCare'                   => 'Child care / daycare',
				'Preschool'                   => 'Preschool / nursery',
				'ElementarySchool'            => 'Primary / elementary school',
				'MiddleSchool'                => 'Middle school',
				'HighSchool'                  => 'High school',
				'CollegeOrUniversity'         => 'College / university',
			),
			'Services & community' => array(
				'FuneralHome'                 => 'Funeral home',
				'DryCleaningOrLaundry'        => 'Dry cleaner / laundry',
				'SelfStorage'                 => 'Self storage',
				'RecyclingCenter'             => 'Recycling center',
				'AnimalShelter'               => 'Animal shelter',
				'Library'                     => 'Library',
				'TouristInformationCenter'    => 'Tourist information center',
			),
			'Public services & media' => array(
				'GovernmentOffice'            => 'Government office',
				'PostOffice'                  => 'Post office',
				'PoliceStation'               => 'Police station',
				'FireStation'                 => 'Fire station',
				'EmergencyService'            => 'Emergency service (generic)',
				'RadioStation'                => 'Radio station',
				'TelevisionStation'           => 'Television station',
			),
		);
	}

	/** @return array<string,string> Flat map of every supported business type. */
	public static function types() {
		$types = array();
		foreach ( self::niches() as $group ) {
			foreach ( $group as $type => $label ) {
				if ( ! isset( $types[ $type ] ) ) {
					$types[ $type ] = $label; }
			}
		}
		return $types;
	}

	/**
	 * Types whose own schema.org class is not a LocalBusiness subtype. They are multi-typed with
	 * LocalBusiness so opening hours, price range and payment details stay valid.
	 */
	const MULTI_TYPED = array( 'VeterinaryCare', 'EducationalOrganization', 'Preschool', 'ElementarySchool', 'MiddleSchool', 'HighSchool', 'CollegeOrUniversity' );

	/**
	 * Option keys with no schema.org class of their own, mapped to a valid @type plus a
	 * Product Types Ontology additionalType that names the real-world category.
	 */
	const UNMAPPED = array(
		'FuneralHome' => array( 'LocalBusiness', 'http://www.productontology.org/id/Funeral_home' ),
	);

	/**
	 * Schema.org @type value emitted for a stored type key: a string, or an array when multi-typed.
	 *
	 * @param string $key Stored type key.
	 * @return string|array<int,string>
	 */
	public static function schema_type( $key ) {
		$key = array_key_exists( (string) $key, self::types() ) ? (string) $key : 'LocalBusiness';
		if ( isset( self::UNMAPPED[ $key ] ) ) {
			return self::UNMAPPED[ $key ][0]; }
		if ( in_array( $key, self::MULTI_TYPED, true ) ) {
			return array( $key, 'LocalBusiness' ); }
		return $key;
	}

	/** additionalType URL for option keys without their own schema.org class. */
	public static function additional_type( $key ) {
		return isset( self::UNMAPPED[ $key ] ) ? self::UNMAPPED[ $key ][1] : '';
	}

	/**
	 * Families decide which specialised fields apply. Membership mirrors the schema.org hierarchy so a
	 * property is only offered (and emitted) for types it is defined on.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function family_map() {
		$niches = self::niches();
		$keys   = static function ( $niche ) use ( $niches ) {
			return isset( $niches[ $niche ] ) ? array_keys( $niches[ $niche ] ) : array();
		};
		return array(
			'food'        => $keys( 'Food & drink' ),
			'lodging'     => array_merge( $keys( 'Lodging & travel stays' ), array( 'SkiResort' ) ),
			'medical'     => $keys( 'Health & medical' ),
			// schema.org defines medicalSpecialty / isAcceptingNewPatients on MedicalOrganization and its subtypes only.
			'medical_org' => array( 'MedicalClinic', 'Physician', 'Hospital', 'Dentist', 'Pharmacy', 'VeterinaryCare' ),
			'financial'   => array( 'FinancialService', 'AccountingService', 'BankOrCreditUnion', 'AutomatedTeller', 'InsuranceAgency' ),
			'retail'      => array_merge( array( 'Store', 'AutoPartsStore', 'Florist' ), $keys( 'Retail & shopping' ) ),
			'automotive'  => $keys( 'Automotive' ),
			'venue'       => array_merge( $keys( 'Entertainment & nightlife' ), $keys( 'Sports & recreation' ), array( 'HealthClub' ) ),
			'home'        => $keys( 'Home & construction services' ),
		);
	}

	/** @return array<int,string> Families of one stored type key. */
	public static function families_of( $type ) {
		$out = array();
		foreach ( self::family_map() as $family => $types ) {
			if ( in_array( (string) $type, $types, true ) ) {
				$out[] = $family; }
		}
		return $out;
	}

	/** Whether a stored type belongs to a family. */
	public static function in_family( $type, $family ) {
		return in_array( $family, self::families_of( $type ), true );
	}

	/**
	 * Build <option>/<optgroup> markup for the Business type dropdown.
	 *
	 * @param string $selected Currently stored type value.
	 * @return string
	 */
	public static function type_options_html( $selected = '' ) {
		$html = '';
		foreach ( self::niches() as $niche => $group ) {
			$html .= '<optgroup label="' . esc_attr( $niche ) . '">';
			foreach ( $group as $value => $label ) {
				$html .= '<option value="' . esc_attr( $value ) . '" data-families="' . esc_attr( implode( ' ', self::families_of( $value ) ) ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</optgroup>';
		}
		return $html;
	}

	/** @return array<string,string> MedicalSpecialty enumeration members => label. */
	public static function medical_specialties() {
		return array(
			'Anesthesia' => 'Anesthesia', 'Audiology' => 'Audiology', 'Cardiovascular' => 'Cardiology', 'CommunityHealth' => 'Community health',
			'Dentistry' => 'Dentistry', 'Dermatology' => 'Dermatology', 'DietNutrition' => 'Diet & nutrition', 'Emergency' => 'Emergency medicine',
			'Endocrine' => 'Endocrinology', 'Gastroenterologic' => 'Gastroenterology', 'Genetic' => 'Genetics', 'Geriatric' => 'Geriatrics',
			'Gynecologic' => 'Gynecology', 'Hematologic' => 'Hematology', 'Infectious' => 'Infectious disease', 'LaboratoryScience' => 'Laboratory science',
			'Midwifery' => 'Midwifery', 'Musculoskeletal' => 'Musculoskeletal / orthopedics', 'Neurologic' => 'Neurology', 'Nursing' => 'Nursing',
			'Obstetric' => 'Obstetrics', 'Oncologic' => 'Oncology', 'Ophthalmology' => 'Ophthalmology', 'Optometric' => 'Optometry',
			'Otolaryngologic' => 'ENT / otolaryngology', 'Pathology' => 'Pathology', 'Pediatric' => 'Pediatrics', 'PharmacySpecialty' => 'Pharmacy',
			'Physiotherapy' => 'Physiotherapy', 'PlasticSurgery' => 'Plastic surgery', 'Podiatric' => 'Podiatry', 'PrimaryCare' => 'Primary care / general practice',
			'Psychiatric' => 'Psychiatry', 'PublicHealth' => 'Public health', 'Pulmonary' => 'Pulmonology', 'Radiography' => 'Radiography / imaging',
			'Renal' => 'Nephrology', 'RespiratoryTherapy' => 'Respiratory therapy', 'Rheumatologic' => 'Rheumatology', 'SpeechPathology' => 'Speech pathology',
			'Surgical' => 'Surgery', 'Toxicologic' => 'Toxicology', 'Urologic' => 'Urology',
		);
	}

	/* --------------------------------------------------------------------------------------------
	 * Profile sanitising
	 * ------------------------------------------------------------------------------------------ */

	/** @return array<string,mixed> Every profile key with its empty default. */
	public static function defaults() {
		return array(
			'enabled'               => 0,
			'name'                  => '',
			'type'                  => 'LocalBusiness',
			'alternate_name'        => '',
			'legal_name'            => '',
			'description'           => '',
			'url'                   => '',
			'phone'                 => '',
			'email'                 => '',
			'logo'                  => '',
			'image'                 => '',
			'booking_url'           => '',
			'founding_date'         => '',
			'vat_id'                => '',
			'street'                => '',
			'city'                  => '',
			'region'                => '',
			'postal_code'           => '',
			'country'               => '',
			'hide_address'          => 0,
			'latitude'              => '',
			'longitude'             => '',
			'has_map'               => '',
			'service_area'          => array(),
			'opening_hours'         => array(),
			'special_hours'         => array(),
			'price_range'           => '',
			'payment_accepted'      => '',
			'currencies_accepted'   => '',
			'services'              => array(),
			'expertise'             => array(),
			'amenities'             => array(),
			'languages'             => array(),
			'same_as'               => array(),
			// Food & drink.
			'cuisine'               => array(),
			'menu_url'              => '',
			'accepts_reservations'  => '',
			'drive_through'         => '',
			// Lodging.
			'checkin_time'          => '',
			'checkout_time'         => '',
			'pets_allowed'          => '',
			'number_of_rooms'       => '',
			'star_rating'           => '',
			// Medical.
			'medical_specialty'     => array(),
			'accepting_patients'    => '',
			// Finance.
			'fees_url'              => '',
			// Retail & automotive.
			'brands'                => array(),
			// Venues.
			'max_capacity'          => '',
			'free_entry'            => '',
		);
	}

	/** Merge a stored profile with defaults (older profiles stored service_area as one string). */
	public static function profile( $stored = null ) {
		$stored = null === $stored ? get_option( self::OPTION, array() ) : $stored;
		$stored = is_array( $stored ) ? $stored : array();
		if ( isset( $stored['service_area'] ) && is_string( $stored['service_area'] ) ) {
			$stored['service_area'] = '' !== trim( $stored['service_area'] ) ? array( $stored['service_area'] ) : array(); }
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Sanitize the profile with conservative, field-specific validation. Accepts form strings and
	 * already-stored arrays, so callers can merge partial input (the Setup Wizard) with a stored profile.
	 */
	public static function sanitize_profile( $input ) {
		$input = is_array( $input ) ? $input : array();
		$text  = static function ( $key ) use ( $input ) {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';
		};
		$url   = static function ( $key ) use ( $input ) {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? self::http_url( (string) $input[ $key ] ) : '';
		};
		$tri   = static function ( $key ) use ( $input ) {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
			return in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';
		};
		$type  = $text( 'type' );
		if ( ! array_key_exists( $type, self::types() ) ) {
			$type = 'LocalBusiness'; }
		$specialties = isset( $input['medical_specialty'] ) ? (array) $input['medical_specialty'] : array();
		$specialties = array_values( array_intersect( array_map( 'strval', $specialties ), array_keys( self::medical_specialties() ) ) );
		$rooms       = isset( $input['number_of_rooms'] ) && is_scalar( $input['number_of_rooms'] ) && '' !== trim( (string) $input['number_of_rooms'] ) ? absint( $input['number_of_rooms'] ) : '';
		$capacity    = isset( $input['max_capacity'] ) && is_scalar( $input['max_capacity'] ) && '' !== trim( (string) $input['max_capacity'] ) ? absint( $input['max_capacity'] ) : '';
		$stars       = isset( $input['star_rating'] ) && is_numeric( $input['star_rating'] ) ? (float) $input['star_rating'] : 0;
		return array(
			'enabled'              => ! empty( $input['enabled'] ) ? 1 : 0,
			'name'                 => $text( 'name' ),
			'type'                 => $type,
			'alternate_name'       => $text( 'alternate_name' ),
			'legal_name'           => $text( 'legal_name' ),
			'description'          => isset( $input['description'] ) && is_scalar( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '',
			'url'                  => $url( 'url' ),
			'phone'                => self::sanitize_phone( $text( 'phone' ) ),
			'email'                => isset( $input['email'] ) && is_scalar( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '',
			'logo'                 => $url( 'logo' ),
			'image'                => $url( 'image' ),
			'booking_url'          => $url( 'booking_url' ),
			'founding_date'        => self::sanitize_date( $text( 'founding_date' ) ),
			'vat_id'               => $text( 'vat_id' ),
			'street'               => $text( 'street' ),
			'city'                 => $text( 'city' ),
			'region'               => $text( 'region' ),
			'postal_code'          => $text( 'postal_code' ),
			'country'              => self::sanitize_country( $text( 'country' ) ),
			'hide_address'         => ! empty( $input['hide_address'] ) ? 1 : 0,
			'latitude'             => isset( $input['latitude'] ) && is_scalar( $input['latitude'] ) ? self::coordinate( $input['latitude'], -90, 90 ) : '',
			'longitude'            => isset( $input['longitude'] ) && is_scalar( $input['longitude'] ) ? self::coordinate( $input['longitude'], -180, 180 ) : '',
			'has_map'              => $url( 'has_map' ),
			'service_area'         => self::sanitize_list( isset( $input['service_area'] ) ? $input['service_area'] : '', 30 ),
			'opening_hours'        => isset( $input['opening_hours'] ) ? self::sanitize_hours( $input['opening_hours'] ) : array(),
			'special_hours'        => isset( $input['special_hours'] ) ? self::sanitize_special_hours( $input['special_hours'] ) : array(),
			'price_range'          => function_exists( 'mb_substr' ) ? mb_substr( $text( 'price_range' ), 0, 100 ) : substr( $text( 'price_range' ), 0, 100 ),
			'payment_accepted'     => $text( 'payment_accepted' ),
			'currencies_accepted'  => self::sanitize_currencies( $text( 'currencies_accepted' ) ),
			'services'             => self::sanitize_list( isset( $input['services'] ) ? $input['services'] : '', 50 ),
			'expertise'            => self::sanitize_list( isset( $input['expertise'] ) ? $input['expertise'] : '', 30 ),
			'amenities'            => self::sanitize_list( isset( $input['amenities'] ) ? $input['amenities'] : '', 40 ),
			'languages'            => self::sanitize_list( isset( $input['languages'] ) ? $input['languages'] : '', 20 ),
			'same_as'              => isset( $input['same_as'] ) ? self::sanitize_profiles( $input['same_as'] ) : array(),
			'cuisine'              => self::sanitize_list( isset( $input['cuisine'] ) ? $input['cuisine'] : '', 20 ),
			'menu_url'             => $url( 'menu_url' ),
			'accepts_reservations' => $tri( 'accepts_reservations' ),
			'drive_through'        => $tri( 'drive_through' ),
			'checkin_time'         => self::sanitize_time( $text( 'checkin_time' ) ),
			'checkout_time'        => self::sanitize_time( $text( 'checkout_time' ) ),
			'pets_allowed'         => $tri( 'pets_allowed' ),
			'number_of_rooms'      => $rooms ? $rooms : '',
			'star_rating'          => ( $stars >= 1 && $stars <= 5 ) ? (string) round( $stars, 1 ) : '',
			'medical_specialty'    => $specialties,
			'accepting_patients'   => $tri( 'accepting_patients' ),
			'fees_url'             => $url( 'fees_url' ),
			'brands'               => self::sanitize_list( isset( $input['brands'] ) ? $input['brands'] : '', 30 ),
			'max_capacity'         => $capacity ? $capacity : '',
			'free_entry'           => $tri( 'free_entry' ),
		);
	}

	/** Only absolute http(s) URLs are stored. */
	private static function http_url( $value ) {
		$value = esc_url_raw( trim( (string) $value ) );
		if ( '' === $value ) {
			return ''; }
		$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $value : '';
	}

	/** Keep digits, spaces and the usual phone punctuation; an optional leading +. */
	private static function sanitize_phone( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/[^0-9+()\-.\s]/', '', $value );
		$value = preg_replace( '/(?!^)\+/', '', (string) $value );
		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	/** Accept YYYY, YYYY-MM or YYYY-MM-DD (schema.org Date). */
	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{4})$/', $value ) ) {
			return $value; }
		if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $value ) ) {
			return $value; }
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $value; }
		return '';
	}

	/** HH:MM 24-hour time. */
	private static function sanitize_time( $value ) {
		return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim( (string) $value ) ) ? trim( (string) $value ) : '';
	}

	/** ISO 4217 currency codes, comma separated. */
	private static function sanitize_currencies( $value ) {
		$codes = array();
		foreach ( preg_split( '/[\s,;]+/', strtoupper( (string) $value ) ) as $code ) {
			if ( preg_match( '/^[A-Z]{3}$/', $code ) ) {
				$codes[] = $code; }
		}
		return implode( ', ', array_values( array_unique( $codes ) ) );
	}

	/** Keep country names readable while normalizing ISO-style two-letter codes. */
	private static function sanitize_country( $value ) {
		$value = sanitize_text_field( $value );
		return 2 === strlen( $value ) ? strtoupper( $value ) : $value;
	}

	/** @param mixed $value @return string */
	private static function coordinate( $value, $min, $max ) {
		if ( '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
			return ''; }
		$value = (float) $value;
		return ( $value >= $min && $value <= $max ) ? (string) $value : '';
	}

	/** Normalise a textarea string or stored array to a list of lines. */
	private static function lines( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_filter( $value, 'is_scalar' ) ); }
		if ( ! is_scalar( $value ) ) {
			return array(); }
		return preg_split( '/\r\n|\r|\n/', (string) $value );
	}

	/** One plain-text item per line, de-duplicated and capped. */
	private static function sanitize_list( $value, $max = 30 ) {
		$out = array();
		foreach ( self::lines( $value ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line; }
		}
		return array_slice( $out, 0, $max );
	}

	/** Accept one Schema.org openingHours string per line, e.g. Mo-Fr 09:00-17:00 (24:00 is stored as 23:59). */
	public static function sanitize_hours( $value ) {
		$valid = array();
		$days  = '(?:Mo|Tu|We|Th|Fr|Sa|Su)(?:-(?:Mo|Tu|We|Th|Fr|Sa|Su))?(?:,(?:Mo|Tu|We|Th|Fr|Sa|Su)(?:-(?:Mo|Tu|We|Th|Fr|Sa|Su))?)*';
		foreach ( self::lines( $value ) as $line ) {
			$line = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $line ) ) );
			$line = preg_replace( '/-24:00$/', '-23:59', $line );
			if ( preg_match( '/^' . $days . ' (?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d$/', $line ) ) {
				$valid[] = $line; }
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Holiday / special hours, one per line:
	 *   2026-12-25 closed
	 *   2026-12-24 10:00-14:00
	 *   2026-12-24..2026-12-26 closed
	 */
	public static function sanitize_special_hours( $value ) {
		$valid = array();
		foreach ( self::lines( $value ) as $line ) {
			$parsed = self::parse_special_line( $line );
			if ( $parsed ) {
				$valid[] = $parsed['line']; }
		}
		return array_values( array_unique( $valid ) );
	}

	/** @return array{line:string,from:string,through:string,opens:string,closes:string}|null */
	private static function parse_special_line( $line ) {
		$line = strtolower( trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $line ) ) ) );
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:\s*(?:\.\.|to)\s*(\d{4}-\d{2}-\d{2}))? (closed|(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d)$/', $line, $m ) ) {
			return null; }
		$from    = $m[1];
		$through = '' !== $m[2] ? $m[2] : $m[1];
		foreach ( array( $from, $through ) as $date ) {
			$parts = explode( '-', $date );
			if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
				return null; }
		}
		if ( strcmp( $through, $from ) < 0 ) {
			return null; }
		if ( 'closed' === $m[3] ) {
			$opens  = '00:00';
			$closes = '00:00';
		} else {
			list( $opens, $closes ) = explode( '-', $m[3] );
		}
		return array(
			'line'    => ( $from === $through ? $from : $from . '..' . $through ) . ' ' . $m[3],
			'from'    => $from,
			'through' => $through,
			'opens'   => $opens,
			'closes'  => $closes,
		);
	}

	/** @param mixed $value @return array<int,string> */
	private static function sanitize_profiles( $value ) {
		$out = array();
		foreach ( self::lines( $value ) as $line ) {
			$url = self::http_url( $line );
			if ( $url ) {
				$out[] = $url; }
		}
		return array_values( array_unique( $out ) );
	}

	/* --------------------------------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------------------------------ */

	/** Expand an openingHours line into schema.org OpeningHoursSpecification day URLs + times. */
	private static function expand_hours_line( $line ) {
		$order = array( 'Mo' => 'Monday', 'Tu' => 'Tuesday', 'We' => 'Wednesday', 'Th' => 'Thursday', 'Fr' => 'Friday', 'Sa' => 'Saturday', 'Su' => 'Sunday' );
		$keys  = array_keys( $order );
		list( $day_part, $time_part ) = explode( ' ', $line, 2 );
		list( $opens, $closes )       = explode( '-', $time_part );
		$days = array();
		foreach ( explode( ',', $day_part ) as $chunk ) {
			if ( false !== strpos( $chunk, '-' ) ) {
				list( $a, $b ) = explode( '-', $chunk );
				$i = array_search( $a, $keys, true );
				$j = array_search( $b, $keys, true );
				for ( $k = 0; $k < 7; $k++ ) {
					$days[] = $keys[ ( $i + $k ) % 7 ];
					if ( ( $i + $k ) % 7 === $j ) {
						break; }
				}
			} else {
				$days[] = $chunk;
			}
		}
		$days = array_values( array_unique( $days ) );
		usort(
			$days,
			static function ( $a, $b ) use ( $keys ) {
				return array_search( $a, $keys, true ) - array_search( $b, $keys, true );
			}
		);
		return array(
			'days'   => array_map(
				static function ( $d ) use ( $order ) {
					return 'https://schema.org/' . $order[ $d ];
				},
				$days
			),
			'opens'  => $opens,
			'closes' => $closes,
		);
	}

	/** @return array<int,array<string,mixed>> OpeningHoursSpecification nodes for regular and special hours. */
	public static function opening_hours_specification( $profile ) {
		$out = array();
		foreach ( self::sanitize_hours( isset( $profile['opening_hours'] ) ? $profile['opening_hours'] : array() ) as $line ) {
			$spec  = self::expand_hours_line( $line );
			$out[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $spec['days'],
				'opens'     => $spec['opens'],
				'closes'    => $spec['closes'],
			);
		}
		foreach ( self::lines( isset( $profile['special_hours'] ) ? $profile['special_hours'] : array() ) as $line ) {
			$parsed = self::parse_special_line( $line );
			if ( ! $parsed ) {
				continue; }
			// Google's documented pattern for holiday hours: validFrom/validThrough; 00:00-00:00 means closed all day.
			$out[] = array(
				'@type'        => 'OpeningHoursSpecification',
				'opens'        => $parsed['opens'],
				'closes'       => $parsed['closes'],
				'validFrom'    => $parsed['from'],
				'validThrough' => $parsed['through'],
			);
		}
		return $out;
	}

	/** @return array<int,string> Clean URL list. */
	private static function url_list( $value ) {
		return array_values( array_filter( array_map( array( __CLASS__, 'http_url' ), is_array( $value ) ? $value : array() ) ) );
	}

	/** @return array<int,string> Clean text list. */
	private static function text_list( $value ) {
		return self::sanitize_list( $value, 50 );
	}

	/** One value as-is, several as a list. */
	private static function one_or_many( array $values ) {
		return 1 === count( $values ) ? $values[0] : $values;
	}

	/** Build schema without relying on the admin request; useful for deterministic tests. */
	public static function build_schema( $profile, $site_url = '' ) {
		$profile = is_array( $profile ) ? self::profile( $profile ) : array();
		if ( empty( $profile['enabled'] ) || empty( $profile['name'] ) ) {
			return array(); }
		$types    = self::types();
		$key      = isset( $types[ $profile['type'] ] ) ? $profile['type'] : 'LocalBusiness';
		$site_url = $site_url ? trailingslashit( $site_url ) : trailingslashit( home_url( '/' ) );
		$hide     = ! empty( $profile['hide_address'] );
		$node     = array(
			'@type' => self::schema_type( $key ),
			'@id'   => $site_url . '#local-business',
			'name'  => sanitize_text_field( $profile['name'] ),
			'url'   => ! empty( $profile['url'] ) ? esc_url_raw( $profile['url'] ) : $site_url,
		);
		if ( '' !== self::additional_type( $key ) ) {
			$node['additionalType'] = self::additional_type( $key ); }
		$simple = array(
			'alternate_name'   => 'alternateName',
			'legal_name'       => 'legalName',
			'phone'            => 'telephone',
			'price_range'      => 'priceRange',
			'payment_accepted' => 'paymentAccepted',
			'vat_id'           => 'vatID',
		);
		foreach ( $simple as $from => $to ) {
			if ( ! empty( $profile[ $from ] ) && is_scalar( $profile[ $from ] ) ) {
				$node[ $to ] = sanitize_text_field( (string) $profile[ $from ] ); }
		}
		if ( ! empty( $profile['description'] ) ) {
			$node['description'] = wp_strip_all_tags( (string) $profile['description'] ); }
		if ( ! empty( $profile['email'] ) && is_email( $profile['email'] ) ) {
			$node['email'] = sanitize_email( $profile['email'] ); }
		$currencies = self::sanitize_currencies( (string) $profile['currencies_accepted'] );
		if ( '' !== $currencies ) {
			$node['currenciesAccepted'] = $currencies; }
		$founding = self::sanitize_date( (string) $profile['founding_date'] );
		if ( '' !== $founding ) {
			$node['foundingDate'] = $founding; }
		$logo  = self::http_url( (string) $profile['logo'] );
		$image = self::http_url( (string) $profile['image'] );
		if ( $logo ) {
			$node['logo'] = $logo; }
		$images = array_values( array_unique( array_filter( array( $image, $logo ) ) ) );
		if ( $images ) {
			$node['image'] = self::one_or_many( $images ); }

		// Address. Service-area businesses that do not serve customers at their address hide the street.
		$address = array( '@type' => 'PostalAddress' );
		$parts   = array(
			'street'      => 'streetAddress',
			'city'        => 'addressLocality',
			'region'      => 'addressRegion',
			'postal_code' => 'postalCode',
			'country'     => 'addressCountry',
		);
		foreach ( $parts as $from => $to ) {
			if ( $hide && 'street' === $from ) {
				continue; }
			if ( ! empty( $profile[ $from ] ) ) {
				$address[ $to ] = sanitize_text_field( (string) $profile[ $from ] ); }
		}
		if ( count( $address ) > 1 ) {
			$node['address'] = $address; }
		$lat = $profile['latitude'];
		$lng = $profile['longitude'];
		if ( ! $hide && '' !== (string) $lat && '' !== (string) $lng && is_numeric( $lat ) && is_numeric( $lng ) && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lng >= -180 && (float) $lng <= 180 ) {
			$node['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			);
		}
		$map = self::http_url( (string) $profile['has_map'] );
		if ( $map && ! $hide ) {
			$node['hasMap'] = $map; }
		$area = self::text_list( $profile['service_area'] );
		if ( $area ) {
			$node['areaServed'] = array_map(
				static function ( $name ) {
					return array(
						'@type' => 'Place',
						'name'  => $name,
					);
				},
				$area
			);
		}

		// Hours.
		$hours = self::opening_hours_specification( $profile );
		if ( $hours ) {
			$node['openingHoursSpecification'] = $hours; }

		// Offer: services, expertise, amenities, languages.
		$services = self::text_list( $profile['services'] );
		if ( $services ) {
			$items = array();
			foreach ( $services as $line ) {
				$pieces  = array_map( 'trim', explode( '|', $line, 2 ) );
				$service = array(
					'@type' => 'Service',
					'name'  => $pieces[0],
				);
				if ( ! empty( $pieces[1] ) ) {
					$service['description'] = $pieces[1]; }
				if ( '' !== $pieces[0] ) {
					$items[] = array(
						'@type'       => 'Offer',
						'itemOffered' => $service,
					);
				}
			}
			if ( $items ) {
				$node['hasOfferCatalog'] = array(
					'@type'           => 'OfferCatalog',
					'name'            => sanitize_text_field( $profile['name'] ),
					'itemListElement' => $items,
				);
			}
		}
		$expertise = self::text_list( $profile['expertise'] );
		if ( $expertise ) {
			$node['knowsAbout'] = $expertise; }
		$amenities = self::text_list( $profile['amenities'] );
		if ( $amenities ) {
			$node['amenityFeature'] = array_map(
				static function ( $name ) {
					return array(
						'@type' => 'LocationFeatureSpecification',
						'name'  => $name,
						'value' => true,
					);
				},
				$amenities
			);
		}
		$languages = self::text_list( $profile['languages'] );
		if ( $languages ) {
			// availableLanguage is defined for LodgingBusiness; every Organization can use knowsLanguage.
			$node[ self::in_family( $key, 'lodging' ) ? 'availableLanguage' : 'knowsLanguage' ] = self::one_or_many( $languages ); }
		$booking = self::http_url( (string) $profile['booking_url'] );
		if ( $booking ) {
			$node['potentialAction'] = array(
				'@type'  => 'ReserveAction',
				'target' => array(
					'@type'          => 'EntryPoint',
					'urlTemplate'    => $booking,
					'actionPlatform' => array( 'https://schema.org/DesktopWebPlatform', 'https://schema.org/MobileWebPlatform' ),
				),
			);
		}

		// Type-specific properties, emitted only for the families schema.org defines them on.
		if ( self::in_family( $key, 'food' ) ) {
			$cuisine = self::text_list( $profile['cuisine'] );
			if ( $cuisine ) {
				$node['servesCuisine'] = self::one_or_many( $cuisine ); }
			$menu = self::http_url( (string) $profile['menu_url'] );
			if ( $menu ) {
				$node['hasMenu'] = $menu; }
			if ( 'yes' === $profile['accepts_reservations'] ) {
				$node['acceptsReservations'] = $booking ? $booking : true;
			} elseif ( 'no' === $profile['accepts_reservations'] ) {
				$node['acceptsReservations'] = false;
			}
		}
		if ( ( self::in_family( $key, 'food' ) || self::in_family( $key, 'financial' ) || self::in_family( $key, 'medical' ) || self::in_family( $key, 'automotive' ) ) && '' !== $profile['drive_through'] ) {
			$node['hasDriveThroughService'] = 'yes' === $profile['drive_through']; }
		if ( self::in_family( $key, 'lodging' ) ) {
			if ( '' !== $profile['checkin_time'] && self::sanitize_time( $profile['checkin_time'] ) ) {
				$node['checkinTime'] = $profile['checkin_time'] . ':00'; }
			if ( '' !== $profile['checkout_time'] && self::sanitize_time( $profile['checkout_time'] ) ) {
				$node['checkoutTime'] = $profile['checkout_time'] . ':00'; }
			if ( '' !== $profile['pets_allowed'] ) {
				$node['petsAllowed'] = 'yes' === $profile['pets_allowed']; }
			if ( '' !== (string) $profile['number_of_rooms'] && absint( $profile['number_of_rooms'] ) > 0 ) {
				$node['numberOfRooms'] = absint( $profile['number_of_rooms'] ); }
			if ( '' !== (string) $profile['star_rating'] && is_numeric( $profile['star_rating'] ) ) {
				$node['starRating'] = array(
					'@type'       => 'Rating',
					'ratingValue' => (float) $profile['star_rating'],
					'bestRating'  => 5,
				);
			}
		}
		if ( self::in_family( $key, 'medical_org' ) ) {
			$specialties = array_values( array_intersect( (array) $profile['medical_specialty'], array_keys( self::medical_specialties() ) ) );
			if ( $specialties ) {
				$node['medicalSpecialty'] = self::one_or_many(
					array_map(
						static function ( $s ) {
							return 'https://schema.org/' . $s;
						},
						$specialties
					)
				);
			}
			if ( '' !== $profile['accepting_patients'] ) {
				$node['isAcceptingNewPatients'] = 'yes' === $profile['accepting_patients']; }
		}
		if ( self::in_family( $key, 'financial' ) ) {
			$fees = self::http_url( (string) $profile['fees_url'] );
			if ( $fees ) {
				$node['feesAndCommissionsSpecification'] = $fees; }
		}
		if ( self::in_family( $key, 'retail' ) || self::in_family( $key, 'automotive' ) ) {
			$brands = self::text_list( $profile['brands'] );
			if ( $brands ) {
				$node['brand'] = array_map(
					static function ( $name ) {
						return array(
							'@type' => 'Brand',
							'name'  => $name,
						);
					},
					$brands
				);
			}
		}
		if ( self::in_family( $key, 'venue' ) ) {
			if ( '' !== (string) $profile['max_capacity'] && absint( $profile['max_capacity'] ) > 0 ) {
				$node['maximumAttendeeCapacity'] = absint( $profile['max_capacity'] ); }
			if ( '' !== $profile['free_entry'] ) {
				$node['isAccessibleForFree'] = 'yes' === $profile['free_entry']; }
		}

		$same_as = self::url_list( $profile['same_as'] );
		if ( $same_as ) {
			$node['sameAs'] = $same_as; }
		return $node;
	}

	/** Add the configured local entity only when explicitly enabled and named. */
	public function append_schema( $graph, $graph_output = null ) {
		$node = self::connected_node();
		if ( $node ) {
			$graph[] = $node; }
		return $graph;
	}

	/** The LocalBusiness node exactly as it is added to the site graph. */
	public static function connected_node( $profile = null ) {
		$profile = self::profile( $profile );
		$base    = trailingslashit( \PublishRankSEO\Core\PublicationProfile::primary_url() );
		$node    = self::build_schema( $profile, $base );
		if ( $node && ! \PublishRankSEO\Core\PublicationProfile::identity_is_person() ) {
			// parentOrganization supersedes the deprecated branchOf property.
			$node['parentOrganization'] = array( '@id' => $base . '#organization' );
		}
		return $node;
	}

	/**
	 * Google-guideline checks for the stored profile.
	 *
	 * @return array<int,array{level:string,text:string}> level: ok|warn|error|info.
	 */
	public static function health( $profile ) {
		$p      = self::profile( $profile );
		$key    = $p['type'];
		$checks = array();
		$add    = static function ( $ok, $good, $bad, $level = 'warn' ) use ( &$checks ) {
			$checks[] = array(
				'level' => $ok ? 'ok' : $level,
				'text'  => $ok ? $good : $bad,
			);
		};
		if ( empty( $p['enabled'] ) ) {
			$checks[] = array(
				'level' => 'info',
				'text'  => __( 'LocalBusiness output is switched off. Nothing is added to your pages.', 'publishrank' ),
			);
		}
		$add( '' !== $p['name'], __( 'Business name is set.', 'publishrank' ), __( 'Add the business name — schema is not output without it.', 'publishrank' ), 'error' );
		$has_address = '' !== $p['city'] && '' !== $p['country'] && ( ! empty( $p['hide_address'] ) || '' !== $p['street'] );
		$add( $has_address, __( 'Address is complete (Google requires it).', 'publishrank' ), __( 'Complete the address: street, city and country are required by Google.', 'publishrank' ), 'error' );
		$add( '' === $p['country'] || 2 === strlen( $p['country'] ), __( 'Country uses a two-letter ISO code.', 'publishrank' ), __( 'Use a two-letter country code such as PK, US or GB.', 'publishrank' ) );
		$add( '' !== $p['phone'], __( 'Public telephone is set.', 'publishrank' ), __( 'Add a public phone number.', 'publishrank' ) );
		if ( '' !== $p['phone'] ) {
			$add( 0 === strpos( $p['phone'], '+' ), __( 'Phone includes the country code.', 'publishrank' ), __( 'Start the phone with + and the country code, e.g. +92 21 1234567.', 'publishrank' ) ); }
		if ( empty( $p['hide_address'] ) ) {
			$precise = '' !== (string) $p['latitude'] && '' !== (string) $p['longitude'] && strlen( (string) substr( strrchr( (string) $p['latitude'], '.' ), 1 ) ) >= 5 && strlen( (string) substr( strrchr( (string) $p['longitude'], '.' ), 1 ) ) >= 5;
			$add( $precise, __( 'Coordinates have at least 5 decimal places.', 'publishrank' ), __( 'Add latitude and longitude with at least 5 decimal places.', 'publishrank' ) );
		}
		$add( ! empty( $p['opening_hours'] ), __( 'Opening hours are set.', 'publishrank' ), __( 'Add your opening hours.', 'publishrank' ) );
		$add( '' !== $p['logo'] || '' !== $p['image'], __( 'A logo or photo is set.', 'publishrank' ), __( 'Add a logo or storefront photo URL.', 'publishrank' ) );
		$add( ! empty( $p['same_as'] ), __( 'Official profiles are linked.', 'publishrank' ), __( 'Link your Google Business Profile and social pages.', 'publishrank' ) );
		if ( self::in_family( $key, 'food' ) ) {
			$add( ! empty( $p['cuisine'] ), __( 'Cuisine is set.', 'publishrank' ), __( 'Add the cuisine you serve (Google recommends it for restaurants).', 'publishrank' ) );
			$add( '' !== $p['menu_url'], __( 'Menu link is set.', 'publishrank' ), __( 'Add a link to your menu.', 'publishrank' ) );
			$add( '' !== $p['price_range'], __( 'Price range is set.', 'publishrank' ), __( 'Add a price range such as $$ or PKR 1,000–2,500.', 'publishrank' ) );
		}
		if ( self::in_family( $key, 'lodging' ) ) {
			$add( '' !== $p['checkin_time'] && '' !== $p['checkout_time'], __( 'Check-in and check-out times are set.', 'publishrank' ), __( 'Add check-in and check-out times.', 'publishrank' ) );
			$add( ! empty( $p['amenities'] ), __( 'Amenities are listed.', 'publishrank' ), __( 'List amenities such as Free Wi-Fi, Parking or Pool.', 'publishrank' ) );
		}
		if ( self::in_family( $key, 'medical_org' ) ) {
			$add( ! empty( $p['medical_specialty'] ), __( 'Medical specialty is set.', 'publishrank' ), __( 'Choose at least one medical specialty.', 'publishrank' ) ); }
		if ( self::in_family( $key, 'home' ) ) {
			$add( ! empty( $p['service_area'] ), __( 'Service area is set.', 'publishrank' ), __( 'Add the areas you serve — key for service businesses.', 'publishrank' ) ); }
		if ( self::in_family( $key, 'retail' ) || self::in_family( $key, 'automotive' ) ) {
			$add( '' !== $p['payment_accepted'], __( 'Payment methods are set.', 'publishrank' ), __( 'Add accepted payment methods.', 'publishrank' ) ); }
		if ( ! self::in_family( $key, 'food' ) && ! self::in_family( $key, 'lodging' ) ) {
			$add( ! empty( $p['services'] ), __( 'Services are listed.', 'publishrank' ), __( 'List the main services or products you offer.', 'publishrank' ) ); }
		$checks[] = array(
			'level' => 'info',
			'text'  => __( 'Ratings and reviews are intentionally not added: Google ignores self-published reviews for a business about itself.', 'publishrank' ),
		);
		return $checks;
	}

	/* --------------------------------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------------------------------ */

	public function menu() {
		add_submenu_page( \PublishRankSEO\Core\Branding::MENU_SLUG, __( 'Local SEO', 'publishrank' ), __( 'Local SEO', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'page' ) );
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page ) {
			return; }
		$ver = static function ( $rel ) {
			$file = PUBLISHRANK_SEO_PATH . 'assets/' . $rel;
			return \PublishRankSEO\Core\Branding::version() . '.' . ( file_exists( $file ) ? (string) filemtime( $file ) : '0' );
		};
		wp_enqueue_style( 'publishrank-local-seo', \PublishRankSEO\Core\Branding::asset_url( 'css/local-seo.css' ), array(), $ver( 'css/local-seo.css' ) );
		wp_enqueue_script( 'publishrank-local-seo', \PublishRankSEO\Core\Branding::asset_url( 'js/local-seo.js' ), array(), $ver( 'js/local-seo.js' ), true );
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Local SEO.', 'publishrank' ) ); }
		check_admin_referer( 'publishrank_local_seo_save' );
		$input   = isset( $_POST['local_seo'] ) && is_array( $_POST['local_seo'] ) ? map_deep( wp_unslash( $_POST['local_seo'] ), 'sanitize_textarea_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$profile = self::sanitize_profile( $input );
		update_option( self::OPTION, $profile, false );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Join a stored list for a textarea. */
	private static function join( $value ) {
		return is_array( $value ) ? implode( "\n", $value ) : (string) $value;
	}

	/** Open a section card. */
	private function card_open( $id, $icon, $title, $lead, $families = '' ) {
		echo '<section class="pr-local-card" id="pr-local-' . esc_attr( $id ) . '"' . ( '' !== $families ? ' data-pr-local-families="' . esc_attr( $families ) . '"' : '' ) . '>';
		echo '<header class="pr-local-card__head"><span class="pr-local-card__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $icon ) . '"></i></span><div><h2 class="pr-local-card__title">' . esc_html( $title ) . '</h2><p class="pr-local-card__lead">' . esc_html( $lead ) . '</p></div></header>';
		echo '<div class="pr-local-grid">';
	}

	private function card_close() {
		echo '</div></section>';
	}

	/**
	 * One field.
	 *
	 * @param string $key   Profile key.
	 * @param string $label Label.
	 * @param mixed  $value Current value.
	 * @param array  $o     kind (text|url|email|number|time|textarea|tri), help, placeholder, wide, required, families, step, min, max.
	 */
	private function field( $key, $label, $value, array $o = array() ) {
		$o    = array_merge(
			array(
				'kind'        => 'text',
				'help'        => '',
				'placeholder' => '',
				'wide'        => false,
				'required'    => false,
				'families'    => '',
				'step'        => '',
				'min'         => '',
				'max'         => '',
				'rows'        => 3,
				'tri'         => array(),
			),
			$o
		);
		$id   = 'pr_local_' . $key;
		$name = 'local_seo[' . $key . ']';
		$help = '' !== $o['help'] ? ' aria-describedby="' . esc_attr( $id . '_help' ) . '"' : '';
		$ph   = '' !== $o['placeholder'] ? ' placeholder="' . esc_attr( $o['placeholder'] ) . '"' : '';
		echo '<div class="pr-local-field' . ( $o['wide'] ? ' pr-local-field--wide' : '' ) . '"' . ( '' !== $o['families'] ? ' data-pr-local-families="' . esc_attr( $o['families'] ) . '"' : '' ) . '>';
		echo '<label class="pr-local-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $o['required'] ? ' <span class="pr-local-req" aria-hidden="true">*</span>' : '' ) . '</label>';
		if ( 'textarea' === $o['kind'] ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . (int) $o['rows'] . '"' . $help . $ph . '>' . esc_textarea( self::join( $value ) ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
		} elseif ( 'tri' === $o['kind'] ) {
			$labels = $o['tri'] ? $o['tri'] : array( __( 'Not specified', 'publishrank' ), __( 'Yes', 'publishrank' ), __( 'No', 'publishrank' ) );
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( array( '', 'yes', 'no' ) as $i => $v ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( (string) $value, $v, false ) . '>' . esc_html( $labels[ $i ] ) . '</option>'; }
			echo '</select>';
		} else {
			$attrs = '';
			foreach ( array( 'step', 'min', 'max' ) as $a ) {
				if ( '' !== (string) $o[ $a ] ) {
					$attrs .= ' ' . $a . '="' . esc_attr( (string) $o[ $a ] ) . '"'; }
			}
			echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $o['kind'] ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . $attrs . $help . $ph . ( $o['required'] ? ' required' : '' ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( '' !== $o['help'] ) {
			echo '<small class="pr-local-help" id="' . esc_attr( $id . '_help' ) . '">' . esc_html( $o['help'] ) . '</small>'; }
		echo '</div>';
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Local SEO.', 'publishrank' ) ); }
		$p        = self::profile();
		$type     = $p['type'];
		$families = implode( ' ', self::families_of( $type ) );
		$checks   = self::health( $p );
		$scored   = array_filter(
			$checks,
			static function ( $c ) {
				return 'info' !== $c['level'];
			}
		);
		$passed   = count(
			array_filter(
				$scored,
				static function ( $c ) {
					return 'ok' === $c['level'];
				}
			)
		);
		$total    = max( 1, count( $scored ) );
		$score    = (int) round( 100 * $passed / $total );
		$preview  = self::connected_node( array_merge( $p, array( 'enabled' => 1 ) ) );
		$home     = home_url( '/' );

		echo '<div class="wrap pr-local" data-pr-local data-families="' . esc_attr( $families ) . '">';
		\PublishRankSEO\Admin\AdminAssets::header( __( 'Local SEO', 'publishrank' ), __( 'Describe your real-world business once. PublishRank turns it into connected, Google-ready LocalBusiness structured data.', 'publishrank' ) );
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display parameter.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Local SEO settings saved.', 'publishrank' ) . '</p></div>'; }

		echo '<form class="pr-local__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_local_seo_save" />';
		wp_nonce_field( 'publishrank_local_seo_save' );

		// Status bar with the main switch.
		echo '<div class="pr-local-status' . ( ! empty( $p['enabled'] ) ? ' is-on' : '' ) . '">';
		echo '<div class="pr-local-status__text"><strong>' . esc_html__( 'LocalBusiness structured data', 'publishrank' ) . '</strong><span data-pr-local-state data-on="' . esc_attr__( 'On — added to every public page.', 'publishrank' ) . '" data-off="' . esc_attr__( 'Off — switch on once your business details are accurate.', 'publishrank' ) . '">' . ( ! empty( $p['enabled'] ) ? esc_html__( 'On — added to every public page.', 'publishrank' ) : esc_html__( 'Off — switch on once your business details are accurate.', 'publishrank' ) ) . '</span></div>';
		echo '<input type="hidden" name="local_seo[enabled]" value="0" />';
		echo '<label class="pr-switch" for="pr_local_enabled"><span class="screen-reader-text">' . esc_html__( 'Enable LocalBusiness schema', 'publishrank' ) . '</span><input id="pr_local_enabled" type="checkbox" name="local_seo[enabled]" value="1"' . checked( ! empty( $p['enabled'] ), true, false ) . ' data-pr-local-toggle /><span class="pr-switch__track"><span class="pr-switch__thumb"></span></span></label>';
		echo '</div>';

		echo '<nav class="pr-local-jump" aria-label="' . esc_attr__( 'Local SEO sections', 'publishrank' ) . '">';
		$jumps = array(
			'business' => array( 'fa-store', __( 'Business', 'publishrank' ) ),
			'location' => array( 'fa-location-dot', __( 'Location', 'publishrank' ) ),
			'hours'    => array( 'fa-clock', __( 'Hours', 'publishrank' ) ),
			'offer'    => array( 'fa-tags', __( 'Services', 'publishrank' ) ),
			'details'  => array( 'fa-sliders', __( 'Type details', 'publishrank' ) ),
			'profiles' => array( 'fa-share-nodes', __( 'Profiles', 'publishrank' ) ),
		);
		foreach ( $jumps as $id => $j ) {
			echo '<a href="#pr-local-' . esc_attr( $id ) . '"><i class="fa-solid ' . esc_attr( $j[0] ) . '" aria-hidden="true"></i>' . esc_html( $j[1] ) . '</a>'; }
		echo '</nav>';

		echo '<div class="pr-local__layout"><div class="pr-local__main">';

		// Business.
		$this->card_open( 'business', 'fa-store', __( 'Business', 'publishrank' ), __( 'Who you are, exactly as customers see you on your sign and Google Business Profile.', 'publishrank' ) );
		$this->field( 'name', __( 'Business / location name', 'publishrank' ), $p['name'], array( 'required' => true, 'help' => __( 'Use the real-world name shown to customers.', 'publishrank' ) ) );
		echo '<div class="pr-local-field"><label class="pr-local-label" for="pr_local_type">' . esc_html__( 'Business type', 'publishrank' ) . '</label><select id="pr_local_type" name="local_seo[type]" data-pr-local-type aria-describedby="pr_local_type_help">';
		echo self::type_options_html( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper.
		echo '</select><small class="pr-local-help" id="pr_local_type_help">' . esc_html__( 'Pick the most specific type. Extra fields for your type appear under Type details.', 'publishrank' ) . '</small></div>';
		$this->field( 'alternate_name', __( 'Also known as', 'publishrank' ), $p['alternate_name'], array( 'help' => __( 'Optional short or local name.', 'publishrank' ) ) );
		$this->field( 'legal_name', __( 'Registered legal name', 'publishrank' ), $p['legal_name'], array( 'help' => __( 'Optional, e.g. Acme Traders (Pvt) Ltd.', 'publishrank' ) ) );
		$this->field( 'description', __( 'Description', 'publishrank' ), $p['description'], array( 'kind' => 'textarea', 'wide' => true, 'help' => __( 'One or two plain sentences about what you do and where.', 'publishrank' ) ) );
		$this->field( 'url', __( 'Business website / location page', 'publishrank' ), $p['url'], array( 'kind' => 'url', 'placeholder' => $home ) );
		$this->field( 'phone', __( 'Public telephone', 'publishrank' ), $p['phone'], array( 'kind' => 'tel', 'placeholder' => '+92 21 1234567', 'help' => __( 'Include the country code.', 'publishrank' ) ) );
		$this->field( 'email', __( 'Public email', 'publishrank' ), $p['email'], array( 'kind' => 'email' ) );
		$this->field( 'booking_url', __( 'Booking / appointment link', 'publishrank' ), $p['booking_url'], array( 'kind' => 'url', 'help' => __( 'Where customers book a table, room or appointment.', 'publishrank' ) ) );
		$this->field( 'logo', __( 'Logo URL', 'publishrank' ), $p['logo'], array( 'kind' => 'url' ) );
		$this->field( 'image', __( 'Storefront / interior photo URL', 'publishrank' ), $p['image'], array( 'kind' => 'url', 'help' => __( 'A real photo of the place. Falls back to the logo.', 'publishrank' ) ) );
		$this->field( 'founding_date', __( 'Founded', 'publishrank' ), $p['founding_date'], array( 'placeholder' => '2015 or 2015-04-01' ) );
		$this->field( 'vat_id', __( 'Tax / VAT number', 'publishrank' ), $p['vat_id'], array( 'help' => __( 'Optional, e.g. NTN or VAT ID.', 'publishrank' ) ) );
		$this->card_close();

		// Location.
		$this->card_open( 'location', 'fa-location-dot', __( 'Location', 'publishrank' ), __( 'Google requires a full address. Service-area businesses can hide the street.', 'publishrank' ) );
		$this->field( 'street', __( 'Street address', 'publishrank' ), $p['street'], array( 'wide' => true ) );
		$this->field( 'city', __( 'City / locality', 'publishrank' ), $p['city'] );
		$this->field( 'region', __( 'State / province', 'publishrank' ), $p['region'] );
		$this->field( 'postal_code', __( 'Postal code', 'publishrank' ), $p['postal_code'] );
		$this->field( 'country', __( 'Country code', 'publishrank' ), $p['country'], array( 'placeholder' => 'PK', 'help' => __( 'Two-letter ISO code, e.g. PK, US, GB.', 'publishrank' ) ) );
		echo '<div class="pr-local-field pr-local-field--wide"><label class="pr-local-check"><input type="hidden" name="local_seo[hide_address]" value="0" /><input type="checkbox" name="local_seo[hide_address]" value="1"' . checked( ! empty( $p['hide_address'] ), true, false ) . ' /><span><b>' . esc_html__( 'I serve customers at their location (hide street, map and coordinates)', 'publishrank' ) . '</b><small>' . esc_html__( 'For plumbers, electricians, movers and other service-area businesses without a public storefront.', 'publishrank' ) . '</small></span></label></div>';
		$this->field( 'latitude', __( 'Latitude', 'publishrank' ), $p['latitude'], array( 'kind' => 'number', 'step' => 'any', 'min' => '-90', 'max' => '90', 'placeholder' => '24.86073' ) );
		$this->field( 'longitude', __( 'Longitude', 'publishrank' ), $p['longitude'], array( 'kind' => 'number', 'step' => 'any', 'min' => '-180', 'max' => '180', 'placeholder' => '67.00113' ) );
		$this->field( 'has_map', __( 'Google Maps link', 'publishrank' ), $p['has_map'], array( 'kind' => 'url', 'wide' => true ) );
		$this->field( 'service_area', __( 'Areas served', 'publishrank' ), $p['service_area'], array( 'kind' => 'textarea', 'wide' => true, 'placeholder' => "Karachi\nHyderabad", 'help' => __( 'One city, district or region per line.', 'publishrank' ) ) );
		$this->card_close();

		// Hours.
		$this->card_open( 'hours', 'fa-clock', __( 'Opening hours', 'publishrank' ), __( 'Published as OpeningHoursSpecification, the format Google reads for local results.', 'publishrank' ) );
		$this->field( 'opening_hours', __( 'Regular hours', 'publishrank' ), $p['opening_hours'], array( 'kind' => 'textarea', 'rows' => 4, 'placeholder' => "Mo-Fr 09:00-17:00\nSa 10:00-14:00", 'help' => __( 'One line per schedule. Days: Mo Tu We Th Fr Sa Su. Open 24 hours: Mo-Su 00:00-23:59. Lines that do not match are dropped.', 'publishrank' ) ) );
		$this->field( 'special_hours', __( 'Holiday & special hours', 'publishrank' ), $p['special_hours'], array( 'kind' => 'textarea', 'rows' => 4, 'placeholder' => "2026-12-25 closed\n2026-12-31 10:00-14:00\n2027-04-01..2027-04-03 closed", 'help' => __( 'Date (or date..date) followed by closed or HH:MM-HH:MM.', 'publishrank' ) ) );
		echo '<div class="pr-local-field pr-local-field--wide"><div class="pr-local-presets" role="group" aria-label="' . esc_attr__( 'Hour presets', 'publishrank' ) . '">';
		foreach ( array(
			"Mo-Fr 09:00-17:00"                   => __( 'Office hours', 'publishrank' ),
			"Mo-Sa 10:00-22:00"                   => __( 'Retail Mon–Sat', 'publishrank' ),
			"Mo-Th 12:00-23:00\nFr-Su 12:00-23:59" => __( 'Restaurant', 'publishrank' ),
			'Mo-Su 00:00-23:59'                   => __( 'Open 24/7', 'publishrank' ),
		) as $preset => $label ) {
			echo '<button type="button" class="pr-local-chip" data-pr-local-preset="' . esc_attr( $preset ) . '">' . esc_html( $label ) . '</button>'; }
		echo '</div></div>';
		$this->card_close();

		// Offer.
		$this->card_open( 'offer', 'fa-tags', __( 'Services, payments & amenities', 'publishrank' ), __( 'What you offer and how customers pay — shared by every business type.', 'publishrank' ) );
		$this->field( 'services', __( 'Services or products offered', 'publishrank' ), $p['services'], array( 'kind' => 'textarea', 'wide' => true, 'rows' => 4, 'placeholder' => "Teeth whitening | In-chair whitening in one visit\nRoot canal treatment", 'help' => __( 'One per line. Optionally add | and a short description.', 'publishrank' ) ) );
		$this->field( 'expertise', __( 'Specialties / areas of expertise', 'publishrank' ), $p['expertise'], array( 'kind' => 'textarea', 'rows' => 3, 'placeholder' => "Family law\nProperty disputes" ) );
		$this->field( 'amenities', __( 'Amenities & accessibility', 'publishrank' ), $p['amenities'], array( 'kind' => 'textarea', 'rows' => 3, 'placeholder' => "Free Wi-Fi\nWheelchair accessible entrance\nParking" ) );
		$this->field( 'price_range', __( 'Price range', 'publishrank' ), $p['price_range'], array( 'placeholder' => '$$', 'help' => __( 'e.g. $$ or PKR 1,000–2,500 (max 100 characters).', 'publishrank' ) ) );
		$this->field( 'payment_accepted', __( 'Payment methods', 'publishrank' ), $p['payment_accepted'], array( 'placeholder' => 'Cash, Credit Card, JazzCash' ) );
		$this->field( 'currencies_accepted', __( 'Currencies', 'publishrank' ), $p['currencies_accepted'], array( 'placeholder' => 'PKR, USD', 'help' => __( 'Three-letter ISO 4217 codes.', 'publishrank' ) ) );
		$this->field( 'languages', __( 'Languages spoken', 'publishrank' ), $p['languages'], array( 'kind' => 'textarea', 'rows' => 2, 'placeholder' => "English\nUrdu" ) );
		$this->card_close();

		// Type details.
		$this->card_open( 'details', 'fa-sliders', __( 'Type details', 'publishrank' ), __( 'Extra fields that only apply to your business type. They change when you pick another type.', 'publishrank' ) );
		echo '<p class="pr-local-empty pr-local-field--wide" data-pr-local-empty>' . esc_html__( 'No extra fields for this type — the sections above cover everything schema.org defines for it.', 'publishrank' ) . '</p>';
		// Food.
		$this->field( 'cuisine', __( 'Cuisine served', 'publishrank' ), $p['cuisine'], array( 'kind' => 'textarea', 'rows' => 2, 'families' => 'food', 'placeholder' => "Pakistani\nBBQ" ) );
		$this->field( 'menu_url', __( 'Menu link', 'publishrank' ), $p['menu_url'], array( 'kind' => 'url', 'families' => 'food' ) );
		$this->field( 'accepts_reservations', __( 'Takes reservations', 'publishrank' ), $p['accepts_reservations'], array( 'kind' => 'tri', 'families' => 'food', 'help' => __( 'Uses the booking link when one is set.', 'publishrank' ) ) );
		$this->field( 'drive_through', __( 'Drive-through', 'publishrank' ), $p['drive_through'], array( 'kind' => 'tri', 'families' => 'food financial medical automotive' ) );
		// Lodging.
		$this->field( 'checkin_time', __( 'Check-in from', 'publishrank' ), $p['checkin_time'], array( 'kind' => 'time', 'families' => 'lodging' ) );
		$this->field( 'checkout_time', __( 'Check-out by', 'publishrank' ), $p['checkout_time'], array( 'kind' => 'time', 'families' => 'lodging' ) );
		$this->field( 'number_of_rooms', __( 'Number of rooms', 'publishrank' ), $p['number_of_rooms'], array( 'kind' => 'number', 'min' => '1', 'step' => '1', 'families' => 'lodging' ) );
		$this->field( 'star_rating', __( 'Official star rating', 'publishrank' ), $p['star_rating'], array( 'kind' => 'number', 'min' => '1', 'max' => '5', 'step' => '0.5', 'families' => 'lodging', 'help' => __( 'Only an official classification — not guest reviews.', 'publishrank' ) ) );
		$this->field( 'pets_allowed', __( 'Pets allowed', 'publishrank' ), $p['pets_allowed'], array( 'kind' => 'tri', 'families' => 'lodging' ) );
		// Medical.
		echo '<div class="pr-local-field pr-local-field--wide" data-pr-local-families="medical_org"><span class="pr-local-label" id="pr_local_spec_label">' . esc_html__( 'Medical specialties', 'publishrank' ) . '</span><div class="pr-local-pills" role="group" aria-labelledby="pr_local_spec_label">';
		foreach ( self::medical_specialties() as $value => $label ) {
			echo '<label class="pr-local-pill"><input type="checkbox" name="local_seo[medical_specialty][]" value="' . esc_attr( $value ) . '"' . checked( in_array( $value, (array) $p['medical_specialty'], true ), true, false ) . ' /><span>' . esc_html( $label ) . '</span></label>'; }
		echo '</div></div>';
		$this->field( 'accepting_patients', __( 'Accepting new patients', 'publishrank' ), $p['accepting_patients'], array( 'kind' => 'tri', 'families' => 'medical_org' ) );
		// Finance.
		$this->field( 'fees_url', __( 'Fees & charges page', 'publishrank' ), $p['fees_url'], array( 'kind' => 'url', 'families' => 'financial' ) );
		// Retail & automotive.
		$this->field( 'brands', __( 'Brands sold or serviced', 'publishrank' ), $p['brands'], array( 'kind' => 'textarea', 'rows' => 3, 'families' => 'retail automotive', 'placeholder' => "Toyota\nHonda" ) );
		// Venues.
		$this->field( 'max_capacity', __( 'Maximum capacity', 'publishrank' ), $p['max_capacity'], array( 'kind' => 'number', 'min' => '1', 'step' => '1', 'families' => 'venue' ) );
		$this->field( 'free_entry', __( 'Free entry', 'publishrank' ), $p['free_entry'], array( 'kind' => 'tri', 'families' => 'venue' ) );
		$this->card_close();

		// Profiles.
		$this->card_open( 'profiles', 'fa-share-nodes', __( 'Official profiles', 'publishrank' ), __( 'Links that prove this is the same business everywhere (sameAs).', 'publishrank' ) );
		$this->field( 'same_as', __( 'Profile URLs', 'publishrank' ), $p['same_as'], array( 'kind' => 'textarea', 'wide' => true, 'rows' => 4, 'placeholder' => "https://maps.google.com/?cid=...\nhttps://www.facebook.com/yourbusiness", 'help' => __( 'One full http(s) URL per line: Google Business Profile, Facebook, Instagram, Yelp, TripAdvisor …', 'publishrank' ) ) );
		$this->card_close();

		echo '<div class="pr-local-savebar"><p>' . esc_html__( 'Use accurate, public details only. Structured data helps search engines understand you but does not guarantee rich results or rankings.', 'publishrank' ) . '</p><button type="submit" class="button button-primary button-hero">' . esc_html__( 'Save Local SEO profile', 'publishrank' ) . '</button></div>';
		echo '</div>';

		// Sidebar.
		echo '<aside class="pr-local__side">';
		echo '<section class="pr-local-card pr-local-health"><header class="pr-local-health__head"><span class="pr-local-ring" style="--pr-local-score:' . (int) $score . '" aria-hidden="true"><b>' . (int) $score . '</b></span><div><h2 class="pr-local-card__title">' . esc_html__( 'Schema health', 'publishrank' ) . '</h2>';
		/* translators: 1: passed checks, 2: total checks. */
		echo '<p class="pr-local-card__lead">' . esc_html( sprintf( __( '%1$d of %2$d checks pass for the saved profile.', 'publishrank' ), $passed, $total ) ) . '</p></div></header><ul class="pr-local-checks">';
		foreach ( $checks as $c ) {
			$icon = array(
				'ok'    => 'fa-circle-check',
				'warn'  => 'fa-circle-exclamation',
				'error' => 'fa-circle-xmark',
				'info'  => 'fa-circle-info',
			);
			echo '<li class="is-' . esc_attr( $c['level'] ) . '"><i class="fa-solid ' . esc_attr( $icon[ $c['level'] ] ) . '" aria-hidden="true"></i><span>' . esc_html( $c['text'] ) . '</span></li>'; }
		echo '</ul></section>';

		echo '<section class="pr-local-card pr-local-preview"><div class="pr-local-preview__bar"><h2 class="pr-local-card__title">' . esc_html__( 'JSON-LD preview', 'publishrank' ) . '</h2><button type="button" class="button button-small" data-pr-local-copy>' . esc_html__( 'Copy', 'publishrank' ) . '</button></div>';
		if ( $preview ) {
			echo '<pre class="pr-local-pre" data-pr-local-json>' . esc_html( (string) wp_json_encode( $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		} else {
			echo '<p class="pr-local-empty">' . esc_html__( 'Add a business name and save to see the structured data.', 'publishrank' ) . '</p>';
		}
		echo '<p class="pr-local-help">' . esc_html__( 'Preview of the saved profile. Save after editing to refresh it.', 'publishrank' ) . '</p>';
		echo '<div class="pr-local-tests"><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( $home ) ) . '"><i class="fa-brands fa-google" aria-hidden="true"></i> ' . esc_html__( 'Rich Results Test', 'publishrank' ) . '</a><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://validator.schema.org/#url=' . rawurlencode( $home ) ) . '"><i class="fa-solid fa-code" aria-hidden="true"></i> ' . esc_html__( 'Schema validator', 'publishrank' ) . '</a></div>';
		echo '</section>';
		echo '</aside></div></form></div>';
	}
}
