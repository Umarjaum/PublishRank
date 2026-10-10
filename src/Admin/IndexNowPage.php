<?php
/** IndexNow configuration, verification and submission history. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Indexing\IndexNow;

final class IndexNowPage {
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 16 );
		add_action( 'admin_post_publishrank_indexnow_save', array( $this, 'save' ) );
		add_action( 'admin_post_publishrank_indexnow_verify', array( $this, 'verify' ) );
		add_action( 'admin_post_publishrank_indexnow_rotate', array( $this, 'rotate_key' ) );
		add_action( 'admin_post_publishrank_indexnow_manual', array( $this, 'manual_submit' ) );
		add_action( 'admin_post_publishrank_indexnow_retry', array( $this, 'retry' ) );
		add_action( 'admin_post_publishrank_indexnow_single', array( $this, 'single_submit' ) );
		add_action( 'admin_post_publishrank_indexnow_export', array( $this, 'export_logs' ) );
		add_action( 'admin_post_publishrank_indexnow_clear_logs', array( $this, 'clear_logs' ) );
		add_action( 'init', array( $this, 'register_post_type_bulk_actions' ), 99 );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'IndexNow', 'publishrank' ), __( 'IndexNow', 'publishrank' ), 'manage_options', 'publishrank-indexnow', array( $this, 'render' ) );
	}

	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage IndexNow settings.', 'publishrank' ) ); }
		check_admin_referer( $action );
	}

	public function save() {
		$this->guard( 'publishrank_indexnow_save' );
		update_option( 'publishrank_indexnow_enabled', empty( $_POST['enabled'] ) ? 0 : 1, false );
		$types = isset( $_POST['post_types'] ) ? sanitize_text_field( wp_unslash( $_POST['post_types'] ) ) : 'post,page';
		$valid = array();
		foreach ( preg_split( '/[\s,]+/', $types, -1, PREG_SPLIT_NO_EMPTY ) as $type ) {
			$type = sanitize_key( $type );
			if ( post_type_exists( $type ) && is_post_type_viewable( $type ) ) { $valid[] = $type; }
		}
		update_option( 'publishrank_indexnow_post_types', implode( ',', array_unique( $valid ) ), false );
		$excluded = isset( $_POST['excluded_paths'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_paths'] ) ) : '';
		update_option( 'publishrank_indexnow_excluded_paths', $excluded, false );
		if ( ! empty( $_POST['enabled'] ) && is_array( get_option( 'publishrank_indexnow_queue', array() ) ) && ! empty( get_option( 'publishrank_indexnow_queue', array() ) ) && ! wp_next_scheduled( 'publishrank_indexnow_flush_queue' ) ) {
			wp_schedule_single_event( time() + 15, 'publishrank_indexnow_flush_queue' );
		}
		$this->redirect( 'saved' );
	}

	public function verify() {
		$this->guard( 'publishrank_indexnow_verify' );
		$key = (string) get_option( 'publishrank_indexnow_key', '' );
		$url = home_url( '/publishrank-indexnow-key.txt' );
		$response = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 3 ) );
		if ( is_wp_error( $response ) ) {
			$this->redirect( 'verify_error', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( 200 === $code && hash_equals( $key, $body ) ) { $this->redirect( 'verified' ); }
		$this->redirect( 'verify_error', sprintf( 'HTTP %d; the public key URL did not return the expected key.', $code ) );
	}

	public function register_post_type_bulk_actions() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $types as $type ) {
			add_filter( 'bulk_actions-edit-' . $type, array( $this, 'register_bulk_action' ) );
			add_filter( 'handle_bulk_actions-edit-' . $type, array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	public function clear_logs() {
		$this->guard( 'publishrank_indexnow_clear_logs' );
		delete_option( IndexNow::LOG_OPTION );
		delete_option( 'publishrank_indexnow_last_status' );
		$this->redirect( 'logs_cleared' );
	}

	public function export_logs() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to export IndexNow history.', 'publishrank' ) ); }
		check_admin_referer( 'publishrank_indexnow_export' );
		$logs = get_option( IndexNow::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) { $logs = array(); }
		$queue = get_option( 'publishrank_indexnow_queue', array() );
		if ( ! is_array( $queue ) ) { $queue = array(); }
		$next_run = wp_next_scheduled( 'publishrank_indexnow_flush_queue' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=publishrank-indexnow-history.csv' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'time_utc', 'url', 'result', 'http_status', 'message', 'event' ) );
		foreach ( array_slice( $logs, 0, 100 ) as $entry ) {
			if ( ! is_array( $entry ) ) { continue; }
			fputcsv( $output, array( isset( $entry['time'] ) ? $entry['time'] : '', isset( $entry['url'] ) ? $entry['url'] : '', isset( $entry['result'] ) ? $entry['result'] : '', isset( $entry['http_status'] ) ? $entry['http_status'] : '', isset( $entry['message'] ) ? $entry['message'] : '', isset( $entry['event'] ) ? $entry['event'] : '' ) );
		}
		fclose( $output );
		exit;
	}

	public function register_bulk_action( $actions ) {
		if ( current_user_can( 'edit_posts' ) ) { $actions['publishrank_indexnow_submit'] = __( 'Submit to IndexNow', 'publishrank' ); }
		return $actions;
	}

	public function row_action( $actions, $post ) {
		if ( $post instanceof \WP_Post && ( new IndexNow() )->can_submit_post( $post ) && current_user_can( 'edit_post', $post->ID ) && get_permalink( $post ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_indexnow_single&post_id=' . (int) $post->ID ), 'publishrank_indexnow_single_' . (int) $post->ID );
			$actions['publishrank_indexnow_submit'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Submit to IndexNow', 'publishrank' ) . '</a>';
		}
		return $actions;
	}

	public function single_submit() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) { wp_die( esc_html__( 'You are not allowed to submit this content.', 'publishrank' ) ); }
		check_admin_referer( 'publishrank_indexnow_single_' . $post_id );
		$post = get_post( $post_id );
		if ( ! $post || ! ( new IndexNow() )->can_submit_post( $post ) || ! get_permalink( $post ) ) { $this->redirect( 'manual_error', 'Only eligible published, indexable content can be submitted.' ); }
		$result = ( new IndexNow() )->submit_bulk( array( get_permalink( $post ) ), 'manual_single' );
		if ( is_wp_error( $result ) ) { $this->redirect( 'manual_error', $result->get_error_message() ); }
		$this->redirect( 'submitted', (string) $result['count'] );
	}

	public function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( 'publishrank_indexnow_submit' !== $action ) { return $redirect; }
		if ( ! current_user_can( 'edit_posts' ) ) { return add_query_arg( 'pr_indexnow_notice', 'manual_error', $redirect ); }
		$urls = array();
		foreach ( array_slice( array_map( 'absint', (array) $post_ids ), 0, 100 ) as $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				$post = get_post( $post_id );
				if ( $post && ( new IndexNow() )->can_submit_post( $post ) && get_permalink( $post ) ) { $urls[] = get_permalink( $post ); }
			}
		}
		$result = ( new IndexNow() )->submit_bulk( $urls, 'manual_bulk' );
		if ( is_wp_error( $result ) ) { return add_query_arg( array( 'pr_indexnow_notice' => 'manual_error', 'pr_indexnow_detail' => rawurlencode( $result->get_error_message() ) ), $redirect ); }
		return add_query_arg( array( 'pr_indexnow_notice' => 'submitted', 'pr_indexnow_detail' => (string) $result['count'] ), $redirect );
	}

	public function rotate_key() {
		$this->guard( 'publishrank_indexnow_rotate' );
		update_option( 'publishrank_indexnow_key', wp_generate_password( 32, false, false ), false );
		delete_option( IndexNow::LOG_OPTION );
		$this->redirect( 'rotated' );
	}

	public function manual_submit() {
		$this->guard( 'publishrank_indexnow_manual' );
		$raw = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';
		$urls = array_slice( array_unique( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|,/', $raw ) ) ) ), 0, 100 );
		if ( ! preg_match( '/^[A-Za-z0-9-]{8,128}$/', (string) get_option( 'publishrank_indexnow_key', '' ) ) ) { $this->redirect( 'manual_error', 'A valid IndexNow key is required before submitting URLs.' ); }
		if ( empty( $urls ) ) { $this->redirect( 'manual_error', 'Enter at least one URL.' ); }
		$indexnow = new IndexNow();
		$valid = array();
		foreach ( $urls as $url ) { $url = esc_url_raw( $url ); if ( $url && $this->same_host( $url ) ) { $valid[] = $url; } }
		if ( empty( $valid ) ) { $this->redirect( 'manual_error', 'No valid URLs from this website were provided.' ); }
		$result = $indexnow->submit_bulk( $valid, 'manual' );
		if ( is_wp_error( $result ) ) { $this->redirect( 'manual_error', $result->get_error_message() ); }
		$this->redirect( 'submitted', (string) $result['count'] );
	}

	public function retry() {
		$this->guard( 'publishrank_indexnow_retry' );
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $url || ! $this->same_host( $url ) ) { $this->redirect( 'manual_error', 'The retry URL is invalid or belongs to another host.' ); }
		wp_schedule_single_event( time() + 5, 'publishrank_indexnow_submit', array( $url, 0, 'retry', 0 ) );
		$this->redirect( 'retry_queued' );
	}

	private function same_host( $url ) {
		$a = wp_parse_url( $url ); $b = wp_parse_url( home_url( '/' ) );
		return is_array( $a ) && is_array( $b ) && isset( $a['host'], $a['scheme'], $b['host'] ) && in_array( strtolower( $a['scheme'] ), array( 'http', 'https' ), true ) && strtolower( $a['host'] ) === strtolower( $b['host'] );
	}

	private function redirect( $state, $detail = '' ) {
		$url = add_query_arg( array( 'page' => 'publishrank-indexnow', 'pr_indexnow_notice' => sanitize_key( $state ), 'pr_indexnow_detail' => rawurlencode( sanitize_text_field( $detail ) ) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url ); exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$enabled = (bool) get_option( 'publishrank_indexnow_enabled', false );
		$key = (string) get_option( 'publishrank_indexnow_key', '' );
		$key_url = home_url( '/publishrank-indexnow-key.txt' );
		$types = (string) get_option( 'publishrank_indexnow_post_types', 'post,page' );
		$excluded_paths = (string) get_option( 'publishrank_indexnow_excluded_paths', '' );
		$logs = get_option( IndexNow::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) { $logs = array(); }
		$queue = get_option( 'publishrank_indexnow_queue', array() );
		if ( ! is_array( $queue ) ) { $queue = array(); }
		$next_run = wp_next_scheduled( 'publishrank_indexnow_flush_queue' );
		echo '<div class="wrap"><h1>' . esc_html__( 'IndexNow', 'publishrank' ) . '</h1>';
		echo '<p>' . esc_html__( 'Submit changed public URLs to the shared IndexNow endpoint (https://api.indexnow.org/indexnow/). Participating search engines can share these notifications; submissions do not guarantee crawling or indexing, and Google does not support IndexNow.', 'publishrank' ) . '</p>';
		$this->notice();
		echo '<div class="card"><h2>' . esc_html__( 'Automation status', 'publishrank' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Automatic submissions:', 'publishrank' ) . '</strong> ' . ( $enabled ? esc_html__( 'Enabled', 'publishrank' ) : esc_html__( 'Disabled', 'publishrank' ) ) . ' &nbsp; <strong>' . esc_html__( 'Queued URLs:', 'publishrank' ) . '</strong> ' . esc_html( (string) count( $queue ) ) . ' &nbsp; <strong>' . esc_html__( 'Next queue run:', 'publishrank' ) . '</strong> ' . esc_html( $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : __( 'Not scheduled', 'publishrank' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Automatic events include newly published content, updates, changed permalinks, and URLs moving out of published status. Work is queued and sent in batches of up to 100 URLs. WP-Cron runs when site traffic triggers it; large publishers should configure a real server cron for dependable scheduling.', 'publishrank' ) . '</p></div>';
		echo '<div class="card"><h2>' . esc_html__( 'Automatic submission settings', 'publishrank' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_indexnow_save">';
		wp_nonce_field( 'publishrank_indexnow_save' );
		echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Enable automatic IndexNow notifications', 'publishrank' ) . '</label></p>';
		echo '<p><label for="pr-indexnow-types"><strong>' . esc_html__( 'Public post types', 'publishrank' ) . '</strong></label><br><input id="pr-indexnow-types" class="regular-text" name="post_types" value="' . esc_attr( $types ) . '"><br><span class="description">' . esc_html__( 'Comma-separated public post type names, for example: post,page. Private, password-protected and detected noindex posts are excluded.', 'publishrank' ) . '</span></p>';
		echo '<p><label for="pr-indexnow-excluded"><strong>' . esc_html__( 'Excluded URL paths (optional)', 'publishrank' ) . '</strong></label><br><textarea id="pr-indexnow-excluded" class="large-text code" rows="4" name="excluded_paths">' . esc_textarea( $excluded_paths ) . '</textarea><br><span class="description">' . esc_html__( 'One path pattern per line, relative to the site, for example /private/* or /tag/* . Use * as a wildcard. Lines beginning with # are comments.', 'publishrank' ) . '</span></p>'; 
		submit_button( __( 'Save IndexNow settings', 'publishrank' ) ); echo '</form></div>';
		echo '<div class="card"><h2>' . esc_html__( 'Verification key', 'publishrank' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Key:', 'publishrank' ) . '</strong> <code>' . esc_html( $key ) . '</code></p><p><strong>' . esc_html__( 'Public key URL:', 'publishrank' ) . '</strong> <a href="' . esc_url( $key_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $key_url ) . '</a></p>';
		echo '<p>' . esc_html__( 'The key file must be publicly reachable and return only the key. If your server or cache blocks WordPress rewrite endpoints, fix that before relying on submissions.', 'publishrank' ) . '</p>';
		echo '<form class="publishrank-indexnow-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_indexnow_verify">'; wp_nonce_field( 'publishrank_indexnow_verify' ); submit_button( __( 'Test key file', 'publishrank' ), 'secondary', 'submit', false ); echo '</form>';
		echo '<form class="publishrank-indexnow-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_indexnow_rotate">'; wp_nonce_field( 'publishrank_indexnow_rotate' ); submit_button( __( 'Generate a new key', 'publishrank' ), 'secondary', 'submit', false ); echo '</form></div>';
		echo '<div class="card"><h2>' . esc_html__( 'Submit URLs manually', 'publishrank' ) . '</h2><p>' . esc_html__( 'Enter one URL per line (maximum 100 per action). URLs must belong to this website and use the generated public key. Automatic submissions are controlled separately above.', 'publishrank' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_indexnow_manual">'; wp_nonce_field( 'publishrank_indexnow_manual' );
		echo '<textarea class="large-text code" rows="5" name="urls" placeholder="' . esc_attr( home_url( '/' ) ) . '"></textarea>'; submit_button( __( 'Submit URLs', 'publishrank' ), 'primary' ); echo '</form></div>';
		echo '<h2>' . esc_html__( 'Recent submission activity', 'publishrank' ) . '</h2><form class="publishrank-indexnow-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_indexnow_export">'; wp_nonce_field( 'publishrank_indexnow_export' ); submit_button( __( 'Export history as CSV', 'publishrank' ), 'secondary', 'submit', false ); echo '</form><form class="publishrank-indexnow-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Clear all IndexNow submission history?', 'publishrank' ) ) . '\');"><input type="hidden" name="action" value="publishrank_indexnow_clear_logs">'; wp_nonce_field( 'publishrank_indexnow_clear_logs' ); submit_button( __( 'Clear history', 'publishrank' ), 'delete', 'submit', false ); echo '</form><p>' . esc_html__( 'The log stores up to 100 recent attempts. HTTP 200 and 202 indicate acceptance/receipt by the endpoint, not a guarantee of indexing.', 'publishrank' ) . '</p>';
		if ( empty( $logs ) ) { echo '<p>' . esc_html__( 'No submissions recorded yet.', 'publishrank' ) . '</p>'; }
		else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'publishrank' ) . '</th><th>' . esc_html__( 'URL', 'publishrank' ) . '</th><th>' . esc_html__( 'Result', 'publishrank' ) . '</th><th>' . esc_html__( 'HTTP', 'publishrank' ) . '</th><th>' . esc_html__( 'Details', 'publishrank' ) . '</th><th>' . esc_html__( 'Action', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $logs, 0, 100 ) as $entry ) {
				if ( ! is_array( $entry ) ) { continue; }
				echo '<tr><td>' . esc_html( isset( $entry['time'] ) ? $entry['time'] : '' ) . '</td><td><a href="' . esc_url( isset( $entry['url'] ) ? $entry['url'] : '' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( isset( $entry['url'] ) ? $entry['url'] : '' ) . '</a></td><td>' . esc_html( isset( $entry['result'] ) ? $entry['result'] : '' ) . '</td><td>' . esc_html( isset( $entry['http_status'] ) ? (string) $entry['http_status'] : '' ) . '</td><td>' . esc_html( isset( $entry['message'] ) ? $entry['message'] : '' ) . '</td><td>';
				if ( isset( $entry['result'], $entry['url'] ) && 'failed' === $entry['result'] ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_indexnow_retry"><input type="hidden" name="url" value="' . esc_attr( $entry['url'] ) . '">'; wp_nonce_field( 'publishrank_indexnow_retry' ); submit_button( __( 'Retry', 'publishrank' ), 'small', 'submit', false ); echo '</form>'; }
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">' . esc_html__( 'IndexNow sends public URL, host, and verification key to api.indexnow.org. Participating search engines may share the notification. No visitor analytics or article body is sent by this module.', 'publishrank' ) . '</p></div>';
	}

	private function notice() {
		if ( empty( $_GET['pr_indexnow_notice'] ) ) { return; }
		$state = sanitize_key( wp_unslash( $_GET['pr_indexnow_notice'] ) );
		$messages = array(
			'saved' => __( 'IndexNow settings saved.', 'publishrank' ), 'verified' => __( 'The public key URL returned the expected key.', 'publishrank' ),
			'rotated' => __( 'A new key was generated. Re-test the public key URL before relying on submissions.', 'publishrank' ),
			'submitted' => sprintf( /* translators: %s: URL count. */ __( 'Submission requests sent for %s URL(s). Review the activity log for responses.', 'publishrank' ), isset( $_GET['pr_indexnow_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['pr_indexnow_detail'] ) ) : '0' ),
			'retry_queued' => __( 'A retry has been queued.', 'publishrank' ), 'logs_cleared' => __( 'IndexNow submission history cleared.', 'publishrank' ), 'manual_error' => isset( $_GET['pr_indexnow_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['pr_indexnow_detail'] ) ) : __( 'Unable to submit URLs.', 'publishrank' ),
			'verify_error' => isset( $_GET['pr_indexnow_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['pr_indexnow_detail'] ) ) : __( 'The key URL could not be verified.', 'publishrank' ),
		);
		if ( isset( $messages[ $state ] ) ) { echo '<div class="notice ' . ( in_array( $state, array( 'verify_error', 'manual_error' ), true ) ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $messages[ $state ] ) . '</p></div>'; }
	}
}
