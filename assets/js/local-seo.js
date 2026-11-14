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
