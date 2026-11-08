<?php
/**
 * Shared helpers for third-party plugin integrations.
 *
 * Integrations are registered on plugins_loaded but detect their host plugin lazily
 * (inside hook callbacks), because plugins such as Web Stories load after PublishRank.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Integrations;

use PublishRankSEO\Core\ModuleSwitches;

defined( 'ABSPATH' ) || exit;

abstract class IntegrationBase {
	/** Module switch id from ModuleSwitches. */
	protected $module = '';

	/** Whether the host plugin is active. */
	abstract public function detected();

	/** Host plugin active and module switch on. */
	public function active() {
		return $this->detected() && ModuleSwitches::is_on( $this->module );
	}

	/** Absolute URL of the current request canonical. */
	protected function canonical() {
		if ( class_exists( '\\PublishRankSEO\\Meta\\MetaGenerator' ) ) {
			return ( new \PublishRankSEO\Meta\MetaGenerator() )->canonical();
		}
		return home_url( add_query_arg( array() ) );
	}

	/** Add a robots directive to an existing robots string without duplicates. */
	protected static function robots_add( $robots, $directives ) {
		$parts = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $robots ) ) ) );
		foreach ( (array) $directives as $directive ) {
			$directive = strtolower( trim( $directive ) );
			if ( 'noindex' === $directive ) {
				$parts = array_diff( $parts, array( 'index' ) );
			}
			if ( ! in_array( $directive, $parts, true ) ) {
				$parts[] = $directive;
			}
		}
		return implode( ', ', $parts );
	}

	/** ISO 8601 duration from "hh:mm:ss", "mm:ss" or seconds. */
	public static function iso_duration( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ctype_digit( $raw ) ) {
			$seconds = (int) $raw;
		} else {
			$bits    = array_reverse( array_map( 'intval', explode( ':', $raw ) ) );
			$seconds = ( $bits[0] ?? 0 ) + 60 * ( $bits[1] ?? 0 ) + 3600 * ( $bits[2] ?? 0 );
		}
		if ( $seconds <= 0 ) {
			return '';
		}
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;
		return 'PT' . ( $h ? $h . 'H' : '' ) . ( $m ? $m . 'M' : '' ) . ( $s || ( ! $h && ! $m ) ? $s . 'S' : '' );
	}
}
