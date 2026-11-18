<?php
/**
 * News & What's New (2.25): official PublishRank announcements and release notes inside WordPress.
 *
 * Data source: the JSON feed published by the official website (https://publishrankseo.vercel.app/publishrank-feed.json),
 * built from the same release list the website renders, so news is written once.
 *
 * Security and privacy:
 * - Fixed, allowlisted host over HTTPS with certificate verification; redirects are never followed.
 * - Response size, time and JSON depth are limited; every field is validated against the schema and sanitized;
 *   only plain text and links to the official host are kept. Output is escaped.
 * - Nothing about the site is sent: a neutral User-Agent replaces WordPress's default (which contains the site URL),
 *   no cookies, no query string, no account.
 * - The feed can never execute code, change settings or install updates. Updates still come from WordPress.org.
 * - Cached copy with ETag / Last-Modified, stale-cache fallback, then the changelog bundled with the plugin.
 *
 * Performance: no front-end code. Admin screens read one small summary option; the full feed is only loaded on this screen.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\News;

use PublishRankSEO\Admin\AdminAssets;
use PublishRankSEO\Admin\Suite;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class WhatsNew {
	const SLUG           = 'publishrank-whats-new';
	const HOST           = 'publishrankseo.vercel.app';
	const FEED_PATH      = '/publishrank-feed.json';
	const CACHE          = 'publishrank_news_cache';
	const SUMMARY        = 'publishrank_news_summary';
	const OPT_REMOTE     = 'publishrank_news_remote';
	const CRON           = 'publishrank_news_refresh';
	const META_SEEN      = 'publishrank_news_seen';
	const META_DISMISSED = 'publishrank_news_dismissed';
	const MAX_BYTES      = 262144;
	const TIMEOUT        = 8;
	const MANUAL_GAP     = 60;
	const KINDS          = array( 'feature', 'improvement', 'fix', 'security', 'compatibility', 'change' );
	const LEVELS         = array( 'info', 'important', 'security' );

	public function register() {
		add_action( self::CRON, array( __CLASS__, 'refresh' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 14 );
			add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
			add_action(
				'admin_enqueue_scripts',
				static function () {
					Suite::enqueue_for( array( self::SLUG, Branding::MENU_SLUG ) );
				}
			);
			add_action( 'admin_post_publishrank_news_refresh', array( $this, 'handle_refresh' ) );
			add_action( 'admin_post_publishrank_news_dismiss', array( $this, 'handle_dismiss' ) );
			add_action( 'admin_post_publishrank_news_settings', array( $this, 'handle_settings' ) );
			add_action( 'admin_notices', array( $this, 'notices' ) );
		}
	}

	/* ------------------------------------------------------------------ Fetching */

	public static function feed_url() {
		return 'https://' . self::HOST . self::FEED_PATH;
	}

	public static function remote_enabled() {
		if ( defined( 'PUBLISHRANK_DISABLE_NEWS_FEED' ) && PUBLISHRANK_DISABLE_NEWS_FEED ) {
			return false;
		}
		return (bool) get_option( self::OPT_REMOTE, 1 );
	}

	/** Keep the twice-daily refresh scheduled only while remote news is on. */
	public static function schedule() {
		$next = wp_next_scheduled( self::CRON );
		if ( self::remote_enabled() && ! $next ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::CRON );
		} elseif ( ! self::remote_enabled() && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/** @return array{feed:?array,etag:string,modified:string,fetched_at:int,checked_at:int,error:string} */
	public static function cache() {
		$defaults = array(
			'feed'       => null,
			'etag'       => '',
			'modified'   => '',
			'fetched_at' => 0,
			'checked_at' => 0,
			'error'      => '',
		);
		$c = get_option( self::CACHE, array() );
		return is_array( $c ) ? array_merge( $defaults, array_intersect_key( $c, $defaults ) ) : $defaults;
	}

	private static function save( array $c ) {
		update_option( self::CACHE, $c, false );
		update_option( self::SUMMARY, self::summarize( is_array( $c['feed'] ) ? $c['feed'] : null ), false );
	}

	private static function fail( array $c, $code, $message ) {
		$c['error'] = $message;
		self::save( $c );
		return new \WP_Error( $code, $message );
	}

	/**
	 * Fetch the official feed. Keeps the previous copy on any failure.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh() {
		if ( ! self::remote_enabled() ) {
			return new \WP_Error( 'publishrank_news_disabled', __( 'Online news is turned off.', 'publishrank' ) );
		}
		$c       = self::cache();
		$headers = array( 'Accept' => 'application/json' );
		if ( is_array( $c['feed'] ) ) {
			if ( '' !== $c['etag'] ) {
				$headers['If-None-Match'] = $c['etag'];
			}
			if ( '' !== $c['modified'] ) {
				$headers['If-Modified-Since'] = $c['modified'];
			}
		}
		$res = wp_safe_remote_get(
			self::feed_url(),
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_BYTES + 1,
				'user-agent'          => 'PublishRank/' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '2' ),
				'headers'             => $headers,
				'cookies'             => array(),
			)
		);
		$c['checked_at'] = time();
		if ( is_wp_error( $res ) ) {
			return self::fail( $c, 'publishrank_news_http', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 304 === $code && is_array( $c['feed'] ) ) {
			$c['error']      = '';
			$c['fetched_at'] = time();
			self::save( $c );
			return true;
		}
		if ( $code >= 300 && $code < 400 ) {
			return self::fail( $c, 'publishrank_news_redirect', __( 'The website answered with a redirect, which is not followed for safety.', 'publishrank' ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return self::fail( $c, 'publishrank_news_status', sprintf( __( 'The website answered with HTTP %d.', 'publishrank' ), $code ) );
		}
		if ( false === stripos( (string) wp_remote_retrieve_header( $res, 'content-type' ), 'json' ) ) {
			return self::fail( $c, 'publishrank_news_type', __( 'The website did not return JSON.', 'publishrank' ) );
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return self::fail( $c, 'publishrank_news_size', __( 'The feed is larger than the 256 KB limit.', 'publishrank' ) );
		}
		$data = json_decode( $body, true, 12 );
		if ( ! is_array( $data ) ) {
			return self::fail( $c, 'publishrank_news_json', __( 'The feed is not valid JSON.', 'publishrank' ) );
		}
		$feed = self::validate( $data );
		if ( is_wp_error( $feed ) ) {
			return self::fail( $c, $feed->get_error_code(), $feed->get_error_message() );
		}
		$c['feed']       = $feed;
		$c['etag']       = self::header_value( wp_remote_retrieve_header( $res, 'etag' ) );
		$c['modified']   = self::header_value( wp_remote_retrieve_header( $res, 'last-modified' ) );
		$c['fetched_at'] = time();
		$c['error']      = '';
		self::save( $c );
		return true;
	}

	private static function header_value( $v ) {
		$v = is_array( $v ) ? (string) reset( $v ) : (string) $v;
		return preg_match( '/^[\x20-\x7e]{1,200}$/', $v ) ? $v : '';
	}

	/* ---------------------------------------------------------------- Validation */

	/** Plain text only: anything containing markup is rejected rather than "cleaned". */
	public static function text( $v, $max ) {
		if ( ! is_string( $v ) || false !== strpos( $v, '<' ) || false !== strpos( $v, '>' ) ) {
			return '';
		}
		$v = sanitize_text_field( $v );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	/** Only https links on the official host are kept. */
	public static function url( $v ) {
		if ( ! is_string( $v ) || strlen( $v ) > 300 ) {
			return '';
		}
		$p = wp_parse_url( $v );
		if ( ! is_array( $p ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || self::HOST !== strtolower( (string) ( $p['host'] ?? '' ) ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) ) {
			return '';
		}
		return esc_url_raw( $v, array( 'https' ) );
	}

	private static function version_str( $v ) {
		return is_string( $v ) && preg_match( '/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $v ) ? $v : '';
	}

	private static function short_version( $v ) {
		return is_string( $v ) && preg_match( '/^\d{1,2}\.\d{1,2}$/', $v ) ? $v : '';
	}

	private static function date( $v ) {
		if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $v;
	}

	private static function datetime( $v ) {
		if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}Z$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return 0;
		}
		$t = strtotime( $v );
		return $t ? (int) $t : 0;
	}

	private static function id( $v ) {
		return is_string( $v ) && preg_match( '/^[a-z0-9][a-z0-9-]{0,79}$/', $v ) ? $v : '';
	}

	/**
	 * Validate and sanitize a decoded feed (schema version 1). Invalid items are dropped; an invalid envelope fails.
	 *
	 * @return array|\WP_Error
	 */
	public static function validate( $d ) {
		if ( ! is_array( $d ) || 1 !== ( $d['schema_version'] ?? null ) || 'PublishRank' !== ( $d['product'] ?? null ) ) {
			return new \WP_Error( 'publishrank_news_schema', __( 'The feed is not a PublishRank feed (schema version 1).', 'publishrank' ) );
		}
		$latest = self::version_str( $d['latest_version'] ?? '' );
		if ( '' === $latest ) {
			return new \WP_Error( 'publishrank_news_schema', __( 'The feed has no valid latest version.', 'publishrank' ) );
		}
		$out = array(
			'updated_at'     => self::datetime( $d['updated_at'] ?? '' ),
			'latest_version' => $latest,
			'releases'       => array(),
			'news'           => array(),
			'announcements'  => array(),
			'links'          => array(),
		);
		foreach ( array_slice( is_array( $d['releases'] ?? null ) ? $d['releases'] : array(), 0, 30 ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$v     = self::version_str( $r['version'] ?? '' );
			$url   = self::url( $r['url'] ?? '' );
			$title = self::text( $r['title'] ?? '', 140 );
			if ( '' === $v || '' === $url || '' === $title ) {
				continue;
			}
			$changes = array();
			foreach ( array_slice( is_array( $r['changes'] ?? null ) ? $r['changes'] : array(), 0, 40 ) as $ch ) {
				$text = is_array( $ch ) ? self::text( $ch['type'] ?? '', 20 ) : '';
				$body = is_array( $ch ) ? self::text( $ch['text'] ?? '', 400 ) : '';
				if ( '' !== $body ) {
					$changes[] = array(
						'type' => in_array( $text, self::KINDS, true ) ? $text : 'change',
						'text' => $body,
					);
				}
			}
			$out['releases'][] = array(
				'version'      => $v,
				'date'         => self::date( $r['date'] ?? '' ),
				'title'        => $title,
				'summary'      => self::text( $r['summary'] ?? '', 400 ),
				'url'          => $url,
				'minimum_wp'   => self::short_version( $r['minimum_wp'] ?? '' ),
				'minimum_php'  => self::short_version( $r['minimum_php'] ?? '' ),
				'tested_up_to' => self::short_version( $r['tested_up_to'] ?? '' ),
				'changes'      => $changes,
			);
		}
		if ( ! $out['releases'] ) {
			return new \WP_Error( 'publishrank_news_schema', __( 'The feed contains no valid release.', 'publishrank' ) );
		}
		foreach ( array_slice( is_array( $d['news'] ?? null ) ? $d['news'] : array(), 0, 30 ) as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			$item = array(
				'id'           => self::id( $n['id'] ?? '' ),
				'title'        => self::text( $n['title'] ?? '', 160 ),
				'summary'      => self::text( $n['summary'] ?? '', 400 ),
				'category'     => self::text( $n['category'] ?? '', 40 ),
				'published_at' => self::datetime( $n['published_at'] ?? '' ),
				'url'          => self::url( $n['url'] ?? '' ),
				'featured'     => true === ( $n['featured'] ?? false ),
			);
			if ( '' !== $item['id'] && '' !== $item['title'] && '' !== $item['url'] && $item['published_at'] ) {
				$out['news'][] = $item;
			}
		}
		foreach ( array_slice( is_array( $d['announcements'] ?? null ) ? $d['announcements'] : array(), 0, 10 ) as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$item = array(
				'id'           => self::id( $a['id'] ?? '' ),
				'title'        => self::text( $a['title'] ?? '', 140 ),
				'summary'      => self::text( $a['summary'] ?? '', 400 ),
				'level'        => in_array( $a['level'] ?? '', self::LEVELS, true ) ? $a['level'] : 'info',
				'published_at' => self::datetime( $a['published_at'] ?? '' ),
				'url'          => self::url( $a['url'] ?? '' ),
			);
			if ( '' !== $item['id'] && '' !== $item['title'] && '' !== $item['url'] && $item['published_at'] ) {
				$out['announcements'][] = $item;
			}
		}
		foreach ( array_slice( is_array( $d['links'] ?? null ) ? $d['links'] : array(), 0, 12 ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$item = array(
				'id'    => self::id( $l['id'] ?? '' ),
				'title' => self::text( $l['title'] ?? '', 60 ),
				'url'   => self::url( $l['url'] ?? '' ),
			);
			if ( '' !== $item['id'] && '' !== $item['title'] && '' !== $item['url'] ) {
				$out['links'][] = $item;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------- Reading */

	/** Small, menu-safe summary of the cached feed (ids, times, levels). */
	private static function summarize( $feed ) {
		if ( ! is_array( $feed ) ) {
			return array();
		}
		$s = array(
			'latest'        => $feed['latest_version'],
			'news'          => array(),
			'announcements' => array(),
		);
		foreach ( $feed['news'] as $n ) {
			$s['news'][ $n['id'] ] = (int) $n['published_at'];
		}
		foreach ( $feed['announcements'] as $a ) {
			$s['announcements'][ $a['id'] ] = array( (int) $a['published_at'], $a['level'], $a['title'], $a['summary'], $a['url'] );
		}
		return $s;
	}

	/** Changelog bundled with the plugin, shaped like the feed (used when no copy of the feed exists). */
	public static function local_feed() {
		$releases = array();
		$file     = defined( 'PUBLISHRANK_SEO_PATH' ) ? PUBLISHRANK_SEO_PATH . 'readme.txt' : '';
		$raw      = $file && is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pos      = strpos( $raw, '== Changelog ==' );
		if ( false !== $pos ) {
			$parts = preg_split( '/^= ([0-9][^=]*?) =\s*$/m', substr( $raw, $pos + 15 ), -1, PREG_SPLIT_DELIM_CAPTURE );
			for ( $i = 1; $i + 1 < count( $parts ) && count( $releases ) < 8; $i += 2 ) {
				$changes = array();
				foreach ( preg_split( '/\R/', (string) $parts[ $i + 1 ] ) as $line ) {
					if ( 0 === strpos( ltrim( $line ), '* ' ) ) {
						$text = trim( str_replace( array( '**', '`' ), '', substr( ltrim( $line ), 2 ) ) );
						$text = self::text( wp_strip_all_tags( $text ), 400 );
						if ( '' !== $text ) {
							$changes[] = array(
								'type' => 'change',
								'text' => $text,
							);
						}
					}
				}
				$releases[] = array(
					'version'      => trim( (string) $parts[ $i ] ),
					'date'         => '',
					'title'        => 'PublishRank ' . trim( (string) $parts[ $i ] ),
					'summary'      => '',
					'url'          => 'https://' . self::HOST . '/changelog',
					'minimum_wp'   => '',
					'minimum_php'  => '',
					'tested_up_to' => '',
					'changes'      => $changes,
				);
			}
		}
		return array(
			'updated_at'     => 0,
			'latest_version' => defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '',
			'releases'       => $releases,
			'news'           => array(),
			'announcements'  => array(),
			'links'          => array(
				array( 'id' => 'documentation', 'title' => __( 'Documentation', 'publishrank' ), 'url' => 'https://' . self::HOST . '/documentation' ),
				array( 'id' => 'changelog', 'title' => __( 'Full release history', 'publishrank' ), 'url' => 'https://' . self::HOST . '/changelog' ),
				array( 'id' => 'support', 'title' => __( 'Support', 'publishrank' ), 'url' => 'https://' . self::HOST . '/support' ),
			),
		);
	}

	/** @return array{source:string,feed:array,cache:array} source: live | stale | local | off */
	public static function data() {
		$c = self::cache();
		if ( is_array( $c['feed'] ) ) {
			return array(
				'source' => '' === $c['error'] ? 'live' : 'stale',
				'feed'   => $c['feed'],
				'cache'  => $c,
			);
		}
		return array(
			'source' => self::remote_enabled() ? 'local' : 'off',
			'feed'   => self::local_feed(),
			'cache'  => $c,
		);
	}

	private static function seen( $user_id ) {
		$seen = (int) get_user_meta( $user_id, self::META_SEEN, true );
		return $seen > 0 ? $seen : time() - 14 * DAY_IN_SECONDS;
	}

	/** @return string[] */
	public static function dismissed( $user_id ) {
		$d = get_user_meta( $user_id, self::META_DISMISSED, true );
		return is_array( $d ) ? array_values( array_filter( $d, 'is_string' ) ) : array();
	}

	/** Unread news plus undismissed new announcements, from the small summary option. */
	public static function unread_count( $user_id ) {
		$s = get_option( self::SUMMARY, array() );
		if ( ! is_array( $s ) || empty( $s['news'] ) && empty( $s['announcements'] ) ) {
			return 0;
		}
		$seen  = self::seen( $user_id );
		$dis   = self::dismissed( $user_id );
		$count = 0;
		foreach ( (array) $s['news'] as $t ) {
			$count += (int) $t > $seen ? 1 : 0;
		}
		foreach ( (array) $s['announcements'] as $id => $a ) {
			$count += ( (int) $a[0] > $seen && ! in_array( (string) $id, $dis, true ) && ! self::is_outdated_release( $id, (string) ( $a[2] ?? '' ) ) ) ? 1 : 0;
		}
		return $count;
	}

	/**
	 * Whether an announcement only says that a release up to the installed version is available
	 * ("release-2-25-0", "PublishRank 2.25.0 is available"). Those are already installed, so they are
	 * neither counted as unread nor shown as notices.
	 */
	public static function is_outdated_release( $id, $title = '' ) {
		$current = defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '';
		if ( '' === $current ) {
			return false;
		}
		if ( preg_match( '/^release-(\d+)-(\d+)(?:-(\d+))?(?:-(\d+))?$/', (string) $id, $m ) ) {
			$v = $m[1] . '.' . $m[2] . ( isset( $m[3] ) && '' !== $m[3] ? '.' . $m[3] : '' ) . ( isset( $m[4] ) && '' !== $m[4] ? '.' . $m[4] : '' );
			return version_compare( $v, $current, '<=' );
		}
		if ( preg_match( '/\b(\d+\.\d+(?:\.\d+){0,2})\s+is\s+available\b/i', (string) $title, $m ) ) {
			return version_compare( $m[1], $current, '<=' );
		}
		return false;
	}

	public static function update_available( array $feed ) {
		return ! empty( $feed['latest_version'] ) && defined( 'PUBLISHRANK_SEO_VERSION' ) && version_compare( $feed['latest_version'], PUBLISHRANK_SEO_VERSION, '>' );
	}

	/* ------------------------------------------------------------------- Actions */

	public function menu() {
		$label = __( 'News & What\'s New', 'publishrank' );
		$count = Capabilities::can( 'view_news' ) ? self::unread_count( get_current_user_id() ) : 0;
		$menu  = esc_html( $label ) . ( $count ? ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>' : '' );
		add_submenu_page( Branding::MENU_SLUG, $label, $menu, Capabilities::cap( 'view_news' ), self::SLUG, array( $this, 'render' ) );
	}

	public function handle_refresh() {
		Suite::guard( 'publishrank_news_refresh', 'view_news' );
		$c = self::cache();
		if ( time() - (int) $c['checked_at'] < self::MANUAL_GAP ) {
			Suite::done( self::SLUG, 'info', __( 'Checked less than a minute ago. Please wait a moment before checking again.', 'publishrank' ) );
		}
		$r = self::refresh();
		if ( is_wp_error( $r ) ) {
			/* translators: %s: error message */
			Suite::done( self::SLUG, 'error', sprintf( __( 'Could not reach the PublishRank website: %s The last saved copy is still shown.', 'publishrank' ), $r->get_error_message() ) );
		}
		Suite::done( self::SLUG, 'success', __( 'News and release notes are up to date.', 'publishrank' ) );
	}

	public function handle_dismiss() {
		$id = isset( $_GET['id'] ) ? self::id( sanitize_key( wp_unslash( $_GET['id'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		Suite::guard( 'publishrank_news_dismiss_' . $id, 'view_news' );
		if ( '' !== $id ) {
			$uid = get_current_user_id();
			$d   = self::dismissed( $uid );
			$d[] = $id;
			update_user_meta( $uid, self::META_DISMISSED, array_slice( array_values( array_unique( $d ) ), -100 ) );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : Suite::url( self::SLUG ) );
		exit;
	}

	public function handle_settings() {
		Suite::guard( 'publishrank_news_settings', 'manage_settings' );
		update_option( self::OPT_REMOTE, empty( $_POST['remote'] ) ? 0 : 1, false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		self::schedule();
		Suite::done( self::SLUG, 'success', __( 'Settings saved.', 'publishrank' ), array(), 'settings' );
	}

	public static function dismiss_url( $id ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'publishrank_news_dismiss', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'publishrank_news_dismiss_' . $id );
	}

	/**
	 * Announcement notices: security notices on every admin screen, others on PublishRank screens and the
	 * WordPress dashboard. Only for users allowed to read news; each user dismisses for themselves.
	 */
	public function notices() {
		if ( ! Capabilities::can( 'view_news' ) ) {
			return;
		}
		$s = get_option( self::SUMMARY, array() );
		if ( ! is_array( $s ) || empty( $s['announcements'] ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		$ours   = 'dashboard' === $id || false !== strpos( $id, 'publishrank' );
		if ( false !== strpos( $id, self::SLUG ) ) {
			return; // The page itself shows them.
		}
		$dis = self::dismissed( get_current_user_id() );
		foreach ( (array) $s['announcements'] as $aid => $a ) {
			if ( in_array( (string) $aid, $dis, true ) || (int) $a[0] < time() - 60 * DAY_IN_SECONDS || ( 'security' !== $a[1] && ! $ours ) || self::is_outdated_release( $aid, (string) $a[2] ) ) {
				continue;
			}
			$class = 'security' === $a[1] ? 'notice-error' : ( 'important' === $a[1] ? 'notice-warning' : 'notice-info' );
			echo '<div class="notice ' . esc_attr( $class ) . ' publishrank-news-notice"><p><strong>' . esc_html( (string) $a[2] ) . '</strong> ' . esc_html( (string) $a[3] ) . ' <a href="' . esc_url( (string) $a[4] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read more', 'publishrank' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'publishrank' ) . '</span></a> · <a href="' . esc_url( Suite::url( self::SLUG ) ) . '">' . esc_html__( 'News & What\'s New', 'publishrank' ) . '</a> · <a href="' . esc_url( self::dismiss_url( (string) $aid ) ) . '">' . esc_html__( 'Dismiss', 'publishrank' ) . '</a></p></div>';
		}
	}

	/* ----------------------------------------------------------------- Rendering */

	private static function ext_link( $url, $label, $class = '' ) {
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'publishrank' ) . '</span></a>';
	}

	private static function kind_labels() {
		return array(
			'feature'       => __( 'New features', 'publishrank' ),
			'improvement'   => __( 'Improvements', 'publishrank' ),
			'fix'           => __( 'Bug fixes', 'publishrank' ),
			'security'      => __( 'Security', 'publishrank' ),
			'compatibility' => __( 'Compatibility', 'publishrank' ),
			'change'        => __( 'Changes', 'publishrank' ),
		);
	}

	private static function when( $ts ) {
		return $ts ? sprintf( /* translators: %s: human time difference */ __( '%s ago', 'publishrank' ), human_time_diff( (int) $ts, time() ) ) : __( 'never', 'publishrank' );
	}

	private static function status_line( array $data ) {
		$c = $data['cache'];
		switch ( $data['source'] ) {
			case 'live':
				/* translators: 1: host, 2: time ago */
				return array( 'ok', __( 'Up to date', 'publishrank' ), sprintf( __( 'Official feed from %1$s, checked %2$s.', 'publishrank' ), self::HOST, self::when( $c['checked_at'] ) ) );
			case 'stale':
				/* translators: 1: time ago, 2: error */
				return array( 'warn', __( 'Showing the last saved copy', 'publishrank' ), sprintf( __( 'Saved %1$s. The website could not be reached just now: %2$s', 'publishrank' ), self::when( $c['fetched_at'] ), $c['error'] ) );
			case 'off':
				return array( 'info', __( 'Online news is off', 'publishrank' ), __( 'Showing the changelog bundled with this plugin. Turn online news on in Settings to see announcements.', 'publishrank' ) );
			default:
				return array( 'info', __( 'Showing the bundled changelog', 'publishrank' ), '' !== $c['error'] ? sprintf( /* translators: %s: error */ __( 'The PublishRank website could not be reached yet: %s', 'publishrank' ), $c['error'] ) : __( 'News will appear after the first check.', 'publishrank' ) );
		}
	}

	private static function release_changes( array $release ) {
		$groups = array();
		foreach ( $release['changes'] as $ch ) {
			$groups[ $ch['type'] ][] = $ch['text'];
		}
		$out = '';
		foreach ( self::kind_labels() as $kind => $label ) {
			if ( empty( $groups[ $kind ] ) ) {
				continue;
			}
			$out .= '<div class="prx-news-group prx-news-group--' . esc_attr( $kind ) . '"><h4>' . esc_html( $label ) . ' <span class="prx-count">' . count( $groups[ $kind ] ) . '</span></h4><ul>';
			foreach ( $groups[ $kind ] as $t ) {
				$out .= '<li>' . esc_html( $t ) . '</li>';
			}
			$out .= '</ul></div>';
		}
		return $out;
	}

	private static function release_meta( array $r ) {
		$bits = array();
		$bits[] = '' !== $r['date'] ? mysql2date( get_option( 'date_format' ), $r['date'] . ' 00:00:00' ) : __( 'Date not recorded', 'publishrank' );
		if ( '' !== $r['minimum_wp'] ) {
			/* translators: %s: version */
			$bits[] = sprintf( __( 'WordPress %s+', 'publishrank' ), $r['minimum_wp'] );
		}
		if ( '' !== $r['minimum_php'] ) {
			/* translators: %s: version */
			$bits[] = sprintf( __( 'PHP %s+', 'publishrank' ), $r['minimum_php'] );
		}
		if ( '' !== $r['tested_up_to'] ) {
			/* translators: %s: version */
			$bits[] = sprintf( __( 'Tested up to %s', 'publishrank' ), $r['tested_up_to'] );
		}
		return implode( ' · ', $bits );
	}

	/** Compact card for the PublishRank dashboard. */
	public static function dashboard_card() {
		if ( ! Capabilities::can( 'view_news' ) ) {
			return;
		}
		$data   = self::data();
		$feed   = $data['feed'];
		$latest = $feed['releases'][0] ?? null;
		$uid    = get_current_user_id();
		$unread = self::unread_count( $uid );
		echo '<div class="prx"><section class="prx-card prx-news-dash" aria-labelledby="prx-news-dash-title"><div class="prx-card__head"><div><h2 class="prx-card__title" id="prx-news-dash-title"><span class="fa-solid fa-bullhorn" aria-hidden="true"></span>' . esc_html__( 'News & What\'s New', 'publishrank' ) . ( $unread ? ' ' . Suite::badge( sprintf( /* translators: %d: count */ _n( '%d new', '%d new', $unread, 'publishrank' ), $unread ), 'info' ) : '' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		/* translators: %s: version */
		echo '<p class="prx-card__sub">' . esc_html( sprintf( __( 'You are running PublishRank %s.', 'publishrank' ), defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '' ) ) . '</p></div><a class="prx-btn prx-btn--sm" href="' . esc_url( Suite::url( self::SLUG ) ) . '">' . esc_html__( 'Open', 'publishrank' ) . '</a></div>';
		echo '<div class="prx-news-dash__body">';
		if ( self::update_available( $feed ) ) {
			/* translators: %s: version */
			echo '<p class="prx-news-update"><span class="fa-solid fa-circle-up" aria-hidden="true"></span> ' . esc_html( sprintf( __( 'PublishRank %s is available. Update from the Plugins screen.', 'publishrank' ), $feed['latest_version'] ) ) . '</p>';
		}
		if ( $latest ) {
			echo '<div><strong>' . esc_html( $latest['title'] ) . '</strong><p class="prx-help">' . esc_html( '' !== $latest['summary'] ? $latest['summary'] : self::release_meta( $latest ) ) . '</p></div>';
		}
		$news = array_slice( $feed['news'], 0, 3 );
		if ( $news ) {
			echo '<ul class="prx-news-mini">';
			foreach ( $news as $n ) {
				echo '<li>' . self::ext_link( $n['url'], $n['title'] ) . ' <span class="prx-help">' . esc_html( date_i18n( get_option( 'date_format' ), $n['published_at'] ) ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul>';
		}
		echo '</div></section></div>';
	}

	public function render() {
		if ( ! Capabilities::can( 'view_news' ) ) {
			return;
		}
		$uid    = get_current_user_id();
		$seen   = self::seen( $uid );
		$dis    = self::dismissed( $uid );
		$data   = self::data();
		$feed   = $data['feed'];
		$status = self::status_line( $data );
		$newest = $seen;

		Suite::open( __( 'News & What\'s New', 'publishrank' ), __( 'Official PublishRank announcements, release notes and update notices.', 'publishrank' ) );

		$icon = array(
			'ok'   => 'fa-circle-check',
			'warn' => 'fa-triangle-exclamation',
			'info' => 'fa-circle-question',
		);
		echo '<div class="prx-hero"><div class="prx-hero__state"><span class="prx-hero__icon is-' . esc_attr( 'info' === $status[0] ? '' : $status[0] ) . '"><span class="fa-solid ' . esc_attr( $icon[ $status[0] ] ) . '" aria-hidden="true"></span></span><div><h2>' . esc_html( $status[1] ) . ( 'live' === $data['source'] ? ' <span class="pr-live-chip">' . UpdateNotifier::live_marker( __( 'Connected to the live feed', 'publishrank' ) ) . esc_html__( 'Live', 'publishrank' ) . '</span>' : '' ) . '</h2><p class="prx-help" role="status">' . esc_html( $status[2] ) . '</p></div></div>';
		if ( self::remote_enabled() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_news_refresh" />';
			wp_nonce_field( 'publishrank_news_refresh' );
			echo '<button type="submit" class="prx-btn"><span class="fa-solid fa-rotate" aria-hidden="true"></span>' . esc_html__( 'Check now', 'publishrank' ) . '</button></form>';
		}
		echo '</div>';

		if ( self::update_available( $feed ) ) {
			echo '<div class="prx-news-update prx-news-update--card"><span class="fa-solid fa-circle-up" aria-hidden="true"></span><div><strong>' . esc_html( sprintf( /* translators: %s: version */ __( 'PublishRank %s is available', 'publishrank' ), $feed['latest_version'] ) ) . '</strong><p class="prx-help">' . esc_html( sprintf( /* translators: %s: version */ __( 'You are running %s. Updates are installed from WordPress.org through the Plugins screen; this news feed never installs anything.', 'publishrank' ), PUBLISHRANK_SEO_VERSION ) ) . '</p></div>';
			if ( current_user_can( 'update_plugins' ) ) {
				echo '<a class="prx-btn prx-btn--primary" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Go to updates', 'publishrank' ) . '</a>';
			}
			echo '</div>';
		}

		foreach ( $feed['announcements'] as $a ) {
			if ( in_array( $a['id'], $dis, true ) ) {
				continue;
			}
			$newest = max( $newest, (int) $a['published_at'] );
			$tone   = 'security' === $a['level'] ? 'bad' : ( 'important' === $a['level'] ? 'warn' : 'info' );
			$label  = array(
				'security'  => __( 'Security notice', 'publishrank' ),
				'important' => __( 'Important', 'publishrank' ),
				'info'      => __( 'Announcement', 'publishrank' ),
			);
			echo '<div class="prx-news-announce prx-news-announce--' . esc_attr( $a['level'] ) . '"><span class="fa-solid fa-bullhorn" aria-hidden="true"></span><div class="prx-news-announce__body">' . Suite::badge( $label[ $a['level'] ], $tone ) . ( (int) $a['published_at'] > $seen ? ' ' . Suite::badge( __( 'Unread', 'publishrank' ), 'info' ) : '' ) . '<strong>' . esc_html( $a['title'] ) . '</strong><p>' . esc_html( $a['summary'] ) . '</p></div><div class="prx-row">' . self::ext_link( $a['url'], __( 'Read more', 'publishrank' ), 'prx-btn prx-btn--sm' ) . '<a class="prx-btn prx-btn--sm prx-btn--ghost" href="' . esc_url( self::dismiss_url( $a['id'] ) ) . '">' . esc_html__( 'Dismiss', 'publishrank' ) . '</a></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$unread_news = 0;
		foreach ( $feed['news'] as $n ) {
			$unread_news += (int) $n['published_at'] > $seen ? 1 : 0;
		}
		$tabs = array(
			'news'     => __( 'Latest news', 'publishrank' ) . ( $unread_news ? '|' . $unread_news : '' ),
			'new'      => __( 'What\'s New', 'publishrank' ),
			'history'  => __( 'Release history', 'publishrank' ) . '|' . count( $feed['releases'] ),
			'settings' => __( 'Settings', 'publishrank' ),
		);
		Suite::tabs( $tabs );

		// Latest news.
		Suite::panel_open( 'news', false );
		if ( ! $feed['news'] ) {
			echo '<div class="prx-card"><div class="prx-empty"><span class="fa-solid fa-newspaper" aria-hidden="true"></span><strong>' . esc_html__( 'No news yet', 'publishrank' ) . '</strong><p>' . esc_html( 'live' === $data['source'] || 'stale' === $data['source'] ? __( 'The official website has not published news.', 'publishrank' ) : __( 'News appears here once the PublishRank website has been reached.', 'publishrank' ) ) . '</p></div></div>';
		} else {
			echo '<div class="prx-news-grid">';
			foreach ( $feed['news'] as $i => $n ) {
				$newest = max( $newest, (int) $n['published_at'] );
				$unread = (int) $n['published_at'] > $seen;
				echo '<article class="prx-news-item' . ( 0 === $i ? ' prx-news-item--lead' : '' ) . ( $unread ? ' is-unread' : '' ) . '"><div class="prx-row">' . Suite::badge( '' !== $n['category'] ? $n['category'] : __( 'News', 'publishrank' ) ) . ( $unread ? Suite::badge( __( 'New', 'publishrank' ), 'info' ) : '' ) . '<span class="prx-help">' . esc_html( date_i18n( get_option( 'date_format' ), $n['published_at'] ) ) . '</span></div><h3>' . self::ext_link( $n['url'], $n['title'] ) . '</h3>' . ( '' !== $n['summary'] ? '<p>' . esc_html( $n['summary'] ) . '</p>' : '' ) . '</article>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
		Suite::panel_close();

		// What's New: installed version and latest release.
		Suite::panel_open( 'new' );
		$installed = null;
		foreach ( $feed['releases'] as $r ) {
			if ( defined( 'PUBLISHRANK_SEO_VERSION' ) && $r['version'] === PUBLISHRANK_SEO_VERSION ) {
				$installed = $r;
			}
		}
		$show = $installed ? $installed : ( $feed['releases'][0] ?? null );
		echo '<div class="prx-news-scope">';
		if ( $show ) {
			$sub = $installed ? __( 'The version installed on this site.', 'publishrank' ) : __( 'The newest release described by the PublishRank website.', 'publishrank' );
			Suite::card_open( $show['title'], 'fa-wand-magic-sparkles', $sub, self::ext_link( $show['url'], __( 'Full release notes', 'publishrank' ), 'prx-btn prx-btn--sm' ) );
			echo '<p class="prx-help">' . esc_html( self::release_meta( $show ) ) . '</p>';
			if ( '' !== $show['summary'] ) {
				echo '<p class="prx-news-lead">' . esc_html( $show['summary'] ) . '</p>';
			}
			echo '<div class="prx-news-groups">' . self::release_changes( $show ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			Suite::card_close();
		} else {
			echo '<div class="prx-card"><div class="prx-empty"><strong>' . esc_html__( 'No release notes available', 'publishrank' ) . '</strong></div></div>';
		}
		echo '</div>';
		Suite::panel_close();

		// Release history.
		Suite::panel_open( 'history' );
		echo '<ol class="prx-news-timeline">';
		foreach ( $feed['releases'] as $i => $r ) {
			$current = defined( 'PUBLISHRANK_SEO_VERSION' ) && $r['version'] === PUBLISHRANK_SEO_VERSION;
			echo '<li class="prx-news-release"><details' . ( 0 === $i ? ' open' : '' ) . '><summary><span class="prx-news-release__v">' . esc_html( $r['version'] ) . '</span><span class="prx-news-release__t"><strong>' . esc_html( $r['title'] ) . '</strong><span class="prx-help">' . esc_html( self::release_meta( $r ) ) . '</span></span>' . ( $current ? Suite::badge( __( 'Installed', 'publishrank' ), 'ok' ) : '' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( '' !== $r['summary'] ) {
				echo '<p class="prx-news-lead">' . esc_html( $r['summary'] ) . '</p>';
			}
			echo '<div class="prx-news-groups">' . self::release_changes( $r ) . '</div><p>' . self::ext_link( $r['url'], __( 'Open on the PublishRank website', 'publishrank' ) ) . '</p></details></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</ol>';
		if ( $feed['links'] ) {
			Suite::card_open( __( 'Documentation & resources', 'publishrank' ), 'fa-book-open' );
			echo '<ul class="prx-news-links">';
			foreach ( $feed['links'] as $l ) {
				echo '<li>' . self::ext_link( $l['url'], $l['title'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '<li><a href="' . esc_url( Suite::url( 'publishrank-help' ) ) . '">' . esc_html__( 'Documentation & Help in this plugin', 'publishrank' ) . '</a></li></ul>';
			Suite::card_close();
		}
		Suite::panel_close();

		// Settings.
		Suite::panel_open( 'settings' );
		Suite::card_open( __( 'Online news', 'publishrank' ), 'fa-shield-halved', __( 'How this screen gets its content.', 'publishrank' ) );
		if ( Capabilities::can( 'manage_settings' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="prx-stack"><input type="hidden" name="action" value="publishrank_news_settings" />';
			wp_nonce_field( 'publishrank_news_settings' );
			Suite::switch_field( 'remote', self::remote_enabled(), __( 'Get news and release notes from the PublishRank website', 'publishrank' ), __( 'Checked twice a day and when you click Check now. When off, the changelog bundled with the plugin is shown.', 'publishrank' ) );
			echo '<div><button type="submit" class="prx-btn prx-btn--primary">' . esc_html__( 'Save', 'publishrank' ) . '</button></div></form>';
			if ( defined( 'PUBLISHRANK_DISABLE_NEWS_FEED' ) && PUBLISHRANK_DISABLE_NEWS_FEED ) {
				echo '<p class="prx-help">' . esc_html__( 'PUBLISHRANK_DISABLE_NEWS_FEED is set in wp-config.php, so online news stays off.', 'publishrank' ) . '</p>';
			}
		}
		echo '<ul class="prx-news-facts">';
		/* translators: %s: URL */
		echo '<li>' . esc_html( sprintf( __( 'Address: %s (HTTPS with certificate check; redirects are not followed).', 'publishrank' ), self::feed_url() ) ) . '</li>';
		echo '<li>' . esc_html__( 'Nothing about this site is sent: no site address, users, settings or statistics. No account is needed.', 'publishrank' ) . '</li>';
		echo '<li>' . esc_html__( 'Only plain text and links to the official website are accepted. The feed cannot run code, change settings or install updates.', 'publishrank' ) . '</li>';
		echo '<li>' . esc_html__( 'Updates are installed from WordPress.org through the Plugins screen.', 'publishrank' ) . '</li>';
		/* translators: 1: time ago, 2: time ago */
		echo '<li>' . esc_html( sprintf( __( 'Last check: %1$s. Last successful download: %2$s.', 'publishrank' ), self::when( $data['cache']['checked_at'] ), self::when( $data['cache']['fetched_at'] ) ) ) . '</li>';
		echo '</ul>';
		Suite::card_close();
		Suite::panel_close();

		if ( $newest > (int) get_user_meta( $uid, self::META_SEEN, true ) ) {
			update_user_meta( $uid, self::META_SEEN, $newest );
		}
		Suite::close();
	}
}
