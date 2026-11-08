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
		$arg = sanitize_key( $type ) . ',' . max( 1, absint( $part ) ) . ',' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '1' ) . ( defined( 'PUBLISHRANK_SEO_PATH' ) ? '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/css/sitemap.css' ) : '' );
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
				'desc'  => __( 'Your published posts and news stories', 'publishrank' ),
				'abbr'  => 'AR',
			),
			'page'       => array(
				'label' => __( 'Pages', 'publishrank' ),
				'desc'  => __( 'Static pages such as About and Contact', 'publishrank' ),
				'abbr'  => 'PG',
			),
			'cpt'        => array(
				'label' => __( 'Custom content', 'publishrank' ),
				'desc'  => __( 'Extra content types added by your theme or plugins', 'publishrank' ),
				'abbr'  => 'CT',
			),
			'taxonomy'   => array(
				'label' => __( 'Categories & tags', 'publishrank' ),
				'desc'  => __( 'Category, tag and other topic archive pages', 'publishrank' ),
				'abbr'  => 'TP',
			),
			'author'     => array(
				'label' => __( 'Authors', 'publishrank' ),
				'desc'  => __( 'Profile pages for people who have published', 'publishrank' ),
				'abbr'  => 'AU',
			),
			'attachment' => array(
				'label' => __( 'Attachment pages', 'publishrank' ),
				'desc'  => __( 'Individual media attachment pages', 'publishrank' ),
				'abbr'  => 'MF',
			),
			'news'       => array(
				'label' => __( 'Google News', 'publishrank' ),
				'desc'  => __( 'Your newest stories, in the format Google News reads', 'publishrank' ),
				'abbr'  => 'GN',
			),
			'image'      => array(
				'label' => __( 'Images', 'publishrank' ),
				'desc'  => __( 'Images used in your content, for Google Images and Discover', 'publishrank' ),
				'abbr'  => 'IM',
			),
			'video'      => array(
				'label' => __( 'Videos', 'publishrank' ),
				'desc'  => __( 'Videos embedded or hosted in your content', 'publishrank' ),
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
			'desc'  => __( 'The main sitemap that links to every other sitemap file on this site', 'publishrank' ),
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
			'%%LOGO%%'     => esc_url( Branding::image( Branding::LOGO_FILE ) ),
			'%%VERSION%%'  => esc_html( Branding::version() ),
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
	 * its stylesheet and script are the bundled assets/css/sitemap.css and assets/js/sitemap.js files, embedded inline.
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

<!-- Plain-language singular / plural. -->
<xsl:template name="plural">
	<xsl:param name="n"/><xsl:param name="one"/><xsl:param name="many"/>
	<xsl:choose><xsl:when test="number($n) = 1"><xsl:value-of select="$one"/></xsl:when><xsl:otherwise><xsl:value-of select="$many"/></xsl:otherwise></xsl:choose>
</xsl:template>

<!-- Line icons (24px grid) for each content type and for the interface. -->
<xsl:template name="icon">
	<xsl:param name="t"/>
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
	<xsl:choose>
		<xsl:when test="$t='post'"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6M9 9h2"/></xsl:when>
		<xsl:when test="$t='page'"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></xsl:when>
		<xsl:when test="$t='cpt'"><path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/></xsl:when>
		<xsl:when test="$t='taxonomy'"><path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/></xsl:when>
		<xsl:when test="$t='author'"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></xsl:when>
		<xsl:when test="$t='attachment'"><path d="m21 11-8.5 8.5a5 5 0 0 1-7-7L14 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 7"/></xsl:when>
		<xsl:when test="$t='news'"><path d="M4 5h13v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2z"/><path d="M17 9h3v10a2 2 0 0 1-4 0"/><path d="M8 9h5M8 13h5M8 17h3"/></xsl:when>
		<xsl:when test="$t='image'"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/></xsl:when>
		<xsl:when test="$t='video'"><rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 10 5-3v10l-5-3z"/></xsl:when>
		<xsl:when test="$t='i-link'"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></xsl:when>
		<xsl:when test="$t='i-layers'"><path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/></xsl:when>
		<xsl:when test="$t='i-files'"><path d="M15 3H8a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V7z"/><path d="M3 8v11a2 2 0 0 0 2 2h9"/></xsl:when>
		<xsl:when test="$t='i-clock'"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></xsl:when>
		<xsl:when test="$t='i-pen'"><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></xsl:when>
		<xsl:when test="$t='i-refresh'"><path d="M20 11a8 8 0 0 0-14.6-4.5L4 8"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.6 4.5L20 16"/><path d="M20 20v-4h-4"/></xsl:when>
		<xsl:when test="$t='i-search'"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></xsl:when>
		<xsl:when test="$t='i-arrow'"><path d="M5 12h14M13 6l6 6-6 6"/></xsl:when>
		<xsl:when test="$t='i-out'"><path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></xsl:when>
		<xsl:when test="$t='i-info'"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></xsl:when>
		<xsl:otherwise><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></xsl:otherwise>
	</xsl:choose>
	</svg>
</xsl:template>

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
	<a class="brand" href="%%HOME%%"><img src="%%LOGO%%" alt=""/><span><strong>%%SITE%%</strong><small>XML sitemap &#183; PublishRank v%%VERSION%%</small></span></a>
	<span class="live"><i></i>Live &#183; updates automatically</span>
</div></header>
<main class="wrap">
<xsl:choose>

<!-- ============ PARENT: sitemap index ============ -->
<xsl:when test="sm:sitemapindex">
	<xsl:variable name="files" select="sm:sitemapindex/sm:sitemap"/>
	<xsl:variable name="nfiles" select="count($files)"/>
	<xsl:variable name="groups" select="$files[generate-id()=generate-id(key('by-type',substring-before(substring-after(sm:loc,'%%PREFIX%%'),'-sitemap'))[1])]"/>
	<xsl:variable name="ntypes" select="count($groups)"/>
	<xsl:variable name="counting" select="$nfiles &lt;= %%LIMIT%%"/>
	<xsl:variable name="totalurls"><xsl:choose><xsl:when test="$counting"><xsl:value-of select="count(document($files/sm:loc)/sm:urlset/sm:url)"/></xsl:when><xsl:otherwise>0</xsl:otherwise></xsl:choose></xsl:variable>

	<section class="intro" aria-labelledby="pr-title">
		<div class="intro__text">
			<p class="eyebrow">Sitemap index &#183; %%SITE%%</p>
			<h1 id="pr-title">Your sitemap</h1>
			<p class="lead">This is the main sitemap for your site. Search engines open this one address and follow it to every article, page and image listed below. PublishRank keeps it up to date for you &#8212; there is nothing to edit by hand.</p>
		</div>
		<div class="addr">
			<p class="addr__label">Submit this address to search engines</p>
			<div class="addr__row"><code>%%INDEXURL%%</code><button type="button" class="btn btn--primary" data-copy="self">Copy</button></div>
			<p class="addr__links">
				<a href="https://search.google.com/search-console/sitemaps" target="_blank" rel="noopener noreferrer">Google Search Console<xsl:call-template name="icon"><xsl:with-param name="t" select="'i-out'"/></xsl:call-template></a>
				<a href="https://www.bing.com/webmasters/sitemaps" target="_blank" rel="noopener noreferrer">Bing Webmaster Tools<xsl:call-template name="icon"><xsl:with-param name="t" select="'i-out'"/></xsl:call-template></a>
				<a href="?raw=1">View raw XML</a>
			</p>
		</div>
	</section>

	<ol class="how" aria-label="How your sitemap works">
		<li><span class="how__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-pen'"/></xsl:call-template></span><div><strong>1. You publish</strong><p>New articles, pages and images are added as soon as they go live.</p></div></li>
		<li><span class="how__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-refresh'"/></xsl:call-template></span><div><strong>2. PublishRank updates</strong><p>The files below refresh automatically, sorted by type of content.</p></div></li>
		<li><span class="how__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-search'"/></xsl:call-template></span><div><strong>3. Search engines read it</strong><p>Google, Bing and others use it to discover what you published.</p></div></li>
	</ol>

	<section class="stats" aria-label="Sitemap totals">
		<div class="stat"><span class="stat__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-link'"/></xsl:call-template></span><div><strong><xsl:choose><xsl:when test="$counting"><xsl:value-of select="format-number($totalurls,'#,##0')"/></xsl:when><xsl:otherwise>&#8212;</xsl:otherwise></xsl:choose></strong><span class="stat__label"><xsl:choose><xsl:when test="$counting">Addresses listed</xsl:when><xsl:otherwise>Too many files to count here</xsl:otherwise></xsl:choose></span></div></div>
		<div class="stat"><span class="stat__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-layers'"/></xsl:call-template></span><div><strong><xsl:value-of select="$ntypes"/></strong><span class="stat__label"><xsl:call-template name="plural"><xsl:with-param name="n" select="$ntypes"/><xsl:with-param name="one" select="'Type of content'"/><xsl:with-param name="many" select="'Types of content'"/></xsl:call-template></span></div></div>
		<div class="stat"><span class="stat__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-files'"/></xsl:call-template></span><div><strong><xsl:value-of select="$nfiles"/></strong><span class="stat__label"><xsl:call-template name="plural"><xsl:with-param name="n" select="$nfiles"/><xsl:with-param name="one" select="'Sitemap file'"/><xsl:with-param name="many" select="'Sitemap files'"/></xsl:call-template></span></div></div>
		<div class="stat"><span class="stat__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-clock'"/></xsl:call-template></span><div><strong class="stat__when"><xsl:for-each select="$files/sm:lastmod"><xsl:sort select="." order="descending"/><xsl:if test="position()=1"><xsl:call-template name="when"><xsl:with-param name="v" select="."/></xsl:call-template></xsl:if></xsl:for-each><xsl:if test="not($files/sm:lastmod)">&#8212;</xsl:if></strong><span class="stat__label">Last change</span></div></div>
	</section>

	<section class="card list" aria-labelledby="pr-list-title">
		<div class="card__head">
			<div><h2 id="pr-list-title">What&#8217;s in your sitemap</h2><p>One row per type of content. Open a row to see every address it lists.</p></div>
			<span class="chip"><xsl:value-of select="$ntypes"/><xsl:text> </xsl:text><xsl:call-template name="plural"><xsl:with-param name="n" select="$ntypes"/><xsl:with-param name="one" select="'type'"/><xsl:with-param name="many" select="'types'"/></xsl:call-template></span>
		</div>
		<div class="rows__head" aria-hidden="true"><span></span><span>Content</span><span>Addresses</span><span>Share</span><span>Last change</span><span></span></div>
		<ul class="rows">
		<xsl:for-each select="$groups">
			<xsl:variable name="t" select="substring-before(substring-after(sm:loc,'%%PREFIX%%'),'-sitemap')"/>
			<xsl:variable name="members" select="key('by-type',$t)"/>
			<xsl:variable name="nm" select="count($members)"/>
			<xsl:variable name="n"><xsl:choose><xsl:when test="$counting"><xsl:value-of select="count(document($members/sm:loc)/sm:urlset/sm:url)"/></xsl:when><xsl:otherwise>-1</xsl:otherwise></xsl:choose></xsl:variable>
			<xsl:variable name="share"><xsl:choose><xsl:when test="$counting and $totalurls &gt; 0"><xsl:value-of select="round($n div $totalurls * 100)"/></xsl:when><xsl:otherwise>0</xsl:otherwise></xsl:choose></xsl:variable>
			<xsl:variable name="empty" select="$counting and number($n) = 0"/>
			<li id="type-{$t}">
				<xsl:attribute name="class">row t-<xsl:value-of select="$t"/><xsl:if test="$empty"> is-empty</xsl:if></xsl:attribute>
				<span class="row__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="$t"/></xsl:call-template></span>
				<div class="row__main">
					<h3><a href="{$members[1]/sm:loc}"><xsl:call-template name="label"><xsl:with-param name="t" select="$t"/></xsl:call-template></a></h3>
					<p><xsl:call-template name="desc"><xsl:with-param name="t" select="$t"/></xsl:call-template></p>
					<xsl:if test="$t='news'"><p class="row__note">Only stories from the last %%WINDOW%% hours are listed, as Google News requires.</p></xsl:if>
					<xsl:if test="$empty"><p class="row__note row__note--soft">Empty for now &#8212; that is fine. It fills up automatically when matching content is published.</p></xsl:if>
					<xsl:if test="$nm &gt; 1"><p class="row__parts">Split into <xsl:value-of select="$nm"/> files: <xsl:for-each select="$members"><xsl:variable name="tail" select="substring-after(substring-after(sm:loc,'%%PREFIX%%'),'-sitemap')"/><xsl:variable name="no" select="translate(substring-before($tail,'.xml'),'-','')"/><a href="{sm:loc}">Part <xsl:choose><xsl:when test="$no != ''"><xsl:value-of select="$no"/></xsl:when><xsl:otherwise><xsl:value-of select="position()"/></xsl:otherwise></xsl:choose></a></xsl:for-each></p></xsl:if>
				</div>
				<div class="row__count">
					<xsl:choose>
						<xsl:when test="$counting"><strong><xsl:value-of select="format-number($n,'#,##0')"/></strong><span><xsl:call-template name="plural"><xsl:with-param name="n" select="$n"/><xsl:with-param name="one" select="'address'"/><xsl:with-param name="many" select="'addresses'"/></xsl:call-template></span></xsl:when>
						<xsl:otherwise><strong><xsl:value-of select="$nm"/></strong><span><xsl:call-template name="plural"><xsl:with-param name="n" select="$nm"/><xsl:with-param name="one" select="'file'"/><xsl:with-param name="many" select="'files'"/></xsl:call-template></span></xsl:otherwise>
					</xsl:choose>
				</div>
				<div class="row__share">
					<xsl:if test="$counting"><span class="meter"><i style="width:{$share}%"></i></span><small><xsl:value-of select="$share"/>% of all</small></xsl:if>
				</div>
				<div class="row__when"><xsl:for-each select="$members/sm:lastmod"><xsl:sort select="." order="descending"/><xsl:if test="position()=1"><xsl:call-template name="when"><xsl:with-param name="v" select="."/></xsl:call-template></xsl:if></xsl:for-each><xsl:if test="not($members/sm:lastmod)"><span class="muted">&#8212;</span></xsl:if></div>
				<a class="btn btn--sm row__open" href="{$members[1]/sm:loc}"><xsl:attribute name="aria-label">Open the <xsl:call-template name="label"><xsl:with-param name="t" select="$t"/></xsl:call-template> sitemap</xsl:attribute>Open<xsl:call-template name="icon"><xsl:with-param name="t" select="'i-arrow'"/></xsl:call-template></a>
			</li>
		</xsl:for-each>
		</ul>
	</section>

	<section class="card good" aria-labelledby="pr-good-title">
		<h2 id="pr-good-title"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-info'"/></xsl:call-template>Good to know</h2>
		<ul>
			<li><strong>Submit just one address.</strong> Every file above is found through this sitemap index. It is also listed in your <a href="%%ROBOTS%%">robots.txt</a> automatically.</li>
			<li><strong>Empty rows are normal.</strong> A type of content with nothing published yet stays empty until you publish something that belongs there.</li>
			<li><strong>This page is for people.</strong> Search engines read the plain XML version behind it. Use <a href="?raw=1">View raw XML</a> to see exactly what they receive.</li>
		</ul>
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
	<section class="intro intro--child t-%%TYPE%%" aria-labelledby="pr-title">
		<div class="intro__text">
			<p class="crumbs"><a href="%%INDEX%%">&#8592; Your sitemap</a> &#8250; %%TLABEL%%<xsl:if test="%%PART%% &gt; 1"> &#8250; Part %%PART%%</xsl:if></p>
			<div class="intro__title"><span class="row__ico row__ico--lg"><xsl:call-template name="icon"><xsl:with-param name="t" select="'%%TYPE%%'"/></xsl:call-template></span><h1 id="pr-title">%%TLABEL%%<xsl:if test="%%PART%% &gt; 1"><xsl:text> &#183; part %%PART%%</xsl:text></xsl:if></h1></div>
			<p class="lead">%%TDESC%%. Every address search engines can find in this file is listed below.</p>
		</div>
		<div class="actions"><button type="button" class="btn btn--primary" data-copy="self">Copy this file&#8217;s URL</button><a class="btn" href="?raw=1">View raw XML</a></div>
	</section>
	<nav class="sibs" aria-label="Other sitemaps"><a href="%%INDEX%%">All</a><xsl:for-each select="document('%%INDEXURL%%')/sm:sitemapindex/sm:sitemap[contains(sm:loc,'-sitemap-1.xml') or not(contains(sm:loc,'-sitemap-'))]"><xsl:variable name="st" select="substring-before(substring-after(sm:loc,'%%PREFIX%%'),'-sitemap')"/><a href="{sm:loc}"><xsl:if test="$st='%%TYPE%%'"><xsl:attribute name="class">is-on</xsl:attribute><xsl:attribute name="aria-current">page</xsl:attribute></xsl:if><xsl:call-template name="label"><xsl:with-param name="t" select="$st"/></xsl:call-template></a></xsl:for-each></nav>
	<section class="stats" aria-label="File totals">
		<div class="stat"><span class="stat__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-link'"/></xsl:call-template></span><div><strong><xsl:value-of select="format-number($n,'#,##0')"/></strong><span class="stat__label"><xsl:call-template name="plural"><xsl:with-param name="n" select="$n"/><xsl:with-param name="one" select="'Address in this file'"/><xsl:with-param name="many" select="'Addresses in this file'"/></xsl:call-template> &#183; limit <xsl:value-of select="format-number($cap,'#,##0')"/></span></div></div>
		<div class="stat"><span class="stat__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'i-clock'"/></xsl:call-template></span><div><strong class="stat__when"><xsl:for-each select="$urls/sm:lastmod"><xsl:sort select="." order="descending"/><xsl:if test="position()=1"><xsl:call-template name="when"><xsl:with-param name="v" select="."/></xsl:call-template></xsl:if></xsl:for-each><xsl:if test="not($urls/sm:lastmod)">&#8212;</xsl:if></strong><span class="stat__label">Latest change</span></div></div>
		<xsl:if test="$isnews"><div class="stat"><span class="stat__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'news'"/></xsl:call-template></span><div><strong>%%WINDOW%% hours</strong><span class="stat__label">Older stories drop out</span></div></div></xsl:if>
		<xsl:if test="$hasimg"><div class="stat"><span class="stat__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'image'"/></xsl:call-template></span><div><strong><xsl:value-of select="format-number(count($urls/image:image),'#,##0')"/></strong><span class="stat__label">Images on <xsl:value-of select="count($urls[image:image])"/> pages</span></div></div></xsl:if>
		<xsl:if test="$hasvid"><div class="stat"><span class="stat__ico"><xsl:call-template name="icon"><xsl:with-param name="t" select="'video'"/></xsl:call-template></span><div><strong><xsl:value-of select="format-number(count($urls/video:video),'#,##0')"/></strong><span class="stat__label">Videos on <xsl:value-of select="count($urls[video:video])"/> pages</span></div></div></xsl:if>
	</section>
	<ul class="checks" aria-label="Quick checks">
		<xsl:choose><xsl:when test="$n &gt; $cap"><li class="bad">Over the <xsl:value-of select="format-number($cap,'#,##0')"/> address limit &#8212; split this file</li></xsl:when><xsl:when test="$n = 0"><li class="info">Nothing to list right now</li></xsl:when><xsl:otherwise><li class="ok">Within the address limit</li></xsl:otherwise></xsl:choose>
		<xsl:choose><xsl:when test="$n &gt; 0 and $nolm = 0"><li class="ok">Every address has a last-changed date</li></xsl:when><xsl:when test="$nolm &gt; 0"><li class="warn"><xsl:value-of select="$nolm"/> without a last-changed date</li></xsl:when></xsl:choose>
		<xsl:choose><xsl:when test="$nohttps &gt; 0"><li class="warn"><xsl:value-of select="$nohttps"/> not using HTTPS</li></xsl:when><xsl:when test="$n &gt; 0"><li class="ok">All addresses use HTTPS</li></xsl:when></xsl:choose>
		<xsl:if test="$isnews and $n &gt; 0"><xsl:choose><xsl:when test="count($urls[not(news:news/news:title)]) = 0"><li class="ok">Every story has a news title and date</li></xsl:when><xsl:otherwise><li class="bad">Some stories are missing news details</li></xsl:otherwise></xsl:choose></xsl:if>
	</ul>
	<section class="card">
		<xsl:choose>
		<xsl:when test="$n &gt; 0">
		<div class="tools"><input type="search" id="pr-filter" placeholder="Filter addresses and headlines&#8230;" aria-label="Filter addresses"/><label class="muted">Rows <select id="pr-size"><option>50</option><option selected="selected">100</option><option>250</option><option>1000</option></select></label><span class="muted" id="pr-count"></span></div>
		<div class="table-wrap"><table><thead><tr>
			<th class="num">#</th>
			<xsl:if test="$isnews"><th>Headline</th></xsl:if>
			<th>Address</th>
			<xsl:if test="$isnews"><th class="hide-sm">Published</th></xsl:if>
			<xsl:if test="$hasimg"><th class="hide-sm">Images</th></xsl:if>
			<xsl:if test="$hasvid"><th class="hide-sm">Video</th></xsl:if>
			<th>Last change</th>
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
		<xsl:otherwise><div class="empty"><span class="row__ico row__ico--lg t-%%TYPE%%"><xsl:call-template name="icon"><xsl:with-param name="t" select="'%%TYPE%%'"/></xsl:call-template></span><b>Nothing to list yet</b>This file is valid but empty. Addresses appear here automatically as soon as matching content is published<xsl:if test="$isnews"> within the last %%WINDOW%% hours</xsl:if>.</div></xsl:otherwise>
		</xsl:choose>
	</section>
</xsl:when>

<xsl:otherwise><section class="card"><div class="empty"><b>Sitemap data unavailable</b>The XML document did not contain a recognised sitemap index or URL set.</div></section></xsl:otherwise>
</xsl:choose>
</main>
<p class="foot">Generated by PublishRank v%%VERSION%% &#183; search engines read the raw XML; this readable view is for people.</p>
%%JSLINK%%
</body>
</html>
</xsl:template>
</xsl:stylesheet>
XSL;
	}
}
