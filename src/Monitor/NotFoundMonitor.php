<?php
/**
 * 404 Monitor v2 — capture. Storage, classification and sampling live in NotFoundStore.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Monitor;

defined( 'ABSPATH' ) || exit;

final class NotFoundMonitor {
	/** Kept for backwards compatibility with code that referenced the 2.22 option name. */
	const OPTION         = NotFoundStore::LEGACY_OPTION;
	const MAX_PATH_LEN   = 512;
	const RETENTION_DAYS = 90;

	public function register() {
		add_action( 'template_redirect', array( $this, 'capture' ), 1 );
		NotFoundStore::register_cron();
		add_action(
			'admin_init',
			static function () {
				if ( ! get_option( NotFoundStore::MIGRATED_OPT ) ) {
					NotFoundStore::migrate_legacy();
				}
			}
		);
		add_action( 'admin_init', array( $this, 'privacy_text' ) );
	}

	public function capture() {
		if ( ! NotFoundStore::enabled() || ! is_404() || wp_doing_ajax() || is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$path = NotFoundStore::normalise_path( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised and sanitised.
		if ( '' === $path || preg_match( '/\.(?:css|js|map|woff2?|ttf|eot)$/i', $path ) ) {
			return; // Missing theme assets are not editorial 404s.
		}
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		NotFoundStore::record( $path, $ua, $ref );
	}

	/** Suggested privacy-policy text (Settings → Privacy). */
	public function privacy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'PublishRank',
			'<p>' . esc_html__( 'When an address on this site cannot be found, PublishRank may record the missing address, the time, the website that linked to it (host and path only, without query strings) and a general visitor type (person, phone, search crawler, AI crawler or automated scanner). IP addresses and full browser identifiers are not stored. These records are deleted automatically after the retention period set by the site owner.', 'publishrank' ) . '</p>'
		);
	}
}
