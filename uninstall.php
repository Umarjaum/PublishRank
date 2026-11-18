<?php
/** Remove PublishRank options, scheduled events and local content-graph tables. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

	$publishrank_cleanup = static function () {
		global $wpdb;
		// Opt-in full erase (default OFF). Read before options are deleted below.
		$delete_data = (bool) get_option( 'publishrank_delete_data_on_uninstall', 0 );
		foreach ( array( 'publishrank_websub_ping', 'uj_newsroom_purge_trash', 'publishrank_404_retention', 'publishrank_shield_daily', 'publishrank_ai_log_retention', 'publishrank_ai_refresh_ip_lists', 'publishrank_ai_rdns_batch', 'publishrank_ai_citation_batch', 'publishrank_ai_citation_schedule' ) as $publishrank_hook ) {
			wp_unschedule_hook( $publishrank_hook );
		}
		if ( $delete_data ) {
			require_once plugin_dir_path( __FILE__ ) . 'src/Core/Database.php';
			require_once plugin_dir_path( __FILE__ ) . 'src/Core/Capabilities.php';
			\PublishRankSEO\Core\Database::drop_all();
			\PublishRankSEO\Core\Capabilities::uninstall();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $wpdb->postmeta, '_publishrank_schema_library' ) );
		}
		// Remove only the Apache block that PublishRank releases before 2.24 managed (its marker ends in "Login Dashboard"), so uninstall cannot leave /wp-admin blocked.
		if ( ! function_exists( 'get_home_path' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$publishrank_home_path = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$publishrank_htaccess   = trailingslashit( $publishrank_home_path ) . '.htaccess';
		if ( is_readable( $publishrank_htaccess ) && is_writable( $publishrank_htaccess ) ) {
			$publishrank_rules = file_get_contents( $publishrank_htaccess );
			if ( is_string( $publishrank_rules ) ) {
				$publishrank_rules = preg_replace( '/\n?# BEGIN (PublishRank [A-Za-z ]*Login Dashboard)\R.*?\R# END \1\R?/s', "\n", $publishrank_rules, 1 );
				file_put_contents( $publishrank_htaccess, $publishrank_rules, LOCK_EX );
			}
		}
		delete_option( 'rewrite_rules' );
		$private_meta      = array( 'publishrank_supabase_session_enc', 'publishrank_supabase_subject', 'publishrank_supabase_verified_at', 'publishrank_ps_seen', 'publishrank_news_seen', 'publishrank_news_dismissed' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s, %s, %s, %s, %s)', $wpdb->usermeta, ...$private_meta ) );
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
		wp_unschedule_hook( 'publishrank_news_refresh' );
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

// Access Shield login-address options and storage keys retired by earlier PublishRank releases.
require_once __DIR__ . '/src/AccessShield/LoginBridge.php';
$publishrank_login_options = \PublishRankSEO\AccessShield\LoginBridge::uninstall_options();
foreach ( $publishrank_login_options as $publishrank_login_option ) {
	delete_option( $publishrank_login_option );
	if ( is_multisite() ) {
		delete_site_option( $publishrank_login_option );
	}
}
delete_site_transient( 'publishrank_private_login_comment_notice' );
