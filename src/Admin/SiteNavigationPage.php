<?php
/**
 * PublishRank → Site Navigation: important pages, their audit, the homepage navigation schema preview,
 * breadcrumb trails and orphaned content.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\SEO\SiteNavigation;

defined( 'ABSPATH' ) || exit;

final class SiteNavigationPage {
	const SLUG   = 'publishrank-site-navigation';
	const ACTION = 'publishrank_site_navigation_save';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 31 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Site Navigation SEO', 'publishrank' ), __( 'Site Navigation', 'publishrank' ), 'publishrank_manage_navigation', self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === $page ) {
			ModuleUi::enqueue();
			$js = PUBLISHRANK_SEO_PATH . 'assets/js/site-navigation.js';
			wp_enqueue_script( 'publishrank-site-navigation', Branding::asset_url( 'js/site-navigation.js' ), array(), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
			wp_localize_script( 'publishrank-site-navigation', 'prxNav', array( 'max' => SiteNavigation::MAX_PAGES ) );
		}
	}

	public function save() {
		AdminAssets::guard( self::ACTION, 'publishrank_manage_navigation' );
		check_admin_referer( self::ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is cast below.
		$rows  = isset( $_POST['nav'] ) && is_array( $_POST['nav'] ) ? wp_unslash( $_POST['nav'] ) : array();
		$roles = SiteNavigation::roles();
		$pages = array();
		$seen  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$id   = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$role = isset( $row['role'] ) ? sanitize_key( $row['role'] ) : 'other';
			if ( $id && ! isset( $seen[ $id ] ) && get_post( $id ) ) {
				$seen[ $id ] = true;
				$pages[]     = array( 'id' => $id, 'role' => isset( $roles[ $role ] ) ? $role : 'other' );
			}
		}
		$add_id   = isset( $_POST['nav_add_id'] ) ? absint( wp_unslash( $_POST['nav_add_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$add_role = isset( $_POST['nav_add_role'] ) ? sanitize_key( wp_unslash( $_POST['nav_add_role'] ) ) : 'other'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $add_id && ! isset( $seen[ $add_id ] ) && get_post( $add_id ) ) {
			$pages[] = array( 'id' => $add_id, 'role' => isset( $roles[ $add_role ] ) ? $add_role : 'other' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( isset( $_POST['nav_suggest'] ) && is_array( $_POST['nav_suggest'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['nav_suggest'] ) ) : array() as $pair ) {
			list( $sid, $srole ) = array_pad( explode( ':', $pair ), 2, 'other' );
			$sid = absint( $sid );
			if ( $sid && ! isset( $seen[ $sid ] ) && get_post( $sid ) ) {
				$seen[ $sid ] = true;
				$pages[]      = array( 'id' => $sid, 'role' => isset( $roles[ $srole ] ) ? sanitize_key( $srole ) : 'other' );
			}
		}
		$over = count( $pages ) > SiteNavigation::MAX_PAGES;
		update_option(
			SiteNavigation::OPTION,
			array(
				'pages'  => array_slice( $pages, 0, SiteNavigation::MAX_PAGES ),
				'schema' => empty( $_POST['nav_schema'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			),
			false
		);
		if ( $over ) {
			/* translators: %d: maximum number of pages */
			AdminAssets::done( self::SLUG, 'warning', sprintf( __( 'Saved the first %d pages. Site navigation holds at most %d important pages, so the rest were not added.', 'publishrank' ), SiteNavigation::MAX_PAGES, SiteNavigation::MAX_PAGES ) );
		}
		AdminAssets::done( self::SLUG, 'success', __( 'Site navigation saved.', 'publishrank' ) );
	}

	public function render() {
		if ( ! \PublishRankSEO\Core\Capabilities::can( 'manage_navigation' ) ) {
			return;
		}
		$s     = SiteNavigation::settings();
		$roles = SiteNavigation::roles();
		$menus = SiteNavigation::menu_targets();
		echo '<div class="wrap pr21">';
		AdminAssets::header( __( 'Site Navigation SEO', 'publishrank' ), __( 'Tell search engines which pages matter most and check that each one is easy to reach, indexable and consistently linked.', 'publishrank' ) );
		AdminAssets::render_flash();
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No plugin can force Google to show particular pages as sitelinks under your homepage. A clear hierarchy, consistent internal links, breadcrumbs and descriptive titles make relevant sitelinks more likely; that is what this screen helps with.', 'publishrank' ) . '</p></div>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>
			<div class="pr21-cols">
			<div class="pr21-main">
			<section class="pr21-card">
				<h2><span class="fa-solid fa-star" aria-hidden="true"></span> <?php esc_html_e( 'Important pages', 'publishrank' ); ?> <span class="pr21-pill" id="pr21-nav-count" data-max="<?php echo (int) SiteNavigation::MAX_PAGES; ?>"><?php echo esc_html( count( $s['pages'] ) . ' / ' . SiteNavigation::MAX_PAGES ); ?></span></h2>
				<p class="description"><?php
				/* translators: %d: maximum number of pages */
				echo esc_html( sprintf( __( 'Choose up to %d pages. The order below is the order used in the homepage schema; use the arrows to change it.', 'publishrank' ), SiteNavigation::MAX_PAGES ) ); ?></p>
				<table class="widefat striped pr21-table" id="pr21-nav-table">
					<thead><tr><th><?php esc_html_e( 'Page', 'publishrank' ); ?></th><th><?php esc_html_e( 'Role', 'publishrank' ); ?></th><th><?php esc_html_e( 'Health', 'publishrank' ); ?></th><th><?php esc_html_e( 'Order', 'publishrank' ); ?></th><th><?php esc_html_e( 'Remove', 'publishrank' ); ?></th></tr></thead>
					<tbody>
					<?php
					if ( ! $s['pages'] ) {
						echo '<tr class="pr21-empty"><td colspan="5">' . esc_html__( 'No important pages yet. Add them below or accept the suggestions.', 'publishrank' ) . '</td></tr>';
					}
					foreach ( $s['pages'] as $i => $row ) {
						$post = get_post( $row['id'] );
						if ( ! $post instanceof \WP_Post ) {
							continue;
						}
						$checks = SiteNavigation::audit( $post, $menus );
						$ok     = count( array_filter( $checks, static function ( $c ) { return true === $c['ok']; } ) );
						echo '<tr data-nav-row><td><input type="hidden" name="nav[' . (int) $i . '][id]" value="' . (int) $post->ID . '" /><strong><a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></strong><br /><a href="' . esc_url( get_permalink( $post ) ) . '" target="_blank" rel="noopener"><code>' . esc_html( wp_make_link_relative( get_permalink( $post ) ) ) . '</code></a></td>';
						echo '<td><select name="nav[' . (int) $i . '][role]">';
						foreach ( $roles as $key => $label ) {
							echo '<option value="' . esc_attr( $key ) . '"' . selected( $row['role'], $key, false ) . '>' . esc_html( $label ) . '</option>';
						}
						echo '</select></td><td><div class="pr21-meter" title="' . esc_attr( $ok . '/' . count( $checks ) ) . '"><span style="width:' . esc_attr( (string) round( 100 * $ok / max( 1, count( $checks ) ) ) ) . '%"></span></div><ul class="pr21-checklist">';
						foreach ( $checks as $c ) {
							$icon = true === $c['ok'] ? 'fa-circle-check is-good' : ( false === $c['ok'] ? 'fa-circle-xmark is-bad' : 'fa-circle-question is-warn' );
							echo '<li><span class="fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></span> ' . esc_html( $c['label'] ) . ( '' !== $c['detail'] ? ' <small>(' . esc_html( $c['detail'] ) . ')</small>' : '' ) . '</li>';
						}
						echo '</ul></td><td class="pr21-order"><button type="button" class="button button-small" data-move="-1" aria-label="' . esc_attr__( 'Move up', 'publishrank' ) . '"><span class="fa-solid fa-arrow-up" aria-hidden="true"></span></button> <button type="button" class="button button-small" data-move="1" aria-label="' . esc_attr__( 'Move down', 'publishrank' ) . '"><span class="fa-solid fa-arrow-down" aria-hidden="true"></span></button></td><td><label><input type="checkbox" name="nav[' . (int) $i . '][remove]" value="1" /> <span class="screen-reader-text">' . esc_html__( 'Remove', 'publishrank' ) . '</span></label></td></tr>';
					}
					?>
					</tbody>
				</table>
				<div class="pr21-row" id="pr21-nav-addrow" style="margin-top:12px">
					<label for="pr21-nav-add"><?php esc_html_e( 'Add a page', 'publishrank' ); ?></label>
					<?php
					wp_dropdown_pages(
						array(
							'name'              => 'nav_add_id',
							'id'                => 'pr21-nav-add',
							'show_option_none'  => __( '— Select a page —', 'publishrank' ),
							'option_none_value' => '0',
							'exclude'           => implode( ',', array_map( 'absint', wp_list_pluck( $s['pages'], 'id' ) ) ),
						)
					);
					?>
					<select name="nav_add_role" aria-label="<?php esc_attr_e( 'Role of the added page', 'publishrank' ); ?>">
						<?php foreach ( $roles as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; } ?>
					</select>
				</div>
				<?php
				$suggest = SiteNavigation::suggestions();
				if ( $suggest ) {
					echo '<div class="pr21-preview"><span class="pr21-kicker">' . esc_html__( 'Suggested from your page addresses', 'publishrank' ) . '</span>';
					foreach ( $suggest as $sg ) {
						echo '<label class="pr21-check"><input type="checkbox" name="nav_suggest[]" value="' . esc_attr( $sg['id'] . ':' . $sg['role'] ) . '" /> ' . esc_html( $sg['title'] ) . ' <span class="pr21-pill">' . esc_html( $roles[ $sg['role'] ] ) . '</span></label>';
					}
					echo '</div>';
				}
				?>
				<label class="pr21-check" style="margin-top:12px"><input type="checkbox" name="nav_schema" value="1" <?php checked( $s['schema'] ); ?> /> <?php esc_html_e( 'Describe these pages on the homepage (SiteNavigationElement list and WebPage significantLink)', 'publishrank' ); ?></label>
				<p id="pr21-nav-full" class="description" hidden><?php
				/* translators: %d: maximum number of pages */
				echo esc_html( sprintf( __( 'You have reached the limit of %d pages. Remove one to add another.', 'publishrank' ), SiteNavigation::MAX_PAGES ) ); ?></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save site navigation', 'publishrank' ); ?></button></p>
			</section>
			</div>
			<aside class="pr21-side">
				<?php $this->render_schema_preview(); ?>
				<?php $this->render_breadcrumb_preview( $s ); ?>
				<?php $this->render_orphans(); ?>
			</aside>
			</div>
		</form>
		</div>
		<?php
	}

	private function render_schema_preview() {
		$items = array();
		foreach ( SiteNavigation::live_pages() as $i => $row ) {
			$items[] = array( '@type' => 'SiteNavigationElement', 'position' => $i + 1, 'name' => wp_strip_all_tags( get_the_title( $row['post'] ) ), 'url' => get_permalink( $row['post'] ) );
		}
		echo '<section class="pr21-card"><h2><span class="fa-solid fa-code" aria-hidden="true"></span> ' . esc_html__( 'Homepage schema preview', 'publishrank' ) . '</h2>';
		if ( ! $items ) {
			echo '<p class="description">' . esc_html__( 'Nothing is added until at least one published, indexable page is saved.', 'publishrank' ) . '</p></section>';
			return;
		}
		$node = array( '@type' => 'ItemList', '@id' => home_url( '/' ) . '#site-navigation', 'name' => __( 'Site navigation', 'publishrank' ), 'numberOfItems' => count( $items ), 'itemListElement' => $items );
		echo '<pre class="pr21-code">' . esc_html( (string) wp_json_encode( $node, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre></section>';
	}

	/** Breadcrumb trail each important page will show (WordPress hierarchy, no guessing). */
	private function render_breadcrumb_preview( array $s ) {
		if ( ! $s['pages'] ) {
			return;
		}
		echo '<section class="pr21-card"><h2><span class="fa-solid fa-chevron-right" aria-hidden="true"></span> ' . esc_html__( 'Breadcrumb trails', 'publishrank' ) . '</h2>';
		foreach ( array_slice( $s['pages'], 0, 12 ) as $row ) {
			$post = get_post( $row['id'] );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$trail = array( (string) get_option( 'publishrank_breadcrumb_home_label', __( 'Home', 'publishrank' ) ) );
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
				$trail[] = get_the_title( $ancestor );
			}
			$trail[] = get_the_title( $post );
			echo '<div class="pr21-trail">';
			foreach ( $trail as $crumb ) {
				echo '<span>' . esc_html( '' !== $crumb ? $crumb : __( 'Home', 'publishrank' ) ) . '</span>';
			}
			echo '</div>';
		}
		echo '<p class="description"><a href="' . esc_url( Branding::page_url( 'publishrank-breadcrumbs' ) ) . '">' . esc_html__( 'Breadcrumb settings', 'publishrank' ) . '</a></p></section>';
	}

	private function render_orphans() {
		echo '<section class="pr21-card"><h2><span class="fa-solid fa-link-slash" aria-hidden="true"></span> ' . esc_html__( 'Orphaned content', 'publishrank' ) . '</h2>';
		$rows = array();
		if ( class_exists( '\\PublishRankSEO\\InternalLinks\\Store' ) && \PublishRankSEO\InternalLinks\Store::enabled() ) {
			$rows = \PublishRankSEO\InternalLinks\Store::orphans( 0, 1, 10 );
		}
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'No orphaned content found in the content graph, or the graph has not been scanned yet.', 'publishrank' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $rows as $node ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( (int) $node->object_id ) ) . '">' . esc_html( (string) $node->title ) . '</a> <span class="pr21-pill">' . esc_html__( '0 internal links', 'publishrank' ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '<p class="description"><a href="' . esc_url( Branding::page_url( 'publishrank-seo-internal-links' ) ) . '">' . esc_html__( 'Content Graph and link suggestions', 'publishrank' ) . '</a> · <a href="' . esc_url( Branding::page_url( 'publishrank-link-intelligence' ) ) . '">' . esc_html__( 'Broken link audit', 'publishrank' ) . '</a></p></section>';
	}
}
