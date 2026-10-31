<?php
/**
 * 2.9.97: breadcrumb placement (classic, block, Elementor hook callbacks, archives, manual), duplicate prevention, visibility,
 * CSS registration, schema ownership/references, and the diagnostic analyzer and probe.
 *
 * Elementor itself is not installed in the test environment: the Elementor tests call PublishRank's callbacks for the documented
 * Elementor hooks directly, so they verify PublishRank's behaviour, not Elementor's runtime.
 */

use PublishRankSEO\Admin\BreadcrumbsPage;
use PublishRankSEO\SEO\BreadcrumbDiagnostics;
use PublishRankSEO\SEO\BreadcrumbEngine;

function pr08_post( $args = array() ) {
	update_option( 'publishrank_seo_publication_name', 'Test Site' );
	update_option( 'publishrank_seo_publisher_name', 'Test Site' );
	return pr_make_post( array_merge( array( 'post_title' => 'Hello World', 'post_name' => 'hello-world' ), $args ), 'News' );
}

function pr08_navs( $html ) {
	return preg_match_all( '/<nav\b[^>]*class="[^"]*\bpublishrank-breadcrumbs\b/', (string) $html );
}

function pr08_go( $url, $placement = 'auto' ) {
	update_option( 'publishrank_breadcrumb_placement', $placement );
	pr_go_to( $url );
}

function pr08_page( $overrides = array() ) {
	return array_merge(
		array(
			'type'     => 'article',
			'url'      => 'https://example.test/a/',
			'status'   => 200,
			'error'    => '',
			'headers'  => array(),
			'html'     => '<html><body></body></html>',
			'settings' => array( 'visible' => true, 'placement' => 'auto', 'schema' => true, 'schema_master' => true ),
		),
		$overrides
	);
}

function pr08_codes( $analysis ) {
	return array_column( $analysis['findings'], 'code' );
}

function pr08_probe( $report ) {
	return '<!-- publishrank-breadcrumb-probe:' . base64_encode( wp_json_encode( $report ) ) . ' -->';
}

