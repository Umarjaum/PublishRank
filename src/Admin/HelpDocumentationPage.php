<?php
/**
 * In-dashboard feature documentation and contextual help.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

defined( 'ABSPATH' ) || exit;

final class HelpDocumentationPage {
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_submenu_page( \PublishRankSEO\Core\Branding::MENU_SLUG, __( 'Feature Documentation', 'publishrank' ), __( 'Documentation & Help', 'publishrank' ), 'manage_options', 'publishrank-documentation', array( $this, 'page' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'publishrank' ) ) { return; }
		wp_register_style( 'publishrank-inline-help', false, array(), PUBLISHRANK_SEO_VERSION );
		wp_enqueue_style( 'publishrank-inline-help' );
		wp_add_inline_style( 'publishrank-inline-help', '.pr-help-icon{display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;margin-left:5px;border:1px solid #2271b1;border-radius:50%;background:#fff;color:#2271b1;font-size:11px;font-weight:700;line-height:1;cursor:help;vertical-align:middle}.pr-help-pop{display:none;position:absolute;z-index:10000;max-width:300px;padding:10px 12px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;color:#1d2327;box-shadow:0 3px 12px #0002;font-size:12px;font-weight:400;line-height:1.5}.pr-help-icon:focus+.pr-help-pop,.pr-help-icon:hover+.pr-help-pop{display:block}.pr-doc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:20px}.pr-doc-card{padding:18px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.pr-doc-card h2{margin-top:0}.pr-doc-card p{font-size:13px;line-height:1.65}.pr-doc-card code{overflow-wrap:anywhere}.pr-doc-note{padding:12px 15px;border-left:4px solid #2271b1;background:#fff;margin:16px 0}' );
		wp_register_script( 'publishrank-inline-help', '', array(), PUBLISHRANK_SEO_VERSION, true );
		wp_enqueue_script( 'publishrank-inline-help' );
		$map = array(
			'SEO title' => 'The title search engines may show for this page. A custom value takes priority over the generated fallback.',
			'Meta description' => 'A concise summary for search snippets and social previews. Leave blank to let PublishRank derive a fallback from the content.',
			'Canonical URL' => 'The preferred URL for this content. Use only when you intentionally need to consolidate duplicate or alternate URLs.',
			'Robots' => 'Controls whether search engines may index or follow links on eligible pages. Avoid noindex on pages you want searchable.',
			'News sitemap' => 'Lists recently published eligible news articles for discovery. Inclusion does not guarantee Google News placement or indexing.',
			'Image sitemap' => 'Adds discoverable image URLs associated with eligible public content.',
			'Video sitemap' => 'Provides metadata for eligible embedded or hosted videos when the required video details are available.',
			'Schema' => 'Structured data describing your organization, articles, authors, breadcrumbs and related entities. Validate the rendered output after theme changes.',
			'Breadcrumbs' => 'A hierarchical navigation trail. Check your theme before enabling visible output to avoid duplicate breadcrumbs.',
			'Redirect' => 'Sends visitors and crawlers from an old URL to a new destination or returns a selected gone status. Check for loops before saving.',
			'IndexNow' => 'Notifies participating search engines about URL changes. It does not guarantee indexing and is not a Google indexing API.',
			'Publisher' => 'Your publication identity, logo, locale and social profiles used by relevant metadata and structured data.',
			'Cache' => 'Clears or refreshes generated SEO/sitemap data. Site, plugin, CDN and host caches may also need to be purged.',
			'404' => 'Records a bounded history of not-found requests so editors can identify broken links and create appropriate redirects.',
			'Import' => 'Loads supported PublishRank settings. Review imported values before applying them; secrets may be deliberately excluded.',
			'Export' => 'Downloads supported configuration for backup or migration. Keep exported files private because they may contain site configuration.',
			'Keyphrase' => 'An editorial focus phrase used by content analysis. It is a writing aid, not a ranking guarantee.',
			'Schema type' => 'Selects the structured-data type that best represents the content. Do not mark up content that is not actually present.',
		);
		wp_add_inline_script( 'publishrank-inline-help', '(()=>{const help='.wp_json_encode( $map ).';const add=(el,msg)=>{if(el.dataset.prHelpAdded)return;el.dataset.prHelpAdded="1";const b=document.createElement("button");b.type="button";b.className="pr-help-icon";b.textContent="i";b.setAttribute("aria-label","Help: "+msg);b.title=msg;b.style.position="relative";const pop=document.createElement("span");pop.className="pr-help-pop";pop.textContent=msg;el.appendChild(b);el.appendChild(pop)};document.querySelectorAll(".wrap label, .wrap h2, .wrap h3, .wrap legend, .wrap th").forEach(el=>{const t=(el.innerText||el.textContent||"").replace(/\\s+/g," ").trim();for(const [k,v] of Object.entries(help)){if(t.length<100&&t.toLowerCase().includes(k.toLowerCase())){add(el,v);break}}});})();' );
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div class="wrap"><h1>' . esc_html__( 'PublishRank — Feature Documentation', 'publishrank' ) . '</h1><p>' . esc_html__( 'A practical guide to the controls available in PublishRank. Look for the blue “i” icons beside supported settings for quick explanations.', 'publishrank' ) . '</p>';
		echo '<div class="pr-doc-note"><strong>' . esc_html__( 'Important:', 'publishrank' ) . '</strong> ' . esc_html__( 'Custom values generally take priority over automatic fallbacks. SEO output can be affected by themes, caching, multilingual plugins and other SEO plugins. Avoid duplicate metadata providers.', 'publishrank' ) . '</div><div class="pr-doc-grid">';
		$sections = array(
			__( 'Titles, descriptions & canonical URLs', 'publishrank' ) => __( 'PublishRank generates title and description fallbacks from WordPress content and templates. A custom title or description should be used when an editor needs a specific search snippet. Canonical URLs identify the preferred public URL; do not point them at unrelated pages.', 'publishrank' ),
			__( 'XML, News, image & video sitemaps', 'publishrank' ) => __( 'The sitemap index links to the enabled sitemap types. News sitemaps focus on eligible recent articles; image and video sitemaps help describe media attached to eligible content. Check Sitemap Health after changing permalink, sitemap or cache settings. A sitemap is a discovery hint, not an indexing guarantee.', 'publishrank' ),
			__( 'Schema & publisher identity', 'publishrank' ) => __( 'Organization, NewsArticle, author, breadcrumb and media structured data help describe the publication and its content. Complete the publisher identity and logo fields accurately. Do not add custom JSON-LD that duplicates or contradicts generated entities.', 'publishrank' ),
			__( 'Robots, archives & indexing controls', 'publishrank' ) => __( 'Robots settings influence crawling and indexing signals. Use noindex carefully, especially on article pages and category archives. robots.txt rules are not a substitute for access control and do not guarantee removal from search results.', 'publishrank' ),
			__( 'Redirects & 404 monitoring', 'publishrank' ) => __( 'Use permanent redirects for durable URL moves and temporary redirects when the move is temporary. Test the destination and avoid redirect loops/chains. The 404 monitor helps identify broken requests; not every 404 needs a redirect.', 'publishrank' ),
			__( 'IndexNow', 'publishrank' ) => __( 'IndexNow can notify supported participating search engines about URL changes after you enable it and configure its verification key. Notifications do not guarantee indexing, and Google does not use IndexNow.', 'publishrank' ),
			__( 'Editorial SEO intelligence', 'publishrank' ) => __( 'Article analysis reviews signals such as headline, keyphrase, description, structure, readability, media and links. Treat scores and suggestions as editorial guidance, not a promise of ranking performance. Always prioritize accuracy and reader value.', 'publishrank' ),
			__( 'Images, media & performance', 'publishrank' ) => __( 'Image optimization settings control supported image-processing behavior. Keep originals or backups according to your workflow, test output with your theme and CDN, and verify that image dimensions and quality remain suitable for publishing.', 'publishrank' ),
			__( 'Diagnostics & compatibility', 'publishrank' ) => __( 'Run diagnostics after installation or major configuration changes. Review conflicts with themes and other SEO plugins, then inspect the live page source and sitemap responses. Disable overlapping SEO output in other plugins only after confirming which plugin owns each output.', 'publishrank' ),
			__( 'Settings import/export & configuration history', 'publishrank' ) => __( 'Use exports for controlled migration and backups. Review imported settings before relying on them, and protect exported files. Configuration history can help identify changes; it is not a substitute for a full database backup.', 'publishrank' ),
			__( 'Privacy & external integrations', 'publishrank' ) => __( 'PublishRank does not currently provide the Google Search Console OAuth connection screen in this build. Use Google Search Console directly to inspect search performance and manage properties. Never paste OAuth client secrets into public support requests.', 'publishrank' ),
		);
		foreach ( $sections as $title => $description ) { echo '<section class="pr-doc-card"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p></section>'; }
		echo '</div><p class="description">' . esc_html__( 'Online product documentation and support:', 'publishrank' ) . ' <a href="https://publishrank.netlify.app/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'PublishRank website', 'publishrank' ) . '</a></p></div>';
	}
}
