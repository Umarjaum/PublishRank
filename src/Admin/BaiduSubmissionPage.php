<?php
/** Baidu publisher submission controls. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Indexing\BaiduSubmission;
use PublishRankSEO\Sitemap\SitemapUrls;

defined( 'ABSPATH' ) || exit;

final class BaiduSubmissionPage {
	public function register() { add_action( 'admin_menu', array( $this, 'menu' ), 15 ); }
	public function menu() { add_submenu_page( Branding::MENU_SLUG, __( 'Baidu & Search Engines', 'publishrank' ), __( 'Search Engines', 'publishrank' ), 'manage_options', 'publishrank-baidu', array( $this, 'render' ) ); }

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="wrap publishrank-search-engines">';
		AdminAssets::header( __( 'Search Engines & Baidu', 'publishrank' ), __( 'Publisher-controlled submission for Baidu and participating IndexNow engines.', 'publishrank' ) );
		$this->notice(); $this->render_context(); $this->render_settings();
		if ( BaiduSubmission::configured() ) { $this->render_bulk(); $this->render_history(); }
		echo '</div>';
	}

	private function render_context() {
		$indexnow = home_url( '/publishrank-indexnow-key.txt' ); $sitemap = SitemapUrls::index_url();
		echo '<div class="rhs-grid"><section class="rhs-card"><h2>' . esc_html__( 'Baidu Search Resource Platform', 'publishrank' ) . '</h2><p>' . esc_html__( 'Verify this site in Baidu Search Resource Platform, open its ordinary-inclusion API submission tool, and copy the latest HTTPS endpoint for this exact site. The token is contained in that endpoint URL and is encrypted by PublishRank. Keep your Baidu verification record in place. Quotas vary; duplicate URLs can consume quota.', 'publishrank' ) . '</p><p><a class="button" href="https://ziyuan.baidu.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Baidu Search Resource Platform', 'publishrank' ) . '</a></p><p class="description">' . esc_html__( 'Baidu’s official documentation does not publish a general OAuth or analytics-read API. This connector only submits URL discovery requests; it does not sync Baidu dashboard analytics or promise indexing.', 'publishrank' ) . '</p></section><section class="rhs-card"><h2>' . esc_html__( 'Sitemaps and IndexNow', 'publishrank' ) . '</h2><p><strong>' . esc_html__( 'PublishRank sitemap index', 'publishrank' ) . '</strong><br /><a href="' . esc_url( $sitemap ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $sitemap ) . '</a></p><p>' . esc_html__( 'You can add this sitemap in Baidu’s platform when the account exposes sitemap submission. PublishRank does not claim that Google’s read-only scope can submit sitemaps.', 'publishrank' ) . '</p><p><strong>' . esc_html__( 'IndexNow verification URL', 'publishrank' ) . '</strong><br /><a href="' . esc_url( $indexnow ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $indexnow ) . '</a></p><p class="description">' . esc_html__( 'IndexNow notifies participating engines through one shared protocol (including Bing and other participating engines). It is separate from Baidu submission and does not guarantee crawling, indexing or ranking.', 'publishrank' ) . '</p><a class="button" href="' . esc_url( Branding::page_url( 'publishrank-indexnow' ) ) . '">' . esc_html__( 'Manage IndexNow', 'publishrank' ) . '</a></section></div>';
	}

	private function render_settings() {
		$configured = BaiduSubmission::configured(); $automatic = BaiduSubmission::automatic();
		echo '<section class="rhs-card pr-baidu-settings"><h2>' . esc_html__( 'Baidu URL submission settings', 'publishrank' ) . '</h2><p><span class="rhs-pill ' . esc_attr( $configured ? 'rhs-pill--pass' : 'rhs-pill--warning' ) . '">' . esc_html( $configured ? __( 'Endpoint configured · secret hidden', 'publishrank' ) : __( 'Not connected', 'publishrank' ) ) . '</span></p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_baidu_save">'; wp_nonce_field( 'publishrank_baidu_save' );
		echo '<p><label for="publishrank-baidu-endpoint"><strong>' . esc_html__( 'HTTPS ordinary-inclusion API endpoint', 'publishrank' ) . '</strong></label><br /><input class="large-text code" type="password" id="publishrank-baidu-endpoint" name="endpoint" autocomplete="new-password" placeholder="' . esc_attr( $configured ? __( 'Leave blank to keep the saved endpoint', 'publishrank' ) : 'https://data.zz.baidu.com/urls?site=…&token=…' ) . '" /></p><p class="description">' . esc_html__( 'Paste the exact endpoint shown inside your verified Baidu account. For safety, this integration rejects plain HTTP, non-Baidu hosts, mismatched site hosts and endpoints without site/token parameters. The endpoint (including its token) is stored encrypted and is never shown or logged.', 'publishrank' ) . '</p><input type="hidden" name="automatic" value="0"><label class="pr-auth-toggle"><input type="checkbox" name="automatic" value="1" ' . checked( $automatic, true, false ) . ' /> ' . esc_html__( 'Automatically submit newly published, public, indexable posts (not edits or deletions)', 'publishrank' ) . '</label><p class="description">' . esc_html__( 'Automatic submissions use the same post-type, noindex, password and public-site checks as PublishRank IndexNow. Temporary network/server failures are retried up to three times. Enable only after checking the Baidu site quota.', 'publishrank' ) . '</p>'; submit_button( __( 'Save Baidu settings', 'publishrank' ), 'primary', 'submit', false ); echo '</form>';
		if ( $configured ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(' . esc_attr( wp_json_encode( __( 'Delete the encrypted Baidu endpoint and local submission history?', 'publishrank' ) ) ) . ');"><input type="hidden" name="action" value="publishrank_baidu_disconnect">'; wp_nonce_field( 'publishrank_baidu_disconnect' ); submit_button( __( 'Disconnect and delete local history', 'publishrank' ), 'delete', 'submit', false ); echo '</form>'; }
		echo '</section>';
	}

	private function render_bulk() {
		echo '<section class="rhs-card"><h2>' . esc_html__( 'Manual URL submission', 'publishrank' ) . '</h2><p>' . esc_html__( 'Submit 1–2,000 unique URLs from this exact site in one plain-text request. Blank lines are ignored. The request is sent only after you submit this form.', 'publishrank' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_baidu_submit">'; wp_nonce_field( 'publishrank_baidu_submit' ); echo '<textarea class="large-text code" rows="8" name="urls" placeholder="https://example.com/news/story/" required></textarea>'; submit_button( __( 'Submit URLs to Baidu', 'publishrank' ), 'primary', 'submit', false ); echo '</form></section>';
	}

	private function render_history() {
		$rows = BaiduSubmission::history(); echo '<section class="rhs-card"><h2>' . esc_html__( 'Recent Baidu response history', 'publishrank' ) . '</h2><p class="description">' . esc_html__( 'Only counts and HTTP/status metadata are stored; submitted URLs, tokens and raw API response bodies are not retained.', 'publishrank' ) . '</p><div class="rhs-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'publishrank' ) . '</th><th>' . esc_html__( 'Result', 'publishrank' ) . '</th><th>' . esc_html__( 'HTTP', 'publishrank' ) . '</th><th>' . esc_html__( 'Submitted', 'publishrank' ) . '</th><th>' . esc_html__( 'Accepted', 'publishrank' ) . '</th><th>' . esc_html__( 'Quota remaining', 'publishrank' ) . '</th><th>' . esc_html__( 'Invalid / wrong site', 'publishrank' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) { echo '<tr><td colspan="7">' . esc_html__( 'No Baidu submissions yet.', 'publishrank' ) . '</td></tr>'; }
		foreach ( $rows as $row ) { if ( ! is_array( $row ) ) { continue; } echo '<tr><td>' . esc_html( (string) ( $row['time'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td><td>' . esc_html( (string) (int) ( $row['http'] ?? 0 ) ) . '</td><td>' . esc_html( (string) (int) ( $row['submitted'] ?? 0 ) ) . '</td><td>' . esc_html( (string) (int) ( $row['success'] ?? 0 ) ) . '</td><td>' . esc_html( null === ( $row['remain'] ?? null ) ? '—' : (string) (int) $row['remain'] ) . '</td><td>' . esc_html( (string) (int) ( $row['invalid'] ?? 0 ) . ' / ' . (string) (int) ( $row['same_site'] ?? 0 ) ) . '</td></tr>'; }
		echo '</tbody></table></div></section>';
	}

	private function notice() { $key = 'publishrank_baidu_notice_' . get_current_user_id(); $notice = get_transient( $key ); if ( ! is_array( $notice ) || empty( $notice['message'] ) ) { return; } delete_transient( $key ); echo '<div class="notice notice-' . esc_attr( $notice['type'] ?? 'info' ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>'; }
}
