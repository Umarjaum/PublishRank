<?php
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
// Static checks on the wizard source. Behaviour is covered by tests/onboarding_wizard.php.
$source   = file_get_contents( __DIR__ . '/../src/Admin/OnboardingPage.php' );
$settings = file_get_contents( __DIR__ . '/../src/Admin/SettingsPage.php' );
$install  = file_get_contents( __DIR__ . '/../src/Core/Installer.php' );
$checks = array(
    'local business profile is part of the wizard' => strpos( $source, 'local_seo[enabled]' ) !== false,
    'local profile uses existing sanitizer' => strpos( $source, 'LocalSEO::sanitize_profile( $local_input )' ) !== false,
    'one-time identity, social and verification fields are in the wizard' => strpos( $source, "'publishrank_identity_legal_name'" ) !== false && strpos( $source, "'publishrank_social_' . \$network" ) !== false && strpos( $source, "'publishrank_verification_' . \$engine" ) !== false && strpos( $source, "'publishrank_schema_knows_about'" ) !== false,
    'wizard opens as an accessible modal' => strpos( $source, 'role="dialog"' ) !== false && strpos( $source, 'aria-modal="true"' ) !== false,
    'required fields are enforced server-side' => strpos( $source, 'missing_required()' ) !== false && strpos( $source, "'required' => true" ) !== false,
    'fresh installs lock plugin screens until setup is done' => strpos( $source, 'maybe_lock_plugin_screens' ) !== false && strpos( $install, 'publishrank_setup_required' ) !== false,
    'exit is refused while locked' => strpos( $source, 'if ( $this->is_locked() )' ) !== false,
    'completed setup has configuration summary' => strpos( $source, 'Your PublishRank configuration' ) !== false,
    'edit action is available' => strpos( $source, 'Edit setup' ) !== false && strpos( $source, 'edit_mode' ) !== false,
    'defaults restore is nonce guarded' => strpos( $source, "AdminAssets::guard( 'publishrank_onboarding_defaults' )" ) !== false,
	'save is nonce and capability guarded' => strpos( $source, "check_admin_referer( 'publishrank_onboarding_save' )" ) !== false && strpos( $source, "current_user_can( 'manage_options' )" ) !== false,
    'completed setup suppresses reminder' => strpos( $source, 'publishrank_onboarding_completed_at' ) !== false && strpos( $source, 'is_setup_complete()' ) !== false,
    'persistent first-run notice has a button and icon' => strpos( $source, 'Start onboarding' ) !== false && strpos( $source, 'publishrank-header-icon-v2.png' ) !== false && strpos( $source, 'notice-info prw-setup-notice' ) !== false,
    'completion is only recorded at final step' => strpos( $source, "if ( 8 === \$step )" ) !== false && strpos( $source, "update_option( 'publishrank_onboarding_completed_at', gmdate( 'c' ) );" ) !== false,
    'defaults do not falsely complete onboarding' => strpos( $source, "delete_option( 'publishrank_onboarding_complete' );" ) !== false,
    'settings page keeps wizard options out of its option group' => strpos( $settings, 'publishrank_seo_unmanaged' ) !== false && strpos( $settings, 'OnboardingPage::setup_keys()' ) !== false,
);
$failed = false;
foreach ( $checks as $label => $passed ) {
    echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
    if ( ! $passed ) { $failed = true; }
}
if ( $failed ) { exit( 1 ); }
echo "Onboarding checks passed.\n";
