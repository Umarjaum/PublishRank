<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/** Static regression checks for llms.txt discovery and source attribution. */
$root = dirname( __DIR__ );
$llms = file_get_contents( $root . '/src/AI/LLMSTxt.php' );
$comment = file_get_contents( $root . '/src/Core/FrontendHeadComment.php' );
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' );
$trace = file_get_contents( $root . '/src/Security/TraceIntegrity.php' );
$settings = file_get_contents( $root . '/src/Admin/SettingsPage.php' );
$head_output = file_get_contents( $root . '/src/Meta/HeadOutput.php' );
$recovery = file_get_contents( $root . '/src/Meta/FrontendOutputRecovery.php' );
$scanner = file_get_contents( $root . '/src/Admin/FrontendScanner.php' );
$checks = array(
    'llms.txt alternate discovery link exists' => strpos( $llms, 'rel="alternate" type="text/plain" title="llms.txt"' ) !== false,
    'llms.txt has PublishRank metadata tag' => strpos( $llms, 'name="publishrank:llms-txt"' ) !== false,
    'optional full file discovery is conditional' => strpos( $llms, 'OPTION_FULL_ENABLED' ) !== false && strpos( $llms, 'publishrank:llms-full-txt' ) !== false,
    'rewrite rules flush only once after registration' => strpos( $llms, 'maybe_flush_rewrite_rules' ) !== false && strpos( $llms, "publishrank_llms_rewrite_ready" ) !== false,
    'powered-by HTML comment exists' => strpos( $comment, '<!-- SEO of this website is Powered by PublishRank -->' ) !== false,
    'head comment module is registered' => strpos( $plugin, 'FrontendHeadComment::class' ) !== false,
    'source attribution controls remain available for optional metadata' => strpos( $trace, "get_option( 'publishrank_source_attribution', false )" ) !== false,
    'source attribution setting is user-controllable' => strpos( $settings, "'publishrank_source_attribution'" ) !== false && strpos( $settings, 'HTML source attribution' ) !== false,
    'generator branding is opt-in' => strpos( $head_output, '$source_attribution = (bool) get_option' ) !== false && strpos( $head_output, 'name="generator" content="PublishRank' ) !== false,
    'neutral output marker preserves recovery behavior' => strpos( $head_output, '<!-- SEO metadata output begin -->' ) !== false && strpos( $recovery, '<!-- SEO metadata output begin -->' ) !== false,
    'diagnostics recognizes output without public attribution' => strpos( $scanner, '<!-- SEO metadata output begin -->' ) !== false,
);
$failed = array();
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; if ( ! $passed ) { $failed[] = $label; } }
if ( $failed ) { exit( 1 ); }
