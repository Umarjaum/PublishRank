<?php
/**
 * First-class SEO REST API (2.26.2, additive).
 *
 * Routes under publishrank/v1:
 * - GET   /posts/{id}/seo       Resolved SEO data. Editorial fields only for users who can edit the post.
 * - PATCH /posts/{id}/seo       Update SEO fields. Requires edit_post + edit_seo. Uses the registered meta sanitizers.
 * - GET   /posts/{id}/analysis  Server-side content analysis. Requires edit_post.
 * - GET   /posts/{id}/head      The real <head> output (title, meta, JSON-LD) for headless sites.
 * - GET   /posts/{id}/schema    The JSON-LD graph only.
 * - GET   /sitemaps             Sitemap index URL and listed files.
 *
 * Read routes for published, non-password posts are public; drafts and private posts need edit_post.
 *
 * @package PublishRankSEO
 */

namespace PublishRankSEO\API;

use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\SEO\ArticleIntelligence;

defined( 'ABSPATH' ) || exit;

final class PostSeoController {

	const NS = 'publishrank/v1';

	/** Friendly field name => registered meta key. */
	public static function fields() {
		return (array) apply_filters(
			'publishrank_rest_seo_fields',
			array(
				'title'                => PostMeta::TITLE,
				'description'          => PostMeta::DESCRIPTION,
				'robots'               => PostMeta::ROBOTS,
				'canonical'            => PostMeta::CANONICAL,
				'og_title'             => PostMeta::OG_TITLE,
				'og_description'       => PostMeta::OG_DESCRIPTION,
				'og_image'             => PostMeta::OG_IMAGE,
				'og_image_alt'         => PostMeta::OG_IMAGE_ALT,
				'x_title'              => PostMeta::X_TITLE,
				'x_description'        => PostMeta::X_DESCRIPTION,
				'focus_keyword'        => PostMeta::FOCUS_KEYWORD,
				'primary_keyphrase'    => PostMeta::PRIMARY_KEYPHRASE,
				'secondary_keyphrases' => PostMeta::SECONDARY_KEYPHRASES,
				'keyphrase_synonyms'   => PostMeta::KEYPHRASE_SYNONYMS,
				'related_terms'        => PostMeta::RELATED_TERMS,
				'entity_topic'         => PostMeta::ENTITY_TOPIC,
				'cornerstone'          => PostMeta::CORNERSTONE,
				'schema_type'          => PostMeta::SCHEMA_TYPE,
			)
		);
	}

