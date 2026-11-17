<?php
/**
 * Boots AI Visibility: crawler control, verification, Markdown delivery, citation tracker, REST API.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AI;

use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AiVisibility {
	public static function boot() {
		BotVerifier::register();
		CrawlerControl::register();
		MarkdownDelivery::register();
		CitationTracker::register();
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
	}

	public static function routes() {
		$ns = 'publishrank/v1';
		register_rest_route(
			$ns,
			'/ai/bots',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'view_ai_log' ),
				'callback'            => static function () {
					$totals = CrawlerControl::totals( 30 );
					$out    = array();
					foreach ( BotRegistry::all() as $token => $b ) {
						$out[] = array_merge(
							array( 'token' => $token ),
							$b,
							array(
								'rule'    => CrawlerControl::rule( $token ),
								'last_30' => $totals[ $token ] ?? array( 'total' => 0, 'verified' => 0, 'spoofed' => 0, 'pending' => 0, 'unverifiable' => 0 ),
							)
						);
					}
					return rest_ensure_response( $out );
				},
			)
		);
		register_rest_route(
			$ns,
			'/ai/bots/(?P<token>[A-Za-z0-9\-_]+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => Capabilities::rest( 'manage_ai' ),
				'args'                => array(
					'mode'     => array( 'type' => 'string', 'enum' => CrawlerControl::MODES, 'required' => true ),
					'limit'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 60 ),
					'markdown' => array( 'type' => 'boolean', 'default' => true ),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$token = (string) $r['token'];
					if ( ! BotRegistry::get( $token ) ) {
						return new \WP_Error( 'unknown_bot', 'Unknown crawler.', array( 'status' => 404 ) );
					}
					$rules           = CrawlerControl::settings()['rules'];
					$rules[ $token ] = array( 'mode' => $r['mode'], 'limit' => (int) $r['limit'], 'markdown' => $r['markdown'] ? 1 : 0 );
					CrawlerControl::save( array( 'rules' => $rules ) );
					return rest_ensure_response( array( 'token' => $token, 'rule' => CrawlerControl::rule( $token ) ) );
				},
			)
		);
		register_rest_route(
			$ns,
			'/ai/visits',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'view_ai_log' ),
				'args'                => array(
					'bot'      => array( 'type' => 'string' ),
					'purpose'  => array( 'type' => 'string' ),
					'verified' => array( 'type' => 'integer' ),
					'action'   => array( 'type' => 'string' ),
					'search'   => array( 'type' => 'string' ),
					'from'     => array( 'type' => 'string' ),
					'to'       => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$f = array();
					foreach ( array( 'bot', 'purpose', 'verified', 'action', 'search', 'from', 'to' ) as $k ) {
						if ( null !== $r[ $k ] ) {
							$f[ $k ] = $r[ $k ];
						}
					}
					$res  = CrawlerControl::visits( $f + array( 'paged' => $r['page'], 'per_page' => $r['per_page'] ) );
					$resp = rest_ensure_response( $res['rows'] );
					$resp->header( 'X-WP-Total', (string) $res['total'] );
					return $resp;
				},
			)
		);
		register_rest_route(
			$ns,
			'/ai/visits/stats',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'view_ai_log' ),
				'callback'            => static function () {
					return rest_ensure_response( array( 'totals' => CrawlerControl::totals( 30 ), 'daily' => CrawlerControl::series( 30 ), 'top_paths' => CrawlerControl::top_paths( 20 ) ) );
				},
			)
		);
		register_rest_route(
			$ns,
			'/ai/citations',
			array(
				'methods'             => 'GET',
				'permission_callback' => Capabilities::rest( 'view_ai_log' ),
				'callback'            => static function () {
					return rest_ensure_response( array( 'summary' => CitationTracker::summary(), 'latest' => CitationTracker::latest(), 'trend' => CitationTracker::trend( 30 ), 'job' => CitationTracker::job() ) );
				},
			)
		);
		register_rest_route(
			$ns,
			'/ai/citations/run',
			array(
				'methods'             => 'POST',
				'permission_callback' => Capabilities::rest( 'manage_ai' ),
				'callback'            => static function () {
					$run = CitationTracker::start_run();
					return is_wp_error( $run ) ? new \WP_Error( $run->get_error_code(), $run->get_error_message(), array( 'status' => 400 ) ) : rest_ensure_response( array( 'run' => $run, 'job' => CitationTracker::job() ) );
				},
			)
		);
		register_rest_route(
			$ns,
			'/ai/markdown/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $r ) {
					$limited = \PublishRankSEO\Core\RateLimiter::rest( 'ai-markdown', 120 );
					if ( $limited ) {
						return $limited;
					}
					$post = get_post( (int) $r['id'] );
					if ( ! $post || ! MarkdownDelivery::eligible( $post ) ) {
						return new \WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
					}
					return rest_ensure_response( array( 'id' => $post->ID, 'url' => get_permalink( $post ), 'markdown_url' => MarkdownDelivery::url_for( $post ), 'markdown' => MarkdownDelivery::render_post( $post ) ) );
				},
			)
		);
	}
}
