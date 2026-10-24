<?php
/** Read-only Google Search Console integration; no front-end or save_post network calls. */
namespace PublishRankSEO\Google;

defined( 'ABSPATH' ) || exit;

final class SearchConsoleClient {
	const API           = 'https://www.googleapis.com/webmasters/v3';
	const SCOPE         = 'https://www.googleapis.com/auth/webmasters.readonly';
	const CLIENT_ID     = 'publishrank_gsc_client_id';
	const CLIENT_SECRET = 'publishrank_gsc_client_secret_enc';
	const REFRESH_TOKEN = 'publishrank_gsc_refresh_token_enc';
	const ACCESS_CACHE  = 'publishrank_gsc_access_cache_enc';
	const PROPERTY      = 'publishrank_gsc_property';
	const SITES_CACHE   = 'publishrank_gsc_sites_cache';
	const METRICS       = 'publishrank_gsc_daily_metrics';
	const SITEMAPS      = 'publishrank_gsc_sitemaps';
	const CONN_ID       = 'publishrank_gsc_conn_id';
	const CONN_SECRET   = 'publishrank_gsc_conn_secret_enc';
	const EMAIL         = 'publishrank_gsc_email';
	const REAUTH        = 'publishrank_gsc_needs_reauth';

	public function register() {
		add_action( 'admin_post_publishrank_gsc_save_credentials', array( $this, 'save_credentials' ) );
		add_action( 'admin_post_publishrank_gsc_connect', array( $this, 'begin_authorization' ) );
		add_action( 'admin_post_publishrank_gsc_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_publishrank_gsc_save_property', array( $this, 'save_property' ) );
		add_action( 'admin_post_publishrank_gsc_refresh', array( $this, 'refresh_metrics' ) );
		add_action( 'admin_post_publishrank_gsc_refresh_sitemaps', array( $this, 'refresh_sitemaps' ) );
		add_action( 'admin_post_publishrank_gsc_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_publishrank_gsc_inspect', array( $this, 'inspect_url' ) );
	}

	public static function callback_url() {
		return admin_url( 'admin-post.php?action=publishrank_gsc_callback' ); }
	public static function client_id() {
		return (string) get_option( self::CLIENT_ID, '' ); }
	public static function configured() {
		return '' !== self::client_id() && '' !== self::decrypt( get_option( self::CLIENT_SECRET, '' ) ); }
	public static function connection_id() {
		return (string) get_option( self::CONN_ID, '' ); }
	public static function email() {
		return (string) get_option( self::EMAIL, '' ); }
	public static function uses_broker() {
		return '' !== self::connection_id(); }
	public static function connected() {
		return self::uses_broker() || '' !== self::decrypt( get_option( self::REFRESH_TOKEN, '' ) ); }
	public static function property() {
		return (string) get_option( self::PROPERTY, '' ); }
	public static function metrics() {
		$value = get_option( self::METRICS, array() );
		return is_array( $value ) ? $value : array(); }
	public static function sitemaps() {
		$value = get_option( self::SITEMAPS, array() );
		return is_array( $value ) ? $value : array(); }
	public static function cached_sites() {
		$value = get_option( self::SITES_CACHE, array() );
		return is_array( $value ) ? $value : array(); }

