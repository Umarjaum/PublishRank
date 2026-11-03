/* PublishRank Google Preferred Sources: copy buttons, live link, live custom-link preview and code checks.
 * Nothing here executes the markup being edited; previews are built from validated style values only. */
(function () {
	'use strict';
	var cfg = window.publishrankPS || { i18n: {}, scriptUrl: '', linkBase: '' };
	var t = cfg.i18n || {};
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

	function say(msg) {
		var el = $('#pr-ps-status');
		if (el) { el.textContent = ''; window.setTimeout(function () { el.textContent = msg; }, 20); }
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var ta = document.createElement('textarea');
			ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
			document.body.appendChild(ta); ta.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			document.body.removeChild(ta);
			ok ? resolve() : reject();
		});
	}

	function flash(btn, msg) {
		var old = btn.getAttribute('data-label') || btn.textContent;
		btn.setAttribute('data-label', old);
		btn.textContent = msg;
		window.setTimeout(function () { btn.textContent = old; }, 1600);
		say(msg);
	}

	// Copy buttons: copy exactly the visible code / field value.
	$$('.pr-ps-copy').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var text = '';
			var target = btn.getAttribute('data-pr-ps-copy-target');
			var value = btn.getAttribute('data-pr-ps-copy-value');
			if (target) {
				var el = document.getElementById(target);
				text = el ? ('value' in el ? el.value : el.textContent) : '';
			} else if (value) {
				var f = $(value); text = f ? f.value : '';
			}
			if (!text) { say(t.noUrl || ''); return; }
			copyText(text).then(function () { flash(btn, t.copied || 'Copied'); }, function () { say(t.copyFailed || ''); });
		});
	});

	// Preview placeholder + guide shortcut.
	var pt = $('#pr-ps-preview-toggle');
	if (pt) {
		pt.addEventListener('click', function () {
			var box = $('#pr-ps-preview');
			var open = box.hidden;
			box.hidden = !open;
			pt.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	}
	var sg = $('#pr-ps-show-guide');
	if (sg) {
		sg.addEventListener('click', function () {
			var d = $('#pr-ps-guide'); var card = $('#pr-ps-guide-card');
			if (d) { d.open = true; }
			if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
		});
	}

	// Confirm before discarding editor changes.
	$$('[data-pr-ps-confirm]').forEach(function (b) {
		b.addEventListener('click', function (e) { if (!window.confirm(t.confirmReset || 'Restore the default?')) { e.preventDefault(); } });
	});

	// Live Google link from the URL field (mirrors the PHP normaliser; PHP stays authoritative).
	function hostFrom(input) {
		input = (input || '').trim();
		if (!input || /[\u0000-\u0020\u007f<>"'`\\]/.test(input)) { return ''; }
		if (/^[a-z][a-z0-9+.\-]*:\/\//i.test(input)) {
			if (!/^https?:\/\//i.test(input)) { return ''; }
		} else if (input.indexOf('//') === 0) {
			input = 'https:' + input;
		} else if (/^[a-z][a-z0-9+.\-]*:(?!\d+(?:[\/?#]|$))/i.test(input)) {
			return '';
		} else { input = 'https://' + input; }
		var u;
		try { u = new URL(input); } catch (e) { return ''; }
		if (u.username || u.password) { return ''; }
		var host = u.hostname.toLowerCase().replace(/\.$/, '');
		// Preserve an internationalized domain exactly as typed (URL() would convert it to punycode).
		var typed = input.replace(/^https?:\/\//i, '').split(/[\/?#:]/)[0].toLowerCase().replace(/\.$/, '');
		if (/[^\u0000-\u007f]/.test(typed)) { host = typed; }
		if (/^\d+\.\d+\.\d+\.\d+$/.test(host) || host.indexOf('[') !== -1 || host.indexOf('.') === -1) { return ''; }
		return host;
	}
	var site = $('#pr-ps-site');
	var link = $('#pr-ps-link');
	var open = $('#pr-ps-open');
	var err = $('#pr-ps-site-error');
	function updateLink() {
		if (!site || !link) { return; }
		var host = hostFrom(site.value);
		var url = host ? cfg.linkBase + encodeURIComponent(host) : '';
		link.value = url;
		if (open) {
			if (url) { open.href = url; open.hidden = false; open.removeAttribute('aria-disabled'); } else { open.hidden = true; open.setAttribute('aria-disabled', 'true'); }
		}
		if (err) { err.hidden = !!host || !site.value.trim(); err.textContent = host ? '' : (t.invalidUrl || ''); }
		var form = $('#pr-ps-form');
		if (form) { form.setAttribute('data-domain', host); renderCustom(); }
	}
	if (site) { site.addEventListener('input', updateLink); }

	// Custom link builder (mirrors PHP custom_link_code()).
	var weights = { 400: 1, 500: 1, 600: 1, 700: 1, 800: 1 };
	function hex(v, d) { return /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(v) ? v.toLowerCase() : d; }
	function num(v, lo, hi, d) { var n = parseInt(v, 10); return isNaN(n) ? d : Math.max(lo, Math.min(hi, n)); }
	function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
	function values() {
		var v = {};
		$$('[data-pr-ps-field]').forEach(function (el) { v[el.getAttribute('data-pr-ps-field')] = el.value; });
		var iconEl = $('#pr-ps-c-icon');
		var opt = iconEl ? iconEl.options[iconEl.selectedIndex] : null;
		var w = parseInt(v.weight, 10);
		return {
			label: (v.label || '').trim().slice(0, 80) || 'Add us as a Preferred Source on Google',
			color: hex(v.color, '#ffffff'), bg: hex(v.bg, '#1a73e8'), border: hex(v.border, '#1a73e8'),
			radius: num(v.radius, 0, 60, 6), bwidth: num(v.bwidth, 0, 10, 1), size: num(v.size, 10, 32, 16),
			weight: weights[w] ? w : 600, pad_v: num(v.pad_v, 0, 40, 10), pad_h: num(v.pad_h, 0, 80, 18),
			glyph: opt ? (opt.getAttribute('data-glyph') || '') : '',
			cls: (v.css_class || '').split(/\s+/).map(function (c) { return c.replace(/[^A-Za-z0-9_-]/g, ''); }).filter(Boolean).filter(function (c, i, a) { return a.indexOf(c) === i; }).slice(0, 5).join(' ')
		};
	}
	function renderCustom() {
		var live = $('#pr-ps-live'); var code = $('#pr-ps-custom-code');
		if (!live || !code) { return; }
		var c = values();
		var form = $('#pr-ps-form');
		var domain = form ? form.getAttribute('data-domain') : '';
		live.className = c.cls;
		live.style.cssText = '';
		var s = live.style;
		s.display = 'inline-flex'; s.alignItems = 'center'; s.gap = '.5em'; s.textDecoration = 'none'; s.lineHeight = '1.2';
		s.color = c.color; s.background = c.bg; s.border = c.bwidth + 'px solid ' + c.border; s.borderRadius = c.radius + 'px';
		s.fontSize = c.size + 'px'; s.fontWeight = String(c.weight); s.padding = c.pad_v + 'px ' + c.pad_h + 'px';
		live.textContent = '';
		if (c.glyph) { var ic = document.createElement('span'); ic.setAttribute('aria-hidden', 'true'); ic.textContent = c.glyph; live.appendChild(ic); }
		live.appendChild(document.createTextNode(c.label));
		var style = 'display:inline-flex;align-items:center;gap:.5em;text-decoration:none;line-height:1.2;color:' + c.color + ';background:' + c.bg + ';border:' + c.bwidth + 'px solid ' + c.border + ';border-radius:' + c.radius + 'px;font-size:' + c.size + 'px;font-weight:' + c.weight + ';padding:' + c.pad_v + 'px ' + c.pad_h + 'px;';
		var href = domain ? cfg.linkBase + encodeURIComponent(domain) : 'https://www.google.com/preferences/source?q=YOUR-DOMAIN';
		var out = '<a href="' + href + '"\n' + (c.cls ? '  class="' + c.cls + '"\n' : '') + '  target="_blank"\n  rel="noopener noreferrer"\n  style="' + style + '">\n    ' + (c.glyph ? '<span aria-hidden="true">' + c.glyph + '</span> ' : '') + esc(c.label) + '\n</a>';
		code.textContent = out;
	}
	var live = $('#pr-ps-live');
	if (live) { live.addEventListener('click', function (e) { e.preventDefault(); }); }
	$$('[data-pr-ps-field]').forEach(function (el) { el.addEventListener('input', renderCustom); el.addEventListener('change', renderCustom); });
	renderCustom();


	// One-time choice: Default (Google) or Custom link. Both panels stay visible without JavaScript.
	function currentMode() { var r = $('input[name="ps[mode]"]:checked'); return r ? r.value : 'default'; }
	function applyMode() {
		var m = currentMode();
		var d = $('#pr-ps-panel-default'); var c = $('#pr-ps-panel-custom');
		if (d) { d.hidden = m !== 'default'; }
		if (c) { c.hidden = m !== 'custom'; }
	}
	$$('input[name="ps[mode]"]').forEach(function (r) { r.addEventListener('change', applyMode); });
	applyMode();

	// Default code follows the documented theme / language options.
	function renderDefault() {
		var code = $('#pr-ps-default-code'); var th = $('#pr-ps-theme'); var lg = $('#pr-ps-lang');
		if (!code || !th || !lg) { return; }
		var attrs = 'google-add-preferred-source-btn';
		if (th.value === 'light' || th.value === 'dark') { attrs += ' data-theme="' + th.value + '"'; }
		var lang = lg.value.trim();
		if (/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/.test(lang)) { attrs += ' data-lang="' + lang + '"'; }
		code.textContent = '<div ' + attrs + '></div>\n<script async src="' + cfg.scriptUrl + '"></script>';
	}
	['#pr-ps-theme', '#pr-ps-lang'].forEach(function (sel) { var el = $(sel); if (el) { el.addEventListener('input', renderDefault); el.addEventListener('change', renderDefault); } });
	renderDefault();

	// Advanced editor: advisory text checks only. The server validates again on save.
	var ta = $('#pr-ps-code'); var notes = $('#pr-ps-code-notes');
	function check() {
		if (!ta || !notes) { return; }
		var code = ta.value; var out = []; var cls = 'notice-info';
		if (!code.trim()) { out.push(t.wEmpty); cls = 'notice-warning'; }
		else {
			var hasAttr = /(^|[\s<])google-add-preferred-source-btn(\s|=|>|\/)/.test(code);
			var scripts = code.match(/<script\b[^>]*>/gi) || [];
			var official = scripts.some(function (s) { return s.indexOf(cfg.scriptUrl) !== -1; });
			var foreign = scripts.some(function (s) { return s.indexOf(cfg.scriptUrl) === -1; });
			if (/<script\b[^>]*>[\s\S]*?\S[\s\S]*?<\/script>/i.test(code) || /\son[a-z]+\s*=/i.test(code) || /javascript\s*:/i.test(code)) { out.push(t.wInline); cls = 'notice-error'; }
			if (foreign) { out.push(t.wBadScript); cls = 'notice-error'; }
			if (scripts.length && !hasAttr) { out.push(t.wNoAttr); cls = cls === 'notice-error' ? cls : 'notice-warning'; }
			if (hasAttr && !official) { out.push(t.wNoScript); cls = cls === 'notice-error' ? cls : 'notice-warning'; }
			var open = (code.match(/<(div|a|span|p|script)\b(?![^>]*\/>)[^>]*>/gi) || []).length;
			var close = (code.match(/<\/(div|a|span|p|script)>/gi) || []).length;
			if (open !== close) { out.push(t.wBalance); cls = cls === 'notice-error' ? cls : 'notice-warning'; }
			if (hasAttr || official) { out.unshift(t.wGoogle); } else if (/<a\b/i.test(code)) { out.unshift(t.wCustom); }
		}
		notes.innerHTML = '';
		var box = document.createElement('div'); box.className = 'notice inline ' + cls;
		out.forEach(function (m) { var p = document.createElement('p'); p.textContent = m; box.appendChild(p); });
		notes.appendChild(box);
	}
	if (ta) { ta.addEventListener('input', check); check(); }
}());
