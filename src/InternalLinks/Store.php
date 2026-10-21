<?php
/** Database storage and shared queries for the internal-link content graph. */
namespace PublishRankSEO\InternalLinks;

final class Store {
	const DB_VERSION      = '2.5.0';
	const SCAN_ID_OPTION  = 'publishrank_internal_links_scan_id';
	const STATS_TRANSIENT = 'publishrank_internal_links_stats';

	public static function table( $name ) {
		global $wpdb;
		$allowed = array( 'nodes', 'edges', 'terms', 'recommendations' );
		if ( ! in_array( $name, $allowed, true ) ) {
			return '';
		}
		$table = $wpdb->prefix . 'publishrank_internal_links_' . $name;
		return preg_match( '/^[A-Za-z0-9_]+$/', $table ) ? $table : '';
	}

	public static function enabled() {
		return (bool) get_option( 'publishrank_internal_link_enabled', 1 );
	}

	public static function settings() {
		$threshold = (int) get_option( 'publishrank_internal_link_min_inbound', 1 );
		$max       = (int) get_option( 'publishrank_internal_link_max_recommendations', 5 );
		return array(
			'threshold' => max( 0, min( 5, $threshold ) ),
			'max'       => max( 1, min( 10, $max ) ),
			'types'     => (string) get_option( 'publishrank_internal_link_post_types', '' ),
		);
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$nodes   = self::table( 'nodes' );
		$edges   = self::table( 'edges' );
		$terms   = self::table( 'terms' );
		$recs    = self::table( 'recommendations' );

		dbDelta( "CREATE TABLE {$nodes} (
			node_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_id bigint(20) unsigned NOT NULL,
			object_type varchar(32) NOT NULL,
			title text,
			url text,
			status varchar(20) NOT NULL,
			content_hash char(64) NOT NULL DEFAULT '',
			inbound_count int(10) unsigned NOT NULL DEFAULT 0,
			outbound_count int(10) unsigned NOT NULL DEFAULT 0,
			priority varchar(10) NOT NULL DEFAULT '',
			features longtext,
			scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			analyzed_at datetime NULL,
			modified_at datetime NULL,
			PRIMARY KEY (node_id),
			UNIQUE KEY object_id (object_id),
			KEY type_inbound (object_type,inbound_count),
			KEY priority (priority)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$edges} (
			edge_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_node_id bigint(20) unsigned NOT NULL,
			target_node_id bigint(20) unsigned NOT NULL,
			anchor_text varchar(255) NOT NULL DEFAULT '',
			source_url text,
			target_url text,
			scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NULL,
			PRIMARY KEY (edge_id),
			UNIQUE KEY source_target (source_node_id,target_node_id),
			KEY target_node (target_node_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$terms} (
			node_id bigint(20) unsigned NOT NULL,
			term varchar(191) NOT NULL,
			weight tinyint(3) unsigned NOT NULL DEFAULT 1,
			scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (node_id,term),
			KEY term (term)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$recs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_node_id bigint(20) unsigned NOT NULL,
			target_node_id bigint(20) unsigned NOT NULL,
			anchor_text varchar(255) NOT NULL DEFAULT '',
			score decimal(6,2) NOT NULL DEFAULT 0,
			signals longtext,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NULL,
			updated_at datetime NULL,
			PRIMARY KEY (id),
			UNIQUE KEY source_target (source_node_id,target_node_id),
			KEY status_score (status,score)
		) {$charset};" );

		update_option( 'publishrank_internal_graph_db_version', self::DB_VERSION, false );
	}

	public static function drop() {
		global $wpdb;
		foreach ( array( 'recommendations', 'terms', 'edges', 'nodes' ) as $table ) {
			$name = self::table( $table );
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	public static function allowed_types() {
		// 'public' only: the built-in 'page' type is public but NOT publicly_queryable, so filtering on that flag silently
		// dropped every page from the graph (no nodes, no edges to pages, no orphan report for pages).
		$public = get_post_types( array( 'public' => true ), 'names' );
		$exclude = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );
		$public  = array_values( array_diff( (array) $public, $exclude ) );

		$configured = preg_split( '/[\s,]+/', (string) get_option( 'publishrank_internal_link_post_types', '' ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $configured ) ) {
			return array_values( array_unique( $public ) );
		}

		$out = array();
		foreach ( $configured as $type ) {
			$type = sanitize_key( $type );
			if ( $type && in_array( $type, $public, true ) ) {
				$out[] = $type;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function supports_post( $post ) {
		return $post instanceof \WP_Post && 'publish' === $post->post_status && in_array( $post->post_type, self::allowed_types(), true );
	}

	public static function current_scan_id() {
		$id = (int) get_option( self::SCAN_ID_OPTION, 0 );
		if ( ! $id ) {
			$id = time();
			update_option( self::SCAN_ID_OPTION, $id, false );
		}
		return $id;
	}

	public static function new_scan_id() {
		$id = time() * 1000 + wp_rand( 0, 999 );
		update_option( self::SCAN_ID_OPTION, $id, false );
		return $id;
	}

	public static function node_for_object( $object_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE object_id = %d', self::table( 'nodes' ), absint( $object_id ) ) );
		return $row ? $row : null;
	}

	public static function node( $node_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE node_id = %d', self::table( 'nodes' ), absint( $node_id ) ) );
		return $row ? $row : null;
	}

	public static function upsert_node( $post, $features, $scan_id ) {
		global $wpdb;
		$existing = self::node_for_object( $post->ID );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$modified = ! empty( $post->post_modified_gmt ) ? $post->post_modified_gmt : $post->post_modified;
		$inbound  = $existing ? (int) $existing->inbound_count : 0;
		$outbound = $existing ? (int) $existing->outbound_count : 0;
		$data     = array(
			'object_id'    => (int) $post->ID,
			'object_type'  => sanitize_key( $post->post_type ),
			'title'        => (string) get_the_title( $post ),
			'url'          => (string) get_permalink( $post ),
			'status'       => 'publish',
			'content_hash' => hash( 'sha256', (string) $post->post_title . "\n" . (string) $post->post_content ),
			'inbound_count'=> $inbound,
			'outbound_count'=> $outbound,
			'priority'     => self::priority_for_post( $post, $inbound ),
			'features'     => wp_json_encode( $features ),
			'scan_id'      => absint( $scan_id ),
			'analyzed_at'  => $now,
			'modified_at'  => $modified,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' );

		if ( $existing ) {
			$wpdb->update( self::table( 'nodes' ), $data, array( 'node_id' => (int) $existing->node_id ), $formats, array( '%d' ) );
			return (int) $existing->node_id;
		}

		$wpdb->insert( self::table( 'nodes' ), $data, $formats );
		return (int) $wpdb->insert_id;
	}

	public static function priority_for_post( $post, $inbound, $threshold = null ) {
		$threshold = null === $threshold ? self::settings()['threshold'] : (int) $threshold;
		if ( $inbound > $threshold ) {
			return '';
		}
		$modified = ! empty( $post->post_modified_gmt ) ? strtotime( $post->post_modified_gmt ) : strtotime( (string) $post->post_modified );
		$age      = $modified ? ( time() - $modified ) / DAY_IN_SECONDS : 0;
		$focus    = trim( (string) get_post_meta( $post->ID, '_publishrank_seo_focus_keyword', true ) );

		if ( 0 === (int) $inbound ) {
			return ( 'post' === $post->post_type || $age <= 365 || '' !== $focus ) ? 'critical' : 'high';
		}
		return ( 'post' === $post->post_type || $age <= 365 ) ? 'medium' : 'low';
	}

	public static function replace_terms( $node_id, $terms, $scan_id ) {
		global $wpdb;
		$node_id = absint( $node_id );
		$wpdb->delete( self::table( 'terms' ), array( 'node_id' => $node_id ), array( '%d' ) );
		foreach ( (array) $terms as $term => $weight ) {
			$term = function_exists( 'mb_substr' ) ? mb_substr( (string) $term, 0, 191, 'UTF-8' ) : substr( (string) $term, 0, 191 );
			if ( '' === trim( $term ) ) {
				continue;
			}
			$wpdb->insert(
				self::table( 'terms' ),
				array(
					'node_id' => $node_id,
					'term'    => $term,
					'weight'  => max( 1, min( 100, absint( $weight ) ) ),
					'scan_id' => absint( $scan_id ),
				),
				array( '%d', '%s', '%d', '%d' )
			);
		}
	}

	public static function insert_edge( $source_node_id, $target_node_id, $anchor, $source_url, $target_url, $scan_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (source_node_id,target_node_id,anchor_text,source_url,target_url,scan_id,created_at) VALUES (%d,%d,%s,%s,%s,%d,%s)',
				self::table( 'edges' ),
				absint( $source_node_id ),
				absint( $target_node_id ),
				mb_substr( (string) $anchor, 0, 255, 'UTF-8' ),
				(string) $source_url,
				(string) $target_url,
				absint( $scan_id ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	public static function edge_exists( $source_node_id, $target_node_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT edge_id FROM %i WHERE source_node_id = %d AND target_node_id = %d',
				self::table( 'edges' ),
				absint( $source_node_id ),
				absint( $target_node_id )
			)
		);
	}

	public static function delete_edges_for_source( $node_id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'edges' ), array( 'source_node_id' => absint( $node_id ) ), array( '%d' ) );
	}

	public static function refresh_counts( $node_id ) {
		global $wpdb;
		$node = self::node( $node_id );
		if ( ! $node ) {
			return;
		}
		$outbound = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_node_id = %d', self::table( 'edges' ), $node->node_id ) );
		$inbound  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE target_node_id = %d', self::table( 'edges' ), $node->node_id ) );
		$post     = get_post( (int) $node->object_id );
		$priority = $post ? self::priority_for_post( $post, $inbound ) : '';
		$wpdb->update(
			self::table( 'nodes' ),
			array(
				'inbound_count'  => $inbound,
				'outbound_count' => $outbound,
				'priority'       => $priority,
			),
			array( 'node_id' => (int) $node->node_id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	public static function delete_post( $post_id ) {
		global $wpdb;
		$node = self::node_for_object( $post_id );
		if ( ! $node ) {
			self::invalidate_cache();
			return;
		}
		$nodes = self::table( 'nodes' );
		$edges = self::table( 'edges' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_node_id = %d OR target_node_id = %d', $edges, $node->node_id, $node->node_id ) );
		$wpdb->delete( self::table( 'terms' ), array( 'node_id' => $node->node_id ), array( '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_node_id = %d OR target_node_id = %d', self::table( 'recommendations' ), $node->node_id, $node->node_id ) );
		$wpdb->delete( $nodes, array( 'node_id' => $node->node_id ), array( '%d' ) );
		self::invalidate_cache();
	}

	public static function delete_unlocked_recommendations() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status IN (%s, %s)', self::table( 'recommendations' ), 'pending', 'dismissed' ) );
	}

	public static function cleanup_scan( $scan_id ) {
		global $wpdb;
		$nodes = self::table( 'nodes' );
		$edges = self::table( 'edges' );
		$terms = self::table( 'terms' );
		$recs  = self::table( 'recommendations' );

		$wpdb->query( $wpdb->prepare( 'DELETE e FROM %i e LEFT JOIN %i n ON n.node_id = e.source_node_id WHERE n.node_id IS NULL OR e.scan_id != %d', $edges, $nodes, $scan_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE t FROM %i t LEFT JOIN %i n ON n.node_id = t.node_id WHERE n.node_id IS NULL OR t.scan_id != %d', $terms, $nodes, $scan_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE scan_id != %d', $nodes, $scan_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE r FROM %i r LEFT JOIN %i s ON s.node_id = r.source_node_id LEFT JOIN %i t ON t.node_id = r.target_node_id WHERE s.node_id IS NULL OR t.node_id IS NULL', $recs, $nodes, $nodes ) );
	}

	public static function total_posts() {
		global $wpdb;
		$types = self::allowed_types();
		if ( empty( $types ) ) {
			return 0;
		}
			$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder count comes only from the validated post-type list; all values are bound by prepare().
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_status = 'publish' AND post_type IN ({$placeholders})", array_merge( array( $wpdb->posts ), $types ) ) );
	}

	public static function post_ids_after( $last_id, $limit ) {
		global $wpdb;
		$types = self::allowed_types();
		if ( empty( $types ) ) {
			return array();
		}
			$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder count comes only from the validated post-type list; all values are bound by prepare().
			return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( array( $wpdb->posts ), $types, array( absint( $last_id ), absint( $limit ) ) ) ) ) );
	}

	public static function orphan_nodes_after( $last_node_id, $limit, $threshold ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE inbound_count <= %d AND node_id > %d ORDER BY node_id ASC LIMIT %d', self::table( 'nodes' ), absint( $threshold ), absint( $last_node_id ), absint( $limit ) ) );
	}

	public static function stats() {
		global $wpdb;
		$settings = self::settings();
		return array(
			'nodes'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table( 'nodes' ) ) ),
			'edges'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table( 'edges' ) ) ),
			'orphans'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE inbound_count <= %d', self::table( 'nodes' ), $settings['threshold'] ) ),
			'pending'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', self::table( 'recommendations' ), 'pending' ) ),
			'approved' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s)', self::table( 'recommendations' ), 'approved', 'inserted' ) ),
		);
	}

	public static function orphans( $threshold, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$page = max( 1, absint( $page ) );
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE inbound_count <= %d ORDER BY inbound_count ASC, object_id ASC LIMIT %d OFFSET %d',
				self::table( 'nodes' ),
				absint( $threshold ),
				absint( $per_page ),
				absint( ( $page - 1 ) * $per_page )
			)
		);
		$rank = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, '' => 4 );
		usort(
			$rows,
			static function ( $a, $b ) use ( $rank ) {
				$left  = isset( $rank[ $a->priority ] ) ? $rank[ $a->priority ] : 4;
				$right = isset( $rank[ $b->priority ] ) ? $rank[ $b->priority ] : 4;
				return $left === $right ? (int) $a->inbound_count <=> (int) $b->inbound_count : $left <=> $right;
			}
		);
		return $rows;
	}

	public static function recommendations( $statuses, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$statuses     = array_values( array_intersect( (array) $statuses, array( 'pending', 'approved', 'inserted', 'dismissed' ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		if ( empty( $statuses ) ) {
			return array();
		}
			$nodes = self::table( 'nodes' );
			$recs  = self::table( 'recommendations' );
			$page  = max( 1, absint( $page ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder count comes only from the status allow-list; table identifiers and all status values are bound by prepare().
			return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, sn.object_id AS source_object_id, sn.title AS source_title, sn.url AS source_url, tn.object_id AS target_object_id, tn.title AS target_title, tn.url AS target_url
				FROM %i r
				JOIN %i sn ON sn.node_id = r.source_node_id
				JOIN %i tn ON tn.node_id = r.target_node_id
				WHERE r.status IN ({$placeholders})
				ORDER BY FIELD(r.status,'pending','approved','inserted','dismissed'), r.score DESC, r.id DESC
				LIMIT %d OFFSET %d",
				array_merge( array( $recs, $nodes, $nodes ), $statuses, array( absint( $per_page ), absint( ( $page - 1 ) * $per_page ) ) )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

	public static function recommendation( $id ) {
		global $wpdb;
		$nodes = self::table( 'nodes' );
		$recs  = self::table( 'recommendations' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.*, sn.object_id AS source_object_id, sn.title AS source_title, sn.url AS source_url, tn.object_id AS target_object_id, tn.title AS target_title, tn.url AS target_url
				FROM %i r
				JOIN %i sn ON sn.node_id = r.source_node_id
				JOIN %i tn ON tn.node_id = r.target_node_id
				WHERE r.id = %d",
				$recs,
				$nodes,
				$nodes,
				absint( $id )
			)
		);
		return $row ? $row : null;
	}
	public static function insert_recommendation( $source_node_id, $target_node_id, $anchor, $score, $signals ) {
		global $wpdb;
		if ( self::edge_exists( $source_node_id, $target_node_id ) ) {
			return false;
		}
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE source_node_id = %d AND target_node_id = %d',
				self::table( 'recommendations' ),
				absint( $source_node_id ),
				absint( $target_node_id )
			)
		);
		if ( $existing ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			self::table( 'recommendations' ),
			array(
				'source_node_id' => absint( $source_node_id ),
				'target_node_id' => absint( $target_node_id ),
				'anchor_text'    => mb_substr( (string) $anchor, 0, 255, 'UTF-8' ),
				'score'          => number_format( (float) $score, 2, '.', '' ),
				'signals'        => wp_json_encode( $signals ),
				'status'         => 'pending',
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (bool) $wpdb->insert_id;
	}

	public static function mark_recommendation( $id, $status ) {
		global $wpdb;
		$status = in_array( $status, array( 'pending', 'approved', 'inserted', 'dismissed' ), true ) ? $status : 'pending';
		$wpdb->update(
			self::table( 'recommendations' ),
			array(
				'status'    => $status,
				'updated_at'=> gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		self::invalidate_cache();
	}

	public static function invalidate_cache() {
		delete_transient( self::STATS_TRANSIENT );
	}
}
