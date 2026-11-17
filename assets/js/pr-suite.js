/* PublishRank Suite UI behaviours (2.23). No dependencies. */
(function () {
	'use strict';
	var cfg = window.PRXConfig || {};
	var i18n = cfg.i18n || {};
	function t(key, fallback) { return i18n[key] || fallback; }
	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	/* REST helper */
	function api(path, opts) {
		opts = opts || {};
		var url = (cfg.root || '/wp-json/') + String(path).replace(/^\//, '');
		var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce || '', 'Accept': 'application/json' } };
		if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		return fetch(url, init).then(function (r) {
			return r.text().then(function (txt) {
				var data = null; try { data = txt ? JSON.parse(txt) : null; } catch (e) { data = { message: txt }; }
				if (!r.ok) { var err = new Error((data && data.message) || ('HTTP ' + r.status)); err.status = r.status; err.data = data; throw err; }
				return data;
			});
		});
	}

	/* Toasts */
	function toastHost() {
		var host = $('.prx-toasts');
		if (!host) { host = document.createElement('div'); host.className = 'prx-toasts'; host.setAttribute('role', 'status'); host.setAttribute('aria-live', 'polite'); document.body.appendChild(host); }
		return host;
	}
	function toast(message, o) {
		o = o || {};
		var el = document.createElement('div');
		el.className = 'prx-toast' + (o.type ? ' prx-toast--' + o.type : '');
		var span = document.createElement('span'); span.textContent = message; span.style.flex = '1'; el.appendChild(span);
		if (o.action) {
			var b = document.createElement('button'); b.type = 'button'; b.textContent = o.action.label;
			b.addEventListener('click', function () { try { o.action.fn(); } finally { el.remove(); } });
			el.appendChild(b);
		}
		var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', t('close', 'Close')); x.textContent = '×';
		x.addEventListener('click', function () { el.remove(); }); el.appendChild(x);
		toastHost().appendChild(el);
		setTimeout(function () { if (el.parentNode) { el.remove(); } }, o.timeout || 6000);
		return el;
	}

	/* Confirm dialog, optional type-to-confirm */
	function confirmDialog(o) {
		o = o || {};
		return new Promise(function (resolve) {
			var d = document.createElement('dialog'); d.className = 'prx-dialog prx';
			var body = document.createElement('div'); body.className = 'prx-dialog__body';
			var h = document.createElement('h3'); h.textContent = o.title || t('confirm', 'Are you sure?'); body.appendChild(h);
			if (o.body) { var p = document.createElement('p'); p.textContent = o.body; body.appendChild(p); }
			var input = null;
			if (o.typeToConfirm) {
				var l = document.createElement('label'); l.className = 'prx-field';
				var s = document.createElement('span'); s.textContent = (t('typeToConfirm', 'Type %s to confirm')).replace('%s', o.typeToConfirm); l.appendChild(s);
				input = document.createElement('input'); input.type = 'text'; input.className = 'prx-input'; input.autocomplete = 'off'; l.appendChild(input); body.appendChild(l);
			}
			var foot = document.createElement('div'); foot.className = 'prx-dialog__foot';
			var cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'prx-btn'; cancel.textContent = t('cancel', 'Cancel');
			var ok = document.createElement('button'); ok.type = 'button'; ok.className = 'prx-btn ' + (o.danger ? 'prx-btn--danger' : 'prx-btn--primary'); ok.textContent = o.confirmLabel || t('continue', 'Continue');
			if (input) { ok.disabled = true; input.addEventListener('input', function () { ok.disabled = input.value.trim() !== o.typeToConfirm; }); }
			foot.appendChild(cancel); foot.appendChild(ok); d.appendChild(body); d.appendChild(foot); document.body.appendChild(d);
			function close(v) { d.close(); d.remove(); resolve(v); }
			cancel.addEventListener('click', function () { close(false); });
			ok.addEventListener('click', function () { close(true); });
			d.addEventListener('cancel', function (e) { e.preventDefault(); close(false); });
			if (typeof d.showModal === 'function') { d.showModal(); } else { d.setAttribute('open', ''); }
			(input || ok).focus();
		});
	}

	/* Forms that need confirmation: data-prx-confirm="Message" [data-prx-type="DELETE"] [data-prx-danger] */
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f || !f.matches || !f.matches('form[data-prx-confirm]') || f.dataset.prxConfirmed === '1') { return; }
		e.preventDefault();
		confirmDialog({ title: f.dataset.prxConfirm, body: f.dataset.prxBody || '', typeToConfirm: f.dataset.prxType || '', danger: f.hasAttribute('data-prx-danger') }).then(function (yes) {
			if (yes) { f.dataset.prxConfirmed = '1'; if (f.requestSubmit) { f.requestSubmit(e.submitter || undefined); } else { f.submit(); } }
		});
	}, true);
	document.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('button[data-prx-confirm],a[data-prx-confirm]') : null;
		if (!b || b.dataset.prxConfirmed === '1') { return; }
		e.preventDefault();
		confirmDialog({ title: b.dataset.prxConfirm, body: b.dataset.prxBody || '', typeToConfirm: b.dataset.prxType || '', danger: b.hasAttribute('data-prx-danger') }).then(function (yes) {
			if (!yes) { return; }
			b.dataset.prxConfirmed = '1';
			if (b.tagName === 'A') { window.location.href = b.href; } else { b.click(); }
			setTimeout(function () { b.dataset.prxConfirmed = ''; }, 500);
		});
	});

	/* Tabs (keyboard: arrows, Home, End; selection kept in the URL hash) */
	function initTabs(root) {
		$$('[data-prx-tabs]', root).forEach(function (list) {
			var tabs = $$('[role="tab"]', list);
			function select(tab, focus) {
				tabs.forEach(function (x) {
					var on = x === tab; x.setAttribute('aria-selected', on ? 'true' : 'false'); x.tabIndex = on ? 0 : -1;
					var p = document.getElementById(x.getAttribute('aria-controls')); if (p) { p.hidden = !on; }
				});
				if (focus) { tab.focus(); }
				if (list.dataset.prxTabs === 'hash' && history.replaceState) { history.replaceState(null, '', '#' + tab.id.replace(/^tab-/, '')); }
				document.dispatchEvent(new CustomEvent('prx:tab', { detail: { id: tab.id } }));
			}
			tabs.forEach(function (tab, i) {
				tab.addEventListener('click', function (e) { e.preventDefault(); select(tab, false); });
				tab.addEventListener('keydown', function (e) {
					var k = e.key, n = null;
					if (k === 'ArrowRight' || k === 'ArrowDown') { n = tabs[(i + 1) % tabs.length]; }
					if (k === 'ArrowLeft' || k === 'ArrowUp') { n = tabs[(i - 1 + tabs.length) % tabs.length]; }
					if (k === 'Home') { n = tabs[0]; } if (k === 'End') { n = tabs[tabs.length - 1]; }
					if (n) { e.preventDefault(); select(n, true); }
				});
			});
			var initial = null;
			if (location.hash) { initial = document.getElementById('tab-' + location.hash.slice(1)); }
			if (!initial || tabs.indexOf(initial) < 0) { initial = tabs.filter(function (x) { return x.getAttribute('aria-selected') === 'true'; })[0] || tabs[0]; }
			if (initial) { select(initial, false); }
			if (list.dataset.prxTabs === 'hash') {
				window.addEventListener('hashchange', function () {
					var t2 = document.getElementById('tab-' + location.hash.slice(1));
					if (t2 && tabs.indexOf(t2) >= 0) { select(t2, false); }
				});
			}
		});
	}

	/* Selectable tables with shift-click ranges and a sticky bulk bar */
	function initSelect(root) {
		$$('[data-prx-select]', root).forEach(function (scope) {
			var bar = $('.prx-bulkbar', scope);
			var all = $('[data-prx-select-all]', scope);
			var last = null;
			function boxes() { return $$('input[type="checkbox"][data-prx-row]', scope); }
			function refresh() {
				var b = boxes(), n = 0;
				b.forEach(function (x) { var tr = x.closest('tr'); if (tr) { tr.classList.toggle('is-selected', x.checked); } if (x.checked) { n++; } });
				if (all) { all.checked = n > 0 && n === b.length; all.indeterminate = n > 0 && n < b.length; }
				if (bar) { bar.classList.toggle('is-visible', n > 0); var c = $('.prx-bulkbar__count', bar); if (c) { c.textContent = String(n); } }
			}
			scope.addEventListener('click', function (e) {
				var x = e.target;
				if (!(x instanceof HTMLInputElement) || !x.hasAttribute('data-prx-row')) { return; }
				var b = boxes();
				if (e.shiftKey && last && last !== x) {
					var a = b.indexOf(last), z = b.indexOf(x);
					if (a > -1 && z > -1) { var lo = Math.min(a, z), hi = Math.max(a, z); for (var i = lo; i <= hi; i++) { b[i].checked = x.checked; } }
				}
				last = x; refresh();
			});
			if (all) { all.addEventListener('change', function () { boxes().forEach(function (x) { x.checked = all.checked; }); refresh(); }); }
			scope.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') { boxes().forEach(function (x) { x.checked = false; }); refresh(); }
			});
			refresh();
		});
	}

	/* Copy buttons */
	document.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('[data-prx-copy]') : null;
		if (!b) { return; }
		e.preventDefault();
		var text = b.getAttribute('data-prx-copy');
		var src = text && text.charAt(0) === '#' ? document.querySelector(text) : null;
		if (src) { text = src.value !== undefined ? src.value : src.textContent; }
		(navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { toast(t('copied', 'Copied to clipboard'), { type: 'ok', timeout: 2500 }); }, function () {
			var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); toast(t('copied', 'Copied to clipboard'), { type: 'ok', timeout: 2500 }); } catch (err) {} ta.remove();
		});
	});

	/* Auto-submit filters */
	document.addEventListener('change', function (e) {
		var el = e.target;
		if (el && el.matches && el.matches('[data-prx-autosubmit]') && el.form) { el.form.submit(); }
	});

	/* Keyboard: "/" focuses the first search box */
	document.addEventListener('keydown', function (e) {
		if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) { return; }
		var tag = (document.activeElement && document.activeElement.tagName) || '';
		if (/INPUT|TEXTAREA|SELECT/.test(tag) || (document.activeElement && document.activeElement.isContentEditable)) { return; }
		var s = $('.prx input[type="search"]');
		if (s) { e.preventDefault(); s.focus(); }
	});

	/* SVG bar charts: <svg class="prx-chart" data-prx-chart='[{"l":"2026-11-01","v":3,"v2":1}]'> */
	function initCharts(root) {
		$$('svg[data-prx-chart]', root).forEach(function (svg) {
			var data = []; try { data = JSON.parse(svg.getAttribute('data-prx-chart') || '[]'); } catch (e) { data = []; }
			var w = svg.clientWidth || 600, h = svg.clientHeight || 160, pad = 18;
			svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
			while (svg.firstChild) { svg.removeChild(svg.firstChild); }
			var NS = 'http://www.w3.org/2000/svg';
			var max = 1; data.forEach(function (d) { max = Math.max(max, (+d.v || 0), (+d.v2 || 0)); });
			var bw = data.length ? (w - pad) / data.length : 0;
			var axis = document.createElementNS(NS, 'line'); axis.setAttribute('class', 'axis'); axis.setAttribute('x1', 0); axis.setAttribute('x2', w); axis.setAttribute('y1', h - pad); axis.setAttribute('y2', h - pad); svg.appendChild(axis);
			data.forEach(function (d, i) {
				var x = i * bw + 2, bh = Math.round(((+d.v || 0) / max) * (h - pad - 14));
				var r = document.createElementNS(NS, 'rect'); r.setAttribute('class', 'bar'); r.setAttribute('x', x); r.setAttribute('width', Math.max(1, (d.v2 !== undefined ? bw / 2 : bw) - 3)); r.setAttribute('y', h - pad - bh); r.setAttribute('height', bh);
				var tt = document.createElementNS(NS, 'title'); tt.textContent = d.l + ': ' + (d.v || 0) + (d.v2 !== undefined ? ' / ' + d.v2 : ''); r.appendChild(tt); svg.appendChild(r);
				if (d.v2 !== undefined) {
					var bh2 = Math.round(((+d.v2 || 0) / max) * (h - pad - 14));
					var r2 = document.createElementNS(NS, 'rect'); r2.setAttribute('class', 'bar2'); r2.setAttribute('x', x + bw / 2); r2.setAttribute('width', Math.max(1, bw / 2 - 3)); r2.setAttribute('y', h - pad - bh2); r2.setAttribute('height', bh2); svg.appendChild(r2);
				}
				if (data.length <= 14 || i % Math.ceil(data.length / 10) === 0) {
					var tx = document.createElementNS(NS, 'text'); tx.setAttribute('x', x); tx.setAttribute('y', h - 4); tx.textContent = String(d.l).slice(5); svg.appendChild(tx);
				}
			});
			if (!data.length) { var e2 = document.createElementNS(NS, 'text'); e2.setAttribute('x', 8); e2.setAttribute('y', h / 2); e2.textContent = t('noData', 'No data yet'); svg.appendChild(e2); }
		});
	}

	/* Drag-and-drop sortable lists with keyboard alternative (Alt+Arrow). Updates hidden inputs' order. */
	function initSortable(root) {
		$$('[data-prx-sortable]', root).forEach(function (list) {
			var dragging = null;
			function renumber() { $$(':scope > li', list).forEach(function (li, i) { $$('[data-prx-order]', li).forEach(function (inp) { inp.value = String(i); }); var n = $('[data-prx-pos]', li); if (n) { n.textContent = String(i + 1); } }); list.dispatchEvent(new CustomEvent('prx:sorted')); }
			list.addEventListener('dragstart', function (e) { var li = e.target.closest('li'); if (!li) { return; } dragging = li; li.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', ''); } catch (err) {} });
			list.addEventListener('dragend', function () { if (dragging) { dragging.classList.remove('is-dragging'); } $$('.is-over', list).forEach(function (x) { x.classList.remove('is-over'); }); dragging = null; renumber(); });
			list.addEventListener('dragover', function (e) {
				e.preventDefault(); var li = e.target.closest('li'); if (!li || !dragging || li === dragging) { return; }
				var rect = li.getBoundingClientRect(); var after = (e.clientY - rect.top) > rect.height / 2;
				list.insertBefore(dragging, after ? li.nextSibling : li);
			});
			list.addEventListener('keydown', function (e) {
				var li = e.target.closest('li'); if (!li || !e.altKey) { return; }
				if (e.key === 'ArrowUp' && li.previousElementSibling) { e.preventDefault(); list.insertBefore(li, li.previousElementSibling); }
				else if (e.key === 'ArrowDown' && li.nextElementSibling) { e.preventDefault(); list.insertBefore(li.nextElementSibling, li); }
				else { return; }
				e.target.focus(); renumber();
			});
			renumber();
		});
	}

	/* Show/hide blocks controlled by a select or checkbox: data-prx-show-when="name=value" */
	function initConditional(root) {
		var nodes = $$('[data-prx-show-when]', root);
		if (!nodes.length) { return; }
		function evaluate() {
			nodes.forEach(function (n) {
				var rule = n.getAttribute('data-prx-show-when').split('='), name = rule[0], want = (rule[1] || '').split('|');
				var fields = $$('[name="' + name + '"]'), val = '';
				fields.forEach(function (f) { if (f.type === 'checkbox' || f.type === 'radio') { if (f.checked) { val = f.value; } } else { val = f.value; } });
				n.hidden = want.indexOf(val) < 0;
			});
		}
		document.addEventListener('change', evaluate); evaluate();
	}

	/* "Select all" checkbox for a table: <input type=checkbox data-prx-check-all> in the header. */
	document.addEventListener('change', function (e) {
		var a = e.target; if (!a.matches || !a.matches('[data-prx-check-all]')) { return; }
		var t = a.closest('table'); if (!t) { return; }
		$$('tbody input[type=checkbox]', t).forEach(function (c) { if (!c.disabled) { c.checked = a.checked; } });
	});

	function init(root) { initTabs(root); initSelect(root); initCharts(root); initSortable(root); initConditional(root); }
	window.PRX = { api: api, toast: toast, confirm: confirmDialog, init: init, t: t };
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { init(document); }); } else { init(document); }
	window.addEventListener('resize', function () { clearTimeout(window.__prxR); window.__prxR = setTimeout(function () { initCharts(document); }, 200); });
})();
