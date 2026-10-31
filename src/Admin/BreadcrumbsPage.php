<?php
/**
 * Breadcrumbs: one screen for the visible trail, the separator, the frontend override and BreadcrumbList structured data,
 * plus a check that reports which breadcrumb renderers the live site actually prints.
 *
 * Everything here reads and writes the same options the engine uses (see SEO\BreadcrumbEngine), so a value saved here is
 * the value rendered on the site. Style, colours and error handling remain on the Settings screen ("Advanced").
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\OutputSettings;
use PublishRankSEO\SEO\BreadcrumbDiagnostics;
use PublishRankSEO\SEO\BreadcrumbEngine;
use PublishRankSEO\SEO\BreadcrumbSeparators;

defined( 'ABSPATH' ) || exit;

final class BreadcrumbsPage {
	const SLUG      = 'publishrank-breadcrumbs';
	const SCAN_KEY  = 'publishrank_breadcrumb_scan';
	const PLACEMENT = array( 'auto', 'content', 'hook', 'manual', 'schema_only' );

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_post_publishrank_breadcrumbs_save', array( $this, 'save' ) );
		add_action( 'admin_post_publishrank_breadcrumbs_scan', array( $this, 'scan' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Breadcrumbs', 'publishrank' ), __( 'Breadcrumbs', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/** Known breadcrumb markup and the renderer that produces it. */
	public static function markers() {
		return BreadcrumbDiagnostics::markers();
	}

	/** Identify breadcrumb renderers in a page's HTML (see BreadcrumbDiagnostics::detect()). */
	public static function detect( $html ) {
		return BreadcrumbDiagnostics::detect( $html );
	}

	public function save() {
		AdminAssets::guard( 'publishrank_breadcrumbs_save' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard().
		foreach ( array( 'publishrank_breadcrumbs_enabled', 'publishrank_breadcrumb_frontend_override', 'publishrank_breadcrumb_schema_enabled' ) as $key ) {
			update_option( $key, isset( $post[ $key ] ) && '1' === (string) $post[ $key ] ? 1 : 0 );
		}
		$notice = array( 'success', __( 'Breadcrumb settings saved.', 'publishrank' ) );
		if ( isset( $post['publishrank_breadcrumbs_separator'] ) ) {
			$sep = BreadcrumbSeparators::normalize( $post['publishrank_breadcrumbs_separator'] );
			if ( null === $sep ) {
				$notice = array( 'error', sprintf( /* translators: %d: maximum characters. */ __( 'Other settings were saved, but the separator was not: use plain text of at most %d characters.', 'publishrank' ), BreadcrumbSeparators::MAX_LENGTH ) );
			} else {
				update_option( BreadcrumbSeparators::OPTION, $sep );
			}
		}
		$placement = isset( $post['publishrank_breadcrumb_placement'] ) ? sanitize_key( $post['publishrank_breadcrumb_placement'] ) : '';
		if ( in_array( $placement, self::PLACEMENT, true ) ) {
			update_option( 'publishrank_breadcrumb_placement', $placement );
		}
		if ( isset( $post['publishrank_breadcrumb_hook'] ) ) {
			update_option( 'publishrank_breadcrumb_hook', trim( (string) preg_replace( '/[^A-Za-z0-9_:\\/\\-]/', '', (string) $post['publishrank_breadcrumb_hook'] ) ) );
		}
			if ( isset( $post['publishrank_breadcrumb_home_label'] ) ) {
				update_option( 'publishrank_breadcrumb_home_label', sanitize_text_field( (string) $post['publishrank_breadcrumb_home_label'] ) );
			}
			$style = isset( $post['publishrank_breadcrumb_style'] ) ? sanitize_key( $post['publishrank_breadcrumb_style'] ) : 'plain';
			update_option( 'publishrank_breadcrumb_style', in_array( $style, array( 'plain', 'chips', 'bar', 'underline' ), true ) ? $style : 'plain' );
			$size = isset( $post['publishrank_breadcrumb_size'] ) ? sanitize_key( $post['publishrank_breadcrumb_size'] ) : 'medium';
			update_option( 'publishrank_breadcrumb_size', in_array( $size, array( 'small', 'medium', 'large' ), true ) ? $size : 'medium' );
			foreach ( array( 'publishrank_breadcrumb_text_color', 'publishrank_breadcrumb_link_color', 'publishrank_breadcrumb_bg_color' ) as $color_key ) {
				$color = isset( $post[ $color_key ] ) ? sanitize_hex_color( (string) $post[ $color_key ] ) : '';
				update_option( $color_key, $color ? $color : '' );
			}
			$error_mode = isset( $post['publishrank_breadcrumb_error_mode'] ) ? sanitize_key( (string) $post['publishrank_breadcrumb_error_mode'] ) : 'skip';
			update_option( 'publishrank_breadcrumb_error_mode', in_array( $error_mode, array( 'skip', 'fallback', 'text', 'disable' ), true ) ? $error_mode : 'skip' );
			if ( isset( $post['publishrank_breadcrumb_error_key'] ) ) {
				update_option( 'publishrank_breadcrumb_error_key', sanitize_key( (string) $post['publishrank_breadcrumb_error_key'] ) ?: 'breadcrumb_error' );
			}
			if ( isset( $post['publishrank_breadcrumb_fallback_label'] ) ) {
				update_option( 'publishrank_breadcrumb_fallback_label', sanitize_text_field( (string) $post['publishrank_breadcrumb_fallback_label'] ) );
			}
			if ( isset( $post['publishrank_breadcrumb_fallback_url'] ) ) {
				update_option( 'publishrank_breadcrumb_fallback_url', esc_url_raw( (string) $post['publishrank_breadcrumb_fallback_url'] ) );
			}
			update_option( 'publishrank_breadcrumb_log_errors', isset( $post['publishrank_log_errors'] ) && '1' === (string) $post['publishrank_log_errors'] ? 1 : 0 );
			BreadcrumbEngine::reset();
		AdminAssets::done( self::SLUG, $notice[0], $notice[1] );
	}

	/** Representative own-site URLs to test: latest article, latest page, one archive. Only URLs WordPress itself generated. */
	public static function test_urls() {
		$urls  = array();
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'suppress_filters' => true ) );
		if ( $posts ) {
			$urls['article'] = (string) get_permalink( $posts[0] );
		}
		$exclude = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
		$pages   = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'exclude' => $exclude, 'suppress_filters' => true ) );
		if ( $pages ) {
			$urls['page'] = (string) get_permalink( $pages[0] );
		}
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'number' => 1, 'orderby' => 'count', 'order' => 'DESC' ) );
		if ( is_array( $terms ) && $terms && $terms[0] instanceof \WP_Term ) {
			$link = get_term_link( $terms[0] );
			if ( ! is_wp_error( $link ) ) {
				$urls['archive'] = (string) $link;
			}
		}
		return array_filter( $urls );
	}

	/** @param mixed $headers Response headers object or array. @return array<string,mixed> */
	private static function header_array( $headers ) {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			return (array) $headers->getAll();
		}
		return is_array( $headers ) ? $headers : array();
	}

	/** Settings the analysis compares the fetched page against. */
	public static function settings_snapshot() {
		return array(
			'visible'       => OutputSettings::visible_breadcrumbs(),
			'placement'     => BreadcrumbEngine::placement(),
			'schema'        => OutputSettings::breadcrumb_schema(),
			'schema_master' => OutputSettings::schema(),
			'override'      => OutputSettings::breadcrumb_override(),
		);
	}

	/** Administrator-triggered, nonce-protected and rate-limited (one run every 20 seconds). */
	public function scan() {
		AdminAssets::guard( 'publishrank_breadcrumbs_scan' );
		if ( get_transient( self::SCAN_KEY . '_lock' ) ) {
			AdminAssets::done( self::SLUG, 'error', __( 'A site check ran a moment ago. Wait about 20 seconds and try again.', 'publishrank' ) );
		}
		set_transient( self::SCAN_KEY . '_lock', 1, 20 );
		$results = array();
		foreach ( self::test_urls() as $type => $url ) {
			$resp = wp_remote_get(
				add_query_arg( BreadcrumbDiagnostics::PROBE_PARAM, BreadcrumbDiagnostics::new_token(), $url ),
				array( 'timeout' => 12, 'redirection' => 3, 'limit_response_size' => 1048576, 'headers' => array( 'Cache-Control' => 'no-cache' ), 'user-agent' => 'PublishRank-Diagnostics' )
			);
			$page = array(
				'type'     => $type,
				'url'      => $url,
				'status'   => is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp ),
				'error'    => is_wp_error( $resp ) ? $resp->get_error_message() : '',
				'headers'  => is_wp_error( $resp ) ? array() : self::header_array( wp_remote_retrieve_headers( $resp ) ),
				'html'     => is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp ),
				'settings' => self::settings_snapshot(),
			);
			$analysis        = BreadcrumbDiagnostics::analyze( $page );
			$results[ $type ] = $analysis;
		}
		set_transient( self::SCAN_KEY, array( 'time' => time(), 'results' => $results, 'settings' => self::settings_snapshot() ), 15 * MINUTE_IN_SECONDS );
		AdminAssets::done( self::SLUG, $results ? 'success' : 'error', $results ? __( 'Site check finished.', 'publishrank' ) : __( 'No published article, page or category was found to test.', 'publishrank' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action  = admin_url( 'admin-post.php' );
		$sep     = BreadcrumbSeparators::current();
		$groups  = BreadcrumbSeparators::groups();
		$place   = BreadcrumbEngine::placement();
		$labels  = array(
			'auto'        => __( 'Automatic (above the title on posts, pages, archives, block themes and Elementor templates where supported)', 'publishrank' ),
			'content'     => __( 'Above the content', 'publishrank' ),
			'hook'        => __( 'Theme hook', 'publishrank' ),
			'manual'      => __( 'Manual (block, shortcode or template tag)', 'publishrank' ),
			'schema_only' => __( 'Structured data only (no visible trail)', 'publishrank' ),
		);
		echo '<div class="wrap">';
		AdminAssets::header( __( 'Breadcrumbs', 'publishrank' ), __( 'Control the visible trail, its separator and the BreadcrumbList structured data.', 'publishrank' ) );
		AdminAssets::render_flash();
		echo '<form method="post" action="' . esc_url( $action ) . '" class="rhs-card"><input type="hidden" name="action" value="publishrank_breadcrumbs_save" />';
		wp_nonce_field( 'publishrank_breadcrumbs_save' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Visible breadcrumbs', 'publishrank' ) . '</th><td>';
		$this->checkbox( 'publishrank_breadcrumbs_enabled', OutputSettings::visible_breadcrumbs(), __( 'Print the breadcrumb trail on the site', 'publishrank' ) );
		echo '<div class="notice notice-warning inline" style="margin:10px 0 8px;padding:8px 12px"><p><strong>' . esc_html__( 'Recommended: turn off theme breadcrumbs', 'publishrank' ) . '</strong> ' . esc_html__( 'When PublishRank visible breadcrumbs are enabled, disable the theme or Elementor template’s own breadcrumb widget/function to prevent two trails appearing on the same page. PublishRank cannot safely switch off theme-owned breadcrumbs automatically. If you prefer the theme’s trail, turn off this PublishRank visibility option instead.', 'publishrank' ) . '</p></div>';
		echo '<br />';
		$this->checkbox( 'publishrank_breadcrumb_frontend_override', OutputSettings::breadcrumb_override(), __( 'Frontend override: use the separator below in breadcrumb trails printed by supported plugins (Yoast SEO, Rank Math, WooCommerce, All in One SEO)', 'publishrank' ) );
		echo '<p class="description">' . esc_html__( 'On by default. Turn it off to leave other plugins’ separators exactly as they are. A theme’s own hard-coded trail cannot be changed from here; use the site check below to see which renderer prints each trail.', 'publishrank' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="pr-bc-preset">' . esc_html__( 'Separator', 'publishrank' ) . '</label></th><td><select id="pr-bc-preset"><option value="__custom">' . esc_html__( 'Custom…', 'publishrank' ) . '</option>';
		foreach ( $groups as $group => $items ) {
			echo '<optgroup label="' . esc_attr( $group ) . '">';
			foreach ( $items as $value => $label ) {
				echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $sep, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select> <label for="pr-bc-custom">' . esc_html__( 'Separator text', 'publishrank' ) . '</label> <input type="text" id="pr-bc-custom" name="publishrank_breadcrumbs_separator" value="' . esc_attr( $sep ) . '" maxlength="' . esc_attr( (string) BreadcrumbSeparators::MAX_LENGTH ) . '" class="small-text" style="white-space:pre" />';
		/* translators: %d: maximum characters. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Choose a preset or type any Unicode symbol or short text (up to %d characters). Spaces are kept. The separator is never written into the JSON-LD.', 'publishrank' ), BreadcrumbSeparators::MAX_LENGTH ) ) . '</p>';
		echo '<p>' . esc_html__( 'Preview:', 'publishrank' ) . ' <code id="pr-bc-preview" style="white-space:pre-wrap" aria-live="polite"></code></p></td></tr>';

		echo '<tr><th scope="row"><label for="pr-bc-placement">' . esc_html__( 'Placement', 'publishrank' ) . '</label></th><td><select id="pr-bc-placement" name="publishrank_breadcrumb_placement">';
		foreach ( $labels as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $place, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <label for="pr-bc-hook">' . esc_html__( 'Theme hook name', 'publishrank' ) . '</label> <input type="text" id="pr-bc-hook" name="publishrank_breadcrumb_hook" value="' . esc_attr( (string) get_option( 'publishrank_breadcrumb_hook', '' ) ) . '" class="regular-text code" /></td></tr>';

			echo '<tr><th scope="row"><label for="pr-bc-home">' . esc_html__( 'First crumb label', 'publishrank' ) . '</label></th><td><input type="text" id="pr-bc-home" name="publishrank_breadcrumb_home_label" value="' . esc_attr( (string) get_option( 'publishrank_breadcrumb_home_label', '' ) ) . '" class="regular-text" /><p class="description">' . esc_html__( 'Leave empty to use the site name. The hierarchy follows the post’s primary category, parent pages, taxonomy ancestors and archives.', 'publishrank' ) . '</p></td></tr>';
			$style = sanitize_key( (string) get_option( 'publishrank_breadcrumb_style', 'plain' ) );
			$size  = sanitize_key( (string) get_option( 'publishrank_breadcrumb_size', 'medium' ) );
			echo '<tr><th scope="row">' . esc_html__( 'Appearance', 'publishrank' ) . '</th><td><label for="pr-bc-style">' . esc_html__( 'Look', 'publishrank' ) . '</label> <select id="pr-bc-style" name="publishrank_breadcrumb_style">';
			foreach ( array( 'plain' => __( 'Plain text', 'publishrank' ), 'chips' => __( 'Rounded chips', 'publishrank' ), 'bar' => __( 'Soft background bar', 'publishrank' ), 'underline' => __( 'Underline', 'publishrank' ) ) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $style, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> <label for="pr-bc-size">' . esc_html__( 'Size', 'publishrank' ) . '</label> <select id="pr-bc-size" name="publishrank_breadcrumb_size">';
			foreach ( array( 'small' => __( 'Small', 'publishrank' ), 'medium' => __( 'Medium', 'publishrank' ), 'large' => __( 'Large', 'publishrank' ) ) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $size, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select><br /><label for="pr-bc-text-color">' . esc_html__( 'Text', 'publishrank' ) . '</label> <input id="pr-bc-text-color" name="publishrank_breadcrumb_text_color" type="text" class="small-text code" value="' . esc_attr( (string) get_option( 'publishrank_breadcrumb_text_color', '' ) ) . '" placeholder="#444444" /> <label for="pr-bc-link-color">' . esc_html__( 'Links', 'publishrank' ) . '</label> <input id="pr-bc-link-color" name="publishrank_breadcrumb_link_color" type="text" class="small-text code" value="' . esc_attr( (string) get_option( 'publishrank_breadcrumb_link_color', '' ) ) . '" placeholder="#0a58ca" /> <label for="pr-bc-bg-color">' . esc_html__( 'Background', 'publishrank' ) . '</label> <input id="pr-bc-bg-color" name="publishrank_breadcrumb_bg_color" type="text" class="small-text code" value="' . esc_attr( (string) get_option( 'publishrank_breadcrumb_bg_color', '' ) ) . '" placeholder="#eef2f7" /><p class="description">' . esc_html__( 'Colors accept hex values and are applied only to the PublishRank trail.', 'publishrank' ) . '</p></td></tr>';
			$preview = $this->preview_items();
			echo '<tr><th scope="row">' . esc_html__( 'Actual preview', 'publishrank' ) . '</th><td><div class="publishrank-breadcrumb-preview" style="padding:12px 14px;border:1px solid #dbe3ee;border-radius:8px;background:#fff;max-width:760px">';
			echo '<p class="description" style="margin-top:0">' . esc_html( $preview['description'] ) . '</p><nav aria-label="' . esc_attr__( 'Breadcrumb preview', 'publishrank' ) . '"><ol style="display:flex;flex-wrap:wrap;gap:0;list-style:none;margin:0;padding:0;align-items:center">';
			foreach ( $preview['items'] as $index => $item ) {
				if ( $index > 0 ) { echo '<li aria-hidden="true" style="margin:0 8px">' . esc_html( $sep ) . '</li>'; }
				echo '<li>' . ( $index < count( $preview['items'] ) - 1 ? '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>' : '<span aria-current="page">' . esc_html( $item['name'] ) . '</span>' ) . '</li>';
			}
			echo '</ol></nav><details style="margin-top:12px"><summary>' . esc_html__( 'Preview the exact BreadcrumbList JSON-LD items', 'publishrank' ) . '</summary><pre style="white-space:pre-wrap;max-height:220px;overflow:auto">' . esc_html( wp_json_encode( $preview['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details></div></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Structured data', 'publishrank' ) . '</th><td>';
		$this->checkbox( 'publishrank_breadcrumb_schema_enabled', OutputSettings::breadcrumb_schema(), __( 'Output BreadcrumbList JSON-LD (independent of the visible trail)', 'publishrank' ) );
		echo '<p class="description">' . esc_html__( 'Not added while another active SEO plugin has its own breadcrumb structured data switched on (PublishRank checks that plugin’s setting where it can). The site check shows how many BreadcrumbList objects the page really contains.', 'publishrank' ) . '</p></td></tr>';

			echo '<tr><th scope="row">' . esc_html__( 'Recovery and diagnostics', 'publishrank' ) . '</th><td><label for="pr-bc-error-mode">' . esc_html__( 'Invalid item behavior', 'publishrank' ) . '</label> <select id="pr-bc-error-mode" name="publishrank_breadcrumb_error_mode">';
			foreach ( array( 'skip' => __( 'Skip invalid item', 'publishrank' ), 'fallback' => __( 'Use fallback item', 'publishrank' ), 'text' => __( 'Keep text without link', 'publishrank' ), 'disable' => __( 'Disable trail on error', 'publishrank' ) ) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( get_option( 'publishrank_breadcrumb_error_mode', 'skip' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select><br /><label for="pr-bc-fallback-label">' . esc_html__( 'Fallback label', 'publishrank' ) . '</label> <input id="pr-bc-fallback-label" name="publishrank_breadcrumb_fallback_label" type="text" class="regular-text" value="' . esc_attr( (string) get_option( 'publishrank_breadcrumb_fallback_label', 'Home' ) ) . '" /><br /><label for="pr-bc-fallback-url">' . esc_html__( 'Fallback URL', 'publishrank' ) . '</label> <input id="pr-bc-fallback-url" name="publishrank_breadcrumb_fallback_url" type="url" class="large-text" value="' . esc_attr( (string) get_option( 'publishrank_breadcrumb_fallback_url', home_url( '/' ) ) ) . '" /><br /><input type="hidden" name="publishrank_log_errors" value="0" /><label><input type="checkbox" name="publishrank_log_errors" value="1"' . checked( get_option( 'publishrank_breadcrumb_log_errors', false ), true, false ) . ' /> ' . esc_html__( 'Record invalid breadcrumb items locally for administrators', 'publishrank' ) . '</label><p class="description">' . esc_html__( 'The preview uses your latest published post and the saved publication, category, title and URL values. It never inserts the visual separator into JSON-LD.', 'publishrank' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save breadcrumb settings', 'publishrank' ) );
		echo '</form>';

		// Site check.
		echo '<div class="rhs-card"><h2>' . esc_html__( 'Site check: why is (or is not) a breadcrumb trail visible?', 'publishrank' ) . '</h2><p>' . esc_html__( 'Fetches your latest article, latest page and a category archive as a visitor would, reads the HTML and a diagnostic note the plugin prints during that request, and reports what is confirmed and what is only a possible cause. It never runs on visitor requests.', 'publishrank' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $action ) . '"><input type="hidden" name="action" value="publishrank_breadcrumbs_scan" />';
		wp_nonce_field( 'publishrank_breadcrumbs_scan' );
		submit_button( __( 'Run site check', 'publishrank' ), 'secondary', 'submit', false );
		echo '</form>';
		$result = get_transient( self::SCAN_KEY );
		if ( is_array( $result ) && ! empty( $result['results'] ) ) {
			$cfg = isset( $result['settings'] ) ? (array) $result['settings'] : array();
			echo '<p class="description">' . esc_html( sprintf( /* translators: 1: visible yes/no, 2: placement, 3: schema yes/no. */ __( 'Settings at the time of the check: visible breadcrumbs %1$s, placement %2$s, BreadcrumbList %3$s.', 'publishrank' ), ! empty( $cfg['visible'] ) ? __( 'on', 'publishrank' ) : __( 'off', 'publishrank' ), (string) ( isset( $cfg['placement'] ) ? $cfg['placement'] : '' ), ! empty( $cfg['schema'] ) ? __( 'on', 'publishrank' ) : __( 'off', 'publishrank' ) ) ) . '</p>';
			foreach ( (array) $result['results'] as $type => $analysis ) {
				echo '<h3>' . esc_html( ucfirst( (string) $type ) ) . '</h3><p><code>' . esc_html( (string) $analysis['url'] ) . '</code> — HTTP ' . esc_html( (string) (int) $analysis['status'] ) . '</p>';
				if ( empty( $analysis['conclusive'] ) ) {
					echo '<p class="notice notice-warning inline"><span>' . esc_html__( 'This result is inconclusive: some causes below could not be confirmed.', 'publishrank' ) . '</span></p>';
				}
				echo '<ul class="ul-disc">';
				foreach ( (array) $analysis['findings'] as $finding ) {
					echo '<li><strong>' . esc_html( ucfirst( (string) $finding['level'] ) ) . ( empty( $finding['confirmed'] ) ? ' · ' . esc_html__( 'not confirmed', 'publishrank' ) : '' ) . ':</strong> ' . esc_html( (string) $finding['message'] ) . ( '' !== $finding['next'] ? ' <em>' . esc_html__( 'Next:', 'publishrank' ) . ' ' . esc_html( (string) $finding['next'] ) . '</em>' : '' ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<p class="description">' . esc_html__( 'Cached pages: purge your page cache first if a change you made does not appear.', 'publishrank' ) . '</p>';
		}
		echo '</div>';
		?>
		<script>
		(function(){
			var sel=document.getElementById('pr-bc-preset'),inp=document.getElementById('pr-bc-custom'),pv=document.getElementById('pr-bc-preview');
			if(!sel||!inp||!pv){return;}
			function draw(){pv.textContent='Home'+inp.value+'News'+inp.value+'Article title';}
			function sync(){var f=false;for(var i=0;i<sel.options.length;i++){if(sel.options[i].value===inp.value&&sel.options[i].value!=='__custom'){sel.selectedIndex=i;f=true;break;}}if(!f){sel.value='__custom';}}
			sel.addEventListener('change',function(){if(sel.value!=='__custom'){inp.value=sel.value;}draw();});
			inp.addEventListener('input',function(){sync();draw();});
			sync();draw();
		})();
		</script>
		<?php
		echo '</div>';
	}

		/** Build preview items from real site data without changing the front-end query. */
		private function preview_items() {
			$items = array(
				array(
					'name' => (string) ( get_option( 'publishrank_breadcrumb_home_label', '' ) ?: \PublishRankSEO\Core\PublicationProfile::publication_name() ),
					'url'  => home_url( '/' ),
				),
			);
			$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'suppress_filters' => true ) );
			if ( ! empty( $posts ) && $posts[0] instanceof \WP_Post ) {
				$post = $posts[0];
				$terms = get_the_category( $post->ID );
				if ( ! empty( $terms ) && $terms[0] instanceof \WP_Term ) {
					$term_url = get_term_link( $terms[0] );
					if ( ! is_wp_error( $term_url ) && '' !== (string) $term_url ) {
						$items[] = array( 'name' => (string) $terms[0]->name, 'url' => (string) $term_url );
					}
				}
				$items[] = array( 'name' => (string) get_the_title( $post ), 'url' => (string) get_permalink( $post ) );
				return array( 'items' => $items, 'description' => sprintf( /* translators: %s: URL. */ __( 'Using the latest published post: %s', 'publishrank' ), (string) get_permalink( $post ) ) );
			}
			$items[] = array( 'name' => __( 'Sample article title', 'publishrank' ), 'url' => home_url( '/' ) );
			return array( 'items' => $items, 'description' => __( 'No published post was found, so a safe sample item is shown.', 'publishrank' ) );
		}

		/** Hidden 0 + checkbox 1 so an unchecked box is saved as off. */
	private function checkbox( $name, $checked, $label ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" /><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $checked, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}
}
