<?php
/**
 * PublishRank country database: every ISO 3166-1 country and territory (249 entries) with its alpha-2,
 * alpha-3 and numeric code. Display names come from Unicode CLDR via umpirsky/country-list (MIT, see
 * COUNTRY-LIST-LICENSE.txt); alpha-3 and numeric codes are cross-checked against the Debian iso-codes
 * data set. To update: regenerate CODES from iso_3166-1.json and add any new name to all().
 *
 * Security decisions never use browser geolocation: country codes for requests come from
 * CountryResolver (trusted CDN/server headers or a developer-supplied lookup).
 */
namespace PublishRankSEO\Core;
defined( 'ABSPATH' ) || exit;
final class Countries {
	/** Alpha-2 => array( alpha-3, numeric ). */
	const CODES = array(
		'AD' => array( 'AND', '020' ), 'AE' => array( 'ARE', '784' ), 'AF' => array( 'AFG', '004' ), 'AG' => array( 'ATG', '028' ),
		'AI' => array( 'AIA', '660' ), 'AL' => array( 'ALB', '008' ), 'AM' => array( 'ARM', '051' ), 'AO' => array( 'AGO', '024' ),
		'AQ' => array( 'ATA', '010' ), 'AR' => array( 'ARG', '032' ), 'AS' => array( 'ASM', '016' ), 'AT' => array( 'AUT', '040' ),
		'AU' => array( 'AUS', '036' ), 'AW' => array( 'ABW', '533' ), 'AX' => array( 'ALA', '248' ), 'AZ' => array( 'AZE', '031' ),
		'BA' => array( 'BIH', '070' ), 'BB' => array( 'BRB', '052' ), 'BD' => array( 'BGD', '050' ), 'BE' => array( 'BEL', '056' ),
		'BF' => array( 'BFA', '854' ), 'BG' => array( 'BGR', '100' ), 'BH' => array( 'BHR', '048' ), 'BI' => array( 'BDI', '108' ),
		'BJ' => array( 'BEN', '204' ), 'BL' => array( 'BLM', '652' ), 'BM' => array( 'BMU', '060' ), 'BN' => array( 'BRN', '096' ),
		'BO' => array( 'BOL', '068' ), 'BQ' => array( 'BES', '535' ), 'BR' => array( 'BRA', '076' ), 'BS' => array( 'BHS', '044' ),
		'BT' => array( 'BTN', '064' ), 'BV' => array( 'BVT', '074' ), 'BW' => array( 'BWA', '072' ), 'BY' => array( 'BLR', '112' ),
		'BZ' => array( 'BLZ', '084' ), 'CA' => array( 'CAN', '124' ), 'CC' => array( 'CCK', '166' ), 'CD' => array( 'COD', '180' ),
		'CF' => array( 'CAF', '140' ), 'CG' => array( 'COG', '178' ), 'CH' => array( 'CHE', '756' ), 'CI' => array( 'CIV', '384' ),
		'CK' => array( 'COK', '184' ), 'CL' => array( 'CHL', '152' ), 'CM' => array( 'CMR', '120' ), 'CN' => array( 'CHN', '156' ),
		'CO' => array( 'COL', '170' ), 'CR' => array( 'CRI', '188' ), 'CU' => array( 'CUB', '192' ), 'CV' => array( 'CPV', '132' ),
		'CW' => array( 'CUW', '531' ), 'CX' => array( 'CXR', '162' ), 'CY' => array( 'CYP', '196' ), 'CZ' => array( 'CZE', '203' ),
		'DE' => array( 'DEU', '276' ), 'DJ' => array( 'DJI', '262' ), 'DK' => array( 'DNK', '208' ), 'DM' => array( 'DMA', '212' ),
		'DO' => array( 'DOM', '214' ), 'DZ' => array( 'DZA', '012' ), 'EC' => array( 'ECU', '218' ), 'EE' => array( 'EST', '233' ),
		'EG' => array( 'EGY', '818' ), 'EH' => array( 'ESH', '732' ), 'ER' => array( 'ERI', '232' ), 'ES' => array( 'ESP', '724' ),
		'ET' => array( 'ETH', '231' ), 'FI' => array( 'FIN', '246' ), 'FJ' => array( 'FJI', '242' ), 'FK' => array( 'FLK', '238' ),
		'FM' => array( 'FSM', '583' ), 'FO' => array( 'FRO', '234' ), 'FR' => array( 'FRA', '250' ), 'GA' => array( 'GAB', '266' ),
		'GB' => array( 'GBR', '826' ), 'GD' => array( 'GRD', '308' ), 'GE' => array( 'GEO', '268' ), 'GF' => array( 'GUF', '254' ),
		'GG' => array( 'GGY', '831' ), 'GH' => array( 'GHA', '288' ), 'GI' => array( 'GIB', '292' ), 'GL' => array( 'GRL', '304' ),
		'GM' => array( 'GMB', '270' ), 'GN' => array( 'GIN', '324' ), 'GP' => array( 'GLP', '312' ), 'GQ' => array( 'GNQ', '226' ),
		'GR' => array( 'GRC', '300' ), 'GS' => array( 'SGS', '239' ), 'GT' => array( 'GTM', '320' ), 'GU' => array( 'GUM', '316' ),
		'GW' => array( 'GNB', '624' ), 'GY' => array( 'GUY', '328' ), 'HK' => array( 'HKG', '344' ), 'HM' => array( 'HMD', '334' ),
		'HN' => array( 'HND', '340' ), 'HR' => array( 'HRV', '191' ), 'HT' => array( 'HTI', '332' ), 'HU' => array( 'HUN', '348' ),
		'ID' => array( 'IDN', '360' ), 'IE' => array( 'IRL', '372' ), 'IL' => array( 'ISR', '376' ), 'IM' => array( 'IMN', '833' ),
		'IN' => array( 'IND', '356' ), 'IO' => array( 'IOT', '086' ), 'IQ' => array( 'IRQ', '368' ), 'IR' => array( 'IRN', '364' ),
		'IS' => array( 'ISL', '352' ), 'IT' => array( 'ITA', '380' ), 'JE' => array( 'JEY', '832' ), 'JM' => array( 'JAM', '388' ),
		'JO' => array( 'JOR', '400' ), 'JP' => array( 'JPN', '392' ), 'KE' => array( 'KEN', '404' ), 'KG' => array( 'KGZ', '417' ),
		'KH' => array( 'KHM', '116' ), 'KI' => array( 'KIR', '296' ), 'KM' => array( 'COM', '174' ), 'KN' => array( 'KNA', '659' ),
		'KP' => array( 'PRK', '408' ), 'KR' => array( 'KOR', '410' ), 'KW' => array( 'KWT', '414' ), 'KY' => array( 'CYM', '136' ),
		'KZ' => array( 'KAZ', '398' ), 'LA' => array( 'LAO', '418' ), 'LB' => array( 'LBN', '422' ), 'LC' => array( 'LCA', '662' ),
		'LI' => array( 'LIE', '438' ), 'LK' => array( 'LKA', '144' ), 'LR' => array( 'LBR', '430' ), 'LS' => array( 'LSO', '426' ),
		'LT' => array( 'LTU', '440' ), 'LU' => array( 'LUX', '442' ), 'LV' => array( 'LVA', '428' ), 'LY' => array( 'LBY', '434' ),
		'MA' => array( 'MAR', '504' ), 'MC' => array( 'MCO', '492' ), 'MD' => array( 'MDA', '498' ), 'ME' => array( 'MNE', '499' ),
		'MF' => array( 'MAF', '663' ), 'MG' => array( 'MDG', '450' ), 'MH' => array( 'MHL', '584' ), 'MK' => array( 'MKD', '807' ),
		'ML' => array( 'MLI', '466' ), 'MM' => array( 'MMR', '104' ), 'MN' => array( 'MNG', '496' ), 'MO' => array( 'MAC', '446' ),
		'MP' => array( 'MNP', '580' ), 'MQ' => array( 'MTQ', '474' ), 'MR' => array( 'MRT', '478' ), 'MS' => array( 'MSR', '500' ),
		'MT' => array( 'MLT', '470' ), 'MU' => array( 'MUS', '480' ), 'MV' => array( 'MDV', '462' ), 'MW' => array( 'MWI', '454' ),
		'MX' => array( 'MEX', '484' ), 'MY' => array( 'MYS', '458' ), 'MZ' => array( 'MOZ', '508' ), 'NA' => array( 'NAM', '516' ),
		'NC' => array( 'NCL', '540' ), 'NE' => array( 'NER', '562' ), 'NF' => array( 'NFK', '574' ), 'NG' => array( 'NGA', '566' ),
		'NI' => array( 'NIC', '558' ), 'NL' => array( 'NLD', '528' ), 'NO' => array( 'NOR', '578' ), 'NP' => array( 'NPL', '524' ),
		'NR' => array( 'NRU', '520' ), 'NU' => array( 'NIU', '570' ), 'NZ' => array( 'NZL', '554' ), 'OM' => array( 'OMN', '512' ),
		'PA' => array( 'PAN', '591' ), 'PE' => array( 'PER', '604' ), 'PF' => array( 'PYF', '258' ), 'PG' => array( 'PNG', '598' ),
		'PH' => array( 'PHL', '608' ), 'PK' => array( 'PAK', '586' ), 'PL' => array( 'POL', '616' ), 'PM' => array( 'SPM', '666' ),
		'PN' => array( 'PCN', '612' ), 'PR' => array( 'PRI', '630' ), 'PS' => array( 'PSE', '275' ), 'PT' => array( 'PRT', '620' ),
		'PW' => array( 'PLW', '585' ), 'PY' => array( 'PRY', '600' ), 'QA' => array( 'QAT', '634' ), 'RE' => array( 'REU', '638' ),
		'RO' => array( 'ROU', '642' ), 'RS' => array( 'SRB', '688' ), 'RU' => array( 'RUS', '643' ), 'RW' => array( 'RWA', '646' ),
		'SA' => array( 'SAU', '682' ), 'SB' => array( 'SLB', '090' ), 'SC' => array( 'SYC', '690' ), 'SD' => array( 'SDN', '729' ),
		'SE' => array( 'SWE', '752' ), 'SG' => array( 'SGP', '702' ), 'SH' => array( 'SHN', '654' ), 'SI' => array( 'SVN', '705' ),
		'SJ' => array( 'SJM', '744' ), 'SK' => array( 'SVK', '703' ), 'SL' => array( 'SLE', '694' ), 'SM' => array( 'SMR', '674' ),
		'SN' => array( 'SEN', '686' ), 'SO' => array( 'SOM', '706' ), 'SR' => array( 'SUR', '740' ), 'SS' => array( 'SSD', '728' ),
		'ST' => array( 'STP', '678' ), 'SV' => array( 'SLV', '222' ), 'SX' => array( 'SXM', '534' ), 'SY' => array( 'SYR', '760' ),
		'SZ' => array( 'SWZ', '748' ), 'TC' => array( 'TCA', '796' ), 'TD' => array( 'TCD', '148' ), 'TF' => array( 'ATF', '260' ),
		'TG' => array( 'TGO', '768' ), 'TH' => array( 'THA', '764' ), 'TJ' => array( 'TJK', '762' ), 'TK' => array( 'TKL', '772' ),
		'TL' => array( 'TLS', '626' ), 'TM' => array( 'TKM', '795' ), 'TN' => array( 'TUN', '788' ), 'TO' => array( 'TON', '776' ),
		'TR' => array( 'TUR', '792' ), 'TT' => array( 'TTO', '780' ), 'TV' => array( 'TUV', '798' ), 'TW' => array( 'TWN', '158' ),
		'TZ' => array( 'TZA', '834' ), 'UA' => array( 'UKR', '804' ), 'UG' => array( 'UGA', '800' ), 'UM' => array( 'UMI', '581' ),
		'US' => array( 'USA', '840' ), 'UY' => array( 'URY', '858' ), 'UZ' => array( 'UZB', '860' ), 'VA' => array( 'VAT', '336' ),
		'VC' => array( 'VCT', '670' ), 'VE' => array( 'VEN', '862' ), 'VG' => array( 'VGB', '092' ), 'VI' => array( 'VIR', '850' ),
		'VN' => array( 'VNM', '704' ), 'VU' => array( 'VUT', '548' ), 'WF' => array( 'WLF', '876' ), 'WS' => array( 'WSM', '882' ),
		'YE' => array( 'YEM', '887' ), 'YT' => array( 'MYT', '175' ), 'ZA' => array( 'ZAF', '710' ), 'ZM' => array( 'ZMB', '894' ),
		'ZW' => array( 'ZWE', '716' ),
	);

