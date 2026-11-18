<?php
/**
 * Schema Library type definitions: each type lists its fields with a validator kind and which fields are required
 * for a valid node (Google rich-result requirements where they exist, schema.org otherwise). A node is only printed
 * when every required field passes validation — never a half-filled stub.
 *
 * Field kinds: text, textarea, url, date, datetime, duration (ISO 8601), number, money, currency, country, lines,
 * rating, select, json (validated custom JSON-LD object).
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Library;

defined( 'ABSPATH' ) || exit;

final class Definitions {

	/** @return array<string,array{label:string,group:string,doc:string,fields:array<string,array>}> */
	public static function all() {
		static $defs = null;
		if ( null !== $defs ) {
			return $defs; }
		$t    = static function ( $label, $kind = 'text', $required = false, $extra = array() ) {
			return array_merge( array( 'label' => $label, 'kind' => $kind, 'required' => $required ), $extra );
		};
		$defs = array(
			'HowTo'                          => array( 'label' => __( 'How-to guide', 'publishrank' ), 'group' => 'guides', 'doc' => 'https://schema.org/HowTo', 'fields' => array(
				'name'      => $t( __( 'Title', 'publishrank' ), 'text', true ),
				'steps'     => $t( __( 'Steps (one per line)', 'publishrank' ), 'lines', true ),
				'supply'    => $t( __( 'Supplies (one per line)', 'publishrank' ), 'lines' ),
				'tool'      => $t( __( 'Tools (one per line)', 'publishrank' ), 'lines' ),
				'totalTime' => $t( __( 'Total time (ISO 8601, e.g. PT45M)', 'publishrank' ), 'duration' ),
			) ),
			'Course'                         => array( 'label' => __( 'Course', 'publishrank' ), 'group' => 'learning', 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/course', 'fields' => array(
				'name'        => $t( __( 'Course name', 'publishrank' ), 'text', true ),
				'description' => $t( __( 'Description', 'publishrank' ), 'textarea', true ),
				'provider'    => $t( __( 'Provider (organisation)', 'publishrank' ), 'text', true ),
				'providerUrl' => $t( __( 'Provider website', 'publishrank' ), 'url' ),
				'courseCode'  => $t( __( 'Course code', 'publishrank' ) ),
			) ),
			'EducationalOccupationalProgram' => array( 'label' => __( 'Education / training programme', 'publishrank' ), 'group' => 'learning', 'doc' => 'https://schema.org/EducationalOccupationalProgram', 'fields' => array(
				'name'               => $t( __( 'Programme name', 'publishrank' ), 'text', true ),
				'provider'           => $t( __( 'Provider', 'publishrank' ), 'text', true ),
				'timeToComplete'     => $t( __( 'Time to complete (ISO 8601, e.g. P6M)', 'publishrank' ), 'duration' ),
				'educationalCredentialAwarded' => $t( __( 'Credential awarded', 'publishrank' ) ),
				'offerPrice'         => $t( __( 'Tuition', 'publishrank' ), 'money' ),
				'priceCurrency'      => $t( __( 'Currency (ISO 4217)', 'publishrank' ), 'currency' ),
			) ),
			'SoftwareApplication'            => array( 'label' => __( 'Software / app', 'publishrank' ), 'group' => 'products', 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/software-app', 'fields' => array(
				'name'                => $t( __( 'App name', 'publishrank' ), 'text', true ),
				'applicationCategory' => $t( __( 'Category', 'publishrank' ), 'select', true, array( 'options' => array( 'GameApplication', 'BusinessApplication', 'EducationalApplication', 'FinanceApplication', 'HealthApplication', 'LifestyleApplication', 'MultimediaApplication', 'NewsApplication', 'ReferenceApplication', 'SocialNetworkingApplication', 'TravelApplication', 'UtilitiesApplication', 'DeveloperApplication', 'SecurityApplication' ) ) ),
				'operatingSystem'     => $t( __( 'Operating system', 'publishrank' ), 'text', true ),
				'price'               => $t( __( 'Price (0 for free)', 'publishrank' ), 'money', true ),
				'priceCurrency'       => $t( __( 'Currency (ISO 4217)', 'publishrank' ), 'currency', true ),
				'ratingValue'         => $t( __( 'Average rating (1–5)', 'publishrank' ), 'rating' ),
				'ratingCount'         => $t( __( 'Number of ratings', 'publishrank' ), 'number' ),
			) ),
			'Book'                           => array( 'label' => __( 'Book', 'publishrank' ), 'group' => 'media', 'doc' => 'https://schema.org/Book', 'fields' => array(
				'name'          => $t( __( 'Title', 'publishrank' ), 'text', true ),
				'author'        => $t( __( 'Author', 'publishrank' ), 'text', true ),
				'isbn'          => $t( __( 'ISBN', 'publishrank' ) ),
				'datePublished' => $t( __( 'Publication date', 'publishrank' ), 'date' ),
				'publisher'     => $t( __( 'Publisher', 'publishrank' ) ),
				'numberOfPages' => $t( __( 'Pages', 'publishrank' ), 'number' ),
			) ),
			'Movie'                          => array( 'label' => __( 'Film', 'publishrank' ), 'group' => 'media', 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/movie', 'fields' => array(
				'name'        => $t( __( 'Title', 'publishrank' ), 'text', true ),
				'image'       => $t( __( 'Poster image URL', 'publishrank' ), 'url', true ),
				'dateCreated' => $t( __( 'Release date', 'publishrank' ), 'date' ),
				'director'    => $t( __( 'Director', 'publishrank' ) ),
			) ),
			'MusicAlbum'                     => array( 'label' => __( 'Music album', 'publishrank' ), 'group' => 'media', 'doc' => 'https://schema.org/MusicAlbum', 'fields' => array(
				'name'          => $t( __( 'Album title', 'publishrank' ), 'text', true ),
				'byArtist'      => $t( __( 'Artist', 'publishrank' ), 'text', true ),
				'datePublished' => $t( __( 'Release date', 'publishrank' ), 'date' ),
				'numTracks'     => $t( __( 'Number of tracks', 'publishrank' ), 'number' ),
			) ),
			'LocalBusiness'                  => array( 'label' => __( 'Local business / venue', 'publishrank' ), 'group' => 'places', 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/local-business', 'fields' => array(
				'businessType'    => $t( __( 'Business type', 'publishrank' ), 'select', true, array( 'options' => array( 'LocalBusiness', 'Restaurant', 'CafeOrCoffeeShop', 'BarOrPub', 'Hotel', 'Store', 'MedicalClinic', 'Dentist', 'LegalService', 'RealEstateAgent', 'AutoRepair', 'HealthClub', 'Library', 'Museum', 'MovieTheater', 'PerformingArtsTheater' ) ) ),
				'name'            => $t( __( 'Name', 'publishrank' ), 'text', true ),
				'streetAddress'   => $t( __( 'Street address', 'publishrank' ), 'text', true ),
				'addressLocality' => $t( __( 'City', 'publishrank' ), 'text', true ),
				'postalCode'      => $t( __( 'Postal code', 'publishrank' ) ),
				'addressCountry'  => $t( __( 'Country (ISO 3166-1 alpha-2)', 'publishrank' ), 'country', true ),
				'telephone'       => $t( __( 'Telephone', 'publishrank' ) ),
				'priceRange'      => $t( __( 'Price range (e.g. $$)', 'publishrank' ) ),
				'openingHours'    => $t( __( 'Opening hours (one per line, e.g. Mo-Fr 09:00-17:00)', 'publishrank' ), 'lines' ),
				'url'             => $t( __( 'Website', 'publishrank' ), 'url' ),
			) ),
			'TouristAttraction'              => array( 'label' => __( 'Place / attraction', 'publishrank' ), 'group' => 'places', 'doc' => 'https://schema.org/TouristAttraction', 'fields' => array(
				'name'            => $t( __( 'Name', 'publishrank' ), 'text', true ),
				'addressLocality' => $t( __( 'City', 'publishrank' ), 'text', true ),
				'addressCountry'  => $t( __( 'Country (ISO 3166-1 alpha-2)', 'publishrank' ), 'country', true ),
				'latitude'        => $t( __( 'Latitude', 'publishrank' ), 'number' ),
				'longitude'       => $t( __( 'Longitude', 'publishrank' ), 'number' ),
			) ),
			'Service'                        => array( 'label' => __( 'Service', 'publishrank' ), 'group' => 'products', 'doc' => 'https://schema.org/Service', 'fields' => array(
				'name'        => $t( __( 'Service name', 'publishrank' ), 'text', true ),
				'serviceType' => $t( __( 'Service type', 'publishrank' ), 'text', true ),
				'provider'    => $t( __( 'Provider', 'publishrank' ), 'text', true ),
				'areaServed'  => $t( __( 'Area served', 'publishrank' ) ),
			) ),
			'Dataset'                        => array( 'label' => __( 'Dataset (data journalism)', 'publishrank' ), 'group' => 'journalism', 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/dataset', 'fields' => array(
				'name'          => $t( __( 'Dataset name', 'publishrank' ), 'text', true ),
				'description'   => $t( __( 'Description (50–5000 characters)', 'publishrank' ), 'textarea', true, array( 'min' => 50, 'max' => 5000 ) ),
				'license'       => $t( __( 'Licence URL', 'publishrank' ), 'url' ),
				'creator'       => $t( __( 'Creator', 'publishrank' ) ),
				'contentUrl'    => $t( __( 'Download URL', 'publishrank' ), 'url' ),
				'encodingFormat' => $t( __( 'File format (e.g. text/csv)', 'publishrank' ) ),
				'temporalCoverage' => $t( __( 'Time period (e.g. 2020/2024)', 'publishrank' ) ),
			) ),
			'ClaimReview'                    => array( 'label' => __( 'Fact check', 'publishrank' ), 'group' => 'journalism', 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/factcheck', 'fields' => array(
				'claimReviewed' => $t( __( 'Claim being checked', 'publishrank' ), 'textarea', true ),
				'claimAuthor'   => $t( __( 'Who made the claim', 'publishrank' ), 'text', true ),
				'claimDate'     => $t( __( 'When the claim was made', 'publishrank' ), 'date' ),
				'ratingValue'   => $t( __( 'Rating (1 = false … 5 = true)', 'publishrank' ), 'rating', true ),
				'alternateName' => $t( __( 'Verdict in words (e.g. Mostly false)', 'publishrank' ), 'text', true ),
			) ),
			'QAPage'                         => array( 'label' => __( 'Question & answer', 'publishrank' ), 'group' => 'community', 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/qapage', 'fields' => array(
				'question'    => $t( __( 'Question', 'publishrank' ), 'text', true ),
				'questionText' => $t( __( 'Question details', 'publishrank' ), 'textarea' ),
				'answer'      => $t( __( 'Accepted answer', 'publishrank' ), 'textarea', true ),
				'answerCount' => $t( __( 'Number of answers', 'publishrank' ), 'number' ),
			) ),
			'DiscussionForumPosting'         => array( 'label' => __( 'Discussion / forum post', 'publishrank' ), 'group' => 'community', 'doc' => 'https://developers.google.com/search/docs/appearance/structured-data/discussion-forum', 'fields' => array(
				'headline' => $t( __( 'Headline', 'publishrank' ), 'text', true ),
				'text'     => $t( __( 'Opening post text', 'publishrank' ), 'textarea', true ),
			) ),
			'SpecialAnnouncement'            => array( 'label' => __( 'Special announcement', 'publishrank' ), 'group' => 'journalism', 'doc' => 'https://schema.org/SpecialAnnouncement', 'fields' => array(
				'name'           => $t( __( 'Announcement title', 'publishrank' ), 'text', true ),
				'text'           => $t( __( 'Announcement', 'publishrank' ), 'textarea', true ),
				'datePosted'     => $t( __( 'Posted', 'publishrank' ), 'datetime', true ),
				'expires'        => $t( __( 'Expires', 'publishrank' ), 'datetime' ),
				'category'       => $t( __( 'Category URL (e.g. Wikipedia topic)', 'publishrank' ), 'url' ),
				'spatialCoverage' => $t( __( 'Area affected', 'publishrank' ) ),
			) ),
			'Quiz'                           => array( 'label' => __( 'Quiz', 'publishrank' ), 'group' => 'learning', 'doc' => 'https://schema.org/Quiz', 'fields' => array(
				'name'      => $t( __( 'Quiz title', 'publishrank' ), 'text', true ),
				'questions' => $t( __( 'Questions as "Question | Answer" (one per line)', 'publishrank' ), 'lines', true ),
				'about'     => $t( __( 'Topic', 'publishrank' ) ),
			) ),
			'VideoGame'                      => array( 'label' => __( 'Video game', 'publishrank' ), 'group' => 'media', 'doc' => 'https://schema.org/VideoGame', 'fields' => array(
				'name'        => $t( __( 'Title', 'publishrank' ), 'text', true ),
				'gamePlatform' => $t( __( 'Platforms (one per line)', 'publishrank' ), 'lines', true ),
				'publisher'   => $t( __( 'Publisher', 'publishrank' ) ),
				'genre'       => $t( __( 'Genre', 'publishrank' ) ),
			) ),
			'Person'                         => array( 'label' => __( 'Person (profile / obituary subject)', 'publishrank' ), 'group' => 'people', 'doc' => 'https://schema.org/Person', 'fields' => array(
				'name'      => $t( __( 'Full name', 'publishrank' ), 'text', true ),
				'jobTitle'  => $t( __( 'Job title', 'publishrank' ) ),
				'birthDate' => $t( __( 'Born', 'publishrank' ), 'date' ),
				'deathDate' => $t( __( 'Died', 'publishrank' ), 'date' ),
				'sameAs'    => $t( __( 'Profiles (one URL per line)', 'publishrank' ), 'lines' ),
			) ),
			'CustomJsonLd'                   => array( 'label' => __( 'Custom schema (JSON-LD)', 'publishrank' ), 'group' => 'custom', 'doc' => 'https://schema.org/docs/full.html', 'fields' => array(
				'json' => $t( __( 'JSON-LD object for one schema.org type, e.g. {"@type":"Event", "name":"…"}. It is checked before it is published and joins the page graph.', 'publishrank' ), 'json', true ),
			) ),
		);
		$defs = (array) apply_filters( 'publishrank_schema_library_types', $defs );
		return $defs;
	}

	public static function groups() {
		return array(
			'journalism' => __( 'Journalism', 'publishrank' ),
			'guides'     => __( 'Guides', 'publishrank' ),
			'learning'   => __( 'Learning', 'publishrank' ),
			'media'      => __( 'Books, film, music & games', 'publishrank' ),
			'places'     => __( 'Places & businesses', 'publishrank' ),
			'products'   => __( 'Software & services', 'publishrank' ),
			'community'  => __( 'Community', 'publishrank' ),
			'people'     => __( 'People', 'publishrank' ),
			'custom'     => __( 'Custom', 'publishrank' ),
		);
	}
}
