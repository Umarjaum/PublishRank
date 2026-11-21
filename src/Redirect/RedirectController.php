<?php
/**
 * Runtime redirects (Redirect Engine v2).
 *
 * Indexed exact lookup first, then prefix / wildcard / regex candidates. External targets are
 * followed only for rules marked "allow external" whose host is on the allow-list; everything
 * else goes through wp_safe_redirect(). 410 and 451 answer with a small body and cache headers.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Redirect;

defined( 'ABSPATH' ) || exit;

final class RedirectController {
	public function register() {
		add_action( 'template_redirect', array( $this, 'handle' ), 0 );
		// 2.26.1: a missing uploads file must answer 404 (so it is monitored and diagnosed), not a
		// canonical "trailing slash" redirect to another address that does not exist either.
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical_for_missing_upload' ), 10, 2 );
	}

	/** Path relative to the site root (works for sub-directory installs). */
	public static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised below, only compared.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base = '/' === $base ? '' : untrailingslashit( $base );
		if ( '' !== $base && 0 === stripos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return '/' . ltrim( $path, '/' );
	}

	/**
	 * Whether a request asks for a file under uploads that is not on disk. WordPress can resolve such a path to a
	 * page or attachment instead of a 404 (and then add a trailing slash), so redirects that the Media Link Manager
	 * created for moved files must still be checked for it (2.26.1).
	 */
	public static function is_missing_upload( $path ) {
		$up   = wp_get_upload_dir();
		$base = (string) wp_parse_url( (string) $up['baseurl'], PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = '/' === $home ? '' : untrailingslashit( $home );
		if ( '' !== $home && 0 === strpos( $base, $home ) ) {
			$base = substr( $base, strlen( $home ) );
		}
		$path = untrailingslashit( (string) $path );
		if ( '' === $base || 0 !== strpos( $path, $base . '/' ) || ! preg_match( '/\.[A-Za-z0-9]{2,5}$/', $path ) ) {
			return false;
		}
		$rel = rawurldecode( substr( $path, strlen( $base ) + 1 ) );
		return false === strpos( $rel, '..' ) && ! file_exists( trailingslashit( (string) $up['basedir'] ) . $rel );
	}

	/** @param string|false $redirect @param string $requested */
	public static function no_canonical_for_missing_upload( $redirect, $requested = '' ) {
		return $redirect && self::is_missing_upload( self::request_path() ) ? false : $redirect;
	}

	public function handle() {
		if ( ! get_option( 'publishrank_redirect_enabled', true ) || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( get_option( 'publishrank_redirect_only_404', true ) && ! is_404() && ! self::is_missing_upload( self::request_path() ) ) {
			return;
		}
		if ( ! RedirectStore::v2() ) {
			return; // Storage not migrated yet (first request after the upgrade); the migration runs on init.
		}
		$path  = self::request_path();
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised by query_key().
		$match = RedirectRepository::match( $path, array( 'query' => $query ) );
		if ( ! $match ) {
			self::force_404_for_missing_upload( $path );
			return;
		}
		$rule = $match['rule'];
		if ( empty( $rule['flags']['force'] ) && RedirectRepository::is_protected( $path ) ) {
			return;
		}
		$status = in_array( (int) $rule['status'], RedirectRepository::statuses(), true ) ? (int) $rule['status'] : 301;
		RedirectRepository::record_hit( $rule['id'] );
		if ( in_array( $status, array( 410, 451 ), true ) ) {
			$this->gone( $status );
		}
		$target = $match['target'];
		if ( '' === $target ) {
			return;
		}
		$host     = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
		$external = '' !== $host && $host !== RedirectRepository::home_host();
		if ( ! $external && '' === $host ) {
			$target = home_url( '/' . ltrim( $target, '/' ) );
		}
		if ( ! $external && RedirectRepository::key( $target ) === RedirectRepository::key( $path ) ) {
			return; // Would loop.
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pass-through of public query args.
		$target = RedirectRepository::apply_query( $rule, $target, is_array( $_GET ) ? wp_unslash( $_GET ) : array() );
		if ( get_option( 'publishrank_redirect_x_header', true ) && ! headers_sent() ) {
			header( 'X-Redirect-By: PublishRank' );
		}
		if ( $external ) {
			if ( empty( $rule['flags']['external'] ) || ! RedirectRepository::host_allowed( $host ) ) {
				return; // The allow-list changed after the rule was saved: do nothing rather than redirect anywhere.
			}
			wp_redirect( esc_url_raw( $target ), $status, 'PublishRank' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- host verified against the allow-list.
			exit;
		}
		wp_safe_redirect( $target, $status, 'PublishRank' );
		exit;
	}

	/**
	 * A request for a file in uploads that does not exist sometimes resolves to the home page or an archive (a "soft 404").
	 * Answer a real 404 so search engines drop it and the 404 Monitor can record and diagnose it (2.26.1).
	 */
	private static function force_404_for_missing_upload( $path ) {
		global $wp_query;
		if ( is_404() || ! self::is_missing_upload( $path ) || ! apply_filters( 'publishrank_missing_upload_404', true, $path ) ) {
			return;
		}
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/** Minimal 410 / 451 response with cache headers. */
	private function gone( $status ) {
		status_header( $status );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: public, max-age=' . (int) apply_filters( 'publishrank_gone_max_age', 86400 ) );
			header( 'X-Robots-Tag: noindex' );
			if ( get_option( 'publishrank_redirect_x_header', true ) ) {
				header( 'X-Redirect-By: PublishRank' );
			}
		}
		$title = 410 === $status ? __( 'Gone', 'publishrank' ) : __( 'Unavailable For Legal Reasons', 'publishrank' );
		$text  = 410 === $status ? __( 'This page has been removed permanently.', 'publishrank' ) : __( 'This page is not available for legal reasons.', 'publishrank' );
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'HEAD' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . esc_html( $status . ' ' . $title ) . '</title></head><body><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></p></body></html>';
		}
		exit;
	}
}
