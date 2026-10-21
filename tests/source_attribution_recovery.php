<?php
/** Regression test for SEO recovery when a theme omits wp_head(), including optional attribution. */
namespace PublishRankSEO\Core {
	if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
	final class OutputRegistry {
		public function has( $slot ) { return false; }
	}
}
namespace {
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	define( 'PUBLISHRANK_SEO_VERSION', '2.9.58' );
	$GLOBALS['pr_recovery_hooks'] = array();
	$GLOBALS['pr_recovery_options'] = array();
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
	function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['pr_recovery_options'] ) ? $GLOBALS['pr_recovery_options'][ $key ] : $default; }
	function did_action( $hook ) { return 'template_redirect' === $hook ? 1 : 0; }
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }

	require dirname( __DIR__ ) . '/src/Security/TraceIntegrity.php';
	require dirname( __DIR__ ) . '/src/Meta/FrontendOutputRecovery.php';
	$tests = 0;
	$failed = 0;
	function recovery_check( $condition, $label ) {
		global $tests, $failed;
		++$tests;
		if ( $condition ) { fwrite( STDOUT, "PASS {$label}\n" ); }
		else { fwrite( STDOUT, "FAIL {$label}\n" ); ++$failed; }
	}

	$html = '<!doctype html><html><head><title>Example</title></head><body></body></html>';
	$default_recovery = new \PublishRankSEO\Meta\FrontendOutputRecovery( new \PublishRankSEO\Core\OutputRegistry() );
	$default_recovery->prepare_payload();
	$default_output = $default_recovery->inject( $html );
	recovery_check( false === strpos( $default_output, 'PublishRank provenance:' ), 'recovery does not add source attribution by default' );

	$GLOBALS['pr_recovery_options']['publishrank_source_attribution'] = 1;
	$recovery = new \PublishRankSEO\Meta\FrontendOutputRecovery( new \PublishRankSEO\Core\OutputRegistry() );
	$recovery->prepare_payload();
	$out = $recovery->inject( $html );
	recovery_check( false !== strpos( $out, '<!-- PublishRank provenance:' ), 'opted-in provenance is recovered when wp_head output is absent' );
	recovery_check( false !== strpos( $out, 'name="publishrank-brand" content="PublishRank"' ), 'opted-in brand marker is recovered into head' );
	recovery_check( false !== strpos( $out, 'name="publishrank-version" content="2.9.58"' ), 'opted-in version marker reflects the current plugin version' );

	$recovery2 = new \PublishRankSEO\Meta\FrontendOutputRecovery( new \PublishRankSEO\Core\OutputRegistry() );
	$recovery2->prepare_payload();
	$html_with_seo = '<html><head><!-- SEO metadata output begin --><title>Example</title></head><body></body></html>';
	$out_with_seo = $recovery2->inject( $html_with_seo );
	recovery_check( false !== strpos( $out_with_seo, 'PublishRank provenance:' ), 'opted-in provenance is added when SEO metadata already exists' );
	recovery_check( 1 === substr_count( $out_with_seo, 'PublishRank provenance:' ), 'recovered provenance is not duplicated' );
	fwrite( STDOUT, "\nRecovery checks: {$tests} run, {$failed} failed.\n" );
	exit( $failed ? 1 : 0 );
}