	/** Pseudo-codes that are not countries but can appear in CDN headers. */
	const SPECIAL = array(
		'XX' => 'Unknown location',
		'T1' => 'Tor network',
	);

	public static function all() {
		return array(
			'AF' => __( 'Afghanistan', 'publishrank' ),
			'AX' => __( 'Åland Islands', 'publishrank' ),
			'AL' => __( 'Albania', 'publishrank' ),
			'DZ' => __( 'Algeria', 'publishrank' ),
			'AS' => __( 'American Samoa', 'publishrank' ),
			'AD' => __( 'Andorra', 'publishrank' ),
			'AO' => __( 'Angola', 'publishrank' ),
			'AI' => __( 'Anguilla', 'publishrank' ),
			'AQ' => __( 'Antarctica', 'publishrank' ),
			'AG' => __( 'Antigua & Barbuda', 'publishrank' ),
			'AR' => __( 'Argentina', 'publishrank' ),
			'AM' => __( 'Armenia', 'publishrank' ),
			'AW' => __( 'Aruba', 'publishrank' ),
			'AU' => __( 'Australia', 'publishrank' ),
			'AT' => __( 'Austria', 'publishrank' ),
			'AZ' => __( 'Azerbaijan', 'publishrank' ),
			'BS' => __( 'Bahamas', 'publishrank' ),
			'BH' => __( 'Bahrain', 'publishrank' ),
			'BD' => __( 'Bangladesh', 'publishrank' ),
			'BB' => __( 'Barbados', 'publishrank' ),
			'BY' => __( 'Belarus', 'publishrank' ),
			'BE' => __( 'Belgium', 'publishrank' ),
			'BZ' => __( 'Belize', 'publishrank' ),
			'BJ' => __( 'Benin', 'publishrank' ),
			'BM' => __( 'Bermuda', 'publishrank' ),
			'BT' => __( 'Bhutan', 'publishrank' ),
			'BO' => __( 'Bolivia', 'publishrank' ),
			'BA' => __( 'Bosnia & Herzegovina', 'publishrank' ),
			'BW' => __( 'Botswana', 'publishrank' ),
			'BV' => __( 'Bouvet Island', 'publishrank' ),
			'BR' => __( 'Brazil', 'publishrank' ),
			'IO' => __( 'British Indian Ocean Territory', 'publishrank' ),
			'VG' => __( 'British Virgin Islands', 'publishrank' ),
			'BN' => __( 'Brunei', 'publishrank' ),
			'BG' => __( 'Bulgaria', 'publishrank' ),
			'BF' => __( 'Burkina Faso', 'publishrank' ),
			'BI' => __( 'Burundi', 'publishrank' ),
			'KH' => __( 'Cambodia', 'publishrank' ),
			'CM' => __( 'Cameroon', 'publishrank' ),
			'CA' => __( 'Canada', 'publishrank' ),
			'CV' => __( 'Cape Verde', 'publishrank' ),
			'BQ' => __( 'Caribbean Netherlands', 'publishrank' ),
			'KY' => __( 'Cayman Islands', 'publishrank' ),
			'CF' => __( 'Central African Republic', 'publishrank' ),
			'TD' => __( 'Chad', 'publishrank' ),
			'CL' => __( 'Chile', 'publishrank' ),
			'CN' => __( 'China', 'publishrank' ),
			'CX' => __( 'Christmas Island', 'publishrank' ),
			'CC' => __( 'Cocos (Keeling) Islands', 'publishrank' ),
			'CO' => __( 'Colombia', 'publishrank' ),
			'KM' => __( 'Comoros', 'publishrank' ),
			'CG' => __( 'Congo - Brazzaville', 'publishrank' ),
			'CD' => __( 'Congo - Kinshasa', 'publishrank' ),
			'CK' => __( 'Cook Islands', 'publishrank' ),
			'CR' => __( 'Costa Rica', 'publishrank' ),
			'CI' => __( 'Côte d’Ivoire', 'publishrank' ),
			'HR' => __( 'Croatia', 'publishrank' ),
			'CU' => __( 'Cuba', 'publishrank' ),
			'CW' => __( 'Curaçao', 'publishrank' ),
			'CY' => __( 'Cyprus', 'publishrank' ),
			'CZ' => __( 'Czechia', 'publishrank' ),
			'DK' => __( 'Denmark', 'publishrank' ),
			'DJ' => __( 'Djibouti', 'publishrank' ),
			'DM' => __( 'Dominica', 'publishrank' ),
			'DO' => __( 'Dominican Republic', 'publishrank' ),
			'EC' => __( 'Ecuador', 'publishrank' ),
			'EG' => __( 'Egypt', 'publishrank' ),
			'SV' => __( 'El Salvador', 'publishrank' ),
			'GQ' => __( 'Equatorial Guinea', 'publishrank' ),
			'ER' => __( 'Eritrea', 'publishrank' ),
			'EE' => __( 'Estonia', 'publishrank' ),
			'SZ' => __( 'Eswatini', 'publishrank' ),
			'ET' => __( 'Ethiopia', 'publishrank' ),
			'FK' => __( 'Falkland Islands', 'publishrank' ),
			'FO' => __( 'Faroe Islands', 'publishrank' ),
			'FJ' => __( 'Fiji', 'publishrank' ),
			'FI' => __( 'Finland', 'publishrank' ),
			'FR' => __( 'France', 'publishrank' ),
			'GF' => __( 'French Guiana', 'publishrank' ),
			'PF' => __( 'French Polynesia', 'publishrank' ),
			'TF' => __( 'French Southern Territories', 'publishrank' ),
			'GA' => __( 'Gabon', 'publishrank' ),
			'GM' => __( 'Gambia', 'publishrank' ),
			'GE' => __( 'Georgia', 'publishrank' ),
			'DE' => __( 'Germany', 'publishrank' ),
			'GH' => __( 'Ghana', 'publishrank' ),
			'GI' => __( 'Gibraltar', 'publishrank' ),
			'GR' => __( 'Greece', 'publishrank' ),
			'GL' => __( 'Greenland', 'publishrank' ),
			'GD' => __( 'Grenada', 'publishrank' ),
			'GP' => __( 'Guadeloupe', 'publishrank' ),
			'GU' => __( 'Guam', 'publishrank' ),
			'GT' => __( 'Guatemala', 'publishrank' ),
			'GG' => __( 'Guernsey', 'publishrank' ),
			'GN' => __( 'Guinea', 'publishrank' ),
			'GW' => __( 'Guinea-Bissau', 'publishrank' ),
			'GY' => __( 'Guyana', 'publishrank' ),
			'HT' => __( 'Haiti', 'publishrank' ),
			'HM' => __( 'Heard & McDonald Islands', 'publishrank' ),
			'HN' => __( 'Honduras', 'publishrank' ),
			'HK' => __( 'Hong Kong SAR China', 'publishrank' ),
			'HU' => __( 'Hungary', 'publishrank' ),
			'IS' => __( 'Iceland', 'publishrank' ),
			'IN' => __( 'India', 'publishrank' ),
			'ID' => __( 'Indonesia', 'publishrank' ),
			'IR' => __( 'Iran', 'publishrank' ),
			'IQ' => __( 'Iraq', 'publishrank' ),
			'IE' => __( 'Ireland', 'publishrank' ),
			'IM' => __( 'Isle of Man', 'publishrank' ),
			'IL' => __( 'Israel', 'publishrank' ),
			'IT' => __( 'Italy', 'publishrank' ),
			'JM' => __( 'Jamaica', 'publishrank' ),
			'JP' => __( 'Japan', 'publishrank' ),
			'JE' => __( 'Jersey', 'publishrank' ),
			'JO' => __( 'Jordan', 'publishrank' ),
			'KZ' => __( 'Kazakhstan', 'publishrank' ),
			'KE' => __( 'Kenya', 'publishrank' ),
			'KI' => __( 'Kiribati', 'publishrank' ),
			'KW' => __( 'Kuwait', 'publishrank' ),
			'KG' => __( 'Kyrgyzstan', 'publishrank' ),
			'LA' => __( 'Laos', 'publishrank' ),
			'LV' => __( 'Latvia', 'publishrank' ),
			'LB' => __( 'Lebanon', 'publishrank' ),
			'LS' => __( 'Lesotho', 'publishrank' ),
			'LR' => __( 'Liberia', 'publishrank' ),
			'LY' => __( 'Libya', 'publishrank' ),
			'LI' => __( 'Liechtenstein', 'publishrank' ),
			'LT' => __( 'Lithuania', 'publishrank' ),
			'LU' => __( 'Luxembourg', 'publishrank' ),
			'MO' => __( 'Macao SAR China', 'publishrank' ),
			'MG' => __( 'Madagascar', 'publishrank' ),
			'MW' => __( 'Malawi', 'publishrank' ),
			'MY' => __( 'Malaysia', 'publishrank' ),
			'MV' => __( 'Maldives', 'publishrank' ),
			'ML' => __( 'Mali', 'publishrank' ),
			'MT' => __( 'Malta', 'publishrank' ),
			'MH' => __( 'Marshall Islands', 'publishrank' ),
			'MQ' => __( 'Martinique', 'publishrank' ),
			'MR' => __( 'Mauritania', 'publishrank' ),
			'MU' => __( 'Mauritius', 'publishrank' ),
			'YT' => __( 'Mayotte', 'publishrank' ),
			'MX' => __( 'Mexico', 'publishrank' ),
			'FM' => __( 'Micronesia', 'publishrank' ),
			'MD' => __( 'Moldova', 'publishrank' ),
			'MC' => __( 'Monaco', 'publishrank' ),
			'MN' => __( 'Mongolia', 'publishrank' ),
			'ME' => __( 'Montenegro', 'publishrank' ),
			'MS' => __( 'Montserrat', 'publishrank' ),
			'MA' => __( 'Morocco', 'publishrank' ),
			'MZ' => __( 'Mozambique', 'publishrank' ),
			'MM' => __( 'Myanmar (Burma)', 'publishrank' ),
			'NA' => __( 'Namibia', 'publishrank' ),
			'NR' => __( 'Nauru', 'publishrank' ),
			'NP' => __( 'Nepal', 'publishrank' ),
			'NL' => __( 'Netherlands', 'publishrank' ),
			'NC' => __( 'New Caledonia', 'publishrank' ),
			'NZ' => __( 'New Zealand', 'publishrank' ),
			'NI' => __( 'Nicaragua', 'publishrank' ),
			'NE' => __( 'Niger', 'publishrank' ),
			'NG' => __( 'Nigeria', 'publishrank' ),
			'NU' => __( 'Niue', 'publishrank' ),
			'NF' => __( 'Norfolk Island', 'publishrank' ),
			'KP' => __( 'North Korea', 'publishrank' ),
			'MK' => __( 'North Macedonia', 'publishrank' ),
			'MP' => __( 'Northern Mariana Islands', 'publishrank' ),
			'NO' => __( 'Norway', 'publishrank' ),
			'OM' => __( 'Oman', 'publishrank' ),
			'PK' => __( 'Pakistan', 'publishrank' ),
			'PW' => __( 'Palau', 'publishrank' ),
			'PS' => __( 'Palestinian Territories', 'publishrank' ),
			'PA' => __( 'Panama', 'publishrank' ),
			'PG' => __( 'Papua New Guinea', 'publishrank' ),
			'PY' => __( 'Paraguay', 'publishrank' ),
			'PE' => __( 'Peru', 'publishrank' ),
			'PH' => __( 'Philippines', 'publishrank' ),
			'PN' => __( 'Pitcairn Islands', 'publishrank' ),
			'PL' => __( 'Poland', 'publishrank' ),
			'PT' => __( 'Portugal', 'publishrank' ),
			'PR' => __( 'Puerto Rico', 'publishrank' ),
			'QA' => __( 'Qatar', 'publishrank' ),
			'RE' => __( 'Réunion', 'publishrank' ),
			'RO' => __( 'Romania', 'publishrank' ),
			'RU' => __( 'Russia', 'publishrank' ),
			'RW' => __( 'Rwanda', 'publishrank' ),
			'WS' => __( 'Samoa', 'publishrank' ),
			'SM' => __( 'San Marino', 'publishrank' ),
			'ST' => __( 'São Tomé & Príncipe', 'publishrank' ),
			'SA' => __( 'Saudi Arabia', 'publishrank' ),
			'SN' => __( 'Senegal', 'publishrank' ),
			'RS' => __( 'Serbia', 'publishrank' ),
			'SC' => __( 'Seychelles', 'publishrank' ),
			'SL' => __( 'Sierra Leone', 'publishrank' ),
			'SG' => __( 'Singapore', 'publishrank' ),
			'SX' => __( 'Sint Maarten', 'publishrank' ),
			'SK' => __( 'Slovakia', 'publishrank' ),
			'SI' => __( 'Slovenia', 'publishrank' ),
			'SB' => __( 'Solomon Islands', 'publishrank' ),
			'SO' => __( 'Somalia', 'publishrank' ),
			'ZA' => __( 'South Africa', 'publishrank' ),
			'GS' => __( 'South Georgia & South Sandwich Islands', 'publishrank' ),
			'KR' => __( 'South Korea', 'publishrank' ),
			'SS' => __( 'South Sudan', 'publishrank' ),
			'ES' => __( 'Spain', 'publishrank' ),
			'LK' => __( 'Sri Lanka', 'publishrank' ),
			'BL' => __( 'St. Barthélemy', 'publishrank' ),
			'SH' => __( 'St. Helena', 'publishrank' ),
			'KN' => __( 'St. Kitts & Nevis', 'publishrank' ),
			'LC' => __( 'St. Lucia', 'publishrank' ),
			'MF' => __( 'St. Martin', 'publishrank' ),
			'PM' => __( 'St. Pierre & Miquelon', 'publishrank' ),
			'VC' => __( 'St. Vincent & Grenadines', 'publishrank' ),
			'SD' => __( 'Sudan', 'publishrank' ),
			'SR' => __( 'Suriname', 'publishrank' ),
			'SJ' => __( 'Svalbard & Jan Mayen', 'publishrank' ),
			'SE' => __( 'Sweden', 'publishrank' ),
			'CH' => __( 'Switzerland', 'publishrank' ),
			'SY' => __( 'Syria', 'publishrank' ),
			'TW' => __( 'Taiwan', 'publishrank' ),
			'TJ' => __( 'Tajikistan', 'publishrank' ),
			'TZ' => __( 'Tanzania', 'publishrank' ),
			'TH' => __( 'Thailand', 'publishrank' ),
			'TL' => __( 'Timor-Leste', 'publishrank' ),
			'TG' => __( 'Togo', 'publishrank' ),
			'TK' => __( 'Tokelau', 'publishrank' ),
			'TO' => __( 'Tonga', 'publishrank' ),
			'TT' => __( 'Trinidad & Tobago', 'publishrank' ),
			'TN' => __( 'Tunisia', 'publishrank' ),
			'TR' => __( 'Turkey', 'publishrank' ),
			'TM' => __( 'Turkmenistan', 'publishrank' ),
			'TC' => __( 'Turks & Caicos Islands', 'publishrank' ),
			'TV' => __( 'Tuvalu', 'publishrank' ),
			'UM' => __( 'U.S. Outlying Islands', 'publishrank' ),
			'VI' => __( 'U.S. Virgin Islands', 'publishrank' ),
			'UG' => __( 'Uganda', 'publishrank' ),
			'UA' => __( 'Ukraine', 'publishrank' ),
			'AE' => __( 'United Arab Emirates', 'publishrank' ),
			'GB' => __( 'United Kingdom', 'publishrank' ),
			'US' => __( 'United States', 'publishrank' ),
			'UY' => __( 'Uruguay', 'publishrank' ),
			'UZ' => __( 'Uzbekistan', 'publishrank' ),
			'VU' => __( 'Vanuatu', 'publishrank' ),
			'VA' => __( 'Vatican City', 'publishrank' ),
			'VE' => __( 'Venezuela', 'publishrank' ),
			'VN' => __( 'Vietnam', 'publishrank' ),
			'WF' => __( 'Wallis & Futuna', 'publishrank' ),
			'EH' => __( 'Western Sahara', 'publishrank' ),
			'YE' => __( 'Yemen', 'publishrank' ),
			'ZM' => __( 'Zambia', 'publishrank' ),
			'ZW' => __( 'Zimbabwe', 'publishrank' ),
		);
	}
	public static function sanitize( $value ) {
		$code = strtoupper( trim( (string) $value ) );
		if ( 3 === strlen( $code ) ) {
			$code = self::from_alpha3( $code );
		}
		return isset( self::CODES[ $code ] ) ? $code : '';
	}

