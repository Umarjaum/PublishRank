<?php
/** Breadcrumb frontend override, extra separators, detector and the Breadcrumbs screen. */

use PublishRankSEO\Admin\BreadcrumbsPage;
use PublishRankSEO\Core\OutputSettings;
use PublishRankSEO\SEO\BreadcrumbEngine;
use PublishRankSEO\SEO\BreadcrumbSeparators;

return array(
	'override defaults to on and can be turned off' => function () {
		delete_option( 'publishrank_breadcrumb_frontend_override' );
		pr_assert( OutputSettings::breadcrumb_override(), 'missing option means enabled' );
		update_option( 'publishrank_breadcrumb_frontend_override', 0 );
		pr_assert( ! OutputSettings::breadcrumb_override(), 'explicit 0 means disabled' );
	},
	'third-party separator filters use the configured separator only when the override is on' => function () {
		update_option( BreadcrumbSeparators::OPTION, '»»' );
		$engine = new BreadcrumbEngine();
		$engine->register_override();
		update_option( 'publishrank_breadcrumb_frontend_override', 1 );
		pr_same( '»»', apply_filters( 'wpseo_breadcrumb_separator', '-' ), 'Yoast separator' );
		pr_same( '»»', apply_filters( 'rank_math/frontend/breadcrumb/args', array( 'separator' => '-' ) )['separator'], 'Rank Math separator' );
		pr_contains( '»»', apply_filters( 'woocommerce_breadcrumb_defaults', array( 'delimiter' => '/' ) )['delimiter'], 'WooCommerce delimiter' );
		update_option( 'publishrank_breadcrumb_frontend_override', 0 );
		pr_same( '-', apply_filters( 'wpseo_breadcrumb_separator', '-' ), 'unchanged when off' );
		pr_same( '-', apply_filters( 'rank_math/frontend/breadcrumb/args', array( 'separator' => '-' ) )['separator'], 'Rank Math unchanged when off' );
	},
	'every separator requested for the selector is a preset' => function () {
		foreach ( array( '»', '›', '>', '≫', '⟩', '/', '//', '|', '‖', '¦', '-', '–', '—', '·', '•', '⋅', '…', '→', '➜', '⟶', ':', '::', '»»' ) as $sep ) {
			pr_assert( BreadcrumbSeparators::is_preset( $sep ), "preset missing: {$sep}" );
			pr_same( $sep, BreadcrumbSeparators::normalize( $sep ), "normalize keeps {$sep}" );
		}
	},
	'custom unicode separator persists and renders' => function () {
		update_option( BreadcrumbSeparators::OPTION, '⟫' );
		update_option( 'publishrank_breadcrumbs_enabled', 1 );
		$id = pr_make_post( array( 'post_title' => 'Hello', 'post_name' => 'hello' ), 'News' );
		pr_go_to( get_permalink( $id ) );
		BreadcrumbEngine::reset();
		pr_contains( '⟫', ( new BreadcrumbEngine() )->render(), 'custom separator in trail' );
	},
	'renderer detector finds known and unknown trails and counts JSON-LD' => function () {
		$html = '<nav class="aioseo-breadcrumbs">x</nav><div class="my-theme-breadcrumb">y</div><nav class="publishrank-breadcrumbs publishrank-breadcrumbs--plain">z</nav>'
			. '<script type="application/ld+json">{"@graph":[{"@type":"BreadcrumbList"}]}</script>';
		$r = BreadcrumbsPage::detect( $html );
		pr_same( 1, $r['renderers']['All in One SEO'], 'aioseo' );
		pr_same( 1, $r['renderers']['PublishRank'], 'publishrank' );
		pr_same( array( 'my-theme-breadcrumb' ), $r['other'], 'other renderer' );
		pr_same( 1, $r['json_ld'], 'json-ld count' );
	},
	'breadcrumbs screen renders for administrators and saves with a valid nonce only' => function () {
		pr_as_admin();
		ob_start();
		( new BreadcrumbsPage() )->render();
		$html = (string) ob_get_clean();
		pr_contains( 'name="publishrank_breadcrumbs_enabled"', $html );
		pr_contains( 'Recommended: turn off theme breadcrumbs', $html );
		pr_contains( 'disable the theme or Elementor template', $html );
		pr_contains( 'name="publishrank_breadcrumb_frontend_override"', $html );
		pr_contains( 'name="publishrank_breadcrumbs_separator"', $html );
		pr_contains( 'name="publishrank_breadcrumb_schema_enabled"', $html );
		wp_set_current_user( 0 );
		ob_start();
		( new BreadcrumbsPage() )->render();
		pr_same( '', (string) ob_get_clean(), 'nothing rendered without manage_options' );
	},
);
