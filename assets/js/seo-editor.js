/**
 * PublishRank SEO — unified block editor experience (2.26.1).
 *
 * One editing surface instead of a sidebar + meta boxes + floating window:
 *  - PluginSidebar "PublishRank SEO" (pinnable toolbar button with the live score) organised in tabs
 *    (SEO, Social, Schema, Advanced, Insights) with collapsible groups.
 *  - PluginDocumentSettingPanel "PublishRank SEO" in the Post tab of the settings sidebar with the essential
 *    fields. It can be switched on/off in Preferences → Panels like any native panel.
 *  - Entries in the editor's Options (⋮) menu: "PublishRank SEO editor", "PublishRank SEO panel" and
 *    "PublishRank SEO preferences", plus command palette commands and Ctrl/Cmd+Shift+E.
 *  - Per-user preferences stored with the editor's own preferences store (persisted in user meta by WordPress).
 *
 * The PHP-rendered sections live in a PublishRank-owned host (#publishrank-ue-host, see UnifiedEditor.php).
 * A group displays a section by adopting its node and gives it back when it closes; Gutenberg never manages these
 * nodes. Registered meta keys are mirrored into core/editor so they save natively; the rest is saved by the
 * save bridge right after a successful post save.
 *
 * No build step: plain wp.element calls.
 */
