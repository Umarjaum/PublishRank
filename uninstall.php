<?php
/** Remove PublishRank options, scheduled events and local content-graph tables. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

	$publishrank_cleanup = static function () {
		global $wpdb;
		// Remove only PublishRank's managed Apache block so uninstall cannot leave /wp-admin blocked.
		if ( ! function_exists( 'get_home_path' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$publishrank_home_path = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$publishrank_htaccess   = trailingslashit( $publishrank_home_path ) . '.htaccess';
		if ( is_readable( $publishrank_htaccess ) && is_writable( $publishrank_htaccess ) ) {
			$publishrank_rules = file_get_contents( $publishrank_htaccess );
			if ( is_string( $publishrank_rules ) ) {
				$publishrank_rules = preg_replace( '/\n?# BEGIN PublishRank WP Hide Login Dashboard\R.*?\R# END PublishRank WP Hide Login Dashboard\R?/s', "\n", $publishrank_rules, 1 );
				file_put_contents( $publishrank_htaccess, $publishrank_rules, LOCK_EX );
			}
		}
		delete_option( 'rewrite_rules' );
		$private_meta      = array( 'publishrank_supabase_session_enc', 'publishrank_supabase_subject', 'publishrank_supabase_verified_at', 'publishrank_ps_seen' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s, %s, %s)', $wpdb->usermeta, ...$private_meta ) );
		$post_meta              = array( '_publishrank_baidu_submitted', '_publishrank_baidu_pending' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s)', $wpdb->postmeta, ...$post_meta ) );
		$like = $wpdb->esc_like( 'publishrank_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, $wpdb->esc_like( '_transient_publishrank_' ) . '%', $wpdb->esc_like( '_transient_timeout_publishrank_' ) . '%' ) );
		// Newsroom settings (workflow, desks, calendar token, reminders) use the uj_newsroom_ prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'uj_newsroom_' ) . '%' ) );
		wp_unschedule_hook( 'uj_newsroom_daily_reminders' );
		wp_unschedule_hook( 'publishrank_indexnow_flush_queue' );
		wp_unschedule_hook( 'publishrank_gsc_sync' );
		wp_unschedule_hook( 'publishrank_indexnow_submit' );
		wp_unschedule_hook( 'publishrank_baidu_submit_post' );
		wp_unschedule_hook( 'publishrank_daily_analytics_snapshot' );
		wp_unschedule_hook( 'publishrank_weekly_audit' );
		wp_unschedule_hook( 'publishrank_prepare_sitemap_cursors' );
		wp_unschedule_hook( 'publishrank_continue_sitemap_cursor_preparation' );
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

// PublishRank Private Login settings (current and earlier option names).
foreach ( array( 'publishrank_login_slug', 'publishrank_login_redirect', 'publishrank_private_login_enabled', 'publishrank_private_login_deferred', 'publishrank_private_login_storage', 'publishrank_hide_login_enabled', 'publishrank_hide_login_deferred', 'whl_page', 'whl_redirect_admin' ) as $publishrank_login_option ) {
	delete_option( $publishrank_login_option );
}
delete_site_transient( 'publishrank_private_login_comment_notice' );
delete_option( 'publishrank_wp_hide_login' );
delete_option( 'publishrank_wp_hide_login_apache_rules_active' );
if ( is_multisite() ) {
	foreach ( array( 'publishrank_login_slug', 'publishrank_login_redirect', 'whl_page', 'whl_redirect_admin' ) as $publishrank_login_option ) {
		delete_site_option( $publishrank_login_option );
	}
}
