/* PublishRank General Settings: section highlighting, setting search and unsaved-changes bar. No dependencies. */
(function () {
	'use strict';
	var t = window.publishrankSettingsUi || {};
	var form = document.querySelector('[data-pr-set-form]');
	if (!form) { return; }

	/* ---------- unsaved changes ---------- */
	var bar = document.querySelector('[data-pr-set-savebar]');
	var status = bar ? bar.querySelector('[data-pr-set-status] span') : null;
	var dirty = false, submitting = false, touched = false;
	function snapshot() {
		var out = [];
		new FormData(form).forEach(function (v, k) { out.push(k + '=' + (typeof v === 'string' ? v : '')); });
		return out.join('&');
	}
	var initial = snapshot();
	function setDirty(now) {
		if (now === dirty) { return; }
		dirty = now;
		if (bar) { bar.classList.toggle('is-dirty', dirty); }
		if (status) { status.textContent = dirty ? (t.dirty || 'You have unsaved changes') : (t.clean || 'All changes saved'); }
	}
	function check() {
		setDirty(snapshot() !== initial);
	}
	// Other scripts (repeaters, pickers, defaults) may adjust fields right after load. Until the person actually edits
	// something, treat the current values as the saved state so the warning never appears without a real change.
	function rebaseline() {
		if (!touched && !dirty) { initial = snapshot(); }
	}
	window.addEventListener('load', function () { window.setTimeout(rebaseline, 150); });
	if (status) { status.textContent = t.clean || 'All changes saved'; }
	function onEdit(event) {
		if (event && event.isTrusted) { touched = true; }
		check();
	}
	form.addEventListener('input', onEdit);
	form.addEventListener('change', onEdit);
	// Buttons that add or remove list items change the form without firing input/change.
	form.addEventListener('click', function (event) {
		if (event.target.closest && event.target.closest('button, .button, [data-pr-repeater-add], [data-pr-repeater-remove]')) {
			window.setTimeout(check, 0);
		}
	});
	form.addEventListener('submit', function (event) {
		if (submitting) { event.preventDefault(); return; } // Ignore a second click while the first save is running.
		submitting = true;
		window.setTimeout(function () {
			if (event.defaultPrevented) {
				submitting = false; // Another handler or validation cancelled the save: keep protecting unsaved edits.
				return;
			}
			if (status) { status.textContent = t.saving || 'Saving…'; }
			if (bar) { bar.classList.add('is-saving'); }
		}, 0);
	});
	window.addEventListener('pageshow', function (event) {
		if (event.persisted) { submitting = false; if (bar) { bar.classList.remove('is-saving'); } check(); }
	});
	window.addEventListener('beforeunload', function (e) {
		if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; }
	});

	/* ---------- current section chip ---------- */
	var chips = {};
	document.querySelectorAll('[data-pr-set-chip]').forEach(function (a) { chips[a.getAttribute('data-pr-set-chip')] = a; });
	var sections = Array.prototype.slice.call(document.querySelectorAll('[data-pr-set-section]'));
	function setActive(id) {
		Object.keys(chips).forEach(function (k) {
			var on = k === id;
			chips[k].classList.toggle('is-active', on);
			if (on) {
				chips[k].setAttribute('aria-current', 'true');
				var nav = chips[k].parentNode;
				if (nav && nav.scrollWidth > nav.clientWidth) {
					nav.scrollLeft = chips[k].offsetLeft - nav.clientWidth / 2 + chips[k].clientWidth / 2;
				}
			} else {
				chips[k].removeAttribute('aria-current');
			}
		});
	}
	function onScroll() {
		var current = sections[0] ? sections[0].id : '';
		for (var i = 0; i < sections.length; i++) {
			if (sections[i].hidden) { continue; }
			if (sections[i].getBoundingClientRect().top - 160 <= 0) { current = sections[i].id; }
		}
		if ((window.innerHeight + window.scrollY) >= document.body.scrollHeight - 4 && sections.length) {
			for (var j = sections.length - 1; j >= 0; j--) { if (!sections[j].hidden) { current = sections[j].id; break; } }
		}
		setActive(current);
	}
	var ticking = false;
	window.addEventListener('scroll', function () {
		if (ticking) { return; }
		ticking = true;
		window.requestAnimationFrame(function () { ticking = false; onScroll(); });
	}, { passive: true });
	onScroll();

	/* ---------- search ---------- */
	var search = document.querySelector('[data-pr-set-search]');
	var info = document.querySelector('[data-pr-set-search-status]');
	var empty = document.querySelector('[data-pr-set-empty]');
	function text(el) { return (el.textContent || '').toLowerCase(); }
	function rowText(row) {
		var s = text(row);
		row.querySelectorAll('input[placeholder], textarea[placeholder]').forEach(function (f) { s += ' ' + f.getAttribute('placeholder').toLowerCase(); });
		return s;
	}
	function applySearch() {
		var q = search.value.trim().toLowerCase(), total = 0;
		sections.forEach(function (sec) {
			var head = sec.querySelector('.pr-set-card__head'), headHit = q !== '' && text(head).indexOf(q) !== -1;
			var rows = sec.querySelectorAll('.form-table > tbody > tr'), hits = 0;
			rows.forEach(function (row) {
				var hit = q === '' || headHit || rowText(row).indexOf(q) !== -1;
				row.hidden = !hit;
				row.classList.toggle('is-match', q !== '' && !headHit && hit);
				if (hit) { hits++; }
			});
			if (!rows.length) { hits = (q === '' || headHit || text(sec).indexOf(q) !== -1) ? 1 : 0; }
			sec.hidden = hits === 0;
			if (q !== '' && hits > 0) { sec.open = true; }
			if (q === '' && sec.id !== 'pr-settings-titles') { sec.open = false; }
			if (chips[sec.id]) { chips[sec.id].classList.toggle('is-hidden', hits === 0); }
			if (q !== '') { total += rows.length ? hits : (hits ? 1 : 0); }
		});
		if (info) {
			info.hidden = q === '';
			info.textContent = String(t.matches || '%d matching settings').replace('%d', total);
		}
		if (empty) { empty.hidden = q === '' || total > 0; }
		onScroll();
	}
	if (search) {
		search.addEventListener('input', applySearch);
		search.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { search.value = ''; applySearch(); }
		});
	}
}());
