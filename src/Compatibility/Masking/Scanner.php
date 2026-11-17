<?php
/**
 * Theme fingerprint scanner.
 *
 * Opens real pages of the site twice — exactly as the theme prints them (one-time scan token, cleanup and masking
 * bypassed) and as visitors receive them — plus every same-origin stylesheet and script they load, the public REST
 * index and a direct probe of the theme folder. Each place a theme can be recognised from is counted separately so
 * the dashboard can explain what is left and why.
 *
 * Fetching rules: WordPress HTTP API, TLS verification on (filterable for local development only), same-host URLs
 * only, redirects followed manually and only to the same host, bounded timeouts and response sizes.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility\Masking;

use PublishRankSEO\Compatibility\ThemeSeo;

defined( 'ABSPATH' ) || exit;

final class Scanner {
	const RESULT     = 'publishrank_theme_scan_result';
	const MAX_BYTES  = 4194304;
	const MAX_ASSETS = 40;

	/** Fingerprint categories in display order. @return array<string,array{0:string,1:string}> */
	public static function categories() {
		return array(
			'paths'      => array( __( 'Theme folder addresses', 'publishrank' ), __( 'Links to /wp-content/themes/<theme>/ name the theme directly. Fixed by the neutral address.', 'publishrank' ) ),
			'generator'  => array( __( 'Generator tags', 'publishrank' ), __( 'Some themes announce themselves in a generator meta tag. Removed whenever masking is on.', 'publishrank' ) ),
			'body'       => array( __( 'Theme body classes', 'publishrank' ), __( 'WordPress adds wp-theme-<slug> to <body>. Removed by "Remove theme name from body classes".', 'publishrank' ) ),
			'attrs'      => array( __( 'Theme class names and IDs', 'publishrank' ), __( 'Class, ID and data attributes such as "ast-container" or "kadence-header". Rewritten consistently at Maximum level.', 'publishrank' ) ),
			'inline_css' => array( __( 'Inline styles', 'publishrank' ), __( 'Theme CSS printed inside <style> tags (Customizer output). Rewritten at Maximum level.', 'publishrank' ) ),
			'inline_js'  => array( __( 'Inline scripts and globals', 'publishrank' ), __( 'JavaScript objects such as "astra" or "kadenceConfig". Rewritten at Maximum level together with the theme scripts.', 'publishrank' ) ),
			'comments'   => array( __( 'HTML comments', 'publishrank' ), __( 'Template comments that name the theme. Rewritten at Maximum level, or remove all comments on the SEO cleanup tab.', 'publishrank' ) ),
			'credits'    => array( __( 'Footer credit links', 'publishrank' ), __( 'Links such as "Powered by <theme>". Removed by "Remove theme credit links".', 'publishrank' ) ),
			'assets'     => array( __( 'Stylesheet and script contents', 'publishrank' ), __( 'Theme names inside the CSS/JS files the page loads. Rewritten at Maximum level; a file loaded from a folder outside the masked scope keeps its names (add it under Extra identifiers or Extended scope).', 'publishrank' ) ),
			'headers'    => array( __( 'Stylesheet header', 'publishrank' ), __( 'The "Theme Name / Author / Version" comment at the top of style.css. Removed by "Hide the theme header".', 'publishrank' ) ),
			'sourcemaps' => array( __( 'Source maps', 'publishrank' ), __( 'sourceMappingURL references expose original file names and developer folders. Stripped (and .map files refused) by the source map policy.', 'publishrank' ) ),
			'rest'       => array( __( 'Public REST index', 'publishrank' ), __( 'Theme namespaces listed at /wp-json/. Hidden from visitors by "Hide theme REST namespaces".', 'publishrank' ) ),
		);
	}

	/** @return array{words:string,pre:string} detection regexes built from the real identifiers. */
	private static function detectors( array $cfg ) {
		$w = array();
		$p = array();
		foreach ( (array) $cfg['pairs'] as $pair ) {
			if ( 'prefix' === $pair[2] ) {
				$p[] = preg_quote( $pair[0], '#' );
			} else {
				$w[] = preg_quote( $pair[0], '#' );
			}
		}
		return array(
			'words' => $w ? '#(?<![A-Za-z0-9])(?:' . implode( '|', $w ) . ')(?![a-z0-9])#' : '',
			'pre'   => $p ? '#(?<![A-Za-z0-9])(?:' . implode( '|', $p ) . ')(?=[-_][A-Za-z0-9])#' : '',
		);
	}

	private static function count( $text, array $d, array &$examples = null ) {
		$n = 0;
		foreach ( array( 'words', 'pre' ) as $k ) {
			if ( '' === $d[ $k ] ) {
				continue;
			}
			if ( preg_match_all( $d[ $k ], (string) $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$n += count( $m[0] );
				if ( null !== $examples && count( $examples ) < 6 ) {
					foreach ( array_slice( $m[0], 0, 3 ) as $hit ) {
						$examples[] = trim( (string) substr( (string) $text, max( 0, $hit[1] - 40 ), 90 ) );
					}
				}
			}
		}
		return $n;
	}

	/** Same-host check. */
	private static function same_host( $url ) {
		$h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $h && strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $h;
	}

	/** Fetch one same-host URL. @return array{status:int,body:string,headers:array}|\WP_Error */
	public static function fetch( $url, $raw = false ) {
		if ( ! self::same_host( $url ) ) {
			return new \WP_Error( 'publishrank_scan_host', __( 'Only addresses on this site can be scanned.', 'publishrank' ) );
		}
		$url  = $raw ? add_query_arg( ThemeSeo::SCAN_ARG, ThemeSeo::issue_scan_token(), $url ) : add_query_arg( 'prts', wp_rand( 1000, 999999 ), $url );
		$args = array(
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_BYTES,
			'sslverify'           => (bool) apply_filters( 'publishrank_theme_scan_sslverify', true ),
			'reject_unsafe_urls'  => false, // Own site: loopback hosts are expected.
			'headers'             => array( 'Cache-Control' => 'no-cache', 'Accept' => 'text/html,text/css,application/javascript,*/*' ),
			'user-agent'          => 'Mozilla/5.0 (compatible; PublishRank-ThemeScan/' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '' ) . ')',
		);
		for ( $hop = 0; $hop < 3; $hop++ ) {
			$r = wp_remote_get( $url, $args );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( $code >= 300 && $code < 400 ) {
				$loc = (string) wp_remote_retrieve_header( $r, 'location' );
				$loc = $loc ? \WP_Http::make_absolute_url( $loc, $url ) : '';
				if ( ! $loc || ! self::same_host( $loc ) ) {
					return new \WP_Error( 'publishrank_scan_redirect', __( 'The page redirected to another site, so it was not followed.', 'publishrank' ) );
				}
				$url = $loc;
				continue;
			}
			return array( 'status' => $code, 'body' => (string) wp_remote_retrieve_body( $r ), 'headers' => (array) wp_remote_retrieve_headers( $r ) );
		}
		return new \WP_Error( 'publishrank_scan_loop', __( 'Too many redirects.', 'publishrank' ) );
	}

	/** Pages to scan. @return array<string,string> */
	public static function targets() {
		$t    = array( __( 'Home page', 'publishrank' ) => home_url( '/' ) );
		$post = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'fields' => 'ids', 'no_found_rows' => true ) );
		if ( $post ) {
			$t[ __( 'Latest post', 'publishrank' ) ] = get_permalink( $post[0] );
			$cats = wp_get_post_categories( $post[0] );
			if ( $cats ) {
				$t[ __( 'Category archive', 'publishrank' ) ] = get_category_link( $cats[0] );
			}
		}
		$page = get_posts( array( 'numberposts' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'fields' => 'ids', 'no_found_rows' => true, 'exclude' => array( (int) get_option( 'page_on_front' ) ) ) );
		if ( $page ) {
			$t[ __( 'A page', 'publishrank' ) ] = get_permalink( $page[0] );
		}
		$t[ __( 'Search results', 'publishrank' ) ] = add_query_arg( 's', 'news', home_url( '/' ) );
		if ( (int) wp_count_posts()->publish > (int) get_option( 'posts_per_page', 10 ) ) {
			$t[ __( 'Pagination (page 2)', 'publishrank' ) ] = get_pagenum_link( 2, false );
		}
		if ( post_type_exists( 'product' ) ) {
			$prod = get_posts( array( 'numberposts' => 1, 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'no_found_rows' => true ) );
			if ( $prod ) {
				$t[ __( 'A product', 'publishrank' ) ] = get_permalink( $prod[0] );
			}
		}
		return array_slice( $t, 0, 8, true );
	}

	/** Analyse one page's HTML. @return array<string,mixed> */
	public static function analyse( $html, array $cfg ) {
		$d    = self::detectors( $cfg );
		$ex   = array();
		$out  = array_fill_keys( array( 'paths', 'generator', 'body', 'attrs', 'inline_css', 'inline_js', 'comments', 'credits' ), 0 );
		$html = (string) $html;
		$out['paths'] = preg_match_all( '#wp-content(?:\\\\?/)themes(?:\\\\?/)#i', $html );
		$names        = array_filter( array_map( 'preg_quote', array( (string) wp_get_theme()->get( 'Name' ), (string) wp_get_theme( get_template() )->get( 'Name' ), get_template(), get_stylesheet() ) ) );
		if ( $names && preg_match_all( '#<meta\s[^>]*name=["\']generator["\'][^>]*content=["\'][^"\']*(?:' . implode( '|', $names ) . ')#i', $html, $m ) ) {
			$out['generator'] = count( $m[0] );
		}
		if ( preg_match( '#<body[^>]*class=["\']([^"\']*)#i', $html, $b ) ) {
			$out['body'] = preg_match_all( '#\bwp-(?:child-)?theme-[a-z0-9_-]+#i', $b[1] );
		}
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $m ) ) {
			$out['inline_css'] = self::count( implode( "\n", $m[1] ), $d, $ex );
		}
		$js = '';
		if ( preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $sc ) {
				if ( ! preg_match( '#application/(?:ld\+)?json#i', $sc[1] ) ) {
					$js .= "\n" . $sc[2];
				}
			}
		}
		$out['inline_js'] = self::count( $js, $d, $ex );
		if ( preg_match_all( '#<!--(.*?)-->#s', preg_replace( '#<script\b.*?</script>#is', '', $html ), $m ) ) {
			$out['comments'] = self::count( implode( "\n", $m[1] ), $d );
		}
		$attrs = '';
		$bare  = preg_replace( array( '#<script\b.*?</script>#is', '#<style\b.*?</style>#is', '#<!--.*?-->#s' ), '', $html );
		if ( preg_match_all( '#\s(?:' . Rewriter::REF_ATTRS . '|data-[\w-]+)\s*=\s*("[^"]*"|\'[^\']*\')#i', (string) $bare, $m ) ) {
			$attrs = implode( ' ', $m[1] );
		}
		$out['attrs'] = self::count( $attrs, $d, $ex );
		if ( ! empty( $cfg['credit_hosts'] ) ) {
			$hosts          = implode( '|', array_map( static function ( $h ) { return preg_quote( $h, '#' ); }, (array) $cfg['credit_hosts'] ) );
			$out['credits'] = preg_match_all( '#<a\b[^>]*href=["\']https?://(?:[a-z0-9-]+\.)*(?:' . $hosts . ')[/"\'?:]#i', (string) $bare );
		}
		$text = wp_strip_all_tags( (string) $bare );
		$tn   = 0;
		foreach ( array_filter( array( (string) wp_get_theme()->get( 'Name' ), (string) wp_get_theme( get_template() )->get( 'Name' ) ) ) as $n ) {
			$tn += substr_count( strtolower( $text ), strtolower( $n ) );
		}
		$assets = array();
		if ( preg_match_all( '#<(?:link|script)\b[^>]*(?:href|src)=["\']([^"\']+\.(?:css|js|mjs)(?:\?[^"\']*)?)["\']#i', $html, $m ) ) {
			foreach ( $m[1] as $u ) {
				$u = html_entity_decode( $u );
				$u = 0 === strpos( $u, '//' ) ? set_url_scheme( $u ) : \WP_Http::make_absolute_url( $u, home_url( '/' ) );
				if ( self::same_host( $u ) && false === strpos( $u, '/wp-includes/' ) && false === strpos( $u, '/wp-admin/' ) ) {
					$assets[] = $u;
				}
			}
		}
		return array( 'counts' => $out, 'text' => $tn, 'examples' => array_slice( $ex, 0, 6 ), 'assets' => array_values( array_unique( $assets ) ), 'bytes' => strlen( $html ) );
	}

	/** Analyse a stylesheet or script. @return array<string,mixed> */
	public static function analyse_asset( $body, array $cfg ) {
		$d = self::detectors( $cfg );
		return array(
			'hits'      => self::count( $body, $d ),
			'header'    => (int) (bool) preg_match( '#Theme Name\s*:#i', (string) $body ),
			'sourcemap' => (int) (bool) preg_match( '#sourceMappingURL\s*=#', (string) $body ),
			'paths'     => preg_match_all( '#wp-content(?:\\\\?/)themes(?:\\\\?/)#i', (string) $body ),
		);
	}

	/** Run the full scan, store and return the result. @return array<string,mixed> */
	public static function run() {
		$t0  = microtime( true );
		$cfg = Config::rebuild();
		$s   = ThemeSeo::settings();
		$res = array(
			'version'  => 2,
			'time'     => time(),
			'theme'    => wp_get_theme()->get( 'Name' ),
			'parent'   => wp_get_theme()->parent() ? wp_get_theme()->parent()->get( 'Name' ) : '',
			'level'    => $s['mask'] ? $cfg['level'] : 'off',
			'adapter'  => $cfg['adapter'],
			'pages'    => array(),
			'assets'   => array(),
			'before'   => array_fill_keys( array_keys( self::categories() ), 0 ),
			'after'    => array_fill_keys( array_keys( self::categories() ), 0 ),
			'errors'   => array(),
			'broken'   => array(),
			'warnings' => array(),
		);
		$after_assets  = array();
		$before_assets = array();
		foreach ( self::targets() as $label => $url ) {
			$raw = self::fetch( $url, true );
			$vis = self::fetch( $url, false );
			if ( is_wp_error( $raw ) || is_wp_error( $vis ) ) {
				$res['errors'][] = array( 'label' => $label, 'url' => $url, 'message' => is_wp_error( $raw ) ? $raw->get_error_message() : $vis->get_error_message() );
				continue;
			}
			$a = self::analyse( $raw['body'], $cfg );
			$b = self::analyse( $vis['body'], $cfg );
			foreach ( $a['counts'] as $k => $v ) {
				$res['before'][ $k ] += $v;
				$res['after'][ $k ]  += $b['counts'][ $k ];
			}
			$res['pages'][] = array( 'label' => $label, 'url' => $url, 'status' => $vis['status'], 'before' => $a['counts'], 'after' => $b['counts'], 'examples' => $b['examples'], 'text' => $b['text'], 'bytes' => array( $a['bytes'], $b['bytes'] ) );
			$after_assets  = array_merge( $after_assets, $b['assets'] );
			$before_assets = array_merge( $before_assets, $a['assets'] );
		}
		foreach ( array( 'before' => array_slice( array_values( array_unique( $before_assets ) ), 0, self::MAX_ASSETS ), 'after' => array_slice( array_values( array_unique( $after_assets ) ), 0, self::MAX_ASSETS ) ) as $phase => $list ) {
			foreach ( $list as $u ) {
				$r = self::fetch( $u, false );
				if ( is_wp_error( $r ) ) {
					if ( 'after' === $phase ) {
						$res['broken'][] = array( 'url' => $u, 'status' => 0, 'message' => $r->get_error_message() );
					}
					continue;
				}
				if ( 'after' === $phase && 200 !== $r['status'] ) {
					$res['broken'][] = array( 'url' => $u, 'status' => $r['status'], 'message' => '' );
					continue;
				}
				$an = self::analyse_asset( $r['body'], $cfg );
				$res[ $phase ]['assets']     += $an['hits'] + $an['paths'];
				$res[ $phase ]['headers']    += $an['header'];
				$res[ $phase ]['sourcemaps'] += $an['sourcemap'];
				if ( 'after' === $phase && ( $an['hits'] || $an['header'] || $an['sourcemap'] || $an['paths'] ) ) {
					$res['assets'][] = array( 'url' => $u, 'hits' => $an['hits'] + $an['paths'], 'header' => $an['header'], 'sourcemap' => $an['sourcemap'] );
				}
			}
		}
		// Public REST index, as an anonymous visitor sees it.
		$rest = self::fetch( rest_url(), false );
		if ( ! is_wp_error( $rest ) && 200 === $rest['status'] ) {
			$data = json_decode( $rest['body'], true );
			$ns   = isset( $data['namespaces'] ) ? implode( ' ', (array) $data['namespaces'] ) : '';
			$res['after']['rest'] = self::count( $ns, self::detectors( $cfg ) );
		}
		$res['before']['rest'] = max( $res['before']['rest'], $res['after']['rest'] );
		// Direct probe of the real theme folder (cannot be closed from PHP; needs a web-server rule).
		$probe = self::fetch( trailingslashit( get_stylesheet_directory_uri() ) . 'style.css', false );
		$res['probe'] = is_wp_error( $probe ) ? array( 'status' => 0, 'header' => 0 ) : array( 'status' => $probe['status'], 'header' => (int) (bool) preg_match( '#Theme Name\s*:#i', $probe['body'] ) );
		if ( 200 === $res['probe']['status'] ) {
			$res['warnings'][] = 'probe';
		}
		if ( ! ThemeSeo::masking_available() ) {
			$res['warnings'][] = 'permalinks';
		}
		foreach ( array( 'autoptimize/autoptimize.php' => 'Autoptimize', 'wp-rocket/wp-rocket.php' => 'WP Rocket', 'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache', 'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache', 'wp-optimize/wp-optimize.php' => 'WP-Optimize', 'sg-cachepress/sg-cachepress.php' => 'SiteGround Optimizer' ) as $file => $name ) {
			if ( in_array( $file, (array) get_option( 'active_plugins', array() ), true ) ) {
				$res['warnings'][] = 'optimizer:' . $name;
			}
		}
		if ( ! empty( $cfg['skipped'] ) ) {
			$res['warnings'][] = 'generic:' . implode( ', ', $cfg['skipped'] );
		}
		$total          = array_sum( $res['after'] );
		$res['total']   = array( 'before' => array_sum( $res['before'] ), 'after' => $total );
		$res['state']   = ( $res['errors'] || $res['broken'] || ! $res['pages'] ) ? 'review' : ( 0 === $total ? 'protected' : 'partial' );
		$res['seconds'] = round( microtime( true ) - $t0, 2 );
		update_option( self::RESULT, $res, false );
		return $res;
	}
}
