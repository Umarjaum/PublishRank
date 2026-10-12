<?php
/** 404 monitor screen: review broken URLs and turn them into redirects. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Redirect\RedirectStore;

defined( 'ABSPATH' ) || exit;

final class NotFoundPage {
	const SLUG     = 'publishrank-seo-404';
	const OPTION   = 'publishrank_seo_404_log';
	const PER_PAGE = 50;

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_post_publishrank_seo_404_redirect', array( $this, 'handle_redirect' ) );
		add_action( 'admin_post_publishrank_seo_404_dismiss', array( $this, 'handle_dismiss' ) );
		add_action( 'admin_post_publishrank_seo_404_clear', array( $this, 'handle_clear' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( '404 Monitor', 'publishrank' ), __( '404 Monitor', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/** @return array<int,array<string,mixed>> */
	public static function entries() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}

	public static function entry_id( array $entry ) {
		return md5( (string) ( isset( $entry['path'] ) ? $entry['path'] : '' ) . '|' . (string) ( isset( $entry['agent_category'] ) ? $entry['agent_category'] : '' ) );
	}

	private function remove_entry( $id ) {
		$kept = array();
		foreach ( self::entries() as $entry ) {
			if ( self::entry_id( $entry ) !== $id ) {
				$kept[] = $entry;
			}
		}
		update_option( self::OPTION, $kept, false );
	}

	public function handle_redirect() {
		AdminAssets::guard( 'publishrank_seo_404_redirect' );
		$id     = isset( $_POST['entry'] ) ? sanitize_key( wp_unslash( $_POST['entry'] ) ) : '';
		$target = isset( $_POST['target'] ) ? (string) wp_unslash( $_POST['target'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path   = '';
		foreach ( self::entries() as $entry ) {
			if ( self::entry_id( $entry ) === $id ) {
				$path = (string) $entry['path'];
				break;
			}
		}
		if ( '' === $path ) {
			AdminAssets::done( self::SLUG, 'error', __( 'That entry no longer exists.', 'publishrank' ) );
		}
		$result = RedirectStore::add( $path, $target, 301, false );
		if ( is_wp_error( $result ) ) {
			AdminAssets::done( self::SLUG, 'error', $result->get_error_message() );
		}
		$this->remove_entry( $id );
		AdminAssets::done( self::SLUG, 'success', __( 'Redirect created and the entry was cleared.', 'publishrank' ) );
	}

	public function handle_dismiss() {
		AdminAssets::guard( 'publishrank_seo_404_dismiss' );
		$this->remove_entry( isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '' );
		AdminAssets::done( self::SLUG, 'success', __( 'Entry dismissed.', 'publishrank' ) );
	}

	public function handle_clear() {
		AdminAssets::guard( 'publishrank_seo_404_clear' );
		update_option( self::OPTION, array(), false );
		AdminAssets::done( self::SLUG, 'success', __( '404 log cleared.', 'publishrank' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$entries = self::entries();
		usort( $entries, static function ( $a, $b ) { return (int) ( isset( $b['count'] ) ? $b['count'] : 0 ) - (int) ( isset( $a['count'] ) ? $a['count'] : 0 ); } );
		$total = count( $entries );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = isset( $_GET['paged'] ) ? min( $pages, max( 1, absint( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slice = array_slice( $entries, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$nonce = wp_create_nonce( 'publishrank_seo_404_redirect' );
		echo '<div class="wrap">';
		AdminAssets::header( __( '404 Monitor', 'publishrank' ), __( 'Broken URLs visitors and crawlers hit, most frequent first.', 'publishrank' ) );
		AdminAssets::render_flash();
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'PublishRank groups hits by URL and broad visitor category (browser, mobile or crawler). It does not identify individual people or store IP addresses. Use the referrer and hit count to prioritize fixes.', 'publishrank' ) . '</p></div>';
		if ( ! get_option( 'publishrank_seo_404_monitor', false ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The 404 monitor is turned off, so no new broken URLs are being recorded.', 'publishrank' ) . ' <a href="' . esc_url( Branding::page_url( 'publishrank' ) ) . '">' . esc_html__( 'Enable it in settings', 'publishrank' ) . '</a></p></div>';
		}
		if ( $total ) {
			$clear = wp_nonce_url( add_query_arg( 'action', 'publishrank_seo_404_clear', admin_url( 'admin-post.php' ) ), 'publishrank_seo_404_clear' );
			echo '<p class="rhs-actions"><a class="button" href="' . esc_url( $clear ) . '">' . esc_html__( 'Clear entire log', 'publishrank' ) . '</a></p>';
		}
		?>
		<div class="rhs-table-wrap"><table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'URL path', 'publishrank' ); ?></th><th><?php esc_html_e( 'Hits', 'publishrank' ); ?></th><th><?php esc_html_e( 'Visitor', 'publishrank' ); ?></th><th><?php esc_html_e( 'First seen', 'publishrank' ); ?></th><th><?php esc_html_e( 'Last seen', 'publishrank' ); ?></th><th><?php esc_html_e( 'Referrer', 'publishrank' ); ?></th><th><?php esc_html_e( 'Redirect to', 'publishrank' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $slice ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No 404 errors recorded.', 'publishrank' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $slice as $entry ) :
				$id        = self::entry_id( $entry );
				$first      = isset( $entry['first_seen'] ) ? (string) $entry['first_seen'] : ( isset( $entry['time'] ) ? (string) $entry['time'] : '' );
				$first_text = $first && false !== strtotime( $first ) ? get_date_from_gmt( $first, 'Y-m-d H:i' ) : '—';
				$last       = isset( $entry['last_seen'] ) ? (string) $entry['last_seen'] : '';
				$last_text  = $last && false !== strtotime( $last ) ? get_date_from_gmt( $last, 'Y-m-d H:i' ) : '—';
				$referrer  = isset( $entry['referrer'] ) ? (string) $entry['referrer'] : '';
				$dismiss   = wp_nonce_url( add_query_arg( array( 'action' => 'publishrank_seo_404_dismiss', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'publishrank_seo_404_dismiss' );
				?>
				<tr>
					<td>
						<code><?php echo esc_html( isset( $entry['path'] ) ? $entry['path'] : '' ); ?></code><br />
						<?php
						$path_value = isset( $entry['path'] ) ? (string) $entry['path'] : '/';
						$public_url = home_url( '/' . ltrim( $path_value, '/' ) );
						$slug_bits = array_filter( explode( '/', trim( (string) wp_parse_url( $path_value, PHP_URL_PATH ), '/' ) ) );
						$page_title = $slug_bits ? ucwords( str_replace( array( '-', '_' ), ' ', (string) end( $slug_bits ) ) ) : '';
						$create_url = add_query_arg( array( 'post_type' => 'page', 'post_title' => $page_title ), admin_url( 'post-new.php' ) );
						?>
						<a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open URL', 'publishrank' ); ?></a>
						| <a href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Create page', 'publishrank' ); ?></a>
					</td>
					<td><?php echo esc_html( (string) (int) ( isset( $entry['count'] ) ? $entry['count'] : 1 ) ); ?></td>
						<td><?php echo esc_html( isset( $entry['agent_category'] ) ? $entry['agent_category'] : '' ); ?></td>
					<td><?php echo esc_html( $first_text ); ?></td>
					<td><?php echo esc_html( $last_text ); ?></td>
					<td><?php echo $referrer ? '<a href="' . esc_url( $referrer ) . '" rel="noopener noreferrer nofollow">' . esc_html( wp_html_excerpt( $referrer, 40, '…' ) ) . '</a>' : '&mdash;'; ?></td>
					<td>
						<form class="rhs-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="publishrank_seo_404_redirect" />
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
							<input type="hidden" name="entry" value="<?php echo esc_attr( $id ); ?>" />
							<input type="text" name="target" placeholder="/new-page" aria-label="<?php esc_attr_e( 'New path or URL', 'publishrank' ); ?>" />
							<button class="button button-primary" type="submit"><?php esc_html_e( '301', 'publishrank' ); ?></button>
							<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'publishrank' ); ?></a>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'total' => $pages, 'current' => $paged ) ) ) . '</div></div>';
		}
		echo '</div>';
	}
}