	/** @var object|null Plugin instance, for the output registry. */
	private static $plugin = null;

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action(
			'publishrank_seo_loaded',
			static function ( $plugin ) {
				self::$plugin = $plugin;
			}
		);
	}

	private static function reset_registry() {
		if ( self::$plugin && method_exists( self::$plugin, 'registry' ) && self::$plugin->registry() ) {
			self::$plugin->registry()->reset();
		}
	}

	public function routes() {
		$id = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/seo',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_seo' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => $id,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_seo' ),
					'permission_callback' => array( $this, 'can_write' ),
					'args'                => $id,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/analysis',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'analysis' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/head',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'head' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/schema',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'schema' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NS,
			'/sitemaps',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sitemaps' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ---------- permissions ---------- */

	private static function post( $request ) {
		$post = get_post( (int) $request['id'] );
		return ( $post instanceof \WP_Post && in_array( $post->post_type, PostMeta::post_types(), true ) ) ? $post : null;
	}

	/** Published, non-password posts of a public type are public; everything else needs edit_post. */
	public static function is_public( \WP_Post $post ) {
		$type = get_post_type_object( $post->post_type );
		return 'publish' === $post->post_status && '' === (string) $post->post_password && $type && ! empty( $type->public );
	}

	public function can_read( $request ) {
		$post = self::post( $request );
		if ( ! $post ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'publishrank' ), array( 'status' => 404 ) );
		}
		if ( self::is_public( $post ) || current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}
		$limited = \PublishRankSEO\Core\RateLimiter::rest( 'seo_rest', 120, 60 );
		return $limited ? $limited : new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to view this item.', 'publishrank' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function can_edit( $request ) {
		$post = self::post( $request );
		if ( ! $post ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'publishrank' ), array( 'status' => 404 ) );
		}
		return current_user_can( 'edit_post', $post->ID ) ? true : new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this item.', 'publishrank' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function can_write( $request ) {
		$post = self::post( $request );
		if ( ! $post ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'publishrank' ), array( 'status' => 404 ) );
		}
		return Capabilities::can_edit_seo( $post->ID ) ? true : new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit SEO for this item.', 'publishrank' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/* ---------- handlers ---------- */

	public function get_seo( $request ) {
		$post = self::post( $request );
		$data = ( new RestFields() )->get( array( 'id' => $post->ID ) );
		$data['id']       = (int) $post->ID;
		$data['status']   = (string) $post->post_status;
		$data['modified'] = get_post_modified_time( 'c', true, $post );
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$raw = array();
			foreach ( self::fields() as $name => $key ) {
				$raw[ $name ] = get_post_meta( $post->ID, $key, true );
			}
			$data['stored'] = $raw; // Values as saved (empty = automatic), next to the resolved values above.
		}
		return rest_ensure_response( $data );
	}

	public function update_seo( $request ) {
		$post   = self::post( $request );
		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : $request->get_body_params();
		$map    = self::fields();
		$keys   = get_registered_meta_keys( 'post', $post->post_type );
		$errors = array();
		$todo   = array();
		foreach ( (array) $body as $name => $value ) {
			if ( ! isset( $map[ $name ] ) ) {
				$errors[ $name ] = 'unknown_field';
				continue;
			}
			$key  = $map[ $name ];
			$type = isset( $keys[ $key ]['type'] ) ? $keys[ $key ]['type'] : 'string';
			if ( 'array' === $type ) {
				if ( is_string( $value ) ) {
					$value = array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' );
				}
				if ( ! is_array( $value ) ) {
					$errors[ $name ] = 'expected_array';
					continue;
				}
				$value = array_values( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? '1' : '0';
			} elseif ( null === $value ) {
				$value = '';
			} elseif ( ! is_scalar( $value ) ) {
				$errors[ $name ] = 'expected_string';
				continue;
			} else {
				$value = (string) $value;
			}
			$todo[ $key ] = array( $name, $value );
		}
		if ( $errors ) {
			return new \WP_Error( 'publishrank_invalid_fields', __( 'Some fields were not accepted. Nothing was saved.', 'publishrank' ), array( 'status' => 400, 'fields' => $errors ) );
		}
		$changed = array();
		foreach ( $todo as $key => $pair ) {
			$before = get_post_meta( $post->ID, $key, true );
			if ( '' === $pair[1] || array() === $pair[1] ) {
				delete_post_meta( $post->ID, $key ); // Empty = back to automatic.
			} else {
				update_post_meta( $post->ID, $key, $pair[1] ); // Runs the registered sanitize_callback.
			}
			if ( get_post_meta( $post->ID, $key, true ) !== $before ) {
				$changed[] = $pair[0];
			}
		}
		if ( $changed ) {
			\PublishRankSEO\Meta\SeoData::flush( (int) $post->ID );
			clean_post_cache( (int) $post->ID );
			/** Fires after SEO fields change through the REST API. */
			do_action( 'publishrank_rest_seo_updated', (int) $post->ID, $changed );
		}
		$response = $this->get_seo( $request );
		$response->header( 'X-PublishRank-Changed', implode( ',', $changed ) );
		return $response;
	}

	public function analysis( $request ) {
		$post   = self::post( $request );
		$report = ArticleIntelligence::analyze( ArticleIntelligence::data_from_post( $post ), true );
		return rest_ensure_response(
			array(
				'id'     => (int) $post->ID,
				'report' => $report,
			)
		);
	}

	public function head( $request ) {
		$post = self::post( $request );
		$out  = self::render_head( $post );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	public function schema( $request ) {
		$post = self::post( $request );
		$out  = self::render_head( $post );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return rest_ensure_response(
			array(
				'id'     => (int) $post->ID,
				'jsonld' => $out['jsonld'],
			)
		);
	}

	public function sitemaps() {
		$engine = new \PublishRankSEO\Sitemap\Engine\SitemapEngine();
		$files  = array();
		foreach ( $engine->providers() as $provider ) {
			if ( ! $provider->is_enabled() ) {
				continue;
			}
			foreach ( $engine->listed_shards( $provider ) as $shard ) {
				$files[] = array(
					'type' => $provider->get_slug(),
					'url'  => \PublishRankSEO\Sitemap\SitemapUrls::shard_url( $provider->get_slug(), $shard ),
				);
			}
		}
		return rest_ensure_response(
			array(
				'index' => \PublishRankSEO\Sitemap\SitemapUrls::index_url(),
				'files' => $files,
			)
		);
	}

	/**
	 * Render the singular <head> for a post in-process: set up the main query, run wp_head, capture the output and
	 * restore every global. Returns title, meta/link tags and decoded JSON-LD.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function render_head( \WP_Post $post ) {
		$saved = array( isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null, isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null, isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null );
		$html  = '';
		try {
			$q = new \WP_Query(
				array(
					'p'                   => (int) $post->ID,
					'post_type'           => $post->post_type,
					'post_status'         => 'any',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);
			if ( ! $q->have_posts() ) {
				return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'publishrank' ), array( 'status' => 404 ) );
			}
			$GLOBALS['wp_query']     = $q; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in finally.
			$GLOBALS['wp_the_query'] = $q; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$q->the_post();
			self::reset_registry();
			ob_start();
			try {
				wp_head();
			} finally {
				$html = (string) ob_get_clean();
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'publishrank_head_failed', substr( $e->getMessage(), 0, 200 ), array( 'status' => 500 ) );
		} finally {
			list( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $GLOBALS['post'] ) = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wp_reset_postdata();
			self::reset_registry();
		}
		return self::parse_head( $html, (int) $post->ID );
	}

	/** @return array<string,mixed> */
	public static function parse_head( $html, $post_id = 0 ) {
		$out = array(
			'id'     => (int) $post_id,
			'title'  => '',
			'meta'   => array(),
			'links'  => array(),
			'jsonld' => array(),
		);
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$out['title'] = html_entity_decode( trim( wp_strip_all_tags( $m[1] ) ), ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match_all( '#<meta\s[^>]*>#i', $html, $metas ) ) {
			foreach ( $metas[0] as $tag ) {
				$a = self::attrs( $tag );
				$k = isset( $a['property'] ) ? 'property' : ( isset( $a['name'] ) ? 'name' : '' );
				if ( '' !== $k && isset( $a['content'] ) ) {
					$out['meta'][] = array( $k => $a[ $k ], 'content' => $a['content'] );
				}
			}
		}
		if ( preg_match_all( '#<link\s[^>]*>#i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				$a = self::attrs( $tag );
				if ( isset( $a['rel'], $a['href'] ) && in_array( strtolower( $a['rel'] ), array( 'canonical', 'prev', 'next', 'alternate', 'shortlink' ), true ) ) {
					$out['links'][] = array_intersect_key( $a, array_flip( array( 'rel', 'href', 'hreflang', 'type', 'title' ) ) );
				}
			}
		}
		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $json ) {
				$data = json_decode( trim( $json ), true );
				if ( is_array( $data ) ) {
					$out['jsonld'][] = $data;
				}
			}
		}
		return $out;
	}

	/** @return array<string,string> */
	private static function attrs( $tag ) {
		$a = array();
		if ( preg_match_all( '#([a-zA-Z:_-]+)\s*=\s*("([^"]*)"|\'([^\']*)\')#', $tag, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$a[ strtolower( $x[1] ) ] = html_entity_decode( isset( $x[4] ) && '' !== $x[4] ? $x[4] : $x[3], ENT_QUOTES, 'UTF-8' );
			}
		}
		return $a;
	}
}
