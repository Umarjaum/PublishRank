<?php
/**
 * Keeps masked front-end code talking to the real theme on the server.
 *
 * When identifiers are rewritten, the theme's JavaScript sends masked AJAX action names, wc-ajax endpoints,
 * REST routes, request keys and cookie names. This maps them back before WordPress dispatches the request, and
 * re-masks HTML/selectors in the response so AJAX-loaded markup matches the masked stylesheets.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility\Masking;

defined( 'ABSPATH' ) || exit;

final class RequestMapper {

	/** @var bool */
	private static $mapped = false;

	public static function register( array $cfg ) {
		if ( empty( $cfg['identifiers'] ) || empty( $cfg['reverse'] ) ) {
			if ( ! empty( $cfg['rest'] ) ) {
				add_filter( 'rest_index', array( __CLASS__, 'rest_index' ), 99 );
			}
			return;
		}
		self::map_request( $cfg );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_route' ), 5, 3 );
		if ( ! empty( $cfg['rest'] ) ) {
			add_filter( 'rest_index', array( __CLASS__, 'rest_index' ), 99 );
		}
		$ajax = ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || isset( $_GET['wc-ajax'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $ajax && self::from_front_end() ) {
			ob_start(
				static function ( $out ) use ( $cfg ) {
					return RequestMapper::mask_response( (string) $out, $cfg );
				}
			);
		}
	}

	private static function from_front_end() {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' === $ref ? self::$mapped : false === strpos( $ref, '/wp-admin/' );
	}

	/** Reverse a masked string (neutral → original). */
	public static function unmask( $s, array $cfg ) {
		return strtr( (string) $s, (array) $cfg['reverse'] );
	}

	private static function has_token( $s, array $cfg ) {
		foreach ( array_keys( (array) $cfg['reverse'] ) as $t ) {
			if ( false !== strpos( (string) $s, (string) $t ) ) {
				return true;
			}
		}
		return false;
	}

	/** Map request keys, cookie names and the action / wc-ajax values back to the real identifiers. */
	public static function map_request( array $cfg ) {
		// phpcs:disable WordPress.Security.NonceVerification
		foreach ( array( '_GET', '_POST', '_REQUEST', '_COOKIE' ) as $g ) {
			if ( empty( $GLOBALS[ $g ] ) || ! is_array( $GLOBALS[ $g ] ) ) {
				continue;
			}
			$new = array();
			foreach ( $GLOBALS[ $g ] as $k => $v ) {
				$nk = is_string( $k ) && self::has_token( $k, $cfg ) ? self::unmask( $k, $cfg ) : $k;
				if ( $nk !== $k ) {
					self::$mapped = true;
				}
				if ( in_array( $nk, array( 'action', 'wc-ajax' ), true ) && is_string( $v ) && self::has_token( $v, $cfg ) ) {
					$v            = self::unmask( $v, $cfg );
					self::$mapped = true;
				}
				$new[ $nk ] = $v;
			}
			$GLOBALS[ $g ] = $new;
		}
		// phpcs:enable
	}

	/** Re-dispatch REST requests whose route was masked. */
	public static function rest_route( $result, $server, $request ) {
		$cfg   = Config::get();
		$route = $request->get_route();
		if ( null !== $result || ! self::has_token( $route, $cfg ) ) {
			return $result;
		}
		$real = new \WP_REST_Request( $request->get_method(), self::unmask( $route, $cfg ) );
		$real->set_query_params( (array) $request->get_query_params() );
		$real->set_body_params( (array) $request->get_body_params() );
		$real->set_headers( (array) $request->get_headers() );
		$real->set_body( (string) $request->get_body() );
		$real->set_url_params( (array) $request->get_url_params() );
		$response = $server->dispatch( $real );
		if ( $response instanceof \WP_REST_Response ) {
			$response->set_data( Rewriter::payload( $response->get_data(), $cfg ) );
		}
		return $response;
	}

	/** Hide theme namespaces from the public REST index. */
	public static function rest_index( $response ) {
		if ( ! $response instanceof \WP_REST_Response || current_user_can( 'edit_theme_options' ) ) {
			return $response;
		}
		$cfg  = Config::get();
		$data = $response->get_data();
		$bad  = static function ( $s ) use ( $cfg ) {
			$s = strtolower( (string) $s );
			foreach ( (array) $cfg['pairs'] as $p ) {
				if ( 'word' === $p[2] && false !== strpos( $s, strtolower( $p[0] ) ) ) {
					return true;
				}
			}
			return false;
		};
		if ( isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
			$data['namespaces'] = array_values( array_filter( $data['namespaces'], static function ( $n ) use ( $bad ) { return ! $bad( $n ); } ) );
		}
		if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
			foreach ( array_keys( $data['routes'] ) as $r ) {
				if ( $bad( $r ) ) {
					unset( $data['routes'][ $r ] );
				}
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/** Mask an AJAX response body (JSON or HTML fragment). */
	public static function mask_response( $out, array $cfg ) {
		$trim = ltrim( $out );
		if ( '' === $trim ) {
			return $out;
		}
		if ( '{' === $trim[0] || '[' === $trim[0] ) {
			$data = json_decode( $out, true );
			if ( null !== $data ) {
				$enc = wp_json_encode( Rewriter::payload( $data, $cfg ) );
				return false === $enc ? $out : $enc;
			}
			return $out;
		}
		if ( false !== strpos( $out, '<' ) ) {
			return Rewriter::markup( Rewriter::urls( $out, $cfg ), $cfg );
		}
		return $out;
	}
}
