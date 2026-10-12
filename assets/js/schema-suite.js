(function () {
	'use strict';

	var select = document.getElementById('_publishrank_seo_schema_type');
	if (!select) { return; }
	var panels = document.querySelectorAll('#publishrank-schema .pr-schema-panel');
	function togglePanels() {
		panels.forEach(function (panel) {
			var types = (panel.getAttribute('data-types') || '').split(',');
			panel.style.display = types.indexOf(select.value) > -1 ? 'block' : 'none';
		});
	}
	select.addEventListener('change', togglePanels);
	togglePanels();
}());
