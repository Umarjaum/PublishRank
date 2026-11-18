/**
 * PublishRank Source Directory — block editor panel.
 * Shows which links in the article match the newsroom's Source Directory, warns about sources marked
 * "Use with caution" or "Do not cite", flags links that are not in the directory, and suggests listed
 * sources. Nothing in the content changes unless the writer clicks "Insert link".
 */
( function ( wp, cfg ) {
	if ( ! wp || ! wp.plugins || ! cfg ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}
	var C = wp.components;

	function host( url ) {
		try {
			return new URL( url ).hostname.toLowerCase().replace( /^www\./, '' );
		} catch ( e ) {
			return '';
		}
	}
	function match( h, sources ) {
		var best = null;
		sources.forEach( function ( s ) {
			var d = s.domain;
			if ( h === d || h.slice( -( d.length + 1 ) ) === '.' + d ) {
				if ( ! best || d.length > best.domain.length ) {
					best = s;
				}
			}
		} );
		return best;
	}
	function linksIn( html ) {
		var out = [];
		var doc = new window.DOMParser().parseFromString( '<div>' + ( html || '' ) + '</div>', 'text/html' );
		doc.querySelectorAll( 'a[href^="http"]' ).forEach( function ( a ) {
			var h = host( a.getAttribute( 'href' ) );
			if ( ! h || h === cfg.home || h.slice( -( cfg.home.length + 1 ) ) === '.' + cfg.home ) {
				return;
			}
			out.push( { url: a.getAttribute( 'href' ), host: h, text: ( a.textContent || '' ).trim() } );
		} );
		return out.slice( 0, 300 );
	}
	function words( s ) {
		return ( s || '' ).toLowerCase().split( /[^a-z0-9\u00c0-\uffff]+/ ).filter( function ( w ) {
			return w.length > 3;
		} );
	}

	function SourcesPanel() {
		var state = useState( { loading: true, error: '', sources: [], classes: {} } );
		var data = state[ 0 ];
		var setData = state[ 1 ];
		var q = useState( '' );
		var notice = useState( '' );
		var content = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostContent();
		}, [] );
		var title = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
		}, [] );

		useEffect( function () {
			wp.apiFetch( { path: cfg.path } ).then( function ( r ) {
				setData( { loading: false, error: '', sources: r.sources || [], classes: r.classes || {} } );
			} ).catch( function () {
				setData( { loading: false, error: __( 'The Source Directory could not be loaded.', 'publishrank' ), sources: [], classes: {} } );
			} );
		}, [] );

		var review = useMemo( function () {
			var seen = {};
			return linksIn( content ).filter( function ( l ) {
				if ( seen[ l.host ] ) {
					return false;
				}
				seen[ l.host ] = true;
				return true;
			} ).map( function ( l ) {
				var s = match( l.host, data.sources );
				return { link: l, source: s, status: ! s ? 'unknown' : ( s['class'] === 'avoid' || s['class'] === 'caution' ? s['class'] : 'listed' ) };
			} );
		}, [ content, data.sources ] );

		var suggestions = useMemo( function () {
			var needle = q[ 0 ].trim().toLowerCase();
			var topic = words( title );
			return data.sources.filter( function ( s ) {
				if ( s['class'] === 'avoid' ) {
					return false;
				}
				if ( ! needle ) {
					return true;
				}
				return ( s.name + ' ' + s.domain + ' ' + s.description + ' ' + ( s.categories || [] ).join( ' ' ) + ' ' + s.class_label ).toLowerCase().indexOf( needle ) !== -1;
			} ).map( function ( s ) {
				var hay = words( s.name + ' ' + s.description + ' ' + ( s.categories || [] ).join( ' ' ) );
				var overlap = topic.filter( function ( w ) {
					return hay.indexOf( w ) !== -1;
				} ).length;
				return { s: s, score: overlap * 100 + s.priority };
			} ).sort( function ( a, b ) {
				return b.score - a.score;
			} ).slice( 0, needle ? 12 : 5 );
		}, [ q[ 0 ], title, data.sources ] );

		function insert( s ) {
			var block = wp.blocks.createBlock( 'core/paragraph', { content: '<a href="' + encodeURI( s.url ) + '">' + s.name.replace( /[<>&"]/g, '' ) + '</a>' } );
			var sel = wp.data.select( 'core/block-editor' );
			var id = sel.getSelectedBlockClientId();
			var index = id ? sel.getBlockIndex( id ) + 1 : undefined;
			var root = id ? sel.getBlockRootClientId( id ) : undefined;
			wp.data.dispatch( 'core/block-editor' ).insertBlocks( block, index, root, false );
			notice[ 1 ]( sprintf( __( 'Added a paragraph linking to %s below the selected block. Edit or move it as needed.', 'publishrank' ), s.name ) );
		}
		function copy( s ) {
			if ( window.navigator.clipboard ) {
				window.navigator.clipboard.writeText( s.url ).then( function () {
					notice[ 1 ]( sprintf( __( 'Copied the address of %s.', 'publishrank' ), s.name ) );
				} );
			}
		}

		var counts = { listed: 0, unknown: 0, caution: 0, avoid: 0 };
		review.forEach( function ( r ) {
			counts[ r.status ]++;
		} );

		var label = { listed: __( 'In your directory', 'publishrank' ), unknown: __( 'Not in your directory', 'publishrank' ), caution: __( 'Use with caution', 'publishrank' ), avoid: __( 'Do not cite', 'publishrank' ) };
		var tone = { listed: 'is-ok', unknown: 'is-unknown', caution: 'is-warn', avoid: 'is-bad' };

		return el( Panel, { name: 'publishrank-sources', title: __( 'Sources', 'publishrank' ), className: 'publishrank-sources-panel', icon: 'book-alt' },
			data.loading ? el( C.Spinner ) : null,
			data.error ? el( C.Notice, { status: 'error', isDismissible: false }, data.error ) : null,
			! data.loading && ! data.error ? el( 'div', null,
				el( 'p', { className: 'prsrc-summary' }, review.length
					? sprintf( __( '%1$d linked websites: %2$d in your directory, %3$d not listed, %4$d flagged.', 'publishrank' ), review.length, counts.listed, counts.unknown, counts.caution + counts.avoid )
					: __( 'No links to other websites yet.', 'publishrank' ) ),
				counts.avoid ? el( C.Notice, { status: 'error', isDismissible: false }, __( 'This article links to a source your newsroom marked "Do not cite". Nothing has been changed — review the link before publishing.', 'publishrank' ) ) : null,
				counts.caution ? el( C.Notice, { status: 'warning', isDismissible: false }, __( 'This article links to a source marked "Use with caution". Consider adding corroboration.', 'publishrank' ) ) : null,
				review.length ? el( 'ul', { className: 'prsrc-list' }, review.map( function ( r ) {
					return el( 'li', { key: r.link.host, className: 'prsrc-item ' + tone[ r.status ] },
						el( 'strong', null, r.source ? r.source.name : r.link.host ),
						el( 'span', { className: 'prsrc-badge' }, r.status === 'listed' && r.source && r.source.class_label ? r.source.class_label : label[ r.status ] )
					);
				} ) ) : null,
				el( 'hr' ),
				el( C.TextControl, { label: __( 'Find a source', 'publishrank' ), value: q[ 0 ], onChange: q[ 1 ], placeholder: __( 'Name, domain or topic', 'publishrank' ), __nextHasNoMarginBottom: true } ),
				el( 'p', { className: 'prsrc-hint' }, q[ 0 ] ? __( 'Matching sources:', 'publishrank' ) : __( 'Suggested for this article (topic match, then priority):', 'publishrank' ) ),
				data.sources.length ? null : el( 'p', null, __( 'Your Source Directory is empty.', 'publishrank' ) ),
				el( 'ul', { className: 'prsrc-list' }, suggestions.map( function ( x ) {
					return el( 'li', { key: x.s.id, className: 'prsrc-item' },
						el( 'strong', null, x.s.name ),
						el( 'span', { className: 'prsrc-badge' }, x.s.class_label || x.s.domain ),
						x.s['class'] === 'caution' ? el( 'span', { className: 'prsrc-warn' }, __( 'Use with caution', 'publishrank' ) ) : null,
						el( 'span', { className: 'prsrc-actions' },
							el( C.Button, { variant: 'secondary', size: 'small', onClick: function () { insert( x.s ); } }, __( 'Insert link', 'publishrank' ) ),
							el( C.Button, { variant: 'tertiary', size: 'small', onClick: function () { copy( x.s ); } }, __( 'Copy address', 'publishrank' ) )
						)
					);
				} ) ),
				notice[ 0 ] ? el( C.Notice, { status: 'success', onRemove: function () { notice[ 1 ]( '' ); } }, notice[ 0 ] ) : null,
				cfg.manageUrl ? el( 'p', null, el( 'a', { href: cfg.manageUrl }, __( 'Manage the Source Directory', 'publishrank' ) ) ) : null,
				el( 'p', { className: 'prsrc-hint' }, __( 'Classifications are your newsroom\'s own labels, not a verification.', 'publishrank' ) )
			) : null
		);
	}

	wp.plugins.registerPlugin( 'publishrank-sources', { render: SourcesPanel, icon: 'book-alt' } );

	var css = '.publishrank-sources-panel .prsrc-list{margin:8px 0;padding:0;list-style:none}.publishrank-sources-panel .prsrc-item{display:flex;flex-wrap:wrap;gap:4px 8px;align-items:center;padding:8px 0;border-bottom:1px solid #f0f0f0}.publishrank-sources-panel .prsrc-item strong{flex:1 1 60%;overflow-wrap:anywhere}.publishrank-sources-panel .prsrc-badge{font-size:11px;padding:2px 8px;border-radius:999px;background:#f0f0f1;color:#1d2327}.publishrank-sources-panel .is-ok .prsrc-badge{background:#e6f4ea;color:#0b5b2b}.publishrank-sources-panel .is-unknown .prsrc-badge{background:#fcf3dc;color:#6b4e00}.publishrank-sources-panel .is-warn .prsrc-badge{background:#fff1e0;color:#8a4600}.publishrank-sources-panel .is-bad .prsrc-badge{background:#fde8e8;color:#8a1f1f}.publishrank-sources-panel .prsrc-warn{font-size:11px;color:#8a4600}.publishrank-sources-panel .prsrc-actions{display:flex;gap:4px;width:100%}.publishrank-sources-panel .prsrc-hint{color:#50575e;font-size:12px}';
	var tag = document.createElement( 'style' );
	tag.textContent = css;
	document.head.appendChild( tag );
}( window.wp, window.publishrankSources ) );
