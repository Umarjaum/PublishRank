<?php
/**
 * Diagnostics screen: configuration checks and a live scan of the site's own pages.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Compatibility\ConflictDetector;
use PublishRankSEO\Compatibility\JNewsAdapter;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\OutputRegistry;
use PublishRankSEO\Sitemap\SitemapController;
use PublishRankSEO\Sitemap\Engine\SitemapAudit;

final class DiagnosticsPage {
	/** @var OutputRegistry */
	private $registry;

	/** @var JNewsAdapter */
	private $jnews;

	public function __construct( OutputRegistry $registry, JNewsAdapter $jnews ) {
		$this->registry = $registry;
		$this->jnews    = $jnews;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 14 );
		add_action( 'admin_post_publishrank_seo_scan', array( $this, 'handle_scan' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'PublishRank Diagnostics', 'publishrank' ), __( 'Diagnostics', 'publishrank' ), 'manage_options', 'publishrank-seo-diagnostics', array( $this, 'render' ) );
	}

	/** Run the live scan and keep the result for the next page view only. */
	public function handle_scan() {
		AdminAssets::guard( 'publishrank_seo_scan' );
		set_transient( 'publishrank_seo_scan_' . get_current_user_id(), ( new FrontendScanner() )->run(), 300 );
		wp_safe_redirect( Branding::page_url( 'publishrank-seo-diagnostics' ) );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		AdminAssets::header( __( 'Diagnostics', 'publishrank' ), __( 'Check configuration, then scan your live pages for duplicate or missing SEO tags.', 'publishrank' ) );

		$conflicts     = ( new ConflictDetector() )->diagnostics();
		$compatibility = $this->jnews->diagnostics();
		$this->row( __( 'SEO plugin conflicts', 'publishrank' ), $conflicts['status'], $conflicts['message'] );
		$this->row( __( 'JNews compatibility', 'publishrank' ), $compatibility['status'], $compatibility['message'] );

		$engine    = ( new SitemapController() )->engine();
		$audit     = SitemapAudit::run( $engine, 5 );
		$providers = $engine->providers();
		$enabled   = 0;
		$shards    = 0;
		foreach ( $providers as $provider ) {
			if ( $provider->is_enabled() ) {
				$enabled++;
				$shards += $provider->get_shard_count();
			}
		}
		$this->row( __( 'Sitemap XML audit', 'publishrank' ), $audit['status'], sprintf( /* translators: 1: providers, 2: shards, 3: URLs. */ __( '%1$d active providers, %2$d shard(s), %3$d URL(s), %4$d XML issue(s).', 'publishrank' ), $enabled, $shards, $audit['urls'], count( $audit['errors'] ) ) );
		$this->row( __( 'Sitemap cache', 'publishrank' ), get_option( 'publishrank_seo_cache_epoch', false ) ? 'PASS' : 'WARNING', sprintf( /* translators: 1: cache epoch, 2: last invalidation. */ __( 'Cache epoch %1$s; last invalidation %2$s.', 'publishrank' ), (string) get_option( 'publishrank_seo_cache_epoch', 'not initialized' ), (string) get_option( 'publishrank_seo_last_invalidation', 'not recorded' ) ) );

		echo '<h2>' . esc_html__( 'Live frontend scan', 'publishrank' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Requests your homepage and latest article from this server and counts the SEO tags in the HTML. This is the only reliable way to catch a theme or plugin printing a second canonical or description.', 'publishrank' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_seo_scan" />';
		wp_nonce_field( 'publishrank_seo_scan' );
		submit_button( __( 'Run frontend scan', 'publishrank' ), 'primary', 'submit', false );
		echo '</form>';

		$key     = 'publishrank_seo_scan_' . get_current_user_id();
		$results = get_transient( $key );
		if ( is_array( $results ) ) {
			delete_transient( $key );
			foreach ( $results as $result ) {
				echo '<h3>' . esc_html( $result['label'] ) . ' <small><code>' . esc_html( $result['url'] ) . '</code></small></h3>';
				if ( isset( $result['error'] ) ) {
					$this->row( $result['label'], 'ERROR', $result['error'] );
					continue;
				}
				foreach ( FrontendScanner::findings( $result['counts'] ) as $finding ) {
					$this->row( $result['label'], $finding['status'], $finding['message'] );
				}
			}
		}
		echo '</div>';
	}

	private function row( $label, $status, $message ) {
		$class = 'PASS' === strtoupper( $status ) ? 'notice-success' : ( 'ERROR' === strtoupper( $status ) ? 'notice-error' : 'notice-warning' );
		echo '<div class="notice inline ' . esc_attr( $class ) . '"><p><strong>' . esc_html( $status ) . '</strong> — ' . esc_html( $label ) . ': ' . esc_html( $message ) . '</p></div>';
	}
}
