<?php
/** Supabase sign-in and integration bootstrap screen. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Auth\SupabaseAuth;
use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class AuthPage {
	const SLUG = 'publishrank-auth';
	private $auth;

	public function __construct() { $this->auth = new SupabaseAuth(); }
	public function register() { add_action( 'admin_menu', array( $this, 'menu' ), 14 ); $this->auth->register(); }
	public function menu() { add_submenu_page( Branding::MENU_SLUG, __( 'Publisher Account', 'publishrank' ), __( 'Publisher Account', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) ); }

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="wrap publishrank-auth-page">';
			AdminAssets::header( __( 'Publisher Account', 'publishrank' ), __( 'Sign in or sign up with a provider to link this existing WordPress administrator to PublishRank.', 'publishrank' ) );
		$this->render_notice();
		$this->render_configuration();
		if ( SupabaseAuth::configured() ) { $this->render_session(); }
		$this->render_recovery();
		echo '</div>';
	}

	private function render_configuration() {
		$central = SupabaseAuth::uses_central_config();
		$project = $central ? SupabaseAuth::project_url() : (string) get_option( SupabaseAuth::URL_OPTION, '' );
		$key = (string) get_option( SupabaseAuth::KEY_OPTION, '' );
		$enabled = (bool) get_option( SupabaseAuth::ENABLED_OPTION, false );
		$provider_callback = '' !== $project ? untrailingslashit( $project ) . '/auth/v1/callback' : 'https://<project-ref>.supabase.co/auth/v1/callback';
		echo '<section class="rhs-card pr-auth-setup"><h2>' . esc_html__( 'Connect Supabase Auth', 'publishrank' ) . '</h2>';
		if ( $central ) {
			echo '<p>' . esc_html__( 'PublishRank is preconfigured with its shared Supabase identity project. This site does not need a Supabase URL or key. The publishable key is intended for public client identification; no service_role key is used.', 'publishrank' ) . '</p><ol><li>' . esc_html__( 'Ensure this site’s exact callback URL below is included in the shared project’s Supabase Auth redirect allow-list.', 'publishrank' ) . '</li><li>' . esc_html__( 'Configure Google and any other desired OAuth provider credentials in the shared Supabase project.', 'publishrank' ) . '</li><li>' . esc_html__( 'Link each existing WordPress administrator separately. First-time Supabase identity creation depends on the shared project’s sign-up settings.', 'publishrank' ) . '</li></ol>';
		} else {
			echo '<p>' . esc_html__( 'Use a Supabase project you manage for this site. The publishable/anon key is intended for public client identification; never enter a service_role key.', 'publishrank' ) . '</p><ol><li>' . esc_html__( 'Allow the exact callback URL below in Supabase Auth URL Configuration.', 'publishrank' ) . '</li><li>' . esc_html__( 'Enable and configure each desired OAuth provider in Supabase Auth.', 'publishrank' ) . '</li><li>' . esc_html__( 'Enter the project URL and publishable/anon key, then link each existing WordPress administrator.', 'publishrank' ) . '</li></ol>';
		}
		echo '<p><strong>' . esc_html__( 'Supabase redirect URL allow-list entry', 'publishrank' ) . ':</strong><br /><code class="pr-gsc-callback">' . esc_html( SupabaseAuth::callback_url() ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'OAuth provider callback URI (configure this at each provider, such as Google)', 'publishrank' ) . ':</strong><br /><code class="pr-gsc-callback">' . esc_html( $provider_callback ) . '</code></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_supabase_save">'; wp_nonce_field( 'publishrank_supabase_save' );
		if ( $central ) {
			echo '<p class="description">' . esc_html__( 'The project URL and publishable key are managed centrally and are not editable on this site.', 'publishrank' ) . '</p>';
		} else {
			echo '<p><label for="publishrank-supabase-url"><strong>' . esc_html__( 'Project URL', 'publishrank' ) . '</strong></label><br /><input class="large-text code" type="url" id="publishrank-supabase-url" name="project_url" value="' . esc_attr( $project ) . '" placeholder="https://your-project.supabase.co" required /></p><p><label for="publishrank-supabase-key"><strong>' . esc_html__( 'Publishable / anon key', 'publishrank' ) . '</strong></label><br /><input class="large-text code" type="password" id="publishrank-supabase-key" name="anon_key" value="' . esc_attr( $key ) . '" autocomplete="new-password" required /></p>';
		}
		echo '<input type="hidden" name="auth_enabled" value="0"><label class="pr-auth-toggle"><input type="checkbox" name="auth_enabled" value="1" ' . checked( $enabled, true, false ) . ' /> ' . esc_html__( 'Require a linked Supabase identity for PublishRank admin screens after save', 'publishrank' ) . '</label><p class="description">' . esc_html__( 'The sign-in gate is optional. Local SEO, schema, metadata and sitemap tools remain usable when it is disabled. If enabled, it never replaces WordPress roles, blocks the public site or locks unrelated wp-admin screens. An emergency WordPress-admin bypass is provided below.', 'publishrank' ) . '</p>'; submit_button( __( 'Save identity settings', 'publishrank' ), 'primary', 'submit', false ); echo '</form></section>';
	}

	private function render_session() {
		$user = wp_get_current_user(); $info = SupabaseAuth::session_info( $user->ID ); $linked = ! empty( $info['subject'] ); $enabled = SupabaseAuth::enabled();
		echo '<section class="rhs-card pr-auth-session"><h2>' . esc_html__( 'This WordPress administrator', 'publishrank' ) . '</h2>';
		if ( $linked ) {
			echo '<p><span class="rhs-pill rhs-pill--pass">' . esc_html__( 'Identity linked', 'publishrank' ) . '</span> ' . esc_html( $info['email'] ?: __( 'Email not shared by provider', 'publishrank' ) ) . '</p><p>' . esc_html( sprintf( __( 'Provider: %1$s · WordPress user: %2$s', 'publishrank' ), $info['provider'] ?: __( 'OAuth', 'publishrank' ), $user->user_login ) ) . '</p><p class="description">' . esc_html__( 'The link is bound to this WordPress user ID and Supabase subject. Matching email addresses alone are never used to link identities.', 'publishrank' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_supabase_unlink">'; wp_nonce_field( 'publishrank_supabase_unlink' ); submit_button( __( 'Unlink this administrator', 'publishrank' ), 'secondary', 'submit', false ); echo '</form>';
		} else {
			echo '<p><span class="rhs-pill rhs-pill--warning">' . esc_html__( 'Not linked', 'publishrank' ) . '</span> ' . esc_html__( 'Link the current administrator before enabling the gate. This does not create or promote a WordPress account.', 'publishrank' ) . '</p>';
			$enabled_map = SupabaseAuth::enabled_providers();
			$google_on = null === $enabled_map || ! empty( $enabled_map['google'] );
			$this->render_google_button( $google_on );
				$labels = array(
					'google'        => 'Google',
					'apple'         => 'Apple',
					'azure'         => 'Microsoft / Azure',
					'bitbucket'     => 'Bitbucket',
					'discord'       => 'Discord',
					'facebook'      => 'Facebook',
					'figma'         => 'Figma',
					'github'        => 'GitHub',
					'gitlab'        => 'GitLab',
					'kakao'         => 'Kakao',
					'keycloak'      => 'Keycloak',
					'linkedin_oidc' => 'LinkedIn',
					'notion'        => 'Notion',
					'slack_oidc'    => 'Slack',
					'spotify'       => 'Spotify',
					'twitter'       => 'X / Twitter',
					'twitch'        => 'Twitch',
					'workos'        => 'WorkOS',
					'zoom'          => 'Zoom',
				);
				$icons = array(
					'google'        => 'fa-brands fa-google',
					'apple'         => 'fa-brands fa-apple',
					'azure'         => 'fa-brands fa-microsoft',
					'bitbucket'     => 'fa-brands fa-bitbucket',
					'discord'       => 'fa-brands fa-discord',
					'facebook'      => 'fa-brands fa-facebook',
					'figma'         => 'fa-brands fa-figma',
					'github'        => 'fa-brands fa-github',
					'gitlab'        => 'fa-brands fa-gitlab',
					'kakao'         => 'fa-solid fa-comment-dots',
					'keycloak'      => 'fa-solid fa-key',
					'linkedin_oidc' => 'fa-brands fa-linkedin-in',
					'notion'        => 'fa-brands fa-notion',
					'slack_oidc'    => 'fa-brands fa-slack',
					'spotify'       => 'fa-brands fa-spotify',
					'twitter'       => 'fa-brands fa-x-twitter',
					'twitch'        => 'fa-brands fa-twitch',
					'workos'        => 'fa-solid fa-shield-halved',
					'zoom'          => 'fa-solid fa-video',
				);
				ob_start(); $others = 0;
				foreach ( $labels as $provider => $label ) {
					if ( 'google' === $provider || ( is_array( $enabled_map ) && empty( $enabled_map[ $provider ] ) ) ) { continue; }
					$others++;
					$icon = isset( $icons[ $provider ] ) ? $icons[ $provider ] : 'fa-solid fa-key';
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_supabase_start"><input type="hidden" name="provider" value="' . esc_attr( $provider ) . '">';
					wp_nonce_field( 'publishrank_supabase_start' );
					echo '<button type="submit" class="button button-secondary pr-provider-button pr-provider-button--' . esc_attr( $provider ) . '"><i class="' . esc_attr( $icon ) . '" aria-hidden="true"></i><span>' . esc_html( sprintf( __( 'Sign in or sign up with %s', 'publishrank' ), $label ) ) . '</span></button></form>';
				}
			$others_html = ob_get_clean();
			if ( $others ) { echo '<details class="pr-other-providers"><summary>' . esc_html__( 'Other sign-in options', 'publishrank' ) . '</summary><div class="pr-provider-grid">' . $others_html . '</div></details>'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped forms above.
			echo '<p class="description">' . esc_html__( 'Only providers enabled and configured in the Supabase project will work. Email verification and provider policies are managed by the project owner.', 'publishrank' ) . '</p>';
		}
		echo '<p>' . esc_html( $enabled ? __( 'The additional PublishRank admin sign-in gate is enabled.', 'publishrank' ) : __( 'The additional sign-in gate is disabled; existing WordPress administrator access remains in place.', 'publishrank' ) ) . '</p></section>';
	}

	private function render_google_button( $on ) {
		$g = '<svg class="pr-google-btn__g" viewBox="0 0 48 48" width="20" height="20" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
		echo '<form class="pr-google-signin" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_supabase_start"><input type="hidden" name="provider" value="google">'; wp_nonce_field( 'publishrank_supabase_start' );
		echo '<button type="submit" class="pr-google-btn"' . ( $on ? '' : ' disabled' ) . '>' . $g . '<span>' . esc_html__( 'Continue with Google', 'publishrank' ) . '</span></button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		if ( ! $on ) { echo '<p class="description">' . esc_html__( 'Google sign-in is not enabled on the Supabase project yet. Enable the Google provider in Supabase Authentication → Sign In / Providers.', 'publishrank' ) . '</p>'; }
	}

	private function render_recovery() {
		$bypass = (bool) get_option( 'publishrank_supabase_emergency_bypass', false );
		if ( $bypass ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Emergency local WordPress-admin bypass is active. Supabase is not currently required for PublishRank screens.', 'publishrank' ) . '</p></div>'; }
		else { echo '<section class="rhs-card pr-auth-recovery"><h2>' . esc_html__( 'Emergency local recovery', 'publishrank' ) . '</h2><p>' . esc_html__( 'If the provider is unavailable or an administrator is locked out, this action disables only the extra Supabase gate. WordPress administrator authentication and capability checks remain required.', 'publishrank' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_supabase_emergency_bypass">'; wp_nonce_field( 'publishrank_supabase_emergency_bypass' ); submit_button( __( 'Enable local admin recovery', 'publishrank' ), 'secondary', 'submit', false ); echo '</form></section>'; }
	}

	private function render_notice() {
		$key = 'publishrank_supabase_notice_' . get_current_user_id(); $notice = get_transient( $key ); if ( ! is_array( $notice ) || empty( $notice['message'] ) ) { return; } delete_transient( $key ); echo '<div class="notice notice-' . esc_attr( $notice['type'] ?? 'info' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}
}