(function (wp, cfg) {
	'use strict';
	if (!wp || !wp.plugins || !wp.element || !wp.data || !wp.components || !cfg) { return; }
	var el = wp.element.createElement, Fragment = wp.element.Fragment;
	var useState = wp.element.useState, useEffect = wp.element.useEffect, useRef = wp.element.useRef;
	var useLayoutEffect = wp.element.useLayoutEffect || useEffect;
	var __ = (wp.i18n && wp.i18n.__) || function (s) { return s; };
	var sprintf = (wp.i18n && wp.i18n.sprintf) || function (s) { var a = arguments, i = 1; return String(s).replace(/%(\d+\$)?[sd]/g, function () { return a[i++]; }); };
	var C = wp.components, data = wp.data;
	function pick(name) { return (wp.editor && wp.editor[name]) || (wp.editPost && wp.editPost[name]) || null; }
	var PluginSidebar = pick('PluginSidebar'), PluginSidebarMoreMenuItem = pick('PluginSidebarMoreMenuItem');
	var PluginDocumentSettingPanel = pick('PluginDocumentSettingPanel'), PluginMoreMenuItem = pick('PluginMoreMenuItem');
	if (!PluginSidebar) { return; }

	var PLUGIN = 'publishrank-seo', SIDEBAR = 'publishrank-seo-sidebar', FULL = PLUGIN + '/' + SIDEBAR;
	var DOC_PANEL = 'publishrank-seo-panel', DOC_FULL = PLUGIN + '/' + DOC_PANEL;
	var SCOPE = 'publishrank/seo-editor';
	var DEFAULTS = { openOnLoad: true, userClosed: false, rememberSection: true, lastTab: 'seo', panelsOpen: {}, expand: 'essential', density: 'comfortable', floating: false, floatMin: false, floatPos: null };
	var REST = cfg.restKeys || {};

	/* ------------------------------------------------------------------ preferences */
	var prefStore = null;
	try { prefStore = data.select('core/preferences') ? 'core/preferences' : null; } catch (e) { prefStore = null; }
	if (prefStore) { try { data.dispatch(prefStore).setDefaults(SCOPE, DEFAULTS); } catch (e) {} }
	var memoryPrefs = {};
	function getPref(key) {
		if (prefStore) { try { var v = data.select(prefStore).get(SCOPE, key); return v === undefined ? DEFAULTS[key] : v; } catch (e) {} }
		return memoryPrefs.hasOwnProperty(key) ? memoryPrefs[key] : DEFAULTS[key];
	}
	function setPref(key, value) {
		if (prefStore) { try { data.dispatch(prefStore).set(SCOPE, key, value); return; } catch (e) {} }
		memoryPrefs[key] = value;
	}
	function resetPrefs() { Object.keys(DEFAULTS).forEach(function (k) { setPref(k, DEFAULTS[k]); }); }
	function usePrefs() {
		return data.useSelect(function (select) {
			var store = prefStore ? select(prefStore) : null, o = {};
			Object.keys(DEFAULTS).forEach(function (k) { var v = store ? store.get(SCOPE, k) : memoryPrefs[k]; o[k] = v === undefined ? DEFAULTS[k] : v; });
			return o;
		}, []);
	}

	/* ------------------------------------------------------------------ editor helpers */
	function dispatchSafe(store) { try { return data.dispatch(store); } catch (e) { return null; } }
	function selectSafe(store) { try { return data.select(store); } catch (e) { return null; } }
	function activeArea() {
		var i = selectSafe('core/interface');
		if (!i || !i.getActiveComplementaryArea) { return null; }
		var a = null;
		try { a = i.getActiveComplementaryArea('core'); } catch (e) {}
		if (a === undefined || a === null) { try { a = i.getActiveComplementaryArea('core/edit-post'); } catch (e) {} }
		return a || null;
	}
	/** Show the SEO editor: restores the movable window when the publisher uses it, otherwise opens the sidebar. */
	function openSidebar() {
		if (getPref('floating')) { if (getPref('floatMin')) { setPref('floatMin', false); } return; }
		openSidebarDirect();
	}
	function openSidebarDirect() {
		var i = dispatchSafe('core/interface');
		try { if (i && i.enableComplementaryArea) { i.enableComplementaryArea('core', FULL); } } catch (e) {}
		var ep = dispatchSafe('core/edit-post');
		try { if (activeArea() !== FULL && ep && ep.openGeneralSidebar) { ep.openGeneralSidebar(FULL); } } catch (e) {}
	}
	function closeSidebar() {
		var i = dispatchSafe('core/interface');
		try { if (i && i.disableComplementaryArea) { i.disableComplementaryArea('core'); } } catch (e) {}
		var ep = dispatchSafe('core/edit-post');
		try { if (activeArea() === FULL && ep && ep.closeGeneralSidebar) { ep.closeGeneralSidebar(); } } catch (e) {}
	}
	function panelApi() {
		var ed = selectSafe('core/editor'), ep = selectSafe('core/edit-post');
		var edD = dispatchSafe('core/editor'), epD = dispatchSafe('core/edit-post');
		var useEd = ed && ed.isEditorPanelEnabled;
		return {
			enabled: function (n) { try { return useEd ? ed.isEditorPanelEnabled(n) : ep.isEditorPanelEnabled(n); } catch (e) { return true; } },
			opened: function (n) { try { return useEd ? ed.isEditorPanelOpened(n) : ep.isEditorPanelOpened(n); } catch (e) { return true; } },
			toggleEnabled: function (n) { try { (useEd ? edD : epD).toggleEditorPanelEnabled(n); } catch (e) {} },
			toggleOpened: function (n) { try { (useEd ? edD : epD).toggleEditorPanelOpened(n); } catch (e) {} }
		};
	}
	function openDocPanel() {
		var p = panelApi();
		if (!p.enabled(DOC_FULL)) { p.toggleEnabled(DOC_FULL); }
		if (!p.opened(DOC_FULL)) { p.toggleOpened(DOC_FULL); }
		var i = dispatchSafe('core/interface');
		try { if (i && i.enableComplementaryArea) { i.enableComplementaryArea('core', 'edit-post/document'); } } catch (e) {}
		var ep = dispatchSafe('core/edit-post');
		try { if (ep && ep.openGeneralSidebar && activeArea() !== 'edit-post/document') { ep.openGeneralSidebar('edit-post/document'); } } catch (e) {}
		window.setTimeout(function () { var n = document.querySelector('.pr-ue-docpanel'); if (n && n.scrollIntoView) { n.scrollIntoView({ block: 'nearest' }); } }, 250);
	}
	function postId() { var ed = selectSafe('core/editor'); return (ed && ed.getCurrentPostId && ed.getCurrentPostId()) || cfg.postId || 0; }
	function notices() { return dispatchSafe('core/notices'); }

	/* ------------------------------------------------------------------ live score (from the score card) */
	function card() { return window.PublishRankScoreCard || null; }
	function band(n) { return n >= 90 ? 'excellent' : (n >= 80 ? 'good' : (n >= 55 ? 'ok' : 'bad')); }
	function useScore() {
		var st = useState(card() ? card().score() : { score: 0, band: 'bad', label: '' });
		useEffect(function () {
			function on(e) { if (e && e.detail) { st[1](e.detail); } }
			document.addEventListener('publishrank:score', on);
			if (card()) { st[1](card().score()); }
			return function () { document.removeEventListener('publishrank:score', on); };
		}, []);
		return st[0];
	}
	function Badge(props) {
		var s = useScore(), n = Math.max(0, Math.min(100, parseInt(s.score, 10) || 0));
		return el('span', { className: 'pr-esc-badge is-' + (s.band || band(n)) + (props && props.large ? ' pr-ue-badge--large' : ''), style: { '--pr-esc-score': String(n) }, role: props && props.large ? 'img' : undefined, 'aria-label': props && props.large ? sprintf(__('SEO score %d out of 100', 'publishrank'), n) : undefined, 'aria-hidden': props && props.large ? undefined : 'true' }, el('b', null, String(n)));
	}

	/* ------------------------------------------------------------------ section nodes (PHP host) */
	function host() { return document.getElementById('publishrank-ue-host'); }
	function box(id) { var h = host(); return h ? h.querySelector('form[data-pr-box="' + id + '"]') : null; }
	var claimed = []; // { key, node, placeholder }
	function remember(key, node) {
		for (var i = 0; i < claimed.length; i++) { if (claimed[i].node === node) { return claimed[i]; } }
		var ph = document.createComment('pr-ue:' + key);
		if (node.parentNode) { node.parentNode.insertBefore(ph, node); }
		var rec = { key: key, node: node, placeholder: ph };
		claimed.push(rec);
		return rec;
	}
	function fieldWrap(name) {
		var f = document.querySelector('.pr-ue-box [name="' + name + '"], [data-pr-ue-slot] [name="' + name + '"]');
		return f ? (f.closest('.pr-seo-field') || f) : null;
	}
	/** Resolve a part spec to a DOM node, at most once per node. */
	var partCache = {};
	function resolvePart(spec) {
		var k = JSON.stringify(spec);
		if (partCache[k] && (partCache[k].isConnected !== false)) { return partCache[k]; }
		var node = null;
		if (spec.field) { node = fieldWrap(spec.field); }
		else if (spec.box && !spec.sel) { node = box(spec.box); node = node ? (node.__prInner || wrapBox(node)) : null; }
		else if (spec.sel) {
			var scope = spec.box ? box(spec.box) : null;
			node = (scope && scope.querySelector(spec.sel)) || document.querySelector('.pr-ue-box ' + spec.sel);
			if (!node) { node = findMoved(spec.sel); }
		}
		if (node) { partCache[k] = node; }
		return node;
	}
	function findMoved(sel) { for (var i = 0; i < claimed.length; i++) { if (claimed[i].node.matches && claimed[i].node.matches(sel)) { return claimed[i].node; } } return null; }
	/** A whole box is displayed through an inner wrapper so the <form> itself stays in the host. */
	function wrapBox(form) {
		var inner = document.createElement('div');
		inner.className = 'pr-ue-boxinner';
		inner.setAttribute('data-pr-box-inner', form.getAttribute('data-pr-box'));
		while (form.firstChild) { inner.appendChild(form.firstChild); }
		form.appendChild(inner);
		form.__prInner = inner;
		return inner;
	}
	function adopt(slot, nodes, key) {
		nodes.forEach(function (n) { if (!n) { return; } remember(key, n); slot.appendChild(n); });
	}
	function giveBack(nodes) {
		nodes.forEach(function (n) {
			if (!n) { return; }
			for (var i = 0; i < claimed.length; i++) {
				var r = claimed[i];
				if (r.node === n && r.placeholder.parentNode) { r.placeholder.parentNode.insertBefore(n, r.placeholder.nextSibling); return; }
			}
		});
	}
	/** Mount the given parts inside this component while it is rendered. */
	function Slot(props) {
		var ref = useRef(null), st = useState(0), missing = st[0], setMissing = st[1];
		useLayoutEffect(function () {
			var slot = ref.current; if (!slot) { return; }
			var nodes = props.parts.map(resolvePart).filter(Boolean);
			setMissing(props.parts.length && !nodes.length ? 1 : 0);
			adopt(slot, nodes, props.id);
			try { document.dispatchEvent(new CustomEvent('publishrank:section-shown', { detail: { id: props.id } })); } catch (e) {}
			return function () { giveBack(nodes); };
		}, [props.id]);
		return el(Fragment, null,
			missing ? el('p', { className: 'pr-ue-empty' }, props.empty || __('This section is not available for this content type or is switched off in PublishRank → Features.', 'publishrank')) : null,
			el('div', { ref: ref, className: 'pr-ue-slot', 'data-pr-ue-slot': props.id }));
	}

	/* ------------------------------------------------------------------ meta mirroring + save bridge */
	var syncing = false, bridgeDirty = false, lastMeta = null, bridgeBusy = false;
	function metaValue(key, raw) { return REST[key] === 'list' ? (Array.isArray(raw) ? raw.join('\n') : String(raw || '')) : String(raw === undefined || raw === null ? '' : raw); }
	function toMeta(key, value) {
		if (REST[key] !== 'list') { return String(value); }
		return String(value).split(/[\r\n]+/).map(function (s) { return s.trim(); }).filter(Boolean);
	}
	function inEditorRoots(node) { return !!(node && node.closest && node.closest('.pr-ue-box, [data-pr-ue-slot]')); }
	function touch() {
		var d = dispatchSafe('core/editor'); if (!d || !d.editPost || !cfg.touchKey) { return; }
		var m = {}; m[cfg.touchKey] = String(Date.now()); d.editPost({ meta: m });
	}
	/* Only real edits count: trusted events, or script changes that directly follow a user action (Fix / Apply buttons). */
	var lastUserAction = 0;
	['pointerdown', 'keydown'].forEach(function (type) { document.addEventListener(type, function (e) { if (e.isTrusted && inEditorRoots(e.target)) { lastUserAction = Date.now(); } }, true); });
	function onFieldEvent(e) {
		var t = e.target;
		if (syncing || !t || !inEditorRoots(t)) { return; }
		if (!e.isTrusted && Date.now() - lastUserAction > 2500) { return; }
		bridgeDirty = true;
		if (t.name && REST.hasOwnProperty(t.name) && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT') && t.type !== 'checkbox' && t.type !== 'hidden') {
			var m = {}; m[t.name] = toMeta(t.name, t.value);
			var d = dispatchSafe('core/editor'); if (d && d.editPost) { d.editPost({ meta: m }); }
		} else { touch(); }
	}
	document.addEventListener('input', onFieldEvent, true);
	document.addEventListener('change', onFieldEvent, true);
	document.addEventListener('click', function (e) { var b = e.target && e.target.closest && e.target.closest('button'); if (b && inEditorRoots(b) && !b.closest('[role="tablist"]')) { bridgeDirty = true; } }, true);

	function pushMetaToDom() {
		var ed = selectSafe('core/editor'); if (!ed || !ed.getEditedPostAttribute) { return; }
		var meta = ed.getEditedPostAttribute('meta') || {};
		if (!lastMeta) { lastMeta = {}; Object.keys(REST).forEach(function (k) { lastMeta[k] = metaValue(k, meta[k]); }); return; }
		Object.keys(REST).forEach(function (k) {
			var v = metaValue(k, meta[k]);
			if (v === lastMeta[k]) { return; }
			lastMeta[k] = v;
			document.querySelectorAll('.pr-ue-box [name="' + k + '"], [data-pr-ue-slot] [name="' + k + '"]').forEach(function (f) {
				if (f === document.activeElement || f.value === v || f.type === 'hidden' || f.type === 'checkbox') { return; }
				syncing = true; f.value = v;
				try { f.dispatchEvent(new Event('input', { bubbles: true })); f.dispatchEvent(new Event('change', { bubbles: true })); } catch (er) {}
				syncing = false;
				bridgeDirty = true;
			});
		});
	}

	/** Every named control of the PublishRank sections, wherever it is displayed. */
	function collect() {
		var fd = new FormData();
		var roots = Array.prototype.slice.call(document.querySelectorAll('.pr-ue-box, [data-pr-ue-slot]'));
		var seen = [];
		roots.forEach(function (root) {
			root.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (f) {
				if (seen.indexOf(f) >= 0 || f.disabled) { return; }
				seen.push(f);
				var type = (f.type || '').toLowerCase();
				if (type === 'file' || type === 'submit' || type === 'button' || type === 'image' || type === 'reset') { return; }
				if ((type === 'checkbox' || type === 'radio') && !f.checked) { return; }
				if (f.tagName === 'SELECT' && f.multiple) { Array.prototype.forEach.call(f.options, function (o) { if (o.selected) { fd.append(f.name, o.value); } }); return; }
				fd.append(f.name, f.value);
			});
		});
		return fd;
	}
	function bridgeSave(force) {
		if (bridgeBusy || (!bridgeDirty && !force) || !host()) { return Promise.resolve(false); }
		bridgeBusy = true;
		var fd = collect();
		fd.append('action', cfg.action); fd.append(cfg.nonceField, cfg.nonce); fd.append('post_ID', String(postId()));
		setStatus('saving');
		return window.fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'HTTP ' + r.status } }; }); })
			.then(function (j) {
				bridgeBusy = false;
				if (j && j.success) {
					bridgeDirty = false; setStatus('saved');
					try { document.dispatchEvent(new CustomEvent('publishrank:editor-saved', { detail: j.data || {} })); } catch (e) {}
					return true;
				}
				throw new Error((j && j.data && j.data.message) || __('Unknown error', 'publishrank'));
			})
			.catch(function (err) {
				bridgeBusy = false; setStatus('error');
				var n = notices();
				if (n && n.createErrorNotice) {
					n.createErrorNotice(sprintf(__('PublishRank SEO fields were not saved: %s', 'publishrank'), err.message), { id: 'publishrank-seo-save', isDismissible: true, actions: [{ label: __('Try again', 'publishrank'), onClick: function () { bridgeSave(true); } }] });
				}
				return false;
			});
	}
	var statusListeners = [];
	var saveStatus = 'idle';
	function setStatus(s) { saveStatus = s; statusListeners.forEach(function (f) { f(s); }); }
	function useSaveStatus() {
		var st = useState(saveStatus);
		useEffect(function () { statusListeners.push(st[1]); return function () { statusListeners = statusListeners.filter(function (f) { return f !== st[1]; }); }; }, []);
		return st[0];
	}
	var wasSaving = false;
	data.subscribe(function () {
		pushMetaToDom();
		var ed = selectSafe('core/editor'); if (!ed || !ed.isSavingPost) { return; }
		var saving = !!ed.isSavingPost() && !(ed.isAutosavingPost && ed.isAutosavingPost());
		if (wasSaving && !saving && ed.didPostSaveRequestSucceed && ed.didPostSaveRequestSucceed()) { bridgeSave(false); }
		wasSaving = saving;
	});
	window.addEventListener('beforeunload', function (e) { if (bridgeDirty && host()) { e.preventDefault(); e.returnValue = ''; } });

	/* ------------------------------------------------------------------ structure */
	var OV = 'publishrank-seo-overrides';
	var TABS = [
		{ name: 'seo', title: __('SEO', 'publishrank'), panels: [
			{ id: 'overview', title: __('Overview & SEO score', 'publishrank'), essential: true, render: function () { return el(Overview); } },
			{ id: 'search', title: __('Search appearance: full live preview & checks', 'publishrank'), essential: true, parts: [{ box: 'publishrank-live-preview' }], empty: __('Live previews are switched off for this content type.', 'publishrank') },
			{ id: 'meta', title: __('Google preview, SEO title & meta description', 'publishrank'), essential: true, render: function () { return el(GooglePreview); }, parts: [{ field: '_publishrank_seo_title' }, { field: '_publishrank_seo_description' }] },
			{ id: 'keywords', title: __('Focus keywords', 'publishrank'), essential: true, parts: [{ box: OV, sel: '[data-publishrank-keyword-fields]' }] }
		] },
		{ name: 'social', title: __('Social', 'publishrank'), panels: [
			{ id: 'social-preview', title: __('Social previews', 'publishrank'), essential: true, render: function () { return el(SocialPreview); } },
			{ id: 'social', title: __('Social titles, descriptions & images', 'publishrank'), essential: true, parts: [{ box: OV, sel: 'section[aria-labelledby="pr-seo-social-title"]' }] }
		] },
		{ name: 'schema', title: __('Schema', 'publishrank'), panels: [
			{ id: 'schema-studio', title: __('Schema Studio', 'publishrank'), essential: true, parts: [{ box: 'publishrank-schema-studio' }], render: function () { return el(SchemaTools); } },
			{ id: 'schema-type', title: __('Schema type & properties', 'publishrank'), essential: true, parts: [{ box: 'publishrank-schema' }] },
			{ id: 'schema-library', title: __('More structured data', 'publishrank'), parts: [{ box: 'publishrank-schema-library' }] }
		] },
		{ name: 'advanced', title: __('Advanced', 'publishrank'), panels: [
			{ id: 'robots', title: __('Canonical URL & robots', 'publishrank'), essential: true, parts: [{ field: '_publishrank_seo_canonical' }, { box: OV, sel: 'section[aria-labelledby="pr-seo-indexing-title"]' }] },
			{ id: 'advanced', title: __('Advanced SEO', 'publishrank'), parts: [{ box: OV, sel: 'section[aria-labelledby="pr-seo-context-title"]' }] },
			{ id: 'publisher', title: __('Publisher & newsroom', 'publishrank'), parts: [{ box: OV, sel: 'section[aria-labelledby="pr-seo-article-title"]' }] },
			{ id: 'multilingual', title: __('Multilingual SEO', 'publishrank'), parts: [{ box: 'publishrank-localized-seo' }], empty: __('Multilingual SEO appears when a translation plugin or extra languages are configured.', 'publishrank') },
			{ id: 'more', title: __('Other PublishRank fields', 'publishrank'), leftovers: true }
		] },
		{ name: 'insights', title: __('Insights', 'publishrank'), panels: [
			{ id: 'intel', title: __('Article Intelligence', 'publishrank'), essential: true, parts: [{ box: 'publishrank_article_intelligence' }], empty: __('Article Intelligence is switched off or not available for this content type.', 'publishrank') },
			{ id: 'links', title: __('Internal linking', 'publishrank'), render: function () { return el(LinksPanel); } },
			{ id: 'diagnostics', title: __('Indexing & technical diagnostics', 'publishrank'), essential: true, parts: [{ box: OV, sel: '[data-publishrank-resolved-summary]' }], render: function () { return el(Diagnostics); } }
		] }
	];
	function allPanels() { var out = []; TABS.forEach(function (t) { t.panels.forEach(function (p) { out.push({ tab: t.name, panel: p }); }); }); return out; }

	/** Top-level children of the boxes that no group displays (so no field is ever silently dropped). */
	var leftoverNodes = null;
	function leftovers() {
		if (leftoverNodes) { return leftoverNodes; }
		var known = [];
		allPanels().forEach(function (x) { (x.panel.parts || []).forEach(function (s) { var n = resolvePart(s); if (n) { known.push(n); } }); });
		var out = [];
		(cfg.boxes || []).forEach(function (id) {
			var f = box(id); if (!f) { return; }
			var root = f.__prInner || f;
			if (known.indexOf(root) >= 0) { return; }
			Array.prototype.forEach.call(root.children, function (c) {
				if (known.indexOf(c) >= 0) { return; }
				if (known.some(function (k) { return c.contains(k) || k.contains(c); })) { return; }
				if ((c.tagName === 'INPUT' && c.type === 'hidden') || c.tagName === 'SCRIPT' || c.classList.contains('pr-seo-editor-intro') || c.classList.contains('notice-success')) { return; }
				if (!c.querySelector('input:not([type="hidden"]),select,textarea,button')) { return; }
				out.push(c);
			});
		});
		leftoverNodes = out;
		return out;
	}
	function LeftoverSlot() {
		var ref = useRef(null);
		useLayoutEffect(function () { var nodes = leftovers(); adopt(ref.current, nodes, 'more'); return function () { giveBack(nodes); }; }, []);
		return el(Fragment, null, leftovers().length ? null : el('p', { className: 'pr-ue-empty' }, __('Every PublishRank field is shown in the groups above.', 'publishrank')), el('div', { ref: ref, className: 'pr-ue-slot', 'data-pr-ue-slot': 'more' }));
	}

	/* ------------------------------------------------------------------ panels with native content */
	function Overview() {
		var ref = useRef(null), mode = useMode();
		useLayoutEffect(function () {
			var api = card(), slot = ref.current;
			if (!api || !slot || mode !== 'docked') { return; }
			api.dock(slot);
			return function () { api.release(slot); };
		}, [mode]);
		if (!card()) { return el(QuickSummary); }
		if (mode === 'floating') {
			return el('div', { className: 'pr-ue-note' }, el('p', null, __('The SEO analysis is popped out as a floating panel.', 'publishrank')),
				el(C.Button, { variant: 'secondary', onClick: function () { card().setMode('docked'); } }, __('Show it here', 'publishrank')));
		}
		return el('div', { ref: ref, className: 'pr-esc-dock pr-esc-dock--sidebar' });
	}
	function useMode() {
		var st = useState(card() ? card().mode() : 'docked');
		useEffect(function () { function on(e) { if (e && e.detail) { st[1](e.detail.mode); } } document.addEventListener('publishrank:mode', on); return function () { document.removeEventListener('publishrank:mode', on); }; }, []);
		return st[0];
	}
	function useMeta() {
		return data.useSelect(function (s) { var ed = s('core/editor'); return (ed && ed.getEditedPostAttribute && ed.getEditedPostAttribute('meta')) || {}; }, []);
	}
	function setMeta(key, value) { var d = dispatchSafe('core/editor'); if (d && d.editPost) { var m = {}; m[key] = value; d.editPost({ meta: m }); } }
	function counter(len, lo, hi) { return len === 0 ? 'is-auto' : (len < lo ? 'is-short' : (len > hi ? 'is-long' : 'is-ok')); }
	function QuickSummary() {
		var meta = useMeta();
		var t = String(meta._publishrank_seo_title || ''), d = String(meta._publishrank_seo_description || '');
		return el('ul', { className: 'pr-ue-chips' },
			el('li', { className: counter(t.length, 30, 60) }, sprintf(__('SEO title: %d characters', 'publishrank'), t.length)),
			el('li', { className: counter(d.length, 120, 160) }, sprintf(__('Meta description: %d characters', 'publishrank'), d.length)));
	}
	function SchemaTools() {
		return el('div', { className: 'pr-ue-actions' },
			el(C.Button, { variant: 'secondary', onClick: function () { showJsonLd(); } }, __('Preview JSON-LD', 'publishrank')),
			cfg.canSchema ? el(C.Button, { variant: 'tertiary', href: cfg.links.schemaStudio, target: '_blank' }, __('Open Schema Studio templates', 'publishrank')) : null);
	}
	function showJsonLd() {
		state.go('seo', 'search');
		window.setTimeout(function () { var b = document.querySelector('[data-pr-ue-slot] [data-pr-pv-tab="json"]'); if (b) { b.click(); b.focus(); } }, 300);
	}

	/* Internal linking: local analysis of the links in the current content. */
	var GENERIC = ['click here', 'here', 'read more', 'more', 'this', 'link', 'this article', 'learn more', 'details'];
	function analyseLinks(html) {
		var doc = document.implementation.createHTMLDocument('x'); doc.body.innerHTML = html || '';
		var home = window.location.origin, out = { internal: [], external: [], issues: [] }, seen = {};
		Array.prototype.forEach.call(doc.querySelectorAll('a[href]'), function (a) {
			var href = a.getAttribute('href') || '', text = (a.textContent || '').replace(/\s+/g, ' ').trim(), url;
			if (/^(mailto:|tel:|#|javascript:)/i.test(href)) { return; }
			try { url = new URL(href, home); } catch (e) { out.issues.push({ level: 'error', text: sprintf(__('Malformed link: %s', 'publishrank'), href) }); return; }
			var rel = (a.getAttribute('rel') || '').toLowerCase();
			var row = { href: url.href, text: text, nofollow: rel.indexOf('nofollow') >= 0 || rel.indexOf('sponsored') >= 0 || rel.indexOf('ugc') >= 0 };
			(url.origin === home ? out.internal : out.external).push(row);
			if (!text && !a.querySelector('img[alt]:not([alt=""])')) { out.issues.push({ level: 'warning', text: sprintf(__('Link without anchor text: %s', 'publishrank'), url.href) }); }
			if (text && GENERIC.indexOf(text.toLowerCase()) >= 0) { out.issues.push({ level: 'warning', text: sprintf(__('Generic anchor text "%1$s" → %2$s', 'publishrank'), text, url.href) }); }
			if (seen[url.href]) { out.issues.push({ level: 'notice', text: sprintf(__('Linked more than once: %s', 'publishrank'), url.href) }); }
			seen[url.href] = true;
			if (url.protocol === 'http:' && window.location.protocol === 'https:' && url.origin === home.replace('https:', 'http:')) { out.issues.push({ level: 'warning', text: sprintf(__('Internal link uses http:// on an https site: %s', 'publishrank'), url.href) }); }
		});
		return out;
	}
	function LinksPanel() {
		var content = data.useSelect(function (s) { var ed = s('core/editor'); return ed && ed.getEditedPostContent ? ed.getEditedPostContent() : ''; }, []);
		var r = analyseLinks(content);
		function list(rows) {
			if (!rows.length) { return el('p', { className: 'pr-ue-empty' }, __('None yet.', 'publishrank')); }
			return el('ul', { className: 'pr-ue-links' }, rows.slice(0, 30).map(function (row, i) {
				return el('li', { key: i }, el('strong', null, row.text || __('(no anchor text)', 'publishrank')), el('span', { className: 'pr-ue-url' }, row.href), row.nofollow ? el('span', { className: 'pr-ue-tag' }, 'nofollow') : null);
			}));
		}
		return el('div', { className: 'pr-ue-linkpanel' },
			el('ul', { className: 'pr-ue-chips' },
				el('li', { className: r.internal.length ? 'is-ok' : 'is-short' }, sprintf(__('%d internal', 'publishrank'), r.internal.length)),
				el('li', { className: 'is-neutral' }, sprintf(__('%d external', 'publishrank'), r.external.length)),
				el('li', { className: r.issues.length ? 'is-long' : 'is-ok' }, sprintf(__('%d issues', 'publishrank'), r.issues.length))),
			r.issues.length ? el('ul', { className: 'pr-ue-issues' }, r.issues.slice(0, 12).map(function (i, k) { return el('li', { key: k, className: 'is-' + i.level }, i.text); })) : null,
			el('h3', { className: 'pr-ue-h' }, __('Internal links', 'publishrank')), list(r.internal),
			el('h3', { className: 'pr-ue-h' }, __('External links', 'publishrank')), list(r.external),
			el('div', { className: 'pr-ue-actions' },
				el(C.Button, { variant: 'secondary', onClick: function () { var b = document.querySelector('[data-pr-esc-links]'); if (b) { state.go('seo', 'overview'); window.setTimeout(function () { var x = document.querySelector('[data-pr-esc-links]'); if (x) { x.click(); } }, 300); } } }, __('Suggest related articles', 'publishrank')),
				el(C.Button, { variant: 'tertiary', href: cfg.links.linkHealth, target: '_blank' }, __('Link Health Audit', 'publishrank'))),
			el('p', { className: 'pr-ue-help' }, __('Suggestions are never inserted automatically: you review and place every link yourself.', 'publishrank')));
	}
	function Diagnostics() {
		var info = data.useSelect(function (s) {
			var ed = s('core/editor') || {};
			return { status: ed.getEditedPostAttribute ? ed.getEditedPostAttribute('status') : '', link: ed.getPermalink ? ed.getPermalink() : '', slug: ed.getEditedPostSlug ? ed.getEditedPostSlug() : '', meta: (ed.getEditedPostAttribute && ed.getEditedPostAttribute('meta')) || {}, pw: ed.getEditedPostAttribute ? ed.getEditedPostAttribute('password') : '' };
		}, []);
		var robots = String(info.meta._publishrank_seo_robots || '').toLowerCase(), canon = String(info.meta._publishrank_seo_canonical || '');
		var rows = [];
		rows.push({ ok: info.status === 'publish', text: info.status === 'publish' ? __('Published: search engines can reach this URL.', 'publishrank') : sprintf(__('Status "%s": not public yet, so it is not indexed.', 'publishrank'), info.status || 'draft') });
		if (info.pw) { rows.push({ ok: false, text: __('Password protected: content is hidden from search engines.', 'publishrank') }); }
		rows.push({ ok: robots.indexOf('noindex') < 0, text: robots.indexOf('noindex') >= 0 ? __('Robots: noindex — this page is excluded from search results and the sitemap.', 'publishrank') : __('Robots: indexable (site default or your directives).', 'publishrank') });
		if (canon) {
			var same = info.link && canon.replace(/\/$/, '') === String(info.link).replace(/\/$/, '');
			rows.push({ ok: same, text: same ? __('Canonical URL points to this page.', 'publishrank') : sprintf(__('Canonical points elsewhere: %s. Search engines will usually index that URL instead.', 'publishrank'), canon) });
			if (robots.indexOf('noindex') >= 0 && !same) { rows.push({ ok: false, text: __('Conflict: noindex together with a cross-URL canonical sends mixed signals. Use one of them.', 'publishrank') }); }
		} else { rows.push({ ok: true, text: __('Canonical: automatic (this page).', 'publishrank') }); }
		if (info.slug && info.slug.length > 75) { rows.push({ ok: false, text: sprintf(__('The URL slug is long (%d characters). Shorter slugs are easier to share.', 'publishrank'), info.slug.length) }); }
		return el('div', null,
			el('ul', { className: 'pr-ue-diag' }, rows.map(function (r, i) { return el('li', { key: i, className: r.ok ? 'is-ok' : 'is-warn' }, r.text); })),
			el('div', { className: 'pr-ue-actions' },
				el(C.Button, { variant: 'secondary', href: cfg.links.inspector, target: '_blank' }, __('Open in SEO Inspector', 'publishrank')),
				el(C.Button, { variant: 'tertiary', onClick: showJsonLd }, __('JSON-LD preview', 'publishrank'))));
	}

	/* ------------------------------------------------------------------ Google + social previews */
	// Exact values come from the Live Preview endpoint (the same code that prints the page <head>); live-preview.js
	// announces every result. Until it answers, or when Live Preview is switched off for this content type, the
	// cards show an approximation built from the editor's own data and say so.
	var lastPreview = null;
	document.addEventListener('publishrank:live-preview', function (e) { if (e && e.detail) { lastPreview = e.detail; } });
	function usePreviewData() {
		var st = useState(lastPreview || (window.PublishRankLivePreview && window.PublishRankLivePreview.last ? window.PublishRankLivePreview.last() : null));
		useEffect(function () {
			function on(e) { if (e && e.detail) { st[1](e.detail); } }
			document.addEventListener('publishrank:live-preview', on);
			if (lastPreview) { st[1](lastPreview); }
			return function () { document.removeEventListener('publishrank:live-preview', on); };
		}, []);
		var local = data.useSelect(function (s) {
			var ed = s('core/editor') || {}, core = s('core') || {};
			function get(k) { try { return ed.getEditedPostAttribute ? ed.getEditedPostAttribute(k) : ''; } catch (e) { return ''; } }
			var fm = get('featured_media'), media = null, img = '', link = '', content = '';
			try { media = fm && core.getMedia ? core.getMedia(fm) : null; } catch (e) { media = null; }
			if (media) { var sz = media.media_details && media.media_details.sizes; img = (sz && sz.large && sz.large.source_url) || media.source_url || ''; }
			try { link = ed.getPermalink ? (ed.getPermalink() || '') : ''; } catch (e) { link = ''; }
			try { content = ed.getEditedPostContent ? (ed.getEditedPostContent() || '') : ''; } catch (e) { content = ''; }
			return { title: String(get('title') || ''), excerpt: String(get('excerpt') || ''), content: content, link: link, date: get('date') || '', meta: get('meta') || {}, image: img };
		}, []);
		return { server: st[0], local: local };
	}
	function plainText(html) { var d = document.createElement('div'); d.innerHTML = String(html || '').replace(/<!--[\s\S]*?-->/g, ' '); return (d.textContent || '').replace(/\s+/g, ' ').trim(); }
	function fillVars(s, ctx) {
		return String(s || '').replace(/%%([a-z_]+)%%/gi, function (m, k) {
			k = k.toLowerCase();
			return k === 'title' ? ctx.title : (k === 'sitename' ? ctx.site : (k === 'sep' ? '–' : (k === 'excerpt' ? ctx.excerpt : (k === 'focuskw' || k === 'focus_keyword' ? ctx.kw : ''))));
		}).replace(/\s+/g, ' ').trim();
	}
	function previewValues(p) {
		var sv = p.server, l = p.local, m = l.meta || {}, site = cfg.siteName || '';
		if (sv) {
			var so = sv.social || {};
			return { exact: true, site: sv.site_name || site, url: sv.canonical || sv.permalink || l.link, favicon: sv.favicon || '', date: sv.date || l.date, title: sv.title || '', desc: sv.description || '',
				ogTitle: so.title || sv.title || '', ogDesc: so.description || sv.description || '', ogImage: so.image || '',
				xTitle: so.x_title || so.title || sv.title || '', xDesc: so.x_description || so.description || sv.description || '', xImage: so.x_image || so.image || '', xCard: so.card || 'summary_large_image' };
		}
		var text = plainText(l.content), excerpt = l.excerpt || (text.length > 155 ? text.slice(0, 155).replace(/\s+\S*$/, '') + '…' : text);
		var ctx = { title: l.title, site: site, excerpt: excerpt, kw: String(m._publishrank_seo_focus_keyword || '') };
		var title = fillVars(m._publishrank_seo_title, ctx) || (l.title ? l.title + (site ? ' – ' + site : '') : site);
		var desc = fillVars(m._publishrank_seo_description, ctx) || excerpt;
		var ogT = fillVars(m._publishrank_seo_og_title, ctx) || title, ogD = fillVars(m._publishrank_seo_og_description, ctx) || desc;
		var ogI = String(m._publishrank_seo_og_image || '') || l.image;
		return { exact: false, site: site, url: String(m._publishrank_seo_canonical || '') || l.link || cfg.homeUrl || '', favicon: '', date: l.date, title: title, desc: desc,
			ogTitle: ogT, ogDesc: ogD, ogImage: ogI, xTitle: fillVars(m._publishrank_seo_x_title, ctx) || ogT, xDesc: fillVars(m._publishrank_seo_x_description, ctx) || ogD, xImage: ogI,
			xCard: String(m._publishrank_seo_twitter_card || '') || 'summary_large_image' };
	}
	function safeHttp(u) { return /^https?:\/\//i.test(u || '') ? String(u) : ''; }
	function urlHost(u) { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return ''; } }
	function urlCrumbs(u) { try { var x = new URL(u), parts = x.pathname.split('/').filter(Boolean).slice(0, 3); return x.hostname + (parts.length ? ' › ' + parts.join(' › ') : ''); } catch (e) { return u || ''; } }
	var measureCtx = null;
	function titlePx(s) {
		if (!measureCtx) { try { measureCtx = document.createElement('canvas').getContext('2d'); } catch (e) { measureCtx = false; } }
		if (!measureCtx) { return String(s).length * 9; }
		measureCtx.font = '20px Arial,sans-serif';
		return Math.round(measureCtx.measureText(String(s)).width);
	}
	/** Length progress bar: green inside the recommended range, amber when short, red when too long or missing. */
	function LengthBar(props) {
		var v = Math.max(0, Math.round(props.value || 0)), lo = props.min, hi = props.max;
		var cls = !v ? 'is-over' : (v > hi ? 'is-over' : (v < lo ? 'is-short' : 'is-good'));
		var word = !v ? __('missing', 'publishrank') : (v > hi ? __('too long', 'publishrank') : (v < lo ? __('could be longer', 'publishrank') : __('good', 'publishrank')));
		var pct = Math.min(100, Math.round(v / hi * 100));
		return el('div', { className: 'pr-ue-meter ' + cls },
			el('div', { className: 'pr-ue-meter__top' }, el('span', { className: 'pr-ue-meter__label' }, props.label), el('span', { className: 'pr-ue-meter__val' }, sprintf(__('%1$d / %2$d %3$s · %4$s', 'publishrank'), v, hi, props.unit, word))),
			el('div', { className: 'pr-ue-meter__track', role: 'meter', 'aria-label': props.label, 'aria-valuemin': 0, 'aria-valuemax': hi, 'aria-valuenow': Math.min(v, hi), 'aria-valuetext': v + ' ' + props.unit + ', ' + word + ' (' + sprintf(__('recommended %1$d–%2$d', 'publishrank'), lo, hi) + ')' },
				el('span', { className: 'pr-ue-meter__fill', style: { width: pct + '%' } })));
	}
	function previewNote(v) {
		return el('p', { className: 'pr-ue-help' }, v.exact
			? __('Built from your unsaved edits by the same code that outputs your page. Search engines and social networks may still rewrite it.', 'publishrank')
			: __('Approximate preview from the editor. The exact version appears as soon as the live preview has loaded.', 'publishrank'));
	}
	function Switch(props) {
		return el('div', { className: 'pr-ue-pv__switch', role: 'group', 'aria-label': props.label }, props.options.map(function (o) {
			var on = props.value === o.value;
			return el(C.Button, { key: o.value, size: 'small', variant: on ? 'primary' : 'secondary', 'aria-pressed': on ? 'true' : 'false', onClick: function () { props.onChange(o.value); } }, o.label);
		}));
	}
	function GooglePreview(props) {
		var v = previewValues(usePreviewData());
		var ds = useState('desktop'), device = ds[0];
		var date = '';
		if (v.date) { try { date = new Date(v.date).toLocaleDateString(document.documentElement.lang || undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (e) { date = ''; } }
		var fav = safeHttp(v.favicon);
		var site = v.site || urlHost(v.url);
		return el('div', { className: 'pr-ue-pv pr-ue-pv--google' },
			el('div', { className: 'pr-ue-pv__bar' },
				el('strong', { className: 'pr-ue-pv__label' }, __('Google preview', 'publishrank')),
				props.compact ? null : el(Switch, { label: __('Search snippet preview size', 'publishrank'), value: device, onChange: ds[1], options: [{ value: 'desktop', label: __('Desktop', 'publishrank') }, { value: 'mobile', label: __('Mobile', 'publishrank') }] })),
			el('div', { className: 'pr-ue-serp' + (device === 'mobile' || props.compact ? ' is-mobile' : ''), role: 'img', 'aria-label': sprintf(__('Google result preview: %1$s. %2$s', 'publishrank'), v.title, v.desc) },
				el('div', { className: 'pr-ue-serp__top' },
					fav ? el('img', { className: 'pr-ue-serp__fav', src: fav, alt: '' }) : el('span', { className: 'pr-ue-serp__fav is-letter', 'aria-hidden': 'true' }, (site || 'S').charAt(0).toUpperCase()),
					el('span', { className: 'pr-ue-serp__who' }, el('span', { className: 'pr-ue-serp__site' }, site), el('span', { className: 'pr-ue-serp__url' }, urlCrumbs(v.url)))),
				el('div', { className: 'pr-ue-serp__title' }, v.title || __('(no title)', 'publishrank')),
				el('div', { className: 'pr-ue-serp__desc' }, date ? el('span', { className: 'pr-ue-serp__date' }, date + ' — ') : null, v.desc || __('No meta description: Google will pick text from the page.', 'publishrank'))),
			el('div', { className: 'pr-ue-meters' },
				el(LengthBar, { label: __('Title width', 'publishrank'), value: v.title ? titlePx(v.title) : 0, min: 285, max: 600, unit: __('px', 'publishrank') }),
				el(LengthBar, { label: __('Description', 'publishrank'), value: v.desc.length, min: 120, max: 160, unit: __('characters', 'publishrank') })),
			props.compact ? null : previewNote(v));
	}
	function SocialCard(props) {
		var img = safeHttp(props.image), small = props.kind === 'x' && props.card === 'summary';
		return el('section', { className: 'pr-ue-sc pr-ue-sc--' + props.kind + (small ? ' is-small' : ''), 'aria-label': props.name },
			el('header', { className: 'pr-ue-sc__head' }, el('span', { className: 'pr-ue-sc__badge pr-ue-sc__badge--' + props.kind, 'aria-hidden': 'true' }, props.badge), el('strong', null, props.name)),
			el('div', { className: 'pr-ue-sc__frame' },
				el('div', { className: 'pr-ue-sc__media' + (img ? '' : ' is-empty') }, img ? el('img', { src: img, alt: '', loading: 'lazy' }) : el('span', null, __('No social image — set a featured image or a social image below.', 'publishrank'))),
				el('div', { className: 'pr-ue-sc__body' },
					el('div', { className: 'pr-ue-sc__host' }, props.kind === 'x' ? props.host : String(props.host).toUpperCase()),
					el('div', { className: 'pr-ue-sc__title' }, props.title || __('(no title)', 'publishrank')),
					props.desc ? el('div', { className: 'pr-ue-sc__desc' }, props.desc) : null)),
			el('div', { className: 'pr-ue-meters' },
				el(LengthBar, { label: __('Title', 'publishrank'), value: props.title.length, min: props.kind === 'x' ? 20 : 25, max: props.kind === 'x' ? 70 : 88, unit: __('characters', 'publishrank') }),
				el(LengthBar, { label: __('Description', 'publishrank'), value: props.desc.length, min: 50, max: 200, unit: __('characters', 'publishrank') })));
	}
	function SocialPreview() {
		var v = previewValues(usePreviewData()), host = urlHost(v.url) || urlHost(cfg.homeUrl);
		return el('div', { className: 'pr-ue-pv pr-ue-pv--social' },
			el(SocialCard, { kind: 'facebook', badge: 'f', name: __('Facebook, LinkedIn, WhatsApp & others', 'publishrank'), host: host, title: v.ogTitle, desc: v.ogDesc, image: v.ogImage }),
			el(SocialCard, { kind: 'x', badge: '𝕏', name: __('X (Twitter)', 'publishrank'), host: host, title: v.xTitle, desc: v.xDesc, image: v.xImage, card: v.xCard }),
			previewNote(v),
			el('div', { className: 'pr-ue-actions' },
				el(C.Button, { variant: 'secondary', onClick: function () { state.go('social', 'social'); window.setTimeout(function () { var f = document.querySelector('[data-pr-ue-slot="social"] input, [data-pr-ue-slot="social"] textarea'); if (f) { if (f.scrollIntoView) { f.scrollIntoView({ block: 'center' }); } f.focus(); } }, 300); } }, __('Edit social title, text & image', 'publishrank')),
				window.PublishRankLivePreview ? el(C.Button, { variant: 'tertiary', onClick: function () { window.PublishRankLivePreview.refresh(); } }, __('Refresh preview', 'publishrank')) : null));
	}

	/* ------------------------------------------------------------------ movable, minimisable window */
	// Optional: the same SEO editor can be popped out of the sidebar into a window the publisher can drag anywhere,
	// minimise to its title bar, resize, and dock back. Position and state are per-user editor preferences.
	// Only one copy of the editor is ever mounted (the sidebar shows a short note while the window is open).
	var FLOAT_W = 380, floatHost = null;
	function floatContainer() {
		if (!floatHost) { floatHost = document.createElement('div'); floatHost.id = 'publishrank-ue-float-root'; document.body.appendChild(floatHost); }
		return floatHost;
	}
	function clampPos(x, y, w) {
		var vw = window.innerWidth, vh = window.innerHeight, width = Math.min(w || FLOAT_W, vw);
		return { x: Math.round(Math.max(0, Math.min(x, vw - width))), y: Math.round(Math.max(40, Math.min(y, vh - 48))) };
	}
	function popOut() { setPref('floatMin', false); setPref('floating', true); closeSidebar(); }
	function dockBack() { setPref('floating', false); openSidebarDirect(); }
	function FloatingWindow() {
		var prefs = usePrefs();
		var ref = useRef(null), drag = useRef(null);
		var ps = useState(null), pos = ps[0], setPos = ps[1];
		var small = useViewportMatch ? useViewportMatch('small', '<') : window.innerWidth < 600;
		var floating = !!prefs.floating;
		useEffect(function () {
			if (!floating) { return; }
			var p = prefs.floatPos && typeof prefs.floatPos.x === 'number' ? prefs.floatPos : { x: window.innerWidth - FLOAT_W - 24, y: 110 };
			setPos(clampPos(p.x, p.y, FLOAT_W));
			function onResize() { setPos(function (c) { return c ? clampPos(c.x, c.y, ref.current ? ref.current.offsetWidth : FLOAT_W) : c; }); }
			window.addEventListener('resize', onResize);
			return function () { window.removeEventListener('resize', onResize); };
		}, [floating]);
		if (!floating || !wp.element.createPortal) { return null; }
		function width() { return ref.current ? ref.current.offsetWidth : FLOAT_W; }
		function down(e) {
			if (small || e.button !== 0 || !ref.current || (e.target.closest && e.target.closest('button, a, input, select, textarea'))) { return; }
			var r = ref.current.getBoundingClientRect();
			drag.current = { dx: e.clientX - r.left, dy: e.clientY - r.top, last: null };
			try { e.currentTarget.setPointerCapture(e.pointerId); } catch (er) {}
			e.preventDefault();
		}
		function move(e) { if (!drag.current) { return; } var np = clampPos(e.clientX - drag.current.dx, e.clientY - drag.current.dy, width()); drag.current.last = np; setPos(np); }
		function up() { if (!drag.current) { return; } var last = drag.current.last; drag.current = null; if (last) { setPref('floatPos', last); } }
		function keyMove(e) {
			var step = e.shiftKey ? 60 : 20, c = pos || { x: 0, y: 110 }, nx = c.x, ny = c.y;
			if (e.key === 'ArrowLeft') { nx -= step; } else if (e.key === 'ArrowRight') { nx += step; } else if (e.key === 'ArrowUp') { ny -= step; } else if (e.key === 'ArrowDown') { ny += step; } else { return; }
			e.preventDefault();
			var np = clampPos(nx, ny, width()); setPos(np); setPref('floatPos', np);
		}
		var min = !!prefs.floatMin;
		var style = small || !pos ? undefined : { left: pos.x + 'px', top: pos.y + 'px' };
		return wp.element.createPortal(
			el('div', { ref: ref, className: 'pr-ue-float' + (min ? ' is-min' : '') + (small ? ' is-sheet' : ''), style: style, role: 'dialog', 'aria-modal': 'false', 'aria-label': __('PublishRank SEO window', 'publishrank') },
				el('div', { className: 'pr-ue-float__head', onPointerDown: down, onPointerMove: move, onPointerUp: up, onPointerCancel: up, title: small ? undefined : __('Drag to move', 'publishrank') },
					el(Badge),
					el('strong', { className: 'pr-ue-float__title' }, __('PublishRank SEO', 'publishrank')),
					small ? null : el(C.Button, { className: 'pr-ue-float__move', icon: 'move', size: 'small', label: __('Move window: drag the title bar, or focus here and use the arrow keys', 'publishrank'), onKeyDown: keyMove }),
					el(C.Button, { icon: min ? 'arrow-up-alt2' : 'minus', size: 'small', label: min ? __('Restore window', 'publishrank') : __('Minimize window', 'publishrank'), 'aria-expanded': min ? 'false' : 'true', onClick: function () { setPref('floatMin', !min); } }),
					el(C.Button, { icon: 'align-pull-right', size: 'small', label: __('Dock in the right sidebar', 'publishrank'), onClick: dockBack }),
					el(C.Button, { icon: 'no-alt', size: 'small', label: __('Close window', 'publishrank'), onClick: function () { setPref('floating', false); } })),
				el('div', { className: 'pr-ue-float__body', hidden: min }, el(SidebarBody, { inFloat: true }))),
			floatContainer());
	}
	function SidebarContent() {
		var prefs = usePrefs();
		if (!prefs.floating) { return el(SidebarBody); }
		return el('div', { className: 'pr-ue-root is-' + (prefs.density || 'comfortable') },
			el('div', { className: 'pr-ue-note pr-ue-floatnote' },
				el('p', null, __('PublishRank SEO is open in a movable window. You can drag it anywhere, minimise it or dock it back here.', 'publishrank')),
				el('div', { className: 'pr-ue-actions' },
					el(C.Button, { variant: 'primary', onClick: dockBack }, __('Show it here instead', 'publishrank')),
					prefs.floatMin ? el(C.Button, { variant: 'secondary', onClick: function () { setPref('floatMin', false); } }, __('Restore the window', 'publishrank')) : null)));
	}

	/* ------------------------------------------------------------------ sidebar state */
	var state = { listeners: [], tab: null, open: null, go: function (tab, panel) { state.tab = tab; state.open = panel; openSidebar(); state.listeners.forEach(function (f) { f(tab, panel); }); } };
	function isOpenByDefault(p, prefs) {
		if (prefs.rememberSection && prefs.panelsOpen && prefs.panelsOpen.hasOwnProperty(p.id)) { return !!prefs.panelsOpen[p.id]; }
		if (prefs.expand === 'all') { return true; }
		if (prefs.expand === 'none') { return p.id === 'overview'; }
		return !!p.essential && (p.id === 'overview' || p.id === 'meta' || p.id === 'social-preview' || p.id === 'social' || p.id === 'schema-studio' || p.id === 'robots' || p.id === 'intel');
	}
	function Group(props) {
		var p = props.panel, prefs = props.prefs;
		var st = useState(isOpenByDefault(p, prefs)), open = st[0], setOpen = st[1];
		useEffect(function () {
			function on(tab, panel) { if (panel === p.id) { setOpen(true); } }
			state.listeners.push(on);
			return function () { state.listeners = state.listeners.filter(function (f) { return f !== on; }); };
		}, []);
		function toggle(next) {
			setOpen(next);
			if (prefs.rememberSection) { var o = Object.assign({}, getPref('panelsOpen') || {}); o[p.id] = next; setPref('panelsOpen', o); }
		}
		return el(C.PanelBody, { title: p.title, opened: open, onToggle: function () { toggle(!open); }, className: 'pr-ue-group pr-ue-group--' + p.id },
			open ? el(Fragment, null,
				p.render ? p.render() : null,
				p.parts ? el(Slot, { id: p.id, parts: p.parts, empty: p.empty }) : null,
				p.leftovers ? el(LeftoverSlot) : null) : null);
	}
	function Preferences(props) {
		var prefs = props.prefs;
		var panels = panelApi();
		var docOn = data.useSelect(function (select) {
			var ed = select('core/editor'), ep = select('core/edit-post');
			try { return ed && ed.isEditorPanelEnabled ? ed.isEditorPanelEnabled(DOC_FULL) : ep.isEditorPanelEnabled(DOC_FULL); } catch (e) { return true; }
		}, []);
		return el('div', { className: 'pr-ue-prefs', role: 'region', 'aria-label': __('PublishRank SEO editor preferences', 'publishrank') },
			el(C.ToggleControl, { __nextHasNoMarginBottom: true, label: __('Open PublishRank SEO in the right sidebar when the editor loads', 'publishrank'), help: __('It stays closed after you close it yourself, until you open it again.', 'publishrank'), checked: !!prefs.openOnLoad, onChange: function (v) { setPref('openOnLoad', v); } }),
			el(C.ToggleControl, { __nextHasNoMarginBottom: true, label: __('Use a movable window instead of the right sidebar', 'publishrank'), help: __('Drag it anywhere, minimise it to its title bar, resize it from the corner, or dock it back.', 'publishrank'), checked: !!prefs.floating, onChange: function (v) { if (v) { popOut(); } else { dockBack(); } } }),
			el(C.ToggleControl, { __nextHasNoMarginBottom: true, label: __('Show the PublishRank SEO panel in the Post tab', 'publishrank'), checked: !!docOn, onChange: function () { panels.toggleEnabled(DOC_FULL); } }),
			el(C.ToggleControl, { __nextHasNoMarginBottom: true, label: __('Remember the last tab and open groups', 'publishrank'), checked: !!prefs.rememberSection, onChange: function (v) { setPref('rememberSection', v); if (!v) { setPref('panelsOpen', {}); } } }),
			el(C.SelectControl, { __nextHasNoMarginBottom: true, label: __('Groups open by default', 'publishrank'), value: prefs.expand, options: [{ value: 'essential', label: __('Essential groups', 'publishrank') }, { value: 'all', label: __('All groups', 'publishrank') }, { value: 'none', label: __('Only the overview', 'publishrank') }], onChange: function (v) { setPref('expand', v); setPref('panelsOpen', {}); } }),
			el(C.SelectControl, { __nextHasNoMarginBottom: true, label: __('Density', 'publishrank'), value: prefs.density, options: [{ value: 'comfortable', label: __('Comfortable', 'publishrank') }, { value: 'compact', label: __('Compact', 'publishrank') }], onChange: function (v) { setPref('density', v); } }),
			el('div', { className: 'pr-ue-actions' },
				el(C.Button, { variant: 'secondary', isDestructive: true, onClick: function () { resetPrefs(); var n = notices(); if (n && n.createNotice) { n.createNotice('info', __('PublishRank SEO editor preferences were reset.', 'publishrank'), { type: 'snackbar', id: 'publishrank-prefs-reset' }); } } }, __('Reset editor preferences', 'publishrank')),
				el(C.Button, { variant: 'tertiary', onClick: props.onClose }, __('Done', 'publishrank'))));
	}
	function SaveState() {
		var s = useSaveStatus();
		if (s === 'idle') { return null; }
		var text = s === 'saving' ? __('Saving SEO fields…', 'publishrank') : (s === 'saved' ? __('SEO fields saved', 'publishrank') : __('SEO fields not saved', 'publishrank'));
		return el('span', { className: 'pr-ue-save is-' + s, role: 'status', 'aria-live': 'polite' }, text);
	}
	// Options-menu entry for the sidebar. WordPress hides the sidebar's own menu entry on small screens, so phones
	// and narrow windows get an equivalent item that opens the same sidebar (the publisher must always see it).
	var useViewportMatch = wp.compose && wp.compose.useViewportMatch;
	function EditorMenuItem() {
		var small = useViewportMatch ? useViewportMatch('medium', '<') : false;
		if (small || !PluginSidebarMoreMenuItem) {
			return PluginMoreMenuItem ? el(PluginMoreMenuItem, { icon: 'chart-line', onClick: openSidebar }, __('PublishRank SEO editor', 'publishrank')) : null;
		}
		return el(PluginSidebarMoreMenuItem, { target: SIDEBAR, icon: 'chart-line' }, __('PublishRank SEO editor', 'publishrank'));
	}

	function SidebarBody(props) {
		var prefs = usePrefs(), inFloat = !!(props && props.inFloat);
		var st = useState(false), showPrefs = st[0], setShowPrefs = st[1];
		var initial = prefs.rememberSection && prefs.lastTab ? prefs.lastTab : 'seo';
		if (state.tab) { initial = state.tab; }
		var ts = useState(initial), tab = ts[0], setTab = ts[1];
		var ks = useState(0), key = ks[0], setKey = ks[1];
		useEffect(function () {
			function on(t) { setTab(t); setKey(function (k) { return k + 1; }); }
			state.listeners.push(on);
			return function () { state.listeners = state.listeners.filter(function (f) { return f !== on; }); };
		}, []);
		return el('div', { className: 'pr-ue-root is-' + (prefs.density || 'comfortable') },
			el('div', { className: 'pr-ue-head' },
				el(Badge, { large: true }),
				el('div', { className: 'pr-ue-head__text' }, el('strong', null, __('Live SEO score', 'publishrank')), el(SaveState), el('span', { className: 'pr-ue-help' }, __('Tabs group every SEO setting for this item.', 'publishrank'))),
				inFloat ? null : el(C.Button, { icon: 'external', label: __('Open as a movable window', 'publishrank'), onClick: popOut }),
				el(C.Button, { icon: 'admin-generic', label: __('Editor preferences', 'publishrank'), isPressed: showPrefs, onClick: function () { setShowPrefs(!showPrefs); } })),
			showPrefs ? el(Preferences, { prefs: prefs, onClose: function () { setShowPrefs(false); } }) : null,
			el(C.TabPanel, {
				key: 'tabs-' + key,
				className: 'pr-ue-tabs',
				activeClass: 'is-active',
				initialTabName: tab,
				onSelect: function (name) { if (name !== tab) { setTab(name); } state.tab = name; if (getPref('rememberSection')) { setPref('lastTab', name); } },
				tabs: TABS.map(function (t) { return { name: t.name, title: t.title, className: 'pr-ue-tab' }; })
			}, function (t) {
				var def = TABS.filter(function (x) { return x.name === t.name; })[0] || TABS[0];
				return el('div', { className: 'pr-ue-tabpanel' }, def.panels.map(function (p) { return el(Group, { key: p.id, panel: p, prefs: prefs }); }));
			}));
	}

	/* ------------------------------------------------------------------ Post tab panel (native fields) */
	function DocPanel() {
		var meta = useMeta();
		var t = String(meta._publishrank_seo_title || ''), d = String(meta._publishrank_seo_description || ''), k = String(meta._publishrank_seo_focus_keyword || '');
		return el('div', { className: 'pr-ue-docpanel' },
			el('div', { className: 'pr-ue-docpanel__score' }, el(Badge, { large: true }), el('span', null, __('Live SEO score', 'publishrank'))),
			el(C.TextControl, { __nextHasNoMarginBottom: true, label: __('Focus keyword', 'publishrank'), value: k, onChange: function (v) { setMeta('_publishrank_seo_focus_keyword', v); } }),
			el(C.TextControl, { __nextHasNoMarginBottom: true, label: __('SEO title', 'publishrank'), help: sprintf(__('%d characters · blank = automatic', 'publishrank'), t.length), value: t, placeholder: '%%title%% %%sep%% %%sitename%%', onChange: function (v) { setMeta('_publishrank_seo_title', v); } }),
			el(C.TextareaControl, { __nextHasNoMarginBottom: true, label: __('Meta description', 'publishrank'), help: sprintf(__('%d characters · blank = automatic', 'publishrank'), d.length), value: d, rows: 3, onChange: function (v) { setMeta('_publishrank_seo_description', v); } }),
			el(GooglePreview, { compact: true }),
			el('div', { className: 'pr-ue-actions' },
				el(C.Button, { variant: 'primary', onClick: function () { state.go('seo', 'overview'); } }, __('Open full SEO editor', 'publishrank')),
				el(C.Button, { variant: 'secondary', icon: 'external', onClick: popOut }, __('Movable window', 'publishrank')),
				el(C.Button, { variant: 'tertiary', onClick: function () { state.go('schema', 'schema-studio'); } }, __('Schema', 'publishrank')),
				el(C.Button, { variant: 'tertiary', onClick: function () { state.go('social', 'social-preview'); } }, __('Social previews', 'publishrank'))));
	}

	/* ------------------------------------------------------------------ open/close behaviour */
	function Bridge() {
		useEffect(function () {
			var prev = null, ready = false;
			var unsub = data.subscribe(function () {
				var a = activeArea();
				if (!ready || a === prev) { prev = a; return; }
				if (prev === FULL && a !== FULL) { setPref('userClosed', true); }
				if (a === FULL && prev !== FULL) { setPref('userClosed', false); }
				prev = a;
			});
			var t = window.setTimeout(function () {
				prev = activeArea(); ready = true;
				if (!getPref('floating') && getPref('openOnLoad') && !getPref('userClosed') && window.innerWidth > 782 && prev !== FULL) { openSidebar(); prev = FULL; }
			}, 400);
			function onOpen() { openSidebar(); }
			function onKey(e) { if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'E' || e.key === 'e')) { e.preventDefault(); if (getPref('floating')) { setPref('floatMin', !getPref('floatMin')); } else if (activeArea() === FULL) { closeSidebar(); } else { openSidebar(); } } }
			document.addEventListener('publishrank:open-sidebar', onOpen);
			document.addEventListener('keydown', onKey);
			return function () { unsub(); window.clearTimeout(t); document.removeEventListener('publishrank:open-sidebar', onOpen); document.removeEventListener('keydown', onKey); };
		}, []);
		return null;
	}
	function Commands() {
		var uc = wp.commands.useCommand;
		uc({ name: 'publishrank/open-seo-editor', label: __('Open PublishRank SEO editor', 'publishrank'), callback: function (o) { openSidebar(); if (o && o.close) { o.close(); } } });
		uc({ name: 'publishrank/open-seo-panel', label: __('Open PublishRank SEO panel (Post tab)', 'publishrank'), callback: function (o) { openDocPanel(); if (o && o.close) { o.close(); } } });
		uc({ name: 'publishrank/open-schema', label: __('Open PublishRank Schema', 'publishrank'), callback: function (o) { state.go('schema', 'schema-studio'); if (o && o.close) { o.close(); } } });
		uc({ name: 'publishrank/open-social', label: __('Open PublishRank social previews', 'publishrank'), callback: function (o) { state.go('social', 'social-preview'); if (o && o.close) { o.close(); } } });
		return null;
	}

	/* Compatibility API used by the score card ("Fix" buttons) and other PublishRank scripts. */
	var legacy = window.PublishRankSeoDock || {};
	function focusField(selector) {
		var target = typeof selector === 'string' ? document.querySelector(selector) : selector;
		if (!target) { openSidebar(); return false; }
		var found = null;
		allPanels().forEach(function (x) { if (found) { return; } (x.panel.parts || []).forEach(function (s) { var n = resolvePart(s); if (!found && n && (n === target || n.contains(target))) { found = x; } }); });
		if (!found) { found = { tab: 'advanced', panel: { id: 'more' } }; }
		state.go(found.tab, found.panel.id);
		window.setTimeout(function () { var f = typeof selector === 'string' ? document.querySelector(selector) : target; if (f) { if (f.scrollIntoView) { f.scrollIntoView({ block: 'center' }); } if (f.focus) { f.focus(); } } }, 350);
		return true;
	}
	window.PublishRankSeoDock = Object.assign({}, legacy, { focusField: focusField, openSidebar: openSidebar, openFloat: popOut, isDocked: function () { return true; } });
	window.PublishRankSeoEditor = { popOut: popOut, dock: dockBack, go: function (tab, panel) { state.go(tab, panel); }, open: openSidebar, close: closeSidebar, openPanel: openDocPanel, save: function () { return bridgeSave(true); }, focusField: focusField, isDirty: function () { return bridgeDirty; }, preferences: { get: getPref, set: setPref, reset: resetPrefs } };

	wp.plugins.registerPlugin(PLUGIN, {
		icon: 'chart-line',
		render: function () {
			return el(Fragment, null,
				el(Bridge),
				wp.commands && wp.commands.useCommand ? el(Commands) : null,
				el(EditorMenuItem),
				PluginMoreMenuItem ? el(PluginMoreMenuItem, { icon: 'text-page', onClick: openDocPanel }, __('PublishRank SEO panel', 'publishrank')) : null,
				PluginMoreMenuItem ? el(PluginMoreMenuItem, { icon: 'admin-generic', onClick: function () { openSidebar(); window.setTimeout(function () { var b = document.querySelector('.pr-ue-head button[aria-label]'); if (b && b.getAttribute('aria-pressed') !== 'true') { b.click(); } }, 300); } }, __('PublishRank SEO preferences', 'publishrank')) : null,
				PluginDocumentSettingPanel ? el(PluginDocumentSettingPanel, { name: DOC_PANEL, title: __('PublishRank SEO', 'publishrank'), className: 'pr-ue-docpanel-wrap', icon: 'chart-line' }, el(DocPanel)) : null,
				PluginMoreMenuItem ? el(PluginMoreMenuItem, { icon: 'external', onClick: popOut }, __('PublishRank SEO movable window', 'publishrank')) : null,
				el(FloatingWindow),
				el(PluginSidebar, { name: SIDEBAR, title: __('PublishRank SEO', 'publishrank'), icon: el(Badge), className: 'pr-ue-sidebar pr-esc-sidebar' }, el(SidebarContent)));
		}
	});
})(window.wp, window.publishrankSeoEditor);
