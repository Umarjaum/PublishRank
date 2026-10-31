<?php
/**
 * Human-friendly XSL stylesheet for the XML sitemaps.
 *
 * The same /publishrank-sitemap.xsl URL serves every sitemap; the query argument pr=type,part,version tells the
 * stylesheet which sitemap it is dressing (the XML itself cannot tell an XSLT 1.0 transform its own address).
 * Search engines never use this file: they read the plain XML.
 */
namespace PublishRankSEO\Sitemap;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class SitemapStylesheet {
	/** Largest sitemap index for which the browser view fetches every child file to show URL counts. */
	const COUNT_LIMIT = 40;

	public function register() {
		add_action( 'init', array( $this, 'rewrite' ) );
		add_action( 'template_redirect', array( $this, 'render' ), 1 );
		// Serve the stylesheet even when the stored rewrite rules are stale or missing (same safety net as the sitemap XML itself).
		// A 404 here makes the browser render the styled XML sitemap as a blank white page.
		add_action( 'parse_request', array( $this, 'match_request' ), 0 );
	}

	/** Resolve /publishrank-sitemap.xsl straight from the URL when no stored rewrite rule matched it. */
	public function match_request( $wp ) {
		if ( ! is_object( $wp ) || ! empty( $wp->query_vars['publishrank_xsl'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return; }
		$path = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) ); }
		if ( 'publishrank-sitemap.xsl' === ltrim( rawurldecode( $path ), '/' ) ) {
			$wp->query_vars['publishrank_xsl'] = '1'; }
	}

	public function rewrite() {
		add_rewrite_tag( '%publishrank_xsl%', '([0-1])' );
		add_rewrite_rule( '^publishrank-sitemap\.xsl$', 'index.php?publishrank_xsl=1', 'top' );
	}

	/**
	 * Root-relative URL so the browser treats it as same-origin.
	 *
	 * @param string $type Sitemap slug ('index' for the parent sitemap).
	 * @param int    $part Shard number.
	 */
	public static function url( $type = 'index', $part = 1 ) {
		// One comma-separated argument (type,part,version): an XML processing instruction does not reliably decode '&'.
		$arg = sanitize_key( $type ) . ',' . max( 1, absint( $part ) ) . ',' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '1' );
		return wp_make_link_relative( home_url( '/publishrank-sitemap.xsl' ) ) . '?pr=' . $arg;
	}

	/**
	 * Display metadata per sitemap type: label, description and a two-letter badge.
	 *
	 * @return array<string,array{label:string,desc:string,abbr:string}>
	 */
	public static function types() {
		$types = array(
			'post'       => array(
				'label' => __( 'Articles', 'publishrank' ),
				'desc'  => __( 'Published posts and articles', 'publishrank' ),
				'abbr'  => 'AR',
			),
			'page'       => array(
				'label' => __( 'Pages', 'publishrank' ),
				'desc'  => __( 'Static pages such as About and Contact', 'publishrank' ),
				'abbr'  => 'PG',
			),
			'cpt'        => array(
				'label' => __( 'Custom content', 'publishrank' ),
				'desc'  => __( 'Custom post types', 'publishrank' ),
				'abbr'  => 'CT',
			),
			'taxonomy'   => array(
				'label' => __( 'Topics', 'publishrank' ),
				'desc'  => __( 'Categories, tags and other topic hubs', 'publishrank' ),
				'abbr'  => 'TP',
			),
			'author'     => array(
				'label' => __( 'Authors', 'publishrank' ),
				'desc'  => __( 'Author profile and archive pages', 'publishrank' ),
				'abbr'  => 'AU',
			),
			'attachment' => array(
				'label' => __( 'Media files', 'publishrank' ),
				'desc'  => __( 'Uploaded image files', 'publishrank' ),
				'abbr'  => 'MF',
			),
			'news'       => array(
				'label' => __( 'Google News', 'publishrank' ),
				'desc'  => __( 'Fresh articles for Google News and Top Stories', 'publishrank' ),
				'abbr'  => 'GN',
			),
			'image'      => array(
				'label' => __( 'Images', 'publishrank' ),
				'desc'  => __( 'Images that appear in your content', 'publishrank' ),
				'abbr'  => 'IM',
			),
			'video'      => array(
				'label' => __( 'Videos', 'publishrank' ),
				'desc'  => __( 'Embedded and hosted video', 'publishrank' ),
				'abbr'  => 'VD',
			),
		);
		return (array) apply_filters( 'publishrank_sitemap_type_labels', $types );
	}

	/** @return array{label:string,desc:string,abbr:string} */
	public static function type( $slug ) {
		$types = self::types();
		if ( isset( $types[ $slug ] ) ) {
			return $types[ $slug ]; }
		$label = ucwords( str_replace( array( '-', '_' ), ' ', (string) $slug ) );
		return array(
			'label' => '' !== $label ? $label : __( 'Sitemap', 'publishrank' ),
			'desc'  => '',
			'abbr'  => strtoupper( substr( preg_replace( '/[^a-z]/i', '', (string) $slug ) . 'SM', 0, 2 ) ),
		);
	}

	public function render() {
		if ( '1' !== (string) get_query_var( 'publishrank_xsl' ) ) {
			return;
		}
		// WordPress' canonical redirect must never rewrite the stylesheet URL, and no theme/plugin output may precede the XML declaration.
		remove_action( 'template_redirect', 'redirect_canonical' );
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); }
		status_header( 200 );
		header( 'Content-Type: text/xsl; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );
		// Read-only display parameters, never stored; both are reduced to a slug / integer before use.
		$arg  = isset( $_GET['pr'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['pr'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = ! empty( $arg[0] ) ? sanitize_key( $arg[0] ) : 'index';
		$part = isset( $arg[1] ) ? max( 1, absint( $arg[1] ) ) : 1;
		echo self::build( $type, $part ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built from escaped values.
		exit;
	}

	/** Build the XSLT document for one sitemap. Public so the admin health screen and tests can reuse it. */
	public static function build( $type = 'index', $part = 1 ) {
		$meta     = 'index' === $type ? array(
			'label' => __( 'Sitemap index', 'publishrank' ),
			'desc'  => __( 'The master list of every sitemap on this site', 'publishrank' ),
			'abbr'  => 'XML',
		) : self::type( $type );
		$home     = home_url( '/' );
		$window   = class_exists( '\PublishRankSEO\Sitemap\News\NewsSitemapProvider' ) ? ( new \PublishRankSEO\Sitemap\News\NewsSitemapProvider() )->window_hours() : 48;
		$prefix   = trailingslashit( $home ) . SitemapUrls::shard_prefix() . '-';
		$maps     = array(
			'LABELS' => 'label',
			'DESCS'  => 'desc',
			'ABBRS'  => 'abbr',
		);
		$tokens   = array(
			// This XSLT document is served as text/xsl, not an HTML page, so the bundled stylesheet and script are embedded inline from the plugin's own local files.
			'%%CSSLINK%%'  => self::inline_asset( 'css/sitemap.css', 'style' ),
			'%%JSLINK%%'   => self::inline_asset( 'js/sitemap.js', 'script' ),
			'%%LOGO%%'     => esc_url( Branding::image( 'publishrank-header-icon-v2.png' ) ),
			'%%SITE%%'     => esc_html( wp_strip_all_tags( (string) \PublishRankSEO\Core\PublicationProfile::publication_name() ) ),
			'%%HOME%%'     => esc_url( ! empty( wp_make_link_relative( $home ) ) ? wp_make_link_relative( $home ) : '/' ),
			'%%INDEX%%'    => esc_url( wp_make_link_relative( SitemapUrls::index_url() ) ),
			'%%INDEXURL%%' => esc_html( SitemapUrls::index_url() ),
			'%%ROBOTS%%'   => esc_url( wp_make_link_relative( home_url( '/robots.txt' ) ) ),
			'%%PREFIX%%'   => self::xpath( $prefix ),
			'%%HOMEURL%%'  => self::xpath( $home ),
			'%%TYPE%%'     => esc_attr( $type ),
			'%%PART%%'     => (int) $part,
			'%%TLABEL%%'   => esc_html( $meta['label'] ),
			'%%TDESC%%'    => esc_html( $meta['desc'] ),
			'%%TABBR%%'    => esc_html( $meta['abbr'] ),
			'%%WINDOW%%'   => (int) $window,
			'%%LIMIT%%'    => (int) self::COUNT_LIMIT,
		);
		foreach ( $maps as $token => $field ) {
			$when = '';
			foreach ( self::types() as $slug => $row ) {
				$when .= '<xsl:when test="$t=\'' . esc_attr( $slug ) . '\'">' . esc_html( $row[ $field ] ) . '</xsl:when>'; }
			$tokens[ '%%' . $token . '%%' ] = $when;
		}
		return strtr( self::xsl(), $tokens );
	}

	/**
	 * Embed one bundled asset inline in the XSLT output as an XML-safe <style> or <script> element.
	 *
	 * @param string $relative Path inside the plugin assets directory.
	 * @param string $tag      'style' or 'script'.
	 */
	private static function inline_asset( $relative, $tag ) {
		$file = PUBLISHRANK_SEO_PATH . 'assets/' . $relative;
		$code = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled plugin file, not a remote request.
		$code = str_replace( '</', '<\\/', $code );
		$code = htmlspecialchars( $code, ENT_XML1 | ENT_NOQUOTES, 'UTF-8' );
		$tag  = 'script' === $tag ? 'script' : 'style';
		return '<' . $tag . '><xsl:text>' . $code . '</xsl:text></' . $tag . '>';
	}

	/** Safe content for an XPath single-quoted string literal that sits inside an XML attribute or text. */
	private static function xpath( $value ) {
		return htmlspecialchars( str_replace( "'", '%27', (string) $value ), ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}

	/**
	 * XSLT document served at /publishrank-sitemap.xsl for browsers viewing the XML sitemap.
	 * It is not an HTML page rendered by WordPress, so wp_enqueue_style() cannot apply;
	 * its stylesheet and script are the bundled assets/css/sitemap.css and assets/js/sitemap.js files, referenced by URL.
	 */
	private static function xsl() {
		return <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"
	xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
	xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
	exclude-result-prefixes="sm news image video">
<xsl:output method="html" encoding="UTF-8" indent="no" doctype-system="about:legacy-compat"/>
<xsl:key name="by-type" match="sm:sitemap" use="substring-before(substring-after(sm:loc,'%%PREFIX%%'),'-sitemap')"/>

<xsl:template name="label"><xsl:param name="t"/><xsl:choose>%%LABELS%%<xsl:otherwise><xsl:text>Other sitemaps</xsl:text></xsl:otherwise></xsl:choose></xsl:template>
<xsl:template name="desc"><xsl:param name="t"/><xsl:choose>%%DESCS%%<xsl:otherwise/></xsl:choose></xsl:template>
<xsl:template name="abbr"><xsl:param name="t"/><xsl:choose>%%ABBRS%%<xsl:otherwise><xsl:text>SM</xsl:text></xsl:otherwise></xsl:choose></xsl:template>

<xsl:template name="when">
	<xsl:param name="v"/>
	<xsl:choose>
		<xsl:when test="string-length($v) &gt; 0"><time datetime="{$v}"><xsl:value-of select="concat(substring($v,1,10),' ',substring($v,12,5))"/></time></xsl:when>
		<xsl:otherwise><span class="muted">&#8212;</span></xsl:otherwise>
	</xsl:choose>
</xsl:template>

<xsl:template name="urlcell">
	<xsl:param name="loc"/>
	<xsl:variable name="rest" select="substring-after($loc,'://')"/>
	<a class="url" href="{$loc}"><span class="host"><xsl:value-of select="substring-before($rest,'/')"/></span><xsl:value-of select="concat('/',substring-after($rest,'/'))"/></a>
</xsl:template>

<xsl:template match="/">
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="robots" content="noindex"/>
<title><xsl:text>%%TLABEL%% &#183; XML sitemap &#183; %%SITE%%</xsl:text></title>
%%CSSLINK%%
</head>
<body>
<header class="top"><div class="wrap top__in">
	<a class="brand" href="%%HOME%%"><img src="%%LOGO%%" alt=""/><span><strong>%%SITE%%</strong><small>XML sitemap &#183; PublishRank</small></span></a>
	<span class="live"><i></i>Live &#183; auto-generated</span>
</div></header>
<main class="wrap">
<xsl:choose>

<!-- ============ PARENT: sitemap index ============ -->
<xsl:when test="sm:sitemapindex">
	<xsl:variable name="files" select="sm:sitemapindex/sm:sitemap"/>
	<xsl:variable name="nfiles" select="count($files)"/>
	<xsl:variable name="groups" select="$files[generate-id()=generate-id(key('by-type',substring-before(substring-after(sm:loc,'%%PREFIX%%'),'-sitemap'))[1])]"/>
	<xsl:variable name="counting" select="$nfiles &lt;= %%LIMIT%%"/>
	<div class="hero">
		<div class="hero__main"><span class="badge badge--lg">XML</span><div><p class="eyebrow">Publication discovery</p><h1>Sitemap index</h1><p>The master list search engines start from. Each card below is one type of content, and each row inside it is a sitemap file you can open.</p></div></div>
		<div class="actions"><button type="button" class="btn btn--primary" data-copy="self">Copy sitemap URL</button><a class="btn" href="?raw=1">View raw XML</a></div>
	</div>
	<section class="stats">
		<div class="stat"><span class="stat__label">Sitemap files</span><strong><xsl:value-of select="$nfiles"/></strong><small>listed in this index</small></div>
		<div class="stat"><span class="stat__label">Content types</span><strong><xsl:value-of select="count($groups)"/></strong><small>articles, news, media and more</small></div>
		<div class="stat"><span class="stat__label">URLs listed</span><strong><xsl:choose><xsl:when test="$counting"><xsl:value-of select="format-number(count(document($files/sm:loc)/sm:urlset/sm:url),'#,##0')"/></xsl:when><xsl:otherwise>&#8212;</xsl:otherwise></xsl:choose></strong><small><xsl:choose><xsl:when test="$counting">across all files</xsl:when><xsl:otherwise>too many files to count here</xsl:otherwise></xsl:choose></small></div>
		<div class="stat"><span class="stat__label">Last update</span><strong style="font-size:18px;line-height:2"><xsl:for-each select="$files/sm:lastmod"><xsl:sort select="." order="descending"/><xsl:if test="position()=1"><xsl:call-template name="when"><xsl:with-param name="v" select="."/></xsl:call-template></xsl:if></xsl:for-each><xsl:if test="not($files/sm:lastmod)">&#8212;</xsl:if></strong><small>newest change in any file</small></div>
	</section>
	<div class="grid">
	<xsl:for-each select="$groups">
		<xsl:variable name="t" select="substring-before(substring-after(sm:loc,'%%PREFIX%%'),'-sitemap')"/>
		<xsl:variable name="members" select="key('by-type',$t)"/>
		<article class="card type">
			<div class="type__head">
				<span class="badge t-{$t}"><xsl:call-template name="abbr"><xsl:with-param name="t" select="$t"/></xsl:call-template></span>
				<div><h3><xsl:call-template name="label"><xsl:with-param name="t" select="$t"/></xsl:call-template></h3><p><xsl:call-template name="desc"><xsl:with-param name="t" select="$t"/></xsl:call-template></p></div>
				<span class="chip"><xsl:value-of select="count($members)"/><xsl:choose><xsl:when test="count($members)=1"> file</xsl:when><xsl:otherwise> files</xsl:otherwise></xsl:choose></span>
			</div>
			<xsl:if test="$t='news'"><p class="note">Only articles from the last %%WINDOW%% hours are listed here, as Google News requires.</p></xsl:if>
			<ul class="files">
			<xsl:for-each select="$members">
				<xsl:variable name="tail" select="substring-after(substring-after(sm:loc,'%%PREFIX%%'),'-sitemap')"/>
				<xsl:variable name="no" select="translate(substring-before($tail,'.xml'),'-','')"/>
				<li>
					<a href="{sm:loc}"><xsl:choose><xsl:when test="$no != ''">Part <xsl:value-of select="$no"/></xsl:when><xsl:when test="$t != ''">Part 1</xsl:when><xsl:otherwise><xsl:value-of select="sm:loc"/></xsl:otherwise></xsl:choose></a>
					<xsl:if test="$counting"><span class="n"><xsl:choose><xsl:when test="document(sm:loc)/sm:urlset"><xsl:value-of select="format-number(count(document(sm:loc)/sm:urlset/sm:url),'#,##0')"/><span class="muted"><xsl:choose><xsl:when test="count(document(sm:loc)/sm:urlset/sm:url)=1"> URL</xsl:when><xsl:otherwise> URLs</xsl:otherwise></xsl:choose></span></xsl:when><xsl:otherwise><span class="muted">open</span></xsl:otherwise></xsl:choose></span></xsl:if>
					<span class="when"><xsl:call-template name="when"><xsl:with-param name="v" select="sm:lastmod"/></xsl:call-template></span>
				</li>
			</xsl:for-each>
			</ul>
		</article>
	</xsl:for-each>
	</div>
	<section class="card tip">
		<div><p><strong>Tell search engines about it.</strong> Submit this one address in Google Search Console and Bing Webmaster Tools; it also appears in your robots.txt automatically.</p><code>%%INDEXURL%%</code></div>
		<a class="btn" href="%%ROBOTS%%">Open robots.txt</a>
	</section>
</xsl:when>

<!-- ============ CHILD: URL set ============ -->
<xsl:when test="sm:urlset">
	<xsl:variable name="urls" select="sm:urlset/sm:url"/>
	<xsl:variable name="n" select="count($urls)"/>
	<xsl:variable name="isnews" select="count($urls/news:news) &gt; 0 or '%%TYPE%%'='news'"/>
	<xsl:variable name="hasimg" select="count($urls/image:image) &gt; 0"/>
	<xsl:variable name="hasvid" select="count($urls/video:video) &gt; 0"/>
	<xsl:variable name="cap"><xsl:choose><xsl:when test="$isnews">1000</xsl:when><xsl:otherwise>50000</xsl:otherwise></xsl:choose></xsl:variable>
	<xsl:variable name="nolm" select="count($urls[not(sm:lastmod)])"/>
	<xsl:variable name="nohttps" select="count($urls[not(starts-with(sm:loc,'https://'))])"/>
	<div class="hero">
		<div class="hero__main"><span class="badge badge--lg t-%%TYPE%%">%%TABBR%%</span><div>
			<p class="crumbs"><a href="%%INDEX%%">All sitemaps</a> &#8250; %%TLABEL%%<xsl:if test="%%PART%% &gt; 1"> &#8250; Part %%PART%%</xsl:if></p>
			<h1>%%TLABEL%% sitemap<xsl:if test="%%PART%% &gt; 1"><xsl:text> &#183; part %%PART%%</xsl:text></xsl:if></h1>
			<p>%%TDESC%%. Search engines read the plain XML behind this page; this view is only for people.</p></div></div>
		<div class="actions"><a class="btn" href="%%INDEX%%">&#8592; Sitemap index</a><button type="button" class="btn btn--primary" data-copy="self">Copy URL</button><a class="btn" href="?raw=1">View raw XML</a></div>
	</div>
	<nav class="sibs" aria-label="Other sitemaps"><xsl:for-each select="document('%%INDEXURL%%')/sm:sitemapindex/sm:sitemap[contains(sm:loc,'-sitemap-1.xml') or not(contains(sm:loc,'-sitemap-'))]"><xsl:variable name="st" select="substring-before(substring-after(sm:loc,'%%PREFIX%%'),'-sitemap')"/><a href="{sm:loc}"><xsl:if test="$st='%%TYPE%%'"><xsl:attribute name="class">is-on</xsl:attribute></xsl:if><xsl:call-template name="label"><xsl:with-param name="t" select="$st"/></xsl:call-template></a></xsl:for-each></nav>
	<section class="stats">
		<div class="stat"><span class="stat__label">URLs in this file</span><strong><xsl:value-of select="format-number($n,'#,##0')"/></strong><small>limit <xsl:value-of select="format-number($cap,'#,##0')"/> per file</small></div>
		<div class="stat"><span class="stat__label">Latest update</span><strong style="font-size:18px;line-height:2"><xsl:for-each select="$urls/sm:lastmod"><xsl:sort select="." order="descending"/><xsl:if test="position()=1"><xsl:call-template name="when"><xsl:with-param name="v" select="."/></xsl:call-template></xsl:if></xsl:for-each><xsl:if test="not($urls/sm:lastmod)">&#8212;</xsl:if></strong><small>newest lastmod</small></div>
		<xsl:if test="$isnews"><div class="stat"><span class="stat__label">News window</span><strong>%%WINDOW%% h</strong><small>articles older than this drop out</small></div></xsl:if>
		<xsl:if test="$hasimg"><div class="stat"><span class="stat__label">Images</span><strong><xsl:value-of select="format-number(count($urls/image:image),'#,##0')"/></strong><small>on <xsl:value-of select="count($urls[image:image])"/> pages</small></div></xsl:if>
		<xsl:if test="$hasvid"><div class="stat"><span class="stat__label">Videos</span><strong><xsl:value-of select="format-number(count($urls/video:video),'#,##0')"/></strong><small>on <xsl:value-of select="count($urls[video:video])"/> pages</small></div></xsl:if>
	</section>
	<ul class="checks">
		<xsl:choose><xsl:when test="$n &gt; $cap"><li class="bad">Over the <xsl:value-of select="format-number($cap,'#,##0')"/> URL limit &#8212; split this file</li></xsl:when><xsl:when test="$n = 0"><li class="info">No eligible URLs right now</li></xsl:when><xsl:otherwise><li class="ok">Within the URL limit</li></xsl:otherwise></xsl:choose>
		<xsl:choose><xsl:when test="$n &gt; 0 and $nolm = 0"><li class="ok">Every URL has a last-modified date</li></xsl:when><xsl:when test="$nolm &gt; 0"><li class="warn"><xsl:value-of select="$nolm"/> URLs have no last-modified date</li></xsl:when></xsl:choose>
		<xsl:choose><xsl:when test="$nohttps &gt; 0"><li class="warn"><xsl:value-of select="$nohttps"/> URLs are not HTTPS</li></xsl:when><xsl:when test="$n &gt; 0"><li class="ok">All URLs use HTTPS</li></xsl:when></xsl:choose>
		<xsl:if test="$isnews and $n &gt; 0"><xsl:choose><xsl:when test="count($urls[not(news:news/news:title)]) = 0"><li class="ok">Every entry has a news title and date</li></xsl:when><xsl:otherwise><li class="bad">Some entries are missing news data</li></xsl:otherwise></xsl:choose></xsl:if>
	</ul>
	<section class="card">
		<xsl:choose>
		<xsl:when test="$n &gt; 0">
		<div class="tools"><input type="search" id="pr-filter" placeholder="Filter URLs and titles&#8230;" aria-label="Filter URLs"/><label class="muted">Rows <select id="pr-size"><option>50</option><option selected="selected">100</option><option>250</option><option>1000</option></select></label><span class="muted" id="pr-count"></span></div>
		<div class="table-wrap"><table><thead><tr>
			<th class="num">#</th>
			<xsl:if test="$isnews"><th>Headline</th></xsl:if>
			<th>URL</th>
			<xsl:if test="$isnews"><th class="hide-sm">Published</th></xsl:if>
			<xsl:if test="$hasimg"><th class="hide-sm">Images</th></xsl:if>
			<xsl:if test="$hasvid"><th class="hide-sm">Video</th></xsl:if>
			<th>Updated</th>
		</tr></thead><tbody id="pr-rows">
		<xsl:for-each select="$urls"><tr>
			<td class="num"><xsl:value-of select="position()"/></td>
			<xsl:if test="$isnews"><td><span class="headline"><xsl:value-of select="news:news/news:title"/></span></td></xsl:if>
			<td><xsl:call-template name="urlcell"><xsl:with-param name="loc" select="sm:loc"/></xsl:call-template></td>
			<xsl:if test="$isnews"><td class="when hide-sm"><xsl:call-template name="when"><xsl:with-param name="v" select="news:news/news:publication_date"/></xsl:call-template></td></xsl:if>
			<xsl:if test="$hasimg"><td class="hide-sm"><xsl:choose><xsl:when test="image:image"><span class="thumbs"><xsl:for-each select="image:image[position() &lt;= 3]"><img loading="lazy" alt="" src="{image:loc}" title="{image:title}"/></xsl:for-each><xsl:if test="count(image:image) &gt; 3"><span class="more">+<xsl:value-of select="count(image:image) - 3"/></span></xsl:if></span></xsl:when><xsl:otherwise><span class="muted">&#8212;</span></xsl:otherwise></xsl:choose></td></xsl:if>
			<xsl:if test="$hasvid"><td class="hide-sm"><xsl:choose><xsl:when test="video:video"><span class="vwrap"><img class="vthumb" loading="lazy" alt="" src="{video:video[1]/video:thumbnail_loc}"/><span><xsl:value-of select="video:video[1]/video:title"/><xsl:if test="video:video[1]/video:duration"><br/><span class="dur"><xsl:value-of select="floor(video:video[1]/video:duration div 60)"/>:<xsl:value-of select="format-number(video:video[1]/video:duration mod 60,'00')"/></span></xsl:if></span></span></xsl:when><xsl:otherwise><span class="muted">&#8212;</span></xsl:otherwise></xsl:choose></td></xsl:if>
			<td class="when"><xsl:call-template name="when"><xsl:with-param name="v" select="sm:lastmod"/></xsl:call-template></td>
		</tr></xsl:for-each>
		</tbody></table></div>
		<div class="pager"><span id="pr-range"></span><span><button type="button" class="btn" id="pr-prev">&#8592; Previous</button> <button type="button" class="btn" id="pr-next">Next &#8594;</button></span></div>
		</xsl:when>
		<xsl:otherwise><div class="empty"><b>Nothing to list yet</b>This sitemap is valid but empty. URLs appear here as soon as matching content is published<xsl:if test="$isnews"> within the last %%WINDOW%% hours</xsl:if>.</div></xsl:otherwise>
		</xsl:choose>
	</section>
</xsl:when>

<xsl:otherwise><section class="card"><div class="empty"><b>Sitemap data unavailable</b>The XML document did not contain a recognised sitemap index or URL set.</div></section></xsl:otherwise>
</xsl:choose>
</main>
<p class="foot">Generated by PublishRank &#183; search engines read the raw XML, this readable view is for people.</p>
%%JSLINK%%
</body>
</html>
</xsl:template>
</xsl:stylesheet>
XSL;
	}
}
