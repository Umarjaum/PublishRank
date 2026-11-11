/* PublishRank → Breadcrumbs: live designer. Redraws the preview from the form fields as they change. */
( function ( $ ) {
	'use strict';
	var root = document.getElementById( 'pr-bc-designer' );
	if ( ! root ) { return; }
	var data = {};
	try { data = JSON.parse( root.getAttribute( 'data-config' ) || '{}' ); } catch ( e ) { data = {}; }
	var items = data.items && data.items.length ? data.items : [ 'Home', 'News', 'Article title' ];
	var LOOKS = data.looks || [];
	var LOOK_SEPARATORS = { minimal: '»', pills: '›' };
	var DIRECTIONAL = /[→➜➝➞➡➤⇒⇢⇨⟶›»≫❯❱⟩⟫〉>]/;
	var form = root.closest( 'form' ) || document;
	function field( name ) { return form.querySelector( '[name="' + name + '"]' ); }
	var f = {
		look: field( 'publishrank_breadcrumb_style' ),
		size: field( 'publishrank_breadcrumb_size' ),
		align: field( 'publishrank_breadcrumb_align' ),
		offset: field( 'publishrank_breadcrumb_offset' ),
		sep: field( 'publishrank_breadcrumbs_separator' ),
		icons: form.querySelector( 'input[type="checkbox"][name="publishrank_breadcrumb_icons"]' ),
		text: field( 'publishrank_breadcrumb_text_color' ),
		link: field( 'publishrank_breadcrumb_link_color' ),
		bg: field( 'publishrank_breadcrumb_bg_color' ),
		accent: field( 'publishrank_breadcrumb_accent_color' )
	};
	var range = document.getElementById( 'pr-bc-offset-range' );
	var stage = document.getElementById( 'pr-bc-live' );
	var gallery = document.getElementById( 'pr-bc-gallery' );
	if ( ! stage || ! f.look ) { return; }

	function esc( t ) { var d = document.createElement( 'div' ); d.textContent = String( t ); return d.innerHTML; }
	function hex( v ) { return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( v || '' ) ? v : ''; }

	function trail( look, opts ) {
		opts = opts || {};
		var sep = opts.sep !== undefined ? opts.sep : ( f.sep ? f.sep.value : '→' );
		var list = opts.items || items;
		var style = [];
		var offset = opts.offset !== undefined ? opts.offset : ( parseInt( f.offset && f.offset.value, 10 ) || 0 );
		var colors = opts.plain ? {} : { text: hex( f.text && f.text.value ), link: hex( f.link && f.link.value ), bg: hex( f.bg && f.bg.value ), accent: hex( f.accent && f.accent.value ) };
		Object.keys( colors ).forEach( function ( k ) { if ( colors[ k ] ) { style.push( '--pr-bc-' + k + ':' + colors[ k ] ); } } );
		if ( offset ) { style.push( '--pr-bc-x:' + offset + 'px' ); }
		var icons = ! opts.plain && f.icons && f.icons.checked && look !== 'steps';
		var cls = 'publishrank-breadcrumbs publishrank-breadcrumbs--' + look +
			' publishrank-breadcrumbs--size-' + ( opts.size || ( f.size ? f.size.value : 'medium' ) ) +
			' publishrank-breadcrumbs--align-' + ( opts.align || ( f.align ? f.align.value : 'left' ) ) +
			( offset ? ' publishrank-breadcrumbs--offset' : '' ) + ( icons ? ' publishrank-breadcrumbs--icons' : '' );
		var sepCls = 'publishrank-breadcrumb-separator' + ( DIRECTIONAL.test( sep ) ? ' publishrank-breadcrumb-separator--directional' : '' );
		var last = list.length - 1;
		var html = '<nav class="' + cls + '" style="' + esc( style.join( ';' ) ) + '" aria-label="Breadcrumb preview"><ol class="publishrank-breadcrumbs__list">';
		list.forEach( function ( name, i ) {
			html += '<li class="publishrank-breadcrumbs__item ' + ( i === last ? 'is-current' : 'is-done' ) + '" data-step="' + ( i + 1 ) + '">' +
				( i === last ? '<span aria-current="page">' + esc( name ) + '</span>' : '<a href="#" tabindex="-1">' + esc( name ) + '</a>' ) +
				( i < last && sep !== '' ? '<span class="' + sepCls + '" aria-hidden="true">' + esc( sep ) + '</span>' : '' ) + '</li>';
		} );
		return html + '</ol></nav>';
	}

	function draw() {
		stage.innerHTML = trail( f.look.value );
		if ( range && f.offset ) { range.value = f.offset.value || 0; }
		if ( gallery ) {
			gallery.querySelectorAll( '.pr-bc-card' ).forEach( function ( card ) {
				card.classList.toggle( 'is-active', card.getAttribute( 'data-look' ) === f.look.value );
			} );
		}
	}

	if ( gallery ) {
		var sample = [ 'Home', 'Profile', 'Projects', 'DailyUI' ];
		gallery.innerHTML = LOOKS.map( function ( l ) {
			return '<button type="button" class="pr-bc-card" data-look="' + esc( l.value ) + '" title="' + esc( l.label ) + '"><span class="pr-bc-card__view" aria-hidden="true">' +
				trail( l.value, { plain: true, items: sample, sep: LOOK_SEPARATORS[ l.value ] || '›', offset: 0, align: 'left', size: 'medium' } ) +
				'</span><span class="pr-bc-card__name">' + esc( l.short ) + '</span></button>';
		} ).join( '' );
		gallery.addEventListener( 'click', function ( e ) {
			var card = e.target.closest ? e.target.closest( '.pr-bc-card' ) : null;
			if ( ! card ) { return; }
			var look = card.getAttribute( 'data-look' );
			f.look.value = look;
			// Minimal and Pills bring the separator of the reference design, unless a custom one was typed.
			var presets = [ '»', '›' ];
			if ( f.sep && LOOK_SEPARATORS[ look ] && ( presets.indexOf( f.sep.value ) !== -1 || f.sep.value === '→' ) ) {
				f.sep.value = LOOK_SEPARATORS[ look ];
				f.sep.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
			draw();
		} );
	}

	[ 'look', 'size', 'align', 'offset', 'sep', 'icons' ].forEach( function ( k ) {
		if ( f[ k ] ) {
			f[ k ].addEventListener( 'input', draw );
			f[ k ].addEventListener( 'change', draw );
		}
	} );
	var preset = document.getElementById( 'pr-bc-preset' );
	if ( preset ) { preset.addEventListener( 'change', function () { setTimeout( draw, 0 ); } ); }
	if ( range && f.offset ) {
		range.addEventListener( 'input', function () { f.offset.value = range.value; draw(); } );
	}

	// Built-in WordPress color picker (Iris) on the four color fields.
	if ( $ && $.fn && $.fn.wpColorPicker ) {
		[ 'text', 'link', 'bg', 'accent' ].forEach( function ( k ) {
			if ( ! f[ k ] ) { return; }
			$( f[ k ] ).wpColorPicker( {
				change: function ( event, ui ) { f[ k ].value = ui.color.toString(); draw(); },
				clear: function () { f[ k ].value = ''; draw(); }
			} );
		} );
	} else {
		[ 'text', 'link', 'bg', 'accent' ].forEach( function ( k ) { if ( f[ k ] ) { f[ k ].addEventListener( 'input', draw ); } } );
	}
	draw();
}( window.jQuery ) );
