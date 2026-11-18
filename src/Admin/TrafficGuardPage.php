<?php
/**
 * Traffic Guard screen: overview, event investigation, rule builder, lists, protection settings and
 * a rule tester. Every state change goes through admin-post with a nonce and the manage_security
 * capability; reads are capability-checked as well.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Analytics\Geo;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Core\Countries;
use PublishRankSEO\Security\CountryResolver;
use PublishRankSEO\Security\TrafficGuard;
use PublishRankSEO\Security\TrafficGuardRules;
use PublishRankSEO\Security\TrafficGuardStore;

defined( 'ABSPATH' ) || exit;

final class TrafficGuardPage {
	const SLUG   = 'publishrank-traffic-guard';
	const ACTION = 'publishrank_traffic_guard_save';
	const CAP    = 'manage_security';

	/** Cloudflare's published edge ranges (https://www.cloudflare.com/ips/), offered as a one-click fill. Check them against the live list. */
	const CLOUDFLARE_RANGES = array( '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32' );

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		foreach ( array( 'save', 'lists', 'rule_save', 'rule_action', 'event_action', 'block_add', 'export', 'clear' ) as $a ) {
			add_action( 'admin_post_publishrank_tg_' . $a, array( $this, 'handle_' . $a ) );
		}
		// 2.25.0 form action name kept so an open old screen still saves.
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Traffic Guard', 'publishrank' ), __( 'Traffic Guard', 'publishrank' ), 'publishrank_manage_security', self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		if ( self::SLUG !== Suite::current_page() ) {
			return;
		}
		Suite::enqueue();
		Charts::enqueue( true );
		CountryPicker::enqueue();
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/traffic-guard.css';
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/traffic-guard.js';
		wp_enqueue_style( 'publishrank-traffic-guard', Branding::asset_url( 'css/traffic-guard.css' ), array( 'publishrank-suite' ), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_enqueue_script( 'publishrank-traffic-guard', Branding::asset_url( 'js/traffic-guard.js' ), array(), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_localize_script(
			'publishrank-traffic-guard',
			'publishrankTG',
			array(
				'fields'     => TrafficGuardRules::fields(),
				'fieldNames' => TrafficGuardRules::field_labels(),
				'opNames'    => TrafficGuardRules::operator_labels(),
				'hints'      => array(
					'ip'         => __( 'e.g. 203.0.113.7, 198.51.100.0/24', 'publishrank' ),
					'country'    => __( 'e.g. PK, USA, DE (alpha-2 or alpha-3)', 'publishrank' ),
					'path'       => __( 'e.g. /checkout/', 'publishrank' ),
					'query'      => __( 'e.g. utm_source=spam', 'publishrank' ),
					'user_agent' => __( 'e.g. python-requests', 'publishrank' ),
					'referer'    => __( 'e.g. spam-site.example', 'publishrank' ),
					'method'     => __( 'e.g. POST', 'publishrank' ),
					'bot'        => __( 'human, search, ai, tool, bot, empty', 'publishrank' ),
				),
				'cloudflare' => self::CLOUDFLARE_RANGES,
				'i18n'       => array(
					'remove'  => __( 'Remove condition', 'publishrank' ),
					'unsaved' => __( 'You have unsaved changes on this screen.', 'publishrank' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ Handlers */

	private static function guard( $action ) {
		Suite::guard( $action, self::CAP );
	}

	private static function post( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller runs guard() first.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	private static function done( $tab, $type, $message, array $args = array() ) {
		Suite::done( self::SLUG, $type, $message, array_merge( array( 'tab' => $tab ), $args ) );
	}

	/** Protection settings (also accepts the 2.25.0 form, which posted enabled/allow_crawlers and the lists). */
	public function handle_save() {
		$action = isset( $_POST['action'] ) && self::ACTION === $_POST['action'] ? self::ACTION : 'publishrank_tg_save'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::guard( $action );
		$s                        = TrafficGuard::settings();
		$s['enabled']             = '1' === self::post( 'enabled' );
		$s['mode']                = 'enforce' === self::post( 'mode', $s['mode'] ) ? 'enforce' : 'monitor';
		$s['level']               = self::post( 'level', $s['level'] );
		$s['allow_crawlers']      = '1' === self::post( 'allow_crawlers' );
		$s['geo_source']          = self::post( 'geo_source', $s['geo_source'] );
		$s['rate_enabled']        = '1' === self::post( 'rate_enabled' );
		$s['rate_requests']       = (int) self::post( 'rate_requests', (string) $s['rate_requests'] );
		$s['rate_window']         = (int) self::post( 'rate_window', (string) $s['rate_window'] );
		$s['rate_action']         = self::post( 'rate_action', $s['rate_action'] );
		$s['rate_block_minutes']  = (int) self::post( 'rate_block_minutes', (string) $s['rate_block_minutes'] );
		$s['reputation_threshold']= (int) self::post( 'reputation_threshold', (string) $s['reputation_threshold'] );
		$s['block_message']       = self::post( 'block_message', $s['block_message'] );
		$s['contact_email']       = self::post( 'contact_email', $s['contact_email'] );
		$s['retention_days']      = (int) self::post( 'retention_days', (string) $s['retention_days'] );
		$s['log_cap_minute']      = (int) self::post( 'log_cap_minute', (string) $s['log_cap_minute'] );
		$proxies                  = TrafficGuard::inspect_networks( self::post( 'trusted_proxies', implode( "\n", $s['trusted_proxies'] ) ) );
		if ( $proxies['invalid'] ) {
			self::done( 'protection', 'error', sprintf( /* translators: %s: invalid entries */ __( 'No changes were saved. These trusted proxy entries are invalid: %s', 'publishrank' ), implode( ', ', array_slice( $proxies['invalid'], 0, 5 ) ) ) );
		}
		$s['trusted_proxies'] = $proxies['valid'];
		if ( self::ACTION === $action ) {
			// Legacy single-form save also carried the lists.
			$this->apply_lists( $s, 'protection' );
		}
		$s = TrafficGuard::save( $s );
		self::done( 'protection', 'success', self::saved_message( $s ) );
	}

	public function handle_lists() {
		self::guard( 'publishrank_tg_lists' );
		$s = TrafficGuard::settings();
		$this->apply_lists( $s, 'lists' );
		$s = TrafficGuard::save( $s );
		self::done( 'lists', self::locks_out_others( $s ) ? 'warning' : 'success', self::saved_message( $s ) );
	}

	private function apply_lists( array &$s, $tab ) {
		$blocked   = TrafficGuard::inspect_networks( self::post( 'blocked_ips' ) );
		$allowed   = TrafficGuard::inspect_networks( self::post( 'allow_ips' ) );
		$countries = Countries::parse_list( self::post( 'blocked_countries' ), true );
		$allow_cc  = Countries::parse_list( self::post( 'allowed_countries' ) );
		$invalid   = array_merge( $blocked['invalid'], $allowed['invalid'], $countries['invalid'], $allow_cc['invalid'] );
		if ( $invalid ) {
			self::done( $tab, 'error', sprintf( /* translators: %s: invalid IP, CIDR or country entries. */ __( 'No changes were saved. Check these invalid or excess entries: %s', 'publishrank' ), implode( ', ', array_slice( array_unique( $invalid ), 0, 5 ) ) ) );
		}
		$s['blocked_ips']       = $blocked['valid'];
		$s['allow_ips']         = $allowed['valid'];
		$s['blocked_countries'] = $countries['valid'];
		$s['allowed_countries'] = $allow_cc['valid'];
		if ( isset( $_POST['country_mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$s['country_mode']    = 'allow_only' === self::post( 'country_mode' ) ? 'allow_only' : 'block';
			$s['unknown_country'] = 'block' === self::post( 'unknown_country' ) ? 'block' : 'allow';
		}
	}

	/** Would the current administrator's own network be blocked for other visitors? */
	private static function locks_out_others( array $s ) {
		$ctx = TrafficGuard::request_context( $s );
		if ( '' === $ctx['ip'] || ! $s['enabled'] ) {
			return false;
		}
		$ctx['bot'] = 'human';
		$r          = TrafficGuard::evaluate( $ctx, $s, array( 'simulate' => true ) );
		return in_array( $r['action'], array( 'block', 'temp_block' ), true );
	}

	private static function saved_message( array $s ) {
		$msg = __( 'Traffic Guard settings saved.', 'publishrank' );
		if ( self::locks_out_others( $s ) ) {
			$msg .= ' ' . __( 'Notice: your own address or country matches a block rule. You are exempt while signed in, but other visitors from your network would be blocked; add your IP to the allow list if that is unintended.', 'publishrank' );
		}
		if ( $s['enabled'] && 'monitor' === $s['mode'] ) {
			$msg .= ' ' . __( 'Monitor mode is on: requests are only recorded, nothing is blocked.', 'publishrank' );
		}
		return $msg;
	}

	public function handle_rule_save() {
		self::guard( 'publishrank_tg_rule_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$raw = isset( $_POST['rule'] ) && is_array( $_POST['rule'] ) ? wp_unslash( $_POST['rule'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitised in TrafficGuardRules::sanitize().
		// phpcs:enable
		$s        = TrafficGuard::settings();
		$existing = isset( $raw['id'] ) && isset( $s['rules'][ (string) $raw['id'] ] ) ? $s['rules'][ (string) $raw['id'] ] : null;
		if ( $existing ) {
			$raw['created'] = $existing['created'];
		} else {
			unset( $raw['id'] );
			if ( count( $s['rules'] ) >= TrafficGuard::MAX_CUSTOM_RULES ) {
				self::done( 'rules', 'error', __( 'The rule limit has been reached. Delete a rule before adding another.', 'publishrank' ) );
			}
		}
		$raw['enabled'] = ! empty( $raw['enabled'] );
		$r              = TrafficGuardRules::sanitize( is_array( $raw ) ? $raw : array() );
		if ( $r['errors'] ) {
			set_transient( 'publishrank_tg_rule_draft_' . get_current_user_id(), $raw, 10 * MINUTE_IN_SECONDS );
			self::done( 'rules', 'error', __( 'The rule was not saved:', 'publishrank' ) . ' ' . implode( ' ', $r['errors'] ), array( 'edit' => $existing ? $existing['id'] : 'new' ) );
		}
		$s['rules'][ $r['rule']['id'] ] = $r['rule'];
		TrafficGuard::save( $s );
		delete_transient( 'publishrank_tg_rule_draft_' . get_current_user_id() );
		$msg = 'test' === $r['rule']['mode'] ? __( 'Rule saved in test mode. It records matching requests without acting on them; switch it to Enforce when the results look right.', 'publishrank' ) : __( 'Rule saved and enforcing.', 'publishrank' );
		self::done( 'rules', 'success', $msg );
	}

	public function handle_rule_action() {
		$id = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard().
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::guard( 'publishrank_tg_rule_' . $do . '_' . $id );
		$s = TrafficGuard::settings();
		if ( ! isset( $s['rules'][ $id ] ) ) {
			self::done( 'rules', 'error', __( 'That rule no longer exists.', 'publishrank' ) );
		}
		switch ( $do ) {
			case 'delete':
				unset( $s['rules'][ $id ] );
				$msg = __( 'Rule deleted.', 'publishrank' );
				break;
			case 'toggle':
				$s['rules'][ $id ]['enabled'] = empty( $s['rules'][ $id ]['enabled'] );
				$msg = $s['rules'][ $id ]['enabled'] ? __( 'Rule switched on.', 'publishrank' ) : __( 'Rule switched off.', 'publishrank' );
				break;
			case 'enforce':
				$s['rules'][ $id ]['mode'] = 'enforce';
				$msg = __( 'Rule is now enforcing.', 'publishrank' );
				break;
			case 'test':
				$s['rules'][ $id ]['mode'] = 'test';
				$msg = __( 'Rule is back in test mode.', 'publishrank' );
				break;
			case 'duplicate':
				$copy            = $s['rules'][ $id ];
				unset( $copy['id'] );
				$copy['name']    = sprintf( /* translators: %s: rule name */ __( '%s (copy)', 'publishrank' ), $copy['name'] );
				$copy['mode']    = 'test';
				$copy['created'] = time();
				$r               = TrafficGuardRules::sanitize( $copy );
				$s['rules'][ $r['rule']['id'] ] = $r['rule'];
				$msg = __( 'Rule duplicated in test mode.', 'publishrank' );
				break;
			default:
				self::done( 'rules', 'error', __( 'Unknown action.', 'publishrank' ) );
		}
		TrafficGuard::save( $s );
		self::done( 'rules', 'success', $msg );
	}

	public function handle_event_action() {
		$id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard().
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::guard( 'publishrank_tg_event_' . $do . '_' . $id );
		$event = TrafficGuardStore::get( $id );
		if ( ! $event ) {
			self::done( 'events', 'error', __( 'That event no longer exists.', 'publishrank' ) );
		}
		$s = TrafficGuard::settings();
		switch ( $do ) {
			case 'false_positive':
				TrafficGuardStore::mark_false_positive( $id );
				if ( '' !== $event['ip'] && ! TrafficGuard::ip_matches_list( $event['ip'], $s['allow_ips'] ) ) {
					$s['allow_ips'][] = $event['ip'];
					TrafficGuard::save( $s );
				}
				TrafficGuardStore::unblock_ip( $event['ip'] );
				$msg = sprintf( /* translators: %s: IP */ __( 'Marked as a false positive and %s was added to the allow list. Review the rule that matched so it does not catch other legitimate visitors.', 'publishrank' ), $event['ip'] );
				break;
			case 'block_day':
				TrafficGuardStore::block_ip( $event['ip'], 'manual', DAY_IN_SECONDS );
				$msg = sprintf( /* translators: %s: IP */ __( '%s is blocked for 24 hours.', 'publishrank' ), $event['ip'] );
				break;
			case 'allow':
				if ( ! TrafficGuard::ip_matches_list( $event['ip'], $s['allow_ips'] ) ) {
					$s['allow_ips'][] = $event['ip'];
					TrafficGuard::save( $s );
				}
				TrafficGuardStore::unblock_ip( $event['ip'] );
				$msg = sprintf( /* translators: %s: IP */ __( '%s was added to the allow list.', 'publishrank' ), $event['ip'] );
				break;
			default:
				self::done( 'events', 'error', __( 'Unknown action.', 'publishrank' ) );
		}
		self::done( 'events', 'success', $msg );
	}

	public function handle_block_add() {
		self::guard( 'publishrank_tg_block_add' );
		$unblock = self::post( 'unblock' );
		if ( '' !== $unblock ) {
			TrafficGuardStore::unblock_ip( $unblock );
			TrafficGuardStore::purge_expired_blocks();
			self::done( 'lists', 'success', sprintf( /* translators: %s: IP */ __( 'Temporary block on %s removed.', 'publishrank' ), $unblock ) );
		}
		$ip      = TrafficGuard::valid_ip( self::post( 'ip' ) );
		$minutes = max( 1, min( 10080, (int) self::post( 'minutes', '60' ) ) );
		if ( '' === $ip ) {
			self::done( 'lists', 'error', __( 'Enter a single valid IPv4 or IPv6 address.', 'publishrank' ) );
		}
		$own = TrafficGuard::current_client_ip( TrafficGuard::settings()['trusted_proxies'] );
		TrafficGuardStore::block_ip( $ip, 'manual', $minutes * MINUTE_IN_SECONDS );
		self::done( 'lists', $ip === $own ? 'warning' : 'success', sprintf( /* translators: 1: IP, 2: minutes */ __( '%1$s is blocked for %2$d minutes.', 'publishrank' ), $ip, $minutes ) . ( $ip === $own ? ' ' . __( 'This is your own address; you stay exempt while signed in.', 'publishrank' ) : '' ) );
	}

	public function handle_export() {
		self::guard( 'publishrank_tg_export' );
		$f             = self::filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$f['per_page'] = 5000;
		$f['paged']    = 1;
		$data          = TrafficGuardStore::query( $f );
		$rows          = array();
		foreach ( $data['rows'] as $r ) {
			$rows[] = array( $r['created_at'] . ' UTC', $r['decision'], TrafficGuard::reason_label( $r['reason'] ), $r['rule_id'], $r['ip'], $r['country'], Countries::alpha3( $r['country'] ), $r['method'], $r['path'], $r['user_agent'], $r['bot'], $r['reference'], $r['false_positive'] ? 'yes' : 'no' );
		}
		Suite::csv( 'traffic-guard-events-' . gmdate( 'Y-m-d' ) . '.csv', array( 'time', 'decision', 'reason', 'rule', 'ip', 'country', 'country_alpha3', 'method', 'path', 'user_agent', 'visitor_type', 'reference', 'false_positive' ), $rows );
	}

	public function handle_clear() {
		self::guard( 'publishrank_tg_clear' );
		if ( 'CLEAR' !== strtoupper( trim( self::post( 'confirm' ) ) ) ) {
			self::done( 'events', 'error', __( 'Type CLEAR to confirm deleting the event log.', 'publishrank' ) );
		}
		TrafficGuardStore::clear_events();
		self::done( 'events', 'success', __( 'The event log was cleared.', 'publishrank' ) );
	}

	/** Event filters from a request array. */
	private static function filters( array $src ) {
		$get = static function ( $k ) use ( $src ) {
			return isset( $src[ $k ] ) && is_string( $src[ $k ] ) ? sanitize_text_field( wp_unslash( $src[ $k ] ) ) : '';
		};
		return array(
			'decision' => sanitize_key( $get( 'decision' ) ),
			'reason'   => sanitize_key( $get( 'reason' ) ),
			'rule'     => sanitize_key( $get( 'rule' ) ),
			'country'  => Countries::sanitize( $get( 'country' ) ),
			'search'   => $get( 's' ),
			'from'     => $get( 'from' ),
			'to'       => $get( 'to' ),
			'fp'       => '1' === $get( 'fp' ),
			'orderby'  => sanitize_key( $get( 'orderby' ) ),
			'order'    => sanitize_key( $get( 'order' ) ),
			'paged'    => max( 1, absint( $get( 'paged' ) ) ),
			'per_page' => in_array( (int) $get( 'per_page' ), array( 25, 50, 100 ), true ) ? (int) $get( 'per_page' ) : 25,
		);
	}

	/* ------------------------------------------------------------------ Rendering */

	public function render() {
		if ( ! Capabilities::can( self::CAP ) ) {
			return;
		}
		$s    = TrafficGuard::settings();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'overview'   => array( __( 'Overview', 'publishrank' ), 'fa-chart-line' ),
			'events'     => array( __( 'Events', 'publishrank' ), 'fa-list-ul' ),
			'rules'      => array( __( 'Rules', 'publishrank' ), 'fa-sliders' ),
			'lists'      => array( __( 'IPs & countries', 'publishrank' ), 'fa-earth-americas' ),
			'protection' => array( __( 'Protection', 'publishrank' ), 'fa-shield-halved' ),
			'test'       => array( __( 'Test a request', 'publishrank' ), 'fa-flask' ),
		);
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'overview';

		Suite::open( __( 'Traffic Guard', 'publishrank' ), __( 'Measure, test and control front-end traffic: IP and country rules, custom rules, rate limiting and suspicious-request detection.', 'publishrank' ) );
		echo '<div class="prtg2">';
		$this->status_bar( $s );
		echo '<nav class="prtg2-tabs" aria-label="' . esc_attr__( 'Traffic Guard sections', 'publishrank' ) . '">';
		foreach ( $tabs as $key => $t ) {
			echo '<a class="prtg2-tab' . ( $key === $tab ? ' is-current' : '' ) . '" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => $key ) ) ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . '><span class="fa-solid ' . esc_attr( $t[1] ) . '" aria-hidden="true"></span>' . esc_html( $t[0] ) . '</a>';
		}
		echo '</nav>';
		echo '<div class="prtg2-body">';
		call_user_func( array( $this, 'tab_' . $tab ), $s );
		echo '</div></div>';
		Suite::close();
	}

	private function status_bar( array $s ) {
		if ( ! $s['enabled'] ) {
			$tone = 'off';
			$text = __( 'Traffic Guard is off', 'publishrank' );
			$sub  = __( 'Nothing is recorded or blocked. Turn it on under Protection — start in Monitor mode to see what would be blocked first.', 'publishrank' );
		} elseif ( 'monitor' === $s['mode'] ) {
			$tone = 'monitor';
			$text = __( 'Monitoring — nothing is blocked', 'publishrank' );
			$sub  = __( 'Requests that rules would block are recorded as “would block”. Switch to Enforce under Protection when the results look right.', 'publishrank' );
		} else {
			$tone = 'on';
			$text = __( 'Enforcing', 'publishrank' );
			$sub  = __( 'Matching requests receive a 403 page with a reference code (429 for rate limits).', 'publishrank' );
		}
		$levels = self::level_labels();
		$facts  = array(
			sprintf( /* translators: %s: level */ __( 'Protection level: %s', 'publishrank' ), $levels[ $s['level'] ] ),
			sprintf( /* translators: %d: count */ _n( '%d custom rule', '%d custom rules', count( $s['rules'] ), 'publishrank' ), count( $s['rules'] ) ),
			sprintf( /* translators: %d: count */ _n( '%d blocked country', '%d blocked countries', count( $s['blocked_countries'] ), 'publishrank' ), count( $s['blocked_countries'] ) ),
			$s['rate_enabled'] ? sprintf( /* translators: 1: requests, 2: seconds */ __( 'Rate limit: %1$d per %2$ds', 'publishrank' ), $s['rate_requests'], $s['rate_window'] ) : __( 'Rate limit: off', 'publishrank' ),
			$s['allow_crawlers'] ? __( 'Verified crawlers always allowed', 'publishrank' ) : __( 'Crawler protection off', 'publishrank' ),
		);
		echo '<div class="prtg2-status prtg2-status--' . esc_attr( $tone ) . '" role="status"><span class="prtg2-status__dot" aria-hidden="true"></span><div><strong>' . esc_html( $text ) . '</strong><p>' . esc_html( $sub ) . '</p><ul class="prtg2-facts">';
		foreach ( $facts as $f ) {
			echo '<li>' . esc_html( $f ) . '</li>';
		}
		echo '</ul></div><a class="prx-btn prx-btn--sm" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'protection' ) ) ) . '">' . esc_html__( 'Change', 'publishrank' ) . '</a></div>';
	}

	private static function level_labels() {
		return array(
			'off'      => __( 'Off', 'publishrank' ),
			'low'      => __( 'Low — log probes only', 'publishrank' ),
			'balanced' => __( 'Balanced — block probes, injections, scanners', 'publishrank' ),
			'strict'   => __( 'Strict — also block scripts and empty user agents', 'publishrank' ),
		);
	}

	private static function decision_labels() {
		return array(
			'blocked'      => __( 'Blocked', 'publishrank' ),
			'would_block'  => __( 'Would block', 'publishrank' ),
			'rate_limited' => __( 'Rate limited', 'publishrank' ),
			'logged'       => __( 'Logged', 'publishrank' ),
			'test_match'   => __( 'Test rule match', 'publishrank' ),
		);
	}

	private static function decision_tone( $d ) {
		$t = array( 'blocked' => 'bad', 'would_block' => 'warn', 'rate_limited' => 'warn', 'logged' => 'info', 'test_match' => 'muted' );
		return isset( $t[ $d ] ) ? $t[ $d ] : 'muted';
	}

	/* ---------- Overview */

	private function tab_overview( array $s ) {
		$days  = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 7; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days  = in_array( $days, array( 1, 7, 30, 90 ), true ) ? $days : 7;
		$st    = TrafficGuardStore::stats( $days );
		$tot   = static function ( $arr, $keys ) {
			$n = 0;
			foreach ( (array) $keys as $k ) {
				$n += isset( $arr[ $k ] ) ? (int) $arr[ $k ] : 0;
			}
			return $n;
		};
		echo '<div class="prtg2-toolbar"><div class="prc-seg" role="group" aria-label="' . esc_attr__( 'Period', 'publishrank' ) . '">';
		foreach ( array( 1 => __( '24 hours', 'publishrank' ), 7 => __( '7 days', 'publishrank' ), 30 => __( '30 days', 'publishrank' ), 90 => __( '90 days', 'publishrank' ) ) as $d => $label ) {
			echo '<a class="prtg2-seg' . ( $d === $days ? ' is-current' : '' ) . '" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'overview', 'days' => $d ) ) ) . '"' . ( $d === $days ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</div><span class="prx-help">' . esc_html__( 'Measured by Traffic Guard on this site. Only requests that matched a rule are recorded; allowed visits are not stored.', 'publishrank' ) . '</span></div>';

		$anomaly = TrafficGuardStore::anomaly( $st['hourly'] );
		if ( $anomaly['anomaly'] ) {
			echo '<div class="prx-callout prx-callout--warn" role="alert"><span class="fa-solid fa-wave-square" aria-hidden="true"></span><div><strong>' . esc_html__( 'Unusual activity this hour', 'publishrank' ) . '</strong><p>' . esc_html( sprintf( /* translators: 1: events now, 2: baseline */ __( '%1$d events so far this hour, against an average of %2$s per hour over the last 7 days. Check the Events tab for the IPs and paths involved.', 'publishrank' ), $anomaly['now'], number_format_i18n( $anomaly['baseline'], 1 ) ) ) . '</p></div></div>';
		}
		$dropped = TrafficGuardStore::dropped_today();
		if ( $dropped ) {
			echo '<div class="prx-callout prx-callout--info"><span class="fa-solid fa-circle-question" aria-hidden="true"></span><div><p>' . esc_html( sprintf( /* translators: %d: number */ __( '%d events today were counted but not written because the per-minute logging cap was reached. Enforcement was not affected.', 'publishrank' ), $dropped ) ) . '</p></div></div>';
		}

		$labels = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$labels[] = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		}
		$series = array();
		foreach ( array( 'blocked' => 'plum', 'would_block' => 'brass', 'rate_limited' => 'coral', 'logged' => 'slate' ) as $dec => $tone ) {
			$vals = array();
			foreach ( $labels as $d ) {
				$vals[] = isset( $st['daily'][ $d ][ $dec ] ) ? (int) $st['daily'][ $d ][ $dec ] : 0;
			}
			$series[] = array( 'name' => self::decision_labels()[ $dec ], 'tone' => $tone, 'values' => $vals );
		}
		$spark = static function ( $dec ) use ( $series ) {
			foreach ( $series as $x ) {
				if ( $x['name'] === self::decision_labels()[ $dec ] ) {
					return $x['values'];
				}
			}
			return array();
		};
		echo '<div class="prc-kpis prc-kpis--four">';
		Charts::kpi( __( 'Blocked', 'publishrank' ), number_format_i18n( $tot( $st['totals'], 'blocked' ) ), Charts::delta( $tot( $st['totals'], 'blocked' ), $tot( $st['previous'], 'blocked' ), true ), 'fa-ban', 'plum', $spark( 'blocked' ) );
		Charts::kpi( __( 'Would block (monitor & test)', 'publishrank' ), number_format_i18n( $tot( $st['totals'], array( 'would_block', 'test_match' ) ) ), Charts::delta( $tot( $st['totals'], array( 'would_block', 'test_match' ) ), $tot( $st['previous'], array( 'would_block', 'test_match' ) ), true ), 'fa-eye', 'brass', $spark( 'would_block' ) );
		Charts::kpi( __( 'Rate limited', 'publishrank' ), number_format_i18n( $tot( $st['totals'], 'rate_limited' ) ), Charts::delta( $tot( $st['totals'], 'rate_limited' ), $tot( $st['previous'], 'rate_limited' ), true ), 'fa-gauge-high', 'coral', $spark( 'rate_limited' ) );
		Charts::kpi( __( 'Suspicious (logged)', 'publishrank' ), number_format_i18n( $tot( $st['totals'], 'logged' ) ), Charts::delta( $tot( $st['totals'], 'logged' ), $tot( $st['previous'], 'logged' ), true ), 'fa-user-secret', 'slate', $spark( 'logged' ) );
		echo '</div>';

		$all = array_sum( array_map( 'intval', $st['totals'] ) );
		if ( ! $all ) {
			echo '<div class="prtg2-empty"><span class="fa-solid fa-shield-halved" aria-hidden="true"></span><h2>' . esc_html__( 'No events in this period', 'publishrank' ) . '</h2><p>' . esc_html( $s['enabled'] ? __( 'Traffic Guard is running and nothing has matched a rule yet. That is normal for a site without active attacks.', 'publishrank' ) : __( 'Traffic Guard is off. Turn it on under Protection to start measuring.', 'publishrank' ) ) . '</p><a class="prx-btn prx-btn--primary" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => $s['enabled'] ? 'test' : 'protection' ) ) ) . '">' . esc_html( $s['enabled'] ? __( 'Test a request', 'publishrank' ) : __( 'Set up protection', 'publishrank' ) ) . '</a></div>';
			return;
		}

		echo '<div class="prc-grid prc-grid--main">';
		Charts::card_open( __( 'Activity timeline', 'publishrank' ), 'fa-chart-area', __( 'Events per day by decision', 'publishrank' ) );
		echo Charts::line( $labels, $series, $days > 14, 280 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Charts::line().
		Charts::card_close();
		Charts::card_open( __( 'Why requests matched', 'publishrank' ), 'fa-filter' );
		$rows = array();
		foreach ( $st['reasons'] as $reason => $n ) {
			$rows[] = array( 'label' => TrafficGuard::reason_label( $reason ), 'value' => (int) $n, 'url' => Suite::url( self::SLUG, array( 'tab' => 'events', 'reason' => $reason ) ) );
		}
		echo Charts::bars( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Charts::card_close();
		echo '</div>';

		echo '<div class="prc-grid prc-grid--two">';
		Charts::card_open( __( 'Countries', 'publishrank' ), 'fa-earth-europe', __( 'Blocked and would-block requests by country (trusted sources only)', 'publishrank' ) );
		echo Charts::world( array_map( 'intval', $st['countries'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$rows = array();
		foreach ( array_slice( $st['countries'], 0, 8, true ) as $cc => $n ) {
			$rows[] = array( 'label' => Countries::name( $cc ) . ' (' . $cc . ')', 'icon' => Geo::flag( $cc ), 'value' => (int) $n, 'url' => Suite::url( self::SLUG, array( 'tab' => 'events', 'country' => $cc ) ) );
		}
		echo Charts::bars( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Charts::card_close();
		Charts::card_open( __( 'Most active IP addresses', 'publishrank' ), 'fa-network-wired', __( 'Request frequency by address in this period', 'publishrank' ) );
		if ( $st['ips'] ) {
			echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th scope="col">' . esc_html__( 'IP address', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Country', 'publishrank' ) . '</th><th scope="col" class="prx-num">' . esc_html__( 'Events', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Last seen', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $st['ips'] as $r ) {
				echo '<tr><td><a class="prx-mono" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'events', 's' => $r['ip'] ) ) ) . '">' . esc_html( $r['ip'] ) . '</a></td><td>' . esc_html( '' !== $r['country'] ? Geo::flag( $r['country'] ) . ' ' . $r['country'] : '—' ) . '</td><td class="prx-num">' . esc_html( number_format_i18n( (int) $r['n'] ) ) . '</td><td>' . esc_html( Suite::ago( $r['last_at'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		Charts::card_close();
		echo '</div>';

		echo '<div class="prc-grid prc-grid--two">';
		Charts::card_open( __( 'Visitor types', 'publishrank' ), 'fa-robot', __( 'Classified from the user agent; search engines are verified by DNS before any exemption', 'publishrank' ) );
		$seg   = array();
		$tones = array( 'plum', 'teal', 'brass', 'slate', 'coral', 'sage' );
		$i     = 0;
		foreach ( $st['bots'] as $bot => $n ) {
			$seg[] = array( 'label' => TrafficGuard::bot_labels()[ $bot ] ?? $bot, 'value' => (int) $n, 'color' => $tones[ $i++ % count( $tones ) ] );
		}
		echo Charts::donut( $seg, number_format_i18n( $all ), __( 'events', 'publishrank' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Charts::card_close();
		Charts::card_open( __( 'Quality checks', 'publishrank' ), 'fa-circle-check' );
		$checks = array(
			array( $s['allow_crawlers'], __( 'Verified search-engine crawlers are never blocked.', 'publishrank' ), __( 'Crawler protection is off — country or IP rules can block Googlebot and Bingbot.', 'publishrank' ) ),
			array( 'block' !== $s['unknown_country'] || 'allow_only' !== $s['country_mode'], __( 'Visitors with an unknown location are allowed (safe fallback).', 'publishrank' ), __( 'Visitors with an unknown location are blocked. Without a trusted country source, that blocks everyone.', 'publishrank' ) ),
			array( 0 === (int) $st['fp'], __( 'No false positives were reported in this period.', 'publishrank' ), sprintf( /* translators: %d: count */ __( '%d false positives were reported — review the rules involved.', 'publishrank' ), (int) $st['fp'] ) ),
			array( ! ( $s['rate_enabled'] && ! wp_using_ext_object_cache() ), __( 'Rate limiting cost is under control.', 'publishrank' ), __( 'Rate limiting is on without a persistent object cache: each anonymous page view costs a small database write.', 'publishrank' ) ),
		);
		echo '<ul class="prtg2-checks">';
		foreach ( $checks as $c ) {
			echo '<li class="' . ( $c[0] ? 'is-ok' : 'is-warn' ) . '"><span class="fa-solid ' . ( $c[0] ? 'fa-circle-check' : 'fa-triangle-exclamation' ) . '" aria-hidden="true"></span>' . esc_html( $c[0] ? $c[1] : $c[2] ) . '</li>';
		}
		echo '</ul>';
		Charts::card_close();
		echo '</div>';
	}

	/* ---------- Events */

	private function tab_events( array $s ) {
		$f    = self::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$data = TrafficGuardStore::query( $f );
		$base = array_filter( array( 'tab' => 'events', 'decision' => $f['decision'], 'reason' => $f['reason'], 'rule' => $f['rule'], 'country' => $f['country'], 's' => $f['search'], 'from' => $f['from'], 'to' => $f['to'], 'fp' => $f['fp'] ? '1' : '', 'per_page' => 25 !== $f['per_page'] ? $f['per_page'] : '' ) );

		echo '<form class="prtg2-filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" role="search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="events" />';
		echo '<div class="prx-field"><label for="prtg-s">' . esc_html__( 'Search', 'publishrank' ) . '</label><input class="prx-input" type="search" id="prtg-s" name="s" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'IP, path, user agent or reference', 'publishrank' ) . '" /></div>';
		echo '<div class="prx-field"><label for="prtg-d">' . esc_html__( 'Decision', 'publishrank' ) . '</label><select id="prtg-d" name="decision"><option value="">' . esc_html__( 'All', 'publishrank' ) . '</option>';
		foreach ( self::decision_labels() as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['decision'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="prx-field"><label for="prtg-c">' . esc_html__( 'Country', 'publishrank' ) . '</label><select id="prtg-c" name="country"><option value="">' . esc_html__( 'All', 'publishrank' ) . '</option>';
		foreach ( Countries::records() as $r ) {
			echo '<option value="' . esc_attr( $r['code'] ) . '"' . selected( $f['country'], $r['code'], false ) . '>' . esc_html( $r['name'] . ' (' . $r['code'] . ')' ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="prx-field"><label for="prtg-from">' . esc_html__( 'From', 'publishrank' ) . '</label><input class="prx-input" type="date" id="prtg-from" name="from" value="' . esc_attr( $f['from'] ) . '" /></div>';
		echo '<div class="prx-field"><label for="prtg-to">' . esc_html__( 'To', 'publishrank' ) . '</label><input class="prx-input" type="date" id="prtg-to" name="to" value="' . esc_attr( $f['to'] ) . '" /></div>';
		echo '<label class="prtg2-check"><input type="checkbox" name="fp" value="1"' . checked( $f['fp'], true, false ) . ' /> ' . esc_html__( 'False positives only', 'publishrank' ) . '</label>';
		if ( $f['reason'] ) {
			echo '<input type="hidden" name="reason" value="' . esc_attr( $f['reason'] ) . '" />';
		}
		if ( $f['rule'] ) {
			echo '<input type="hidden" name="rule" value="' . esc_attr( $f['rule'] ) . '" />';
		}
		echo '<div class="prtg2-filters__actions"><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Apply filters', 'publishrank' ) . '</button><a class="prx-btn prx-btn--ghost" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'events' ) ) ) . '">' . esc_html__( 'Reset', 'publishrank' ) . '</a></div>';
		echo '</form>';
		if ( $f['reason'] || $f['rule'] ) {
			echo '<p class="prx-help">' . esc_html( sprintf( /* translators: %s: filter */ __( 'Filtered by %s.', 'publishrank' ), $f['rule'] ? __( 'rule', 'publishrank' ) . ' ' . ( $s['rules'][ $f['rule'] ]['name'] ?? $f['rule'] ) : TrafficGuard::reason_label( $f['reason'] ) ) ) . '</p>';
		}

		echo '<div class="prtg2-toolbar"><strong>' . esc_html( sprintf( /* translators: %s: number */ _n( '%s event', '%s events', $data['total'], 'publishrank' ), number_format_i18n( $data['total'] ) ) ) . '</strong>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="prx-inline"><input type="hidden" name="action" value="publishrank_tg_export" />';
		wp_nonce_field( 'publishrank_tg_export' );
		foreach ( array( 'decision' => $f['decision'], 'reason' => $f['reason'], 'rule' => $f['rule'], 'country' => $f['country'], 's' => $f['search'], 'from' => $f['from'], 'to' => $f['to'], 'fp' => $f['fp'] ? '1' : '' ) as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<button class="prx-btn prx-btn--sm" type="submit"' . ( $data['total'] ? '' : ' disabled' ) . '><span class="fa-solid fa-file-csv" aria-hidden="true"></span>' . esc_html__( 'Export CSV', 'publishrank' ) . '</button></form></div>';

		if ( ! $data['rows'] ) {
			echo '<div class="prtg2-empty"><span class="fa-solid fa-magnifying-glass" aria-hidden="true"></span><h2>' . esc_html__( 'No matching events', 'publishrank' ) . '</h2><p>' . esc_html__( 'Try a wider date range or remove a filter.', 'publishrank' ) . '</p></div>';
			return;
		}
		$sort = static function ( $col, $label ) use ( $f, $base ) {
			$is   = $f['orderby'] === $col || ( '' === $f['orderby'] && 'created_at' === $col );
			$next = $is && 'asc' !== $f['order'] ? 'asc' : 'desc';
			$aria = $is ? ( 'asc' === $f['order'] ? 'ascending' : 'descending' ) : 'none';
			return '<th scope="col" aria-sort="' . esc_attr( $aria ) . '"><a href="' . esc_url( Suite::url( self::SLUG, array_merge( $base, array( 'orderby' => $col, 'order' => $next ) ) ) ) . '">' . esc_html( $label ) . ( $is ? ' <span aria-hidden="true">' . ( 'asc' === $f['order'] ? '▲' : '▼' ) . '</span>' : '' ) . '</a></th>';
		};
		echo '<div class="prx-table-wrap"><table class="prx-table prtg2-events"><caption class="screen-reader-text">' . esc_html__( 'Traffic Guard events', 'publishrank' ) . '</caption><thead><tr>';
		echo $sort( 'created_at', __( 'Time (UTC)', 'publishrank' ) ) . $sort( 'decision', __( 'Decision', 'publishrank' ) ) . $sort( 'reason', __( 'Reason', 'publishrank' ) ) . $sort( 'ip', __( 'IP', 'publishrank' ) ) . $sort( 'country', __( 'Country', 'publishrank' ) ) . $sort( 'path', __( 'Request', 'publishrank' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping above.
		echo '<th scope="col">' . esc_html__( 'Actions', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $data['rows'] as $r ) {
			$rule = '' !== $r['rule_id'] && isset( $s['rules'][ $r['rule_id'] ] ) ? $s['rules'][ $r['rule_id'] ]['name'] : '';
			echo '<tr' . ( $r['false_positive'] ? ' class="is-fp"' : '' ) . '>';
			echo '<td class="prx-nowrap"><span title="' . esc_attr( $r['created_at'] ) . '">' . esc_html( mysql2date( 'M j, H:i:s', $r['created_at'] ) ) . '</span><br /><span class="prx-help prx-mono">' . esc_html( $r['reference'] ) . '</span></td>';
			echo '<td>' . Suite::badge( self::decision_labels()[ $r['decision'] ] ?? $r['decision'], self::decision_tone( $r['decision'] ) ) . ( $r['false_positive'] ? ' ' . Suite::badge( __( 'False positive', 'publishrank' ), 'muted' ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( TrafficGuard::reason_label( $r['reason'] ) ) . ( '' !== $rule ? '<br /><span class="prx-help">' . esc_html( $rule ) . '</span>' : '' ) . '</td>';
			echo '<td class="prtg2-ip"><a class="prx-mono" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'events', 's' => $r['ip'] ) ) ) . '">' . esc_html( $r['ip'] ) . '</a><br /><span class="prx-help">' . esc_html( TrafficGuard::bot_labels()[ $r['bot'] ] ?? $r['bot'] ) . '</span></td>';
			echo '<td>' . esc_html( '' !== $r['country'] ? Geo::flag( $r['country'] ) . ' ' . Countries::name( $r['country'] ) : __( 'Unknown', 'publishrank' ) ) . '</td>';
			echo '<td class="prtg2-req"><span class="prx-mono">' . esc_html( $r['method'] . ' ' . $r['path'] ) . '</span><br /><span class="prx-help prx-truncate" title="' . esc_attr( $r['user_agent'] ) . '">' . esc_html( '' !== $r['user_agent'] ? $r['user_agent'] : __( '(no user agent)', 'publishrank' ) ) . '</span></td>';
			echo '<td class="prtg2-actions">';
			if ( ! $r['false_positive'] ) {
				echo '<a class="prx-btn prx-btn--sm prx-btn--ghost" href="' . esc_url( self::event_url( (int) $r['id'], 'false_positive' ) ) . '" title="' . esc_attr__( 'Mark as a false positive and allow this IP', 'publishrank' ) . '">' . esc_html__( 'False positive', 'publishrank' ) . '</a>';
			}
			echo '<a class="prx-btn prx-btn--sm prx-btn--ghost" href="' . esc_url( self::event_url( (int) $r['id'], 'block_day' ) ) . '">' . esc_html__( 'Block 24h', 'publishrank' ) . '</a>';
			echo '<a class="prx-btn prx-btn--sm prx-btn--ghost" href="' . esc_url( self::event_url( (int) $r['id'], 'allow' ) ) . '">' . esc_html__( 'Allow IP', 'publishrank' ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<div class="prtg2-toolbar"><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="prx-inline"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		foreach ( $base as $k => $v ) {
			if ( 'per_page' !== $k ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
			}
		}
		echo '<label for="prtg-pp">' . esc_html__( 'Rows per page', 'publishrank' ) . '</label> <select id="prtg-pp" name="per_page" onchange="this.form.submit()">';
		foreach ( array( 25, 50, 100 ) as $pp ) {
			echo '<option value="' . (int) $pp . '"' . selected( $f['per_page'], $pp, false ) . '>' . (int) $pp . '</option>';
		}
		echo '</select> <noscript><button class="prx-btn prx-btn--sm" type="submit">' . esc_html__( 'Apply', 'publishrank' ) . '</button></noscript></form>';
		Suite::pagination( $data['total'], $f['per_page'], $f['paged'] );
		echo '</div>';

		echo '<details class="prtg2-danger"><summary>' . esc_html__( 'Clear the event log', 'publishrank' ) . '</summary>';
		Suite::form_open( 'publishrank_tg_clear' );
		echo '<p>' . esc_html( sprintf( /* translators: %d: days */ __( 'Events are deleted automatically after %d days. To delete all of them now, type CLEAR.', 'publishrank' ), $s['retention_days'] ) ) . '</p><div class="prx-row"><label class="screen-reader-text" for="prtg-clear">' . esc_html__( 'Type CLEAR to confirm', 'publishrank' ) . '</label><input class="prx-input prx-input--sm" id="prtg-clear" name="confirm" autocomplete="off" /><button class="prx-btn prx-btn--danger prx-btn--sm" type="submit">' . esc_html__( 'Delete all events', 'publishrank' ) . '</button></div></form></details>';
	}

	private static function event_url( $id, $do ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'publishrank_tg_event_action', 'event' => $id, 'do' => $do ), admin_url( 'admin-post.php' ) ), 'publishrank_tg_event_' . $do . '_' . $id );
	}

	private static function rule_url( $id, $do ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'publishrank_tg_rule_action', 'rule' => $id, 'do' => $do ), admin_url( 'admin-post.php' ) ), 'publishrank_tg_rule_' . $do . '_' . $id );
	}

	/* ---------- Rules */

	private function tab_rules( array $s ) {
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $edit ) {
			$draft = get_transient( 'publishrank_tg_rule_draft_' . get_current_user_id() );
			$rule  = is_array( $draft ) ? $draft : ( isset( $s['rules'][ $edit ] ) ? $s['rules'][ $edit ] : array() );
			$this->rule_form( $rule, isset( $s['rules'][ $edit ] ) );
			return;
		}
		$stats = TrafficGuardRules::stats();
		echo '<div class="prtg2-toolbar"><p class="prx-help">' . esc_html__( 'Rules run from the lowest priority number up. The first enforcing rule that matches decides; rules in test mode only record what they would have done. Verified search-engine crawlers are exempt from block actions while crawler protection is on.', 'publishrank' ) . '</p><a class="prx-btn prx-btn--primary" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'rules', 'edit' => 'new' ) ) ) . '"><span class="fa-solid fa-plus" aria-hidden="true"></span>' . esc_html__( 'Add rule', 'publishrank' ) . '</a></div>';
		if ( ! $s['rules'] ) {
			echo '<div class="prtg2-empty"><span class="fa-solid fa-sliders" aria-hidden="true"></span><h2>' . esc_html__( 'No custom rules yet', 'publishrank' ) . '</h2><p>' . esc_html__( 'Combine conditions such as country, path, user agent and visitor type, then choose an action. New rules start in test mode so you can see what they would match before they act.', 'publishrank' ) . '</p><a class="prx-btn prx-btn--primary" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'rules', 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Create your first rule', 'publishrank' ) . '</a></div>';
			return;
		}
		$fl = TrafficGuardRules::field_labels();
		$ol = TrafficGuardRules::operator_labels();
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th scope="col" class="prx-num">' . esc_html__( 'Priority', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Rule', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Action', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Status', 'publishrank' ) . '</th><th scope="col" class="prx-num">' . esc_html__( 'Matches', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Manage', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( TrafficGuardRules::ordered( $s['rules'] ) as $r ) {
			$st    = isset( $stats[ $r['id'] ] ) ? $stats[ $r['id'] ] : array( 'hits' => 0, 'last' => '', 'fp' => 0 );
			$conds = array();
			foreach ( $r['conditions'] as $c ) {
				$conds[] = $fl[ $c['field'] ] . ' ' . $ol[ $c['op'] ] . ' ' . ( is_array( $c['value'] ) ? implode( ', ', $c['value'] ) : $c['value'] );
			}
			echo '<tr><td class="prx-num">' . (int) $r['priority'] . '</td><td><strong>' . esc_html( $r['name'] ) . '</strong><br /><span class="prx-help">' . esc_html( implode( 'all' === $r['match'] ? ' ' . __( 'AND', 'publishrank' ) . ' ' : ' ' . __( 'OR', 'publishrank' ) . ' ', $conds ) ) . '</span></td>';
			echo '<td>' . esc_html( TrafficGuardRules::action_label( $r['action'] ) . ( 'temp_block' === $r['action'] ? ' (' . sprintf( /* translators: %d: minutes */ __( '%d min', 'publishrank' ), $r['minutes'] ) . ')' : '' ) ) . '</td>';
			echo '<td>' . ( $r['enabled'] ? Suite::badge( 'test' === $r['mode'] ? __( 'Test mode', 'publishrank' ) : __( 'Enforcing', 'publishrank' ), 'test' === $r['mode'] ? 'warn' : 'good' ) : Suite::badge( __( 'Off', 'publishrank' ), 'muted' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="prx-num"><a href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'events', 'rule' => $r['id'] ) ) ) . '">' . esc_html( number_format_i18n( $st['hits'] ) ) . '</a>' . ( $st['fp'] ? '<br /><span class="prx-help">' . esc_html( sprintf( /* translators: %d: count */ __( '%d false positives', 'publishrank' ), $st['fp'] ) ) . '</span>' : '' ) . '</td>';
			echo '<td class="prtg2-actions"><a class="prx-btn prx-btn--sm" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'rules', 'edit' => $r['id'] ) ) ) . '">' . esc_html__( 'Edit', 'publishrank' ) . '</a>';
			echo '<a class="prx-btn prx-btn--sm prx-btn--ghost" href="' . esc_url( self::rule_url( $r['id'], 'test' === $r['mode'] ? 'enforce' : 'test' ) ) . '">' . esc_html( 'test' === $r['mode'] ? __( 'Enforce', 'publishrank' ) : __( 'Back to test', 'publishrank' ) ) . '</a>';
			echo '<a class="prx-btn prx-btn--sm prx-btn--ghost" href="' . esc_url( self::rule_url( $r['id'], 'toggle' ) ) . '">' . esc_html( $r['enabled'] ? __( 'Switch off', 'publishrank' ) : __( 'Switch on', 'publishrank' ) ) . '</a>';
			echo '<a class="prx-btn prx-btn--sm prx-btn--ghost" href="' . esc_url( self::rule_url( $r['id'], 'duplicate' ) ) . '">' . esc_html__( 'Duplicate', 'publishrank' ) . '</a>';
			echo '<a class="prx-btn prx-btn--sm prx-btn--danger" href="' . esc_url( self::rule_url( $r['id'], 'delete' ) ) . '" data-prx-confirm="' . esc_attr__( 'Delete this rule?', 'publishrank' ) . '">' . esc_html__( 'Delete', 'publishrank' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function rule_form( array $r, $existing ) {
		$r = array_merge( array( 'id' => '', 'name' => '', 'enabled' => true, 'mode' => 'test', 'priority' => 100, 'match' => 'all', 'action' => 'block', 'minutes' => 60, 'note' => '', 'conditions' => array() ), $r );
		if ( ! $r['conditions'] ) {
			$r['conditions'] = array( array( 'field' => 'country', 'op' => 'in', 'value' => '' ) );
		}
		echo '<a class="prtg2-back" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'rules' ) ) ) . '">← ' . esc_html__( 'All rules', 'publishrank' ) . '</a>';
		Suite::card_open( $existing ? __( 'Edit rule', 'publishrank' ) : __( 'New rule', 'publishrank' ), 'fa-sliders', __( 'Describe which requests the rule matches, then what happens to them.', 'publishrank' ) );
		Suite::form_open( 'publishrank_tg_rule_save', array( 'class' => 'prtg2-rule', 'data-prtg-dirty' => '1' ) );
		if ( $existing ) {
			echo '<input type="hidden" name="rule[id]" value="' . esc_attr( $r['id'] ) . '" />';
		}
		echo '<div class="prx-grid prx-grid--3">';
		Suite::field( 'text', 'rule[name]', $r['name'], __( 'Rule name', 'publishrank' ), __( 'Shown in the event log.', 'publishrank' ), array( 'maxlength' => 80, 'required' => 'required' ) );
		Suite::field( 'number', 'rule[priority]', $r['priority'], __( 'Priority', 'publishrank' ), __( '1 runs first; 999 runs last.', 'publishrank' ), array( 'min' => 1, 'max' => 999 ) );
		Suite::field( 'select', 'rule[match]', $r['match'], __( 'Match', 'publishrank' ), '', array(), array( 'all' => __( 'All conditions (AND)', 'publishrank' ), 'any' => __( 'Any condition (OR)', 'publishrank' ) ) );
		echo '</div>';
		echo '<fieldset class="prtg2-conds"><legend>' . esc_html__( 'Conditions', 'publishrank' ) . '</legend><ol class="prtg2-conds__list" data-prtg-conds>';
		foreach ( array_values( $r['conditions'] ) as $i => $c ) {
			$this->condition_row( $i, $c );
		}
		echo '</ol><button type="button" class="prx-btn prx-btn--sm" data-prtg-add><span class="fa-solid fa-plus" aria-hidden="true"></span>' . esc_html__( 'Add condition', 'publishrank' ) . '</button>';
		echo '<template id="prtg-cond-template">';
		$this->condition_row( '__i__', array( 'field' => 'path', 'op' => 'starts_with', 'value' => '' ) );
		echo '</template></fieldset>';
		echo '<div class="prx-grid prx-grid--3">';
		Suite::field( 'select', 'rule[action]', $r['action'], __( 'Action', 'publishrank' ), '', array( 'data-prtg-action' => '1' ), TrafficGuardRules::action_labels() );
		Suite::field( 'number', 'rule[minutes]', $r['minutes'], __( 'Block for (minutes)', 'publishrank' ), __( 'Temporary blocks expire automatically.', 'publishrank' ), array( 'min' => 1, 'max' => 10080, 'data-prtg-minutes' => '1' ) );
		Suite::field( 'select', 'rule[mode]', $r['mode'], __( 'Mode', 'publishrank' ), __( 'Test mode records matches without acting on them.', 'publishrank' ), array(), array( 'test' => __( 'Test — record only', 'publishrank' ), 'enforce' => __( 'Enforce', 'publishrank' ) ) );
		echo '</div>';
		Suite::field( 'text', 'rule[note]', $r['note'], __( 'Internal note', 'publishrank' ), __( 'Why this rule exists (optional).', 'publishrank' ), array( 'maxlength' => 200 ) );
		Suite::switch_field( 'rule[enabled]', ! empty( $r['enabled'] ), __( 'Rule is switched on', 'publishrank' ) );
		echo '<div class="prx-row"><button type="submit" class="prx-btn prx-btn--primary">' . esc_html__( 'Save rule', 'publishrank' ) . '</button><a class="prx-btn prx-btn--ghost" href="' . esc_url( Suite::url( self::SLUG, array( 'tab' => 'rules' ) ) ) . '">' . esc_html__( 'Cancel', 'publishrank' ) . '</a></div>';
		echo '</form>';
		Suite::card_close();
		delete_transient( 'publishrank_tg_rule_draft_' . get_current_user_id() );
	}

	private function condition_row( $i, array $c ) {
		$fields = TrafficGuardRules::fields();
		$fl     = TrafficGuardRules::field_labels();
		$ol     = TrafficGuardRules::operator_labels();
		$field  = isset( $fields[ $c['field'] ?? '' ] ) ? $c['field'] : 'path';
		$value  = isset( $c['value'] ) ? ( is_array( $c['value'] ) ? implode( ', ', $c['value'] ) : (string) $c['value'] ) : '';
		$n      = esc_attr( (string) $i );
		echo '<li class="prtg2-cond" data-prtg-cond>';
		echo '<label class="screen-reader-text" for="prtg-f-' . $n . '">' . esc_html__( 'Field', 'publishrank' ) . '</label><select id="prtg-f-' . $n . '" name="rule[conditions][' . $n . '][field]" data-prtg-field>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $n is escaped.
		foreach ( $fl as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $field, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select><label class="screen-reader-text" for="prtg-o-' . $n . '">' . esc_html__( 'Operator', 'publishrank' ) . '</label><select id="prtg-o-' . $n . '" name="rule[conditions][' . $n . '][op]" data-prtg-op>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $fields[ $field ] as $op ) {
			echo '<option value="' . esc_attr( $op ) . '"' . selected( $c['op'] ?? '', $op, false ) . '>' . esc_html( $ol[ $op ] ) . '</option>';
		}
		echo '</select><label class="screen-reader-text" for="prtg-v-' . $n . '">' . esc_html__( 'Value', 'publishrank' ) . '</label><input class="prx-input" type="text" id="prtg-v-' . $n . '" name="rule[conditions][' . $n . '][value]" value="' . esc_attr( $value ) . '" data-prtg-value />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="button" class="prx-icon-btn prx-icon-btn--danger" data-prtg-remove aria-label="' . esc_attr__( 'Remove condition', 'publishrank' ) . '"><span class="fa-solid fa-xmark" aria-hidden="true"></span></button></li>';
	}

	/* ---------- Lists */

	private function tab_lists( array $s ) {
		$ip = TrafficGuard::current_client_ip( $s['trusted_proxies'] );
		Suite::form_open( 'publishrank_tg_lists', array( 'data-prtg-dirty' => '1' ) );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::card_open( __( 'Allow list', 'publishrank' ), 'fa-user-check', __( 'Always allowed; checked before every other rule.', 'publishrank' ) );
		Suite::field( 'textarea', 'allow_ips', implode( "\n", $s['allow_ips'] ), __( 'Allowed IPs and networks', 'publishrank' ), __( 'One IPv4/IPv6 address or CIDR network per line.', 'publishrank' ), array( 'rows' => 7, 'class' => 'prx-textarea prx-mono', 'spellcheck' => 'false' ) );
		if ( '' !== $ip && ! TrafficGuard::ip_matches_list( $ip, $s['allow_ips'] ) ) {
			echo '<p><button type="button" class="prx-btn prx-btn--sm" data-prtg-append="allow_ips" data-value="' . esc_attr( $ip ) . '">' . esc_html( sprintf( /* translators: %s: IP */ __( 'Add my IP (%s)', 'publishrank' ), $ip ) ) . '</button></p>';
		}
		Suite::card_close();
		Suite::card_open( __( 'Block list', 'publishrank' ), 'fa-ban', __( 'Permanently blocked addresses and networks.', 'publishrank' ) );
		Suite::field( 'textarea', 'blocked_ips', implode( "\n", $s['blocked_ips'] ), __( 'Blocked IPs and networks', 'publishrank' ), __( 'Networks broader than /8 (IPv4) or /16 (IPv6) are rejected as unsafe.', 'publishrank' ), array( 'rows' => 7, 'class' => 'prx-textarea prx-mono', 'spellcheck' => 'false' ) );
		Suite::card_close();
		echo '</div>';

		Suite::card_open( __( 'Country rules', 'publishrank' ), 'fa-earth-americas', __( 'Country comes only from trusted sources (developer lookup, web-server GeoIP or a trusted CDN header). Browser location is never used.', 'publishrank' ) );
		$geo = CountryResolver::describe( $s['trusted_proxies'], $s['geo_source'] );
		echo '<p class="prx-help">' . esc_html( '' !== $geo['code'] ? sprintf( /* translators: 1: country, 2: source */ __( 'Your current request resolves to %1$s via %2$s.', 'publishrank' ), Countries::name( $geo['code'] ) . ' (' . $geo['code'] . ')', $geo['source'] ) : __( 'No trusted country source is available for your current request, so country rules cannot match anyone yet. Configure a trusted proxy (for example Cloudflare) or a server GeoIP module under Protection.', 'publishrank' ) ) . '</p>';
		echo '<fieldset class="prtg2-radios"><legend>' . esc_html__( 'How country rules work', 'publishrank' ) . '</legend>';
		echo '<label><input type="radio" name="country_mode" value="block"' . checked( $s['country_mode'], 'block', false ) . ' data-prtg-cmode /> <strong>' . esc_html__( 'Block selected countries', 'publishrank' ) . '</strong> — ' . esc_html__( 'everyone else is allowed.', 'publishrank' ) . '</label>';
		echo '<label><input type="radio" name="country_mode" value="allow_only"' . checked( $s['country_mode'], 'allow_only', false ) . ' data-prtg-cmode /> <strong>' . esc_html__( 'Allow only selected countries', 'publishrank' ) . '</strong> — ' . esc_html__( 'use with care; it affects every reader abroad.', 'publishrank' ) . '</label></fieldset>';
		echo '<div data-prtg-cmode-panel="block">';
		CountryPicker::render( 'blocked_countries', $s['blocked_countries'], __( 'Blocked countries', 'publishrank' ), __( 'Search by name, alpha-2 or alpha-3 code, or paste a list such as RU, KP, IRN.', 'publishrank' ), true );
		echo '</div><div data-prtg-cmode-panel="allow_only">';
		CountryPicker::render( 'allowed_countries', $s['allowed_countries'], __( 'Allowed countries', 'publishrank' ), __( 'Only these countries can open front-end pages. Verified crawlers and signed-in administrators are not affected.', 'publishrank' ) );
		Suite::field( 'select', 'unknown_country', $s['unknown_country'], __( 'When the location is unknown', 'publishrank' ), __( 'Allowing is the safe fallback: IP geolocation is never complete.', 'publishrank' ), array(), array( 'allow' => __( 'Allow the visitor (recommended)', 'publishrank' ), 'block' => __( 'Block the visitor', 'publishrank' ) ) );
		echo '</div>';
		Suite::card_close();
		echo '<div class="prtg2-savebar"><button type="submit" class="prx-btn prx-btn--primary">' . esc_html__( 'Save IPs & countries', 'publishrank' ) . '</button><span class="prx-help" data-prtg-dirty-note hidden>' . esc_html__( 'Unsaved changes', 'publishrank' ) . '</span></div>';
		echo '</form>';

		Suite::card_open( __( 'Temporary blocks', 'publishrank' ), 'fa-hourglass-half', __( 'Added by rules, rate limiting or by hand. They expire on their own.', 'publishrank' ) );
		Suite::form_open( 'publishrank_tg_block_add', array( 'class' => 'prtg2-inline-form' ) );
		echo '<div class="prx-field"><label for="prtg-tb-ip">' . esc_html__( 'IP address', 'publishrank' ) . '</label><input class="prx-input prx-mono" id="prtg-tb-ip" name="ip" required /></div><div class="prx-field"><label for="prtg-tb-min">' . esc_html__( 'Minutes', 'publishrank' ) . '</label><input class="prx-input" type="number" id="prtg-tb-min" name="minutes" value="60" min="1" max="10080" /></div><button class="prx-btn" type="submit">' . esc_html__( 'Block temporarily', 'publishrank' ) . '</button></form>';
		$blocks = TrafficGuardStore::active_blocks();
		if ( ! $blocks ) {
			echo '<p class="prx-empty">' . esc_html__( 'No active temporary blocks.', 'publishrank' ) . '</p>';
		} else {
			echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th scope="col">' . esc_html__( 'IP address', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Reason', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Expires', 'publishrank' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'publishrank' ) . '</span></th></tr></thead><tbody>';
			foreach ( $blocks as $b ) {
				$reason = explode( ':', (string) $b['reason'] );
				echo '<tr><td class="prx-mono">' . esc_html( $b['ip'] ) . '</td><td>' . esc_html( TrafficGuard::reason_label( $reason[0] ) ) . '</td><td>' . esc_html( sprintf( /* translators: %s: time */ __( 'in %s', 'publishrank' ), human_time_diff( time(), strtotime( $b['expires_at'] . ' UTC' ) ) ) ) . '</td><td>';
				Suite::form_open( 'publishrank_tg_block_add', array( 'class' => 'prx-inline' ) );
				echo '<input type="hidden" name="unblock" value="' . esc_attr( $b['ip'] ) . '" /><button class="prx-btn prx-btn--sm prx-btn--ghost" type="submit">' . esc_html__( 'Unblock', 'publishrank' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		Suite::card_close();
	}

	/* ---------- Protection */

	private function tab_protection( array $s ) {
		$ip  = TrafficGuard::current_client_ip( $s['trusted_proxies'] );
		$geo = CountryResolver::describe( $s['trusted_proxies'], $s['geo_source'] );
		Suite::form_open( 'publishrank_tg_save', array( 'data-prtg-dirty' => '1' ) );
		Suite::card_open( __( 'Status', 'publishrank' ), 'fa-toggle-on' );
		Suite::switch_field( 'enabled', $s['enabled'], __( 'Turn on Traffic Guard', 'publishrank' ), __( 'Applies to anonymous front-end page requests. Admin, login, REST, AJAX, cron, feeds, sitemaps, robots.txt and WooCommerce API callbacks are never touched.', 'publishrank' ) );
		echo '<fieldset class="prtg2-radios"><legend>' . esc_html__( 'Mode', 'publishrank' ) . '</legend>';
		echo '<label><input type="radio" name="mode" value="monitor"' . checked( $s['mode'], 'monitor', false ) . ' /> <strong>' . esc_html__( 'Monitor', 'publishrank' ) . '</strong> — ' . esc_html__( 'record what would be blocked, block nothing. Recommended for the first days.', 'publishrank' ) . '</label>';
		echo '<label><input type="radio" name="mode" value="enforce"' . checked( $s['mode'], 'enforce', false ) . ' /> <strong>' . esc_html__( 'Enforce', 'publishrank' ) . '</strong> — ' . esc_html__( 'block matching requests.', 'publishrank' ) . '</label></fieldset>';
		Suite::field( 'select', 'level', $s['level'], __( 'Protection level', 'publishrank' ), __( 'Suspicious-request detection. Probes are requests for files such as .env, .git or database backups; injections are SQL/script patterns in the address; scanners identify themselves in the user agent.', 'publishrank' ), array(), self::level_labels() );
		Suite::switch_field( 'allow_crawlers', $s['allow_crawlers'], __( 'Never block genuine search-engine crawlers', 'publishrank' ), __( 'Google, Bing, Apple, Baidu, Yandex, DuckDuckGo and Petal crawlers are confirmed by reverse and forward DNS before any exemption, so spoofed bots are still blocked.', 'publishrank' ) );
		Suite::card_close();

		Suite::card_open( __( 'Rate limiting', 'publishrank' ), 'fa-gauge-high', __( 'Limit how many page requests one IP can make in a time window.', 'publishrank' ) );
		Suite::switch_field( 'rate_enabled', $s['rate_enabled'], __( 'Turn on rate limiting', 'publishrank' ), wp_using_ext_object_cache() ? __( 'A persistent object cache is active, so counting costs no database writes.', 'publishrank' ) : __( 'No persistent object cache is active: every anonymous page view costs one small database write while this is on. Prefer your CDN or host rate limiting on busy sites.', 'publishrank' ) );
		echo '<div class="prx-grid prx-grid--4">';
		Suite::field( 'number', 'rate_requests', $s['rate_requests'], __( 'Requests allowed', 'publishrank' ), '', array( 'min' => 10, 'max' => 10000 ) );
		Suite::field( 'number', 'rate_window', $s['rate_window'], __( 'Per (seconds)', 'publishrank' ), '', array( 'min' => 10, 'max' => 3600 ) );
		Suite::field( 'select', 'rate_action', $s['rate_action'], __( 'When exceeded', 'publishrank' ), '', array(), array( 'log' => __( 'Log only', 'publishrank' ), 'throttle' => __( 'Answer 429 for the rest of the window', 'publishrank' ), 'temp_block' => __( 'Block temporarily', 'publishrank' ) ) );
		Suite::field( 'number', 'rate_block_minutes', $s['rate_block_minutes'], __( 'Temporary block (minutes)', 'publishrank' ), '', array( 'min' => 1, 'max' => 10080 ) );
		echo '</div>';
		Suite::card_close();

		Suite::card_open( __( 'Client IP and country source', 'publishrank' ), 'fa-server', __( 'Headers such as X-Forwarded-For and CDN country headers are trusted only from the proxy networks listed here.', 'publishrank' ) );
		echo '<p class="prx-help">' . esc_html( sprintf( /* translators: 1: IP, 2: country */ __( 'Your current request: IP %1$s · country %2$s.', 'publishrank' ), '' !== $ip ? $ip : __( 'unknown', 'publishrank' ), '' !== $geo['code'] ? Countries::name( $geo['code'] ) . ' (' . $geo['code'] . ') — ' . $geo['source'] : __( 'not available', 'publishrank' ) ) ) . '</p>';
		Suite::field( 'textarea', 'trusted_proxies', implode( "\n", $s['trusted_proxies'] ), __( 'Trusted proxy networks', 'publishrank' ), __( 'Only ranges that connect directly to this server (your CDN or load balancer). One CIDR per line.', 'publishrank' ), array( 'rows' => 6, 'class' => 'prx-textarea prx-mono', 'spellcheck' => 'false' ) );
		echo '<p><button type="button" class="prx-btn prx-btn--sm" data-prtg-cloudflare>' . esc_html__( 'Fill in Cloudflare ranges', 'publishrank' ) . '</button> <a href="https://www.cloudflare.com/ips/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Compare with the live list', 'publishrank' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'publishrank' ) . '</span></a></p>';
		Suite::field( 'select', 'geo_source', $s['geo_source'], __( 'Country source', 'publishrank' ), __( 'Automatic tries a developer lookup, then web-server GeoIP variables, then CDN headers from trusted proxies.', 'publishrank' ), array(), array( 'auto' => __( 'Automatic (recommended)', 'publishrank' ), 'cdn' => __( 'CDN header from trusted proxies only', 'publishrank' ), 'server' => __( 'Web-server GeoIP variables only', 'publishrank' ), 'filter' => __( 'Developer lookup only', 'publishrank' ), 'off' => __( 'Off — never use country data', 'publishrank' ) ) );
		Suite::field( 'number', 'reputation_threshold', $s['reputation_threshold'], __( 'IP reputation threshold (0 = off)', 'publishrank' ), has_filter( 'publishrank_traffic_guard_ip_reputation' ) ? __( 'A reputation provider is registered. Requests with a risk score at or above this value are blocked.', 'publishrank' ) : __( 'No reputation provider is registered. Developers can add one with the publishrank_traffic_guard_ip_reputation filter (return array( "score" => 0–100, "source" => "name" )).', 'publishrank' ), array( 'min' => 0, 'max' => 100 ) );
		Suite::card_close();

		Suite::card_open( __( 'Block page and log', 'publishrank' ), 'fa-file-lines' );
		Suite::field( 'textarea', 'block_message', $s['block_message'], __( 'Message shown to blocked visitors', 'publishrank' ), __( 'Leave empty for the default text. Every block page shows a reference code you can search for under Events.', 'publishrank' ), array( 'rows' => 3, 'maxlength' => 500, 'class' => 'prx-textarea' ) );
		echo '<div class="prx-grid prx-grid--3">';
		Suite::field( 'email', 'contact_email', $s['contact_email'], __( 'Contact e-mail on the block page', 'publishrank' ), __( 'Optional. Lets wrongly blocked visitors report the problem.', 'publishrank' ) );
		Suite::field( 'number', 'retention_days', $s['retention_days'], __( 'Keep events for (days)', 'publishrank' ), '', array( 'min' => 1, 'max' => 365 ) );
		Suite::field( 'number', 'log_cap_minute', $s['log_cap_minute'], __( 'Maximum events written per minute', 'publishrank' ), __( 'Protects the database during floods; blocking continues above the cap.', 'publishrank' ), array( 'min' => 10, 'max' => 2000 ) );
		echo '</div>';
		Suite::card_close();
		echo '<div class="prx-callout prx-callout--info"><span class="fa-solid fa-circle-question" aria-hidden="true"></span><div><p>' . esc_html__( 'Full-page caches (caching plugins, Varnish, CDN page caching) answer cached pages before WordPress runs, so Traffic Guard cannot act on those requests. Exclude sensitive pages from the cache or use your CDN firewall for the same rules. Traffic Guard is not a replacement for network-level DDoS protection.', 'publishrank' ) . '</p></div></div>';
		echo '<div class="prtg2-savebar"><button type="submit" class="prx-btn prx-btn--primary">' . esc_html__( 'Save protection settings', 'publishrank' ) . '</button><span class="prx-help" data-prtg-dirty-note hidden>' . esc_html__( 'Unsaved changes', 'publishrank' ) . '</span></div>';
		echo '</form>';
	}

	/* ---------- Test */

	private function tab_test( array $s ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only simulation, capability checked in render().
		$in = array(
			'ip'         => isset( $_GET['t_ip'] ) ? sanitize_text_field( wp_unslash( $_GET['t_ip'] ) ) : TrafficGuard::current_client_ip( $s['trusted_proxies'] ),
			'country'    => isset( $_GET['t_cc'] ) ? sanitize_text_field( wp_unslash( $_GET['t_cc'] ) ) : '',
			'path'       => isset( $_GET['t_path'] ) ? sanitize_text_field( wp_unslash( $_GET['t_path'] ) ) : '/',
			'user_agent' => isset( $_GET['t_ua'] ) ? sanitize_text_field( wp_unslash( $_GET['t_ua'] ) ) : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
			'method'     => isset( $_GET['t_method'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['t_method'] ) ) ) : 'GET',
			'referer'    => isset( $_GET['t_ref'] ) ? esc_url_raw( wp_unslash( $_GET['t_ref'] ) ) : '',
			'verified'   => ! empty( $_GET['t_verified'] ),
		);
		$run = isset( $_GET['t_run'] );
		// phpcs:enable
		Suite::card_open( __( 'Test a request', 'publishrank' ), 'fa-flask', __( 'See exactly how the saved configuration would treat a request — including rules in test mode — without sending one. Nothing is logged or blocked.', 'publishrank' ) );
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="prtg2-test"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="test" /><input type="hidden" name="t_run" value="1" />';
		echo '<div class="prx-grid prx-grid--3">';
		Suite::field( 'text', 't_ip', $in['ip'], __( 'IP address', 'publishrank' ), '', array( 'class' => 'prx-input prx-mono' ) );
		Suite::field( 'text', 't_cc', $in['country'], __( 'Country code', 'publishrank' ), __( 'Alpha-2 or alpha-3. Leave empty for an unknown location.', 'publishrank' ), array( 'maxlength' => 3 ) );
		Suite::field( 'select', 't_method', $in['method'], __( 'Method', 'publishrank' ), '', array(), array( 'GET' => 'GET', 'POST' => 'POST', 'HEAD' => 'HEAD' ) );
		echo '</div>';
		Suite::field( 'text', 't_path', $in['path'], __( 'Path and query', 'publishrank' ), __( 'For example /wp-config.php.bak or /?s=test', 'publishrank' ), array( 'class' => 'prx-input prx-mono' ) );
		Suite::field( 'text', 't_ua', $in['user_agent'], __( 'User agent', 'publishrank' ), '', array( 'class' => 'prx-input prx-mono' ) );
		Suite::field( 'text', 't_ref', $in['referer'], __( 'Referrer', 'publishrank' ) );
		Suite::switch_field( 't_verified', $in['verified'], __( 'Treat a crawler user agent as DNS-verified', 'publishrank' ), __( 'Simulations never perform DNS lookups.', 'publishrank' ) );
		echo '<button type="submit" class="prx-btn prx-btn--primary"><span class="fa-solid fa-play" aria-hidden="true"></span>' . esc_html__( 'Run test', 'publishrank' ) . '</button></form>';
		Suite::card_close();
		if ( ! $run ) {
			return;
		}
		$path_q = (string) $in['path'];
		$ctx    = array(
			'ip'         => TrafficGuard::valid_ip( $in['ip'] ),
			'country'    => CountryResolver::normalise( $in['country'] ),
			'geo_source' => 'simulation',
			'method'     => in_array( $in['method'], array( 'GET', 'POST', 'HEAD' ), true ) ? $in['method'] : 'GET',
			'path'       => (string) wp_parse_url( $path_q, PHP_URL_PATH ),
			'query'      => (string) wp_parse_url( $path_q, PHP_URL_QUERY ),
			'user_agent' => $in['user_agent'],
			'referer'    => $in['referer'],
			'bot'        => TrafficGuard::bot_class( $in['user_agent'] ),
			'verified'   => $in['verified'],
		);
		if ( '' === $ctx['path'] ) {
			$ctx['path'] = '/';
		}
		$r      = TrafficGuard::evaluate( $ctx, $s, array( 'simulate' => true ) );
		$block  = in_array( $r['action'], array( 'block', 'temp_block', 'throttle' ), true );
		$labels = array(
			'allow'      => __( 'Allowed', 'publishrank' ),
			'log'        => __( 'Allowed and logged', 'publishrank' ),
			'block'      => __( 'Blocked', 'publishrank' ),
			'temp_block' => __( 'Blocked and IP blocked temporarily', 'publishrank' ),
			'throttle'   => __( 'Rate limited (429)', 'publishrank' ),
		);
		$verdict = $labels[ $r['action'] ];
		if ( $block && 'enforce' !== $s['mode'] ) {
			$verdict = __( 'Would be blocked (Monitor mode records it only)', 'publishrank' );
		}
		if ( ! $s['enabled'] ) {
			$verdict .= ' — ' . __( 'Traffic Guard is currently off, so nothing happens yet.', 'publishrank' );
		}
		echo '<section class="prtg2-result prtg2-result--' . ( $block ? 'block' : 'allow' ) . '" aria-live="polite"><h2><span class="fa-solid ' . ( $block ? 'fa-ban' : 'fa-circle-check' ) . '" aria-hidden="true"></span>' . esc_html( $verdict ) . '</h2>';
		echo '<p>' . esc_html( sprintf( /* translators: 1: reason, 2: visitor type */ __( 'Reason: %1$s · Visitor type: %2$s', 'publishrank' ), TrafficGuard::reason_label( $r['reason'] ), TrafficGuard::bot_labels()[ $ctx['bot'] ] ) ) . '</p>';
		echo '<ol class="prtg2-trace">';
		foreach ( $r['trace'] as $step ) {
			echo '<li><strong>' . esc_html( $step['step'] ) . '</strong> — ' . esc_html( $step['result'] ) . '</li>';
		}
		echo '</ol>';
		if ( $r['tests'] ) {
			$names = array();
			foreach ( $r['tests'] as $id ) {
				$names[] = $s['rules'][ $id ]['name'] ?? $id;
			}
			echo '<p class="prx-help">' . esc_html( sprintf( /* translators: %s: rule names */ __( 'Rules in test mode that matched: %s', 'publishrank' ), implode( ', ', $names ) ) ) . '</p>';
		}
		echo '<p class="prx-help">' . esc_html__( 'Simulations skip temporary blocks and rate counters, which depend on live traffic.', 'publishrank' ) . '</p></section>';
	}
}
