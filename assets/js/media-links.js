/**
 * PublishRank Media Link Manager (2.26.1).
 * Library, reference report, dry-run plans, batched apply with progress, verification, finalize and rollback,
 * media health scan and media host settings. Talks to publishrank/v1/media-links.
 */
( function () {
	'use strict';
	var C = window.publishrankMediaLinks || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	if ( ! apiFetch || ! document.querySelector( '.pr-ml' ) ) { return; }
	apiFetch.use( apiFetch.createNonceMiddleware( C.nonce ) );

	var S = { tab: 'library', q: { search: '', type: '', filter: '', page: 1, per_page: 20 }, list: null, item: null, refs: null, run: null, busy: false, lastFocus: null, health: null, host: null };

	/* ------------------------------------------------------------------ helpers */
	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( null === v || undefined === v || false === v ) { return; }
			if ( 'text' === k ) { n.textContent = v; } else if ( 'html' === k ) { n.innerHTML = v; } else if ( 0 === k.indexOf( 'on' ) && 'function' === typeof v ) { n.addEventListener( k.slice( 2 ), v ); } else if ( 'class' === k ) { n.className = v; } else if ( true === v ) { n.setAttribute( k, '' ); } else { n.setAttribute( k, v ); }
		} );
		( Array.isArray( kids ) ? kids : ( undefined === kids ? [] : [ kids ] ) ).forEach( function ( c ) {
			if ( null === c || undefined === c || false === c ) { return; }
			n.appendChild( 'string' === typeof c || 'number' === typeof c ? document.createTextNode( String( c ) ) : c );
		} );
		return n;
	}
	function api( path, opts ) {
		opts = opts || {};
		var url = C.root + path;
		if ( opts.query ) {
			var qs = Object.keys( opts.query ).filter( function ( k ) { return '' !== opts.query[ k ] && null !== opts.query[ k ]; } ).map( function ( k ) { return encodeURIComponent( k ) + '=' + encodeURIComponent( opts.query[ k ] ); } ).join( '&' );
			if ( qs ) { url += ( url.indexOf( '?' ) < 0 ? '?' : '&' ) + qs; }
		}
		return apiFetch( { url: url, method: opts.method || 'GET', data: opts.data } );
	}
	function toast( msg, err ) {
		var t = document.getElementById( 'pr-ml-toast' );
		if ( ! t ) { return; }
		t.textContent = msg;
		t.className = 'pr-ds-toast' + ( err ? ' pr-ds-toast--err' : '' );
		t.hidden = false;
		clearTimeout( toast.t );
		toast.t = setTimeout( function () { t.hidden = true; }, err ? 7000 : 3500 );
	}
	function fail( e ) { toast( ( e && e.message ) || 'Request failed.', true ); S.busy = false; }
	function bytes( n ) { n = +n || 0; if ( n < 1024 ) { return n + ' B'; } if ( n < 1048576 ) { return ( n / 1024 ).toFixed( 1 ) + ' KB'; } return ( n / 1048576 ).toFixed( 2 ) + ' MB'; }
	function badge( text, tone ) { return el( 'span', { class: 'pr-ds-badge' + ( tone ? ' pr-ds-badge--' + tone : '' ), text: text } ); }
	function card( title, sub, body, extra ) {
		return el( 'div', { class: 'pr-ds-card ' + ( extra || '' ) }, [
			el( 'div', { class: 'pr-ds-card__head' }, [ el( 'div', {}, [ el( 'h2', { class: 'pr-ds-card__title', text: title } ), sub ? el( 'p', { class: 'pr-ds-card__sub', text: sub } ) : null ] ) ] )
		].concat( body ) );
	}
	function copyBtn( value, label ) {
		return el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm pr-ds-btn--ghost', 'aria-label': ( label || 'Copy' ) + ': ' + value, onclick: function () {
			var done = function () { toast( 'Copied' ); };
			if ( navigator.clipboard && window.isSecureContext ) { navigator.clipboard.writeText( value ).then( done, function () { legacy(); } ); } else { legacy(); }
			function legacy() { var ta = el( 'textarea', { class: 'pr-ds-sr' } ); ta.value = value; document.body.appendChild( ta ); ta.select(); try { document.execCommand( 'copy' ); done(); } catch ( e ) {} ta.remove(); }
		} }, 'Copy' );
	}
	function urlLine( label, url, note ) {
		return [ el( 'dt', { text: label } ), el( 'dd', {}, [ url ? el( 'code', { class: 'pr-ml-url', text: url } ) : el( 'span', { class: 'pr-ds-help', text: '—' } ), url ? copyBtn( url, label ) : null, note ? el( 'p', { class: 'pr-ds-help', text: note } ) : null ] ) ];
	}
	var STATUS_TONE = { planned: 'info', applying: 'warn', applied: 'warn', partial: 'warn', verified: 'ok', finalized: 'ok', rolled_back: '', discarded: '', failed: 'err', pending: 'info', conflict: 'err', skipped: '', pass: 'ok', warn: 'warn', fail: 'err' };
	var STATUS_LABEL = { planned: 'Preview ready', applying: 'Applying', applied: 'Applied, needs review', partial: 'Partly applied', verified: 'Applied & verified', finalized: 'Finalized', rolled_back: 'Rolled back', failed: 'Failed (rolled back)', pending: 'Will change', conflict: 'Conflict', skipped: 'Not changed', pass: 'Passed', warn: 'Check', fail: 'Failed' };
	var OP_LABEL = { relocate: 'Rename / move file', replace_url: 'Point references to another URL', rebase: 'Site-wide media host change', slug: 'Attachment page address' };

	/* ------------------------------------------------------------------ tabs */
	function panel( id ) { return document.getElementById( 'pr-ml-panel-' + id ); }
	function selectTab( id, focus ) {
		S.tab = id;
		document.querySelectorAll( '.pr-ml .pr-ds-tab' ).forEach( function ( b ) {
			var on = b.getAttribute( 'data-tab' ) === id;
			b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			b.tabIndex = on ? 0 : -1;
			if ( on && focus ) { b.focus(); }
		} );
		document.querySelectorAll( '.pr-ml-panel' ).forEach( function ( p ) { p.hidden = p.id !== 'pr-ml-panel-' + id; } );
		try { history.replaceState( null, '', '#' + id ); } catch ( e ) {}
		( { library: drawLibrary, health: drawHealth, host: drawHost, history: drawHistory } )[ id ]();
	}
	document.querySelectorAll( '.pr-ml .pr-ds-tab' ).forEach( function ( b, i, all ) {
		b.addEventListener( 'click', function () { selectTab( b.getAttribute( 'data-tab' ) ); } );
		b.addEventListener( 'keydown', function ( e ) {
			var k = e.key, j = -1;
			if ( 'ArrowRight' === k ) { j = ( i + 1 ) % all.length; } else if ( 'ArrowLeft' === k ) { j = ( i - 1 + all.length ) % all.length; } else if ( 'Home' === k ) { j = 0; } else if ( 'End' === k ) { j = all.length - 1; }
			if ( j >= 0 ) { e.preventDefault(); selectTab( all[ j ].getAttribute( 'data-tab' ), true ); }
		} );
	} );

	/* ------------------------------------------------------------------ library */
	function drawLibrary() {
		var p = panel( 'library' );
		p.innerHTML = '';
		if ( ! +C.ready ) { p.appendChild( el( 'div', { class: 'pr-ds-notice pr-ds-notice--warn' }, 'The change-log tables are being installed. Reload this page in a moment.' ) ); }
		var search = el( 'input', { type: 'search', class: 'pr-ds-input', id: 'pr-ml-search', value: S.q.search, placeholder: 'File name, title, ID or URL', 'aria-describedby': 'pr-ml-search-help' } );
		var type = el( 'select', { class: 'pr-ds-input', id: 'pr-ml-type' }, [ [ '', 'All types' ], [ 'image', 'Images' ], [ 'video', 'Video' ], [ 'audio', 'Audio' ], [ 'application', 'Documents' ], [ 'text', 'Text' ] ].map( function ( o ) { return el( 'option', { value: o[ 0 ], selected: S.q.type === o[ 0 ] }, o[ 1 ] ); } ) );
		var filter = el( 'select', { class: 'pr-ds-input', id: 'pr-ml-filter' }, [ [ '', 'Everything' ], [ 'missing', 'Missing files (from health scan)' ], [ 'duplicates', 'Duplicates (from health scan)' ], [ 'unattached', 'Not attached to a post' ] ].map( function ( o ) { return el( 'option', { value: o[ 0 ], selected: S.q.filter === o[ 0 ] }, o[ 1 ] ); } ) );
		var form = el( 'form', { class: 'pr-ml-toolbar', role: 'search', onsubmit: function ( e ) { e.preventDefault(); S.q.search = search.value.trim(); S.q.type = type.value; S.q.filter = filter.value; S.q.page = 1; loadList(); } }, [
			el( 'div', { class: 'pr-ds-field pr-ml-toolbar__search' }, [ el( 'label', { for: 'pr-ml-search', text: 'Search media' } ), search, el( 'p', { class: 'pr-ds-help', id: 'pr-ml-search-help', text: 'Paste any address of the file — any size, http or https, with or without ?query — to find its attachment.' } ) ] ),
			el( 'div', { class: 'pr-ds-field' }, [ el( 'label', { for: 'pr-ml-type', text: 'File type' } ), type ] ),
			el( 'div', { class: 'pr-ds-field' }, [ el( 'label', { for: 'pr-ml-filter', text: 'Show' } ), filter ] ),
			el( 'div', { class: 'pr-ml-toolbar__go' }, el( 'button', { type: 'submit', class: 'pr-ds-btn pr-ds-btn--primary' }, 'Search' ) )
		] );
		p.appendChild( form );
		p.appendChild( el( 'div', { id: 'pr-ml-results', 'aria-live': 'polite', 'aria-busy': 'true' } ) );
		loadList();
	}
	function loadList() {
		var box = document.getElementById( 'pr-ml-results' );
		if ( ! box ) { return; }
		box.setAttribute( 'aria-busy', 'true' );
		box.innerHTML = '';
		box.appendChild( el( 'div', { class: 'pr-ds-skeleton pr-ml-skel' } ) );
		api( '/attachments', { query: S.q } ).then( function ( r ) { S.list = r; renderList(); }, fail );
	}
	function renderList() {
		var box = document.getElementById( 'pr-ml-results' );
		box.innerHTML = '';
		box.setAttribute( 'aria-busy', 'false' );
		var r = S.list || { items: [] };
		if ( ! r.items.length ) {
			box.appendChild( el( 'div', { class: 'pr-ds-empty' }, [ el( 'strong', { text: 'No media found' } ), S.q.filter ? 'Run the health scan first to fill this filter.' : 'Try another name, ID or URL.' ] ) );
			return;
		}
		box.appendChild( el( 'p', { class: 'pr-ds-help', text: r.total + ' item' + ( 1 === r.total ? '' : 's' ) } ) );
		var rows = r.items.map( function ( it ) {
			var flags = [];
			if ( it.missing && it.missing.length ) { flags.push( badge( 'Missing file', 'err' ) ); }
			if ( it.host_mapped ) { flags.push( badge( 'Served from media host', 'info' ) ); }
			if ( it.refs ) { flags.push( badge( it.refs.url + ' URL / ' + it.refs.id + ' ID references', it.refs.url || it.refs.id ? 'brand' : '' ) ); }
			return el( 'tr', {}, [
				el( 'td', { class: 'pr-ml-thumbcell' }, el( 'img', { src: it.thumb, alt: '', width: 48, height: 48, loading: 'lazy', class: 'pr-ml-thumb' } ) ),
				el( 'td', { 'data-label': 'File' }, [ el( 'strong', { class: 'pr-ml-title', text: it.title || it.filename } ), el( 'span', { class: 'pr-ml-sub', text: it.filename + ' · #' + it.id } ), el( 'span', { class: 'pr-ml-flags' }, flags ) ] ),
				el( 'td', { 'data-label': 'Type' }, it.mime ),
				el( 'td', { 'data-label': 'Size' }, [ it.width ? it.width + '×' + it.height : '—', el( 'br' ), el( 'span', { class: 'pr-ds-help', text: bytes( it.filesize ) + ( it.sizes ? ' · ' + it.sizes + ' sizes' : '' ) } ) ] ),
				el( 'td', { 'data-label': 'Current URL', class: 'pr-ml-urlcell' }, el( 'code', { class: 'pr-ml-url', text: it.url } ) ),
				el( 'td', { class: 'pr-ml-actions' }, el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', disabled: ! it.can_edit, onclick: function ( e ) { openItem( it.id, e.currentTarget ); }, 'aria-label': 'Manage ' + ( it.title || it.filename ) }, 'Manage' ) )
			] );
		} );
		box.appendChild( el( 'div', { class: 'pr-ds-table-wrap' }, el( 'table', { class: 'pr-ds-table pr-ml-table' }, [
			el( 'caption', { class: 'pr-ds-sr', text: 'Media attachments' } ),
			el( 'thead', {}, el( 'tr', {}, [ el( 'th', { scope: 'col' }, el( 'span', { class: 'pr-ds-sr', text: 'Preview' } ) ), el( 'th', { scope: 'col', text: 'File' } ), el( 'th', { scope: 'col', text: 'Type' } ), el( 'th', { scope: 'col', text: 'Dimensions' } ), el( 'th', { scope: 'col', text: 'Current URL' } ), el( 'th', { scope: 'col' }, el( 'span', { class: 'pr-ds-sr', text: 'Actions' } ) ) ] ) ),
			el( 'tbody', {}, rows )
		] ) ) );
		if ( r.pages > 1 ) {
			box.appendChild( el( 'nav', { class: 'pr-ml-pager', 'aria-label': 'Pages' }, [
				el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', disabled: S.q.page <= 1, onclick: function () { S.q.page--; loadList(); } }, '← Previous' ),
				el( 'span', { text: 'Page ' + S.q.page + ' of ' + r.pages } ),
				el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', disabled: S.q.page >= r.pages, onclick: function () { S.q.page++; loadList(); } }, 'Next →' )
			] ) );
		}
	}

	/* ------------------------------------------------------------------ drawer */
	var drawer = document.getElementById( 'pr-ml-drawer' );
	function openDrawer( title, opener ) {
		S.lastFocus = opener || document.activeElement;
		drawer.innerHTML = '';
		var closeBtn = el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--ghost pr-ml-drawer__close', onclick: closeDrawer, 'aria-label': 'Close' }, '×' );
		var box = el( 'div', { class: 'pr-ml-drawer__box' }, [
			el( 'div', { class: 'pr-ml-drawer__head' }, [ el( 'h2', { id: 'pr-ml-drawer-title', text: title } ), closeBtn ] ),
			el( 'div', { class: 'pr-ml-drawer__body', id: 'pr-ml-drawer-body' } )
		] );
		drawer.appendChild( el( 'div', { class: 'pr-ml-drawer__scrim', onclick: closeDrawer } ) );
		drawer.appendChild( box );
		drawer.hidden = false;
		document.documentElement.classList.add( 'pr-ml-locked' );
		closeBtn.focus();
		return document.getElementById( 'pr-ml-drawer-body' );
	}
	function closeDrawer() {
		if ( S.busy && ! window.confirm( 'A batch is still running. Close anyway? You can continue it from Change history.' ) ) { return; }
		drawer.hidden = true;
		drawer.innerHTML = '';
		document.documentElement.classList.remove( 'pr-ml-locked' );
		if ( S.lastFocus && S.lastFocus.focus ) { S.lastFocus.focus(); }
		if ( 'library' === S.tab && document.getElementById( 'pr-ml-results' ) ) { loadList(); }
		if ( 'history' === S.tab ) { drawHistory(); }
	}
	drawer.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) { e.preventDefault(); closeDrawer(); return; }
		if ( 'Tab' !== e.key ) { return; }
		var f = Array.prototype.filter.call( drawer.querySelectorAll( 'a[href],button:not([disabled]),input:not([disabled]),select,textarea,summary,[tabindex="0"]' ), function ( n ) { return n.offsetParent !== null; } );
		if ( ! f.length ) { return; }
		if ( e.shiftKey && document.activeElement === f[ 0 ] ) { e.preventDefault(); f[ f.length - 1 ].focus(); } else if ( ! e.shiftKey && document.activeElement === f[ f.length - 1 ] ) { e.preventDefault(); f[ 0 ].focus(); }
	} );

	function openItem( id, opener ) {
		var body = openDrawer( 'Media #' + id, opener );
		body.appendChild( el( 'div', { class: 'pr-ds-skeleton pr-ml-skel' } ) );
		S.refs = null;
		api( '/attachments/' + id ).then( function ( it ) { S.item = it; document.getElementById( 'pr-ml-drawer-title' ).textContent = it.title || it.filename; renderItem(); }, fail );
	}

	function renderItem() {
		var it = S.item, body = document.getElementById( 'pr-ml-drawer-body' );
		if ( ! body ) { return; }
		body.innerHTML = '';
		var head = el( 'div', { class: 'pr-ml-item' }, [
			el( 'img', { src: it.thumb, alt: '', class: 'pr-ml-item__thumb', width: 96, height: 96 } ),
			el( 'div', {}, [ el( 'p', { class: 'pr-ml-sub', text: it.mime + ' · ' + ( it.width ? it.width + '×' + it.height + ' · ' : '' ) + bytes( it.filesize ) + ' · uploaded ' + it.uploaded } ), el( 'p', {}, [ el( 'a', { href: it.edit, class: 'pr-ds-btn pr-ds-btn--sm' }, 'Edit attachment' ) ] ), it.missing && it.missing.length ? el( 'div', { class: 'pr-ds-notice pr-ds-notice--err', role: 'alert' }, 'Missing on disk: ' + it.missing.join( ', ' ) ) : null ] )
		] );
		body.appendChild( head );
		var dl = el( 'dl', { class: 'pr-ds-kv pr-ml-kv' }, [].concat(
			urlLine( 'Current URL', it.url, it.host_mapped ? 'Served through your media host / CDN setting.' : '' ),
			it.host_mapped ? urlLine( 'Stored URL', it.stored_url ) : [],
			urlLine( 'Original upload URL', it.original_url, 'WordPress keeps this as a permanent identifier (GUID). PublishRank never changes it; it is not what visitors load.' ),
			urlLine( 'Attachment page', it.page_link, it.pages_on ? '' : 'Attachment pages are turned off on this site, so this address redirects to the file.' )
		) );
		body.appendChild( card( 'Addresses', '', [ dl ] ) );
		var files = el( 'table', { class: 'pr-ds-table pr-ml-files' }, [ el( 'thead', {}, el( 'tr', {}, [ 'Size', 'File', 'Dimensions', 'Bytes', 'On disk' ].map( function ( h ) { return el( 'th', { scope: 'col', text: h } ); } ) ) ), el( 'tbody', {}, ( it.files || [] ).map( function ( f ) {
			return el( 'tr', {}, [ el( 'td', { 'data-label': 'Size', text: f.kind.replace( 'size:', '' ) } ), el( 'td', { 'data-label': 'File' }, el( 'code', { class: 'pr-ml-url', text: f.rel } ) ), el( 'td', { 'data-label': 'Dimensions', text: f.width ? f.width + '×' + f.height : '—' } ), el( 'td', { 'data-label': 'Bytes', text: bytes( f.bytes ) } ), el( 'td', { 'data-label': 'On disk' }, f.exists ? badge( 'Yes', 'ok' ) : badge( 'Missing', 'err' ) ) ] );
		} ) ) ] );
		body.appendChild( el( 'details', { class: 'pr-ml-details' }, [ el( 'summary', { text: 'Files (' + ( it.files || [] ).length + ')' } ), el( 'div', { class: 'pr-ds-table-wrap' }, files ) ] ) );
		var refsBox = el( 'div', { id: 'pr-ml-refs' } );
		body.appendChild( card( 'Where it is used', 'Searches post content, block attributes, excerpts, custom fields, term and user fields, options, widgets, WooCommerce data and comments.', [ el( 'div', { class: 'pr-ds-btn-row' }, el( 'button', { type: 'button', class: 'pr-ds-btn', onclick: function ( e ) { loadRefs( e.currentTarget ); } }, S.refs ? 'Search again' : 'Find references' ) ), refsBox ] ) );
		if ( S.refs ) { renderRefs(); }
		body.appendChild( changeForm( it ) );
		if ( it.runs && it.runs.length ) {
			body.appendChild( card( 'Earlier changes to this file', '', [ runsTable( it.runs ) ] ) );
		}
	}

	function loadRefs( btn ) {
		var box = document.getElementById( 'pr-ml-refs' );
		box.innerHTML = '';
		box.appendChild( el( 'p', { class: 'pr-ds-help', role: 'status' }, [ el( 'span', { class: 'pr-ds-spinner' } ), ' Searching the database…' ] ) );
		if ( btn ) { btn.disabled = true; }
		api( '/attachments/' + S.item.id + '/references' ).then( function ( r ) { S.refs = r; if ( btn ) { btn.disabled = false; btn.textContent = 'Search again'; } renderRefs(); }, function ( e ) { if ( btn ) { btn.disabled = false; } fail( e ); } );
	}
	function renderRefs() {
		var r = S.refs, box = document.getElementById( 'pr-ml-refs' );
		if ( ! box ) { return; }
		box.innerHTML = '';
		box.appendChild( el( 'div', { class: 'pr-ml-stats' }, [
			stat( r.url_refs.length, 'records with URL references', r.url_matches + ' matches in total' ),
			stat( r.id_refs.length, 'references by ID', 'keep working when the file moves' ),
			stat( r.ambiguous, 'ambiguous values', 'reported, never rewritten', r.ambiguous ? 'warn' : '' )
		] ) );
		if ( r.limit_hit ) { box.appendChild( el( 'div', { class: 'pr-ds-notice pr-ds-notice--warn' }, 'More records exist than one search returns. After applying a change, plan again to catch the rest.' ) ); }
		if ( r.url_refs.length ) {
			box.appendChild( el( 'div', { class: 'pr-ds-table-wrap' }, el( 'table', { class: 'pr-ds-table pr-ml-reftable' }, [
				el( 'caption', { class: 'pr-ds-sr', text: 'URL references' } ),
				el( 'thead', {}, el( 'tr', {}, [ 'Where', 'Record', 'Field', 'Format', 'Matches' ].map( function ( h ) { return el( 'th', { scope: 'col', text: h } ); } ) ) ),
				el( 'tbody', {}, r.url_refs.map( function ( x ) {
					return el( 'tr', {}, [
						el( 'td', { 'data-label': 'Where', text: x.location } ),
						el( 'td', { 'data-label': 'Record' }, x.edit ? el( 'a', { href: x.edit, target: '_blank', rel: 'noopener' }, x.label ) : x.label ),
						el( 'td', { 'data-label': 'Field' }, el( 'code', { text: x.field } ) ),
						el( 'td', { 'data-label': 'Format' }, badge( x.format, 'json' === x.format || 'serialized' === x.format ? 'info' : '' ) ),
						el( 'td', { 'data-label': 'Matches' }, x.error ? badge( 'Ambiguous', 'warn' ) : String( x.matches ) )
					] );
				} ) )
			] ) ) );
		} else {
			box.appendChild( el( 'p', { class: 'pr-ds-help', text: 'No stored URL references found.' } ) );
		}
		if ( r.id_refs.length ) {
			box.appendChild( el( 'h3', { class: 'pr-ml-h3', text: 'Used by ID' } ) );
			box.appendChild( el( 'ul', { class: 'pr-ml-list' }, r.id_refs.map( function ( x ) { return el( 'li', {}, [ badge( x.location ), ' ', x.edit ? el( 'a', { href: x.edit, target: '_blank', rel: 'noopener' }, x.label ) : x.label ] ); } ) ) );
		}
		box.appendChild( el( 'details', { class: 'pr-ml-details' }, [ el( 'summary', { text: 'Generated automatically (no stored URL)' } ), el( 'ul', { class: 'pr-ml-list' }, r.derived.map( function ( t ) { return el( 'li', { text: t } ); } ) ) ] ) );
		box.appendChild( el( 'details', { class: 'pr-ml-details' }, [ el( 'summary', { text: 'Not searched' } ), el( 'p', { class: 'pr-ds-help', text: 'These places can also contain the address. PublishRank cannot see them, so check them yourself after a change:' } ), el( 'ul', { class: 'pr-ml-list' }, r.not_searched.map( function ( t ) { return el( 'li', { text: t } ); } ) ) ] ) );
	}
	function stat( n, label, sub, tone ) { return el( 'div', { class: 'pr-ml-stat' + ( tone ? ' pr-ml-stat--' + tone : '' ) }, [ el( 'strong', { text: String( n ) } ), el( 'span', { text: label } ), sub ? el( 'small', { text: sub } ) : null ] ); }

	function changeForm( it ) {
		var ops = [
			{ id: 'relocate', label: 'Rename or move the file', help: 'Copies every size to the new name, updates the attachment and every stored reference. The original stays until you finalize, so nothing breaks in between.' },
			{ id: 'replace_url', label: 'Point references to another URL', help: 'For files you moved to another host or replaced. Only stored references change; the attachment and its files stay as they are.' },
			{ id: 'slug', label: 'Change the attachment page address', help: it.pages_on ? 'Changes the address of this file\'s attachment page.' : 'Attachment pages are turned off on this site; the slug still exists but visitors are redirected to the file.' }
		];
		var cur = S.op || 'relocate';
		var fields = el( 'div', { class: 'pr-ml-opfields' } );
		var radios = el( 'fieldset', { class: 'pr-ml-ops' }, [ el( 'legend', { class: 'pr-ds-label', text: 'What do you want to change?' } ) ].concat( ops.map( function ( o ) {
			var r = el( 'input', { type: 'radio', name: 'pr-ml-op', value: o.id, checked: o.id === cur, onchange: function () { S.op = o.id; drawFields(); } } );
			return el( 'label', { class: 'pr-ml-op' }, [ r, el( 'span', {}, [ el( 'strong', { text: o.label } ), el( 'small', { text: o.help } ) ] ) ] );
		} ) ) );
		function drawFields() {
			cur = S.op || 'relocate';
			fields.innerHTML = '';
			if ( 'relocate' === cur ) {
				fields.appendChild( el( 'div', { class: 'pr-ds-field' }, [ el( 'label', { for: 'pr-ml-dest', text: 'New file name or path inside uploads' } ), el( 'input', { id: 'pr-ml-dest', class: 'pr-ds-input', type: 'text', value: it.path, spellcheck: 'false', autocomplete: 'off', 'aria-describedby': 'pr-ml-dest-help' } ), el( 'p', { class: 'pr-ds-help', id: 'pr-ml-dest-help', text: 'Example: budget-household-guide.jpg (same folder) or 2026/guides/budget.jpg. Keep the extension; size suffixes are added automatically.' } ) ] ) );
				fields.appendChild( el( 'label', { class: 'pr-ds-check' }, [ el( 'input', { type: 'checkbox', id: 'pr-ml-redirect', checked: true, disabled: ! C.redirects } ), 'When I finalize, redirect the old file addresses to the new ones' ] ) );
			} else if ( 'replace_url' === cur ) {
				fields.appendChild( el( 'div', { class: 'pr-ds-field' }, [ el( 'label', { for: 'pr-ml-newurl', text: 'New URL' } ), el( 'input', { id: 'pr-ml-newurl', class: 'pr-ds-input', type: 'url', placeholder: 'https://media.example.com/budget.jpg', 'aria-describedby': 'pr-ml-newurl-help' } ), el( 'p', { class: 'pr-ds-help', id: 'pr-ml-newurl-help', text: 'The address is checked before planning: it must load and serve the same kind of file. Every size of this image points to it.' } ) ] ) );
			} else {
				fields.appendChild( el( 'div', { class: 'pr-ds-field' }, [ el( 'label', { for: 'pr-ml-slug', text: 'New attachment page slug' } ), el( 'input', { id: 'pr-ml-slug', class: 'pr-ds-input', type: 'text', value: it.slug } ) ] ) );
				fields.appendChild( el( 'label', { class: 'pr-ds-check' }, [ el( 'input', { type: 'checkbox', id: 'pr-ml-redirect', checked: it.pages_on, disabled: ! it.pages_on || ! C.redirects } ), 'Redirect the old attachment page address' ] ) );
			}
		}
		drawFields();
		var go = el( 'button', { type: 'submit', class: 'pr-ds-btn pr-ds-btn--primary' }, 'Preview changes (dry run)' );
		var form = el( 'form', { class: 'pr-ml-change', onsubmit: function ( e ) {
			e.preventDefault();
			var data = { operation: cur };
			var g = function ( id ) { var n = document.getElementById( id ); return n ? ( 'checkbox' === n.type ? n.checked : n.value ) : ''; };
			data.destination = g( 'pr-ml-dest' ); data.new_url = g( 'pr-ml-newurl' ); data.slug = g( 'pr-ml-slug' ); data.redirect = !! g( 'pr-ml-redirect' );
			go.disabled = true; go.textContent = 'Building preview…';
			api( '/attachments/' + it.id + '/plan', { method: 'POST', data: data } ).then( function ( run ) { go.disabled = false; showRun( run ); }, function ( e ) { go.disabled = false; go.textContent = 'Preview changes (dry run)'; fail( e ); } );
		} }, [ radios, fields, el( 'p', { class: 'pr-ds-help', text: 'Nothing is written until you review the preview and confirm.' } ), el( 'div', { class: 'pr-ds-btn-row' }, go ) ] );
		return card( 'Change address', '', [ form ] );
	}

	/* ------------------------------------------------------------------ run view */
	function showRun( run, opener ) {
		S.run = run;
		var body = document.getElementById( 'pr-ml-drawer-body' );
		if ( ! body || drawer.hidden ) { body = openDrawer( OP_LABEL[ run.operation ] || 'Change', opener ); } else { document.getElementById( 'pr-ml-drawer-title' ).textContent = OP_LABEL[ run.operation ] || 'Change'; }
		body.innerHTML = '';
		body.appendChild( renderRun( run ) );
		var h = body.querySelector( 'h3' );
		if ( h ) { h.setAttribute( 'tabindex', '-1' ); h.focus(); }
	}
	function renderRun( run ) {
		var wrap = el( 'div', { class: 'pr-ml-run' } );
		var c = run.counts || {};
		wrap.appendChild( el( 'div', { class: 'pr-ml-run__head' }, [
			el( 'h3', { class: 'pr-ml-h3', text: 'Run #' + run.id + ' · ' + ( OP_LABEL[ run.operation ] || run.operation ) } ),
			badge( STATUS_LABEL[ run.status ] || run.status, STATUS_TONE[ run.status ] )
		] ) );
		if ( run.message ) { wrap.appendChild( el( 'div', { class: 'pr-ds-notice pr-ds-notice--' + ( /could not|failed/i.test( run.message ) ? 'warn' : 'ok' ), role: 'status' }, run.message ) ); }
		wrap.appendChild( el( 'p', { class: 'pr-ds-help', text: run.kind } ) );
		wrap.appendChild( el( 'dl', { class: 'pr-ds-kv pr-ml-kv' }, [].concat( urlLine( 'From', run.old_url ), urlLine( 'To', run.new_url || ( run.plan && run.plan.slug ) || '' ) ) ) );
		wrap.appendChild( el( 'div', { class: 'pr-ml-stats' }, [
			stat( ( c.pending || 0 ) + ( c.applied || 0 ) + ( c.rolled_back || 0 ), 'records and files', 'pending ' + ( c.pending || 0 ) + ' · applied ' + ( c.applied || 0 ) ),
			stat( ( c.conflict || 0 ) + ( c.failed || 0 ), 'conflicts or failures', '', ( c.conflict || c.failed ) ? 'err' : '' ),
			stat( c.skipped || 0, 'reported, not changed', 'ambiguous or no exact match', c.skipped ? 'warn' : '' )
		] ) );
		if ( run.plan && run.plan.limit_note ) { wrap.appendChild( el( 'p', { class: 'pr-ds-help', text: run.plan.limit_note } ) ); }
		if ( run.plan && run.plan.precheck ) { wrap.appendChild( el( 'p', { class: 'pr-ds-help' }, [ 'Destination check: ', badge( STATUS_LABEL[ run.plan.precheck.status ] || run.plan.precheck.status, STATUS_TONE[ run.plan.precheck.status ] ), ' ', run.plan.precheck.detail ] ) ); }
		// Progress
		var prog = el( 'div', { class: 'pr-ml-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': run.progress, 'aria-label': 'Progress' }, el( 'span', { style: 'width:' + ( 'planned' === run.status ? 0 : run.progress ) + '%' } ) );
		if ( 'planned' !== run.status ) { wrap.appendChild( prog ); }
		// Actions
		var actions = el( 'div', { class: 'pr-ds-btn-row pr-ml-run__actions' } );
		var st = run.status;
		if ( 'planned' === st ) {
			var n = ( c.pending || 0 );
			var ok = el( 'input', { type: 'checkbox', id: 'pr-ml-confirm' } );
			var apply = el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', disabled: true, onclick: function () { applyRun( run.id ); } }, 'Apply ' + n + ' change' + ( 1 === n ? '' : 's' ) );
			ok.addEventListener( 'change', function () { apply.disabled = ! ok.checked || ! n; } );
			wrap.appendChild( el( 'label', { class: 'pr-ds-check pr-ml-confirm' }, [ ok, 'I reviewed the preview. Every previous value is kept so this can be rolled back.' ] ) );
			actions.appendChild( apply );
			actions.appendChild( el( 'button', { type: 'button', class: 'pr-ds-btn', onclick: function () { api( '/runs/' + run.id, { method: 'DELETE' } ).then( function () { toast( 'Preview discarded' ); if ( S.item ) { openItem( S.item.id ); } else { closeDrawer(); } }, fail ); } }, 'Discard preview' ) );
		}
		if ( 'applying' === st ) { actions.appendChild( el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', onclick: function () { applyRun( run.id ); } }, 'Continue applying' ) ); }
		if ( 'partial' === st ) { actions.appendChild( el( 'button', { type: 'button', class: 'pr-ds-btn', onclick: function () { api( '/runs/' + run.id + '/retry', { method: 'POST' } ).then( function ( r ) { showRun( r ); applyRun( r.id ); }, fail ); } }, 'Retry failed and conflicting records' ) ); }
		if ( [ 'applied', 'partial', 'verified', 'finalized' ].indexOf( st ) >= 0 ) {
			actions.appendChild( el( 'button', { type: 'button', class: 'pr-ds-btn', onclick: function ( e ) { e.currentTarget.disabled = true; api( '/runs/' + run.id + '/verify', { method: 'POST' } ).then( showRun, fail ); } }, 'Verify again' ) );
		}
		if ( 'relocate' === run.operation && ( 'verified' === st || 'applied' === st ) ) {
			actions.appendChild( el( 'button', { type: 'button', class: 'pr-ds-btn', disabled: !! ( run.report.verify && run.report.verify.failed ), onclick: function () { finalize( run ); } }, 'Finalize: move originals to backup' ) );
		}
		if ( [ 'applying', 'applied', 'partial', 'verified', 'finalized' ].indexOf( st ) >= 0 ) {
			actions.appendChild( el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--danger', onclick: function () { if ( window.confirm( 'Roll back run #' + run.id + '? Every record this run changed gets its previous value back. Records edited since keep those edits.' ) ) { S.busy = true; api( '/runs/' + run.id + '/rollback', { method: 'POST' } ).then( function ( r ) { S.busy = false; showRun( r ); toast( r.message || 'Rolled back' ); }, fail ); } } }, 'Roll back' ) );
		}
		actions.appendChild( el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--ghost', onclick: function () { exportRun( run.id ); } }, 'Download report (CSV)' ) );
		if ( S.item && S.item.id === run.attachment ) { actions.appendChild( el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--ghost', onclick: function () { openItem( run.attachment ); } }, 'Back to file' ) ); }
		wrap.appendChild( actions );
		// Verification
		var v = run.report && run.report.verify;
		if ( v && v.checks ) {
			wrap.appendChild( el( 'h3', { class: 'pr-ml-h3', text: 'Verification' + ( v.failed ? ' — ' + v.failed + ' failed' : ( v.warnings ? ' — passed with ' + v.warnings + ' to check' : ' — all passed' ) ) } ) );
			wrap.appendChild( el( 'ul', { class: 'pr-ml-checks' }, v.checks.map( function ( ch ) { return el( 'li', { class: 'pr-ml-check pr-ml-check--' + ch.status }, [ badge( STATUS_LABEL[ ch.status ] || ch.status, STATUS_TONE[ ch.status ] ), el( 'span', { text: ch.label } ), ch.detail ? el( 'small', { text: ch.detail } ) : null ] ); } ) ) );
		}
		if ( run.report && run.report.finalized ) { wrap.appendChild( el( 'p', { class: 'pr-ds-help', text: 'Finalized ' + run.report.finalized.at + ' UTC · originals in uploads/' + run.report.finalized.backup + ' · ' + run.report.finalized.redirects + ' redirects' } ) ); }
		// Moves
		if ( run.moves && run.moves.length ) {
			wrap.appendChild( el( 'details', { class: 'pr-ml-details', open: 'planned' === st } , [ el( 'summary', { text: 'Files (' + run.moves.length + ')' } ), el( 'ul', { class: 'pr-ml-list pr-ml-moves' }, run.moves.map( function ( m ) { return el( 'li', {}, [ el( 'code', { text: m.old } ), ' → ', el( 'code', { text: m.new } ) ] ); } ) ) ] ) );
		}
		// Changes
		var list = ( run.changes || [] ).filter( function ( ch ) { return 'file' !== ch.type; } );
		wrap.appendChild( el( 'h3', { class: 'pr-ml-h3', text: ( 'planned' === st ? 'Affected records' : 'Records' ) + ' (' + list.length + ')' } ) );
		if ( ! list.length ) { wrap.appendChild( el( 'p', { class: 'pr-ds-help', text: 'No stored references need to change.' } ) ); }
		wrap.appendChild( el( 'ol', { class: 'pr-ml-changes' }, list.map( function ( ch ) {
			return el( 'li', { class: 'pr-ml-chg' }, [
				el( 'div', { class: 'pr-ml-chg__head' }, [ badge( STATUS_LABEL[ ch.status ] || ch.status, STATUS_TONE[ ch.status ] ), el( 'span', { class: 'pr-ml-chg__label' }, ch.edit ? el( 'a', { href: ch.edit, target: '_blank', rel: 'noopener' }, ch.label ) : ch.label ), el( 'code', { class: 'pr-ml-chg__field', text: ch.field } ), ch.matches ? el( 'span', { class: 'pr-ds-help', text: ch.matches + ' match' + ( 1 === ch.matches ? '' : 'es' ) } ) : null ] ),
				ch.message ? el( 'p', { class: 'pr-ds-help', text: ch.message } ) : null,
				ch.diff && ch.diff.before ? el( 'details', { class: 'pr-ml-diff' }, [ el( 'summary', { text: 'Before / after' } ), el( 'div', { class: 'pr-ml-diff__grid' }, [ el( 'div', {}, [ el( 'span', { class: 'pr-ml-diff__tag', text: 'Before' } ), el( 'pre', { class: 'pr-ml-diff__old', text: ch.diff.before } ) ] ), el( 'div', {}, [ el( 'span', { class: 'pr-ml-diff__tag', text: 'After' } ), el( 'pre', { class: 'pr-ml-diff__new', text: ch.diff.after } ) ] ) ] ) ] ) : null
			] );
		} ) ) );
		return wrap;
	}

	function applyRun( id ) {
		S.busy = true;
		var step = function () {
			api( '/runs/' + id + '/apply', { method: 'POST', data: { confirm: 1, batch: 40 } } ).then( function ( r ) {
				var bar = document.querySelector( '.pr-ml-progress' );
				if ( r.pending > 0 && 'applying' === r.status ) {
					if ( bar ) { bar.setAttribute( 'aria-valuenow', r.progress ); bar.firstChild.style.width = r.progress + '%'; } else { showRun( r ); }
					step();
					return;
				}
				S.busy = false;
				showRun( r );
				toast( r.message || ( 'verified' === r.status ? 'Applied and verified' : 'Applied — review the verification' ), 'failed' === r.status );
			}, function ( e ) { S.busy = false; fail( e ); api( '/runs/' + id ).then( showRun ); } );
		};
		var body = document.getElementById( 'pr-ml-drawer-body' );
		if ( body ) {
			var a = body.querySelector( '.pr-ml-run__actions' );
			if ( a ) { a.innerHTML = ''; a.appendChild( el( 'p', { class: 'pr-ds-help', role: 'status' }, [ el( 'span', { class: 'pr-ds-spinner' } ), ' Applying in batches. Keep this window open.' ] ) ); }
			if ( ! body.querySelector( '.pr-ml-progress' ) ) { a.parentNode.insertBefore( el( 'div', { class: 'pr-ml-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': 0, 'aria-label': 'Progress' }, el( 'span', { style: 'width:0%' } ) ), a ); }
		}
		step();
	}
	function finalize( run ) {
		var redirect = C.redirects && run.plan && run.plan.redirect;
		if ( ! window.confirm( 'Move the original files to the PublishRank backup folder?' + ( redirect ? ' Redirects from the old file addresses will be added.' : ' Old file addresses will stop loading.' ) + ' You can still roll back afterwards.' ) ) { return; }
		api( '/runs/' + run.id + '/finalize', { method: 'POST', data: { confirm: 1, redirect: redirect ? 1 : 0 } } ).then( function ( r ) { showRun( r ); toast( r.message || 'Finalized' ); }, fail );
	}
	function exportRun( id ) {
		api( '/runs/' + id + '/export' ).then( function ( r ) {
			var a = el( 'a', { href: URL.createObjectURL( new Blob( [ r.csv ], { type: 'text/csv' } ) ), download: r.filename } );
			document.body.appendChild( a ); a.click(); setTimeout( function () { URL.revokeObjectURL( a.href ); a.remove(); }, 500 );
		}, fail );
	}

	/* ------------------------------------------------------------------ history */
	function runsTable( runs ) {
		return el( 'div', { class: 'pr-ds-table-wrap' }, el( 'table', { class: 'pr-ds-table pr-ml-runs' }, [
			el( 'caption', { class: 'pr-ds-sr', text: 'Change history' } ),
			el( 'thead', {}, el( 'tr', {}, [ 'Run', 'Change', 'File', 'Status', 'Records', 'When', '' ].map( function ( h ) { return el( 'th', { scope: 'col', text: h } ); } ) ) ),
			el( 'tbody', {}, runs.map( function ( r ) {
				return el( 'tr', {}, [
					el( 'td', { 'data-label': 'Run', text: '#' + r.id } ),
					el( 'td', { 'data-label': 'Change', text: OP_LABEL[ r.operation ] || r.operation } ),
					el( 'td', { 'data-label': 'File', text: r.title || ( r.attachment_id ? '#' + r.attachment_id : 'Site-wide' ) } ),
					el( 'td', { 'data-label': 'Status' }, badge( STATUS_LABEL[ r.status ] || r.status, STATUS_TONE[ r.status ] ) ),
					el( 'td', { 'data-label': 'Records', text: r.done + ' / ' + r.total + ( +r.failed ? ' · ' + r.failed + ' problems' : '' ) } ),
					el( 'td', { 'data-label': 'When', text: r.updated_at + ' UTC' + ( r.user ? ' · ' + r.user : '' ) } ),
					el( 'td', { class: 'pr-ml-actions' }, el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', onclick: function ( e ) { var b = e.currentTarget; api( '/runs/' + r.id ).then( function ( run ) { showRun( run, b ); }, fail ); } }, 'Open' ) )
				] );
			} ) )
		] ) );
	}
	function drawHistory() {
		var p = panel( 'history' );
		p.innerHTML = '';
		p.appendChild( el( 'div', { class: 'pr-ds-skeleton pr-ml-skel' } ) );
		api( '/runs' ).then( function ( runs ) {
			p.innerHTML = '';
			p.appendChild( runs.length ? card( 'Change history', 'Every applied change keeps its previous values. Open a run to verify it again, finalize it or roll it back.', [ runsTable( runs ) ] ) : el( 'div', { class: 'pr-ds-empty' }, [ el( 'strong', { text: 'No changes yet' } ), 'Plans and applied changes appear here.' ] ) );
		}, fail );
	}

	/* ------------------------------------------------------------------ health */
	function drawHealth() {
		var p = panel( 'health' );
		p.innerHTML = '';
		var out = el( 'div', { id: 'pr-ml-health-out', 'aria-live': 'polite' } );
		var bar = el( 'div', { class: 'pr-ml-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': 0, 'aria-label': 'Scan progress', hidden: true }, el( 'span', { style: 'width:0%' } ) );
		var status = el( 'p', { class: 'pr-ds-help', role: 'status' } );
		var btn = el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', onclick: function () {
			btn.disabled = true; bar.hidden = false;
			var run = function ( phase, offset, reset ) {
				api( '/health', { method: 'POST', data: { phase: phase, offset: offset, reset: reset ? 1 : 0 } } ).then( function ( r ) {
					var pct = r.total ? Math.min( 100, Math.round( 100 * r.offset / r.total ) ) : 100;
					var overall = 'attachments' === r.phase ? pct / 2 : 50 + pct / 2;
					bar.setAttribute( 'aria-valuenow', Math.round( overall ) ); bar.firstChild.style.width = overall + '%';
					status.textContent = ( 'attachments' === r.phase ? 'Checking files… ' : 'Checking content for broken media URLs… ' ) + Math.min( r.offset, r.total ) + ' / ' + r.total;
					renderHealth( r.summary );
					if ( r.done ) { btn.disabled = false; status.textContent = 'Scan complete.'; return; }
					run( r.phase, r.offset, false );
				}, function ( e ) { btn.disabled = false; fail( e ); } );
			};
			run( 'attachments', 0, true );
		} }, 'Run health scan' );
		p.appendChild( card( 'Media health', 'Finds attachments whose files are missing, files uploaded more than once, and media URLs in your content that point to files that do not exist. Read-only: nothing is changed.', [ el( 'div', { class: 'pr-ds-btn-row' }, btn ), bar, status ] ) );
		p.appendChild( out );
		api( '/health' ).then( renderHealth, function () {} );
	}
	function renderHealth( h ) {
		var out = document.getElementById( 'pr-ml-health-out' );
		if ( ! out || ! h ) { return; }
		out.innerHTML = '';
		if ( ! h.started ) { out.appendChild( el( 'div', { class: 'pr-ds-empty' }, [ el( 'strong', { text: 'Not scanned yet' } ), 'Run the scan to check your media.' ] ) ); return; }
		out.appendChild( el( 'div', { class: 'pr-ml-stats' }, [ stat( h.missing.length, 'attachments with missing files', '', h.missing.length ? 'err' : '' ), stat( h.duplicates.length, 'groups of duplicate files', '', h.duplicates.length ? 'warn' : '' ), stat( h.broken.length, 'posts with broken media URLs', '', h.broken.length ? 'err' : '' ) ] ) );
		if ( h.finished ) { out.appendChild( el( 'p', { class: 'pr-ds-help', text: 'Last complete scan: ' + new Date( h.finished * 1000 ).toLocaleString() } ) ); }
		if ( h.broken.length ) {
			out.appendChild( card( 'Broken media URLs in content', 'These posts link to uploads files that are not on disk.', [ el( 'ul', { class: 'pr-ml-list' }, h.broken.map( function ( b ) { return el( 'li', {}, [ el( 'a', { href: b.edit, target: '_blank', rel: 'noopener' }, b.title || ( '#' + b.id ) ), ' ', badge( b.type ), el( 'div', {}, b.files.map( function ( f ) { return el( 'code', { class: 'pr-ml-url', text: f } ); } ) ) ] ); } ) ) ] ) );
		}
		if ( h.missing.length ) {
			out.appendChild( card( 'Missing files', 'The attachment exists but one or more of its files are not on disk.', [ el( 'ul', { class: 'pr-ml-list' }, h.missing.map( function ( m ) { return el( 'li', {}, [ el( 'button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm pr-ds-btn--ghost', onclick: function ( e ) { openItem( m.id, e.currentTarget ); } }, m.title || ( '#' + m.id ) ), el( 'div', {}, m.files.map( function ( f ) { return el( 'code', { class: 'pr-ml-url', text: f } ); } ) ) ] ); } ) ) ] ) );
		}
		if ( h.duplicates.length ) {
			out.appendChild( card( 'Duplicate files', 'Same size and same content. Keep one and point references to it with "Point references to another URL".', h.duplicates.map( function ( g ) { return el( 'div', { class: 'pr-ml-dupes' }, g.map( function ( d ) { return el( 'button', { type: 'button', class: 'pr-ml-dupe', onclick: function ( e ) { openItem( d.id, e.currentTarget ); } }, [ el( 'img', { src: d.thumb, alt: '', width: 48, height: 48, loading: 'lazy' } ), el( 'span', { text: ( d.title || d.file ) + ' · #' + d.id } ) ] ); } ) ); } ) ) );
		}
	}

	/* ------------------------------------------------------------------ host */
	function drawHost() {
		var p = panel( 'host' );
		p.innerHTML = '';
		p.appendChild( el( 'div', { class: 'pr-ds-skeleton pr-ml-skel' } ) );
		api( '/host' ).then( function ( h ) {
			S.host = h;
			p.innerHTML = '';
			var media = el( 'input', { id: 'pr-ml-mediahost', class: 'pr-ds-input', type: 'url', value: h.media_host, placeholder: 'https://images.example.com', disabled: ! h.can_save } );
			var cdn = el( 'input', { id: 'pr-ml-cdnhost', class: 'pr-ds-input', type: 'url', value: h.cdn_host, placeholder: 'https://cdn.example.com', disabled: ! h.can_save } );
			var cdnOn = el( 'input', { type: 'checkbox', id: 'pr-ml-cdnon', checked: h.cdn_enabled, disabled: ! h.can_save } );
			var content = el( 'input', { type: 'checkbox', id: 'pr-ml-content', checked: h.content, disabled: ! h.can_save } );
			var result = el( 'div', { 'aria-live': 'polite' } );
			var save = el( 'button', { type: 'submit', class: 'pr-ds-btn pr-ds-btn--primary', disabled: ! h.can_save }, 'Save host settings' );
			p.appendChild( card( 'Media host & CDN', 'Your uploads live at ' + h.uploads + '. These are the same Media host and CDN settings as in General Settings → Infrastructure; nothing in the database is rewritten, so you can switch back at any time.', [
				el( 'form', { onsubmit: function ( e ) { e.preventDefault(); save.disabled = true; api( '/host', { method: 'POST', data: { media_host: media.value, cdn_host: cdn.value, cdn_enabled: cdnOn.checked ? 1 : 0, content: content.checked ? 1 : 0 } } ).then( function () { save.disabled = false; toast( 'Host settings saved' ); }, function ( er ) { save.disabled = false; fail( er ); } ); } }, [
					el( 'div', { class: 'pr-ds-grid pr-ds-grid--2' }, [
						el( 'div', { class: 'pr-ds-field' }, [ el( 'label', { for: 'pr-ml-mediahost', text: 'Media host' } ), media, el( 'p', { class: 'pr-ds-help', text: 'Replaces the host of media URLs. The path stays the same, so the host must serve a copy of your uploads folder.' } ) ] ),
						el( 'div', { class: 'pr-ds-field' }, [ el( 'label', { for: 'pr-ml-cdnhost', text: 'CDN host' } ), cdn, el( 'label', { class: 'pr-ds-check' }, [ cdnOn, 'Use the CDN host' ] ) ] )
					] ),
					el( 'fieldset', { class: 'pr-ml-scope' }, [ el( 'legend', { class: 'pr-ds-label', text: 'Where these hosts are used' } ),
						el( 'p', { class: 'pr-ds-help', text: 'Always: Open Graph and X/Twitter images, and schema image URLs.' } ),
						el( 'label', { class: 'pr-ds-check' }, [ content, 'Also serve images in pages and posts (attachment URLs, srcset and uploads URLs in content) from this host' ] ) ] ),
					h.can_save ? null : el( 'p', { class: 'pr-ds-help', text: 'Changing these needs the "Manage general settings" permission.' } ),
					el( 'div', { class: 'pr-ds-btn-row' }, [ save, el( 'button', { type: 'button', class: 'pr-ds-btn', onclick: function () {
						result.innerHTML = ''; result.appendChild( el( 'p', { class: 'pr-ds-help' }, [ el( 'span', { class: 'pr-ds-spinner' } ), ' Testing a sample image…' ] ) );
						api( '/host/test', { method: 'POST' } ).then( function ( t ) { result.innerHTML = ''; result.appendChild( el( 'div', { class: 'pr-ds-notice pr-ds-notice--' + ( 'pass' === t.status ? 'ok' : ( 'fail' === t.status ? 'err' : 'warn' ) ) }, [ el( 'strong', { text: 'pass' === t.status ? 'The media host serves your files.' : ( 'fail' === t.status ? 'The media host did not serve the sample correctly.' : 'Could not confirm.' ) } ), el( 'br' ), t.detail || '', t.mapped && t.mapped !== t.local ? el( 'div', {}, [ el( 'code', { class: 'pr-ml-url', text: t.local } ), ' → ', el( 'code', { class: 'pr-ml-url', text: t.mapped } ) ] ) : null ] ) ); }, fail );
					} }, 'Test with a sample image (saved settings)' ) ] ),
					result
				] )
			] ) );
			if ( C.canSite ) { p.appendChild( rebaseCard( h ) ); }
		}, fail );
	}
	function rebaseCard( h ) {
		var oldB = el( 'input', { id: 'pr-ml-oldbase', class: 'pr-ds-input', type: 'url', placeholder: 'https://old.example.com/wp-content/uploads' } );
		var newB = el( 'input', { id: 'pr-ml-newbase', class: 'pr-ds-input', type: 'url', value: h.uploads } );
		var go = el( 'button', { type: 'submit', class: 'pr-ds-btn pr-ds-btn--primary' }, 'Preview changes (dry run)' );
		return card( 'Move stored media URLs to a new host', 'After a domain move or a switch away from an old media host, content can still contain the old uploads addresses. This rewrites only those addresses in stored content, fields and settings — with a preview, verification and rollback.', [
			el( 'form', { onsubmit: function ( e ) { e.preventDefault(); go.disabled = true; api( '/rebase/plan', { method: 'POST', data: { old_base: oldB.value, new_base: newB.value } } ).then( function ( r ) { go.disabled = false; S.item = null; showRun( r, go ); }, function ( er ) { go.disabled = false; fail( er ); } ); } }, [
				el( 'div', { class: 'pr-ds-grid pr-ds-grid--2' }, [
					el( 'div', { class: 'pr-ds-field' }, [ el( 'label', { for: 'pr-ml-oldbase', text: 'Old uploads address' } ), oldB, el( 'p', { class: 'pr-ds-help', text: 'Include the uploads path. A bare domain is refused because it would also change links that are not media.' } ) ] ),
					el( 'div', { class: 'pr-ds-field' }, [ el( 'label', { for: 'pr-ml-newbase', text: 'New uploads address' } ), newB ] )
				] ),
				el( 'div', { class: 'pr-ds-btn-row' }, go )
			] )
		] );
	}

	/* ------------------------------------------------------------------ boot */
	var start = ( location.hash || '' ).replace( '#', '' );
	selectTab( [ 'library', 'health', 'host', 'history' ].indexOf( start ) >= 0 ? start : 'library' );
	if ( +C.attachment ) { openItem( +C.attachment ); }
}() );
