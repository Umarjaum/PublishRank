<?php
/**
 * REST routes for the Media Link Manager (publishrank/v1/media-links). Every route needs the
 * "Manage media links" permission plus WordPress' own upload_files; attachment routes also need
 * edit_post on that attachment. The REST nonce (wp_rest) is verified by WordPress for cookie sessions.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\MediaLinks;

use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class RestApi {
	const NS   = 'publishrank/v1';
	const BASE = '/media-links';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function allowed() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'publishrank_forbidden', __( 'Sign in first.', 'publishrank' ), array( 'status' => 401 ) );
		}
		if ( Capabilities::can( 'manage_media_links' ) && current_user_can( 'upload_files' ) ) {
			return true;
		}
		return new \WP_Error( 'publishrank_forbidden', __( 'You do not have permission to manage media links.', 'publishrank' ), array( 'status' => 403 ) );
	}

	public static function allowed_attachment( \WP_REST_Request $req ) {
		$ok = self::allowed();
		if ( true !== $ok ) {
			return $ok;
		}
		$id = (int) $req['id'];
		if ( $id && ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'publishrank_forbidden', __( 'You cannot edit this attachment.', 'publishrank' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function allowed_site( ) {
		$ok = self::allowed();
		if ( true !== $ok ) {
			return $ok;
		}
		return Capabilities::can( 'manage_settings' ) ? true : new \WP_Error( 'publishrank_forbidden', __( 'Site-wide media changes need the "Manage general settings" permission too.', 'publishrank' ), array( 'status' => 403 ) );
	}

	public static function allowed_run( \WP_REST_Request $req ) {
		$ok = self::allowed();
		if ( true !== $ok ) {
			return $ok;
		}
		$run = Store::run( (int) $req['run'] );
		if ( ! $run ) {
			return new \WP_Error( 'publishrank_media_run', __( 'Run not found.', 'publishrank' ), array( 'status' => 404 ) );
		}
		if ( $run['attachment_id'] && ! current_user_can( 'edit_post', (int) $run['attachment_id'] ) ) {
			return new \WP_Error( 'publishrank_forbidden', __( 'You cannot edit this attachment.', 'publishrank' ), array( 'status' => 403 ) );
		}
		if ( ! $run['attachment_id'] && ! Capabilities::can( 'manage_settings' ) ) {
			return new \WP_Error( 'publishrank_forbidden', __( 'Site-wide media changes need the "Manage general settings" permission too.', 'publishrank' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function routes() {
		$b = self::BASE;
		register_rest_route( self::NS, $b . '/attachments', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_items' ), 'permission_callback' => array( __CLASS__, 'allowed' ) ) );
		register_rest_route( self::NS, $b . '/attachments/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_item' ), 'permission_callback' => array( __CLASS__, 'allowed_attachment' ) ) );
		register_rest_route( self::NS, $b . '/attachments/(?P<id>\d+)/references', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'references' ), 'permission_callback' => array( __CLASS__, 'allowed_attachment' ) ) );
		register_rest_route( self::NS, $b . '/attachments/(?P<id>\d+)/plan', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'plan' ), 'permission_callback' => array( __CLASS__, 'allowed_attachment' ) ) );
		// 2.26.2: the Media tab inside both SEO editors.
		register_rest_route( self::NS, $b . '/post/(?P<id>\d+)/images', array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'post_images' ), 'permission_callback' => array( __CLASS__, 'allowed_post_images' ), 'args' => array( 'featured' => array( 'type' => 'integer' ), 'og_image' => array( 'type' => 'string' ), 'content' => array( 'type' => 'string' ) ) ) );
		register_rest_route( self::NS, $b . '/attachments/(?P<id>\d+)/metadata', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'attachment_metadata' ), 'permission_callback' => array( __CLASS__, 'allowed_attachment_metadata' ), 'args' => array( 'alt' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ), 'caption' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'modified' => array( 'type' => 'string' ) ) ) );
		register_rest_route( self::NS, $b . '/check-url', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_url' ), 'permission_callback' => array( __CLASS__, 'allowed_check_url' ), 'args' => array( 'url' => array( 'type' => 'string', 'required' => true ), 'verify' => array( 'type' => 'boolean' ) ) ) );
		register_rest_route( self::NS, $b . '/rebase/plan', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'plan_rebase' ), 'permission_callback' => array( __CLASS__, 'allowed_site' ) ) );
		register_rest_route( self::NS, $b . '/runs', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'runs' ), 'permission_callback' => array( __CLASS__, 'allowed' ) ) );
		register_rest_route( self::NS, $b . '/runs/(?P<run>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'run' ), 'permission_callback' => array( __CLASS__, 'allowed_run' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'discard' ), 'permission_callback' => array( __CLASS__, 'allowed_run' ) ),
		) );
		foreach ( array( 'apply', 'verify', 'rollback', 'finalize', 'retry' ) as $action ) {
			register_rest_route( self::NS, $b . '/runs/(?P<run>\d+)/' . $action, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'run_' . $action ), 'permission_callback' => array( __CLASS__, 'allowed_run' ) ) );
		}
		register_rest_route( self::NS, $b . '/runs/(?P<run>\d+)/export', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'export' ), 'permission_callback' => array( __CLASS__, 'allowed_run' ) ) );
		register_rest_route( self::NS, $b . '/health', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'health_get' ), 'permission_callback' => array( __CLASS__, 'allowed' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'health_step' ), 'permission_callback' => array( __CLASS__, 'allowed' ) ),
		) );
		register_rest_route( self::NS, $b . '/host', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'host_get' ), 'permission_callback' => array( __CLASS__, 'allowed' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'host_save' ), 'permission_callback' => array( __CLASS__, 'allowed_site' ) ),
		) );
		register_rest_route( self::NS, $b . '/host/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'host_test' ), 'permission_callback' => array( __CLASS__, 'allowed' ) ) );
	}

	/** Post Media tab: edit the post and hold edit_seo. Media Library permissions are checked per action. */
	public static function allowed_post_images( \WP_REST_Request $req ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'publishrank_forbidden', __( 'Sign in first.', 'publishrank' ), array( 'status' => 401 ) );
		}
		$id = (int) $req['id'];
		if ( ! get_post( $id ) || ! Capabilities::can_edit_seo( $id ) ) {
			return new \WP_Error( 'publishrank_forbidden', __( 'You cannot edit the SEO fields of this item.', 'publishrank' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** Attachment metadata: edit the attachment and hold edit_seo. manage_media_links is NOT required. */
	public static function allowed_attachment_metadata( \WP_REST_Request $req ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'publishrank_forbidden', __( 'Sign in first.', 'publishrank' ), array( 'status' => 401 ) );
		}
		$id = (int) $req['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'publishrank_media_missing', __( 'Attachment not found.', 'publishrank' ), array( 'status' => 404 ) );
		}
		if ( ! Capabilities::can_edit_seo( $id ) ) {
			return new \WP_Error( 'publishrank_forbidden', __( 'You cannot edit this image.', 'publishrank' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function allowed_check_url() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'publishrank_forbidden', __( 'Sign in first.', 'publishrank' ), array( 'status' => 401 ) );
		}
		return Capabilities::can( 'edit_seo' ) ? true : new \WP_Error( 'publishrank_forbidden', __( 'You cannot edit SEO fields.', 'publishrank' ), array( 'status' => 403 ) );
	}

	public static function post_images( \WP_REST_Request $r ) {
		$state = array();
		if ( null !== $r->get_param( 'featured' ) ) {
			$state['featured'] = absint( $r->get_param( 'featured' ) );
		}
		if ( null !== $r->get_param( 'og_image' ) ) {
			$state['og_image'] = (string) $r->get_param( 'og_image' );
		}
		if ( null !== $r->get_param( 'content' ) ) {
			$state['content'] = (string) $r->get_param( 'content' );
		}
		return rest_ensure_response( PostImages::collect( (int) $r['id'], $state ) );
	}

	public static function attachment_metadata( \WP_REST_Request $r ) {
		$fields = array();
		foreach ( array( 'alt', 'title', 'caption', 'description', 'modified', 'credit', 'creator', 'copyright', 'license', 'license_page' ) as $k ) {
			if ( null !== $r->get_param( $k ) ) {
				$fields[ $k ] = (string) $r->get_param( $k );
			}
		}
		$res = PostImages::update_metadata( (int) $r['id'], $fields );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function check_url( \WP_REST_Request $r ) {
		return rest_ensure_response( PostImages::check_url( (string) $r->get_param( 'url' ), (bool) $r->get_param( 'verify' ) ) );
	}

	private static function out( $data ) {
		if ( is_wp_error( $data ) ) {
			$d = $data->get_error_data();
			if ( ! is_array( $d ) || empty( $d['status'] ) ) {
				$data->add_data( array( 'status' => 'publishrank_media_state' === $data->get_error_code() ? 409 : 400 ) );
			}
			return $data;
		}
		return rest_ensure_response( $data );
	}

	public static function list_items( \WP_REST_Request $r ) {
		return self::out( Library::query( $r->get_params() ) );
	}

	public static function get_item( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'publishrank_media_missing', __( 'Attachment not found.', 'publishrank' ), array( 'status' => 404 ) );
		}
		$item          = Library::item( $id );
		$item['files'] = Scanner::files( $id );
		foreach ( $item['files'] as $i => $f ) {
			$item['files'][ $i ]['exists'] = file_exists( $f['path'] );
			$item['files'][ $i ]['bytes']  = $item['files'][ $i ]['exists'] ? (int) filesize( $f['path'] ) : 0;
			unset( $item['files'][ $i ]['path'] );
		}
		$item['runs'] = Store::ready() ? Store::runs( 10, $id ) : array();
		return rest_ensure_response( $item );
	}

	public static function references( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'publishrank_media_missing', __( 'Attachment not found.', 'publishrank' ), array( 'status' => 404 ) );
		}
		$rep = Scanner::report( $id );
		foreach ( $rep['files'] as $i => $f ) {
			unset( $rep['files'][ $i ]['path'] );
		}
		return rest_ensure_response( $rep );
	}

	public static function plan( \WP_REST_Request $r ) {
		$op = sanitize_key( (string) $r->get_param( 'operation' ) );
		if ( ! in_array( $op, array( 'relocate', 'replace_url', 'slug' ), true ) ) {
			return new \WP_Error( 'publishrank_media_op', __( 'Unknown operation.', 'publishrank' ), array( 'status' => 400 ) );
		}
		$params = array(
			'destination' => (string) $r->get_param( 'destination' ),
			'new_url'     => (string) $r->get_param( 'new_url' ),
			'slug'        => (string) $r->get_param( 'slug' ),
			'redirect'    => (bool) $r->get_param( 'redirect' ),
			'force'       => (bool) $r->get_param( 'force' ),
		);
		return self::out( Migrator::plan( $op, (int) $r['id'], $params ) );
	}

	public static function plan_rebase( \WP_REST_Request $r ) {
		return self::out( Migrator::plan( 'rebase', 0, array( 'old_base' => (string) $r->get_param( 'old_base' ), 'new_base' => (string) $r->get_param( 'new_base' ) ) ) );
	}

	public static function runs( \WP_REST_Request $r ) {
		if ( ! Store::ready() ) {
			return rest_ensure_response( array() );
		}
		$rows = Store::runs( 100, (int) $r->get_param( 'attachment' ) );
		foreach ( $rows as $i => $row ) {
			$u                    = get_userdata( (int) $row['user_id'] );
			$rows[ $i ]['user']   = $u ? $u->display_name : '';
			$rows[ $i ]['title']  = $row['attachment_id'] ? get_the_title( (int) $row['attachment_id'] ) : __( 'Site-wide', 'publishrank' );
			if ( $row['attachment_id'] && ! current_user_can( 'edit_post', (int) $row['attachment_id'] ) ) {
				unset( $rows[ $i ] );
			}
		}
		return rest_ensure_response( array_values( $rows ) );
	}

	public static function run( \WP_REST_Request $r ) {
		return self::out( Migrator::summary( (int) $r['run'] ) );
	}

	public static function discard( \WP_REST_Request $r ) {
		$run = Store::run( (int) $r['run'] );
		if ( 'planned' !== $run['status'] ) {
			return new \WP_Error( 'publishrank_media_state', __( 'Only a plan that was never applied can be discarded. Applied runs are kept for rollback.', 'publishrank' ), array( 'status' => 409 ) );
		}
		Store::delete_run( (int) $r['run'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public static function run_apply( \WP_REST_Request $r ) {
		if ( ! $r->get_param( 'confirm' ) ) {
			return new \WP_Error( 'publishrank_media_confirm', __( 'Confirm the preview before applying.', 'publishrank' ), array( 'status' => 400 ) );
		}
		return self::out( Migrator::apply_batch( (int) $r['run'], (int) ( $r->get_param( 'batch' ) ?: Migrator::BATCH ) ) );
	}

	public static function run_verify( \WP_REST_Request $r ) {
		return self::out( Migrator::verify( (int) $r['run'] ) );
	}

	public static function run_rollback( \WP_REST_Request $r ) {
		return self::out( Migrator::rollback( (int) $r['run'], (bool) $r->get_param( 'force' ) ) );
	}

	public static function run_finalize( \WP_REST_Request $r ) {
		if ( ! $r->get_param( 'confirm' ) ) {
			return new \WP_Error( 'publishrank_media_confirm', __( 'Confirm before moving the original files to the backup folder.', 'publishrank' ), array( 'status' => 400 ) );
		}
		return self::out( Migrator::finalize( (int) $r['run'], (bool) $r->get_param( 'redirect' ) ) );
	}

	public static function run_retry( \WP_REST_Request $r ) {
		return self::out( Migrator::retry( (int) $r['run'] ) );
	}

	public static function export( \WP_REST_Request $r ) {
		$s = Migrator::summary( (int) $r['run'] );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$lines = array( array( 'change', 'type', 'object', 'field', 'where', 'matches', 'status', 'message' ) );
		foreach ( $s['changes'] as $c ) {
			$lines[] = array( $c['id'], $c['type'], $c['object'], $c['field'], $c['label'], $c['matches'], $c['status'], $c['message'] );
		}
		foreach ( isset( $s['report']['verify']['checks'] ) ? $s['report']['verify']['checks'] : array() as $c ) {
			$lines[] = array( 'check', '', '', '', $c['label'], '', $c['status'], $c['detail'] );
		}
		$csv = '';
		foreach ( $lines as $l ) {
			$csv .= implode(
				',',
				array_map(
					static function ( $v ) {
						$v = (string) $v;
						if ( preg_match( '/^[=+\-@\t\r]/', $v ) ) {
							$v = "'" . $v; // CSV formula injection guard.
						}
						return '"' . str_replace( '"', '""', $v ) . '"';
					},
					$l
				)
			) . "\n";
		}
		return rest_ensure_response( array( 'filename' => 'publishrank-media-run-' . (int) $r['run'] . '.csv', 'csv' => $csv ) );
	}

	public static function health_get() {
		return rest_ensure_response( Library::health_summary() );
	}

	public static function health_step( \WP_REST_Request $r ) {
		$phase = 'content' === $r->get_param( 'phase' ) ? 'content' : 'attachments';
		return rest_ensure_response( Library::health_step( $phase, max( 0, (int) $r->get_param( 'offset' ) ), (bool) $r->get_param( 'reset' ) ) );
	}

	public static function host_get() {
		$up = Scanner::uploads();
		return rest_ensure_response(
			array(
				'uploads'     => $up['baseurl'],
				'media_host'  => (string) get_option( 'publishrank_media_host', '' ),
				'cdn_host'    => (string) get_option( 'publishrank_cdn_host', '' ),
				'cdn_enabled' => (bool) get_option( 'publishrank_cdn_enabled', false ),
				'content'     => (bool) get_option( HostRewrite::OPTION, false ),
				'can_save'    => Capabilities::can( 'manage_settings' ),
			)
		);
	}

	public static function host_save( \WP_REST_Request $r ) {
		foreach ( array( 'media_host' => 'publishrank_media_host', 'cdn_host' => 'publishrank_cdn_host' ) as $k => $opt ) {
			if ( null === $r->get_param( $k ) ) {
				continue;
			}
			$v = trim( (string) $r->get_param( $k ) );
			if ( '' !== $v ) {
				$v = Migrator::clean_url( $v );
				if ( is_wp_error( $v ) ) {
					return $v;
				}
				$v = untrailingslashit( $v );
			}
			update_option( $opt, $v );
		}
		if ( null !== $r->get_param( 'cdn_enabled' ) ) {
			update_option( 'publishrank_cdn_enabled', (bool) $r->get_param( 'cdn_enabled' ) );
		}
		if ( null !== $r->get_param( 'content' ) ) {
			update_option( HostRewrite::OPTION, (bool) $r->get_param( 'content' ), true );
		}
		return self::host_get();
	}

	public static function host_test() {
		return rest_ensure_response( HostRewrite::test() );
	}
}
