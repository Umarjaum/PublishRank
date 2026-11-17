<?php
/**
 * wp publishrank shield …  — Access Shield recovery and status.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\CLI;

use PublishRankSEO\AccessShield\AccessShield;
use PublishRankSEO\AccessShield\Guard;
use PublishRankSEO\AccessShield\LoginAddress;
use PublishRankSEO\AccessShield\SecurityLog;
use PublishRankSEO\AccessShield\Settings;
use PublishRankSEO\AccessShield\TwoFactor;

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Manage PublishRank Access Shield.
	 */
	class ShieldCommand {
		/**
		 * Show Access Shield status.
		 *
		 * [--format=<format>]
		 * : table or json. Default table.
		 *
		 * @subcommand status
		 */
		public function status( $args, $assoc ) {
			$s = AccessShield::status();
			if ( 'json' === \WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' ) ) {
				\WP_CLI::line( wp_json_encode( $s ) );
				return;
			}
			$rows = array();
			foreach ( $s as $k => $v ) {
				$rows[] = array( 'key' => $k, 'value' => is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v );
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
		}

		/**
		 * Live verification: test every protection from the outside and print the evidence.
		 *
		 * [--format=<format>]
		 * : table or json. Default table.
		 *
		 * [--output=<file>]
		 * : Also save the signed JSON report to this file.
		 *
		 * @subcommand verify
		 */
		public function verify( $args, $assoc ) {
			$r = \PublishRankSEO\AccessShield\Verifier::run();
			$out = \WP_CLI\Utils\get_flag_value( $assoc, 'output', '' );
			if ( $out ) {
				file_put_contents( $out, wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			if ( 'json' === \WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' ) ) {
				\WP_CLI::line( wp_json_encode( $r ) );
				return;
			}
			\WP_CLI\Utils\format_items( 'table', $r['results'], array( 'status', 'severity', 'label', 'evidence' ) );
			$msg = sprintf( 'Score %d/100 — %d passed, %d warnings, %d failed, %d skipped (%ss).', $r['score'], $r['counts']['pass'], $r['counts']['warn'], $r['counts']['fail'], $r['counts']['skip'], $r['seconds'] );
			$r['counts']['fail'] ? \WP_CLI::warning( $msg ) : \WP_CLI::success( $msg );
		}

		/**
		 * Check that an exported verification report is unmodified and came from this site.
		 *
		 * <file>
		 * : Path to the JSON report.
		 *
		 * @subcommand verify-report
		 */
		public function verify_report( $args ) {
			$data = json_decode( (string) file_get_contents( $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $data ) ) {
				\WP_CLI::error( 'Not a JSON report.' );
			}
			\PublishRankSEO\AccessShield\Verifier::check( $data ) ? \WP_CLI::success( 'Signature valid: the report is unmodified and was produced by this site.' ) : \WP_CLI::error( 'Signature invalid: the report was modified or produced elsewhere.' );
		}

		/**
		 * Turn off the private login address (the standard /wp-login.php works again).
		 *
		 * [--all]
		 * : Turn off the whole of Access Shield instead.
		 *
		 * @subcommand disable
		 */
		public function disable( $args, $assoc ) {
			if ( \WP_CLI\Utils\get_flag_value( $assoc, 'all', false ) ) {
				update_option( AccessShield::MASTER, 0, true );
				SecurityLog::add( 'settings', 'warn', array( 'detail' => 'Access Shield turned off from WP-CLI.' ) );
				\WP_CLI::success( 'Access Shield is off.' );
				return;
			}
			AccessShield::disable_login_address();
			\WP_CLI::success( 'Private login address turned off. Sign in at ' . trailingslashit( site_url() ) . 'wp-login.php' );
		}

		/**
		 * Turn Access Shield back on.
		 *
		 * @subcommand enable
		 */
		public function enable() {
			update_option( AccessShield::MASTER, 1, true );
			\WP_CLI::success( 'Access Shield is on.' );
		}

		/**
		 * Set the private login address (no 10-minute undo: you are on the server).
		 *
		 * <slug>
		 * : New address, e.g. newsroom-desk.
		 *
		 * @subcommand set-login
		 */
		public function set_login( $args ) {
			$slug  = LoginAddress::clean_slug( $args[0] );
			$valid = LoginAddress::validate_slug( $slug );
			if ( is_wp_error( $valid ) ) {
				\WP_CLI::error( $valid->get_error_message() );
			}
			Settings::update( array( 'login_enabled' => 1, 'login_slug' => $slug ) );
			delete_option( LoginAddress::PENDING_OPT );
			SecurityLog::add( 'settings', 'notice', array( 'detail' => 'Private login address set from WP-CLI.' ) );
			flush_rewrite_rules( false );
			\WP_CLI::success( 'Login address: ' . LoginAddress::url() );
		}

		/**
		 * Remove lockouts for an IP address or username.
		 *
		 * <target>
		 * : IP address or username. Use "all" to clear every lock.
		 *
		 * @subcommand unlock
		 */
		public function unlock( $args ) {
			global $wpdb;
			if ( 'all' === $args[0] ) {
				$n = (int) $wpdb->query( 'DELETE FROM ' . \PublishRankSEO\Core\Database::table( 'shield_attempts' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
				delete_transient( Guard::CHALLENGE );
			} else {
				$n = Guard::unlock( $args[0] );
			}
			\WP_CLI::success( sprintf( 'Cleared %d records.', $n ) );
		}

		/**
		 * List active locks.
		 *
		 * @subcommand locks
		 */
		public function locks() {
			$rows = Guard::active_locks( 500 );
			if ( ! $rows ) {
				\WP_CLI::line( 'Nobody is locked out.' );
				return;
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'kind', 'ip', 'username', 'lock_level', 'lock_until' ) );
		}

		/**
		 * Reset a user's two-factor sign-in.
		 *
		 * <user>
		 * : User ID, login or e-mail.
		 *
		 * @subcommand 2fa-reset
		 */
		public function twofa_reset( $args ) {
			$u = is_numeric( $args[0] ) ? get_user_by( 'id', (int) $args[0] ) : ( is_email( $args[0] ) ? get_user_by( 'email', $args[0] ) : get_user_by( 'login', $args[0] ) );
			if ( ! $u ) {
				\WP_CLI::error( 'User not found.' );
			}
			TwoFactor::disable( $u->ID, 'cli' );
			\WP_CLI::success( 'Two-factor removed for ' . $u->user_login . '. They can sign in with their password and enrol again.' );
		}

		/**
		 * Show recent security events.
		 *
		 * [--event=<event>]
		 * : Filter by event key.
		 *
		 * [--limit=<n>]
		 * : Default 30.
		 *
		 * @subcommand log
		 */
		public function log( $args, $assoc ) {
			$res = SecurityLog::query(
				array(
					'event'    => (string) \WP_CLI\Utils\get_flag_value( $assoc, 'event', '' ),
					'per_page' => (int) \WP_CLI\Utils\get_flag_value( $assoc, 'limit', 30 ),
				)
			);
			\WP_CLI\Utils\format_items( 'table', $res['rows'], array( 'created_at', 'event', 'severity', 'ip', 'username', 'detail' ) );
		}
	}
	\WP_CLI::add_command( 'publishrank shield', __NAMESPACE__ . '\\ShieldCommand' );
}
