<?php
/**
 * PublishRank update notifications and the "live" indicator for News & What's New.
 *
 * Two independent signals are combined:
 * 1. WordPress's own plugin-update data (update_plugins site transient). This is authoritative:
 *    when it lists a newer PublishRank, the update can be installed from the Plugins screen.
 * 2. latest_version from the official PublishRank feed (already fetched and cached by WhatsNew).
 *    It is used only to announce a release early; nothing is ever downloaded or installed here.
 *
 * Users who can update plugins see one dismissible notice per version on PublishRank screens, the
 * Dashboard and the Plugins screen. A pulsing "live" marker appears next to News & What's New
 * (admin menu and toolbar) while there is unread news, an undismissed announcement or an update.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\News;

use PublishRankSEO\Admin\Suite;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class UpdateNotifier {
	const META_DISMISSED = 'publishrank_update_dismissed';
	const ACTION         = 'publishrank_update_dismiss';

	public function register() {
		if ( ! is_admin() ) {
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 95 );
			return;
		}
		add_action( 'admin_notices', array( __CLASS__, 'notice' ), 5 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 95 );
		add_action( 'admin_menu', array( __CLASS__, 'mark_menu' ), 999 );
	}

	/** Version currently running. */
	public static function current() {
		return defined( 'PUBLISHRANK_SEO_VERSION' ) ? (string) PUBLISHRANK_SEO_VERSION : '0.0.0';
	}

	/**
	 * The newest known PublishRank release, or null when this site is up to date.
	 *
	 * @return array{version:string,installable:bool,source:string}|null
	 */
	public static function available() {
		$current = self::current();
		$best    = null;
		$basename = defined( 'PUBLISHRANK_SEO_FILE' ) ? plugin_basename( PUBLISHRANK_SEO_FILE ) : 'publishrank/publishrank.php';
		$updates  = get_site_transient( 'update_plugins' );
		if ( is_object( $updates ) && isset( $updates->response[ $basename ] ) && is_object( $updates->response[ $basename ] ) ) {
			$v = isset( $updates->response[ $basename ]->new_version ) ? (string) $updates->response[ $basename ]->new_version : '';
			if ( self::valid_version( $v ) && version_compare( $v, $current, '>' ) ) {
				$best = array(
					'version'     => $v,
					'installable' => ! empty( $updates->response[ $basename ]->package ),
					'source'      => 'wordpress',
				);
			}
		}
		$summary = get_option( WhatsNew::SUMMARY, array() );
		$feed_v  = is_array( $summary ) && isset( $summary['latest'] ) ? (string) $summary['latest'] : '';
		if ( '' === $feed_v ) {
			$cache  = WhatsNew::cache();
			$feed_v = is_array( $cache['feed'] ) && isset( $cache['feed']['latest_version'] ) ? (string) $cache['feed']['latest_version'] : '';
		}
		if ( self::valid_version( $feed_v ) && version_compare( $feed_v, $current, '>' ) && ( ! $best || version_compare( $feed_v, $best['version'], '>' ) ) ) {
			$best = array(
				'version'     => $feed_v,
				'installable' => false,
				'source'      => 'feed',
			);
		}
		return $best;
	}

	private static function valid_version( $v ) {
		return is_string( $v ) && (bool) preg_match( '/^\d+\.\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.\-]+)?$/', $v );
	}

	/** Whether the live marker should show for this user: unread news, open announcements or an update. */
	public static function has_live_items( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! Capabilities::can( 'view_news' ) ) {
			return false;
		}
		return WhatsNew::unread_count( $user_id ) > 0 || ( self::available() && current_user_can( 'update_plugins' ) );
	}

	/** Accessible live marker markup. */
	public static function live_marker( $label = '' ) {
		$label = '' !== $label ? $label : __( 'New updates available', 'publishrank' );
		return '<span class="pr-live-dot" aria-hidden="true"></span><span class="screen-reader-text"> ' . esc_html( $label ) . '</span>';
	}

	/** Add the marker to the PublishRank top-level menu and the News & What's New entry. */
	public static function mark_menu() {
		global $menu, $submenu;
		if ( ! self::has_live_items() ) {
			return;
		}
		$marker = self::live_marker();
		foreach ( (array) $menu as $i => $item ) {
			if ( isset( $item[2] ) && Branding::MENU_SLUG === $item[2] && false === strpos( (string) $item[0], 'pr-live-dot' ) ) {
				$menu[ $i ][0] .= ' ' . $marker; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- appending a status marker to our own menu item.
			}
		}
		if ( ! empty( $submenu[ Branding::MENU_SLUG ] ) ) {
			foreach ( $submenu[ Branding::MENU_SLUG ] as $i => $item ) {
				if ( isset( $item[2] ) && WhatsNew::SLUG === $item[2] && false === strpos( (string) $item[0], 'pr-live-dot' ) ) {
					$submenu[ Branding::MENU_SLUG ][ $i ][0] .= ' ' . $marker; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
			}
		}
	}

	/** Toolbar shortcut with the live marker, only while something is new. */
	public static function admin_bar( $bar ) {
		if ( ! is_object( $bar ) || ! is_admin_bar_showing() || ! self::has_live_items() ) {
			return;
		}
		$update = self::available();
		$label  = $update && current_user_can( 'update_plugins' )
			/* translators: %s: version number */
			? sprintf( __( 'PublishRank %s available', 'publishrank' ), $update['version'] )
			: __( 'PublishRank news', 'publishrank' );
		$bar->add_node(
			array(
				'id'     => 'publishrank-live',
				'parent' => 'top-secondary',
				'title'  => '<span class="pr-live-dot pr-live-dot--bar" aria-hidden="true"></span><span class="ab-label">' . esc_html( $label ) . '</span>',
				'href'   => esc_url( Branding::page_url( WhatsNew::SLUG ) ),
				'meta'   => array( 'title' => $label ),
			)
		);
	}

	/** Versions this user has dismissed. @return string[] */
	private static function dismissed( $user_id ) {
		$d = get_user_meta( $user_id, self::META_DISMISSED, true );
		return is_array( $d ) ? array_values( array_filter( $d, 'is_string' ) ) : array();
	}

	public static function dismiss_url( $version ) {
		return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'v' => rawurlencode( $version ) ), admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $version );
	}

	public static function handle_dismiss() {
		$v = isset( $_GET['v'] ) ? sanitize_text_field( wp_unslash( $_GET['v'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.
		if ( ! self::valid_version( $v ) || ! check_admin_referer( self::ACTION . '_' . $v ) || ! is_user_logged_in() ) {
			wp_die( esc_html__( 'This link has expired. Please go back and try again.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$uid = get_current_user_id();
		$d   = self::dismissed( $uid );
		$d[] = $v;
		update_user_meta( $uid, self::META_DISMISSED, array_slice( array_values( array_unique( $d ) ), -20 ) );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/** One notice per version, on PublishRank screens, the Dashboard and the Plugins screen. */
	public static function notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( ! ( 'dashboard' === $id || 'plugins' === $id || 'update-core' === $id || Branding::is_plugin_screen() ) ) {
			return;
		}
		$update = self::available();
		if ( ! $update || in_array( $update['version'], self::dismissed( get_current_user_id() ), true ) ) {
			return;
		}
		if ( 'plugins' === $id && 'wordpress' === $update['source'] ) {
			return; // WordPress already prints its own update row for this plugin on the Plugins screen.
		}
		$notes = Branding::page_url( WhatsNew::SLUG );
		echo '<div class="notice notice-info publishrank-update-notice" role="status"><p>';
		echo self::live_marker( __( 'Update available', 'publishrank' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in live_marker().
		echo '<strong>' . esc_html( sprintf( /* translators: 1: new version, 2: running version */ __( 'PublishRank %1$s is available. You are running %2$s.', 'publishrank' ), $update['version'], self::current() ) ) . '</strong> ';
		if ( $update['installable'] ) {
			echo esc_html__( 'You can install it from the WordPress updates screen.', 'publishrank' ) . ' ';
			echo '<a class="button button-primary button-small" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Go to updates', 'publishrank' ) . '</a> ';
		} else {
			echo esc_html__( 'It was announced on the PublishRank website and will appear on the WordPress updates screen once it is distributed.', 'publishrank' ) . ' ';
		}
		echo '<a href="' . esc_url( $notes ) . '">' . esc_html__( 'What\'s new', 'publishrank' ) . '</a> · ';
		echo '<a href="' . esc_url( self::dismiss_url( $update['version'] ) ) . '">' . esc_html__( 'Dismiss for this version', 'publishrank' ) . '</a>';
		echo '</p></div>';
	}
}
