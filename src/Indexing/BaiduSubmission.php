<?php
/** Optional Baidu ordinary-inclusion URL submission; never logs endpoint credentials or submitted URLs. */
namespace PublishRankSEO\Indexing;

defined( 'ABSPATH' ) || exit;

final class BaiduSubmission {
	const ENDPOINT_OPTION = 'publishrank_baidu_endpoint_enc';
	const HISTORY_OPTION  = 'publishrank_baidu_history';
	const MAX_BATCH       = 2000;
	const MAX_RETRIES     = 3;

	public function register() {
		add_action( 'admin_post_publishrank_baidu_save', array( $this, 'save_settings' ) );
		add_action( 'admin_post_publishrank_baidu_submit', array( $this, 'submit_manual' ) );
		add_action( 'admin_post_publishrank_baidu_disconnect', array( $this, 'disconnect' ) );
		add_action( 'publishrank_post_indexable_published', array( $this, 'queue_post' ), 10, 2 );
		add_action( 'publishrank_baidu_submit_post', array( $this, 'submit_post' ), 10, 2 );
	}

	public static function configured() {
		return '' !== self::endpoint(); }
	public static function automatic() {
		return (bool) get_option( 'publishrank_baidu_automatic', false ); }
	public static function history() {
		$v = get_option( self::HISTORY_OPTION, array() );
		return is_array( $v ) ? $v : array(); }

