<?php
/**
 * Google Preferred Sources tool: generates the publisher's Preferred Sources link, shows Google's official button
 * code or a clearly labelled custom link, and (opt-in) prints it at chosen places on the public site.
 * Google's script is loaded only when the publisher enables placement and chooses Google's button.
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class PreferredSourcesPage {
	const SLUG       = 'publishrank-google-preferred-sources';
	const OPTION     = 'publishrank_ps_settings';
	const META_SEEN  = 'publishrank_ps_seen';
	const HANDLE     = 'publishrank-google-preferred-sources';
	const SCRIPT_URL = 'https://news.google.com/swg/js/v1/publisher.js';
	const LINK_BASE  = 'https://www.google.com/preferences/source?q=';
	const DOCS_URL   = 'https://developers.google.com/search/docs/appearance/preferred-sources';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 16 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_publishrank_ps_save', array( $this, 'save' ) );
		// Front-end placement. Each callback returns immediately unless the publisher enabled it.
		add_action( 'wp_body_open', array( $this, 'place_top' ), 5 );
		add_filter( 'the_content', array( $this, 'place_content' ), 20 );
		add_shortcode( 'publishrank_preferred_source', array( $this, 'shortcode' ) );
		add_action( 'wp_footer', array( $this, 'place_footer' ), 5 );
		add_filter( 'script_loader_tag', array( $this, 'async_tag' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ menu / assets */

	/** True until the current user has opened this tool once; drives the "New" badges. */
	public static function is_new() {
		return ! get_user_meta( get_current_user_id(), self::META_SEEN, true );
	}

	public function menu() {
		$title = __( 'Google Preferred Sources', 'publishrank' );
		$label = $title;
		if ( self::is_new() ) {
			$label .= ' <span class="pr-menu-new">' . esc_html__( 'New', 'publishrank' ) . '</span>';
		}
		add_submenu_page( Branding::MENU_SLUG, $title, $label, 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page ) {
			return;
		}
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/preferred-sources.css';
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/preferred-sources.js';
		wp_enqueue_style( 'publishrank-preferred-sources', Branding::asset_url( 'css/preferred-sources.css' ), array( 'publishrank-seo-admin' ), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_enqueue_script( 'publishrank-preferred-sources', Branding::asset_url( 'js/preferred-sources.js' ), array(), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_localize_script(
			'publishrank-preferred-sources',
			'publishrankPS',
			array(
				'scriptUrl' => self::SCRIPT_URL,
				'linkBase'  => self::LINK_BASE,
				'i18n'      => array(
					'copied'       => __( 'Copied to the clipboard.', 'publishrank' ),
					'copyFailed'   => __( 'Copy failed. Select the text and copy it manually.', 'publishrank' ),
					'invalidUrl'   => __( 'Enter a valid http:// or https:// website address.', 'publishrank' ),
					'noUrl'        => __( 'No valid website address yet.', 'publishrank' ),
					'confirmReset' => __( 'Discard your edits and restore the default?', 'publishrank' ),
					'wGoogle'      => __( 'Google-supported markup detected.', 'publishrank' ),
					'wCustom'      => __( 'Custom link: independently styled, not Google’s interactive button.', 'publishrank' ),
					'wNoAttr'      => __( 'Warning: the required google-add-preferred-source-btn attribute is missing, so Google’s script has nothing to render.', 'publishrank' ),
					'wNoScript'    => __( 'Warning: the official publisher.js script tag is missing, so the Google button will not load.', 'publishrank' ),
					'wBadScript'   => __( 'Warning: a script tag points somewhere other than Google’s official publisher.js URL.', 'publishrank' ),
					'wInline'      => __( 'Not allowed: inline scripts, event-handler attributes and javascript: links cannot be saved.', 'publishrank' ),
					'wBalance'     => __( 'Warning: the markup looks incomplete (an opening or closing tag does not match).', 'publishrank' ),
					'wEmpty'       => __( 'The editor is empty. Restore the default code to start again.', 'publishrank' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ settings model */

	public static function placements() {
		return array(
			'top'    => __( 'Top of every page (right after the opening body tag)', 'publishrank' ),
			'before' => __( 'Above the article content', 'publishrank' ),
			'middle' => __( 'Middle of the article content', 'publishrank' ),
			'after'  => __( 'Below the article content', 'publishrank' ),
			'footer' => __( 'Footer of every page (just before the closing body tag)', 'publishrank' ),
		);
	}

	public static function show_on() {
		return array(
			'posts'    => __( 'Posts and other single content', 'publishrank' ),
			'pages'    => __( 'Pages', 'publishrank' ),
			'home'     => __( 'Home / blog page', 'publishrank' ),
			'archives' => __( 'Archives and search results', 'publishrank' ),
		);
	}

	public static function defaults() {
		return array(
			'site'     => '',
			'mode'     => 'custom',
			'theme'    => '',
			'lang'     => '',
			'custom'   => self::custom_defaults(),
			'code'     => '',
			'use_code' => false,
			'enabled'  => true,
			'place'    => array( 'after' ),
			'show'     => array( 'posts', 'pages' ),
			'align'    => 'center',
		);
	}

	/** Validate and complete a settings array. Unknown or invalid values fall back to the defaults. */
	public static function sanitize_settings( $in ) {
		$d   = self::defaults();
		$in  = is_array( $in ) ? $in : array();
		$out = $d;
		$out['site']     = isset( $in['site'] ) && is_string( $in['site'] ) ? $in['site'] : '';
		$out['mode']     = ! array_key_exists( 'mode', $in ) ? $d['mode'] : ( 'custom' === $in['mode'] ? 'custom' : 'default' );
		$out['theme']    = isset( $in['theme'] ) && in_array( $in['theme'], array( 'light', 'dark' ), true ) ? $in['theme'] : '';
		$out['lang']     = isset( $in['lang'] ) && is_string( $in['lang'] ) && preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $in['lang'] ) ? $in['lang'] : '';
		$out['custom']   = self::sanitize_custom( isset( $in['custom'] ) ? $in['custom'] : array() );
		$out['code']     = isset( $in['code'] ) && is_string( $in['code'] ) ? $in['code'] : '';
		$out['use_code'] = ! empty( $in['use_code'] ) && '' !== trim( $out['code'] );
		// Respect an explicitly saved checkbox value, but use the actual defaults when
		// the option has never been saved. Without this distinction a fresh install
		// silently turned the feature off because an absent checkbox looked unchecked.
		$out['enabled']  = array_key_exists( 'enabled', $in ) ? ! empty( $in['enabled'] ) : (bool) $d['enabled'];
		$out['place']    = isset( $in['place'] ) && is_array( $in['place'] ) ? array_values( array_intersect( array_keys( self::placements() ), $in['place'] ) ) : $d['place'];
		$out['show']     = isset( $in['show'] ) && is_array( $in['show'] ) ? array_values( array_intersect( array_keys( self::show_on() ), $in['show'] ) ) : $d['show'];
		$out['align']    = isset( $in['align'] ) && in_array( $in['align'], array( 'left', 'center', 'right' ), true ) ? $in['align'] : 'center';
		return $out;
	}

	/** Saved settings merged with defaults (cached per request). */
	public static function settings( $refresh = false ) {
		static $cache = null;
		if ( null === $cache || $refresh ) {
			$saved = get_option( self::OPTION, array() );
			$cache = self::sanitize_settings( is_array( $saved ) ? $saved : array() );
		}
		return $cache;
	}

	/** Canonical home URL of this WordPress site, used until the publisher saves their own value. */
	public static function detected_site() {
		return (string) home_url( '/' );
	}

	public static function site_value() {
		$s = self::settings();
		return '' !== trim( $s['site'] ) ? $s['site'] : self::detected_site();
	}

	/**
	 * Normalise a website address to a bare host. Returns array( 'host' => string|null, 'error' => string ).
	 * Only http(s) is accepted; paths, queries, fragments, ports and credentials are discarded or rejected.
	 */
	public static function normalize_host( $input ) {
		$input = trim( (string) $input );
		if ( '' === $input ) {
			return array( 'host' => null, 'error' => __( 'Enter your publication website address.', 'publishrank' ) );
		}
		if ( strlen( $input ) > 2048 || preg_match( '/[\x00-\x20\x7F<>"\'`\\\\]/', $input ) ) {
			return array( 'host' => null, 'error' => __( 'The address contains spaces or characters that are not allowed.', 'publishrank' ) );
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $input ) ) {
			if ( ! preg_match( '#^https?://#i', $input ) ) {
				return array( 'host' => null, 'error' => __( 'Only http:// and https:// addresses are accepted.', 'publishrank' ) );
			}
		} elseif ( 0 === strpos( $input, '//' ) ) {
			$input = 'https:' . $input;
		} elseif ( preg_match( '#^[a-z][a-z0-9+.\-]*:(?!\d+(?:[/?\#]|$))#i', $input ) ) {
			return array( 'host' => null, 'error' => __( 'Only http:// and https:// addresses are accepted.', 'publishrank' ) );
		} else {
			$input = 'https://' . $input;
		}
		$parts = wp_parse_url( $input );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array( 'host' => null, 'error' => __( 'That does not look like a valid website address.', 'publishrank' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return array( 'host' => null, 'error' => __( 'Remove the username or password from the address.', 'publishrank' ) );
		}
		$host = rtrim( function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $parts['host'], 'UTF-8' ) : strtolower( (string) $parts['host'] ), '.' );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) || false !== strpos( $host, '[' ) ) {
			return array( 'host' => null, 'error' => __( 'Use a domain name, not an IP address.', 'publishrank' ) );
		}
		$label = '[\p{L}\p{N}](?:[\p{L}\p{N}\-]{0,61}[\p{L}\p{N}])?';
		if ( strlen( $host ) > 253 || ! preg_match( '/^(?:' . $label . '\.)+' . $label . '$/u', $host ) ) {
			return array( 'host' => null, 'error' => __( 'Enter a public domain name such as example.com.', 'publishrank' ) );
		}
		return array( 'host' => $host, 'scheme' => ( isset( $parts['scheme'] ) && 'http' === strtolower( $parts['scheme'] ) ) ? 'http' : 'https', 'error' => '' );
	}

	/** Domain used for the Google link, or '' when the configured value is not valid. */
	public static function domain() {
		$result = self::normalize_host( self::site_value() );
		return null === $result['host'] ? '' : $result['host'];
	}

	public static function source_url( $domain = null ) {
		$domain = null === $domain ? self::domain() : $domain;
		return '' === $domain ? '' : self::LINK_BASE . rawurlencode( $domain );
	}

	public static function custom_defaults() {
		return array(
			'label'    => 'Add us as a Preferred Source on Google',
			'color'    => '#ffffff',
			'bg'       => '#1a73e8',
			'border'   => '#1a73e8',
			'radius'   => 6,
			'bwidth'   => 1,
			'size'     => 16,
			'weight'   => 600,
			'pad_v'    => 10,
			'pad_h'    => 18,
			'icon'     => 'star',
			'css_class' => '',
		);
	}

	public static function icons() {
		return array(
			'none'  => '',
			'star'  => '★',
			'plus'  => '+',
			'check' => '✓',
			'arrow' => '→',
		);
	}

	/** Sanitise the builder fields. Unknown or out-of-range values fall back to the recommended defaults. */
	public static function sanitize_custom( $in ) {
		$d   = self::custom_defaults();
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		$label = isset( $in['label'] ) ? sanitize_text_field( (string) $in['label'] ) : $d['label'];
		$out['label'] = '' === $label ? $d['label'] : ( function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 80 ) : substr( $label, 0, 80 ) );
		foreach ( array( 'color', 'bg', 'border' ) as $key ) {
			$hex         = isset( $in[ $key ] ) ? sanitize_hex_color( (string) $in[ $key ] ) : null;
			$out[ $key ] = $hex ? strtolower( $hex ) : $d[ $key ];
		}
		$range = array(
			'radius' => array( 0, 60 ),
			'bwidth' => array( 0, 10 ),
			'size'   => array( 10, 32 ),
			'pad_v'  => array( 0, 40 ),
			'pad_h'  => array( 0, 80 ),
		);
		foreach ( $range as $key => $bounds ) {
			$out[ $key ] = isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? max( $bounds[0], min( $bounds[1], (int) $in[ $key ] ) ) : $d[ $key ];
		}
		$weight        = isset( $in['weight'] ) ? (int) $in['weight'] : $d['weight'];
		$out['weight'] = in_array( $weight, array( 400, 500, 600, 700, 800 ), true ) ? $weight : $d['weight'];
		$icon          = isset( $in['icon'] ) ? (string) $in['icon'] : $d['icon'];
		$out['icon']   = array_key_exists( $icon, self::icons() ) ? $icon : $d['icon'];
		$classes       = array();
		foreach ( preg_split( '/\s+/', isset( $in['css_class'] ) ? (string) $in['css_class'] : '', -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$token = sanitize_html_class( $token );
			if ( '' !== $token ) {
				$classes[] = $token;
			}
		}
		$out['css_class'] = implode( ' ', array_slice( array_unique( $classes ), 0, 5 ) );
		return $out;
	}

	public static function custom() {
		$s = self::settings();
		return $s['custom'];
	}

	/** Google's documented markup, optionally with the documented data-theme / data-lang attributes. */
	public static function default_code( $theme = '', $lang = '' ) {
		return self::button_div( $theme, $lang ) . "\n" . '<script async src="' . self::SCRIPT_URL . '"></script>';
	}

	private static function button_div( $theme = '', $lang = '' ) {
		$attrs = 'google-add-preferred-source-btn';
		if ( in_array( $theme, array( 'light', 'dark' ), true ) ) {
			$attrs .= ' data-theme="' . $theme . '"';
		}
		if ( '' !== $lang && preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $lang ) ) {
			$attrs .= ' data-lang="' . $lang . '"';
		}
		return '<div ' . $attrs . '></div>';
	}

	/** Accessible, independently styled anchor to the Preferred Sources deeplink. */
	public static function custom_link_code( $domain, $c ) {
		$icons = self::icons();
		$style = 'display:inline-flex;align-items:center;gap:.5em;text-decoration:none;line-height:1.2;'
			. 'color:' . $c['color'] . ';background:' . $c['bg'] . ';border:' . $c['bwidth'] . 'px solid ' . $c['border'] . ';'
			. 'border-radius:' . $c['radius'] . 'px;font-size:' . $c['size'] . 'px;font-weight:' . $c['weight'] . ';'
			. 'padding:' . $c['pad_v'] . 'px ' . $c['pad_h'] . 'px;';
		$class = '' !== $c['css_class'] ? '  class="' . $c['css_class'] . '"' . "\n" : '';
		$href  = '' === $domain ? 'https://www.google.com/preferences/source?q=YOUR-DOMAIN' : self::source_url( $domain );
		$icon  = '' !== $icons[ $c['icon'] ] ? '<span aria-hidden="true">' . $icons[ $c['icon'] ] . '</span> ' : '';
		return '<a href="' . $href . '"' . "\n" . $class . '  target="_blank"' . "\n" . '  rel="noopener noreferrer"' . "\n" . '  style="' . $style . '">' . "\n    " . $icon . esc_html( $c['label'] ) . "\n" . '</a>';
	}

	/** Code shown in the advanced editor: the saved text, or the default template. */
	public static function editor_code() {
		$s = self::settings();
		return '' !== trim( $s['code'] ) ? $s['code'] : self::default_code( $s['theme'], $s['lang'] );
	}

	/**
	 * Inspect markup as text only; nothing is executed or rewritten.
	 * Returns array( 'errors' => string[] blocking save, 'warnings' => string[], 'mode' => 'google'|'custom'|'unknown' ).
	 */
	public static function analyze_code( $code ) {
		$errors   = array();
		$warnings = array();
		$code     = (string) $code;
		$allowed  = array(
			'div'    => array( 'google-add-preferred-source-btn', 'data-theme', 'data-lang', 'class', 'id', 'style', 'aria-label', 'role', 'title' ),
			'span'   => array( 'class', 'id', 'style', 'aria-hidden', 'aria-label', 'title' ),
			'p'      => array( 'class', 'id', 'style' ),
			'a'      => array( 'href', 'target', 'rel', 'class', 'id', 'style', 'aria-label', 'title', 'role' ),
			'script' => array( 'async', 'defer', 'src' ),
		);
		if ( '' === trim( $code ) ) {
			return array( 'errors' => array( __( 'The code is empty.', 'publishrank' ) ), 'warnings' => array(), 'mode' => 'unknown' );
		}
		if ( strlen( $code ) > 8192 ) {
			$errors[] = __( 'The code is longer than 8 KB.', 'publishrank' );
		}
		if ( ! wp_check_invalid_utf8( $code, true ) && '' !== $code ) {
			$errors[] = __( 'The code contains invalid characters.', 'publishrank' );
		}
		$has_attr   = false;
		$has_script = false;
		$has_anchor = false;
		$stack      = array();
		$void       = array();
		if ( preg_match_all( '/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/', $code, $tags, PREG_SET_ORDER ) ) {
			foreach ( $tags as $tag ) {
				$name = strtolower( $tag[2] );
				if ( ! isset( $allowed[ $name ] ) ) {
					/* translators: %s: HTML tag name. */
					$errors[] = sprintf( __( 'The <%s> tag is not allowed.', 'publishrank' ), $name );
					continue;
				}
				if ( '/' === $tag[1] ) {
					if ( empty( $stack ) || array_pop( $stack ) !== $name ) {
						$warnings[] = __( 'The markup looks incomplete: a closing tag does not match.', 'publishrank' );
					}
					continue;
				}
				if ( '/' !== substr( trim( $tag[3] ), -1 ) || in_array( $name, $void, true ) ) {
					$stack[] = $name;
				}
				if ( 'a' === $name ) {
					$has_anchor = true;
				}
				if ( 'script' === $name ) {
					$has_script = true;
				}
				if ( preg_match_all( '/([^\s=\/"\'>]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/', $tag[3], $attrs, PREG_SET_ORDER ) ) {
					foreach ( $attrs as $attr ) {
						$aname = strtolower( $attr[1] );
						$aval  = isset( $attr[2] ) ? trim( $attr[2], "\"'" ) : '';
						if ( 0 === strpos( $aname, 'on' ) || ! in_array( $aname, $allowed[ $name ], true ) ) {
							/* translators: %s: attribute name. */
							$errors[] = sprintf( __( 'The attribute "%s" is not allowed here.', 'publishrank' ), $aname );
							continue;
						}
						if ( 'google-add-preferred-source-btn' === $aname ) {
							$has_attr = true;
						} elseif ( 'src' === $aname && self::SCRIPT_URL !== $aval ) {
							$errors[] = __( 'Script tags may only load Google’s official publisher.js URL.', 'publishrank' );
						} elseif ( 'href' === $aname && ! preg_match( '#^(https?://|\#|/)#i', $aval ) ) {
							$errors[] = __( 'Links must start with http://, https://, / or #.', 'publishrank' );
						} elseif ( 'target' === $aname && ! in_array( $aval, array( '_blank', '_self' ), true ) ) {
							$errors[] = __( 'The link target must be _blank or _self.', 'publishrank' );
						} elseif ( 'style' === $aname && preg_match( '/expression|url\s*\(|@import|javascript|behavior|<|\\\\/i', $aval ) ) {
							$errors[] = __( 'Inline styles may not contain url(), @import, expressions or scripts.', 'publishrank' );
						} elseif ( 'data-theme' === $aname && ! in_array( $aval, array( 'light', 'dark' ), true ) ) {
							$warnings[] = __( 'Google documents data-theme values "light" and "dark" only.', 'publishrank' );
						}
					}
				}
			}
		}
		if ( ! empty( $stack ) ) {
			$warnings[] = __( 'The markup looks incomplete: a tag is not closed.', 'publishrank' );
		}
		if ( preg_match_all( '#<script\b[^>]*>(.*?)</script>#is', $code, $bodies ) ) {
			foreach ( $bodies[1] as $body ) {
				if ( '' !== trim( $body ) ) {
					$errors[] = __( 'Inline JavaScript is not allowed. Only the official publisher.js script tag can be saved.', 'publishrank' );
					break;
				}
			}
		}
		if ( preg_match( '/javascript\s*:|data\s*:text\/html/i', $code ) ) {
			$errors[] = __( 'javascript: and data: links are not allowed.', 'publishrank' );
		}
		if ( $has_attr && ! $has_script ) {
			$warnings[] = __( 'The google-add-preferred-source-btn element is present but Google’s publisher.js script tag is missing, so the button will not render.', 'publishrank' );
		}
		if ( $has_script && ! $has_attr ) {
			$warnings[] = __( 'Google’s script is present but the required google-add-preferred-source-btn attribute is missing.', 'publishrank' );
		}
		$mode = $has_attr || $has_script ? 'google' : ( $has_anchor ? 'custom' : 'unknown' );
		return array(
			'errors'   => array_values( array_unique( $errors ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
			'mode'     => $mode,
		);
	}


	/* ------------------------------------------------------------------ front-end placement */

	/** Markup to print (never includes the script tag) and whether Google's script is needed. */
	private static function output() {
		$s = self::settings();
		if ( $s['use_code'] ) {
			$code  = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $s['code'] );
			$needs = (bool) preg_match( '/google-add-preferred-source-btn/', $s['code'] );
			$allowed = array(
				'div'  => array( 'google-add-preferred-source-btn' => true, 'data-theme' => true, 'data-lang' => true, 'class' => true, 'id' => true, 'style' => true, 'aria-label' => true, 'role' => true, 'title' => true ),
				'span' => array( 'class' => true, 'id' => true, 'style' => true, 'aria-hidden' => true, 'aria-label' => true, 'title' => true ),
				'p'    => array( 'class' => true, 'id' => true, 'style' => true ),
				'a'    => array( 'href' => true, 'target' => true, 'rel' => true, 'class' => true, 'id' => true, 'style' => true, 'aria-label' => true, 'title' => true, 'role' => true ),
			);
			$keep = static function ( $props ) {
				return array_merge( (array) $props, array( 'display', 'align-items', 'gap', 'justify-content', 'text-decoration', 'line-height', 'border-radius' ) );
			};
			add_filter( 'safe_style_css', $keep );
			$html = wp_kses( $code, $allowed, array( 'http', 'https' ) );
			remove_filter( 'safe_style_css', $keep );
			return array( trim( preg_replace( '/\s*\n\s*/', ' ', $html ) ), $needs );
		}
		if ( 'custom' === $s['mode'] ) {
			$domain = self::domain();
			return '' === $domain ? array( '', false ) : array( preg_replace( '/\s*\n\s*/', ' ', self::custom_link_code( $domain, $s['custom'] ) ), false );
		}
		return array( self::button_div( $s['theme'], $s['lang'] ), true );
	}

	/** Should anything be printed for this placement on this request? */
	private static function active( $place ) {
		$s = self::settings();
		if ( ! $s['enabled'] || ! in_array( $place, $s['place'], true ) ) {
			return false;
		}
		if ( is_admin() || is_feed() || is_embed() || is_customize_preview() || is_robots() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'amp_is_request' ) && amp_is_request() ) ) {
			return false;
		}
		if ( is_front_page() || is_home() ) {
			$type = 'home';
		} elseif ( is_page() ) {
			$type = 'pages';
		} elseif ( is_singular() ) {
			$type = 'posts';
		} elseif ( is_archive() || is_search() ) {
			$type = 'archives';
		} else {
			return false;
		}
		if ( ! in_array( $type, $s['show'], true ) ) {
			return false;
		}
		return (bool) apply_filters( 'publishrank_ps_should_render', true, $place, $type );
	}

	/** In-flow wrapper: static position, full width, own margins, so it never overlaps theme content. */
	private static function slot( $place ) {
		list( $markup, $needs ) = self::output();
		if ( '' === $markup ) {
			return '';
		}
		$s     = self::settings();
		$flex  = array( 'left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end' );
		if ( $needs ) {
			wp_enqueue_script( self::HANDLE, self::SCRIPT_URL, array(), null, array( 'in_footer' => true, 'strategy' => 'async' ) );
		}
		return '<div class="pr-ps-slot pr-ps-slot--' . esc_attr( $place ) . '" style="clear:both;display:flex;flex-wrap:wrap;justify-content:' . esc_attr( $flex[ $s['align'] ] ) . ';position:static;width:100%;max-width:100%;box-sizing:border-box;margin:16px 0;padding:0;float:none;z-index:auto;">' . $markup . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from validated values or passed through wp_kses().
	}

	public function async_tag( $tag, $handle ) {
		if ( self::HANDLE === $handle && false === strpos( $tag, ' async' ) ) {
			$tag = str_replace( ' src=', ' async src=', $tag );
		}
		return $tag;
	}

	public function place_top() {
		if ( self::active( 'top' ) ) {
			echo self::slot( 'top' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/** Manual placement shortcode: [publishrank_preferred_source]. It works independently of automatic placement. */
	public function shortcode( $atts = array(), $content = null ) {
		return self::slot( 'shortcode' );
	}

	public function place_footer() {
		if ( self::active( 'footer' ) ) {
			echo self::slot( 'footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function place_content( $content ) {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() || false !== strpos( $content, 'pr-ps-slot' ) ) {
			return $content;
		}
		if ( self::active( 'before' ) ) {
			$content = self::slot( 'before' ) . $content;
		}
		if ( self::active( 'middle' ) ) {
			$content = self::insert_middle( $content, self::slot( 'middle' ) );
		}
		if ( self::active( 'after' ) ) {
			$content .= self::slot( 'after' );
		}
		return $content;
	}

	/** Insert after the middle paragraph, never inside a quote, list, table, figure or pre block. */
	private static function insert_middle( $content, $slot ) {
		if ( '' === $slot ) {
			return $content;
		}
		$parts = preg_split( '#(</p>)#i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$total = (int) floor( count( $parts ) / 2 );
		if ( $total < 2 ) {
			return $content . $slot;
		}
		$target = (int) ceil( $total / 2 );
		$out    = '';
		$count  = 0;
		$depth  = 0;
		$done   = false;
		for ( $i = 0, $n = count( $parts ); $i < $n; $i += 2 ) {
			$out .= $parts[ $i ];
			$depth += preg_match_all( '#<(?:blockquote|ul|ol|table|figure|pre)\b#i', $parts[ $i ] ) - preg_match_all( '#</(?:blockquote|ul|ol|table|figure|pre)>#i', $parts[ $i ] );
			if ( isset( $parts[ $i + 1 ] ) ) {
				$out .= $parts[ $i + 1 ];
				++$count;
				if ( ! $done && $count >= $target && $depth <= 0 ) {
					$out .= $slot;
					$done = true;
				}
			}
		}
		return $done ? $out : $out . $slot;
	}

	/* ------------------------------------------------------------------ save handler */

	private function back( $type, $message ) {
		AdminAssets::done( self::SLUG, $type, $message );
	}

	public function save() {
		AdminAssets::guard( 'publishrank_ps_save' );
		$uid = get_current_user_id();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by AdminAssets::guard().
		$op = isset( $_POST['ps_op'] ) ? sanitize_key( wp_unslash( $_POST['ps_op'] ) ) : 'save';
		if ( 'reset' === $op ) {
			delete_option( self::OPTION );
			delete_transient( 'publishrank_ps_draft_' . $uid );
			$this->back( 'success', __( 'Settings reset to the defaults. Automatic placement is enabled by default; review the selected locations below if you do not want the button displayed.', 'publishrank' ) );
		}
		$current = self::settings();
		if ( 'restore_code' === $op ) {
			$current['code']     = '';
			$current['use_code'] = false;
			update_option( self::OPTION, self::sanitize_settings( $current ), true );
			delete_transient( 'publishrank_ps_draft_' . $uid );
			$this->back( 'success', __( 'Default code restored.', 'publishrank' ) );
		}
		$in = isset( $_POST['ps'] ) && is_array( $_POST['ps'] ) ? wp_unslash( $_POST['ps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is validated below.
		// phpcs:enable
		$site   = isset( $in['site'] ) && is_scalar( $in['site'] ) ? sanitize_text_field( (string) $in['site'] ) : '';
		$result = self::normalize_host( $site );
		if ( null === $result['host'] ) {
			$this->back( 'error', $result['error'] . ' ' . __( 'Nothing was saved.', 'publishrank' ) );
		}
		$lang = isset( $in['lang'] ) && is_scalar( $in['lang'] ) ? trim( sanitize_text_field( (string) $in['lang'] ) ) : '';
		if ( '' !== $lang && ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $lang ) ) {
			$this->back( 'error', __( 'The language code is not valid (examples: en, pt-BR). Nothing was saved.', 'publishrank' ) );
		}
		$theme = isset( $in['theme'] ) && is_scalar( $in['theme'] ) ? sanitize_key( (string) $in['theme'] ) : '';
		$code  = isset( $in['code'] ) && is_scalar( $in['code'] ) ? str_replace( array( "\r\n", "\r" ), "\n", (string) $in['code'] ) : '';
		$custom_code = '';
		$warnings    = array();
		if ( '' !== trim( $code ) && trim( $code ) !== trim( self::default_code( $theme, $lang ) ) ) {
			$check = self::analyze_code( $code );
			if ( ! empty( $check['errors'] ) ) {
				set_transient( 'publishrank_ps_draft_' . $uid, $code, 300 );
				$this->back( 'error', __( 'Code not saved, and nothing else was changed: ', 'publishrank' ) . implode( ' ', $check['errors'] ) );
			}
			$custom_code = $code;
			$warnings    = $check['warnings'];
		}
		delete_transient( 'publishrank_ps_draft_' . $uid );
		$new = self::sanitize_settings(
			array(
				'site'     => $result['scheme'] . '://' . $result['host'],
				'mode'     => isset( $in['mode'] ) ? $in['mode'] : 'default',
				'theme'    => $theme,
				'lang'     => $lang,
				'custom'   => isset( $in['custom'] ) ? $in['custom'] : array(),
				'code'     => $custom_code,
				'use_code' => ! empty( $in['use_code'] ),
				'enabled'  => ! empty( $in['enabled'] ),
				'place'    => isset( $in['place'] ) && is_array( $in['place'] ) ? $in['place'] : array(),
				'show'     => isset( $in['show'] ) && is_array( $in['show'] ) ? $in['show'] : array(),
				'align'    => isset( $in['align'] ) ? $in['align'] : 'center',
			)
		);
		update_option( self::OPTION, $new, true );
		$msg = __( 'Settings saved.', 'publishrank' );
		if ( $new['enabled'] && empty( $new['place'] ) ) {
			$msg .= ' ' . __( 'No placement is selected, so nothing will be shown yet.', 'publishrank' );
		}
		if ( $warnings ) {
			$msg .= ' ' . __( 'Code warnings: ', 'publishrank' ) . implode( ' ', $warnings );
		}
		$this->back( 'success', $msg );
	}

	/* ------------------------------------------------------------------ rendering */

	private function copy_button( $target, $text ) {
		echo '<button type="button" class="button pr-ps-copy" data-pr-ps-copy-target="' . esc_attr( $target ) . '">' . esc_html( $text ) . '</button>';
	}

	private function code_block( $id, $code, $label ) {
		echo '<pre class="pr-ps-pre" tabindex="0" aria-label="' . esc_attr( $label ) . '"><code id="' . esc_attr( $id ) . '">' . esc_html( $code ) . '</code></pre>';
	}

	private function checkboxes( $name, $options, $selected ) {
		foreach ( $options as $key => $label ) {
			echo '<label class="pr-ps-check"><input type="checkbox" name="ps[' . esc_attr( $name ) . '][]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $selected, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$uid     = get_current_user_id();
		$was_new = self::is_new();
		if ( $was_new ) {
			update_user_meta( $uid, self::META_SEEN, 1 );
		}
		$s       = self::settings( true );
		$domain  = self::domain();
		$url     = self::source_url( $domain );
		$custom  = $s['custom'];
		$default = self::default_code( $s['theme'], $s['lang'] );
		$draft   = get_transient( 'publishrank_ps_draft_' . $uid );
		$editor  = is_string( $draft ) ? $draft : self::editor_code();
		$icons   = self::icons();

		echo '<div class="wrap publishrank-preferred-sources">';
		AdminAssets::header( __( 'Google Preferred Sources', 'publishrank' ), __( 'Choose Google’s button or a custom link once, then pick where it appears on your site.', 'publishrank' ) );
		AdminAssets::render_flash();
		$ps_status = array();
		if ( ! $s['enabled'] ) {
			$ps_status[] = __( 'Automatic placement is OFF. Enable “Add the button automatically to my site” and save.', 'publishrank' );
		} elseif ( empty( $s['place'] ) ) {
			$ps_status[] = __( 'No placement location is selected. Select at least one location and save.', 'publishrank' );
		} elseif ( empty( $s['show'] ) ) {
			$ps_status[] = __( 'No page types are selected. Select at least one page type and save.', 'publishrank' );
		} elseif ( 'custom' === $s['mode'] && '' === $domain ) {
			$ps_status[] = __( 'The publication domain is invalid, so the custom link cannot be generated.', 'publishrank' );
		} else {
			$ps_status[] = __( 'Settings are configured for automatic placement. Check a published URL that matches the selected page types and locations.', 'publishrank' );
		}
		echo '<div class="notice ' . ( ( ! $s['enabled'] || empty( $s['place'] ) || empty( $s['show'] ) || ( 'custom' === $s['mode'] && '' === $domain ) ) ? 'notice-warning' : 'notice-info' ) . '"><p><strong>' . esc_html__( 'Frontend status:', 'publishrank' ) . '</strong> ' . esc_html( implode( ' ', $ps_status ) ) . '</p><p>' . esc_html__( 'Manual placement shortcode: [publishrank_preferred_source]. If using Google’s official button, Google’s own script must load and recognize the publication; a custom link does not depend on that script.', 'publishrank' ) . '</p></div>';
		if ( $was_new ) {
			echo '<p><span class="pr-ps-new-pill">' . esc_html__( 'New', 'publishrank' ) . '</span> <span class="description">' . esc_html__( 'This tool was added in this version of PublishRank.', 'publishrank' ) . '</span></p>';
		}
		echo '<div class="screen-reader-text" id="pr-ps-status" role="status" aria-live="polite"></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="pr-ps-main"><input type="hidden" name="action" value="publishrank_ps_save" />';
		wp_nonce_field( 'publishrank_ps_save' );
		echo '<div class="pr-ps-grid">';

		/* 1. Website and link */
		echo '<section class="rhs-card pr-ps-card"><h2>' . esc_html__( '1. Your publication', 'publishrank' ) . '</h2>';
		echo '<p><label for="pr-ps-site"><strong>' . esc_html__( 'Publication Website URL', 'publishrank' ) . '</strong></label><br /><input type="text" inputmode="url" class="large-text code" id="pr-ps-site" name="ps[site]" value="' . esc_attr( self::site_value() ) . '" autocomplete="off" spellcheck="false" aria-describedby="pr-ps-site-help pr-ps-site-error" /></p>';
		echo '<p class="description" id="pr-ps-site-help">' . esc_html__( 'Pre-filled with this site’s home URL. Only the domain is used; paths, queries and fragments are removed.', 'publishrank' ) . '</p><p class="pr-ps-error" id="pr-ps-site-error" role="alert" hidden></p>';
		echo '<p><label for="pr-ps-link"><strong>' . esc_html__( 'Your Google Preferred Sources link', 'publishrank' ) . '</strong></label><br /><input type="text" readonly class="large-text code" id="pr-ps-link" value="' . esc_attr( $url ) . '" placeholder="' . esc_attr__( 'Enter a valid website address above', 'publishrank' ) . '" /></p>';
		echo '<p class="pr-ps-actions"><button type="button" class="button pr-ps-copy" data-pr-ps-copy-value="#pr-ps-link">' . esc_html__( 'Copy Link', 'publishrank' ) . '</button> <a class="button" id="pr-ps-open" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '"' . ( '' === $url ? ' hidden' : '' ) . '>' . esc_html__( 'Open Link', 'publishrank' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Generated locally. Entering a domain does not register or verify your publication with Google, and Google decides whether it recognizes your site.', 'publishrank' ) . '</p></section>';

		/* 2. Choose the button (once) */
		echo '<section class="rhs-card pr-ps-card" id="pr-ps-choose"><h2>' . esc_html__( '2. Choose your button', 'publishrank' ) . '</h2><fieldset class="pr-ps-modes"><legend class="screen-reader-text">' . esc_html__( 'Button type', 'publishrank' ) . '</legend>';
		echo '<label class="pr-ps-mode"><input type="radio" name="ps[mode]" value="default" ' . checked( $s['mode'], 'default', false ) . ' /> <strong>' . esc_html__( 'Default — Google’s official button', 'publishrank' ) . '</strong><span>' . esc_html__( 'Drawn and operated by Google’s own script; opens Google’s popup on your page.', 'publishrank' ) . '</span></label>';
		echo '<label class="pr-ps-mode"><input type="radio" name="ps[mode]" value="custom" ' . checked( $s['mode'], 'custom', false ) . ' /> <strong>' . esc_html__( 'Custom — your own styled link', 'publishrank' ) . '</strong><span>' . esc_html__( 'A link in your colors to your Preferred Sources page. Not Google’s official button.', 'publishrank' ) . '</span></label></fieldset>';

		echo '<div class="pr-ps-panel" id="pr-ps-panel-default">';
		echo '<p class="pr-ps-fields"><label for="pr-ps-theme">' . esc_html__( 'Theme', 'publishrank' ) . '</label> <select id="pr-ps-theme" name="ps[theme]"><option value="" ' . selected( $s['theme'], '', false ) . '>' . esc_html__( 'Default (light)', 'publishrank' ) . '</option><option value="light" ' . selected( $s['theme'], 'light', false ) . '>light</option><option value="dark" ' . selected( $s['theme'], 'dark', false ) . '>dark</option></select> <label for="pr-ps-lang">' . esc_html__( 'Language code', 'publishrank' ) . '</label> <input type="text" id="pr-ps-lang" name="ps[lang]" class="small-text" maxlength="20" value="' . esc_attr( $s['lang'] ) . '" placeholder="auto" /></p>';
		echo '<p class="description">' . esc_html__( 'Google documents only these two options (data-theme, data-lang). Leave them empty for the exact default code.', 'publishrank' ) . '</p>';
		echo '<p><span class="pr-ps-tag pr-ps-tag--google">' . esc_html__( 'Google-supported markup', 'publishrank' ) . '</span></p>';
		$this->code_block( 'pr-ps-default-code', $default, __( 'Default Google Preferred Sources code', 'publishrank' ) );
		echo '<p class="pr-ps-actions">';
		$this->copy_button( 'pr-ps-default-code', __( 'Copy HTML Code', 'publishrank' ) );
		echo ' <button type="button" class="button" id="pr-ps-preview-toggle" aria-expanded="false" aria-controls="pr-ps-preview">' . esc_html__( 'Preview Button', 'publishrank' ) . '</button></p>';
		echo '<div class="pr-ps-preview-box" id="pr-ps-preview" hidden><p><strong>' . esc_html__( 'Preview unavailable in the dashboard', 'publishrank' ) . '</strong></p><p>' . esc_html__( 'Google’s button is drawn by Google’s script on your public pages. PublishRank does not load that script in wp-admin, so this is a placeholder, not a working button. Check the real button on a published page.', 'publishrank' ) . '</p><div class="pr-ps-placeholder" aria-hidden="true">' . esc_html__( 'Google button appears here on your site', 'publishrank' ) . '</div></div>';
		echo '</div>';

		echo '<div class="pr-ps-panel" id="pr-ps-panel-custom"><div class="pr-ps-builder"><div class="pr-ps-form" id="pr-ps-form" data-domain="' . esc_attr( $domain ) . '">';
		echo '<p><label for="pr-ps-c-label">' . esc_html__( 'Button label', 'publishrank' ) . '</label><br /><input type="text" class="regular-text" id="pr-ps-c-label" name="ps[custom][label]" maxlength="80" value="' . esc_attr( $custom['label'] ) . '" data-pr-ps-field="label" /></p>';
		echo '<p><label for="pr-ps-c-css_class">' . esc_html__( 'Custom CSS class (optional)', 'publishrank' ) . '</label><br /><input type="text" class="regular-text" id="pr-ps-c-css_class" name="ps[custom][css_class]" maxlength="120" placeholder="my-button" value="' . esc_attr( $custom['css_class'] ) . '" data-pr-ps-field="css_class" /></p>';
		echo '<div class="pr-ps-row">';
		foreach ( array( 'color' => __( 'Text color', 'publishrank' ), 'bg' => __( 'Background color', 'publishrank' ), 'border' => __( 'Border color', 'publishrank' ) ) as $key => $label ) {
			echo '<p><label for="pr-ps-c-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br /><input type="color" id="pr-ps-c-' . esc_attr( $key ) . '" name="ps[custom][' . esc_attr( $key ) . ']" value="' . esc_attr( $custom[ $key ] ) . '" data-pr-ps-field="' . esc_attr( $key ) . '" /></p>';
		}
		echo '</div><div class="pr-ps-row">';
		$numbers = array(
			'radius' => array( __( 'Border radius (px)', 'publishrank' ), 0, 60 ),
			'bwidth' => array( __( 'Border width (px)', 'publishrank' ), 0, 10 ),
			'size'   => array( __( 'Font size (px)', 'publishrank' ), 10, 32 ),
			'pad_v'  => array( __( 'Padding top/bottom (px)', 'publishrank' ), 0, 40 ),
			'pad_h'  => array( __( 'Padding left/right (px)', 'publishrank' ), 0, 80 ),
		);
		foreach ( $numbers as $key => $def ) {
			echo '<p><label for="pr-ps-c-' . esc_attr( $key ) . '">' . esc_html( $def[0] ) . '</label><br /><input type="number" class="small-text" id="pr-ps-c-' . esc_attr( $key ) . '" name="ps[custom][' . esc_attr( $key ) . ']" min="' . (int) $def[1] . '" max="' . (int) $def[2] . '" value="' . (int) $custom[ $key ] . '" data-pr-ps-field="' . esc_attr( $key ) . '" /></p>';
		}
		echo '</div><div class="pr-ps-row"><p><label for="pr-ps-c-weight">' . esc_html__( 'Font weight', 'publishrank' ) . '</label><br /><select id="pr-ps-c-weight" name="ps[custom][weight]" data-pr-ps-field="weight">';
		foreach ( array( 400 => 'Regular (400)', 500 => 'Medium (500)', 600 => 'Semi-bold (600)', 700 => 'Bold (700)', 800 => 'Extra-bold (800)' ) as $w => $wl ) {
			echo '<option value="' . (int) $w . '" ' . selected( (int) $custom['weight'], $w, false ) . '>' . esc_html( $wl ) . '</option>';
		}
		echo '</select></p><p><label for="pr-ps-c-icon">' . esc_html__( 'Icon (optional)', 'publishrank' ) . '</label><br /><select id="pr-ps-c-icon" name="ps[custom][icon]" data-pr-ps-field="icon">';
		$icon_names = array( 'none' => __( 'None', 'publishrank' ), 'star' => __( 'Star', 'publishrank' ), 'plus' => __( 'Plus', 'publishrank' ), 'check' => __( 'Check mark', 'publishrank' ), 'arrow' => __( 'Arrow', 'publishrank' ) );
		foreach ( $icon_names as $ik => $il ) {
			echo '<option value="' . esc_attr( $ik ) . '" data-glyph="' . esc_attr( $icons[ $ik ] ) . '" ' . selected( $custom['icon'], $ik, false ) . '>' . esc_html( $il . ( '' !== $icons[ $ik ] ? ' ' . $icons[ $ik ] : '' ) ) . '</option>';
		}
		echo '</select></p></div></div>';
		echo '<div class="pr-ps-output"><h4>' . esc_html__( 'Live preview', 'publishrank' ) . '</h4><div class="pr-ps-preview-stage"><a href="#" id="pr-ps-live" tabindex="-1" aria-disabled="true"></a></div><p class="description">' . esc_html__( 'Preview only; the link does not navigate here.', 'publishrank' ) . '</p>';
		echo '<p><span class="pr-ps-tag pr-ps-tag--custom">' . esc_html__( 'Custom link, not Google’s official button', 'publishrank' ) . '</span></p>';
		$this->code_block( 'pr-ps-custom-code', self::custom_link_code( $domain, $custom ), __( 'Generated custom link code', 'publishrank' ) );
		echo '<p class="pr-ps-actions">';
		$this->copy_button( 'pr-ps-custom-code', __( 'Copy Custom Link Code', 'publishrank' ) );
		echo '</p></div></div></div>';

		echo '<details class="pr-ps-adv" id="pr-ps-advanced"' . ( $s['use_code'] || is_string( $draft ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Advanced: edit the code yourself (optional)', 'publishrank' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'The code is never executed in the dashboard. Allowed: div, span, p, a and Google’s official publisher.js script tag. Inline scripts and event handlers are rejected. Code is saved exactly as typed, or not at all.', 'publishrank' ) . '</p>';
		echo '<p><label for="pr-ps-code" class="screen-reader-text">' . esc_html__( 'Integration code', 'publishrank' ) . '</label><textarea id="pr-ps-code" name="ps[code]" class="large-text code" rows="8" spellcheck="false" aria-describedby="pr-ps-code-notes">' . esc_textarea( $editor ) . '</textarea></p>';
		echo '<div id="pr-ps-code-notes" class="pr-ps-notes" aria-live="polite"></div>';
		echo '<p><label><input type="checkbox" name="ps[use_code]" value="1" ' . checked( $s['use_code'], true, false ) . ' /> ' . esc_html__( 'Show my edited code on the site instead of the choice above', 'publishrank' ) . '</label></p>';
		echo '<p class="pr-ps-actions">';
		$this->copy_button( 'pr-ps-code', __( 'Copy Code', 'publishrank' ) );
		echo ' <button type="submit" name="ps_op" value="restore_code" class="button" formnovalidate data-pr-ps-confirm="1">' . esc_html__( 'Restore Default Code', 'publishrank' ) . '</button></p></details>';
		echo '</section>';

		/* 3. Placement */
		echo '<section class="rhs-card pr-ps-card" id="pr-ps-place"><h2>' . esc_html__( '3. Show it on my site', 'publishrank' ) . '</h2>';
		echo '<p><label class="pr-ps-check"><input type="checkbox" name="ps[enabled]" value="1" ' . checked( $s['enabled'], true, false ) . ' /> <strong>' . esc_html__( 'Add the button automatically to my site', 'publishrank' ) . '</strong></label></p>';
		echo '<div class="pr-ps-cols"><fieldset><legend><strong>' . esc_html__( 'Where', 'publishrank' ) . '</strong></legend>';
		$this->checkboxes( 'place', self::placements(), $s['place'] );
		echo '</fieldset><fieldset><legend><strong>' . esc_html__( 'On which pages', 'publishrank' ) . '</strong></legend>';
		$this->checkboxes( 'show', self::show_on(), $s['show'] );
		echo '</fieldset></div>';
		echo '<p><label for="pr-ps-align"><strong>' . esc_html__( 'Alignment', 'publishrank' ) . '</strong></label> <select id="pr-ps-align" name="ps[align]"><option value="left" ' . selected( $s['align'], 'left', false ) . '>' . esc_html__( 'Left', 'publishrank' ) . '</option><option value="center" ' . selected( $s['align'], 'center', false ) . '>' . esc_html__( 'Center', 'publishrank' ) . '</option><option value="right" ' . selected( $s['align'], 'right', false ) . '>' . esc_html__( 'Right', 'publishrank' ) . '</option></select></p>';
		echo '<p class="description">' . esc_html__( 'The button is placed in the normal page flow with its own spacing, so it sits between your content instead of on top of it. The top placement uses the standard wp_body_open hook, which most modern themes support; the middle placement is inserted after a paragraph, never inside a list, quote or table. Google’s script is added to the page only when Google’s button is shown, and nothing is added to the dashboard or to pages where no placement applies.', 'publishrank' ) . '</p></section>';

		echo '</div>';
		echo '<p class="pr-ps-actions pr-ps-savebar"><button type="submit" name="ps_op" value="save" class="button button-primary button-large">' . esc_html__( 'Save settings', 'publishrank' ) . '</button> <button type="submit" name="ps_op" value="reset" class="button" formnovalidate data-pr-ps-confirm="1">' . esc_html__( 'Reset everything to default', 'publishrank' ) . '</button> <button type="button" class="button" id="pr-ps-show-guide">' . esc_html__( 'Show Installation Instructions', 'publishrank' ) . '</button></p>';
		echo '</form>';

		/* 4. Guide */
		echo '<section class="rhs-card pr-ps-card" id="pr-ps-guide-card"><h2>' . esc_html__( 'Installation guide', 'publishrank' ) . '</h2><details id="pr-ps-guide"><summary>' . esc_html__( 'Automatic or manual installation', 'publishrank' ) . '</summary>';
		echo '<p>' . esc_html__( 'Automatic: tick “Add the button automatically”, choose the places, and save. PublishRank prints it for you.', 'publishrank' ) . '</p><p>' . esc_html__( 'Manual:', 'publishrank' ) . '</p><ol class="pr-ps-steps">';
		echo '<li>' . esc_html__( 'Open Google Preferred Sources with your generated link.', 'publishrank' ) . '</li><li>' . esc_html__( 'Check that Google recognizes your publication.', 'publishrank' ) . '</li><li>' . esc_html__( 'Choose Google’s official button or your own styled link above.', 'publishrank' ) . '</li><li>' . esc_html__( 'For automatic placement, enable the checkbox, choose locations, and save.', 'publishrank' ) . '</li><li>' . esc_html__( 'For manual placement, add the shortcode [publishrank_preferred_source] to a post, page, or shortcode block.', 'publishrank' ) . '</li><li>' . esc_html__( 'Test it on a published page.', 'publishrank' ) . '</li></ol>';
		echo '<p class="description">' . esc_html__( 'Availability and behavior depend on Google’s own implementation and publication recognition. Do not use manual and automatic placement together or the button will appear twice.', 'publishrank' ) . '</p>';
		echo '<p><a href="' . esc_url( self::DOCS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google’s Preferred Sources documentation', 'publishrank' ) . '</a></p></details></section>';
		echo '</div>';
	}
}
