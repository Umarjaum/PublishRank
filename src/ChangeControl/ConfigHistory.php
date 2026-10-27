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
		if ( ! get_option( SnapshotStore::OPTION, false ) ) {
			SnapshotStore::snapshot( __( 'Initial SEO configuration', 'publishrank' ) ); }
	}
	public function changed( $old, $new, $option ) {
		if ( wp_doing_ajax() && ! current_user_can( 'manage_options' ) ) {
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
