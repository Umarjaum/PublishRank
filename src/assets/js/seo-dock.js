/* PublishRank SEO fields dock.
 *
 * Classic editor: server-rendered dock under the title; this file turns its sections into tabs.
 * Block editor: block-editor-sidebar.js hands over a slot and the SEO meta boxes are moved into the
 * PublishRank sidebar (and moved back when it closes, so nothing is ever lost or left unsaved).
 *
 * Values are never re-created: the original inputs (same name, id, nonce) are moved, so
 * PostMeta::save() receives the same POST data. On top of that this file
 *  - marks the block editor post as changed when a classic field changes (so Update saves them),
 *  - keeps a per-tab backup of unsaved values and restores it after a reload,
 *  - shows live length counters for the SEO title and meta description. */
(function ($) {
	'use strict';
	var cfg = window.publishrankSeoDock || {};
	var t = cfg.i18n || {};
	var BOX_IDS = cfg.boxIds || [];
	var isBlock = !!cfg.blockEditor || document.body.classList.contains('block-editor-page');
	var K_TAB = 'publishrank-seodock-tab', K_OPEN = 'publishrank-seodock-open';
	var DRAFT = 'publishrank-seodock-draft-' + (cfg.postId || 0);
	var OFF = 'pr-seodock-off';

	function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
	function emit(name, detail) { try { document.dispatchEvent(new CustomEvent(name, { detail: detail || {} })); } catch (e) {} }

	/* ---------- tabs ---------- */
	function partsFor(root, id, title) {
		var kids = Array.prototype.slice.call(root.children);
		var sections = kids.filter(function (n) { return n.classList && n.classList.contains('pr-seo-group'); });
		if (!sections.length) { return [{ key: id, label: title, nodes: [root] }]; }
		var first = kids.indexOf(sections[0]);
		var pre = kids.slice(0, first).filter(function (n) { return !(n.tagName === 'INPUT' && n.type === 'hidden'); });
		var out = [];
		if (pre.length) { out.push({ key: id + ':summary', label: t.summary || 'Published values', nodes: pre }); }
		sections.forEach(function (sec, i) {
			var h = sec.querySelector('h3');
			out.push({ key: id + ':' + i, label: h ? h.textContent.replace(/^\s*\d+\.\s*/, '').trim() : 'Section ' + (i + 1), nodes: [sec] });
		});
		return out;
	}

	function buildTabs(tabsEl, sources, onSelect) {
		var parts = [];
		sources.forEach(function (s) { parts = parts.concat(partsFor(s.root, s.id, s.title)); });
		tabsEl.replaceChildren();
		tabsEl.setAttribute('aria-label', t.tabs || 'SEO field sections');
		parts.forEach(function (p) {
			var b = document.createElement('button');
			b.type = 'button'; b.className = 'pr-seodock__tab'; b.setAttribute('role', 'tab'); b.setAttribute('data-pr-tab', p.key);
			b.textContent = p.label;
			p.button = b; tabsEl.appendChild(b);
		});
		var current = '';
		function show(key) {
			if (!parts.length) { return ''; }
			if (!parts.some(function (p) { return p.key === key; })) { key = parts[0].key; }
			current = key;
			parts.forEach(function (p) {
				var on = p.key === key;
				p.nodes.forEach(function (n) { n.classList.toggle(OFF, !on); });
				p.button.setAttribute('aria-selected', on ? 'true' : 'false');
				p.button.classList.toggle('is-active', on);
				p.button.tabIndex = on ? 0 : -1;
			});
			return key;
		}
		function reset() { parts.forEach(function (p) { p.nodes.forEach(function (n) { n.classList.remove(OFF); }); }); }
		function partFor(el) {
			for (var i = 0; i < parts.length; i++) {
				for (var j = 0; j < parts[i].nodes.length; j++) { if (parts[i].nodes[j] === el || parts[i].nodes[j].contains(el)) { return parts[i]; } }
			}
			return null;
		}
		tabsEl.addEventListener('click', function (e) {
			var b = e.target.closest('[data-pr-tab]'); if (!b) { return; }
			var key = b.getAttribute('data-pr-tab'), was = current;
			show(key); lsSet(K_TAB, key);
			if (onSelect) { onSelect(key, was === key); }
		});
		tabsEl.addEventListener('keydown', function (e) {
			if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
			var list = Array.prototype.slice.call(tabsEl.querySelectorAll('[data-pr-tab]')), i = list.indexOf(document.activeElement);
			if (i < 0) { return; }
			var n = list[(i + (e.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length];
			n.focus(); n.click(); e.preventDefault();
		});
		return { parts: parts, show: show, reset: reset, current: function () { return current; }, revealFor: function (el) { var p = partFor(el); if (p) { show(p.key); lsSet(K_TAB, p.key); } return !!p; } };
	}

	/* ---------- movable panel helpers ---------- */
	var FPOS = 'publishrank-seofloat-pos', FMIN = 'publishrank-seofloat-min', CPOS = 'publishrank-seodock-pos', CFLOAT = 'publishrank-seodock-float';
	function makeDraggable(host, handle, key, canDrag, onReset) {
		var drag = null;
		handle.addEventListener('pointerdown', function (e) {
			if (!canDrag() || window.innerWidth <= 782 || (e.button !== undefined && e.button !== 0) || e.target.closest('button,a,input,select,textarea')) { return; }
			var r = host.getBoundingClientRect(); drag = { x: e.clientX, y: e.clientY, l: r.left, t: r.top };
			if (handle.setPointerCapture) { handle.setPointerCapture(e.pointerId); }
			e.preventDefault();
		});
		handle.addEventListener('pointermove', function (e) {
			if (!drag) { return; }
			host.style.left = Math.max(0, Math.min(window.innerWidth - Math.min(host.offsetWidth, 120), drag.l + e.clientX - drag.x)) + 'px';
			host.style.top = Math.max(0, Math.min(window.innerHeight - 48, drag.t + e.clientY - drag.y)) + 'px';
			host.style.right = 'auto'; host.style.bottom = 'auto';
		});
		function end() { if (!drag) { return; } drag = null; host.setAttribute('data-pr-moved', '1'); var r = host.getBoundingClientRect(); lsSet(key, JSON.stringify({ left: Math.round(r.left), top: Math.round(r.top) })); }
		handle.addEventListener('pointerup', end); handle.addEventListener('pointercancel', end);
		handle.addEventListener('dblclick', function (e) { if (canDrag() && !e.target.closest('button,a')) { host.style.left = host.style.top = host.style.right = host.style.bottom = ''; host.removeAttribute('data-pr-moved'); lsSet(key, ''); if (onReset) { onReset(); } } });
	}
	function restorePos(host, key) {
		try {
			var p = JSON.parse(lsGet(key) || 'null');
			if (p && typeof p.left === 'number' && typeof p.top === 'number' && window.innerWidth > 782) {
				host.style.left = Math.max(0, Math.min(p.left, window.innerWidth - 120)) + 'px'; host.style.top = Math.max(0, Math.min(p.top, window.innerHeight - 60)) + 'px'; host.style.right = 'auto'; host.setAttribute('data-pr-moved', '1');
			}
		} catch (e) {}
	}

	/* ---------- classic editor dock ---------- */
	var classic = null;
	function initClassic() {
		var host = document.getElementById('publishrank-seo-dock');
		if (!host || isBlock) { return; }
		var tabsEl = host.querySelector('[data-pr-tabs]'), panes = host.querySelector('[data-pr-panes]'), toggle = host.querySelector('[data-pr-toggle]');
		var sources = Array.prototype.map.call(host.querySelectorAll('.pr-seodock__box'), function (el) {
			return { id: el.getAttribute('data-pr-box'), title: (el.getAttribute('data-pr-title') || '').replace(/^PublishRank\s+/i, ''), root: el };
		});
		if (!tabsEl || !panes || !sources.length) { return; }
		host.classList.add('is-enhanced');
		function setOpen(open) {
			panes.hidden = !open; host.classList.toggle('is-open', open);
			if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
			lsSet(K_OPEN, open ? '1' : '0');
		}
		var tabs = buildTabs(tabsEl, sources, function (key, same) { setOpen(same ? panes.hidden : true); });
		tabs.show(lsGet(K_TAB) || '');
		setOpen(lsGet(K_OPEN) === '1');
		if (toggle) { toggle.addEventListener('click', function () { setOpen(panes.hidden); }); }
		var pop = host.querySelector('[data-pr-pop]'), head = host.querySelector('.pr-seodock__head');
		function setFloat(on) {
			host.classList.toggle('is-floating', on); lsSet(CFLOAT, on ? '1' : '0');
			if (pop) { pop.setAttribute('aria-pressed', on ? 'true' : 'false'); }
			if (on) { setOpen(true); restorePos(host, CPOS); } else { host.style.left = host.style.top = host.style.right = host.style.bottom = ''; }
		}
		if (pop) { pop.addEventListener('click', function () { setFloat(!host.classList.contains('is-floating')); }); }
		if (head) { makeDraggable(host, head, CPOS, function () { return host.classList.contains('is-floating'); }); }
		if (lsGet(CFLOAT) === '1') { setFloat(true); }
		classic = { host: host, tabs: tabs, open: function () { setOpen(true); } };
	}

	/* ---------- block editor dock ---------- */
	var docked = null;
	function bind(s) {
		if (!s.formId) { return; }
		s.root.querySelectorAll('input, select, textarea').forEach(function (c) {
			if (!c.hasAttribute('form')) { c.setAttribute('form', s.formId); c.setAttribute('data-pr-bound', '1'); }
		});
	}
	function unbind(s) {
		s.root.querySelectorAll('[data-pr-bound]').forEach(function (c) { c.removeAttribute('form'); c.removeAttribute('data-pr-bound'); });
	}
	function dock(slot) {
		if (!isBlock || !slot) { return false; }
		if (docked) { release(docked.slot); }
		var sources = [];
		BOX_IDS.forEach(function (id) {
			var box = document.getElementById(id), root = box && box.querySelector('.inside');
			if (!root) { return; }
			var form = root.closest('form'), formId = '';
			if (form) { if (!form.id) { form.id = 'publishrank-metabox-' + ((form.className.match(/metabox-location-(\w+)/) || [])[1] || id); } formId = form.id; }
			var marker = document.createElement('span'); marker.className = 'pr-seodock-marker'; marker.hidden = true;
			root.parentNode.insertBefore(marker, root);
			var head = box.querySelector('.hndle, .postbox-header h2');
			sources.push({ id: id, title: (head ? head.textContent : id).replace(/^PublishRank\s+/i, '').trim(), root: root, marker: marker, box: box, formId: formId });
		});
		if (!sources.length) { return false; }
		var ui = document.createElement('div'); ui.className = 'pr-seodock pr-seodock--sidebar is-enhanced is-open';
		var tabsEl = document.createElement('div'); tabsEl.className = 'pr-seodock__tabs'; tabsEl.setAttribute('role', 'tablist');
		var panes = document.createElement('div'); panes.className = 'pr-seodock__panes';
		ui.appendChild(tabsEl); ui.appendChild(panes); slot.appendChild(ui);
		sources.forEach(function (s) {
			s.box.classList.add('pr-seodock-moved'); panes.appendChild(s.root); bind(s);
			s.observer = new MutationObserver(function () { bind(s); });
			s.observer.observe(s.root, { childList: true, subtree: true });
		});
		var tabs = buildTabs(tabsEl, sources);
		tabs.show(lsGet(K_TAB) || '');
		docked = { slot: slot, ui: ui, sources: sources, tabs: tabs };
		refreshCounters();
		emit('publishrank:seodock', { docked: true });
		return true;
	}
	function release(slot) {
		if (!docked || (slot && docked.slot !== slot)) { return; }
		var d = docked; docked = null;
		d.tabs.reset();
		d.sources.forEach(function (s) {
			if (s.observer) { s.observer.disconnect(); }
			unbind(s);
			s.root.classList.remove(OFF);
			if (s.marker.parentNode) { s.marker.parentNode.insertBefore(s.root, s.marker.nextSibling); s.marker.parentNode.removeChild(s.marker); }
			s.box.classList.remove('pr-seodock-moved');
		});
		if (d.ui.parentNode) { d.ui.parentNode.removeChild(d.ui); }
		emit('publishrank:seodock', { docked: false });
	}


	/* ---------- block editor: the fields live in the PublishRank sidebar ----------
	 * The old floating panel covered the article, so it is gone. While the PublishRank sidebar is open the
	 * fields are docked in it (block-editor-sidebar.js calls dock()); when it is closed they stay in the
	 * editor's own "Meta Boxes" area at the bottom of the screen. */
	function openSidebarDock() {
		if (docked) { return true; }
		emit('publishrank:open-sidebar');
		return true;
	}
	/* Make sure the block editor's bottom meta box area is expanded so a field there can be reached. */
	function revealMetaBoxArea(el) {
		var area = el && el.closest('.edit-post-meta-boxes-main, .editor-meta-boxes-main, .edit-post-layout__metaboxes');
		if (!area) { return; }
		var btn = area.querySelector('button[aria-expanded="false"]');
		if (btn) { btn.click(); }
		var box = el.closest('.postbox');
		if (box && box.classList.contains('closed')) { var h = box.querySelector('.handlediv, .postbox-header button[aria-expanded]'); if (h) { h.click(); } else { box.classList.remove('closed'); } }
	}

	/* ---------- jump to a field (used by the SEO analysis "Fix" buttons) ---------- */
	function focusField(selector) {
		var tries = 0;
		(function go() {
			var el = document.querySelector(selector);
			if (!el) { return; }
			if (isBlock && !docked) {
				if (tries === 0) { openSidebarDock(); }
				if (tries++ < 25) { window.setTimeout(go, 120); return; }
				revealMetaBoxArea(el);
			} else if (classic) { classic.tabs.revealFor(el); classic.open(); }
			if (docked) { docked.tabs.revealFor(el); }
			var reduce = false; try { reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
			el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
			try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
		}());
		return true;
	}

	/* ---------- length counters ---------- */
	var COUNTED = [['_publishrank_seo_title', 30, 60], ['_publishrank_seo_description', 120, 160]];
	function refreshCounters() {
		COUNTED.forEach(function (c) {
			var el = document.getElementById(c[0]); if (!el) { return; }
			var p = el.parentNode.querySelector('.pr-seodock-count[data-for="' + c[0] + '"]');
			if (!p) { p = document.createElement('p'); p.className = 'pr-seodock-count'; p.setAttribute('data-for', c[0]); el.insertAdjacentElement('afterend', p); }
			var n = el.value.length;
			if (!n) { p.textContent = t.auto || 'Automatic'; p.className = 'pr-seodock-count is-auto'; return; }
			var state = n > c[2] ? 'over' : (n < c[1] ? 'short' : 'ok');
			p.textContent = n + ' / ' + c[2] + ' ' + (t.chars || 'characters') + (state === 'over' ? ' — ' + (t.long || 'too long') : (state === 'short' ? ' — ' + (t.short || 'a little short') : ''));
			p.className = 'pr-seodock-count is-' + state;
		});
	}

	/* ---------- change tracking, backup and restore ---------- */
	function roots() { return document.querySelectorAll('.pr-seodock-root'); }
	function snapshot() {
		var out = {};
		roots().forEach(function (root) {
			root.querySelectorAll('input, textarea, select').forEach(function (el) {
				var name = el.name;
				if (!name || el.disabled || /^(hidden|button|submit|file|image|reset)$/.test(el.type) || /nonce|^_wp_http_referer$/.test(name)) { return; }
				if (el.type === 'checkbox' || el.type === 'radio') { out[name + '[' + el.value + ']'] = el.checked ? '1' : '0'; return; }
				if (el.multiple) { out[name] = Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; }).join('\u0001'); return; }
				out[name] = el.value;
			});
		});
		return out;
	}
	function apply(values) {
		var changed = 0;
		roots().forEach(function (root) {
			root.querySelectorAll('input, textarea, select').forEach(function (el) {
				var name = el.name; if (!name) { return; }
				var key = (el.type === 'checkbox' || el.type === 'radio') ? name + '[' + el.value + ']' : name;
				if (!Object.prototype.hasOwnProperty.call(values, key)) { return; }
				var v = values[key];
				if (el.type === 'checkbox' || el.type === 'radio') {
					if (el.checked !== (v === '1')) { el.checked = v === '1'; changed++; el.dispatchEvent(new Event('change', { bubbles: true })); }
				} else if (el.multiple) {
					var wanted = String(v).split('\u0001');
					Array.prototype.forEach.call(el.options, function (o) { o.selected = wanted.indexOf(o.value) > -1; });
				} else if (el.value !== v) {
					el.value = v; changed++;
					el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
				}
			});
		});
		return changed;
	}
	var base = null, baseStr = '', lastStr = '', timer = 0;
	function readDraft() { try { var d = JSON.parse(window.sessionStorage.getItem(DRAFT) || 'null'); return d && Date.now() - (d.t || 0) < 43200000 ? d : null; } catch (e) { return null; } }
	function writeDraft(cur) { try { window.sessionStorage.setItem(DRAFT, JSON.stringify({ base: base, cur: cur, t: Date.now() })); } catch (e) {} }
	function clearDraft() { try { window.sessionStorage.removeItem(DRAFT); } catch (e) {} }
	function markEditorDirty() {
		if (!isBlock || !window.wp || !wp.data || !cfg.touchKey) { return; }
		try { var d = wp.data.dispatch('core/editor'); if (d && d.editPost) { var m = {}; m[cfg.touchKey] = String(Date.now()); d.editPost({ meta: m }); } } catch (e) {}
	}
	function tick() {
		if (base === null) { return; }
		refreshCounters();
		var cur = snapshot(), str = JSON.stringify(cur);
		if (str === lastStr) { return; }
		lastStr = str;
		if (str === baseStr) { clearDraft(); } else { writeDraft(cur); markEditorDirty(); }
	}
	function schedule() { window.clearTimeout(timer); timer = window.setTimeout(tick, 200); }
	function rebase() { base = snapshot(); baseStr = JSON.stringify(base); lastStr = baseStr; clearDraft(); }
	function notice(text) {
		var n = document.createElement('div'); n.className = 'notice notice-warning inline pr-seodock-restored'; n.setAttribute('role', 'status');
		var p = document.createElement('p'); p.textContent = text; n.appendChild(p);
		var host = (docked && docked.ui) || (classic && classic.host);
		if (host) { host.insertBefore(n, host.firstChild); } else { return; }
		window.setTimeout(function () { if (n.parentNode) { n.parentNode.removeChild(n); } }, 15000);
	}
	function initTracking() {
		if (!roots().length) { return; }
		base = snapshot(); baseStr = JSON.stringify(base); lastStr = baseStr;
		var d = readDraft();
		if (d && d.base && JSON.stringify(d.base) === baseStr && d.cur) {
			if (apply(d.cur)) { lastStr = JSON.stringify(snapshot()); markEditorDirty(); notice(t.restored || ''); }
		} else if (d) { clearDraft(); }
		refreshCounters();
		document.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('.pr-seodock-root')) { schedule(); } }, true);
		document.addEventListener('change', function (e) { if (e.target.closest && e.target.closest('.pr-seodock-root')) { schedule(); } }, true);
		window.setInterval(function () { if (!document.hidden) { tick(); } }, 1000);
		if (isBlock) {
			var wasSaving = false;
			wp.data.subscribe(function () {
				var ed; try { ed = wp.data.select('core/editor'); } catch (e) { return; }
				if (!ed || !ed.isSavingPost) { return; }
				var saving = !!ed.isSavingPost() && !(ed.isAutosavingPost && ed.isAutosavingPost());
				if (wasSaving && !saving && ed.didPostSaveRequestSucceed && ed.didPostSaveRequestSucceed()) { afterSave(); }
				wasSaving = saving;
			});
		} else {
			$('#post').on('submit', function () { clearDraft(); base = null; });
		}
	}
	function afterSave() {
		var tries = 0;
		window.setTimeout(function wait() {
			var busy = false;
			try { var ep = wp.data.select('core/edit-post'); busy = !!(ep && ep.isSavingMetaBoxes && ep.isSavingMetaBoxes()); } catch (e) {}
			if (busy && tries++ < 40) { window.setTimeout(wait, 250); return; }
			rebase();
		}, 400);
	}

	window.PublishRankSeoDock = { dock: dock, release: release, openFloat: openSidebarDock, openSidebar: openSidebarDock, focusField: focusField, isDocked: function () { return !!docked || !!classic; } };

	$(function () {
		if (isBlock) {
			BOX_IDS.forEach(function (id) { var box = document.getElementById(id), inside = box && box.querySelector('.inside'); if (inside) { inside.classList.add('pr-seodock-root'); } });
		}
		initClassic();
		/* Let the block editor finish moving its meta box containers before the first snapshot. */
		window.setTimeout(initTracking, isBlock ? 700 : 150);
		if (isBlock) { try { window.localStorage.removeItem('publishrank-seofloat-pos'); window.localStorage.removeItem('publishrank-seofloat-min'); } catch (e) {} }
	});
}(jQuery));
