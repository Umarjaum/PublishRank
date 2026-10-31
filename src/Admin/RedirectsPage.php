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
		AdminAssets::header( __( 'Redirects', 'publishrank' ), __( 'Send old URLs to the right place with loop-safe 301, 302, 307, 308 and 410 rules.', 'publishrank' ) );
		AdminAssets::render_flash();
		?>
		<div class="rhs-grid">
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Add a redirect', 'publishrank' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="publishrank_seo_redirect_add" />
					<?php wp_nonce_field( 'publishrank_seo_redirect_add' ); ?>
					<p><label for="rhs-source"><strong><?php esc_html_e( 'Old path or URL', 'publishrank' ); ?></strong></label><br /><input id="rhs-source" name="source" type="text" class="large-text" placeholder="/old-article" required /></p>
					<p><label for="rhs-target"><strong><?php esc_html_e( 'New path or URL', 'publishrank' ); ?></strong></label><br /><input id="rhs-target" name="target" type="text" class="large-text" placeholder="/new-article" /></p>
					<p><label for="rhs-status"><strong><?php esc_html_e( 'Type', 'publishrank' ); ?></strong></label><br />
						<select id="rhs-status" name="status">
							<option value="301"><?php esc_html_e( '301 — Moved permanently', 'publishrank' ); ?></option>
							<option value="302"><?php esc_html_e( '302 — Found (temporary)', 'publishrank' ); ?></option>
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
		</div>
		<p class="description"><?php esc_html_e( 'Redirects apply to addresses that would otherwise return a 404 page. Rules are checked top to bottom and the first match wins.', 'publishrank' ); ?></p>
		<h2><?php echo esc_html( sprintf( /* translators: %d: number of rules. */ _n( '%d rule', '%d rules', $total, 'publishrank' ), $total ) ); ?></h2>
		<div class="rhs-table-wrap"><table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Old', 'publishrank' ); ?></th><th><?php esc_html_e( 'New', 'publishrank' ); ?></th><th><?php esc_html_e( 'Type', 'publishrank' ); ?></th><th><?php esc_html_e( 'Match', 'publishrank' ); ?></th><th><?php esc_html_e( 'Schedule', 'publishrank' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php if ( empty( $slice ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No redirects yet.', 'publishrank' ); ?></td></tr>
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
