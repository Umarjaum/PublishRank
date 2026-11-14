<?php
/**
 * Sitemap Hub: one screen to switch the XML sitemap and its content types on or off, copy and submit the
 * sitemap address, and check that every generated file is valid.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\ModuleSwitches;
use PublishRankSEO\Core\OutputSettings;
use PublishRankSEO\Robots\RobotsTxt;
use PublishRankSEO\Sitemap\Engine\SitemapAudit;
use PublishRankSEO\Sitemap\SitemapUrls;
use PublishRankSEO\Sitemap\Engine\SitemapEngine;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;
use PublishRankSEO\Sitemap\News\NewsSitemapProvider;

defined( 'ABSPATH' ) || exit;

final class SitemapHealthPage {
	const SLUG        = 'publishrank-sitemap-health';
	const AJAX_ACTION = 'publishrank_toggle_sitemap_part';
	const SAVE_ACTION = 'publishrank_save_sitemap_parts';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 13 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 1001 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Sitemap Hub', 'publishrank' ), __( 'Sitemap Hub', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( 'publishrank-sitemap-hub', Branding::asset_url( 'css/sitemap-hub.css' ), array(), AdminShell::ver( 'css/sitemap-hub.css' ) );
		wp_enqueue_script( 'publishrank-sitemap-hub', Branding::asset_url( 'js/sitemap-hub.js' ), array(), AdminShell::ver( 'js/sitemap-hub.js' ), true );
		wp_localize_script(
			'publishrank-sitemap-hub',
			'publishrankSitemapHub',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'i18n'    => array(
					'on'      => __( 'Included', 'publishrank' ),
					'off'     => __( 'Not included', 'publishrank' ),
					/* translators: %s: sitemap content type, for example Pages. */
					'enabled' => __( '%s added to your sitemap.', 'publishrank' ),
					/* translators: %s: sitemap content type, for example Pages. */
					'disabled' => __( '%s removed from your sitemap.', 'publishrank' ),
					'failed'  => __( 'Could not save. Check your connection and try again.', 'publishrank' ),
					'copied'  => __( 'Sitemap address copied.', 'publishrank' ),
					'copyFailed' => __( 'Copy failed. Select the address and copy it manually.', 'publishrank' ),
					'notChanged' => __( 'This setting could not be changed. Reload the page to see its current state.', 'publishrank' ),
					/* translators: 1: content types included, 2: total content types. */
					'count'   => __( '%1$d of %2$d', 'publishrank' ),
				),
			)
		);
	}

	/**
	 * Content types that can be switched in or out of the sitemap index. Posts are always included.
	 *
	 * @return array<string,array{option:string,default:bool,icon:string,label:string,description:string}>
	 */
	public static function parts() {
		return array(
			'page'       => array( 'option' => 'publishrank_sitemap_pages_enabled', 'default' => true, 'icon' => 'fa-file', 'label' => __( 'Pages', 'publishrank' ), 'description' => __( 'Your published pages, such as About and Contact.', 'publishrank' ) ),
			'cpt'        => array( 'option' => 'publishrank_sitemap_cpt_enabled', 'default' => true, 'icon' => 'fa-shapes', 'label' => __( 'Custom post types', 'publishrank' ), 'description' => __( 'Public content types added by your theme or plugins.', 'publishrank' ) ),
			'taxonomy'   => array( 'option' => 'publishrank_sitemap_taxonomies_enabled', 'default' => true, 'icon' => 'fa-tags', 'label' => __( 'Categories & tags', 'publishrank' ), 'description' => __( 'Category, tag and other topic archive pages.', 'publishrank' ) ),
			'author'     => array( 'option' => 'publishrank_sitemap_authors_enabled', 'default' => true, 'icon' => 'fa-user-pen', 'label' => __( 'Authors', 'publishrank' ), 'description' => __( 'Author profile pages for people who have published.', 'publishrank' ) ),
			'news'       => array( 'option' => 'publishrank_seo_news_enabled', 'default' => true, 'icon' => 'fa-newspaper', 'label' => __( 'Google News', 'publishrank' ), 'description' => __( 'Articles from the last few days, in the format Google News reads.', 'publishrank' ) ),
			'image'      => array( 'option' => 'publishrank_seo_image_sitemap', 'default' => true, 'icon' => 'fa-image', 'label' => __( 'Images', 'publishrank' ), 'description' => __( 'Images used in your content, for Google Images and Discover.', 'publishrank' ) ),
			'video'      => array( 'option' => 'publishrank_seo_video_sitemap', 'default' => true, 'icon' => 'fa-video', 'label' => __( 'Videos', 'publishrank' ), 'description' => __( 'Videos embedded in your content.', 'publishrank' ) ),
			'attachment' => array( 'option' => 'publishrank_sitemap_attachments_enabled', 'default' => false, 'icon' => 'fa-paperclip', 'label' => __( 'Attachment pages', 'publishrank' ), 'description' => __( 'Individual media attachment pages. Usually best left off.', 'publishrank' ), 'why' => __( 'Off by default on purpose: attachment pages are thin, near-duplicate URLs that can compete with your articles in search. Turn this on only if you intentionally index them. You can switch it back off at any time.', 'publishrank' ) ),
		);
	}

	/** Whether a content type is included. */
	public static function part_on( $key ) {
		$parts = self::parts();
		if ( ! isset( $parts[ $key ] ) ) {
			return false;
		}
		return OutputSettings::is_on( $parts[ $key ]['option'], $parts[ $key ]['default'] );
	}

	/**
	 * Include or exclude a content type.
	 *
	 * @return bool False for an unknown content type.
	 */
	public static function set_part( $key, $on ) {
		$parts = self::parts();
		if ( ! isset( $parts[ $key ] ) ) {
			return false;
		}
		if ( 'news' === $key ) {
			// Same option as the Features screen switch; going through it keeps the rewrite refresh and the toggle action.
			return ModuleSwitches::set( 'news_sitemap', $on );
		}
		ModuleSwitches::write( $parts[ $key ]['option'], (bool) $on );
		return true;
	}

	/** Number of content types included (posts count as one, always on). */
	public static function included_count() {
		$count = 1;
		foreach ( array_keys( self::parts() ) as $key ) {
			if ( self::part_on( $key ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function ajax_toggle() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'publishrank' ) ), 403 );
		}
		$key = isset( $_POST['part'] ) ? sanitize_key( wp_unslash( $_POST['part'] ) ) : '';
		$on  = isset( $_POST['enabled'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
		if ( ! self::set_part( $key, $on ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown sitemap content type.', 'publishrank' ) ), 400 );
		}
		wp_send_json_success(
			array(
				'part'     => $key,
				'enabled'  => self::part_on( $key ),
				'included' => self::included_count(),
				'total'    => count( self::parts() ) + 1,
			)
		);
	}

	/** No-JavaScript fallback for the content type switches. */
	public function save() {
		AdminAssets::guard( self::SAVE_ACTION );
		$posted = isset( $_POST['parts'] ) && is_array( $_POST['parts'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['parts'] ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array_keys( self::parts() ) as $key ) {
			self::set_part( $key, in_array( $key, $posted, true ) );
		}
		AdminAssets::done( self::SLUG, 'success', __( 'Sitemap content saved.', 'publishrank' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled = ModuleSwitches::sitemap_enabled();
		$engine  = ( new \PublishRankSEO\Sitemap\SitemapController() )->engine();
		$report  = $enabled ? SitemapAudit::run( $engine, 12 ) : null;

		echo '<div class="wrap pr-modules-wrap pr-hub-wrap">';
		AdminAssets::header( __( 'Sitemap Hub', 'publishrank' ), __( 'Choose what goes in your sitemap, submit it to search engines and check that every file is valid.', 'publishrank' ) );
		AdminAssets::render_flash();
		echo '<div class="pr-modules-layout">';
		AdminShell::nav( 'sitemap' );
		echo '<div class="pr-modules-main pr-hub-main">';

		$this->render_intro( $enabled, $report );
		ModulesPage::render_form( self::SLUG, false, array( 'sitemap' ) );

		if ( $enabled && $report ) {
			$this->render_address();
			$this->render_stats( $report );
			$this->render_issues( $report );
			$this->render_files( $engine );
		} else {
			echo '<div class="pr-hub-off"><i class="fa-solid fa-circle-pause" aria-hidden="true"></i><div><strong>' . esc_html__( 'PublishRank sitemaps are off', 'publishrank' ) . '</strong><p>' . esc_html__( 'Search engines are given the WordPress built-in sitemap at /wp-sitemap.xml instead. Turn the switch above back on to use PublishRank sitemaps, Google News and media sitemaps again.', 'publishrank' ) . '</p></div></div>';
		}

		$this->render_parts( $engine, $enabled );

		if ( $enabled && $report ) {
			echo '<div class="pr-hub-columns">';
			$this->render_preview( $report );
			$this->render_news( $engine );
			echo '</div>';
			$this->render_last_error();
		}

		echo '</div></div>';
		echo '<div class="pr-modules-toast" data-pr-modules-toast role="status" aria-live="polite"></div>';
		echo '</div>';
	}

	/** Title, summary and overall status badge. */
	private function render_intro( $enabled, $report ) {
		if ( ! $enabled ) {
			$badge = array( 'is-off', 'fa-circle-pause', __( 'Off', 'publishrank' ) );
		} elseif ( 'PASS' === $report['status'] ) {
			$badge = array( 'is-good', 'fa-circle-check', __( 'Healthy', 'publishrank' ) );
		} elseif ( 'ERROR' === $report['status'] ) {
			$badge = array( 'is-bad', 'fa-circle-xmark', __( 'Has errors', 'publishrank' ) );
		} else {
			$badge = array( 'is-warn', 'fa-triangle-exclamation', __( 'Needs attention', 'publishrank' ) );
		}
		echo '<div class="pr-modules-intro"><div><p class="pr-shell-kicker">' . esc_html__( 'Control center', 'publishrank' ) . '</p><h2 class="pr-modules-title">' . esc_html__( 'Sitemap Hub', 'publishrank' ) . '</h2>';
		echo '<p class="pr-modules-lead">' . esc_html__( 'Your sitemap tells search engines which pages exist and when they changed. Choose what it lists, then submit its address once in Google Search Console and Bing Webmaster Tools.', 'publishrank' ) . '</p></div>';
		echo '<span class="pr-hub-badge ' . esc_attr( $badge[0] ) . '"><i class="fa-solid ' . esc_attr( $badge[1] ) . '" aria-hidden="true"></i> ' . esc_html( $badge[2] ) . '</span></div>';
	}

	/** Sitemap address with copy, open and submit actions. */
	private function render_address() {
		$url      = SitemapUrls::index_url();
		$home     = home_url( '/' );
		$robots   = OutputSettings::is_on( 'publishrank_robots_enabled', true ) && (bool) get_option( RobotsTxt::OPT_SITEMAPS, 1 ) && in_array( $url, RobotsTxt::sitemap_urls(), true );
		$google   = 'https://search.google.com/search-console/sitemaps?resource_id=' . rawurlencode( $home );
		$bing     = 'https://www.bing.com/webmasters/sitemaps?siteUrl=' . rawurlencode( $home );
		echo '<section class="pr-hub-card pr-hub-address" aria-labelledby="pr-hub-address-title">';
		echo '<div class="pr-hub-address__main"><h3 class="pr-hub-card__title" id="pr-hub-address-title">' . esc_html__( 'Your sitemap address', 'publishrank' ) . '</h3>';
		// The full address is shown as wrapping text so it is never cut off; the read-only field beside it feeds the Copy button.
		echo '<div class="pr-hub-url"><i class="fa-solid fa-link" aria-hidden="true"></i><a class="pr-hub-url__text" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a><input type="text" class="pr-hub-url__field" readonly tabindex="-1" value="' . esc_attr( $url ) . '" aria-hidden="true" data-pr-hub-url />';
		echo '<button type="button" class="pr-hub-btn hide-if-no-js" data-pr-hub-copy><i class="fa-solid fa-copy" aria-hidden="true"></i> ' . esc_html__( 'Copy', 'publishrank' ) . '</button>';
		echo '<a class="pr-hub-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> ' . esc_html__( 'Open', 'publishrank' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'publishrank' ) . '</span></a></div>';
		echo '<p class="pr-hub-robots ' . ( $robots ? 'is-good' : 'is-warn' ) . '"><i class="fa-solid ' . ( $robots ? 'fa-circle-check' : 'fa-circle-info' ) . '" aria-hidden="true"></i> ';
		if ( $robots ) {
			esc_html_e( 'Listed in your robots.txt, so crawlers can find it on their own.', 'publishrank' );
		} else {
			echo esc_html__( 'Not listed in your robots.txt.', 'publishrank' ) . ' <a href="' . esc_url( Branding::page_url( 'publishrank-seo-robots' ) ) . '">' . esc_html__( 'Robots.txt settings', 'publishrank' ) . '</a>';
		}
		echo '</p></div>';
		echo '<div class="pr-hub-submit"><p class="pr-hub-submit__label">' . esc_html__( 'Submit to search engines', 'publishrank' ) . '</p>';
		echo '<a class="pr-hub-engine" href="' . esc_url( $google ) . '" target="_blank" rel="noopener"><i class="fa-brands fa-google" aria-hidden="true"></i><span>' . esc_html__( 'Google Search Console', 'publishrank' ) . '</span><i class="fa-solid fa-arrow-up-right-from-square pr-hub-engine__ext" aria-hidden="true"></i></a>';
		echo '<a class="pr-hub-engine" href="' . esc_url( $bing ) . '" target="_blank" rel="noopener"><i class="fa-brands fa-microsoft" aria-hidden="true"></i><span>' . esc_html__( 'Bing Webmaster Tools', 'publishrank' ) . '</span><i class="fa-solid fa-arrow-up-right-from-square pr-hub-engine__ext" aria-hidden="true"></i></a>';
		echo '</div></section>';
	}

	/** Every sitemap file listed in the index, grouped by content type, so each one can be opened or submitted. */
	private function render_files( $engine ) {
		$labels = array( 'post' => __( 'Posts', 'publishrank' ) );
		foreach ( self::parts() as $key => $part ) {
			$labels[ $key ] = $part['label'];
		}
		$groups = array();
		$total  = 0;
		foreach ( $labels as $key => $label ) {
			if ( 'post' !== $key && ! self::part_on( $key ) ) {
				continue;
			}
			$provider = $engine->provider( $key );
			if ( ! $provider ) {
				continue;
			}
			$shards = $engine->listed_shards( $provider );
			if ( empty( $shards ) ) {
				continue;
			}
			$groups[ $key ] = array( 'label' => $label, 'shards' => $shards );
			$total         += count( $shards );
		}
		if ( ! $groups ) {
			return;
		}
		echo '<section class="pr-hub-card pr-hub-files" aria-labelledby="pr-hub-files-title">';
		echo '<div class="pr-hub-card__head"><div><h3 class="pr-hub-card__title" id="pr-hub-files-title">' . esc_html__( 'Sitemap files', 'publishrank' ) . '</h3>';
		/* translators: %d: number of sitemap files. */
		echo '<p class="pr-hub-card__lead">' . esc_html( sprintf( _n( 'Your sitemap index links to %d file. Search engines read them all from the address above, so you only submit that one address.', 'Your sitemap index links to %d files. Search engines read them all from the address above, so you only submit that one address.', $total, 'publishrank' ), $total ) ) . '</p></div></div>';
		echo '<ul class="pr-hub-files__list">';
		foreach ( $groups as $key => $group ) {
			foreach ( $group['shards'] as $shard ) {
				$file = SitemapUrls::shard_url( $key, (int) $shard );
				echo '<li><span class="pr-hub-files__type">' . esc_html( $group['label'] ) . ( count( $group['shards'] ) > 1 ? ' <span class="pr-hub-files__part">' . esc_html( sprintf( /* translators: %d: file number. */ __( 'part %d', 'publishrank' ), (int) $shard ) ) . '</span>' : '' ) . '</span>';
				echo '<a class="pr-hub-files__url" href="' . esc_url( $file ) . '" target="_blank" rel="noopener">' . esc_html( $file ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'publishrank' ) . '</span></a></li>';
			}
		}
		echo '</ul></section>';
	}

	/** Four headline numbers. */
	private function render_stats( array $report ) {
		$coverage = $report['urls'] > 0 ? (int) round( 100 * min( 1, $report['lastmod'] / $report['urls'] ) ) : 0;
		$stats    = array(
			array( 'fa-link', number_format_i18n( (int) $report['unique_urls'] ), __( 'Unique URLs', 'publishrank' ), '' ),
			array( 'fa-file-code', number_format_i18n( (int) $report['shards'] ), __( 'Sitemap files', 'publishrank' ), '' ),
			array( 'fa-layer-group', sprintf( /* translators: 1: included, 2: total. */ __( '%1$d of %2$d', 'publishrank' ), self::included_count(), count( self::parts() ) + 1 ), __( 'Content types included', 'publishrank' ), 'data-pr-hub-included data-total="' . (int) ( count( self::parts() ) + 1 ) . '"' ),
			array( 'fa-clock-rotate-left', $coverage . '%', __( 'URLs with a last-updated date', 'publishrank' ), '' ),
		);
		echo '<div class="pr-hub-stats">';
		foreach ( $stats as $stat ) {
			echo '<div class="pr-hub-stat"><span class="pr-hub-stat__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $stat[0] ) . '"></i></span><div><strong class="pr-hub-stat__value" ' . $stat[3] . '>' . esc_html( $stat[1] ) . '</strong><span class="pr-hub-stat__label">' . esc_html( $stat[2] ) . '</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data attributes are fixed strings with an integer.
		}
		echo '</div>';
	}

	/** Validation problems and duplicate notes. */
	private function render_issues( array $report ) {
		if ( empty( $report['errors'] ) && empty( $report['duplicates'] ) ) {
			return;
		}
		echo '<section class="pr-hub-card pr-hub-issues" aria-labelledby="pr-hub-issues-title"><h3 class="pr-hub-card__title" id="pr-hub-issues-title">' . esc_html__( 'Things to check', 'publishrank' ) . '</h3><ul class="pr-hub-list">';
		foreach ( (array) $report['errors'] as $error ) {
			echo '<li class="is-bad"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i><span>' . esc_html( $error ) . '</span></li>';
		}
		if ( ! empty( $report['duplicates'] ) ) {
			/* translators: %d: number of duplicate sitemap URL entries suppressed in the preview. */
			echo '<li class="is-info"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>' . esc_html( sprintf( __( '%d duplicate URL entries were found across sitemap providers and suppressed from the preview. News and extension sitemaps may intentionally reference the same canonical article URL.', 'publishrank' ), $report['duplicates'] ) ) . '</span></li>';
		}
		echo '</ul></section>';
	}

	/** Content types with switches, file links and preparation state. */
	private function render_parts( $engine, $enabled ) {
		$rows = array_merge(
			array( 'post' => array( 'option' => '', 'default' => true, 'icon' => 'fa-file-lines', 'label' => __( 'Posts', 'publishrank' ), 'description' => __( 'Always included. Choose extra post types under Settings → News SEO and sitemaps.', 'publishrank' ) ) ),
			self::parts()
		);
		echo '<section class="pr-hub-card pr-hub-parts" aria-labelledby="pr-hub-parts-title">';
		echo '<div class="pr-hub-card__head"><div><h3 class="pr-hub-card__title" id="pr-hub-parts-title">' . esc_html__( 'What your sitemap includes', 'publishrank' ) . '</h3><p class="pr-hub-card__lead">' . esc_html__( 'Switch content types in or out. Changes save instantly and apply to the sitemap right away.', 'publishrank' ) . '</p></div>';
		echo '<a class="pr-set-card__link pr-hub-link" href="' . esc_url( Branding::page_url( 'publishrank' ) . '#pr-settings-news' ) . '"><i class="fa-solid fa-sliders" aria-hidden="true"></i> ' . esc_html__( 'More sitemap settings', 'publishrank' ) . '</a></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pr-hub-parts__form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<ul class="pr-hub-rows">';
		foreach ( $rows as $key => $row ) {
			$always   = 'post' === $key;
			$on       = $always || self::part_on( $key );
			$provider = $engine->provider( $key );
			$files    = ( $enabled && $on && $provider ) ? count( $engine->listed_shards( $provider ) ) : 0;
			$id       = 'pr-hub-part-' . $key;
			echo '<li class="pr-hub-row' . ( $on ? ' is-on' : '' ) . '" data-pr-hub-row="' . esc_attr( $key ) . '">';
			echo '<span class="pr-hub-row__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $row['icon'] ) . '"></i></span>';
			echo '<div class="pr-hub-row__body"><span class="pr-hub-row__title" id="' . esc_attr( $id ) . '-title">' . esc_html( $row['label'] ) . '</span>';
			echo '<span class="pr-hub-row__desc" id="' . esc_attr( $id ) . '-desc">' . esc_html( $row['description'] ) . '</span>';
			$why = ( ! $always && empty( $row['default'] ) && ! empty( $row['why'] ) ) ? (string) $row['why'] : '';
			if ( '' !== $why ) {
				echo '<span class="pr-hub-row__why" id="' . esc_attr( $id ) . '-why"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> ' . esc_html( $why ) . '</span>';
			}
			echo '<span class="pr-hub-row__meta"><span class="pr-hub-row__state" data-pr-hub-state>' . ( $on ? esc_html__( 'Included', 'publishrank' ) : esc_html__( 'Not included', 'publishrank' ) ) . '</span>';
			if ( $enabled && $on && $provider ) {
				if ( $files > 0 ) {
					echo '<a href="' . esc_url( SitemapUrls::shard_url( $key, 1 ) ) . '" target="_blank" rel="noopener">' . esc_html( sprintf( /* translators: %d: number of sitemap files. */ _n( 'View %d file', 'View %d files', $files, 'publishrank' ), $files ) ) . '</a>';
				} else {
					echo '<span class="pr-hub-row__muted">' . esc_html__( 'Nothing to list yet', 'publishrank' ) . '</span>';
				}
				if ( method_exists( $provider, 'prepare_cursor' ) ) {
					$h = SitemapCursorStore::health( $provider->get_slug() );
					// Routine background preparation is normal and not shown; only a stalled run or an overdue WP-Cron needs the owner's attention.
					if ( ! $h['complete'] && ( $h['stale'] || $h['cron_overdue'] ) ) {
						$note = $h['stale'] ? __( 'Background preparation stalled; it restarts automatically on the next request.', 'publishrank' ) : __( 'WP-Cron looks overdue; sitemaps still advance on request, but consider a real server cron.', 'publishrank' );
						echo '<span class="pr-hub-row__prep"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ' . esc_html( $note ) . '</span>';
					}
				}
			}
			echo '</span></div>';
			if ( $always ) {
				echo '<span class="pr-hub-row__lock"><i class="fa-solid fa-lock" aria-hidden="true"></i> ' . esc_html__( 'Always on', 'publishrank' ) . '</span>';
			} else {
				echo '<label class="pr-switch" for="' . esc_attr( $id ) . '">';
				echo '<input type="checkbox" role="switch" id="' . esc_attr( $id ) . '" name="parts[' . esc_attr( $key ) . ']" value="1" data-pr-sitemap-part="' . esc_attr( $key ) . '" data-label="' . esc_attr( $row['label'] ) . '" aria-labelledby="' . esc_attr( $id ) . '-title" aria-describedby="' . esc_attr( $id ) . '-desc' . ( '' !== $why ? ' ' . esc_attr( $id ) . '-why' : '' ) . '"' . checked( $on, true, false ) . ' />';
				echo '<span class="pr-switch__track" aria-hidden="true"><span class="pr-switch__thumb"></span></span></label>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="pr-modules-save hide-if-js"><button type="submit" class="button button-primary">' . esc_html__( 'Save sitemap content', 'publishrank' ) . '</button></p>';
		echo '</form></section>';
	}

	/** First URLs found across the sitemap files. */
	private function render_preview( array $report ) {
		echo '<section class="pr-hub-card" aria-labelledby="pr-hub-preview-title"><h3 class="pr-hub-card__title" id="pr-hub-preview-title">' . esc_html__( 'URL preview', 'publishrank' ) . '</h3>';
		if ( empty( $report['preview'] ) ) {
			echo '<p class="pr-hub-card__lead">' . esc_html__( 'No URLs yet. Publish a post or page and it will appear here.', 'publishrank' ) . '</p></section>';
			return;
		}
		echo '<ol class="pr-hub-urls">';
		foreach ( $report['preview'] as $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $url ) . '">' . esc_html( '' !== $path ? $path : $url ) . '</a></li>';
		}
		echo '</ol></section>';
	}

	/** News sitemap diagnostics: everything an owner needs to see why the sitemap is, or is not, populated. */
	private function render_news( $engine ) {
		$provider = $engine->provider( 'news' );
		echo '<section class="pr-hub-card" aria-labelledby="pr-hub-news-title"><h3 class="pr-hub-card__title" id="pr-hub-news-title">' . esc_html__( 'News sitemap diagnostics', 'publishrank' ) . '</h3>';
		if ( ! $provider instanceof NewsSitemapProvider ) {
			echo '<p class="pr-hub-card__lead">' . esc_html__( 'The News sitemap provider is not registered (another plugin may have filtered it out).', 'publishrank' ) . '</p></section>';
			return;
		}
		$d        = $provider->diagnostics();
		$url      = SitemapUrls::shard_url( 'news', 1 );
		$index    = (string) $engine->index();
		$in_index = false !== strpos( $index, esc_url_raw( $url ) ) || false !== strpos( $index, str_replace( '&', '&amp;', esc_url_raw( $url ) ) );
		$xml      = $d['enabled'] ? $engine->render_public( 'news', 1 ) : '';
		$is_empty = $d['enabled'] && 0 === (int) $d['eligible'];
		$valid    = false;
		if ( is_string( $xml ) && '' !== $xml && function_exists( 'simplexml_load_string' ) ) {
			$prev  = libxml_use_internal_errors( true );
			$valid = false !== simplexml_load_string( $xml );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
		}
		$skipped = array();
		$labels  = array(
			'noindex'            => __( 'noindex', 'publishrank' ),
			'excluded_by_editor' => __( 'excluded by editor', 'publishrank' ),
			'missing_title'      => __( 'no title', 'publishrank' ),
			'invalid_url'        => __( 'invalid or off-site URL', 'publishrank' ),
			'not_public'         => __( 'not public', 'publishrank' ),
			'missing_date'       => __( 'no publication date', 'publishrank' ),
		);
		foreach ( (array) $d['skipped'] as $reason => $count ) {
			$skipped[] = ( $labels[ $reason ] ?? $reason ) . ': ' . (int) $count;
		}
		$yes  = __( 'Yes', 'publishrank' );
		$no   = __( 'No', 'publishrank' );
		$rows = array(
			array( __( 'Provider enabled', 'publishrank' ), $d['enabled'] ? $yes : __( 'No. Switch on Google News above.', 'publishrank' ), $d['enabled'] ? 'good' : 'warn' ),
			array( __( 'Sitemap status', 'publishrank' ), ! $d['enabled'] ? __( 'Off', 'publishrank' ) : ( $is_empty ? __( 'Empty: no new or updated articles in the time window', 'publishrank' ) : sprintf( /* translators: 1: total articles, 2: new articles, 3: updated articles. */ __( 'Has %1$d articles (%2$d new, %3$d updated)', 'publishrank' ), (int) $d['eligible'], (int) $d['new'], (int) $d['updated'] ) ), ! $d['enabled'] ? '' : ( $is_empty ? 'warn' : 'good' ) ),
			array( __( 'Listed in sitemap index', 'publishrank' ), $in_index ? $yes : $no, $in_index ? 'good' : ( $d['enabled'] ? 'bad' : '' ) ),
			array( __( 'Returns valid XML', 'publishrank' ), $valid ? $yes : $no, $valid ? 'good' : ( $d['enabled'] ? 'bad' : '' ) ),
			array( __( 'Publication window', 'publishrank' ), sprintf( /* translators: %d: hours */ __( '%d hours', 'publishrank' ), (int) $d['window_hours'] ), '' ),
			array( __( 'Updated articles', 'publishrank' ), $d['include_modified'] ? sprintf( /* translators: %d: hours */ __( 'Included for %d hours after an edit', 'publishrank' ), (int) $d['modified_window_hours'] ) : __( 'Not included. Enable under Settings → News SEO and sitemaps.', 'publishrank' ), '' ),
			array( __( 'Eligible articles now', 'publishrank' ), (int) $d['eligible'] . ' (' . sprintf( /* translators: %d: files */ _n( '%d file', '%d files', (int) $d['shards'], 'publishrank' ), (int) $d['shards'] ) . ')', '' ),
			array( __( 'Publication name / language', 'publishrank' ), ( '' !== (string) $d['publication'] ? $d['publication'] : '—' ) . ' / ' . $d['language'], '' === (string) $d['publication'] ? 'warn' : '' ),
			array( __( 'Last generated', 'publishrank' ), $d['generated_at'] ? gmdate( 'Y-m-d H:i', (int) $d['generated_at'] ) . ' UTC' : __( 'Not yet', 'publishrank' ), '' ),
			array( __( 'Skipped in last scan', 'publishrank' ), $skipped ? implode( ', ', $skipped ) : __( 'None', 'publishrank' ), '' ),
		);
		echo '<dl class="pr-hub-facts">';
		foreach ( $rows as $row ) {
			echo '<div' . ( '' !== $row[2] ? ' class="is-' . esc_attr( $row[2] ) . '"' : '' ) . '><dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( (string) $row[1] ) . '</dd></div>';
		}
		echo '</dl>';
		echo '<p class="pr-hub-card__foot"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $url ) . '</code></a></p>';
		if ( $d['enabled'] && 0 === (int) $d['eligible'] ) {
			echo '<p class="pr-hub-note is-warn">' . esc_html( sprintf( /* translators: %d: hours */ __( 'No eligible articles were published or updated in the time window (publication window: %d hours), so the News sitemap is empty. This is expected if nothing new has been published or edited. If you expect entries, check that the article is published (not scheduled or private), is not noindex, is not excluded in its News setting, and uses a post type listed under News post types.', 'publishrank' ), (int) $d['window_hours'] ) ) . '</p>';
		}
		if ( ! empty( $d['truncated'] ) ) {
			echo '<p class="pr-hub-note is-warn">' . esc_html__( 'The scan hit its safety limit. Reduce the publication window so every recent article is considered.', 'publishrank' ) . '</p>';
		}
		if ( '' !== $d['error'] ) {
			echo '<p class="pr-hub-note is-bad">' . esc_html( $d['error'] ) . '</p>';
		}
		echo '</section>';
	}

	/** Last generation error recorded by the engine. */
	private function render_last_error() {
		$error = get_option( SitemapEngine::ERROR_OPTION, array() );
		if ( is_array( $error ) && ! empty( $error['message'] ) ) {
			echo '<p class="pr-hub-note is-bad"><strong>' . esc_html__( 'Last sitemap error', 'publishrank' ) . ':</strong> ' . esc_html( sanitize_key( (string) ( $error['provider'] ?? '' ) ) . ': ' . (string) $error['message'] . ' (' . gmdate( 'Y-m-d H:i:s', (int) ( $error['time'] ?? 0 ) ) . ' UTC)' ) . '</p>';
		}
	}
}
