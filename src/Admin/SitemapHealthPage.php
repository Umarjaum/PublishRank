<?php
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Sitemap\Engine\SitemapAudit;
use PublishRankSEO\Sitemap\SitemapUrls;

final class SitemapHealthPage {
	const SLUG = 'publishrank-sitemap-health';
	public function register() { add_action( 'admin_menu', array( $this, 'menu' ), 13 ); }
	public function menu() { add_submenu_page( Branding::MENU_SLUG, __( 'Sitemap Health', 'publishrank' ), __( 'Sitemap Health', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) ); }
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$engine = ( new \PublishRankSEO\Sitemap\SitemapController() )->engine(); $report = SitemapAudit::run( $engine, 20 );
		echo '<div class="wrap">'; AdminAssets::header( __( 'Sitemap Health', 'publishrank' ), __( 'Validate generated XML, inspect coverage, and preview URLs before submission.', 'publishrank' ) );
		echo '<div class="notice inline ' . esc_attr( 'PASS' === $report['status'] ? 'notice-success' : ( 'ERROR' === $report['status'] ? 'notice-error' : 'notice-warning' ) ) . '"><p><strong>' . esc_html( $report['status'] ) . '</strong> — ' . esc_html( sprintf( __( '%1$d provider(s), %2$d shard(s), %3$d URL entries, %4$d unique URLs, %5$d URL(s) with lastmod.', 'publishrank' ), $report['providers'], $report['shards'], $report['urls'], $report['unique_urls'], $report['lastmod'] ) ) . '</p></div>';
		if ( ! empty( $report['duplicates'] ) ) { echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( '%d duplicate URL entries were found across sitemap providers and suppressed from the preview. News and extension sitemaps may intentionally reference the same canonical article URL.', 'publishrank' ), $report['duplicates'] ) ) . '</p></div>'; }
		if ( $report['errors'] ) { echo '<ul>'; foreach ( $report['errors'] as $error ) { echo '<li>' . esc_html( $error ) . '</li>'; } echo '</ul>'; }
		echo '<p><strong>' . esc_html__( 'Canonical sitemap index:', 'publishrank' ) . '</strong> <a href="' . esc_url( SitemapUrls::index_url() ) . '" target="_blank" rel="noopener"><code>' . esc_html( SitemapUrls::index_url() ) . '</code></a></p>';
		if ( $report['preview'] ) { echo '<h2>' . esc_html__( 'URL preview', 'publishrank' ) . '</h2><ol>'; foreach ( $report['preview'] as $url ) { echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $url ) . '</code></a></li>'; } echo '</ol>'; }
		echo '</div>';
	}
}
