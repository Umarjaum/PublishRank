<?php
/**
 * Generic sitemap provider for integration-owned post types (forums, topics, web stories, episodes).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Integrations;

use PublishRankSEO\Sitemap\Providers\AbstractPostProvider;

defined( 'ABSPATH' ) || exit;

final class PostTypeSitemapProvider extends AbstractPostProvider {
	private $enabled;
	private $types_source;
	private $item_filter = null;

	/**
	 * @param string   $slug       Sitemap slug (URL part).
	 * @param string   $name       Human label.
	 * @param string[]|callable $post_types Post types listed, or a callable returning them (resolved lazily).
	 * @param callable $enabled    Lazy check whether the provider is live.
	 * @param string[]|null $statuses Post statuses listed (default: publish only).
	 * @param callable|null $item_filter Receives a post ID, returns false to keep it out of the sitemap.
	 */
	public function __construct( $slug, $name, $post_types, callable $enabled, $statuses = null, $item_filter = null ) {
		$this->slug       = sanitize_key( $slug );
		$this->name       = $name;
		$this->types_source = $post_types;
		$this->post_types   = is_callable( $post_types ) ? array() : (array) $post_types;
		$this->enabled    = $enabled;
		if ( is_array( $statuses ) && $statuses ) {
			$this->post_statuses = array_values( array_map( 'sanitize_key', $statuses ) );
		}
		$this->item_filter = is_callable( $item_filter ) ? $item_filter : null;
	}

	protected function is_indexable( $post_id ) {
		if ( ! parent::is_indexable( $post_id ) ) {
			return false;
		}
		return $this->item_filter ? (bool) call_user_func( $this->item_filter, (int) $post_id ) : true;
	}

	public function is_enabled() {
		$source = is_callable( $this->types_source ) ? (array) call_user_func( $this->types_source ) : (array) $this->types_source;
		$types  = array_values( array_filter( $source, 'post_type_exists' ) );
		if ( empty( $types ) || ! call_user_func( $this->enabled ) ) {
			return false;
		}
		$this->post_types = $types;
		return true;
	}
}
