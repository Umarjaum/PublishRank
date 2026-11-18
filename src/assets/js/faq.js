/* PublishRank FAQ front end: single-open accordions, expand/collapse all and question search. No dependencies. */
(function () {
	'use strict';
	function init(root) {
		if (root.dataset.faqReady) { return; }
		root.dataset.faqReady = '1';
		var items = Array.prototype.slice.call(root.querySelectorAll('details.publishrank-faq__item'));
		if (root.getAttribute('data-single') === '1') {
			items.forEach(function (d) {
				d.addEventListener('toggle', function () {
					if (!d.open) { return; }
					items.forEach(function (o) { if (o !== d) { o.open = false; } });
				});
			});
		}
		var exp = root.querySelector('[data-faq-expand]');
		var col = root.querySelector('[data-faq-collapse]');
		if (exp) { exp.addEventListener('click', function () { items.forEach(function (d) { if (!d.hidden) { d.open = true; } }); }); }
		if (col) { col.addEventListener('click', function () { items.forEach(function (d) { d.open = false; }); }); }
		var search = root.querySelector('[data-faq-search]');
		var status = root.querySelector('[data-faq-status]');
		if (search) {
			search.addEventListener('input', function () {
				var q = search.value.trim().toLowerCase();
				var shown = 0;
				items.forEach(function (d) {
					var match = !q || d.textContent.toLowerCase().indexOf(q) > -1;
					d.hidden = !match;
					if (match) { shown++; if (q) { d.open = true; } }
				});
				if (status) { status.textContent = q ? shown + ' / ' + items.length : ''; }
			});
		}
		// Open the question addressed by the URL fragment.
		if (location.hash) {
			try {
				var target = root.querySelector(location.hash);
				if (target && target.tagName === 'DETAILS') { target.open = true; }
			} catch (e) { /* invalid selector */ }
		}
	}
	function run() { Array.prototype.forEach.call(document.querySelectorAll('.publishrank-faq'), init); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
}());
