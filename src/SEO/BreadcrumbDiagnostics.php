<?php
/**
 * Breadcrumb diagnostics.
 *
 *  - analyze() is a pure function: it turns saved settings + the HTML/headers of a fetched page (+ an optional probe report
 *    printed by the plugin during that very request) into findings. Each finding says whether it is CONFIRMED (a fact read from
 *    settings, markup or the probe) or only a CANDIDATE cause, and gives the next practical step.
 *  - The probe is an HTML comment the plugin prints in wp_footer ONLY when the request carries a one-time token that an
 *    administrator's diagnostic run created (a transient, two minutes). It reports item count, registered placement hooks and whether the
 *    trail was printed. No remote request is ever made from the front end.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

final class BreadcrumbDiagnostics {
	const PROBE_PARAM  = 'pr_bc_probe';
	const PROBE_PREFIX = 'publishrank_bc_probe_';

	/** Known breadcrumb markup and the renderer that produces it. */
	public static function markers() {
		return array(
			'publishrank-breadcrumbs' => 'PublishRank',
			'yoast-breadcrumb'        => 'Yoast SEO',
			'wpseo-breadcrumb'        => 'Yoast SEO',
			'rank-math-breadcrumb'    => 'Rank Math',
			'aioseo-breadcrumbs'      => 'All in One SEO',
			'woocommerce-breadcrumb'  => 'WooCommerce',
			'jeg_breadcrumbs'         => 'JNews theme',
		);
	}

	/* ---------------------------------------------------------------- probe (front end) */

	public function register_probe() {
		add_action( 'wp_footer', array( $this, 'print_probe' ), 99999 );
	}

	/** Create a one-time probe token (administrator action). */
	public static function new_token() {
		$token = wp_generate_password( 20, false, false );
		set_transient( self::PROBE_PREFIX . $token, 1, 2 * MINUTE_IN_SECONDS );
		return $token;
	}

	public function print_probe() {
		if ( empty( $_GET[ self::PROBE_PARAM ] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorised by a one-time server-side token.
			return; }
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET[ self::PROBE_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token || strlen( $token ) > 40 || false === get_transient( self::PROBE_PREFIX . $token ) ) {
			return; }
		delete_transient( self::PROBE_PREFIX . $token );
		$engine = new BreadcrumbEngine();
		$report = array(
			'context'   => BreadcrumbEngine::context_key(),
			'supported' => BreadcrumbEngine::context_supported(),
			'visible'   => BreadcrumbEngine::visible_enabled(),
			'placement' => BreadcrumbEngine::placement(),
			'hooks'     => BreadcrumbEngine::registered_hooks(),
			'items'     => BreadcrumbEngine::context_supported() ? count( $engine->items() ) : 0,
			'printed'   => BreadcrumbEngine::was_printed(),
			'schema'    => BreadcrumbEngine::schema_enabled(),
			'owner'     => BreadcrumbEngine::external_schema_owner(),
			'block'     => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'elementor' => (bool) did_action( 'elementor/loaded' ),
			'theme'     => get_template(),
		);
		echo "\n<!-- publishrank-breadcrumb-probe:" . base64_encode( (string) wp_json_encode( $report ) ) . " -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64 payload.
	}

	/* ---------------------------------------------------------------- HTML inspection (pure) */

	/** @return array{renderers:array<string,int>,other:string[],json_ld:int} Kept for compatibility with BreadcrumbsPage::detect(). */
	public static function detect( $html ) {
		$html  = (string) $html;
		$found = array();
		$known = array();
		foreach ( self::markers() as $needle => $name ) {
			$count = preg_match_all( '/class\s*=\s*(["\'])[^"\']*\b' . preg_quote( $needle, '/' ) . '\b[^"\']*\1/i', $html );
			if ( $count ) {
				$found[ $name ] = max( isset( $found[ $name ] ) ? $found[ $name ] : 0, (int) $count ); // Two class names of one renderer are one trail.
				$known[]        = $needle;
			}
		}
		$other = array();
		if ( preg_match_all( '/class\s*=\s*(["\'])([^"\']*breadcrumb[^"\']*)\1/i', $html, $m ) ) {
			foreach ( $m[2] as $classes ) {
				$is_known = false;
				foreach ( $known as $needle ) {
					if ( false !== stripos( $classes, $needle ) ) {
						$is_known = true;
						break;
					}
				}
				// Child elements of a trail (items, separators, links) carry "breadcrumb" in their class too; only report containers.
				if ( ! $is_known && ! preg_match( '/(__|--|-item|-separator|-link|-sep|_item|_sep)/i', $classes ) ) {
					$other[ sanitize_text_field( substr( $classes, 0, 80 ) ) ] = true;
				}
			}
		}
		$schema = self::schema_summary( $html );
		return array(
			'renderers' => $found,
			'other'     => array_keys( $other ),
			'json_ld'   => $schema['count'],
		);
	}

	/**
	 * BreadcrumbList objects in the page's JSON-LD (decoded, so a quoted string elsewhere is not counted) and the references to them.
	 *
	 * @return array{count:int,ids:string[],refs:string[],dangling:string[]}
	 */
	public static function schema_summary( $html ) {
		$out = array(
			'count'    => 0,
			'ids'      => array(),
			'refs'     => array(),
			'dangling' => array(),
		);
		if ( ! preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', (string) $html, $blocks ) ) {
			return $out; }
		$walk = static function ( $node ) use ( &$walk, &$out ) {
			if ( ! is_array( $node ) ) {
				return; }
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( in_array( 'BreadcrumbList', $type, true ) ) {
				++$out['count'];
				if ( isset( $node['@id'] ) ) {
					$out['ids'][] = (string) $node['@id']; }
			}
			if ( isset( $node['breadcrumb']['@id'] ) ) {
				$out['refs'][] = (string) $node['breadcrumb']['@id']; }
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$walk( $child ); }
			}
		};
		foreach ( $blocks[1] as $raw ) {
			$data = json_decode( html_entity_decode( trim( $raw ), ENT_QUOTES, 'UTF-8' ), true );
			if ( is_array( $data ) ) {
				$walk( $data ); }
		}
		$out['dangling'] = array_values( array_diff( array_unique( $out['refs'] ), $out['ids'] ) );
		return $out;
	}

	/** Number of PublishRank trails, their list items, whether the stylesheet is linked and whether a trail is hidden inline. */
	public static function publishrank_markup( $html ) {
		$html  = (string) $html;
		$count = preg_match_all( '/<nav\b[^>]*class\s*=\s*(["\'])[^"\']*\bpublishrank-breadcrumbs\b[^"\']*\1[^>]*>(.*?)<\/nav>/is', $html, $m );
		$items = 0;
		$hid   = false;
		if ( $count ) {
			foreach ( $m[0] as $i => $nav ) {
				$items = max( $items, preg_match_all( '/<li\b/i', $m[2][ $i ] ) );
				if ( preg_match( '/<nav\b[^>]*(\bhidden\b|display\s*:\s*none|visibility\s*:\s*hidden)/i', $nav ) ) {
					$hid = true; }
			}
		}
		return array(
			'count'      => (int) $count,
			'items'      => (int) $items,
			'css_linked' => (bool) preg_match( '/<link\b[^>]*breadcrumbs(\.min)?\.css/i', $html ),
			'hidden'     => $hid,
		);
	}

	/** Probe report printed by the plugin during the fetched request, or null when absent (cached page, or the plugin did not run). */
	public static function read_probe( $html ) {
		if ( ! preg_match( '/<!-- publishrank-breadcrumb-probe:([A-Za-z0-9+\/=]+) -->/', (string) $html, $m ) ) {
			return null; }
		$data = json_decode( (string) base64_decode( $m[1], true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return is_array( $data ) ? $data : null;
	}

	/** Cache indicators in the response headers. @return string[] Names of headers that suggest a cached response. */
	public static function cache_hints( $headers ) {
		$hints = array();
		foreach ( (array) $headers as $name => $value ) {
			$name  = strtolower( (string) $name );
			$value = strtolower( is_array( $value ) ? implode( ',', $value ) : (string) $value );
			if ( in_array( $name, array( 'x-cache', 'x-cache-status', 'cf-cache-status', 'x-litespeed-cache', 'x-sg-cache', 'x-proxy-cache', 'x-wp-cf-super-cache', 'x-kinsta-cache', 'x-nginx-cache' ), true ) && preg_match( '/hit|cached/', $value ) ) {
				$hints[] = $name; }
			if ( 'age' === $name && (int) $value > 0 ) {
				$hints[] = 'age'; }
		}
		return $hints;
	}

	/* ---------------------------------------------------------------- analysis (pure) */

	private static function f( $code, $level, $confirmed, $message, $next = '' ) {
		return array(
			'code'      => $code,
			'level'     => $level, // ok | info | warn | error.
			'confirmed' => (bool) $confirmed,
			'message'   => $message,
			'next'      => $next,
		);
	}

	/**
	 * Analyse one tested page.
	 *
	 * @param array $page type (article|page|archive), url, status (int), error (string), headers (array), html (string),
	 *                    settings (visible, placement, hook, schema, schema_master) and expects_probe (bool).
	 * @return array{type:string,url:string,status:int,conclusive:bool,findings:array<int,array>,probe:?array}
	 */
	public static function analyze( array $page ) {
		$type     = isset( $page['type'] ) ? (string) $page['type'] : 'article';
		$status   = isset( $page['status'] ) ? (int) $page['status'] : 0;
		$settings = isset( $page['settings'] ) && is_array( $page['settings'] ) ? $page['settings'] : array();
		$html     = isset( $page['html'] ) ? (string) $page['html'] : '';
		$result   = array(
			'type'       => $type,
			'url'        => isset( $page['url'] ) ? (string) $page['url'] : '',
			'status'     => $status,
			'conclusive' => true,
			'findings'   => array(),
			'probe'      => null,
		);
		$f = array();

		if ( ! empty( $page['error'] ) || 200 !== $status ) {
			$why   = ! empty( $page['error'] ) ? (string) $page['error'] : sprintf( 'HTTP %d', $status );
			$f[]   = self::f( 'fetch_failed', 'error', true, 'The page could not be fetched as a visitor would see it (' . $why . '). Nothing can be concluded about the markup.', 'Check that the site can reach its own address (loopback, firewall, basic-auth or maintenance mode), then run the check again.' );
			$result['findings']   = $f;
			$result['conclusive'] = false;
			return $result;
		}

		$visible   = ! empty( $settings['visible'] );
		$placement = isset( $settings['placement'] ) ? (string) $settings['placement'] : 'auto';
		$probe     = self::read_probe( $html );
		$result['probe'] = $probe;
		$pr        = self::publishrank_markup( $html );
		$det       = self::detect( $html );
		$schema    = self::schema_summary( $html );

		// 1-3 settings (confirmed facts read from the saved options).
		if ( ! $visible ) {
			$f[] = self::f( 'visible_disabled', 'info', true, 'Visible breadcrumbs are switched off in PublishRank, so PublishRank prints no visible trail (including the shortcode and block).', 'Turn on “Print the breadcrumb trail on the site” if you want a visible trail.' );
		} elseif ( 'manual' === $placement ) {
			$f[] = self::f( 'placement_manual', 'info', true, 'Placement is set to Manual: PublishRank prints a trail only where the shortcode [publishrank_breadcrumbs] or the Breadcrumbs block is placed.', 'Add the shortcode to the template (for Elementor, a Shortcode widget) or choose Automatic.' );
		} elseif ( 'schema_only' === $placement ) {
			$f[] = self::f( 'placement_schema_only', 'info', true, 'Placement is set to Structured data only: no visible trail is printed automatically.', 'Choose Automatic for a visible trail.' );
		}

		$should_print = $visible && in_array( $placement, array( 'auto', 'content', 'hook' ), true );

		// 4-5 probe facts (printed by the plugin during the fetched request).
		if ( $should_print ) {
			if ( null === $probe ) {
				$result['conclusive'] = false;
				$f[] = self::f( 'probe_missing', 'warn', false, 'The plugin’s diagnostic note is missing from the fetched page. The page may have been served from a cache, or the footer is not printed by the template, so the cause cannot be confirmed.', 'Purge your page/CDN cache, check the template calls wp_footer(), and run the check again.' );
			} else {
				if ( empty( $probe['supported'] ) ) {
					$f[] = self::f( 'context_unsupported', 'info', true, 'This kind of page has no breadcrumb trail (the front page, search, 404 and author/date archives are excluded on purpose).', '' );
				} elseif ( empty( $probe['hooks'] ) ) {
					$f[] = self::f( 'auto_not_registered', 'warn', true, 'No automatic placement is registered for this page with placement “' . $placement . '” (for example “Above the content” does not apply to archives, or “Theme hook” has no hook name).', 'Choose Automatic, or enter a valid theme hook name.' );
				} elseif ( (int) $probe['items'] < 2 ) {
					$f[] = self::f( 'no_items', 'warn', true, 'PublishRank generated fewer than two valid breadcrumb items for this page (' . (int) $probe['items'] . '), so no trail is printed or emitted.', 'Check the page’s parent pages, primary category and the “invalid item” setting; the item list is shown in the preview.' );
				} elseif ( empty( $probe['printed'] ) && 0 === $pr['count'] ) {
					$f[] = self::f( 'not_printed', 'warn', true, 'PublishRank registered placement hooks (' . implode( ', ', array_map( 'strval', (array) $probe['hooks'] ) ) . ') and has ' . (int) $probe['items'] . ' items, but none of those hooks ran in this template.', 'This template does not use the standard content, title or loop hooks. Place the shortcode or Breadcrumbs block in the template, or choose Theme hook and enter a hook the theme fires.' );
				}
			}
		}

		// 6 / 9 PublishRank markup.
		if ( $pr['count'] > 0 ) {
			$f[] = self::f( 'publishrank_markup_present', 'ok', true, 'PublishRank breadcrumb markup is present in the returned HTML (' . $pr['count'] . ' trail, ' . $pr['items'] . ' items).', '' );
			if ( ! $pr['css_linked'] ) {
				$f[] = self::f( 'css_not_linked', 'warn', true, 'The PublishRank breadcrumb stylesheet link was not found in the returned HTML (it may be printed late, combined or deferred by an optimisation plugin).', 'If the trail is not visible, exclude breadcrumbs.css from CSS combining/defer settings.' );
			}
			if ( $pr['hidden'] ) {
				$f[] = self::f( 'hidden_inline', 'warn', true, 'The trail carries an inline hidden/display:none rule.', 'Remove the rule or the plugin/template that adds it.' );
			}
			$f[] = self::f( 'css_inspection', 'info', false, 'Markup being present does not prove it is visible: theme or Elementor CSS can still hide or move it. This tool cannot read computed styles.', 'In the browser, inspect nav.publishrank-breadcrumbs and check display, visibility, height and overflow of it and its parents.' );
		} elseif ( $should_print && null !== $probe && (int) $probe['items'] >= 2 && ! empty( $probe['printed'] ) ) {
			$f[] = self::f( 'printed_not_in_html', 'warn', false, 'The plugin reports it printed the trail, but the HTML returned to this check has no PublishRank trail. An output filter, cache or the template may have removed it.', 'Disable HTML minify/optimisation temporarily and run the check again.' );
		}

		// 7 / 8 other renderers.
		$others = array_diff( array_keys( $det['renderers'] ), array( 'PublishRank' ) );
		if ( $others ) {
			$f[] = self::f( 'other_renderer', 'info', true, 'Breadcrumb markup from another source was found: ' . implode( ', ', $others ) . '. Its separator is only changed by PublishRank for plugins with a supported separator filter.', 'Keep one visible trail: switch the other renderer off or choose Manual/Structured data only in PublishRank.' );
		}
		if ( $det['other'] ) {
			$f[] = self::f( 'theme_or_unknown_renderer', 'info', true, 'Breadcrumb markup with an unrecognised class was found (' . implode( ', ', array_slice( $det['other'], 0, 3 ) ) . '). Whether it comes from the theme or a plugin cannot be determined here; PublishRank cannot change its separator.', 'Check the theme’s breadcrumb setting, or remove the theme/Elementor breadcrumb widget if you use PublishRank’s.' );
		}

		// 11 multiple visible trails.
		$visible_trails = $pr['count'] + array_sum( array_diff_key( $det['renderers'], array( 'PublishRank' => 1 ) ) ) + count( $det['other'] );
		if ( $visible_trails > 1 ) {
			$f[] = self::f( 'multiple_visible', 'warn', true, 'More than one visible breadcrumb trail is in the page (' . $visible_trails . ').', 'Remove or disable all but one source (for example the theme widget, or set PublishRank to Manual).' );
		}

		// 10 / 12 JSON-LD.
		if ( $schema['count'] > 0 ) {
			$f[] = self::f( 'schema_present', 'ok', true, 'BreadcrumbList JSON-LD is present (' . $schema['count'] . '). JSON-LD alone does not prove a visible trail, and it does not guarantee breadcrumbs in search results.', '' );
		} else {
			$expected = ! empty( $settings['schema'] ) && ! empty( $settings['schema_master'] );
			$f[]      = self::f( 'schema_absent', $expected ? 'warn' : 'info', true, $expected ? 'No BreadcrumbList JSON-LD was found although PublishRank’s breadcrumb structured data is enabled (a trail with fewer than two items, an external owner or a cache can explain this).' : 'No BreadcrumbList JSON-LD was found (breadcrumb structured data is switched off, or another plugin owns it).', $expected ? 'See the item count above and the schema ownership note; purge caches.' : '' );
		}
		if ( $schema['count'] > 1 ) {
			$f[] = self::f( 'multiple_schema', 'warn', true, 'More than one BreadcrumbList object is present (' . $schema['count'] . ').', 'Keep one source: switch off breadcrumb structured data in PublishRank or in the other SEO plugin.' );
		}
		if ( $schema['dangling'] ) {
			$f[] = self::f( 'dangling_schema_reference', 'warn', true, 'A WebPage points to a BreadcrumbList that is not in the page: ' . implode( ', ', array_slice( $schema['dangling'], 0, 2 ) ) . '.', 'Another plugin or filter may have removed it; check the schema sources.' );
		}

		// 13 cache.
		$cache = self::cache_hints( isset( $page['headers'] ) ? $page['headers'] : array() );
		if ( $cache ) {
			$f[] = self::f( 'cached_response', 'warn', false, 'The response headers suggest a cached page (' . implode( ', ', $cache ) . '); it may predate your latest settings.', 'Purge the page cache/CDN and run the check again.' );
		}

		$result['findings'] = $f;
		return $result;
	}
}
