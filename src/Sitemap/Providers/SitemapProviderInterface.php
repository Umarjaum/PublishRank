<?php
namespace PublishRankSEO\Sitemap\Providers;

defined( 'ABSPATH' ) || exit;

interface SitemapProviderInterface {
	public function get_name();
	public function get_slug();
	public function is_enabled();
	public function get_shard_count();
	public function render_shard( $shard );
}
