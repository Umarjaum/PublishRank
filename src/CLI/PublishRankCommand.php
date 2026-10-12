<?php
namespace PublishRankSEO\CLI;

use PublishRankSEO\Sitemap\SitemapController;
use PublishRankSEO\Sitemap\Engine\SitemapAudit;

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class PublishRankCommand {
		private function engine() { return ( new SitemapController() )->engine(); }
		/** Regenerate sitemap caches. */
		public function sitemap( $args, $assoc_args ) {
			$engine = $this->engine(); $type = isset( $assoc_args['type'] ) ? sanitize_key( $assoc_args['type'] ) : '';
			$subcommand = $args[0] ?? '';
			if ( in_array( $subcommand, array( 'validate', 'audit' ), true ) ) { $report = SitemapAudit::run( $engine, 10 ); if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) { \WP_CLI::line( wp_json_encode( $report ) ); } elseif ( 'PASS' === $report['status'] ) { \WP_CLI::success( sprintf( '%d providers, %d shards, %d URLs audited.', $report['providers'], $report['shards'], $report['urls'] ) ); } else { foreach ( $report['errors'] as $error ) { \WP_CLI::warning( $error ); } \WP_CLI::error( 'Sitemap audit found issues.' ); } return; }
			if ( 'clear-cache' === ( $args[0] ?? '' ) ) { $engine->clear_cache(); \WP_CLI::success( 'PublishRank sitemap cache epoch advanced.' ); return; }
			if ( 'status' === ( $args[0] ?? '' ) ) { foreach ( $engine->providers() as $provider ) { \WP_CLI::line( $provider->get_slug() . ': ' . ( $provider->is_enabled() ? 'enabled' : 'disabled' ) . ', shards=' . $provider->get_shard_count() ); } return; }
			foreach ( $engine->providers() as $provider ) { if ( $type && $type !== $provider->get_slug() ) { continue; } for ( $shard = 1; $shard <= $provider->get_shard_count(); $shard++ ) { $engine->render( $provider->get_slug(), $shard ); } }
			\WP_CLI::success( $type ? 'Regenerated ' . $type . ' sitemap.' : 'Regenerated enabled sitemaps.' );
		}
	}
	\WP_CLI::add_command( 'publishrank sitemap', __NAMESPACE__ . '\\PublishRankCommand' );
}
