<?php
/**
 * wp publishrank redirect … and wp publishrank 404 …
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\CLI;

use PublishRankSEO\Admin\RedirectsPage;
use PublishRankSEO\Monitor\NotFoundStore;
use PublishRankSEO\Redirect\RedirectRepository as R;
use PublishRankSEO\Redirect\RedirectStore;

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Manage PublishRank redirects.
	 */
	class RedirectCommand {
		/**
		 * List redirects.
		 *
		 * [--search=<text>]
		 * : Search.
		 * [--match=<type>]
		 * : Match.
		 * [--status=<code>]
		 * : Status.
		 * [--per-page=<n>]
		 * : Per page.
		 * [--page=<n>]
		 * : Page.
		 * [--format=<format>]
		 * : table, csv, json, ids, count. Default table.
		 *
		 * @subcommand list
		 */
		public function list_( $args, $assoc ) {
			$res = R::query(
				array(
					'search'     => $assoc['search'] ?? '',
					'match_type' => $assoc['match'] ?? '',
					'status'     => isset( $assoc['status'] ) ? (int) $assoc['status'] : 0,
					'per_page'   => isset( $assoc['per-page'] ) ? (int) $assoc['per-page'] : 100,
					'paged'      => isset( $assoc['page'] ) ? (int) $assoc['page'] : 1,
				)
			);
			$rows = array_map( static function ( $r ) { return array( 'id' => $r['id'], 'source' => $r['source'], 'target' => $r['target'], 'status' => $r['status'], 'match' => $r['match_type'], 'enabled' => $r['enabled'], 'hits' => $r['hits'] ); }, $res['rows'] );
			$fmt  = $assoc['format'] ?? 'table';
			if ( 'count' === $fmt ) {
				\WP_CLI::line( (string) $res['total'] );
				return;
			}
			\WP_CLI\Utils\format_items( $fmt, $rows, array( 'id', 'source', 'target', 'status', 'match', 'enabled', 'hits' ) );
		}

		/**
		 * Add a redirect.
		 *
		 * <source>
		 * : Source.
		 * [<target>]
		 * : Target.
		 * [--status=<code>]
		 * : Status.
		 * [--match=<type>]
		 * : Match.
		 * [--external]
		 * : External.
		 *
		 * @subcommand add
		 */
		public function add( $args, $assoc ) {
			$res = R::save( array( 'source' => $args[0], 'target' => $args[1] ?? '', 'status' => (int) ( $assoc['status'] ?? 301 ), 'match_type' => $assoc['match'] ?? 'exact', 'external' => isset( $assoc['external'] ) ? 1 : 0, 'origin' => 'cli' ) );
			if ( is_wp_error( $res ) ) {
				\WP_CLI::error( implode( ' ', $res->get_error_messages() ) );
			}
			\WP_CLI::success( 'Redirect #' . $res . ' saved.' );
		}

		/**
		 * Delete redirects by id.
		 *
		 * <id>...
		 * : Id.
		 *
		 * @subcommand delete
		 */
		public function delete( $args ) {
			$n = R::bulk( array_map( 'absint', $args ), 'delete' );
			\WP_CLI::success( $n . ' redirect(s) deleted. Undo with: wp publishrank redirect undo ' . R::last_batch() );
		}

		/**
		 * Trace where an address ends up.
		 *
		 * <url>
		 * : Url.
		 *
		 * @subcommand test
		 */
		public function test( $args ) {
			$hops = RedirectsPage::trace( $args[0] );
			if ( ! $hops ) {
				\WP_CLI::line( 'No redirect applies.' );
				return;
			}
			\WP_CLI\Utils\format_items( 'table', $hops, array( 'from', 'status', 'to', 'match', 'id', 'loop' ) );
		}

		/**
		 * Import a CSV or .htaccess file.
		 *
		 * <file>
		 * : File.
		 * [--format=<format>]
		 * : csv or htaccess. Default: by file extension.
		 * [--dry-run]
		 * : Dry run.
		 *
		 * @subcommand import
		 */
		public function import( $args, $assoc ) {
			if ( ! is_readable( $args[0] ) ) {
				\WP_CLI::error( 'File not readable.' );
			}
			$text = (string) file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$fmt  = $assoc['format'] ?? ( preg_match( '/\.csv$/i', $args[0] ) ? 'csv' : 'htaccess' );
			$rows = 'csv' === $fmt ? RedirectStore::parse_csv( $text ) : R::parse_htaccess( $text );
			$res  = R::import( $rows, 0, isset( $assoc['dry-run'] ) );
			foreach ( array_slice( $res['errors'], 0, 50 ) as $e ) {
				\WP_CLI::warning( 'Row ' . $e['row'] . ' (' . $e['source'] . '): ' . $e['error'] );
			}
			\WP_CLI::success( sprintf( '%d rows: %d new, %d updated, %d errors%s.', count( $rows ), $res['created'], $res['updated'], count( $res['errors'] ), isset( $assoc['dry-run'] ) ? ' (dry run)' : '' ) );
		}

		/**
		 * Export all redirects as CSV.
		 *
		 * [--file=<path>]
		 * : File.
		 *
		 * @subcommand export
		 */
		public function export( $args, $assoc ) {
			$rows = R::export_rows();
			$out  = isset( $assoc['file'] ) ? fopen( $assoc['file'], 'w' ) : fopen( 'php://stdout', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			foreach ( $rows as $row ) {
				fputcsv( $out, array_map( array( '\\PublishRankSEO\\Admin\\Suite', 'csv_cell' ), $row ), ',', '"', '\\' );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( isset( $assoc['file'] ) ) {
				\WP_CLI::success( ( count( $rows ) - 1 ) . ' redirects written to ' . $assoc['file'] );
			}
		}

		/**
		 * Show recent change batches.
		 *
		 * @subcommand history
		 */
		public function history() {
			\WP_CLI\Utils\format_items( 'table', R::batches( 30 ), array( 'batch', 'created_at', 'changes', 'actions', 'label' ) );
		}

		/**
		 * Undo a change batch.
		 *
		 * <batch>
		 * : Batch.
		 *
		 * @subcommand undo
		 */
		public function undo( $args ) {
			$res = R::rollback( $args[0] );
			if ( is_wp_error( $res ) ) {
				\WP_CLI::error( $res->get_error_message() );
			}
			\WP_CLI::success( $res . ' change(s) undone.' );
		}

		/**
		 * Move 2.22 option-stored redirects into the table (safe to rerun).
		 *
		 * @subcommand migrate
		 */
		public function migrate() {
			$r = R::migrate_legacy();
			\WP_CLI::success( sprintf( '%d migrated, %d need review.', $r['migrated'], count( $r['errors'] ) ) );
		}
	}

	/**
	 * Inspect and maintain the PublishRank 404 monitor.
	 */
	class NotFoundCommand {
		/**
		 * List 404 addresses.
		 *
		 * [--class=<class>]
		 * : human, crawler, search, ai, scanner.
		 * [--status=<status>]
		 * : Status.
		 * [--search=<text>]
		 * : Search.
		 * [--limit=<n>]
		 * : Limit.
		 * [--format=<format>]
		 * : Format.
		 *
		 * @subcommand list
		 */
		public function list_( $args, $assoc ) {
			$res = NotFoundStore::query( array( 'class' => $assoc['class'] ?? '', 'status' => $assoc['status'] ?? '', 'search' => $assoc['search'] ?? '', 'per_page' => (int) ( $assoc['limit'] ?? 50 ), 'noise' => 'scanner' === ( $assoc['class'] ?? '' ) ? 'show' : 'hide' ) );
			\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $res['rows'], array( 'id', 'path', 'hits', 'hits_human', 'hits_search', 'hits_ai', 'referrer_host', 'last_seen', 'status' ) );
		}

		/**
		 * Summary numbers.
		 *
		 * [--days=<n>]
		 * : Days.
		 *
		 * @subcommand stats
		 */
		public function stats( $args, $assoc ) {
			\WP_CLI::line( (string) wp_json_encode( NotFoundStore::summary( (int) ( $assoc['days'] ?? 30 ) ), JSON_PRETTY_PRINT ) );
		}

		/**
		 * Export 404 rows as CSV.
		 *
		 * [--file=<path>]
		 * : File.
		 *
		 * @subcommand export
		 */
		public function export( $args, $assoc ) {
			$rows = NotFoundStore::export_rows( array( 'noise' => 'show' ) );
			$out  = isset( $assoc['file'] ) ? fopen( $assoc['file'], 'w' ) : fopen( 'php://stdout', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			foreach ( $rows as $row ) {
				fputcsv( $out, array_map( array( '\\PublishRankSEO\\Admin\\Suite', 'csv_cell' ), $row ), ',', '"', '\\' );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( isset( $assoc['file'] ) ) {
				\WP_CLI::success( ( count( $rows ) - 1 ) . ' rows written to ' . $assoc['file'] );
			}
		}

		/**
		 * Delete expired rows now, or everything with --all.
		 *
		 * [--all]
		 * : All.
		 * [--yes]
		 * : Yes.
		 *
		 * @subcommand purge
		 */
		public function purge( $args, $assoc ) {
			if ( isset( $assoc['all'] ) ) {
				\WP_CLI::confirm( 'Delete every 404 entry?', $assoc );
				NotFoundStore::clear( true );
				\WP_CLI::success( 'All 404 entries deleted.' );
				return;
			}
			\WP_CLI::success( NotFoundStore::retention() . ' expired row(s) deleted.' );
		}

		/**
		 * Move the 2.22 option log into the table.
		 *
		 * @subcommand migrate
		 */
		public function migrate() {
			$r = NotFoundStore::migrate_legacy();
			\WP_CLI::success( sprintf( '%d of %d entries migrated%s.', $r['migrated'], $r['total'], $r['deleted_option'] ? '; old option removed' : '' ) );
		}
	}
	\WP_CLI::add_command( 'publishrank redirect', __NAMESPACE__ . '\\RedirectCommand' );
	\WP_CLI::add_command( 'publishrank 404', __NAMESPACE__ . '\\NotFoundCommand' );
}
