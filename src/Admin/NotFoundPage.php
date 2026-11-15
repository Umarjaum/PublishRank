<?php
/** 404 monitor screen: review broken URLs and turn them into redirects. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\ModuleSwitches;
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
		add_action( 'admin_post_publishrank_seo_404_toggle', array( $this, 'handle_toggle' ) );
	}

	/** Whether 404 recording is on (same switch as Features and Settings). */
	public static function recording() {
		return ModuleSwitches::is_on( 'monitor_404' );
	}

	/** Readable, translated name for a stored visitor category. */
	public static function visitor_label( $category ) {
		$labels = array(
			'browser' => __( 'Browser', 'publishrank' ),
			'mobile'  => __( 'Mobile', 'publishrank' ),
			'crawler' => __( 'Crawler', 'publishrank' ),
		);
		return isset( $labels[ $category ] ) ? $labels[ $category ] : __( 'Other', 'publishrank' );
	}

	/** Turn recording on or off from this screen. */
	public function handle_toggle() {
		AdminAssets::guard( 'publishrank_seo_404_toggle' );
		$on = isset( $_POST['enable'] ) && '1' === sanitize_key( wp_unslash( $_POST['enable'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard.
		ModuleSwitches::set( 'monitor_404', $on );
		AdminAssets::done( self::SLUG, 'success', $on ? __( '404 monitoring is on. New broken URLs will appear here.', 'publishrank' ) : __( '404 monitoring is off. Existing entries are kept.', 'publishrank' ) );
	}

	private static function toggle_form( $enable ) {
		$out  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-flex;margin:0">';
		$out .= '<input type="hidden" name="action" value="publishrank_seo_404_toggle" />';
		$out .= '<input type="hidden" name="enable" value="' . ( $enable ? '1' : '0' ) . '" />';
		$out .= wp_nonce_field( 'publishrank_seo_404_toggle', '_wpnonce', true, false );
		$out .= '<button type="submit" class="button ' . ( $enable ? 'button-primary' : 'button-secondary' ) . '">' . esc_html( $enable ? __( 'Turn on 404 monitoring', 'publishrank' ) : __( 'Turn off', 'publishrank' ) ) . '</button></form>';
		return $out;
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
		check_admin_referer( 'publishrank_seo_404_redirect' ); // Explicit nonce verification for the request data read below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
		$id = isset( $_POST['entry'] ) ? sanitize_key( wp_unslash( $_POST['entry'] ) ) : '';
		$target = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard.
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
		if ( '' === trim( $target ) ) {
			AdminAssets::done( self::SLUG, 'error', __( 'Enter the page or URL to redirect to.', 'publishrank' ) );
		}
		$result = RedirectStore::add( $path, $target, 301, false );
		if ( is_wp_error( $result ) ) {
			AdminAssets::done( self::SLUG, 'error', $result->get_error_message() );
		}
		// The redirect now serves every visitor type, so clear browser, mobile and crawler rows for this path.
		$kept = array();
		foreach ( self::entries() as $entry ) {
			if ( (string) ( $entry['path'] ?? '' ) !== $path ) {
				$kept[] = $entry;
			}
		}
		update_option( self::OPTION, $kept, false );
		AdminAssets::done( self::SLUG, 'success', __( 'Redirect created and the address was cleared from the log.', 'publishrank' ) );
	}

	public function handle_dismiss() {
		AdminAssets::guard( 'publishrank_seo_404_dismiss' );
		check_admin_referer( 'publishrank_seo_404_dismiss' );
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
		$entries   = self::entries();
		$all_count = count( $entries );
		$recording = self::recording();
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$visitor = isset( $_GET['visitor'] ) ? sanitize_key( wp_unslash( $_GET['visitor'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sort    = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'hits'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $visitor, array( '', 'browser', 'mobile', 'crawler' ), true ) ) {
			$visitor = ''; }
		if ( ! in_array( $sort, array( 'hits', 'recent' ), true ) ) {
			$sort = 'hits'; }
		if ( '' !== $search || '' !== $visitor ) {
			$entries = array_values( array_filter( $entries, static function ( $entry ) use ( $search, $visitor ) {
				$path_match = '' === $search || false !== stripos( (string) ( $entry['path'] ?? '' ), $search );
				$type_match = '' === $visitor || $visitor === (string) ( $entry['agent_category'] ?? '' );
				return $path_match && $type_match;
			} ) );
		}
		usort(
			$entries,
			static function ( $a, $b ) use ( $sort ) {
				if ( 'recent' === $sort ) {
					return strcmp( (string) ( $b['last_seen'] ?? '' ), (string) ( $a['last_seen'] ?? '' ) );
				}
				return (int) ( $b['count'] ?? 0 ) - (int) ( $a['count'] ?? 0 );
			}
		);
		$total = count( $entries );
		$total_hits = array_sum( array_map( static function ( $entry ) { return absint( $entry['count'] ?? 0 ); }, $entries ) );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = isset( $_GET['paged'] ) ? min( $pages, max( 1, absint( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slice = array_slice( $entries, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$nonce = wp_create_nonce( 'publishrank_seo_404_redirect' );
		echo '<div class="wrap">';
		AdminAssets::header( __( '404 Monitor', 'publishrank' ), __( 'Broken URLs visitors and crawlers hit, most frequent first.', 'publishrank' ) );
		AdminAssets::render_flash();
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'PublishRank groups hits by URL and broad visitor category (browser, mobile or crawler). It does not identify individual people or store IP addresses. The monitor ignores common static-asset and infrastructure probes, keeps a 90-day rolling history, and caps storage at 500 entries.', 'publishrank' ) . '</p></div>';
		if ( ! $recording ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The 404 monitor is turned off, so no new broken URLs are being recorded.', 'publishrank' ) . '</p><p>' . self::toggle_form( true ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- toggle_form() escapes its parts.
		} else {
			echo '<div class="publishrank-404-status" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 0"><span class="dashicons dashicons-yes-alt" aria-hidden="true" style="color:#00a32a"></span><span>' . esc_html__( 'Recording is on.', 'publishrank' ) . '</span>' . self::toggle_form( false ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- toggle_form() escapes its parts.
		}
			echo '<p><strong>' . esc_html( sprintf( /* translators: 1: number of paths, 2: number of hits. */ __( '%1$d paths · %2$d recorded hits', 'publishrank' ), $total, $total_hits ) ) . '</strong></p>';
			echo '<form method="get" class="rhs-inline-form" style="margin:12px 0"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><label class="screen-reader-text" for="publishrank-404-search">' . esc_html__( 'Search paths', 'publishrank' ) . '</label><input id="publishrank-404-search" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search URL paths', 'publishrank' ) . '" /> <select name="visitor"><option value="">' . esc_html__( 'All visitors', 'publishrank' ) . '</option><option value="browser" ' . selected( $visitor, 'browser', false ) . '>' . esc_html__( 'Browser', 'publishrank' ) . '</option><option value="mobile" ' . selected( $visitor, 'mobile', false ) . '>' . esc_html__( 'Mobile', 'publishrank' ) . '</option><option value="crawler" ' . selected( $visitor, 'crawler', false ) . '>' . esc_html__( 'Crawler', 'publishrank' ) . '</option></select> <select name="sort"><option value="hits" ' . selected( $sort, 'hits', false ) . '>' . esc_html__( 'Most hits', 'publishrank' ) . '</option><option value="recent" ' . selected( $sort, 'recent', false ) . '>' . esc_html__( 'Most recent', 'publishrank' ) . '</option></select> <button class="button" type="submit">' . esc_html__( 'Filter', 'publishrank' ) . '</button></form>';
			if ( $all_count ) {
			$clear = wp_nonce_url( add_query_arg( 'action', 'publishrank_seo_404_clear', admin_url( 'admin-post.php' ) ), 'publishrank_seo_404_clear' );
			echo '<p class="rhs-actions"><a class="button" href="' . esc_url( $clear ) . '" onclick="return window.confirm(' . esc_attr( wp_json_encode( __( 'Delete every recorded 404 entry? This cannot be undone.', 'publishrank' ) ) ) . ');">' . esc_html__( 'Clear entire log', 'publishrank' ) . '</a></p>';
		}
		?>
		<style>
			.publishrank-404-table{table-layout:auto}
			.publishrank-404-table td{vertical-align:middle}
			.publishrank-404-table .pr404-path code{display:inline-block;max-width:100%;word-break:break-all;white-space:normal}
			.publishrank-404-table .pr404-path .row-links{margin-top:4px;font-size:12px}
			.publishrank-404-table .pr404-hits{width:56px;text-align:center;font-weight:600}
			.publishrank-404-table .pr404-visitor,.publishrank-404-table .pr404-seen{white-space:nowrap}
			.publishrank-404-table .pr404-visitor span{display:inline-block;padding:2px 9px;border-radius:999px;background:#f0f0f1;font-size:12px}
			.publishrank-404-table .pr404-visitor .is-crawler{background:#fcf0f1}
			.publishrank-404-table .pr404-seen small{display:block;color:#646970}
			.publishrank-404-table .pr404-ref{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
			.publishrank-404-table .pr404-redirect form{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;margin:0}
			.publishrank-404-table .pr404-redirect input[type=text]{width:180px;min-width:120px;margin:0}
			.publishrank-404-table .pr404-redirect .button{margin:0}
		</style>
		<div class="rhs-table-wrap"><table class="widefat striped publishrank-404-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'URL path', 'publishrank' ); ?></th><th scope="col" class="pr404-hits"><?php esc_html_e( 'Hits', 'publishrank' ); ?></th><th scope="col"><?php esc_html_e( 'Visitor', 'publishrank' ); ?></th><th scope="col"><?php esc_html_e( 'Last seen', 'publishrank' ); ?></th><th scope="col"><?php esc_html_e( 'Referrer', 'publishrank' ); ?></th><th scope="col"><?php esc_html_e( 'Redirect to', 'publishrank' ); ?></th></tr></thead>
			<tbody>
			<?php
			if ( ! $slice ) {
				if ( $all_count ) {
					$empty = __( 'No entries match this filter.', 'publishrank' );
				} elseif ( $recording ) {
					$empty = __( 'No broken URLs recorded yet. When a visitor or crawler opens an address that does not exist, it appears here with a one-click redirect.', 'publishrank' );
				} else {
					$empty = __( 'No broken URLs recorded. Turn on 404 monitoring above to start.', 'publishrank' );
				}
				echo '<tr class="no-items"><td colspan="6">' . esc_html( $empty ) . '</td></tr>';
			}
			foreach ( $slice as $entry ) :
				$id         = self::entry_id( $entry );
				$first      = isset( $entry['first_seen'] ) ? (string) $entry['first_seen'] : ( isset( $entry['time'] ) ? (string) $entry['time'] : '' );
				$first_text = $first && false !== strtotime( $first ) ? get_date_from_gmt( $first, 'Y-m-d H:i' ) : '—';
				$last       = isset( $entry['last_seen'] ) ? (string) $entry['last_seen'] : '';
				$last_text  = $last && false !== strtotime( $last ) ? get_date_from_gmt( $last, 'Y-m-d H:i' ) : '—';
				$referrer   = isset( $entry['referrer'] ) ? (string) $entry['referrer'] : '';
				$dismiss    = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'publishrank_seo_404_dismiss',
							'id'     => $id,
						),
						admin_url( 'admin-post.php' )
					),
					'publishrank_seo_404_dismiss'
				);
				?>
				<tr>
					<td class="pr404-path">
						<code><?php echo esc_html( isset( $entry['path'] ) ? $entry['path'] : '' ); ?></code>
						<?php
						$path_value = isset( $entry['path'] ) ? (string) $entry['path'] : '/';
						$public_url = home_url( '/' . ltrim( $path_value, '/' ) );
						$slug_bits  = array_filter( explode( '/', trim( (string) wp_parse_url( $path_value, PHP_URL_PATH ), '/' ) ) );
						$page_title = $slug_bits ? ucwords( str_replace( array( '-', '_' ), ' ', (string) end( $slug_bits ) ) ) : '';
						$create_url = add_query_arg(
							array(
								'post_type'  => 'page',
								'post_title' => $page_title,
							),
							admin_url( 'post-new.php' )
						);
						?>
						<div class="row-links"><a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open URL', 'publishrank' ); ?></a>
						| <a href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Create page', 'publishrank' ); ?></a></div>
					</td>
					<td class="pr404-hits"><?php echo esc_html( (string) (int) ( isset( $entry['count'] ) ? $entry['count'] : 1 ) ); ?></td>
					<?php $agent = isset( $entry['agent_category'] ) ? (string) $entry['agent_category'] : ''; ?>
					<td class="pr404-visitor"><span class="<?php echo esc_attr( 'crawler' === $agent ? 'is-crawler' : '' ); ?>"><?php echo esc_html( self::visitor_label( $agent ) ); ?></span></td>
					<td class="pr404-seen"><?php echo esc_html( $last_text ); ?><small>
					<?php
					/* translators: %s: date and time the URL was first recorded. */
					echo esc_html( sprintf( __( 'First: %s', 'publishrank' ), $first_text ) );
					?>
					</small></td>
					<?php $ref_host = $referrer ? wp_parse_url( $referrer, PHP_URL_HOST ) : ''; ?>
					<td class="pr404-ref"><?php echo $referrer ? '<a href="' . esc_url( $referrer ) . '" title="' . esc_attr( $referrer ) . '" rel="noopener noreferrer nofollow">' . esc_html( $ref_host ? (string) $ref_host : wp_html_excerpt( $referrer, 40, '…' ) ) . '</a>' : '&mdash;'; ?></td>
					<td class="pr404-redirect">
						<form class="rhs-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="publishrank_seo_404_redirect" />
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
							<input type="hidden" name="entry" value="<?php echo esc_attr( $id ); ?>" />
							<input type="text" name="target" required placeholder="/new-page" aria-label="<?php esc_attr_e( 'New path or URL', 'publishrank' ); ?>" />
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
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( array_filter( array( 'page' => self::SLUG, 's' => $search, 'visitor' => $visitor, 'sort' => $sort, 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
							'format'  => '',
						'total'   => $pages,
						'current' => $paged,
					)
				)
			) . '</div></div>';
		}
		echo '</div>';
	}
}
