<?php
/** Admin controls for the local Traffic Guard. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Security\TrafficGuard;

defined( 'ABSPATH' ) || exit;

final class TrafficGuardPage {
	const SLUG = 'publishrank-traffic-guard';
	const ACTION = 'publishrank_traffic_guard_save';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Traffic Guard', 'publishrank' ), __( 'Traffic Guard', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function save() {
		AdminAssets::guard( self::ACTION );
		$blocked = TrafficGuard::inspect_networks( $this->post_value( 'blocked_ips' ) );
		$allowed = TrafficGuard::inspect_networks( $this->post_value( 'allow_ips' ) );
		$proxies = TrafficGuard::inspect_networks( $this->post_value( 'trusted_proxies' ) );
		$countries = TrafficGuard::inspect_country_codes( $this->post_value( 'blocked_countries' ) );
		$invalid = array_merge( $blocked['invalid'], $allowed['invalid'], $proxies['invalid'], $countries['invalid'] );
		if ( $invalid ) {
			$sample = implode( ', ', array_slice( array_unique( $invalid ), 0, 5 ) );
			$message = sprintf( /* translators: %s: invalid IP, CIDR or country entries. */ __( 'No changes were saved. Check these invalid or excess entries: %s', 'publishrank' ), $sample );
			AdminAssets::done( self::SLUG, 'error', $message );
		}

		$settings = array(
			'enabled'           => isset( $_POST['enabled'] ) && '1' === (string) wp_unslash( $_POST['enabled'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'blocked_ips'       => $blocked['valid'],
			'allow_ips'         => $allowed['valid'],
			'trusted_proxies'   => $proxies['valid'],
			'blocked_countries' => $countries['valid'],
		);
		update_option( TrafficGuard::OPTION, $settings, false );
		AdminAssets::done( self::SLUG, 'success', __( 'Traffic Guard settings saved.', 'publishrank' ) );
	}

	private function post_value( $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}
		return (string) wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = TrafficGuard::settings();
		$action = admin_url( 'admin-post.php' );
		?>
		<div class="wrap prtg-wrap">
			<?php AdminAssets::header( __( 'Traffic Guard', 'publishrank' ), __( 'Local IP and country rules for public-facing page requests.', 'publishrank' ) ); ?>
			<?php AdminAssets::render_flash(); ?>
			<div class="prtg-notice" role="note">
				<span class="prtg-notice__icon fa-solid fa-shield-halved" aria-hidden="true"></span>
				<div><strong><?php esc_html_e( 'Use country rules as a coarse access signal, not a compliance guarantee.', 'publishrank' ); ?></strong><p><?php esc_html_e( 'IP geolocation can be inaccurate, and broad rules can block readers or search crawlers from the affected networks. This feature is disabled until you turn it on, works on public front-end requests, and is not a substitute for a CDN firewall or DDoS protection.', 'publishrank' ); ?></p></div>
			</div>
			<form class="prtg-form" method="post" action="<?php echo esc_url( $action ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<section class="prtg-card prtg-card--primary">
					<div class="prtg-card__heading"><span class="prtg-icon prtg-icon--plum fa-solid fa-toggle-on" aria-hidden="true"></span><div><p class="prtg-kicker"><?php esc_html_e( 'Protection status', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Enable Traffic Guard', 'publishrank' ); ?></h2></div></div>
					<label class="prtg-toggle"><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> /><span class="prtg-toggle__track" aria-hidden="true"></span><span><?php esc_html_e( 'Apply the rules below to front-end page requests.', 'publishrank' ); ?></span></label>
					<p class="description"><?php esc_html_e( 'WordPress administrators, wp-admin, login, REST, XML-RPC, cron, feeds, robots.txt, llms.txt, and XML sitemap URLs are not blocked. Other visitors, including non-administrator accounts, follow the rules. No visitor IP is stored by Traffic Guard.', 'publishrank' ); ?></p>
				</section>

				<div class="prtg-grid">
					<section class="prtg-card">
						<div class="prtg-card__heading"><span class="prtg-icon prtg-icon--rose fa-solid fa-ban" aria-hidden="true"></span><div><p class="prtg-kicker"><?php esc_html_e( 'Network rules', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Block IP addresses', 'publishrank' ); ?></h2></div></div>
						<p><?php esc_html_e( 'Enter one IPv4 address, IPv6 address, or CIDR network per line. Examples:', 'publishrank' ); ?> <code>203.0.113.24</code>, <code>2001:db8::24</code>, <code>198.51.100.0/24</code>.</p>
						<label for="prtg-blocked-ips"><strong><?php esc_html_e( 'Blocked IPs and networks', 'publishrank' ); ?></strong></label>
						<textarea id="prtg-blocked-ips" name="blocked_ips" rows="7" class="large-text code" spellcheck="false" placeholder="203.0.113.24&#10;198.51.100.0/24"><?php echo esc_textarea( implode( "\n", $settings['blocked_ips'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Avoid broad networks unless you intend to restrict every visitor in that range. A /0 network matches all public IP addresses.', 'publishrank' ); ?></p>
					</section>

					<section class="prtg-card">
						<div class="prtg-card__heading"><span class="prtg-icon prtg-icon--sage fa-solid fa-user-check" aria-hidden="true"></span><div><p class="prtg-kicker"><?php esc_html_e( 'Exceptions', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Allow trusted visitors', 'publishrank' ); ?></h2></div></div>
						<p><?php esc_html_e( 'Allowlist entries take precedence over blocked IP and country rules.', 'publishrank' ); ?></p>
						<label for="prtg-allow-ips"><strong><?php esc_html_e( 'Allowed IPs and networks', 'publishrank' ); ?></strong></label>
						<textarea id="prtg-allow-ips" name="allow_ips" rows="7" class="large-text code" spellcheck="false" placeholder="203.0.113.10"><?php echo esc_textarea( implode( "\n", $settings['allow_ips'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'This exception applies to public-page requests. Administrator accounts always bypass these rules.', 'publishrank' ); ?></p>
					</section>

					<section class="prtg-card">
						<div class="prtg-card__heading"><span class="prtg-icon prtg-icon--brass fa-solid fa-earth-americas" aria-hidden="true"></span><div><p class="prtg-kicker"><?php esc_html_e( 'Geographic rules', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Block countries', 'publishrank' ); ?></h2></div></div>
						<p><?php esc_html_e( 'Enter ISO 3166-1 alpha-2 country codes, one per line. Cloudflare special values XX (unknown) and T1 (Tor) are also accepted.', 'publishrank' ); ?></p>
						<label for="prtg-blocked-countries"><strong><?php esc_html_e( 'Blocked country codes', 'publishrank' ); ?></strong></label>
						<textarea id="prtg-blocked-countries" name="blocked_countries" rows="5" class="large-text code" spellcheck="false" placeholder="T1&#10;XX"><?php echo esc_textarea( implode( "\n", $settings['blocked_countries'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Country rules use only a valid CF-IPCountry header received from a trusted proxy. Missing, malformed, or untrusted data fails open; the special code XX is blocked only if you explicitly list it.', 'publishrank' ); ?></p>
					</section>

					<section class="prtg-card">
						<div class="prtg-card__heading"><span class="prtg-icon prtg-icon--ink fa-solid fa-server" aria-hidden="true"></span><div><p class="prtg-kicker"><?php esc_html_e( 'Header trust boundary', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Trusted proxy networks', 'publishrank' ); ?></h2></div></div>
						<p><?php esc_html_e( 'Enter the exact proxy IPv4/IPv6 ranges that connect directly to this WordPress server. Do not add ranges unless your host or CDN confirms them.', 'publishrank' ); ?></p>
						<label for="prtg-trusted-proxies"><strong><?php esc_html_e( 'Trusted proxy CIDRs', 'publishrank' ); ?></strong></label>
						<textarea id="prtg-trusted-proxies" name="trusted_proxies" rows="7" class="large-text code" spellcheck="false" placeholder="198.51.100.0/24"><?php echo esc_textarea( implode( "\n", $settings['trusted_proxies'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Without a trusted proxy match, PublishRank uses REMOTE_ADDR and ignores country and forwarded-IP headers. For Cloudflare, enable IP Geolocation and use its current official edge ranges. Confirm those ranges with Cloudflare before adding them here.', 'publishrank' ); ?></p>
					</section>
				</div>
				<div class="prtg-savebar"><div><strong><?php echo esc_html( $settings['enabled'] ? __( 'Traffic Guard is enabled', 'publishrank' ) : __( 'Traffic Guard is disabled', 'publishrank' ) ); ?></strong><span><?php echo esc_html( sprintf( /* translators: 1: blocked IP rules, 2: blocked countries. */ __( '%1$d IP/network rules · %2$d country rules', 'publishrank' ), count( $settings['blocked_ips'] ), count( $settings['blocked_countries'] ) ) ); ?></span></div><button type="submit" class="button button-primary"><span class="fa-solid fa-floppy-disk" aria-hidden="true"></span> <?php esc_html_e( 'Save Traffic Guard', 'publishrank' ); ?></button></div>
			</form>
		</div>
		<?php
	}
}
