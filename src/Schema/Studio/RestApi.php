<?php
/**
 * REST API for Schema Studio (namespace publishrank/v1, base /schema-studio). Every route requires the
 * "Manage schema" PublishRank capability; the preview endpoint additionally needs edit access to the post.
 *
 * Previews are produced by requesting the real front-end page with a short-lived preview token, so the
 * JSON-LD shown in the editor is exactly what the page prints with the unsaved template applied.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\Schema\Studio;

use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class RestApi {

	const NS          = 'publishrank/v1';
	const PREVIEW_ARG = 'pr_studio_preview';

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		// Front-end side of the preview: swap in the unsaved template for this one response.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_preview_request' ), 0 );
	}

	public function can() {
		return Capabilities::can( 'manage_schema' );
	}

	public function routes() {
		$base = '/schema-studio';
		$can  = array( $this, 'can' );
		register_rest_route( self::NS, $base . '/templates', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_templates' ), 'permission_callback' => $can ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'save_template' ), 'permission_callback' => $can ),
		) );
		register_rest_route( self::NS, $base . '/templates/(?P<id>[a-z0-9_-]+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_template' ), 'permission_callback' => $can ),
			array( 'methods' => 'PUT,POST', 'callback' => array( $this, 'save_template' ), 'permission_callback' => $can ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_template' ), 'permission_callback' => $can ),
		) );
		register_rest_route( self::NS, $base . '/templates/(?P<id>[a-z0-9_-]+)/(?P<action>duplicate|reset|restore|status|default)', array(
			'methods' => 'POST', 'callback' => array( $this, 'action' ), 'permission_callback' => $can,
		) );
		register_rest_route( self::NS, $base . '/templates/(?P<id>[a-z0-9_-]+)/revisions', array(
			'methods' => 'GET', 'callback' => array( $this, 'revisions' ), 'permission_callback' => $can,
		) );
		register_rest_route( self::NS, $base . '/type/(?P<type>[A-Za-z0-9]+)', array(
			'methods' => 'GET', 'callback' => array( $this, 'type' ), 'permission_callback' => $can,
		) );
		register_rest_route( self::NS, $base . '/catalog', array( 'methods' => 'GET', 'callback' => array( $this, 'catalog' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, $base . '/fields', array( 'methods' => 'GET', 'callback' => array( $this, 'fields' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, $base . '/samples', array( 'methods' => 'GET', 'callback' => array( $this, 'samples' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, $base . '/preview', array( 'methods' => 'POST', 'callback' => array( $this, 'preview' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, $base . '/validate', array( 'methods' => 'POST', 'callback' => array( $this, 'validate' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, $base . '/export', array( 'methods' => 'GET', 'callback' => array( $this, 'export' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, $base . '/import', array( 'methods' => 'POST', 'callback' => array( $this, 'import' ), 'permission_callback' => $can ) );
		register_rest_route( self::NS, $base . '/settings', array( 'methods' => 'POST', 'callback' => array( $this, 'settings' ), 'permission_callback' => $can ) );
	}

	/* ------------------------------------------------------------ Templates */

	public function list_templates() {
		return array( 'templates' => array_values( Templates::all() ), 'settings' => Templates::settings() );
	}

	public function get_template( \WP_REST_Request $r ) {
		$tpl = Templates::get( (string) $r['id'] );
		return $tpl ? $tpl : new \WP_Error( 'not_found', __( 'Template not found.', 'publishrank' ), array( 'status' => 404 ) );
	}

	public function save_template( \WP_REST_Request $r ) {
		$body = $r->get_json_params();
		$body = is_array( $body ) ? $body : array();
		if ( isset( $r['id'] ) ) {
			$body['id'] = (string) $r['id'];
		}
		$saved = Templates::save( $body );
		if ( is_wp_error( $saved ) ) {
			$saved->add_data( array( 'status' => 400 ) );
			return $saved;
		}
		return $saved;
	}

	public function delete_template( \WP_REST_Request $r ) {
		$res = Templates::delete( (string) $r['id'] );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $res;
		}
		return array( 'deleted' => true );
	}

	public function action( \WP_REST_Request $r ) {
		$id = (string) $r['id'];
		switch ( (string) $r['action'] ) {
			case 'duplicate':
				$res = Templates::duplicate( $id );
				break;
			case 'reset':
				$res = Templates::reset_builtin( $id );
				break;
			case 'restore':
				$res = Templates::restore( $id, absint( $r->get_param( 'index' ) ) );
				break;
			case 'status':
			case 'default':
				$tpl = Templates::get( $id );
				if ( ! $tpl ) {
					$res = new \WP_Error( 'not_found', __( 'Template not found.', 'publishrank' ) );
					break;
				}
				if ( 'status' === (string) $r['action'] ) {
					$tpl['status'] = 'enabled' === $r->get_param( 'status' ) ? 'enabled' : 'disabled';
				} else {
					$tpl['is_default'] = (bool) $r->get_param( 'value' );
				}
				$res = Templates::save( $tpl );
				break;
			default:
				$res = new \WP_Error( 'bad_action', 'Unknown action.' );
		}
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
		}
		return $res;
	}

	public function revisions( \WP_REST_Request $r ) {
		return array_map(
			static function ( $rev ) {
				return array( 'saved' => $rev['saved'], 'name' => $rev['template']['name'] ?? '', 'properties' => count( $rev['template']['properties'] ?? array() ) );
			},
			Templates::revisions( (string) $r['id'] )
		);
	}

	/* ------------------------------------------------------------ Vocabulary */

	public function catalog() {
		$out = array();
		foreach ( Vocabulary::catalog() as $type => $t ) {
			$out[] = array(
				'type'    => $type,
				'label'   => $t['label'],
				'group'   => $t['group'],
				'parents' => $t['parents'],
				'comment' => $t['comment'],
				'pending' => ! empty( $t['pending'] ),
				'google'  => Vocabulary::google( $type ),
			);
		}
		return array( 'version' => Vocabulary::version(), 'types' => $out, 'excluded' => Vocabulary::data()['excluded'] );
	}

	public function type( \WP_REST_Request $r ) {
		$type = (string) $r['type'];
		if ( ! Vocabulary::exists( $type ) ) {
			return new \WP_Error( 'unknown_type', __( 'Not a Schema.org type.', 'publishrank' ), array( 'status' => 404 ) );
		}
		$groups = array();
		foreach ( Vocabulary::properties_for( $type ) as $class => $props ) {
			$list = array();
			foreach ( $props as $name => $p ) {
				$enum = array();
				foreach ( $p['r'] as $range ) {
					if ( Vocabulary::is_enum( $range ) ) {
						$enum = array_merge( $enum, Vocabulary::enum_members( $range ) );
					}
				}
				$list[] = array( 'name' => $name, 'ranges' => $p['r'], 'comment' => $p['c'], 'pending' => ! empty( $p['pending'] ), 'enum' => array_slice( $enum, 0, 80 ) );
			}
			$groups[] = array( 'class' => $class, 'properties' => $list );
		}
		$t = Vocabulary::in_catalog( $type ) ? Vocabulary::catalog()[ $type ] : array();
		return array(
			'type'    => $type,
			'label'   => isset( $t['label'] ) ? $t['label'] : $type,
			'comment' => isset( $t['comment'] ) ? $t['comment'] : '',
			'parents' => Vocabulary::ancestors( $type ),
			'pending' => ! empty( $t['pending'] ),
			'google'  => Vocabulary::google( $type ),
			'groups'  => $groups,
		);
	}

	public function fields() {
		$out = array();
		foreach ( Fields::all() as $key => $f ) {
			$out[] = array( 'key' => $key, 'label' => $f[0], 'group' => $f[1], 'kind' => $f[2] );
		}
		$tax = array();
		$objs  = get_taxonomies( array( 'public' => true ), 'objects' );
		$count = array_count_values( array_map( static function ( $t ) { return $t->labels->singular_name; }, $objs ) );
		foreach ( $objs as $t ) {
			$label = $t->labels->singular_name;
			// Two "Category" taxonomies (posts and products) must stay distinguishable: name their post type.
			if ( $count[ $label ] > 1 && ! empty( $t->object_type ) ) {
				$pto   = get_post_type_object( $t->object_type[0] );
				$label = sprintf( '%1$s (%2$s)', $label, $pto ? $pto->labels->name : $t->object_type[0] );
			}
			$tax[] = array( 'name' => $t->name, 'label' => $label );
		}
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' !== $pt->name ) {
				$types[] = array( 'name' => $pt->name, 'label' => $pt->labels->singular_name );
			}
		}
		$business = class_exists( '\\PublishRankSEO\\SEO\\LocalSEO' ) ? \PublishRankSEO\SEO\LocalSEO::types() : array();
		return array(
			'fields'          => $out,
			'patterns'        => Fields::patterns(),
			'meta_keys'       => Fields::known_meta_keys(),
			'publishrank'     => Fields::publishrank_keys(),
			'taxonomies'      => $tax,
			'post_types'      => $types,
			'business_types'  => $business,
			'acf'             => function_exists( 'get_field' ),
			'woocommerce'     => function_exists( 'wc_get_product' ),
			'formats'         => Templates::FORMATS,
		);
	}

	/** Sample documents for previews: recent items of each public post type plus terms and the front page. */
	public function samples( \WP_REST_Request $r ) {
		$search = sanitize_text_field( (string) $r->get_param( 'search' ) );
		$out    = array( array( 'kind' => 'front', 'id' => 0, 'label' => __( 'Front page', 'publishrank' ) ) );
		$q      = new \WP_Query(
			array(
				'post_type'      => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ),
				'post_status'    => 'publish',
				's'              => $search,
				'posts_per_page' => 30,
				'no_found_rows'  => true,
			)
		);
		foreach ( $q->posts as $p ) {
			$out[] = array( 'kind' => 'post', 'id' => $p->ID, 'label' => get_the_title( $p ) . ' — ' . $p->post_type, 'post_type' => $p->post_type );
		}
		foreach ( get_terms( array( 'taxonomy' => array_values( get_taxonomies( array( 'public' => true ) ) ), 'number' => 15, 'hide_empty' => true, 'search' => $search ) ) as $t ) {
			if ( $t instanceof \WP_Term ) {
				$out[] = array( 'kind' => 'term', 'id' => $t->term_id, 'label' => $t->name . ' — ' . $t->taxonomy );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ Preview */

	private static function context_from( array $sample ) {
		$kind = isset( $sample['kind'] ) ? (string) $sample['kind'] : 'front';
		$id   = isset( $sample['id'] ) ? absint( $sample['id'] ) : 0;
		if ( 'post' === $kind ) {
			return Context::for_post( $id );
		}
		if ( 'term' === $kind ) {
			return Context::for_term( $id );
		}
		return Context::front();
	}

	public function preview( \WP_REST_Request $r ) {
		$body   = (array) $r->get_json_params();
		$tpl    = Templates::sanitize( isset( $body['template'] ) && is_array( $body['template'] ) ? $body['template'] : array() );
		$sample = isset( $body['sample'] ) && is_array( $body['sample'] ) ? $body['sample'] : array();
		$ctx    = self::context_from( $sample );
		if ( ! $ctx ) {
			return new \WP_Error( 'bad_sample', __( 'Choose a sample page for the preview.', 'publishrank' ), array( 'status' => 400 ) );
		}
		if ( $ctx->post && ! current_user_can( 'edit_post', $ctx->post->ID ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot preview this item.', 'publishrank' ), array( 'status' => 403 ) );
		}
		if ( '' === $tpl['id'] ) {
			$tpl['id'] = 'preview';
		}
		$tpl['status'] = 'enabled';
		$built         = Engine::build( $tpl, $ctx, $ctx->post ? ( Templates::post_assignment( $ctx->post->ID )['overrides'][ $tpl['id'] ] ?? array() ) : array() );
		$matches       = Templates::matches( $tpl, $ctx );
		// Real page output with the unsaved template swapped in.
		$token = wp_generate_password( 32, false, false );
		set_transient( 'publishrank_studio_preview_' . $token, $tpl, 5 * MINUTE_IN_SECONDS );
		$page  = self::fetch( add_query_arg( self::PREVIEW_ARG, $token, $ctx->url ) );
		delete_transient( 'publishrank_studio_preview_' . $token );
		$graph = array();
		$mode  = 'page';
		$note  = '';
		if ( is_wp_error( $page ) ) {
			$mode = 'template-only';
			/* translators: %s: error */
			$note  = sprintf( __( 'The live page could not be loaded (%s), so only this template\'s entity is shown.', 'publishrank' ), $page->get_error_message() );
			$graph = $built['node'] ? array( $built['node'] + array( '@id' => trailingslashit( $ctx->url ) . '#' . $tpl['id'] ) ) : array();
		} else {
			foreach ( Validator::blocks_from_html( $page ) as $b ) {
				if ( 'publishrank' === $b['source'] && is_array( $b['data'] ) && isset( $b['data']['@graph'] ) ) {
					$graph = $b['data']['@graph'];
				}
			}
			if ( ! $graph ) {
				$mode  = 'template-only';
				$note  = __( 'PublishRank structured data is not printed on this page (check Schema output settings), so only this template\'s entity is shown.', 'publishrank' );
				$graph = $built['node'] ? array( $built['node'] ) : array();
			}
		}
		return array(
			'context'     => $ctx->label(),
			'url'         => $ctx->url,
			'matches'     => $matches,
			'mode'        => $mode,
			'note'        => $note,
			'node'        => $built['node'],
			'skipped'     => $built['skipped'],
			'trace'       => $built['trace'],
			'result'      => Output::$report,
			'graph'       => $graph,
			'jsonld'      => wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'issues'      => Validator::validate( $graph ),
			'node_issues' => $built['node'] ? Validator::validate( array( $built['node'] ) ) : array(),
			'eligibility' => Validator::eligibility( $graph ),
			'tools'       => array( 'rich_results' => Validator::RICH_RESULTS_TEST . rawurlencode( $ctx->url ), 'schema_org' => Validator::SCHEMA_VALIDATOR . rawurlencode( $ctx->url ) ),
		);
	}

	/** Front-end: apply an unsaved template for one preview response. */
	public static function maybe_preview_request() {
		if ( empty( $_GET[ self::PREVIEW_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-gated, read-only preview.
			return;
		}
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET[ self::PREVIEW_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tpl   = 32 === strlen( $token ) ? get_transient( 'publishrank_studio_preview_' . $token ) : false;
		if ( ! is_array( $tpl ) ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		add_filter(
			'publishrank_schema_studio_preview_template',
			static function () use ( $tpl ) {
				return $tpl;
			}
		);
	}

	/** Same-site page fetch (loopback). */
	public static function fetch( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || strtolower( $host ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return new \WP_Error( 'publishrank_external', __( 'Only pages on this site can be checked.', 'publishrank' ) );
		}
		$res = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 3, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => array( 'Cache-Control' => 'no-cache' ), 'user-agent' => 'PublishRank Schema Studio; ' . home_url( '/' ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 ) {
			/* translators: %d: HTTP status */
			return new \WP_Error( 'publishrank_http', sprintf( __( 'HTTP %d', 'publishrank' ), $code ) );
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	public function validate( \WP_REST_Request $r ) {
		$body = (array) $r->get_json_params();
		if ( ! empty( $body['json'] ) && is_string( $body['json'] ) ) {
			$issues = Validator::validate_json( $body['json'] );
			$data   = json_decode( $body['json'], true );
			$nodes  = is_array( $data ) ? ( isset( $data['@graph'] ) ? $data['@graph'] : ( isset( $data['@type'] ) ? array( $data ) : $data ) ) : array();
			return array( 'issues' => $issues, 'eligibility' => is_array( $nodes ) ? Validator::eligibility( $nodes ) : array(), 'summary' => Validator::summary( $issues ) );
		}
		$url = isset( $body['url'] ) ? esc_url_raw( (string) $body['url'] ) : '';
		if ( '' === $url && ! empty( $body['sample'] ) && is_array( $body['sample'] ) ) {
			$ctx = self::context_from( $body['sample'] );
			$url = $ctx ? $ctx->url : '';
		}
		if ( '' === $url ) {
			$url = home_url( '/' );
		}
		$html = self::fetch( $url );
		if ( is_wp_error( $html ) ) {
			$html->add_data( array( 'status' => 400 ) );
			return $html;
		}
		$report            = Validator::page_report( $html, $url );
		$report['summary'] = Validator::summary( $report['issues'] );
		return $report;
	}

	public function export( \WP_REST_Request $r ) {
		$ids = array_filter( array_map( 'sanitize_key', explode( ',', (string) $r->get_param( 'ids' ) ) ) );
		return Templates::export( $ids );
	}

	public function import( \WP_REST_Request $r ) {
		$body = $r->get_json_params();
		$res  = Templates::import( isset( $body['data'] ) ? $body['data'] : $body, ! empty( $body['overwrite'] ) );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
		}
		return $res;
	}

	public function settings( \WP_REST_Request $r ) {
		$b = (array) $r->get_json_params();
		$s = array( 'enabled' => ! empty( $b['enabled'] ) ? 1 : 0, 'connect_orphans' => ! empty( $b['connect_orphans'] ) ? 1 : 0 );
		update_option( Templates::SETTINGS, $s, false );
		return Templates::settings();
	}
}
