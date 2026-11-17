<?php
/**
 * REST API for the Redirect Engine and the 404 Monitor (namespace publishrank/v1).
 *
 *   GET    /redirects                 list (search, match_type, status, group_id, origin, enabled, unused, per_page, page, orderby, order)
 *   POST   /redirects                 create
 *   GET    /redirects/{id}            read (with history)
 *   PUT    /redirects/{id}            update (PATCH accepted)
 *   DELETE /redirects/{id}            delete
 *   POST   /redirects/bulk            {ids[], action, value}
 *   POST   /redirects/import          {rows[] | htaccess, dry_run, group_id}
 *   GET    /redirects/test?url=       trace hops
 *   GET    /redirects/history         change batches
 *   POST   /redirects/history/{batch}/undo
 *   GET    /redirects/groups          POST /redirects/groups {name}
 *   GET    /404                       list (search, class, status, noise, min_hits, from, to, per_page, page, orderby, order)
 *   GET    /404/stats                 summary + daily series + spikes
 *   GET    /404/{id}/suggestions
 *   POST   /404/{id}/redirect         {target, status}
 *   POST   /404/bulk                  {ids[], action: ignore|reopen|delete}
 *   DELETE /404                       clear (requires confirm=DELETE)
 *
 * Every route checks a PublishRank capability; list routes send X-WP-Total / X-WP-TotalPages.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Redirect;

use PublishRankSEO\Admin\RedirectsPage;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Monitor\NotFoundStore;

defined( 'ABSPATH' ) || exit;

final class RestApi {
	const NS = 'publishrank/v1';

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	private static function paged( array $res, $per ) {
		$r = rest_ensure_response( $res['rows'] );
		$r->header( 'X-WP-Total', (string) (int) $res['total'] );
		$r->header( 'X-WP-TotalPages', (string) (int) max( 1, ceil( $res['total'] / max( 1, $per ) ) ) );
		return $r;
	}

	private static function rule_args() {
		return array(
			'source'     => array( 'type' => 'string', 'maxLength' => 2000 ),
			'target'     => array( 'type' => 'string', 'maxLength' => 2000 ),
			'status'     => array( 'type' => 'integer', 'enum' => RedirectRepository::statuses() ),
			'match_type' => array( 'type' => 'string', 'enum' => RedirectRepository::MATCH_TYPES ),
			'group_id'   => array( 'type' => 'integer', 'minimum' => 0 ),
			'enabled'    => array( 'type' => 'boolean' ),
			'note'       => array( 'type' => 'string', 'maxLength' => 255 ),
			'start_at'   => array( 'type' => 'integer', 'minimum' => 0 ),
			'end_at'     => array( 'type' => 'integer', 'minimum' => 0 ),
			'query'      => array( 'type' => 'string', 'enum' => RedirectRepository::QUERY_MODES ),
			'query_map'  => array( 'type' => array( 'object', 'string' ) ),
			'external'   => array( 'type' => 'boolean' ),
			'force'      => array( 'type' => 'boolean' ),
			'allow_live' => array( 'type' => 'boolean' ),
			'conditions' => array( 'type' => 'object' ),
		);
	}

	private static function rule_input( \WP_REST_Request $req, array $base = array() ) {
		$in = $base;
		foreach ( array_keys( self::rule_args() ) as $k ) {
			if ( null !== $req->get_param( $k ) ) {
				$v        = $req->get_param( $k );
				$in[ $k ] = is_bool( $v ) ? (int) $v : $v;
			}
		}
		if ( $base && isset( $base['flags'] ) ) {
			foreach ( array( 'query', 'query_map', 'external', 'force', 'conditions' ) as $k ) {
				if ( ! isset( $in[ $k ] ) ) {
					$in[ $k ] = $base['flags'][ $k ];
				}
			}
			unset( $in['flags'] );
		}
		$in['origin'] = isset( $base['origin'] ) ? $base['origin'] : 'api';
		return $in;
	}

	public function routes() {
		$m = Capabilities::rest( 'manage_redirects' );
		$v = Capabilities::rest( 'view_404' );
		$w = Capabilities::rest( 'manage_404' );

		register_rest_route(
			self::NS,
			'/redirects',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $m,
					'args'                => array(
						'search'     => array( 'type' => 'string' ),
						'match_type' => array( 'type' => 'string', 'enum' => array_merge( array( '' ), RedirectRepository::MATCH_TYPES ) ),
						'status'     => array( 'type' => 'integer' ),
						'group_id'   => array( 'type' => 'integer' ),
						'origin'     => array( 'type' => 'string' ),
						'enabled'    => array( 'type' => 'integer', 'enum' => array( 0, 1 ) ),
						'unused'     => array( 'type' => 'boolean' ),
						'per_page'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50 ),
						'page'       => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'orderby'    => array( 'type' => 'string', 'enum' => array( 'id', 'source', 'hits', 'last_hit', 'status', 'created_at', 'updated_at' ), 'default' => 'id' ),
						'order'      => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
					),
					'callback'            => static function ( \WP_REST_Request $r ) {
						$f = array_filter( $r->get_params(), static function ( $x ) { return null !== $x && '' !== $x; } );
						$f['paged'] = (int) $r['page'];
						return self::paged( RedirectRepository::query( $f ), (int) $r['per_page'] );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $m,
					'args'                => array_merge( self::rule_args(), array( 'source' => array( 'type' => 'string', 'required' => true, 'maxLength' => 2000 ) ) ),
					'callback'            => static function ( \WP_REST_Request $r ) {
						$id = RedirectRepository::save( self::rule_input( $r ) );
						if ( is_wp_error( $id ) ) {
							$id->add_data( array( 'status' => 400 ) );
							return $id;
						}
						return new \WP_REST_Response( RedirectRepository::get( $id ), 201 );
					},
				),
			)
		);
		register_rest_route(
			self::NS,
			'/redirects/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $m,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$row = RedirectRepository::get( (int) $r['id'] );
						if ( ! $row ) {
							return new \WP_Error( 'not_found', __( 'Redirect not found.', 'publishrank' ), array( 'status' => 404 ) );
						}
						$row['history'] = RedirectRepository::history_for( (int) $r['id'], 20 );
						return $row;
					},
				),
				array(
					'methods'             => 'PUT, PATCH',
					'permission_callback' => $m,
					'args'                => self::rule_args(),
					'callback'            => static function ( \WP_REST_Request $r ) {
						$row = RedirectRepository::get( (int) $r['id'] );
						if ( ! $row ) {
							return new \WP_Error( 'not_found', __( 'Redirect not found.', 'publishrank' ), array( 'status' => 404 ) );
						}
						$res = RedirectRepository::save( self::rule_input( $r, $row ), (int) $r['id'] );
						if ( is_wp_error( $res ) ) {
							$res->add_data( array( 'status' => 400 ) );
							return $res;
						}
						return RedirectRepository::get( (int) $r['id'] );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $m,
					'callback'            => static function ( \WP_REST_Request $r ) {
						if ( ! RedirectRepository::delete( (int) $r['id'] ) ) {
							return new \WP_Error( 'not_found', __( 'Redirect not found.', 'publishrank' ), array( 'status' => 404 ) );
						}
						return array( 'deleted' => true, 'id' => (int) $r['id'] );
					},
				),
			)
		);
		register_rest_route(
			self::NS,
			'/redirects/bulk',
			array(
				'methods'             => 'POST',
				'permission_callback' => $m,
				'args'                => array(
					'ids'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'required' => true, 'maxItems' => 5000 ),
					'action' => array( 'type' => 'string', 'enum' => array( 'delete', 'enable', 'disable', 'group', 'status', 'reset_hits' ), 'required' => true ),
					'value'  => array( 'type' => array( 'string', 'integer' ) ),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$n = RedirectRepository::bulk( (array) $r['ids'], (string) $r['action'], $r['value'] );
					return array( 'changed' => $n, 'batch' => RedirectRepository::last_batch() );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/redirects/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => $m,
				'args'                => array(
					'rows'     => array( 'type' => 'array', 'maxItems' => 20000, 'items' => array( 'type' => 'object' ) ),
					'htaccess' => array( 'type' => 'string', 'maxLength' => 5 * 1024 * 1024 ),
					'csv'      => array( 'type' => 'string', 'maxLength' => 5 * 1024 * 1024 ),
					'dry_run'  => array( 'type' => 'boolean', 'default' => true ),
					'group_id' => array( 'type' => 'integer', 'default' => 0 ),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$rows = (array) $r['rows'];
					if ( $r['htaccess'] ) {
						$rows = RedirectRepository::parse_htaccess( (string) $r['htaccess'] );
					} elseif ( $r['csv'] ) {
						$rows = RedirectStore::parse_csv( (string) $r['csv'] );
					}
					return RedirectRepository::import( array_slice( $rows, 0, 20000 ), (int) $r['group_id'], (bool) $r['dry_run'] );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/redirects/test',
			array(
				'methods'             => 'GET',
				'permission_callback' => $m,
				'args'                => array( 'url' => array( 'type' => 'string', 'required' => true, 'maxLength' => 2000 ) ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$hops = RedirectsPage::trace( (string) $r['url'] );
					return array( 'url' => (string) $r['url'], 'hops' => $hops, 'final' => $hops ? end( $hops )['to'] : (string) $r['url'], 'chain' => count( $hops ) > 1, 'loop' => (bool) array_filter( wp_list_pluck( $hops, 'loop' ) ) );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/redirects/history',
			array(
				'methods'             => 'GET',
				'permission_callback' => $m,
				'args'                => array( 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ) ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					return RedirectRepository::batches( (int) $r['limit'] );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/redirects/history/(?P<batch>[a-f0-9\-]{36})/undo',
			array(
				'methods'             => 'POST',
				'permission_callback' => $m,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = RedirectRepository::rollback( (string) $r['batch'] );
					if ( is_wp_error( $res ) ) {
						$res->add_data( array( 'status' => 404 ) );
						return $res;
					}
					return array( 'reverted' => (int) $res );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/redirects/groups',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $m,
					'callback'            => static function () {
						$out = array();
						foreach ( RedirectRepository::groups() as $id => $name ) {
							$out[] = array( 'id' => $id, 'name' => $name, 'count' => RedirectRepository::count( array( 'group_id' => $id ) ) );
						}
						return $out;
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $m,
					'args'                => array( 'name' => array( 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 120 ) ),
					'callback'            => static function ( \WP_REST_Request $r ) {
						return new \WP_REST_Response( array( 'id' => RedirectRepository::add_group( (string) $r['name'] ) ), 201 );
					},
				),
			)
		);

		/* ---------------- 404 ---------------- */
		register_rest_route(
			self::NS,
			'/404',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $v,
					'args'                => array(
						'search'   => array( 'type' => 'string' ),
						'class'    => array( 'type' => 'string', 'enum' => array( '', 'human', 'mobile', 'crawler', 'search', 'ai', 'scanner' ) ),
						'status'   => array( 'type' => 'string', 'enum' => array_merge( array( '' ), NotFoundStore::STATUSES ), 'default' => '' ),
						'noise'    => array( 'type' => 'string', 'enum' => array( 'show', 'hide' ), 'default' => 'hide' ),
						'min_hits' => array( 'type' => 'integer', 'minimum' => 0 ),
						'from'     => array( 'type' => 'string', 'format' => 'date' ),
						'to'       => array( 'type' => 'string', 'format' => 'date' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'orderby'  => array( 'type' => 'string', 'enum' => array( 'hits', 'last_seen', 'first_seen', 'path', 'hits_search', 'hits_ai' ), 'default' => 'hits' ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
					),
					'callback'            => static function ( \WP_REST_Request $r ) {
						$f          = $r->get_params();
						$f['paged'] = (int) $r['page'];
						return self::paged( NotFoundStore::query( $f ), (int) $r['per_page'] );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $w,
					'args'                => array(
						'confirm'      => array( 'type' => 'string', 'required' => true, 'enum' => array( 'DELETE' ) ),
						'with_history' => array( 'type' => 'boolean', 'default' => false ),
					),
					'callback'            => static function ( \WP_REST_Request $r ) {
						NotFoundStore::clear( (bool) $r['with_history'] );
						return array( 'cleared' => true );
					},
				),
			)
		);
		register_rest_route(
			self::NS,
			'/404/stats',
			array(
				'methods'             => 'GET',
				'permission_callback' => $v,
				'args'                => array( 'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30 ) ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$d = (int) $r['days'];
					return array( 'summary' => NotFoundStore::summary( $d ), 'series' => NotFoundStore::series( $d ), 'spikes' => NotFoundStore::spikes( $d ) );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/404/(?P<id>\d+)/suggestions',
			array(
				'methods'             => 'GET',
				'permission_callback' => $v,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$row = NotFoundStore::get( (int) $r['id'] );
					return $row ? NotFoundStore::suggestions( $row['path'], 5 ) : new \WP_Error( 'not_found', __( '404 entry not found.', 'publishrank' ), array( 'status' => 404 ) );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/404/(?P<id>\d+)/redirect',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () use ( $w, $m ) {
					$a = $w();
					return true === $a ? $m() : $a;
				},
				'args'                => array(
					'target' => array( 'type' => 'string', 'required' => true, 'maxLength' => 2000 ),
					'status' => array( 'type' => 'integer', 'enum' => RedirectRepository::statuses(), 'default' => 301 ),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = NotFoundStore::redirect( (int) $r['id'], (string) $r['target'], (int) $r['status'] );
					if ( is_wp_error( $res ) ) {
						$res->add_data( array( 'status' => 400 ) );
						return $res;
					}
					return new \WP_REST_Response( array( 'redirect_id' => $res ), 201 );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/404/bulk',
			array(
				'methods'             => 'POST',
				'permission_callback' => $w,
				'args'                => array(
					'ids'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'required' => true, 'maxItems' => 5000 ),
					'action' => array( 'type' => 'string', 'enum' => array( 'ignore', 'reopen', 'delete' ), 'required' => true ),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$ids = (array) $r['ids'];
					$n   = 'delete' === $r['action'] ? NotFoundStore::delete( $ids ) : NotFoundStore::set_status( $ids, 'ignore' === $r['action'] ? 'ignored' : 'open' );
					return array( 'changed' => $n );
				},
			)
		);
	}
}
