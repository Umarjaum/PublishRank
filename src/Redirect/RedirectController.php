<?php
/** Validated, loop-safe redirects without touching rewrite files. */
namespace PublishRankSEO\Redirect;

final class RedirectController {
	public function register() { add_action( 'template_redirect', array( $this, 'handle' ), 0 ); }

	public function handle() {
		if ( ! get_option( 'publishrank_redirect_enabled', true ) ) { return; }
		if ( get_option( 'publishrank_redirect_only_404', true ) && ! is_404() ) { return; }
		if ( empty( $GLOBALS['wp']->request ) ) { return; }
		$path = $this->normalise_path( (string) $GLOBALS['wp']->request );
		$rules = get_option( 'publishrank_seo_redirects', array() );
		if ( ! is_array( $rules ) ) { return; }
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['enabled'] ) || ! $this->matches( $rule, $path ) ) { continue; }
			$status = (int) ( $rule['status'] ?? 301 );
			$status = in_array( $status, array( 301, 302, 303, 307, 308, 410, 451 ), true ) ? $status : 301;
			if ( in_array( $status, array( 410, 451 ), true ) ) { status_header( $status ); nocache_headers(); exit; }
			$target = $this->safe_target( (string) ( $rule['target'] ?? '' ) );
			if ( '' === $target || $this->is_loop( $path, $target ) ) { return; }
			if ( get_option( 'publishrank_redirect_preserve_query', true ) && ! empty( $_SERVER['QUERY_STRING'] ) ) { $target = add_query_arg( wp_parse_args( (string) wp_unslash( $_SERVER['QUERY_STRING'] ) ), $target ); }
			if ( get_option( 'publishrank_redirect_x_header', true ) ) { header( 'X-Redirect-By: PublishRank' ); }
			wp_safe_redirect( $target, $status ); exit;
		}
	}

	private function matches( $rule, $path ) {
		$source = (string) ( $rule['source'] ?? '' );
		if ( ! empty( $rule['regex'] ) ) {
			$pattern = $source;
			if ( 0 !== strpos( $pattern, '~' ) ) { $pattern = '~' . str_replace( '~', '\\~', $pattern ) . '~'; }
			$result = @preg_match( $pattern, $path );
			return 1 === $result;
		}
		return $this->normalise_path( $source ) === $path;
	}

	private function safe_target( $target ) {
		$target = esc_url_raw( trim( $target ) );
		if ( ! $target ) { return ''; }
		$host = wp_parse_url( $target, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return ( ! $host || ( $home_host && strtolower( $host ) === strtolower( $home_host ) ) ) && wp_validate_redirect( $target, '' ) ? $target : '';
	}

	private function normalise_path( $value ) {
		$parsed = wp_parse_url( trim( $value ), PHP_URL_PATH );
		return untrailingslashit( '/' . ltrim( (string) $parsed, '/' ) );
	}

	private function is_loop( $source, $target ) {
		$rules = get_option( 'publishrank_seo_redirects', array() ); if ( ! is_array( $rules ) ) { return false; }
		$edges = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! empty( $rule['regex'] ) ) { continue; }
			$from = $this->normalise_path( (string) ( $rule['source'] ?? '' ) ); $to = $this->normalise_path( (string) ( $rule['target'] ?? '' ) );
			if ( '/' !== $from && '' !== $to ) { $edges[ $from ] = $to; }
		}
		$current = $this->normalise_path( $target ); $seen = array( $source => true );
		for ( $depth = 0; $depth < min( 50, max( 1, absint( get_option( 'publishrank_redirect_max_hops', 10 ) ) ) ) && isset( $edges[ $current ] ); $depth++ ) {
			if ( isset( $seen[ $current ] ) ) { return true; }
			$seen[ $current ] = true; $current = $edges[ $current ];
		}
		return isset( $seen[ $current ] );
	}
}
