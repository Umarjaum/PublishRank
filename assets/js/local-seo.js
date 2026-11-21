/* PublishRank Local SEO screen: type-specific fields, on/off state, hour presets and JSON copy. */
( function () {
	'use strict';
	var root = document.querySelector( '[data-pr-local]' );
	if ( ! root ) {
		return;
	}
	var typeSelect = root.querySelector( '[data-pr-local-type]' );
	var toggle = root.querySelector( '[data-pr-local-toggle]' );
	var status = root.querySelector( '.pr-local-status' );
	var state = root.querySelector( '[data-pr-local-state]' );
	var empty = root.querySelector( '[data-pr-local-empty]' );
	var sab = root.querySelector( 'input[type="checkbox"][name="local_seo[hide_address]"]' );

	function currentFamilies() {
		if ( ! typeSelect ) {
			return [];
		}
		var opt = typeSelect.options[ typeSelect.selectedIndex ];
		var raw = opt ? opt.getAttribute( 'data-families' ) || '' : '';
		return raw.split( /\s+/ ).filter( Boolean );
	}

	function applyFamilies() {
		var fams = currentFamilies();
		var shown = 0;
		root.querySelectorAll( '.pr-local-field[data-pr-local-families]' ).forEach( function ( el ) {
			var needs = ( el.getAttribute( 'data-pr-local-families' ) || '' ).split( /\s+/ ).filter( Boolean );
			var match = needs.some( function ( f ) {
				return fams.indexOf( f ) !== -1;
			} );
			el.hidden = ! match;
			if ( match ) {
				shown++;
			}
		} );
		if ( empty ) {
			empty.hidden = shown > 0;
		}
	}

	function applyToggle() {
		if ( ! toggle ) {
			return;
		}
		var on = toggle.checked;
		root.classList.toggle( 'is-off', ! on );
		if ( status ) {
			status.classList.toggle( 'is-on', on );
		}
		if ( state ) {
			state.textContent = on ? ( state.getAttribute( 'data-on' ) || state.textContent ) : ( state.getAttribute( 'data-off' ) || state.textContent );
		}
	}

	function applySab() {
		var hidden = !! ( sab && sab.checked );
		root.classList.toggle( 'is-service-area', hidden );
		[ 'street', 'latitude', 'longitude', 'has_map' ].forEach( function ( key ) {
			var input = root.querySelector( '[name="local_seo[' + key + ']"]' );
			var field = input ? input.closest( '.pr-local-field' ) : null;
			if ( field ) {
				field.setAttribute( 'data-pr-local-hide-sab', '' );
			}
		} );
	}

	if ( typeSelect ) {
		typeSelect.addEventListener( 'change', applyFamilies );
	}
	if ( toggle ) {
		toggle.addEventListener( 'change', applyToggle );
	}
	if ( sab ) {
		sab.addEventListener( 'change', applySab );
	}

	root.querySelectorAll( '[data-pr-local-preset]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var area = root.querySelector( '[name="local_seo[opening_hours]"]' );
			if ( ! area ) {
				return;
			}
			area.value = btn.getAttribute( 'data-pr-local-preset' ) || '';
			area.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			area.focus();
		} );
	} );

	var copy = root.querySelector( '[data-pr-local-copy]' );
	var json = root.querySelector( '[data-pr-local-json]' );
	if ( copy ) {
		if ( ! json ) {
			copy.hidden = true;
		} else {
			copy.addEventListener( 'click', function () {
				var label = copy.textContent;
				var done = function () {
					copy.textContent = ( window.publishrankSeo && window.publishrankSeo.copied ) || 'Copied';
					setTimeout( function () {
						copy.textContent = label;
					}, 1600 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( json.textContent ).then( done, function () {} );
				} else {
					var range = document.createRange();
					range.selectNodeContents( json );
					var sel = window.getSelection();
					sel.removeAllRanges();
					sel.addRange( range );
					try {
						document.execCommand( 'copy' );
						done();
					} catch ( e ) {}
				}
			} );
		}
	}

	applyFamilies();
	applyToggle();
	applySab();
}() );