	/** Alpha-3 code for an alpha-2 code, or ''. */
	public static function alpha3( $alpha2 ) {
		$alpha2 = strtoupper( (string) $alpha2 );
		return isset( self::CODES[ $alpha2 ] ) ? self::CODES[ $alpha2 ][0] : '';
	}

	/** ISO numeric code (three digits) for an alpha-2 code, or ''. */
	public static function numeric( $alpha2 ) {
		$alpha2 = strtoupper( (string) $alpha2 );
		return isset( self::CODES[ $alpha2 ] ) ? self::CODES[ $alpha2 ][1] : '';
	}

	/** Alpha-2 code for an alpha-3 code, or ''. */
	public static function from_alpha3( $alpha3 ) {
		static $index = null;
		if ( null === $index ) {
			$index = array();
			foreach ( self::CODES as $a2 => $c ) {
				$index[ $c[0] ] = $a2;
			}
		}
		$alpha3 = strtoupper( (string) $alpha3 );
		return isset( $index[ $alpha3 ] ) ? $index[ $alpha3 ] : '';
	}

	/** Localised name for a code (alpha-2, alpha-3 or a special code). */
	public static function name( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( isset( self::SPECIAL[ $code ] ) ) {
			return 'XX' === $code ? __( 'Unknown location', 'publishrank' ) : __( 'Tor network', 'publishrank' );
		}
		$a2  = 3 === strlen( $code ) ? self::from_alpha3( $code ) : $code;
		$all = self::all();
		return isset( $all[ $a2 ] ) ? (string) $all[ $a2 ] : $code;
	}

