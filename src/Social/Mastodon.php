<?php
/**
 * Mastodon connection: profile verification (rel="me" + fediverse:creator) and sharing
 * published posts to a Mastodon account through the official Mastodon REST API.
 *
 * The publisher creates an application on their own Mastodon server (Preferences → Development →
 * New application, scopes read:accounts and write:statuses) and pastes the access token here.
 * The token is encrypted with the site's auth salt and is never printed back.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Social;

defined( 'ABSPATH' ) || exit;

final class Mastodon {
	const SETTINGS_OPTION = 'publishrank_mastodon_settings';
	const TOKEN_OPTION    = 'publishrank_mastodon_token';
	const ACCOUNT_OPTION  = 'publishrank_mastodon_account';
	const HISTORY_OPTION  = 'publishrank_mastodon_history';
	const ENABLED_OPTION  = 'publishrank_mastodon_enabled';
	const META_SHARE      = '_publishrank_mastodon_share';
	const META_MESSAGE    = '_publishrank_mastodon_message';
	const META_STATUS     = '_publishrank_mastodon_status_url';
	const META_IMAGE      = '_publishrank_mastodon_image';
	const META_SPOILER    = '_publishrank_mastodon_spoiler';
	const CRON_HOOK       = 'publishrank_mastodon_share_post';
	const DEFAULT_TEMPLATE = "{title}\n\n{excerpt}\n\n{url}\n\n{hashtags}";

	public function register() {
		add_action( 'wp_head', array( $this, 'head_tags' ), 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 40, 3 );
		add_action( self::CRON_HOOK, array( $this, 'share_post' ), 10, 1 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 20, 2 );
		add_action( 'admin_post_publishrank_mastodon_share_now', array( $this, 'share_now' ) );
	}

	/* ------------------------------------------------------------------ settings */

	public static function enabled() {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public static function defaults() {
		return array(
			'instance'     => '',
			'profile_url'  => '',
			'auto_share'   => 1,
			'post_types'   => array( 'post' ),
			'template'     => self::DEFAULT_TEMPLATE,
			'visibility'   => 'public',
			'hashtags'     => 1,
			'attach_image' => 0,
			'creator_tag'  => 1,
			'author_tags'  => 1,
		);
	}

	public static function settings() {
		$saved = get_option( self::SETTINGS_OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** Normalise a Mastodon server to https://host (no path). Returns '' when invalid. */
	public static function normalize_instance( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( false === strpos( $value, '://' ) ) {
			$value = 'https://' . ltrim( $value, '/' );
		}
		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( ! is_string( $host ) || ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host ) ) {
			return '';
		}
		return 'https://' . strtolower( $host );
	}

	/** Parse "@user@server" or a profile URL into a profile URL. */
	public static function normalize_profile( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^@?([a-z0-9_]+)@([a-z0-9.-]+\.[a-z]{2,})$/i', $value, $m ) ) {
			return 'https://' . strtolower( $m[2] ) . '/@' . $m[1];
		}
		$url = esc_url_raw( $value, array( 'https' ) );
		return $url ? $url : '';
	}

	/** "@user@server" handle for a profile URL, or '' . */
	public static function handle_from_url( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( $host && preg_match( '#^/@([a-z0-9_]+)#i', $path, $m ) ) {
			return '@' . $m[1] . '@' . strtolower( $host );
		}
		return '';
	}

	public static function token() {
		return self::decrypt( (string) get_option( self::TOKEN_OPTION, '' ) );
	}

	public static function save_token( $token ) {
		$enc = self::encrypt( (string) $token );
		if ( '' === $enc ) {
			return false;
		}
		update_option( self::TOKEN_OPTION, $enc, false );
		return true;
	}

	public static function connected() {
		$s = self::settings();
		return '' !== $s['instance'] && '' !== self::token();
	}

	public static function account() {
		$a = get_option( self::ACCOUNT_OPTION, array() );
		return is_array( $a ) ? $a : array();
	}

	public static function disconnect() {
		delete_option( self::TOKEN_OPTION );
		delete_option( self::ACCOUNT_OPTION );
	}

	/** Return the configured status limit for this instance, falling back to Mastodon's default. */
	public static function character_limit() {
		$s = self::settings();
		if ( '' === $s['instance'] ) {
			return 500;
		}
		$key    = 'publishrank_mastodon_limit_' . md5( $s['instance'] );
		$cached = get_transient( $key );
		if ( is_numeric( $cached ) && (int) $cached >= 100 && (int) $cached <= 10000 ) {
			return (int) $cached;
		}
		$response = wp_safe_remote_get(
			$s['instance'] . '/api/v2/instance',
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return 500;
		}
		$data  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$limit = (int) ( $data['configuration']['statuses']['max_characters'] ?? $data['max_toot_chars'] ?? 500 );
		if ( $limit < 100 || $limit > 10000 ) {
			$limit = 500;
		}
		set_transient( $key, $limit, 12 * HOUR_IN_SECONDS );
		return $limit;
	}

	/* ------------------------------------------------------------------ API */

	private static function request( $method, $path, $body = null ) {
		$s     = self::settings();
		$token = self::token();
		if ( '' === $s['instance'] || '' === $token ) {
			return new \WP_Error( 'publishrank_mastodon_not_connected', __( 'Mastodon is not connected.', 'publishrank' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'User-Agent'    => 'PublishRank/' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '1' ) . '; ' . home_url( '/' ),
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type']    = 'application/json';
			$args['headers']['Idempotency-Key'] = isset( $body['_key'] ) ? (string) $body['_key'] : wp_generate_uuid4();
			unset( $body['_key'] );
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_safe_remote_request( $s['instance'] . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : sprintf( 'HTTP %d', $code );
			/* translators: 1: HTTP status code, 2: error from the Mastodon server. */
			return new \WP_Error( 'publishrank_mastodon_http', sprintf( __( 'Mastodon returned %1$d: %2$s', 'publishrank' ), $code, $msg ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Check the token, store the account, and report whether the Mastodon profile shows this
	 * site as a verified link (green check). Mastodon marks a profile field verified once it
	 * finds a rel="me" link back to the profile on the linked page.
	 *
	 * @return array|\WP_Error
	 */
	public static function verify() {
		$data = self::request( 'GET', '/api/v1/accounts/verify_credentials' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$home      = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$verified  = false;
		$has_field = false;
		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			$value = wp_strip_all_tags( (string) ( $field['value'] ?? '' ) );
			if ( $home && false !== stripos( $value, (string) $home ) ) {
				$has_field = true;
				if ( ! empty( $field['verified_at'] ) ) {
					$verified = true;
				}
			}
		}
		$account = array(
			'id'          => (string) ( $data['id'] ?? '' ),
			'username'    => (string) ( $data['username'] ?? '' ),
			'acct'        => (string) ( $data['acct'] ?? '' ),
			'display'     => (string) ( $data['display_name'] ?? '' ),
			'url'         => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
			'avatar'      => esc_url_raw( (string) ( $data['avatar_static'] ?? '' ) ),
			'followers'   => (int) ( $data['followers_count'] ?? 0 ),
			'has_field'   => $has_field ? 1 : 0,
			'verified'    => $verified ? 1 : 0,
			'checked_at'  => time(),
		);
		update_option( self::ACCOUNT_OPTION, $account, false );
		if ( $account['url'] ) {
			$s = self::settings();
			if ( '' === $s['profile_url'] ) {
				$s['profile_url'] = $account['url'];
				update_option( self::SETTINGS_OPTION, $s, false );
			}
		}
		return $account;
	}

	/* ------------------------------------------------------------------ front end */

	/** rel="me" links make Mastodon show the green verified check; fediverse:creator credits the author on link previews. */
	public function head_tags() {
		if ( ! self::enabled() ) {
			return;
		}
		$s       = self::settings();
		$profile = '' !== $s['profile_url'] ? $s['profile_url'] : (string) ( self::account()['url'] ?? '' );
		if ( '' !== $profile ) {
			echo '<link rel="me" href="' . esc_url( $profile ) . '" />' . "\n";
		}
		if ( ! is_singular() || empty( $s['creator_tag'] ) ) {
			return;
		}
		$creator = '';
		if ( ! empty( $s['author_tags'] ) ) {
			$author_profile = self::normalize_profile( (string) get_user_meta( (int) get_post_field( 'post_author', get_queried_object_id() ), '_publishrank_mastodon_profile', true ) );
			$creator        = self::handle_from_url( $author_profile );
			if ( $creator && $author_profile !== $profile ) {
				echo '<link rel="me" href="' . esc_url( $author_profile ) . '" />' . "\n";
			}
		}
		if ( '' === $creator ) {
			$creator = self::handle_from_url( $profile );
		}
		if ( '' !== $creator ) {
			echo '<meta name="fediverse:creator" content="' . esc_attr( $creator ) . '" />' . "\n";
		}
	}

	/* ------------------------------------------------------------------ sharing */

	public function on_transition( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! self::should_share( $post ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( (int) $post->ID ) ) ) {
			wp_schedule_single_event( time() + 15, self::CRON_HOOK, array( (int) $post->ID ) );
		}
	}

	public static function should_share( \WP_Post $post ) {
		if ( ! self::enabled() || ! self::connected() || ! empty( $post->post_password ) ) {
			return false;
		}
		$s = self::settings();
		if ( ! in_array( $post->post_type, (array) $s['post_types'], true ) ) {
			return false;
		}
		if ( '' !== (string) get_post_meta( $post->ID, self::META_STATUS, true ) ) {
			return false; // Already shared once.
		}
		$choice = (string) get_post_meta( $post->ID, self::META_SHARE, true );
		if ( '' === $choice ) {
			return ! empty( $s['auto_share'] );
		}
		return 'yes' === $choice;
	}

	/** Build the status text from the template. Mastodon counts any link as 23 characters. */
	public static function compose( \WP_Post $post, $template = '' ) {
		$s        = self::settings();
		$custom   = trim( (string) get_post_meta( $post->ID, self::META_MESSAGE, true ) );
		$template = '' !== $custom ? $custom : ( '' !== $template ? $template : (string) $s['template'] );
		$excerpt  = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 35, '…' );
		$tags     = array();
		if ( ! empty( $s['hashtags'] ) ) {
			foreach ( (array) wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ) as $name ) {
				$tag = preg_replace( '/[^\p{L}\p{N}_]+/u', '', ucwords( (string) $name ) );
				if ( '' !== $tag ) {
					$tags[] = '#' . $tag;
				}
				if ( count( $tags ) >= 5 ) {
					break;
				}
			}
		}
		$text = strtr(
			$template,
			array(
				'{title}'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'{excerpt}'  => html_entity_decode( (string) $excerpt, ENT_QUOTES, 'UTF-8' ),
				'{url}'      => get_permalink( $post ),
				'{hashtags}' => implode( ' ', $tags ),
				'{site}'     => get_bloginfo( 'name' ),
			)
		);
		$text = trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
		return self::fit( $text, self::character_limit() );
	}

	/** Shorten the excerpt part so the status fits Mastodon's limit (links count as 23). */
	private static function text_length( $text ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text );
		}
		$matched = preg_match_all( '/./us', $text, $characters );
		return false === $matched ? strlen( $text ) : $matched;
	}

	private static function text_substr( $text, $start, $length = null ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $start, $length );
		}
		if ( false === preg_match_all( '/./us', $text, $characters ) ) {
			return substr( $text, $start, $length );
		}
		return implode( '', array_slice( $characters[0], $start, $length ) );
	}

	public static function fit( $text, $limit ) {
		$length = self::length( $text );
		if ( $length <= $limit ) {
			return $text;
		}
		$lines = explode( "\n", $text );
		$over  = $length - $limit + 1;
		foreach ( $lines as $i => $line ) {
			if ( preg_match( '#https?://#', $line ) || 0 === strpos( ltrim( $line ), '#' ) || $i === 0 ) {
				continue;
			}
			$len = self::text_length( $line );
			if ( $len > $over ) {
				$lines[ $i ] = rtrim( self::text_substr( $line, 0, $len - $over ) ) . '…';
				return implode( "\n", $lines );
			}
			$lines[ $i ] = '';
			$over       -= $len;
		}
		$text = implode( "\n", $lines );
		return self::text_substr( $text, 0, $limit );
	}

	public static function length( $text ) {
		$text = preg_replace( '#https?://\S+#', str_repeat( 'x', 23 ), (string) $text );
		return self::text_length( $text );
	}

	/** Upload the post thumbnail with the Media Library alt text as its accessible description. */
	private static function upload_featured_image( \WP_Post $post ) {
		$attachment_id = (int) get_post_thumbnail_id( $post->ID );
		if ( ! $attachment_id ) {
			return '';
		}
		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return new \WP_Error( 'publishrank_mastodon_image_file', __( 'The featured image file is not available on this server.', 'publishrank' ) );
		}
		$size = filesize( $file );
		if ( false === $size || $size > 32 * 1024 * 1024 ) {
			return new \WP_Error( 'publishrank_mastodon_image_size', __( 'The featured image must be smaller than 32 MB to attach it to a Mastodon post.', 'publishrank' ) );
		}
		$type     = wp_check_filetype( $file );
		$mime     = (string) ( $type['type'] ?? '' );
		$allowed  = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || ! in_array( $mime, $allowed, true ) ) {
			return new \WP_Error( 'publishrank_mastodon_image_type', __( 'The featured image must be a readable JPEG, PNG, GIF, or WebP file.', 'publishrank' ) );
		}
		$alt      = sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$filename = sanitize_file_name( basename( $file ) );
		$boundary = '----PublishRank' . str_replace( '-', '', wp_generate_uuid4() );
		$body     = self::multipart_body( $contents, $filename, $mime, $alt, $boundary );
		$response = wp_safe_remote_request(
			self::settings()['instance'] . '/api/v2/media',
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::token(),
					'Accept'        => 'application/json',
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);
		// Mastodon 3.1.3+ supports the asynchronous v2 endpoint. Fall back for older compatible servers.
		if ( ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), array( 404, 405 ), true ) ) {
			$response = wp_safe_remote_request(
				self::settings()['instance'] . '/api/v1/media',
				array(
					'method'  => 'POST',
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . self::token(),
						'Accept'        => 'application/json',
						'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					),
					'body'    => $body,
				)
			);
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['id'] ) ) {
			$error = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : sprintf( 'HTTP %d', $code );
			return new \WP_Error( 'publishrank_mastodon_media', sprintf( __( 'Mastodon could not upload the featured image: %s', 'publishrank' ), $error ) );
		}
		$media_id = (string) $data['id'];
		if ( empty( $data['url'] ) ) {
			for ( $attempt = 0; $attempt < 5; $attempt++ ) {
				usleep( 400000 );
				$ready = self::request( 'GET', '/api/v1/media/' . rawurlencode( $media_id ) );
				if ( is_wp_error( $ready ) ) {
					return $ready;
				}
				if ( ! empty( $ready['url'] ) ) {
					break;
				}
			}
			if ( empty( $ready['url'] ) ) {
				return new \WP_Error( 'publishrank_mastodon_media_processing', __( 'Mastodon is still processing the featured image. Try sharing again shortly.', 'publishrank' ) );
			}
		}
		return $media_id;
	}

	/** Build a multipart request body for Mastodon's media endpoint. */
	private static function multipart_body( $contents, $filename, $mime, $description, $boundary ) {
		$filename    = str_replace( array( '"', "\r", "\n" ), '', sanitize_file_name( (string) $filename ) );
		$description = str_replace( array( "\r", "\n" ), ' ', sanitize_text_field( (string) $description ) );
		return '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\n"
			. 'Content-Type: ' . $mime . "\r\n\r\n" . $contents . "\r\n"
			. '--' . $boundary . "\r\n"
			. "Content-Disposition: form-data; name=\"description\"\r\n\r\n" . $description . "\r\n"
			. '--' . $boundary . "--\r\n";
	}

	/** @return array|\WP_Error */
	public function share_post( $post_id, $force = false ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'publishrank_mastodon_post', __( 'Only published posts can be shared.', 'publishrank' ) );
		}
		if ( ! $force && ! self::should_share( $post ) ) {
			return new \WP_Error( 'publishrank_mastodon_skip', __( 'Sharing is turned off for this post.', 'publishrank' ) );
		}
		$s         = self::settings();
		$image     = (string) get_post_meta( $post->ID, self::META_IMAGE, true );
		$use_image = '' === $image ? ! empty( $s['attach_image'] ) : 'yes' === $image;
		$media_id  = '';
		if ( $use_image && has_post_thumbnail( $post ) ) {
			$uploaded = self::upload_featured_image( $post );
			if ( is_wp_error( $uploaded ) ) {
				self::log( $post, $uploaded );
				return $uploaded;
			}
			$media_id = (string) $uploaded;
		}
		$spoiler = sanitize_text_field( (string) get_post_meta( $post->ID, self::META_SPOILER, true ) );
		$body    = array(
			'status'       => self::compose( $post ),
			'visibility'   => in_array( $s['visibility'], array( 'public', 'unlisted', 'private' ), true ) ? $s['visibility'] : 'public',
			'language'     => substr( (string) get_locale(), 0, 2 ),
			'spoiler_text' => $spoiler,
			'sensitive'    => '' !== $spoiler,
			'_key'         => 'publishrank-' . $post->ID . '-' . md5( (string) $post->post_date_gmt ),
		);
		if ( '' !== $media_id ) {
			$body['media_ids'] = array( $media_id );
		}
		$result = self::request(
			'POST',
			'/api/v1/statuses',
			$body
		);
		self::log( $post, $result );
		if ( ! is_wp_error( $result ) && ! empty( $result['url'] ) ) {
			update_post_meta( $post->ID, self::META_STATUS, esc_url_raw( (string) $result['url'] ) );
		}
		return $result;
	}

	private static function log( \WP_Post $post, $result ) {
		$history = get_option( self::HISTORY_OPTION, array() );
		$history = is_array( $history ) ? $history : array();
		array_unshift(
			$history,
			array(
				'time'    => time(),
				'post_id' => (int) $post->ID,
				'title'   => get_the_title( $post ),
				'ok'      => is_wp_error( $result ) ? 0 : 1,
				'url'     => is_wp_error( $result ) ? '' : esc_url_raw( (string) ( $result['url'] ?? '' ) ),
				'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
			)
		);
		update_option( self::HISTORY_OPTION, array_slice( $history, 0, 30 ), false );
	}

	/* ------------------------------------------------------------------ editor box */

	public function add_meta_box() {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( (array) self::settings()['post_types'] as $type ) {
			add_meta_box( 'publishrank-mastodon', __( 'Share to Mastodon', 'publishrank' ), array( $this, 'render_meta_box' ), $type, 'side', 'default' );
		}
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'publishrank_mastodon_meta', 'publishrank_mastodon_nonce' );
		if ( ! self::connected() ) {
			echo '<p>' . esc_html__( 'Connect your Mastodon account first under PublishRank → Mastodon.', 'publishrank' ) . '</p>';
			return;
		}
		$status = (string) get_post_meta( $post->ID, self::META_STATUS, true );
		if ( '' !== $status ) {
			echo '<p>✅ ' . esc_html__( 'Shared on Mastodon.', 'publishrank' ) . ' <a href="' . esc_url( $status ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View post', 'publishrank' ) . '</a></p>';
		}
		$choice  = (string) get_post_meta( $post->ID, self::META_SHARE, true );
		$default = ! empty( self::settings()['auto_share'] );
		$checked = '' === $choice ? $default : 'yes' === $choice;
		echo '<p><label><input type="checkbox" name="publishrank_mastodon_share" value="yes" ' . checked( $checked, true, false ) . ' /> ' . esc_html__( 'Share when published', 'publishrank' ) . '</label></p>';
		$image_choice = (string) get_post_meta( $post->ID, self::META_IMAGE, true );
		$use_image    = '' === $image_choice ? ! empty( self::settings()['attach_image'] ) : 'yes' === $image_choice;
		echo '<p><label><input type="checkbox" name="publishrank_mastodon_image" value="yes" ' . checked( $use_image, true, false ) . ' /> ' . esc_html__( 'Attach featured image', 'publishrank' ) . '</label><br /><span class="description">' . esc_html__( 'Uses the image alt text from the Media Library as Mastodon’s accessible description.', 'publishrank' ) . '</span></p>';
		if ( $use_image && has_post_thumbnail( $post ) && '' === trim( (string) get_post_meta( (int) get_post_thumbnail_id( $post->ID ), '_wp_attachment_image_alt', true ) ) ) {
			echo '<p class="description">' . esc_html__( 'This featured image has no alt text yet. Add it in the Media Library so Mastodon receives an accessible image description.', 'publishrank' ) . '</p>';
		}
		echo '<p><label for="publishrank_mastodon_message">' . esc_html__( 'Custom message (optional)', 'publishrank' ) . '</label><textarea class="widefat" rows="4" id="publishrank_mastodon_message" name="publishrank_mastodon_message" placeholder="' . esc_attr( self::DEFAULT_TEMPLATE ) . '">' . esc_textarea( (string) get_post_meta( $post->ID, self::META_MESSAGE, true ) ) . '</textarea></p>';
		echo '<p><label for="publishrank_mastodon_spoiler">' . esc_html__( 'Content warning (optional)', 'publishrank' ) . '</label><input class="widefat" type="text" id="publishrank_mastodon_spoiler" name="publishrank_mastodon_spoiler" value="' . esc_attr( (string) get_post_meta( $post->ID, self::META_SPOILER, true ) ) . '" /><span class="description">' . esc_html__( 'Adds a Mastodon content warning and marks the post as sensitive.', 'publishrank' ) . '</span></p>';
		echo '<p class="description">' . esc_html__( 'Variables: {title} {excerpt} {url} {hashtags} {site}. Links count as 23; the status is fitted to your server’s character limit.', 'publishrank' ) . '</p>';
		if ( 'publish' === $post->post_status ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_mastodon_share_now&post_id=' . (int) $post->ID ), 'publishrank_mastodon_share_now_' . (int) $post->ID );
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( '' !== $status ? __( 'Share again now', 'publishrank' ) : __( 'Share now', 'publishrank' ) ) . '</a></p>';
		}
	}

	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['publishrank_mastodon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_mastodon_nonce'] ) ), 'publishrank_mastodon_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_SHARE, isset( $_POST['publishrank_mastodon_share'] ) ? 'yes' : 'no' );
		$message = isset( $_POST['publishrank_mastodon_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['publishrank_mastodon_message'] ) ) : '';
		if ( '' === $message ) {
			delete_post_meta( $post_id, self::META_MESSAGE );
		} else {
			update_post_meta( $post_id, self::META_MESSAGE, $message );
		}
		update_post_meta( $post_id, self::META_IMAGE, isset( $_POST['publishrank_mastodon_image'] ) ? 'yes' : 'no' );
		$spoiler = isset( $_POST['publishrank_mastodon_spoiler'] ) ? sanitize_text_field( wp_unslash( $_POST['publishrank_mastodon_spoiler'] ) ) : '';
		if ( '' === $spoiler ) {
			delete_post_meta( $post_id, self::META_SPOILER );
		} else {
			update_post_meta( $post_id, self::META_SPOILER, $spoiler );
		}
	}

	public function share_now() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'publishrank_mastodon_share_now_' . $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You cannot share this post.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$result = $this->share_post( $post_id, true );
		set_transient(
			'publishrank_mastodon_notice_' . get_current_user_id(),
			is_wp_error( $result ) ? array( 'type' => 'error', 'message' => $result->get_error_message() ) : array( 'type' => 'success', 'message' => __( 'Shared to Mastodon.', 'publishrank' ) ),
			60
		);
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/* ------------------------------------------------------------------ crypto */

	private static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) || '' === (string) wp_salt( 'auth' ) ) {
			return '';
		}
		try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $e ) {
			return '';
		}
		$key    = hash_hmac( 'sha256', 'publishrank-mastodon-token-v1', wp_salt( 'auth' ), true );
		$tag    = '';
		$cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'publishrank-mastodon-v1', 16 );
		return false === $cipher ? '' : base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt( $encoded ) {
		if ( '' === $encoded || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$key   = hash_hmac( 'sha256', 'publishrank-mastodon-token-v1', wp_salt( 'auth' ), true );
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'publishrank-mastodon-v1' );
		return false === $plain ? '' : $plain;
	}
}
