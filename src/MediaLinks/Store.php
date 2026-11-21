<?php
/**
 * Media Link Manager change log: runs (one planned operation) and changes (one database value or file each).
 * Every change keeps the exact previous raw value so the run can be rolled back, and the exact new value so a
 * rollback can refuse to overwrite anything edited after the run.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\MediaLinks;

use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Store {
	const STATUSES = array( 'planned', 'applying', 'applied', 'partial', 'verified', 'finalized', 'rolled_back', 'discarded', 'failed' );

	public static function ready() {
		return Database::ready( 'media_runs' ) && Database::ready( 'media_changes' );
	}

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** @return int Run id. */
	public static function create_run( array $run, array $changes ) {
		global $wpdb;
		$now = self::now();
		$wpdb->insert(
			Database::table( 'media_runs' ),
			array(
				'created_at'    => $now,
				'updated_at'    => $now,
				'user_id'       => get_current_user_id(),
				'attachment_id' => (int) $run['attachment_id'],
				'operation'     => (string) $run['operation'],
				'status'        => 'planned',
				'old_url'       => (string) $run['old_url'],
				'new_url'       => (string) $run['new_url'],
				'plan'          => wp_json_encode( $run['plan'] ),
				'report'        => '',
				'total'         => count( $changes ),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return 0;
		}
		foreach ( $changes as $c ) {
			$wpdb->insert(
				Database::table( 'media_changes' ),
				array(
					'run_id'      => $id,
					'seq'         => (int) $c['seq'],
					'object_type' => (string) $c['object_type'],
					'object_id'   => (int) $c['object_id'],
					'sub_id'      => (int) $c['sub_id'],
					'field'       => substr( (string) $c['field'], 0, 191 ),
					'label'       => substr( (string) $c['label'], 0, 255 ),
					'old_value'   => (string) $c['old_value'],
					'new_value'   => (string) $c['new_value'],
					'matches'     => (int) $c['matches'],
					'status'      => isset( $c['status'] ) ? (string) $c['status'] : 'pending',
					'message'     => substr( isset( $c['message'] ) ? (string) $c['message'] : '', 0, 255 ),
				)
			);
		}
		return $id;
	}

	public static function run( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'media_runs' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $r ) {
			return null;
		}
		$r['plan']   = json_decode( (string) $r['plan'], true ) ?: array();
		$r['report'] = json_decode( (string) $r['report'], true ) ?: array();
		foreach ( array( 'id', 'user_id', 'attachment_id', 'total', 'done', 'failed' ) as $k ) {
			$r[ $k ] = (int) $r[ $k ];
		}
		return $r;
	}

	public static function update_run( $id, array $fields ) {
		global $wpdb;
		foreach ( array( 'plan', 'report' ) as $k ) {
			if ( isset( $fields[ $k ] ) && is_array( $fields[ $k ] ) ) {
				$fields[ $k ] = wp_json_encode( $fields[ $k ] );
			}
		}
		$fields['updated_at'] = self::now();
		$wpdb->update( Database::table( 'media_runs' ), $fields, array( 'id' => (int) $id ) );
	}

	/** @return array<int,array<string,mixed>> */
	public static function runs( $limit = 50, $attachment_id = 0 ) {
		global $wpdb;
		$where = $attachment_id ? $wpdb->prepare( 'WHERE attachment_id = %d', (int) $attachment_id ) : '';
		$rows  = $wpdb->get_results( 'SELECT id, created_at, updated_at, user_id, attachment_id, operation, status, old_url, new_url, total, done, failed FROM ' . Database::table( 'media_runs' ) . " {$where} ORDER BY id DESC LIMIT " . (int) $limit, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function changes( $run_id, $status = '', $order = 'ASC', $limit = 500, $with_values = true ) {
		global $wpdb;
		$cols  = $with_values ? '*' : 'id, run_id, seq, object_type, object_id, sub_id, field, label, matches, status, message, applied_at';
		$where = $wpdb->prepare( 'run_id = %d', (int) $run_id );
		if ( $status ) {
			$st    = (array) $status;
			$where .= " AND status IN ('" . implode( "','", array_map( 'esc_sql', $st ) ) . "')";
		}
		$order = 'DESC' === $order ? 'DESC' : 'ASC';
		$rows  = $wpdb->get_results( 'SELECT ' . $cols . ' FROM ' . Database::table( 'media_changes' ) . " WHERE {$where} ORDER BY seq {$order}, id {$order} LIMIT " . (int) $limit, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	public static function counts( $run_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM ' . Database::table( 'media_changes' ) . ' WHERE run_id = %d GROUP BY status', (int) $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	public static function set_change( $id, $status, $message = '', $extra = array() ) {
		global $wpdb;
		$wpdb->update(
			Database::table( 'media_changes' ),
			array_merge( array( 'status' => $status, 'message' => substr( (string) $message, 0, 255 ), 'applied_at' => self::now() ), $extra ),
			array( 'id' => (int) $id )
		);
	}

	public static function add_change( $run_id, array $c ) {
		global $wpdb;
		$wpdb->insert(
			Database::table( 'media_changes' ),
			array(
				'run_id'      => (int) $run_id,
				'seq'         => (int) $c['seq'],
				'object_type' => (string) $c['object_type'],
				'object_id'   => (int) ( isset( $c['object_id'] ) ? $c['object_id'] : 0 ),
				'sub_id'      => 0,
				'field'       => substr( (string) $c['field'], 0, 191 ),
				'label'       => substr( (string) $c['label'], 0, 255 ),
				'old_value'   => (string) $c['old_value'],
				'new_value'   => (string) $c['new_value'],
				'matches'     => 0,
				'status'      => (string) $c['status'],
				'message'     => substr( (string) ( isset( $c['message'] ) ? $c['message'] : '' ), 0, 255 ),
				'applied_at'  => self::now(),
			)
		);
	}

	public static function delete_run( $id ) {
		global $wpdb;
		$wpdb->delete( Database::table( 'media_changes' ), array( 'run_id' => (int) $id ) );
		$wpdb->delete( Database::table( 'media_runs' ), array( 'id' => (int) $id ) );
	}

	/** Transactions where the engine supports them (InnoDB, SQLite); the change log keeps everything reversible otherwise. */
	public static function begin() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function commit() {
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function rollback_tx() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
