<?php
/** Optional live PublishRank news feed in the WordPress administration area. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;


defined( 'ABSPATH' ) || exit;

final class NewsPage {
	const SLUG           = 'publishrank-news';
	const FEED_URL       = 'https://publishrank.netlify.app/announcements.json';
	const ENABLED_OPTION = 'publishrank_news_feed_enabled';
	const CACHE_KEY      = 'publishrank_news_feed_cache_v1';
	const STALE_KEY      = 'publishrank_news_feed_stale_v1';
	const NONCE_ACTION   = 'publishrank_news_feed_action';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_publishrank_news_feed', array( $this, 'manage_feed' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	public function menu() {
		add_submenu_page(
			Branding::MENU_SLUG,
			__( 'News', 'publishrank' ),
			__( 'News', 'publishrank' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'publishrank-news', Branding::asset_url( 'css/admin-news.css' ), array(), Branding::version() );
	}

	/** Process only the explicit enable, refresh, and disable actions. */
	public function manage_feed() {
		AdminAssets::guard( self::NONCE_ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AdminAssets::guard() validates the matching action nonce above.
		$action = isset( $_POST['feed_action'] ) ? sanitize_key( wp_unslash( $_POST['feed_action'] ) ) : '';

		if ( 'enable' === $action ) {
			update_option( self::ENABLED_OPTION, true );
			AdminAssets::done( self::SLUG, 'success', __( 'Live PublishRank News is enabled. The feed will load on this screen.', 'publishrank' ) );
		}

		if ( 'disable' === $action ) {
			update_option( self::ENABLED_OPTION, false );
			delete_transient( self::CACHE_KEY );
			delete_transient( self::STALE_KEY );
			AdminAssets::done( self::SLUG, 'success', __( 'Live PublishRank News is disabled and its cached feed has been cleared.', 'publishrank' ) );
		}

		if ( 'refresh' === $action ) {
			if ( ! get_option( self::ENABLED_OPTION, false ) ) {
				AdminAssets::done( self::SLUG, 'error', __( 'Enable the live feed before refreshing it.', 'publishrank' ) );
			}
			delete_transient( self::CACHE_KEY );
			$feed = $this->get_feed( true );
			if ( is_wp_error( $feed ) || ! empty( $feed['stale'] ) ) {
				AdminAssets::done( self::SLUG, 'error', __( 'The live feed could not be refreshed. The last saved copy is shown when available.', 'publishrank' ) );
			}
			AdminAssets::done( self::SLUG, 'success', __( 'PublishRank News was refreshed.', 'publishrank' ) );
		}

		AdminAssets::done( self::SLUG, 'error', __( 'Choose a valid News feed action.', 'publishrank' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		AdminAssets::header( __( 'PublishRank News', 'publishrank' ), __( 'Product news and publishing guidance, from the live PublishRank feed.', 'publishrank' ) );
		AdminAssets::render_flash();
		echo '<main class="pr-news" aria-labelledby="pr-news-title">';

		if ( ! get_option( self::ENABLED_OPTION, false ) ) {
			$this->render_consent();
			echo '</main></div>';
			return;
		}

		$feed_state = $this->get_feed();
		if ( is_wp_error( $feed_state ) ) {
			$this->render_error();
			echo '</main></div>';
			return;
		}

		$payload = isset( $feed_state['payload'] ) && is_array( $feed_state['payload'] ) ? $feed_state['payload'] : array();
		$entries = self::filter_announcements( isset( $payload['announcements'] ) ? $payload['announcements'] : array(), time(), Branding::version() );
		$this->render_hero( $payload );

		if ( ! empty( $feed_state['stale'] ) ) {
			echo '<div class="pr-news__notice" role="status">' . esc_html__( 'The live service could not be reached. Showing the last saved copy; use Refresh to try again.', 'publishrank' ) . '</div>';
		}

		$this->render_toolbar( $feed_state, count( $entries ) );
		echo '<p class="pr-news__status">' . esc_html__( 'The feed is read-only. It does not publish content, change settings, or display pop-ups.', 'publishrank' ) . '</p>';

		if ( empty( $entries ) ) {
			echo '<section class="pr-news__empty"><h2>' . esc_html__( 'No current updates', 'publishrank' ) . '</h2><p>' . esc_html__( 'There are no active feed items for this plugin version right now. You can refresh later.', 'publishrank' ) . '</p></section>';
		} else {
			$this->render_entries( $entries );
		}

		$this->render_privacy_note();
		echo '</main></div>';
	}

	/** Register a suggested privacy-policy statement following WordPress guidance. */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			/* translators: 1: live PublishRank announcements feed URL, 2: Netlify privacy policy URL. */
			__( '<p class="privacy-policy-tutorial">PublishRank News is optional and makes no request until an administrator enables it under PublishRank → News.</p><p>When enabled, the site server requests the read-only JSON feed at <a href="%1$s">%1$s</a> when an administrator opens the News screen and no cached copy is available. Automatic visits reuse the local cache, normally for one hour and never more than 24 hours; an administrator pressing Refresh makes an immediate new request. PublishRank sets a generic User-Agent and sends no post content, visitor data, or WordPress account details with the feed request. The feed provider can receive the server IP address and ordinary HTTP request metadata. If a feed item includes an image, the administrator’s browser loads that image from the same provider, which can receive the browser IP address and ordinary browser metadata such as the origin/referrer. No external JavaScript is loaded. The stale fallback copy expires within 24 hours. Disabling the feed clears its local cache. See the provider’s <a href="%2$s">privacy statement</a>.</p>', 'publishrank' ),
			self::FEED_URL,
			'https://www.netlify.com/privacy/'
		);
		wp_add_privacy_policy_content( 'PublishRank SEO', wp_kses_post( wpautop( $content, false ) ) );
	}

	/** Return current, non-example items sorted newest first. */
	public static function filter_announcements( $announcements, $now = null, $plugin_version = '' ) {
		if ( ! is_array( $announcements ) ) {
			return array();
		}
		$now            = null === $now ? time() : (int) $now;
		$plugin_version = '' === $plugin_version ? Branding::version() : (string) $plugin_version;
		$visible        = array();

		foreach ( $announcements as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['active'] ) || true !== $item['active'] || empty( $item['title'] ) || empty( $item['id'] ) ) {
				continue;
			}
			$labels = isset( $item['labels'] ) && is_array( $item['labels'] ) ? array_map( 'sanitize_key', $item['labels'] ) : array();
			$title  = sanitize_text_field( $item['title'] );
			if ( in_array( 'example', $labels, true ) || 0 === stripos( $title, 'example:' ) ) {
				continue;
			}
			$published = isset( $item['published_at'] ) ? strtotime( (string) $item['published_at'] ) : false;
			if ( false === $published || $published > $now ) {
				continue;
			}
			if ( ! empty( $item['expires_at'] ) ) {
				$expires = strtotime( (string) $item['expires_at'] );
				if ( false !== $expires && $expires < $now ) {
					continue;
				}
			}
			$targeting = isset( $item['targeting'] ) && is_array( $item['targeting'] ) ? $item['targeting'] : array();
			$minimum   = isset( $targeting['minimum_plugin_version'] ) ? (string) $targeting['minimum_plugin_version'] : '';
			$maximum   = isset( $targeting['maximum_plugin_version'] ) ? (string) $targeting['maximum_plugin_version'] : '';
			if ( '' !== $minimum && version_compare( $plugin_version, $minimum, '<' ) ) {
				continue;
			}
			if ( '' !== $maximum && version_compare( $plugin_version, $maximum, '>' ) ) {
				continue;
			}
			$item['_published_timestamp'] = $published;
			$visible[]                    = $item;
		}

		usort(
			$visible,
			static function ( $first, $second ) {
				return (int) $second['_published_timestamp'] <=> (int) $first['_published_timestamp'];
			}
		);
		return $visible;
	}

	/** Accept only HTTPS links on the documented PublishRank feed host. */
	public static function trusted_url( $value ) {
		$url    = esc_url_raw( (string) $value, array( 'https' ) );
		$parsed = '' !== $url ? wp_parse_url( $url ) : false;
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || 'https' !== strtolower( (string) $parsed['scheme'] ) || 'publishrank.netlify.app' !== strtolower( (string) $parsed['host'] ) || ( isset( $parsed['port'] ) && 443 !== (int) $parsed['port'] ) ) {
			return '';
		}
		return $url;
	}

	private function render_consent() {
		echo '<section class="pr-news__hero"><p class="pr-news__kicker">' . esc_html__( 'Publisher updates', 'publishrank' ) . '</p><h2 class="pr-news__title" id="pr-news-title">' . esc_html__( 'News for publishers', 'publishrank' ) . '</h2><p class="pr-news__lead">' . esc_html__( 'Enable the optional live feed to see PublishRank product updates and practical publishing guidance here.', 'publishrank' ) . '</p></section>';
		echo '<section class="pr-news__consent" aria-labelledby="pr-news-consent-title"><h2 id="pr-news-consent-title">' . esc_html__( 'You choose whether to connect', 'publishrank' ) . '</h2><p>' . esc_html__( 'No connection is made until you enable the feed. After you opt in, this site’s server requests a small read-only JSON document from publishrank.netlify.app when an administrator opens this screen and the cache has expired. The request uses a generic PublishRank User-Agent; the provider receives the server IP address and ordinary request metadata. No WordPress content, visitor data, or user account details are sent. An administrator can also trigger an immediate request with Refresh.', 'publishrank' ) . '</p><p>' . esc_html__( 'Some announcements include images. If shown, your browser loads them from publishrank.netlify.app, which may receive the browser IP address and normal browser metadata, including the origin/referrer. The feed is displayed only on this page: the plugin ignores popup instructions in the feed and does not load remote scripts.', 'publishrank' ) . '</p>';
		$this->render_action_form( 'enable', __( 'Enable live News', 'publishrank' ), 'button button-primary pr-news__button' );
		echo '<details class="pr-news__details"><summary>' . esc_html__( 'Privacy and service details', 'publishrank' ) . '</summary><p>' . esc_html__( 'The feed is read-only, cached locally for no more than 24 hours, and may be disabled at any time. Disabling it clears the local feed cache. Read the ', 'publishrank' ) . '<a href="https://www.netlify.com/privacy/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Netlify Privacy Statement', 'publishrank' ) . '</a> ' . esc_html__( 'and', 'publishrank' ) . ' <a href="https://www.netlify.com/legal/terms-of-use/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Netlify Terms of Use', 'publishrank' ) . '</a>.</p></details></section>';
	}

	private function render_error() {
		echo '<section class="pr-news__hero"><p class="pr-news__kicker">' . esc_html__( 'Publisher updates', 'publishrank' ) . '</p><h2 class="pr-news__title" id="pr-news-title">' . esc_html__( 'News for publishers', 'publishrank' ) . '</h2><p class="pr-news__lead">' . esc_html__( 'The live feed is temporarily unavailable.', 'publishrank' ) . '</p></section>';
		echo '<section class="pr-news__empty"><h2>' . esc_html__( 'We could not load the News feed', 'publishrank' ) . '</h2><p>' . esc_html__( 'Check that this WordPress server can make outbound HTTPS requests, then try again. No site content or visitor data is sent with the request.', 'publishrank' ) . '</p>';
		$this->render_action_form( 'refresh', __( 'Try again', 'publishrank' ), 'button button-primary pr-news__button' );
		echo '</section>';
	}

	private function render_hero( $payload ) {
		$publisher = isset( $payload['publisher']['name'] ) ? sanitize_text_field( $payload['publisher']['name'] ) : __( 'PublishRank', 'publishrank' );
		echo '<section class="pr-news__hero"><p class="pr-news__kicker">' . esc_html__( 'Publisher updates', 'publishrank' ) . '</p><h2 class="pr-news__title" id="pr-news-title">' . esc_html__( 'News for publishers', 'publishrank' ) . '</h2><p class="pr-news__lead">' . esc_html__( 'Product updates and practical guidance from', 'publishrank' ) . ' ' . esc_html( $publisher ) . '.</p></section>';
	}

	private function render_toolbar( $feed_state, $count ) {
		$cached_at = isset( $feed_state['cached_at'] ) ? (int) $feed_state['cached_at'] : 0;
		$updated   = $cached_at ? wp_date( get_option( 'date_format', 'M j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $cached_at ) : __( 'unknown', 'publishrank' );
		echo '<div class="pr-news__toolbar"><span class="pr-news__feed-meta">' . esc_html( sprintf( /* translators: 1: number of announcements; 2: local date and time when the feed was cached. */ __( '%1$d updates · refreshed %2$s', 'publishrank' ), (int) $count, $updated ) ) . '</span><div class="pr-news__form">';
		$this->render_action_form( 'refresh', __( 'Refresh feed', 'publishrank' ), 'button button-secondary pr-news__button' );
		$this->render_action_form( 'disable', __( 'Disable feed', 'publishrank' ), 'button button-link-delete pr-news__button' );
		echo '</div></div>';
	}

	private function render_entries( $entries ) {
		$featured_used = false;
		echo '<div class="pr-news__grid">';
		foreach ( $entries as $item ) {
			$title         = sanitize_text_field( $item['title'] );
			$summary       = sanitize_text_field( isset( $item['summary'] ) ? $item['summary'] : ( isset( $item['message'] ) ? $item['message'] : '' ) );
			$published     = isset( $item['_published_timestamp'] ) ? (int) $item['_published_timestamp'] : strtotime( (string) $item['published_at'] );
			$type          = isset( $item['category'] ) ? sanitize_text_field( $item['category'] ) : ( isset( $item['type'] ) ? sanitize_text_field( ucfirst( (string) $item['type'] ) ) : __( 'Update', 'publishrank' ) );
			$image         = isset( $item['image'] ) ? self::trusted_url( $item['image'] ) : '';
			$image_alt     = isset( $item['image_alt'] ) ? sanitize_text_field( $item['image_alt'] ) : '';
			$is_featured   = ! $featured_used && ! empty( $item['featured'] );
			$featured_used = $featured_used || $is_featured;
			$card_class    = 'pr-news__card' . ( $is_featured ? ' pr-news__card--featured' : '' );
			echo '<article class="' . esc_attr( $card_class ) . '">';
			if ( '' !== $image ) {
				echo '<img class="pr-news__image" src="' . esc_url( $image ) . '" alt="' . esc_attr( $image_alt ) . '" loading="lazy" decoding="async" />';
			}
			echo '<div class="pr-news__content"><span class="pr-news__type">' . esc_html( $type ) . '</span><p class="pr-news__date"><time datetime="' . esc_attr( gmdate( 'c', $published ) ) . '">' . esc_html( wp_date( get_option( 'date_format', 'M j, Y' ), $published ) ) . '</time></p><h3 class="pr-news__heading">' . esc_html( $title ) . '</h3>';
			if ( '' !== $summary ) {
				echo '<p class="pr-news__summary">' . esc_html( $summary ) . '</p>';
			}
			$this->render_body( $item );
			$this->render_actions( $item );
			echo '</div></article>';
		}
		echo '</div>';
	}

	private function render_body( $item ) {
		$body = isset( $item['body'] ) && is_array( $item['body'] ) ? array_slice( $item['body'], 0, 8 ) : array();
		if ( empty( $body ) ) {
			return;
		}
		echo '<details class="pr-news__details"><summary>' . esc_html__( 'Read the full update', 'publishrank' ) . '</summary><div class="pr-news__body">';
		foreach ( $body as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$heading = isset( $section['heading'] ) ? sanitize_text_field( $section['heading'] ) : '';
			$text    = isset( $section['text'] ) ? sanitize_text_field( $section['text'] ) : '';
			if ( '' !== $heading ) {
				echo '<h4>' . esc_html( $heading ) . '</h4>';
			}
			if ( '' !== $text ) {
				echo '<p>' . esc_html( $text ) . '</p>';
			}
		}
		echo '</div></details>';
	}

	private function render_actions( $item ) {
		$actions = isset( $item['actions'] ) && is_array( $item['actions'] ) ? array_slice( $item['actions'], 0, 3 ) : array();
		$valid   = array();
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || empty( $action['url'] ) ) {
				continue;
			}
			$url = self::trusted_url( $action['url'] );
			if ( '' === $url ) {
				continue;
			}
			$valid[] = array(
				'label' => isset( $action['label'] ) ? sanitize_text_field( $action['label'] ) : __( 'Learn more', 'publishrank' ),
				'url'   => $url,
			);
		}
		if ( empty( $valid ) ) {
			return;
		}
		echo '<div class="pr-news__actions">';
		foreach ( $valid as $action ) {
			echo '<a class="button button-secondary pr-news__button" href="' . esc_url( $action['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $action['label'] ) . '</a>';
		}
		echo '</div>';
	}

	private function render_privacy_note() {
		echo '<p class="pr-news__status">' . esc_html__( 'Live feed is enabled. The feed request is made by your WordPress server; images shown above are requested by your browser from publishrank.netlify.app. No WordPress post content or visitor data is sent.', 'publishrank' ) . '</p>';
	}

	private function render_action_form( $action, $label, $button_class ) {
		echo '<form class="pr-news__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="publishrank_news_feed" /><input type="hidden" name="feed_action" value="' . esc_attr( $action ) . '" />';
		echo '<button type="submit" class="' . esc_attr( $button_class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private function get_feed( $force = false ) {
		if ( ! get_option( self::ENABLED_OPTION, false ) ) {
			return new \WP_Error( 'publishrank_news_consent_required', __( 'Enable the News feed first.', 'publishrank' ) );
		}
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ! empty( $cached['payload'] ) && is_array( $cached['payload'] ) ) {
				return $cached;
			}
		}

		$response = wp_safe_remote_get(
			self::FEED_URL,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'user-agent'          => 'PublishRank News Feed; WordPress',
				'limit_response_size' => 524288,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->stale_or_error();
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->stale_or_error();
		}
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( is_string( $content_type ) && '' !== $content_type && false === stripos( $content_type, 'application/json' ) ) {
			return $this->stale_or_error();
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || '1.0' !== ( isset( $decoded['schema_version'] ) ? (string) $decoded['schema_version'] : '' ) || empty( $decoded['feed'] ) || ! is_array( $decoded['feed'] ) || 'active' !== ( isset( $decoded['feed']['status'] ) ? sanitize_key( $decoded['feed']['status'] ) : '' ) || ! isset( $decoded['announcements'] ) || ! is_array( $decoded['announcements'] ) ) {
			return $this->stale_or_error();
		}

		$payload = $this->sanitize_feed( $decoded );
		$ttl     = isset( $decoded['feed']['cache_ttl'] ) ? (int) $decoded['feed']['cache_ttl'] : HOUR_IN_SECONDS;
		$ttl     = max( 300, min( DAY_IN_SECONDS, $ttl ) );
		$state   = array(
			'payload'   => $payload,
			'cached_at' => time(),
			'stale'     => false,
		);
		set_transient( self::CACHE_KEY, $state, $ttl );
		set_transient( self::STALE_KEY, $state, DAY_IN_SECONDS );
		return $state;
	}

	private function stale_or_error() {
		$stale = get_transient( self::STALE_KEY );
		if ( is_array( $stale ) && ! empty( $stale['payload'] ) && is_array( $stale['payload'] ) ) {
			$stale['stale'] = true;
			return $stale;
		}
		return new \WP_Error( 'publishrank_news_unavailable', __( 'The live PublishRank News feed is unavailable.', 'publishrank' ) );
	}

	private function sanitize_feed( $feed ) {
		$publisher = isset( $feed['publisher'] ) && is_array( $feed['publisher'] ) ? $feed['publisher'] : array();
		return array(
			'schema_version' => isset( $feed['schema_version'] ) ? sanitize_text_field( (string) $feed['schema_version'] ) : '',
			'publisher'      => array(
				'name' => isset( $publisher['name'] ) ? sanitize_text_field( $publisher['name'] ) : 'PublishRank',
			),
			'announcements'  => array_values( array_filter( array_map( array( $this, 'sanitize_announcement' ), $feed['announcements'] ) ) ),
		);
	}

	private function sanitize_announcement( $item ) {
		if ( ! is_array( $item ) ) {
			return false;
		}
		$clean = array();
		foreach ( array( 'id', 'slug', 'title', 'summary', 'message', 'category', 'type', 'published_at', 'updated_at', 'expires_at', 'image_alt', 'author' ) as $key ) {
			if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $item[ $key ] );
			}
		}
		foreach ( array( 'active', 'featured' ) as $key ) {
			$clean[ $key ] = isset( $item[ $key ] ) && true === $item[ $key ];
		}
		$clean['image']  = isset( $item['image'] ) ? self::trusted_url( $item['image'] ) : '';
		$clean['labels'] = array();
		if ( isset( $item['labels'] ) && is_array( $item['labels'] ) ) {
			foreach ( array_slice( $item['labels'], 0, 12 ) as $label ) {
				if ( is_scalar( $label ) ) {
					$clean['labels'][] = sanitize_key( (string) $label );
				}
			}
		}
		$clean['targeting'] = isset( $item['targeting'] ) && is_array( $item['targeting'] ) ? array(
			'minimum_plugin_version' => isset( $item['targeting']['minimum_plugin_version'] ) ? sanitize_text_field( (string) $item['targeting']['minimum_plugin_version'] ) : '',
			'maximum_plugin_version' => isset( $item['targeting']['maximum_plugin_version'] ) ? sanitize_text_field( (string) $item['targeting']['maximum_plugin_version'] ) : '',
		) : array();
		$clean['actions']   = array();
		if ( isset( $item['actions'] ) && is_array( $item['actions'] ) ) {
			foreach ( array_slice( $item['actions'], 0, 3 ) as $action ) {
				if ( is_array( $action ) && ! empty( $action['url'] ) ) {
					$clean['actions'][] = array(
						'label' => isset( $action['label'] ) ? sanitize_text_field( $action['label'] ) : '',
						'url'   => self::trusted_url( $action['url'] ),
					);
				}
			}
		}
		$clean['body'] = array();
		if ( isset( $item['body'] ) && is_array( $item['body'] ) ) {
			foreach ( array_slice( $item['body'], 0, 8 ) as $section ) {
				if ( is_array( $section ) ) {
					$clean['body'][] = array(
						'heading' => isset( $section['heading'] ) ? sanitize_text_field( (string) $section['heading'] ) : '',
						'text'    => isset( $section['text'] ) ? sanitize_text_field( (string) $section['text'] ) : '',
					);
				}
			}
		}
		return $clean;
	}
}
