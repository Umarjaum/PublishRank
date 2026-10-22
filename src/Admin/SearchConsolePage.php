<?php
/** Read-only Search Console connection administration. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Google\SearchConsoleClient;
use PublishRankSEO\Google\GscBroker;

defined( 'ABSPATH' ) || exit;

final class SearchConsolePage {
	const SLUG = 'publishrank-search-console';
	private $client;

	public function __construct() { $this->client = new SearchConsoleClient(); }
	public function register() { add_action( 'admin_menu', array( $this, 'menu' ), 13 ); $this->client->register(); }
	public function menu() { add_submenu_page( Branding::MENU_SLUG, __( 'Google Search Console', 'publishrank' ), __( 'Search Console', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) ); }

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="wrap publishrank-search-console">';
		AdminAssets::header( __( 'Google Search Console', 'publishrank' ), __( 'Sign in with Google for live, read-only search performance, Discover and Google News tracking, and URL inspection.', 'publishrank' ) );
			$this->render_notice();
			if ( ! SearchConsoleClient::connected() ) { $this->render_signin(); }
			else { $this->render_connection(); }
		echo '</div>';
	}

	/** One-click "Sign in with Google" — no client ID, no keys. */
	private function render_signin() {
		$health = ! SearchConsoleClient::configured() ? GscBroker::health() : array( 'configured' => true, 'reachable' => true );
		$ready = ! empty( $health['configured'] );
		$g = '<svg class="pr-google-btn__g" viewBox="0 0 48 48" width="20" height="20" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
		echo '<section class="rhs-card pr-gsc-hero"><div class="pr-gsc-hero__main"><span class="pr-eyebrow">' . esc_html__( 'Google Search Console', 'publishrank' ) . '</span><h2>' . esc_html__( 'Connect in one click', 'publishrank' ) . '</h2><p class="pr-gsc-hero__lead">' . esc_html__( 'Sign in with the Google account that manages this site in Search Console. No API keys, no client ID — just your Google account.', 'publishrank' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_gsc_connect">'; wp_nonce_field( 'publishrank_gsc_connect' );
		echo '<button type="submit" class="pr-google-btn"' . ( $ready ? '' : ' disabled' ) . '>' . $g . '<span>' . esc_html__( 'Sign in with Google', 'publishrank' ) . '</span></button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		if ( ! $ready ) { echo '<p class="pr-gsc-hero__note">' . esc_html( empty( $health['reachable'] ) ? __( 'The PublishRank sign-in service can’t be reached from this server right now. Try again shortly, or use your own Google OAuth client below.', 'publishrank' ) : __( 'Google sign-in is being switched on for the PublishRank service. Use your own Google OAuth client below in the meantime.', 'publishrank' ) ) . '</p>'; }
		echo '<p class="pr-gsc-hero__fine">' . esc_html__( 'Read-only access. PublishRank can’t change your Search Console settings, and you can disconnect at any time.', 'publishrank' ) . '</p></div>';
		echo '<ul class="pr-gsc-hero__list"><li><i class="fa-solid fa-bolt" aria-hidden="true"></i><span><strong>' . esc_html__( 'Live numbers', 'publishrank' ) . '</strong> ' . esc_html__( 'Hourly clicks and impressions update on screen without a reload.', 'publishrank' ) . '</span></li><li><i class="fa-solid fa-newspaper" aria-hidden="true"></i><span><strong>' . esc_html__( 'Built for news', 'publishrank' ) . '</strong> ' . esc_html__( 'Google News and Discover tracked alongside Search.', 'publishrank' ) . '</span></li><li><i class="fa-solid fa-ranking-star" aria-hidden="true"></i><span><strong>' . esc_html__( 'What is working', 'publishrank' ) . '</strong> ' . esc_html__( 'Top queries and articles from the last 7 days.', 'publishrank' ) . '</span></li><li><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><span><strong>' . esc_html__( 'Zero setup', 'publishrank' ) . '</strong> ' . esc_html__( 'Your property is matched to this site automatically.', 'publishrank' ) . '</span></li></ul></section>';
		if ( ! SearchConsoleClient::configured() ) {
			echo '<details class="rhs-card pr-gsc-advanced"><summary>' . esc_html__( 'Advanced: use my own Google OAuth client', 'publishrank' ) . '</summary>'; $this->render_credentials(); echo '</details>';
		}
	}

	/** Live panel: JS (assets/js/live.js) fills it from the Heartbeat payload. */
	private function render_live_panel() {
		$type = static function ( $label, $key ) { return '<div class="pr-kpi"><p class="pr-kpi__label">' . esc_html( $label ) . '</p><p class="pr-kpi__value" data-pr-live="kpi.' . esc_attr( $key ) . '" data-fmt="' . esc_attr( 'ctr' === $key ? 'pct' : ( 'position' === $key ? 'pos' : 'int' ) ) . '">—</p>' . ( in_array( $key, array( 'clicks', 'impressions' ), true ) ? '<p class="pr-kpi__delta" data-pr-delta="kpi.' . esc_attr( $key ) . '_delta"></p>' : '<p class="pr-kpi__delta pr-kpi__delta--muted">' . esc_html__( '7-day', 'publishrank' ) . '</p>' ) . '</div>'; };
		echo '<section class="rhs-card pr-live" data-pr-live-root>';
		echo '<div class="pr-live__head"><div class="pr-live__who"><span class="pr-live-pill" data-pr-live-pill><i class="pr-live-dot"></i><span>' . esc_html__( 'Live', 'publishrank' ) . '</span></span><strong data-pr-live="email"></strong><span class="pr-live__prop" data-pr-live="property"></span></div>';
		echo '<div class="pr-live__tools"><span class="pr-live__updated" data-pr-live-updated></span><button type="button" class="button" data-pr-live-refresh>' . esc_html__( 'Refresh now', 'publishrank' ) . '</button></div></div>';
		echo '<div class="pr-live__reauth" data-pr-live-reauth hidden><span>' . esc_html__( 'Google access expired or was revoked. Sign in again to resume live data.', 'publishrank' ) . '</span><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_gsc_connect">'; wp_nonce_field( 'publishrank_gsc_connect' ); echo '<button type="submit" class="button button-primary">' . esc_html__( 'Sign in with Google', 'publishrank' ) . '</button></form></div>';
		echo '<div class="pr-kpis">' . $type( __( 'Clicks · 7 days', 'publishrank' ), 'clicks' ) . $type( __( 'Impressions · 7 days', 'publishrank' ), 'impressions' ) . $type( __( 'Average CTR', 'publishrank' ), 'ctr' ) . $type( __( 'Average position', 'publishrank' ), 'position' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in closure.
		echo '<div class="pr-live__chart"><div class="pr-live__charthead"><h3>' . esc_html__( 'Last 48 hours', 'publishrank' ) . '</h3><span class="pr-legend"><i class="pr-legend__bar"></i>' . esc_html__( 'Impressions', 'publishrank' ) . ' <i class="pr-legend__line"></i>' . esc_html__( 'Clicks', 'publishrank' ) . '</span></div><div data-pr-live-chart></div><p class="description" data-pr-live-chartnote></p></div>';
		echo '<div class="pr-live__cols"><div><h3>' . esc_html__( 'Top queries · 7 days', 'publishrank' ) . '</h3><table class="widefat striped pr-live__table"><thead><tr><th>' . esc_html__( 'Query', 'publishrank' ) . '</th><th>' . esc_html__( 'Clicks', 'publishrank' ) . '</th><th>' . esc_html__( 'Pos.', 'publishrank' ) . '</th></tr></thead><tbody data-pr-live-list="queries"></tbody></table></div>';
		echo '<div><h3>' . esc_html__( 'Top articles · 7 days', 'publishrank' ) . '</h3><table class="widefat striped pr-live__table"><thead><tr><th>' . esc_html__( 'Page', 'publishrank' ) . '</th><th>' . esc_html__( 'Clicks', 'publishrank' ) . '</th><th>' . esc_html__( 'Pos.', 'publishrank' ) . '</th></tr></thead><tbody data-pr-live-list="pages"></tbody></table></div></div>';
		echo '<div class="pr-live__surfaces"><div class="pr-surface"><i class="fa-solid fa-compass" aria-hidden="true"></i><div><p class="pr-surface__label">' . esc_html__( 'Google Discover · 7 days', 'publishrank' ) . '</p><p class="pr-surface__value"><span data-pr-live="surfaces.discover.clicks" data-fmt="int">—</span> ' . esc_html__( 'clicks', 'publishrank' ) . ' · <span data-pr-live="surfaces.discover.impressions" data-fmt="int">—</span> ' . esc_html__( 'impressions', 'publishrank' ) . '</p></div></div><div class="pr-surface"><i class="fa-solid fa-newspaper" aria-hidden="true"></i><div><p class="pr-surface__label">' . esc_html__( 'Google News · 7 days', 'publishrank' ) . '</p><p class="pr-surface__value"><span data-pr-live="surfaces.googleNews.clicks" data-fmt="int">—</span> ' . esc_html__( 'clicks', 'publishrank' ) . ' · <span data-pr-live="surfaces.googleNews.impressions" data-fmt="int">—</span> ' . esc_html__( 'impressions', 'publishrank' ) . '</p></div></div></div>';
		echo '<div class="pr-live__newsroom"><h3>' . esc_html__( 'Newsroom right now', 'publishrank' ) . '</h3><ul class="pr-live__feed" data-pr-live-list="latest"></ul></div>';
		echo '</section>';
	}

	private function render_credentials() {
		$callback = SearchConsoleClient::callback_url();
		echo '<div class="rhs-grid"><section class="rhs-card pr-gsc-setup"><h2>' . esc_html__( 'Set up Google OAuth', 'publishrank' ) . '</h2><ol><li>' . esc_html__( 'Create or select a Google Cloud project and enable the Google Search Console API.', 'publishrank' ) . '</li><li>' . esc_html__( 'Create an OAuth client ID for a Web application and add the exact callback URI shown below as an authorized redirect URI.', 'publishrank' ) . '</li><li>' . esc_html__( 'Add the Google account that will connect Search Console as a test user if the consent screen is still in testing.', 'publishrank' ) . '</li><li>' . esc_html__( 'Save the client ID and secret here, then connect the Google account that already has access to the Search Console property.', 'publishrank' ) . '</li></ol><p><strong>' . esc_html__( 'Authorized redirect URI', 'publishrank' ) . ':</strong><br /><code class="pr-gsc-callback">' . esc_html( $callback ) . '</code></p><p class="description">' . esc_html__( 'Google requires an API project and OAuth 2.0 for Search Console data. No service account is used. PublishRank requests only webmasters.readonly; it cannot submit/delete sitemaps or make indexing requests with this permission.', 'publishrank' ) . '</p></section>';
		echo '<section class="rhs-card"><h2>' . esc_html__( 'OAuth client credentials', 'publishrank' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_gsc_save_credentials">'; wp_nonce_field( 'publishrank_gsc_save_credentials' );
		echo '<p><label for="publishrank-gsc-client-id"><strong>' . esc_html__( 'Client ID', 'publishrank' ) . '</strong></label><br /><input class="large-text code" type="text" id="publishrank-gsc-client-id" name="client_id" autocomplete="off" required /></p><p><label for="publishrank-gsc-client-secret"><strong>' . esc_html__( 'Client secret', 'publishrank' ) . '</strong></label><br /><input class="large-text" type="password" id="publishrank-gsc-client-secret" name="client_secret" autocomplete="new-password" required /></p><p class="description">' . esc_html__( 'The client secret is encrypted at rest with the WordPress authentication salt and is never displayed, exported or sent to the browser after saving.', 'publishrank' ) . '</p>'; submit_button( __( 'Save OAuth client', 'publishrank' ), 'primary', 'submit', false ); echo '</form></section></div>';
	}

	private function render_connection() {
		$sites = SearchConsoleClient::cached_sites(); $property = SearchConsoleClient::property(); $metrics = SearchConsoleClient::metrics(); $connected = SearchConsoleClient::connected();
		$this->render_live_panel();
		echo '<div class="rhs-card pr-gsc-status"><div class="pr-gsc-actions"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_gsc_connect">'; wp_nonce_field( 'publishrank_gsc_connect' ); submit_button( __( 'Switch Google account / update properties', 'publishrank' ), 'secondary', 'submit', false ); echo '</form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(' . esc_attr( wp_json_encode( __( 'Disconnect Search Console and delete cached data? Access is also revoked at Google.', 'publishrank' ) ) ) . ');"><input type="hidden" name="action" value="publishrank_gsc_disconnect">'; wp_nonce_field( 'publishrank_gsc_disconnect' ); submit_button( __( 'Disconnect', 'publishrank' ), 'delete', 'submit', false ); echo '</form></div><p class="description">' . esc_html__( 'Data refreshes automatically every hour and whenever you click Refresh now. Nothing is requested on the public site or when articles are saved.', 'publishrank' ) . '</p></div>';
		if ( $sites ) {
			echo '<section class="rhs-card"><h2>' . esc_html__( 'Verified Search Console property', 'publishrank' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_gsc_save_property">'; wp_nonce_field( 'publishrank_gsc_save_property' ); echo '<label for="publishrank-gsc-property">' . esc_html__( 'Property', 'publishrank' ) . '</label> <select id="publishrank-gsc-property" name="property" required><option value="">' . esc_html__( 'Choose a property', 'publishrank' ) . '</option>';
			foreach ( $sites as $site ) { if ( ! is_array( $site ) || empty( $site['siteUrl'] ) ) { continue; } echo '<option value="' . esc_attr( $site['siteUrl'] ) . '" ' . selected( $property, $site['siteUrl'], false ) . '>' . esc_html( $site['siteUrl'] . ' — ' . ( $site['permissionLevel'] ?? '' ) ) . '</option>'; }
			echo '</select> '; submit_button( __( 'Save property', 'publishrank' ), 'secondary', 'submit', false ); echo '</form><p class="description">' . esc_html__( 'Only properties returned by the connected Google account with an eligible permission are available. Domain properties use the sc-domain:example.com identifier.', 'publishrank' ) . '</p></section>';
		} else { echo '<div class="notice notice-warning"><p>' . esc_html__( 'No verified properties are available to this Google account. Reconnect after verifying the site or granting account access in Search Console.', 'publishrank' ) . '</p></div>'; }
			if ( '' !== $property ) { $this->render_performance( $property, $metrics ); $this->render_sitemaps( $property ); $this->render_inspection(); }
	}

	private function render_performance( $property, $metrics ) {
		/* translators: %s: Search Console property. */
		echo '<section class="rhs-card"><h2>' . esc_html__( 'Search performance', 'publishrank' ) . '</h2><p>' . esc_html( sprintf( __( 'Property: %s', 'publishrank' ), $property ) ) . '</p><form class="pr-gsc-refresh" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_gsc_refresh">'; wp_nonce_field( 'publishrank_gsc_refresh' );
		echo '<label for="publishrank-gsc-days">' . esc_html__( 'Range', 'publishrank' ) . '</label> <select id="publishrank-gsc-days" name="days"><option value="7">7 days</option><option value="28" selected>28 days</option><option value="90">90 days</option></select> <label for="publishrank-gsc-type">' . esc_html__( 'Search type', 'publishrank' ) . '</label> <select id="publishrank-gsc-type" name="search_type"><option value="web">Web</option><option value="news">News</option><option value="googleNews">Google News</option><option value="discover">Discover</option></select> '; submit_button( __( 'Refresh read-only data', 'publishrank' ), 'primary', 'submit', false ); echo '</form>';
		if ( ! empty( $metrics['days'] ) ) {
			$totals = array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0 ); $rows = 0;
			foreach ( $metrics['days'] as $row ) { if ( ! is_array( $row ) ) { continue; } $totals['clicks'] += (int) ( $row['clicks'] ?? 0 ); $totals['impressions'] += (int) ( $row['impressions'] ?? 0 ); $totals['ctr'] += (float) ( $row['ctr'] ?? 0 ); $totals['position'] += (float) ( $row['position'] ?? 0 ); $rows++; }
			$avg_ctr = $rows ? $totals['ctr'] / $rows : 0; $avg_position = $rows ? $totals['position'] / $rows : 0;
			echo '<div class="rhs-grid pr-gsc-kpis"><div class="rhs-card"><p class="rhs-label">' . esc_html__( 'Clicks', 'publishrank' ) . '</p><p class="rhs-stat">' . esc_html( number_format_i18n( $totals['clicks'] ) ) . '</p></div><div class="rhs-card"><p class="rhs-label">' . esc_html__( 'Impressions', 'publishrank' ) . '</p><p class="rhs-stat">' . esc_html( number_format_i18n( $totals['impressions'] ) ) . '</p></div><div class="rhs-card"><p class="rhs-label">' . esc_html__( 'Average daily CTR', 'publishrank' ) . '</p><p class="rhs-stat">' . esc_html( number_format_i18n( $avg_ctr * 100, 2 ) ) . '<small>%</small></p></div><div class="rhs-card"><p class="rhs-label">' . esc_html__( 'Average position', 'publishrank' ) . '</p><p class="rhs-stat">' . esc_html( number_format_i18n( $avg_position, 1 ) ) . '</p></div></div>';
			/* translators: %1$s: data retrieval date and time; %2$s: data retrieval date and time; %3$s: data retrieval date and time. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Fetched %1$s · %2$s / %3$s · Google Search Console data is aggregated, can be delayed, and may omit low-volume rows.', 'publishrank' ), (string) ( $metrics['fetched_at'] ?? '' ), (string) ( $metrics['start_date'] ?? '' ), (string) ( $metrics['end_date'] ?? '' ) ) ) . '</p><div class="rhs-table-wrap"><table class="widefat striped"><thead><tr><th>Date (Google)</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody>';
			foreach ( array_reverse( $metrics['days'], true ) as $date => $row ) { if ( ! is_array( $row ) ) { continue; } echo '<tr><td>' . esc_html( (string) $date ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $row['clicks'] ?? 0 ) ) ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $row['impressions'] ?? 0 ) ) ) . '</td><td>' . esc_html( number_format_i18n( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ) ) . '%</td><td>' . esc_html( number_format_i18n( (float) ( $row['position'] ?? 0 ), 1 ) ) . '</td></tr>'; }
			echo '</tbody></table></div>';
		} else { echo '<p class="description">' . esc_html__( 'No Search Console data is cached yet. Select a range and refresh.', 'publishrank' ) . '</p>'; }
			echo '</section>';
		}

		private function render_sitemaps( $property ) {
			$cache = SearchConsoleClient::sitemaps();
			$rows = is_array( $cache['rows'] ?? null ) && ( $cache['property'] ?? '' ) === $property ? $cache['rows'] : array();
			echo '<section class="rhs-card"><h2>' . esc_html__( 'Submitted sitemap status', 'publishrank' ) . '</h2><p>' . esc_html__( 'This reads sitemap status reported by Google for the selected property. PublishRank does not submit or delete Google sitemaps with its read-only permission.', 'publishrank' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_gsc_refresh_sitemaps">'; wp_nonce_field( 'publishrank_gsc_refresh_sitemaps' ); submit_button( __( 'Refresh sitemap status', 'publishrank' ), 'secondary', 'submit', false ); echo '</form>';
			/* translators: %s: last refresh date and time. */
			if ( ! empty( $cache['fetched_at'] ) && ( $cache['property'] ?? '' ) === $property ) { echo '<p class="description">' . esc_html( sprintf( __( 'Last refreshed: %s', 'publishrank' ), (string) $cache['fetched_at'] ) ) . '</p>'; }
			if ( empty( $rows ) ) { echo '<p class="description">' . esc_html__( 'No cached sitemap rows. Refresh to check whether Google reports submitted sitemaps for this property.', 'publishrank' ) . '</p></section>'; return; }
			echo '<div class="rhs-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Sitemap', 'publishrank' ) . '</th><th>' . esc_html__( 'Last submitted', 'publishrank' ) . '</th><th>' . esc_html__( 'Last downloaded', 'publishrank' ) . '</th><th>' . esc_html__( 'State', 'publishrank' ) . '</th><th>' . esc_html__( 'Warnings', 'publishrank' ) . '</th><th>' . esc_html__( 'Errors', 'publishrank' ) . '</th><th>' . esc_html__( 'Submitted / indexed', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$counts = array(); foreach ( (array) ( $row['contents'] ?? array() ) as $content ) { if ( is_array( $content ) ) { $counts[] = (string) ( $content['type'] ?? '' ) . ': ' . number_format_i18n( (int) ( $content['submitted'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $content['indexed'] ?? 0 ) ); } }
				echo '<tr><td><a href="' . esc_url( (string) ( $row['path'] ?? '' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) ( $row['path'] ?? '' ) ) . '</a></td><td>' . esc_html( (string) ( $row['last_submitted'] ?? '—' ) ) . '</td><td>' . esc_html( (string) ( $row['last_downloaded'] ?? '—' ) ) . '</td><td>' . esc_html( ! empty( $row['is_pending'] ) ? __( 'Pending', 'publishrank' ) : __( 'Processed', 'publishrank' ) ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $row['warnings'] ?? 0 ) ) ) . '</td><td>' . esc_html( number_format_i18n( (int) ( $row['errors'] ?? 0 ) ) ) . '</td><td>' . esc_html( $counts ? implode( '; ', $counts ) : '—' ) . '</td></tr>';
			}
			echo '</tbody></table></div></section>';
		}

		private function render_inspection() {
		$last = get_transient( 'publishrank_gsc_inspection_' . get_current_user_id() );
		echo '<section class="rhs-card"><h2>' . esc_html__( 'Inspect a URL', 'publishrank' ) . '</h2><p>' . esc_html__( 'Read-only inspection of Google’s known index version. This does not test the live URL and cannot request indexing.', 'publishrank' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_gsc_inspect">'; wp_nonce_field( 'publishrank_gsc_inspect' ); echo '<label for="publishrank-gsc-inspect-url">' . esc_html__( 'URL within the selected property', 'publishrank' ) . '</label><br /><input class="large-text" type="url" id="publishrank-gsc-inspect-url" name="inspect_url" placeholder="' . esc_attr( home_url( '/' ) ) . '" required /> '; submit_button( __( 'Inspect URL', 'publishrank' ), 'secondary', 'submit', false ); echo '</form>';
		if ( is_array( $last ) && ! empty( $last['result'] ) ) { echo '<p><strong>' . esc_html( $last['url'] ?? '' ) . '</strong></p><dl class="pr-gsc-inspection">'; foreach ( $last['result'] as $key => $value ) { echo '<dt>' . esc_html( ucwords( preg_replace( '/([A-Z])/', ' $1', (string) $key ) ) ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>'; } echo '</dl>'; }
		echo '</section>';
	}

	private function render_notice() {
		$key = 'publishrank_gsc_notice_' . get_current_user_id(); $notice = get_transient( $key ); if ( ! is_array( $notice ) || empty( $notice['message'] ) ) { return; } delete_transient( $key ); echo '<div class="notice notice-' . esc_attr( $notice['type'] ?? 'info' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}
}
