<?php
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Sitemap\Engine\SitemapAudit;
use PublishRankSEO\Sitemap\SitemapUrls;
use PublishRankSEO\Sitemap\Engine\SitemapEngine;
use PublishRankSEO\Sitemap\Engine\SitemapCursorStore;
use PublishRankSEO\Sitemap\News\NewsSitemapProvider;

defined( 'ABSPATH' ) || exit;

final class SitemapHealthPage {
	const SLUG = 'publishrank-sitemap-health';
	public function register() { add_action( 'admin_menu', array( $this, 'menu' ), 13 ); }
	public function menu() { add_submenu_page( Branding::MENU_SLUG, __( 'Sitemap Health', 'publishrank' ), __( 'Sitemap Health', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) ); }
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$engine = ( new \PublishRankSEO\Sitemap\SitemapController() )->engine(); $report = SitemapAudit::run( $engine, 20 );
		echo '<div class="wrap">'; AdminAssets::header( __( 'Sitemap Health', 'publishrank' ), __( 'Validate generated XML, inspect coverage, and preview URLs before submission.', 'publishrank' ) );
		echo '<div class="notice inline ' . esc_attr( 'PASS' === $report['status'] ? 'notice-success' : ( 'ERROR' === $report['status'] ? 'notice-error' : 'notice-warning' ) ) . '"><p><strong>' . esc_html( $report['status'] ) . '</strong> — ' . esc_html( sprintf( /* translators: 1: provider count, 2: shard count, 3: URL entry count, 4: unique URL count, 5: URL count with lastmod. */ __( '%1$d provider(s), %2$d shard(s), %3$d URL entries, %4$d unique URLs, %5$d URL(s) with lastmod.', 'publishrank' ), $report['providers'], $report['shards'], $report['urls'], $report['unique_urls'], $report['lastmod'] ) ) . '</p></div>';
		if ( ! empty( $report['duplicates'] ) ) { echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( /* translators: %d: number of duplicate URL entries found. */ __( '%d duplicate URL entries were found across sitemap providers and suppressed from the preview. News and extension sitemaps may intentionally reference the same canonical article URL.', 'publishrank' ), $report['duplicates'] ) ) . '</p></div>'; }
		if ( $report['errors'] ) { echo '<ul>'; foreach ( $report['errors'] as $error ) { echo '<li>' . esc_html( $error ) . '</li>'; } echo '</ul>'; }
		echo '<p><strong>' . esc_html__( 'Canonical sitemap index:', 'publishrank' ) . '</strong> <a href="' . esc_url( SitemapUrls::index_url() ) . '" target="_blank" rel="noopener"><code>' . esc_html( SitemapUrls::index_url() ) . '</code></a></p>';
		if ( $report['preview'] ) { echo '<h2>' . esc_html__( 'URL preview', 'publishrank' ) . '</h2><ol>'; foreach ( $report['preview'] as $url ) { echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $url ) . '</code></a></li>'; } echo '</ol>'; }
		$this->render_news( $engine );
		$this->render_engine_status( $engine );
		echo '</div>';
	}

	/** News sitemap diagnostics: everything an owner needs to see why the sitemap is, or is not, populated. */
	private function render_news( $engine ) {
		$provider = $engine->provider( 'news' );
		echo '<h2>' . esc_html__( 'News sitemap diagnostics', 'publishrank' ) . '</h2>';
		if ( ! $provider instanceof NewsSitemapProvider ) { echo '<p>' . esc_html__( 'The News sitemap provider is not registered (another plugin may have filtered it out).', 'publishrank' ) . '</p>'; return; }
		$d = $provider->diagnostics();
		$url = SitemapUrls::shard_url( 'news', 1 );
		$index = (string) $engine->index();
		$in_index = false !== strpos( $index, esc_url_raw( $url ) ) || false !== strpos( $index, str_replace( '&', '&amp;', esc_url_raw( $url ) ) );
		$xml = $d['enabled'] ? $engine->render( 'news', 1 ) : '';
		$valid = is_string( $xml ) && '' !== $xml && function_exists( 'simplexml_load_string' ) && ( libxml_use_internal_errors( true ) || true ) && false !== simplexml_load_string( $xml );
		libxml_clear_errors();
		$skipped = array();
		$labels = array( 'noindex' => __( 'noindex', 'publishrank' ), 'excluded_by_editor' => __( 'excluded by editor', 'publishrank' ), 'missing_title' => __( 'no title', 'publishrank' ), 'invalid_url' => __( 'invalid or off-site URL', 'publishrank' ), 'not_public' => __( 'not public', 'publishrank' ), 'missing_date' => __( 'no publication date', 'publishrank' ) );
		foreach ( (array) $d['skipped'] as $reason => $count ) { $skipped[] = ( $labels[ $reason ] ?? $reason ) . ': ' . (int) $count; }
		$rows = array(
			__( 'Provider enabled', 'publishrank' ) => $d['enabled'] ? __( 'Yes', 'publishrank' ) : __( 'No. Enable it in Settings.', 'publishrank' ),
			__( 'Public URL', 'publishrank' ) => $url,
			__( 'Listed in sitemap index', 'publishrank' ) => $in_index ? __( 'Yes', 'publishrank' ) : __( 'No', 'publishrank' ),
			__( 'Publication window', 'publishrank' ) => sprintf( /* translators: %d: hours */ __( '%d hours', 'publishrank' ), (int) $d['window_hours'] ),
			__( 'Eligible articles now', 'publishrank' ) => (int) $d['eligible'] . ' (' . sprintf( /* translators: %d: files */ _n( '%d file', '%d files', (int) $d['shards'], 'publishrank' ), (int) $d['shards'] ) . ')',
			__( 'Publication name / language', 'publishrank' ) => $d['publication'] . ' / ' . $d['language'],
			__( 'Returns valid XML', 'publishrank' ) => $valid ? __( 'Yes', 'publishrank' ) : __( 'No', 'publishrank' ),
			__( 'Last generated', 'publishrank' ) => $d['generated_at'] ? gmdate( 'Y-m-d H:i:s', (int) $d['generated_at'] ) . ' UTC' : __( 'Not yet', 'publishrank' ),
			__( 'Skipped in last scan', 'publishrank' ) => $skipped ? implode( ', ', $skipped ) : __( 'None', 'publishrank' ),
		);
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $rows as $label => $value ) { echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>'; }
		echo '</tbody></table>';
		if ( $d['enabled'] && 0 === (int) $d['eligible'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( /* translators: %d: hours */ __( 'No eligible articles were published in the last %d hours, so the News sitemap is valid but empty. This is expected if nothing new has been published. If you expect entries, check that the article is published (not scheduled or private), is not noindex, is not excluded in its News setting, and uses a post type listed under News post types.', 'publishrank' ), (int) $d['window_hours'] ) ) . '</p></div>';
		}
		if ( ! empty( $d['truncated'] ) ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The scan hit its safety limit. Reduce the publication window so every recent article is considered.', 'publishrank' ) . '</p></div>'; }
		if ( '' !== $d['error'] ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $d['error'] ) . '</p></div>'; }
	}

	/** Engine-level status: last generation error and cursor/cron health of the cursor-backed providers. */
	private function render_engine_status( $engine ) {
		$error = get_option( SitemapEngine::ERROR_OPTION, array() );
		if ( is_array( $error ) && ! empty( $error['message'] ) ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Last sitemap error', 'publishrank' ) . ':</strong> ' . esc_html( sanitize_key( (string) ( $error['provider'] ?? '' ) ) . ': ' . (string) $error['message'] . ' (' . gmdate( 'Y-m-d H:i:s', (int) ( $error['time'] ?? 0 ) ) . ' UTC)' ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'Background preparation', 'publishrank' ) . '</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Provider', 'publishrank' ) . '</th><th>' . esc_html__( 'State', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $engine->providers() as $provider ) {
			if ( ! method_exists( $provider, 'prepare_cursor' ) ) { continue; }
			$h = SitemapCursorStore::health( $provider->get_slug() );
			$state = $h['complete'] ? __( 'Ready', 'publishrank' ) : sprintf( /* translators: %d: prepared shards */ __( 'Preparing (%d shard checkpoint(s))', 'publishrank' ), (int) $h['prepared'] );
			if ( $h['stale'] ) { $state .= ' — ' . __( 'stalled; it will restart automatically on the next request', 'publishrank' ); }
			if ( $h['cron_overdue'] ) { $state .= ' — ' . __( 'WP-Cron looks overdue; sitemaps still advance on request, but consider a real server cron', 'publishrank' ); }
			echo '<tr><td>' . esc_html( $provider->get_name() ) . '</td><td>' . esc_html( $state ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
