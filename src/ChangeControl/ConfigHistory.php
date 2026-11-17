<?php
/** Watches important SEO settings and creates bounded configuration history. */
namespace PublishRankSEO\ChangeControl;

defined( 'ABSPATH' ) || exit;

final class ConfigHistory {
	public function register() {
		add_action( 'init', array( $this, 'ensure' ), 100 );
		foreach ( SnapshotStore::tracked_options() as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'changed' ), 20, 3 ); }
	}
	public function ensure() {
		// Only in the admin: reading the (large) history on every public page costs a query and memory for nothing.
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! get_option( 'publishrank_seo_config_snapshot_initial', 0 ) && ! get_option( SnapshotStore::OPTION, false ) ) {
			SnapshotStore::snapshot( __( 'Initial SEO configuration', 'publishrank' ) ); }
		update_option( 'publishrank_seo_config_snapshot_initial', 1, true );
	}
	public function changed( $old, $new, $option ) {
		if ( wp_doing_ajax() && ! \PublishRankSEO\Core\Capabilities::can( 'manage_settings' ) ) {
			return; }
		if ( wp_json_encode( $old ) === wp_json_encode( $new ) ) {
			return; }
		$last = SnapshotStore::all();
		/* translators: %s: WordPress option name. */
		if ( ! empty( $last[0]['data'][ $option ] ) && wp_json_encode( $last[0]['data'][ $option ] ) === wp_json_encode( $old ) ) {
			/* translators: %s: SEO setting option name that changed. */
			SnapshotStore::snapshot( sprintf( __( 'Changed %s', 'publishrank' ), $option ) ); }
	}
}
