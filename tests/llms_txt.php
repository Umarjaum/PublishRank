<?php
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
/** Static regression checks for llms.txt discovery and current provenance behavior. */
$root       = dirname( __DIR__ );
$llms       = file_get_contents( $root . '/src/AI/LLMSTxt.php' );
$plugin     = file_get_contents( $root . '/src/Core/Plugin.php' );
$settings   = file_get_contents( $root . '/src/Admin/SettingsPage.php' );
$head       = file_get_contents( $root . '/src/Meta/HeadOutput.php' );
$recovery   = file_get_contents( $root . '/src/Meta/FrontendOutputRecovery.php' );
$scanner    = file_get_contents( $root . '/src/Admin/FrontendScanner.php' );
$checks     = array(
	'llms.txt alternate discovery link exists' => false !== strpos( $llms, 'rel="alternate" type="text/plain" title="llms.txt"' ),
	'llms.txt has PublishRank metadata tag' => false !== strpos( $llms, 'name="publishrank:llms-txt"' ),
	'optional full file discovery is conditional' => false !== strpos( $llms, 'OPTION_FULL_ENABLED' ) && false !== strpos( $llms, 'publishrank:llms-full-txt' ),
	'rewrite rules flush only once after registration' => false !== strpos( $llms, 'maybe_flush_rewrite_rules' ) && false !== strpos( $llms, 'publishrank_llms_rewrite_ready' ),
	'head provenance is registered through the primary metadata output module' => false !== strpos( $plugin, 'HeadOutput::class' ) && false !== strpos( $head, '<!-- SEO metadata output begin -->' ),
	'technical provenance is clearly always enabled without an unsupported footer-credit toggle' => false !== strpos( $settings, 'Always enabled' ) && false !== strpos( $settings, 'source markers cannot be disabled' ) && false === strpos( $settings, 'publishrank_public_attribution' ),
	'generator metadata includes an escaped plugin version' => false !== strpos( $head, 'name="generator" content="PublishRank' ) && false !== strpos( $head, 'esc_attr( PUBLISHRANK_SEO_VERSION )' ),
	'neutral output marker preserves recovery behavior' => false !== strpos( $head, '<!-- SEO metadata output begin -->' ) && false !== strpos( $recovery, '<!-- SEO metadata output begin -->' ),
	'diagnostics recognizes SEO output without public attribution' => false !== strpos( $scanner, '<!-- SEO metadata output begin -->' ),
);
$failed = array();
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	if ( ! $passed ) {
		$failed[] = $label;
	}
}
if ( $failed ) {
	exit( 1 );
}
echo "llms.txt/provenance checks passed.\n";
