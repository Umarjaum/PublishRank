/* PublishRank Private Login: remember when the Settings → Discussion notice is dismissed. */
(function () {
	'use strict';
	var cfg = window.publishrankPrivateLogin;
	if (!cfg) { return; }
	document.addEventListener('click', function (event) {
		var button = event.target && event.target.closest ? event.target.closest('[data-pr-plogin-notice] .notice-dismiss') : null;
		if (!button) { return; }
		var body = new FormData();
		body.append('action', cfg.action);
		body.append('_ajax_nonce', cfg.nonce);
		if (window.fetch) { window.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }); }
	});
}());