	/**
	 * Full records for pickers, exports and the REST API, sorted by localised name.
	 *
	 * @return array<int,array{code:string,alpha3:string,numeric:string,name:string}>
	 */
	public static function records() {
		$out = array();
		foreach ( self::all() as $a2 => $name ) {
			$out[] = array(
				'code'    => $a2,
				'alpha3'  => self::alpha3( $a2 ),
				'numeric' => self::numeric( $a2 ),
				'name'    => (string) $name,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( remove_accents( $a['name'] ), remove_accents( $b['name'] ) );
			}
		);
		return $out;
	}

	/**
	 * Parse a list of codes (comma/space/newline separated, alpha-2 or alpha-3) into unique alpha-2
	 * codes. Unknown entries are returned separately so screens can report them.
	 *
	 * @param string|array $raw
	 * @param bool         $allow_special Accept T1 (Tor). "Unknown location" is a separate policy, never a list entry.
	 * @return array{valid:string[],invalid:string[]}
	 */
	public static function parse_list( $raw, $allow_special = false ) {
		if ( is_array( $raw ) ) {
			$raw = implode( ',', array_map( 'strval', $raw ) );
		}
		$parts   = preg_split( '/[\s,;]+/', strtoupper( (string) $raw ) );
		$valid   = array();
		$invalid = array();
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( $allow_special && 'T1' === $part ) {
				$valid[ $part ] = true;
				continue;
			}
			$code = self::sanitize( $part );
			if ( '' === $code ) {
				$invalid[] = $part;
			} else {
				$valid[ $code ] = true;
			}
		}
		return array(
			'valid'   => array_slice( array_keys( $valid ), 0, 260 ),
			'invalid' => array_values( array_unique( $invalid ) ),
		);
	}
}
