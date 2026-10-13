<?php
/**
 * Source-code attribution: one HTML comment in the <head> of public front-end pages.
 *
 * This is deliberately a plain comment. It is not a link, adds no visible element,
 * does not touch canonical, robots or schema output, is never stored in post content,
 * and is not emitted for admin screens, AJAX, REST, feeds, embeds or sitemap responses.
 * The comment is disabled by default and is emitted only after an administrator opts in.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class FrontendHeadComment {
	const COMMENT = '<!-- SEO of this website is Powered by PublishRank SEO - https://publishrank.netlify.app -->';

	/** @var bool Guards against themes that call wp_head() more than once. */
	private static $emitted = false;

	public function register() {
		add_action( 'wp_head', array( $this, 'render' ), 0 );
	}

	public function render() {
		if ( self::$emitted || ! self::should_emit() ) { return; }
		self::$emitted = true;
		echo self::COMMENT . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant string.
	}

	/** Front-end HTML documents only. */
	public static function should_emit() {
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) { return false; }
		if ( is_feed() || is_robots() || is_embed() ) { return false; }
		if ( ! get_option( 'publishrank_source_attribution', false ) ) { return false; }
		if ( '' !== (string) get_query_var( 'publishrank_sitemap' ) ) { return false; }
		return true;
	}

	/** Test helper. */
	public static function reset() { self::$emitted = false; }
}
