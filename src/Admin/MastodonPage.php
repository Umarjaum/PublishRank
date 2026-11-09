<?php
/**
 * PublishRank → Mastodon: connect an account, verify the profile and control automatic sharing.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Social\Mastodon;

defined( 'ABSPATH' ) || exit;

final class MastodonPage {
	const SLUG = 'publishrank-mastodon';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 15 );
		add_action( 'admin_post_publishrank_mastodon_save', array( $this, 'save' ) );
		add_action( 'admin_post_publishrank_mastodon_verify', array( $this, 'verify' ) );
		add_action( 'admin_post_publishrank_mastodon_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_notices', array( $this, 'editor_notice' ) );
		add_action( 'show_user_profile', array( $this, 'user_field' ) );
		add_action( 'edit_user_profile', array( $this, 'user_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_field' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Mastodon', 'publishrank' ), __( 'Mastodon', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = Mastodon::settings();
		$account = Mastodon::account();
		echo '<div class="wrap publishrank-mastodon">';
		AdminAssets::header( __( 'Mastodon', 'publishrank' ), __( 'Verify your Mastodon profile and share new posts to the fediverse automatically.', 'publishrank' ) );
		$this->notice();

		if ( ! Mastodon::enabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The Mastodon feature is off. Turn it on below or under PublishRank → Features.', 'publishrank' ) . '</p></div>';
		}

		// Connection status.
		echo '<div class="rhs-grid"><section class="rhs-card"><h2>' . esc_html__( 'Account', 'publishrank' ) . '</h2>';
		if ( Mastodon::connected() && ! empty( $account['acct'] ) ) {
			if ( ! empty( $account['avatar'] ) ) {
				echo '<img src="' . esc_url( $account['avatar'] ) . '" alt="" width="48" height="48" style="border-radius:50%;float:left;margin-right:12px" />';
			}
			$server = (string) wp_parse_url( $s['instance'], PHP_URL_HOST );
			echo '<p><strong>' . esc_html( '' !== $account['display'] ? $account['display'] : $account['username'] ) . '</strong><br /><a href="' . esc_url( $account['url'] ) . '" target="_blank" rel="noopener noreferrer">@' . esc_html( $account['username'] . '@' . $server ) . '</a></p>';
			/* translators: %s: number of followers. */
			echo '<p style="clear:both">' . esc_html( sprintf( _n( '%s follower', '%s followers', (int) $account['followers'], 'publishrank' ), number_format_i18n( (int) $account['followers'] ) ) ) . '</p>';
			if ( ! empty( $account['verified'] ) ) {
				echo '<p>✅ <strong>' . esc_html__( 'Verified', 'publishrank' ) . '</strong> — ' . esc_html__( 'Mastodon shows a green check next to your website link.', 'publishrank' ) . '</p>';
			} elseif ( ! empty( $account['has_field'] ) ) {
				echo '<p>⏳ ' . esc_html__( 'Your website is on your profile but not verified yet. Mastodon re-checks when you save your profile — open Edit profile on Mastodon and click Save, then press "Check again".', 'publishrank' ) . '</p>';
			} else {
				echo '<p>⚠️ ' . esc_html__( 'Not verified yet. Follow the steps under "Verify your profile".', 'publishrank' ) . '</p>';
			}
			$this->button( 'publishrank_mastodon_verify', __( 'Check again', 'publishrank' ), 'secondary' );
			echo ' ';
			$this->button( 'publishrank_mastodon_disconnect', __( 'Disconnect', 'publishrank' ), 'link-delete' );
		} else {
			echo '<p>' . esc_html__( 'Not connected.', 'publishrank' ) . '</p>';
			echo '<ol><li>' . esc_html__( 'On your Mastodon server open Preferences → Development → New application.', 'publishrank' ) . '</li>';
			echo '<li>' . esc_html__( 'Name it "PublishRank", tick only the read:accounts and write:statuses permissions, and save.', 'publishrank' ) . '</li>';
			echo '<li>' . esc_html__( 'Open the application, copy "Your access token" and paste it below with your server address.', 'publishrank' ) . '</li></ol>';
		}
		echo '</section>';

		// Verification steps.
		$home = home_url( '/' );
		echo '<section class="rhs-card"><h2>' . esc_html__( 'Verify your profile', 'publishrank' ) . '</h2>';
		echo '<p>' . esc_html__( 'PublishRank adds the rel="me" link Mastodon looks for to every page of your site, so you only need one step on Mastodon:', 'publishrank' ) . '</p>';
		echo '<ol><li>' . esc_html__( 'On Mastodon open Edit profile → Extra fields.', 'publishrank' ) . '</li>';
		/* translators: %s: site URL. */
		echo '<li>' . wp_kses_post( sprintf( __( 'Add a field named "Website" with the value %s and save.', 'publishrank' ), '<code>' . esc_html( $home ) . '</code>' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Come back here and press "Check again". A green check appears on your Mastodon profile within a few minutes.', 'publishrank' ) . '</li></ol>';
		echo '<p class="description">' . esc_html__( 'Article pages also get a fediverse:creator tag, so Mastodon credits your account (or the author\'s, if they added theirs on their WordPress profile) under link previews.', 'publishrank' ) . '</p>';
		echo '</section></div>';

		// Settings form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'publishrank_mastodon_save' );
		echo '<input type="hidden" name="action" value="publishrank_mastodon_save" />';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Mastodon feature', 'publishrank' ) . '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( Mastodon::enabled(), true, false ) . ' /> ' . esc_html__( 'Turn on Mastodon verification and sharing', 'publishrank' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="pr-mastodon-instance">' . esc_html__( 'Server', 'publishrank' ) . '</label></th><td><input type="text" class="regular-text" id="pr-mastodon-instance" name="instance" value="' . esc_attr( $s['instance'] ) . '" placeholder="mastodon.social" /></td></tr>';
		echo '<tr><th scope="row"><label for="pr-mastodon-token">' . esc_html__( 'Access token', 'publishrank' ) . '</label></th><td><input type="password" class="regular-text" id="pr-mastodon-token" name="token" value="" autocomplete="off" placeholder="' . esc_attr( Mastodon::connected() ? __( 'Saved — leave empty to keep', 'publishrank' ) : '' ) . '" /><p class="description">' . esc_html__( 'Stored encrypted and never shown again.', 'publishrank' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="pr-mastodon-profile">' . esc_html__( 'Profile to verify', 'publishrank' ) . '</label></th><td><input type="text" class="regular-text" id="pr-mastodon-profile" name="profile_url" value="' . esc_attr( $s['profile_url'] ) . '" placeholder="https://mastodon.social/@yourpublication" /><p class="description">' . esc_html__( 'Profile URL or @user@server. Filled in automatically after connecting. You can verify a profile without connecting a token.', 'publishrank' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Automatic sharing', 'publishrank' ) . '</th><td><label><input type="checkbox" name="auto_share" value="1" ' . checked( ! empty( $s['auto_share'] ), true, false ) . ' /> ' . esc_html__( 'Share new posts when they are published (can be changed per post)', 'publishrank' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Content types', 'publishrank' ) . '</th><td>';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			echo '<label style="margin-right:14px"><input type="checkbox" name="post_types[]" value="' . esc_attr( $type->name ) . '" ' . checked( in_array( $type->name, (array) $s['post_types'], true ), true, false ) . ' /> ' . esc_html( $type->labels->singular_name ) . '</label>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="pr-mastodon-template">' . esc_html__( 'Message format', 'publishrank' ) . '</label></th><td><textarea class="large-text" rows="5" id="pr-mastodon-template" name="template">' . esc_textarea( $s['template'] ) . '</textarea><p class="description">' . esc_html__( 'Variables: {title} {excerpt} {url} {hashtags} {site}. Long excerpts are shortened to fit 500 characters.', 'publishrank' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Hashtags', 'publishrank' ) . '</th><td><label><input type="checkbox" name="hashtags" value="1" ' . checked( ! empty( $s['hashtags'] ), true, false ) . ' /> ' . esc_html__( 'Turn the post\'s tags into hashtags (up to 5)', 'publishrank' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="pr-mastodon-visibility">' . esc_html__( 'Who can see shared posts', 'publishrank' ) . '</label></th><td><select id="pr-mastodon-visibility" name="visibility">';
		foreach ( array( 'public' => __( 'Public', 'publishrank' ), 'unlisted' => __( 'Unlisted', 'publishrank' ), 'private' => __( 'Followers only', 'publishrank' ) ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $s['visibility'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Author credit', 'publishrank' ) . '</th><td><label><input type="checkbox" name="creator_tag" value="1" ' . checked( ! empty( $s['creator_tag'] ), true, false ) . ' /> ' . esc_html__( 'Add the fediverse:creator tag to articles', 'publishrank' ) . '</label><br /><label><input type="checkbox" name="author_tags" value="1" ' . checked( ! empty( $s['author_tags'] ), true, false ) . ' /> ' . esc_html__( 'Credit each author\'s own Mastodon profile when they have added one', 'publishrank' ) . '</label></td></tr>';
		echo '</table>';
		submit_button( __( 'Save Mastodon settings', 'publishrank' ) );
		echo '</form>';

		$this->history();
		echo '</div>';
	}

	private function history() {
		$history = get_option( Mastodon::HISTORY_OPTION, array() );
		if ( empty( $history ) || ! is_array( $history ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Recent shares', 'publishrank' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'publishrank' ) . '</th><th>' . esc_html__( 'Post', 'publishrank' ) . '</th><th>' . esc_html__( 'Result', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $history as $row ) {
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ) . '</td><td><a href="' . esc_url( (string) get_edit_post_link( (int) $row['post_id'] ) ) . '">' . esc_html( (string) $row['title'] ) . '</a></td><td>';
			if ( ! empty( $row['ok'] ) ) {
				echo '✅ <a href="' . esc_url( (string) $row['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View on Mastodon', 'publishrank' ) . '</a>';
			} else {
				echo '❌ ' . esc_html( (string) $row['error'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function button( $action, $label, $class ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
		echo '<a class="button ' . ( 'link-delete' === $class ? 'button-link-delete' : 'button-secondary' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	public function save() {
		$this->guard( 'publishrank_mastodon_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$old      = Mastodon::settings();
		$instance = Mastodon::normalize_instance( isset( $_POST['instance'] ) ? sanitize_text_field( wp_unslash( $_POST['instance'] ) ) : '' );
		$types    = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
		$types    = array_values( array_filter( $types, 'post_type_exists' ) );
		$vis      = isset( $_POST['visibility'] ) ? sanitize_key( wp_unslash( $_POST['visibility'] ) ) : 'public';
		$settings = array(
			'instance'    => $instance,
			'profile_url' => Mastodon::normalize_profile( isset( $_POST['profile_url'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_url'] ) ) : '' ),
			'auto_share'  => empty( $_POST['auto_share'] ) ? 0 : 1,
			'post_types'  => $types ? $types : array( 'post' ),
			'template'    => isset( $_POST['template'] ) && '' !== trim( (string) wp_unslash( $_POST['template'] ) ) ? sanitize_textarea_field( wp_unslash( $_POST['template'] ) ) : Mastodon::DEFAULT_TEMPLATE,
			'visibility'  => in_array( $vis, array( 'public', 'unlisted', 'private' ), true ) ? $vis : 'public',
			'hashtags'    => empty( $_POST['hashtags'] ) ? 0 : 1,
			'creator_tag' => empty( $_POST['creator_tag'] ) ? 0 : 1,
			'author_tags' => empty( $_POST['author_tags'] ) ? 0 : 1,
		);
		update_option( Mastodon::ENABLED_OPTION, empty( $_POST['enabled'] ) ? 0 : 1 );
		if ( $old['instance'] !== $instance && '' !== $old['instance'] ) {
			Mastodon::disconnect(); // A token only works on the server that issued it.
		}
		update_option( Mastodon::SETTINGS_OPTION, $settings, false );
		$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		// phpcs:enable
		if ( '' !== $token ) {
			if ( '' === $instance ) {
				$this->redirect( 'error', __( 'Enter your Mastodon server address together with the token.', 'publishrank' ) );
			}
			if ( ! Mastodon::save_token( $token ) ) {
				$this->redirect( 'error', __( 'The token could not be stored securely: the OpenSSL PHP extension is required.', 'publishrank' ) );
			}
			$result = Mastodon::verify();
			if ( is_wp_error( $result ) ) {
				Mastodon::disconnect();
				$this->redirect( 'error', $result->get_error_message() );
			}
			/* translators: %s: Mastodon handle. */
			$this->redirect( 'success', sprintf( __( 'Connected as @%s.', 'publishrank' ), $result['acct'] ) );
		}
		$this->redirect( 'success', __( 'Mastodon settings saved.', 'publishrank' ) );
	}

	public function verify() {
		$this->guard( 'publishrank_mastodon_verify' );
		$result = Mastodon::verify();
		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}
		$this->redirect( 'success', ! empty( $result['verified'] ) ? __( 'Your Mastodon profile is verified.', 'publishrank' ) : __( 'Checked. Your profile is not verified yet — see the steps on this page.', 'publishrank' ) );
	}

	public function disconnect() {
		$this->guard( 'publishrank_mastodon_disconnect' );
		Mastodon::disconnect();
		$this->redirect( 'success', __( 'Mastodon disconnected. The access token was deleted.', 'publishrank' ) );
	}

	/** Shows the result of "Share now" on the post edit screen. */
	public function editor_notice() {
		$key    = 'publishrank_mastodon_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-' . esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	private function notice() {
		$key    = 'publishrank_mastodon_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-' . esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
		}
	}

	public function user_field( $user ) {
		echo '<h2>' . esc_html__( 'Mastodon', 'publishrank' ) . '</h2><table class="form-table" role="presentation"><tr><th><label for="pr-mastodon-user">' . esc_html__( 'Mastodon profile', 'publishrank' ) . '</label></th><td>';
		wp_nonce_field( 'publishrank_mastodon_user', 'publishrank_mastodon_user_nonce' );
		echo '<input type="text" class="regular-text" id="pr-mastodon-user" name="publishrank_mastodon_profile" value="' . esc_attr( (string) get_user_meta( $user->ID, '_publishrank_mastodon_profile', true ) ) . '" placeholder="@you@mastodon.social" /><p class="description">' . esc_html__( 'Your articles credit this account in Mastodon link previews, and your profile can be verified with a link to this site.', 'publishrank' ) . '</p></td></tr></table>';
	}

	public function save_user_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['publishrank_mastodon_user_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_mastodon_user_nonce'] ) ), 'publishrank_mastodon_user' ) ) {
			return;
		}
		$value = isset( $_POST['publishrank_mastodon_profile'] ) ? Mastodon::normalize_profile( sanitize_text_field( wp_unslash( $_POST['publishrank_mastodon_profile'] ) ) ) : '';
		update_user_meta( $user_id, '_publishrank_mastodon_profile', $value );
	}

	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Mastodon settings.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private function redirect( $type, $message ) {
		set_transient( 'publishrank_mastodon_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => (string) $message ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
