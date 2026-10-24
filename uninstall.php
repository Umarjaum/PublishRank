<?php
/** Remove PublishRank options, scheduled events and local content-graph tables. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

	$publishrank_cleanup = static function () {
		global $wpdb;
		$private_meta      = array( 'publishrank_supabase_session_enc', 'publishrank_supabase_subject', 'publishrank_supabase_verified_at' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s, %s)', $wpdb->usermeta, ...$private_meta ) );
		$post_meta              = array( '_publishrank_baidu_submitted', '_publishrank_baidu_pending' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s)', $wpdb->postmeta, ...$post_meta ) );
		$like = $wpdb->esc_like( 'publishrank_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, $wpdb->esc_like( '_transient_publishrank_' ) . '%', $wpdb->esc_like( '_transient_timeout_publishrank_' ) . '%' ) );
		wp_unschedule_hook( 'publishrank_gsc_sync' );
		wp_unschedule_hook( 'publishrank_indexnow_submit' );
		wp_unschedule_hook( 'publishrank_baidu_submit_post' );
		wp_unschedule_hook( 'publishrank_daily_analytics_snapshot' );
		require_once plugin_dir_path( __FILE__ ) . 'src/InternalLinks/Store.php';
		\PublishRankSEO\InternalLinks\Store::drop();
	};

	if ( is_multisite() ) {
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $publishrank_site_id ) {
			switch_to_blog( $publishrank_site_id );
			$publishrank_cleanup();
			restore_current_blog();
		}
	} else {
		$publishrank_cleanup();
	}
