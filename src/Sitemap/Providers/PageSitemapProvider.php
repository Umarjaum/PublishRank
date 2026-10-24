<?php
namespace PublishRankSEO\Sitemap\Providers;

defined( 'ABSPATH' ) || exit;

final class PageSitemapProvider extends AbstractPostProvider {
	protected $slug       = 'page';
	protected $name       = 'Pages';
	protected $post_types = array( 'page' );
	public function is_enabled() {
		return (bool) get_option( 'publishrank_sitemap_pages_enabled', true ); }
}
