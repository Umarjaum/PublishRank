/* Traffic Guard screen: rule builder rows, operator filtering, list helpers, unsaved-change warnings. */
(function () {
	'use strict';
	var cfg = window.publishrankTG || {};
	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

	/* ---- Rule builder ---- */
	var list = $('[data-prtg-conds]');
	var tpl = document.getElementById('prtg-cond-template');
	var counter = list ? list.children.length + 100 : 0;

	function syncRow(row) {
		var field = $('[data-prtg-field]', row);
		var op = $('[data-prtg-op]', row);
		var value = $('[data-prtg-value]', row);
		if (!field || !op) { return; }
		var allowed = (cfg.fields || {})[field.value] || [];
		var current = op.value;
		op.innerHTML = '';
		allowed.forEach(function (k) {
			var o = document.createElement('option');
			o.value = k;
			o.textContent = (cfg.opNames || {})[k] || k;
			if (k === current) { o.selected = true; }
			op.appendChild(o);
		});
		if (value && cfg.hints) { value.placeholder = cfg.hints[field.value] || ''; }
	}
	function renumber() {
		if (!list) { return; }
		var rows = $$('[data-prtg-cond]', list);
		rows.forEach(function (row) {
			var btn = $('[data-prtg-remove]', row);
			if (btn) { btn.disabled = rows.length < 2; }
		});
	}
	if (list) {
		$$('[data-prtg-cond]', list).forEach(syncRow);
		list.addEventListener('change', function (e) {
			if (e.target.matches('[data-prtg-field]')) { syncRow(e.target.closest('[data-prtg-cond]')); }
		});
		list.addEventListener('click', function (e) {
			var b = e.target.closest('[data-prtg-remove]');
			if (!b) { return; }
			var row = b.closest('[data-prtg-cond]');
			var next = row.nextElementSibling || row.previousElementSibling;
			row.remove();
			renumber();
			markDirty(list.closest('form'));
			var f = next && $('[data-prtg-field]', next);
			if (f) { f.focus(); }
		});
		var add = $('[data-prtg-add]');
		if (add && tpl) {
			add.addEventListener('click', function () {
				var html = tpl.innerHTML.replace(/__i__/g, String(++counter));
				var wrap = document.createElement('ol');
				wrap.innerHTML = html.trim();
				var row = wrap.firstElementChild;
				list.appendChild(row);
				syncRow(row);
				renumber();
				markDirty(list.closest('form'));
				var f = $('[data-prtg-field]', row);
				if (f) { f.focus(); }
			});
		}
		renumber();
	}
	var action = $('[data-prtg-action]');
	var minutes = $('[data-prtg-minutes]');
	function syncAction() {
		if (!action || !minutes) { return; }
		var field = minutes.closest('.prx-field');
		if (field) { field.hidden = action.value !== 'temp_block'; }
	}
	if (action) { action.addEventListener('change', syncAction); syncAction(); }

	/* ---- Country mode panels ---- */
	var modes = $$('[data-prtg-cmode]');
	function syncMode() {
		var v = (modes.filter(function (m) { return m.checked; })[0] || {}).value || 'block';
		$$('[data-prtg-cmode-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-prtg-cmode-panel') !== v; });
	}
	modes.forEach(function (m) { m.addEventListener('change', syncMode); });
	if (modes.length) { syncMode(); }

	/* ---- Helpers that append to textareas ---- */
	function append(name, values) {
		var ta = document.querySelector('textarea[name="' + name + '"]');
		if (!ta) { return; }
		var have = ta.value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
		values.forEach(function (v) { if (have.indexOf(v) === -1) { have.push(v); } });
		ta.value = have.join('\n');
		ta.dispatchEvent(new Event('input', { bubbles: true }));
		ta.focus();
	}
	$$('[data-prtg-append]').forEach(function (b) {
		b.addEventListener('click', function () { append(b.getAttribute('data-prtg-append'), [b.getAttribute('data-value')]); b.disabled = true; });
	});
	var cf = $('[data-prtg-cloudflare]');
	if (cf) { cf.addEventListener('click', function () { append('trusted_proxies', cfg.cloudflare || []); }); }

	/* ---- Unsaved changes ---- */
	// Compare against a snapshot taken after every enhancement (country pickers, rows) has run, so
	// programmatic setup never counts as a change.
	var dirty = false;
	var snapshots = new WeakMap();
	function serialize(form) {
		try { return new URLSearchParams(new FormData(form)).toString(); } catch (e) { return ''; }
	}
	function markDirty(form) {
		if (!form || !form.hasAttribute('data-prtg-dirty')) { return; }
		var changed = snapshots.has(form) ? serialize(form) !== snapshots.get(form) : false;
		dirty = $$('form[data-prtg-dirty]').some(function (f) { return snapshots.has(f) && serialize(f) !== snapshots.get(f); });
		var note = $('[data-prtg-dirty-note]', form);
		if (note) { note.hidden = !changed; }
	}
	function snapshot() { $$('form[data-prtg-dirty]').forEach(function (f) { snapshots.set(f, serialize(f)); }); }
	if (document.readyState === 'complete') { setTimeout(snapshot, 60); } else { window.addEventListener('load', function () { setTimeout(snapshot, 60); }); }
	$$('form[data-prtg-dirty]').forEach(function (form) {
		form.addEventListener('input', function () { markDirty(form); });
		form.addEventListener('change', function () { markDirty(form); });
		form.addEventListener('submit', function () { dirty = false; snapshots.set(form, serialize(form)); });
	});
	window.addEventListener('beforeunload', function (e) {
		if (!dirty) { return; }
		e.preventDefault();
		e.returnValue = (cfg.i18n || {}).unsaved || '';
		return e.returnValue;
	});
}());
