/* PublishRank Breadcrumbs block: live preview in the editor, server-rendered on the front end. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components || ! wp.data ) { return; }

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
	var be = wp.blockEditor;
	var cmp = wp.components;
	var cfg = window.publishrankBreadcrumbsBlock || {};
	var defaults = cfg.defaults || {};
	var siteColors = defaults.colors || {};
	var DIRECTIONAL = /[→➜➝➞➡➤⇒⇢⇨⟶›»≫❯❱⟩⟫〉>]/;
	/* Separators of the reference design: Minimal uses a double angle, Pills a single angle. */
	var LOOK_SEPARATORS = { minimal: '»', pills: '›' };
	var QUICK_SEPARATORS = [ '→', '›', '»', '/', '|', '·', '—' ];
	var LOOKS = cfg.looks && cfg.looks.length ? cfg.looks : [
		{ value: 'arrows', label: 'Arrows', short: 'Arrows' },
		{ value: 'minimal', label: 'Minimal', short: 'Minimal' },
		{ value: 'steps', label: 'Steps', short: 'Steps' },
		{ value: 'pills', label: 'Pills', short: 'Pills' },
		{ value: 'plain', label: 'Plain', short: 'Plain' },
		{ value: 'chips', label: 'Chips', short: 'Chips' },
		{ value: 'bar', label: 'Bar', short: 'Bar' },
		{ value: 'underline', label: 'Underline', short: 'Underline' }
	];
	var COLOR_KEYS = [
		{ attr: 'colorText', key: 'text', label: __( 'Text', 'publishrank' ) },
		{ attr: 'colorLink', key: 'link', label: __( 'Links', 'publishrank' ) },
		{ attr: 'colorBg', key: 'bg', label: __( 'Background', 'publishrank' ) },
		{ attr: 'colorAccent', key: 'accent', label: __( 'Accent (current page, steps, hover)', 'publishrank' ) }
	];

	function stop( event ) { if ( event && event.preventDefault ) { event.preventDefault(); } }

	/* Builds the same markup as BreadcrumbEngine::render(), so the preview matches the front end. */
	function trail( items, attrs, overrideLook ) {
		var look = overrideLook || attrs.look || defaults.look || 'plain';
		var size = attrs.size || defaults.size || 'medium';
		var align = attrs.position || defaults.align || 'left';
		var sep = ( typeof attrs.separator === 'string' && attrs.separator !== '' ) ? attrs.separator : ( typeof defaults.separator === 'string' ? defaults.separator : '→' );
		var vars = {};
		var offset = overrideLook ? 0 : ( typeof attrs.offsetX === 'number' ? attrs.offsetX : ( defaults.offset || 0 ) );
		var icons = ! overrideLook && ( typeof attrs.icons === 'boolean' ? attrs.icons : !! defaults.icons ) && look !== 'steps';
		if ( offset ) { vars[ '--pr-bc-x' ] = offset + 'px'; }
		if ( ! overrideLook ) {
			COLOR_KEYS.forEach( function ( c ) {
				var value = attrs[ c.attr ] || siteColors[ c.key ];
				if ( value ) { vars[ '--pr-bc-' + c.key ] = value; }
			} );
		}
		var last = items.length - 1;
		var sepClass = 'publishrank-breadcrumb-separator' + ( DIRECTIONAL.test( sep ) ? ' publishrank-breadcrumb-separator--directional' : '' );
		var lis = items.map( function ( name, i ) {
			var isLast = i === last;
			var kids = [ isLast ?
				el( 'span', { key: 't', 'aria-current': 'page' }, name ) :
				el( 'a', { key: 't', href: '#', onClick: stop }, name ) ];
			if ( ! isLast && sep !== '' ) {
				kids.push( el( 'span', { key: 's', className: sepClass, 'aria-hidden': 'true' }, sep ) );
			}
			return el( 'li', {
				key: i,
				className: 'publishrank-breadcrumbs__item ' + ( isLast ? 'is-current' : 'is-done' ),
				'data-step': String( i + 1 )
			}, kids );
		} );
		return el( 'nav', {
			className: 'publishrank-breadcrumbs publishrank-breadcrumbs--' + look + ' publishrank-breadcrumbs--size-' + size + ' publishrank-breadcrumbs--align-' + align + ( offset ? ' publishrank-breadcrumbs--offset' : '' ) + ( icons ? ' publishrank-breadcrumbs--icons' : '' ),
			style: vars,
			'aria-label': __( 'Breadcrumbs', 'publishrank' )
		}, el( 'ol', { className: 'publishrank-breadcrumbs__list' }, lis ) );
	}

	/* The trail of the post being edited: Home, its first category, its title. Falls back to sample text. */
	function usePreviewItems() {
		return wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var title = '';
			var catName = '';
			var type = '';
			if ( editor ) {
				title = editor.getEditedPostAttribute ? ( editor.getEditedPostAttribute( 'title' ) || '' ) : '';
				type = editor.getCurrentPostType ? ( editor.getCurrentPostType() || '' ) : '';
				var cats = editor.getEditedPostAttribute ? editor.getEditedPostAttribute( 'categories' ) : null;
				if ( cats && cats.length ) {
					var core = select( 'core' );
					var rec = core && core.getEntityRecord ? core.getEntityRecord( 'taxonomy', 'category', cats[ 0 ] ) : null;
					catName = rec && rec.name ? rec.name : '';
				}
			}
			var home = defaults.home || __( 'Home', 'publishrank' );
			var middle = catName || ( type === 'page' ? __( 'Parent page', 'publishrank' ) : __( 'Category', 'publishrank' ) );
			return [ home, middle, title || __( 'This page', 'publishrank' ) ];
		}, [] );
	}

	function LookPicker( props ) {
		var sample = [ 'Home', 'News', 'Post' ];
		var buttons = [ el( 'button', {
			key: 'site',
			type: 'button',
			className: 'publishrank-bc-look publishrank-bc-look--site' + ( ! props.value ? ' is-active' : '' ),
			'aria-pressed': ! props.value,
			onClick: function () { props.onChange( undefined ); }
		}, el( 'span', { className: 'publishrank-bc-look__label' }, __( 'Use the site default look (PublishRank → Breadcrumbs)', 'publishrank' ) ) ) ];
		LOOKS.forEach( function ( look ) {
			buttons.push( el( 'button', {
				key: look.value,
				type: 'button',
				title: look.label,
				className: 'publishrank-bc-look' + ( props.value === look.value ? ' is-active' : '' ),
				'aria-pressed': props.value === look.value,
				onClick: function () { props.onChange( look.value ); }
			},
			el( 'span', { className: 'publishrank-bc-look__preview', 'aria-hidden': 'true' }, trail( sample, {}, look.value ) ),
			el( 'span', { className: 'publishrank-bc-look__label' }, look.short || look.label ) ) );
		} );
		return el( 'div', { className: 'publishrank-bc-looks', role: 'group', 'aria-label': __( 'Breadcrumb look', 'publishrank' ) }, buttons );
	}

	function colorPanel( attrs, setAttributes ) {
		var settings = COLOR_KEYS.map( function ( c ) {
			return {
				label: c.label,
				value: attrs[ c.attr ],
				onChange: function ( value ) {
					var patch = {};
					patch[ c.attr ] = value ? value : undefined;
					setAttributes( patch );
				}
			};
		} );
		if ( be.PanelColorSettings ) {
			return el( be.PanelColorSettings, {
				title: __( 'Colors', 'publishrank' ),
				initialOpen: true,
				enableAlpha: false,
				colorSettings: settings
			} );
		}
		// Fallback for editors without PanelColorSettings: one palette per color.
		return el( cmp.PanelBody, { title: __( 'Colors', 'publishrank' ), initialOpen: true },
			settings.map( function ( s ) {
				return el( 'div', { key: s.label, style: { marginBottom: '12px' } },
					el( 'p', { style: { margin: '0 0 6px', fontWeight: 600 } }, s.label ),
					el( cmp.ColorPalette, { value: s.value, onChange: s.onChange, enableAlpha: false } ) );
			} ) );
	}

	/* Picking Minimal or Pills switches to that design's separator, unless the publisher typed their own. */
	function nextSeparator( current, look ) {
		var presets = Object.keys( LOOK_SEPARATORS ).map( function ( k ) { return LOOK_SEPARATORS[ k ]; } );
		if ( current && presets.indexOf( current ) === -1 ) { return current; }
		return LOOK_SEPARATORS[ look ] || undefined;
	}

	function Edit( props ) {
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps = be.useBlockProps( { className: 'publishrank-bc-editor' } );
		var items = usePreviewItems();
		var hasOverride = !! ( attrs.look || attrs.size || attrs.position || typeof attrs.offsetX === 'number' || typeof attrs.icons === 'boolean' || attrs.separator || attrs.colorText || attrs.colorLink || attrs.colorBg || attrs.colorAccent );
		var sepValue = typeof attrs.separator === 'string' ? attrs.separator : '';

		function reset() {
			setAttributes( { look: undefined, size: undefined, position: undefined, offsetX: undefined, icons: undefined, separator: undefined, colorText: undefined, colorLink: undefined, colorBg: undefined, colorAccent: undefined } );
		}

		var quick = QUICK_SEPARATORS.map( function ( s ) {
			return el( cmp.Button, {
				key: s,
				variant: sepValue === s ? 'primary' : 'secondary',
				size: 'small',
				onClick: function () { setAttributes( { separator: s } ); }
			}, s );
		} );

		var inspector = el( be.InspectorControls, null,
			cfg.visible === false ? el( cmp.PanelBody, { title: __( 'Heads up', 'publishrank' ), initialOpen: true },
				el( cmp.Notice, { status: 'warning', isDismissible: false },
					__( 'Visible breadcrumbs are switched off in PublishRank → Breadcrumbs, so this block prints nothing on the live site until you switch them on.', 'publishrank' ) ) ) : null,
			el( cmp.PanelBody, { title: __( 'Look', 'publishrank' ), initialOpen: true },
				el( LookPicker, { value: attrs.look, onChange: function ( look ) { setAttributes( { look: look, separator: nextSeparator( attrs.separator, look ) } ); } } ),
					el( cmp.ToggleControl, {
						label: __( 'Show icons (home, folder, page)', 'publishrank' ),
						checked: typeof attrs.icons === 'boolean' ? attrs.icons : !! defaults.icons,
						onChange: function ( value ) { setAttributes( { icons: !! value } ); }
					} ) ),
			colorPanel( attrs, setAttributes ),
			el( cmp.PanelBody, { title: __( 'Layout', 'publishrank' ), initialOpen: false },
				el( cmp.SelectControl, {
					label: __( 'Size', 'publishrank' ),
					value: attrs.size || '',
					options: [
						{ value: '', label: __( 'Site default', 'publishrank' ) },
						{ value: 'small', label: __( 'Small', 'publishrank' ) },
						{ value: 'medium', label: __( 'Medium', 'publishrank' ) },
						{ value: 'large', label: __( 'Large', 'publishrank' ) }
					],
					onChange: function ( value ) { setAttributes( { size: value || undefined } ); }
				} ),
				el( cmp.SelectControl, {
					label: __( 'Position', 'publishrank' ),
					value: attrs.position || '',
					options: [
						{ value: '', label: __( 'Site default', 'publishrank' ) },
						{ value: 'left', label: __( 'Left', 'publishrank' ) },
						{ value: 'center', label: __( 'Center', 'publishrank' ) },
						{ value: 'right', label: __( 'Right', 'publishrank' ) }
					],
					onChange: function ( value ) { setAttributes( { position: value || undefined } ); }
				} ),
				el( cmp.RangeControl, {
					label: __( 'Horizontal position (px)', 'publishrank' ),
					help: __( 'Moves the trail from left to right. Negative values move it left, positive values move it right. Ignored on small phones.', 'publishrank' ),
					value: typeof attrs.offsetX === 'number' ? attrs.offsetX : ( defaults.offset || 0 ),
					min: -400,
					max: 400,
					step: 1,
					allowReset: true,
					resetFallbackValue: 0,
					withInputField: true,
					onChange: function ( value ) { setAttributes( { offsetX: typeof value === 'number' ? value : undefined } ); }
				} ),
				el( 'p', { style: { margin: '0 0 6px', fontWeight: 600 } }, __( 'Separator', 'publishrank' ) ),
				el( 'div', { className: 'publishrank-bc-quick-seps' }, quick ),
				el( cmp.TextControl, {
					label: __( 'Custom separator (up to 10 characters)', 'publishrank' ),
					help: __( 'Leave empty to use the site default separator.', 'publishrank' ),
					value: sepValue,
					maxLength: 10,
					onChange: function ( value ) { setAttributes( { separator: value ? value : undefined } ); }
				} ) ),
			el( cmp.PanelBody, { title: __( 'Reset', 'publishrank' ), initialOpen: false },
				el( cmp.Button, { variant: 'secondary', disabled: ! hasOverride, onClick: reset }, __( 'Back to site defaults', 'publishrank' ) ) )
		);

		var toolbar = be.BlockControls && be.AlignmentControl ? el( be.BlockControls, { group: 'block' },
			el( be.AlignmentControl, {
				value: attrs.position || defaults.align || 'left',
				onChange: function ( value ) { setAttributes( { position: value || undefined } ); }
			} ) ) : null;

		return el( Fragment, null, toolbar, inspector,
			el( 'div', blockProps,
				trail( items, attrs ),
				el( 'small', { className: 'publishrank-bc-editor__note' }, __( 'Live preview — the real trail is generated on the published page.', 'publishrank' ) ) ) );
	}

	wp.blocks.registerBlockType( 'publishrank/breadcrumbs', {
		apiVersion: 2,
		title: __( 'PublishRank Breadcrumbs', 'publishrank' ),
		description: __( 'Shows the breadcrumb trail for this page with your choice of look and colors. It uses the same trail as the BreadcrumbList structured data.', 'publishrank' ),
		category: 'widgets',
		icon: 'arrow-right-alt',
		keywords: [ 'breadcrumb', 'navigation', 'seo', 'trail', 'path' ],
		supports: { html: false, multiple: false },
		attributes: {
			look: { type: 'string' },
			size: { type: 'string' },
			position: { type: 'string' },
			separator: { type: 'string' },
			offsetX: { type: 'number' },
			icons: { type: 'boolean' },
			colorText: { type: 'string' },
			colorLink: { type: 'string' },
			colorBg: { type: 'string' },
			colorAccent: { type: 'string' }
		},
		example: { attributes: { look: 'arrows' } },
		edit: Edit,
		save: function () { return null; }
	} );
}( window.wp ) );
