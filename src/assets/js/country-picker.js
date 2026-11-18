/* PublishRank country picker: accessible combobox + chips over a plain comma-separated text field. */
(function () {
	'use strict';
	var dataEl = document.getElementById('pr-country-data');
	if (!dataEl) { return; }
	var rows;
	try { rows = JSON.parse(dataEl.textContent || '[]'); } catch (e) { return; }
	var cfg = window.publishrankCountryPicker || {};
	var t = cfg.i18n || {};
	var byCode = {};
	var byA3 = {};
	rows.forEach(function (r) { byCode[r[0]] = r; if (r[1]) { byA3[r[1]] = r[0]; } });
	function flag(cc) {
		if (!/^[A-Z]{2}$/.test(cc) || cc === 'T1') { return '\u{1F310}'; }
		return String.fromCodePoint(127397 + cc.charCodeAt(0), 127397 + cc.charCodeAt(1));
	}
	function fold(s) { return String(s).normalize ? String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase() : String(s).toLowerCase(); }
	function fmt(s, v) { return String(s || '').replace('%s', v).replace('%d', v); }
	var uid = 0;

	function enhance(wrap) {
		var raw = wrap.querySelector('.pr-country-picker__raw');
		if (!raw || wrap.dataset.enhanced) { return; }
		wrap.dataset.enhanced = '1';
		var special = wrap.getAttribute('data-special') === '1';
		var id = 'prcp' + (++uid);
		var selected = [];

		function parse(text) {
			var out = [];
			String(text || '').toUpperCase().split(/[\s,;]+/).forEach(function (p) {
				if (!p) { return; }
				if (p.length === 3 && byA3[p]) { p = byA3[p]; }
				if (byCode[p] && (special || p !== 'T1') && out.indexOf(p) === -1) { out.push(p); }
			});
			return out;
		}
		selected = parse(raw.value);

		raw.type = 'hidden';
		var label = wrap.querySelector('label');
		var box = document.createElement('div');
		box.className = 'pr-cp';
		var chips = document.createElement('ul');
		chips.className = 'pr-cp__chips';
		chips.setAttribute('aria-label', label ? label.textContent : '');
		var row = document.createElement('div');
		row.className = 'pr-cp__row';
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'pr-cp__input';
		input.id = id + '-input';
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', id + '-list');
		input.setAttribute('autocomplete', 'off');
		input.placeholder = t.placeholder || 'Search…';
		var count = document.createElement('span');
		count.className = 'pr-cp__count';
		var clear = document.createElement('button');
		clear.type = 'button';
		clear.className = 'button-link pr-cp__clear';
		clear.textContent = t.clear || 'Clear all';
		var list = document.createElement('ul');
		list.className = 'pr-cp__list';
		list.id = id + '-list';
		list.setAttribute('role', 'listbox');
		list.setAttribute('aria-multiselectable', 'true');
		list.hidden = true;
		var live = document.createElement('span');
		live.className = 'screen-reader-text';
		live.setAttribute('aria-live', 'polite');
		if (label) { label.setAttribute('for', input.id); }
		var help = wrap.querySelector('.description');
		if (help && help.id) { input.setAttribute('aria-describedby', help.id); }

		row.appendChild(input); row.appendChild(count); row.appendChild(clear);
		box.appendChild(chips); box.appendChild(row); box.appendChild(list); box.appendChild(live);
		raw.insertAdjacentElement('afterend', box);

		var active = -1;
		var matches = [];

		function sync() {
			raw.value = selected.join(',');
			chips.innerHTML = '';
			if (!selected.length) {
				var none = document.createElement('li');
				none.className = 'pr-cp__none';
				none.textContent = t.none || '';
				chips.appendChild(none);
			}
			selected.forEach(function (cc) {
				var r = byCode[cc];
				var li = document.createElement('li');
				li.className = 'pr-cp__chip';
				var txt = document.createElement('span');
				txt.textContent = flag(cc) + ' ' + r[2] + ' (' + cc + (r[1] ? ' · ' + r[1] : '') + ')';
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'pr-cp__x';
				b.setAttribute('aria-label', fmt(t.remove || 'Remove %s', r[2]));
				b.textContent = '\u00d7';
				b.addEventListener('click', function () { toggle(cc); input.focus(); });
				li.appendChild(txt); li.appendChild(b);
				chips.appendChild(li);
			});
			count.textContent = fmt(t.selected || '%d selected', selected.length);
			clear.hidden = !selected.length;
			raw.dispatchEvent(new Event('change', { bubbles: true }));
		}

		function toggle(cc) {
			var i = selected.indexOf(cc);
			if (i === -1) { selected.push(cc); live.textContent = fmt(t.added || '%s added', byCode[cc][2]); }
			else { selected.splice(i, 1); live.textContent = fmt(t.removed || '%s removed', byCode[cc][2]); }
			sync();
			render();
		}

		function render() {
			var q = fold(input.value.trim());
			list.innerHTML = '';
			matches = rows.filter(function (r) {
				if (!special && r[0] === 'T1') { return false; }
				if (!q) { return true; }
				return fold(r[2]).indexOf(q) !== -1 || r[0].toLowerCase() === q || (r[1] && r[1].toLowerCase() === q);
			}).sort(function (a, b) {
				if (!q) { return 0; }
				var ea = (a[0].toLowerCase() === q || (a[1] || '').toLowerCase() === q || fold(a[2]).indexOf(q) === 0) ? 0 : 1;
				var eb = (b[0].toLowerCase() === q || (b[1] || '').toLowerCase() === q || fold(b[2]).indexOf(q) === 0) ? 0 : 1;
				return ea - eb;
			}).slice(0, 60);
			if (!matches.length) {
				var li = document.createElement('li');
				li.className = 'pr-cp__empty';
				li.textContent = t.noMatch || '';
				list.appendChild(li);
			}
			matches.forEach(function (r, i) {
				var li = document.createElement('li');
				li.id = id + '-opt-' + r[0];
				li.setAttribute('role', 'option');
				li.className = 'pr-cp__opt' + (i === active ? ' is-active' : '');
				var on = selected.indexOf(r[0]) !== -1;
				li.setAttribute('aria-selected', on ? 'true' : 'false');
				li.innerHTML = '';
				var check = document.createElement('span');
				check.className = 'pr-cp__check';
				check.setAttribute('aria-hidden', 'true');
				check.textContent = on ? '\u2713' : '';
				var name = document.createElement('span');
				name.className = 'pr-cp__name';
				name.textContent = flag(r[0]) + ' ' + r[2];
				var code = document.createElement('span');
				code.className = 'pr-cp__code';
				code.textContent = r[0] + (r[1] ? ' · ' + r[1] : '');
				li.appendChild(check); li.appendChild(name); li.appendChild(code);
				li.addEventListener('mousedown', function (e) { e.preventDefault(); toggle(r[0]); });
				list.appendChild(li);
			});
			if (active >= 0 && matches[active]) { input.setAttribute('aria-activedescendant', id + '-opt-' + matches[active][0]); }
			else { input.removeAttribute('aria-activedescendant'); }
		}

		function open() { list.hidden = false; input.setAttribute('aria-expanded', 'true'); render(); }
		function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; input.removeAttribute('aria-activedescendant'); }
		function move(d) {
			if (list.hidden) { open(); }
			if (!matches.length) { return; }
			active = (active + d + matches.length) % matches.length;
			render();
			var el = document.getElementById(id + '-opt-' + matches[active][0]);
			if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'nearest' }); }
		}

		input.addEventListener('focus', open);
		input.addEventListener('input', function () { active = matches.length ? 0 : -1; open(); active = 0; render(); });
		input.addEventListener('blur', function () { setTimeout(close, 120); });
		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
			else if (e.key === 'Enter') {
				e.preventDefault();
				var pasted = parse(input.value);
				if (pasted.length > 1 || (pasted.length === 1 && input.value.trim().length <= 3)) {
					pasted.forEach(function (cc) { if (selected.indexOf(cc) === -1) { selected.push(cc); } });
					input.value = ''; sync(); render(); return;
				}
				if (active >= 0 && matches[active]) { toggle(matches[active][0]); }
			} else if (e.key === 'Escape') { if (!list.hidden) { e.preventDefault(); close(); } }
			else if (e.key === 'Backspace' && !input.value && selected.length) { toggle(selected[selected.length - 1]); }
		});
		input.addEventListener('paste', function (e) {
			var text = (e.clipboardData || window.clipboardData).getData('text');
			var pasted = parse(text);
			if (pasted.length > 1) {
				e.preventDefault();
				pasted.forEach(function (cc) { if (selected.indexOf(cc) === -1) { selected.push(cc); } });
				sync(); render();
			}
		});
		clear.addEventListener('click', function () { selected = []; sync(); render(); input.focus(); });
		sync();
	}

	function init() { document.querySelectorAll('.pr-country-picker').forEach(enhance); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
	window.publishrankEnhanceCountryPickers = init;
}());
