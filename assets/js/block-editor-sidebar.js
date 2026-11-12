/**
 * PublishRank sidebar for the block editor.
 *
 * - A live SEO score badge sits in the editor's top toolbar (the pinned sidebar icon). It recolors and
 *   updates as you write, so the score is always visible without covering the content.
 * - Clicking it opens the PublishRank SEO sidebar: the full SEO Analysis panel is docked at the top,
 *   followed by the SEO title and meta description fields with live character counts.
 * - The analysis can be popped out into a floating panel and docked back (remembered per browser).
 * - Also reachable from the editor's Options menu and the command palette (Ctrl/Cmd + K).
 * No build step: plain wp.element calls.
 */
(function (wp) {
	if (!wp || !wp.plugins || !wp.element || !wp.data || !wp.components) { return; }
	var el = wp.element.createElement;
	var useState = wp.element.useState, useEffect = wp.element.useEffect;
	var useLayoutEffect = wp.element.useLayoutEffect || wp.element.useEffect, useRef = wp.element.useRef;
	var __ = (wp.i18n && wp.i18n.__) || function (s) { return s; };
	var editorPkg = wp.editor && wp.editor.PluginSidebar ? wp.editor : wp.editPost;
	if (!editorPkg || !editorPkg.PluginSidebar) { return; }
	var PluginSidebar = editorPkg.PluginSidebar;
	var PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem;
	var C = wp.components;
	var PLUGIN = 'publishrank-seo', SIDEBAR = 'publishrank-seo-sidebar', FULL = PLUGIN + '/' + SIDEBAR;
	var SEEN = 'publishrank-seo-sidebar-introduced';
	var TITLE = '_publishrank_seo_title';
	var DESC = '_publishrank_seo_description';

	function card() { return window.PublishRankScoreCard || null; }
	function band(n) { return n >= 90 ? 'excellent' : (n >= 80 ? 'good' : (n >= 55 ? 'ok' : 'bad')); }

	function openSidebar() {
		try { var ep = wp.data.dispatch('core/edit-post'); if (ep && ep.openGeneralSidebar) { ep.openGeneralSidebar(FULL); return; } } catch (e) {}
		try { wp.data.dispatch('core/interface').enableComplementaryArea('core', FULL); } catch (e) {}
	}

	function useScore() {
		var initial = card() ? card().score() : { score: 0, band: 'bad', label: '' };
		var st = useState(initial);
		useEffect(function () {
			function on(e) { if (e && e.detail) { st[1](e.detail); } }
			document.addEventListener('publishrank:score', on);
			if (card()) { st[1](card().score()); }
			return function () { document.removeEventListener('publishrank:score', on); };
		}, []);
		return st[0];
	}
	function useMode() {
		var st = useState(card() ? card().mode() : 'docked');
		useEffect(function () {
			function on(e) { if (e && e.detail) { st[1](e.detail.mode); } }
			document.addEventListener('publishrank:mode', on);
			return function () { document.removeEventListener('publishrank:mode', on); };
		}, []);
		return st[0];
	}

	/* Live score ring used as the toolbar icon. */
	function Badge() {
		var s = useScore(), n = Math.max(0, Math.min(100, parseInt(s.score, 10) || 0));
		return el('span', { className: 'pr-esc-badge is-' + (s.band || band(n)), style: { '--pr-esc-score': String(n) }, 'aria-hidden': 'true' },
			el('b', null, String(n)));
	}

	function Counter(props) {
		var n = (props.value || '').length;
		var state = n > props.max ? 'over' : (n < props.min ? 'short' : 'ok');
		var color = state === 'over' ? '#d63638' : (state === 'short' ? '#996800' : '#00a32a');
		return el('p', { style: { margin: '-4px 0 12px', fontSize: '12px', color: color } },
			n + ' / ' + props.max + ' ' + __('characters', 'publishrank') + (state === 'over' ? ' — ' + __('too long', 'publishrank') : (state === 'short' ? ' — ' + __('a little short', 'publishrank') : '')));
	}

	/* The PHP-rendered SEO Analysis panel is moved into this slot while the sidebar is open. */
	function AnalysisDock() {
		var ref = useRef(null), mode = useMode();
		useLayoutEffect(function () {
			var api = card(), slot = ref.current;
			if (!api || !slot || mode !== 'docked') { return; }
			api.dock(slot);
			return function () { api.release(slot); };
		}, [mode]);
		if (!card()) {
			return el('p', { className: 'pr-esc-dock-note' }, __('The SEO analysis is not available for this post type.', 'publishrank'));
		}
		if (mode === 'floating') {
			return el('div', { className: 'pr-esc-dock-note' },
				el('p', null, __('The SEO analysis is popped out as a floating panel.', 'publishrank')),
				el(C.Button, { variant: 'secondary', onClick: function () { card().setMode('docked'); } }, __('Dock it here', 'publishrank')));
		}
		return el('div', { ref: ref, className: 'pr-esc-dock pr-esc-dock--sidebar' });
	}

	function SearchAppearance() {
		var meta = wp.data.useSelect(function (select) { return select('core/editor').getEditedPostAttribute('meta') || {}; }, []);
		var postTitle = wp.data.useSelect(function (select) { return select('core/editor').getEditedPostAttribute('title') || ''; }, []);
		var editPost = wp.data.useDispatch('core/editor').editPost;
		function set(key) { return function (v) { var m = {}; m[key] = v; editPost({ meta: m }); }; }
		var hasTitle = Object.prototype.hasOwnProperty.call(meta, TITLE);
		var hasDesc = Object.prototype.hasOwnProperty.call(meta, DESC);
		if (!hasTitle && !hasDesc) { return null; }
		return el(C.PanelBody, { title: __('Search appearance', 'publishrank'), initialOpen: true },
			hasTitle ? el(C.TextControl, { label: __('SEO title', 'publishrank'), value: meta[TITLE] || '', placeholder: postTitle, onChange: set(TITLE), __nextHasNoMarginBottom: true }) : null,
			hasTitle ? el(Counter, { value: meta[TITLE] || postTitle, min: 30, max: 60 }) : null,
			hasDesc ? el(C.TextareaControl, { label: __('Meta description', 'publishrank'), value: meta[DESC] || '', onChange: set(DESC), rows: 4, __nextHasNoMarginBottom: true }) : null,
			hasDesc ? el(Counter, { value: meta[DESC] || '', min: 120, max: 160 }) : null,
			el('p', { style: { fontSize: '12px', color: '#50575e', marginTop: '4px' } }, __('Schema, social and news fields stay in the PublishRank boxes below the editor.', 'publishrank'))
		);
	}

	function Commands() {
		wp.commands.useCommand({
			name: 'publishrank/open-seo-analysis',
			label: __('Open PublishRank SEO analysis', 'publishrank'),
			callback: function (o) { openSidebar(); if (o && o.close) { o.close(); } }
		});
		return null;
	}

	/* Always mounted: listens for "dock" requests from the floating panel and introduces the sidebar once. */
	function Bridge() {
		useEffect(function () {
			function on() { openSidebar(); }
			document.addEventListener('publishrank:open-sidebar', on);
			var seen = true; try { seen = window.localStorage.getItem(SEEN) === '1'; } catch (e) {}
			if (!seen && window.innerWidth > 782 && card() && card().mode() === 'docked') {
				window.setTimeout(openSidebar, 300);
				try { window.localStorage.setItem(SEEN, '1'); } catch (e) {}
			}
			return function () { document.removeEventListener('publishrank:open-sidebar', on); };
		}, []);
		return null;
	}

	wp.plugins.registerPlugin(PLUGIN, {
		icon: 'chart-line',
		render: function () {
			return el(wp.element.Fragment, null,
				el(Bridge),
				wp.commands && wp.commands.useCommand ? el(Commands) : null,
				PluginSidebarMoreMenuItem ? el(PluginSidebarMoreMenuItem, { target: SIDEBAR, icon: 'chart-line' }, 'PublishRank SEO') : null,
				el(PluginSidebar, { name: SIDEBAR, title: 'PublishRank SEO', icon: el(Badge), className: 'pr-esc-sidebar' },
					el(AnalysisDock),
					el(SearchAppearance))
			);
		}
	});
})(window.wp);
