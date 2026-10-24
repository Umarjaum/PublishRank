<?php
/** Publisher-safe IndexNow integration for participating search engines. */
namespace PublishRankSEO\Indexing;

defined( 'ABSPATH' ) || exit;

final class IndexNow {
	const LOG_OPTION  = 'publishrank_indexnow_log';
	const MAX_LOGS    = 100;
	const MAX_RETRIES = 3;

	public static function activate() {
		if ( ! get_option( 'publishrank_indexnow_key', '' ) ) {
			update_option( 'publishrank_indexnow_key', wp_generate_password( 32, false, false ), false );
		}
		$instance = new self();
		$instance->rewrite();
		flush_rewrite_rules();
		update_option( 'publishrank_indexnow_schema_version', '4', false );
	}

	public function register() {
		add_action( 'init', array( $this, 'maybe_upgrade' ), 0 );
		add_action( 'init', array( $this, 'rewrite' ) );
		add_action( 'template_redirect', array( $this, 'key_endpoint' ) );
		add_action( 'save_post', array( $this, 'queue_post' ), 30, 3 );
		add_action( 'transition_post_status', array( $this, 'queue_status_transition' ), 30, 3 );
		add_action( 'post_updated', array( $this, 'queue_permalink_change' ), 30, 3 );
		add_action( 'deleted_post', array( $this, 'queue_deleted_post' ), 20, 2 );
		add_action( 'publishrank_indexnow_submit', array( $this, 'submit_queued' ), 10, 4 );
		add_action( 'publishrank_indexnow_flush_queue', array( $this, 'flush_queue' ) );
	}

	public function maybe_upgrade() {
		if ( '4' === (string) get_option( 'publishrank_indexnow_schema_version', '' ) ) {
			return; }
		if ( ! get_option( 'publishrank_indexnow_key', '' ) ) {
			update_option( 'publishrank_indexnow_key', wp_generate_password( 32, false, false ), false );
		}
		if ( false === get_option( 'publishrank_indexnow_queue', false ) ) {
			update_option( 'publishrank_indexnow_queue', array(), false ); }
		$this->rewrite();
		flush_rewrite_rules( false );
		update_option( 'publishrank_indexnow_schema_version', '4', false );
	}

	public function rewrite() {
		add_rewrite_tag( '%publishrank_indexnow_key%', '([0-1])' );
		add_rewrite_rule( '^publishrank-indexnow-key\.txt$', 'index.php?publishrank_indexnow_key=1', 'top' );
	}

	public function key_endpoint() {
		if ( '1' !== (string) get_query_var( 'publishrank_indexnow_key' ) ) {
			return; }
		$key = $this->key();
		if ( ! $key ) {
			status_header( 404 );
			exit; }
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo esc_html( $key ); // The endpoint intentionally returns only the verification key.
		exit;
	}

	public function can_submit_post( $post ) {
		return $this->is_indexable_post( $post );
	}

