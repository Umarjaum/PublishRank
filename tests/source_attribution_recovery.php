<?php
/** Regression test for provenance recovery when a theme omits wp_head(). */
namespace PublishRankSEO\Core {
	final class OutputRegistry {
		public function has( $slot ) { return false; }
	}
}
namespace {
	if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) { exit; }
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	define( 'PUBLISHRANK_SEO_VERSION', '2.9.55' );
	$GLOBALS['pr_recovery_hooks'] = array();
	function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['pr_recovery_hooks'][ $hook ] = $callback; }
	function add_filter( $hook, $callback, $priority = 10 ) { $GLOBALS['pr_recovery_hooks'][ $hook ] = $callback; }
	function is_admin() { return false; }
	function wp_doing_ajax() { return false; }
	function wp_doing_cron() { return false; }
	function is_feed() { return false; }
	function is_robots() { return false; }
	function is_embed() { return false; }
	function is_trackback() { return false; }
	function get_query_var( $key ) { return ''; }
	function get_option( $key, $default = false ) { return false; }
	function did_action( $hook ) { return 'template_redirect' === $hook ? 1 : 0; }
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }

	require dirname( __DIR__ ) . '/src/Security/TraceIntegrity.php';
	require dirname( __DIR__ ) . '/src/Meta/FrontendOutputRecovery.php';
	$recovery = new \PublishRankSEO\Meta\FrontendOutputRecovery( new \PublishRankSEO\Core\OutputRegistry() );
	$recovery->prepare_payload();
	$tests = 0;
	$failed = 0;
	function recovery_check( $condition, $label ) {
		global $tests, $failed;
		++$tests;
		if ( $condition ) { echo "PASS {$label}\n"; }
		else { echo "FAIL {$label}\n"; ++$failed; }
	}

	$html = '<!doctype html><html><head><title>Example</title></head><body></body></html>';
	$out = $recovery->inject( $html );
	recovery_check( false !== strpos( $out, '<!-- PublishRank provenance:' ), 'provenance is recovered when wp_head output is absent' );
	recovery_check( false !== strpos( $out, 'name="publishrank-brand" content="PublishRank"' ), 'brand meta is recovered into head' );
	recovery_check( false !== strpos( $out, 'name="publishrank-version" content="2.9.55"' ), 'version meta is recovered into head' );

	$recovery2 = new \PublishRankSEO\Meta\FrontendOutputRecovery( new \PublishRankSEO\Core\OutputRegistry() );
	$recovery2->prepare_payload();
	$html_with_seo = '<html><head><!-- SEO metadata output begin --><title>Example</title></head><body></body></html>';
	$out_with_seo = $recovery2->inject( $html_with_seo );
	recovery_check( false !== strpos( $out_with_seo, '<!-- PublishRank provenance:' ), 'provenance is injected even when SEO metadata is already present' );
	recovery_check( 1 === substr_count( $out_with_seo, '<!-- PublishRank provenance:' ), 'provenance is not duplicated by recovery' );
	echo "\nRecovery checks: {$tests} run, {$failed} failed.\n";
	exit( $failed ? 1 : 0 );
}
