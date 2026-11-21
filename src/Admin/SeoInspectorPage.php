<?php
/**
 * SEO Inspector: preview and check any public page of this site (search snippet, social card,
 * indexing signals and every JSON-LD entity), or paste JSON-LD / HTML to check it before publishing.
 *
 * Pages are fetched server-side as an anonymous visitor, only from this site's own host (no
 * open proxy), with a size and time limit. Nothing is stored.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Schema\SchemaValidator;

defined( 'ABSPATH' ) || exit;

final class SeoInspectorPage {
	const SLUG = 'publishrank-seo-inspector';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 40 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 96 );
	}

	public static function cap() {
		return Capabilities::can( 'manage_schema' ) ? Capabilities::cap( 'manage_schema' ) : Capabilities::cap( 'edit_seo' );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'SEO Inspector', 'publishrank' ), __( 'SEO Inspector', 'publishrank' ), self::cap(), self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		if ( self::SLUG !== Suite::current_page() ) {
			return;
		}
		Suite::enqueue();
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/seo-inspector.css';
		wp_enqueue_style( 'publishrank-seo-inspector', Branding::asset_url( 'css/seo-inspector.css' ), array( 'publishrank-suite' ), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
	}

	/** "Inspect this page" in the toolbar on the front end. */
	public static function admin_bar( $bar ) {
		if ( is_admin() || ! is_admin_bar_showing() || ! is_object( $bar ) || ! Capabilities::can( 'edit_seo' ) && ! Capabilities::can( 'manage_schema' ) ) {
			return;
		}
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$bar->add_node(
			array(
				'id'    => 'publishrank-inspect',
				'title' => '<span class="ab-icon dashicons dashicons-search" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Inspect SEO', 'publishrank' ) . '</span>',
				'href'  => add_query_arg( array( 'page' => self::SLUG, 'url' => rawurlencode( $scheme . $host . $uri ) ), admin_url( 'admin.php' ) ),
				'meta'  => array( 'title' => __( 'Preview this page in search and social, and check its structured data', 'publishrank' ) ),
			)
		);
	}

	/** Only URLs on this site's host may be fetched. */
	public static function allowed_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return '' !== $host && $host === $home ? $url : '';
	}

	/** @return array{status:int,html:string,error:string,final:string,headers:array} */
	public static function fetch( $url ) {
		// Redirects are followed by hand and only within this site, so a redirect can never make the
		// server fetch another host (for example an internal address).
		$current = $url;
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			$res = wp_remote_get(
				$current,
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'limit_response_size' => 3 * MB_IN_BYTES,
					'user-agent'          => 'PublishRank-Inspector/' . Branding::version() . '; ' . home_url( '/' ),
					'headers'             => array( 'Cache-Control' => 'no-cache' ),
					'cookies'             => array(),
					'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
				)
			);
			if ( is_wp_error( $res ) ) {
				return array( 'status' => 0, 'html' => '', 'error' => $res->get_error_message(), 'final' => $current, 'headers' => array() );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code >= 300 && $code < 400 && '' !== (string) wp_remote_retrieve_header( $res, 'location' ) ) {
				$location = (string) wp_remote_retrieve_header( $res, 'location' );
				$next     = self::allowed_url( 0 === strpos( $location, '/' ) && 0 !== strpos( $location, '//' ) ? home_url( $location ) : $location );
				if ( '' === $next || $hop === 3 ) {
					/* translators: %s: redirect target */
					return array( 'status' => $code, 'html' => '', 'error' => sprintf( __( 'The page redirects to %s, which was not followed (only addresses on this site are inspected).', 'publishrank' ), $location ), 'final' => $current, 'headers' => array() );
				}
				$current = $next;
				continue;
			}
			break;
		}
		$headers = wp_remote_retrieve_headers( $res );
		return array(
			'status'  => $code,
			'html'    => (string) wp_remote_retrieve_body( $res ),
			'error'   => '',
			'final'   => $current,
			'headers' => array( 'x-robots-tag' => is_object( $headers ) && isset( $headers['x-robots-tag'] ) ? (string) ( is_array( $headers['x-robots-tag'] ) ? implode( ', ', $headers['x-robots-tag'] ) : $headers['x-robots-tag'] ) : '' ),
		);
	}

	/**
	 * Indexing and metadata checks for a parsed page.
	 *
	 * @return array<int,array{level:string,area:string,msg:string}>
	 */
	public static function page_checks( array $p, $url, $status = 200, $x_robots = '' ) {
		$out  = array();
		$add  = static function ( $level, $area, $msg ) use ( &$out ) {
			$out[] = array( 'level' => $level, 'area' => $area, 'msg' => $msg );
		};
		$len  = static function ( $s ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s ) : strlen( (string) $s );
		};
		if ( $status && 200 !== (int) $status ) {
			/* translators: %d: HTTP status */
			$add( $status >= 400 ? 'error' : 'warn', __( 'Page', 'publishrank' ), sprintf( __( 'The page answered with HTTP status %d.', 'publishrank' ), (int) $status ) );
		}
		$title = $p['title'];
		if ( '' === $title ) {
			$add( 'error', __( 'Title', 'publishrank' ), __( 'The page has no <title>.', 'publishrank' ) );
		} elseif ( $len( $title ) > 65 ) {
			$add( 'warn', __( 'Title', 'publishrank' ), __( 'The title is longer than about 60 characters and may be shortened in search results.', 'publishrank' ) );
		} elseif ( $len( $title ) < 15 ) {
			$add( 'warn', __( 'Title', 'publishrank' ), __( 'The title is very short; describe the page more clearly.', 'publishrank' ) );
		}
		$desc = $p['meta']['description'][0] ?? '';
		if ( '' === trim( $desc ) ) {
			$add( 'warn', __( 'Description', 'publishrank' ), __( 'No meta description. Search engines will pick text from the page.', 'publishrank' ) );
		} elseif ( $len( $desc ) > 170 ) {
			$add( 'info', __( 'Description', 'publishrank' ), __( 'The description is longer than about 160 characters and may be shortened.', 'publishrank' ) );
		}
		if ( count( $p['meta']['description'] ?? array() ) > 1 ) {
			$add( 'warn', __( 'Description', 'publishrank' ), __( 'More than one meta description is printed. Another plugin or the theme may add a second one.', 'publishrank' ) );
		}
		$canon = $p['canonical'];
		if ( ! $canon ) {
			$add( 'warn', __( 'Canonical', 'publishrank' ), __( 'No canonical link.', 'publishrank' ) );
		} elseif ( count( $canon ) > 1 ) {
			$add( 'error', __( 'Canonical', 'publishrank' ), __( 'More than one canonical link. Search engines may ignore all of them.', 'publishrank' ) );
		} elseif ( untrailingslashit( strtok( $canon[0], '#' ) ) !== untrailingslashit( strtok( $url, '#?' ) ) ) {
			/* translators: %s: URL */
			$add( 'info', __( 'Canonical', 'publishrank' ), sprintf( __( 'The canonical points to another address: %s', 'publishrank' ), $canon[0] ) );
		}
		$robots = strtolower( implode( ',', $p['meta']['robots'] ?? array() ) . ',' . $x_robots );
		if ( false !== strpos( $robots, 'noindex' ) ) {
			$add( 'warn', __( 'Indexing', 'publishrank' ), __( 'This page asks search engines not to index it (noindex).', 'publishrank' ) );
		}
		if ( empty( $p['meta']['og:title'] ) ) {
			$add( 'warn', __( 'Social', 'publishrank' ), __( 'No og:title for social sharing.', 'publishrank' ) );
		}
		if ( empty( $p['meta']['og:image'] ) ) {
			$add( 'info', __( 'Social', 'publishrank' ), __( 'No og:image; shared links will appear without a picture.', 'publishrank' ) );
		}
		$h1 = count( $p['h1'] );
		if ( 0 === $h1 ) {
			$add( 'warn', __( 'Headings', 'publishrank' ), __( 'No H1 heading found on the page.', 'publishrank' ) );
		} elseif ( $h1 > 1 ) {
			/* translators: %d: count */
			$add( 'info', __( 'Headings', 'publishrank' ), sprintf( __( '%d H1 headings found. One clear main heading is easier to understand.', 'publishrank' ), $h1 ) );
		}
		if ( '' === $p['lang'] ) {
			$add( 'info', __( 'Language', 'publishrank' ), __( 'The <html> element has no lang attribute.', 'publishrank' ) );
		}
		if ( ! $p['jsonld'] ) {
			$add( 'info', __( 'Structured data', 'publishrank' ), __( 'No JSON-LD structured data found.', 'publishrank' ) );
		}
		return array_merge( $out, self::site_name_checks( $p, $url ) );
	}

	/**
	 * Homepage-only checks for the two things Google shows for a homepage: the site name above the URL and the
	 * homepage title link below it. Returns nothing for any other page.
	 *
	 * @return array<int,array{level:string,area:string,msg:string}>
	 */
	private static function site_name_checks( array $p, $url ) {
		$out  = array();
		$home = untrailingslashit( (string) strtok( \PublishRankSEO\Core\PublicationProfile::primary_url(), '#?' ) );
		if ( untrailingslashit( (string) strtok( (string) $url, '#?' ) ) !== $home ) {
			return $out;
		}
		$area = __( 'Site name', 'publishrank' );
		$add  = static function ( $level, $msg ) use ( &$out, $area ) {
			$out[] = array( 'level' => $level, 'area' => $area, 'msg' => $msg );
		};
		$sites = array();
		$orgs  = array();
		foreach ( (array) ( $p['jsonld'] ?? array() ) as $raw ) {
			$data = json_decode( (string) $raw, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$list = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ? $data : array( $data ) );
			foreach ( $list as $node ) {
				if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
					continue;
				}
				$types = array_map( 'strval', (array) $node['@type'] );
				if ( in_array( 'WebSite', $types, true ) ) {
					$sites[] = $node;
				} elseif ( array_intersect( $types, array( 'Organization', 'NewsMediaOrganization', 'Person' ) ) ) {
					$orgs[] = $node;
				}
			}
		}
		if ( ! $sites ) {
			$add( 'warn', __( 'The homepage has no WebSite structured data. Google uses it, with the homepage itself, to choose the site name shown above the URL.', 'publishrank' ) );
			return $out;
		}
		$name  = isset( $sites[0]['name'] ) && is_string( $sites[0]['name'] ) ? trim( $sites[0]['name'] ) : '';
		$alts  = array();
		foreach ( (array) ( $sites[0]['alternateName'] ?? array() ) as $alt ) {
			if ( is_string( $alt ) ) {
				$alts[] = strtolower( trim( $alt ) );
			}
		}
		$title = trim( (string) $p['title'] );
		$og    = isset( $p['meta']['og:site_name'][0] ) ? trim( (string) $p['meta']['og:site_name'][0] ) : '';
		if ( '' !== $name && '' !== $og && 0 !== strcasecmp( $name, $og ) ) {
			/* translators: 1: WebSite name, 2: og:site_name */
			$add( 'warn', sprintf( __( 'The WebSite name (%1$s) differs from og:site_name (%2$s). Use one name everywhere so Google is not left to guess.', 'publishrank' ), $name, $og ) );
		}
		foreach ( $orgs as $org ) {
			$org_name = isset( $org['name'] ) && is_string( $org['name'] ) ? trim( $org['name'] ) : '';
			if ( '' !== $name && '' !== $org_name && 0 !== strcasecmp( $name, $org_name ) && ! in_array( strtolower( $org_name ), $alts, true ) ) {
				/* translators: 1: WebSite name, 2: Organization or Person name */
				$add( 'info', sprintf( __( 'The WebSite name (%1$s) and the publisher name (%2$s) differ. That is fine if intended; otherwise list the publisher name as an alternative site name.', 'publishrank' ), $name, $org_name ) );
				break;
			}
		}
		if ( '' !== $name && '' !== $title && 0 === strcasecmp( $title, $name ) ) {
			$add( 'info', __( 'The homepage title is only the site name. Add a short description of what the site covers, so the title link says more than the name above it.', 'publishrank' ) );
		}
		return $out;
	}

	public function render() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- read-only inspection; the GET form only fetches a public page of this site, the POST form is nonce-checked below.
		$mode = isset( $_REQUEST['mode'] ) && 'code' === $_REQUEST['mode'] ? 'code' : 'url';
		$raw  = isset( $_GET['url'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['url'] ) ) ) : '';
		// phpcs:enable
		$url  = self::allowed_url( $raw );
		$code = '';
		if ( 'code' === $mode && isset( $_POST['code'] ) ) {
			check_admin_referer( 'publishrank_inspect_code' );
			$code = (string) wp_unslash( $_POST['code'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as data only, never stored or echoed unescaped.
			$code = substr( $code, 0, 500000 );
		}
		Suite::open( __( 'SEO Inspector', 'publishrank' ), __( 'See how any page of your site appears in search and social, and check its structured data.', 'publishrank' ) );
		echo '<div class="prsi">';
		echo '<nav class="prsi-tabs" aria-label="' . esc_attr__( 'Inspector mode', 'publishrank' ) . '">';
		echo '<a class="prsi-tab' . ( 'url' === $mode ? ' is-current' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '"' . ( 'url' === $mode ? ' aria-current="page"' : '' ) . '><span class="fa-solid fa-globe" aria-hidden="true"></span> ' . esc_html__( 'Inspect a page', 'publishrank' ) . '</a>';
		echo '<a class="prsi-tab' . ( 'code' === $mode ? ' is-current' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&mode=code' ) ) . '"' . ( 'code' === $mode ? ' aria-current="page"' : '' ) . '><span class="fa-solid fa-code" aria-hidden="true"></span> ' . esc_html__( 'Check code', 'publishrank' ) . '</a></nav>';

		if ( 'url' === $mode ) {
			echo '<form class="prsi-form" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
			echo '<label for="prsi-url">' . esc_html__( 'Page address on this site', 'publishrank' ) . '</label><div class="prsi-form__row"><input type="url" id="prsi-url" name="url" class="regular-text" required placeholder="' . esc_attr( home_url( '/' ) ) . '" value="' . esc_attr( '' !== $raw ? $raw : '' ) . '" list="prsi-recent" /> <button class="button button-primary" type="submit">' . esc_html__( 'Inspect', 'publishrank' ) . '</button></div>';
			echo '<datalist id="prsi-recent"><option value="' . esc_attr( home_url( '/' ) ) . '"></option>';
			foreach ( get_posts( array( 'post_type' => array( 'post', 'page', 'product' ), 'post_status' => 'publish', 'numberposts' => 15, 'orderby' => 'modified' ) ) as $recent ) {
				echo '<option value="' . esc_attr( (string) get_permalink( $recent ) ) . '">' . esc_html( get_the_title( $recent ) ) . '</option>';
			}
			echo '</datalist><p class="prx-help">' . esc_html__( 'The page is fetched as a signed-out visitor would see it. Tip: on any page of your site use "Inspect SEO" in the toolbar.', 'publishrank' ) . '</p></form>';
			if ( '' !== $raw && '' === $url ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Only addresses on this site can be inspected.', 'publishrank' ) . '</p></div>';
			} elseif ( '' !== $url ) {
				$res = self::fetch( $url );
				if ( '' !== $res['error'] ) {
					/* translators: %s: error */
					echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'The page could not be loaded: %s', 'publishrank' ), $res['error'] ) ) . '</p></div>';
				} else {
					$this->report( SchemaValidator::parse_html( $res['html'] ), $url, $res['status'], $res['headers']['x-robots-tag'] );
				}
			}
		} else {
			echo '<form class="prsi-form" method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&mode=code' ) ) . '">';
			wp_nonce_field( 'publishrank_inspect_code' );
			echo '<label for="prsi-code">' . esc_html__( 'JSON-LD or a full HTML page', 'publishrank' ) . '</label><textarea id="prsi-code" name="code" rows="12" class="large-text code" spellcheck="false" placeholder="{&quot;@context&quot;:&quot;https://schema.org&quot;,&quot;@type&quot;:&quot;Event&quot;, …}">' . esc_textarea( $code ) . '</textarea><p><button class="button button-primary" type="submit">' . esc_html__( 'Check', 'publishrank' ) . '</button></p></form>';
			if ( '' !== trim( $code ) ) {
				$is_html = false !== stripos( $code, '<html' ) || false !== stripos( $code, '<head' ) || false !== stripos( $code, '<script' );
				if ( $is_html ) {
					$this->report( SchemaValidator::parse_html( $code ), '', 0, '' );
				} else {
					$this->schema_report( SchemaValidator::check_blocks( array( $code ) ), '' );
				}
			}
		}
		echo '</div>';
		Suite::close();
	}

	private function report( array $p, $url, $status, $x_robots ) {
		$title = '' !== $p['title'] ? $p['title'] : __( '(no title)', 'publishrank' );
		$desc  = $p['meta']['description'][0] ?? '';
		$display_url = '' !== $url ? $url : ( $p['canonical'][0] ?? home_url( '/' ) );
		$host  = (string) wp_parse_url( $display_url, PHP_URL_HOST );
		$path  = trim( (string) wp_parse_url( $display_url, PHP_URL_PATH ), '/' );
		$crumb = $host . ( '' !== $path ? ' › ' . str_replace( '/', ' › ', $path ) : '' );
		$cut   = static function ( $s, $n ) {
			$s = trim( (string) $s );
			return ( function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s ) ) > $n ? rtrim( function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n - 1 ) : substr( $s, 0, $n - 1 ) ) . '…' : $s;
		};
		echo '<div class="prsi-grid">';
		echo '<section class="prx-card prsi-card"><h2 class="prx-card__title"><span class="fa-brands fa-google" aria-hidden="true"></span> ' . esc_html__( 'Search result preview', 'publishrank' ) . '</h2>';
		echo '<div class="prsi-serp"><div class="prsi-serp__site">' . esc_html( $crumb ) . '</div><div class="prsi-serp__title">' . esc_html( $cut( $title, 62 ) ) . '</div><div class="prsi-serp__desc">' . esc_html( '' !== $desc ? $cut( $desc, 158 ) : __( 'No description: the search engine chooses text from the page.', 'publishrank' ) ) . '</div></div>';
		echo '<p class="prx-help">' . esc_html__( 'An approximation: search engines may rewrite titles and descriptions.', 'publishrank' ) . '</p></section>';
		$og_img = $p['meta']['og:image'][0] ?? ( $p['meta']['twitter:image'][0] ?? '' );
		echo '<section class="prx-card prsi-card"><h2 class="prx-card__title"><span class="fa-solid fa-share-nodes" aria-hidden="true"></span> ' . esc_html__( 'Social card preview', 'publishrank' ) . '</h2><div class="prsi-social">';
		if ( '' !== $og_img && preg_match( '#^https?://#', $og_img ) ) {
			echo '<img class="prsi-social__img" src="' . esc_url( $og_img ) . '" alt="" loading="lazy" referrerpolicy="no-referrer" />';
		} else {
			echo '<div class="prsi-social__img prsi-social__img--none">' . esc_html__( 'No image', 'publishrank' ) . '</div>';
		}
		echo '<div class="prsi-social__body"><div class="prsi-social__host">' . esc_html( strtoupper( $host ) ) . '</div><div class="prsi-social__title">' . esc_html( $cut( $p['meta']['og:title'][0] ?? $title, 90 ) ) . '</div><div class="prsi-social__desc">' . esc_html( $cut( $p['meta']['og:description'][0] ?? $desc, 140 ) ) . '</div></div></div></section>';
		echo '</div>';

		$checks = self::page_checks( $p, $url, $status, $x_robots );
		echo '<section class="prx-card"><h2 class="prx-card__title"><span class="fa-solid fa-list-check" aria-hidden="true"></span> ' . esc_html__( 'Page checks', 'publishrank' ) . '</h2>';
		echo '<dl class="prsi-facts">';
		$facts = array(
			__( 'HTTP status', 'publishrank' ) => $status ? (string) $status : '—',
			__( 'Canonical', 'publishrank' )   => $p['canonical'] ? implode( ' · ', $p['canonical'] ) : '—',
			__( 'Robots', 'publishrank' )      => trim( implode( ', ', $p['meta']['robots'] ?? array() ) . ( '' !== $x_robots ? ' (X-Robots-Tag: ' . $x_robots . ')' : '' ) ) ?: __( 'index, follow (default)', 'publishrank' ),
			__( 'Language', 'publishrank' )    => '' !== $p['lang'] ? $p['lang'] : '—',
			__( 'hreflang links', 'publishrank' ) => (string) count( $p['hreflang'] ),
			__( 'H1', 'publishrank' )          => $p['h1'] ? implode( ' · ', array_slice( $p['h1'], 0, 3 ) ) : '—',
		);
		foreach ( $facts as $k => $v ) {
			echo '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
		}
		echo '</dl>';
		$this->issue_list( $checks, __( 'No problems found in titles, descriptions, canonical, robots or social tags.', 'publishrank' ) );
		echo '</section>';
		$this->schema_report( SchemaValidator::check_blocks( $p['jsonld'] ), $url );
	}

	private function issue_list( array $issues, $empty ) {
		if ( ! $issues ) {
			echo '<p class="prsi-ok"><span class="fa-solid fa-circle-check" aria-hidden="true"></span> ' . esc_html( $empty ) . '</p>';
			return;
		}
		$order = array( 'error' => 0, 'warn' => 1, 'info' => 2 );
		usort( $issues, static function ( $a, $b ) use ( $order ) { return ( $order[ $a['level'] ] ?? 3 ) <=> ( $order[ $b['level'] ] ?? 3 ); } );
		$labels = array( 'error' => array( __( 'Error', 'publishrank' ), 'bad' ), 'warn' => array( __( 'Warning', 'publishrank' ), 'warn' ), 'info' => array( __( 'Suggestion', 'publishrank' ), 'info' ) );
		echo '<ul class="prsi-issues">';
		foreach ( $issues as $x ) {
			$l = $labels[ $x['level'] ] ?? $labels['info'];
			echo '<li class="is-' . esc_attr( $x['level'] ) . '">' . Suite::badge( $l[0], $l[1] ) . ' <strong>' . esc_html( $x['area'] ) . '</strong> — ' . esc_html( $x['msg'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		}
		echo '</ul>';
	}

	private function schema_report( array $r, $url ) {
		$s = $r['summary'];
		echo '<section class="prx-card"><h2 class="prx-card__title"><span class="fa-solid fa-diagram-project" aria-hidden="true"></span> ' . esc_html__( 'Structured data', 'publishrank' ) . '</h2>';
		echo '<div class="prsi-kpis"><span>' . Suite::badge( sprintf( /* translators: %d: count */ _n( '%d entity', '%d entities', count( $r['nodes'] ), 'publishrank' ), count( $r['nodes'] ) ), 'muted' ) . '</span><span>' . Suite::badge( sprintf( /* translators: %d: count */ _n( '%d error', '%d errors', (int) $s['error'], 'publishrank' ), (int) $s['error'] ), $s['error'] ? 'bad' : 'good' ) . '</span><span>' . Suite::badge( sprintf( /* translators: %d: count */ _n( '%d warning', '%d warnings', (int) $s['warn'], 'publishrank' ), (int) $s['warn'] ), $s['warn'] ? 'warn' : 'good' ) . '</span><span>' . Suite::badge( sprintf( /* translators: %d: count */ _n( '%d suggestion', '%d suggestions', (int) $s['info'], 'publishrank' ), (int) $s['info'] ), 'info' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		if ( '' !== $url ) {
			echo ' <a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( $url ) ) . '">' . esc_html__( 'Open in Google Rich Results Test', 'publishrank' ) . '</a> <a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://validator.schema.org/#url=' . rawurlencode( $url ) ) . '">' . esc_html__( 'Open in Schema.org validator', 'publishrank' ) . '</a>';
		}
		echo '</div>';
		if ( $r['nodes'] ) {
			echo '<table class="widefat striped prsi-nodes"><thead><tr><th scope="col">' . esc_html__( 'Type', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Name', 'publishrank' ) . '</th><th scope="col">@id</th><th scope="col">' . esc_html__( 'Status', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $r['nodes'] as $n ) {
				echo '<tr><td><code>' . esc_html( $n['type'] ) . '</code></td><td>' . esc_html( $n['name'] ) . '</td><td class="prsi-id">' . esc_html( $n['id'] ) . '</td><td>' . ( $n['errors'] ? Suite::badge( __( 'Needs fixing', 'publishrank' ), 'bad' ) : Suite::badge( __( 'Valid', 'publishrank' ), 'good' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			}
			echo '</tbody></table>';
		}
		$this->issue_list( $r['issues'], __( 'All structured data passed the checks.', 'publishrank' ) );
		foreach ( $r['blocks'] as $i => $b ) {
			/* translators: 1: number, 2: types */
			echo '<details class="prsi-json"><summary>' . esc_html( sprintf( __( 'JSON-LD block %1$d %2$s', 'publishrank' ), $i + 1, $b['types'] ? '(' . implode( ', ', array_slice( $b['types'], 0, 6 ) ) . ')' : '' ) ) . '</summary><pre><code>' . esc_html( $b['pretty'] ) . '</code></pre></details>';
		}
		echo '<p class="prx-help">' . esc_html__( 'These checks follow Google\'s documented required and recommended properties. Passing them means the markup is well formed; it does not guarantee a rich result.', 'publishrank' ) . '</p></section>';
	}
}
