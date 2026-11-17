<?php
/**
 * PublishRank → Theme SEO & Masking (2.25 enterprise dashboard).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Compatibility\ThemeSeo;
use PublishRankSEO\Compatibility\Masking\Adapters;
use PublishRankSEO\Compatibility\Masking\Config;
use PublishRankSEO\Compatibility\Masking\Scanner;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class ThemeSeoPage {
	const SLUG   = 'publishrank-theme-seo';
	const RESULT = Scanner::RESULT;

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 33 );
		add_action( 'admin_post_publishrank_theme_seo_save', array( $this, 'save' ) );
		add_action( 'admin_post_publishrank_theme_seo_scan', array( $this, 'scan' ) );
		add_action( 'admin_post_publishrank_theme_seo_purge', array( $this, 'purge' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Theme SEO & Masking', 'publishrank' ), __( 'Theme Masking', 'publishrank' ), Capabilities::cap( 'manage_theme_masking' ), self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		if ( self::SLUG === Suite::current_page() ) {
			Suite::enqueue();
			Charts::enqueue();
		}
	}

	public function save() {
		Suite::guard( 'publishrank_theme_seo_save', 'manage_theme_masking' );
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : 'cleanup';
		$in      = isset( $_POST['ts'] ) && is_array( $_POST['ts'] ) ? wp_unslash( $_POST['ts'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast per key below.
		$cur     = ThemeSeo::settings();
		$keys    = 'mask' === $section
			? array( 'mask', 'mask_classes', 'mask_versions', 'mask_ids', 'mask_header', 'mask_credits', 'mask_rest' )
			: array( 'cleanup', 'remove_title', 'remove_desc', 'remove_canonical', 'remove_robots', 'remove_social', 'remove_jsonld', 'remove_keywords', 'remove_microdata', 'theme_hooks', 'remove_generator', 'remove_comments' );
		foreach ( $keys as $k ) {
			$cur[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		if ( 'mask' === $section ) {
			$cur['mask_alias']      = ThemeSeo::clean_alias( isset( $in['mask_alias'] ) ? $in['mask_alias'] : $cur['mask_alias'] );
			$cur['mask_level']      = isset( $in['mask_level'] ) && 'standard' === $in['mask_level'] ? 'standard' : 'maximum';
			$cur['mask_sourcemaps'] = isset( $in['mask_sourcemaps'] ) && 'keep' === $in['mask_sourcemaps'] ? 'keep' : 'strip';
			foreach ( array( 'mask_tokens', 'mask_prefixes', 'mask_cdn_hosts' ) as $k ) {
				$cur[ $k ] = isset( $in[ $k ] ) ? sanitize_text_field( (string) $in[ $k ] ) : '';
			}
		}
		update_option( ThemeSeo::OPTION, $cur, false );
		if ( 'mask' === $section ) {
			$s = ThemeSeo::settings();
			if ( $s['mask'] ) {
				add_rewrite_rule( '^' . preg_quote( $s['mask_alias'], '#' ) . '/(c|p|x)/(.+)$', 'index.php?' . ThemeSeo::QV_ROOT . '=$matches[1]&' . ThemeSeo::QV_FILE . '=$matches[2]', 'top' );
			}
			flush_rewrite_rules( false );
			Config::purge_cache();
			Config::rebuild();
		}
		Suite::done( self::SLUG, 'success', __( 'Settings saved. Run a new scan to verify the result on your pages.', 'publishrank' ), array(), $section );
	}

	public function scan() {
		Suite::guard( 'publishrank_theme_seo_scan', 'manage_theme_masking' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
		$r = Scanner::run();
		Suite::done( self::SLUG, 'review' === $r['state'] ? 'warning' : 'success', __( 'Scan finished.', 'publishrank' ), array(), 'scan' );
	}

	public function purge() {
		Suite::guard( 'publishrank_theme_seo_purge', 'manage_theme_masking' );
		$n = Config::purge_cache();
		Config::rebuild();
		/* translators: %d: number of files */
		Suite::done( self::SLUG, 'success', sprintf( __( 'Masked file cache cleared (%d files).', 'publishrank' ), $n ), array(), 'mask' );
	}

	/** @return array{0:string,1:string,2:string} label, tone, explanation */
	public static function state_info( $state ) {
		switch ( $state ) {
			case 'protected':
				return array( __( 'Protected', 'publishrank' ), 'ok', __( 'No theme fingerprint was found in the inspected pages, stylesheets, scripts and REST index.', 'publishrank' ) );
			case 'partial':
				return array( __( 'Partial', 'publishrank' ), 'warn', __( 'Some theme fingerprints remain. The table below lists each one and how to remove it.', 'publishrank' ) );
			case 'review':
				return array( __( 'Review required', 'publishrank' ), 'bad', __( 'A page could not be scanned or a masked file did not load. Check the errors below before relying on masking.', 'publishrank' ) );
		}
		return array( __( 'Not scanned', 'publishrank' ), 'muted', __( 'Run a scan to verify what visitors and theme-detector tools can see.', 'publishrank' ) );
	}

	public function render() {
		if ( ! Capabilities::can( 'manage_theme_masking' ) ) {
			return;
		}
		$s      = ThemeSeo::settings();
		$result = get_option( self::RESULT, array() );
		$result = is_array( $result ) && isset( $result['version'] ) && 2 === (int) $result['version'] ? $result : array();
		$theme  = wp_get_theme();
		$adapt  = Adapters::for_theme( get_template() );
		$state  = $result ? $result['state'] : '';
		$info   = self::state_info( $state );
		Suite::open( __( 'Theme SEO & Masking', 'publishrank' ), __( 'Remove duplicate SEO tags from your theme, and hide which theme your site runs from page source, stylesheets, scripts and theme-detector tools.', 'publishrank' ) );

		echo '<div class="prc-screen">';
		echo '<div class="prx-callout prx-callout--' . esc_attr( 'ok' === $info[1] ? 'ok' : ( 'warn' === $info[1] ? 'warn' : ( 'bad' === $info[1] ? 'bad' : 'info' ) ) ) . '" role="status"><span class="fa-solid ' . esc_attr( 'ok' === $info[1] ? 'fa-shield-halved' : ( 'bad' === $info[1] ? 'fa-triangle-exclamation' : 'fa-circle-info' ) ) . '" aria-hidden="true"></span><p><strong>' . esc_html( $info[0] ) . '</strong> — ' . esc_html( $info[2] );
		if ( $result ) {
			/* translators: 1: time ago, 2: seconds */
			echo ' <span class="prx-help">' . esc_html( sprintf( __( 'Last scan %1$s ago, took %2$ss.', 'publishrank' ), human_time_diff( (int) $result['time'] ), $result['seconds'] ) ) . '</span>';
		}
		echo '</p></div>';
		echo '<div class="prc-kpis prc-kpis--four">';
		Charts::kpi( __( 'Active theme', 'publishrank' ), $theme->get( 'Name' ), '<span class="prc-delta prc-delta--flat">' . esc_html( 'v' . $theme->get( 'Version' ) ) . '</span>', 'fa-palette', 'slate', array(), '', $theme->parent() ? sprintf( /* translators: %s: parent theme */ __( 'child of %s', 'publishrank' ), $theme->parent()->get( 'Name' ) ) : $adapt['name'] );
		Charts::kpi( __( 'Masking level', 'publishrank' ), $s['mask'] ? ( 'maximum' === $s['mask_level'] ? __( 'Maximum', 'publishrank' ) : __( 'Standard', 'publishrank' ) ) : __( 'Off', 'publishrank' ), $s['mask'] && ! ThemeSeo::masking_available() ? '<span class="prc-delta prc-delta--down">' . esc_html__( 'needs pretty permalinks', 'publishrank' ) . '</span>' : '', 'fa-masks-theater', 'plum', array(), '', '' );
		Charts::kpi( __( 'Fingerprints before', 'publishrank' ), $result ? number_format_i18n( $result['total']['before'] ) : '—', '<span class="prc-delta prc-delta--flat">' . esc_html__( 'theme output, untouched', 'publishrank' ) . '</span>', 'fa-fingerprint', 'brass', array(), '', '' );
		Charts::kpi( __( 'Fingerprints visible now', 'publishrank' ), $result ? number_format_i18n( $result['total']['after'] ) : '—', $result ? ( $result['total']['after'] ? '<span class="prc-delta prc-delta--down">' . esc_html__( 'review', 'publishrank' ) . '</span>' : '<span class="prc-delta prc-delta--up">' . esc_html__( 'none found', 'publishrank' ) . '</span>' ) : '', 'fa-eye-slash', 'teal', array(), '', '' );
		echo '</div></div>';

		Suite::tabs(
			array(
				'scan'    => __( 'Fingerprint scan', 'publishrank' ),
				'mask'    => __( 'Masking settings', 'publishrank' ),
				'compat'  => __( 'Compatibility', 'publishrank' ),
				'cleanup' => __( 'SEO cleanup', 'publishrank' ),
			)
		);

		// ---------------------------------------------------------------- Scan.
		Suite::panel_open( 'scan', false );
		Suite::card_open( __( 'Verify what visitors can see', 'publishrank' ), 'fa-fingerprint', __( 'Opens your home page, latest post, a category, a page, search results, page 2 and a product — first exactly as the theme prints them, then as visitors receive them — plus every stylesheet and script they load, the public REST index and the theme folder itself.', 'publishrank' ) );
		Suite::form_open( 'publishrank_theme_seo_scan' );
		echo '<button class="prx-btn prx-btn--primary" type="submit"><span class="fa-solid fa-rotate" aria-hidden="true"></span>' . esc_html( $result ? __( 'Rescan now', 'publishrank' ) : __( 'Run scan now', 'publishrank' ) ) . '</button>';
		echo '</form>';
		Suite::card_close();
		if ( $result ) {
			$cats = Scanner::categories();
			Suite::card_open( __( 'Before and after', 'publishrank' ), 'fa-code-compare', __( 'Every place a theme can be recognised from, counted across all scanned pages and files.', 'publishrank' ) );
			echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Fingerprint', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Theme output', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Visible now', 'publishrank' ) . '</th><th>' . esc_html__( 'Status', 'publishrank' ) . '</th><th>' . esc_html__( 'What it means', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $cats as $k => $c ) {
				$b = (int) $result['before'][ $k ];
				$a = (int) $result['after'][ $k ];
				$badge = ! $b && ! $a ? Suite::badge( __( 'None', 'publishrank' ), 'muted' ) : ( ! $a ? Suite::badge( __( 'Hidden', 'publishrank' ), 'ok' ) : Suite::badge( __( 'Unresolved', 'publishrank' ), 'warn' ) );
				echo '<tr><td><strong>' . esc_html( $c[0] ) . '</strong></td><td class="prx-num">' . esc_html( number_format_i18n( $b ) ) . '</td><td class="prx-num">' . esc_html( number_format_i18n( $a ) ) . '</td><td>' . $badge . '</td><td class="prx-help">' . esc_html( $c[1] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escaped by Suite.
			}
			echo '</tbody></table></div>';
			Suite::card_close();

			if ( $result['errors'] || $result['broken'] ) {
				Suite::card_open( __( 'Needs review', 'publishrank' ), 'fa-triangle-exclamation' );
				echo '<ul class="prx-list">';
				foreach ( $result['errors'] as $e ) {
					echo '<li>' . esc_html( $e['label'] . ': ' . $e['message'] ) . ' <code>' . esc_html( $e['url'] ) . '</code></li>';
				}
				foreach ( $result['broken'] as $e ) {
					/* translators: %s: HTTP status */
					echo '<li>' . esc_html( sprintf( __( 'A file did not load (HTTP %s):', 'publishrank' ), $e['status'] ? $e['status'] : '—' ) ) . ' <code>' . esc_html( $e['url'] ) . '</code> ' . esc_html( $e['message'] ) . '</li>';
				}
				echo '</ul>';
				echo '<p class="prx-help">' . esc_html__( 'If a masked file does not load, the server is not passing unknown addresses to WordPress (check permalinks), or a security rule blocks it. Turn masking off until it is fixed.', 'publishrank' ) . '</p>';
				Suite::card_close();
			}
			if ( $result['assets'] ) {
				Suite::card_open( __( 'Files that still name the theme', 'publishrank' ), 'fa-file-code', __( 'Usually files from a plugin folder outside the masked scope. Add the folder or identifier under Masking settings → Extra identifiers.', 'publishrank' ) );
				echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'File', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Mentions', 'publishrank' ) . '</th><th>' . esc_html__( 'Header', 'publishrank' ) . '</th><th>' . esc_html__( 'Source map', 'publishrank' ) . '</th></tr></thead><tbody>';
				foreach ( array_slice( $result['assets'], 0, 30 ) as $f ) {
					echo '<tr><td class="prx-code">' . esc_html( (string) wp_parse_url( $f['url'], PHP_URL_PATH ) ) . '</td><td class="prx-num">' . esc_html( number_format_i18n( $f['hits'] ) ) . '</td><td>' . esc_html( $f['header'] ? __( 'Yes', 'publishrank' ) : '—' ) . '</td><td>' . esc_html( $f['sourcemap'] ? __( 'Yes', 'publishrank' ) : '—' ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
				Suite::card_close();
			}
			Suite::card_open( __( 'Pages scanned', 'publishrank' ), 'fa-file-lines' );
			echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Page', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Before', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Now', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Theme name in visible text', 'publishrank' ) . '</th><th>' . esc_html__( 'Example still visible', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $result['pages'] as $p ) {
				echo '<tr><td><a href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $p['label'] ) . '</a></td><td class="prx-num">' . esc_html( number_format_i18n( array_sum( $p['before'] ) ) ) . '</td><td class="prx-num">' . esc_html( number_format_i18n( array_sum( $p['after'] ) ) ) . '</td><td class="prx-num">' . esc_html( number_format_i18n( (int) $p['text'] ) ) . '</td><td class="prx-code">' . esc_html( $p['examples'] ? $p['examples'][0] : '—' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo '<p class="prx-help">' . esc_html__( 'Visible text (for example an article that mentions the theme by name) is never changed and is not counted as a fingerprint.', 'publishrank' ) . '</p>';
			Suite::card_close();
		}
		Suite::panel_close();

		// ---------------------------------------------------------------- Mask settings.
		Suite::panel_open( 'mask' );
		Suite::form_open( 'publishrank_theme_seo_save' );
		echo '<input type="hidden" name="section" value="mask" />';
		Suite::card_open( __( 'Hide which theme you use', 'publishrank' ), 'fa-masks-theater', __( 'Stops "what theme is this?" tools and competitors from reading your theme from the page source, stylesheets and scripts.', 'publishrank' ) );
		if ( ! ThemeSeo::masking_available() ) {
			echo '<div class="prx-callout prx-callout--warn"><span class="fa-solid fa-triangle-exclamation" aria-hidden="true"></span><p>' . esc_html__( 'Masking needs pretty permalinks (Settings → Permalinks, anything except "Plain").', 'publishrank' ) . '</p></div>';
		}
		Suite::switch_field( 'ts[mask]', $s['mask'], __( 'Mask the theme', 'publishrank' ), __( 'Theme files are served from a neutral address instead of /wp-content/themes/your-theme/.', 'publishrank' ) );
		Suite::field( 'select', 'ts[mask_level]', $s['mask_level'], __( 'Masking level', 'publishrank' ), __( 'Maximum rewrites theme class names, IDs, JavaScript globals and file names consistently in the page and in the theme\'s CSS/JS, so styles and scripts keep working. Standard only hides addresses, versions, headers and generator tags.', 'publishrank' ), array(), array( 'maximum' => __( 'Maximum (recommended)', 'publishrank' ), 'standard' => __( 'Standard', 'publishrank' ) ) );
		Suite::field( 'text', 'ts[mask_alias]', $s['mask_alias'], __( 'Neutral address for theme files', 'publishrank' ), sprintf( /* translators: %s: example URL */ __( 'Example: %s', 'publishrank' ), home_url( '/' . $s['mask_alias'] . '/c/style.css' ) ), array( 'pattern' => '[a-z0-9/_-]+', 'maxlength' => '60' ) );
		echo '<div class="prx-grid prx-grid--2" style="margin-top:12px">';
		Suite::switch_field( 'ts[mask_classes]', $s['mask_classes'], __( 'Remove theme name from body classes', 'publishrank' ), __( 'For example "wp-theme-astra".', 'publishrank' ) );
		Suite::switch_field( 'ts[mask_versions]', $s['mask_versions'], __( 'Replace theme versions with opaque cache keys', 'publishrank' ), __( 'Browsers still receive new files after a theme update.', 'publishrank' ) );
		Suite::switch_field( 'ts[mask_ids]', $s['mask_ids'], __( 'Rename theme-named style and script IDs', 'publishrank' ), __( 'Standard level. Maximum level rewrites every identifier.', 'publishrank' ) );
		Suite::switch_field( 'ts[mask_header]', $s['mask_header'], __( 'Hide the theme header in style.css', 'publishrank' ), __( 'Theme Name, Author and Version lines are not sent.', 'publishrank' ) );
		Suite::switch_field( 'ts[mask_credits]', $s['mask_credits'], __( 'Remove theme credit links', 'publishrank' ), __( '"Powered by <theme>" links to the theme vendor in the footer.', 'publishrank' ) );
		Suite::switch_field( 'ts[mask_rest]', $s['mask_rest'], __( 'Hide theme REST namespaces', 'publishrank' ), __( 'From the public /wp-json/ index only; logged-in editors still see them.', 'publishrank' ) );
		echo '</div>';
		Suite::field( 'select', 'ts[mask_sourcemaps]', $s['mask_sourcemaps'], __( 'Source maps', 'publishrank' ), __( 'Strip removes sourceMappingURL references from served CSS/JS and refuses .map files on the neutral address. Pages keep working: browsers only use source maps in developer tools.', 'publishrank' ), array(), array( 'strip' => __( 'Strip (recommended)', 'publishrank' ), 'keep' => __( 'Keep', 'publishrank' ) ) );
		Suite::field( 'text', 'ts[mask_tokens]', $s['mask_tokens'], __( 'Extra identifiers', 'publishrank' ), __( 'Comma-separated words to mask as well, such as a theme family name or a companion plugin prefix.', 'publishrank' ) );
		Suite::field( 'text', 'ts[mask_prefixes]', $s['mask_prefixes'], __( 'Extra class prefixes', 'publishrank' ), __( 'Short prefixes that only count when followed by - or _, for example "td" for td-container.', 'publishrank' ) );
		Suite::field( 'text', 'ts[mask_cdn_hosts]', $s['mask_cdn_hosts'], __( 'CDN host names', 'publishrank' ), __( 'If theme files are delivered from a CDN host, list it so those addresses are masked too.', 'publishrank' ) );
		echo '<p class="prx-help">' . esc_html__( 'Only images, fonts, media, stylesheets and scripts are served from the neutral address — never PHP, templates or text files. Processed files are cached on disk and in browsers; cache keys change automatically when the theme or these settings change.', 'publishrank' ) . '</p>';
		echo '<div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit"><span class="fa-solid fa-floppy-disk" aria-hidden="true"></span>' . esc_html__( 'Save masking settings', 'publishrank' ) . '</button>';
		echo '</div>';
		Suite::card_close();
		echo '</form>';
		Suite::form_open( 'publishrank_theme_seo_purge' );
		echo '<button class="prx-btn" type="submit"><span class="fa-solid fa-broom" aria-hidden="true"></span>' . esc_html__( 'Clear masked file cache', 'publishrank' ) . '</button>';
		echo '</form>';
		Suite::panel_close();

		// ---------------------------------------------------------------- Compatibility.
		Suite::panel_open( 'compat' );
		Suite::card_open( __( 'Theme compatibility', 'publishrank' ), 'fa-table-cells', __( 'Adapters add the short class prefixes, companion plugin folders and credit links each theme family uses. "Tested" means masking was verified on that theme in the 2.25.0 release test.', 'publishrank' ) );
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Theme', 'publishrank' ) . '</th><th>' . esc_html__( 'Extra prefixes', 'publishrank' ) . '</th><th>' . esc_html__( 'Companion folders', 'publishrank' ) . '</th><th>' . esc_html__( 'Release test', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( Adapters::all() as $key => $a ) {
			$cur = get_template() === $key ? ' ' . Suite::badge( __( 'Active', 'publishrank' ), 'brand' ) : '';
			echo '<tr><td><strong>' . esc_html( $a['name'] ) . '</strong>' . $cur . '</td><td class="prx-mono">' . esc_html( $a['prefixes'] ? implode( '-, ', $a['prefixes'] ) . '-' : '—' ) . '</td><td class="prx-mono">' . esc_html( $a['plugins'] ? implode( ', ', $a['plugins'] ) : '—' ) . '</td><td>' . ( $a['tested'] ? Suite::badge( __( 'Tested', 'publishrank' ), 'ok' ) : Suite::badge( __( 'Generic rules (not tested)', 'publishrank' ), 'muted' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badges escaped by Suite.
		}
		echo '</tbody></table></div>';
		Suite::card_close();
		$warnings = $result ? (array) $result['warnings'] : array();
		Suite::card_open( __( 'Server rule for direct folder probes', 'publishrank' ), 'fa-server', __( 'Masking hides the theme from page source. Someone who already guesses the theme can still request /wp-content/themes/<theme>/style.css directly unless the web server refuses it. PHP cannot close this — add the rule below.', 'publishrank' ) );
		if ( $result && isset( $result['probe'] ) ) {
			echo '<p>' . ( 200 === (int) $result['probe']['status'] ? Suite::badge( __( 'Direct probe answered', 'publishrank' ), 'warn' ) : Suite::badge( __( 'Direct probe refused', 'publishrank' ), 'ok' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		$slugs = implode( '|', array_map( 'preg_quote', array_unique( array( get_stylesheet(), get_template() ) ) ) );
		echo '<p><strong>Apache (.htaccess)</strong></p><pre class="prx-code">' . esc_html( "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^wp-content/themes/(" . $slugs . ")/.*\\.(css|js|map|txt|md)$ - [F,L]\n</IfModule>" ) . '</pre>';
		echo '<p><strong>Nginx</strong></p><pre class="prx-code">' . esc_html( 'location ~* ^/wp-content/themes/(' . $slugs . ')/.*\.(css|js|map|txt|md)$ { return 403; }' ) . '</pre>';
		echo '<p class="prx-help">' . esc_html__( 'Add the rule only while masking is on and a scan shows no broken files: masked files are read from disk, so they keep working.', 'publishrank' ) . '</p>';
		Suite::card_close();
		if ( $warnings ) {
			Suite::card_open( __( 'Compatibility notes', 'publishrank' ), 'fa-circle-info' );
			echo '<ul class="prx-list">';
			foreach ( $warnings as $w ) {
				if ( 'probe' === $w ) {
					echo '<li>' . esc_html__( 'The real theme folder answers direct requests. See the server rule above.', 'publishrank' ) . '</li>';
				} elseif ( 'permalinks' === $w ) {
					echo '<li>' . esc_html__( 'Pretty permalinks are off, so the neutral address cannot work.', 'publishrank' ) . '</li>';
				} elseif ( 0 === strpos( $w, 'optimizer:' ) ) {
					/* translators: %s: plugin name */
					echo '<li>' . esc_html( sprintf( __( '%s is active. Its combined files are included in the masked scope; clear its cache after changing masking settings.', 'publishrank' ), substr( $w, 10 ) ) ) . '</li>';
				} elseif ( 0 === strpos( $w, 'generic:' ) ) {
					/* translators: %s: words */
					echo '<li>' . esc_html( sprintf( __( 'These theme names are ordinary words and are not rewritten on their own, to avoid changing unrelated code: %s', 'publishrank' ), substr( $w, 8 ) ) ) . '</li>';
				}
			}
			echo '</ul>';
			Suite::card_close();
		}
		Suite::panel_close();

		// Cleanup.
		Suite::panel_open( 'cleanup' );
		Suite::form_open( 'publishrank_theme_seo_save' );
		echo '<input type="hidden" name="section" value="cleanup" />';
		Suite::card_open( __( 'Remove duplicate SEO tags', 'publishrank' ), 'fa-broom', __( 'A kind of tag is removed only when PublishRank printed the same kind on that page, so a page never ends up without one.', 'publishrank' ) );
		Suite::switch_field( 'ts[cleanup]', $s['cleanup'], __( 'Clean up SEO tags from the theme and other plugins', 'publishrank' ), __( 'Master switch for everything on this tab.', 'publishrank' ) );
		echo '<div class="prx-grid prx-grid--2" style="margin-top:12px">';
		$rows = array(
			'remove_title'     => array( __( 'Extra page titles', 'publishrank' ), __( 'Keeps PublishRank\'s title, removes a second one printed by old themes.', 'publishrank' ) ),
			'remove_desc'      => array( __( 'Meta descriptions', 'publishrank' ), '' ),
			'remove_canonical' => array( __( 'Canonical links', 'publishrank' ), '' ),
			'remove_robots'    => array( __( 'Robots tags', 'publishrank' ), __( 'robots, googlebot and bingbot meta tags.', 'publishrank' ) ),
			'remove_social'    => array( __( 'Open Graph and X/Twitter tags', 'publishrank' ), __( 'og:, article:, fb:, product: and twitter: tags.', 'publishrank' ) ),
			'remove_jsonld'    => array( __( 'Duplicate structured data', 'publishrank' ), __( 'Removes JSON-LD blocks that only describe things PublishRank already describes (for example a second WebSite or Article). Product data from WooCommerce is kept.', 'publishrank' ) ),
			'remove_keywords'  => array( __( 'Meta keywords', 'publishrank' ), __( 'Ignored by search engines; often left by old themes.', 'publishrank' ) ),
			'theme_hooks'      => array( __( 'Turn off theme SEO features', 'publishrank' ), __( 'Uses the official switches of Genesis, Astra, GeneratePress, Hello Elementor, Blocksy, Kadence, Neve, OceanWP, Avada, Newspaper and Jannah.', 'publishrank' ) ),
			'remove_microdata' => array( __( 'Theme microdata in the page body', 'publishrank' ), __( 'Removes itemscope / itemtype / itemprop attributes. Leave off unless the scan shows conflicting microdata.', 'publishrank' ) ),
			'remove_generator' => array( __( 'Generator tags', 'publishrank' ), __( 'Hides "WordPress 6.x", WooCommerce, page-builder and theme version tags.', 'publishrank' ) ),
			'remove_comments'  => array( __( 'HTML comments', 'publishrank' ), __( 'Removes comments themes and plugins leave in the page source. PublishRank\'s own markers stay.', 'publishrank' ) ),
		);
		foreach ( $rows as $k => $r ) {
			Suite::switch_field( 'ts[' . $k . ']', $s[ $k ], $r[0], $r[1] );
		}
		echo '</div>';
		echo '<div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit"><span class="fa-solid fa-floppy-disk" aria-hidden="true"></span>' . esc_html__( 'Save cleanup settings', 'publishrank' ) . '</button></div>';
		Suite::card_close();
		echo '</form>';
		Suite::panel_close();

		Suite::close();
	}
}
