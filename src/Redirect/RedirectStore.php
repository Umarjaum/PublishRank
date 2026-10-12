<?php
/** Storage and validation for redirect rules. */
namespace PublishRankSEO\Redirect;

defined( 'ABSPATH' ) || exit;

final class RedirectStore {
	const OPTION    = 'publishrank_seo_redirects';
	const MAX_RULES = 2000;

	/** @return int[] */
	public static function statuses() {
		return array( 301, 302, 303, 307, 308, 410, 451 );
	}

	/** @return array<int,array<string,mixed>> */
	public static function all() {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? array_values( array_filter( $rules, 'is_array' ) ) : array();
	}

	/** @param array<int,array<string,mixed>> $rules Rules to store. */
	public static function save( array $rules ) {
		update_option( self::OPTION, array_slice( array_values( $rules ), 0, self::MAX_RULES ), false );
	}

	/** Stable identifier for a rule. */
	public static function id( array $rule ) {
		return md5( (string) ( isset( $rule['source'] ) ? $rule['source'] : '' ) . '|' . ( empty( $rule['regex'] ) ? '0' : '1' ) );
	}

	/** Same path normalisation as the runtime redirect controller. */
	public static function normalise_path( $value ) {
		$parsed = wp_parse_url( trim( (string) $value ), PHP_URL_PATH );
		return untrailingslashit( '/' . ltrim( (string) $parsed, '/' ) );
	}

	public static function regex_is_valid( $pattern ) {
		$pattern = (string) $pattern;
		if ( '' === $pattern ) {
			return false;
		}
		if ( 0 !== strpos( $pattern, '~' ) ) {
			$pattern = '~' . str_replace( '~', '\\~', $pattern ) . '~';
		}
		return false !== @preg_match( $pattern, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/** Targets must be a path on this site or a full URL on this site's host. */
	public static function safe_target( $target ) {
		$target = esc_url_raw( trim( (string) $target ) );
		if ( '' === $target ) {
			return '';
		}
		$host = wp_parse_url( $target, PHP_URL_HOST );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( $host && ( ! $home || strtolower( $host ) !== strtolower( $home ) ) ) {
			return '';
		}
		return $target;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function build_rule( $source, $target, $status = 301, $regex = false ) {
		$status = (int) $status;
		if ( ! in_array( $status, self::statuses(), true ) ) {
			$status = 301;
		}
		$source = trim( (string) $source );
		if ( '' === $source ) {
			return new \WP_Error( 'publishrank_redirect_source', __( 'Enter the old URL or path.', 'publishrank' ) );
		}
		if ( $regex ) {
			$source = function_exists( 'mb_substr' ) ? mb_substr( $source, 0, 255 ) : substr( $source, 0, 255 );
			if ( ! self::regex_is_valid( $source ) ) {
				return new \WP_Error( 'publishrank_redirect_regex', __( 'The regular expression is not valid.', 'publishrank' ) );
			}
		} else {
			$source = self::normalise_path( $source );
			if ( '' === $source ) {
				return new \WP_Error( 'publishrank_redirect_home', __( 'The homepage cannot be redirected.', 'publishrank' ) );
			}
		}
		if ( in_array( $status, array( 410, 451 ), true ) ) {
			$target = '';
		} else {
			$target = self::safe_target( $target );
			if ( '' === $target ) {
				return new \WP_Error( 'publishrank_redirect_target', __( 'Enter a target on this site: a path such as /new-page or a full URL on your own domain.', 'publishrank' ) );
			}
			if ( ! $regex && self::normalise_path( $target ) === $source ) {
				return new \WP_Error( 'publishrank_redirect_loop', __( 'The target is the same as the source, which would create a redirect loop.', 'publishrank' ) );
			}
		}
		return array(
			'source' => $source,
			'target' => $target,
			'status' => $status,
			'regex'  => $regex ? 1 : 0,
			'enabled' => 1,
			'preserve_query' => 1,
			'note' => '',
		);
	}

	/** Add a rule, or replace the rule that has the same source. @return true|\WP_Error */
	public static function add( $source, $target, $status = 301, $regex = false ) {
		$rule = self::build_rule( $source, $target, $status, $regex );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$rules = self::all();
		foreach ( $rules as $index => $existing ) {
			if ( self::id( $existing ) === self::id( $rule ) ) {
				$rules[ $index ] = $rule;
				self::save( $rules );
				return true;
			}
		}
		if ( count( $rules ) >= self::MAX_RULES ) {
			return new \WP_Error( 'publishrank_redirect_limit', __( 'The redirect limit has been reached. Remove unused rules first.', 'publishrank' ) );
		}
		$rules[] = $rule;
		self::save( $rules );
		return true;
	}

	/** @return bool Whether a rule was removed. */
	public static function delete( $id ) {
		$rules = self::all();
		$kept  = array();
		foreach ( $rules as $rule ) {
			if ( self::id( $rule ) !== (string) $id ) {
				$kept[] = $rule;
			}
		}
		if ( count( $kept ) === count( $rules ) ) {
			return false;
		}
		self::save( $kept );
		return true;
	}

	/**
	 * Parse "source target [status]" lines. Pure string handling; plain paths only.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function parse_bulk( $text ) {
		$rows = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$parts  = preg_split( '/[\s,]+/', $line, -1, PREG_SPLIT_NO_EMPTY );
			$status = 301;
			$target = '';
			if ( 2 === count( $parts ) && in_array( $parts[1], array( '410', '451' ), true ) ) {
				$status = 410;
			} elseif ( count( $parts ) >= 2 ) {
				$target = $parts[1];
				if ( isset( $parts[2] ) && ctype_digit( $parts[2] ) ) {
					$status = (int) $parts[2];
				}
			}
			$rows[] = array( 'source' => $parts[0], 'target' => $target, 'status' => $status );
		}
		return $rows;
	}
}
