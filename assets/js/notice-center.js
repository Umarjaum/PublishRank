/* PublishRank Notice Center: minimize, expand, snooze — saved per user. */
(function () {
	'use strict';
	var tray = document.getElementById('publishrank-notice-center');
	if (!tray) { return; }
	var headerEnd = document.querySelector('.wp-header-end');
	if (headerEnd && headerEnd.parentNode && !tray.contains(headerEnd)) { headerEnd.parentNode.insertBefore(tray, headerEnd.nextSibling); tray.classList.add('is-docked'); }
	var list = tray.querySelector('.prn-list');
	var empty = tray.querySelector('.prn-empty');
	function save(op, keys, severity) {
		var body = new URLSearchParams();
		body.set('action', 'publishrank_notice_state');
		body.set('nonce', tray.dataset.nonce);
		body.set('op', op);
		if (keys) { body.set('keys', keys.join(',')); }
		if (severity) { body.set('severity', severity); }
		return fetch(tray.dataset.ajax, { method: 'POST', credentials: 'same-origin', body: body }).catch(function () {});
	}
	function items() { return Array.prototype.slice.call(tray.querySelectorAll('.prn-item')); }
	function visible() { return items().filter(function (i) { return !i.hidden; }); }
	function refresh() {
		var n = visible().length;
		var c = tray.querySelector('[data-count]'); if (c) { c.textContent = String(n); }
		if (empty) { empty.hidden = n > 0; }
		var snoozed = items().length - n;
		var b = tray.querySelector('[data-all="unsnooze"]');
		if (b) { b.hidden = snoozed === 0; b.textContent = b.textContent.replace(/\d+/, String(snoozed)); }
	}
	function setMin(item, min) {
		item.classList.toggle('is-min', min);
		var t = item.querySelector('.prn-toggle'); if (t) { t.setAttribute('aria-expanded', min ? 'false' : 'true'); }
		var body = item.querySelector('.prn-body'); if (body) { body.hidden = min; }
	}
	tray.addEventListener('click', function (e) {
		var t = e.target.closest('button'); if (!t || !tray.contains(t)) { return; }
		if (t.classList.contains('prn-bar__toggle')) {
			var open = t.getAttribute('aria-expanded') !== 'true';
			t.setAttribute('aria-expanded', open ? 'true' : 'false'); list.hidden = !open;
			save(open ? 'tray_open' : 'tray_closed'); return;
		}
		var item = t.closest('.prn-item');
		if (t.classList.contains('prn-toggle') && item) {
			var min = !item.classList.contains('is-min');
			setMin(item, min); save(min ? 'min' : 'open', [item.dataset.key]); return;
		}
		if (t.dataset.act === 'snooze' && item) {
			item.hidden = true; item.dataset.snoozed = '1';
			save('snooze', [item.dataset.key], item.dataset.severity); refresh();
			var next = visible()[0]; if (next) { next.querySelector('.prn-toggle').focus(); } else { tray.querySelector('.prn-bar__toggle').focus(); }
			return;
		}
		if (t.dataset.all === 'min' || t.dataset.all === 'open') {
			var m = t.dataset.all === 'min';
			var keys = visible().map(function (i) { setMin(i, m); return i.dataset.key; });
			if (list.hidden) { list.hidden = false; tray.querySelector('.prn-bar__toggle').setAttribute('aria-expanded', 'true'); }
			save(m ? 'min' : 'open', keys); return;
		}
		if (t.dataset.all === 'unsnooze') {
			items().forEach(function (i) { i.hidden = false; delete i.dataset.snoozed; });
			save('unsnooze'); refresh();
		}
	});
	// A notice whose own "dismiss" removes it from the page leaves an empty row; tidy it.
	new MutationObserver(function () {
		items().forEach(function (i) {
			var body = i.querySelector('.prn-body');
			if (body && !body.textContent.trim() && !body.querySelector('form,input,button,img')) { i.remove(); }
		});
		refresh();
	}).observe(list, { childList: true, subtree: true });
})();
