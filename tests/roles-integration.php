<?php
/**
 * Feature access rules (Roles & permissions). Run with: wp eval-file tests/roles-integration.php (isolated WordPress).
 * Creates two throwaway users and one role change; everything is restored at the end.
 */
use PublishRankSEO\Core\Capabilities as Caps;

$n = 0; $fail = 0;
$expect = static function ( $label, $got, $want ) use ( &$n, &$fail ) {
	++$n;
	$ok = $got === $want;
	$fail += $ok ? 0 : 1;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok ? '' : ' => got ' . var_export( $got, true ) . ', want ' . var_export( $want, true ) ) . "\n";
};

// The screen must be reachable: the class is registered and its submenu exists for administrators.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$plugin_src = file_get_contents( PUBLISHRANK_SEO_PATH . 'src/Core/Plugin.php' );
$expect( 'Roles & permissions screen is registered by the plugin loader', false !== strpos( $plugin_src, 'Admin\\RolesPage::class' ), true );
$expect( 'Roles & permissions REST route exists', isset( rest_get_server()->get_routes()['/publishrank/v1/capabilities'] ), true );

$ed  = wp_insert_user( array( 'user_login' => 'pr_roles_ed_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'editor', 'user_email' => 'ed' . wp_rand() . '@example.test' ) );
$sub = wp_insert_user( array( 'user_login' => 'pr_roles_sub_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'subscriber', 'user_email' => 'sub' . wp_rand() . '@example.test' ) );
$editor_role  = get_role( 'editor' );
$had_masking  = $editor_role->has_cap( Caps::cap( 'manage_theme_masking' ) );

// Per-user overrides: deny beats the role, grant gives one user one feature.
$base_news = Caps::can( 'view_news', $ed );
Caps::save_user_overrides( $ed, array( 'view_news' => 'deny', 'manage_theme_masking' => 'grant' ) );
$expect( 'Deny override removes a feature the role grants', Caps::can( 'view_news', $ed ), false );
$expect( 'Grant override gives one user a feature the role lacks', Caps::can( 'manage_theme_masking', $ed ), true );
$expect( 'Override does not leak to other users', Caps::can( 'manage_theme_masking', $sub ), false );
$expect( 'WordPress current_user_can honours the override', user_can( $ed, Caps::cap( 'manage_theme_masking' ) ), true );
Caps::save_user_overrides( $ed, array( 'view_news' => 'inherit', 'manage_theme_masking' => 'inherit' ) );
$expect( 'Inherit restores the role default', Caps::can( 'view_news', $ed ), $base_news );
$expect( 'Inherit clears the stored overrides', Caps::user_overrides( $ed ), array() );
Caps::save_user_overrides( $ed, array( 'not_a_feature' => 'grant', 'view_news' => 'superuser' ) );
$expect( 'Unknown features and modes are rejected', Caps::user_overrides( $ed ), array() );

// Role matrix: granting a feature to a role applies to every user with that role.
$matrix = Caps::role_matrix();
$matrix['editor']['manage_theme_masking'] = true;
$expect( 'Role matrix saves', Caps::save_matrix( $matrix ), true );
$expect( 'Role grant applies to editors', Caps::can( 'manage_theme_masking', $ed ), true );
$expect( 'Role grant does not apply to subscribers', Caps::can( 'manage_theme_masking', $sub ), false );
$matrix['editor']['manage_theme_masking'] = $had_masking;
Caps::save_matrix( $matrix );
$expect( 'Role revoke is restored', Caps::can( 'manage_theme_masking', $ed ), $had_masking );

// Administrators can never be locked out; non-admin managers cannot lock themselves out.
update_user_meta( (int) $admins[0], Caps::USER_META, array( 'manage_roles' => 'deny' ) );
$expect( 'Administrators always keep every feature', Caps::can( 'manage_roles', (int) $admins[0] ), true );
delete_user_meta( (int) $admins[0], Caps::USER_META );
Caps::save_user_overrides( $ed, array( 'manage_roles' => 'grant' ) );
wp_set_current_user( $ed );
$res = Caps::save_user_overrides( $ed, array( 'manage_roles' => 'deny' ) );
$expect( 'A non-admin manager cannot remove their own manage-roles permission', is_wp_error( $res ) ? $res->get_error_code() : 'saved', 'publishrank_self_lockout' );
wp_set_current_user( (int) $admins[0] );

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $ed );
wp_delete_user( $sub );
echo "\n{$n} checks, {$fail} failed\n";
