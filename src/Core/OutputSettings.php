<?php
/**
 * Single reading point for the front-end output switches.
 *
 * Each switch controls exactly one kind of output and is independent of the others:
 *  - publishrank_meta_enabled                 title, description, canonical, robots, Open Graph, X/Twitter
 *  - publishrank_schema_enabled               the JSON-LD graph as a whole (master switch)
 *  - publishrank_org_website_schema_enabled   Organization/Person and WebSite nodes inside that graph
 *  - publishrank_breadcrumbs_enabled          the visible breadcrumb trail printed by PublishRank
 *  - publishrank_breadcrumb_schema_enabled    BreadcrumbList node inside the JSON-LD graph
 *
 * A switch is ON when it was never saved (fresh installs and upgrades from releases that lack the option) and OFF
 * only when an administrator saved a false-like value, so explicit choices always win over defaults.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class OutputSettings {
	const META        = 'publishrank_meta_enabled';
	const SCHEMA      = 'publishrank_schema_enabled';
	const ORG_WEBSITE = 'publishrank_org_website_schema_enabled';
	const BREADCRUMBS = 'publishrank_breadcrumbs_enabled';
	const BREADCRUMB_SCHEMA = 'publishrank_breadcrumb_schema_enabled';
	/** Not an output switch (not in keys()): lets PublishRank's separator replace the separator of other breadcrumb renderers. Default on. */
	const BREADCRUMB_OVERRIDE = 'publishrank_breadcrumb_frontend_override';

	/** @return string[] Every output switch with its default (all on). */
	public static function keys() {
		return array( self::META, self::SCHEMA, self::ORG_WEBSITE, self::BREADCRUMBS, self::BREADCRUMB_SCHEMA );
	}

	/**
	 * Whether a switch is on. Accepts the values WordPress and imports produce: 1, "1", true, "yes" mean on;
	 * 0, "0", "", false, "false", "no", "off" mean off. A missing option means the default (on).
	 *
	 * @param string $option  Option name.
	 * @param bool   $default Value when the option row does not exist.
	 * @return bool
	 */
	public static function is_on( $option, $default = true ) {
		$sentinel = new \stdClass();
		$value    = get_option( $option, $sentinel );
		if ( $sentinel === $value ) {
			return (bool) $default;
		}
		return self::is_on_value( $value );
	}

	/** Interpret a stored or imported raw value as on/off. */
	public static function is_on_value( $value ) {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value, " \t\n\r\0\x0B" ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}
		return (bool) $value;
	}

	public static function meta() {
		return self::is_on( self::META );
	}

	public static function schema() {
		return self::is_on( self::SCHEMA );
	}

	public static function org_website_schema() {
		return self::is_on( self::ORG_WEBSITE );
	}

	public static function visible_breadcrumbs() {
		return self::is_on( self::BREADCRUMBS );
	}

	public static function breadcrumb_override() {
		return self::is_on( self::BREADCRUMB_OVERRIDE );
	}

	public static function breadcrumb_schema() {
		return self::is_on( self::BREADCRUMB_SCHEMA );
	}
}