	public function save_settings() {
		$this->guard( 'publishrank_baidu_save' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$endpoint = isset( $_POST['endpoint'] ) ? trim( (string) wp_unslash( $_POST['endpoint'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$automatic = isset( $_POST['automatic'] ) && '1' === (string) wp_unslash( $_POST['automatic'] );
		if ( '' !== $endpoint && ! $this->valid_endpoint( $endpoint ) ) {
			$this->redirect( 'error', __( 'Endpoint rejected. Use the HTTPS Baidu ordinary-inclusion URL shown for this verified site, with matching site and token parameters.', 'publishrank' ) ); }
		if ( $automatic && '' === $endpoint && ! self::configured() ) {
			$this->redirect( 'error', __( 'Save a valid Baidu endpoint before enabling automatic submissions.', 'publishrank' ) ); }
		if ( '' !== $endpoint ) {
			$encrypted = self::encrypt( $endpoint );
			if ( '' === $encrypted ) {
				$this->redirect( 'error', __( 'Secure endpoint storage is unavailable. Enable OpenSSL and valid WordPress security salts.', 'publishrank' ) ); }
			update_option( self::ENDPOINT_OPTION, $encrypted, false );
		}
		update_option( 'publishrank_baidu_automatic', $automatic, false );
		$this->redirect( 'success', __( 'Baidu settings saved. The API endpoint and token are encrypted and never shown again.', 'publishrank' ) );
	}

	public function submit_manual() {
		$this->guard( 'publishrank_baidu_submit' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$text = isset( $_POST['urls'] ) ? (string) wp_unslash( $_POST['urls'] ) : '';
		if ( strlen( $text ) > 524288 ) {
			$this->redirect( 'error', __( 'The URL list is too large. Split it into batches of at most 2,000 URLs.', 'publishrank' ) ); }
		$urls = preg_split( '/\r\n|\r|\n/', $text );
		if ( count( $urls ) > self::MAX_BATCH + 500 ) {
			$this->redirect( 'error', __( 'The URL list has too many lines. Split it into batches of at most 2,000 URLs.', 'publishrank' ) ); }
		$result = $this->submit_urls( $urls, 0 );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() ); }
		$success = (int) ( $result['success'] ?? 0 );
		$remain  = isset( $result['remain'] ) ? (int) $result['remain'] : -1;
		/* translators: %1$d: total count for the preceding category; %2$d: numeric count or measurement described by the message. */
		$message = sprintf( __( 'Baidu accepted %1$d of %2$d submitted URLs. This is a discovery submission, not an indexing guarantee.', 'publishrank' ), $success, (int) $result['submitted'] );
		/* translators: %d: numeric count or measurement described by the message. */
		if ( $remain >= 0 ) {
			/* translators: %d: number of submissions remaining in Baidu quota. */
			$message .= ' ' . sprintf( __( 'Reported quota remaining: %d.', 'publishrank' ), $remain ); }
		$this->redirect( 'success', $message );
	}

	public function disconnect() {
		$this->guard( 'publishrank_baidu_disconnect' );
		delete_option( self::ENDPOINT_OPTION );
		delete_option( self::HISTORY_OPTION );
		update_option( 'publishrank_baidu_automatic', false, false );
		$this->redirect( 'success', __( 'The encrypted Baidu endpoint and local response history were deleted.', 'publishrank' ) );
	}

	/** Triggered only for a post that IndexNow has already checked as public and indexable. */
	public function queue_post( $post, $url ) {
		if ( ! self::automatic() || ! self::configured() || ! $post instanceof \WP_Post || empty( $post->ID ) ) {
			return; }
		if ( get_post_meta( $post->ID, '_publishrank_baidu_submitted', true ) || get_post_meta( $post->ID, '_publishrank_baidu_pending', true ) ) {
			return; }
		if ( ! wp_next_scheduled( 'publishrank_baidu_submit_post', array( (int) $post->ID, 0 ) ) ) {
			if ( wp_schedule_single_event( time() + 10, 'publishrank_baidu_submit_post', array( (int) $post->ID, 0 ) ) ) {
				update_post_meta( $post->ID, '_publishrank_baidu_pending', 1 ); }
		}
	}

	public function submit_post( $post_id, $attempt = 0 ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			delete_post_meta( $post_id, '_publishrank_baidu_pending' );
			return; }
		$eligible = new IndexNow();
		if ( ! self::automatic() || ! self::configured() || ! $eligible->is_indexable_post( $post ) ) {
			delete_post_meta( $post_id, '_publishrank_baidu_pending' );
			return; }
		$url    = get_permalink( $post );
		$result = $this->submit_urls( array( $url ), $post_id );
		if ( is_wp_error( $result ) ) {
			if ( (int) $attempt < self::MAX_RETRIES && in_array( $result->get_error_code(), array( 'baidu_network', 'baidu_temporary' ), true ) ) {
				$next  = (int) $attempt + 1;
				$delay = min( HOUR_IN_SECONDS, 60 * ( 2 ** ( $next - 1 ) ) );
				if ( ! wp_schedule_single_event( time() + $delay, 'publishrank_baidu_submit_post', array( $post_id, $next ) ) ) {
					delete_post_meta( $post_id, '_publishrank_baidu_pending' ); }
				return;
			}
			delete_post_meta( $post_id, '_publishrank_baidu_pending' );
			return;
		}
		delete_post_meta( $post_id, '_publishrank_baidu_pending' );
		if ( (int) ( $result['success'] ?? 0 ) > 0 ) {
			update_post_meta( $post_id, '_publishrank_baidu_submitted', gmdate( 'c' ) ); }
	}

	/** Submit 1–2,000 unique, same-host URLs in a single plain-text request. */
	public function submit_urls( $urls, $post_id = 0 ) {
		$endpoint = self::endpoint();
		if ( '' === $endpoint || ! $this->valid_endpoint( $endpoint ) ) {
			return new \WP_Error( 'baidu_not_configured', __( 'Configure a valid HTTPS Baidu site endpoint first.', 'publishrank' ) ); }
		$valid = array();
		foreach ( (array) $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			} if ( strlen( $url ) > 2048 ) {
				return new \WP_Error( 'baidu_invalid_url', __( 'A submitted URL exceeds the 2,048-character safety limit.', 'publishrank' ) );
			} $url = esc_url_raw( $url );
			if ( ! $this->valid_site_url( $url ) ) {
				return new \WP_Error( 'baidu_invalid_url', __( 'Every submitted URL must be a valid HTTP(S) URL on this exact WordPress host.', 'publishrank' ) );
			} $valid[] = $url; }
		$valid = array_values( array_unique( $valid ) );
		if ( empty( $valid ) || count( $valid ) > self::MAX_BATCH ) {
			return new \WP_Error( 'baidu_invalid_batch', __( 'Provide between 1 and 2,000 unique same-host URLs.', 'publishrank' ) ); }
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type' => 'text/plain; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'body'        => implode( "\n", $valid ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->record(
				array(
					'status'    => 'network_error',
					'http'      => 0,
					'submitted' => count( $valid ),
					'success'   => 0,
					'remain'    => null,
					'invalid'   => 0,
					'same_site' => 0,
				)
			);
			return new \WP_Error( 'baidu_network', __( 'Could not reach the Baidu endpoint over verified HTTPS.', 'publishrank' ) ); }
		$http    = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $payload ) ) {
			$this->record(
				array(
					'status'    => 'invalid_response',
					'http'      => $http,
					'submitted' => count( $valid ),
					'success'   => 0,
					'remain'    => null,
					'invalid'   => 0,
					'same_site' => 0,
				)
			);
			return new \WP_Error( $http >= 500 || 429 === $http ? 'baidu_temporary' : 'baidu_response', __( 'Baidu returned an unrecognized response; no response body or endpoint credentials were stored.', 'publishrank' ) ); }
		$success       = max( 0, min( count( $valid ), (int) ( $payload['success'] ?? 0 ) ) );
		$remain        = isset( $payload['remain'] ) && is_numeric( $payload['remain'] ) ? max( 0, (int) $payload['remain'] ) : null;
		$invalid       = $this->count_field( $payload['not_valid'] ?? 0 );
		$same_site     = $this->count_field( $payload['not_same_site'] ?? 0 );
		$has_api_error = isset( $payload['error'] ) || isset( $payload['errorCode'] ) || ( isset( $payload['message'] ) && ! isset( $payload['success'] ) );
		$accepted_http = $http >= 200 && $http < 300;
		$status        = $accepted_http && ! $has_api_error ? 'accepted' : ( 429 === $http || $http >= 500 ? 'temporary_error' : 'api_error' );
		$this->record(
			array(
				'status'    => $status,
				'http'      => $http,
				'submitted' => count( $valid ),
				'success'   => $success,
				'remain'    => $remain,
				'invalid'   => $invalid,
				'same_site' => $same_site,
			)
		);
		if ( ! $accepted_http || $has_api_error ) {
			return new \WP_Error( 'baidu_temporary' === $status ? 'baidu_temporary' : 'baidu_api', __( 'Baidu rejected the submission or returned an error. Review its token, verified-site endpoint and remaining quota.', 'publishrank' ) ); }
		return array(
			'submitted' => count( $valid ),
			'success'   => $success,
			'remain'    => $remain,
			'invalid'   => $invalid,
			'same_site' => $same_site,
		);
	}

	private function valid_endpoint( $endpoint ) {
		$parts = wp_parse_url( (string) $endpoint );
		if ( strlen( (string) $endpoint ) > 2048 || ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || isset( $parts['fragment'] ) || ! in_array( strtolower( $parts['host'] ), array( 'data.zz.baidu.com', 'data.baidu.com' ), true ) || '/urls' !== (string) ( $parts['path'] ?? '' ) || empty( $parts['query'] ) ) {
			return false; }
		parse_str( (string) $parts['query'], $query );
		if ( empty( $query['site'] ) || empty( $query['token'] ) ) {
			return false; }
		$site      = (string) $query['site'];
		$site_host = wp_parse_url( $site, PHP_URL_HOST );
		if ( ! $site_host ) {
			$site_host = preg_replace( '/:\d+$/', '', $site ); }
		return $this->same_registered_host( (string) $site_host );
	}

	private function valid_site_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		$home  = wp_parse_url( home_url( '/' ) );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) && empty( $parts['user'] ) && empty( $parts['pass'] ) && is_array( $home ) && isset( $home['host'] ) && strtolower( (string) $parts['host'] ) === strtolower( (string) $home['host'] );
	}

	private function same_registered_host( $host ) {
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! $home ) {
			return false; }
		$normalize = static function ( $value ) {
			return preg_replace( '/^www\./i', '', strtolower( trim( (string) $value, ". \t\n\r\0\x0B" ) ) );
		};
		return $normalize( $host ) === $normalize( $home );
	}

	private function count_field( $field ) {
		if ( is_array( $field ) ) {
			return count( $field );
		} return max( 0, (int) $field ); }
	private static function endpoint() {
		return self::decrypt( (string) get_option( self::ENDPOINT_OPTION, '' ) ); }

	private function record( $row ) {
		$history = self::history();
		array_unshift(
			$history,
			array(
				'time'      => gmdate( 'c' ),
				'status'    => sanitize_key( $row['status'] ?? 'error' ),
				'http'      => (int) ( $row['http'] ?? 0 ),
				'submitted' => (int) ( $row['submitted'] ?? 0 ),
				'success'   => (int) ( $row['success'] ?? 0 ),
				'remain'    => null === ( $row['remain'] ?? null ) ? null : (int) $row['remain'],
				'invalid'   => (int) ( $row['invalid'] ?? 0 ),
				'same_site' => (int) ( $row['same_site'] ?? 0 ),
			)
		);
		update_option( self::HISTORY_OPTION, array_slice( $history, 0, 30 ), false );
	}

	private static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) || ! function_exists( 'wp_salt' ) || '' === (string) wp_salt( 'auth' ) ) {
			return '';
		} try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $e ) {
			return '';
		} $key  = hash_hmac( 'sha256', 'publishrank-baidu-endpoint-v1', wp_salt( 'auth' ), true );
		$tag    = '';
		$cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'publishrank-baidu-v1', 16 );
		return false === $cipher ? '' : base64_encode( $iv . $tag . $cipher ); }
	private static function decrypt( $encoded ) {
		if ( '' === $encoded || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'wp_salt' ) ) {
			return '';
		} $raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		} $key = hash_hmac( 'sha256', 'publishrank-baidu-endpoint-v1', wp_salt( 'auth' ), true );
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'publishrank-baidu-v1' );
		return false === $plain ? '' : $plain; }
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Baidu submissions.', 'publishrank' ), '', array( 'response' => 403 ) );
		} check_admin_referer( $action ); }
	private function redirect( $type, $message ) {
		set_transient(
			'publishrank_baidu_notice_' . get_current_user_id(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => (string) $message,
			),
			120
		);
		wp_safe_redirect( admin_url( 'admin.php?page=publishrank-baidu' ) );
		exit; }
}
