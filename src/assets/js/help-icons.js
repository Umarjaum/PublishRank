/* PublishRank contextual help icons.
 * One icon per setting, one shared popover (never clipped by cards or tables), keyboard and screen-reader friendly.
 * Esc and outside click close it; focus returns to the icon after Esc. */
(function () {
	'use strict';

	var cfg = window.publishrankHelp;
	if (!cfg || !cfg.map) { return; }
	var i18n = cfg.i18n || {};

	// Most specific phrase wins ("Schema type" before "Schema").
	var entries = Object.keys(cfg.map).map(function (key) {
		return { key: key, lower: key.toLowerCase(), text: String(cfg.map[key]) };
	}).sort(function (a, b) { return b.key.length - a.key.length; });

	var SKIP = '.pr-docs, .pr-modules-nav, .pr-shell-intro, .rhs-header, .pr-shell-nav, .pr-module, .pr-hub-row, .notice, #wpadminbar, #adminmenu, [data-pr-no-help]';
	var EXISTING = '.pr-help-icon, [data-pr-help], .dashicons-editor-help, .pr-tip, .rhs-info, .pr-info';
	var SCOPE = 'tr, fieldset, .pr-set-card, .pr-hub-card, .rhs-card, .pr-field, .pr-seo-field, .postbox, .card, section, form';

	function escapeRegExp(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

	function findEntry(text) {
		var t = text.toLowerCase();
		for (var i = 0; i < entries.length; i++) {
			var re = new RegExp('(^|[^a-z0-9])' + escapeRegExp(entries[i].lower) + '($|[^a-z0-9])');
			if (re.test(t)) { return entries[i]; }
		}
		return null;
	}

	function cleanText(el) {
		return (el.textContent || '').replace(/\s+/g, ' ').trim();
	}

	/* ---------- shared popover ---------- */
	var pop = document.createElement('div');
	pop.className = 'pr-help-pop';
	pop.id = 'pr-help-pop';
	pop.setAttribute('role', 'status');
	pop.hidden = true;
	var activeBtn = null;
	var raf = 0;

	function place() {
		raf = 0;
		if (!activeBtn || pop.hidden) { return; }
		var r = activeBtn.getBoundingClientRect();
		var vw = document.documentElement.clientWidth;
		var vh = document.documentElement.clientHeight;
		pop.style.left = '0px';
		pop.style.top = '0px';
		var w = pop.offsetWidth;
		var h = pop.offsetHeight;
		var gap = 8;
		var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), Math.max(8, vw - w - 8));
		var below = r.bottom + gap;
		var top = (below + h <= vh - 8 || r.top - gap - h < 8) ? below : r.top - gap - h;
		top = Math.max(8, Math.min(top, Math.max(8, vh - h - 8)));
		pop.style.left = Math.round(left) + 'px';
		pop.style.top = Math.round(top) + 'px';
	}

	function schedule() {
		if (!raf) { raf = window.requestAnimationFrame(place); }
	}

	function close(returnFocus) {
		if (!activeBtn) { return; }
		var btn = activeBtn;
		activeBtn = null;
		btn.setAttribute('aria-expanded', 'false');
		btn.removeAttribute('aria-describedby');
		btn.classList.remove('is-open');
		pop.hidden = true;
		pop.textContent = '';
		if (returnFocus) { btn.focus(); }
	}

	function open(btn) {
		if (activeBtn && activeBtn !== btn) { close(false); }
		activeBtn = btn;
		btn.setAttribute('aria-expanded', 'true');
		btn.setAttribute('aria-describedby', pop.id);
		btn.classList.add('is-open');
		pop.hidden = false;
		pop.textContent = btn.getAttribute('data-pr-help-text') || '';
		place();
	}

	document.body.appendChild(pop);

	document.addEventListener('click', function (event) {
		var btn = event.target.closest ? event.target.closest('.pr-help-icon') : null;
		if (btn) {
			event.preventDefault(); // Inside a <label> this also stops the click from toggling the field.
			event.stopPropagation();
			if (activeBtn === btn) { close(false); } else { open(btn); }
			return;
		}
		if (activeBtn && !(pop.contains(event.target))) { close(false); }
	}, true);

	document.addEventListener('keydown', function (event) {
		if ((event.key === 'Escape' || event.key === 'Esc') && activeBtn) {
			event.stopPropagation();
			close(true);
		}
	}, true);

	window.addEventListener('resize', schedule);
	window.addEventListener('scroll', schedule, true);

	/* ---------- icon placement ---------- */
	var used = new Set(); // "scopeId|key": one icon per setting per section.
	var scopeIds = new WeakMap();
	var scopeCounter = 0;
	var counter = 0;

	function scopeId(el) {
		var scope = el.closest(SCOPE) || document.body;
		if (!scopeIds.has(scope)) { scopeIds.set(scope, ++scopeCounter); }
		return scopeIds.get(scope);
	}

	function addIcon(el, entry, label) {
		var id = scopeId(el) + '|' + entry.key;
		if (used.has(id)) { return; }
		if (el.querySelector(EXISTING) || el.dataset.prHelpAdded) { return; }
		var row = el.closest('tr');
		if (row && row.querySelector(EXISTING)) { return; }
		var next = el.nextElementSibling;
		if (next && next.matches && next.matches(EXISTING)) { return; }
		used.add(id);
		el.dataset.prHelpAdded = '1';

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'pr-help-icon';
		btn.setAttribute('data-pr-help', entry.key);
		btn.setAttribute('data-pr-help-text', entry.text);
		btn.setAttribute('aria-expanded', 'false');
		btn.setAttribute('aria-controls', pop.id);
		btn.setAttribute('aria-label', String(i18n.about || 'More information about %s').replace('%s', label));
		btn.id = 'pr-help-btn-' + (++counter);
		var glyph = document.createElement('span');
		glyph.setAttribute('aria-hidden', 'true');
		glyph.textContent = 'i';
		btn.appendChild(glyph);

		if (el.tagName === 'LABEL' && el.querySelector('input, select, textarea')) {
			el.insertAdjacentElement('afterend', btn); // Keep the control's accessible name clean.
		} else {
			el.classList.add('pr-help-anchor');
			el.appendChild(btn);
		}
	}

	function run() {
		// Specific settings first, then section headings, so a heading never doubles up a setting's own icon.
		['label', 'th', 'legend', 'h3', 'h2'].forEach(function (tag) {
			document.querySelectorAll('.wrap ' + tag).forEach(function (el) {
				if (el.closest(SKIP) || el.querySelector('.pr-help-icon')) { return; }
				if (tag === 'th' && el.querySelector('label')) { return; }
				if (el.classList.contains('screen-reader-text')) { return; }
				var text = cleanText(el);
				if (!text || text.length >= 100) { return; }
				var entry = findEntry(text);
				if (entry) { addIcon(el, entry, text); }
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
}());
