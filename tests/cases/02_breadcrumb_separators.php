<?php
/** Breadcrumb separators: presets, persistence, rendering, escaping, structured data independence, duplicates. */

use PublishRankSEO\SEO\BreadcrumbEngine;
use PublishRankSEO\SEO\BreadcrumbSeparators;

function pr_bc_setup() {
	update_option( 'publishrank_seo_publication_name', 'Test Site' );
	update_option( 'publishrank_seo_publisher_name', 'Test Site' );
	$id = pr_make_post( array( 'post_title' => 'Hello World', 'post_name' => 'hello-world' ), 'News' );
	pr_go_to( get_permalink( $id ) );
	return $id;
}

/** Visible trail HTML for the current request. */
function pr_bc_html( $args = array() ) {
	BreadcrumbEngine::reset();
	return ( new BreadcrumbEngine() )->render( $args );
}

/** Separator strings of a rendered trail, in order. */
function pr_bc_separators( $html ) {
	preg_match_all( '#<span class="publishrank-breadcrumb-separator[^"]*" aria-hidden="true">(.*?)</span>#su', $html, $m );
	return array_map( 'html_entity_decode', $m[1] );
}

/** Settings page DOM, so the form is checked as a browser would read it. */
function pr_settings_dom() {
	ob_start();
	( new \PublishRankSEO\Admin\SettingsPage() )->page();
	$html = (string) ob_get_clean();
	$dom  = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();
	return new DOMXPath( $dom );
}

function pr_settings_state( $xp ) {
	$input    = $xp->query( '//input[@id="publishrank_breadcrumbs_separator"]' )->item( 0 );
	$selected = $xp->query( '//select[@id="publishrank_breadcrumb_sep_preset"]//option[@selected]' )->item( 0 );
	return array(
		'input'    => $input ? $input->getAttribute( 'value' ) : null,
		'selected' => $selected ? $selected->getAttribute( 'value' ) : null,
		'sep'      => $selected && $selected->hasAttribute( 'data-sep' ) ? $selected->getAttribute( 'data-sep' ) : null,
	);
}

