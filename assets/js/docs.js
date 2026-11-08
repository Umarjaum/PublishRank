/* PublishRank Help & documentation: topic search and category filter. No dependencies. */
(function () {
	'use strict';
	var root = document.querySelector('.pr-docs');
	if (!root) { return; }
	var t = window.publishrankDocs || {};
	var search = root.querySelector('[data-pr-docs-search]');
	var chips = [].slice.call(root.querySelectorAll('[data-pr-docs-cat]'));
	var docs = [].slice.call(root.querySelectorAll('[data-pr-doc]'));
	var sections = [].slice.call(root.querySelectorAll('[data-pr-docs-section]'));
	var empty = root.querySelector('[data-pr-docs-empty]');
	var count = root.querySelector('[data-pr-docs-count]');
	var cat = 'all';

	function fmt(str, a, b) {
		return String(str).replace('%1$d', a).replace('%2$d', b).replace('%d', a);
	}

	function apply() {
		var q = search ? search.value.trim().toLowerCase() : '';
		var shown = 0;
		docs.forEach(function (doc) {
			var okCat = cat === 'all' || doc.getAttribute('data-cat') === cat;
			var text = doc.getAttribute('data-search') || '';
			var okText = !q || text.indexOf(q) > -1;
			var visible = okCat && okText;
			doc.hidden = !visible;
			if (visible) { shown++; }
			// Open the full explanation when the match is only inside it.
			var more = doc.querySelector('details');
			if (more) {
				var head = (doc.querySelector('.pr-doc__title') || {}).textContent + ' ' + (doc.querySelector('.pr-doc__summary') || {}).textContent;
				more.open = !!(q && visible && head.toLowerCase().indexOf(q) === -1);
			}
		});
		sections.forEach(function (sec) {
			sec.hidden = !sec.querySelector('[data-pr-doc]:not([hidden])');
		});
		if (empty) { empty.hidden = shown !== 0; }
		if (count) {
			count.textContent = shown === docs.length ? fmt(t.all || '%d topics', docs.length) : fmt(t.count || 'Showing %1$d of %2$d topics', shown, docs.length);
		}
	}

	chips.forEach(function (chip) {
		chip.addEventListener('click', function () {
			cat = chip.getAttribute('data-pr-docs-cat') || 'all';
			chips.forEach(function (c) {
				var on = c === chip;
				c.classList.toggle('is-active', on);
				c.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
			apply();
		});
	});
	if (search) {
		var timer;
		search.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(apply, 120);
		});
	}
	apply();
})();
