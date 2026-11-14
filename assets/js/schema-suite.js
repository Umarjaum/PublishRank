(function () {
	'use strict';

	var select = document.getElementById('_publishrank_seo_schema_type');
	var panels = document.querySelectorAll('.publishrank-schema-suite .pr-schema-panel');
	function togglePanels() {
		if (!select) { return; }
		for (var i = 0; i < panels.length; i++) {
			var types = (panels[i].getAttribute('data-types') || '').split(',');
			panels[i].style.display = types.indexOf(select.value) > -1 ? 'block' : 'none';
		}
	}
	if (select) {
		select.addEventListener('change', togglePanels);
		togglePanels();
	}

	var faq = document.querySelector('[data-faq-repeater]');
	if (!faq) { return; }
	var add = faq.parentNode.querySelector('[data-faq-add]');
	var count = faq.parentNode.querySelector('[data-faq-count]');
	var max = parseInt(faq.getAttribute('data-max') || '20', 10);
	var next = parseInt(faq.getAttribute('data-next-index') || '0', 10);

	function rows() { return faq.querySelectorAll('[data-faq-row]'); }
	function refresh() {
		var current = rows().length;
		if (count) { count.textContent = current + ' of ' + max + ' question slots'; }
		if (add) {
			add.disabled = current >= max;
			add.setAttribute('aria-disabled', current >= max ? 'true' : 'false');
		}
	}
	function addRow() {
		if (rows().length >= max) { return; }
		var index = next++;
		var number = rows().length + 1;
		var row = document.createElement('p');
		row.className = 'publishrank-faq-row';
		row.setAttribute('data-faq-row', '');
		row.innerHTML = '<label><strong data-faq-label>Question ' + number + '</strong><br><input class="widefat" type="text" name="publishrank_faq_items[' + index + '][q]"></label><br><textarea class="widefat" rows="2" name="publishrank_faq_items[' + index + '][a]" placeholder="Answer"></textarea><button type="button" class="button-link-delete publishrank-faq-remove" data-faq-remove>Remove question</button>';
		faq.appendChild(row);
		var input = row.querySelector('input');
		if (input) { input.focus(); }
		refresh();
	}
	function removeRow(button) {
		var row = button.parentNode;
		while (row && !row.hasAttribute('data-faq-row')) { row = row.parentNode; }
		if (row && row.parentNode === faq) { faq.removeChild(row); }
		var current = rows();
		for (var i = 0; i < current.length; i++) {
			var label = current[i].querySelector('[data-faq-label]');
			if (label) { label.textContent = 'Question ' + (i + 1); }
		}
		refresh();
	}
	if (add) { add.addEventListener('click', addRow); }
	faq.addEventListener('click', function (event) {
		var target = event.target;
		while (target && target !== faq && !target.hasAttribute('data-faq-remove')) { target = target.parentNode; }
		if (target && target !== faq) { removeRow(target); }
	});
	refresh();
}());
