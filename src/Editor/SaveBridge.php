<?php
/**
 * Targeted save for the block editor bridge.
 *
 * The block editor saves registered SEO meta natively through the REST API. The remaining PublishRank section
 * fields (schema selection details, Schema Studio, More structured data, live blog, multilingual SEO) are posted
 * by seo-editor.js right after a successful post save. This class saves them by calling ONLY the PublishRank
 * callbacks that own those sections. It never replays save_post, save_post_{type} or wp_insert_post, so other
 * plugins' handlers (and PublishRank's own IndexNow, analytics and link counters) run exactly once per Update.
 *
 * Each callback still performs its own nonce and capability checks. $_POST is swapped for the bridged fields for
 * the duration of the call and always restored, even when a callback throws.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Editor;

defined( 'ABSPATH' ) || exit;

final class SaveBridge {

	/** @var bool */
	private static $active = false;

	/**
	 * Owners of the bridged sections, as "Class::method". Filterable so add-ons that add a section to the unified
	 * editor can register their own save callback.
	 *
	 * @return string[]
	 */
	public static function callbacks() {
		$list = array(
			'PublishRankSEO\\Meta\\PostMeta::save',
			'PublishRankSEO\\Schema\\LiveBlogSchema::save',
			'PublishRankSEO\\Schema\\Library\\Library::save',
			'PublishRankSEO\\Schema\\Studio\\PostPanel::save',
			'PublishRankSEO\\Meta\\LocalizedSeoMeta::save',
		);
		/**
		 * Filters the save callbacks the block editor bridge calls.
		 *
		 * @param string[] $list "Class::method" names, in any order (they run in their save_post priority order).
		 */
		$list = (array) apply_filters( 'publishrank_editor_bridge_callbacks', $list );
		return array_values( array_unique( array_filter( array_map( 'strval', $list ) ) ) );
	}

	/** True while the bridge is running callbacks. */
	public static function is_active() {
		return self::$active;
	}

	/**
	 * Resolve the registered save_post callbacks that belong to the allow-list, keeping their priority and argument
	 * count. Reusing the registered callback means the same instance (with its state) is called.
	 *
	 * @return array<int,array{fn:callable,args:int,priority:int,name:string}>
	 */
	public static function resolve() {
		global $wp_filter;
		$allowed = array_map( 'strtolower', self::callbacks() );
		$found   = array();
		if ( empty( $wp_filter['save_post'] ) || ! $wp_filter['save_post'] instanceof \WP_Hook ) {
			return $found;
		}
		foreach ( $wp_filter['save_post']->callbacks as $priority => $items ) {
			foreach ( (array) $items as $item ) {
				$name = self::callable_name( isset( $item['function'] ) ? $item['function'] : null );
				if ( '' === $name || ! in_array( strtolower( $name ), $allowed, true ) ) {
					continue;
				}
				$found[] = array(
					'fn'       => $item['function'],
					'args'     => isset( $item['accepted_args'] ) ? (int) $item['accepted_args'] : 1,
					'priority' => (int) $priority,
					'name'     => $name,
				);
			}
		}
		usort(
			$found,
			static function ( $a, $b ) {
				return $a['priority'] - $b['priority'];
			}
		);
		return $found;
	}

	/** "Class::method" for array or string callables, '' otherwise. */
	public static function callable_name( $fn ) {
		if ( is_string( $fn ) ) {
			return ltrim( $fn, '\\' );
		}
		if ( is_array( $fn ) && 2 === count( $fn ) && is_string( $fn[1] ) ) {
			$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : ( is_string( $fn[0] ) ? ltrim( $fn[0], '\\' ) : '' );
			return '' === $class ? '' : $class . '::' . $fn[1];
		}
		return '';
	}

	/**
	 * Run the owned callbacks for one post with the given fields as $_POST.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @param array    $fields  Posted PublishRank section fields (still slashed, like $_POST).
	 * @return string[] Names of the callbacks that ran.
	 */
	public static function run( $post_id, $post, array $fields ) {
		$original     = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- restored below; callbacks verify their own nonces.
		$ran          = array();
		self::$active = true;
		try {
			$_POST = $fields;
			foreach ( self::resolve() as $cb ) {
				$args = array_slice( array( $post_id, $post, true ), 0, max( 1, min( 3, $cb['args'] ) ) );
				call_user_func_array( $cb['fn'], $args );
				$ran[] = $cb['name'];
			}
		} finally {
			$_POST        = $original;
			self::$active = false;
		}
		return $ran;
	}
}
