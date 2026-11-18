<?php
/**
 * Notice Center — gathers admin notices into one tidy, minimizable tray.
 *
 * Every PublishRank notice (on every admin screen) and every notice from any source on PublishRank's
 * own screens is captured callback by callback, so each one has a stable identity
 * (Class::method). The tray then renders them as compact rows that the user can expand,
 * minimize, or snooze. State is kept per user in user meta; nothing is hidden for other users.
 *
 * Safety rules:
 * - Error notices can be minimized but never snoozed for more than a day, and the tray always
 *   counts them, so a critical message cannot disappear for good.
 * - The original notice markup is kept intact inside the tray, so its own buttons, forms and
 *   "dismiss" links keep working.
 * - If anything fails while capturing, the original output is printed unchanged.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class NoticeCenter {
	const META   = 'publishrank_notice_state';
	const ACTION = 'publishrank_notice_state';
	/** Notices shown minimized until the user opens them (promotional or low-priority). */
	const QUIET = array( 'RatingNotice', 'ClassicEditorNotice', 'ConflictNotice', 'setup_reminder_notice', 'missing_identity_notice' );

	/** @var array<int,array> */
	private $items = array();
	/** @var bool */
	private $active = false;

	public function register() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			if ( wp_doing_ajax() ) {
				add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax' ) );
			}
			return;
		}
		if ( ! (bool) apply_filters( 'publishrank_notice_center_enabled', true ) ) {
			return;
		}
		add_action( 'in_admin_header', array( $this, 'wrap' ), PHP_INT_MAX );
		add_action( 'all_admin_notices', array( $this, 'render' ), PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/** Should this callback be captured? */
	private function owned( $callback, $plugin_screen ) {
		if ( $plugin_screen ) {
			return true;
		}
		$id = self::callback_id( $callback );
		return 0 === strpos( $id, 'PublishRankSEO\\' );
	}

	public static function callback_id( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $class . '::' . (string) $callback[1];
		}
		if ( $callback instanceof \Closure ) {
			try {
				$ref = new \ReflectionFunction( $callback );
				$scope = $ref->getClosureScopeClass();
				return ( $scope ? $scope->getName() . '::' : '' ) . '{closure}@' . basename( (string) $ref->getFileName() ) . ':' . $ref->getStartLine();
			} catch ( \ReflectionException $e ) {
				return 'closure';
			}
		}
		if ( is_object( $callback ) ) {
			return get_class( $callback ) . '::__invoke';
		}
		return 'unknown';
	}

	/** Replace captured callbacks with buffering wrappers just before the notices print. */
	public function wrap() {
		global $wp_filter;
		if ( ! current_user_can( 'read' ) ) {
			return;
		}
		$plugin_screen = Branding::is_plugin_screen();
		foreach ( array( 'admin_notices', 'network_admin_notices', 'user_admin_notices', 'all_admin_notices' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof \WP_Hook ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $key => $entry ) {
					$cb = $entry['function'];
					if ( 'all_admin_notices' === $hook && is_array( $cb ) && $cb[0] === $this ) {
						continue;
					}
					if ( ! $this->owned( $cb, $plugin_screen ) ) {
						continue;
					}
					$id   = self::callback_id( $cb );
					$self = $this;
					$wp_filter[ $hook ]->callbacks[ $priority ][ $key ]['function'] = static function () use ( $cb, $id, $self ) {
						$args = func_get_args();
						ob_start();
						try {
							call_user_func_array( $cb, $args );
						} catch ( \Throwable $e ) {
							$html = (string) ob_get_clean();
							echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- original notice output.
							throw $e;
						}
						$self->collect( $id, (string) ob_get_clean() );
					};
				}
			}
		}
		$this->active = true;
	}

	/** @internal Called by wrappers. */
	public function collect( $id, $html ) {
		if ( '' === trim( wp_strip_all_tags( $html, true ) ) && false === stripos( $html, '<form' ) ) {
			// Notices that print nothing visible (or print a hidden element) are passed through.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		if ( false !== strpos( substr( $html, 0, 500 ), 'data-prn-passthrough' ) ) {
			// Screen tools (not messages) that only use the notices hook for placement stay in the page.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the callback's own escaped output.
			return;
		}
		if ( preg_match( '/<div[^>]*\shidden(\s|>|=)/i', substr( $html, 0, 2000 ) ) && 1 === substr_count( strtolower( $html ), 'class="notice' ) ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- client-side delayed notice keeps its own logic.
			return;
		}
		$this->items[] = array(
			'key'      => substr( md5( $id . '|' . count( $this->items ) ), 0, 12 ),
			'id'       => $id,
			'html'     => $html,
			'severity' => self::severity( $html ),
			'title'    => self::title( $html ),
			'quiet'    => self::is_quiet( $id ),
		);
	}

	private static function is_quiet( $id ) {
		foreach ( self::QUIET as $needle ) {
			if ( false !== strpos( $id, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public static function severity( $html ) {
		$head = strtolower( substr( $html, 0, 600 ) );
		if ( preg_match( '/notice-error|class="[^"]*\berror\b/', $head ) ) {
			return 'error';
		}
		if ( false !== strpos( $head, 'notice-warning' ) || false !== strpos( $head, 'update-nag' ) ) {
			return 'warning';
		}
		if ( false !== strpos( $head, 'notice-success' ) || preg_match( '/class="[^"]*\bupdated\b/', $head ) ) {
			return 'success';
		}
		return 'info';
	}

	public static function title( $html ) {
		if ( preg_match( '#<(strong|b|h2|h3)[^>]*>(.*?)</\1>#is', $html, $m ) ) {
			$t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[2] ) ) );
			$t = rtrim( $t, ':' );
			if ( strlen( $t ) >= 4 ) {
				return wp_html_excerpt( $t, 90, '…' );
			}
		}
		$t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		return wp_html_excerpt( $t, 90, '…' );
	}

	/** Prevent WordPress core from moving captured notices out of the tray. */
	private static function pin( $html ) {
		return preg_replace_callback(
			'/(<div\b[^>]*\bclass=")([^"]*)(")/i',
			static function ( $m ) {
				if ( preg_match( '/\b(notice|updated|error|update-nag)\b/', $m[2] ) && ! preg_match( '/\binline\b/', $m[2] ) ) {
					return $m[1] . $m[2] . ' inline prn-pinned' . $m[3];
				}
				return $m[0];
			},
			$html
		);
	}

	public static function state( $user_id = 0 ) {
		$s = get_user_meta( $user_id ? $user_id : get_current_user_id(), self::META, true );
		$s = is_array( $s ) ? $s : array();
		$s += array( 'tray' => 'open', 'items' => array() );
		return $s;
	}

	public function render() {
		if ( ! $this->active || empty( $this->items ) ) {
			return;
		}
		$state = self::state();
		$now   = time();
		$open  = 0;
		$snoozed_count = 0;
		$errors = 0;
		$rows   = '';
		foreach ( $this->items as $item ) {
			$sk     = md5( $item['id'] );
			$st     = isset( $state['items'][ $sk ] ) && is_array( $state['items'][ $sk ] ) ? $state['items'][ $sk ] : array();
			$min    = isset( $st['m'] ) ? (bool) $st['m'] : $item['quiet'];
			$until  = isset( $st['s'] ) ? (int) $st['s'] : 0;
			if ( 'error' === $item['severity'] ) {
				$until = min( $until, $now + DAY_IN_SECONDS );
				$errors++;
			}
			$snoozed = $until > $now;
			if ( $snoozed ) {
				$snoozed_count++;
			} else {
				$open++;
			}
			$icon = array(
				'error'   => 'fa-circle-exclamation',
				'warning' => 'fa-triangle-exclamation',
				'success' => 'fa-circle-check',
				'info'    => 'fa-circle-question',
			)[ $item['severity'] ];
			$label = array(
				'error'   => __( 'Needs attention', 'publishrank' ),
				'warning' => __( 'Warning', 'publishrank' ),
				'success' => __( 'Done', 'publishrank' ),
				'info'    => __( 'Info', 'publishrank' ),
			)[ $item['severity'] ];
			$body_id = 'prn-body-' . $item['key'];
			$rows   .= '<li class="prn-item prn-' . esc_attr( $item['severity'] ) . ( $min ? ' is-min' : '' ) . '" data-key="' . esc_attr( $sk ) . '" data-severity="' . esc_attr( $item['severity'] ) . '"' . ( $snoozed ? ' hidden data-snoozed="1"' : '' ) . '>';
			$rows   .= '<div class="prn-head">';
			$rows   .= '<button type="button" class="prn-toggle" aria-expanded="' . ( $min ? 'false' : 'true' ) . '" aria-controls="' . esc_attr( $body_id ) . '">';
			$rows   .= '<span class="prn-icon fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
			$rows   .= '<span class="screen-reader-text">' . esc_html( $label ) . ': </span>';
			$rows   .= '<span class="prn-title">' . esc_html( $item['title'] ) . '</span>';
			$rows   .= '<span class="prn-chev fa-solid fa-chevron-down" aria-hidden="true"></span>';
			$rows   .= '</button>';
			$rows   .= '<div class="prn-actions">';
			$rows   .= '<button type="button" class="prn-act" data-act="snooze" title="' . esc_attr( 'error' === $item['severity'] ? __( 'Hide until tomorrow', 'publishrank' ) : __( 'Hide for 7 days', 'publishrank' ) ) . '"><span class="fa-solid fa-bell-slash" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Snooze', 'publishrank' ) . '</span></button>';
			$rows   .= '</div></div>';
			$rows   .= '<div class="prn-body" id="' . esc_attr( $body_id ) . '"' . ( $min ? ' hidden' : '' ) . '>' . self::pin( $item['html'] ) . '</div>';
			$rows   .= '</li>';
		}
		$collapsed = 'closed' === $state['tray'];
		$total     = count( $this->items );
		echo '<div class="prn-tray' . ( $errors ? ' has-errors' : '' ) . '" id="publishrank-notice-center" data-nonce="' . esc_attr( wp_create_nonce( self::ACTION ) ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" role="region" aria-label="' . esc_attr__( 'Notifications', 'publishrank' ) . '">';
		echo '<div class="prn-bar">';
		echo '<button type="button" class="prn-bar__toggle" aria-expanded="' . ( $collapsed ? 'false' : 'true' ) . '" aria-controls="prn-list"><span class="fa-solid fa-bell" aria-hidden="true"></span> ';
		echo '<span class="prn-bar__label">' . esc_html__( 'Notifications', 'publishrank' ) . '</span> <span class="prn-count" data-count>' . esc_html( (string) $open ) . '</span>';
		if ( $errors ) {
			/* translators: %d: number of notices that need attention */
			echo ' <span class="prn-alert">' . esc_html( sprintf( _n( '%d needs attention', '%d need attention', $errors, 'publishrank' ), $errors ) ) . '</span>';
		}
		echo '<span class="prn-chev fa-solid fa-chevron-down" aria-hidden="true"></span></button>';
		echo '<div class="prn-bar__tools">';
		echo '<button type="button" class="prn-link" data-all="min">' . esc_html__( 'Minimize all', 'publishrank' ) . '</button>';
		echo '<button type="button" class="prn-link" data-all="open">' . esc_html__( 'Expand all', 'publishrank' ) . '</button>';
		/* translators: %d: number of snoozed notices */
		echo '<button type="button" class="prn-link" data-all="unsnooze"' . ( $snoozed_count ? '' : ' hidden' ) . '>' . esc_html( sprintf( __( 'Show %d snoozed', 'publishrank' ), $snoozed_count ) ) . '</button>';
		echo '</div></div>';
		echo '<ul class="prn-list" id="prn-list"' . ( $collapsed ? ' hidden' : '' ) . '>' . $rows . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows escaped above; notice bodies are the original notice output.
		echo '<p class="prn-empty"' . ( $open ? ' hidden' : '' ) . '>' . esc_html__( 'All caught up — snoozed notifications come back automatically.', 'publishrank' ) . '</p>';
		echo '</div>';
		$this->items = array();
	}

	public function assets() {
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/notice-center.css';
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/notice-center.js';
		if ( ! file_exists( $css ) || ! file_exists( $js ) ) {
			return;
		}
		wp_enqueue_style( 'publishrank-fa-core', Branding::asset_url( 'vendor/fontawesome-free-7.3.1/css/fontawesome.css' ), array(), '7.3.1' );
		wp_enqueue_style( 'publishrank-fa-solid', Branding::asset_url( 'vendor/fontawesome-free-7.3.1/css/solid.css' ), array( 'publishrank-fa-core' ), '7.3.1' );
		wp_enqueue_style( 'publishrank-notice-center', Branding::asset_url( 'css/notice-center.css' ), array( 'publishrank-fa-solid' ), Branding::version() . '.' . (string) filemtime( $css ) );
		wp_enqueue_script( 'publishrank-notice-center', Branding::asset_url( 'js/notice-center.js' ), array(), Branding::version() . '.' . (string) filemtime( $js ), true );
	}

	public function ajax() {
		check_ajax_referer( self::ACTION, 'nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			wp_send_json_error( null, 403 );
		}
		$user  = get_current_user_id();
		$state = self::state( $user );
		$op    = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$keys  = isset( $_POST['keys'] ) ? array_filter( array_map( 'sanitize_key', explode( ',', (string) wp_unslash( $_POST['keys'] ) ) ) ) : array();
		$keys  = array_slice( array_filter( $keys, static function ( $k ) { return 1 === preg_match( '/^[a-f0-9]{32}$/', $k ); } ), 0, 100 );
		$sev   = isset( $_POST['severity'] ) ? sanitize_key( wp_unslash( $_POST['severity'] ) ) : '';
		switch ( $op ) {
			case 'tray_open':
			case 'tray_closed':
				$state['tray'] = 'tray_open' === $op ? 'open' : 'closed';
				break;
			case 'min':
			case 'open':
				foreach ( $keys as $k ) {
					$state['items'][ $k ]['m'] = 'min' === $op ? 1 : 0;
				}
				break;
			case 'snooze':
				foreach ( $keys as $k ) {
					$state['items'][ $k ]['s'] = time() + ( 'error' === $sev ? DAY_IN_SECONDS : 7 * DAY_IN_SECONDS );
				}
				break;
			case 'unsnooze':
				foreach ( $state['items'] as $k => $v ) {
					unset( $state['items'][ $k ]['s'] );
				}
				break;
			default:
				wp_send_json_error( array( 'message' => 'Unknown operation.' ), 400 );
		}
		// Keep the record small: retain only the 200 most recent notice states.
		if ( count( $state['items'] ) > 200 ) {
			$state['items'] = array_slice( $state['items'], -200, null, true );
		}
		update_user_meta( $user, self::META, $state );
		wp_send_json_success( array( 'ok' => true ) );
	}
}