	public function save_credentials() {
		$this->guard( 'publishrank_gsc_save_credentials' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$secret = isset( $_POST['client_secret'] ) ? trim( (string) wp_unslash( $_POST['client_secret'] ) ) : '';
		if ( '' !== $id && ! preg_match( '/^[A-Za-z0-9._-]{12,255}\.apps\.googleusercontent\.com$/', $id ) ) {
			$this->redirect( 'error', __( 'Enter a valid Google OAuth client ID.', 'publishrank' ) ); }
		if ( '' === $id && '' === self::client_id() ) {
			$this->redirect( 'error', __( 'A Google OAuth client ID is required.', 'publishrank' ) ); }
		if ( '' !== $id && 0 !== strcmp( $id, self::client_id() ) && '' === $secret ) {
			$this->redirect( 'error', __( 'When changing the OAuth client ID, enter the matching client secret too.', 'publishrank' ) ); }
		if ( '' === $secret && '' === self::decrypt( get_option( self::CLIENT_SECRET, '' ) ) ) {
			$this->redirect( 'error', __( 'A Google OAuth client secret is required for the first setup.', 'publishrank' ) ); }
		if ( '' !== $id ) {
			update_option( self::CLIENT_ID, $id, false ); }
		if ( '' !== $secret ) {
			$encrypted = self::encrypt( $secret );
			if ( '' === $encrypted ) {
				$this->redirect( 'error', __( 'Secure token storage is unavailable. Enable OpenSSL and valid WordPress security salts before saving OAuth credentials.', 'publishrank' ) ); }
			update_option( self::CLIENT_SECRET, $encrypted, false );
		}
		$this->redirect( 'success', __( 'Google OAuth configuration saved. The client secret is encrypted and is never shown again.', 'publishrank' ) );
	}

	public function begin_authorization() {
		$this->guard( 'publishrank_gsc_connect' );
		$broker = ! self::configured(); // own OAuth client saved => advanced/legacy flow, otherwise one-click broker sign-in
		if ( $broker ) {
			$health = GscBroker::health( true );
			if ( empty( $health['configured'] ) ) {
				$this->redirect( 'error', empty( $health['reachable'] ) ? __( 'The PublishRank Google sign-in service could not be reached. Check this server’s outbound HTTPS access and try again.', 'publishrank' ) : __( 'Google sign-in is not switched on for the PublishRank service yet. Please try again later.', 'publishrank' ) ); }
		}
		try {
			$state    = bin2hex( random_bytes( 24 ) );
			$verifier = self::base64url( random_bytes( 48 ) );
		} catch ( \Exception $e ) {
			$this->redirect( 'error', __( 'Secure random generation is unavailable on this server.', 'publishrank' ) ); }
		$transient = 'publishrank_gsc_state_' . hash( 'sha256', $state );
		set_transient(
			$transient,
			array(
				'user_id'  => get_current_user_id(),
				'verifier' => $verifier,
				'created'  => time(),
				'broker'   => $broker,
			),
			10 * MINUTE_IN_SECONDS
		);
		if ( $broker ) {
			wp_redirect( GscBroker::start_url( $state, self::base64url( hash( 'sha256', $verifier, true ) ), self::callback_url() ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external, fixed broker host.
			exit;
		}
		$query = array(
			'client_id'              => self::client_id(),
			'redirect_uri'           => self::callback_url(),
			'response_type'          => 'code',
			'scope'                  => self::SCOPE,
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
			'state'                  => $state,
			'code_challenge'         => self::base64url( hash( 'sha256', $verifier, true ) ),
			'code_challenge_method'  => 'S256',
		);
		wp_redirect( 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		exit;
	}

	public function callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect Search Console.', 'publishrank' ), '', array( 'response' => 403 ) ); }
		$state      = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bound to one-time state transient and current admin session.
		$transient  = 'publishrank_gsc_state_' . hash( 'sha256', $state );
		$state_data = '' !== $state ? get_transient( $transient ) : false;
		delete_transient( $transient );
		if ( ! is_array( $state_data ) || (int) ( $state_data['user_id'] ?? 0 ) !== get_current_user_id() || time() - (int) ( $state_data['created'] ?? 0 ) > 600 ) {
			$this->redirect( 'error', __( 'The Google authorization state expired or did not match this administrator. Start the connection again.', 'publishrank' ) ); }
		if ( ! empty( $state_data['broker'] ) ) {
			$this->finish_broker_connection( $state_data ); }
		if ( isset( $_GET['error'] ) ) {
			$this->redirect( 'error', __( 'Google authorization was denied or cancelled.', 'publishrank' ) ); }
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			$this->redirect( 'error', __( 'Google did not return an authorization code.', 'publishrank' ) ); }
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => array(
					'code'          => $code,
					'client_id'     => self::client_id(),
					'client_secret' => self::decrypt( get_option( self::CLIENT_SECRET, '' ) ),
					'redirect_uri'  => self::callback_url(),
					'grant_type'    => 'authorization_code',
					'code_verifier' => (string) ( $state_data['verifier'] ?? '' ),
				),
			)
		);
		$tokens   = $this->decode_response( $response );
		if ( is_wp_error( $tokens ) || empty( $tokens['access_token'] ) ) {
			$this->redirect( 'error', __( 'Google token exchange failed. Check the OAuth client, callback URL and Search Console API setup; sensitive provider details were not logged.', 'publishrank' ) ); }
		if ( ! empty( $tokens['refresh_token'] ) ) {
			$this->store_secret( self::REFRESH_TOKEN, $tokens['refresh_token'] ); }
		$this->cache_access_token( $tokens );
		$sites = $this->request( 'GET', '/sites' );
		if ( is_wp_error( $sites ) ) {
			$this->redirect( 'error', __( 'Connected to Google, but could not list Search Console properties. Check the Google account’s property access and try again.', 'publishrank' ) ); }
		$entries = isset( $sites['siteEntry'] ) && is_array( $sites['siteEntry'] ) ? $sites['siteEntry'] : array();
		$allowed = array();
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['siteUrl'] ) && in_array( (string) ( $entry['permissionLevel'] ?? '' ), array( 'siteOwner', 'siteFullUser', 'siteRestrictedUser' ), true ) ) {
				$allowed[] = array(
					'siteUrl'         => (string) $entry['siteUrl'],
					'permissionLevel' => (string) $entry['permissionLevel'],
				); }
		}
		update_option( self::SITES_CACHE, $allowed, false );
		$this->redirect( 'success', __( 'Google Search Console connected with read-only access. Choose a verified property to begin.', 'publishrank' ) );
	}

	private function finish_broker_connection( $state_data ) {
		$err = isset( $_GET['pr_error'] ) ? sanitize_key( wp_unslash( $_GET['pr_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $err ) {
			$this->redirect( 'error', self::broker_error_message( $err ) ); }
		$ticket = isset( $_GET['ticket'] ) ? sanitize_text_field( wp_unslash( $_GET['ticket'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $ticket ) {
			$this->redirect( 'error', __( 'Google sign-in did not complete. Please try again.', 'publishrank' ) ); }
		$res = GscBroker::exchange( $ticket, (string) ( $state_data['verifier'] ?? '' ) );
		if ( is_wp_error( $res ) || empty( $res['connection_id'] ) || empty( $res['connection_secret'] ) ) {
			$this->redirect( 'error', __( 'Could not finish connecting to Google. Please sign in again.', 'publishrank' ) ); }
		if ( ! $this->store_secret( self::CONN_SECRET, (string) $res['connection_secret'] ) ) {
			$this->redirect( 'error', __( 'Secure token storage is unavailable. Enable OpenSSL and valid WordPress security salts, then try again.', 'publishrank' ) ); }
		// Replace any previous broker connection (best effort) and legacy local tokens.
		update_option( self::CONN_ID, sanitize_text_field( (string) $res['connection_id'] ), false );
		update_option( self::EMAIL, sanitize_email( (string) ( $res['email'] ?? '' ) ), false );
		delete_option( self::REAUTH );
		delete_option( self::REFRESH_TOKEN );
		delete_option( self::ACCESS_CACHE );
		if ( ! empty( $res['access_token'] ) ) {
			$this->cache_access_token(
				array(
					'access_token' => (string) $res['access_token'],
					'expires_in'   => (int) ( $res['expires_in'] ?? 3600 ),
				)
			); }
		$allowed = array();
		foreach ( (array) ( $res['sites'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['siteUrl'] ) && in_array( (string) ( $entry['permissionLevel'] ?? '' ), array( 'siteOwner', 'siteFullUser', 'siteRestrictedUser' ), true ) ) {
				$allowed[] = array(
					'siteUrl'         => (string) $entry['siteUrl'],
					'permissionLevel' => (string) $entry['permissionLevel'],
				); }
		}
		update_option( self::SITES_CACHE, $allowed, false );
		$auto = self::best_property( $allowed );
		if ( '' !== $auto ) {
			update_option( self::PROPERTY, $auto, false );
			LiveSync::sync(); }
		$this->redirect( 'success', '' !== $auto ? __( 'Google Search Console connected. Your property was selected automatically and live data is loading.', 'publishrank' ) : __( 'Google Search Console connected. Choose the property for this site to start live data.', 'publishrank' ) );
	}

	private static function broker_error_message( $code ) {
		$map = array(
			'denied'           => __( 'Google sign-in was cancelled.', 'publishrank' ),
			'scope_missing'    => __( 'Search Console permission was not granted. Sign in again and keep the “View Search Console data” box ticked.', 'publishrank' ),
			'no_property'      => __( 'This Google account has no Search Console property for this site. Add and verify the site in Search Console, or sign in with the account that owns it.', 'publishrank' ),
			'no_refresh_token' => __( 'Google did not grant lasting access. Remove PublishRank at myaccount.google.com/permissions and sign in again.', 'publishrank' ),
			'expired'          => __( 'The sign-in took too long. Please try again.', 'publishrank' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : __( 'Google sign-in failed. Please try again in a moment.', 'publishrank' );
	}

	/** Pick the Search Console property that best matches this site (domain property first). */
	public static function best_property( $sites ) {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$home = trailingslashit( home_url() );
		if ( '' === $host ) {
			return ''; }
		$best  = '';
		$score = 0;
		foreach ( (array) $sites as $site ) {
			$url = is_array( $site ) && isset( $site['siteUrl'] ) ? (string) $site['siteUrl'] : '';
			$s   = 0;
			if ( 0 === strpos( $url, 'sc-domain:' ) ) {
				$d = strtolower( substr( $url, 10 ) );
				if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) {
					$s = 90 + ( $host === $d ? 5 : 0 ); }
			} else {
				$u = trailingslashit( $url );
				if ( $u === $home ) {
					$s = 100;
				} elseif ( strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) === $host ) {
					$s = 60; }
			}
			if ( $s > $score ) {
				$score = $s;
				$best  = $url; }
		}
		return $best;
	}

	public function save_property() {
		$this->guard( 'publishrank_gsc_save_property' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$requested = isset( $_POST['property'] ) ? sanitize_text_field( wp_unslash( $_POST['property'] ) ) : '';
		$found     = false;
		foreach ( self::cached_sites() as $site ) {
			if ( is_array( $site ) && isset( $site['siteUrl'] ) && hash_equals( (string) $site['siteUrl'], $requested ) ) {
				$found = true;
				break; }
		}
		if ( ! $found ) {
			$this->redirect( 'error', __( 'Select a Search Console property returned for the connected Google account.', 'publishrank' ) ); }
		update_option( self::PROPERTY, $requested, false );
		$this->redirect( 'success', __( 'Search Console property saved.', 'publishrank' ) );
	}

	public function refresh_metrics() {
		$this->guard( 'publishrank_gsc_refresh' );
		$property = self::property();
		if ( ! $this->property_allowed( $property ) ) {
			$this->redirect( 'error', __( 'Reconnect or choose a property that is available to the connected Google account.', 'publishrank' ) ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 28;
		if ( ! in_array( $days, array( 7, 28, 90 ), true ) ) {
			$days = 28; }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$type = isset( $_POST['search_type'] ) ? sanitize_text_field( wp_unslash( $_POST['search_type'] ) ) : 'web';
		if ( ! in_array( $type, array( 'web', 'news', 'googleNews', 'discover' ), true ) ) {
			$type = 'web'; }
		$result = $this->sync_daily( $days, $type );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', __( 'Search Console refresh failed. Existing saved data was kept; check the connection and try again.', 'publishrank' ) ); }
		$snap = LiveSync::snapshot();
		if ( ( $snap['search_type'] ?? $type ) !== $type ) {
			delete_option( LiveSync::OPTION ); }
		LiveSync::sync();
		$this->redirect( 'success', __( 'Search Console data refreshed. The newest days are provisional and can change as Google finalises them.', 'publishrank' ) );
	}

	/** Fetch daily totals including today's provisional data and cache them. Used by manual refresh, cron and live sync. */
	public function sync_daily( $days = 28, $type = 'web' ) {
		$property = self::property();
		if ( ! $this->property_allowed( $property ) ) {
			return new \WP_Error( 'publishrank_gsc_property', __( 'Reconnect or choose a property that is available to the connected Google account.', 'publishrank' ) ); }
		$end    = gmdate( 'Y-m-d' );
		$start  = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		$result = $this->request(
			'POST',
			'/sites/' . rawurlencode( $property ) . '/searchAnalytics/query',
			array(
				'startDate'  => $start,
				'endDate'    => $end,
				'dimensions' => array( 'date' ),
				'type'       => $type,
				'dataState'  => 'all',
				'rowLimit'   => 25000,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result; }
		$daily = array();
		foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['keys'][0] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $row['keys'][0] ) ) {
				continue; }
			$date           = (string) $row['keys'][0];
			$daily[ $date ] = array(
				'clicks'      => max( 0, (int) ( $row['clicks'] ?? 0 ) ),
				'impressions' => max( 0, (int) ( $row['impressions'] ?? 0 ) ),
				'ctr'         => max( 0, min( 1, (float) ( $row['ctr'] ?? 0 ) ) ),
				'position'    => max( 0, (float) ( $row['position'] ?? 0 ) ),
			);
		}
		ksort( $daily );
		update_option(
			self::METRICS,
			array(
				'property'    => $property,
				'search_type' => $type,
				'start_date'  => $start,
				'end_date'    => $end,
				'fetched_at'  => gmdate( 'c' ),
				'days'        => $daily,
			),
			false
		);
		return $daily;
	}

	/** Fetch only the current property's sitemap inventory; never submits or deletes a sitemap. */
	public function refresh_sitemaps() {
		$this->guard( 'publishrank_gsc_refresh_sitemaps' );
		$property = self::property();
		if ( ! $this->property_allowed( $property ) ) {
			$this->redirect( 'error', __( 'Reconnect or choose a property that is available to the connected Google account.', 'publishrank' ) ); }
		$result = $this->request( 'GET', '/sites/' . rawurlencode( $property ) . '/sitemaps' );
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', __( 'Search Console sitemap status could not be refreshed. Existing saved data was kept.', 'publishrank' ) ); }
		$rows = array();
		foreach ( array_slice( (array) ( $result['sitemap'] ?? array() ), 0, 200 ) as $sitemap ) {
			if ( ! is_array( $sitemap ) || empty( $sitemap['path'] ) ) {
				continue; }
			$contents = array();
			foreach ( array_slice( (array) ( $sitemap['contents'] ?? array() ), 0, 20 ) as $content ) {
				if ( is_array( $content ) ) {
					$contents[] = array(
						'type'      => sanitize_text_field( (string) ( $content['type'] ?? '' ) ),
						'submitted' => max( 0, (int) ( $content['submitted'] ?? 0 ) ),
						'indexed'   => max( 0, (int) ( $content['indexed'] ?? 0 ) ),
					); }
			}
			$rows[] = array(
				'path'            => esc_url_raw( (string) $sitemap['path'] ),
				'last_submitted'  => sanitize_text_field( (string) ( $sitemap['lastSubmitted'] ?? '' ) ),
				'last_downloaded' => sanitize_text_field( (string) ( $sitemap['lastDownloaded'] ?? '' ) ),
				'is_pending'      => ! empty( $sitemap['isPending'] ),
				'errors'          => max( 0, (int) ( $sitemap['errors'] ?? 0 ) ),
				'warnings'        => max( 0, (int) ( $sitemap['warnings'] ?? 0 ) ),
				'contents'        => $contents,
			);
		}
		update_option(
			self::SITEMAPS,
			array(
				'property'   => $property,
				'fetched_at' => gmdate( 'c' ),
				'rows'       => $rows,
			),
			false
		);
		$this->redirect( 'success', __( 'Read-only Search Console sitemap status refreshed.', 'publishrank' ) );
	}

	public function inspect_url() {
		$this->guard( 'publishrank_gsc_inspect' );
		$property = self::property();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$url = isset( $_POST['inspect_url'] ) ? esc_url_raw( wp_unslash( $_POST['inspect_url'] ) ) : '';
		if ( ! $this->property_allowed( $property ) || ! $this->url_matches_property( $url, $property ) ) {
			$this->redirect( 'error', __( 'Enter an HTTP(S) URL within the selected Search Console property.', 'publishrank' ) ); }
		$result = $this->request(
			'POST',
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			array(
				'inspectionUrl' => $url,
				'siteUrl'       => $property,
				'languageCode'  => str_replace( '_', '-', determine_locale() ),
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', __( 'URL Inspection failed. Existing analytics data is unchanged.', 'publishrank' ) ); }
		$inspection = isset( $result['inspectionResult'] ) && is_array( $result['inspectionResult'] ) ? $result['inspectionResult'] : array();
		$index      = isset( $inspection['indexStatusResult'] ) && is_array( $inspection['indexStatusResult'] ) ? $inspection['indexStatusResult'] : array();
		$summary    = array();
		foreach ( array( 'verdict', 'coverageState', 'indexingState', 'pageFetchState', 'robotsTxtState', 'lastCrawlTime', 'googleCanonical', 'userCanonical' ) as $field ) {
			if ( isset( $index[ $field ] ) && is_scalar( $index[ $field ] ) ) {
				$summary[ $field ] = sanitize_text_field( (string) $index[ $field ] ); }
		}
		set_transient(
			'publishrank_gsc_inspection_' . get_current_user_id(),
			array(
				'url'    => $url,
				'result' => $summary,
				'time'   => time(),
			),
			10 * MINUTE_IN_SECONDS
		);
		$this->redirect( 'success', __( 'Read-only URL Inspection completed. This reports Google’s known index version, not a live crawl or an indexing request.', 'publishrank' ) );
	}

	public function disconnect() {
		$this->guard( 'publishrank_gsc_disconnect' );
		if ( self::uses_broker() ) {
			GscBroker::disconnect( self::connection_id(), self::decrypt( get_option( self::CONN_SECRET, '' ) ) ); }
		foreach ( array( self::CLIENT_ID, self::CLIENT_SECRET, self::REFRESH_TOKEN, self::ACCESS_CACHE, self::PROPERTY, self::SITES_CACHE, self::METRICS, self::SITEMAPS, self::CONN_ID, self::CONN_SECRET, self::EMAIL, self::REAUTH ) as $option ) {
			delete_option( $option ); }
		LiveSync::unschedule();
		delete_transient( 'publishrank_gsc_inspection_' . get_current_user_id() );
		$this->redirect( 'success', __( 'Search Console was disconnected: access was revoked at Google and local cached data was deleted.', 'publishrank' ) );
	}

	/** Read-only property list; cached only after an explicit OAuth connection. */
	public function request( $method, $path, $body = null ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token; }
		$url  = 0 === strpos( (string) $path, 'https://' ) ? (string) $path : self::API . $path;
		$args = array(
			'method'      => strtoupper( (string) $method ),
			'timeout'     => 20,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body ); }
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'publishrank_gsc_network', __( 'Could not reach Google Search Console. Check outbound HTTPS access.', 'publishrank' ) ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code ) {
			$token = $this->access_token( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			} $args['headers']['Authorization'] = 'Bearer ' . $token;
			$response                           = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'publishrank_gsc_network', __( 'Google Search Console retry failed.', 'publishrank' ) );
			} $code = (int) wp_remote_retrieve_response_code( $response ); }
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
			return new \WP_Error( 'publishrank_gsc_api', __( 'Google Search Console returned an error. Check permissions, property access, API enablement and quotas.', 'publishrank' ) ); }
		return $decoded;
	}

	private function access_token( $force = false ) {
		if ( ! $force ) {
			$cached = self::decrypt( get_option( self::ACCESS_CACHE, '' ) );
			$bundle = '' !== $cached ? json_decode( $cached, true ) : false;
			if ( is_array( $bundle ) && ! empty( $bundle['access_token'] ) && (int) ( $bundle['expires_at'] ?? 0 ) > time() + 90 ) {
				return (string) $bundle['access_token']; }
		}
		if ( self::uses_broker() ) {
			$res = GscBroker::token( self::connection_id(), self::decrypt( get_option( self::CONN_SECRET, '' ) ) );
			if ( is_wp_error( $res ) || empty( $res['access_token'] ) ) {
				if ( is_wp_error( $res ) && in_array( $res->get_error_code(), array( 'reauth', 'invalid_connection' ), true ) ) {
					update_option( self::REAUTH, 1, false );
					return new \WP_Error( 'publishrank_gsc_reauth', __( 'Google authorization expired or was revoked. Sign in with Google again.', 'publishrank' ) ); }
				return new \WP_Error( 'publishrank_gsc_network', __( 'Could not reach the PublishRank sign-in service.', 'publishrank' ) );
			}
			delete_option( self::REAUTH );
			$this->cache_access_token( $res );
			return (string) $res['access_token'];
		}
		$refresh = self::decrypt( get_option( self::REFRESH_TOKEN, '' ) );
		$secret  = self::decrypt( get_option( self::CLIENT_SECRET, '' ) );
		if ( '' === $refresh || '' === $secret || '' === self::client_id() ) {
			return new \WP_Error( 'publishrank_gsc_not_connected', __( 'Connect a Google account to Search Console first.', 'publishrank' ) ); }
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => array(
					'client_id'     => self::client_id(),
					'client_secret' => $secret,
					'refresh_token' => $refresh,
					'grant_type'    => 'refresh_token',
				),
			)
		);
		$tokens   = $this->decode_response( $response );
		if ( is_wp_error( $tokens ) || empty( $tokens['access_token'] ) ) {
			return new \WP_Error( 'publishrank_gsc_reauth', __( 'Google authorization expired or was revoked. Reconnect the account.', 'publishrank' ) ); }
		$this->cache_access_token( $tokens );
		return (string) $tokens['access_token'];
	}

	private function cache_access_token( $tokens ) {
		if ( empty( $tokens['access_token'] ) ) {
			return; }
		$seconds = max( 60, min( 3600, (int) ( $tokens['expires_in'] ?? 3600 ) ) );
		$encoded = wp_json_encode(
			array(
				'access_token' => (string) $tokens['access_token'],
				'expires_at'   => time() + $seconds,
			)
		);
		$this->store_secret( self::ACCESS_CACHE, $encoded );
	}

	private function decode_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'publishrank_gsc_token_network', __( 'Could not reach Google OAuth. Check outbound HTTPS access.', 'publishrank' ) ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new \WP_Error( 'publishrank_gsc_token', __( 'Google OAuth token exchange failed. Provider response details were not retained.', 'publishrank' ) ); }
		return $body;
	}

	private function property_allowed( $property ) {
		if ( '' === (string) $property ) {
			return false; }
		foreach ( self::cached_sites() as $site ) {
			if ( is_array( $site ) && ! empty( $site['siteUrl'] ) && hash_equals( (string) $site['siteUrl'], (string) $property ) && in_array( (string) ( $site['permissionLevel'] ?? '' ), array( 'siteOwner', 'siteFullUser', 'siteRestrictedUser' ), true ) ) {
				return true; }
		}
		return false;
	}

	private function url_matches_property( $url, $property ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false; }
		$host = strtolower( (string) $parts['host'] );
		if ( 0 === strpos( (string) $property, 'sc-domain:' ) ) {
			$domain = strtolower( substr( (string) $property, 10 ) );
			return $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain; }
		$prefix = wp_parse_url( (string) $property );
		if ( ! is_array( $prefix ) || empty( $prefix['host'] ) || strtolower( (string) $prefix['host'] ) !== $host ) {
			return false; }
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$base = isset( $prefix['path'] ) ? rtrim( (string) $prefix['path'], '/' ) : '';
		return '' === $base || $path === $base || 0 === strpos( $path, $base . '/' );
	}

	private function store_secret( $option, $plain ) {
		$cipher = self::encrypt( (string) $plain );
		if ( '' === $cipher ) {
			return false; }
		return update_option( $option, $cipher, false );
	}

	/** AES-256-GCM key derives from WordPress auth salt; no secret is stored in plaintext. */
	private static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) || ! function_exists( 'wp_salt' ) || '' === (string) wp_salt( 'auth' ) ) {
			return ''; }
		try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $e ) {
			return ''; }
		$key    = hash_hmac( 'sha256', 'publishrank-google-search-console-v1', wp_salt( 'auth' ), true );
		$tag    = '';
		$cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'publishrank-gsc-v1', 16 );
		return false === $cipher ? '' : base64_encode( $iv . $tag . $cipher );
	}

	private static function decrypt( $encoded ) {
		if ( ! is_string( $encoded ) || '' === $encoded || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'wp_salt' ) ) {
			return ''; }
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return ''; }
		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$key    = hash_hmac( 'sha256', 'publishrank-google-search-console-v1', wp_salt( 'auth' ), true );
		$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'publishrank-gsc-v1' );
		return false === $plain ? '' : $plain;
	}

	private static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); }
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Search Console.', 'publishrank' ), '', array( 'response' => 403 ) );
		} check_admin_referer( $action ); }
	private function redirect( $type, $message ) {
		set_transient(
			'publishrank_gsc_notice_' . get_current_user_id(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => (string) $message,
			),
			120
		);
		wp_safe_redirect( admin_url( 'admin.php?page=publishrank-search-console' ) );
		exit; }
}
