/* PublishRank Breadcrumbs block: server-rendered on the front end, static preview in the editor. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element ) { return; }
	var el = wp.element.createElement;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
	wp.blocks.registerBlockType( 'publishrank/breadcrumbs', {
		apiVersion: 2,
		title: __( 'PublishRank Breadcrumbs', 'publishrank' ),
		description: __( 'Shows the breadcrumb trail for this page. It uses the same trail as the BreadcrumbList structured data.', 'publishrank' ),
		category: 'widgets',
		icon: 'arrow-right-alt',
		keywords: [ 'breadcrumb', 'navigation', 'seo' ],
		supports: { html: false, multiple: false },
		edit: function () {
			return el(
				'nav',
				{ className: 'publishrank-breadcrumbs', 'aria-label': __( 'Breadcrumbs', 'publishrank' ) },
				el( 'span', null, __( 'Home → Category → This page', 'publishrank' ) ),
				el( 'small', { style: { display: 'block', opacity: 0.7 } }, __( 'Preview — the real trail is generated on the live page.', 'publishrank' ) )
			);
		},
		save: function () { return null; }
	} );
}( window.wp ) );
