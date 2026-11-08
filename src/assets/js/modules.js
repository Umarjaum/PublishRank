/* PublishRank Features (formerly SEO Modules): instant on/off switches, filter and search. No dependencies. */
(function () {
	'use strict';
	var cfg = window.publishrankModules;
	if (!cfg) { return; }
	var t = cfg.i18n || {};

	function fmt(str, a, b) { return String(str || '').replace('%1$d', a).replace('%2$d', b).replace('%s', a); }

	function toast(root, message, isError) {
		var box = root.querySelector('[data-pr-modules-toast]') || document.querySelector('[data-pr-modules-toast]');
		if (!box) { return; }
		box.textContent = message;
		box.classList.toggle('is-error', !!isError);
		box.classList.add('is-visible');
		clearTimeout(box._prTimer);
		box._prTimer = setTimeout(function () { box.classList.remove('is-visible'); }, 2600);
	}

	function updateCounts(active, total) {
		document.querySelectorAll('[data-pr-modules-count]').forEach(function (el) {
			el.textContent = fmt(t.count || '%1$d of %2$d on', active, total || el.getAttribute('data-total'));
		});
	}

	function paint(card, on) {
		if (!card) { return; }
		card.classList.toggle('is-on', on);
		var state = card.querySelector('[data-pr-module-state]');
		if (state) { state.textContent = on ? (t.on || 'On') : (t.off || 'Off'); }
	}

	function syncTwins(key, on, source) {
		document.querySelectorAll('[data-pr-module="' + key + '"]').forEach(function (input) {
			if (input !== source) { input.checked = on; }
			paint(input.closest('[data-pr-module-card]'), on);
		});
	}

	function toggle(input) {
		var key = input.getAttribute('data-pr-module'), on = input.checked, card = input.closest('[data-pr-module-card]');
		var root = input.closest('.pr-modules-wrap, .pr-modules-dash') || document;
		paint(card, on);
		if (card) { card.classList.add('is-saving'); }
		input.disabled = true;
		var body = new URLSearchParams();
		body.append('action', cfg.action);
		body.append('nonce', cfg.nonce);
		body.append('module', key);
		body.append('enabled', on ? '1' : '0');
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) { throw new Error('rejected'); }
				var saved = !!res.data.enabled;
				syncTwins(key, saved, input);
				input.checked = saved;
				updateCounts(res.data.active, res.data.total);
				toast(root, fmt(saved ? t.enabled : t.disabled, input.getAttribute('data-label') || key));
			})
			.catch(function () {
				input.checked = !on;
				paint(card, !on);
				toast(root, t.failed || 'Could not save.', true);
			})
			.then(function () {
				input.disabled = false;
				if (card) { card.classList.remove('is-saving'); }
			});
	}

	document.addEventListener('change', function (e) {
		var input = e.target;
		if (input && input.matches && input.matches('[data-pr-module]')) { toggle(input); }
	});

	/* Filter + search on the full modules screen. */
	var filter = 'all', search = document.querySelector('[data-pr-modules-search]');
	function applyFilter() {
		var q = search ? search.value.trim().toLowerCase() : '', shown = 0;
		document.querySelectorAll('.pr-modules-wrap [data-pr-module-card]').forEach(function (card) {
			var on = card.classList.contains('is-on');
			var ok = (filter === 'all' || (filter === 'on' && on) || (filter === 'off' && !on)) && (!q || (card.getAttribute('data-search') || '').indexOf(q) !== -1);
			card.hidden = !ok;
			if (ok) { shown++; }
		});
		var empty = document.querySelector('[data-pr-modules-empty]');
		if (empty) { empty.hidden = shown !== 0; }
	}
	document.querySelectorAll('[data-pr-modules-filter]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			filter = btn.getAttribute('data-pr-modules-filter');
			document.querySelectorAll('[data-pr-modules-filter]').forEach(function (b) {
				var active = b === btn;
				b.classList.toggle('is-active', active);
				b.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
			applyFilter();
		});
	});
	if (search) { search.addEventListener('input', applyFilter); }
}());
