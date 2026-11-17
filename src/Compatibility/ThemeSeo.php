<?php
/**
 * Theme SEO cleanup and theme masking.
 *
 * Cleanup: removes SEO tags that the active theme (or another plugin) prints when PublishRank already
 * prints the same kind of tag, so search engines see one title, one description, one canonical, one set
 * of social tags and one structured-data graph. PublishRank's own output (between its begin/end markers)
 * is never touched, and a kind of tag is only removed when PublishRank printed that kind on the page.
 *
 * Masking: hides which theme the site uses — theme file URLs are served from a neutral address, theme
 * names are removed from generator tags, body classes and asset IDs, and style.css loses its header.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility;

use PublishRankSEO\Compatibility\Masking\AssetServer;
use PublishRankSEO\Compatibility\Masking\Config;
use PublishRankSEO\Compatibility\Masking\RequestMapper;
use PublishRankSEO\Compatibility\Masking\Rewriter;

defined( 'ABSPATH' ) || exit;

final class ThemeSeo {
	const OPTION    = 'publishrank_theme_seo';
	const QV_ROOT   = 'prk_ta';
	const QV_FILE   = 'prk_tf';
	const SCAN_ARG  = 'publishrank_theme_scan';

	/** File types the masked address may serve. */
	const TYPES = array(
		'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript', 'map' => 'application/json', 'json' => 'application/json',
		'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
		'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
		'eot' => 'application/vnd.ms-fontobject', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
	);

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'cleanup'          => 1,
			'remove_title'     => 1,
			'remove_desc'      => 1,
			'remove_canonical' => 1,
			'remove_robots'    => 1,
			'remove_social'    => 1,
			'remove_jsonld'    => 1,
			'remove_keywords'  => 1,
			'remove_microdata' => 0,
			'theme_hooks'      => 1,
			'remove_generator' => 0,
			'remove_comments'  => 0,
			'mask'             => 0,
			'mask_alias'       => 'assets/ui',
			'mask_classes'     => 1,
			'mask_versions'    => 1,
			'mask_ids'         => 1,
			'mask_header'      => 1,
			'mask_level'       => 'maximum',
			'mask_sourcemaps'  => 'strip',
			'mask_credits'     => 1,
			'mask_rest'        => 1,
			'mask_tokens'      => '',
			'mask_prefixes'    => '',
			'mask_cdn_hosts'   => '',
		);
	}

	/** @return array<string,mixed> */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = array_merge( self::defaults(), is_array( $s ) ? $s : array() );
		foreach ( self::defaults() as $k => $v ) {
			$s[ $k ] = is_int( $v ) ? (int) (bool) $s[ $k ] : $s[ $k ];
		}
		$s['mask_alias']      = self::clean_alias( $s['mask_alias'] );
		$s['mask_level']      = in_array( $s['mask_level'], array( 'standard', 'maximum' ), true ) ? $s['mask_level'] : 'maximum';
		$s['mask_sourcemaps'] = 'keep' === $s['mask_sourcemaps'] ? 'keep' : 'strip';
		foreach ( array( 'mask_tokens', 'mask_prefixes', 'mask_cdn_hosts' ) as $k ) {
			$s[ $k ] = substr( (string) preg_replace( '#[^A-Za-z0-9 ,._:\-]#', '', (string) $s[ $k ] ), 0, 500 );
		}
		return $s;
	}

	public static function clean_alias( $alias ) {
		$alias = strtolower( trim( (string) $alias, " /\t\n" ) );
		$alias = preg_replace( '#[^a-z0-9/_-]#', '', $alias );
		$alias = preg_replace( '#/{2,}#', '/', $alias );
		$bad   = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'wp-login.php' );
		if ( '' === $alias || in_array( strtok( $alias, '/' ), $bad, true ) ) {
			$alias = 'assets/ui';
		}
		return substr( $alias, 0, 60 );
	}

	public function register() {
		Config::register();
		add_action( 'init', array( $this, 'rewrite' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'request_mapper' ), 20 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'serve' ), 0 );
		add_action( 'after_setup_theme', array( $this, 'theme_hooks' ), 99 );
		add_action( 'template_redirect', array( $this, 'start_buffer' ), PHP_INT_MAX );
		add_filter( 'body_class', array( $this, 'body_class' ), 99 );
	}

	/** Is this request a diagnostic scan that must see the untouched page? */
	public static function is_scan() {
		if ( empty( $_GET[ self::SCAN_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		static $ok = null;
		if ( null !== $ok ) {
			return $ok;
		}
		$given  = sanitize_text_field( wp_unslash( $_GET[ self::SCAN_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tokens = get_transient( 'publishrank_theme_scan_tokens' );
		$ok     = false;
		if ( is_array( $tokens ) && strlen( $given ) >= 32 ) {
			$h = hash( 'sha256', $given );
			if ( isset( $tokens[ $h ] ) && (int) $tokens[ $h ] >= time() ) {
				$ok = true;
				unset( $tokens[ $h ] ); // Single use: a leaked URL cannot be replayed.
				set_transient( 'publishrank_theme_scan_tokens', $tokens, 5 * MINUTE_IN_SECONDS );
			}
		}
		if ( $ok ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		return $ok;
	}

	/** Issue a one-time token that lets the scanner see one page without cleanup or masking. */
	public static function issue_scan_token() {
		$token  = wp_generate_password( 40, false );
		$tokens = get_transient( 'publishrank_theme_scan_tokens' );
		$tokens = is_array( $tokens ) ? array_filter( $tokens, static function ( $exp ) { return (int) $exp >= time(); } ) : array();
		$tokens[ hash( 'sha256', $token ) ] = time() + 2 * MINUTE_IN_SECONDS;
		set_transient( 'publishrank_theme_scan_tokens', array_slice( $tokens, -50, null, true ), 5 * MINUTE_IN_SECONDS );
		return $token;
	}

	/** Map masked AJAX/REST requests back to real identifiers. */
	public function request_mapper() {
		$s = self::settings();
		if ( $s['mask'] && self::masking_available() ) {
			RequestMapper::register( Config::get() );
		}
	}

	/* ------------------------------------------------------------ masking: serve files */

	public static function masking_available() {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	public function rewrite() {
		$s = self::settings();
		if ( $s['mask'] && self::masking_available() ) {
			add_rewrite_rule( '^' . preg_quote( $s['mask_alias'], '#' ) . '/(c|p|x)/(.+)$', 'index.php?' . self::QV_ROOT . '=$matches[1]&' . self::QV_FILE . '=$matches[2]', 'top' );
		}
	}

	public function query_vars( $vars ) {
		$vars[] = self::QV_ROOT;
		$vars[] = self::QV_FILE;
		return $vars;
	}

	/** Absolute directory for c (active/child theme) or p (parent theme). */
	private static function root_dir( $which ) {
		return 'p' === $which ? get_template_directory() : get_stylesheet_directory();
	}

	/**
	 * Resolve a masked request to a real theme file, or null when it is not allowed.
	 *
	 * @return array{path:string,type:string}|null
	 */
	public static function resolve( $which, $rel ) {
		$hit = AssetServer::resolve( (string) $which . '/' . ltrim( (string) $rel, '/' ), Config::get() );
		return $hit ? array( 'path' => $hit['path'], 'type' => $hit['type'] ) : null;
	}

	/** Strip the "Theme Name / Author / Version" header comment from a stylesheet. */
	public static function strip_header( $css ) {
		return (string) preg_replace( '#^\s*/\*.*?(Theme Name|Theme URI|Template)\s*:.*?\*/\s*#is', '', (string) $css, 1 );
	}

	public function serve( $wp ) {
		if ( empty( $wp->query_vars[ self::QV_ROOT ] ) || empty( $wp->query_vars[ self::QV_FILE ] ) ) {
			return;
		}
		$s = self::settings();
		if ( ! $s['mask'] ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		AssetServer::serve( sanitize_key( (string) $wp->query_vars[ self::QV_ROOT ] ) . '/' . (string) $wp->query_vars[ self::QV_FILE ], Config::get() );
	}

	/* ------------------------------------------------------------ theme-level switches */

	/** Turn off SEO features of well-known themes through their own documented filters. */
	public function theme_hooks() {
		$s = self::settings();
		if ( ! $s['cleanup'] || ! $s['theme_hooks'] || self::is_scan() ) {
			return;
		}
		// Genesis Framework: treat PublishRank as an SEO plugin so Genesis disables its SEO settings and output.
		add_filter(
			'genesis_detect_seo_plugins',
			static function ( $plugins ) {
				$plugins              = is_array( $plugins ) ? $plugins : array();
				$plugins['constants'] = array_merge( isset( $plugins['constants'] ) ? (array) $plugins['constants'] : array(), array( 'PUBLISHRANK_SEO_VERSION' ) );
				return $plugins;
			}
		);
		add_filter( 'astra_schema_enabled', '__return_false', 99 );                       // Astra microdata.
		add_filter( 'generate_schema_type', '__return_empty_string', 99 );                 // GeneratePress microdata.
		add_filter( 'hello_elementor_description_meta_tag', '__return_false', 99 );       // Hello Elementor description tag.
		add_filter( 'blocksy:schema-org:enabled', '__return_false', 99 );                  // Blocksy schema.
		add_filter( 'kadence_schema_output', '__return_false', 99 );                       // Kadence.
		add_filter( 'neve_schema_enabled', '__return_false', 99 );                         // Neve.
		add_filter( 'ocean_schema_markup', '__return_empty_string', 99 );                  // OceanWP microdata helper.
		add_filter( 'td_seo_meta_tags', '__return_false', 99 );                            // tagDiv (Newspaper).
		add_filter( 'jannah_open_graph', '__return_false', 99 );                           // Jannah.
		add_filter( 'avada_schema_enabled', '__return_false', 99 );                        // Avada.
		add_filter( 'fusion_disable_og', '__return_true', 99 );                            // Avada Open Graph.
		// WordPress core duplicates PublishRank replaces (only while PublishRank prints its own metadata).
		if ( ! class_exists( '\\PublishRankSEO\\Core\\OutputSettings' ) || \PublishRankSEO\Core\OutputSettings::metadata_output() ) {
			remove_action( 'wp_head', 'rel_canonical' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
		}
		if ( $s['remove_generator'] ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
	}

	public function body_class( $classes ) {
		$s = self::settings();
		if ( ! $s['mask'] || ! $s['mask_classes'] || self::is_scan() ) {
			return $classes;
		}
		$slugs = array_unique( array_filter( array( get_stylesheet(), get_template() ) ) );
		$out   = array();
		foreach ( (array) $classes as $c ) {
			$hit = false;
			foreach ( $slugs as $slug ) {
				if ( false !== stripos( (string) $c, $slug ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ HTML processing */

	public function start_buffer() {
		if ( is_admin() || is_feed() || is_robots() || wp_doing_ajax() || is_customize_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || self::is_scan() ) {
			return;
		}
		$s = self::settings();
		if ( ! $s['cleanup'] && ! $s['mask'] && ! $s['remove_generator'] && ! $s['remove_comments'] ) {
			return;
		}
		$title = function_exists( 'wp_get_document_title' ) ? wp_get_document_title() : '';
		ob_start(
			static function ( $html ) use ( $s, $title ) {
				return self::process( (string) $html, $s, $title );
			}
		);
	}

	/** Pull PublishRank's own blocks out so nothing inside them is changed. @return array{0:string,1:array<string,string>} */
	private static function protect( $html ) {
		$kept = array();
		$html = preg_replace_callback(
			'#<!-- (?:SEO Powered by PublishRank|PublishRank: begin).*?(?:<!-- end of SEO data Powered by PublishRank -->|<!-- PublishRank: end -->)#s',
			static function ( $m ) use ( &$kept ) {
				$key          = '<!--PRKEEP' . count( $kept ) . '-->';
				$kept[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		return array( (string) $html, $kept );
	}

	/** Which kinds of tag PublishRank printed on this page. @return array<string,bool|string[]> */
	public static function own_kinds( $own ) {
		$types = array();
		if ( preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $own, $m ) ) {
			foreach ( $m[1] as $json ) {
				$types = array_merge( $types, self::jsonld_types( $json ) );
			}
		}
		return array(
			'desc'      => (bool) preg_match( '#<meta\s[^>]*name=["\']description["\']#i', $own ),
			'canonical' => (bool) preg_match( '#<link\s[^>]*rel=["\']canonical["\']#i', $own ),
			'robots'    => (bool) preg_match( '#<meta\s[^>]*name=["\']robots["\']#i', $own ),
			'og'        => (bool) preg_match( '#<meta\s[^>]*property=["\']og:#i', $own ),
			'twitter'   => (bool) preg_match( '#<meta\s[^>]*name=["\']twitter:#i', $own ),
			'jsonld'    => array_values( array_unique( $types ) ),
		);
	}

	/** @return string[] All @type values in a JSON-LD block (including @graph items). */
	public static function jsonld_types( $json ) {
		$data = json_decode( html_entity_decode( trim( (string) $json ) ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$items = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( isset( $data[0] ) ? $data : array( $data ) );
		$out   = array();
		foreach ( $items as $it ) {
			if ( is_array( $it ) && isset( $it['@type'] ) ) {
				foreach ( (array) $it['@type'] as $t ) {
					$out[] = (string) $t;
				}
			}
		}
		return $out;
	}

	/**
	 * Clean a full HTML page.
	 *
	 * @param array<string,mixed> $s Settings.
	 */
	public static function process( $html, array $s, $title = '' ) {
		if ( '' === $html || false === stripos( $html, '<html' ) ) {
			return $html; // Not a page (JSON, XML, fragments).
		}
		list( $html, $kept ) = self::protect( $html );
		$own                 = self::own_kinds( implode( "\n", $kept ) );
		$has_own             = ! empty( $kept );

		if ( $s['cleanup'] && $has_own ) {
			$html = self::clean_head( $html, $s, $own, $title );
			if ( $s['remove_jsonld'] && $own['jsonld'] ) {
				$html = self::clean_jsonld( $html, $own['jsonld'] );
			}
			if ( $s['remove_microdata'] ) {
				$html = self::strip_microdata( $html );
			}
		}
		if ( $s['remove_generator'] ) {
			$html = preg_replace( '#[ \t]*<meta\s[^>]*name=["\']generator["\'][^>]*>\s*#i', '', $html );
		}
		if ( $s['remove_comments'] ) {
			// Keep conditional comments and our placeholders.
			$html = preg_replace( '#<!--(?!\[if|\s*\[endif|PRKEEP)(?!<!).*?-->#s', '', $html );
		}
		if ( $s['mask'] && self::masking_available() ) {
			$html = self::mask( $html, $s );
		}
		if ( $s['mask'] && self::masking_available() && $kept ) {
			$cfg = Config::get();
			foreach ( $kept as $k => $v ) {
				$kept[ $k ] = Rewriter::urls( $v, $cfg );
			}
		}
		return strtr( $html, $kept );
	}

	private static function clean_head( $html, array $s, array $own, $title ) {
		$end = stripos( $html, '</head>' );
		if ( false === $end ) {
			return $html;
		}
		$head = substr( $html, 0, $end );
		$rest = substr( $html, $end );
		$drop = static function ( $pattern ) use ( &$head ) {
			$head = (string) preg_replace( $pattern, '', $head );
		};
		if ( $s['remove_desc'] && $own['desc'] ) {
			$drop( '#[ \t]*<meta\s[^>]*name=["\'](?:description|DC\.description)["\'][^>]*>\s*#i' );
		}
		if ( $s['remove_canonical'] && $own['canonical'] ) {
			$drop( '#[ \t]*<link\s[^>]*rel=["\']canonical["\'][^>]*>\s*#i' );
		}
		if ( $s['remove_robots'] && $own['robots'] ) {
			$drop( '#[ \t]*<meta\s[^>]*name=["\'](?:robots|googlebot|bingbot)["\'][^>]*>\s*#i' );
		}
		if ( $s['remove_social'] && $own['og'] ) {
			$drop( '#[ \t]*<meta\s[^>]*(?:property|name)=["\'](?:og|article|fb|product):[^"\']*["\'][^>]*>\s*#i' );
		}
		if ( $s['remove_social'] && $own['twitter'] ) {
			$drop( '#[ \t]*<meta\s[^>]*(?:name|property)=["\']twitter:[^"\']*["\'][^>]*>\s*#i' );
		}
		if ( $s['remove_keywords'] ) {
			$drop( '#[ \t]*<meta\s[^>]*name=["\']keywords["\'][^>]*>\s*#i' );
		}
		if ( $s['remove_title'] && preg_match_all( '#<title[^>]*>.*?</title>#is', $head, $m ) && count( $m[0] ) > 1 ) {
			// Keep the title that matches PublishRank's (or the first), remove the rest.
			$keep = 0;
			foreach ( $m[0] as $i => $t ) {
				if ( '' !== $title && false !== strpos( html_entity_decode( wp_strip_all_tags( $t ) ), html_entity_decode( $title ) ) ) {
					$keep = $i;
					break;
				}
			}
			foreach ( $m[0] as $i => $t ) {
				if ( $i !== $keep ) {
					$pos = strpos( $head, $t );
					if ( false !== $pos ) {
						$head = substr_replace( $head, '', $pos, strlen( $t ) );
					}
				}
			}
		}
		return $head . $rest;
	}

	/** Remove other JSON-LD blocks whose types PublishRank already describes. */
	private static function clean_jsonld( $html, array $own_types ) {
		$own_types = array_map( 'strtolower', $own_types );
		return (string) preg_replace_callback(
			'#[ \t]*<script[^>]+application/ld\+json[^>]*>(.*?)</script>\s*#is',
			static function ( $m ) use ( $own_types ) {
				$types = array_map( 'strtolower', self::jsonld_types( $m[1] ) );
				if ( ! $types ) {
					return $m[0];
				}
				return array_diff( $types, $own_types ) ? $m[0] : '';
			},
			$html
		);
	}

	/** Remove schema.org microdata attributes from the page body. */
	private static function strip_microdata( $html ) {
		$pos = stripos( $html, '<body' );
		if ( false === $pos ) {
			return $html;
		}
		$body = substr( $html, $pos );
		$body = preg_replace( '#\s(?:itemscope(?:=["\'][^"\']*["\'])?|itemtype=["\']https?://schema\.org/[^"\']*["\']|itemprop=["\'][^"\']*["\'])(?=[\s>/])#i', '', $body );
		return substr( $html, 0, $pos ) . $body;
	}

	/** Point theme file URLs at the neutral address and remove theme fingerprints. */
	private static function mask( $html, array $s ) {
		$cfg  = Config::get();
		$html = Rewriter::urls( $html, $cfg );
		if ( ! empty( $cfg['identifiers'] ) ) {
			$html = Rewriter::markup( $html, $cfg );
		} elseif ( $s['mask_ids'] ) {
			foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $slug ) {
				$html = (string) preg_replace( '#(\sid=["\'])' . preg_quote( $slug, '#' ) . '(-[a-z0-9_-]+["\'])#i', '$1site$2', $html );
			}
		}
		if ( $s['mask_credits'] ) {
			$html = Rewriter::credits( $html, $cfg );
		}
		// Generator tags that name the theme are removed at every level.
		$names = array_filter( array( wp_get_theme()->get( 'Name' ), wp_get_theme( get_template() )->get( 'Name' ), get_stylesheet(), get_template() ) );
		foreach ( $names as $n ) {
			$html = (string) preg_replace( '#[ \t]*<meta\s[^>]*name=["\']generator["\'][^>]*content=["\'][^"\']*' . preg_quote( $n, '#' ) . '[^>]*>\s*#i', '', $html );
		}
		return (string) $html;
	}

	/* ------------------------------------------------------------ diagnostics */

	/**
	 * Analyse a page's raw HTML: what PublishRank prints and what else prints SEO tags.
	 *
	 * @return array<string,mixed>
	 */
	public static function analyse( $html ) {
		list( $rest, $kept ) = self::protect( (string) $html );
		$own                 = self::own_kinds( implode( "\n", $kept ) );
		$end                 = stripos( $rest, '</head>' );
		$head                = false === $end ? $rest : substr( $rest, 0, $end );
		$count               = static function ( $pattern, $hay ) {
			return (int) preg_match_all( $pattern, $hay );
		};
		$jsonld = array();
		if ( preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $rest, $m ) ) {
			foreach ( $m[1] as $j ) {
				$jsonld[] = implode( ', ', self::jsonld_types( $j ) );
			}
		}
		$gens = array();
		if ( preg_match_all( '#<meta\s[^>]*name=["\']generator["\'][^>]*content=["\']([^"\']*)#i', $rest, $g ) ) {
			$gens = $g[1];
		}
		$theme_url = (string) wp_parse_url( trailingslashit( get_theme_root_uri() ), PHP_URL_PATH );
		return array(
			'publishrank' => ! empty( $kept ),
			'own'         => $own,
			'other'       => array(
				'title'     => $count( '#<title[^>]*>#i', $head ),
				'desc'      => $count( '#<meta\s[^>]*name=["\']description["\']#i', $head ),
				'canonical' => $count( '#<link\s[^>]*rel=["\']canonical["\']#i', $head ),
				'robots'    => $count( '#<meta\s[^>]*name=["\'](?:robots|googlebot|bingbot)["\']#i', $head ),
				'og'        => $count( '#<meta\s[^>]*(?:property|name)=["\'](?:og|article|fb|product):#i', $head ),
				'twitter'   => $count( '#<meta\s[^>]*(?:name|property)=["\']twitter:#i', $head ),
				'keywords'  => $count( '#<meta\s[^>]*name=["\']keywords["\']#i', $head ),
				'jsonld'    => $jsonld,
				'microdata' => $count( '#itemtype=["\']https?://schema\.org/#i', $rest ),
			),
			'generators'  => $gens,
			'theme_refs'  => substr_count( $rest, $theme_url ),
		);
	}
}
