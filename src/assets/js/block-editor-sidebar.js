/**
 * PublishRank sidebar for the block editor.
 *
 * Edits the SEO title and meta description (registered post meta) with live
 * character counts, and gives one-click access to the floating SEO Analysis
 * card and the internal-link inserter. No build step: plain wp.element calls.
 */
(function (wp) {
	if (!wp || !wp.plugins || !wp.element || !wp.data || !wp.components) { return; }
	var el = wp.element.createElement;
	var __ = (wp.i18n && wp.i18n.__) || function (s) { return s; };
	var editorPkg = wp.editor && wp.editor.PluginSidebar ? wp.editor : wp.editPost;
	if (!editorPkg || !editorPkg.PluginSidebar) { return; }
	var PluginSidebar = editorPkg.PluginSidebar;
	var PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem;
	var C = wp.components;
	var TITLE = '_publishrank_seo_title';
	var DESC = '_publishrank_seo_description';
	var FOCUS = '_publishrank_seo_focus_keyword';
	var SECONDARY = '_publishrank_seo_secondary_keyphrases';
	var SYNONYMS = '_publishrank_seo_keyphrase_synonyms';
	var RELATED = '_publishrank_seo_related_terms';

	function Counter(props) {
		var n = (props.value || '').length;
		var state = n > props.max ? 'over' : (n < props.min ? 'short' : 'ok');
		var color = state === 'over' ? '#d63638' : (state === 'short' ? '#996800' : '#00a32a');
		return el('p', { style: { margin: '-4px 0 12px', fontSize: '12px', color: color } },
			n + ' / ' + props.max + ' ' + __('characters', 'publishrank') + (state === 'over' ? ' — ' + __('too long', 'publishrank') : (state === 'short' ? ' — ' + __('a little short', 'publishrank') : '')));
	}

	/* One item per line. Local state keeps the textarea natural to type in (Enter, trailing spaces) while the saved meta stays a clean list. */
	function ListField(props) {
		var initial = Array.isArray(props.value) ? props.value.join('\n') : '';
		var state = wp.element.useState(initial);
		var text = state[0], setText = state[1];
		return el(C.TextareaControl, {
			label: props.label, help: props.help, value: text, rows: 3, __nextHasNoMarginBottom: true,
			onChange: function (v) {
				setText(v);
				props.onList(v.split(/[\r\n,;\u060C\u061B]+/).map(function (x) { return x.trim(); }).filter(Boolean).slice(0, props.max));
			}
		});
	}

	function openCard() {
		var card = document.getElementById('publishrank-editor-score');
		if (!card) { return; }
		var expand = card.querySelector('[data-pr-esc-expand]');
		if (card.classList.contains('is-minimized') && expand) { expand.click(); }
		card.scrollIntoView({ block: 'nearest' });
	}
	function addLinks() {
		var btn = document.querySelector('[data-pr-esc-links]');
		if (btn) { btn.click(); }
	}

	function Panel() {
		var meta = wp.data.useSelect(function (select) { return select('core/editor').getEditedPostAttribute('meta') || {}; }, []);
		var postTitle = wp.data.useSelect(function (select) { return select('core/editor').getEditedPostAttribute('title') || ''; }, []);
		var editPost = wp.data.useDispatch('core/editor').editPost;
		function set(key) { return function (v) { var m = {}; m[key] = v; editPost({ meta: m }); }; }
		var hasTitle = Object.prototype.hasOwnProperty.call(meta, TITLE);
		var hasDesc = Object.prototype.hasOwnProperty.call(meta, DESC);
		var hasFocus = Object.prototype.hasOwnProperty.call(meta, FOCUS);
		return el(C.PanelBody, { title: __('Search appearance', 'publishrank'), initialOpen: true },
			hasFocus ? el(C.TextControl, { label: __('Focus keyword', 'publishrank'), help: __('The main phrase this article should rank for.', 'publishrank'), value: meta[FOCUS] || '', maxLength: 100, onChange: set(FOCUS), __nextHasNoMarginBottom: true }) : null,
			Object.prototype.hasOwnProperty.call(meta, SECONDARY) ? el(ListField, { label: __('Secondary keyphrases', 'publishrank'), help: __('One per line, up to 10. Each gets its own score; the focus keyword stays the main target.', 'publishrank'), value: meta[SECONDARY], max: 10, onList: set(SECONDARY) }) : null,
			Object.prototype.hasOwnProperty.call(meta, SYNONYMS) ? el(ListField, { label: __('Synonyms for the focus keyword', 'publishrank'), help: __('Alternative wordings that count as the focus keyword. One per line.', 'publishrank'), value: meta[SYNONYMS], max: 10, onList: set(SYNONYMS) }) : null,
			Object.prototype.hasOwnProperty.call(meta, RELATED) ? el(ListField, { label: __('Related keyphrases', 'publishrank'), help: __('Supporting terms a thorough article would mention. Checked for coverage, not scored as targets.', 'publishrank'), value: meta[RELATED], max: 20, onList: set(RELATED) }) : null,
			hasTitle ? el(C.TextControl, { label: __('SEO title', 'publishrank'), value: meta[TITLE] || '', placeholder: postTitle, onChange: set(TITLE), __nextHasNoMarginBottom: true }) : null,
			hasTitle ? el(Counter, { value: meta[TITLE] || postTitle, min: 30, max: 60 }) : null,
			hasDesc ? el(C.TextareaControl, { label: __('Meta description', 'publishrank'), value: meta[DESC] || '', onChange: set(DESC), rows: 4, __nextHasNoMarginBottom: true }) : null,
			hasDesc ? el(Counter, { value: meta[DESC] || '', min: 120, max: 160 }) : null,
			el('div', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
				el(C.Button, { variant: 'secondary', onClick: openCard }, __('Show SEO analysis', 'publishrank')),
				el(C.Button, { variant: 'primary', onClick: addLinks }, __('Add internal links', 'publishrank'))
			),
			el('p', { style: { fontSize: '12px', color: '#50575e', marginTop: '12px' } }, __('Schema, social and news fields stay in the PublishRank boxes below the editor.', 'publishrank'))
		);
	}

	/* The meta box note under the editor has a button that opens this sidebar. */
	document.addEventListener('click', function (e) {
		var t = e.target;
		if (!t || !t.closest || !t.closest('#pr-open-seo-sidebar')) { return; }
		var id = 'publishrank-seo/publishrank-seo-sidebar';
		try {
			var iface = wp.data.dispatch('core/interface');
			if (iface && iface.enableComplementaryArea) { iface.enableComplementaryArea('core/edit-post', id); return; }
			var ep = wp.data.dispatch('core/edit-post');
			if (ep && ep.openGeneralSidebar) { ep.openGeneralSidebar(id); }
		} catch (err) { /* The sidebar can still be opened from the editor's top bar. */ }
	});

	wp.plugins.registerPlugin('publishrank-seo', {
		icon: 'chart-line',
		render: function () {
			return el(wp.element.Fragment, null,
				PluginSidebarMoreMenuItem ? el(PluginSidebarMoreMenuItem, { target: 'publishrank-seo-sidebar' }, 'PublishRank SEO') : null,
				el(PluginSidebar, { name: 'publishrank-seo-sidebar', title: 'PublishRank SEO', icon: 'chart-line' }, el(Panel))
			);
		}
	});
})(window.wp);
