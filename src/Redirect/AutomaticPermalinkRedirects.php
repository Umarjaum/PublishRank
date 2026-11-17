<?php
/**
 * Automatic redirects when published addresses change: posts, pages, custom post types,
 * hierarchical descendants (in batches), taxonomy terms (and their child terms) and author archives.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Redirect;

defined( 'ABSPATH' ) || exit;

final class AutomaticPermalinkRedirects {

	const HISTORY     = 'publishrank_redirect_auto_history';
	const CHILD_QUEUE = 'publishrank_redirect_child_queue';
	const CRON        = 'publishrank_redirect_children_batch';
	const BATCH       = 200;

	/** @var array<int,string> */
	private $before = array();
	/** @var array<int,array<int,string>> Descendant URLs before a parent changed. */
	private $children = array();
	/** @var array<string,array<int,string>> Term URLs before an edit, keyed "taxonomy:id". */
	private $terms = array();
	/** @var array<int,string> */
	private $authors = array();

	public function register() {
		add_action( 'pre_post_update', array( $this, 'capture' ), 5, 2 );
		add_action( 'wp_after_insert_post', array( $this, 'complete' ), 100, 4 );
		add_action( 'edit_terms', array( $this, 'capture_term' ), 5, 2 );
		add_action( 'edited_term', array( $this, 'complete_term' ), 100, 3 );
		add_filter( 'wp_pre_insert_user_data', array( $this, 'capture_author' ), 5, 3 );
		add_action( 'profile_update', array( $this, 'complete_author' ), 100, 2 );
		add_action( self::CRON, array( __CLASS__, 'process_child_queue' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	private static function on() {
		return get_option( 'publishrank_redirect_enabled', true ) && get_option( 'publishrank_redirect_auto_enabled', true );
	}

	/* ------------------------------------------------------------ Posts */

	public function capture( $post_id, $data ) {
		if ( ! self::on() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( is_string( $url ) && '' !== $url ) {
			$this->before[ (int) $post_id ] = $url;
		}
		if ( is_post_type_hierarchical( $post->post_type ) && ( ( isset( $data['post_name'] ) && $data['post_name'] !== $post->post_name ) || ( isset( $data['post_parent'] ) && (int) $data['post_parent'] !== (int) $post->post_parent ) ) ) {
			$this->children[ (int) $post_id ] = self::descendant_urls( $post, self::BATCH );
		}
	}

	/** @return array<int,string> First $limit descendant URLs (the rest are queued). */
	private static function descendant_urls( \WP_Post $post, $limit ) {
		$ids = get_posts(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => 'publish',
				'post_parent'    => $post->ID,
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		// Breadth-first walk of descendants, bounded.
		$queue = $ids;
		while ( $queue && count( $out ) < $limit ) {
			$id         = (int) array_shift( $queue );
			$out[ $id ] = (string) get_permalink( $id );
			foreach ( get_posts( array( 'post_type' => $post->post_type, 'post_status' => 'publish', 'post_parent' => $id, 'fields' => 'ids', 'posts_per_page' => $limit, 'no_found_rows' => true ) ) as $child ) {
				$queue[] = (int) $child;
			}
		}
		return $out;
	}

	public function complete( $post_id, $post, $update, $post_before ) {
		$id = (int) $post_id;
		if ( ! isset( $this->before[ $id ] ) ) {
			return;
		}
		$old = $this->before[ $id ];
		unset( $this->before[ $id ] );
		if ( ! self::on() || 'publish' !== $post->post_status ) {
			return;
		}
		$new = get_permalink( $id );
		$this->create( 'post', $id, $old, (string) $new );
		if ( isset( $this->children[ $id ] ) ) {
			$kids = $this->children[ $id ];
			unset( $this->children[ $id ] );
			clean_post_cache( $id );
			$done = 0;
			foreach ( $kids as $child_id => $child_old ) {
				clean_post_cache( $child_id );
				$this->create( 'post', $child_id, $child_old, (string) get_permalink( $child_id ), false );
				++$done;
			}
			if ( count( $kids ) >= self::BATCH ) {
				// Very large trees: the rest is handled in the background, oldest first.
				$queue                     = get_option( self::CHILD_QUEUE, array() );
				$queue                     = is_array( $queue ) ? $queue : array();
				$queue[]                   = array( 'parent' => $id, 'old_base' => $old, 'new_base' => (string) $new, 'done' => array_keys( $kids ) );
				update_option( self::CHILD_QUEUE, $queue, false );
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_single_event( time() + 30, self::CRON );
				}
			}
			if ( $done ) {
				$this->record( 'post', $id, $old, (string) $new, 'children', sprintf( '%d descendant redirects created.', $done ) );
			}
		}
	}

	/**
	 * Background batches for very large page trees. Walks the tree breadth-first from the parent,
	 * BATCH children per run, and rebuilds each old URL by swapping the parent's old base path in.
	 */
	public static function process_child_queue() {
		$queue = get_option( self::CHILD_QUEUE, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}
		$job      = array_shift( $queue );
		$frontier = isset( $job['frontier'] ) ? array_map( 'intval', (array) $job['frontier'] ) : array( (int) $job['parent'] );
		$done     = isset( $job['done'] ) ? array_flip( array_map( 'intval', (array) $job['done'] ) ) : array();
		$type     = get_post_type( (int) $job['parent'] );
		$self     = new self();
		$budget   = self::BATCH;
		while ( $frontier && $budget > 0 && $type ) {
			$parent = (int) $frontier[0];
			$offset = isset( $job['offset'][ $parent ] ) ? (int) $job['offset'][ $parent ] : 0;
			$want   = $budget;
			$kids   = get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'post_parent' => $parent, 'fields' => 'ids', 'posts_per_page' => $want, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ) );
			foreach ( $kids as $cid ) {
				$cid        = (int) $cid;
				$frontier[] = $cid;
				if ( isset( $done[ $cid ] ) ) {
					continue;
				}
				$new = (string) get_permalink( $cid );
				$old = str_replace( untrailingslashit( (string) $job['new_base'] ), untrailingslashit( (string) $job['old_base'] ), $new );
				$self->create( 'post', $cid, $old, $new, false );
				$done[ $cid ] = 1;
				--$budget;
			}
			if ( count( $kids ) < $want ) {
				array_shift( $frontier );
				unset( $job['offset'][ $parent ] );
			} else {
				$job['offset'][ $parent ] = $offset + count( $kids );
			}
		}
		if ( $frontier && $type ) {
			$job['frontier'] = array_values( array_unique( $frontier ) );
			$job['done']     = array_keys( $done );
			array_unshift( $queue, $job );
		}
		update_option( self::CHILD_QUEUE, $queue, false );
		if ( $queue ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
	}

	/* ------------------------------------------------------------ Terms */

	public function capture_term( $term_id, $taxonomy ) {
		if ( ! self::on() || ! is_taxonomy_viewable( $taxonomy ) ) {
			return;
		}
		$urls = array();
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( is_string( $link ) ) {
			$urls[ (int) $term_id ] = $link;
		}
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			foreach ( array_slice( (array) get_term_children( (int) $term_id, $taxonomy ), 0, self::BATCH ) as $child ) {
				$l = get_term_link( (int) $child, $taxonomy );
				if ( is_string( $l ) ) {
					$urls[ (int) $child ] = $l;
				}
			}
		}
		$this->terms[ $taxonomy . ':' . (int) $term_id ] = $urls;
	}

	public function complete_term( $term_id, $tt_id, $taxonomy ) {
		$k = $taxonomy . ':' . (int) $term_id;
		if ( ! isset( $this->terms[ $k ] ) ) {
			return;
		}
		$urls = $this->terms[ $k ];
		unset( $this->terms[ $k ] );
		clean_term_cache( (int) $term_id, $taxonomy );
		foreach ( $urls as $id => $old ) {
			clean_term_cache( (int) $id, $taxonomy );
			$new = get_term_link( (int) $id, $taxonomy );
			if ( is_string( $new ) ) {
				$this->create( 'term', (int) $id, $old, $new, (int) $id === (int) $term_id );
			}
		}
	}

	/* ------------------------------------------------------------ Authors */

	public function capture_author( $data, $update, $user_id ) {
		if ( $update && $user_id && self::on() && isset( $data['user_nicename'] ) ) {
			$old = get_userdata( (int) $user_id );
			if ( $old && $old->user_nicename !== $data['user_nicename'] && count_user_posts( (int) $user_id ) > 0 ) {
				$this->authors[ (int) $user_id ] = get_author_posts_url( (int) $user_id, $old->user_nicename );
			}
		}
		return $data;
	}

	public function complete_author( $user_id, $old_user ) {
		if ( ! isset( $this->authors[ (int) $user_id ] ) ) {
			return;
		}
		$old = $this->authors[ (int) $user_id ];
		unset( $this->authors[ (int) $user_id ] );
		clean_user_cache( (int) $user_id );
		$user = get_userdata( (int) $user_id );
		if ( $user ) {
			$this->create( 'author', (int) $user_id, $old, get_author_posts_url( (int) $user_id, $user->user_nicename ) );
		}
	}

	/* ------------------------------------------------------------ Shared */

	/** Create one automatic rule with conflict checks. */
	public function create( $kind, $id, $old, $new, $notify = true ) {
		if ( '' === $new || $old === $new || wp_parse_url( $old, PHP_URL_QUERY ) || wp_parse_url( $new, PHP_URL_QUERY ) ) {
			return; // Path-based rules cannot distinguish ?p=123 style addresses.
		}
		$source = RedirectStore::v2() ? RedirectRepository::key( $old ) : RedirectStore::normalise_path( $old );
		$dest   = RedirectStore::v2() ? RedirectRepository::key( $new ) : RedirectStore::normalise_path( $new );
		if ( $source === $dest ) {
			return;
		}
		if ( 'post' === $kind ) {
			$owner = url_to_postid( $old );
			if ( $owner && $owner !== $id ) {
				$this->record( $kind, $id, $old, $new, 'conflict', 'The old URL belongs to another published item.', $notify );
				return;
			}
		}
		if ( RedirectStore::v2() ) {
			$existing = RedirectRepository::match( $source, array( 'ignore_conditions' => true ) );
			if ( $existing && 'exact' !== $existing['rule']['match_type'] ) {
				$this->record( $kind, $id, $old, $new, 'conflict', 'A pattern rule already matches the old URL; it was not overwritten.', $notify );
				return;
			}
			// Never overwrite a redirect someone set up by hand for the old address.
			if ( $existing && 'auto' !== ( $existing['rule']['origin'] ?? '' ) ) {
				$this->record( $kind, $id, $old, $new, 'conflict', 'A manual redirect already exists for the old URL; it was kept.', $notify );
				return;
			}
			// A rule that already sends the new address elsewhere would hide the new canonical page: remove it.
			$back = RedirectRepository::match( $dest, array( 'ignore_conditions' => true ) );
			if ( $back && 'exact' === $back['rule']['match_type'] && 'auto' === $back['rule']['origin'] ) {
				RedirectRepository::delete( (int) $back['rule']['id'] );
			} elseif ( $back ) {
				$this->record( $kind, $id, $old, $new, 'conflict', 'The new address already has a manual redirect; resolve it before an automatic rule is created.', $notify );
				return;
			}
			$res = RedirectRepository::save(
				array(
					'source'     => $source,
					'target'     => $new,
					'status'     => 301,
					'match_type' => 'exact',
					'origin'     => 'auto',
					'note'       => sprintf( 'Automatic (%s #%d).', $kind, $id ),
					'allow_live' => 1,
				)
			);
		} else {
			$res = RedirectStore::add( $old, $new, 301, false );
		}
		$this->record( $kind, $id, $old, $new, is_wp_error( $res ) ? 'failed' : 'created', is_wp_error( $res ) ? $res->get_error_message() : '', $notify );
	}

	private function record( $kind, $id, $old, $new, $status, $reason, $notify = true ) {
		$history   = get_option( self::HISTORY, array() );
		$history   = is_array( $history ) ? $history : array();
		$event     = array( 'kind' => $kind, 'post_id' => $id, 'old' => $old, 'new' => $new, 'status' => $status, 'reason' => $reason, 'at' => gmdate( 'c' ), 'user_id' => get_current_user_id() );
		$history[] = $event;
		update_option( self::HISTORY, array_slice( $history, -300 ), false );
		if ( $notify && get_current_user_id() ) {
			set_transient( 'publishrank_redirect_notice_' . get_current_user_id(), $event, 300 );
		}
	}

	public function notice() {
		if ( ! \PublishRankSEO\Core\Capabilities::can( 'manage_redirects' ) ) {
			return;
		}
		$key   = 'publishrank_redirect_notice_' . get_current_user_id();
		$event = get_transient( $key );
		if ( ! is_array( $event ) ) {
			return;
		}
		delete_transient( $key );
		$created = in_array( $event['status'], array( 'created', 'children' ), true );
		echo '<div class="notice is-dismissible ' . ( $created ? 'notice-success' : 'notice-warning' ) . '"><p>';
		echo esc_html( $created ? __( 'PublishRank created a permanent redirect for the changed address.', 'publishrank' ) : __( 'PublishRank could not safely create the redirect for the changed address.', 'publishrank' ) );
		echo ' <code>' . esc_html( $event['old'] ) . '</code> → <code>' . esc_html( $event['new'] ) . '</code> ' . esc_html( $event['reason'] );
		echo '</p></div>';
	}
}
