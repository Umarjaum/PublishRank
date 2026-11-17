/* Newsroom Story Manager & Trash: selection count, shift-click ranges, sticky bulk bar. */
(function () {
	'use strict';
	document.querySelectorAll('[data-uj-bulkbar]').forEach(function (bar) {
		var form = bar.closest('form');
		var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-uj-pick]'));
		var count = bar.querySelector('[data-uj-count]');
		var apply = bar.querySelector('[data-uj-apply]');
		var last = null;
		function update() {
			var n = boxes.filter(function (b) { return b.checked; }).length;
			count.textContent = n + (n === 1 ? ' selected' : ' selected');
			apply.disabled = n === 0;
			bar.classList.toggle('is-active', n > 0);
		}
		boxes.forEach(function (b, i) {
			b.addEventListener('click', function (e) {
				if (e.shiftKey && last !== null) {
					var a = Math.min(last, i), z = Math.max(last, i);
					for (var k = a; k <= z; k++) { boxes[k].checked = b.checked; }
				}
				last = i; update();
			});
		});
		form.addEventListener('change', update);
		form.addEventListener('submit', function (e) {
			var sel = form.querySelector('select[name=bulk]');
			if (sel && sel.value === 'purge') {
				var c = form.querySelector('input[name=confirm]');
				if (!c || c.value !== 'DELETE') { e.preventDefault(); if (c) { c.focus(); c.setCustomValidity('Type DELETE to confirm'); c.reportValidity(); c.addEventListener('input', function () { c.setCustomValidity(''); }, { once: true }); } }
			}
		});
		update();
	});
})();
