/* PublishRank Sitemap Hub: instant content-type switches and copy-address button. No dependencies. */
(function () {
	'use strict';
	var cfg = window.publishrankSitemapHub;
	if (!cfg) { return; }
	var t = cfg.i18n || {};

	function toast(message, isError) {
		var box = document.querySelector('.pr-hub-wrap [data-pr-modules-toast]');
		if (!box) { return; }
		box.textContent = message;
		box.classList.toggle('is-error', !!isError);
		box.classList.add('is-visible');
		clearTimeout(box._prTimer);
		box._prTimer = setTimeout(function () { box.classList.remove('is-visible'); }, 2600);
	}

	function paint(row, on) {
		if (!row) { return; }
		row.classList.toggle('is-on', on);
		var state = row.querySelector('[data-pr-hub-state]');
		if (state) { state.textContent = on ? (t.on || 'Included') : (t.off || 'Not included'); }
	}

	function toggle(input) {
		var key = input.getAttribute('data-pr-sitemap-part'), on = input.checked, row = input.closest('[data-pr-hub-row]');
		paint(row, on);
		if (row) { row.classList.add('is-saving'); }
		input.disabled = true;
		var body = new URLSearchParams();
		body.append('action', cfg.action);
		body.append('nonce', cfg.nonce);
		body.append('part', key);
		body.append('enabled', on ? '1' : '0');
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) { throw new Error('rejected'); }
				var saved = !!res.data.enabled;
				input.checked = saved;
				paint(row, saved);
				document.querySelectorAll('[data-pr-hub-included]').forEach(function (el) {
					el.textContent = String(t.count || '%1$d of %2$d').replace('%1$d', res.data.included).replace('%2$d', res.data.total);
				});
				toast(String(saved ? t.enabled : t.disabled).replace('%s', input.getAttribute('data-label') || key));
			})
			.catch(function () {
				input.checked = !on;
				paint(row, !on);
				toast(t.failed || 'Could not save.', true);
			})
			.then(function () {
				input.disabled = false;
				if (row) { row.classList.remove('is-saving'); }
			});
	}

	document.addEventListener('change', function (e) {
		var input = e.target;
		if (input && input.matches && input.matches('[data-pr-sitemap-part]')) { toggle(input); }
	});

	var copy = document.querySelector('[data-pr-hub-copy]');
	var field = document.querySelector('[data-pr-hub-url]');
	if (copy && field) {
		copy.addEventListener('click', function () {
			function done() {
				copy.classList.add('is-done');
				toast(t.copied || 'Copied.');
				setTimeout(function () { copy.classList.remove('is-done'); }, 1800);
			}
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(field.value).then(done, function () { toast(t.copyFailed || 'Copy failed.', true); });
				return;
			}
			field.select();
			try { if (document.execCommand('copy')) { done(); return; } } catch (err) { /* fall through */ }
			toast(t.copyFailed || 'Copy failed.', true);
		});
	}
}());
