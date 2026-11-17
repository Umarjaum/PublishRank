(function () {
	'use strict';
	var table = document.getElementById('pr21-nav-table');
	if (!table) { return; }
	var max = (window.prxNav && window.prxNav.max) || 12;
	var body = table.tBodies[0];
	var form = table.closest('form');
	var count = document.getElementById('pr21-nav-count');
	var addRow = document.getElementById('pr21-nav-addrow');
	var full = document.getElementById('pr21-nav-full');
	function rows() { return Array.prototype.slice.call(body.querySelectorAll('tr[data-nav-row]')); }
	function kept() { return rows().filter(function (r) { var c = r.querySelector('input[type=checkbox][name$="[remove]"]'); return !(c && c.checked); }); }
	function suggested() { return form.querySelectorAll('input[name="nav_suggest[]"]:checked').length; }
	function refresh() {
		rows().forEach(function (r, i) {
			r.querySelectorAll('input,select').forEach(function (el) { if (el.name) { el.name = el.name.replace(/^nav\[\d+\]/, 'nav[' + i + ']'); } });
			var up = r.querySelector('[data-move="-1"]'), dn = r.querySelector('[data-move="1"]');
			if (up) { up.disabled = i === 0; } if (dn) { dn.disabled = i === rows().length - 1; }
		});
		var add = form.querySelector('select[name="nav_add_id"]');
		var n = kept().length + (add && add.value !== '0' && add.value !== '' ? 1 : 0) + suggested();
		if (count) { count.textContent = n + ' / ' + max; count.classList.toggle('is-bad', n > max); }
		var atLimit = kept().length + suggested() >= max;
		if (add) { add.disabled = atLimit && (add.value === '0' || add.value === ''); }
		var role = form.querySelector('select[name="nav_add_role"]'); if (role) { role.disabled = add ? add.disabled : false; }
		form.querySelectorAll('input[name="nav_suggest[]"]:not(:checked)').forEach(function (c) { c.disabled = kept().length + suggested() + (add && add.value !== '0' && add.value !== '' ? 1 : 0) >= max; });
		if (full) { full.hidden = !atLimit; }
		var btn = form.querySelector('button[type=submit]'); if (btn) { btn.disabled = n > max; }
	}
	body.addEventListener('click', function (e) {
		var b = e.target.closest('[data-move]'); if (!b) { return; }
		var tr = b.closest('tr'); var d = parseInt(b.dataset.move, 10);
		if (d < 0 && tr.previousElementSibling && tr.previousElementSibling.hasAttribute('data-nav-row')) { body.insertBefore(tr, tr.previousElementSibling); }
		if (d > 0 && tr.nextElementSibling) { body.insertBefore(tr.nextElementSibling, tr); }
		b.focus(); refresh();
	});
	form.addEventListener('change', refresh);
	refresh();
})();
