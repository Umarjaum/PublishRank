<?php
/** Redirect manager screen. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Redirect\RedirectStore;

defined( 'ABSPATH' ) || exit;

final class RedirectsPage {
	const SLUG     = 'publishrank-seo-redirects';
	const PER_PAGE = 50;

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_post_publishrank_seo_redirect_add', array( $this, 'handle_add' ) );
		add_action( 'admin_post_publishrank_seo_redirect_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_publishrank_seo_redirect_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_publishrank_seo_redirect_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_publishrank_seo_redirect_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_publishrank_seo_redirect_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_publishrank_seo_redirect_bulk_delete', array( $this, 'handle_bulk_delete' ) );
	}

	/** Download every rule as CSV (re-importable here, readable in any spreadsheet). */
	public function handle_export() {
		AdminAssets::guard( 'publishrank_seo_redirect_export' );
		check_admin_referer( 'publishrank_seo_redirect_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=publishrank-redirects-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a download.
		fputcsv( $out, array( 'source', 'target', 'status', 'regex', 'start_at', 'end_at' ), ',', '"', '\\' );
		foreach ( RedirectStore::all() as $rule ) {
			$row = array( (string) ( $rule['source'] ?? '' ), (string) ( $rule['target'] ?? '' ), (int) ( $rule['status'] ?? 301 ), empty( $rule['regex'] ) ? 0 : 1, empty( $rule['start_at'] ) ? '' : gmdate( 'c', (int) $rule['start_at'] ), empty( $rule['end_at'] ) ? '' : gmdate( 'c', (int) $rule['end_at'] ) );
			// Spreadsheet formula injection guard.
			$row = array_map( static function ( $v ) { $v = (string) $v; return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v; }, $row );
			fputcsv( $out, $row, ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public function handle_import() {
		AdminAssets::guard( 'publishrank_seo_redirect_import' );
		check_admin_referer( 'publishrank_seo_redirect_import' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the temporary path is used; the content is parsed and validated rule by rule.
		$file = isset( $_FILES['csv']['tmp_name'] ) ? (string) $_FILES['csv']['tmp_name'] : '';
		if ( '' === $file || ! is_uploaded_file( $file ) || filesize( $file ) > 2 * MB_IN_BYTES ) {
			AdminAssets::done( self::SLUG, 'error', __( 'Choose a CSV file up to 2 MB.', 'publishrank' ) );
		}
		$rows  = array_slice( RedirectStore::parse_csv( (string) file_get_contents( $file ) ), 0, 2000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$added = 0;
		$skip  = array();
		foreach ( $rows as $i => $row ) {
			$result = RedirectStore::add( $row['source'], $row['target'], $row['status'], $row['regex'] );
			if ( true === $result ) {
				++$added;
			} else {
				$skip[] = sprintf( '#%d %s', $i + 2, is_wp_error( $result ) ? $result->get_error_message() : '' );
			}
		}
		/* translators: 1: imported rules, 2: skipped rows. */
		$msg = sprintf( __( '%1$d redirect(s) imported, %2$d row(s) skipped.', 'publishrank' ), $added, count( $skip ) );
		if ( $skip ) {
			$msg .= ' ' . implode( ' · ', array_slice( $skip, 0, 5 ) );
		}
		AdminAssets::done( self::SLUG, $skip ? 'error' : 'success', $msg );
	}

	public function handle_bulk_delete() {
		AdminAssets::guard( 'publishrank_seo_redirect_bulk_delete' );
		check_admin_referer( 'publishrank_seo_redirect_bulk_delete' );
		$ids     = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$removed = 0;
		foreach ( $ids as $id ) {
			$removed += RedirectStore::delete( $id ) ? 1 : 0;
		}
		/* translators: %d: number of removed rules. */
		AdminAssets::done( self::SLUG, 'success', sprintf( _n( '%d redirect removed.', '%d redirects removed.', $removed, 'publishrank' ), $removed ) );
	}

	public function menu() {
		add_submenu_page( \PublishRankSEO\Core\Branding::MENU_SLUG, __( 'Redirects', 'publishrank' ), __( 'Redirects', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function handle_add() {
		AdminAssets::guard( 'publishrank_seo_redirect_add' );
		check_admin_referer( 'publishrank_seo_redirect_add' ); // Explicit nonce verification for the request data read below.
		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard.
		$target = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$status = isset( $_POST['status'] ) ? absint( $_POST['status'] ) : 301;
		$start_at = isset( $_POST['start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['start_at'] ) ) : '';
		$end_at   = isset( $_POST['end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['end_at'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$result = RedirectStore::add( $source, $target, $status, ! empty( $_POST['regex'] ), $start_at, $end_at );
		if ( is_wp_error( $result ) ) {
			AdminAssets::done( self::SLUG, 'error', $result->get_error_message() );
		}
		AdminAssets::done( self::SLUG, 'success', __( 'Redirect saved.', 'publishrank' ) );
	}

	public function handle_bulk() {
		AdminAssets::guard( 'publishrank_seo_redirect_bulk' );
		check_admin_referer( 'publishrank_seo_redirect_bulk' ); // Explicit nonce verification for the request data read below.
		$text  = isset( $_POST['bulk'] ) ? wp_strip_all_tags( wp_unslash( $_POST['bulk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard.
		$rows  = array_slice( RedirectStore::parse_bulk( $text ), 0, 500 );
		$added = 0;
		$skip  = 0;
		foreach ( $rows as $row ) {
			if ( true === RedirectStore::add( $row['source'], $row['target'], $row['status'], false ) ) {
				++$added;
			} else {
				++$skip;
			}
		}
		/* translators: 1: saved rules, 2: skipped lines. */
		AdminAssets::done( self::SLUG, $skip ? 'error' : 'success', sprintf( __( '%1$d redirect(s) saved, %2$d line(s) skipped.', 'publishrank' ), $added, $skip ) );
	}

	public function handle_settings() {
		AdminAssets::guard( 'publishrank_seo_redirect_settings' );
		check_admin_referer( 'publishrank_seo_redirect_settings' ); // Explicit nonce verification for the request data read below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		update_option( 'publishrank_redirect_enabled', ! empty( $_POST['enabled'] ) ? 1 : 0, false );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		update_option( 'publishrank_redirect_only_404', ! empty( $_POST['only_404'] ) ? 1 : 0, false );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		update_option( 'publishrank_redirect_preserve_query', ! empty( $_POST['preserve_query'] ) ? 1 : 0, false );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		update_option( 'publishrank_redirect_x_header', ! empty( $_POST['x_header'] ) ? 1 : 0, false );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		update_option( 'publishrank_redirect_max_hops', min( 50, max( 1, isset( $_POST['max_hops'] ) ? absint( wp_unslash( $_POST['max_hops'] ) ) : 10 ) ), false );
		AdminAssets::done( self::SLUG, 'success', __( 'Redirect behavior saved.', 'publishrank' ) );
	}

	public function handle_delete() {
		AdminAssets::guard( 'publishrank_seo_redirect_delete' );
		check_admin_referer( 'publishrank_seo_redirect_delete' );
		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		RedirectStore::delete( $id );
		AdminAssets::done( self::SLUG, 'success', __( 'Redirect removed.', 'publishrank' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rules = RedirectStore::all();
		$total = count( $rules );
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$slice = array_slice( $rules, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		echo '<div class="wrap">';
			AdminAssets::header( __( 'Redirects', 'publishrank' ), __( 'Send old URLs to the right place with loop-safe 301, 302, 303, 307, 308, 410 and 451 rules.', 'publishrank' ) );
		AdminAssets::render_flash();
		?>
		<div class="rhs-grid">
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Add a redirect', 'publishrank' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="publishrank_seo_redirect_add" />
					<?php wp_nonce_field( 'publishrank_seo_redirect_add' ); ?>
					<p><label for="rhs-source"><strong><?php esc_html_e( 'Old path or URL on this site', 'publishrank' ); ?></strong></label><br /><input id="rhs-source" name="source" type="text" class="large-text" placeholder="/old-article" required /><br /><span class="description"><?php esc_html_e( 'Use a path or a full URL on this site. External source URLs are rejected because rules match this site’s paths only.', 'publishrank' ); ?></span></p>
					<p><label for="rhs-target"><strong><?php esc_html_e( 'New path or URL', 'publishrank' ); ?></strong></label><br /><input id="rhs-target" name="target" type="text" class="large-text" placeholder="/new-article" /></p>
					<p><label for="rhs-status"><strong><?php esc_html_e( 'Type', 'publishrank' ); ?></strong></label><br />
						<select id="rhs-status" name="status">
							<option value="301"><?php esc_html_e( '301 — Moved permanently', 'publishrank' ); ?></option>
							<option value="302"><?php esc_html_e( '302 — Found (temporary)', 'publishrank' ); ?></option>
							<option value="303"><?php esc_html_e( '303 — See other (temporary)', 'publishrank' ); ?></option>
							<option value="307"><?php esc_html_e( '307 — Temporary redirect', 'publishrank' ); ?></option>
							<option value="308"><?php esc_html_e( '308 — Permanent redirect', 'publishrank' ); ?></option>
							<option value="410"><?php esc_html_e( '410 — Content deleted (no target)', 'publishrank' ); ?></option>
							<option value="451"><?php esc_html_e( '451 — Unavailable for legal reasons (no target)', 'publishrank' ); ?></option>
						</select></p>
					<p><label><input type="checkbox" name="regex" value="1" /> <?php esc_html_e( 'Treat the old path as a regular expression', 'publishrank' ); ?></label><br /><span class="description"><?php esc_html_e( 'Regex rules are limited to 255 characters; matching is bounded to protect request performance.', 'publishrank' ); ?></span></p>
					<fieldset><legend><strong><?php esc_html_e( 'Schedule (optional)', 'publishrank' ); ?></strong></legend><p><label for="rhs-start-at"><?php esc_html_e( 'Start time', 'publishrank' ); ?></label><br /><input id="rhs-start-at" name="start_at" type="datetime-local" /></p><p><label for="rhs-end-at"><?php esc_html_e( 'End time', 'publishrank' ); ?></label><br /><input id="rhs-end-at" name="end_at" type="datetime-local" /></p><span class="description"><?php echo esc_html( sprintf( __( 'Uses the site timezone: %s. Before the start and after the end, this redirect automatically becomes inactive.', 'publishrank' ), wp_timezone_string() ?: 'UTC' ) ); ?></span></fieldset>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save redirect', 'publishrank' ); ?></button>
				</form>
			</div>
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Redirect behavior', 'publishrank' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="publishrank_seo_redirect_settings" /><?php wp_nonce_field( 'publishrank_seo_redirect_settings' ); ?>
					<p><label><input type="checkbox" name="enabled" value="1" <?php checked( get_option( 'publishrank_redirect_enabled', true ) ); ?> /> <?php esc_html_e( 'Enable redirect engine', 'publishrank' ); ?></label></p>
					<p><label><input type="checkbox" name="only_404" value="1" <?php checked( get_option( 'publishrank_redirect_only_404', true ) ); ?> /> <?php esc_html_e( 'Only process URLs that are 404', 'publishrank' ); ?></label></p>
					<p><label><input type="checkbox" name="preserve_query" value="1" <?php checked( get_option( 'publishrank_redirect_preserve_query', true ) ); ?> /> <?php esc_html_e( 'Preserve query parameters', 'publishrank' ); ?></label></p>
					<p><label><input type="checkbox" name="x_header" value="1" <?php checked( get_option( 'publishrank_redirect_x_header', true ) ); ?> /> <?php esc_html_e( 'Send X-Redirect-By: PublishRank', 'publishrank' ); ?></label></p>
					<p><label for="rhs-max-hops"><strong><?php esc_html_e( 'Maximum redirect-chain depth', 'publishrank' ); ?></strong></label><br /><input id="rhs-max-hops" name="max_hops" type="number" min="1" max="50" value="<?php echo esc_attr( get_option( 'publishrank_redirect_max_hops', 10 ) ); ?>" /></p>
					<?php submit_button( __( 'Save behavior', 'publishrank' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Bulk add', 'publishrank' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="publishrank_seo_redirect_bulk" />
					<?php wp_nonce_field( 'publishrank_seo_redirect_bulk' ); ?>
					<p class="description"><?php esc_html_e( 'One rule per line: old-path new-path [status]. Use "old-path 410" for removed content. Plain paths only; up to 500 lines.', 'publishrank' ); ?></p>
					<textarea name="bulk" rows="7" class="large-text code" placeholder="/old-1 /new-1&#10;/old-2 /new-2 302&#10;/removed 410"></textarea>
					<?php submit_button( __( 'Add rules', 'publishrank' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Import and export', 'publishrank' ); ?></h2>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="publishrank_seo_redirect_import" /><?php wp_nonce_field( 'publishrank_seo_redirect_import' ); ?>
					<p class="description"><?php esc_html_e( 'CSV with a header row. Exports from PublishRank, Yoast SEO Premium, Rank Math and Redirection are recognised (source, target, status and regex columns). Every row is validated; existing rules with the same source are updated.', 'publishrank' ); ?></p>
					<p><label for="rhs-csv" class="screen-reader-text"><?php esc_html_e( 'CSV file', 'publishrank' ); ?></label><input id="rhs-csv" type="file" name="csv" accept=".csv,text/csv" required /></p>
					<?php submit_button( __( 'Import CSV', 'publishrank' ), 'secondary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_seo_redirect_export' ), 'publishrank_seo_redirect_export' ) ); ?>"><?php esc_html_e( 'Export all as CSV', 'publishrank' ); ?></a>
				</form>
			</div>
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Test an address', 'publishrank' ); ?></h2>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<?php $test = isset( $_GET['test_url'] ) ? sanitize_text_field( wp_unslash( $_GET['test_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup. ?>
					<p><label for="rhs-test"><strong><?php esc_html_e( 'Path or URL', 'publishrank' ); ?></strong></label><br /><input id="rhs-test" name="test_url" type="text" class="large-text" value="<?php echo esc_attr( $test ); ?>" placeholder="/old-article" /></p>
					<?php submit_button( __( 'Trace redirects', 'publishrank' ), 'secondary', '', false ); ?>
				</form>
				<?php
				if ( '' !== $test ) {
					$hops = RedirectStore::trace( $test );
					echo '<div class="rhs-trace" data-hops="' . (int) count( $hops ) . '">';
					if ( ! $hops ) {
						echo '<p>' . esc_html__( 'No redirect rule matches this address.', 'publishrank' ) . '</p>';
					}
					foreach ( $hops as $hop ) {
						echo '<p><code>' . esc_html( $hop['path'] ) . '</code> → <strong>' . (int) $hop['status'] . '</strong> ' . ( '' !== $hop['target'] ? '<code>' . esc_html( $hop['target'] ) . '</code>' : '' ) . ( $hop['regex'] ? ' <em>regex</em>' : '' ) . ( $hop['loop'] ? ' <strong style="color:#b32d2e">' . esc_html__( 'Loop detected', 'publishrank' ) . '</strong>' : '' ) . '</p>';
					}
					if ( count( $hops ) > 1 ) {
						echo '<p class="description">' . esc_html__( 'This is a redirect chain. Point the first rule straight at the final address to save a hop.', 'publishrank' ) . '</p>';
					}
					echo '</div>';
				}
				?>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Redirects apply to addresses that would otherwise return a 404 page. Rules are checked top to bottom and the first match wins.', 'publishrank' ); ?></p>
		<h2><?php echo esc_html( sprintf( /* translators: %d: number of rules. */ _n( '%d rule', '%d rules', $total, 'publishrank' ), $total ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rhs-bulk-delete">
		<input type="hidden" name="action" value="publishrank_seo_redirect_bulk_delete" /><?php wp_nonce_field( 'publishrank_seo_redirect_bulk_delete' ); ?>
		<p><button type="submit" class="button" onclick="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Delete the selected redirects?', 'publishrank' ); ?>"><?php esc_html_e( 'Delete selected', 'publishrank' ); ?></button></p>
		<div class="rhs-table-wrap"><table class="widefat striped">
			<thead><tr><td class="check-column"><input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'publishrank' ); ?>" onclick="var c=this.checked;this.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(function(b){b.checked=c;});" /></td><th><?php esc_html_e( 'Old', 'publishrank' ); ?></th><th><?php esc_html_e( 'New', 'publishrank' ); ?></th><th><?php esc_html_e( 'Type', 'publishrank' ); ?></th><th><?php esc_html_e( 'Match', 'publishrank' ); ?></th><th><?php esc_html_e( 'Schedule', 'publishrank' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php if ( empty( $slice ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No redirects yet.', 'publishrank' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $slice as $rule ) :
				$delete = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'publishrank_seo_redirect_delete',
							'id'     => RedirectStore::id( $rule ),
						),
						admin_url( 'admin-post.php' )
					),
					'publishrank_seo_redirect_delete'
				);
				?>
				<tr>
					<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( RedirectStore::id( $rule ) ); ?>" /></th>
					<td><code><?php echo esc_html( isset( $rule['source'] ) ? $rule['source'] : '' ); ?></code></td>
					<td><?php echo ! empty( $rule['target'] ) ? '<code>' . esc_html( $rule['target'] ) . '</code>' : '&mdash;'; ?></td>
					<td><?php echo esc_html( isset( $rule['status'] ) ? (string) (int) $rule['status'] : '301' ); ?></td>
					<td><?php echo ! empty( $rule['regex'] ) ? esc_html__( 'Regex', 'publishrank' ) : esc_html__( 'Exact', 'publishrank' ); ?></td>
					<td><span class="description"><?php echo esc_html( RedirectStore::schedule_label( $rule ) ); ?></span><?php if ( ! RedirectStore::is_scheduled_active( $rule ) ) : ?><br /><em><?php esc_html_e( 'Inactive now', 'publishrank' ); ?></em><?php endif; ?></td>
					<td><a class="button-link-delete" href="<?php echo esc_url( $delete ); ?>"><?php esc_html_e( 'Delete', 'publishrank' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		</form>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'total'   => $pages,
						'current' => $paged,
					)
				)
			) . '</div></div>';
		}
		echo '</div>';
	}
}
