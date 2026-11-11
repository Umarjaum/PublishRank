<?php
/**
 * "Rate PublishRank on WordPress.org" notice.
 *
 * Shown on PublishRank screens to administrators. When it is dismissed it comes back after the
 * interval below (five minutes by default) until the administrator rates the plugin or presses
 * "I already rated it". State is stored per user, so one administrator's choice never hides the
 * notice from another.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class RatingNotice {
	const ACTION            = 'publishrank_rate_notice';
	const META_DISMISSED_AT = 'publishrank_rate_notice_dismissed_at';
	const META_RATED        = 'publishrank_rate_notice_rated';

	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax' ) );
	}

	/** Seconds a dismissed notice stays hidden. Filterable; never lower than one minute. */
	public static function interval() {
		return max( MINUTE_IN_SECONDS, (int) apply_filters( 'publishrank_rate_notice_interval', 5 * MINUTE_IN_SECONDS ) );
	}

	private function should_show() {
		return current_user_can( 'manage_options' )
			&& Branding::is_plugin_screen()
			&& ! get_user_meta( get_current_user_id(), self::META_RATED, true );
	}

	public function enqueue() {
		if ( ! $this->should_show() ) {
			return;
		}
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/rate-notice.js';
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/rate-notice.css';
		wp_enqueue_style( 'publishrank-rate-notice', Branding::asset_url( 'css/rate-notice.css' ), array(), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_enqueue_script( 'publishrank-rate-notice', Branding::asset_url( 'js/rate-notice.js' ), array(), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
	}

	public function render() {
		if ( ! $this->should_show() ) {
			return;
		}
		$dismissed = (int) get_user_meta( get_current_user_id(), self::META_DISMISSED_AT, true );
		$interval  = self::interval();
		$remaining = $dismissed ? max( 0, $dismissed + $interval - time() ) : 0;
		?>
		<div class="notice notice-info publishrank-rate-notice" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( self::ACTION ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>" data-remaining="<?php echo esc_attr( (string) $remaining ); ?>" data-interval="<?php echo esc_attr( (string) $interval ); ?>"<?php echo $remaining > 0 ? ' hidden' : ''; ?>>
			<p class="publishrank-rate-notice__text">
				<strong><?php esc_html_e( 'Enjoying PublishRank?', 'publishrank' ); ?></strong>
				<?php esc_html_e( 'A quick five-star review on WordPress.org helps other publishers find it and keeps the plugin free.', 'publishrank' ); ?>
			</p>
			<p class="publishrank-rate-notice__actions">
				<a class="button button-primary" href="<?php echo esc_url( Branding::rate_url() ); ?>" target="_blank" rel="noopener noreferrer" data-pr-rate="rated"><?php esc_html_e( 'Rate on WordPress.org ★★★★★', 'publishrank' ); ?></a>
				<button type="button" class="button button-secondary" data-pr-rate="dismiss"><?php esc_html_e( 'Dismiss', 'publishrank' ); ?></button>
				<button type="button" class="button-link" data-pr-rate="rated"><?php esc_html_e( 'I already rated it', 'publishrank' ); ?></button>
			</p>
		</div>
		<?php
	}

	public function ajax() {
		check_ajax_referer( self::ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'rated' === $do ) {
			update_user_meta( get_current_user_id(), self::META_RATED, time() );
		} elseif ( 'dismiss' === $do ) {
			update_user_meta( get_current_user_id(), self::META_DISMISSED_AT, time() );
		} else {
			wp_send_json_error( null, 400 );
		}
		wp_send_json_success();
	}
}