return array(
	'classic single post: the content filter prints one trail before the content (placement "content")' => function () {
		$id = pr08_post();
		pr08_go( get_permalink( $id ), 'content' );
		$html = pr_content();
		pr_same( 1, pr08_navs( $html ), 'one trail' );
		pr_same( 0, strpos( $html, '<nav' ), 'trail comes first' );
	},
	'automatic placement on a standard page prints the page trail with its parent' => function () {
		$parent = pr08_post( array( 'post_type' => 'page', 'post_title' => 'Parent', 'post_name' => 'parent' ) );
		$child  = pr08_post( array( 'post_type' => 'page', 'post_title' => 'Child', 'post_name' => 'child', 'post_parent' => $parent ) );
		pr08_go( get_permalink( $child ), 'content' );
		$html = pr_content();
		pr_contains( '>Parent<', $html, 'parent crumb' );
		pr_contains( 'aria-current="page">Child<', $html, 'current crumb' );
	},
	'block theme: the trail goes before the post title block, never inside a Query Loop, and falls back to post content' => function () {
		$id = pr08_post();
		pr08_go( get_permalink( $id ) );
		$e = new BreadcrumbEngine();
		$title = $e->before_title_block( '<h1>Hello World</h1>', array( 'blockName' => 'core/post-title' ), null );
		pr_same( 0, strpos( $title, '<nav' ), 'before title' );
		pr_contains( '<h1>Hello World</h1>', $title );
		BreadcrumbEngine::reset();
		$loop = new WP_Block( array( 'blockName' => 'core/post-title' ), array( 'queryId' => 3 ) );
		pr_same( '<h1>x</h1>', $e->before_title_block( '<h1>x</h1>', array( 'blockName' => 'core/post-title' ), $loop ), 'query loop item untouched' );
		pr_same( '<p>b</p>', $e->before_title_block( '<p>b</p>', array( 'blockName' => 'core/paragraph' ), null ), 'other blocks untouched' );
		$fallback = $e->before_title_block( '<div>content</div>', array( 'blockName' => 'core/post-content' ), null );
		pr_same( 0, strpos( $fallback, '<nav' ), 'post-content fallback when no title block printed it' );
		pr_same( '<h1>again</h1>', $e->before_title_block( '<h1>again</h1>', array( 'blockName' => 'core/post-title' ), null ), 'second title block does not print a second trail' );
	},
	'Elementor: title widget and page-template hooks print once; unrelated widgets are untouched' => function () {
		$id = pr08_post();
		pr08_go( get_permalink( $id ) );
		$title = new class() {
			public function get_name() {
				return 'theme-post-title'; }
		};
		$other = new class() {
			public function get_name() {
				return 'heading'; }
		};
		$e = new BreadcrumbEngine();
		pr_same( '<h2>x</h2>', $e->before_elementor_title( '<h2>x</h2>', $other ), 'other widget untouched' );
		$out = $e->before_elementor_title( '<h1>Hello</h1>', $title );
		pr_same( 0, strpos( $out, '<nav' ), 'before the Elementor title' );
		pr_same( '<h1>Hello</h1>', $e->before_elementor_title( '<h1>Hello</h1>', $title ), 'not twice' );
		BreadcrumbEngine::reset();
		ob_start();
		$e->echo_once();
		$e->echo_once();
		pr_same( 1, pr08_navs( ob_get_clean() ), 'canvas/header-footer hook prints once even when it fires twice' );
	},
	'Elementor-style rendering outside the main loop still receives the trail once Elementor is loaded' => function () {
		$id = pr08_post();
		pr08_go( get_permalink( $id ), 'content' );
		pr_head();
		do_action( 'elementor/loaded' );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
		$GLOBALS['wp_query']->in_the_loop = false;
		$html = apply_filters( 'the_content', 'Body' );
		pr_same( 1, pr08_navs( $html ), 'trail printed outside the loop' );
	},
	'manual shortcode prints, and automatic placement then adds no second trail' => function () {
		$id = pr08_post( array( 'post_content' => 'Intro [publishrank_breadcrumbs] text' ) );
		pr08_go( get_permalink( $id ) );
		pr_same( 1, pr08_navs( pr_content() ), 'shortcode in content: exactly one trail' );
		BreadcrumbEngine::reset();
		$e    = new BreadcrumbEngine();
		$html = do_shortcode( '[publishrank_breadcrumbs]' );
		pr_same( 1, pr08_navs( $html ), 'shortcode output' );
		pr_same( 'Body', $e->prepend( 'Body' ), 'later automatic placement skips this context' );
	},
	'Gutenberg block output uses the same renderer' => function () {
		$id = pr08_post( array( 'post_content' => '<!-- wp:publishrank/breadcrumbs /-->' ) );
		pr08_go( get_permalink( $id ) );
		pr_same( 1, pr08_navs( do_blocks( '<!-- wp:publishrank/breadcrumbs /-->' ) ), 'block renders one trail' );
	},
	'Elementor shortcode widget data counts as a manual trail' => function () {
		$id = pr08_post();
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( array( array( 'widgetType' => 'shortcode', 'settings' => array( 'shortcode' => '[publishrank_breadcrumbs]' ) ) ) ) ) );
		pr08_go( get_permalink( $id ) );
		$widget = new class() {
			public function get_name() {
				return 'theme-post-title'; }
		};
		pr_same( '<h1>t</h1>', ( new BreadcrumbEngine() )->before_elementor_title( '<h1>t</h1>', $widget ), 'automatic placement stays out' );
	},
	'visible breadcrumbs off: no automatic trail and no manual trail' => function () {
		$id = pr08_post( array( 'post_content' => 'x [publishrank_breadcrumbs]' ) );
		update_option( 'publishrank_breadcrumbs_enabled', 0 );
		pr08_go( get_permalink( $id ) );
		pr_same( 0, pr08_navs( pr_content() ), 'content' );
		pr_same( '', do_shortcode( '[publishrank_breadcrumbs]' ), 'shortcode' );
		pr_same( '', do_blocks( '<!-- wp:publishrank/breadcrumbs /-->' ), 'block' );
	},
	'schema-only placement prints nothing automatically but still emits BreadcrumbList' => function () {
		$id = pr08_post();
		pr08_go( get_permalink( $id ), 'schema_only' );
		pr_same( 0, pr08_navs( pr_content() ), 'no automatic visible trail' );
		pr_assert( null !== pr_node_of_type( pr_nodes( pr_head() ), 'BreadcrumbList' ), 'BreadcrumbList emitted' );
	},
	'manual placement registers no automatic hooks' => function () {
		$id = pr08_post();
		pr08_go( get_permalink( $id ), 'manual' );
		pr_same( 0, pr08_navs( pr_content() ), 'nothing automatic' );
		pr_same( array(), BreadcrumbEngine::registered_hooks(), 'no hooks' );
	},
	'category archive: loop_start of the MAIN query prints the trail once; other queries are ignored' => function () {
		$id  = pr08_post();
		$cat = get_the_category( $id )[0];
		pr08_go( get_category_link( $cat ) );
		pr_assert( in_array( 'loop_start', BreadcrumbEngine::registered_hooks(), true ), 'loop_start registered on archives' );
		$other = new WP_Query( array( 'post_type' => 'post' ) );
		ob_start();
		do_action_ref_array( 'loop_start', array( &$other ) );
		pr_same( 0, pr08_navs( ob_get_clean() ), 'secondary query ignored' );
		ob_start();
		do_action_ref_array( 'loop_start', array( &$GLOBALS['wp_query'] ) );
		do_action_ref_array( 'loop_start', array( &$GLOBALS['wp_query'] ) );
		$html = ob_get_clean();
		pr_same( 1, pr08_navs( $html ), 'main query, once' );
		pr_contains( 'aria-current="page">' . $cat->name . '<', $html, 'current term is the last crumb' );
	},
	'placement "content" registers nothing on archives, and the diagnostic says so' => function () {
		$id  = pr08_post();
		$cat = get_the_category( $id )[0];
		pr08_go( get_category_link( $cat ), 'content' );
		pr_same( array(), BreadcrumbEngine::registered_hooks(), 'no hooks' );
	},
	'front page and homepage get no trail; the posts page does' => function () {
		$front = pr08_post( array( 'post_type' => 'page', 'post_title' => 'Welcome', 'post_name' => 'welcome' ) );
		$blog  = pr08_post( array( 'post_type' => 'page', 'post_title' => 'Blog', 'post_name' => 'blog' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $blog );
		pr08_go( home_url( '/' ) );
		pr_same( '', ( new BreadcrumbEngine() )->render(), 'front page: no trail' );
		pr08_go( get_permalink( $blog ) );
		pr_assert( BreadcrumbEngine::context_supported(), 'posts page supported' );
		$items = ( new BreadcrumbEngine() )->items();
		pr_same( 2, count( $items ), 'Home + Blog' );
		pr_same( 'Blog', $items[1]['name'] );
	},
	'empty or invalid items never produce a one-item trail or a fabricated parent' => function () {
		$id = pr08_post();
		pr08_go( get_permalink( $id ), 'content' );
		add_filter( 'publishrank_breadcrumb_items', function () {
			return array( array( 'name' => 'Only', 'url' => 'https://example.test/' ) ); } );
		BreadcrumbEngine::reset();
		pr_same( '', ( new BreadcrumbEngine() )->render(), 'one item: no visible trail' );
		$nodes = pr_nodes( pr_head() );
		pr_same( null, pr_node_of_type( $nodes, 'BreadcrumbList' ), 'one item: no BreadcrumbList' );
		foreach ( $nodes as $n ) {
			pr_assert( ! isset( $n['breadcrumb'] ), 'no WebPage reference to a list that was not emitted' );
		}
	},
	'visible trail and JSON-LD come from the same items, and JSON-LD has no separator' => function () {
		update_option( 'publishrank_breadcrumbs_separator', '»»' );
		$id = pr08_post();
		pr08_go( get_permalink( $id ), 'content' );
		$e     = new BreadcrumbEngine();
		$names = array_column( $e->items(), 'name' );
		$list  = pr_node_of_type( pr_nodes( pr_head() ), 'BreadcrumbList' );
		pr_same( $names, array_column( $list['itemListElement'], 'name' ), 'same hierarchy' );
		pr_not_contains( '»»', wp_json_encode( $list ), 'no separator in JSON-LD' );
		foreach ( $list['itemListElement'] as $i => $el ) {
			pr_same( $i + 1, $el['position'], 'positions are sequential' );
		}
	},
	'schema ownership: an external owner suppresses the list and the WebPage reference; no owner emits both consistently' => function () {
		$id = pr08_post();
		pr08_go( get_permalink( $id ) );
		add_filter( 'publishrank_breadcrumb_schema_external_owner', function () {
			return 'yoast'; } );
		$nodes = pr_nodes( pr_head() );
		pr_same( null, pr_node_of_type( $nodes, 'BreadcrumbList' ), 'owned elsewhere: none' );
		foreach ( $nodes as $n ) {
			pr_assert( ! isset( $n['breadcrumb'] ), 'no dangling reference' );
		}
		remove_all_filters( 'publishrank_breadcrumb_schema_external_owner' );
		BreadcrumbEngine::reset();
		$nodes = pr_nodes( pr_head() );
		$list  = pr_node_of_type( $nodes, 'BreadcrumbList' );
		pr_assert( null !== $list, 'emitted' );
		$web = pr_node_of_type( $nodes, 'WebPage' );
		if ( $web && isset( $web['breadcrumb'] ) ) {
			pr_same( $list['@id'], $web['breadcrumb']['@id'], 'reference matches the emitted list' );
		}
	},
	'breadcrumb schema switch off: no list and no reference' => function () {
		$id = pr08_post();
		update_option( 'publishrank_breadcrumb_schema_enabled', 0 );
		pr08_go( get_permalink( $id ) );
		$nodes = pr_nodes( pr_head() );
		pr_same( null, pr_node_of_type( $nodes, 'BreadcrumbList' ) );
		foreach ( $nodes as $n ) {
			pr_assert( ! isset( $n['breadcrumb'] ), 'no reference' );
		}
	},
	'stylesheet: file ships in both asset trees, is registered with a file-based version and enqueued where a trail prints' => function () {
		pr_assert( is_file( PUBLISHRANK_SEO_PATH . 'assets/css/breadcrumbs.css' ), 'runtime stylesheet exists' );
		pr_same( file_get_contents( PUBLISHRANK_SEO_PATH . 'assets/css/breadcrumbs.css' ), file_get_contents( PUBLISHRANK_SEO_PATH . 'src/assets/css/breadcrumbs.css' ), 'source copy identical' );
		$id = pr08_post();
		pr08_go( get_permalink( $id ), 'content' );
		do_action( 'wp_enqueue_scripts' );
		pr_assert( wp_style_is( BreadcrumbEngine::STYLE_HANDLE, 'enqueued' ), 'enqueued on a supported page' );
		$style = wp_styles()->registered[ BreadcrumbEngine::STYLE_HANDLE ];
		pr_contains( 'css/breadcrumbs.css', $style->src );
		pr_same( array(), $style->deps, 'no dependency on admin styles' );
		pr_assert( false !== strpos( (string) $style->ver, '.' ), 'versioned' );
		$css = (string) file_get_contents( PUBLISHRANK_SEO_PATH . 'assets/css/breadcrumbs.css' );
		pr_assert( ! preg_match( '/!important/', $css ), 'no !important' );
		preg_match_all( '/^([^{}@\/\n][^{]*)\{/m', $css, $m );
		foreach ( $m[1] as $selector ) {
			foreach ( explode( ',', $selector ) as $one ) {
				$one = trim( $one );
				if ( '' !== $one && 0 !== strpos( $one, '.publishrank-' ) && 0 !== strpos( $one, '[dir' ) && 0 !== strpos( $one, '.rtl' ) && 0 !== strpos( $one, '.elementor .publishrank-' ) && 0 !== strpos( $one, '.wp-site-blocks .publishrank-' ) && 0 !== strpos( $one, 'from' ) && 0 !== strpos( $one, 'to' ) ) {
					pr_fail( 'unscoped selector: ' . $one );
				}
			}
		}
	},
	'RTL: directional separators flip, text separators do not' => function () {
		$css = (string) file_get_contents( PUBLISHRANK_SEO_PATH . 'assets/css/breadcrumbs.css' );
		pr_contains( '[dir="rtl"] .publishrank-breadcrumb-separator--directional', $css );
	},
	'diagnostics: fetch failure is reported and inconclusive' => function () {
		$a = BreadcrumbDiagnostics::analyze( pr08_page( array( 'status' => 0, 'error' => 'cURL error 28' ) ) );
		pr_same( array( 'fetch_failed' ), pr08_codes( $a ) );
		pr_same( false, $a['conclusive'] );
		$a = BreadcrumbDiagnostics::analyze( pr08_page( array( 'status' => 503 ) ) );
		pr_same( array( 'fetch_failed' ), pr08_codes( $a ) );
	},
	'diagnostics: settings-based findings are confirmed' => function () {
		$s = pr08_page()['settings'];
		$a = BreadcrumbDiagnostics::analyze( pr08_page( array( 'settings' => array_merge( $s, array( 'visible' => false ) ) ) ) );
		pr_assert( in_array( 'visible_disabled', pr08_codes( $a ), true ) );
		$a = BreadcrumbDiagnostics::analyze( pr08_page( array( 'settings' => array_merge( $s, array( 'placement' => 'manual' ) ) ) ) );
		pr_assert( in_array( 'placement_manual', pr08_codes( $a ), true ) );
		$a = BreadcrumbDiagnostics::analyze( pr08_page( array( 'settings' => array_merge( $s, array( 'placement' => 'schema_only' ) ) ) ) );
		pr_assert( in_array( 'placement_schema_only', pr08_codes( $a ), true ) );
		pr_assert( $a['findings'][0]['confirmed'], 'confirmed fact' );
	},
	'diagnostics: missing probe is inconclusive and never claims a cause' => function () {
		$a = BreadcrumbDiagnostics::analyze( pr08_page() );
		pr_same( false, $a['conclusive'] );
		pr_assert( in_array( 'probe_missing', pr08_codes( $a ), true ) );
		foreach ( $a['findings'] as $f ) {
			if ( 'probe_missing' === $f['code'] ) {
				pr_same( false, $f['confirmed'], 'probe_missing is not a confirmed cause' );
			}
		}
	},
	'diagnostics: probe facts (no hooks, too few items, hooks never ran) are distinguished' => function () {
		$base = array( 'supported' => true, 'visible' => true, 'placement' => 'auto', 'hooks' => array( 'the_content' ), 'items' => 3, 'printed' => false );
		$a    = BreadcrumbDiagnostics::analyze( pr08_page( array( 'html' => pr08_probe( array_merge( $base, array( 'hooks' => array() ) ) ) ) ) );
		pr_assert( in_array( 'auto_not_registered', pr08_codes( $a ), true ), 'not registered' );
		$a = BreadcrumbDiagnostics::analyze( pr08_page( array( 'html' => pr08_probe( array_merge( $base, array( 'items' => 1 ) ) ) ) ) );
		pr_assert( in_array( 'no_items', pr08_codes( $a ), true ), 'no items' );
		$a = BreadcrumbDiagnostics::analyze( pr08_page( array( 'html' => pr08_probe( $base ) ) ) );
		pr_assert( in_array( 'not_printed', pr08_codes( $a ), true ), 'hooks did not run' );
		pr_same( true, $a['conclusive'] );
	},
	'diagnostics: PublishRank markup, stylesheet and inline hiding' => function () {
		$probe = pr08_probe( array( 'supported' => true, 'hooks' => array( 'the_content' ), 'items' => 2, 'printed' => true ) );
		$nav   = '<nav class="publishrank-breadcrumbs publishrank-breadcrumbs--plain" aria-label="Breadcrumb"><ol><li class="publishrank-breadcrumbs__item"><a href="/">H</a><span class="publishrank-breadcrumb-separator" aria-hidden="true">»</span></li><li class="publishrank-breadcrumbs__item"><span aria-current="page">P</span></li></ol></nav>';
		$a     = BreadcrumbDiagnostics::analyze( pr08_page( array( 'html' => $probe . $nav ) ) );
		$codes = pr08_codes( $a );
		pr_assert( in_array( 'publishrank_markup_present', $codes, true ) && in_array( 'css_not_linked', $codes, true ) && in_array( 'css_inspection', $codes, true ), implode( ',', $codes ) );
		pr_assert( ! in_array( 'theme_or_unknown_renderer', $codes, true ), 'separator span is not another renderer' );
		pr_assert( ! in_array( 'multiple_visible', $codes, true ), 'one trail' );
		$linked = '<link rel="stylesheet" href="https://x.test/assets/css/breadcrumbs.css?ver=1" />';
		$a      = BreadcrumbDiagnostics::analyze( pr08_page( array( 'html' => $probe . $linked . str_replace( '<nav ', '<nav style="display:none" ', $nav ) ) ) );
		pr_assert( in_array( 'hidden_inline', pr08_codes( $a ), true ) && ! in_array( 'css_not_linked', pr08_codes( $a ), true ) );
	},
	'diagnostics: other plugin, theme markup, multiple trails, multiple and dangling schema, cache' => function () {
		$schema = '<script type="application/ld+json">{"@graph":[{"@type":"BreadcrumbList","@id":"https://x.test/#b1"},{"@type":"BreadcrumbList","@id":"https://x.test/#b2"},{"@type":"WebPage","breadcrumb":{"@id":"https://x.test/#gone"}}]}</script>';
		$html   = '<nav class="aioseo-breadcrumbs">a</nav><div class="my-theme-breadcrumb">b</div><nav class="publishrank-breadcrumbs"><ol><li>1</li><li>2</li></ol></nav>' . $schema;
		$a      = BreadcrumbDiagnostics::analyze( pr08_page( array( 'html' => $html, 'headers' => array( 'X-Cache' => 'HIT', 'Age' => '40' ) ) ) );
		$codes  = pr08_codes( $a );
		foreach ( array( 'other_renderer', 'theme_or_unknown_renderer', 'multiple_visible', 'multiple_schema', 'dangling_schema_reference', 'cached_response' ) as $code ) {
			pr_assert( in_array( $code, $codes, true ), "missing {$code}: " . implode( ',', $codes ) );
		}
	},
	'diagnostics: BreadcrumbList is counted from decoded JSON-LD, not from text' => function () {
		$html = '<script type="application/ld+json">{"@type":"Article","headline":"About \"BreadcrumbList\" markup"}</script>';
		pr_same( 0, BreadcrumbDiagnostics::schema_summary( $html )['count'] );
		$html = '<script type="application/ld+json">{"@graph":[{"@type":"BreadcrumbList","@id":"#b"}]}</script>';
		pr_same( 1, BreadcrumbDiagnostics::schema_summary( $html )['count'] );
	},
	'diagnostics probe: one-time token, correct report, nothing printed without a valid token' => function () {
		$id = pr08_post();
		update_option( 'publishrank_breadcrumb_placement', 'content' );
		$token = BreadcrumbDiagnostics::new_token();
		pr_go_to( add_query_arg( BreadcrumbDiagnostics::PROBE_PARAM, 'WRONG', get_permalink( $id ) ) );
		ob_start();
		do_action( 'wp_footer' );
		pr_not_contains( 'publishrank-breadcrumb-probe', ob_get_clean(), 'invalid token' );
		pr_go_to( add_query_arg( BreadcrumbDiagnostics::PROBE_PARAM, $token, get_permalink( $id ) ) );
		pr_content();
		ob_start();
		do_action( 'wp_footer' );
		$out   = ob_get_clean();
		$probe = BreadcrumbDiagnostics::read_probe( $out );
		pr_assert( is_array( $probe ), 'probe printed' );
		pr_same( true, $probe['printed'], 'reports that the trail printed' );
		pr_assert( $probe['items'] >= 2, 'item count' );
		pr_same( array( 'the_content' ), $probe['hooks'] );
		pr_go_to( add_query_arg( BreadcrumbDiagnostics::PROBE_PARAM, $token, get_permalink( $id ) ) );
		ob_start();
		do_action( 'wp_footer' );
		pr_not_contains( 'publishrank-breadcrumb-probe', ob_get_clean(), 'token is single-use' );
	},
	'diagnostics page: test URLs are own-site URLs and the scan is protected' => function () {
		$id = pr08_post();
		pr08_post( array( 'post_type' => 'page', 'post_title' => 'About', 'post_name' => 'about' ) );
		$urls = BreadcrumbsPage::test_urls();
		pr_same( array( 'article', 'page', 'archive' ), array_keys( $urls ) );
		foreach ( $urls as $url ) {
			pr_same( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $url, PHP_URL_HOST ), 'own host' );
		}
		wp_set_current_user( 0 );
		$src = (string) file_get_contents( PUBLISHRANK_SEO_PATH . 'src/Admin/BreadcrumbsPage.php' );
		pr_contains( "AdminAssets::guard( 'publishrank_breadcrumbs_scan' )", $src );
		pr_contains( "SCAN_KEY . '_lock'", $src );
	},
	'schema ownership: Yoast/Rank Math/AIOSEO owners can be overridden by filter' => function () {
		add_filter( 'publishrank_breadcrumb_schema_external_owner', function () {
			return ''; } );
		pr_same( '', BreadcrumbEngine::external_schema_owner() );
	},
);
