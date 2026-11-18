<?php
/**
 * Traffic Guard custom rules: validation, ordering and matching.
 *
 * A rule is: name, enabled, mode (enforce|test), priority (1–999, lowest runs first), match (all|any),
 * action (block|temp_block|allow|log), minutes (temp_block only) and up to 10 conditions. A condition
 * is field + operator + value. Fields: ip, country, path, query, user_agent, referer, method, bot.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Security;

use PublishRankSEO\Core\Countries;

defined( 'ABSPATH' ) || exit;

final class TrafficGuardRules {
	const MAX_CONDITIONS = 10;

	/** Operators allowed per field. @return array<string,string[]> */
	public static function fields() {
		return array(
			'ip'         => array( 'in', 'not_in' ),
			'country'    => array( 'in', 'not_in' ),
			'path'       => array( 'equals', 'starts_with', 'ends_with', 'contains', 'not_contains', 'matches' ),
			'query'      => array( 'contains', 'not_contains', 'matches' ),
			'user_agent' => array( 'contains', 'not_contains', 'matches', 'equals' ),
			'referer'    => array( 'contains', 'not_contains', 'matches', 'equals' ),
			'method'     => array( 'in', 'not_in' ),
			'bot'        => array( 'in', 'not_in' ),
		);
	}

	public static function field_labels() {
		return array(
			'ip'         => __( 'IP address or network', 'publishrank' ),
			'country'    => __( 'Country', 'publishrank' ),
			'path'       => __( 'Page path', 'publishrank' ),
			'query'      => __( 'Query string', 'publishrank' ),
			'user_agent' => __( 'User agent', 'publishrank' ),
			'referer'    => __( 'Referrer', 'publishrank' ),
			'method'     => __( 'HTTP method', 'publishrank' ),
			'bot'        => __( 'Visitor type', 'publishrank' ),
		);
	}

	public static function operator_labels() {
		return array(
			'in'           => __( 'is one of', 'publishrank' ),
			'not_in'       => __( 'is not one of', 'publishrank' ),
			'equals'       => __( 'equals', 'publishrank' ),
			'starts_with'  => __( 'starts with', 'publishrank' ),
			'ends_with'    => __( 'ends with', 'publishrank' ),
			'contains'     => __( 'contains', 'publishrank' ),
			'not_contains' => __( 'does not contain', 'publishrank' ),
			'matches'      => __( 'matches pattern', 'publishrank' ),
		);
	}

	public static function action_labels() {
		return array(
			'block'      => __( 'Block', 'publishrank' ),
			'temp_block' => __( 'Block temporarily', 'publishrank' ),
			'allow'      => __( 'Always allow', 'publishrank' ),
			'log'        => __( 'Log only', 'publishrank' ),
		);
	}

	public static function action_label( $action ) {
		$l = self::action_labels();
		return isset( $l[ $action ] ) ? $l[ $action ] : (string) $action;
	}

	/**
	 * Validate one rule. Returns the clean rule plus a list of problems (empty when valid).
	 *
	 * @return array{rule:array,errors:string[]}
	 */
	public static function sanitize( array $in ) {
		$errors = array();
		$id     = isset( $in['id'] ) && preg_match( '/^[a-z0-9]{6,32}$/', (string) $in['id'] ) ? (string) $in['id'] : strtolower( wp_generate_password( 12, false, false ) );
		$rule   = array(
			'id'         => $id,
			'name'       => isset( $in['name'] ) ? substr( sanitize_text_field( (string) $in['name'] ), 0, 80 ) : '',
			'enabled'    => ! empty( $in['enabled'] ),
			'mode'       => isset( $in['mode'] ) && 'enforce' === $in['mode'] ? 'enforce' : 'test',
			'priority'   => isset( $in['priority'] ) ? max( 1, min( 999, (int) $in['priority'] ) ) : 100,
			'match'      => isset( $in['match'] ) && 'any' === $in['match'] ? 'any' : 'all',
			'action'     => isset( $in['action'] ) && array_key_exists( (string) $in['action'], self::action_labels() ) ? (string) $in['action'] : 'block',
			'minutes'    => isset( $in['minutes'] ) ? max( 1, min( 10080, (int) $in['minutes'] ) ) : 60,
			'note'       => isset( $in['note'] ) ? substr( sanitize_text_field( (string) $in['note'] ), 0, 200 ) : '',
			'conditions' => array(),
			'created'    => isset( $in['created'] ) ? (int) $in['created'] : time(),
		);
		if ( '' === $rule['name'] ) {
			$rule['name'] = __( 'Untitled rule', 'publishrank' );
		}
		$fields = self::fields();
		foreach ( array_slice( isset( $in['conditions'] ) && is_array( $in['conditions'] ) ? array_values( $in['conditions'] ) : array(), 0, self::MAX_CONDITIONS ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$field = isset( $c['field'] ) ? (string) $c['field'] : '';
			$op    = isset( $c['op'] ) ? (string) $c['op'] : '';
			$raw   = isset( $c['value'] ) ? ( is_array( $c['value'] ) ? implode( ',', array_map( 'strval', $c['value'] ) ) : (string) $c['value'] ) : '';
			if ( '' === trim( $raw ) && '' === $field ) {
				continue; // Empty row in the builder.
			}
			if ( ! isset( $fields[ $field ] ) || ! in_array( $op, $fields[ $field ], true ) ) {
				$errors[] = __( 'A condition uses an unknown field or operator.', 'publishrank' );
				continue;
			}
			$value = self::clean_value( $field, $op, $raw, $errors );
			if ( null === $value ) {
				continue;
			}
			$rule['conditions'][] = array( 'field' => $field, 'op' => $op, 'value' => $value );
		}
		if ( ! $rule['conditions'] ) {
			$errors[] = __( 'Add at least one valid condition.', 'publishrank' );
		}
		return array( 'rule' => $rule, 'errors' => array_values( array_unique( $errors ) ) );
	}

	/** @return string|string[]|null */
	private static function clean_value( $field, $op, $raw, array &$errors ) {
		$raw = trim( wp_unslash( $raw ) );
		switch ( $field ) {
			case 'ip':
				$r = TrafficGuard::inspect_networks( $raw );
				if ( $r['invalid'] || ! $r['valid'] ) {
					$errors[] = sprintf( /* translators: %s: invalid entries */ __( 'Invalid IP addresses or networks: %s', 'publishrank' ), implode( ', ', array_slice( $r['invalid'], 0, 5 ) ) );
					return null;
				}
				return $r['valid'];
			case 'country':
				$r = Countries::parse_list( $raw, true );
				if ( $r['invalid'] || ! $r['valid'] ) {
					$errors[] = sprintf( /* translators: %s: invalid codes */ __( 'Unknown country codes: %s', 'publishrank' ), implode( ', ', array_slice( $r['invalid'], 0, 5 ) ) );
					return null;
				}
				return $r['valid'];
			case 'method':
				$list = array_values( array_intersect( array_map( 'strtoupper', preg_split( '/[\s,]+/', $raw ) ), array( 'GET', 'POST', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ) ) );
				if ( ! $list ) {
					$errors[] = __( 'Enter HTTP methods such as GET or POST.', 'publishrank' );
					return null;
				}
				return array_values( array_unique( $list ) );
			case 'bot':
				$list = array_values( array_intersect( preg_split( '/[\s,]+/', strtolower( $raw ) ), array_keys( TrafficGuard::bot_labels() ) ) );
				if ( ! $list ) {
					$errors[] = __( 'Choose at least one visitor type.', 'publishrank' );
					return null;
				}
				return array_values( array_unique( $list ) );
		}
		$raw = substr( sanitize_text_field( $raw ), 0, 200 );
		if ( '' === $raw ) {
			$errors[] = __( 'A condition has no value.', 'publishrank' );
			return null;
		}
		if ( 'matches' === $op && ! self::valid_pattern( $raw ) ) {
			$errors[] = sprintf( /* translators: %s: pattern */ __( 'The pattern “%s” is not a valid regular expression.', 'publishrank' ), $raw );
			return null;
		}
		return $raw;
	}

	/** A user pattern is wrapped in ~…~i; it must compile and must not take long on a sample. */
	public static function valid_pattern( $pattern ) {
		$re = self::regex( $pattern );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a compile failure is the answer.
		return false !== @preg_match( $re, str_repeat( 'a', 256 ) ) && PREG_NO_ERROR === preg_last_error();
	}

	private static function regex( $pattern ) {
		return '~' . str_replace( '~', '\\~', (string) $pattern ) . '~i';
	}

	/** Validate a list of stored rules; invalid ones are dropped. */
	public static function sanitize_all( array $rules ) {
		$out = array();
		foreach ( array_slice( $rules, 0, TrafficGuard::MAX_CUSTOM_RULES ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$r = self::sanitize( $rule );
			if ( $r['rule']['conditions'] ) {
				$out[ $r['rule']['id'] ] = $r['rule'];
			}
		}
		return $out;
	}

	/** Rules sorted by priority, then age. */
	public static function ordered( array $rules ) {
		$rules = array_values( $rules );
		usort(
			$rules,
			static function ( $a, $b ) {
				return array( (int) $a['priority'], (int) $a['created'] ) <=> array( (int) $b['priority'], (int) $b['created'] );
			}
		);
		return $rules;
	}

	/** Does the request match a rule? */
	public static function matches( array $rule, array $ctx ) {
		if ( empty( $rule['conditions'] ) ) {
			return false;
		}
		$any = 'any' === $rule['match'];
		foreach ( $rule['conditions'] as $c ) {
			$hit = self::condition( $c, $ctx );
			if ( $any && $hit ) {
				return true;
			}
			if ( ! $any && ! $hit ) {
				return false;
			}
		}
		return ! $any;
	}

	private static function condition( array $c, array $ctx ) {
		$field = $c['field'];
		$op    = $c['op'];
		$value = $c['value'];
		$have  = isset( $ctx[ $field ] ) ? (string) $ctx[ $field ] : '';
		switch ( $field ) {
			case 'ip':
				$hit = '' !== $have && TrafficGuard::ip_matches_list( $have, (array) $value );
				return 'in' === $op ? $hit : ! $hit;
			case 'country':
			case 'method':
			case 'bot':
				$hit = '' !== $have && in_array( strtoupper( $have ), array_map( 'strtoupper', (array) $value ), true );
				return 'in' === $op ? $hit : ! $hit;
		}
		$h = strtolower( $have );
		$v = strtolower( (string) $value );
		switch ( $op ) {
			case 'equals':
				return $h === $v;
			case 'starts_with':
				return '' !== $v && 0 === strpos( $h, $v );
			case 'ends_with':
				return '' !== $v && substr( $h, -strlen( $v ) ) === $v;
			case 'contains':
				return '' !== $v && false !== strpos( $h, $v );
			case 'not_contains':
				return '' === $v || false === strpos( $h, $v );
			case 'matches':
				return 1 === @preg_match( self::regex( (string) $value ), $have ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validated at save time.
		}
		return false;
	}

	/** Hits, last match and false positives for every rule, derived from the event log (no extra writes). */
	public static function stats() {
		return TrafficGuardStore::rule_stats();
	}
}
