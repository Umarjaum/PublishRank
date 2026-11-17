/* Schema Library editor panel: add/remove blocks without a page reload. */
(function () {
	'use strict';
	var root = document.querySelector('[data-prx-sl]');
	if (!root) { return; }
	var tpl = {};
	try { tpl = JSON.parse(document.getElementById('prx-sl-templates').textContent); } catch (e) { return; }
	var max = 5;
	function reindex() {
		root.querySelectorAll('[data-prx-sl-entry]').forEach(function (fs, i) {
			fs.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace(/\[\d+\]|\[__i__\]/, '[' + i + ']'); });
			fs.querySelectorAll('[id]').forEach(function (el) { el.id = el.id.replace(/^prx-sl-(\d+|__i__)-/, 'prx-sl-' + i + '-'); });
			fs.querySelectorAll('label[for]').forEach(function (el) { el.htmlFor = el.htmlFor.replace(/^prx-sl-(\d+|__i__)-/, 'prx-sl-' + i + '-'); });
		});
		var add = document.querySelector('[data-prx-sl-add]');
		if (add) { add.disabled = root.querySelectorAll('[data-prx-sl-entry]').length >= max; }
	}
	document.addEventListener('click', function (e) {
		var a = e.target.closest('[data-prx-sl-add]');
		if (a) {
			var sel = document.querySelector('[data-prx-sl-type]');
			if (!sel.value || !tpl[sel.value] || root.querySelectorAll('[data-prx-sl-entry]').length >= max) { return; }
			root.insertAdjacentHTML('beforeend', tpl[sel.value]);
			reindex();
			var last = root.lastElementChild; if (last) { var f = last.querySelector('input,textarea,select:not([type=hidden])'); if (f) { f.focus(); } }
			sel.value = '';
		}
		var r = e.target.closest('[data-prx-sl-remove]');
		if (r) { r.closest('[data-prx-sl-entry]').remove(); reindex(); }
	});
	reindex();
})();
