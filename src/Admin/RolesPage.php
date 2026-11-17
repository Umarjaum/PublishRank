<?php
/**
 * PublishRank → Roles & permissions: role × capability matrix, presets, per-user overrides,
 * an effective-permission explainer and the audit log. Also exposes the same data over REST.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class RolesPage {
	const SLUG = 'publishrank-roles';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 40 );
		add_action( 'admin_init', array( $this, 'redirect_legacy' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_publishrank_roles_matrix', array( $this, 'save_matrix' ) );
		add_action( 'admin_post_publishrank_roles_preset', array( $this, 'save_preset' ) );
		add_action( 'admin_post_publishrank_roles_user', array( $this, 'save_user' ) );
		add_action( 'admin_post_publishrank_uninstall_data', array( $this, 'save_uninstall' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Roles & permissions', 'publishrank' ), __( 'Roles & permissions', 'publishrank' ), Capabilities::cap( 'manage_roles' ), self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		Suite::enqueue_for( self::SLUG );
	}

	/** The old Newsroom roles screen now lives here. */
	public function redirect_legacy() {
		if ( 'uj-newsroom-roles' === Suite::current_page() && Capabilities::can( 'manage_roles' ) ) {
			wp_safe_redirect( Suite::url( self::SLUG, array( 'group' => 'newsroom' ) ) );
			exit;
		}
	}

	public function save_matrix() {
		Suite::guard( 'publishrank_roles_matrix', 'manage_roles' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys are validated against the registry and values are cast to booleans.
		$raw    = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : array();
		$matrix = array();
		$defs   = Capabilities::definitions();
		foreach ( wp_roles()->roles as $role_name => $data ) {
			foreach ( array_keys( $defs ) as $key ) {
				$matrix[ $role_name ][ $key ] = ! empty( $raw[ $role_name ][ $key ] );
			}
		}
		$result = Capabilities::save_matrix( $matrix );
		if ( is_wp_error( $result ) ) {
			Suite::done( self::SLUG, 'error', $result->get_error_message() );
		}
		Suite::done( self::SLUG, 'success', __( 'Permissions saved.', 'publishrank' ) );
	}

	/** Opt-in switch: remove tables, capabilities and preset roles when the plugin is deleted (default OFF). */
	public function save_uninstall() {
		Suite::guard( 'publishrank_uninstall_data', 'manage_roles' );
		update_option( 'publishrank_delete_data_on_uninstall', empty( $_POST['delete_data'] ) ? 0 : 1, false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in Suite::guard().
		Suite::done( self::SLUG, 'success', empty( $_POST['delete_data'] ) ? __( 'Your data will be kept if the plugin is deleted.', 'publishrank' ) : __( 'All PublishRank data will be erased when the plugin is deleted.', 'publishrank' ), array(), '#data' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	public function save_preset() {
		Suite::guard( 'publishrank_roles_preset', 'manage_roles' );
		$role   = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
		if ( 'administrator' === $role ) {
			Suite::done( self::SLUG, 'error', __( 'Administrators always have every permission.', 'publishrank' ), array(), 'presets' );
		}
		// Applying a preset to one of the acting user's own roles must not remove manage_roles.
		$user = wp_get_current_user();
		if ( ! Capabilities::is_admin_user( $user ) && in_array( $role, (array) $user->roles, true ) && ! in_array( 'manage_roles', Capabilities::presets()[ $preset ]['caps'] ?? array(), true ) ) {
			Suite::done( self::SLUG, 'error', __( 'You cannot remove your own permission to manage roles.', 'publishrank' ), array(), 'presets' );
		}
		if ( ! Capabilities::apply_preset( $role, $preset ) ) {
			Suite::done( self::SLUG, 'error', __( 'Choose a valid role and preset.', 'publishrank' ), array(), 'presets' );
		}
		Suite::done( self::SLUG, 'success', __( 'Preset applied.', 'publishrank' ), array(), 'presets' );
	}

	public function save_user() {
		Suite::guard( 'publishrank_roles_user', 'manage_roles' );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against the registry.
		$raw = isset( $_POST['ov'] ) && is_array( $_POST['ov'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['ov'] ) ) : array();
		$res = Capabilities::save_user_overrides( $user_id, $raw );
		if ( is_wp_error( $res ) ) {
			Suite::done( self::SLUG, 'error', $res->get_error_message(), array( 'user' => $user_id ), 'users' );
		}
		Suite::done( self::SLUG, 'success', __( 'User overrides saved.', 'publishrank' ), array( 'user' => $user_id ), 'users' );
	}

	public function routes() {
		register_rest_route(
			'publishrank/v1',
			'/capabilities',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'manage_roles' ),
				'callback'            => static function () {
					return rest_ensure_response(
						array(
							'definitions' => Capabilities::definitions(),
							'groups'      => Capabilities::groups(),
							'presets'     => Capabilities::presets(),
							'matrix'      => Capabilities::role_matrix(),
						)
					);
				},
			)
		);
		register_rest_route(
			'publishrank/v1',
			'/capabilities/matrix',
			array(
				'methods'             => 'POST',
				'permission_callback' => Capabilities::rest( 'manage_roles' ),
				'args'                => array( 'matrix' => array( 'type' => 'object', 'required' => true ) ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = Capabilities::save_matrix( (array) $r->get_param( 'matrix' ) );
					return is_wp_error( $res ) ? new \WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 409 ) ) : rest_ensure_response( array( 'saved' => true, 'matrix' => Capabilities::role_matrix() ) );
				},
			)
		);
		register_rest_route(
			'publishrank/v1',
			'/capabilities/explain',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'manage_roles' ),
				'args'                => array(
					'user' => array( 'type' => 'integer', 'required' => true ),
					'cap'  => array( 'type' => 'string', 'required' => true ),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$key = sanitize_key( (string) $r->get_param( 'cap' ) );
					if ( ! isset( Capabilities::definitions()[ $key ] ) ) {
						return new \WP_Error( 'publishrank_cap', __( 'Unknown capability.', 'publishrank' ), array( 'status' => 400 ) );
					}
					return rest_ensure_response( Capabilities::explain( (int) $r->get_param( 'user' ), $key ) );
				},
			)
		);
		register_rest_route(
			'publishrank/v1',
			'/capabilities/audit',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'manage_roles' ),
				'callback'            => static function () {
					return rest_ensure_response( Capabilities::audit_log( 200 ) );
				},
			)
		);
	}

	public function render() {
		if ( ! Capabilities::can( 'manage_roles' ) ) {
			return;
		}
		$defs    = Capabilities::definitions();
		$groups  = Capabilities::groups();
		$matrix  = Capabilities::role_matrix();
		$roles   = wp_roles()->roles;
		$filter  = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Suite::open( __( 'Roles & permissions', 'publishrank' ), __( 'Decide who can use each PublishRank tool. Administrators always keep full access.', 'publishrank' ) );
		echo '<div class="prx-grid prx-grid--4">';
		Suite::kpi( __( 'Capabilities', 'publishrank' ), count( $defs ), __( 'Granular, plugin-wide', 'publishrank' ), true );
		Suite::kpi( __( 'Roles', 'publishrank' ), count( $roles ) );
		Suite::kpi( __( 'Presets', 'publishrank' ), count( Capabilities::presets() ) );
		Suite::kpi( __( 'Audit entries', 'publishrank' ), count( Capabilities::audit_log( 500 ) ) );
		echo '</div>';
		Suite::tabs(
			array(
				'matrix'  => __( 'Permission matrix', 'publishrank' ),
				'presets' => __( 'Presets', 'publishrank' ),
				'users'   => __( 'Per-user overrides', 'publishrank' ),
				'explain' => __( 'Why can / can’t?', 'publishrank' ),
				'audit'   => __( 'Audit log', 'publishrank' ),
				'data'    => __( 'Data on uninstall', 'publishrank' ),
			)
		);

		// Matrix.
		Suite::panel_open( 'matrix', false );
		echo '<div class="prx-toolbar"><span class="prx-help">' . esc_html__( 'Filter:', 'publishrank' ) . '</span>';
		echo '<a class="prx-btn prx-btn--sm' . ( '' === $filter ? ' prx-btn--primary' : '' ) . '" href="' . esc_url( Suite::url( self::SLUG ) ) . '">' . esc_html__( 'All', 'publishrank' ) . '</a>';
		foreach ( $groups as $gk => $gl ) {
			echo '<a class="prx-btn prx-btn--sm' . ( $filter === $gk ? ' prx-btn--primary' : '' ) . '" href="' . esc_url( Suite::url( self::SLUG, array( 'group' => $gk ) ) ) . '">' . esc_html( $gl ) . '</a>';
		}
		echo '</div>';
		Suite::form_open( 'publishrank_roles_matrix' );
		// Keep the capabilities of hidden groups when a filter is active.
		if ( '' !== $filter ) {
			foreach ( $matrix as $role_name => $caps ) {
				foreach ( $caps as $key => $on ) {
					if ( $on && $defs[ $key ]['group'] !== $filter ) {
						echo '<input type="hidden" name="caps[' . esc_attr( $role_name ) . '][' . esc_attr( $key ) . ']" value="1" />';
					}
				}
			}
		}
		echo '<div class="prx-table-wrap" style="max-height:70vh"><table class="prx-table prx-matrix"><thead><tr><th>' . esc_html__( 'Capability', 'publishrank' ) . '</th>';
		foreach ( $roles as $role_name => $data ) {
			echo '<th class="prx-role" scope="col">' . esc_html( translate_user_role( $data['name'] ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $groups as $gk => $gl ) {
			if ( '' !== $filter && $filter !== $gk ) {
				continue;
			}
			echo '<tr class="prx-group-row"><td colspan="' . (int) ( count( $roles ) + 1 ) . '">' . esc_html( $gl ) . '</td></tr>';
			foreach ( $defs as $key => $def ) {
				if ( $def['group'] !== $gk ) {
					continue;
				}
				echo '<tr><th scope="row"><span class="prx-capname">' . esc_html( $def['label'] ) . '</span><span class="prx-capdesc">' . esc_html( $def['desc'] ) . ' <code>' . esc_html( Capabilities::cap( $key ) ) . '</code></span></th>';
				foreach ( $roles as $role_name => $data ) {
					$is_admin = 'administrator' === $role_name;
					echo '<td class="prx-cell"><input type="checkbox" aria-label="' . esc_attr( $def['label'] . ' — ' . $role_name ) . '" name="caps[' . esc_attr( $role_name ) . '][' . esc_attr( $key ) . ']" value="1"' . checked( $is_admin || ! empty( $matrix[ $role_name ][ $key ] ), true, false ) . disabled( $is_admin, true, false ) . ' /></td>';
				}
				echo '</tr>';
			}
		}
		echo '</tbody></table></div><div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit"><span class="fa-solid fa-floppy-disk" aria-hidden="true"></span>' . esc_html__( 'Save permissions', 'publishrank' ) . '</button><span class="prx-help">' . esc_html__( 'Administrators are locked on. Capabilities are stored on WordPress roles, so other role editors see them too.', 'publishrank' ) . '</span></div></form>';
		Suite::panel_close();

		// Presets.
		Suite::panel_open( 'presets' );
		echo '<div class="prx-grid prx-grid--3">';
		foreach ( Capabilities::presets() as $pk => $preset ) {
			echo '<section class="prx-card"><div class="prx-card__head"><div><h3 class="prx-card__title"><span class="fa-solid fa-user-shield" aria-hidden="true"></span>' . esc_html( $preset['label'] ) . '</h3><p class="prx-card__sub">' . esc_html( $preset['desc'] ) . '</p></div>' . Suite::badge( count( $preset['caps'] ) . ' ' . __( 'caps', 'publishrank' ), 'brand' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			if ( 'administrator' !== $pk ) {
				Suite::form_open( 'publishrank_roles_preset', array( 'class' => 'prx-row', 'data-prx-confirm' => __( 'Apply this preset? The role’s current PublishRank permissions are replaced.', 'publishrank' ) ) );
				echo '<input type="hidden" name="preset" value="' . esc_attr( $pk ) . '" /><select name="role" aria-label="' . esc_attr__( 'Role', 'publishrank' ) . '">';
				foreach ( $roles as $role_name => $data ) {
					if ( 'administrator' === $role_name ) {
						continue;
					}
					$suggest = array_search( $pk, Capabilities::default_role_presets() + Capabilities::preset_roles(), true );
					echo '<option value="' . esc_attr( $role_name ) . '"' . selected( $suggest, $role_name, false ) . '>' . esc_html( translate_user_role( $data['name'] ) ) . '</option>';
				}
				echo '</select><button class="prx-btn prx-btn--sm" type="submit">' . esc_html__( 'Reset role to preset', 'publishrank' ) . '</button></form>';
			}
			echo '</section>';
		}
		echo '</div>';
		Suite::panel_close();

		// Per-user overrides.
		Suite::panel_open( 'users' );
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Suite::card_open( __( 'Per-user overrides', 'publishrank' ), 'fa-user-gear', __( 'Grant or deny one capability for a single person without changing their role. Denials never apply to administrators.', 'publishrank' ) );
		echo '<form method="get" class="prx-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		wp_dropdown_users( array( 'name' => 'user', 'selected' => $user_id, 'show_option_none' => __( '— Choose a user —', 'publishrank' ), 'option_none_value' => 0 ) );
		echo '<button class="prx-btn" type="submit">' . esc_html__( 'Load', 'publishrank' ) . '</button></form>';
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( $user ) {
			$ov = Capabilities::user_overrides( $user_id );
			Suite::form_open( 'publishrank_roles_user' );
			echo '<input type="hidden" name="user_id" value="' . (int) $user_id . '" /><div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Capability', 'publishrank' ) . '</th><th>' . esc_html__( 'Effective', 'publishrank' ) . '</th><th>' . esc_html__( 'Override', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $defs as $key => $def ) {
				$mode = isset( $ov[ $key ] ) ? $ov[ $key ] : 'inherit';
				echo '<tr><td><strong>' . esc_html( $def['label'] ) . '</strong> <code>' . esc_html( Capabilities::cap( $key ) ) . '</code></td><td>' . ( Capabilities::can( $key, $user_id ) ? Suite::badge( __( 'Allowed', 'publishrank' ), 'ok' ) : Suite::badge( __( 'Not allowed', 'publishrank' ) ) ) . '</td><td><select name="ov[' . esc_attr( $key ) . ']"><option value="inherit"' . selected( $mode, 'inherit', false ) . '>' . esc_html__( 'Inherit from role', 'publishrank' ) . '</option><option value="grant"' . selected( $mode, 'grant', false ) . '>' . esc_html__( 'Grant', 'publishrank' ) . '</option><option value="deny"' . selected( $mode, 'deny', false ) . '>' . esc_html__( 'Deny', 'publishrank' ) . '</option></select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table></div><div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Save overrides', 'publishrank' ) . '</button></div></form>';
		}
		Suite::card_close();
		Suite::panel_close();

		// Explain.
		Suite::panel_open( 'explain' );
		Suite::card_open( __( 'Effective permissions', 'publishrank' ), 'fa-magnifying-glass', __( 'Pick a user and a capability to see exactly why access is allowed or refused.', 'publishrank' ) );
		$ex_user = isset( $_GET['ex_user'] ) ? absint( $_GET['ex_user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ex_cap  = isset( $_GET['ex_cap'] ) ? sanitize_key( wp_unslash( $_GET['ex_cap'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<form method="get" class="prx-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		wp_dropdown_users( array( 'name' => 'ex_user', 'selected' => $ex_user ) );
		echo '<select name="ex_cap">';
		foreach ( $defs as $key => $def ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $ex_cap, $key, false ) . '>' . esc_html( $def['label'] ) . '</option>';
		}
		echo '</select><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Explain', 'publishrank' ) . '</button></form>';
		if ( $ex_user && isset( $defs[ $ex_cap ] ) ) {
			$res = Capabilities::explain( $ex_user, $ex_cap );
			echo '<div class="prx-callout prx-callout--' . ( $res['allowed'] ? 'ok' : 'bad' ) . '"><span class="fa-solid ' . ( $res['allowed'] ? 'fa-circle-check' : 'fa-circle-xmark' ) . '" aria-hidden="true"></span><div><strong>' . esc_html( $res['allowed'] ? __( 'Allowed', 'publishrank' ) : __( 'Not allowed', 'publishrank' ) ) . '</strong><ul>';
			foreach ( $res['reasons'] as $reason ) {
				echo '<li>' . esc_html( $reason ) . '</li>';
			}
			echo '</ul></div></div>';
		}
		Suite::card_close();
		Suite::panel_close();

		// Audit.
		Suite::panel_open( 'audit' );
		$log = Capabilities::audit_log( 200 );
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'When', 'publishrank' ) . '</th><th>' . esc_html__( 'By', 'publishrank' ) . '</th><th>' . esc_html__( 'Event', 'publishrank' ) . '</th><th>' . esc_html__( 'Details', 'publishrank' ) . '</th></tr></thead><tbody>';
		if ( ! $log ) {
			echo '<tr><td colspan="4"><div class="prx-empty"><span class="fa-solid fa-clipboard-list" aria-hidden="true"></span><strong>' . esc_html__( 'No changes recorded yet', 'publishrank' ) . '</strong></div></td></tr>';
		}
		foreach ( $log as $row ) {
			$by = $row['user'] ? get_userdata( (int) $row['user'] ) : null;
			echo '<tr><td>' . esc_html( Suite::ago( $row['at'] ) ) . '</td><td>' . esc_html( $by ? $by->display_name : __( 'System', 'publishrank' ) ) . '</td><td>' . Suite::badge( $row['event'], 'info' ) . '</td><td>' . esc_html( $row['message'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		Suite::panel_close();

		// Data on uninstall.
		Suite::panel_open( 'data' );
		Suite::card_open( __( 'When the plugin is deleted', 'publishrank' ), 'fa-trash-can', __( 'Deactivating never removes anything. Deleting the plugin from the Plugins screen removes settings and caches; the tables below are kept unless you opt in.', 'publishrank' ) );
		Suite::form_open( 'publishrank_uninstall_data' );
		echo '<p><label><input type="checkbox" name="delete_data" value="1"' . checked( (bool) get_option( 'publishrank_delete_data_on_uninstall', 0 ), true, false ) . ' /> <strong>' . esc_html__( 'Delete all PublishRank data on uninstall', 'publishrank' ) . '</strong></label></p>';
		echo '<p class="prx-help">' . esc_html__( 'Erases redirects and their history, the 404 log, AI crawler visit log, citation results, security log, lockouts, feed statistics, permissions and preset roles, and per-post schema blocks. On multisite every site is processed. Off by default.', 'publishrank' ) . '</p>';
		echo '<button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Save', 'publishrank' ) . '</button></form>';
		Suite::card_close();
		Suite::panel_close();
		Suite::close();
	}
}
