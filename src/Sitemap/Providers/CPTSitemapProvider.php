<?php
namespace PublishRankSEO\Sitemap\Providers;

final class CPTSitemapProvider extends AbstractPostProvider {
	protected $slug = 'cpt';
	protected $name = 'Custom post types';
	public function __construct() {
		$types = get_post_types( array( 'public' => true, 'publicly_queryable' => true ), 'names' );
		$excluded = array( 'post', 'page', 'attachment' );
		$this->post_types = array_values( array_diff( (array) $types, $excluded ) );
	}
	public function is_enabled() { return ! empty( $this->post_types ) && (bool) get_option( 'publishrank_sitemap_cpt_enabled', true ); }
}
