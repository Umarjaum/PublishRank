<?php
/**
 * Optional, dismissible "Recommended for publishers: Classic Editor" notice.
 *
 * - Shown only to users who can install plugins, only on the Dashboard, the Posts list and PublishRank
 *   screens, and only while the Classic Editor plugin is not active.
 * - Dismissal is stored per user and is permanent (no reminder loop).
 * - Never installs or activates anything: the button opens the official WordPress.org plugin page (or the
 *   core plugin installer when the user may install plugins). Gutenberg keeps working as before.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class ClassicEditorNotice {
	const ACTION    = 'publishrank_classic_editor_notice';
	const META      = 'publishrank_classic_editor_notice_dismissed';
	const DIRECTORY = 'https://wordpress.org/plugins/classic-editor/';

	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ), 25 );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'dismiss_fallback' ) );
	}

	public static function classic_editor_active() {
		return class_exists( 'Classic_Editor' ) || function_exists( 'classic_editor_init_actions' ) || ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'classic-editor/classic-editor.php' ) );
	}

	private function should_show() {
		if ( ! current_user_can( 'edit_posts' ) || get_user_meta( get_current_user_id(), self::META, true ) || self::classic_editor_active() ) {
			return false;
		}
		/** Filter: return false to never show the Classic Editor recommendation. */
		if ( ! apply_filters( 'publishrank_show_classic_editor_recommendation', true ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return 'dashboard' === $screen->id || 'edit-post' === $screen->id || Branding::is_plugin_screen();
	}

	public function render() {
		if ( ! $this->should_show() ) {
			return;
		}
		$install = current_user_can( 'install_plugins' ) && ! is_multisite()
			? admin_url( 'plugin-install.php?tab=plugin-information&plugin=classic-editor&TB_iframe=true&width=772&height=700' )
			: self::DIRECTORY;
		$fallback = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		?>
		<div class="notice notice-info is-dismissible publishrank-classic-notice" data-action="<?php echo esc_attr( self::ACTION ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>">
			<p><strong><?php esc_html_e( 'Recommended for publishers: Classic Editor', 'publishrank' ); ?></strong></p>
			<p><?php esc_html_e( 'PublishRank recommends Classic Editor for a focused, traditional writing workflow. It provides a familiar editing experience for publishers and editorial teams.', 'publishrank' ); ?></p>
			<p><?php esc_html_e( 'You can continue using Gutenberg if you prefer.', 'publishrank' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $install ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Classic Editor on WordPress.org', 'publishrank' ); ?></a>
				<a class="button publishrank-classic-notice__dismiss" href="<?php echo esc_url( $fallback ); ?>"><?php esc_html_e( 'No thanks, keep Gutenberg', 'publishrank' ); ?></a>
			</p>
		</div>
		<script>
		( function () {
			var box = document.currentScript && document.currentScript.previousElementSibling;
			if ( ! box || ! window.fetch ) { return; }
			function dismiss( e ) {
				if ( e ) { e.preventDefault(); }
				var body = new URLSearchParams();
				body.append( 'action', box.getAttribute( 'data-action' ) );
				body.append( 'nonce', box.getAttribute( 'data-nonce' ) );
				window.fetch( window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', credentials: 'same-origin', body: body } );
				box.parentNode && box.parentNode.removeChild( box );
			}
			box.querySelector( '.publishrank-classic-notice__dismiss' ).addEventListener( 'click', dismiss );
			box.addEventListener( 'click', function ( e ) { if ( e.target.closest( '.notice-dismiss' ) ) { dismiss(); } } );
		}() );
		</script>
		<?php
	}

	public function ajax() {
		check_ajax_referer( self::ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( null, 403 );
		}
		update_user_meta( get_current_user_id(), self::META, time() );
		wp_send_json_success();
	}

	/** No-JS dismissal. */
	public function dismiss_fallback() {
		check_admin_referer( self::ACTION );
		if ( current_user_can( 'edit_posts' ) ) {
			update_user_meta( get_current_user_id(), self::META, time() );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
