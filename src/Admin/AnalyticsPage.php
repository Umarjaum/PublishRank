<?php
/** Local SEO and IndexNow analytics dashboard. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\AnalyticsCollector;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Indexing\IndexNow;

final class AnalyticsPage {
	public function register() { add_action( 'admin_menu', array( $this, 'menu' ), 12 ); add_action( 'admin_post_publishrank_gsc_save', array( $this, 'save_credentials' ) ); add_action( 'admin_post_publishrank_gsc_connect', array( $this, 'connect' ) ); add_action( 'admin_post_publishrank_gsc_disconnect', array( $this, 'disconnect' ) ); add_action( 'admin_post_publishrank_gsc_callback', array( $this, 'oauth_callback' ) ); }

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Analytics', 'publishrank' ), __( 'Analytics', 'publishrank' ), 'manage_options', 'publishrank-analytics', array( $this, 'render' ) );
	}

	private function chart( $title, $description, $values, $label, $period = '' ) {
		$values = array_values( $values );
		if ( empty( $values ) ) { $values = array( 0 ); }
		$max = max( 1, max( array_map( 'intval', $values ) ) );
		$width = 720; $height = 190; $pad = 18; $points = array(); $count = count( $values );
		foreach ( $values as $i => $value ) {
			$x = $count > 1 ? $pad + ( $i * ( $width - 2 * $pad ) / ( $count - 1 ) ) : $width / 2;
			$y = $height - $pad - ( (int) $value / $max ) * ( $height - 2 * $pad );
			$points[] = round( $x, 2 ) . ',' . round( $y, 2 );
		}
		echo '<section class="pr-analytics-card"><h2>' . esc_html( $title ) . '</h2><p class="description">' . esc_html( $description ) . '</p><svg viewBox="0 0 720 190" role="img" aria-label="' . esc_attr( $title ) . '" style="width:100%;max-width:900px;height:auto;display:block">';
		foreach ( array( 45, 85, 125, 165 ) as $y ) { echo '<line x1="18" y1="' . (int) $y . '" x2="702" y2="' . (int) $y . '" stroke="#dcdcde" stroke-width="1" />'; }
		echo '<polyline fill="none" stroke="#3858e9" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="' . esc_attr( implode( ' ', $points ) ) . '" />';
		foreach ( $points as $point ) { $xy = explode( ',', $point ); echo '<circle cx="' . esc_attr( $xy[0] ) . '" cy="' . esc_attr( $xy[1] ) . '" r="4" fill="#3858e9" />'; }
		echo '</svg><p class="description">' . esc_html( $period ) . '</p><p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( number_format_i18n( array_sum( array_map( 'intval', $values ) ) ) ) . '</p></section>';
	}

	private function gsc_settings() {
		$settings = get_option( 'publishrank_gsc_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	private function callback_url() { return admin_url( 'admin-post.php?action=publishrank_gsc_callback' ); }

	public function save_credentials() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'publishrank_gsc_save' );
		$old = $this->gsc_settings();
		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$client_secret = isset( $_POST['client_secret'] ) ? trim( (string) wp_unslash( $_POST['client_secret'] ) ) : '';
		if ( '' === $client_secret && ! empty( $old['client_secret'] ) ) { $client_secret = $old['client_secret']; }
		if ( '' !== $client_id && ( false === strpos( $client_id, '.apps.googleusercontent.com' ) || '' === $client_secret ) ) {
			set_transient( 'publishrank_gsc_notice_' . get_current_user_id(), __( 'Enter a valid Google OAuth client ID and client secret.', 'publishrank' ), 60 );
		} else {
			$old['client_id'] = $client_id; $old['client_secret'] = $client_secret;
			update_option( 'publishrank_gsc_settings', $old, false );
			set_transient( 'publishrank_gsc_notice_' . get_current_user_id(), __( 'Google OAuth credentials saved. They are stored on this WordPress site and are not displayed again.', 'publishrank' ), 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=publishrank-analytics' ) ); exit;
	}

	public function connect() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'publishrank_gsc_connect' );
		$s = $this->gsc_settings();
		if ( empty( $s['client_id'] ) || empty( $s['client_secret'] ) ) { wp_safe_redirect( admin_url( 'admin.php?page=publishrank-analytics' ) ); exit; }
		$state = wp_generate_password( 48, false, false );
		set_transient( 'publishrank_gsc_state_' . hash( 'sha256', $state ), array( 'user_id' => get_current_user_id(), 'created' => time() ), 10 * MINUTE_IN_SECONDS );
		$url = add_query_arg( array( 'client_id' => $s['client_id'], 'redirect_uri' => $this->callback_url(), 'response_type' => 'code', 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state ), 'https://accounts.google.com/o/oauth2/v2/auth' );
		wp_redirect( $url ); exit;
	}

	public function oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Please sign in as a WordPress administrator and try connecting again.', 'publishrank' ), '', array( 'response' => 403 ) ); }
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$key = 'publishrank_gsc_state_' . hash( 'sha256', $state );
		$state_data = $state ? get_transient( $key ) : false; delete_transient( $key );
		if ( ! is_array( $state_data ) || (int) $state_data['user_id'] !== get_current_user_id() || time() - (int) $state_data['created'] > 600 ) { wp_die( esc_html__( 'Google authorization state expired or did not match. Please reconnect.', 'publishrank' ), '', array( 'response' => 400 ) ); }
		if ( ! empty( $_GET['error'] ) || empty( $_GET['code'] ) ) { wp_safe_redirect( admin_url( 'admin.php?page=publishrank-analytics' ) ); exit; }
		$s = $this->gsc_settings();
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'timeout' => 20, 'body' => array( 'code' => sanitize_text_field( wp_unslash( $_GET['code'] ) ), 'client_id' => $s['client_id'] ?? '', 'client_secret' => $s['client_secret'] ?? '', 'redirect_uri' => $this->callback_url(), 'grant_type' => 'authorization_code' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { wp_die( esc_html__( 'Google token exchange failed. Check your OAuth client and redirect URI, then reconnect.', 'publishrank' ), '', array( 'response' => 502 ) ); }
		$token = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $token ) || empty( $token['access_token'] ) ) { wp_die( esc_html__( 'Google did not return a usable access token.', 'publishrank' ), '', array( 'response' => 502 ) ); }
		$s['access_token'] = sanitize_text_field( $token['access_token'] );
		if ( ! empty( $token['refresh_token'] ) ) { $s['refresh_token'] = sanitize_text_field( $token['refresh_token'] ); }
		$s['expires_at'] = time() + max( 60, (int) ( $token['expires_in'] ?? 3600 ) ) - 60;
		$s['connected_at'] = time();
		update_option( 'publishrank_gsc_settings', $s, false );
		delete_transient( 'publishrank_gsc_sites_' . get_current_blog_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=publishrank-analytics&gsc_connected=1' ) ); exit;
	}

	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'publishrank_gsc_disconnect' );
		$s = $this->gsc_settings();
		if ( ! empty( $s['refresh_token'] ) ) { wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'timeout' => 10, 'body' => array( 'token' => $s['refresh_token'] ) ) ); }
		unset( $s['access_token'], $s['refresh_token'], $s['expires_at'], $s['connected_at'], $s['selected_site'] ); update_option( 'publishrank_gsc_settings', $s, false );
		delete_transient( 'publishrank_gsc_sites_' . get_current_blog_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=publishrank-analytics' ) ); exit;
	}

	private function access_token() {
		$s = $this->gsc_settings();
		if ( empty( $s['refresh_token'] ) ) { return ''; }
		if ( ! empty( $s['access_token'] ) && ! empty( $s['expires_at'] ) && time() < (int) $s['expires_at'] ) { return $s['access_token']; }
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'timeout' => 20, 'body' => array( 'client_id' => $s['client_id'] ?? '', 'client_secret' => $s['client_secret'] ?? '', 'refresh_token' => $s['refresh_token'], 'grant_type' => 'refresh_token' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return ''; }
		$token = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $token['access_token'] ) ) { return ''; }
		$s['access_token'] = sanitize_text_field( $token['access_token'] ); $s['expires_at'] = time() + max( 60, (int) ( $token['expires_in'] ?? 3600 ) ) - 60; update_option( 'publishrank_gsc_settings', $s, false );
		return $s['access_token'];
	}

	private function gsc_request( $url, $body = null ) {
		$token = $this->access_token(); if ( ! $token ) { return new \WP_Error( 'publishrank_gsc_auth', __( 'Search Console is disconnected or needs authorization again.', 'publishrank' ) ); }
		$args = array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) );
		if ( null !== $body ) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode( $body ); return wp_remote_post( $url, $args ); }
		return wp_remote_get( $url, $args );
	}

	private function sites() {
		$key = 'publishrank_gsc_sites_' . get_current_blog_id(); $cached = get_transient( $key ); if ( is_array( $cached ) ) { return $cached; }
		$r = $this->gsc_request( 'https://www.googleapis.com/webmasters/v3/sites' ); if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) { return array(); }
		$j = json_decode( wp_remote_retrieve_body( $r ), true ); $sites = array();
		foreach ( (array) ( $j['siteEntry'] ?? array() ) as $site ) { if ( ! empty( $site['siteUrl'] ) && in_array( $site['permissionLevel'] ?? '', array( 'siteOwner', 'siteFullUser', 'siteRestrictedUser' ), true ) ) { $sites[] = array( 'url' => (string) $site['siteUrl'], 'permission' => (string) ( $site['permissionLevel'] ?? '' ) ); } }
		set_transient( $key, $sites, 10 * MINUTE_IN_SECONDS ); return $sites;
	}

	private function performance( $site, $days ) {
		$days = in_array( (int) $days, array( 7, 28, 90 ), true ) ? (int) $days : 28;
		$end = gmdate( 'Y-m-d', strtotime( '-3 days' ) ); $start = gmdate( 'Y-m-d', strtotime( '-' . ( $days + 2 ) . ' days' ) );
		$cache_key = 'publishrank_gsc_perf_' . md5( $site . '|' . $start . '|' . $end ); $cached = get_transient( $cache_key ); if ( is_array( $cached ) ) { return $cached; }
		$api_site = rawurlencode( $site ); $body = array( 'startDate' => $start, 'endDate' => $end, 'dimensions' => array( 'date' ), 'rowLimit' => 25000, 'dataState' => 'final' );
		$r = $this->gsc_request( 'https://www.googleapis.com/webmasters/v3/sites/' . $api_site . '/searchAnalytics/query', $body );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) { return array( 'error' => is_wp_error( $r ) ? $r->get_error_message() : __( 'Google Search Console could not return performance data. Check the selected property and API access.', 'publishrank' ) ); }
		$j = json_decode( wp_remote_retrieve_body( $r ), true ); $rows = array(); foreach ( (array) ( $j['rows'] ?? array() ) as $row ) { $date = $row['keys'][0] ?? ''; if ( $date ) { $rows[ $date ] = array( 'clicks' => (float) ( $row['clicks'] ?? 0 ), 'impressions' => (float) ( $row['impressions'] ?? 0 ), 'ctr' => (float) ( $row['ctr'] ?? 0 ), 'position' => (float) ( $row['position'] ?? 0 ) ); } }
		$result = array( 'start' => $start, 'end' => $end, 'rows' => $rows ); set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS ); return $result;
	}

	private function performance_chart( $title, $metric, $rows, $suffix = '' ) {
		$values = array(); foreach ( $rows as $date => $row ) { $value = (float) ( $row[ $metric ] ?? 0 ); if ( 'ctr' === $metric ) { $value *= 100; } $values[] = array( 'date' => $date, 'value' => $value ); }
		if ( empty( $values ) ) { echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No data returned for this period. Check Search Console property permissions or try a longer date range.', 'publishrank' ) . '</p></div>'; return; }
		$w = 900; $h = 270; $left = 58; $right = 22; $top = 22; $bottom = 40; $plotw = $w - $left - $right; $ploth = $h - $top - $bottom; $max = max( 1, max( array_column( $values, 'value' ) ) ); $n = count( $values ); $coords = array();
		foreach ( $values as $i => $point ) { $x = $left + ( $n > 1 ? $plotw * $i / ( $n - 1 ) : $plotw / 2 ); $y = $top + $ploth - ( $point['value'] / $max * $ploth ); $coords[] = array( round( $x, 2 ), round( $y, 2 ), $point['date'], $point['value'] ); }
		$headline = array_sum( array_column( $values, 'value' ) ); if ( 'ctr' === $metric || 'position' === $metric ) { $headline = $headline / count( $values ); }
		echo '<section class="pr-gsc-chart"><div class="pr-gsc-chart__head"><div><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( sprintf( __( 'Daily Search Console data · %1$s', 'publishrank' ), $suffix ) ) . '</p></div><strong>' . esc_html( number_format_i18n( $headline, 'ctr' === $metric || 'position' === $metric ? 2 : 0 ) . ( 'ctr' === $metric ? '%' : '' ) ) . '</strong></div><svg viewBox="0 0 900 270" role="img" aria-label="' . esc_attr( $title ) . '" class="pr-gsc-svg">';
		for ( $i = 0; $i <= 4; $i++ ) { $y = $top + $ploth * $i / 4; $v = $max * ( 4 - $i ) / 4; echo '<line x1="' . $left . '" y1="' . round( $y, 2 ) . '" x2="' . ( $w - $right ) . '" y2="' . round( $y, 2 ) . '" stroke="#e7ebf2" stroke-width="1"/><text x="' . ( $left - 10 ) . '" y="' . ( round( $y, 2 ) + 4 ) . '" text-anchor="end" fill="#6b7280" font-size="11">' . esc_html( number_format_i18n( $v, 'ctr' === $metric || 'position' === $metric ? 1 : 0 ) ) . '</text>'; }
		$poly = array(); foreach ( $coords as $c ) { $poly[] = $c[0] . ',' . $c[1]; }
		$area = $left . ',' . ( $top + $ploth ) . ' ' . implode( ' ', $poly ) . ' ' . ( $w - $right ) . ',' . ( $top + $ploth );
		echo '<polygon points="' . esc_attr( $area ) . '" fill="#3858e9" opacity=".09"/><polyline points="' . esc_attr( implode( ' ', $poly ) ) . '" fill="none" stroke="#3858e9" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>';
		foreach ( $coords as $i => $c ) { echo '<circle cx="' . esc_attr( $c[0] ) . '" cy="' . esc_attr( $c[1] ) . '" r="3.4" fill="#fff" stroke="#3858e9" stroke-width="2"><title>' . esc_html( $c[2] . ': ' . number_format_i18n( $c[3], 2 ) . ( 'ctr' === $metric ? '%' : '' ) ) . '</title></circle>'; }
		$step = max( 1, (int) ceil( $n / 7 ) ); foreach ( $coords as $i => $c ) { if ( 0 === $i % $step || $i === $n - 1 ) { echo '<text x="' . esc_attr( $c[0] ) . '" y="' . ( $h - 12 ) . '" text-anchor="middle" fill="#6b7280" font-size="11">' . esc_html( substr( $c[2], 5 ) ) . '</text>'; } }
		echo '</svg></section>';
	}

	private function render_search_console() {
		$s = $this->gsc_settings();
		$notice = get_transient( 'publishrank_gsc_notice_' . get_current_user_id() ); if ( $notice ) { delete_transient( 'publishrank_gsc_notice_' . get_current_user_id() ); echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>'; }
		if ( ! empty( $_GET['gsc_connected'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Google Search Console connected with read-only access.', 'publishrank' ) . '</p></div>'; }
		$redirect = $this->callback_url();
		echo '<section class="pr-analytics-card"><h2>' . esc_html__( 'Google Search Console performance', 'publishrank' ) . '</h2><p>' . esc_html__( 'Connect your Google account using read-only permission. PublishRank can read Search Console reports, but cannot change your properties, sitemaps, or settings.', 'publishrank' ) . '</p>';
		if ( empty( $s['client_id'] ) || empty( $s['client_secret'] ) ) {
			echo '<p>' . esc_html__( 'One-time setup: create an OAuth 2.0 Web application credential in Google Cloud Console, enable the Search Console API, and add this exact redirect URI to the credential:', 'publishrank' ) . '</p><code class="pr-gsc-redirect">' . esc_html( $redirect ) . '</code><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pr-gsc-credentials">';
			echo '<input type="hidden" name="action" value="publishrank_gsc_save">'; wp_nonce_field( 'publishrank_gsc_save' );
			echo '<label>' . esc_html__( 'OAuth Client ID', 'publishrank' ) . '<input type="text" name="client_id" required autocomplete="off" value="' . esc_attr( $s['client_id'] ?? '' ) . '"></label><label>' . esc_html__( 'OAuth Client Secret', 'publishrank' ) . '<input type="password" name="client_secret" required autocomplete="new-password" value="" placeholder="' . esc_attr__( 'Enter client secret', 'publishrank' ) . '"></label><p class="description">' . esc_html__( 'The client secret is stored only in this WordPress site. Use HTTPS and keep WordPress administrator access secure.', 'publishrank' ) . '</p><button class="button button-primary">' . esc_html__( 'Save OAuth credentials', 'publishrank' ) . '</button></form>';
		} elseif ( empty( $s['refresh_token'] ) ) {
			echo '<p><strong>' . esc_html__( 'OAuth credentials saved.', 'publishrank' ) . '</strong> ' . esc_html__( 'Continue to Google to authorize read-only access.', 'publishrank' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_gsc_connect">'; wp_nonce_field( 'publishrank_gsc_connect' ); echo '<button class="button button-primary">' . esc_html__( 'Connect Google Search Console', 'publishrank' ) . '</button></form>';
		} else {
			echo '<p class="pr-gsc-connected"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Connected · read-only access', 'publishrank' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pr-gsc-toolbar"><input type="hidden" name="action" value="publishrank_gsc_disconnect">'; wp_nonce_field( 'publishrank_gsc_disconnect' ); echo '<button class="button">' . esc_html__( 'Disconnect Google account', 'publishrank' ) . '</button></form>';
			$sites = $this->sites(); $chosen = isset( $_GET['gsc_site'] ) ? sanitize_text_field( wp_unslash( $_GET['gsc_site'] ) ) : ( $s['selected_site'] ?? '' );
			if ( empty( $sites ) ) { echo '<p>' . esc_html__( 'No accessible Search Console properties were returned. Confirm that this Google account has access and that the Search Console API is enabled.', 'publishrank' ) . '</p>'; }
			else {
				$valid = array_column( $sites, 'url' ); if ( ! in_array( $chosen, $valid, true ) ) { $chosen = $valid[0]; }
				$days = isset( $_GET['gsc_days'] ) ? absint( $_GET['gsc_days'] ) : 28; if ( ! in_array( $days, array( 7, 28, 90 ), true ) ) { $days = 28; }
				echo '<form method="get" class="pr-gsc-toolbar"><input type="hidden" name="page" value="publishrank-analytics"><label>' . esc_html__( 'Search Console property', 'publishrank' ) . '<select name="gsc_site">'; foreach ( $sites as $site ) { echo '<option value="' . esc_attr( $site['url'] ) . '" ' . selected( $chosen, $site['url'], false ) . '>' . esc_html( $site['url'] ) . '</option>'; } echo '</select></label><label>' . esc_html__( 'Date range', 'publishrank' ) . '<select name="gsc_days"><option value="7" ' . selected( $days, 7, false ) . '>7 ' . esc_html__( 'days', 'publishrank' ) . '</option><option value="28" ' . selected( $days, 28, false ) . '>28 ' . esc_html__( 'days', 'publishrank' ) . '</option><option value="90" ' . selected( $days, 90, false ) . '>90 ' . esc_html__( 'days', 'publishrank' ) . '</option></select></label><button class="button button-primary">' . esc_html__( 'Load report', 'publishrank' ) . '</button></form>';
				$report = $this->performance( $chosen, $days );
				if ( ! empty( $report['error'] ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $report['error'] ) . '</p></div>'; }
				else {
					$rows = $report['rows'] ?? array(); $clicks = $impressions = $ctr_weight = $position_weight = 0; foreach ( $rows as $row ) { $clicks += $row['clicks']; $impressions += $row['impressions']; $ctr_weight += $row['ctr'] * $row['impressions']; $position_weight += $row['position'] * $row['impressions']; }
					$avg_ctr = $impressions ? $clicks / $impressions * 100 : 0; $avg_pos = $impressions ? $position_weight / $impressions : 0;
					echo '<div class="pr-gsc-kpis"><div><span>' . esc_html__( 'Total clicks', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( $clicks ) ) . '</strong></div><div><span>' . esc_html__( 'Impressions', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( $impressions ) ) . '</strong></div><div><span>' . esc_html__( 'Average CTR', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( $avg_ctr, 2 ) ) . '%</strong></div><div><span>' . esc_html__( 'Average position', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( $avg_pos, 1 ) ) . '</strong></div></div>';
					if ( empty( $rows ) ) { echo '<p>' . esc_html__( 'Google returned no daily rows for this period. New or low-traffic properties may have limited data.', 'publishrank' ) . '</p>'; }
					else { $chart_rows = array(); $cursor = strtotime( $report['start'] ); $end_ts = strtotime( $report['end'] ); while ( $cursor <= $end_ts ) { $date = gmdate( 'Y-m-d', $cursor ); $chart_rows[ $date ] = $rows[ $date ] ?? array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 ); $cursor = strtotime( '+1 day', $cursor ); }
						$this->performance_chart( __( 'Organic search clicks', 'publishrank' ), 'clicks', $chart_rows, $report['start'] . ' – ' . $report['end'] ); $this->performance_chart( __( 'Search impressions', 'publishrank' ), 'impressions', $chart_rows, $report['start'] . ' – ' . $report['end'] );
						echo '<div class="pr-gsc-secondary">'; $this->performance_chart( __( 'Click-through rate', 'publishrank' ), 'ctr', $chart_rows, __( 'CTR shown as daily percentage', 'publishrank' ) ); $this->performance_chart( __( 'Average search position', 'publishrank' ), 'position', $chart_rows, __( 'Lower values are generally better; this is an average', 'publishrank' ) ); echo '</div>';
						echo '<p class="description">' . esc_html__( 'Google Search Console data is subject to Google reporting delays and aggregation limits. The latest three days are excluded to reduce incomplete-data effects. Average position is an average, not a fixed ranking for every query.', 'publishrank' ) . '</p>';
					}
				}
			}
		}
		echo '</section>';
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$data = AnalyticsCollector::snapshots();
		if ( empty( $data ) ) { ( new AnalyticsCollector() )->snapshot(); $data = AnalyticsCollector::snapshots(); }
		$dates = array_keys( $data );
		$posts = $accepted = $failed = array();
		foreach ( $data as $row ) { $posts[] = (int) ( $row['published_posts'] ?? 0 ); $accepted[] = (int) ( $row['indexnow_accepted'] ?? 0 ); $failed[] = (int) ( $row['indexnow_failed'] ?? 0 ); }
		$logs = get_option( IndexNow::LOG_OPTION, array() ); if ( ! is_array( $logs ) ) { $logs = array(); }
		$ok = count( array_filter( $logs, static function ( $row ) { return is_array( $row ) && isset( $row['result'] ) && 'accepted' === $row['result']; } ) );
		$bad = count( array_filter( $logs, static function ( $row ) { return is_array( $row ) && isset( $row['result'] ) && 'failed' === $row['result']; } ) );
		$queue = get_option( 'publishrank_indexnow_queue', array() ); if ( ! is_array( $queue ) ) { $queue = array(); }
		$llms_on = (bool) get_option( 'publishrank_llms_txt_enabled', 1 );
		echo '<div class="wrap"><h1>' . esc_html__( 'PublishRank Analytics', 'publishrank' ) . '</h1><p>' . esc_html__( 'Local plugin activity and technical SEO snapshots. Search traffic and actual search-engine index status are not inferred from these local metrics.', 'publishrank' ) . '</p>';
		echo '<style>.pr-analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:18px 0}.pr-analytics-card,.pr-analytics-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;box-sizing:border-box}.pr-analytics-kpi strong{display:block;font-size:28px;margin:8px 0}.pr-analytics-kpi span{color:#646970}.pr-analytics-card{margin:16px 0;max-width:980px}.pr-analytics-card h2{margin-top:0}.pr-analytics-notice{max-width:940px;border-left:4px solid #dba617;padding:12px 16px;background:#fff}.pr-gsc-chart{background:linear-gradient(145deg,#fff,#fbfcff);border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin:18px 0;box-shadow:0 4px 18px rgba(20,35,70,.04)}.pr-gsc-chart__head{display:flex;justify-content:space-between;align-items:start;gap:16px}.pr-gsc-chart__head h3{margin:0;font-size:16px}.pr-gsc-chart__head p{margin:6px 0 0;color:#6b7280}.pr-gsc-chart__head strong{font-size:25px;color:#1f2a44}.pr-gsc-svg{width:100%;height:auto;display:block;overflow:visible}.pr-gsc-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:20px 0}.pr-gsc-kpis>div{background:#fff;border:1px solid #e3e8f0;border-radius:12px;padding:16px;box-shadow:0 3px 12px rgba(20,35,70,.04)}.pr-gsc-kpis span{display:block;color:#667085;font-size:12px}.pr-gsc-kpis strong{display:block;font-size:24px;margin-top:7px;color:#17233b}.pr-gsc-toolbar{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin:16px 0}.pr-gsc-toolbar label,.pr-gsc-credentials label{display:flex;flex-direction:column;gap:6px;font-weight:600}.pr-gsc-credentials{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;max-width:850px;margin-top:18px}.pr-gsc-credentials input{width:100%;max-width:100%}.pr-gsc-credentials .description,.pr-gsc-credentials button{grid-column:1/-1}.pr-gsc-redirect{display:block;overflow-wrap:anywhere;padding:10px;background:#f6f7f7}.pr-gsc-connected{color:#087443;font-weight:600}.pr-gsc-secondary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}@media(max-width:782px){.pr-gsc-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.pr-gsc-secondary,.pr-gsc-credentials{grid-template-columns:1fr}.pr-gsc-credentials .description,.pr-gsc-credentials button{grid-column:auto}}</style>';
		echo '<div class="pr-analytics-grid"><div class="pr-analytics-kpi"><span>' . esc_html__( 'Published posts', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( (int) end( $posts ) ) ) . '</strong><span>' . esc_html__( 'Current snapshot', 'publishrank' ) . '</span></div>';
		echo '<div class="pr-analytics-kpi"><span>' . esc_html__( 'Accepted IndexNow events', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( $ok ) ) . '</strong><span>' . esc_html__( 'In retained activity log', 'publishrank' ) . '</span></div>';
		echo '<div class="pr-analytics-kpi"><span>' . esc_html__( 'Failed IndexNow events', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( $bad ) ) . '</strong><span>' . esc_html__( 'In retained activity log', 'publishrank' ) . '</span></div>';
		echo '<div class="pr-analytics-kpi"><span>' . esc_html__( 'Queued URLs', 'publishrank' ) . '</span><strong>' . esc_html( number_format_i18n( count( $queue ) ) ) . '</strong><span>' . esc_html__( 'Awaiting processing', 'publishrank' ) . '</span></div></div>';
		$period = empty( $dates ) ? __( 'No snapshots yet', 'publishrank' ) : reset( $dates ) . ' – ' . end( $dates );
		$this->chart( __( 'Published content history', 'publishrank' ), __( 'Daily snapshots begin when this analytics version is activated; this is inventory, not traffic.', 'publishrank' ), $posts, __( 'Sum of daily post-count snapshots', 'publishrank' ), $period );
		$this->chart( __( 'IndexNow accepted events by snapshot day', 'publishrank' ), __( 'Accepted means the IndexNow endpoint accepted the request; it does not confirm indexing.', 'publishrank' ), $accepted, __( 'Accepted events across retained snapshots', 'publishrank' ), $period );
		$this->chart( __( 'IndexNow failures by snapshot day', 'publishrank' ), __( 'Only events retained in the local IndexNow log can be counted. The current log is capped.', 'publishrank' ), $failed, __( 'Failed events across retained snapshots', 'publishrank' ), $period );
		echo '<section class="pr-analytics-card"><h2>' . esc_html__( 'AI Discovery status', 'publishrank' ) . '</h2><p><strong>' . esc_html__( 'llms.txt:', 'publishrank' ) . '</strong> ' . ( $llms_on ? esc_html__( 'Enabled', 'publishrank' ) : esc_html__( 'Disabled', 'publishrank' ) ) . ' — <a href="' . esc_url( admin_url( 'admin.php?page=publishrank-llms-txt' ) ) . '">' . esc_html__( 'Configure AI & llms.txt', 'publishrank' ) . '</a></p><p><a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open llms.txt', 'publishrank' ) . '</a></p></section>';
		$this->render_search_console();
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: snapshot dates. */ __( 'Retained daily snapshots: %s. History starts from installation of this analytics collector; no historical data is backfilled.', 'publishrank' ), empty( $dates ) ? __( 'none', 'publishrank' ) : implode( ', ', $dates ) ) ) . '</p></div>';
	}
}