return array(
	'preset catalogue covers every required symbol, has no duplicates and a custom option exists' => function () {
		$presets  = BreadcrumbSeparators::presets();
		$required = array(
			'arrows'  => array( '→', '➜', '➝', '➞', '➡', '⇒', '⇢', '⇨', '⟶' ),
			'angles'  => array( '›', '»', '❯', '❱', '⟩', '⟫', '〉' ),
			'lines'   => array( '/', '//', '\\', '|', '¦', '‖', ':', '::' ),
			'dots'    => array( '·', '•', '‧', '⋅', '∙', '●', '○', '◦' ),
			'dashes'  => array( '-', '–', '—', '−', '~', '≈' ),
			'other'   => array( '∷', '※', '✦', '✧' ),
			'spacing' => array( ' ', BreadcrumbSeparators::NBSP, '' ),
		);
		foreach ( $required as $group => $values ) {
			foreach ( $values as $v ) {
				pr_assert( array_key_exists( $v, $presets ), "missing {$group} preset " . json_encode( $v ) );
			}
		}
		$count = 0;
		foreach ( BreadcrumbSeparators::groups() as $group ) {
			$count += count( $group );
		}
		pr_same( $count, count( $presets ), 'no value appears in two groups' );
		pr_same( count( $presets ), count( array_unique( array_values( $presets ) ) ), 'labels are distinct' );
		foreach ( $presets as $value => $label ) {
			pr_same( $value, BreadcrumbSeparators::normalize( $value ), 'every preset is itself valid: ' . json_encode( $value ) );
		}
		$xp = pr_settings_dom();
		pr_assert( $xp->query( '//select[@id="publishrank_breadcrumb_sep_preset"]/optgroup' )->length >= 7, 'presets are grouped' );
		pr_assert( $xp->query( '//select[@id="publishrank_breadcrumb_sep_preset"]//option[@value="__custom"]' )->length === 1, 'custom option present' );
	},

	'every preset saves, reloads selected on the settings page and renders on the front end' => function () {
		pr_bc_setup();
		$failures = array();
		foreach ( BreadcrumbSeparators::presets() as $value => $label ) {
			$value = (string) $value;
			update_option( 'publishrank_breadcrumbs_separator', $value ); // Goes through the registered sanitizer like options.php.
			wp_cache_delete( 'publishrank_breadcrumbs_separator', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			if ( get_option( 'publishrank_breadcrumbs_separator' ) !== $value ) {
				$failures[] = 'stored ' . json_encode( $value ) . ' as ' . json_encode( get_option( 'publishrank_breadcrumbs_separator' ) );
				continue;
			}
			$state = pr_settings_state( pr_settings_dom() );
			if ( $state['input'] !== $value || $state['selected'] !== $value || $state['sep'] !== $value ) {
				$failures[] = 'settings page for ' . json_encode( $value ) . ' showed ' . json_encode( $state );
			}
			$seps = pr_bc_separators( pr_bc_html() );
			$want = '' === $value ? array() : array( $value, $value );
			if ( $seps !== $want ) {
				$failures[] = 'front end for ' . json_encode( $value ) . ' printed ' . json_encode( $seps );
			}
		}
		pr_assert( ! $failures, implode( "\n", $failures ) );
	},

	'custom unicode, spaced, empty and whitespace-only separators are preserved' => function () {
		pr_bc_setup();
		foreach ( array( '⸺', '🔹', 'ab', '＞', ' ⟡ ', '  ', "\u{00A0}\u{00A0}", '' ) as $raw ) {
			$expected = preg_replace( '/[ \t\r\n]+/', ' ', $raw );
			update_option( 'publishrank_breadcrumbs_separator', $raw );
			pr_same( $expected, get_option( 'publishrank_breadcrumbs_separator' ), 'stored ' . json_encode( $raw ) );
			$state = pr_settings_state( pr_settings_dom() );
			pr_same( $expected, $state['input'], 'form input shows ' . json_encode( $expected ) );
			pr_same( '' === $expected ? array() : array( $expected, $expected ), pr_bc_separators( pr_bc_html() ), 'rendered ' . json_encode( $expected ) );
		}
		update_option( 'publishrank_breadcrumbs_separator', '⸺' );
		$state = pr_settings_state( pr_settings_dom() );
		pr_same( '__custom', $state['selected'], 'a non-preset value selects the Custom entry' );
		// Never saved: the default arrow; saved as empty: honoured as "none".
		delete_option( 'publishrank_breadcrumbs_separator' );
		pr_same( '→', BreadcrumbSeparators::current() );
		update_option( 'publishrank_breadcrumbs_separator', '' );
		pr_same( '', BreadcrumbSeparators::current() );
	},

	'invalid separators are rejected or neutralised and never reach the page as markup' => function () {
		pr_bc_setup();
		update_option( 'publishrank_breadcrumbs_separator', '›' );
		foreach ( array( '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', 'abcdefghijk', str_repeat( '→', 50 ) ) as $bad ) {
			update_option( 'publishrank_breadcrumbs_separator', $bad );
			pr_same( '›', get_option( 'publishrank_breadcrumbs_separator' ), 'rejected, previous kept: ' . json_encode( $bad ) );
		}
		update_option( 'publishrank_breadcrumbs_separator', '<b>x</b>' );
		pr_same( 'x', get_option( 'publishrank_breadcrumbs_separator' ), 'tags stripped' );
		update_option( 'publishrank_breadcrumbs_separator', "a\u{202E}b\u{200B}\x01" );
		pr_same( 'ab', get_option( 'publishrank_breadcrumbs_separator' ), 'bidi override, zero-width and control characters removed' );
		update_option( 'publishrank_breadcrumbs_separator', '<' );
		$html = pr_bc_html();
		pr_contains( '&lt;', $html, 'a lone < is text and is escaped' );
		// Even a value written around the sanitizer (database edit, import bug) cannot inject markup.
		remove_all_filters( 'sanitize_option_publishrank_breadcrumbs_separator' );
		update_option( 'publishrank_breadcrumbs_separator', '<img src=x onerror=alert(1)>' );
		$html = pr_bc_html();
		pr_not_contains( '<img', $html );
		pr_not_contains( 'onerror', $html );
		update_option( 'publishrank_breadcrumbs_separator', '"><script>alert(1)</script>' );
		$html = pr_bc_html();
		pr_not_contains( '<script', $html );
		pr_same( null, BreadcrumbSeparators::normalize( array( 'x' ) ) );
		pr_same( null, BreadcrumbSeparators::normalize( null ) );
	},

	'shortcode separator attribute goes through the same validation' => function () {
		pr_bc_setup();
		update_option( 'publishrank_breadcrumbs_separator', '→' );
		$out = do_shortcode( '[publishrank_breadcrumbs separator=" // "]' );
		pr_same( array( ' // ', ' // ' ), pr_bc_separators( $out ) );
		BreadcrumbEngine::reset();
		$out = do_shortcode( '[publishrank_breadcrumbs separator="<i>x</i>"]' );
		pr_not_contains( '<i>', $out );
		pr_same( array( 'x', 'x' ), pr_bc_separators( $out ) );
		BreadcrumbEngine::reset();
		$out = do_shortcode( '[publishrank_breadcrumbs separator="waytoolongseparator"]' );
		pr_same( array( '→', '→' ), pr_bc_separators( $out ), 'invalid attribute falls back to the saved separator' );
	},

	'markup is accessible: nav landmark, ordered list, current page, hidden separators' => function () {
		pr_bc_setup();
		$html = pr_bc_html();
		pr_contains( '<nav class="publishrank-breadcrumbs', $html );
		pr_contains( 'aria-label="Breadcrumbs"', $html );
		pr_contains( '<ol class="publishrank-breadcrumbs__list">', $html );
		pr_count( 1, 'aria-current="page"', $html );
		pr_count( 2, 'aria-hidden="true"', $html, 'one hidden separator between each pair of crumbs' );
		pr_contains( '<a href="https://example.test/category/news/">News</a>', $html );
		pr_contains( '<span aria-current="page">Hello World</span>', $html );
		// Directional separators flip in RTL; text separators never do.
		pr_contains( 'publishrank-breadcrumb-separator--directional', $html );
		update_option( 'publishrank_breadcrumbs_separator', '|' );
		pr_not_contains( '--directional', pr_bc_html() );
		$css = file_get_contents( PUBLISHRANK_SEO_PATH . 'assets/css/breadcrumbs.css' );
		pr_contains( 'white-space: pre', $css, 'spaced separators keep their spaces' );
		pr_contains( '@media (max-width: 600px)', $css, 'mobile rule' );
		pr_contains( 'flex-wrap: wrap', $css, 'long trails wrap on narrow screens' );
		pr_same( file_get_contents( PUBLISHRANK_SEO_PATH . 'assets/css/breadcrumbs.css' ), file_get_contents( PUBLISHRANK_SEO_PATH . 'src/assets/css/breadcrumbs.css' ), 'both asset copies identical' );
	},

	'BreadcrumbList JSON-LD is independent of the visual separator and positions are correct' => function () {
		pr_bc_setup();
		$sets = array( '→', ' › ', '', '⸺', '/', ' ' );
		$reference = null;
		foreach ( $sets as $sep ) {
			update_option( 'publishrank_breadcrumbs_separator', $sep );
			pr_go_to( get_permalink( get_posts( array( 'fields' => 'ids', 'numberposts' => 1 ) )[0] ) );
			$nodes = pr_nodes( pr_head() );
			$list  = pr_node_of_type( $nodes, 'BreadcrumbList' );
			pr_assert( null !== $list, 'BreadcrumbList emitted for separator ' . json_encode( $sep ) );
			$names = array();
			foreach ( $list['itemListElement'] as $i => $el ) {
				pr_same( 'ListItem', $el['@type'] );
				pr_same( $i + 1, $el['position'], 'positions are 1..n' );
				$names[] = $el['name'];
				if ( isset( $el['item'] ) ) {
					pr_same( $el['item'], esc_url_raw( $el['item'] ), 'item is a clean URL' );
				}
			}
			pr_same( array( 'Home', 'News', 'Hello World' ), $names, 'names are the real hierarchy with Home as the root' );
			pr_same( esc_url_raw( home_url( '/' ) ), esc_url_raw( $list['itemListElement'][0]['item'] ), 'Home uses the actual homepage URL' );
			$encoded = wp_json_encode( $list );
			foreach ( array( '→', '›', '⸺' ) as $symbol ) {
				pr_not_contains( $symbol, $encoded, 'separator ' . $symbol . ' must not leak into JSON-LD' );
			}
			$reference = null === $reference ? $encoded : $reference;
			pr_same( $reference, $encoded, 'JSON-LD is byte-identical whatever the separator' );
			$page = pr_node_of_type( $nodes, 'WebPage' );
			pr_same( array( '@id' => $list['@id'] ), $page['breadcrumb'], 'WebPage points at the BreadcrumbList' );
		}
	},

	'block theme automatic placement prints once above the post title and yields to a manual trail' => function () {
		pr_assert( wp_is_block_theme(), 'this test needs a block theme as the active theme' );
		$id = pr_bc_setup();
		$block = array( 'blockName' => 'core/post-title' );
		$inst  = new WP_Block( $block );
		$out   = apply_filters( 'render_block', '<h1>Hello World</h1>', $block, $inst );
		pr_count( 1, '<nav class="publishrank-breadcrumbs', $out );
		pr_assert( strpos( $out, '<nav' ) < strpos( $out, '<h1>' ), 'trail is above the title' );
		pr_same( '<h1>x</h1>', apply_filters( 'render_block', '<h1>x</h1>', $block, $inst ), 'second post-title block gets nothing' );
		wp_update_post( array( 'ID' => $id, 'post_content' => '[publishrank_breadcrumbs]' ) );
		pr_go_to( get_permalink( $id ) );
		pr_same( '<h1>x</h1>', apply_filters( 'render_block', '<h1>x</h1>', $block, $inst ), 'manual shortcode in the content: no automatic trail' );
	},

	'no duplicate breadcrumbs: visible trail prints once and a manual trail suppresses the automatic one' => function () {
		update_option( 'publishrank_breadcrumb_placement', 'content' );
		$id = pr_bc_setup();
		$content = pr_content();
		pr_count( 1, '<nav class="publishrank-breadcrumbs', $content, 'automatic placement above the content' );
		// The same request again (second the_content pass, e.g. a page builder) does not print a second trail.
		pr_count( 0, '<nav class="publishrank-breadcrumbs', apply_filters( 'the_content', 'Again' ), 'second pass' );
		// Editor placed the shortcode: automatic placement stays out.
		wp_update_post( array( 'ID' => $id, 'post_content' => 'Intro [publishrank_breadcrumbs] text' ) );
		pr_go_to( get_permalink( $id ) );
		pr_count( 1, '<nav class="publishrank-breadcrumbs', pr_content(), 'manual shortcode only' );
		// Theme-hook placement runs the callback once however often the hook fires.
		update_option( 'publishrank_breadcrumb_placement', 'hook' );
		update_option( 'publishrank_breadcrumb_hook', 'my_theme_before_content' );
		wp_update_post( array( 'ID' => $id, 'post_content' => 'Plain body' ) );
		pr_go_to( get_permalink( $id ) );
		ob_start();
		do_action( 'my_theme_before_content' );
		do_action( 'my_theme_before_content' );
		pr_count( 1, '<nav class="publishrank-breadcrumbs', (string) ob_get_clean(), 'hook fired twice' );
		pr_count( 0, '<nav class="publishrank-breadcrumbs', pr_content(), 'hook placement does not also prepend to content' );
	},

	'visible breadcrumbs off keeps BreadcrumbList on; schema off keeps the visible trail and metadata on' => function () {
		update_option( 'publishrank_breadcrumb_placement', 'content' );
		$id = pr_bc_setup();
		update_option( 'publishrank_breadcrumbs_enabled', 0 );
		pr_go_to( get_permalink( $id ) );
		$head = pr_head();
		pr_not_contains( '<nav class="publishrank-breadcrumbs', pr_content(), 'visible trail is off' );
		pr_assert( null !== pr_node_of_type( pr_nodes( $head ), 'BreadcrumbList' ), 'BreadcrumbList still output' );

		update_option( 'publishrank_breadcrumbs_enabled', 1 );
		update_option( 'publishrank_breadcrumb_schema_enabled', 0 );
		pr_go_to( get_permalink( $id ) );
		$head  = pr_head();
		$nodes = pr_nodes( $head );
		pr_same( null, pr_node_of_type( $nodes, 'BreadcrumbList' ), 'BreadcrumbList is off' );
		$page = pr_node_of_type( $nodes, 'WebPage' );
		pr_assert( ! isset( $page['breadcrumb'] ), 'WebPage must not reference a BreadcrumbList that is not output' );
		pr_count( 1, '<nav class="publishrank-breadcrumbs', pr_content(), 'visible trail still on' );

		// Master schema switch off: no JSON-LD at all, SEO metadata unaffected, visible trail unaffected.
		update_option( 'publishrank_breadcrumb_schema_enabled', 1 );
		update_option( 'publishrank_schema_enabled', 0 );
		pr_go_to( get_permalink( $id ) );
		$head = pr_head();
		pr_not_contains( 'application/ld+json', $head );
		pr_contains( '<meta name="description"', $head );
		pr_contains( '<link rel="canonical"', $head );
		pr_count( 1, '<nav class="publishrank-breadcrumbs', pr_content(), 'visible trail unaffected by the schema switch' );

		// "Structured data only" placement: no visible trail, schema stays.
		update_option( 'publishrank_schema_enabled', 1 );
		update_option( 'publishrank_breadcrumb_placement', 'schema_only' );
		pr_go_to( get_permalink( $id ) );
		pr_not_contains( '<nav class="publishrank-breadcrumbs', pr_content() );
		pr_assert( null !== pr_node_of_type( pr_nodes( pr_head() ), 'BreadcrumbList' ) );
	},

	'import sanitizer keeps spaced separators and rejects invalid ones' => function () {
		pr_bc_setup();
		$import = new \PublishRankSEO\Admin\ImportExport();
		$m      = new ReflectionMethod( $import, 'sanitize' );
		$m->setAccessible( true );
		pr_same( ' › ', $m->invoke( $import, 'publishrank_breadcrumbs_separator', ' › ' ) );
		pr_same( '', $m->invoke( $import, 'publishrank_breadcrumbs_separator', '' ) );
		update_option( 'publishrank_breadcrumbs_separator', '·' );
		pr_same( '·', $m->invoke( $import, 'publishrank_breadcrumbs_separator', '<script>x</script>' ), 'invalid import keeps the saved value' );
		pr_same( '%%date%% %%category%%', $m->invoke( $import, 'publishrank_seo_title_template_post', '%%date%% %%category%%' ), 'import keeps title tokens' );
		pr_same( 0, $m->invoke( $import, 'publishrank_org_website_schema_enabled', 'false' ) );
		pr_same( 1, $m->invoke( $import, 'publishrank_breadcrumbs_enabled', '1' ) );
	},
);
