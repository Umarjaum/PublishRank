/* PublishRank movable SEO editor window.
 *
 * One floating window that holds the SEO fields, the live previews (Google desktop/mobile, Facebook &
 * LinkedIn, X, Mastodon, meta tags, JSON-LD, checks) and the SEO analysis. It works in the block editor
 * and the classic editor and can be:
 *  - dragged anywhere by its header (mouse, touch or pen), kept inside the screen, snapping to edges;
 *  - resized from the corner handle;
 *  - moved and resized from the keyboard (focus the header: Arrow keys move, Shift+Arrow moves faster,
 *    Alt+Arrow resizes, Home re-centres, Escape minimizes);
 *  - snapped to the left or right edge as a full-height panel, minimized to a bar, or docked back.
 * Position, size, open state and the chosen tab are remembered per browser.
 *
 * Nothing is copied: the original fields and preview nodes are moved in (with a marker so they go back
 * exactly where they came from), and inputs stay attached to their original form, so saving the post
 * stores the same values as before. Dispatches `publishrank:seowin` ({open}) whenever it opens or closes. */
(function () {
	'use strict';
	var cfg = window.publishrankSeoWindow || {};
	var t = cfg.i18n || {};
	var isBlock = !!cfg.blockEditor || document.body.classList.contains('block-editor-page');
	var K_RECT = 'publishrank-seowin-rect', K_OPEN = 'publishrank-seowin-open', K_TAB = 'publishrank-seowin-tab', K_MIN = 'publishrank-seowin-min', K_SNAP = 'publishrank-seowin-snap';
	var MIN_W = 320, MIN_H = 220, SNAP = 18, MOBILE = 782;

	function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { if (v === null) { window.localStorage.removeItem(k); } else { window.localStorage.setItem(k, v); } } catch (e) {} }
	function emit(name, detail) { try { document.dispatchEvent(new CustomEvent(name, { detail: detail || {} })); } catch (e) {} }
	function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
	function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
	function mobile() { return window.innerWidth <= MOBILE; }
	function topOffset() {
		var bar = document.getElementById('wpadminbar');
		return bar && window.getComputedStyle(bar).position === 'fixed' ? bar.offsetHeight : 0;
	}

	/* ---------- moving existing nodes in and out (with form binding) ---------- */
	function moveIn(node, pane) {
		if (!node || !pane) { return null; }
		var form = node.closest('form'), formId = '';
		if (form) { if (!form.id) { form.id = 'publishrank-seowin-form-' + Math.random().toString(36).slice(2, 8); } formId = form.id; }
		var marker = document.createComment('publishrank-seowin');
		node.parentNode.insertBefore(marker, node);
		pane.appendChild(node);
		function bind() {
			if (!formId) { return; }
			node.querySelectorAll('input, select, textarea, button').forEach(function (c) {
				if (!c.hasAttribute('form')) { c.setAttribute('form', formId); c.setAttribute('data-pr-seowin-bound', '1'); }
			});
		}
		bind();
		var observer = formId && !pane.closest('form') ? new MutationObserver(bind) : null;
		if (observer) { observer.observe(node, { childList: true, subtree: true }); }
		return {
			restore: function () {
				if (observer) { observer.disconnect(); }
				node.querySelectorAll('[data-pr-seowin-bound]').forEach(function (c) { c.removeAttribute('form'); c.removeAttribute('data-pr-seowin-bound'); });
				if (marker.parentNode) { marker.parentNode.insertBefore(node, marker); marker.parentNode.removeChild(marker); }
			}
		};
	}

	/* ---------- window ---------- */
	var win = null, moved = {}, current = '';
	var TABS = [
		{ key: 'fields', label: t.fields || 'SEO fields', icon: 'dashicons-edit' },
		{ key: 'preview', label: t.preview || 'Previews', icon: 'dashicons-visibility' },
		{ key: 'analysis', label: t.analysis || 'Analysis', icon: 'dashicons-chart-line' }
	];

	function build() {
		var w = el('section', 'pr-seowin');
		w.id = 'pr-seowin';
		w.setAttribute('role', 'dialog');
		w.setAttribute('aria-modal', 'false');
		w.setAttribute('aria-labelledby', 'pr-seowin-title');
		w.hidden = true;
		// Only the body pane scrolls. focus()/scrollIntoView() on a field could otherwise scroll the window
		// itself and push its title bar (the drag handle and close button) out of view.
		w.addEventListener('scroll', function () { if (w.scrollTop || w.scrollLeft) { w.scrollTop = 0; w.scrollLeft = 0; } });

		var head = el('header', 'pr-seowin__head');
		head.tabIndex = 0;
		head.setAttribute('data-pr-seowin-drag', '');
		head.setAttribute('aria-roledescription', t.draggable || 'movable window header');
		head.setAttribute('aria-describedby', 'pr-seowin-help');
		head.title = t.dragHelp || 'Drag to move. Double-click to reset.';
		var grip = el('span', 'pr-seowin__grip'); grip.setAttribute('aria-hidden', 'true');
		var badge = el('span', 'pr-seowin__badge'); badge.setAttribute('data-pr-seowin-score', ''); badge.textContent = '–';
		var title = el('strong', 'pr-seowin__title', t.title || 'SEO editor'); title.id = 'pr-seowin-title';
		var tools = el('div', 'pr-seowin__tools');
		function tool(action, label, icon) {
			var b = el('button', 'pr-seowin__tool'); b.type = 'button'; b.setAttribute('data-pr-seowin-action', action);
			b.setAttribute('aria-label', label); b.title = label;
			var i = el('span', 'dashicons ' + icon); i.setAttribute('aria-hidden', 'true'); b.appendChild(i);
			tools.appendChild(b); return b;
		}
		tool('snap-left', t.snapLeft || 'Snap to the left edge', 'dashicons-align-pull-left');
		tool('center', t.center || 'Centre the window', 'dashicons-align-center');
		tool('snap-right', t.snapRight || 'Snap to the right edge', 'dashicons-align-pull-right');
		tool('minimize', t.minimize || 'Minimize', 'dashicons-minus');
		tool('close', t.close || 'Dock the SEO editor back', 'dashicons-no-alt');
		head.appendChild(grip); head.appendChild(badge); head.appendChild(title); head.appendChild(tools);

		var help = el('p', 'screen-reader-text', t.keyboardHelp || 'Use the arrow keys to move this window, Shift and an arrow key to move it faster, Alt and an arrow key to resize it, Home to centre it and Escape to minimize it.');
		help.id = 'pr-seowin-help';

		var tabs = el('div', 'pr-seowin__tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', t.tabs || 'SEO editor sections');
		var body = el('div', 'pr-seowin__body');
		var panes = {};
		TABS.forEach(function (tab) {
			var b = el('button', 'pr-seowin__tab'); b.type = 'button'; b.setAttribute('role', 'tab'); b.id = 'pr-seowin-tab-' + tab.key;
			b.setAttribute('data-pr-seowin-tab', tab.key); b.setAttribute('aria-controls', 'pr-seowin-pane-' + tab.key);
			var i = el('span', 'dashicons ' + tab.icon); i.setAttribute('aria-hidden', 'true'); b.appendChild(i); b.appendChild(document.createTextNode(' ' + tab.label));
			tabs.appendChild(b);
			var p = el('div', 'pr-seowin__pane'); p.id = 'pr-seowin-pane-' + tab.key; p.setAttribute('role', 'tabpanel'); p.setAttribute('aria-labelledby', b.id); p.hidden = true;
			body.appendChild(p); panes[tab.key] = p;
		});
		var resize = el('span', 'pr-seowin__resize'); resize.setAttribute('aria-hidden', 'true'); resize.title = t.resize || 'Drag to resize';

		w.appendChild(head); w.appendChild(help); w.appendChild(tabs); w.appendChild(body); w.appendChild(resize);
		/* Classic editor: live inside the post form so every moved field still submits with it. */
		var host = (!isBlock && document.getElementById('post')) || document.body;
		host.appendChild(w);
		return { root: w, head: head, tabs: tabs, body: body, panes: panes, resize: resize, badge: badge };
	}

	function note(pane, text, actionLabel, onAction) {
		pane.replaceChildren();
		var box = el('div', 'pr-seowin__note');
		box.appendChild(el('p', '', text));
		if (actionLabel) { var b = el('button', 'button'); b.type = 'button'; b.textContent = actionLabel; b.addEventListener('click', onAction); box.appendChild(b); }
		pane.appendChild(box);
	}

	function fillFields() {
		var pane = win.panes.fields;
		if (isBlock) {
			var api = window.PublishRankSeoDock;
			if (api && api.dock(pane)) { moved.fields = { restore: function () { api.release(pane); } }; return; }
			note(pane, t.noFields || 'The SEO fields are not available for this post type.');
			return;
		}
		var dock = document.getElementById('publishrank-seo-dock');
		if (!dock) { note(pane, t.noFields || 'The SEO fields are not available for this post type.'); return; }
		dock.classList.remove('is-floating'); dock.style.left = dock.style.top = dock.style.right = dock.style.bottom = '';
		dock.classList.add('is-in-window', 'is-open');
		var panes = dock.querySelector('[data-pr-panes]'); if (panes) { panes.hidden = false; }
		var h = moveIn(dock, pane);
		moved.fields = { restore: function () { dock.classList.remove('is-in-window'); h.restore(); } };
	}
	function fillPreview() {
		var pane = win.panes.preview, root = document.getElementById('pr-preview');
		if (!root) { note(pane, t.noPreview || 'Live previews are switched off for this post type.'); return; }
		moved.preview = moveIn(root, pane);
		var refresh = root.querySelector('[data-pr-pv-refresh]'); if (refresh) { window.setTimeout(function () { refresh.click(); }, 50); }
	}
	function fillAnalysis() {
		var pane = win.panes.analysis, card = window.PublishRankScoreCard;
		if (!card) { note(pane, t.noAnalysis || 'The SEO analysis is not available for this post type.'); return; }
		if (card.mode() === 'floating') {
			note(pane, t.analysisFloating || 'The SEO analysis is open as its own floating panel.', t.moveHere || 'Move it into this window', function () { card.setMode('docked'); pane.replaceChildren(); card.dock(pane); moved.analysis = { restore: function () { card.release(pane); } }; });
			return;
		}
		card.dock(pane);
		moved.analysis = { restore: function () { card.release(pane); } };
	}

	function show(key) {
		if (!TABS.some(function (x) { return x.key === key; })) { key = 'fields'; }
		current = key; lsSet(K_TAB, key);
		TABS.forEach(function (tab) {
			var on = tab.key === key, b = win.tabs.querySelector('[data-pr-seowin-tab="' + tab.key + '"]');
			win.panes[tab.key].hidden = !on;
			b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; b.classList.toggle('is-active', on);
		});
	}

	/* ---------- geometry ---------- */
	function defaultRect() {
		var w = Math.min(560, window.innerWidth - 40), h = Math.min(Math.round(window.innerHeight * 0.72), 720);
		return { left: Math.round((window.innerWidth - w) / 2), top: Math.max(topOffset() + 16, Math.round((window.innerHeight - h) / 3)), width: w, height: h };
	}
	function readRect() {
		try { var r = JSON.parse(lsGet(K_RECT) || 'null'); if (r && typeof r.left === 'number' && typeof r.top === 'number') { return r; } } catch (e) {}
		return defaultRect();
	}
	function apply(r, save) {
		var top = topOffset();
		var w = clamp(r.width || MIN_W, MIN_W, Math.max(MIN_W, window.innerWidth - 8));
		var h = clamp(r.height || MIN_H, MIN_H, Math.max(MIN_H, window.innerHeight - top - 8));
		var left = clamp(r.left, 0, Math.max(0, window.innerWidth - Math.min(w, 140)));
		var tp = clamp(r.top, top, Math.max(top, window.innerHeight - 48));
		/* Keep the whole window on screen: shrink it to the space below its top edge, then move it up if needed. */
		if (!win.root.classList.contains('is-min') && tp + h > window.innerHeight - 4) {
			h = Math.max(MIN_H, window.innerHeight - 4 - tp);
			if (tp + h > window.innerHeight - 4) { tp = Math.max(top, window.innerHeight - 4 - h); }
		}
		var s = win.root.style;
		s.left = left + 'px'; s.top = tp + 'px'; s.width = w + 'px'; s.height = win.root.classList.contains('is-min') ? '' : h + 'px';
		win.rect = { left: left, top: tp, width: w, height: h };
		if (save) { lsSet(K_RECT, JSON.stringify(win.rect)); }
	}
	function snap(side) {
		var top = topOffset(), w = Math.min(460, Math.max(MIN_W, Math.round(window.innerWidth * 0.34)));
		setMin(false);
		if (side === 'center') { apply(defaultRect(), true); lsSet(K_SNAP, null); win.root.removeAttribute('data-snap'); return; }
		apply({ left: side === 'left' ? 0 : window.innerWidth - w, top: top, width: w, height: window.innerHeight - top }, true);
		win.root.setAttribute('data-snap', side); lsSet(K_SNAP, side);
	}
	function setMin(on) {
		win.root.classList.toggle('is-min', on); lsSet(K_MIN, on ? '1' : '0');
		var b = win.root.querySelector('[data-pr-seowin-action="minimize"]');
		if (b) { var label = on ? (t.restore || 'Restore') : (t.minimize || 'Minimize'); b.setAttribute('aria-label', label); b.title = label; b.setAttribute('aria-expanded', on ? 'false' : 'true'); }
		if (win.rect) { apply(win.rect, false); }
	}

	function pointerDrag(handle, onMove, onEnd, canStart) {
		var st = null;
		handle.addEventListener('pointerdown', function (e) {
			if ((e.button !== undefined && e.button !== 0) || (canStart && !canStart(e))) { return; }
			st = { x: e.clientX, y: e.clientY, r: { left: win.rect.left, top: win.rect.top, width: win.rect.width, height: win.rect.height } };
			if (handle.setPointerCapture) { try { handle.setPointerCapture(e.pointerId); } catch (err) {} }
			win.root.classList.add('is-dragging'); e.preventDefault();
		});
		handle.addEventListener('pointermove', function (e) { if (st) { onMove(st, e.clientX - st.x, e.clientY - st.y); } });
		function end() { if (!st) { return; } st = null; win.root.classList.remove('is-dragging'); onEnd(); }
		handle.addEventListener('pointerup', end); handle.addEventListener('pointercancel', end);
	}

	function wire() {
		pointerDrag(win.head, function (st, dx, dy) {
			var r = { left: st.r.left + dx, top: st.r.top + dy, width: st.r.width, height: st.r.height };
			if (Math.abs(r.left) < SNAP) { r.left = 0; }
			if (Math.abs(window.innerWidth - (r.left + r.width)) < SNAP) { r.left = window.innerWidth - r.width; }
			if (Math.abs(r.top - topOffset()) < SNAP) { r.top = topOffset(); }
			win.root.removeAttribute('data-snap'); lsSet(K_SNAP, null);
			apply(r, false);
		}, function () { apply(win.rect, true); }, function (e) { return !mobile() && !e.target.closest('button, a, input, select, textarea'); });

		pointerDrag(win.resize, function (st, dx, dy) {
			apply({ left: st.r.left, top: st.r.top, width: st.r.width + dx, height: st.r.height + dy }, false);
		}, function () { apply(win.rect, true); }, function () { return !mobile() && !win.root.classList.contains('is-min'); });

		win.head.addEventListener('dblclick', function (e) { if (!e.target.closest('button')) { snap('center'); } });
		win.head.addEventListener('keydown', function (e) {
			if (e.target !== win.head) { return; }
			var step = e.shiftKey ? 64 : 16, r = { left: win.rect.left, top: win.rect.top, width: win.rect.width, height: win.rect.height }, used = true;
			if (e.altKey) {
				if (e.key === 'ArrowRight') { r.width += step; } else if (e.key === 'ArrowLeft') { r.width -= step; } else if (e.key === 'ArrowDown') { r.height += step; } else if (e.key === 'ArrowUp') { r.height -= step; } else { used = false; }
			} else if (e.key === 'ArrowRight') { r.left += step; } else if (e.key === 'ArrowLeft') { r.left -= step; } else if (e.key === 'ArrowDown') { r.top += step; } else if (e.key === 'ArrowUp') { r.top -= step; }
			else if (e.key === 'Home') { snap('center'); e.preventDefault(); return; }
			else if (e.key === 'Escape') { setMin(!win.root.classList.contains('is-min')); e.preventDefault(); return; }
			else { used = false; }
			if (used) { e.preventDefault(); win.root.removeAttribute('data-snap'); apply(r, true); }
		});
		win.root.addEventListener('click', function (e) {
			var a = e.target.closest('[data-pr-seowin-action]');
			if (a) {
				e.preventDefault();
				var act = a.getAttribute('data-pr-seowin-action');
				if (act === 'close') { close(); } else if (act === 'minimize') { setMin(!win.root.classList.contains('is-min')); } else if (act === 'snap-left') { snap('left'); } else if (act === 'snap-right') { snap('right'); } else if (act === 'center') { snap('center'); }
				return;
			}
			var tb = e.target.closest('[data-pr-seowin-tab]');
			if (tb) { e.preventDefault(); show(tb.getAttribute('data-pr-seowin-tab')); if (win.root.classList.contains('is-min')) { setMin(false); } }
		});
		win.tabs.addEventListener('keydown', function (e) {
			if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
			var list = Array.prototype.slice.call(win.tabs.querySelectorAll('[data-pr-seowin-tab]')), i = list.indexOf(document.activeElement);
			if (i < 0) { return; }
			var n = list[(i + (e.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length]; n.focus(); n.click(); e.preventDefault();
		});
		window.addEventListener('resize', function () {
			if (!win || win.root.hidden) { return; }
			var s = win.root.getAttribute('data-snap');
			if (s === 'left' || s === 'right') { snap(s); } else { apply(win.rect, false); }
		});
		document.addEventListener('publishrank:score', function (e) {
			if (!e.detail) { return; }
			var n = parseInt(e.detail.score, 10) || 0;
			win.badge.textContent = String(n); win.badge.className = 'pr-seowin__badge is-' + (e.detail.band || 'bad');
			win.badge.setAttribute('aria-label', String(t.scoreAria || 'SEO score %d out of 100').replace('%d', n));
		});
	}

	/* ---------- open / close ---------- */
	function open(tab) {
		if (!win) { win = build(); wire(); }
		if (!win.root.hidden) { if (tab) { show(tab); } return; }
		win.root.hidden = false;
		fillFields(); fillPreview(); fillAnalysis();
		show(tab || lsGet(K_TAB) || 'fields');
		var c = window.PublishRankScoreCard; if (c) { var s = c.score(); emit('publishrank:score', s); }
		win.root.classList.toggle('is-min', lsGet(K_MIN) === '1');
		var sn = lsGet(K_SNAP);
		if (sn === 'left' || sn === 'right') { win.rect = readRect(); snap(sn); } else { apply(readRect(), false); }
		lsSet(K_OPEN, '1');
		document.body.classList.add('pr-seowin-open');
		emit('publishrank:seowin', { open: true });
		window.requestAnimationFrame(function () { win.head.focus(); });
	}
	function close() {
		if (!win || win.root.hidden) { return; }
		['analysis', 'preview', 'fields'].forEach(function (k) { if (moved[k]) { moved[k].restore(); moved[k] = null; } });
		TABS.forEach(function (tab) { win.panes[tab.key].replaceChildren(); });
		win.root.hidden = true;
		lsSet(K_OPEN, '0');
		document.body.classList.remove('pr-seowin-open');
		emit('publishrank:seowin', { open: false });
	}

	window.PublishRankSeoWindow = {
		open: open,
		close: close,
		toggle: function (tab) { if (win && !win.root.hidden) { close(); } else { open(tab); } },
		isOpen: function () { return !!(win && !win.root.hidden); },
		focusTab: function (tab) { open(tab); }
	};

	/* Classic editor: a launcher in the SEO fields header. Block editor: the sidebar adds its own button. */
	function classicLauncher() {
		var head = document.querySelector('#publishrank-seo-dock .pr-seodock__head');
		if (!head || head.querySelector('[data-pr-seowin-open]')) { return; }
		var b = el('button', 'pr-seodock__pop pr-seodock__win'); b.type = 'button'; b.setAttribute('data-pr-seowin-open', '');
		b.title = t.open || 'Open the movable SEO editor with previews';
		var i = el('span', 'dashicons dashicons-external'); i.setAttribute('aria-hidden', 'true'); b.appendChild(i);
		b.appendChild(el('span', 'screen-reader-text', t.open || 'Open the movable SEO editor with previews'));
		b.addEventListener('click', function () { open(); });
		var pop = head.querySelector('[data-pr-pop]'); head.insertBefore(b, pop || null);
	}
	document.addEventListener('click', function (e) { var o = e.target.closest && e.target.closest('[data-pr-seowin-open]'); if (o && !o.closest('#publishrank-seo-dock .pr-seodock__head')) { e.preventDefault(); open(o.getAttribute('data-pr-seowin-open') || ''); } });
	document.addEventListener('keydown', function (e) {
		/* Ctrl/Cmd + Shift + E toggles the window from anywhere in the editor. */
		if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'E' || e.key === 'e')) { e.preventDefault(); window.PublishRankSeoWindow.toggle(); }
	});

	function boot() {
		if (!isBlock) { classicLauncher(); }
		if (lsGet(K_OPEN) === '1') { window.setTimeout(function () { open(); }, isBlock ? 1500 : 300); }
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
