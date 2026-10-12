<?php
/** Admin UI for SEO configuration snapshots and safe rollback. */
namespace PublishRankSEO\Admin;
use PublishRankSEO\ChangeControl\SnapshotStore;

defined( 'ABSPATH' ) || exit;

final class ConfigHistoryPage {
	const SLUG = 'publishrank-seo-config-history';
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_post_publishrank_snapshot_create', array( $this, 'create' ) );
		add_action( 'admin_post_publishrank_snapshot_restore', array( $this, 'restore' ) );
	}
	public function menu() {
		add_submenu_page( \PublishRankSEO\Core\Branding::MENU_SLUG, __( 'SEO Configuration History', 'publishrank' ), __( 'Configuration History', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}
	public function create() { AdminAssets::guard( 'publishrank_snapshot_create' ); SnapshotStore::snapshot( __( 'Manual SEO configuration snapshot', 'publishrank' ) ); AdminAssets::done( self::SLUG, 'success', __( 'Snapshot created.', 'publishrank' ) ); }
	public function restore() { AdminAssets::guard( 'publishrank_snapshot_restore' ); $id = isset( $_POST['snapshot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot_id'] ) ) : ''; $r = SnapshotStore::restore( $id ); if ( is_wp_error( $r ) ) { AdminAssets::done( self::SLUG, 'error', $r->get_error_message() ); } AdminAssets::done( self::SLUG, 'success', __( 'Configuration restored. A safety snapshot was created first.', 'publishrank' ) ); }
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="wrap">'; AdminAssets::header( __( 'SEO Configuration History', 'publishrank' ), __( 'Automatic, bounded snapshots of important PublishRank settings with safe rollback.', 'publishrank' ) ); AdminAssets::render_flash();
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0;"><input type="hidden" name="action" value="publishrank_snapshot_create" /><?php wp_nonce_field( 'publishrank_snapshot_create' ); ?><?php submit_button( __( 'Create snapshot now', 'publishrank' ), 'primary', 'submit', false ); ?></form>
		<div class="rhs-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Time', 'publishrank' ); ?></th><th><?php esc_html_e( 'Label', 'publishrank' ); ?></th><th><?php esc_html_e( 'Actor', 'publishrank' ); ?></th><th><?php esc_html_e( 'Settings', 'publishrank' ); ?></th><th></th></tr></thead><tbody><?php $rows = SnapshotStore::all(); if ( empty( $rows ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No snapshots yet.', 'publishrank' ); ?></td></tr><?php else : foreach ( $rows as $row ) : ?><tr><td><code><?php echo esc_html( isset( $row['time'] ) ? $row['time'] : '' ); ?></code></td><td><?php echo esc_html( isset( $row['label'] ) ? $row['label'] : '' ); ?></td><td><?php echo esc_html( isset( $row['actor'] ) ? $row['actor'] : '' ); ?></td><td><?php echo esc_html( isset( $row['data'] ) && is_array( $row['data'] ) ? count( $row['data'] ) : 0 ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Restore this configuration? A safety snapshot will be created first.', 'publishrank' ) ); ?>');"><input type="hidden" name="action" value="publishrank_snapshot_restore" /><input type="hidden" name="snapshot_id" value="<?php echo esc_attr( $row['id'] ); ?>" /><?php wp_nonce_field( 'publishrank_snapshot_restore' ); ?><button class="button"><?php esc_html_e( 'Restore', 'publishrank' ); ?></button></form></td></tr><?php endforeach; endif; ?></tbody></table></div></div><?php
	}
}
