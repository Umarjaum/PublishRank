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

	/* ---------- Google-style homepage preview (step 5) ---------- */
	(function () {
		var box = form.querySelector('[data-prw-serp]') || root.querySelector('[data-prw-serp]');
		var pt = t.preview || {};
		var titleField = form.elements['publishrank_home_title'];
		var descField = form.elements['publishrank_home_description'];
		var sepField = form.elements['publishrank_seo_title_separator'];
		if (!box || !titleField || !descField || !t.ajaxUrl) { return; }
		var card = box.querySelector('[data-prw-serp-card]');
		var notes = box.querySelector('[data-prw-serp-notes]');
		var parts = {
			fav: box.querySelector('[data-prw-serp-fav]'), site: box.querySelector('[data-prw-serp-site]'),
			url: box.querySelector('[data-prw-serp-url]'), title: box.querySelector('[data-prw-serp-title]'), desc: box.querySelector('[data-prw-serp-desc]')
		};
		var device = 'desktop', last = null, seq = 0, timer = null;
		var ctx = null;
		try { ctx = document.createElement('canvas').getContext('2d'); } catch (e) { ctx = null; }
		function px(text, font) { if (!ctx) { return text.length * 9; } ctx.font = font; return ctx.measureText(text).width; }
		function clip(text, font, max) {
			if (px(text, font) <= max) { return text; }
			var lo = 0, hi = text.length;
			while (lo < hi) { var mid = Math.ceil((lo + hi) / 2); if (px(text.slice(0, mid) + '\u2026', font) <= max) { lo = mid; } else { hi = mid - 1; } }
			return text.slice(0, lo).replace(/\s+$/, '') + '\u2026';
		}
		function crumbs(url) {
			try { var u = new URL(url); var path = u.pathname.replace(/\/+$/, ''); return u.hostname + (path ? ' \u203a ' + path.split('/').filter(Boolean).slice(0, 3).join(' \u203a ') : ''); } catch (e) { return url || ''; }
		}
		function addNote(text, warn) { var li = document.createElement('li'); li.className = warn ? 'is-warn' : ''; li.textContent = text; notes.appendChild(li); }
		function render(d) {
			var mobile = device === 'mobile';
			card.classList.toggle('is-mobile', mobile);
			parts.site.textContent = d.site_name || '';
			parts.url.textContent = crumbs(d.url);
			parts.fav.textContent = ''; parts.fav.className = 'prw-serp__fav';
			if (d.favicon && /^https?:\/\//i.test(d.favicon)) { var img = document.createElement('img'); img.src = d.favicon; img.alt = ''; parts.fav.appendChild(img); }
			else { parts.fav.textContent = (d.site_name || 'S').charAt(0).toUpperCase(); parts.fav.className += ' is-letter'; }
			var tfont = '20px Arial,sans-serif', dfont = '14px Arial,sans-serif';
			var tmax = mobile ? 1120 : 600, dmax = mobile ? 1920 : 1840;
			var title = d.title || '', desc = d.description || '';
			var tpx = px(title, tfont);
			parts.title.textContent = clip(title, tfont, tmax);
			parts.desc.textContent = desc ? clip(desc, dfont, dmax) : '';
			parts.desc.classList.toggle('is-empty', !desc);
			notes.textContent = '';
			if (!d.title_custom && pt.titleFall) { addNote(pt.titleFall, false); }
			if (tpx > tmax && pt.titleLong) { addNote(pt.titleLong, true); }
			if (!desc) { if (pt.descNone) { addNote(pt.descNone, true); } }
			else {
				if (d.source_label && d.source !== 'homepage' && pt.descFrom) { addNote(pt.descFrom.replace('%s', d.source_label), false); }
				if (desc.length > 160 && pt.descLong) { addNote(pt.descLong, true); }
				else if (desc.length < 70 && pt.descShort) { addNote(pt.descShort, true); }
			}
		}
		function fetchPreview() {
			var mine = ++seq;
			var body = new URLSearchParams();
			body.append('action', 'publishrank_home_preview'); body.append('nonce', t.previewNonce || '');
			body.append('title', titleField.value); body.append('description', descField.value);
			if (sepField) { body.append('sep', sepField.value); }
			card.classList.add('is-loading');
			fetch(t.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (mine !== seq) { return; }
					if (!res || !res.success || !res.data) { throw new Error('bad'); }
					last = res.data; render(last);
				})
				.catch(function () { if (mine === seq && pt.failed) { notes.textContent = ''; addNote(pt.failed, true); } })
				.then(function () { if (mine === seq) { card.classList.remove('is-loading'); } });
		}
		function schedule() { window.clearTimeout(timer); timer = window.setTimeout(fetchPreview, 350); }
		[titleField, descField, sepField].forEach(function (field) {
			if (!field) { return; }
			field.addEventListener('input', schedule); field.addEventListener('change', schedule);
		});
		box.querySelectorAll('[data-prw-serp-device]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				device = btn.getAttribute('data-prw-serp-device');
				box.querySelectorAll('[data-prw-serp-device]').forEach(function (b) {
					var on = b === btn; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
				});
				if (last) { render(last); }
			});
		});
		fetchPreview();
	}());

	/* ---------- homepage description length guide ---------- */
	form.querySelectorAll('[data-prw-count-for]').forEach(function (counter) {
		var field = document.getElementById(counter.getAttribute('data-prw-count-for'));
		if (!field) { return; }
		var min = parseInt(counter.getAttribute('data-prw-count-min'), 10) || 120;
		var max = parseInt(counter.getAttribute('data-prw-count-max'), 10) || 160;
		function update() {
			var text = field.value.replace(/\s+/g, ' ').trim();
			// Variables resolve on the site, so their length cannot be known here.
			if (!text || text.indexOf('%%') !== -1) { counter.textContent = ''; counter.className = 'prw-count'; return; }
			var n = text.length;
			counter.textContent = n + ' / ' + max + ' characters' + (n > max ? ' (search engines may shorten it)' : (n < min ? ' (a little short)' : ''));
			counter.className = 'prw-count' + (n > max || n < min ? ' is-warn' : ' is-ok');
		}
		field.addEventListener('input', update);
		update();
	});

	/* ---------- Save & exit: unlocks as soon as every required field is filled in ---------- */
	var exitButton = form.querySelector('[data-prw-exit]');
	function requiredFilled() {
		var ok = true;
		form.querySelectorAll('[required]').forEach(function (el) {
			if (el.disabled || el.type === 'radio') { return; }
			if (!String(el.value || '').trim()) { ok = false; }
		});
		return ok;
	}
	function syncExit() {
		if (!exitButton) { return; }
		var elsewhere = '1' === exitButton.getAttribute('data-prw-exit-elsewhere');
		exitButton.disabled = elsewhere || !requiredFilled();
	}
	if (exitButton) {
		form.addEventListener('input', syncExit);
		form.addEventListener('change', syncExit);
		syncExit();
	}

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
	/* ---------- Security step: live login address + copy button ---------- */
	(function () {
		var box = root.querySelector('[data-prw-loginurl]');
		if (!box) { return; }
		var out = box.querySelector('[data-prw-loginurl-text]');
		var btn = box.querySelector('[data-prw-copy-url]');
		var status = box.querySelector('[data-prw-copy-status]');
		var input = document.getElementById(box.getAttribute('data-prw-input') || '');
		var prefix = box.getAttribute('data-prw-prefix') || '';
		var tail = box.getAttribute('data-prw-tail') || '';

		function clean(value) {
			var slug = String(value || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/-{2,}/g, '-').replace(/^-+|-+$/g, '');
			return slug || 'login';
		}
		function refresh() {
			if (input && out) { out.textContent = prefix + clean(input.value) + tail; }
		}
		function say(message) {
			if (status) { status.textContent = message; }
		}
		function legacyCopy(text) {
			var area = document.createElement('textarea');
			area.value = text;
			area.setAttribute('readonly', '');
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild(area);
			area.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			document.body.removeChild(area);
			return ok;
		}

		if (input) { input.addEventListener('input', refresh); }
		if (btn && out) {
			btn.addEventListener('click', function () {
				var text = out.textContent || '';
				var done = function () { say(box.getAttribute('data-copied') || 'Copied'); };
				var fail = function () { say(box.getAttribute('data-copy-failed') || 'Press Ctrl+C to copy'); };
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done, function () { if (legacyCopy(text)) { done(); } else { fail(); } });
				} else if (legacyCopy(text)) {
					done();
				} else {
					fail();
				}
			});
		}
	})();
})();
