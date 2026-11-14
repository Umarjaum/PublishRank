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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_verification_assets' ) );
		add_action( 'admin_post_publishrank_mastodon_verify', array( $this, 'verify' ) );
		add_action( 'admin_post_publishrank_mastodon_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_publishrank_mastodon_check_author', array( $this, 'check_author' ) );
		add_action( 'admin_notices', array( $this, 'editor_notice' ) );
		add_action( 'show_user_profile', array( $this, 'user_field' ) );
		add_action( 'edit_user_profile', array( $this, 'user_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_field' ) );
	}

	public function enqueue_verification_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page && ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		$path = PUBLISHRANK_SEO_PATH . 'assets/js/mastodon.js';
		wp_enqueue_script( 'publishrank-mastodon', Branding::asset_url( 'js/mastodon.js' ), array(), Branding::version() . '.' . ( file_exists( $path ) ? (string) filemtime( $path ) : '0' ), true );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Mastodon', 'publishrank' ), __( 'Mastodon', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = Mastodon::settings();
		$account   = Mastodon::account();
		$connected = Mastodon::connected();
		$enabled   = Mastodon::enabled();
		$server    = (string) wp_parse_url( (string) $s['instance'], PHP_URL_HOST );
		echo '<div class="wrap publishrank-mastodon pr-masto">';
		AdminAssets::header( __( 'Mastodon', 'publishrank' ), __( 'Verify your Mastodon profile and share new posts to the fediverse automatically.', 'publishrank' ) );
		$this->notice();

		// Status strip: one glance shows whether the feature is on, connected and verified.
		$verified = $connected && ! empty( $account['verified'] );
		echo '<section class="pr-masto-status" aria-label="' . esc_attr__( 'Mastodon status', 'publishrank' ) . '">';
		echo '<div class="pr-masto-status__who">';
		if ( $connected && ! empty( $account['avatar'] ) ) {
			echo '<img src="' . esc_url( $account['avatar'] ) . '" alt="" width="52" height="52" class="pr-masto-status__avatar" />';
		} else {
			echo '<span class="pr-masto-status__avatar pr-masto-status__avatar--empty" aria-hidden="true"><i class="fa-brands fa-mastodon"></i></span>';
		}
		echo '<div>';
		if ( $connected && ! empty( $account['acct'] ) ) {
			echo '<strong class="pr-masto-status__name">' . esc_html( '' !== $account['display'] ? $account['display'] : $account['username'] ) . '</strong>';
			echo '<a class="pr-masto-status__handle" href="' . esc_url( $account['url'] ) . '" target="_blank" rel="noopener noreferrer">@' . esc_html( $account['username'] . '@' . $server ) . '</a>';
			/* translators: %s: number of followers. */
			echo '<span class="pr-masto-status__meta">' . esc_html( sprintf( _n( '%s follower', '%s followers', (int) $account['followers'], 'publishrank' ), number_format_i18n( (int) $account['followers'] ) ) ) . '</span>';
		} elseif ( $connected ) {
			echo '<strong class="pr-masto-status__name">' . esc_html__( 'Token saved', 'publishrank' ) . '</strong><span class="pr-masto-status__meta">' . esc_html__( 'The account has not been confirmed yet. Check the server address and token permissions, then press Verify connection.', 'publishrank' ) . '</span>';
		} else {
			echo '<strong class="pr-masto-status__name">' . esc_html__( 'No account connected', 'publishrank' ) . '</strong><span class="pr-masto-status__meta">' . esc_html__( 'Follow step 1 below to connect your Mastodon account.', 'publishrank' ) . '</span>';
		}
		echo '</div></div>';
		echo '<ul class="pr-masto-status__chips">';
		$this->chip( $enabled, __( 'Feature on', 'publishrank' ), __( 'Feature off', 'publishrank' ) );
		$confirmed = $connected && ! empty( $account['acct'] );
		if ( $connected && ! $confirmed ) {
			$this->chip( false, '', __( 'Token not confirmed', 'publishrank' ), 'warn' );
		} else {
			$this->chip( $connected, __( 'Connected', 'publishrank' ), __( 'Not connected', 'publishrank' ) );
		}
		if ( $confirmed ) {
			if ( $verified ) {
				$this->chip( true, __( 'Profile verified', 'publishrank' ), '' );
			} else {
				$this->chip( false, '', ! empty( $account['has_field'] ) ? __( 'Waiting for Mastodon to verify', 'publishrank' ) : __( 'Profile not verified', 'publishrank' ), 'warn' );
			}
		}
		echo '</ul>';
		if ( $connected ) {
			echo '<div class="pr-masto-status__actions">';
			$this->button( 'publishrank_mastodon_verify', ! empty( $account['acct'] ) ? __( 'Check again', 'publishrank' ) : __( 'Verify connection', 'publishrank' ), 'secondary' );
			$this->button( 'publishrank_mastodon_disconnect', __( 'Disconnect', 'publishrank' ), 'link-delete' );
			echo '</div>';
		}
		echo '</section>';

		if ( ! $enabled ) {
			echo '<div class="pr-masto-alert" role="note"><i class="fa-solid fa-toggle-off" aria-hidden="true"></i><p>' . esc_html__( 'The Mastodon feature is off, so nothing is shared and no verification link is added to your site. Switch it on in step 1 and save.', 'publishrank' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pr-mastodon-form pr-masto-form">';
		wp_nonce_field( 'publishrank_mastodon_save' );
		echo '<input type="hidden" name="action" value="publishrank_mastodon_save" />';

		// Step 1: connect.
		$this->step_open( 1, __( 'Connect your account', 'publishrank' ), __( 'Switch the feature on, then enter your server, access token and profile.', 'publishrank' ) );
		echo '<label class="pr-masto-switch"><input type="checkbox" name="enabled" value="1" ' . checked( $enabled, true, false ) . ' /><span class="pr-masto-switch__track" aria-hidden="true"></span><span>' . esc_html__( 'Turn on Mastodon verification and sharing', 'publishrank' ) . '</span></label>';
		echo '<details class="pr-masto-howto"' . ( $connected ? '' : ' open' ) . '><summary>' . esc_html__( 'How to get an access token', 'publishrank' ) . '</summary><ol>';
		echo '<li>' . esc_html__( 'On your Mastodon server open Preferences → Development → New application.', 'publishrank' ) . '</li>';
		echo '<li>' . esc_html__( 'Name it "PublishRank", select read:accounts and write:statuses, and save. Add write:media only if you want to attach featured images.', 'publishrank' ) . '</li>';
		echo '<li>' . esc_html__( 'Open the application, copy "Your access token" and paste it below with your server address.', 'publishrank' ) . '</li></ol></details>';
		echo '<div class="pr-masto-fields">';
		echo '<div class="pr-masto-field"><label for="pr-mastodon-instance">' . esc_html__( 'Mastodon server URL', 'publishrank' ) . '</label><input type="text" inputmode="url" spellcheck="false" autocapitalize="off" class="regular-text" id="pr-mastodon-instance" name="instance" value="' . esc_attr( $s['instance'] ) . '" placeholder="https://mastodon.social" autocomplete="url" /><p class="description">' . esc_html__( 'The server that issued your token, for example mastodon.social or https://mastodon.social.', 'publishrank' ) . '</p></div>';
		$has_token = '' !== Mastodon::token();
		echo '<div class="pr-masto-field"><label for="pr-mastodon-token">' . esc_html__( 'Mastodon access token', 'publishrank' ) . '</label><div class="pr-mastodon-token-field"><input type="password" class="regular-text" id="pr-mastodon-token" name="token" value="" placeholder="' . esc_attr( $has_token ? __( 'Saved — leave empty to keep it', 'publishrank' ) : __( 'Paste your access token', 'publishrank' ) ) . '" autocomplete="new-password" spellcheck="false" autocapitalize="off" />';
		echo '<button type="button" class="button pr-mastodon-token-toggle" aria-controls="pr-mastodon-token" aria-pressed="false" data-show-label="' . esc_attr__( 'Show access token', 'publishrank' ) . '" data-hide-label="' . esc_attr__( 'Hide access token', 'publishrank' ) . '" aria-label="' . esc_attr__( 'Show access token', 'publishrank' ) . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Show access token', 'publishrank' ) . '</span></button></div><p class="description">' . esc_html( $has_token ? __( 'A token is saved and stored encrypted. It is never shown again; paste a new one only to replace it.', 'publishrank' ) : __( 'Stored encrypted on this site and never shown again after saving.', 'publishrank' ) ) . '</p></div>';
		echo '<div class="pr-masto-field pr-masto-field--wide"><label for="pr-mastodon-profile">' . esc_html__( 'Mastodon profile URL or handle', 'publishrank' ) . '</label><input type="text" class="regular-text" id="pr-mastodon-profile" name="profile_url" value="' . esc_attr( $s['profile_url'] ) . '" placeholder="https://mastodon.social/@yourpublication or @you@mastodon.social" autocomplete="url" /><p class="description">' . esc_html__( 'The profile your site links back to with rel="me". Filled in automatically after connecting when left empty.', 'publishrank' ) . '</p></div>';
		echo '</div>';
		$this->step_close();

		// Step 2: verify.
		$home        = home_url( '/' );
		$profile_url = Mastodon::normalize_profile( '' !== $s['profile_url'] ? $s['profile_url'] : (string) ( $account['url'] ?? '' ) );
		$rel_me_code = '' !== $profile_url ? '<link rel="me" href="' . esc_url( $profile_url ) . '" />' : '';
		$this->step_open( 2, __( 'Verify your profile', 'publishrank' ), __( 'Get the green check next to your website on Mastodon.', 'publishrank' ) );
		echo '<ol class="pr-masto-steps">';
		echo '<li>' . esc_html__( 'Save your profile in step 1 with the feature switched on. PublishRank then adds a rel="me" link back to your Mastodon profile on every page of your site.', 'publishrank' ) . '</li>';
		echo '<li>' . esc_html__( 'On Mastodon open Edit profile → Extra fields, add a field named Website, paste your site address below and save your profile.', 'publishrank' ) . '</li>';
		echo '<li>' . esc_html__( 'Come back here and press Check again. Mastodon can take a few minutes to show the check.', 'publishrank' ) . '</li></ol>';
		echo '<label class="pr-mastodon-site-label" for="pr-mastodon-site-url">' . esc_html__( 'Your site address to paste into Mastodon', 'publishrank' ) . '</label>';
		echo '<div class="pr-mastodon-copy-row"><input type="url" class="regular-text code" id="pr-mastodon-site-url" value="' . esc_attr( $home ) . '" readonly />';
		echo '<button type="button" class="button button-secondary" data-pr-copy-target="pr-mastodon-site-url" data-pr-copy-status="pr-mastodon-site-status" data-pr-copy-done="' . esc_attr__( 'Copied', 'publishrank' ) . '" data-pr-copy-failed="' . esc_attr__( 'Select and copy the URL manually.', 'publishrank' ) . '">' . esc_html__( 'Copy site address', 'publishrank' ) . '</button></div>';
		echo '<p class="description pr-masto-copy-status" id="pr-mastodon-site-status" role="status" aria-live="polite"></p>';
		echo '<details class="pr-masto-howto"><summary>' . esc_html__( 'Manual fallback: rel="me" code for your site', 'publishrank' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'PublishRank already prints this link in your site’s head. Paste it into your theme only if a caching or theme setting removes it.', 'publishrank' ) . '</p>';
		echo '<div class="pr-mastodon-copy-row pr-mastodon-copy-row--code"><textarea id="pr-mastodon-rel-code" class="large-text code" rows="2" readonly placeholder="' . esc_attr__( 'Enter your Mastodon profile in step 1 to create this code.', 'publishrank' ) . '">' . esc_textarea( $rel_me_code ) . '</textarea>';
		echo '<button type="button" class="button button-secondary" data-pr-copy-target="pr-mastodon-rel-code" data-pr-copy-status="pr-mastodon-rel-status" data-pr-copy-done="' . esc_attr__( 'Copied', 'publishrank' ) . '" data-pr-copy-failed="' . esc_attr__( 'Select and copy the code manually.', 'publishrank' ) . '">' . esc_html__( 'Copy code', 'publishrank' ) . '</button></div>';
		echo '<p class="description pr-masto-copy-status" id="pr-mastodon-rel-status" role="status" aria-live="polite"></p></details>';
		$this->step_close();

		// Step 3: what to share.
		$this->step_open( 3, __( 'Choose what to share', 'publishrank' ), __( 'Decide which content is shared and whether it is shared automatically.', 'publishrank' ) );
		echo '<label class="pr-masto-switch"><input type="checkbox" name="auto_share" value="1" ' . checked( ! empty( $s['auto_share'] ), true, false ) . ' /><span class="pr-masto-switch__track" aria-hidden="true"></span><span>' . esc_html__( 'Share new posts when they are published (can be changed per post)', 'publishrank' ) . '</span></label>';
		echo '<fieldset class="pr-masto-fieldset"><legend>' . esc_html__( 'Content types', 'publishrank' ) . '</legend><div class="pr-masto-checks">';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			echo '<label class="pr-mastodon-check"><input type="checkbox" name="post_types[]" value="' . esc_attr( $type->name ) . '" ' . checked( in_array( $type->name, (array) $s['post_types'], true ), true, false ) . ' /> ' . esc_html( $type->labels->singular_name ) . '</label>';
		}
		echo '</div></fieldset>';
		echo '<div class="pr-masto-field"><label for="pr-mastodon-visibility">' . esc_html__( 'Who can see shared posts', 'publishrank' ) . '</label><select id="pr-mastodon-visibility" name="visibility">';
		foreach ( array( 'public' => __( 'Public', 'publishrank' ), 'unlisted' => __( 'Unlisted', 'publishrank' ), 'private' => __( 'Followers only', 'publishrank' ) ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $s['visibility'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></div>';
		$this->step_close();

		// Step 4: the message.
		$this->step_open( 4, __( 'Write the post', 'publishrank' ), __( 'Build the text of each Mastodon post. A post can override this with its own message in the editor.', 'publishrank' ) );
		echo '<label class="pr-mastodon-site-label" for="pr-mastodon-template">' . esc_html__( 'Post message template', 'publishrank' ) . '</label>';
		echo '<textarea class="large-text" rows="6" id="pr-mastodon-template" name="template" data-default="' . esc_attr( Mastodon::DEFAULT_TEMPLATE ) . '">' . esc_textarea( $s['template'] ) . '</textarea>';
		echo '<div class="pr-mastodon-vars" role="group" aria-label="' . esc_attr__( 'Insert a variable', 'publishrank' ) . '"><span class="pr-mastodon-vars__label">' . esc_html__( 'Tap to insert:', 'publishrank' ) . '</span>';
		foreach ( Mastodon::variables() as $tag => $info ) {
			echo '<button type="button" class="button pr-mastodon-var" data-pr-insert="' . esc_attr( $tag ) . '" title="' . esc_attr( $info['hint'] ) . '">' . esc_html( $tag ) . '</button>';
		}
		echo '<button type="button" class="button-link pr-mastodon-reset" data-pr-template-reset>' . esc_html__( 'Reset to default', 'publishrank' ) . '</button></div>';
		echo '<details class="pr-mastodon-vars-help"><summary>' . esc_html__( 'What each variable does', 'publishrank' ) . '</summary><dl>';
		foreach ( Mastodon::variables() as $tag => $info ) {
			echo '<dt><code>' . esc_html( $tag ) . '</code> ' . esc_html( $info['label'] ) . '</dt><dd>' . esc_html( $info['hint'] ) . '</dd>';
		}
		echo '</dl></details>';
		echo '<p class="description">' . esc_html__( 'Your line breaks are kept; blank lines left by empty variables are tidied away. Long excerpts are shortened to fit your server’s post limit.', 'publishrank' ) . '</p>';
		echo '<div class="pr-masto-checks pr-masto-checks--stack">';
		echo '<label class="pr-mastodon-check"><input type="checkbox" name="hashtags" value="1" ' . checked( ! empty( $s['hashtags'] ), true, false ) . ' /> ' . esc_html__( 'Turn the post\'s tags into hashtags (up to 5)', 'publishrank' ) . '</label>';
		echo '<label class="pr-mastodon-check"><input type="checkbox" name="attach_image" value="1" ' . checked( ! empty( $s['attach_image'] ), true, false ) . ' /> ' . esc_html__( 'Attach featured images to shared posts by default', 'publishrank' ) . '</label>';
		echo '</div><p class="description">' . esc_html__( 'Images are sent with their Media Library alt text as the description. This needs the optional write:media permission.', 'publishrank' ) . '</p>';
		$this->step_close();

		// Step 5: author credit.
		$this->step_open( 5, __( 'Credit authors', 'publishrank' ), __( 'Tell Mastodon who wrote your articles, so link previews credit the right account.', 'publishrank' ) );
		echo '<div class="pr-masto-checks pr-masto-checks--stack">';
		echo '<label class="pr-mastodon-check"><input type="checkbox" name="creator_tag" value="1" ' . checked( ! empty( $s['creator_tag'] ), true, false ) . ' /> ' . esc_html__( 'Add the fediverse:creator tag to articles', 'publishrank' ) . '</label>';
		echo '<label class="pr-mastodon-check"><input type="checkbox" name="author_tags" value="1" ' . checked( ! empty( $s['author_tags'] ), true, false ) . ' /> ' . esc_html__( 'Credit each author\'s own Mastodon profile when they have added one', 'publishrank' ) . '</label>';
		echo '<label class="pr-mastodon-check"><input type="checkbox" name="author_verify" value="1" ' . checked( ! empty( $s['author_verify'] ), true, false ) . ' /> ' . esc_html__( 'Let authors verify their Mastodon profile (adds a rel="me" link to each author page)', 'publishrank' ) . '</label>';
		echo '</div>';
		$this->step_close();

		echo '<div class="pr-masto-savebar"><span>' . esc_html__( 'Changes apply to your site and to new shares as soon as you save.', 'publishrank' ) . '</span><button type="submit" class="button button-primary">' . esc_html__( 'Save Mastodon settings', 'publishrank' ) . '</button></div>';
		echo '</form>';

		$this->authors_card();
		$this->history();
		echo '</div>';
	}

	/** Small status pill. */
	private function chip( $ok, $on_label, $off_label, $off_tone = 'off' ) {
		$tone  = $ok ? 'ok' : $off_tone;
		$icon  = $ok ? 'fa-circle-check' : ( 'warn' === $off_tone ? 'fa-hourglass-half' : 'fa-circle-minus' );
		echo '<li class="pr-masto-chip is-' . esc_attr( $tone ) . '"><i class="fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></i> ' . esc_html( $ok ? $on_label : $off_label ) . '</li>';
	}

	private function step_open( $num, $title, $desc ) {
		echo '<section class="pr-masto-card" aria-labelledby="pr-masto-step-' . (int) $num . '"><div class="pr-masto-card__head"><span class="pr-masto-card__num" aria-hidden="true">' . (int) $num . '</span><div><h2 id="pr-masto-step-' . (int) $num . '">' . esc_html( $title ) . '</h2><p>' . esc_html( $desc ) . '</p></div></div><div class="pr-masto-card__body">';
	}

	private function step_close() {
		echo '</div></section>';
	}

	private function history() {
		$history = get_option( Mastodon::HISTORY_OPTION, array() );
		if ( empty( $history ) || ! is_array( $history ) ) {
			return;
		}
		echo '<section class="pr-masto-card pr-masto-card--plain"><div class="pr-masto-card__head"><span class="pr-masto-card__num pr-masto-card__num--icon" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span><div><h2>' . esc_html__( 'Recent shares', 'publishrank' ) . '</h2><p>' . esc_html__( 'The last 30 attempts to share a post, newest first.', 'publishrank' ) . '</p></div></div><div class="pr-masto-card__body"><div class="pr-mastodon-table-wrap"><table class="widefat striped pr-mastodon-history"><thead><tr><th>' . esc_html__( 'When', 'publishrank' ) . '</th><th>' . esc_html__( 'Post', 'publishrank' ) . '</th><th>' . esc_html__( 'Result', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $history as $row ) {
			echo '<tr><td data-label="' . esc_attr__( 'When', 'publishrank' ) . '">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ) . '</td><td data-label="' . esc_attr__( 'Post', 'publishrank' ) . '"><a href="' . esc_url( (string) get_edit_post_link( (int) $row['post_id'] ) ) . '">' . esc_html( (string) $row['title'] ) . '</a></td><td data-label="' . esc_attr__( 'Result', 'publishrank' ) . '">';
			if ( ! empty( $row['ok'] ) ) {
				echo '✅ <a href="' . esc_url( (string) $row['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View on Mastodon', 'publishrank' ) . '</a>';
			} else {
				echo '❌ ' . esc_html( (string) $row['error'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></div></section>';
	}

	private function button( $action, $label, $class ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );
		echo '<a class="button ' . ( 'link-delete' === $class ? 'button-link-delete' : 'button-secondary' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	public function save() {
		$this->guard( 'publishrank_mastodon_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$old      = Mastodon::settings();
		$token    = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
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
			'attach_image' => empty( $_POST['attach_image'] ) ? 0 : 1,
			'creator_tag' => empty( $_POST['creator_tag'] ) ? 0 : 1,
			'author_tags' => empty( $_POST['author_tags'] ) ? 0 : 1,
			'author_verify' => empty( $_POST['author_verify'] ) ? 0 : 1,
		);
		update_option( Mastodon::ENABLED_OPTION, empty( $_POST['enabled'] ) ? 0 : 1 );
		if ( $old['instance'] !== $instance ) {
			if ( '' === $token ) {
				Mastodon::disconnect(); // A token only works on the server that issued it.
			} else {
				delete_option( Mastodon::ACCOUNT_OPTION ); // Do not show the previous server's account during reconnection.
			}
		}
		update_option( Mastodon::SETTINGS_OPTION, $settings, false );
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
				$this->redirect( 'error', sprintf( __( 'The token was saved securely, but Mastodon could not verify the connection: %s. Check the server URL, token permissions, and network connection, then try again.', 'publishrank' ), $result->get_error_message() ) );
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

	/** Short status label for an author's last verification check. */
	private function status_text( $user_id ) {
		$profile = Mastodon::author_profile( $user_id );
		if ( '' === $profile ) {
			return __( 'No Mastodon profile', 'publishrank' );
		}
		$check = Mastodon::author_check( $user_id );
		if ( empty( $check['state'] ) ) {
			return __( 'Not checked yet', 'publishrank' );
		}
		$labels = array(
			'verified' => '✅ ' . __( 'Verified', 'publishrank' ),
			'pending'  => '⏳ ' . __( 'Waiting for Mastodon', 'publishrank' ),
			'no_field' => '⚠️ ' . __( 'Link not added on Mastodon', 'publishrank' ),
			'not_found' => '❌ ' . __( 'Profile not found', 'publishrank' ),
		);
		return isset( $labels[ $check['state'] ] ) ? $labels[ $check['state'] ] : '❌ ' . __( 'Could not check', 'publishrank' );
	}

	private function check_url( $user_id, $return ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=publishrank_mastodon_check_author&user_id=' . (int) $user_id . '&return=' . rawurlencode( $return ) ), 'publishrank_mastodon_check_author_' . (int) $user_id );
	}

	/** Table of authors with their Mastodon profile, author page and verification status. */
	private function authors_card() {
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'orderby'    => 'display_name',
				'number'     => 100,
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		echo '<section class="pr-masto-card pr-masto-card--plain pr-mastodon-authors"><div class="pr-masto-card__head"><span class="pr-masto-card__num pr-masto-card__num--icon" aria-hidden="true"><i class="fa-solid fa-user-check"></i></span><div><h2>' . esc_html__( 'Author verification', 'publishrank' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each author can get their own green check on Mastodon. The author adds their Mastodon profile on their WordPress profile screen, then adds their author page address to a field on their Mastodon profile (Edit profile → Extra fields). PublishRank puts the matching rel="me" link on the author page.', 'publishrank' ) . '</p></div></div><div class="pr-masto-card__body">';
		if ( empty( Mastodon::settings()['author_verify'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Author verification is switched off in step 5, “Credit authors”, so author pages do not publish the rel="me" link yet.', 'publishrank' ) . '</p></div>';
		}
		echo '<div class="pr-mastodon-table-wrap"><table class="widefat striped pr-mastodon-history"><thead><tr><th>' . esc_html__( 'Author', 'publishrank' ) . '</th><th>' . esc_html__( 'Mastodon profile', 'publishrank' ) . '</th><th>' . esc_html__( 'Author page to add on Mastodon', 'publishrank' ) . '</th><th>' . esc_html__( 'Status', 'publishrank' ) . '</th><th>' . esc_html__( 'Action', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $users as $user ) {
			$uid     = (int) $user->ID;
			$profile = Mastodon::author_profile( $uid );
			$edit    = get_edit_user_link( $uid );
			echo '<tr><td data-label="' . esc_attr__( 'Author', 'publishrank' ) . '">' . esc_html( $user->display_name ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Mastodon profile', 'publishrank' ) . '">' . ( '' !== $profile ? '<a href="' . esc_url( $profile ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( Mastodon::handle_from_url( $profile ) ) . '</a>' : '—' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Author page to add on Mastodon', 'publishrank' ) . '"><code>' . esc_html( Mastodon::author_url( $uid ) ) . '</code></td>';
			echo '<td data-label="' . esc_attr__( 'Status', 'publishrank' ) . '">' . esc_html( $this->status_text( $uid ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Action', 'publishrank' ) . '">';
			if ( '' !== $profile ) {
				echo '<a class="button button-secondary" href="' . esc_url( $this->check_url( $uid, 'page' ) ) . '">' . esc_html__( 'Check', 'publishrank' ) . '</a> ';
			}
			if ( $edit ) {
				echo '<a href="' . esc_url( $edit . '#pr-mastodon-user' ) . '">' . esc_html__( 'Edit profile', 'publishrank' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></div></section>';
	}

	/** Admin-post handler: re-check one author against their Mastodon server. */
	public function check_author() {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( 'publishrank_mastodon_check_author_' . $user_id );
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You cannot check this author.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$return = isset( $_GET['return'] ) ? sanitize_key( wp_unslash( $_GET['return'] ) ) : '';
		$result = Mastodon::check_author( $user_id );
		set_transient(
			'publishrank_mastodon_notice_' . get_current_user_id(),
			array( 'type' => 'verified' === $result['state'] ? 'success' : 'error', 'message' => (string) $result['message'] ),
			60
		);
		if ( 'page' === $return && current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		} else {
			wp_safe_redirect( get_current_user_id() === $user_id ? admin_url( 'profile.php#pr-mastodon-user' ) : get_edit_user_link( $user_id ) );
		}
		exit;
	}

	public function user_field( $user ) {
		$profile = Mastodon::author_profile( (int) $user->ID );
		$author  = Mastodon::author_url( (int) $user->ID );
		echo '<h2>' . esc_html__( 'Mastodon', 'publishrank' ) . '</h2><table class="form-table" role="presentation"><tr><th><label for="pr-mastodon-user">' . esc_html__( 'Mastodon profile', 'publishrank' ) . '</label></th><td>';
		wp_nonce_field( 'publishrank_mastodon_user', 'publishrank_mastodon_user_nonce' );
		echo '<input type="text" class="regular-text" id="pr-mastodon-user" name="publishrank_mastodon_profile" value="' . esc_attr( (string) get_user_meta( $user->ID, Mastodon::META_PROFILE, true ) ) . '" placeholder="@you@mastodon.social" /><p class="description">' . esc_html__( 'Your articles credit this account in Mastodon link previews, and your profile can be verified with a link to this site.', 'publishrank' ) . '</p></td></tr>';
		if ( Mastodon::enabled() ) {
			echo '<tr><th><label for="pr-mastodon-author-url">' . esc_html__( 'Verify on Mastodon', 'publishrank' ) . '</label></th><td>';
			echo '<div class="pr-mastodon-copy-row"><input type="url" class="regular-text code" id="pr-mastodon-author-url" value="' . esc_attr( $author ) . '" readonly />';
			echo '<button type="button" class="button button-secondary" data-pr-copy-target="pr-mastodon-author-url" data-pr-copy-status="pr-mastodon-copy-status" data-pr-copy-done="' . esc_attr__( 'Copied', 'publishrank' ) . '" data-pr-copy-failed="' . esc_attr__( 'Select and copy the address manually.', 'publishrank' ) . '">' . esc_html__( 'Copy author page', 'publishrank' ) . '</button></div>';
			echo '<p class="description" id="pr-mastodon-copy-status" role="status" aria-live="polite">' . esc_html__( 'Save your Mastodon profile above, then on Mastodon open Edit profile → Extra fields and paste this address into a field. This page links back to your profile, so Mastodon can show the green check.', 'publishrank' ) . '</p>';
			if ( '' !== $profile ) {
				echo '<p>' . esc_html( $this->status_text( (int) $user->ID ) ) . ' <a class="button button-secondary" href="' . esc_url( $this->check_url( (int) $user->ID, 'profile' ) ) . '">' . esc_html__( 'Check verification', 'publishrank' ) . '</a></p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	public function save_user_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['publishrank_mastodon_user_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['publishrank_mastodon_user_nonce'] ) ), 'publishrank_mastodon_user' ) ) {
			return;
		}
		$value = isset( $_POST['publishrank_mastodon_profile'] ) ? Mastodon::normalize_profile( sanitize_text_field( wp_unslash( $_POST['publishrank_mastodon_profile'] ) ) ) : '';
		update_user_meta( $user_id, Mastodon::META_PROFILE, $value );
		delete_user_meta( $user_id, Mastodon::META_AUTHOR_CHECK ); // The old result belongs to the old profile.
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