	public function queue_post( $post_id, $post, $update ) {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return; }
		if ( 'publish' !== $post->post_status || ! $this->allowed_type( $post->post_type ) || ! $this->is_indexable_post( $post ) ) {
			return; }
		$url = get_permalink( $post );
		$this->queue_url( $url, (int) $post_id, 'publish', 0 );
	}

	/**
	 * Notify IndexNow when content changes from published to another status,
	 * or becomes public. Duplicate save_post notifications are coalesced by queue_url().
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function queue_status_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return; }
		if ( ! $this->allowed_type( $post->post_type ) ) {
			return; }
		$url = get_permalink( $post );
		if ( ! $url ) {
			return; }
		if ( 'publish' === $new_status && 'publish' !== $old_status && $this->is_indexable_post( $post ) ) {
			do_action( 'publishrank_post_indexable_published', $post, $url );
			$this->queue_url( $url, (int) $post->ID, 'publish', 0 );
		} elseif ( 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->queue_url( $url, (int) $post->ID, 'delete', 0 );
		}
	}

	/**
	 * Notify the old and new URLs when a published post slug/permalink changes.
	 *
	 * @param int      $post_id     Updated post ID.
	 * @param \WP_Post $post_after  Updated post.
	 * @param \WP_Post $post_before Previous post.
	 */
	public function queue_permalink_change( $post_id, $post_after, $post_before ) {
		if ( ! $post_after instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
			return; }
		if ( 'publish' !== $post_after->post_status || 'publish' !== $post_before->post_status ) {
			return; }
		if ( wp_is_post_revision( $post_after->ID ) || wp_is_post_autosave( $post_after->ID ) || ! $this->allowed_type( $post_after->post_type ) ) {
			return; }
		$old_url = get_permalink( $post_before );
		$new_url = get_permalink( $post_after );
		if ( ! $old_url || ! $new_url || untrailingslashit( $old_url ) === untrailingslashit( $new_url ) ) {
			return; }
		$this->queue_url( $old_url, (int) $post_after->ID, 'delete', 0 );
		if ( $this->is_indexable_post( $post_after ) ) {
			$this->queue_url( $new_url, (int) $post_after->ID, 'publish', 0 );
		}
	}

	public function queue_deleted_post( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || ! $this->allowed_type( $post->post_type ) || 'publish' !== $post->post_status ) {
			return; }
		$url = get_permalink( $post );
		$this->queue_url( $url, (int) $post_id, 'delete', 0 );
	}

	private function queue_url( $url, $post_id, $event, $attempt ) {
		if ( ! get_option( 'publishrank_indexnow_enabled', false ) || ! $this->key() || ! $this->valid_site_url( $url ) || $this->is_excluded_url( $url ) ) {
			return; }
		$url        = esc_url_raw( $url );
		$event      = sanitize_key( $event );
		$dedupe_key = 'publishrank_indexnow_dedupe_' . md5( strtolower( untrailingslashit( $url ) ) . '|' . $event );
		if ( get_transient( $dedupe_key ) ) {
			return; }
		set_transient( $dedupe_key, 1, 5 * MINUTE_IN_SECONDS );
		$queue = get_option( 'publishrank_indexnow_queue', array() );
		if ( ! is_array( $queue ) ) {
			$queue = array(); }
		$item_key           = md5( strtolower( untrailingslashit( $url ) ) . '|' . $event );
		$queue[ $item_key ] = array(
			'url'     => $url,
			'post_id' => (int) $post_id,
			'event'   => $event,
			'attempt' => (int) $attempt,
		);
		// Bound persistent queue growth on very large sites or during prolonged API outages.
		if ( count( $queue ) > 5000 ) {
			$queue = array_slice( $queue, -5000, null, true );
		}
		update_option( 'publishrank_indexnow_queue', $queue, false );
		if ( ! wp_next_scheduled( 'publishrank_indexnow_flush_queue' ) ) {
			wp_schedule_single_event( time() + 1, 'publishrank_indexnow_flush_queue' );
			// Ask WordPress to spawn the cron runner now. The queued request is
			// still non-blocking; this avoids depending solely on the next visitor.
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron( time() ); }
		}
	}

	/** Process queued URLs in batches to reduce outbound requests on high-volume publishers. */
	public function flush_queue() {
		if ( ! get_option( 'publishrank_indexnow_enabled', false ) || ! $this->key() ) {
			return; }
		if ( get_transient( 'publishrank_indexnow_queue_lock' ) ) {
			if ( ! wp_next_scheduled( 'publishrank_indexnow_flush_queue' ) ) {
				wp_schedule_single_event( time() + 30, 'publishrank_indexnow_flush_queue' ); }
			return;
		}
		set_transient( 'publishrank_indexnow_queue_lock', 1, 2 * MINUTE_IN_SECONDS );
		$queue = get_option( 'publishrank_indexnow_queue', array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			delete_transient( 'publishrank_indexnow_queue_lock' );
			return;
		}
		$batch = array_slice( $queue, 0, 100, true );
		$ready = array();
		foreach ( $batch as $queue_key => $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) || ! $this->valid_site_url( $item['url'] ) || $this->is_excluded_url( $item['url'] ) ) {
				continue; }
			if ( isset( $item['event'] ) && 'publish' === $item['event'] && ! empty( $item['post_id'] ) && ! $this->is_indexable_post( get_post( (int) $item['post_id'] ) ) ) {
				$this->record( $item['url'], 'skipped', 0, 'Post is no longer publicly indexable.', 'publish' );
				continue;
			}
			$ready[ $queue_key ] = $item;
		}
		if ( ! empty( $ready ) ) {
			$this->submit_batch_items( $ready ); }
		// Re-read before removing processed items so URLs enqueued during the HTTP request survive.
		$current = get_option( 'publishrank_indexnow_queue', array() );
		if ( ! is_array( $current ) ) {
			$current = array(); }
		foreach ( $batch as $queue_key => $item ) {
			if ( isset( $current[ $queue_key ] ) && $current[ $queue_key ] === $item ) {
				unset( $current[ $queue_key ] );
			}
		}
		update_option( 'publishrank_indexnow_queue', $current, false );
		delete_transient( 'publishrank_indexnow_queue_lock' );
		if ( ! empty( $current ) && ! wp_next_scheduled( 'publishrank_indexnow_flush_queue' ) ) {
			wp_schedule_single_event( time() + 15, 'publishrank_indexnow_flush_queue' );
		}
	}

	/** Send a single batched request and retry temporary failures with bounded exponential backoff. */
	private function submit_batch_items( $items ) {
		$key  = $this->key();
		$urls = array();
		foreach ( $items as $item ) {
			$urls[] = $item['url']; }
		$urls = array_values( array_unique( $urls ) );
		if ( empty( $urls ) || count( $urls ) > 100 ) {
			return; }
		$host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$response = wp_remote_post(
			'https://api.indexnow.org/indexnow/',
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json, text/plain, */*',
				),
				'body'        => wp_json_encode(
					array(
						'host'        => $host,
						'key'         => $key,
						'keyLocation' => home_url( '/publishrank-indexnow-key.txt' ),
						'urlList'     => $urls,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$status  = 0;
			$message = $response->get_error_message(); } else {
			$status  = (int) wp_remote_retrieve_response_code( $response );
			$message = $this->response_message( $status ); }
			$success     = in_array( $status, array( 200, 202 ), true );
			$log_entries = array();
			foreach ( $items as $item ) {
				$event         = isset( $item['event'] ) ? sanitize_key( $item['event'] ) : 'publish';
				$log_entries[] = array(
					'url'         => $item['url'],
					'result'      => $success ? 'accepted' : 'failed',
					'http_status' => $status,
					'message'     => $message,
					'event'       => $event,
				);
				if ( ! $success && ( 0 === $status || 429 === $status || $status >= 500 ) && (int) ( isset( $item['attempt'] ) ? $item['attempt'] : 0 ) < self::MAX_RETRIES ) {
					$next_attempt = (int) ( isset( $item['attempt'] ) ? $item['attempt'] : 0 ) + 1;
					$delay        = min( 3600, 60 * ( 2 ** ( $next_attempt - 1 ) ) );
					wp_schedule_single_event( time() + $delay, 'publishrank_indexnow_submit', array( $item['url'], (int) ( isset( $item['post_id'] ) ? $item['post_id'] : 0 ), $event, $next_attempt ) );
				}
			}
			$this->record_many( $log_entries );
	}

	/** Submit a validated batch using one IndexNow request (maximum 100 URLs per admin action). */
	public function submit_bulk( $urls, $event = 'manual' ) {
		$key = $this->key();
		if ( ! $key ) {
			return new \WP_Error( 'indexnow_disabled', __( 'A valid IndexNow key is required before submitting URLs.', 'publishrank' ) ); }
		$valid = array();
		foreach ( (array) $urls as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( $url && $this->valid_site_url( $url ) && ! $this->is_excluded_url( $url ) ) {
				$valid[] = $url; }
		}
		$valid = array_values( array_unique( $valid ) );
		if ( empty( $valid ) || count( $valid ) > 100 ) {
			return new \WP_Error( 'indexnow_invalid_batch', __( 'Provide between 1 and 100 valid URLs from this website.', 'publishrank' ) ); }
		$host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$response = wp_remote_post(
			'https://api.indexnow.org/indexnow/',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => wp_json_encode(
					array(
						'host'        => $host,
						'key'         => $key,
						'keyLocation' => home_url( '/publishrank-indexnow-key.txt' ),
						'urlList'     => $valid,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$status  = 0;
			$message = $response->get_error_message(); } else {
			$status  = (int) wp_remote_retrieve_response_code( $response );
			$message = $this->response_message( $status ); }
			$success = in_array( $status, array( 200, 202 ), true );
			foreach ( $valid as $url ) {
				$this->record( $url, $success ? 'accepted' : 'failed', $status, $message, $event ); }
			if ( ! $success && ( 0 === $status || 429 === $status || $status >= 500 ) ) {
				foreach ( $valid as $url ) {
					wp_schedule_single_event( time() + 60, 'publishrank_indexnow_submit', array( $url, 0, sanitize_key( $event ), 1 ) ); }
			}
			return array(
				'status'  => $status,
				'count'   => count( $valid ),
				'success' => $success,
				'message' => $message,
			);
	}

	/** Submit one queued URL and retry only temporary failures. */
	public function submit_queued( $url, $post_id = 0, $event = 'publish', $attempt = 0 ) {
		$url = esc_url_raw( (string) $url );
		$key = $this->key();
		if ( ! $key || ( ! get_option( 'publishrank_indexnow_enabled', false ) && in_array( $event, array( 'publish', 'delete' ), true ) ) || ! $this->valid_site_url( $url ) || $this->is_excluded_url( $url ) ) {
			return; }
		if ( 'publish' === $event && $post_id && ! $this->is_indexable_post( get_post( $post_id ) ) ) {
			$this->record( $url, 'skipped', 0, 'Post is no longer publicly indexable.', $event );
			return;
		}
		$host         = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$key_location = home_url( '/publishrank-indexnow-key.txt' );
		$payload      = array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => $key_location,
			'urlList'     => array( $url ),
		);
		$response     = wp_remote_post(
			'https://api.indexnow.org/indexnow/',
			array(
				'timeout'     => 12,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json, text/plain, */*',
				),
				'body'        => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$status  = 0;
			$message = $response->get_error_message();
		} else {
			$status  = (int) wp_remote_retrieve_response_code( $response );
			$message = $this->response_message( $status );
		}
		$success = in_array( $status, array( 200, 202 ), true );
		$this->record( $url, $success ? 'accepted' : 'failed', $status, $message, $event );
		if ( ! $success && ( 0 === $status || 429 === $status || $status >= 500 ) && (int) $attempt < self::MAX_RETRIES ) {
			$next_attempt = (int) $attempt + 1;
			$delay        = min( 3600, 60 * ( 2 ** ( $next_attempt - 1 ) ) );
			wp_schedule_single_event( time() + $delay, 'publishrank_indexnow_submit', array( $url, (int) $post_id, sanitize_key( $event ), $next_attempt ) );
		}
	}

	private function response_message( $status ) {
		$messages = array(
			200 => 'Accepted by IndexNow endpoint.',
			202 => 'Accepted; key verification may be pending.',
			400 => 'Bad request: check payload and URL encoding.',
			403 => 'Key verification failed. Check the public key file.',
			422 => 'URL host or key does not match the protocol requirements.',
			429 => 'Rate limited; a retry may be scheduled.',
		);
		if ( isset( $messages[ $status ] ) ) {
			return $messages[ $status ]; }
		return $status >= 500 ? 'IndexNow endpoint server error; a retry may be scheduled.' : ( 0 === $status ? 'Network request failed; a retry may be scheduled.' : 'HTTP response from IndexNow endpoint.' );
	}

	private function record( $url, $result, $http_status, $message, $event ) {
		$logs = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array(); }
		array_unshift(
			$logs,
			array(
				'time'        => gmdate( 'c' ),
				'url'         => esc_url_raw( $url ),
				'result'      => sanitize_key( $result ),
				'http_status' => (int) $http_status,
				'message'     => sanitize_text_field( $message ),
				'event'       => sanitize_key( $event ),
			)
		);
		$logs = array_slice( $logs, 0, self::MAX_LOGS );
		update_option( self::LOG_OPTION, $logs, false );
		update_option( 'publishrank_indexnow_last_status', (int) $http_status . ':' . gmdate( 'c' ), false );
		\PublishRankSEO\AnalyticsCollector::record_indexnow_result( $result );
	}


	/** Persist a batch of log records with one options write instead of one write per URL. */
	private function record_many( $entries ) {
		$logs = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array(); }
		$time     = gmdate( 'c' );
		$new_logs = array();
		foreach ( $entries as $entry ) {
			$new_logs[] = array(
				'time'        => $time,
				'url'         => esc_url_raw( isset( $entry['url'] ) ? $entry['url'] : '' ),
				'result'      => sanitize_key( isset( $entry['result'] ) ? $entry['result'] : 'failed' ),
				'http_status' => (int) ( isset( $entry['http_status'] ) ? $entry['http_status'] : 0 ),
				'message'     => sanitize_text_field( isset( $entry['message'] ) ? $entry['message'] : '' ),
				'event'       => sanitize_key( isset( $entry['event'] ) ? $entry['event'] : 'publish' ),
			);
		}
		$logs = array_slice( array_merge( $new_logs, $logs ), 0, self::MAX_LOGS );
		update_option( self::LOG_OPTION, $logs, false );
		$last = ! empty( $entries ) ? end( $entries ) : array();
		update_option( 'publishrank_indexnow_last_status', (int) ( isset( $last['http_status'] ) ? $last['http_status'] : 0 ) . ':' . $time, false );
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && isset( $entry['result'] ) ) {
				\PublishRankSEO\AnalyticsCollector::record_indexnow_result( $entry['result'] ); }
		}
	}

	private function key() {
		$key = (string) get_option( 'publishrank_indexnow_key', '' );
		return preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key ) ? $key : '';
	}

	private function is_excluded_url( $url ) {
		$raw = (string) get_option( 'publishrank_indexnow_excluded_paths', '' );
		if ( '' === trim( $raw ) ) {
			return false; }
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$patterns = preg_split( '/\r\n|\r|\n/', $raw );
		foreach ( $patterns as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' === $pattern || 0 === strpos( $pattern, '#' ) ) {
				continue; }
			$pattern = '/' . ltrim( $pattern, '/' );
			$regex   = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
			$regex   = str_replace( '\\.\*', '.*', $regex );
			if ( preg_match( $regex, $path ) ) {
				return true; }
		}
		return false;
	}

	private function valid_site_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false; }
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) && isset( $home['host'] ) && strtolower( $parts['host'] ) === strtolower( $home['host'] ) && ( empty( $parts['user'] ) && empty( $parts['pass'] ) );
	}

	private function allowed_type( $type ) {
		$types = preg_split( '/[\s,]+/', (string) get_option( 'publishrank_indexnow_post_types', 'post' ), -1, PREG_SPLIT_NO_EMPTY );
		$types = array_map( 'sanitize_key', $types );
		return in_array( $type, $types, true ) && post_type_exists( $type ) && is_post_type_viewable( $type );
	}

	public function is_indexable_post( $post ) {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== (string) $post->post_password || ! $this->allowed_type( $post->post_type ) ) {
			return false; }
		$permalink = get_permalink( $post );
		if ( ! $permalink || $this->is_excluded_url( $permalink ) ) {
			return false; }
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return false; }
		$robots = strtolower( (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ) );
		if ( '1' === $robots || 'noindex' === $robots ) {
			return false; }
		$robots = get_post_meta( $post->ID, 'rank_math_robots', true );
		if ( is_array( $robots ) && in_array( 'noindex', array_map( 'strtolower', array_map( 'strval', $robots ) ), true ) ) {
			return false; }
		if ( is_string( $robots ) && false !== strpos( strtolower( $robots ), 'noindex' ) ) {
			return false; }
		$publishrank_robots = strtolower( (string) get_post_meta( $post->ID, '_publishrank_robots', true ) );
		if ( false !== strpos( $publishrank_robots, 'noindex' ) ) {
			return false; }
		return true;
	}
}
