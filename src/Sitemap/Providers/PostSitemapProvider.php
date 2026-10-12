<?php
namespace PublishRankSEO\Sitemap\Providers;

defined( 'ABSPATH' ) || exit;

final class PostSitemapProvider extends AbstractPostProvider {
	protected $slug = 'post';
	protected $name = 'Posts';
	public function __construct() {
		add_action( 'init', array( $this, 'discover' ), 1 );
		$this->discover();
	}
	public function discover() {
		$this->post_types = array();
		$raw = (string) get_option( 'publishrank_sitemap_post_types', 'post' );
		$types = preg_split( '/[\\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $types as $type ) {
			$type = sanitize_key( $type );
			$obj = get_post_type_object( $type );
			if ( $obj && $obj->public && $obj->publicly_queryable && 'attachment' !== $type ) { $this->post_types[] = $type; }
		}
		$this->post_types = array_values( array_unique( $this->post_types ? $this->post_types : array( 'post' ) ) );
	}
}
