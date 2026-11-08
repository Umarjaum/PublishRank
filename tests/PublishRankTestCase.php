<?php
/**
 * Base class: creates content, restores options, renders <head> output and extracts what a crawler sees.
 *
 * @package PublishRankSEO
 */

use PHPUnit\Framework\TestCase;

abstract class PublishRankTestCase extends TestCase {
	/** @var int[] */
	private $post_ids = array();
	/** @var int[] */
	private $term_ids = array();
	/** @var array<string,mixed> Original option values (or a sentinel) keyed by option name. */
	private $options = array();
	const UNSET_OPTION = '__publishrank_test_unset__';

	protected function setUp(): void {
		parent::setUp();
		\PublishRankSEO\Meta\SeoData::flush();
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		foreach ( $this->post_ids as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->term_ids as $row ) {
			wp_delete_term( $row[0], $row[1] );
		}
		foreach ( $this->options as $name => $original ) {
			if ( self::UNSET_OPTION === $original ) {
				delete_option( $name );
			} else {
				update_option( $name, $original );
			}
		}
		$this->post_ids = $this->term_ids = $this->options = array();
		\PublishRankSEO\Meta\SeoData::flush();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Set an option for the duration of one test. */
	protected function set_option( $name, $value ) {
		if ( ! array_key_exists( $name, $this->options ) ) {
			$this->options[ $name ] = get_option( $name, self::UNSET_OPTION );
		}
		update_option( $name, $value );
	}

	protected function make_post( array $args = array() ) {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Test post ' . wp_generate_password( 6, false ),
					'post_content' => '',
					'post_status'  => 'publish',
					'post_type'    => 'post',
					'post_author'  => 1,
				),
				$args
			)
		);
		$this->assertTrue( is_int( $id ) && $id > 0, 'Could not create a post.' );
		$this->post_ids[] = $id;
		return $id;
	}

	protected function make_page( array $args = array() ) {
		return $this->make_post( array_merge( array( 'post_type' => 'page' ), $args ) );
	}

	protected function make_term( $name, $taxonomy = 'category', array $args = array() ) {
		$row = wp_insert_term( $name . ' ' . wp_generate_password( 4, false ), $taxonomy, $args );
		$this->assertFalse( is_wp_error( $row ), 'Could not create a term.' );
		$this->term_ids[] = array( (int) $row['term_id'], $taxonomy );
		return (int) $row['term_id'];
	}

	/** Point the main query at a URL, like a real front-end request. */
	protected function go_to( $url ) {
		$_GET = $_POST = array();
		foreach ( array( 'query_string', 'id', 'postdata', 'authordata', 'day', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' ) as $var ) {
			unset( $GLOBALS[ $var ] );
		}
		$parts = wp_parse_url( $url );
		$req   = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $_GET );
		}
		$_SERVER['REQUEST_URI'] = $req;
		unset( $_SERVER['PATH_INFO'] );
		wp_cache_flush();
		unset( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] );
		$GLOBALS['wp_the_query'] = new WP_Query();
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
		$GLOBALS['wp']           = new WP();
		$GLOBALS['wp']->main( isset( $parts['query'] ) ? $parts['query'] : '' );
		\PublishRankSEO\Meta\SeoData::flush();
	}

	/** Request the permalink of a post and return the HTML that wp_head() prints. */
	protected function head_for_post( $post_id ) {
		$this->go_to( get_permalink( $post_id ) );
		return $this->capture_head();
	}

	protected function head_for_url( $url ) {
		$this->go_to( $url );
		return $this->capture_head();
	}

	protected function capture_head() {
		do_action( 'publishrank_seo_reset_output_registry' );
		ob_start();
		do_action( 'wp_head' );
		return (string) ob_get_clean();
	}

	/** Value of <meta name="x" content="..."> or <meta property="x" content="..."> (first match). */
	protected function meta( $html, $key ) {
		$all = $this->meta_all( $html, $key );
		return $all ? $all[0] : null;
	}

	/** @return string[] Every value of a meta tag, so duplicates are detectable. */
	protected function meta_all( $html, $key ) {
		$found = array();
		if ( preg_match_all( '/<meta\s+(?:name|property)="' . preg_quote( $key, '/' ) . '"\s+content="([^"]*)"\s*\/?>/i', $html, $m ) ) {
			foreach ( $m[1] as $value ) {
				$found[] = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
			}
		}
		return $found;
	}

	/** @return string[] Every href of <link rel="canonical">. */
	protected function canonicals( $html ) {
		$found = array();
		if ( preg_match_all( '/<link\s+rel="canonical"\s+href="([^"]*)"/i', $html, $m ) ) {
			$found = $m[1];
		}
		return $found;
	}

	/** @return array<int,mixed> Decoded JSON-LD blocks. */
	protected function json_ld( $html ) {
		$blocks = array();
		if ( preg_match_all( '/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$decoded = json_decode( trim( $json ), true );
				$blocks[] = null === $decoded ? array( '__invalid_json__' => $json ) : $decoded;
			}
		}
		return $blocks;
	}

	/** Flatten every @graph node of every JSON-LD block. @return array<int,array> */
	protected function schema_nodes( $html ) {
		$nodes = array();
		foreach ( $this->json_ld( $html ) as $block ) {
			if ( isset( $block['@graph'] ) && is_array( $block['@graph'] ) ) {
				foreach ( $block['@graph'] as $node ) {
					$nodes[] = $node;
				}
			} elseif ( is_array( $block ) ) {
				$nodes[] = $block;
			}
		}
		return $nodes;
	}

	protected function node_of_type( array $nodes, $type ) {
		foreach ( $nodes as $node ) {
			$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( in_array( $type, $types, true ) ) {
				return $node;
			}
		}
		return null;
	}

	protected function make_admin() {
		$user_id = wp_insert_user( array( 'user_login' => 'prtest' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
		$this->assertFalse( is_wp_error( $user_id ) );
		return (int) $user_id;
	}
}
