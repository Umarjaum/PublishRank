<?php
/**
 * PublishRank → Access Shield screen.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

use PublishRankSEO\Admin\Suite;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AdminPage {
	const SLUG = 'publishrank-access-shield';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_init', array( $this, 'redirect_legacy' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		foreach ( array( 'save', 'unlock', 'export', 'server_rules', 'confirm', 'clear_log', 'master', 'verify', 'verify_export' ) as $a ) {
			add_action( 'admin_post_publishrank_shield_' . $a, array( $this, 'handle_' . $a ) );
		}
	}

	public function menu() {
		$cap = Capabilities::can( 'manage_security' ) ? Capabilities::cap( 'manage_security' ) : Capabilities::cap( 'view_security_log' );
		add_submenu_page( Branding::MENU_SLUG, __( 'Access Shield', 'publishrank' ), __( 'Access Shield', 'publishrank' ), $cap, self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		Suite::enqueue_for( self::SLUG );
		if ( self::SLUG === Suite::current_page() ) {
			\PublishRankSEO\Admin\Charts::enqueue( false );
		}
	}

	public function redirect_legacy() {
		if ( 'publishrank-private-login' === Suite::current_page() ) {
			wp_safe_redirect( Suite::url( self::SLUG, array() ) . '#login' );
			exit;
		}
	}

	/* ---------------- Handlers ---------------- */

	public function handle_verify() {
		Suite::guard( 'publishrank_shield_verify', 'manage_security' );
		$r = Verifier::run();
		/* translators: 1: score, 2: failed checks */
		Suite::done( self::SLUG, $r['counts']['fail'] ? 'warning' : 'success', sprintf( __( 'Live verification finished: score %1$d/100, %2$d failed checks.', 'publishrank' ), $r['score'], $r['counts']['fail'] ), array(), 'verify' );
	}

	public function handle_verify_export() {
		Suite::guard( 'publishrank_shield_verify_export', 'manage_security' );
		$r = Verifier::last();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="publishrank-shield-verification-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function handle_master() {
		Suite::guard( 'publishrank_shield_master', 'manage_security' );
		$on = ! empty( $_POST['on'] );
		update_option( AccessShield::MASTER, $on ? 1 : 0, true );
		SecurityLog::add( 'settings', 'warn', array( 'detail' => $on ? 'Access Shield turned on.' : 'Access Shield turned off.' ) );
		Suite::done( self::SLUG, 'success', $on ? __( 'Access Shield is on.', 'publishrank' ) : __( 'Access Shield is off. The standard login address works again.', 'publishrank' ) );
	}

	public function handle_save() {
		Suite::guard( 'publishrank_shield_save', 'manage_security' );
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every key is sanitised by Settings::sanitize().
		$in   = isset( $_POST['shield'] ) && is_array( $_POST['shield'] ) ? wp_unslash( $_POST['shield'] ) : array();
		$bool = array(
			'brute'   => array( 'throttle_enabled', 'uniform_errors', 'equalize_timing', 'honeypot' ),
			'paths'   => array( 'block_author_enum', 'block_rest_users', 'hide_oembed_author' ),
			'captcha' => array( 'captcha_fail_open' ),
			'twofa'   => array( 'twofa_enabled', 'twofa_app_password' ),
			'alerts'  => array( 'alert_enabled' ),
			'accounts' => array( 'pw_policy', 'pw_breach_check' ),
		);
		foreach ( isset( $bool[ $section ] ) ? $bool[ $section ] : array() as $k ) {
			$in[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		if ( 'twofa' === $section && ! isset( $in['twofa_roles'] ) ) {
			$in['twofa_roles'] = array();
		}
		if ( 'accounts' === $section && ! isset( $in['pw_roles'] ) ) {
			$in['pw_roles'] = array();
		}
		if ( 'captcha' === $section && ! isset( $in['captcha_forms'] ) ) {
			$in['captcha_forms'] = array();
		}
		if ( 'captcha' === $section && isset( $in['captcha_secret'] ) && '' === trim( (string) $in['captcha_secret'] ) ) {
			unset( $in['captcha_secret'] ); // Keep the stored secret.
		}
		if ( 'hardening' === $section ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Hardening::save().
			Hardening::save( isset( $_POST['hard'] ) && is_array( $_POST['hard'] ) ? wp_unslash( $_POST['hard'] ) : array() );
			SecurityLog::add( 'settings', 'info', array( 'detail' => 'Hardening settings saved.' ) );
			Suite::done( self::SLUG, 'success', __( 'Hardening saved.', 'publishrank' ), array(), 'hardening' );
		}
		if ( 'login' === $section ) {
			$prev   = Settings::all();
			$enable = ! empty( $in['login_enabled'] );
			$slug   = LoginAddress::clean_slug( isset( $in['login_slug'] ) ? $in['login_slug'] : '' );
			if ( $enable ) {
				$valid = LoginAddress::validate_slug( $slug );
				if ( is_wp_error( $valid ) ) {
					Suite::done( self::SLUG, 'error', $valid->get_error_message(), array(), 'login' );
				}
			}
			$in['login_enabled'] = $enable ? 1 : 0;
			$in['login_slug']    = $slug;
			Settings::update( $in );
			$changed = $enable && ( empty( $prev['login_enabled'] ) || $prev['login_slug'] !== $slug );
			SecurityLog::add( 'settings', 'notice', array( 'detail' => $enable ? 'Private login address set.' : 'Private login address turned off.' ) );
			if ( $changed ) {
				LoginAddress::begin_change( $prev, $slug );
				Suite::done( self::SLUG, 'warning', sprintf( /* translators: %s: URL */ __( 'Your login address is now %s. We e-mailed you a confirmation link: open it within 10 minutes, or the previous address comes back automatically.', 'publishrank' ), LoginAddress::url() ), array(), 'login' );
			}
			delete_option( LoginAddress::PENDING_OPT );
			Suite::done( self::SLUG, 'success', __( 'Login address saved.', 'publishrank' ), array(), 'login' );
		}
		if ( in_array( $section, array( 'brute', 'never' ), true ) && ! empty( $_POST['add_my_ip'] ) && '' !== Client::ip() ) {
			$in['never_block_ips'] = trim( (string) ( isset( $in['never_block_ips'] ) ? $in['never_block_ips'] : Settings::get( 'never_block_ips' ) ) . "\n" . Client::ip() );
		}
		Settings::update( $in );
		SecurityLog::add( 'settings', 'info', array( 'detail' => 'Access Shield settings saved (' . $section . ').' ) );
		Suite::done( self::SLUG, 'success', __( 'Settings saved.', 'publishrank' ), array(), $section );
	}

	public function handle_confirm() {
		Suite::guard( 'publishrank_shield_confirm', 'manage_security' );
		LoginAddress::confirm_now();
		SecurityLog::add( 'settings', 'notice', array( 'detail' => 'Private login address confirmed from the dashboard.' ) );
		Suite::done( self::SLUG, 'success', __( 'Login address confirmed and kept.', 'publishrank' ), array(), 'login' );
	}

	public function handle_unlock() {
		Suite::guard( 'publishrank_shield_unlock', 'manage_security' );
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$n      = '' === $target ? 0 : Guard::unlock( $target );
		/* translators: %d: rows */
		Suite::done( self::SLUG, 'success', sprintf( __( 'Unlocked (%d records cleared).', 'publishrank' ), $n ) );
	}

	public function handle_clear_log() {
		Suite::guard( 'publishrank_shield_clear_log', 'manage_security' );
		global $wpdb;
		if ( \PublishRankSEO\Core\Database::ready( 'security_log' ) ) {
			$wpdb->query( 'TRUNCATE TABLE ' . \PublishRankSEO\Core\Database::table( 'security_log' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		SecurityLog::add( 'settings', 'warn', array( 'detail' => 'Security log cleared.' ) );
		Suite::done( self::SLUG, 'success', __( 'Security log cleared.', 'publishrank' ), array(), 'log' );
	}

	public function handle_export() {
		Suite::guard( 'publishrank_shield_export', 'view_security_log' );
		$f    = $this->log_filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard().
		$f['per_page'] = 1000;
		$rows = array();
		for ( $page = 1; $page <= 50; $page++ ) {
			$f['paged'] = $page;
			$res        = SecurityLog::query( $f );
			foreach ( $res['rows'] as $r ) {
				$rows[] = array( $r['created_at'], $r['event'], $r['severity'], $r['ip'], $r['username'], $r['user_id'], $r['path'], $r['detail'] );
			}
			if ( count( $res['rows'] ) < 1000 ) {
				break;
			}
		}
		Suite::csv( 'publishrank-security-log-' . gmdate( 'Ymd-His' ) . '.csv', array( 'created_at_utc', 'event', 'severity', 'ip', 'username', 'user_id', 'path', 'detail' ), $rows );
	}

	public function handle_server_rules() {
		Suite::guard( 'publishrank_shield_server_rules', 'manage_security' );
		$res = Hardening::write_server_rules( ! empty( $_POST['enable'] ) );
		if ( is_wp_error( $res ) ) {
			Suite::done( self::SLUG, 'error', $res->get_error_message(), array(), 'hardening' );
		}
		SecurityLog::add( 'settings', 'notice', array( 'detail' => ! empty( $_POST['enable'] ) ? 'Server rules written to .htaccess.' : 'Server rules removed from .htaccess.' ) );
		Suite::done( self::SLUG, 'success', __( 'Server rules updated.', 'publishrank' ), array(), 'hardening' );
	}

	private function log_filters( $src ) {
		$f = array();
		foreach ( array( 'event', 'ip', 'username', 'from', 'to', 'search' ) as $k ) {
			$f[ $k ] = isset( $src[ 'l_' . $k ] ) ? sanitize_text_field( wp_unslash( $src[ 'l_' . $k ] ) ) : '';
		}
		return $f;
	}

	/* ---------------- Screen ---------------- */

	public function render() {
		$manage = Capabilities::can( 'manage_security' );
		if ( ! $manage && ! Capabilities::can( 'view_security_log' ) ) {
			return;
		}
		$s       = Settings::all();
		$status  = AccessShield::status();
		$pending = LoginAddress::pending();
		Suite::open( __( 'Access Shield', 'publishrank' ), __( 'Private login address, brute-force protection, two-factor, CAPTCHA and WordPress hardening.', 'publishrank' ) );

		$tone = ! $status['enabled'] ? 'bad' : ( $status['hardening_score'] >= 80 ? 'ok' : 'warn' );
		echo '<div class="prx-hero"><div class="prx-hero__state"><span class="prx-hero__icon is-' . esc_attr( $tone ) . '"><span class="fa-solid fa-shield-halved" aria-hidden="true"></span></span><div><h2>';
		echo esc_html( $status['enabled'] ? ( $status['constant_off'] ? __( 'Access Shield is paused by a wp-config.php constant', 'publishrank' ) : __( 'Access Shield is protecting this site', 'publishrank' ) ) : __( 'Access Shield is off', 'publishrank' ) );
		echo '</h2><p>' . esc_html( $status['login_address'] ? sprintf( /* translators: %s: URL */ __( 'Private login: %s', 'publishrank' ), $status['login_address'] ) : __( 'The standard /wp-login.php address is in use.', 'publishrank' ) ) . '</p></div></div><div class="prx-row">';
		if ( $status['login_address'] ) {
			echo '<button type="button" class="prx-btn prx-btn--sm" data-prx-copy="' . esc_attr( $status['login_address'] ) . '"><span class="fa-solid fa-copy" aria-hidden="true"></span>' . esc_html__( 'Copy login URL', 'publishrank' ) . '</button>';
		}
		if ( $manage ) {
			Suite::form_open( 'publishrank_shield_master', $status['enabled'] ? array( 'data-prx-confirm' => __( 'Turn off Access Shield? All protections stop, including the private login address.', 'publishrank' ), 'data-prx-danger' => '1' ) : array() );
			echo '<input type="hidden" name="on" value="' . ( $status['enabled'] ? '0' : '1' ) . '" /><button class="prx-btn prx-btn--sm ' . ( $status['enabled'] ? 'prx-btn--danger' : 'prx-btn--primary' ) . '" type="submit">' . esc_html( $status['enabled'] ? __( 'Turn off', 'publishrank' ) : __( 'Turn on', 'publishrank' ) ) . '</button></form>';
		}
		echo '</div></div>';

		if ( $pending ) {
			$left = max( 0, (int) $pending['expires'] - time() );
			echo '<div class="prx-callout prx-callout--warn" role="alert"><span class="fa-solid fa-hourglass-half" aria-hidden="true"></span><div><p><strong>' . esc_html__( 'Confirm your new login address', 'publishrank' ) . '</strong> — ' . esc_html( sprintf( /* translators: %d: minutes */ __( 'the previous address comes back automatically in about %d minutes unless you confirm.', 'publishrank' ), (int) ceil( $left / 60 ) ) ) . '</p><p><a href="' . esc_url( LoginAddress::url() ) . '" target="_blank" rel="noopener">' . esc_html( LoginAddress::url() ) . '</a></p>';
			if ( $manage ) {
				Suite::form_open( 'publishrank_shield_confirm', array( 'class' => 'prx-row', 'style' => 'margin-top:8px' ) );
				echo '<button class="prx-btn prx-btn--primary prx-btn--sm" type="submit">' . esc_html__( 'I have saved it — keep this address', 'publishrank' ) . '</button></form>';
			}
			echo '</div></div>';
		}

		echo '<div class="prx-grid prx-grid--4">';
		Suite::kpi( __( 'Failed sign-ins (24 h)', 'publishrank' ), number_format_i18n( $status['failed_24h'] ), '', true );
		Suite::kpi( __( 'Lockouts (24 h)', 'publishrank' ), number_format_i18n( $status['lockouts_24h'] ), $status['challenge_active'] ? __( 'Flood challenge active', 'publishrank' ) : '' );
		Suite::kpi( __( 'Active locks', 'publishrank' ), number_format_i18n( $status['active_locks'] ) );
		Suite::kpi( __( 'Hardening score', 'publishrank' ), $status['hardening_score'] . '/100' );
		echo '</div>';

		$tabs = array(
			'overview'  => __( 'Overview', 'publishrank' ),
			'analytics' => __( 'Analytics', 'publishrank' ),
			'login'     => __( 'Login address', 'publishrank' ),
			'brute'     => __( 'Brute force', 'publishrank' ),
			'paths'     => __( 'Credential paths', 'publishrank' ),
			'captcha'   => __( 'CAPTCHA', 'publishrank' ),
			'twofa'     => __( 'Two-factor', 'publishrank' ),
			'accounts'  => __( 'Accounts', 'publishrank' ),
			'hardening' => __( 'Hardening', 'publishrank' ),
			'verify'    => __( 'Live verification', 'publishrank' ),
			'log'       => __( 'Security log', 'publishrank' ),
		);
		if ( ! $manage ) {
			$tabs = array_intersect_key( $tabs, array( 'overview' => 1, 'analytics' => 1, 'log' => 1 ) );
		}
		Suite::tabs( $tabs );
		$this->tab_overview( $manage );
		$this->tab_analytics();
		if ( $manage ) {
			$this->tab_login( $s );
			$this->tab_brute( $s );
			$this->tab_paths( $s );
			$this->tab_captcha( $s );
			$this->tab_twofa( $s );
			$this->tab_accounts( $s );
			$this->tab_hardening();
			$this->tab_verify();
		}
		$this->tab_log( $manage );
		Suite::close();
	}

	private function section_form( $section ) {
		Suite::form_open( 'publishrank_shield_save' );
		echo '<input type="hidden" name="section" value="' . esc_attr( $section ) . '" />';
	}

	private function save_button( $label = '' ) {
		echo '<div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit"><span class="fa-solid fa-floppy-disk" aria-hidden="true"></span>' . esc_html( $label ? $label : __( 'Save changes', 'publishrank' ) ) . '</button></div>';
	}

	private function tab_overview( $manage ) {
		Suite::panel_open( 'overview', false );
		echo '<div class="prx-grid prx-grid--side"><div class="prx-stack">';
		Suite::card_open( __( 'Sign-in attacks, last 14 days', 'publishrank' ), 'fa-chart-column', __( 'Failed sign-ins (plum) and lockouts (green).', 'publishrank' ) );
		echo '<svg class="prx-chart" role="img" aria-label="' . esc_attr__( 'Failed sign-ins and lockouts per day', 'publishrank' ) . '" data-prx-chart="' . esc_attr( wp_json_encode( SecurityLog::series( 14 ) ) ) . '"></svg>';
		Suite::card_close();
		Suite::card_open( __( 'Active locks', 'publishrank' ), 'fa-lock' );
		$locks = Guard::active_locks( 50 );
		if ( ! $locks ) {
			echo '<div class="prx-empty"><span class="fa-solid fa-lock-open" aria-hidden="true"></span><strong>' . esc_html__( 'Nobody is locked out', 'publishrank' ) . '</strong></div>';
		} else {
			echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Type', 'publishrank' ) . '</th><th>IP</th><th>' . esc_html__( 'Username', 'publishrank' ) . '</th><th>' . esc_html__( 'Level', 'publishrank' ) . '</th><th>' . esc_html__( 'Until (UTC)', 'publishrank' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $locks as $l ) {
				echo '<tr><td>' . Suite::badge( 'ipuser' === $l['kind'] ? __( 'IP + user', 'publishrank' ) : __( 'IP', 'publishrank' ), 'warn' ) . '</td><td class="prx-mono">' . esc_html( $l['ip'] ) . '</td><td>' . esc_html( $l['username'] ) . '</td><td class="prx-num">' . (int) $l['lock_level'] . '</td><td>' . esc_html( $l['lock_until'] ) . '</td><td class="prx-actions">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				if ( $manage ) {
					Suite::form_open( 'publishrank_shield_unlock' );
					echo '<input type="hidden" name="target" value="' . esc_attr( $l['ip'] ) . '" /><button class="prx-btn prx-btn--sm" type="submit">' . esc_html__( 'Unlock', 'publishrank' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		if ( $manage ) {
			Suite::form_open( 'publishrank_shield_unlock', array( 'class' => 'prx-toolbar', 'style' => 'margin-top:12px' ) );
			echo '<input class="prx-input" type="text" name="target" placeholder="' . esc_attr__( 'IP address or username', 'publishrank' ) . '" /><button class="prx-btn" type="submit">' . esc_html__( 'Unlock', 'publishrank' ) . '</button></form>';
		}
		Suite::card_close();
		echo '</div><div class="prx-stack">';
		$audit = Hardening::audit( false );
		Suite::card_open( __( 'Security checklist', 'publishrank' ), 'fa-list-check', sprintf( /* translators: %d: score */ __( 'Score %d/100. Open Hardening to run the full audit with live file probes.', 'publishrank' ), $audit['score'] ) );
		echo '<div class="prx-meter prx-meter--' . ( $audit['score'] >= 80 ? 'ok' : ( $audit['score'] >= 50 ? 'warn' : 'bad' ) ) . '" style="margin-bottom:12px"><span style="width:' . (int) $audit['score'] . '%"></span></div><ul class="prx-list">';
		foreach ( $audit['checks'] as $c ) {
			echo '<li><span class="fa-solid ' . ( $c['ok'] ? 'fa-circle-check' : 'fa-circle-xmark' ) . '" style="color:' . ( $c['ok'] ? '#1E6B4F' : '#A1232B' ) . '" aria-hidden="true"></span><div><strong>' . esc_html( $c['label'] ) . '</strong>' . ( $c['ok'] ? '' : '<br /><span class="prx-help">' . esc_html( $c['fix'] ) . '</span>' ) . '</div></li>';
		}
		echo '</ul>';
		Suite::card_close();
		Suite::card_open( __( 'Recovery', 'publishrank' ), 'fa-life-ring', __( 'If you ever lose access:', 'publishrank' ) );
		echo '<ol class="prx-help" style="margin:0 0 0 18px;line-height:1.9"><li>' . esc_html__( 'Add to wp-config.php:', 'publishrank' ) . ' <code>define( \'PUBLISHRANK_DISABLE_ACCESS_SHIELD\', true );</code></li><li>' . esc_html__( 'Or run:', 'publishrank' ) . ' <code>wp publishrank shield disable</code></li><li>' . esc_html__( 'Unlock an IP:', 'publishrank' ) . ' <code>wp publishrank shield unlock 203.0.113.7</code></li><li>' . esc_html__( 'Reset two-factor:', 'publishrank' ) . ' <code>wp publishrank shield 2fa-reset username</code></li></ol>';
		Suite::card_close();
		echo '</div></div>';
		Suite::panel_close();
	}

	private function tab_analytics() {
		$C     = '\\PublishRankSEO\\Admin\\Charts';
		$days  = isset( $_GET['sdays'] ) ? absint( $_GET['sdays'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days  = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
		$now   = SecurityStats::event_counts( $days );
		$prev  = SecurityStats::event_counts( $days, 1 );
		$g     = SecurityStats::grouped( $now );
		$pg    = SecurityStats::grouped( $prev );
		$total = array_sum( $now );
		$ptot  = array_sum( $prev );
		$daily = SecurityStats::daily( $days );
		$groups = SecurityStats::groups();
		Suite::panel_open( 'analytics' );
		echo '<div class="prc-screen">';
		echo '<form method="get" class="prc-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><div class="prc-range"><label for="prc-sdays"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span class="screen-reader-text">' . esc_html__( 'Period', 'publishrank' ) . '</span></label><select id="prc-sdays" name="sdays" data-prc-range>';
		foreach ( array( 7, 30, 90 ) as $d ) {
			/* translators: %d: days */
			echo '<option value="' . (int) $d . '"' . selected( $days, $d, false ) . '>' . esc_html( sprintf( _n( 'Last %d day', 'Last %d days', $d, 'publishrank' ), $d ) ) . '</option>';
		}
		echo '</select></div><span class="prc-muted">' . esc_html__( 'Times are counted in UTC days.', 'publishrank' ) . '</span></form>';
		echo '<script>if(location.search.indexOf("sdays=")>-1&&!location.hash){history.replaceState(null,"",location.href+"#analytics");}</script>';

		echo '<div class="prc-kpis">';
		$C::kpi( __( 'Login activity', 'publishrank' ), number_format_i18n( $total ), $C::delta( $total, $ptot ), 'fa-right-to-bracket', 'slate', array_map( static function ( $i ) use ( $daily ) { $t = 0; foreach ( $daily['series'] as $v ) { $t += $v[ $i ]; } return $t; }, array_keys( $daily['labels'] ) ) );
		$C::kpi( __( 'Successful logins', 'publishrank' ), number_format_i18n( $g['success'] ), $C::delta( $g['success'], $pg['success'] ), 'fa-circle-check', 'teal', $daily['series']['success'] );
		$C::kpi( __( 'Failed attempts', 'publishrank' ), number_format_i18n( $g['failed'] ), $C::delta( $g['failed'], $pg['failed'], true ), 'fa-circle-xmark', 'plum', $daily['series']['failed'] );
		$C::kpi( __( 'Blocked', 'publishrank' ), number_format_i18n( $g['blocked'] ), $C::delta( $g['blocked'], $pg['blocked'] ), 'fa-ban', 'coral', $daily['series']['blocked'], __( 'Lockouts, denied IPs or countries, firewall, XML-RPC, honeypot and hidden-login probes.', 'publishrank' ) );
		$C::kpi( __( 'Two-factor verified', 'publishrank' ), number_format_i18n( $g['twofa'] ), $C::delta( $g['twofa'], $pg['twofa'] ), 'fa-key', 'brass', $daily['series']['twofa'] );
		$C::kpi( __( 'Unique IP addresses', 'publishrank' ), number_format_i18n( SecurityStats::unique_ips( $days ) ), '<span class="prc-delta prc-delta--flat">' . esc_html__( 'period', 'publishrank' ) . '</span>', 'fa-network-wired', 'sage', array(), '', '' );
		echo '</div>';

		echo '<div class="prc-grid prc-grid--main">';
		$C::card_open( __( 'Login activity over time', 'publishrank' ), 'fa-chart-line', __( 'Click a name to hide or show it. Hover for daily numbers.', 'publishrank' ), '', 'prc-span-2' );
		echo $C::line( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$daily['labels'],
			array(
				array( 'name' => __( 'Successful', 'publishrank' ), 'tone' => 'teal', 'values' => $daily['series']['success'] ),
				array( 'name' => __( 'Failed', 'publishrank' ), 'tone' => 'plum', 'values' => $daily['series']['failed'] ),
				array( 'name' => __( 'Blocked', 'publishrank' ), 'tone' => 'coral', 'values' => $daily['series']['blocked'] ),
				array( 'name' => __( 'Two-factor', 'publishrank' ), 'tone' => 'brass', 'values' => $daily['series']['twofa'] ),
			),
			true,
			300
		);
		$C::card_close();
		$labels = SecurityLog::events();
		$seg    = array();
		$tones  = array( 'teal', 'plum', 'coral', 'brass', 'slate', 'sage', 'rose', 'ink' );
		arsort( $now );
		$i     = 0;
		$other = 0;
		foreach ( $now as $ev => $n ) {
			if ( $i < 6 ) {
				$seg[] = array( 'label' => isset( $labels[ $ev ] ) ? $labels[ $ev ] : $ev, 'value' => $n, 'color' => $tones[ $i ] );
			} else {
				$other += $n;
			}
			++$i;
		}
		if ( $other ) {
			$seg[] = array( 'label' => __( 'Other events', 'publishrank' ), 'value' => $other, 'color' => 'ink' );
		}
		$C::card_open( __( 'Events breakdown', 'publishrank' ), 'fa-chart-pie' );
		echo $seg ? $C::donut( $seg, number_format_i18n( $total ), __( 'events', 'publishrank' ) ) : '<p class="prc-empty">' . esc_html__( 'No security events in this period.', 'publishrank' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$C::card_close();
		echo '</div>';

		$bad = array_merge( $groups['failed'], $groups['blocked'] );
		echo '<div class="prc-grid prc-grid--three">';
		$C::card_open( __( 'Attacks by country', 'publishrank' ), 'fa-earth-europe', __( 'Shown when your host, CDN or trusted proxy reports the country.', 'publishrank' ) );
		$rows = array();
		foreach ( SecurityStats::top( 'country', $bad, $days, 8 ) as $r ) {
			$rows[] = array( 'label' => \PublishRankSEO\Analytics\Geo::name( $r['val'] ), 'value' => $r['n'], 'icon' => \PublishRankSEO\Analytics\Geo::flag( $r['val'] ), 'tone' => 'plum' );
		}
		echo $C::bars( $rows, 0, '', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$C::card_close();
		$C::card_open( __( 'Sign-in methods', 'publishrank' ), 'fa-fingerprint' );
		$methods = array(
			array( 'label' => __( 'Password', 'publishrank' ), 'value' => ( $now['login_success'] ?? 0 ) + ( $now['login_failed'] ?? 0 ), 'tone' => 'slate' ),
			array( 'label' => __( 'Two-factor code', 'publishrank' ), 'value' => ( $now['twofa_success'] ?? 0 ) + ( $now['twofa_failed'] ?? 0 ), 'tone' => 'brass' ),
			array( 'label' => __( 'Recovery code', 'publishrank' ), 'value' => $now['twofa_recovery'] ?? 0, 'tone' => 'sage' ),
			array( 'label' => __( 'Application password', 'publishrank' ), 'value' => $now['app_password_failed'] ?? 0, 'tone' => 'teal' ),
			array( 'label' => __( 'XML-RPC (blocked)', 'publishrank' ), 'value' => $now['xmlrpc_blocked'] ?? 0, 'tone' => 'coral' ),
			array( 'label' => __( 'CAPTCHA failed', 'publishrank' ), 'value' => $now['captcha_failed'] ?? 0, 'tone' => 'plum' ),
		);
		$methods = array_values( array_filter( $methods, static function ( $m ) { return $m['value'] > 0; } ) );
		echo $C::bars( $methods ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$C::card_close();
		$C::card_open( __( 'Most targeted usernames', 'publishrank' ), 'fa-user-secret', __( 'Consider removing or renaming accounts attackers guess.', 'publishrank' ) );
		$rows = array();
		foreach ( SecurityStats::top( 'username', $bad, $days, 8 ) as $r ) {
			$rows[] = array( 'label' => $r['val'], 'value' => $r['n'], 'tone' => 'coral' );
		}
		echo $C::bars( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$C::card_close();
		echo '</div><div class="prc-grid prc-grid--two">';
		$C::card_open( __( 'Top attacking IP addresses', 'publishrank' ), 'fa-network-wired' );
		$ips = SecurityStats::top( 'ip', $bad, $days, 10 );
		if ( $ips ) {
			echo '<table class="prc-table"><thead><tr><th>IP</th><th class="num">' . esc_html__( 'Events', 'publishrank' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $ips as $r ) {
				echo '<tr><td class="prx-mono">' . esc_html( $r['val'] ) . '</td><td class="num">' . esc_html( number_format_i18n( $r['n'] ) ) . '</td><td class="num"><a class="prc-link" href="' . esc_url( Suite::url( self::SLUG, array( 'l_ip' => $r['val'] ) ) . '#log' ) . '">' . esc_html__( 'View log', 'publishrank' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="prc-empty">' . esc_html__( 'No attacks recorded in this period.', 'publishrank' ) . '</p>';
		}
		$C::card_close();
		$C::card_open( __( 'Recent activity', 'publishrank' ), 'fa-clock-rotate-left', '', '<a class="prc-link" href="#log">' . esc_html__( 'Full log', 'publishrank' ) . '</a>' );
		$recent = SecurityStats::recent( 8 );
		if ( $recent ) {
			$icons = array( 'login_success' => array( 'fa-circle-check', 'teal' ), 'twofa_success' => array( 'fa-key', 'brass' ), 'login_failed' => array( 'fa-circle-xmark', 'plum' ), 'lockout' => array( 'fa-lock', 'coral' ) );
			echo '<ul class="prc-activity">';
			foreach ( $recent as $r ) {
				$ic = isset( $icons[ $r['event'] ] ) ? $icons[ $r['event'] ] : ( in_array( $r['event'], $groups['blocked'], true ) ? array( 'fa-ban', 'coral' ) : array( 'fa-circle-info', 'slate' ) );
				$cc = isset( $r['country'] ) ? (string) $r['country'] : '';
				echo '<li><span class="prc-kpi__icon prc-bg--' . esc_attr( $ic[1] ) . '" style="width:28px;height:28px;font-size:12px"><i class="fa-solid ' . esc_attr( $ic[0] ) . '" aria-hidden="true"></i></span><span class="prc-activity__main"><strong>' . esc_html( isset( $labels[ $r['event'] ] ) ? $labels[ $r['event'] ] : $r['event'] ) . ( '' !== (string) $r['username'] ? ' · ' . esc_html( $r['username'] ) : '' ) . '</strong><small>' . esc_html( $r['ip'] ) . ( '' !== $cc ? ' · ' . esc_html( \PublishRankSEO\Analytics\Geo::flag( $cc ) . ' ' . \PublishRankSEO\Analytics\Geo::name( $cc ) ) : '' ) . '</small></span><time>' . esc_html( sprintf( /* translators: %s: time */ __( '%s ago', 'publishrank' ), human_time_diff( strtotime( $r['created_at'] . ' UTC' ), time() ) ) ) . '</time></li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="prc-empty">' . esc_html__( 'No security events yet.', 'publishrank' ) . '</p>';
		}
		$C::card_close();
		echo '</div>';
		$C::card_open( __( 'When attacks happen', 'publishrank' ), 'fa-calendar-week', __( 'Failed and blocked attempts by weekday and hour (site time zone).', 'publishrank' ) );
		echo $C::heatmap( SecurityStats::heatmap( $days ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$C::card_close();
		echo '</div>';
		Suite::panel_close();
	}

	private function tab_login( array $s ) {
		Suite::panel_open( 'login' );
		Suite::card_open( __( 'Private login address', 'publishrank' ), 'fa-key', __( 'Signed-out visitors who open /wp-login.php or /wp-admin/ get a “page not found” error. Password reset, logout, registration, privacy e-mails and multisite links use the new address automatically.', 'publishrank' ) );
		$this->section_form( 'login' );
		Suite::switch_field( 'shield[login_enabled]', ! empty( $s['login_enabled'] ), __( 'Use a private login address', 'publishrank' ), __( 'You must confirm the new address within 10 minutes or it is undone automatically.', 'publishrank' ) );
		echo '<div class="prx-field"><label for="prx-slug">' . esc_html__( 'Login address', 'publishrank' ) . '</label><div class="prx-row"><code>' . esc_html( trailingslashit( home_url() ) ) . '</code><input class="prx-input" id="prx-slug" type="text" name="shield[login_slug]" value="' . esc_attr( $s['login_slug'] ? $s['login_slug'] : 'desk-' . strtolower( wp_generate_password( 6, false, false ) ) ) . '" pattern="[a-z0-9_\-]{6,64}" maxlength="64" required /></div><p class="description">' . esc_html__( 'At least 6 characters: lowercase letters, numbers, dashes. Reserved words and existing page addresses are refused.', 'publishrank' ) . '</p></div>';
		echo '<div class="prx-fields">';
		Suite::field( 'select', 'shield[blocked_behaviour]', $s['blocked_behaviour'], __( 'When someone opens the old addresses', 'publishrank' ), '', array(), array( '404' => __( 'Show “page not found” (404)', 'publishrank' ), 'redirect' => __( 'Redirect to a page', 'publishrank' ) ) );
		echo '<div data-prx-show-when="shield[blocked_behaviour]=redirect">';
		Suite::field( 'text', 'shield[blocked_redirect]', $s['blocked_redirect'], __( 'Redirect to (page address)', 'publishrank' ), __( 'For example: not-found', 'publishrank' ) );
		echo '</div></div>';
		$this->save_button( __( 'Save login address', 'publishrank' ) );
		echo '</form>';
		Suite::card_close();
		Suite::panel_close();
	}

	private function tab_brute( array $s ) {
		Suite::panel_open( 'brute' );
		$this->section_form( 'brute' );
		echo '<div class="prx-grid prx-grid--2"><div class="prx-stack">';
		Suite::card_open( __( 'Throttling and lockouts', 'publishrank' ), 'fa-gauge-high', __( 'Counts failures by IP, by username and by IP + username in an indexed table.', 'publishrank' ) );
		Suite::switch_field( 'shield[throttle_enabled]', ! empty( $s['throttle_enabled'] ), __( 'Throttle failed sign-ins', 'publishrank' ) );
		echo '<div class="prx-fields">';
		Suite::field( 'number', 'shield[max_attempts]', $s['max_attempts'], __( 'Failures before a lock (per IP + username)', 'publishrank' ), __( 'An IP trying many usernames locks at three times this number.', 'publishrank' ), array( 'min' => 1, 'max' => 100 ) );
		Suite::field( 'number', 'shield[window_minutes]', $s['window_minutes'], __( 'Counting window (minutes)', 'publishrank' ), '', array( 'min' => 1, 'max' => 1440 ) );
		echo '</div>';
		Suite::field( 'text', 'shield[lock_ladder]', $s['lock_ladder'], __( 'Progressive lock durations (minutes)', 'publishrank' ), __( 'Each repeat lock within a day moves one step up the ladder.', 'publishrank' ) );
		echo '<div class="prx-fields">';
		Suite::field( 'number', 'shield[user_flood]', $s['user_flood'], __( 'Flood: failures for one username', 'publishrank' ), '', array( 'min' => 3 ) );
		Suite::field( 'number', 'shield[flood_ips]', $s['flood_ips'], __( 'Flood: from at least this many IPs', 'publishrank' ), __( 'Raises a site-wide challenge instead of locking the real user out.', 'publishrank' ), array( 'min' => 2 ) );
		Suite::field( 'number', 'shield[retention_days]', $s['retention_days'], __( 'Keep attempt records (days)', 'publishrank' ), '', array( 'min' => 1, 'max' => 365 ) );
		Suite::field( 'number', 'shield[log_retention_days]', $s['log_retention_days'], __( 'Keep security log (days)', 'publishrank' ), '', array( 'min' => 1, 'max' => 365 ) );
		echo '</div>';
		Suite::card_close();
		Suite::card_open( __( 'Response hardening', 'publishrank' ), 'fa-user-secret' );
		Suite::switch_field( 'shield[uniform_errors]', ! empty( $s['uniform_errors'] ), __( 'Identical error for wrong username and wrong password', 'publishrank' ), __( 'Also answers lost-password requests the same way for unknown accounts.', 'publishrank' ) );
		Suite::switch_field( 'shield[equalize_timing]', ! empty( $s['equalize_timing'] ), __( 'Equalise response timing', 'publishrank' ), __( 'Checks a dummy hash for unknown users so timing does not reveal valid usernames.', 'publishrank' ) );
		Suite::switch_field( 'shield[honeypot]', ! empty( $s['honeypot'] ), __( 'Honeypot field on login, registration and lost-password forms', 'publishrank' ) );
		Suite::field( 'number', 'shield[min_submit_seconds]', $s['min_submit_seconds'], __( 'Minimum seconds before a form may be submitted', 'publishrank' ), __( '0 turns the check off.', 'publishrank' ), array( 'min' => 0, 'max' => 30 ) );
		Suite::card_close();
		echo '</div><div class="prx-stack">';
		Suite::card_open( __( 'Allow, deny and never-block lists', 'publishrank' ), 'fa-list', sprintf( /* translators: %s: IP */ __( 'IPv4/IPv6 addresses or CIDR ranges, one per line. Your current IP: %s', 'publishrank' ), Client::ip() ) );
		Suite::field( 'textarea', 'shield[never_block_ips]', $s['never_block_ips'], __( 'Never block', 'publishrank' ), __( 'Administrators who signed in during the last 7 days are also never blocked from their IP.', 'publishrank' ), array( 'rows' => 3 ) );
		echo '<label class="prx-check" style="display:flex;gap:8px;margin:-6px 0 12px"><input type="checkbox" name="add_my_ip" value="1" /> ' . esc_html__( 'Add my current IP to the never-block list', 'publishrank' ) . '</label>';
		Suite::field( 'textarea', 'shield[allow_ips]', $s['allow_ips'], __( 'Allow (skip throttling)', 'publishrank' ), '', array( 'rows' => 3 ) );
		Suite::field( 'textarea', 'shield[deny_ips]', $s['deny_ips'], __( 'Deny (no login screen at all)', 'publishrank' ), '', array( 'rows' => 3 ) );
		echo '<div class="prx-fields">';
		Suite::field( 'text', 'shield[deny_countries]', $s['deny_countries'], __( 'Deny countries', 'publishrank' ), __( 'Two-letter codes, e.g. RU,KP. Needs a trusted CDN header (Traffic Guard → trusted proxies).', 'publishrank' ) );
		Suite::field( 'text', 'shield[allow_countries]', $s['allow_countries'], __( 'Only allow countries', 'publishrank' ), __( 'Leave empty to allow everyone.', 'publishrank' ) );
		echo '</div>';
		Suite::card_close();
		Suite::card_open( __( 'Alerts', 'publishrank' ), 'fa-bell' );
		echo '<input type="hidden" name="shield[alert_enabled]" value="0" />';
		Suite::switch_field( 'shield[alert_enabled]', ! empty( $s['alert_enabled'] ), __( 'E-mail me when lockouts spike', 'publishrank' ), __( 'At most one e-mail per hour.', 'publishrank' ) );
		echo '<div class="prx-fields">';
		Suite::field( 'number', 'shield[alert_threshold]', $s['alert_threshold'], __( 'Lockouts per hour', 'publishrank' ), '', array( 'min' => 1 ) );
		Suite::field( 'email', 'shield[alert_email]', $s['alert_email'], __( 'Send to', 'publishrank' ), __( 'Empty uses the site admin e-mail.', 'publishrank' ) );
		echo '</div>';
		Suite::card_close();
		echo '</div></div>';
		echo '<div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Save brute-force settings', 'publishrank' ) . '</button></div></form>';
		Suite::panel_close();
	}

	private function tab_paths( array $s ) {
		Suite::panel_open( 'paths' );
		$this->section_form( 'paths' );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::card_open( __( 'XML-RPC and application passwords', 'publishrank' ), 'fa-plug' );
		Suite::field(
			'select',
			'shield[xmlrpc_mode]',
			$s['xmlrpc_mode'],
			__( 'XML-RPC', 'publishrank' ),
			__( 'Each password guess inside system.multicall is counted separately either way.', 'publishrank' ),
			array(),
			array(
				'on'           => __( 'On (not recommended)', 'publishrank' ),
				'no_multicall' => __( 'On, without system.multicall (recommended)', 'publishrank' ),
				'no_auth'      => __( 'Only methods that need no password (pingbacks)', 'publishrank' ),
				'off'          => __( 'Off: xmlrpc.php answers 403', 'publishrank' ),
			)
		);
		Suite::field( 'select', 'shield[app_passwords]', $s['app_passwords'], __( 'Application passwords (REST / XML-RPC API keys)', 'publishrank' ), __( 'Locked IPs cannot use application passwords either.', 'publishrank' ), array(), array( 'on' => __( 'Everyone who may have them', 'publishrank' ), 'admins' => __( 'Administrators only', 'publishrank' ), 'off' => __( 'Off', 'publishrank' ) ) );
		Suite::card_close();
		Suite::card_open( __( 'Username enumeration and sessions', 'publishrank' ), 'fa-user-lock' );
		Suite::switch_field( 'shield[block_author_enum]', ! empty( $s['block_author_enum'] ), __( 'Block ?author=N probes for signed-out visitors', 'publishrank' ), __( 'Also removes comment-author-username CSS classes.', 'publishrank' ) );
		Suite::switch_field( 'shield[block_rest_users]', ! empty( $s['block_rest_users'] ), __( 'Hide /wp-json/wp/v2/users from signed-out visitors', 'publishrank' ) );
		Suite::switch_field( 'shield[hide_oembed_author]', ! empty( $s['hide_oembed_author'] ), __( 'Remove author names from oEmbed responses', 'publishrank' ) );
		echo '<div class="prx-fields">';
		Suite::field( 'number', 'shield[session_hours]', $s['session_hours'], __( 'Session length (hours)', 'publishrank' ), __( '0 keeps the WordPress default (2 days).', 'publishrank' ), array( 'min' => 0, 'max' => 720 ) );
		Suite::field( 'number', 'shield[session_remember_days]', $s['session_remember_days'], __( '“Remember me” length (days)', 'publishrank' ), __( '0 keeps the default (14 days).', 'publishrank' ), array( 'min' => 0, 'max' => 365 ) );
		echo '</div>';
		Suite::card_close();
		echo '</div>';
		$this->save_button();
		echo '</form>';
		Suite::panel_close();
	}

	private function tab_captcha( array $s ) {
		Suite::panel_open( 'captcha' );
		Suite::card_open( __( 'Human check (CAPTCHA)', 'publishrank' ), 'fa-robot', __( 'Uses your own provider keys. The secret is stored encrypted and never shown again.', 'publishrank' ) );
		$this->section_form( 'captcha' );
		$providers = array( 'none' => __( 'None', 'publishrank' ) );
		foreach ( Captcha::providers() as $k => $p ) {
			$providers[ $k ] = $p['label'];
		}
		echo '<div class="prx-fields">';
		Suite::field( 'select', 'shield[captcha_provider]', $s['captcha_provider'], __( 'Provider', 'publishrank' ), '', array(), $providers );
		Suite::field( 'select', 'shield[captcha_mode]', $s['captcha_mode'], __( 'When to ask', 'publishrank' ), '', array(), array( 'challenge' => __( 'Only during a detected flood', 'publishrank' ), 'after_failures' => __( 'After failures from the same IP', 'publishrank' ), 'always' => __( 'Always', 'publishrank' ) ) );
		Suite::field( 'number', 'shield[captcha_after]', $s['captcha_after'], __( 'Failures before asking', 'publishrank' ), '', array( 'min' => 1, 'max' => 50 ) );
		Suite::field( 'text', 'shield[captcha_site_key]', $s['captcha_site_key'], __( 'Site key', 'publishrank' ) );
		Suite::field( 'password', 'shield[captcha_secret]', '', __( 'Secret key', 'publishrank' ), $s['captcha_secret'] ? sprintf( /* translators: %s: masked */ __( 'Stored: %s — leave empty to keep it.', 'publishrank' ), Crypto::mask( $s['captcha_secret'] ) ) : __( 'Not set.', 'publishrank' ), array( 'autocomplete' => 'new-password' ) );
		echo '</div><fieldset class="prx-field"><legend class="prx-label">' . esc_html__( 'Protect these forms', 'publishrank' ) . '</legend><div class="prx-row">';
		foreach ( array( 'login' => __( 'Login', 'publishrank' ), 'register' => __( 'Registration', 'publishrank' ), 'lostpassword' => __( 'Lost password', 'publishrank' ) ) as $k => $l ) {
			echo '<label><input type="checkbox" name="shield[captcha_forms][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, (array) $s['captcha_forms'], true ), true, false ) . ' /> ' . esc_html( $l ) . '</label>';
		}
		echo '</div></fieldset>';
		Suite::switch_field( 'shield[captcha_fail_open]', ! empty( $s['captcha_fail_open'] ), __( 'Allow sign-in if the provider is unreachable', 'publishrank' ), __( 'Keeps your site usable during a provider outage; the event is logged.', 'publishrank' ) );
		echo '<p class="prx-help">' . esc_html( Captcha::configured() ? __( 'Status: configured.', 'publishrank' ) : __( 'Status: not configured — no CAPTCHA is shown.', 'publishrank' ) ) . '</p>';
		$this->save_button();
		echo '</form>';
		Suite::card_close();
		Suite::panel_close();
	}

	private function tab_twofa( array $s ) {
		Suite::panel_open( 'twofa' );
		Suite::card_open( __( 'Two-factor authentication (TOTP)', 'publishrank' ), 'fa-mobile-screen-button', __( 'Works with any authenticator app. Each user enrols on their own profile screen and receives 10 one-time recovery codes.', 'publishrank' ), '<a class="prx-btn prx-btn--sm" href="' . esc_url( admin_url( 'profile.php#publishrank-2fa' ) ) . '">' . esc_html__( 'Set up my two-factor', 'publishrank' ) . '</a>' );
		$this->section_form( 'twofa' );
		Suite::switch_field( 'shield[twofa_enabled]', ! empty( $s['twofa_enabled'] ), __( 'Offer two-factor authentication', 'publishrank' ) );
		Suite::switch_field( 'shield[twofa_app_password]', ! empty( $s['twofa_app_password'] ), __( 'Let two-factor users sign in to apps with application passwords', 'publishrank' ), __( 'Password-only XML-RPC sign-in is always refused for two-factor accounts.', 'publishrank' ) );
		echo '<fieldset class="prx-field"><legend class="prx-label">' . esc_html__( 'Require two-factor for these roles', 'publishrank' ) . '</legend><div class="prx-row">';
		foreach ( wp_roles()->roles as $role => $data ) {
			echo '<label><input type="checkbox" name="shield[twofa_roles][]" value="' . esc_attr( $role ) . '"' . checked( in_array( $role, (array) $s['twofa_roles'], true ), true, false ) . ' /> ' . esc_html( translate_user_role( $data['name'] ) ) . '</label>';
		}
		echo '</div></fieldset>';
		Suite::field( 'number', 'shield[twofa_grace_days]', $s['twofa_grace_days'], __( 'Grace period (days)', 'publishrank' ), __( 'After this, users of required roles can only reach their profile until they enrol.', 'publishrank' ), array( 'min' => 0, 'max' => 90 ) );
		$this->save_button();
		echo '</form>';
		$users = get_users( array( 'meta_key' => TwoFactor::META_ENABLED, 'meta_value' => '1', 'fields' => array( 'ID', 'display_name', 'user_login' ), 'number' => 200 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		echo '<h3 style="margin:18px 0 8px">' . esc_html( sprintf( /* translators: %d: users */ __( 'Enrolled users (%d)', 'publishrank' ), count( $users ) ) ) . '</h3><div class="prx-row">';
		foreach ( $users as $u ) {
			echo Suite::badge( $u->display_name . ' (' . $u->user_login . ')', 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
		Suite::card_close();
		Suite::panel_close();
	}

	private function tab_hardening() {
		$h = Hardening::all();
		Suite::panel_open( 'hardening' );
		Suite::form_open( 'publishrank_shield_save' );
		echo '<input type="hidden" name="section" value="hardening" /><div class="prx-grid prx-grid--2"><div class="prx-stack">';
		Suite::card_open( __( 'Hide WordPress', 'publishrank' ), 'fa-mask', __( 'Remove fingerprints scanners use to pick targets.', 'publishrank' ) );
		$rows = array(
			'hide_wp_version'         => array( __( 'Hide the WordPress version', 'publishrank' ), __( 'Generator tag, feeds and ?ver= on core assets.', 'publishrank' ) ),
			'obfuscate_asset_ver'     => array( __( 'Replace every asset version with a hash', 'publishrank' ), __( 'Plugin and theme versions disappear; caches still refresh.', 'publishrank' ) ),
			'hide_plugin_versions'    => array( __( 'Hide PublishRank’s version from public pages', 'publishrank' ), '' ),
			'remove_rsd_wlw'          => array( __( 'Remove RSD and Windows Live Writer links', 'publishrank' ), '' ),
			'remove_shortlink'        => array( __( 'Remove shortlink tag and header', 'publishrank' ), '' ),
			'remove_rest_discovery'   => array( __( 'Remove REST API discovery links', 'publishrank' ), __( 'The API keeps working; it is just not advertised.', 'publishrank' ) ),
			'remove_oembed_discovery' => array( __( 'Remove oEmbed discovery links', 'publishrank' ), '' ),
			'remove_x_powered_by'     => array( __( 'Remove the X-Powered-By (PHP version) header', 'publishrank' ), '' ),
			'disable_user_sitemap'    => array( __( 'Remove usernames from the core wp-sitemap users list', 'publishrank' ), '' ),
		);
		foreach ( $rows as $k => $r ) {
			Suite::switch_field( 'hard[' . $k . ']', ! empty( $h[ $k ] ), $r[0], $r[1] );
		}
		Suite::card_close();
		Suite::card_open( __( 'Attack surface', 'publishrank' ), 'fa-shield-virus' );
		foreach ( array(
			'disable_file_editor' => array( __( 'Disable the theme and plugin file editor', 'publishrank' ), __( 'A stolen admin session cannot plant code from the dashboard.', 'publishrank' ) ),
			'disable_pingbacks'   => array( __( 'Disable pingbacks and self-pings', 'publishrank' ), __( 'Stops your site being used in pingback DDoS reflection.', 'publishrank' ) ),
			'firewall'            => array( __( 'Request firewall', 'publishrank' ), __( 'Blocks path traversal, SQL injection, script injection and PHP wrapper payloads for signed-out visitors.', 'publishrank' ) ),
			'restrict_rest'       => array( __( 'Require sign-in for the REST API', 'publishrank' ), __( 'Except the namespaces listed below.', 'publishrank' ) ),
		) as $k => $r ) {
			Suite::switch_field( 'hard[' . $k . ']', ! empty( $h[ $k ] ), $r[0], $r[1] );
		}
		Suite::field( 'textarea', 'hard[rest_allow]', $h['rest_allow'], __( 'Public REST namespaces', 'publishrank' ), __( 'One per line.', 'publishrank' ), array( 'rows' => 4 ) );
		Suite::card_close();
		echo '</div><div class="prx-stack">';
		Suite::card_open( __( 'Security headers', 'publishrank' ), 'fa-heading' );
		Suite::field( 'select', 'hard[header_frame]', $h['header_frame'], __( 'Framing (clickjacking)', 'publishrank' ), '', array(), array( 'SAMEORIGIN' => 'SAMEORIGIN', 'DENY' => 'DENY', '' => __( 'Do not send', 'publishrank' ) ) );
		Suite::switch_field( 'hard[header_permissions]', ! empty( $h['header_permissions'] ), __( 'Permissions-Policy (camera, microphone, geolocation off)', 'publishrank' ) );
		Suite::switch_field( 'hard[header_hsts]', ! empty( $h['header_hsts'] ), __( 'Strict-Transport-Security (HTTPS only)', 'publishrank' ), __( 'Only turn on when every subdomain works over HTTPS.', 'publishrank' ) );
		Suite::field( 'number', 'hard[hsts_max_age]', $h['hsts_max_age'], __( 'HSTS max-age (seconds)', 'publishrank' ), '', array( 'min' => 300 ) );
		Suite::switch_field( 'hard[header_coop]', ! empty( $h['header_coop'] ), __( 'Cross-Origin-Opener-Policy', 'publishrank' ) );
		Suite::switch_field( 'hard[header_csp_basic]', ! empty( $h['header_csp_basic'] ), __( 'Baseline Content-Security-Policy', 'publishrank' ), __( "frame-ancestors 'self', object-src 'none', base-uri 'self' and upgrade-insecure-requests.", 'publishrank' ) );
		Suite::switch_field( 'hard[admin_noindex]', ! empty( $h['admin_noindex'] ), __( 'noindex header on admin and login screens', 'publishrank' ) );
		Suite::card_close();
		echo '</div></div><div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Save hardening', 'publishrank' ) . '</button></div></form>';

		Suite::card_open( __( 'Server rules for files PHP cannot protect', 'publishrank' ), 'fa-server', __( 'readme.html, license.txt, debug.log, wp-config.php, .env, .git, PHP inside uploads, wp-includes PHP files and directory listings.', 'publishrank' ) );
		$active = (bool) get_option( 'publishrank_hardening_server_rules', 0 );
		if ( Hardening::is_apache() ) {
			Suite::form_open( 'publishrank_shield_server_rules', array( 'class' => 'prx-row' ) );
			echo '<input type="hidden" name="enable" value="' . ( $active ? '0' : '1' ) . '" /><button class="prx-btn ' . ( $active ? 'prx-btn--danger' : 'prx-btn--primary' ) . '" type="submit">' . esc_html( $active ? __( 'Remove rules from .htaccess', 'publishrank' ) : __( 'Write rules to .htaccess', 'publishrank' ) ) . '</button>' . Suite::badge( $active ? __( 'Active', 'publishrank' ) : __( 'Not applied', 'publishrank' ), $active ? 'ok' : '' ) . '</form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<pre class="prx-code" style="margin-top:12px">' . esc_html( implode( "\n", Hardening::apache_rules() ) ) . '</pre>';
		} else {
			echo '<p class="prx-help">' . esc_html__( 'Your server is not Apache/LiteSpeed. Add this to your nginx configuration (or send it to your host):', 'publishrank' ) . '</p>';
		}
		echo '<pre class="prx-code" id="prx-nginx">' . esc_html( Hardening::nginx_snippet() ) . '</pre><p><button type="button" class="prx-btn prx-btn--sm" data-prx-copy="#prx-nginx">' . esc_html__( 'Copy nginx snippet', 'publishrank' ) . '</button></p>';
		Suite::card_close();

		// Configuration checks only: live HTTP probes run on demand in the Live verification tab, not on every page view.
		$audit = Hardening::audit( false );
		Suite::card_open( __( 'Configuration audit', 'publishrank' ), 'fa-stethoscope', sprintf( /* translators: %d: score */ __( 'Score %d/100 for your settings. Use Live verification to prove each protection works from the outside.', 'publishrank' ), $audit['score'] ) );
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Check', 'publishrank' ) . '</th><th>' . esc_html__( 'Result', 'publishrank' ) . '</th><th>' . esc_html__( 'How to fix', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $audit['checks'] as $c ) {
			echo '<tr><td>' . esc_html( $c['label'] ) . '</td><td>' . ( $c['ok'] ? Suite::badge( __( 'Pass', 'publishrank' ), 'ok' ) : Suite::badge( __( 'Fix', 'publishrank' ), 'bad' ) ) . '</td><td class="prx-help">' . ( $c['ok'] ? '—' : esc_html( $c['fix'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		Suite::card_close();
		Suite::panel_close();
	}

	private function tab_accounts( array $s ) {
		Suite::panel_open( 'accounts' );
		$this->section_form( 'accounts' );
		echo '<div class="prx-grid prx-grid--2"><div class="prx-stack">';
		Suite::card_open( __( 'Password policy', 'publishrank' ), 'fa-key', __( 'Applies when privileged users set or reset a password.', 'publishrank' ) );
		Suite::switch_field( 'shield[pw_policy]', ! empty( $s['pw_policy'] ), __( 'Require strong passwords', 'publishrank' ), __( 'Minimum length, three character types (or a 20+ character passphrase), and no username, e-mail name or site name.', 'publishrank' ) );
		Suite::field( 'number', 'shield[pw_min_length]', $s['pw_min_length'], __( 'Minimum length', 'publishrank' ), '', array( 'min' => 8, 'max' => 64 ) );
		Suite::switch_field( 'shield[pw_breach_check]', ! empty( $s['pw_breach_check'] ), __( 'Refuse passwords found in data breaches', 'publishrank' ), __( 'Checks Have I Been Pwned with k-anonymity: only the first 5 characters of the password hash leave your site. If the service is unreachable the password is allowed.', 'publishrank' ) );
		echo '<fieldset class="prx-field"><legend class="prx-label">' . esc_html__( 'Apply to these roles (administrators always included)', 'publishrank' ) . '</legend><div class="prx-row">';
		foreach ( wp_roles()->roles as $role => $data ) {
			echo '<label><input type="checkbox" name="shield[pw_roles][]" value="' . esc_attr( $role ) . '"' . checked( in_array( $role, (array) $s['pw_roles'], true ), true, false ) . ' /> ' . esc_html( translate_user_role( $data['name'] ) ) . '</label>';
		}
		echo '</div></fieldset>';
		Suite::card_close();
		echo '</div><div class="prx-stack">';
		Suite::card_open( __( 'Sessions', 'publishrank' ), 'fa-clock-rotate-left', __( 'For the same privileged roles.', 'publishrank' ) );
		Suite::field( 'number', 'shield[max_sessions]', $s['max_sessions'], __( 'Maximum simultaneous sessions per user', 'publishrank' ), __( 'Signing in ends the oldest sessions beyond this number. 0 = unlimited.', 'publishrank' ), array( 'min' => 0, 'max' => 20 ) );
		Suite::field( 'number', 'shield[idle_minutes]', $s['idle_minutes'], __( 'Sign out after inactivity (minutes)', 'publishrank' ), __( 'An open tab polling in the background does not count as activity. 0 = off.', 'publishrank' ), array( 'min' => 0, 'max' => 1440 ) );
		Suite::card_close();
		echo '</div></div>';
		$this->save_button( __( 'Save account protection', 'publishrank' ) );
		echo '</form>';
		Suite::panel_close();
	}

	private function tab_verify() {
		Suite::panel_open( 'verify' );
		$r       = Verifier::last();
		$actions = '';
		Suite::card_open(
			__( 'Live verification', 'publishrank' ),
			'fa-user-ninja',
			__( 'Tests every protection from the outside, as an anonymous visitor, and records the evidence. It never submits real sign-ins, so it cannot lock anyone out. Takes 5–20 seconds.', 'publishrank' )
		);
		echo '<div class="prx-row">';
		Suite::form_open( 'publishrank_shield_verify' );
		echo '<button class="prx-btn prx-btn--primary" type="submit"><span class="fa-solid fa-play" aria-hidden="true"></span>' . esc_html__( 'Run live verification', 'publishrank' ) . '</button></form>';
		if ( $r ) {
			Suite::form_open( 'publishrank_shield_verify_export' );
			echo '<button class="prx-btn" type="submit"><span class="fa-solid fa-file-arrow-down" aria-hidden="true"></span>' . esc_html__( 'Download signed report (JSON)', 'publishrank' ) . '</button></form>';
		}
		echo '</div>';
		if ( ! $r ) {
			echo '<div class="prx-empty"><span class="fa-solid fa-flask" aria-hidden="true"></span><strong>' . esc_html__( 'No verification has been run yet', 'publishrank' ) . '</strong></div>';
			Suite::card_close();
			Suite::panel_close();
			return;
		}
		echo '<div class="prx-grid prx-grid--4" style="margin-top:14px">';
		Suite::kpi( __( 'Verified score', 'publishrank' ), (int) $r['score'] . '/100', '', true );
		Suite::kpi( __( 'Passed', 'publishrank' ), number_format_i18n( $r['counts']['pass'] ) );
		Suite::kpi( __( 'Warnings', 'publishrank' ), number_format_i18n( $r['counts']['warn'] ) );
		Suite::kpi( __( 'Failed', 'publishrank' ), number_format_i18n( $r['counts']['fail'] ) );
		echo '</div>';
		/* translators: 1: date, 2: seconds */
		echo '<p class="prx-help">' . esc_html( sprintf( __( 'Run %1$s (%2$s s). Report signature: ', 'publishrank' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $r['time'] ) ), $r['seconds'] ) ) . '<code>' . esc_html( substr( (string) $r['signature'], 0, 16 ) ) . '…</code> ' . ( Verifier::check( $r ) ? Suite::badge( __( 'Signature valid', 'publishrank' ), 'ok' ) : Suite::badge( __( 'Signature invalid', 'publishrank' ), 'bad' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$tones = array( 'pass' => 'ok', 'warn' => 'warn', 'fail' => 'bad', 'skip' => '' );
		$names = array( 'pass' => __( 'Pass', 'publishrank' ), 'warn' => __( 'Warning', 'publishrank' ), 'fail' => __( 'Fail', 'publishrank' ), 'skip' => __( 'Skipped', 'publishrank' ) );
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Protection', 'publishrank' ) . '</th><th>' . esc_html__( 'Severity', 'publishrank' ) . '</th><th>' . esc_html__( 'Result', 'publishrank' ) . '</th><th>' . esc_html__( 'Evidence', 'publishrank' ) . '</th><th>' . esc_html__( 'How to fix', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $r['results'] as $row ) {
			echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td>' . esc_html( ucfirst( $row['severity'] ) ) . '</td><td>' . Suite::badge( $names[ $row['status'] ], $tones[ $row['status'] ] ) . '</td><td class="prx-mono prx-help">' . esc_html( $row['evidence'] ) . '</td><td class="prx-help">' . ( $row['fix'] ? esc_html( $row['fix'] ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		Suite::card_close();
		Suite::panel_close();
	}

	private function tab_log( $manage ) {
		Suite::panel_open( 'log' );
		$f     = $this->log_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res   = SecurityLog::query( $f + array( 'paged' => $paged, 'per_page' => 50 ) );
		echo '<form method="get" class="prx-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input class="prx-input prx-search" type="search" name="l_search" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'Search IP, username, path, details… (press /)', 'publishrank' ) . '" />';
		echo '<select name="l_event"><option value="">' . esc_html__( 'All events', 'publishrank' ) . '</option>';
		foreach ( SecurityLog::events() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['event'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><input type="date" name="l_from" value="' . esc_attr( $f['from'] ) . '" aria-label="' . esc_attr__( 'From', 'publishrank' ) . '" /><input type="date" name="l_to" value="' . esc_attr( $f['to'] ) . '" aria-label="' . esc_attr__( 'To', 'publishrank' ) . '" /><button class="prx-btn" type="submit">' . esc_html__( 'Filter', 'publishrank' ) . '</button></form>';
		echo '<div class="prx-row" style="margin-bottom:10px">';
		Suite::form_open( 'publishrank_shield_export' );
		foreach ( $f as $k => $v ) {
			echo '<input type="hidden" name="l_' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
		}
		echo '<button class="prx-btn prx-btn--sm" type="submit"><span class="fa-solid fa-file-csv" aria-hidden="true"></span>' . esc_html__( 'Export CSV', 'publishrank' ) . '</button></form>';
		if ( $manage ) {
			Suite::form_open( 'publishrank_shield_clear_log', array( 'data-prx-confirm' => __( 'Delete the whole security log?', 'publishrank' ), 'data-prx-type' => 'CLEAR', 'data-prx-danger' => '1' ) );
			echo '<button class="prx-btn prx-btn--sm prx-btn--danger" type="submit">' . esc_html__( 'Clear log', 'publishrank' ) . '</button></form>';
		}
		echo '<span class="prx-spacer"></span><span class="prx-help">' . esc_html( sprintf( /* translators: %s: count */ __( '%s events', 'publishrank' ), number_format_i18n( $res['total'] ) ) ) . '</span></div>';
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'When', 'publishrank' ) . '</th><th>' . esc_html__( 'Event', 'publishrank' ) . '</th><th>IP</th><th>' . esc_html__( 'User', 'publishrank' ) . '</th><th class="prx-hide-mobile">' . esc_html__( 'Path', 'publishrank' ) . '</th><th>' . esc_html__( 'Details', 'publishrank' ) . '</th></tr></thead><tbody>';
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="6"><div class="prx-empty"><span class="fa-solid fa-clipboard-check" aria-hidden="true"></span><strong>' . esc_html__( 'No events match', 'publishrank' ) . '</strong></div></td></tr>';
		}
		$labels = SecurityLog::events();
		$tones  = array( 'alert' => 'bad', 'warn' => 'warn', 'notice' => 'info', 'info' => '' );
		foreach ( $res['rows'] as $r ) {
			echo '<tr><td title="' . esc_attr( $r['created_at'] . ' UTC' ) . '">' . esc_html( Suite::ago( $r['created_at'] ) ) . '</td><td>' . Suite::badge( isset( $labels[ $r['event'] ] ) ? $labels[ $r['event'] ] : $r['event'], $tones[ $r['severity'] ] ?? '' ) . '</td><td class="prx-mono"><a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'l_search' => $r['ip'] ), admin_url( 'admin.php' ) ) . '#log' ) . '">' . esc_html( $r['ip'] ) . '</a></td><td>' . esc_html( $r['username'] ) . '</td><td class="prx-mono prx-hide-mobile">' . esc_html( $r['path'] ) . '</td><td>' . esc_html( $r['detail'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		Suite::pagination( $res['total'], 50, $paged );
		Suite::panel_close();
	}
}
