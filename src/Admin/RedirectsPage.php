<?php
/**
 * PublishRank → Redirects (Redirect Engine v2 screen).
 *
 * Tabs: Rules (search, filters, bulk actions, pagination), Add / edit, Test an address,
 * Import & export, History (one-click undo of any change batch) and Settings.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Redirect\RedirectRepository as R;
use PublishRankSEO\Redirect\RedirectStore;

defined( 'ABSPATH' ) || exit;

final class RedirectsPage {
	const SLUG     = 'publishrank-seo-redirects';
	const PER_PAGE = 50;

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		foreach ( array( 'add', 'save', 'bulk', 'quick', 'settings', 'delete', 'export', 'import', 'rollback', 'group', 'migrate' ) as $a ) {
			add_action( 'admin_post_publishrank_seo_redirect_' . $a, array( $this, 'handle_' . $a ) );
		}
		// Legacy action name kept for old bookmarks/forms.
		add_action( 'admin_post_publishrank_seo_redirect_bulk_delete', array( $this, 'handle_bulk' ) );
	}

	public function menu() {
		add_submenu_page( \PublishRankSEO\Core\Branding::MENU_SLUG, __( 'Redirects', 'publishrank' ), __( 'Redirects', 'publishrank' ), Capabilities::cap( 'manage_redirects' ), self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		Suite::enqueue_for( self::SLUG );
	}

	/* ------------------------------------------------------------ Input */

	private static function rule_from_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verify the nonce first.
		$p   = wp_unslash( $_POST );
		$get = static function ( $k, $d = '' ) use ( $p ) {
			return isset( $p[ $k ] ) && is_scalar( $p[ $k ] ) ? trim( (string) $p[ $k ] ) : $d;
		};
		$tz  = wp_timezone();
		$ts  = static function ( $v ) use ( $tz ) {
			if ( '' === $v ) {
				return 0;
			}
			try {
				return ( new \DateTimeImmutable( $v, $tz ) )->getTimestamp();
			} catch ( \Exception $e ) {
				return 0;
			}
		};
		$in = array(
			'source'     => sanitize_text_field( $get( 'source' ) ),
			'target'     => sanitize_text_field( $get( 'target' ) ),
			'status'     => absint( $get( 'status', '301' ) ),
			'match_type' => sanitize_key( $get( 'match_type', 'exact' ) ),
			'group_id'   => absint( $get( 'group_id', '0' ) ),
			'enabled'    => '' === $get( 'enabled' ) ? 0 : 1,
			'note'       => sanitize_text_field( $get( 'note' ) ),
			'start_at'   => $ts( sanitize_text_field( $get( 'start_at' ) ) ),
			'end_at'     => $ts( sanitize_text_field( $get( 'end_at' ) ) ),
			'query'      => sanitize_key( $get( 'query', 'pass' ) ),
			'query_map'  => sanitize_textarea_field( $get( 'query_map' ) ),
			'external'   => '' === $get( 'external' ) ? 0 : 1,
			'force'      => '' === $get( 'force' ) ? 0 : 1,
			'allow_live' => '' === $get( 'allow_live' ) ? 0 : 1,
			'conditions' => array(
				'login'    => sanitize_key( $get( 'cond_login', 'any' ) ),
				'device'   => sanitize_key( $get( 'cond_device', 'any' ) ),
				'referrer' => sanitize_text_field( $get( 'cond_referrer' ) ),
				'cookie'   => sanitize_text_field( $get( 'cond_cookie' ) ),
				'language' => sanitize_text_field( $get( 'cond_language' ) ),
			),
			'origin'     => 'manual',
		);
		$new_group = sanitize_text_field( $get( 'new_group' ) );
		if ( '' !== $new_group ) {
			$in['group_id'] = R::add_group( $new_group );
		}
		// phpcs:enable
		return $in;
	}

	/* ------------------------------------------------------------ Handlers */

	public function handle_add() {
		$this->handle_save();
	}

	public function handle_save() {
		Suite::guard( 'publishrank_seo_redirect_save', 'manage_redirects' );
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$res = R::save( self::rule_from_post(), $id );
		if ( is_wp_error( $res ) ) {
			AdminAssets::flash( 'error', implode( ' ', $res->get_error_messages() ) );
			set_transient( 'publishrank_redirect_draft_' . get_current_user_id(), wp_unslash( $_POST ), 10 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- redisplayed escaped; re-validated on save.
			wp_safe_redirect( Suite::url( self::SLUG, $id ? array( 'edit' => $id ) : array( 'draft' => 1 ) ) . '#editor' );
			exit;
		}
		Suite::done( self::SLUG, 'success', $id ? __( 'Redirect updated.', 'publishrank' ) : __( 'Redirect saved. Any chain that ended at this address now goes straight to the new target.', 'publishrank' ), array(), 'rules' );
	}

	public function handle_quick() {
		Suite::guard( 'publishrank_seo_redirect_quick', 'manage_redirects' );
		$text = isset( $_POST['bulk'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bulk'] ) ) : '';
		$rows = array_slice( RedirectStore::parse_bulk( $text ), 0, 1000 );
		$res  = R::import( array_map( static function ( $r ) { return array( 'source' => $r['source'], 'target' => $r['target'], 'status' => $r['status'] ); }, $rows ) );
		/* translators: 1: created, 2: updated, 3: errors */
		$msg  = sprintf( __( '%1$d created, %2$d updated, %3$d skipped.', 'publishrank' ), $res['created'], $res['updated'], count( $res['errors'] ) );
		foreach ( array_slice( $res['errors'], 0, 5 ) as $e ) {
			$msg .= ' · ' . $e['source'] . ': ' . $e['error'];
		}
		Suite::done( self::SLUG, $res['errors'] ? 'warning' : 'success', $msg, array(), 'tools' );
	}

	public function handle_bulk() {
		Suite::guard( isset( $_POST['action'] ) && 'publishrank_seo_redirect_bulk_delete' === $_POST['action'] ? 'publishrank_seo_redirect_bulk_delete' : 'publishrank_seo_redirect_bulk', 'manage_redirects' );
		$ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : 'delete';
		$value  = isset( $_POST['bulk_value'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_value'] ) ) : '';
		if ( ! in_array( $action, array( 'delete', 'enable', 'disable', 'group', 'status', 'reset_hits' ), true ) || ! $ids ) {
			Suite::done( self::SLUG, 'warning', __( 'Select at least one redirect and an action.', 'publishrank' ), array(), 'rules' );
		}
		$n = R::bulk( $ids, $action, $value );
		/* translators: %d: number of rules */
		Suite::done( self::SLUG, 'success', sprintf( _n( '%d redirect changed. You can undo this in History.', '%d redirects changed. You can undo this in History.', $n, 'publishrank' ), $n ), array(), 'rules' );
	}

	public function handle_delete() {
		if ( ! Capabilities::can( 'manage_redirects' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_seo_redirect_delete' );
		R::delete( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		Suite::done( self::SLUG, 'success', __( 'Redirect removed. You can undo this in History.', 'publishrank' ), array(), 'rules' );
	}

	public function handle_settings() {
		Suite::guard( 'publishrank_seo_redirect_settings', 'manage_redirects' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( array( 'enabled' => 'publishrank_redirect_enabled', 'auto_permalink' => 'publishrank_redirect_auto_enabled', 'only_404' => 'publishrank_redirect_only_404', 'preserve_query' => 'publishrank_redirect_preserve_query', 'x_header' => 'publishrank_redirect_x_header', 'case_insensitive' => 'publishrank_redirect_case_insensitive', 'ignore_trailing' => 'publishrank_redirect_ignore_trailing', 'log_hits' => 'publishrank_redirect_log_hits' ) as $field => $opt ) {
			update_option( $opt, empty( $_POST[ $field ] ) ? 0 : 1, false );
		}
		update_option( 'publishrank_redirect_max_hops', min( 50, max( 1, isset( $_POST['max_hops'] ) ? absint( $_POST['max_hops'] ) : 10 ) ), false );
		$hosts = isset( $_POST['external_hosts'] ) ? sanitize_textarea_field( wp_unslash( $_POST['external_hosts'] ) ) : '';
		update_option( 'publishrank_redirect_external_hosts', $hosts, false );
		// phpcs:enable
		R::rehash();
		R::rebuild_patterns();
		Suite::done( self::SLUG, 'success', __( 'Redirect settings saved.', 'publishrank' ), array(), 'settings' );
	}

	public function handle_export() {
		if ( ! Capabilities::can( 'manage_redirects' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_seo_redirect_export' );
		$rows   = R::export_rows( self::filters() );
		$header = array_shift( $rows );
		Suite::csv( 'publishrank-redirects-' . gmdate( 'Y-m-d' ) . '.csv', $header, $rows );
	}

	public function handle_import() {
		Suite::guard( 'publishrank_seo_redirect_import', 'manage_redirects' );
		$file = isset( $_FILES['file']['tmp_name'] ) ? (string) $_FILES['file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- temp path only.
		if ( '' === $file || ! is_uploaded_file( $file ) || filesize( $file ) > 5 * MB_IN_BYTES ) {
			Suite::done( self::SLUG, 'error', __( 'Choose a CSV or .htaccess file up to 5 MB.', 'publishrank' ), array(), 'tools' );
		}
		$text   = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'csv';
		$rows   = 'htaccess' === $format ? R::parse_htaccess( $text ) : RedirectStore::parse_csv( $text );
		$rows   = array_slice( $rows, 0, 20000 );
		$group  = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
		$dry    = ! empty( $_POST['dry_run'] );
		$res    = R::import( $rows, $group, $dry );
		/* translators: 1: rows read, 2: created, 3: updated, 4: errors */
		$msg = sprintf( __( '%1$d rows read: %2$d new, %3$d updated, %4$d with problems.', 'publishrank' ), count( $rows ), $res['created'], $res['updated'], count( $res['errors'] ) );
		if ( $dry ) {
			$msg = __( 'Dry run — nothing was saved.', 'publishrank' ) . ' ' . $msg;
		}
		foreach ( array_slice( $res['errors'], 0, 8 ) as $e ) {
			/* translators: 1: row number, 2: error */
			$msg .= ' · ' . sprintf( __( 'Row %1$d: %2$s', 'publishrank' ), $e['row'], $e['error'] );
		}
		Suite::done( self::SLUG, $res['errors'] ? 'warning' : 'success', $msg, array(), 'tools' );
	}

	public function handle_rollback() {
		Suite::guard( 'publishrank_seo_redirect_rollback', 'manage_redirects' );
		$batch = isset( $_POST['batch'] ) ? preg_replace( '/[^a-f0-9\-]/', '', strtolower( (string) wp_unslash( $_POST['batch'] ) ) ) : '';
		$res   = R::rollback( $batch );
		if ( is_wp_error( $res ) ) {
			Suite::done( self::SLUG, 'error', $res->get_error_message(), array(), 'history' );
		}
		/* translators: %d: number of changes */
		Suite::done( self::SLUG, 'success', sprintf( _n( '%d change undone.', '%d changes undone.', (int) $res, 'publishrank' ), (int) $res ), array(), 'history' );
	}

	public function handle_group() {
		Suite::guard( 'publishrank_seo_redirect_group', 'manage_redirects' );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( 'delete' === $op ) {
			R::delete_group( isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0 );
			Suite::done( self::SLUG, 'success', __( 'Group removed. Its redirects stay and become ungrouped.', 'publishrank' ), array(), 'settings' );
		}
		R::add_group( isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '' );
		Suite::done( self::SLUG, 'success', __( 'Group added.', 'publishrank' ), array(), 'settings' );
	}

	public function handle_migrate() {
		Suite::guard( 'publishrank_seo_redirect_migrate', 'manage_redirects' );
		$res = R::migrate_legacy();
		/* translators: %d: rules migrated */
		Suite::done( self::SLUG, $res['errors'] ? 'warning' : 'success', sprintf( __( '%d redirects moved to the new engine.', 'publishrank' ), $res['migrated'] ) . ( $res['errors'] ? ' ' . __( 'Rules that need review were saved switched off.', 'publishrank' ) : '' ), array(), 'rules' );
	}

	/* ------------------------------------------------------------ Render */

	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$g = wp_unslash( $_GET );
		$f = array(
			'search'     => isset( $g['s'] ) ? sanitize_text_field( $g['s'] ) : '',
			'match_type' => isset( $g['match'] ) ? sanitize_key( $g['match'] ) : '',
			'status'     => isset( $g['code'] ) ? absint( $g['code'] ) : 0,
			'group_id'   => isset( $g['group'] ) && '' !== $g['group'] ? absint( $g['group'] ) : '',
			'origin'     => isset( $g['origin'] ) ? sanitize_key( $g['origin'] ) : '',
			'enabled'    => isset( $g['state'] ) && '' !== $g['state'] ? absint( $g['state'] ) : '',
			'unused'     => ! empty( $g['unused'] ),
			'orderby'    => isset( $g['orderby'] ) ? sanitize_key( $g['orderby'] ) : 'id',
			'order'      => isset( $g['order'] ) ? sanitize_key( $g['order'] ) : 'desc',
		);
		// phpcs:enable
		return $f;
	}

	private static function status_labels() {
		return array(
			301 => __( '301 · Moved permanently', 'publishrank' ),
			302 => __( '302 · Found (temporary)', 'publishrank' ),
			303 => __( '303 · See other', 'publishrank' ),
			307 => __( '307 · Temporary, keeps method', 'publishrank' ),
			308 => __( '308 · Permanent, keeps method', 'publishrank' ),
			410 => __( '410 · Gone (content removed)', 'publishrank' ),
			451 => __( '451 · Unavailable for legal reasons', 'publishrank' ),
		);
	}

	public function render() {
		if ( ! Capabilities::can( 'manage_redirects' ) ) {
			return;
		}
		$ready = R::ready();
		Suite::open( __( 'Redirects', 'publishrank' ), __( 'Indexed, loop-safe redirects for very large sites: exact, prefix, wildcard and regex rules, conditions, groups, history and undo.', 'publishrank' ) );
		if ( ! $ready ) {
			echo '<div class="prx-callout prx-callout--warn"><p>' . esc_html__( 'The redirect tables are not installed yet. Deactivate and reactivate PublishRank, or run “wp publishrank redirect migrate”.', 'publishrank' ) . '</p></div>';
			Suite::close();
			return;
		}
		$migrated = get_option( R::MIGRATED_OPT );
		$legacy   = get_option( RedirectStore::OPTION, array() );
		if ( ! $migrated && is_array( $legacy ) && $legacy ) {
			echo '<div class="prx-callout prx-callout--warn"><p>' . esc_html( sprintf( /* translators: %d: rules */ __( '%d redirects are still stored the old way. Move them to the faster engine (a backup is kept and the move can be undone).', 'publishrank' ), count( $legacy ) ) ) . '</p>';
			Suite::form_open( 'publishrank_seo_redirect_migrate' );
			echo '<button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Move redirects now', 'publishrank' ) . '</button></form></div>';
		}
		$total    = R::count();
		$active   = R::count( array( 'enabled' => 1 ) );
		$patterns = R::count_patterns();
		$unused   = R::count( array( 'unused' => 1 ) );
		echo '<div class="prx-grid prx-grid--4">';
		Suite::kpi( __( 'Redirects', 'publishrank' ), number_format_i18n( $total ), sprintf( /* translators: %s: number */ __( '%s active', 'publishrank' ), number_format_i18n( $active ) ), true );
		Suite::kpi( __( 'Pattern rules', 'publishrank' ), number_format_i18n( $patterns ), __( 'prefix, wildcard, regex', 'publishrank' ) );
		Suite::kpi( __( 'Never used', 'publishrank' ), number_format_i18n( $unused ), __( 'no hits recorded', 'publishrank' ) );
		Suite::kpi( __( 'Status', 'publishrank' ), get_option( 'publishrank_redirect_enabled', 1 ) ? __( 'On', 'publishrank' ) : __( 'Off', 'publishrank' ), get_option( 'publishrank_redirect_only_404', 1 ) ? __( 'only for missing pages', 'publishrank' ) : __( 'for every request', 'publishrank' ) );
		echo '</div>';
		$editing = isset( $_GET['edit'] ) || isset( $_GET['draft'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Suite::tabs(
			array(
				'rules'    => __( 'Rules', 'publishrank' ) . '|' . number_format_i18n( $total ),
				'editor'   => isset( $_GET['edit'] ) ? __( 'Edit redirect', 'publishrank' ) : __( 'Add redirect', 'publishrank' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'tester'   => __( 'Test an address', 'publishrank' ),
				'tools'    => __( 'Import & export', 'publishrank' ),
				'history'  => __( 'History', 'publishrank' ),
				'settings' => __( 'Settings', 'publishrank' ),
			),
			$editing ? 'editor' : ( isset( $_GET['test_url'] ) ? 'tester' : '' ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		$this->panel_rules();
		$this->panel_editor();
		$this->panel_tester();
		$this->panel_tools();
		$this->panel_history();
		$this->panel_settings();
		Suite::close();
	}

	private function panel_rules() {
		$f      = self::filters();
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res    = R::query( $f + array( 'per_page' => self::PER_PAGE, 'paged' => $paged ) );
		$groups = R::groups();
		Suite::panel_open( 'rules', false );
		echo '<form method="get" class="prx-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input class="prx-input" type="search" name="s" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'Search old or new address, or note', 'publishrank' ) . '" aria-label="' . esc_attr__( 'Search redirects', 'publishrank' ) . '" />';
		echo '<select name="match" aria-label="' . esc_attr__( 'Match type', 'publishrank' ) . '"><option value="">' . esc_html__( 'All match types', 'publishrank' ) . '</option>';
		foreach ( R::MATCH_TYPES as $m ) {
			echo '<option value="' . esc_attr( $m ) . '"' . selected( $f['match_type'], $m, false ) . '>' . esc_html( ucfirst( $m ) ) . '</option>';
		}
		echo '</select><select name="code" aria-label="' . esc_attr__( 'Status code', 'publishrank' ) . '"><option value="">' . esc_html__( 'All codes', 'publishrank' ) . '</option>';
		foreach ( R::statuses() as $c ) {
			echo '<option value="' . (int) $c . '"' . selected( $f['status'], $c, false ) . '>' . (int) $c . '</option>';
		}
		echo '</select><select name="group" aria-label="' . esc_attr__( 'Group', 'publishrank' ) . '"><option value="">' . esc_html__( 'All groups', 'publishrank' ) . '</option><option value="0"' . selected( '0', (string) $f['group_id'], false ) . '>' . esc_html__( 'Ungrouped', 'publishrank' ) . '</option>';
		foreach ( $groups as $gid => $name ) {
			echo '<option value="' . (int) $gid . '"' . selected( (string) $f['group_id'], (string) $gid, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select><select name="origin" aria-label="' . esc_attr__( 'Created by', 'publishrank' ) . '"><option value="">' . esc_html__( 'Any origin', 'publishrank' ) . '</option>';
		foreach ( array( 'manual' => __( 'Added by hand', 'publishrank' ), 'auto' => __( 'Automatic (URL changed)', 'publishrank' ), 'import' => __( 'Imported', 'publishrank' ), 'migrated' => __( 'Migrated', 'publishrank' ), '404' => __( 'From 404 monitor', 'publishrank' ) ) as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['origin'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select><label class="prx-check"><input type="checkbox" name="unused" value="1"' . checked( $f['unused'], true, false ) . ' /> ' . esc_html__( 'Never used', 'publishrank' ) . '</label>';
		echo '<button class="prx-btn" type="submit">' . esc_html__( 'Filter', 'publishrank' ) . '</button></form>';

		Suite::form_open( 'publishrank_seo_redirect_bulk', array( 'id' => 'prx-redirect-bulk' ) );
		echo '<div class="prx-toolbar"><select name="bulk_action" aria-label="' . esc_attr__( 'Bulk action', 'publishrank' ) . '"><option value="">' . esc_html__( 'Bulk action…', 'publishrank' ) . '</option><option value="enable">' . esc_html__( 'Switch on', 'publishrank' ) . '</option><option value="disable">' . esc_html__( 'Switch off', 'publishrank' ) . '</option><option value="status">' . esc_html__( 'Change status code', 'publishrank' ) . '</option><option value="group">' . esc_html__( 'Move to group', 'publishrank' ) . '</option><option value="reset_hits">' . esc_html__( 'Reset hit counts', 'publishrank' ) . '</option><option value="delete">' . esc_html__( 'Delete', 'publishrank' ) . '</option></select>';
		echo '<select name="bulk_value" id="prx-bulk-value" aria-label="' . esc_attr__( 'Value for the bulk action', 'publishrank' ) . '"><option value="">' . esc_html__( '— value —', 'publishrank' ) . '</option><optgroup label="' . esc_attr__( 'Status code', 'publishrank' ) . '">';
		foreach ( R::statuses() as $c ) {
			echo '<option value="' . (int) $c . '">' . (int) $c . '</option>';
		}
		echo '</optgroup><optgroup label="' . esc_attr__( 'Group', 'publishrank' ) . '"><option value="0">' . esc_html__( 'Ungrouped', 'publishrank' ) . '</option>';
		foreach ( $groups as $gid => $name ) {
			echo '<option value="' . (int) $gid . '">' . esc_html( $name ) . '</option>';
		}
		echo '</optgroup></select><button class="prx-btn" type="submit" data-prx-confirm="' . esc_attr__( 'Apply this action to the selected redirects? You can undo it from History.', 'publishrank' ) . '">' . esc_html__( 'Apply', 'publishrank' ) . '</button>';
		echo '<span class="prx-muted">' . esc_html( sprintf( /* translators: %s: number */ __( '%s matching', 'publishrank' ), number_format_i18n( $res['total'] ) ) ) . '</span>';
		echo '<a class="prx-btn prx-btn--primary" href="#editor"><span class="fa-solid fa-plus" aria-hidden="true"></span> ' . esc_html__( 'Add redirect', 'publishrank' ) . '</a></div>';

		$sort = static function ( $key, $label ) use ( $f ) {
			$dir = ( $f['orderby'] === $key && 'desc' === $f['order'] ) ? 'asc' : 'desc';
			return '<a href="' . esc_url( add_query_arg( array( 'orderby' => $key, 'order' => $dir ) ) ) . '">' . esc_html( $label ) . ( $f['orderby'] === $key ? ' <span class="fa-solid fa-sort-' . ( 'asc' === $f['order'] ? 'up' : 'down' ) . '" aria-hidden="true"></span>' : '' ) . '</a>';
		};
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th class="prx-col-check"><input type="checkbox" data-prx-check-all aria-label="' . esc_attr__( 'Select all on this page', 'publishrank' ) . '" /></th>';
		echo '<th>' . $sort( 'source', __( 'Old address', 'publishrank' ) ) . '</th><th>' . esc_html__( 'Goes to', 'publishrank' ) . '</th><th>' . $sort( 'status', __( 'Code', 'publishrank' ) ) . '</th><th>' . esc_html__( 'Match', 'publishrank' ) . '</th><th>' . $sort( 'hits', __( 'Hits', 'publishrank' ) ) . '</th><th>' . $sort( 'last_hit', __( 'Last used', 'publishrank' ) ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'publishrank' ) . '</span></th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $sort escapes.
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="8"><div class="prx-empty"><span class="fa-solid fa-route" aria-hidden="true"></span><p>' . esc_html( $f['search'] || $f['match_type'] || $f['status'] ? __( 'No redirects match these filters.', 'publishrank' ) : __( 'No redirects yet. Add one, import a CSV or .htaccess file, or let PublishRank create them when you change a URL.', 'publishrank' ) ) . '</p></div></td></tr>';
		}
		$now = time();
		foreach ( $res['rows'] as $r ) {
			$state = '';
			if ( ! $r['enabled'] ) {
				$state = Suite::badge( __( 'Off', 'publishrank' ), 'muted' );
			} elseif ( $r['start_at'] && $r['start_at'] > $now ) {
				$state = Suite::badge( __( 'Scheduled', 'publishrank' ), 'info' );
			} elseif ( $r['end_at'] && $r['end_at'] <= $now ) {
				$state = Suite::badge( __( 'Ended', 'publishrank' ), 'muted' );
			}
			$cond = array_filter( array( 'any' !== $r['flags']['conditions']['login'] ? $r['flags']['conditions']['login'] : '', 'any' !== $r['flags']['conditions']['device'] ? $r['flags']['conditions']['device'] : '', $r['flags']['conditions']['referrer'] ? 'referrer' : '', $r['flags']['conditions']['cookie'] ? 'cookie' : '', $r['flags']['conditions']['language'] ? 'language' : '' ) );
			$tone = in_array( $r['status'], array( 410, 451 ), true ) ? 'bad' : ( in_array( $r['status'], array( 301, 308 ), true ) ? 'good' : 'warn' );
			$del  = wp_nonce_url( add_query_arg( array( 'action' => 'publishrank_seo_redirect_delete', 'id' => $r['id'] ), admin_url( 'admin-post.php' ) ), 'publishrank_seo_redirect_delete' );
			echo '<tr><td class="prx-col-check"><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '" aria-label="' . esc_attr( sprintf( /* translators: %s: source */ __( 'Select %s', 'publishrank' ), $r['source'] ) ) . '" /></td>';
			echo '<td><code class="prx-code">' . esc_html( $r['source'] ) . '</code> ' . $state . ( $r['group_id'] && isset( $groups[ $r['group_id'] ] ) ? ' ' . Suite::badge( $groups[ $r['group_id'] ] ) : '' ) . ( $r['note'] ? '<div class="prx-muted">' . esc_html( $r['note'] ) . '</div>' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			echo '<td>' . ( '' !== $r['target'] ? '<code class="prx-code">' . esc_html( $r['target'] ) . '</code>' . ( $r['flags']['external'] ? ' ' . Suite::badge( __( 'external', 'publishrank' ), 'info' ) : '' ) : '<span class="prx-muted">—</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . Suite::badge( (string) $r['status'], $tone ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( ucfirst( $r['match_type'] ) ) . ( 'pass' !== $r['flags']['query'] ? '<div class="prx-muted">' . esc_html( sprintf( /* translators: %s: mode */ __( 'query: %s', 'publishrank' ), $r['flags']['query'] ) ) . '</div>' : '' ) . ( $cond ? '<div class="prx-muted">' . esc_html( sprintf( /* translators: %s: list */ __( 'only when: %s', 'publishrank' ), implode( ', ', $cond ) ) ) . '</div>' : '' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $r['hits'] ) ) . '</td><td>' . esc_html( $r['last_hit'] ? Suite::ago( $r['last_hit'] ) : '—' ) . '</td>';
			echo '<td class="prx-actions"><a class="prx-icon-btn" href="' . esc_url( Suite::url( self::SLUG, array( 'edit' => $r['id'] ) ) . '#editor' ) . '" title="' . esc_attr__( 'Edit', 'publishrank' ) . '"><span class="fa-solid fa-pen" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Edit', 'publishrank' ) . '</span></a><a class="prx-icon-btn" href="' . esc_url( Suite::url( self::SLUG, array( 'test_url' => strtok( $r['source'], '?' ) ) ) . '#tester' ) . '" title="' . esc_attr__( 'Test', 'publishrank' ) . '"><span class="fa-solid fa-magnifying-glass" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Test', 'publishrank' ) . '</span></a><a class="prx-icon-btn prx-icon-btn--danger" href="' . esc_url( $del ) . '" title="' . esc_attr__( 'Delete', 'publishrank' ) . '" data-prx-confirm="' . esc_attr__( 'Delete this redirect? You can undo it from History.', 'publishrank' ) . '"><span class="fa-solid fa-trash-can" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Delete', 'publishrank' ) . '</span></a></td></tr>';
		}
		echo '</tbody></table></div></form>';
		Suite::pagination( $res['total'], self::PER_PAGE, $paged );
		Suite::panel_close();
	}

	private function panel_editor() {
		$id    = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$r     = $id ? R::get( $id ) : null;
		$draft = get_transient( 'publishrank_redirect_draft_' . get_current_user_id() );
		if ( is_array( $draft ) ) {
			delete_transient( 'publishrank_redirect_draft_' . get_current_user_id() );
		}
		$v = static function ( $k, $d = '' ) use ( $r, $draft ) {
			if ( is_array( $draft ) && isset( $draft[ $k ] ) && is_scalar( $draft[ $k ] ) ) {
				return (string) $draft[ $k ];
			}
			if ( ! $r ) {
				return $d;
			}
			$map = array(
				'cond_login'    => $r['flags']['conditions']['login'],
				'cond_device'   => $r['flags']['conditions']['device'],
				'cond_referrer' => $r['flags']['conditions']['referrer'],
				'cond_cookie'   => $r['flags']['conditions']['cookie'],
				'cond_language' => $r['flags']['conditions']['language'],
				'query'         => $r['flags']['query'],
				'external'      => $r['flags']['external'],
				'force'         => $r['flags']['force'],
				'query_map'     => implode( "\n", array_map( static function ( $a, $b ) { return $a . '=' . $b; }, array_keys( $r['flags']['query_map'] ), $r['flags']['query_map'] ) ),
				'start_at'      => $r['start_at'] ? wp_date( 'Y-m-d\TH:i', $r['start_at'] ) : '',
				'end_at'        => $r['end_at'] ? wp_date( 'Y-m-d\TH:i', $r['end_at'] ) : '',
			);
			return array_key_exists( $k, $map ) ? (string) $map[ $k ] : ( isset( $r[ $k ] ) ? (string) $r[ $k ] : $d );
		};
		Suite::panel_open( 'editor' );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::card_open( $r ? __( 'Edit redirect', 'publishrank' ) : __( 'Add a redirect', 'publishrank' ), 'fa-route', __( 'Every rule is validated before it is saved: loops, unsafe targets and addresses that belong to WordPress itself are refused.', 'publishrank' ) );
		Suite::form_open( 'publishrank_seo_redirect_save', array( 'class' => 'prx-form' ) );
		echo '<input type="hidden" name="id" value="' . (int) $id . '" />';
		Suite::field( 'select', 'match_type', $v( 'match_type', 'exact' ), __( 'How to match the old address', 'publishrank' ), __( 'Exact: one address. Prefix: everything that starts with it. Wildcard: * matches any characters. Regex: full regular expression ($1… in the target).', 'publishrank' ), array(), array( 'exact' => __( 'Exact address', 'publishrank' ), 'prefix' => __( 'Starts with (prefix)', 'publishrank' ), 'wildcard' => __( 'Wildcard pattern (*)', 'publishrank' ), 'regex' => __( 'Regular expression', 'publishrank' ) ) );
		Suite::field( 'text', 'source', $v( 'source' ), __( 'Old address on this site', 'publishrank' ), __( 'A path such as /2019/old-story/ or a full URL on this site. Add ?id=5 with “query: exact” to match one query string.', 'publishrank' ), array( 'required' => 'required', 'placeholder' => '/old-story/', 'maxlength' => 2000 ) );
		Suite::field( 'select', 'status', $v( 'status', '301' ), __( 'Response', 'publishrank' ), __( '301/308 for permanent moves, 302/303/307 for temporary ones, 410 or 451 when there is no new address.', 'publishrank' ), array(), self::status_labels() );
		echo '<div data-prx-show-when="status=301|302|303|307|308">';
		Suite::field( 'text', 'target', $v( 'target' ), __( 'New address', 'publishrank' ), __( 'A path on this site or a full URL. External addresses must be on the allowed list in Settings.', 'publishrank' ), array( 'placeholder' => '/new-story/', 'maxlength' => 2000 ) );
		Suite::switch_field( 'external', '1' === $v( 'external' ), __( 'Target is another website', 'publishrank' ), __( 'Only hosts on the allowed list are accepted.', 'publishrank' ) );
		echo '</div>';
		Suite::field( 'select', 'group_id', $v( 'group_id', '0' ), __( 'Group', 'publishrank' ), '', array(), array( 0 => __( 'No group', 'publishrank' ) ) + R::groups() );
		Suite::field( 'text', 'new_group', '', __( 'Or create a new group', 'publishrank' ), '', array( 'placeholder' => __( 'e.g. 2024 site move', 'publishrank' ), 'maxlength' => 120 ) );
		Suite::field( 'text', 'note', $v( 'note' ), __( 'Note (optional)', 'publishrank' ), '', array( 'maxlength' => 255 ) );
		Suite::switch_field( 'enabled', '' === $v( 'enabled', '1' ) ? false : '0' !== $v( 'enabled', '1' ), __( 'Redirect is on', 'publishrank' ) );
		echo '<details class="prx-details"><summary>' . esc_html__( 'Query strings, schedule and conditions', 'publishrank' ) . '</summary>';
		Suite::field( 'select', 'query', $v( 'query', 'pass' ), __( 'Query string', 'publishrank' ), '', array(), array( 'pass' => __( 'Pass it on to the new address', 'publishrank' ), 'ignore' => __( 'Drop it', 'publishrank' ), 'exact' => __( 'Match exactly (part of the old address)', 'publishrank' ), 'map' => __( 'Rename or drop some parameters', 'publishrank' ) ) );
		Suite::field( 'textarea', 'query_map', $v( 'query_map' ), __( 'Parameter changes (for “rename or drop”)', 'publishrank' ), __( 'One per line: old=new renames, old= drops it.', 'publishrank' ), array( 'rows' => 3, 'placeholder' => "ref=utm_source\nsessionid=" ) );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::field( 'datetime-local', 'start_at', $v( 'start_at' ), __( 'Starts', 'publishrank' ) );
		Suite::field( 'datetime-local', 'end_at', $v( 'end_at' ), __( 'Ends', 'publishrank' ) );
		echo '</div><p class="prx-muted">' . esc_html( sprintf( /* translators: %s: timezone */ __( 'Times use the site timezone (%s).', 'publishrank' ), wp_timezone_string() ) ) . '</p>';
		echo '<div class="prx-grid prx-grid--2">';
		Suite::field( 'select', 'cond_login', $v( 'cond_login', 'any' ), __( 'Visitor is', 'publishrank' ), '', array(), array( 'any' => __( 'Anyone', 'publishrank' ), 'out' => __( 'Signed out', 'publishrank' ), 'in' => __( 'Signed in', 'publishrank' ) ) );
		Suite::field( 'select', 'cond_device', $v( 'cond_device', 'any' ), __( 'Device', 'publishrank' ), '', array(), array( 'any' => __( 'Any device', 'publishrank' ), 'mobile' => __( 'Phones and tablets', 'publishrank' ), 'desktop' => __( 'Desktop', 'publishrank' ) ) );
		Suite::field( 'text', 'cond_referrer', $v( 'cond_referrer' ), __( 'Came from (contains)', 'publishrank' ), '', array( 'placeholder' => 'facebook.com' ) );
		Suite::field( 'text', 'cond_cookie', $v( 'cond_cookie' ), __( 'Has cookie', 'publishrank' ), __( 'name or name=value', 'publishrank' ) );
		Suite::field( 'text', 'cond_language', $v( 'cond_language' ), __( 'Browser language', 'publishrank' ), __( 'e.g. de,fr', 'publishrank' ) );
		echo '</div>';
		Suite::switch_field( 'allow_live', '1' === $v( 'allow_live' ), __( 'Allow redirecting a published page', 'publishrank' ), __( 'Normally refused, because readers would never see that page again.', 'publishrank' ) );
		Suite::switch_field( 'force', '1' === $v( 'force' ), __( 'Apply even when the page exists', 'publishrank' ), __( 'Ignores “only for missing pages” for this rule.', 'publishrank' ) );
		echo '</details>';
		echo '<div class="prx-actions-row"><button type="submit" class="prx-btn prx-btn--primary">' . esc_html( $r ? __( 'Update redirect', 'publishrank' ) : __( 'Save redirect', 'publishrank' ) ) . '</button>';
		if ( $r ) {
			echo ' <a class="prx-btn prx-btn--ghost" href="' . esc_url( Suite::url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'publishrank' ) . '</a>';
		}
		echo '</div></form>';
		Suite::card_close();
		if ( $r ) {
			Suite::card_open( __( 'Change history for this rule', 'publishrank' ), 'fa-clock-rotate-left' );
			echo '<ul class="prx-timeline">';
			foreach ( R::history_for( $r['id'], 20 ) as $h ) {
				$user = get_userdata( (int) $h['user_id'] );
				echo '<li><strong>' . esc_html( ucfirst( $h['action'] ) ) . '</strong> · ' . esc_html( Suite::ago( $h['created_at'] ) ) . ( $user ? ' · ' . esc_html( $user->display_name ) : '' ) . '</li>';
			}
			echo '</ul><p class="prx-muted">' . esc_html( sprintf( /* translators: 1: hits, 2: created */ __( '%1$s hits · created %2$s', 'publishrank' ), number_format_i18n( $r['hits'] ), Suite::ago( $r['created_at'] ) ) ) . '</p>';
			Suite::card_close();
		} else {
			Suite::card_open( __( 'Add many at once', 'publishrank' ), 'fa-list', __( 'One rule per line: old new [code]. Use “old 410” for removed content. Up to 1,000 lines; everything is one undo step.', 'publishrank' ) );
			Suite::form_open( 'publishrank_seo_redirect_quick' );
			echo '<textarea name="bulk" rows="10" class="prx-textarea prx-mono" placeholder="/old-1 /new-1&#10;/old-2 /new-2 302&#10;/removed 410" aria-label="' . esc_attr__( 'Redirect lines', 'publishrank' ) . '"></textarea><div class="prx-actions-row"><button class="prx-btn" type="submit">' . esc_html__( 'Add rules', 'publishrank' ) . '</button></div></form>';
			Suite::card_close();
		}
		echo '</div>';
		Suite::panel_close();
	}

	private function panel_tester() {
		$test = isset( $_GET['test_url'] ) ? sanitize_text_field( wp_unslash( $_GET['test_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		Suite::panel_open( 'tester', '' === $test );
		Suite::card_open( __( 'Test an address', 'publishrank' ), 'fa-vial', __( 'Follows every hop exactly as a visitor would (conditions are ignored, schedules respected) and warns about chains and loops.', 'publishrank' ) );
		echo '<form method="get" class="prx-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input class="prx-input" type="text" name="test_url" value="' . esc_attr( $test ) . '" placeholder="/old-story/?utm_source=x" aria-label="' . esc_attr__( 'Address to test', 'publishrank' ) . '" /><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Trace', 'publishrank' ) . '</button></form>';
		if ( '' !== $test ) {
			$hops = self::trace( $test );
			echo '<ol class="prx-trace" data-hops="' . (int) count( $hops ) . '">';
			if ( ! $hops ) {
				echo '<li class="prx-trace__none">' . esc_html__( 'No redirect applies to this address.', 'publishrank' ) . '</li>';
			}
			foreach ( $hops as $h ) {
				echo '<li class="' . ( $h['loop'] ? 'is-loop' : '' ) . '"><code class="prx-code">' . esc_html( $h['from'] ) . '</code> <span class="fa-solid fa-arrow-right" aria-hidden="true"></span> ' . Suite::badge( (string) $h['status'], in_array( (int) $h['status'], array( 410, 451 ), true ) ? 'bad' : 'good' ) . ' ' . ( '' !== $h['to'] ? '<code class="prx-code">' . esc_html( $h['to'] ) . '</code>' : '' ) . ' <span class="prx-muted">' . esc_html( sprintf( /* translators: 1: match type 2: rule id */ __( '%1$s rule #%2$d', 'publishrank' ), $h['match'], $h['id'] ) ) . '</span>' . ( $h['loop'] ? ' ' . Suite::badge( __( 'Loop', 'publishrank' ), 'bad' ) : '' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ol>';
			if ( count( $hops ) > 1 ) {
				echo '<p class="prx-callout prx-callout--warn">' . esc_html__( 'This is a redirect chain. Edit the first rule to point straight at the final address.', 'publishrank' ) . '</p>';
			}
		}
		Suite::card_close();
		Suite::panel_close();
	}

	/** @return array<int,array{from:string,to:string,status:int,match:string,id:int,loop:bool}> */
	public static function trace( $url ) {
		$hops = array();
		$seen = array();
		$cur  = (string) $url;
		$home = R::home_host();
		for ( $i = 0; $i < 12; $i++ ) {
			$host = strtolower( (string) wp_parse_url( $cur, PHP_URL_HOST ) );
			if ( '' !== $host && $host !== $home ) {
				break;
			}
			$path  = (string) wp_parse_url( $cur, PHP_URL_PATH );
			$query = (string) wp_parse_url( $cur, PHP_URL_QUERY );
			$key   = R::key( $path ) . '?' . $query;
			$loop  = isset( $seen[ $key ] );
			$m     = R::match( $path . ( '' !== $query ? '?' . $query : '' ), array( 'query' => $query, 'ignore_conditions' => true ) );
			if ( ! $m ) {
				break;
			}
			$seen[ $key ] = true;
			$to           = in_array( (int) $m['rule']['status'], array( 410, 451 ), true ) ? '' : R::apply_query( $m['rule'], $m['target'], $query ? wp_parse_args( $query ) : array() );
			$hops[]       = array( 'from' => $path . ( '' !== $query ? '?' . $query : '' ), 'to' => $to, 'status' => (int) $m['rule']['status'], 'match' => $m['rule']['match_type'], 'id' => (int) $m['rule']['id'], 'loop' => $loop );
			if ( $loop || '' === $to ) {
				break;
			}
			$cur = $to;
		}
		return $hops;
	}

	private function panel_tools() {
		Suite::panel_open( 'tools' );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::card_open( __( 'Import', 'publishrank' ), 'fa-file-import', __( 'CSV from PublishRank, Yoast SEO Premium, Rank Math or Redirection, or an Apache .htaccess file (Redirect, RedirectMatch and RewriteRule [R]/[G] lines). Up to 20,000 rows; one undo step.', 'publishrank' ) );
		Suite::form_open( 'publishrank_seo_redirect_import', array( 'enctype' => 'multipart/form-data' ) );
		Suite::field( 'select', 'format', 'csv', __( 'File type', 'publishrank' ), '', array(), array( 'csv' => 'CSV', 'htaccess' => '.htaccess' ) );
		echo '<div class="prx-field"><label for="prx-import-file">' . esc_html__( 'File', 'publishrank' ) . '</label><input id="prx-import-file" type="file" name="file" accept=".csv,.txt,.htaccess,text/csv,text/plain" required /></div>';
		Suite::field( 'select', 'group_id', '0', __( 'Put imported rules in group', 'publishrank' ), '', array(), array( 0 => __( 'No group', 'publishrank' ) ) + R::groups() );
		Suite::switch_field( 'dry_run', true, __( 'Dry run first', 'publishrank' ), __( 'Check every row and report problems without saving anything.', 'publishrank' ) );
		echo '<div class="prx-actions-row"><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Import', 'publishrank' ) . '</button></div></form>';
		Suite::card_close();
		Suite::card_open( __( 'Export', 'publishrank' ), 'fa-file-export', __( 'Downloads the rules that match the filters on the Rules tab (or all of them). Spreadsheet formulas are neutralised.', 'publishrank' ) );
		$url = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'publishrank_seo_redirect_export' ), array_filter( array_map( 'strval', self::filters() ) ) ), admin_url( 'admin-post.php' ) ), 'publishrank_seo_redirect_export' );
		echo '<a class="prx-btn" href="' . esc_url( $url ) . '"><span class="fa-solid fa-download" aria-hidden="true"></span> ' . esc_html__( 'Download CSV', 'publishrank' ) . '</a>';
		echo '<p class="prx-muted">' . esc_html__( 'Also available from the command line: wp publishrank redirect export --file=redirects.csv', 'publishrank' ) . '</p>';
		Suite::card_close();
		echo '</div>';
		Suite::panel_close();
	}

	private function panel_history() {
		Suite::panel_open( 'history' );
		Suite::card_open( __( 'Change history', 'publishrank' ), 'fa-clock-rotate-left', __( 'Every save, import, bulk action and automatic redirect is recorded. Undo puts every affected rule back exactly as it was.', 'publishrank' ) );
		$rows = R::batches( 60 );
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'When', 'publishrank' ) . '</th><th>' . esc_html__( 'What', 'publishrank' ) . '</th><th>' . esc_html__( 'Changes', 'publishrank' ) . '</th><th>' . esc_html__( 'By', 'publishrank' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5"><div class="prx-empty"><p>' . esc_html__( 'Nothing has changed yet.', 'publishrank' ) . '</p></div></td></tr>';
		}
		foreach ( $rows as $b ) {
			$user = get_userdata( (int) $b['user_id'] );
			echo '<tr><td>' . esc_html( Suite::ago( $b['created_at'] ) ) . '</td><td>' . esc_html( $b['label'] ? $b['label'] : ucwords( str_replace( ',', ', ', (string) $b['actions'] ) ) ) . '</td><td>' . (int) $b['changes'] . '</td><td>' . esc_html( $user ? $user->display_name : __( 'System', 'publishrank' ) ) . '</td><td>';
			Suite::form_open( 'publishrank_seo_redirect_rollback' );
			echo '<input type="hidden" name="batch" value="' . esc_attr( $b['batch'] ) . '" /><button class="prx-btn prx-btn--sm" type="submit" data-prx-confirm="' . esc_attr__( 'Undo this change?', 'publishrank' ) . '">' . esc_html__( 'Undo', 'publishrank' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
		Suite::card_close();
		Suite::panel_close();
	}

	private function panel_settings() {
		Suite::panel_open( 'settings' );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::card_open( __( 'Behaviour', 'publishrank' ), 'fa-sliders' );
		Suite::form_open( 'publishrank_seo_redirect_settings' );
		Suite::switch_field( 'enabled', get_option( 'publishrank_redirect_enabled', 1 ), __( 'Redirects are on', 'publishrank' ) );
		Suite::switch_field( 'auto_permalink', get_option( 'publishrank_redirect_auto_enabled', 1 ), __( 'Create a redirect automatically when a URL changes', 'publishrank' ), __( 'Covers posts, pages, child pages, categories, tags and author addresses.', 'publishrank' ) );
		Suite::switch_field( 'only_404', get_option( 'publishrank_redirect_only_404', 1 ), __( 'Only redirect addresses that would be “not found”', 'publishrank' ), __( 'Recommended. Rules marked “apply even when the page exists” still run.', 'publishrank' ) );
		Suite::switch_field( 'preserve_query', get_option( 'publishrank_redirect_preserve_query', 1 ), __( 'Pass query strings on by default', 'publishrank' ) );
		Suite::switch_field( 'case_insensitive', R::case_insensitive(), __( 'Ignore upper/lower case', 'publishrank' ) );
		Suite::switch_field( 'ignore_trailing', R::ignore_trailing_slash(), __( 'Ignore a trailing slash', 'publishrank' ) );
		Suite::switch_field( 'log_hits', get_option( 'publishrank_redirect_log_hits', 1 ), __( 'Count hits', 'publishrank' ), __( 'Counts are buffered and written once per request.', 'publishrank' ) );
		Suite::switch_field( 'x_header', get_option( 'publishrank_redirect_x_header', 1 ), __( 'Send X-Redirect-By: PublishRank', 'publishrank' ) );
		Suite::field( 'number', 'max_hops', (string) get_option( 'publishrank_redirect_max_hops', 10 ), __( 'Longest chain to follow when flattening', 'publishrank' ), '', array( 'min' => 1, 'max' => 50 ) );
		Suite::field( 'textarea', 'external_hosts', (string) get_option( 'publishrank_redirect_external_hosts', '' ), __( 'Websites you may redirect to', 'publishrank' ), __( 'One host per line. *.example.com allows every subdomain. Anything else is refused, so a compromised editor account cannot send readers to a malicious site.', 'publishrank' ), array( 'rows' => 4, 'placeholder' => "partner.org\n*.example.com" ) );
		echo '<div class="prx-actions-row"><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Save settings', 'publishrank' ) . '</button></div></form>';
		Suite::card_close();
		Suite::card_open( __( 'Groups', 'publishrank' ), 'fa-folder-tree', __( 'Organise rules by project, campaign or site move.', 'publishrank' ) );
		echo '<ul class="prx-list">';
		foreach ( R::groups() as $gid => $name ) {
			echo '<li><span>' . esc_html( $name ) . ' <span class="prx-muted">' . esc_html( number_format_i18n( R::count( array( 'group_id' => $gid ) ) ) ) . '</span></span>';
			Suite::form_open( 'publishrank_seo_redirect_group', array( 'class' => 'prx-inline' ) );
			echo '<input type="hidden" name="op" value="delete" /><input type="hidden" name="group_id" value="' . (int) $gid . '" /><button class="prx-btn prx-btn--sm prx-btn--ghost" type="submit" data-prx-confirm="' . esc_attr__( 'Remove this group? Its redirects are kept.', 'publishrank' ) . '">' . esc_html__( 'Remove', 'publishrank' ) . '</button></form></li>';
		}
		echo '</ul>';
		Suite::form_open( 'publishrank_seo_redirect_group', array( 'class' => 'prx-filters' ) );
		echo '<input class="prx-input" type="text" name="name" maxlength="120" required placeholder="' . esc_attr__( 'New group name', 'publishrank' ) . '" aria-label="' . esc_attr__( 'New group name', 'publishrank' ) . '" /><button class="prx-btn" type="submit">' . esc_html__( 'Add group', 'publishrank' ) . '</button></form>';
		Suite::card_close();
		echo '</div>';
		Suite::panel_close();
	}
}
