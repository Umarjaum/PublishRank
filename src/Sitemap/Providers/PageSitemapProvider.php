<?php
namespace PublishRankSEO\Sitemap\Providers;

defined( 'ABSPATH' ) || exit;

final class PageSitemapProvider extends AbstractPostProvider {
	protected $slug       = 'page';
	protected $name       = 'Pages';
	protected $post_types = array( 'page' );
	public function is_enabled() {
		return (bool) get_option( 'publishrank_sitemap_pages_enabled', true ); }

	/**
	 * When the homepage shows the latest posts it is not a page, so no provider lists it. Add it to the
	 * first page shard; a static front page is already listed as an ordinary page.
	 */
	public function render_shard( $shard ) {
		$xml = parent::render_shard( $shard );
		if ( 1 !== (int) $shard || 'posts' !== get_option( 'show_on_front' ) || ! apply_filters( 'publishrank_sitemap_include_homepage', true ) ) {
			return $xml;
		}
		$latest = get_lastpostmodified( 'gmt' );
		$entry  = \PublishRankSEO\Sitemap\Engine\SitemapRenderer::url( home_url( '/' ), $latest ? gmdate( DATE_W3C, strtotime( $latest . ' UTC' ) ) : '' );
		$at     = strpos( $xml, '<url>' );
		$at     = false === $at ? strpos( $xml, '</urlset>' ) : $at;
		return false === $at ? $xml : substr( $xml, 0, $at ) . $entry . substr( $xml, $at );
	}
}
