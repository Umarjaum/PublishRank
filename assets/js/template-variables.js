/* PublishRank template-variable picker.
   Builds a grouped, keyboard-accessible dropdown (a native <select>) from window.publishrankVariables, which PHP generates
   from the Meta\TemplateVariables registry, and inserts the exact token at the cursor of the field it belongs to.
   Slots (<span class="pr-var-slot" data-target="field-id" data-field="title|description" data-context="post|home|archive">)
   are mounted once, including ones added later by the block editor or other scripts. */
(function () {
	'use strict';
	var cfg = window.publishrankVariables;
	if (!cfg || !cfg.variables) { return; }
	var t = cfg.i18n || {}, uid = 0;

	function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text != null) { n.textContent = text; } return n; }

	function remember(input) {
		function save() { try { input.__prSel = { s: input.selectionStart, e: input.selectionEnd }; } catch (e) { /* e.g. type=url in old browsers */ } }
		['keyup', 'mouseup', 'select', 'input', 'blur', 'focus'].forEach(function (ev) { input.addEventListener(ev, save); });
	}

	function insertToken(input, token) {
		var value = input.value, sel = input.__prSel || {}, len = value.length;
		var start = typeof sel.s === 'number' ? sel.s : len, end = typeof sel.e === 'number' ? sel.e : len;
		start = Math.min(start, len); end = Math.min(Math.max(end, start), len);
		var next = value.slice(0, start) + token + value.slice(end);
		if (input.maxLength > 0 && next.length > input.maxLength) { return false; }
		input.value = next;
		var pos = start + token.length;
		input.focus();
		try { input.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
		input.__prSel = { s: pos, e: pos };
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
		return true;
	}

	function usable(v, context, field) {
		if (v.contexts.indexOf(context) === -1) { return false; }
		if (field === 'description' && v.id === 'sep') { return false; }
		return true;
	}

	function build(input, context, field) {
		var id = 'pr-var-' + (++uid), wrap = el('span', 'pr-var-picker');
		var label = el('label', 'screen-reader-text', t.label); label.htmlFor = id; wrap.appendChild(label);
		var select = el('select', 'pr-var-select'); select.id = id;
		var help = el('span', 'pr-var-help description'); help.id = id + '-help'; help.setAttribute('aria-live', 'polite');
		select.setAttribute('aria-describedby', help.id);
		var first = el('option', '', t.placeholder); first.value = ''; select.appendChild(first);
		var count = 0;
		(cfg.groups || []).forEach(function (g) {
			var items = cfg.variables.filter(function (v) { return v.group === g.id && usable(v, context, field); });
			if (!items.length) { return; }
			var og = document.createElement('optgroup'); og.label = g.label;
			items.forEach(function (v) {
				var o = el('option', '', v.label + ' — ' + v.token); o.value = v.token; o.setAttribute('data-id', v.id);
				if (!v.available) { o.disabled = true; o.textContent = v.label + ' — ' + v.reason; }
				og.appendChild(o); count++;
			});
			select.appendChild(og);
		});
		if (!count) { select.disabled = true; help.textContent = t.empty || ''; }
		select.addEventListener('change', function () {
			var token = select.value; if (!token) { return; }
			var v = cfg.variables.filter(function (x) { return x.token === token; })[0];
			var ok = insertToken(input, token);
			help.textContent = ok ? (String(t.inserted || '%s').replace('%s', token) + (v && v.fallback ? ' — ' + (t.fallback || '') + ' ' + v.fallback : '')) : (t.tooLong || '');
			select.value = '';
		});
		select.addEventListener('focus', function () { if (!help.textContent) { help.textContent = ''; } });
		wrap.appendChild(select); wrap.appendChild(help);
		return wrap;
	}

	function mount(slot, input, field, context) {
		if (!slot || slot.getAttribute('data-pr-mounted') === '1' || !input) { return false; }
		slot.setAttribute('data-pr-mounted', '1');
		remember(input);
		slot.appendChild(build(input, context || slot.getAttribute('data-context') || 'post', field || slot.getAttribute('data-field') || 'title'));
		return true;
	}

	function scan(root) {
		var slots = (root || document).querySelectorAll ? (root || document).querySelectorAll('.pr-var-slot:not([data-pr-mounted])') : [];
		Array.prototype.forEach.call(slots, function (slot) {
			var target = document.getElementById(slot.getAttribute('data-target') || '');
			if (!target) { return; }
			mount(slot, target);
		});
	}

	window.PublishRankVariables = { mount: mount, scan: scan, insertToken: insertToken };

	var queued = false;
	function schedule() { if (queued) { return; } queued = true; (window.requestAnimationFrame || setTimeout)(function () { queued = false; scan(document); }); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', schedule); } else { schedule(); }
	if (window.MutationObserver && document.body !== undefined) {
		new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
	}
})();
