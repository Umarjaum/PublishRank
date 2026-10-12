<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
$source = file_get_contents( __DIR__ . '/../src/Admin/OnboardingPage.php' );
$checks = array(
    'local business profile is part of the wizard' => strpos( $source, 'name="local_seo[enabled]"' ) !== false,
    'extra publisher identity/schema fields are present' => strpos( $source, 'name="schema_knows_about"' ) !== false && strpos( $source, 'name="legal_name"' ) !== false,
    'local profile uses existing sanitizer' => strpos( $source, 'LocalSEO::sanitize_profile( $local_input )' ) !== false,
    'completed setup has configuration summary' => strpos( $source, 'Your PublishRank configuration' ) !== false,
    'edit action is available' => strpos( $source, 'Edit configuration' ) !== false && strpos( $source, 'edit_mode' ) !== false,
    'defaults restore is nonce guarded' => strpos( $source, "AdminAssets::guard( 'publishrank_onboarding_defaults' )" ) !== false,
    'completed setup suppresses reminder' => strpos( $source, "publishrank_onboarding_completed_at" ) !== false && strpos( $source, 'is_setup_complete()' ) !== false,
    'persistent first-run notice has a button and icon' => strpos( $source, 'Start onboarding' ) !== false && strpos( $source, 'publishrank-header-icon-v2.png' ) !== false && strpos( $source, 'notice-info prw-setup-notice' ) !== false,
    'completion is only recorded at final step' => strpos( $source, "\$set( 'publishrank_onboarding_completed_at', gmdate( 'c' ) );" ) !== false,
    'defaults do not falsely complete onboarding' => strpos( $source, "delete_option( 'publishrank_onboarding_complete' );" ) !== false,
);
$failed = false;
foreach ( $checks as $label => $passed ) {
    echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
    if ( ! $passed ) { $failed = true; }
}
if ( $failed ) { exit( 1 ); }
echo "Onboarding checks passed.\n";
