/* PublishRank Setup wizard: step track animation, unsaved-changes guard, live Organization/Person
 * switch, image thumbnails and save feedback. No dependencies; the media picker itself is in admin.js. */
(function () {
	'use strict';
	var root = document.querySelector('[data-prw-wizard]');
	if (!root) { return; }
	var t = window.publishrankWizard || {};
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ---------- step track: animate the fill from where the previous step left it ---------- */
	var track = root.querySelector('[data-prw-progress]');
	if (track) {
		var target = parseFloat(track.getAttribute('data-prw-progress')) || 0;
		var previous = null;
		try { previous = window.sessionStorage.getItem('prwProgress'); } catch (e) { previous = null; }
		if (!reduced && previous !== null && Math.abs(parseFloat(previous) - target) > 0.001) {
			track.style.setProperty('--prw-progress', String(parseFloat(previous) || 0));
			// Two frames: the first paints the old value, the second starts the transition.
			window.requestAnimationFrame(function () {
				window.requestAnimationFrame(function () { track.style.setProperty('--prw-progress', String(target)); });
			});
		}
		try { window.sessionStorage.setItem('prwProgress', String(target)); } catch (e) { /* storage disabled */ }
	}

	/* ---------- focus ---------- */
	var summary = root.querySelector('.prw-validation-summary');
	if (summary) {
		summary.focus({ preventScroll: false });
	}

	/* ---------- save feedback toast ---------- */
	var toast = document.querySelector('[data-prw-toast]');
	if (toast) {
		window.setTimeout(function () {
			toast.classList.add('is-leaving');
			window.setTimeout(function () { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
		}, 3200);
	}

	var form = root.querySelector('[data-prw-form]');
	if (!form) { return; }

	/* ---------- Organization / Person: show the matching fields right away ---------- */
	function applyIdentity(value) {
		var person = 'person' === value;
		root.querySelectorAll('[data-prw-for]').forEach(function (field) {
			var on = field.getAttribute('data-prw-for') === (person ? 'person' : 'org');
			field.hidden = !on;
			field.querySelectorAll('input,select,textarea,button').forEach(function (el) { el.disabled = !on; });
		});
	}
	form.querySelectorAll('[data-prw-identity]').forEach(function (radio) {
		radio.addEventListener('change', function () { if (radio.checked) { applyIdentity(radio.value); } });
	});

	/* ---------- Local business: dim the details while the schema is off ---------- */
	var localToggle = form.querySelector('[data-prw-local-toggle]');
	var localFields = form.querySelector('[data-prw-local-fields]');
	if (localToggle && localFields) {
		var syncLocal = function () { localFields.classList.toggle('is-off', !localToggle.checked); };
		localToggle.addEventListener('change', syncLocal);
		syncLocal();
	}

	/* ---------- image thumbnails follow the URL field ---------- */
	form.querySelectorAll('[data-prw-media-input]').forEach(function (input) {
		var thumb = input.closest('.prw-media') ? input.closest('.prw-media').querySelector('[data-prw-thumb]') : null;
		if (!thumb) { return; }
		var timer = 0;
		function update() {
			var url = (input.value || '').trim();
			var img = thumb.querySelector('img');
			if (!/^https?:\/\//i.test(url)) {
				if (img) { img.remove(); }
				thumb.classList.add('is-empty');
				return;
			}
			if (!img) {
				img = document.createElement('img');
				img.alt = '';
				img.addEventListener('error', function () { img.remove(); thumb.classList.add('is-empty'); });
				thumb.insertBefore(img, thumb.firstChild);
			}
			if (img.getAttribute('src') !== url) { img.src = url; }
			thumb.classList.remove('is-empty');
		}
		input.addEventListener('input', function () { window.clearTimeout(timer); timer = window.setTimeout(update, 250); });
		input.addEventListener('change', update);
	});

	/* ---------- unsaved changes (same behaviour as General Settings) ---------- */
	var bar = root.querySelector('[data-prw-actions]');
	var statusText = root.querySelector('[data-prw-status-text]');
	var statusIcon = root.querySelector('[data-prw-status] i');
	var cleanText = statusText ? statusText.textContent : '';
	var cleanIcon = statusIcon ? statusIcon.className : '';
	var dirty = false, leaving = false;
	function snapshot() {
		var out = [];
		new FormData(form).forEach(function (v, k) { out.push(k + '=' + (typeof v === 'string' ? v : '')); });
		return out.join('&');
	}
	var initial = snapshot();
	function check() {
		var now = snapshot() !== initial;
		if (now === dirty) { return; }
		dirty = now;
		if (bar) { bar.classList.toggle('is-dirty', dirty); }
		if (statusText) { statusText.textContent = dirty ? (t.dirty || 'You have unsaved changes on this step') : cleanText; }
		if (statusIcon) { statusIcon.className = dirty ? 'fa-solid fa-circle-dot' : cleanIcon; }
	}
	form.addEventListener('input', check);
	form.addEventListener('change', check);

	form.addEventListener('submit', function (event) {
		leaving = true;
		var button = event.submitter || form.querySelector('.prw-btn--primary');
		if (button && button.classList && button.classList.contains('prw-default-submit')) {
			button = form.querySelector('.prw-btn--primary');
		}
		if (button && button.classList) {
			button.classList.add('is-busy');
			button.setAttribute('aria-busy', 'true');
		}
		if (statusText) { statusText.textContent = t.saving || 'Saving…'; }
	});

	// Links (side menu, step track, Skip, Exit) would drop what was typed: ask first. Runs in the capture
	// phase so a cancelled click is already defaultPrevented when the page loader sees it.
	document.addEventListener('click', function (event) {
		if (!dirty || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) { return; }
		var link = event.target.closest ? event.target.closest('a[href]') : null;
		if (!link || link.target === '_blank' || link.getAttribute('href').charAt(0) === '#') { return; }
		if (window.confirm(t.confirm || 'Leave this step? Changes you made here have not been saved yet.')) {
			leaving = true;
		} else {
			event.preventDefault();
			event.stopPropagation();
		}
	}, true);

	window.addEventListener('beforeunload', function (event) {
		if (dirty && !leaving) { event.preventDefault(); event.returnValue = ''; }
	});
})();