/* 2.26: multiple locations and a live preview of unsaved settings. */
( function () {
	'use strict';
	var root = document.querySelector( '[data-pr-local]' );
	if ( ! root ) {
		return;
	}
	var form = root.querySelector( 'form' );
	var live = root.querySelector( '[data-pr-local-live]' );
	var locs = root.querySelector( '[data-pr-local-locs]' );
	var tpl = locs ? locs.querySelector( '[data-pr-local-loc-template]' ) : null;
	var addBtn = root.querySelector( '[data-pr-local-loc-add]' );
	var savedBody = form ? new URLSearchParams( new FormData( form ) ).toString() : '';

	function el( tag, cls, text ) { var n = document.createElement( tag ); if ( cls ) { n.className = cls; } if ( text !== undefined && text !== null ) { n.textContent = text; } return n; }
	function renumber() {
		if ( ! locs ) { return; }
		var n = 0;
		locs.querySelectorAll( '.pr-local-loc:not([data-pr-local-loc-template])' ).forEach( function ( fs ) { n++; var s = fs.querySelector( '[data-pr-local-loc-num]' ); if ( s ) { s.textContent = String( n ); } } );
	}
	if ( addBtn && tpl ) {
		addBtn.addEventListener( 'click', function () {
			var idx = 'n' + Date.now();
			var copy = tpl.cloneNode( true );
			copy.removeAttribute( 'data-pr-local-loc-template' ); copy.hidden = false; copy.disabled = false;
			copy.querySelectorAll( '[name]' ).forEach( function ( i ) { i.name = i.name.replace( '__i__', idx ); } );
			copy.querySelectorAll( '[id]' ).forEach( function ( i ) { var old = i.id; i.id = old + '_' + idx; var l = copy.querySelector( 'label[for="' + old + '"]' ); if ( l ) { l.setAttribute( 'for', i.id ); } } );
			locs.insertBefore( copy, tpl );
			renumber();
			var first = copy.querySelector( 'input' ); if ( first ) { first.focus(); }
			schedule();
		} );
	}
	if ( locs ) {
		locs.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-pr-local-loc-remove]' );
			if ( ! b ) { return; }
			var fs = b.closest( '.pr-local-loc' );
			if ( fs ) { fs.remove(); renumber(); schedule(); }
		} );
	}
	if ( ! live || ! form ) { return; }

	var timer = null, seq = 0, device = 'desktop';
	var cardBox = live.querySelector( '[data-pr-local-card]' ), locBox = live.querySelector( '[data-pr-local-locs-preview]' ), issueBox = live.querySelector( '[data-pr-local-issues]' ), stateBox = live.querySelector( '[data-pr-local-live-state]' );
	var jsonBox = root.querySelector( '[data-pr-local-json]' );
	live.querySelectorAll( '[data-pr-local-device]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () { device = b.getAttribute( 'data-pr-local-device' ); live.querySelectorAll( '[data-pr-local-device]' ).forEach( function ( o ) { o.setAttribute( 'aria-pressed', o === b ? 'true' : 'false' ); } ); live.classList.toggle( 'is-mobile', device === 'mobile' ); } );
	} );

	function card( c, heading ) {
		var box = el( 'article', 'pr-local-bc' );
		if ( heading ) { box.appendChild( el( 'div', 'pr-local-bc__eyebrow', heading ) ); }
		var top = el( 'div', 'pr-local-bc__top' );
		if ( c.image && /^https?:\/\//.test( c.image ) ) { var img = el( 'img', 'pr-local-bc__img' ); img.src = c.image; img.alt = ''; img.loading = 'lazy'; top.appendChild( img ); }
		var t = el( 'div' ); t.appendChild( el( 'h3', 'pr-local-bc__name', c.name || '—' ) ); t.appendChild( el( 'div', 'pr-local-bc__type', c.type + ( c.price_range ? ' · ' + c.price_range : '' ) ) ); top.appendChild( t );
		box.appendChild( top );
		if ( c.description ) { box.appendChild( el( 'p', 'pr-local-bc__desc', c.description ) ); }
		var dl = el( 'dl', 'pr-local-bc__facts' );
		[ [ 'Address', c.address ], [ 'Phone', c.phone ], [ 'Email', c.email ], [ 'Website', c.url ], [ 'Serves', ( c.areas || [] ).join( ', ' ) ], [ 'Coordinates', c.geo ? c.geo[ 0 ].toFixed( 5 ) + ', ' + c.geo[ 1 ].toFixed( 5 ) : '' ] ].forEach( function ( f ) { if ( ! f[ 1 ] ) { return; } dl.appendChild( el( 'dt', null, f[ 0 ] ) ); dl.appendChild( el( 'dd', null, f[ 1 ] ) ); } );
		box.appendChild( dl );
		var today = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ][ new Date().getDay() ];
		var any = ( c.hours || [] ).some( function ( h ) { return h.times.length; } );
		if ( any ) {
			var tb = el( 'table', 'pr-local-bc__hours' ); tb.appendChild( el( 'caption', null, 'Opening hours' ) );
			c.hours.forEach( function ( h ) { var tr = el( 'tr', h.key === today ? 'is-today' : '' ); tr.appendChild( el( 'th', null, h.day ) ); tr.appendChild( el( 'td', null, h.times.length ? h.times.join( ', ' ) : 'Closed' ) ); tb.appendChild( tr ); } );
			box.appendChild( tb );
		} else { box.appendChild( el( 'p', 'pr-local-help', 'No opening hours set.' ) ); }
		return box;
	}
	function render( d ) {
		while ( cardBox.firstChild ) { cardBox.removeChild( cardBox.firstChild ); }
		while ( locBox.firstChild ) { locBox.removeChild( locBox.firstChild ); }
		while ( issueBox.firstChild ) { issueBox.removeChild( issueBox.firstChild ); }
		if ( ! d.enabled ) { cardBox.appendChild( el( 'p', 'pr-local-live__off', 'LocalBusiness output is switched off — nothing is printed until you turn it on and save.' ) ); }
		cardBox.appendChild( card( d.card, 'Main business' ) );
		( d.locations || [] ).forEach( function ( l, i ) { var c = card( l.card, 'Location ' + ( i + 1 ) + ( l.page ? ' — ' + l.page.replace( /^https?:\/\/[^/]+/, '' ) : ' — no landing page (not printed)' ) ); locBox.appendChild( c ); } );
		if ( jsonBox ) { jsonBox.textContent = d.json || ''; jsonBox.hidden = ! d.json; var je = root.querySelector( '[data-pr-local-json-empty]' ); if ( je ) { je.hidden = !! d.json; } }
		var errs = ( d.issues || [] ).filter( function ( i ) { return i.level !== 'notice'; } );
		if ( errs.length ) { var ul = el( 'ul', 'pr-local-live__issues' ); errs.forEach( function ( i ) { ul.appendChild( el( 'li', 'is-' + i.level, i.message ) ); } ); issueBox.appendChild( el( 'h3', 'pr-local-live__h', 'Schema.org checks' ) ); issueBox.appendChild( ul ); }
		var ring = root.querySelector( '.pr-local-ring' ); if ( ring ) { ring.style.setProperty( '--pr-local-score', d.score ); var b = ring.querySelector( 'b' ); if ( b ) { b.textContent = String( d.score ); } }
		var list = root.querySelector( '.pr-local-checks' );
		if ( list ) { while ( list.firstChild ) { list.removeChild( list.firstChild ); } var icons = { ok: 'fa-circle-check', warn: 'fa-circle-exclamation', error: 'fa-circle-xmark', info: 'fa-circle-question' }; d.checks.forEach( function ( c ) { var li = el( 'li', 'is-' + c.level ); var ic = el( 'i', 'fa-solid ' + icons[ c.level ] ); ic.setAttribute( 'aria-hidden', 'true' ); li.appendChild( ic ); li.appendChild( el( 'span', null, c.text ) ); list.appendChild( li ); } ); }
	}
	function run() {
		var fd = new FormData( form ), body = new URLSearchParams( fd ).toString();
		var dirty = body !== savedBody;
		if ( stateBox ) { stateBox.textContent = dirty ? 'Unsaved changes' : 'Matches saved settings'; stateBox.classList.toggle( 'is-dirty', dirty ); }
		fd.set( 'action', 'publishrank_local_seo_preview' );
		var my = ++seq;
		fetch( live.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) { return r.json(); } ).then( function ( j ) { if ( my !== seq || ! j || ! j.success ) { return; } render( j.data ); } ).catch( function () {} );
	}
	function schedule() { clearTimeout( timer ); timer = setTimeout( run, 450 ); }
	form.addEventListener( 'input', schedule );
	form.addEventListener( 'change', schedule );
	root.addEventListener( 'click', function ( e ) { if ( e.target.closest( '[data-pr-local-preset]' ) ) { schedule(); } } );
	run();
} )();
